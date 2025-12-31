<?php
/**
 * Backfill Service
 * 
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Database\Manager as DatabaseManager;

class BackfillService {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    /**
     * Backfill agent evaluations from existing audits
     * 
     * @param bool $verbose Whether to output progress messages
     * @return array Statistics about the backfill process
     */
    public function backfill_agent_evaluations($verbose = false) {
        global $wpdb;
        
        $audits_table = $this->database->get_table('audits');
        $evaluations_table = $this->database->get_table('agent_evaluations');
        
        if ($verbose) {
            echo "Starting backfill process...\n";
        }
        
        // Get all audits that have audit_response data
        $audits = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                a.id,
                a.ticket_id,
                a.audit_response,
                a.created_at
            FROM {$audits_table} a
            WHERE a.audit_response IS NOT NULL
            AND a.audit_response != ''
            AND a.status = %s
            ORDER BY a.created_at DESC",
            'success'
        ));
        
        if (empty($audits)) {
            if ($verbose) {
                echo "No audits found to process.\n";
            }
            return [
                'processed' => 0,
                'skipped' => 0,
                'errors' => 0,
                'evaluations_inserted' => 0
            ];
        }
        
        if ($verbose) {
            echo "Found " . count($audits) . " audits to process.\n\n";
        }
        
        $stats = [
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'evaluations_inserted' => 0
        ];
        
        foreach ($audits as $audit) {
            if ($verbose) {
                echo "Processing Ticket #{$audit->ticket_id}... ";
            }
            
            // Check if already has evaluations
            $existing_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$evaluations_table} WHERE ticket_id = %s",
                $audit->ticket_id
            ));
            
            if ($existing_count > 0) {
                if ($verbose) {
                    echo "SKIP (already has {$existing_count} evaluations)\n";
                }
                $stats['skipped']++;
                continue;
            }
            
            // Parse audit_response JSON
            $audit_data = json_decode($audit->audit_response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                if ($verbose) {
                    echo "ERROR (invalid JSON)\n";
                }
                $stats['errors']++;
                continue;
            }
            
            // Check if agent_evaluations exists
            if (empty($audit_data['agent_evaluations']) || !is_array($audit_data['agent_evaluations'])) {
                if ($verbose) {
                    echo "SKIP (no agent_evaluations in response)\n";
                }
                $stats['skipped']++;
                continue;
            }
            
            // Insert agent evaluations
            $evaluations_count = $this->insert_evaluations($audit->ticket_id, $audit_data['agent_evaluations'], $audit->created_at);
            
            if ($evaluations_count > 0) {
                if ($verbose) {
                    echo "SUCCESS (inserted {$evaluations_count} evaluations)\n";
                }
                $stats['processed']++;
                $stats['evaluations_inserted'] += $evaluations_count;
            } else {
                if ($verbose) {
                    echo "ERROR (failed to insert evaluations)\n";
                }
                $stats['errors']++;
            }
        }
        
        if ($verbose) {
            echo "\n=== BACKFILL COMPLETE ===\n";
            echo "Tickets processed: {$stats['processed']}\n";
            echo "Tickets skipped: {$stats['skipped']}\n";
            echo "Errors: {$stats['errors']}\n";
            echo "Total evaluations inserted: {$stats['evaluations_inserted']}\n";
        }
        
        return $stats;
    }
    
    /**
     * Insert agent evaluations for a ticket
     * 
     * @param string $ticket_id Ticket ID
     * @param array $evaluations Array of evaluation data
     * @param string $created_at Timestamp to use for created_at
     * @return int Number of evaluations inserted
     */
    private function insert_evaluations($ticket_id, $evaluations, $created_at) {
        global $wpdb;
        
        $evaluations_table = $this->database->get_table('agent_evaluations');
        $count = 0;
        
        foreach ($evaluations as $eval) {
            $agent_email = sanitize_email($eval['agent_email'] ?? '');
            $agent_name = sanitize_text_field($eval['agent_name'] ?? '');
            
            if (empty($agent_email)) {
                continue; // Skip if no email
            }
            
            $result = $wpdb->insert($evaluations_table, [
                'ticket_id' => $ticket_id,
                'agent_email' => $agent_email,
                'agent_name' => $agent_name,
                'timing_score' => intval($eval['timing_score'] ?? 0),
                'resolution_score' => intval($eval['resolution_score'] ?? 0),
                'communication_score' => intval($eval['communication_score'] ?? 0),
                'overall_agent_score' => intval($eval['overall_agent_score'] ?? 0),
                'contribution_percentage' => intval($eval['contribution_percentage'] ?? 0),
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
                'created_at' => $created_at // Use original audit timestamp
            ]);
            
            if ($result) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Get backfill statistics
     * 
     * @return array Statistics about pending backfill
     */
    public function get_stats() {
        global $wpdb;
        
        $audits_table = $this->database->get_table('audits');
        $evaluations_table = $this->database->get_table('agent_evaluations');
        
        $total_audits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$audits_table} 
             WHERE status = %s 
             AND audit_response IS NOT NULL 
             AND audit_response != ''",
            'success'
        ));
        
        $existing_evaluations = $wpdb->get_var(
            "SELECT COUNT(DISTINCT ticket_id) FROM {$evaluations_table}"
        );
        
        $pending_backfill = max(0, intval($total_audits) - intval($existing_evaluations));
        
        return [
            'total_audits' => intval($total_audits),
            'existing_evaluations' => intval($existing_evaluations),
            'pending_backfill' => $pending_backfill
        ];
    }
}

