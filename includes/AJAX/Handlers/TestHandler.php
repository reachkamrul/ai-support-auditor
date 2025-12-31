<?php
/**
 * Test AJAX Handler
 * 
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;

class TestHandler {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function test_system_message() {
        $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : null;
        
        if (!$ticket_id) {
            wp_send_json_error('Ticket ID is required');
            return;
        }
        
        global $wpdb;
        
        // Create/update audit record as pending
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ais_audits WHERE ticket_id=%s", 
            $ticket_id
        ));
        
        if($exists) {
            $wpdb->update(
                "{$wpdb->prefix}ais_audits", 
                ['status' => 'pending'], 
                ['ticket_id' => $ticket_id]
            );
        } else {
            $wpdb->insert("{$wpdb->prefix}ais_audits", [
                'ticket_id' => $ticket_id,
                'status' => 'pending'
            ]);
        }
        
        // Trigger n8n webhook with test flag
        $payload = [
            'ticket_id' => $ticket_id,
            'test_mode' => true,
            'raw_json' => json_encode(['ticket_id' => $ticket_id])
        ];
        
        wp_remote_post(N8N_FORCE_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload),
            'timeout' => 5,
            'blocking' => false
        ]);
        
        wp_send_json_success([
            'ticket_id' => $ticket_id,
            'status' => 'pending',
            'message' => 'Test triggered. Polling for results...'
        ]);
    }
    
    public function check_test_status() {
        $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : null;
        
        if (!$ticket_id) {
            wp_send_json_error('Ticket ID is required');
            return;
        }
        
        global $wpdb;
        
        $audit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_audits 
             WHERE ticket_id = %s 
             ORDER BY created_at DESC 
             LIMIT 1",
            $ticket_id
        ));
        
        if (!$audit) {
            wp_send_json_success([
                'ticket_id' => $ticket_id,
                'status' => 'pending',
                'message' => 'Waiting for audit to start...'
            ]);
            return;
        }
        
        if ($audit->status === 'pending') {
            wp_send_json_success([
                'ticket_id' => $ticket_id,
                'status' => 'pending',
                'message' => 'Audit in progress...'
            ]);
            return;
        }
        
        if ($audit->status === 'success' && !empty($audit->audit_response)) {
            wp_send_json_success([
                'ticket_id' => $ticket_id,
                'status' => 'success',
                'audit_result' => $audit->audit_response,
                'score' => $audit->overall_score
            ]);
            return;
        }
        
        if ($audit->status === 'failed') {
            wp_send_json_success([
                'ticket_id' => $ticket_id,
                'status' => 'failed',
                'error_message' => $audit->error_message
            ]);
            return;
        }
        
        wp_send_json_success([
            'ticket_id' => $ticket_id,
            'status' => $audit->status,
            'message' => 'Unknown status'
        ]);
    }
}