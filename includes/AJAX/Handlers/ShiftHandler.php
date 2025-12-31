<?php
/**
 * Shift AJAX Handler
 * 
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Services\ShiftProcessor;

class ShiftHandler {
    
    private $database;
    private $shift_processor;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->shift_processor = new ShiftProcessor($database);
    }
    
    public function save_single() {
        // Convert single date to date range format
        $_POST['date_range'] = $_POST['date'] ?? '';
        
        $result = $this->shift_processor->process($_POST);
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to save shift');
        }
    }
    
    public function delete() {
        global $wpdb;
        
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            wp_die('Invalid shift ID');
        }
        
        $wpdb->delete(
            $this->database->get_table('agent_shifts'),
            ['id' => $id]
        );
        
        wp_die();
    }
}
