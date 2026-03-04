<?php
/**
 * AJAX Manager
 * 
 * @package SupportOps\AJAX
 */

namespace SupportOps\AJAX;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\AJAX\Handlers;

class Manager {
    
    /**
     * Database manager
     */
    private $database;
    
    /**
     * AJAX handlers
     */
    private $handlers = [];
    
    /**
     * Constructor
     */
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->init_handlers();
    }
    
    /**
     * Initialize AJAX handlers
     */
    private function init_handlers() {
        $this->handlers = [
            'audit' => new Handlers\AuditHandler($this->database),
            'shift' => new Handlers\ShiftHandler($this->database),
            'test' => new Handlers\TestHandler($this->database)
        ];
    }
    
    /**
     * Handle force audit request
     */
    public function handle_force_audit() {
        $this->handlers['audit']->force_audit();
    }
    
    /**
     * Check audit status
     */
    public function check_audit_status() {
        $this->handlers['audit']->check_status();
    }
    
    /**
     * Save single shift
     */
    public function save_single_shift() {
        $this->handlers['shift']->save_single();
    }
    
    /**
     * Delete shift
     */
    public function delete_shift() {
        $this->handlers['shift']->delete();
    }
    
    /**
     * Test system message
     */
    public function test_system_message() {
        $this->handlers['test']->test_system_message();
    }
    
    /**
     * Check test status
     */
    public function check_test_status() {
        $this->handlers['test']->check_test_status();
    }

    /**
     * Force watchdog sync
     */
    public function force_watchdog_sync() {
        check_ajax_referer('ai_ops_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $watchdog = new \SupportOps\Services\TicketWatchdog();
        $watchdog->scan();

        $snapshot = \SupportOps\Services\TicketWatchdog::get_snapshot();
        $orphan_count = $snapshot ? count($snapshot['orphaned_tickets']) : 0;

        wp_send_json_success([
            'orphan_count' => $orphan_count,
            'on_shift' => $snapshot['on_shift_count'] ?? 0,
            'total_open' => $snapshot['total_open'] ?? 0,
            'scanned_at' => $snapshot['scanned_at'] ?? '',
        ]);
    }
}