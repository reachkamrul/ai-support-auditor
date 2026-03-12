<?php
/**
 * Transcript Builder Service
 * 
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Database\Manager as DatabaseManager;

class TranscriptBuilder {
    
    /**
     * Database manager
     */
    private $database;
    
    /**
     * Known agent emails
     */
    private $known_agents = [];
    
    /**
     * Constructor
     */
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->load_known_agents();
    }
    
    /**
     * Load known agent emails from database
     */
    private function load_known_agents() {
        global $wpdb;
        $table = $this->database->get_table('agents');
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            $this->known_agents = [];
            return;
        }
        $this->known_agents = $wpdb->get_col("SELECT email FROM {$table}");
    }
    
    /**
     * Build transcript for a ticket
     * 
     * @param int $ticket_id Ticket ID
     * @return string|false Transcript or false on failure
     */
    public function build($ticket_id) {
        if (!function_exists('FluentSupportApi')) {
            return false;
        }
        
        try {
            $api = FluentSupportApi('tickets');
            $ticket = $api->getTicket($ticket_id);
            
            if (!$ticket) {
                return false;
            }
            
            return $this->format_ticket($ticket);
            
        } catch (\Exception $e) {
            error_log("Transcript builder error for ticket {$ticket_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Format ticket into transcript
     * 
     * @param object $ticket Ticket object from FluentSupport
     * @return string Formatted transcript
     */
    private function format_ticket($ticket) {
        $customer = $ticket->customer;
        $customer_name = $customer ? "{$customer->first_name} {$customer->last_name}" : "Customer";
        
        // Build metadata header
        $transcript = $this->build_header($ticket, $customer_name);
        
        // Build timeline
        $transcript .= "\n\n### TIMELINE\n";
        $transcript .= $this->build_timeline($ticket);
        
        return $transcript;
    }
    
    /**
     * Build ticket metadata header
     * 
     * @param object $ticket Ticket object
     * @param string $customer_name Customer name
     * @return string Header section
     */
    private function build_header($ticket, $customer_name) {
        $header = "### TICKET METADATA\n";
        $header .= "ID: {$ticket->id}\n";
        $header .= "TITLE: {$ticket->title}\n";
        $header .= "STATUS: {$ticket->status}\n";
        $header .= "CUSTOMER: {$customer_name}\n";
        $header .= "CONTENT: " . wp_strip_all_tags($ticket->content);
        
        return $header;
    }
    
    /**
     * Build ticket timeline
     * 
     * @param object $ticket Ticket object
     * @return string Timeline section
     */
    private function build_timeline($ticket) {
        $timeline = '';
        $responses = $ticket->getResponses();
        
        if (!$responses) {
            return $timeline;
        }
        
        // Sort chronologically
        usort($responses, function($a, $b) {
            return strtotime($a->created_at) - strtotime($b->created_at);
        });
        
        foreach ($responses as $response) {
            // Skip bot workflow auto-replies
            if (!empty($response->source) && $response->source === 'fluent_bot_workflow') {
                continue;
            }
            $timeline .= $this->format_response($response, $ticket);
        }
        
        return $timeline;
    }
    
    /**
     * Format a single response
     * 
     * @param object $response Response object
     * @param object $ticket Ticket object
     * @return string Formatted response
     */
    private function format_response($response, $ticket) {
        $content_clean = trim(wp_strip_all_tags($response->content));
        
        // Check if it's a genuine system log (status changes only)
        if ($this->is_system_log($content_clean)) {
            return "[{$response->created_at}] SYSTEM_LOG:\n{$content_clean}\n\n";
        }
        
        // Identify actor (agent or customer)
        $actor_info = $this->identify_actor($response, $ticket);
        
        return "[{$response->created_at}] {$actor_info['label']} ({$actor_info['name']}):\n\"{$content_clean}\"\n\n";
    }
    
    /**
     * Check if content is a system log
     * 
     * @param string $content Content to check
     * @return bool True if system log
     */
    private function is_system_log($content) {
        return strpos($content, 'Ticket has been') === 0;
    }
    
    /**
     * Identify actor (agent or customer)
     * 
     * @param object $response Response object
     * @param object $ticket Ticket object
     * @return array Actor information ['label' => string, 'name' => string]
     */
    private function identify_actor($response, $ticket) {
        $is_agent = false;
        $person_email = isset($response->person->email) ? $response->person->email : '';
        
        // Rule A: ID mismatch (not customer ID = staff)
        if ($response->person_id != $ticket->customer_id) {
            $is_agent = true;
        }
        
        // Rule B: Known agent email
        if ($person_email && in_array($person_email, $this->known_agents)) {
            $is_agent = true;
        }
        
        // Rule C: Type check
        if ($response->person_type === 'agent' || $response->person_type === 'user') {
            $is_agent = true;
        }
        
        // Build label and name
        if ($is_agent) {
            $label = "👤 AGENT";
            $name = ($response->person && !empty($response->person->first_name)) 
                ? $response->person->first_name 
                : ($person_email ?: "Staff");
        } else {
            $label = "👤 CUSTOMER";
            $name = ($response->person) 
                ? ($response->person->first_name ?: "Customer") 
                : "Customer";
        }
        
        return [
            'label' => $label,
            'name' => $name
        ];
    }
}