<?php
/**
 * Problem Context Saver Service
 * 
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Database\Manager as DatabaseManager;

class ProblemContextSaver {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function save($ticket_id, $audit_data) {
        global $wpdb;
        
        if (empty($audit_data['problem_contexts'])) {
            return;
        }
        
        // Delete old contexts
        $wpdb->delete($wpdb->prefix . 'ais_problem_contexts', ['ticket_id' => $ticket_id]);
        
        // Insert new contexts
        foreach ($audit_data['problem_contexts'] as $context) {
            $wpdb->insert($wpdb->prefix . 'ais_problem_contexts', [
                'ticket_id' => $ticket_id,
                'problem_slug' => sanitize_title($context['issue_description'] ?? 'unknown'),
                'issue_description' => sanitize_text_field($context['issue_description'] ?? ''),
                'category' => sanitize_text_field($context['category'] ?? ''),
                'severity' => sanitize_text_field($context['severity'] ?? ''),
                'responsible_agent' => sanitize_email($context['handling_agents'][0]['agent_id'] ?? ''),
                'agent_marking' => intval($context['handling_agents'][0]['marking'] ?? 0),
                'reasoning' => sanitize_text_field($context['handling_agents'][0]['reasoning'] ?? '')
            ]);
        }
    }
}