<?php
/**
 * Shift Processor Service
 * 
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Database\Manager as DatabaseManager;

class ShiftProcessor {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function process($post_data) {
        global $wpdb;
        
        $agent = sanitize_email($post_data['agent_email']);
        $defId = intval($post_data['shift_def_id']);
        
        $shift_defs_table = $this->database->get_table('shift_definitions');
        $def = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shift_defs_table} WHERE id = %d", 
            $defId
        ));
        
        if (!$def) {
            return false;
        }
        
        $dates = explode(' to ', $post_data['date_range']);
        $start = new \DateTime($dates[0]);
        $end = isset($dates[1]) ? new \DateTime($dates[1]) : clone $start;
        $end->modify('+1 day');
        
        $shifts_table = $this->database->get_table('agent_shifts');
        
        foreach(new \DatePeriod($start, new \DateInterval('P1D'), $end) as $dt) {
            $ds = $dt->format('Y-m-d');
            
            // Delete existing shifts for this day
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$shifts_table} 
                 WHERE agent_email=%s AND shift_start LIKE %s", 
                $agent, 
                $ds.'%'
            ));
            
            // Calculate end date (handle overnight shifts)
            $s = $ds . ' ' . $def->start_time;
            $e_dt = clone $dt;
            if($def->end_time < $def->start_time) {
                $e_dt->modify('+1 day');
            }
            
            // Insert new shift
            $wpdb->insert($shifts_table, [
                'agent_email' => $agent,
                'shift_def_id' => $def->id,
                'shift_start' => $s,
                'shift_end' => $e_dt->format('Y-m-d').' '.$def->end_time,
                'shift_type' => $def->name,
                'shift_color' => $def->color
            ]);
        }
        
        return true;
    }
}