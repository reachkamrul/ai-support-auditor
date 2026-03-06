<?php
/**
 * Main Plugin Class
 * 
 * @package SupportOps
 */

namespace SupportOps;

class Plugin {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Database handler
     */
    private $database;
    
    /**
     * Admin handler
     */
    private $admin;
    
    /**
     * API handler
     */
    private $api;
    
    /**
     * AJAX handler
     */
    private $ajax;
    
    /**
     * Assets handler
     */
    private $assets;
    
    /**
     * Transcript builder
     */
    private $transcript;

    /**
     * Ticket watchdog
     */
    private $watchdog;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor (Singleton pattern)
     */
    private function __construct() {
        $this->init_components();
        $this->register_hooks();
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        $this->database = new Database\Manager();
        $this->admin = new Admin\Manager($this->database);
        $this->api = new API\Manager($this->database);
        $this->ajax = new AJAX\Manager($this->database);
        $this->assets = new Admin\Assets();
        $this->transcript = new Services\TranscriptBuilder($this->database);
        $this->watchdog = new Services\TicketWatchdog();
    }
    
    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Activation hook
        register_activation_hook(__FILE__, [$this->database, 'setup']);
        
        // Admin init for auto-repair
        add_action('admin_init', [$this->database, 'auto_repair']);
        
        // Initialize components
        add_action('init', [$this, 'init']);
        
        // REST API
        add_action('rest_api_init', [$this->api, 'register_routes']);
        
        // AJAX handlers
        add_action('wp_ajax_ai_audit_force', [$this->ajax, 'handle_force_audit']);
        add_action('wp_ajax_ai_audit_check_status', [$this->ajax, 'check_audit_status']);
        add_action('wp_ajax_ai_ops_save_single', [$this->ajax, 'save_single_shift']);
        add_action('wp_ajax_ai_ops_delete', [$this->ajax, 'delete_shift']);
        add_action('wp_ajax_ai_audit_test_system_message', [$this->ajax, 'test_system_message']);
        add_action('wp_ajax_ai_audit_check_test_status', [$this->ajax, 'check_test_status']);
        add_action('wp_ajax_ai_watchdog_sync', [$this->ajax, 'force_watchdog_sync']);
        add_action('wp_ajax_ai_audit_test_webhook', [$this->ajax, 'test_n8n_webhook']);

        // Calendar AJAX handlers
        add_action('wp_ajax_ai_ops_save_holiday', [$this->ajax, 'save_holiday']);
        add_action('wp_ajax_ai_ops_delete_holiday', [$this->ajax, 'delete_holiday']);
        add_action('wp_ajax_ai_ops_save_holiday_duty', [$this->ajax, 'save_holiday_duty']);
        add_action('wp_ajax_ai_ops_save_leave', [$this->ajax, 'save_leave']);
        add_action('wp_ajax_ai_ops_delete_leave', [$this->ajax, 'delete_leave']);
        add_action('wp_ajax_ai_ops_save_extra', [$this->ajax, 'save_extra']);
        add_action('wp_ajax_ai_ops_delete_extra', [$this->ajax, 'delete_extra']);
        add_action('wp_ajax_ai_ops_get_day_details', [$this->ajax, 'get_day_details']);
        add_action('admin_post_export_leave_csv', [$this->ajax, 'export_leave_csv']);

        // Audit Review AJAX handlers
        add_action('wp_ajax_ai_audit_save_review', [$this->ajax, 'save_audit_review']);
        add_action('wp_ajax_ai_audit_get_review', [$this->ajax, 'get_audit_review']);
        add_action('wp_ajax_ai_audit_save_override', [$this->ajax, 'save_score_override']);
        add_action('wp_ajax_ai_audit_request_override', [$this->ajax, 'request_override']);
        add_action('wp_ajax_ai_audit_resolve_override_request', [$this->ajax, 'resolve_override_request']);
        add_action('wp_ajax_ai_audit_get_override_requests', [$this->ajax, 'get_override_requests']);

        // Knowledge Base AJAX handlers
        add_action('wp_ajax_ai_kb_save_doc', [$this->ajax, 'kb_save_doc']);
        add_action('wp_ajax_ai_kb_delete_doc', [$this->ajax, 'kb_delete_doc']);
        add_action('wp_ajax_ai_kb_update_doc_status', [$this->ajax, 'kb_update_doc_status']);
        add_action('wp_ajax_ai_kb_get_docs', [$this->ajax, 'kb_get_docs']);
        add_action('wp_ajax_ai_kb_import_sitemap', [$this->ajax, 'kb_import_sitemap']);
        add_action('wp_ajax_ai_kb_sync_sitemap', [$this->ajax, 'kb_sync_sitemap']);
        add_action('wp_ajax_ai_kb_save_sitemap_url', [$this->ajax, 'kb_save_sitemap_url']);
        add_action('wp_ajax_ai_kb_remove_sitemap', [$this->ajax, 'kb_remove_sitemap']);

        // Admin post actions
        add_action('admin_post_export_agent_data', [$this->admin, 'export_agent_data']);

        // Ticket Watchdog cron
        add_action('ais_watchdog_scan', [$this->watchdog, 'scan']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

    }
    
    /**
     * Initialize plugin on WordPress init
     */
    public function init() {
        // Generate security token if not exists
        if (!get_option('ai_audit_secret_token')) {
            update_option('ai_audit_secret_token', wp_generate_password(32, true, true));
        }

        // Register team lead role
        $this->register_roles();

        // Schedule watchdog cron if not already scheduled
        if (!wp_next_scheduled('ais_watchdog_scan')) {
            wp_schedule_event(time(), 'every_fifteen_minutes', 'ais_watchdog_scan');
        }
    }

    /**
     * Register custom roles and capabilities
     */
    private function register_roles() {
        // Create the support_lead role if it doesn't exist
        if (!get_role('support_lead')) {
            add_role('support_lead', 'Support Team Lead', [
                'read' => true,
                'view_team_audits' => true,
            ]);
        }

        // Ensure administrators also have the capability
        $admin_role = get_role('administrator');
        if ($admin_role && !$admin_role->has_cap('view_team_audits')) {
            $admin_role->add_cap('view_team_audits');
        }
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 Minutes',
        ];
        return $schedules;
    }
    
    /**
     * Get database manager
     */
    public function get_database() {
        return $this->database;
    }
    
    /**
     * Get transcript builder
     */
    public function get_transcript_builder() {
        return $this->transcript;
    }
}