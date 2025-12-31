<?php
/**
 * Audit AJAX Handler
 * 
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Services\TranscriptBuilder;

class AuditHandler {
    
    /**
     * Database manager
     */
    private $database;
    
    /**
     * Transcript builder
     */
    private $transcript_builder;
    
    /**
     * Constructor
     */
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->transcript_builder = new TranscriptBuilder($database);
    }
    
    /**
     * Force audit for a ticket
     */
    public function force_audit() {
        global $wpdb;
        
        $ticket_id = sanitize_text_field($_POST['ticket_id'] ?? '');
        
        if (empty($ticket_id)) {
            wp_send_json_error('Ticket ID required');
            return;
        }
        
        // 1. Try to fetch transcript
        $transcript = $this->transcript_builder->build($ticket_id);
        
        // 2. Create new database record
        $wpdb->insert($this->database->get_table('audits'), [
            'ticket_id' => $ticket_id,
            'raw_json' => $transcript ?: '',
            'status' => 'pending'
        ]);
        
        // 3. Prepare payload for n8n
        $payload = [
            'ticket_id' => $ticket_id,
            'force' => true,
            'raw_json' => json_encode(['ticket_id' => $ticket_id])
        ];
        
        // 4. Send to n8n (non-blocking)
        wp_remote_post(N8N_FORCE_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload),
            'blocking' => false,
            'timeout' => 5
        ]);
        
        // 5. Return success
        wp_send_json_success('Queued for AI');
    }
    
    /**
     * Check audit status
     */
    public function check_status() {
        global $wpdb;
        
        $ticket_id = sanitize_text_field($_POST['ticket_id'] ?? '');
        
        if (empty($ticket_id)) {
            wp_send_json_error('Ticket ID required');
            return;
        }
        
        // Get latest audit for this ticket
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->database->get_table('audits')} 
             WHERE ticket_id = %s 
             ORDER BY created_at DESC 
             LIMIT 1",
            $ticket_id
        ), ARRAY_A);
        
        if (!$result) {
            wp_send_json_error('No audit found');
            return;
        }
        
        // Convert to object for consistency with frontend expectations
        $response = (object)$result;
        
        // Ensure status is properly formatted
        if (isset($response->status)) {
            // Normalize status values
            if ($response->status === 'completed') {
                $response->status = 'success';
            }
        }
        
        wp_send_json_success($response);
    }
}