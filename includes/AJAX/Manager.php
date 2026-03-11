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
            'test' => new Handlers\TestHandler($this->database),
            'calendar' => new Handlers\CalendarHandler($this->database),
            'review' => new Handlers\AuditReviewHandler($this->database),
            'kb' => new Handlers\KnowledgeBaseHandler($this->database),
            'appeal' => new Handlers\AppealHandler($this->database),
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

    // ── Calendar handlers ──

    public function save_holiday() { $this->handlers['calendar']->save_holiday(); }
    public function delete_holiday() { $this->handlers['calendar']->delete_holiday(); }
    public function save_holiday_duty() { $this->handlers['calendar']->save_holiday_duty(); }
    public function save_leave() { $this->handlers['calendar']->save_leave(); }
    public function delete_leave() { $this->handlers['calendar']->delete_leave(); }
    public function resolve_leave() { $this->handlers['calendar']->resolve_leave(); }
    public function save_extra() { $this->handlers['calendar']->save_extra(); }
    public function delete_extra() { $this->handlers['calendar']->delete_extra(); }
    public function get_day_details() { $this->handlers['calendar']->get_day_details(); }
    public function export_leave_csv() { $this->handlers['calendar']->export_leave_csv(); }

    // ── Shift template handlers ──

    public function copy_week_shifts() { $this->handlers['shift']->copy_week(); }
    public function save_shift_template() { $this->handlers['shift']->save_template(); }
    public function apply_shift_template() { $this->handlers['shift']->apply_template(); }
    public function get_shift_templates() { $this->handlers['shift']->get_templates(); }
    public function delete_shift_template() { $this->handlers['shift']->delete_template(); }

    // ── Audit Review handlers ──

    public function save_audit_review() { $this->handlers['review']->save_review(); }
    public function get_audit_review() { $this->handlers['review']->get_review(); }
    public function save_score_override() { $this->handlers['review']->save_override(); }
    public function request_override() { $this->handlers['review']->request_override(); }
    public function resolve_override_request() { $this->handlers['review']->resolve_override_request(); }
    public function get_override_requests() { $this->handlers['review']->get_override_requests(); }

    // ── Knowledge Base handlers ──

    public function kb_save_doc() { $this->handlers['kb']->save_doc(); }
    public function kb_delete_doc() { $this->handlers['kb']->delete_doc(); }
    public function kb_update_doc_status() { $this->handlers['kb']->update_doc_status(); }
    public function kb_get_docs() { $this->handlers['kb']->get_docs(); }
    public function kb_import_sitemap() { $this->handlers['kb']->import_sitemap(); }
    public function kb_sync_sitemap() { $this->handlers['kb']->sync_sitemap(); }
    public function kb_save_sitemap_url() { $this->handlers['kb']->save_sitemap_url(); }
    public function kb_remove_sitemap() { $this->handlers['kb']->remove_sitemap(); }

    // ── Appeal handlers ──

    public function submit_appeal() { $this->handlers['appeal']->submit_appeal(); }
    public function get_my_appeals() { $this->handlers['appeal']->get_my_appeals(); }
    public function get_pending_appeals() { $this->handlers['appeal']->get_pending_appeals(); }
    public function resolve_appeal() { $this->handlers['appeal']->resolve_appeal(); }

    /**
     * Test N8N webhook connection
     */
    public function test_n8n_webhook() {
        check_ajax_referer('ai_ops_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $n8n_url = get_option('ai_audit_n8n_url', 'https://team.junior.ninja');
        $webhook_path = '/webhook/6d2250a7-1f9f-4c0b-b002-9ae1a95b2437';
        $webhook_url = rtrim($n8n_url, '/') . $webhook_path;

        $response = wp_remote_post($webhook_url, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'ticket' => [
                    'id' => 0,
                    'response_count' => 0,
                    'status' => 'test',
                ],
                'event' => 'connection_test',
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => $response->get_error_message(),
            ]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            wp_send_json_success([
                'http_code' => $code,
                'message' => 'N8N webhook is reachable and responding.',
            ]);
        } else {
            wp_send_json_error([
                'http_code' => $code,
                'message' => 'N8N responded with HTTP ' . $code,
                'body' => substr($body, 0, 200),
            ]);
        }
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