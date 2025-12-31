<?php
/**
 * System Message Endpoint
 * 
 * @package SupportOps\API\Endpoints
 */

namespace SupportOps\API\Endpoints;

use SupportOps\Database\Manager as DatabaseManager;

class SystemMessageEndpoint {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function get_message($request) {
        $message = get_option('ai_audit_system_message', '');
        
        if (empty($message)) {
            $message = $this->get_default_message();
        }
        
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        return [
            'system_message' => $message,
            'updated_at' => get_option('ai_audit_system_message_updated', 'Never'),
            'timestamp' => current_time('mysql')
        ];
    }
    
    public function save_message($request) {
        $data = $request->get_json_params();
        $message = isset($data['system_message']) ? wp_unslash($data['system_message']) : '';
        
        if (empty($message)) {
            return new \WP_Error('empty_message', 'System message cannot be empty', ['status' => 400]);
        }
        
        update_option('ai_audit_system_message', $message);
        update_option('ai_audit_system_message_updated', current_time('mysql'));
        
        return [
            'status' => 'saved',
            'updated_at' => get_option('ai_audit_system_message_updated')
        ];
    }
    
    public function test_message($request) {
        $data = $request->get_json_params();
        $ticket_id = isset($data['ticket_id']) ? intval($data['ticket_id']) : null;
        
        if (!$ticket_id) {
            return new \WP_Error('missing_ticket_id', 'ticket_id is required', ['status' => 400]);
        }
        
        // Build transcript
        $transcript_builder = new \SupportOps\Services\TranscriptBuilder($this->database);
        $transcript = $transcript_builder->build($ticket_id);
        
        if (!$transcript) {
            return new \WP_Error('transcript_failed', 'Failed to build transcript for ticket ' . $ticket_id, ['status' => 500]);
        }
        
        $system_message = get_option('ai_audit_system_message', '');
        
        if (empty($system_message)) {
            return new \WP_Error('no_system_message', 'System message not configured', ['status' => 500]);
        }
        
        $system_message = str_replace('{{ $json.clean_transcript_json_safe }}', $transcript, $system_message);
        $system_message = str_replace('{{ $json.shift_context }}', "\n### SHIFT CONTEXT\nNo shift data available.\n", $system_message);
        
        return [
            'ticket_id' => $ticket_id,
            'system_message_prepared' => $system_message,
            'transcript_length' => strlen($transcript),
            'note' => 'This is a preview. Full AI testing requires API integration.'
        ];
    }
    
    private function get_default_message() {
        return "\nYou are the Head of Support Operations & HR Compliance. [Default message truncated]";
    }
}