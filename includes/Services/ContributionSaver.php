<?php
/**
 * Contribution Saver Service
 * 
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Database\Manager as DatabaseManager;

class ContributionSaver {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function save($ticket_id, $audit_data) {
        global $wpdb;
        
        // Delete old contributions
        $wpdb->delete($wpdb->prefix . 'ais_agent_contributions', ['ticket_id' => $ticket_id]);
        
        // Handle new format (agent_evaluations)
        if (!empty($audit_data['agent_evaluations']) && is_array($audit_data['agent_evaluations'])) {
            foreach ($audit_data['agent_evaluations'] as $eval) {
                $this->save_evaluation_contribution($ticket_id, $eval);
            }
            return;
        }
        
        // Handle legacy format (agent_contributions)
        if (empty($audit_data['agent_contributions'])) {
            return;
        }
        
        $contributions = $audit_data['agent_contributions'];
        
        if (isset($contributions[0]) && is_array($contributions[0])) {
            // Array of objects format
            foreach ($contributions as $contrib) {
                $this->save_contribution($ticket_id, $contrib);
            }
        } else {
            // Object with agent names as keys
            foreach ($contributions as $agent_name => $contrib) {
                $agent_email = $this->find_agent_email($agent_name);
                $this->save_contribution($ticket_id, $contrib, $agent_email ?: $agent_name);
            }
        }
    }
    
    private function save_evaluation_contribution($ticket_id, $eval) {
        global $wpdb;

        $agent_email = sanitize_email($eval['agent_email'] ?? '');

        $wpdb->insert($wpdb->prefix . 'ais_agent_contributions', [
            'ticket_id' => $ticket_id,
            'agent_email' => $agent_email,
            'contribution_percentage' => intval($eval['contribution_percentage'] ?? 0),
            'reply_count' => intval($eval['reply_count'] ?? 0),
            'quality_score' => intval($eval['overall_agent_score'] ?? 0),
            'reasoning' => sanitize_text_field($eval['reasoning'] ?? '')
        ]);
    }
    
    private function save_contribution($ticket_id, $contrib, $email = null) {
        global $wpdb;
        
        $agent_email = $email ?: sanitize_email($contrib['agent_email'] ?? '');
        
        $wpdb->insert($wpdb->prefix . 'ais_agent_contributions', [
            'ticket_id' => $ticket_id,
            'agent_email' => $agent_email,
            'contribution_percentage' => intval($contrib['percentage'] ?? 0),
            'reply_count' => intval($contrib['reply_count'] ?? $contrib['response_count'] ?? 0),
            'quality_score' => intval($contrib['score'] ?? 0),
            'reasoning' => sanitize_text_field($contrib['reasoning'] ?? $contrib['role'] ?? '')
        ]);
    }
    
    private function find_agent_email($agent_name) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}fs_persons 
             WHERE CONCAT(first_name, ' ', last_name) = %s 
             AND person_type = 'agent' 
             LIMIT 1",
            $agent_name
        ));
    }
}