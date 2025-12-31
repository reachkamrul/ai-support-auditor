<?php
/**
 * Shift Endpoint
 * 
 * @package SupportOps\API\Endpoints
 */

namespace SupportOps\API\Endpoints;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Services\ShiftChecker;

class ShiftEndpoint {
    
    private $database;
    private $shift_checker;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->shift_checker = new ShiftChecker($database);
    }
    
    public function get_context($request) {
        global $wpdb;
        
        $ticket_id = $request->get_param('ticket_id');
        
        if (!$ticket_id) {
            return new \WP_Error('missing_param', 'ticket_id parameter required', ['status' => 400]);
        }
        
        $audit = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at, raw_json FROM {$wpdb->prefix}ais_audits WHERE ticket_id = %s",
            $ticket_id
        ));
        
        if (!$audit) {
            return ['ticket_id' => $ticket_id, 'error' => 'Ticket not found in audit table'];
        }
        
        return [
            'ticket_id' => $ticket_id,
            'created_at' => $audit->created_at,
            'shift_context' => 'Feature in development - Phase 2'
        ];
    }
    
    public function check_shift($request) {
        $data = $request->get_json_params();
        
        $agent_email = sanitize_email($data['agent_email'] ?? '');
        $check_datetime = sanitize_text_field($data['datetime'] ?? '');
        
        if (!$agent_email || !$check_datetime) {
            return new \WP_Error('missing_params', 'agent_email and datetime are required', ['status' => 400]);
        }
        
        // Validate datetime
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $check_datetime);
        if (!$dt) {
            $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s', $check_datetime);
        }
        if (!$dt) {
            try {
                $dt = new \DateTime($check_datetime);
            } catch (\Exception $e) {
                return new \WP_Error('invalid_datetime', 'Invalid datetime format', ['status' => 400]);
            }
        }
        
        $result = $this->shift_checker->check($agent_email, $check_datetime);
        $result['agent_email'] = $agent_email;
        $result['datetime'] = $check_datetime;
        
        return $result;
    }
    
    public function check_shifts_batch($request) {
        $data = $request->get_json_params();
        $checks = $data['checks'] ?? [];
        
        if (!is_array($checks)) {
            return new \WP_Error('invalid_checks', 'checks must be an array', ['status' => 400]);
        }
        
        if (empty($checks)) {
            return [
                'results' => [],
                'total_checked' => 0,
                'message' => 'No agent checks provided'
            ];
        }
        
        $results = $this->shift_checker->check_batch($checks);
        
        return [
            'results' => $results,
            'total_checked' => count($results)
        ];
    }
}