<?php
/**
 * Shift Checker Service
 * 
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Database\Manager as DatabaseManager;

class ShiftChecker {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function check($agent_email, $datetime) {
        global $wpdb;
        
        $shifts_table = $this->database->get_table('agent_shifts');
        
        $shift = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shifts_table} 
             WHERE agent_email = %s 
             AND shift_start <= %s 
             AND shift_end >= %s 
             ORDER BY shift_start DESC 
             LIMIT 1",
            $agent_email,
            $datetime,
            $datetime
        ));
        
        $dt = new \DateTime($datetime);
        $day_of_week = (int)$dt->format('w');
        $is_weekend = ($day_of_week == 0 || $day_of_week == 6);
        
        return [
            'was_on_shift' => !empty($shift),
            'is_weekend' => $is_weekend,
            'day_of_week' => $dt->format('l'),
            'shift_info' => $shift ? [
                'shift_type' => $shift->shift_type,
                'shift_start' => $shift->shift_start,
                'shift_end' => $shift->shift_end
            ] : null
        ];
    }
    
    public function check_batch($checks) {
        $results = [];
        
        foreach ($checks as $check) {
            $agent_email = sanitize_email($check['agent_email'] ?? '');
            $datetime = sanitize_text_field($check['datetime'] ?? '');
            
            if (empty($agent_email) || empty($datetime)) {
                $results[] = [
                    'agent_email' => $agent_email,
                    'datetime' => $datetime,
                    'error' => 'Missing agent_email or datetime'
                ];
                continue;
            }
            
            $result = $this->check($agent_email, $datetime);
            $result['agent_email'] = $agent_email;
            $result['datetime'] = $datetime;
            
            $results[] = $result;
        }
        
        return $results;
    }
}