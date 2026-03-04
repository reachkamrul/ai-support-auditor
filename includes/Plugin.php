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

        // Schedule watchdog cron if not already scheduled
        if (!wp_next_scheduled('ais_watchdog_scan')) {
            wp_schedule_event(time(), 'every_fifteen_minutes', 'ais_watchdog_scan');
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