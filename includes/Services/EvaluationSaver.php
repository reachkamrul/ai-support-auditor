<?php
/**
 * Evaluation Saver Service
 * 
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Database\Manager as DatabaseManager;

class EvaluationSaver {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function save($ticket_id, $audit_data) {
        global $wpdb;
        
        if (empty($audit_data['agent_evaluations']) || !is_array($audit_data['agent_evaluations'])) {
            return;
        }
        
        $evaluations_table = $this->database->get_table('agent_evaluations');
        
        // Delete old evaluations
        $wpdb->delete($evaluations_table, ['ticket_id' => $ticket_id]);
        
        // Insert new evaluations
        foreach ($audit_data['agent_evaluations'] as $eval) {
            $agent_email = sanitize_email($eval['agent_email'] ?? '');
            
            if (empty($agent_email)) {
                continue;
            }
            
            // Auto-create agent if not exists
            $this->ensure_agent_exists($agent_email, $eval);
            
            // Clamp scores to valid ranges
            $timing = max(-200, min(100, intval($eval['timing_score'] ?? 0)));
            $resolution = max(-200, min(100, intval($eval['resolution_score'] ?? 0)));
            $communication = max(-200, min(100, intval($eval['communication_score'] ?? 0)));
            $agent_score = max(-200, min(100, intval($eval['overall_agent_score'] ?? 0)));
            $contribution_pct = max(0, min(100, intval($eval['contribution_percentage'] ?? 0)));

            $wpdb->insert($evaluations_table, [
                'ticket_id' => $ticket_id,
                'agent_email' => $agent_email,
                'agent_name' => sanitize_text_field($eval['agent_name'] ?? ''),
                'timing_score' => $timing,
                'resolution_score' => $resolution,
                'communication_score' => $communication,
                'overall_agent_score' => $agent_score,
                'contribution_percentage' => $contribution_pct,
                'reply_count' => intval($eval['reply_count'] ?? 0),
                'reasoning' => sanitize_text_field($eval['reasoning'] ?? ''),
                'shift_compliance' => !empty($eval['shift_compliance']) 
                    ? json_encode($eval['shift_compliance'], JSON_UNESCAPED_UNICODE) 
                    : null,
                'response_breakdown' => !empty($eval['response_breakdown']) 
                    ? json_encode($eval['response_breakdown'], JSON_UNESCAPED_UNICODE) 
                    : null,
                'key_achievements' => !empty($eval['key_achievements']) 
                    ? json_encode($eval['key_achievements'], JSON_UNESCAPED_UNICODE) 
                    : null,
                'areas_for_improvement' => !empty($eval['areas_for_improvement']) 
                    ? json_encode($eval['areas_for_improvement'], JSON_UNESCAPED_UNICODE) 
                    : null,
                'created_at' => current_time('mysql')
            ]);
        }
    }
    
    /**
     * Ensure agent exists in ais_agents table, create if not
     * 
     * @param string $agent_email
     * @param array $eval_data Evaluation data containing agent_name
     */
    private function ensure_agent_exists($agent_email, $eval_data) {
        global $wpdb;
        
        $agents_table = $this->database->get_table('agents');
        
        // Check if agent already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$agents_table} WHERE email = %s LIMIT 1",
            $agent_email
        ));
        
        if ($existing) {
            return; // Agent already exists
        }
        
        // Extract agent name from audit data (comes via API from N8N)
        $agent_name = sanitize_text_field($eval_data['agent_name'] ?? '');
        
        // Try to parse first and last name
        $first_name = '';
        $last_name = '';
        
        if (!empty($agent_name)) {
            $name_parts = explode(' ', trim($agent_name), 2);
            $first_name = $name_parts[0] ?? '';
            $last_name = $name_parts[1] ?? '';
        } else {
            // Fallback: use email username as first name
            $email_parts = explode('@', $agent_email);
            $first_name = $email_parts[0] ?? 'Agent';
        }
        
        // Try to find FluentSupport agent ID only if FluentSupport is on same installation
        // Note: If FluentSupport is on different installation, N8N handles that via API
        $fluent_agent_id = null;
        if (function_exists('FluentSupportApi')) {
            try {
                // Only query if FluentSupport is installed on same WordPress installation
                $persons = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_persons 
                     WHERE email = %s AND person_type = 'agent' 
                     LIMIT 1",
                    $agent_email
                ));
                
                if (!empty($persons)) {
                    $fluent_agent_id = intval($persons[0]->id);
                }
            } catch (\Exception $e) {
                // Silently fail if FluentSupport table doesn't exist (different installation)
            }
        }
        
        // Create new agent record
        // All data comes from audit response via API - no direct FluentSupport dependency
        $wpdb->insert($agents_table, [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $agent_email,
            'title' => null,
            'fluent_agent_id' => $fluent_agent_id, // Will be null if FluentSupport on different installation
            'avatar_url' => null,
            'is_active' => 1,
            'created_at' => current_time('mysql')
        ]);
    }
}