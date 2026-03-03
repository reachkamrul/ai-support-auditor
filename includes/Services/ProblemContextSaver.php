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
            if (empty($context['issue_description'])) {
                continue;
            }

            $responsible_agent = '';
            $agent_marking = 0;
            $reasoning = '';
            $agents = $context['handling_agents'] ?? [];

            if (!empty($agents) && is_array($agents)) {
                $first_agent = $agents[0] ?? null;
                if ($first_agent && is_array($first_agent)) {
                    $responsible_agent = sanitize_email($first_agent['agent_id'] ?? '');
                    $agent_marking = intval($first_agent['marking'] ?? 0);
                }

                // Store all agents' reasoning as structured JSON
                $all_reasoning = [];
                foreach ($agents as $agent) {
                    if (!is_array($agent)) continue;
                    $all_reasoning[] = [
                        'agent_id' => sanitize_email($agent['agent_id'] ?? ''),
                        'marking' => intval($agent['marking'] ?? 0),
                        'reasoning' => sanitize_text_field($agent['reasoning'] ?? '')
                    ];
                }
                $reasoning = json_encode($all_reasoning, JSON_UNESCAPED_UNICODE);
            }

            $wpdb->insert($wpdb->prefix . 'ais_problem_contexts', [
                'ticket_id' => $ticket_id,
                'problem_slug' => substr(sanitize_title($context['issue_description']), 0, 100),
                'issue_description' => sanitize_text_field($context['issue_description']),
                'category' => sanitize_text_field($context['category'] ?? ''),
                'severity' => sanitize_text_field($context['severity'] ?? ''),
                'responsible_agent' => $responsible_agent,
                'agent_marking' => $agent_marking,
                'reasoning' => $reasoning
            ]);
        }
    }
}