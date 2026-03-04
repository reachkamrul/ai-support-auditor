<?php
/**
 * Admin Manager
 * 
 * @package SupportOps\Admin
 */

namespace SupportOps\Admin;

use SupportOps\Database\Manager as DatabaseManager;

class Manager {
    
    /**
     * Database manager
     */
    private $database;
    
    /**
     * Page renderers
     */
    private $pages = [];
    
    /**
     * Constructor
     */
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->init_pages();
        $this->register_hooks();
    }
    
    /**
     * Initialize page renderers
     */
    private function init_pages() {
        $this->pages = [
            'dashboard' => new Pages\Dashboard($this->database),
            'agents' => new Pages\Agents($this->database),
            'agent_performance' => new Pages\AgentPerformance($this->database),
            'calendar' => new Pages\Calendar($this->database),
            'settings' => new Pages\Settings($this->database),
            'analytics' => new Pages\Analytics($this->database),
            'system_message' => new Pages\SystemMessage($this->database),
            'api_config' => new Pages\ApiConfig($this->database),
            'timing_settings' => new Pages\TimingSettings($this->database)
        ];
    }
    
    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_head', [$this, 'enqueue_assets']);
    }
    
    /**
     * Register admin menu
     */
    public function register_menu() {
        $icon_url = SUPPORT_OPS_PLUGIN_URL . 'assets/images/icon.svg';
        add_menu_page(
            'Support Ops & AI Auditor',
            'Support Ops',
            'manage_options',
            'ai-ops',
            [$this, 'render_main_page'],
            $icon_url,
            30
        );
        
        add_submenu_page(
            'ai-ops',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'ai-ops',
            [$this, 'render_main_page']
        );
        
        // Agent Performance is now integrated as a tab in the main page
        // Removed separate submenu item for consistency
    }
    
    /**
     * Enqueue assets
     */
    public function enqueue_assets() {
        $assets = new Assets();
        $assets->enqueue();
    }
    
    /**
     * Render main page
     */
    public function render_main_page() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'calendar';
        
        $logo_url = SUPPORT_OPS_PLUGIN_URL . 'assets/images/logo.svg?v=' . SUPPORT_OPS_VERSION;
        echo '<div class="wrap ops-wrapper">';
        echo '<div class="ops-header">';
        echo '<img src="' . esc_url($logo_url) . '" alt="Support Ops & AI Auditor Logo" class="ops-logo">';
        echo '<span style="display:inline-block;background:#0ea5e9;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:8px;vertical-align:middle;">v' . SUPPORT_OPS_VERSION . ' (deployed: 2 Mar 2026)</span>';
        $this->render_tabs($tab);
        echo '</div>';
        echo '<hr class="wp-header-end">';
        
        switch ($tab) {
            case 'audits':
                $this->pages['dashboard']->render();
                break;
            case 'analytics':
                $this->pages['analytics']->render();
                break;
            case 'calendar':
                $this->pages['calendar']->render();
                break;
            case 'agents':
                $this->pages['agents']->render();
                break;
            case 'agent-performance':
                $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'list';
                $agent_email = isset($_GET['agent']) ? sanitize_email($_GET['agent']) : '';
                if ($view === 'detail' && $agent_email) {
                    $this->pages['agent_performance']->render_detail($agent_email);
                } else {
                    $this->pages['agent_performance']->render_list();
                }
                break;
            case 'settings':
                $this->pages['settings']->render();
                break;
            case 'timing-penalties':
                $this->pages['timing_settings']->render();
                break;
            case 'system-message':
                $this->pages['system_message']->render();
                break;
            case 'api-config':
                $this->pages['api_config']->render();
                break;
        }
        
        echo '</div>';
    }
    
    /**
     * Render agents page
     */
    public function render_agents_page() {
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'list';
        $agent_email = isset($_GET['agent']) ? sanitize_email($_GET['agent']) : '';
        
        if ($view === 'detail' && $agent_email) {
            $this->pages['agent_performance']->render_detail($agent_email);
        } else {
            $this->pages['agent_performance']->render_list();
        }
    }
    
    /**
     * Render navigation tabs
     */
    private function render_tabs($current_tab) {
        $tabs = [
            'calendar' => 'Shift Calendar',
            'settings' => 'Shift Settings',
            'timing-penalties' => 'Timing Penalties',
            'audits' => 'AI Audits',
            'analytics' => 'Analytics',
            'agents' => 'Agents',
            'agent-performance' => 'Agent Performance',
            'system-message' => 'System Message',
            'api-config' => 'API Config'
        ];
        
        echo '<nav class="nav-tab-wrapper ops-nav-tabs">';
        foreach ($tabs as $tab => $label) {
            $active = ($tab === $current_tab) ? 'nav-tab-active' : '';
            $url = admin_url("admin.php?page=ai-ops&tab=$tab");
            echo "<a href='$url' class='nav-tab $active'>$label</a>";
        }
        echo '</nav>';
    }
    
    /**
     * Export agent data to CSV
     */
    public function export_agent_data() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        
        global $wpdb;
        
        $agent_email = isset($_GET['agent']) ? sanitize_email($_GET['agent']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');
        
        if ($agent_email) {
            $this->export_single_agent($agent_email, $date_from, $date_to);
        } else {
            $this->export_all_agents($date_from, $date_to);
        }
    }
    
    /**
     * Export single agent data
     */
    private function export_single_agent($agent_email, $date_from, $date_to) {
        global $wpdb;
        
        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT agent_name, agent_email FROM {$wpdb->prefix}ais_agent_evaluations 
            WHERE agent_email = %s LIMIT 1
        ", $agent_email));
        
        $tickets = $wpdb->get_results($wpdb->prepare("
            SELECT 
                ticket_id, created_at, overall_agent_score, timing_score, 
                resolution_score, communication_score, reply_count
            FROM {$wpdb->prefix}ais_agent_evaluations
            WHERE agent_email = %s AND DATE(created_at) BETWEEN %s AND %s
            ORDER BY created_at DESC
        ", $agent_email, $date_from, $date_to));
        
        $filename = 'agent_' . sanitize_file_name($summary->agent_name) . '_' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Ticket ID', 'Date', 'Overall Score', 'Timing', 'Resolution', 'Communication', 'Replies']);
        
        foreach ($tickets as $ticket) {
            fputcsv($output, [
                $ticket->ticket_id,
                $ticket->created_at,
                $ticket->overall_agent_score,
                $ticket->timing_score,
                $ticket->resolution_score,
                $ticket->communication_score,
                $ticket->reply_count
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Export all agents data
     */
    private function export_all_agents($date_from, $date_to) {
        global $wpdb;
        
        $agents = $wpdb->get_results($wpdb->prepare("
            SELECT 
                agent_email, agent_name,
                COUNT(DISTINCT ticket_id) as total_tickets,
                ROUND(AVG(overall_agent_score), 1) as avg_overall_score,
                ROUND(AVG(timing_score), 1) as avg_timing_score,
                ROUND(AVG(resolution_score), 1) as avg_resolution_score,
                ROUND(AVG(communication_score), 1) as avg_communication_score
            FROM {$wpdb->prefix}ais_agent_evaluations
            WHERE DATE(created_at) BETWEEN %s AND %s
            GROUP BY agent_email, agent_name
            ORDER BY avg_overall_score DESC
        ", $date_from, $date_to));
        
        $filename = 'all_agents_performance_' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Agent Name', 'Email', 'Total Tickets', 'Avg Overall Score', 'Avg Timing', 'Avg Resolution', 'Avg Communication']);
        
        foreach ($agents as $agent) {
            fputcsv($output, [
                $agent->agent_name,
                $agent->agent_email,
                $agent->total_tickets,
                $agent->avg_overall_score,
                $agent->avg_timing_score,
                $agent->avg_resolution_score,
                $agent->avg_communication_score
            ]);
        }
        
        fclose($output);
        exit;
    }
}