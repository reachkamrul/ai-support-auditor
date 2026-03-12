<?php
/**
 * Admin Manager — Sidebar Navigation
 *
 * @package SupportOps\Admin
 */

namespace SupportOps\Admin;

use SupportOps\Database\Manager as DatabaseManager;

class Manager {

    private $database;
    private $pages = [];

    /**
     * Sidebar navigation structure
     */
    private $nav_sections = [
        'MY PORTAL' => [
            'my-performance'   => ['label' => 'My Performance',   'icon' => 'bar-chart'],
            'my-schedule'      => ['label' => 'My Schedule',      'icon' => 'calendar'],
        ],
        'OVERVIEW' => [
            'dashboard'        => ['label' => 'Dashboard',        'icon' => 'grid'],
            'flagged'          => ['label' => 'Flagged Tickets',  'icon' => 'flag',    'badge' => true],
        ],
        'TEAM' => [
            'agents'           => ['label' => 'Agents',           'icon' => 'users'],
            'teams'            => ['label' => 'Teams & Products', 'icon' => 'layers'],
            'calendar'         => ['label' => 'Shift Calendar',   'icon' => 'calendar', 'badge' => 'leaves'],
            'shift-settings'   => ['label' => 'Shift Settings',   'icon' => 'clock'],
            'handoffs'         => ['label' => 'Handoff Report',   'icon' => 'repeat'],
        ],
        'AUDITS' => [
            'audit-queue'      => ['label' => 'Audit Queue',      'icon' => 'loader', 'badge' => 'queue'],
            'audits'           => ['label' => 'All Audits',       'icon' => 'clipboard'],
            'agent-reports'    => ['label' => 'Agent Reports',    'icon' => 'bar-chart'],
            'compare'          => ['label' => 'Compare',           'icon' => 'git-compare'],
            'sla'              => ['label' => 'SLA Dashboard',    'icon' => 'shield'],
            'analytics'        => ['label' => 'Analytics',        'icon' => 'trending-up'],
            'time-machine'     => ['label' => 'Time Machine',    'icon' => 'activity'],
        ],
        'KNOWLEDGE' => [
            'doc-gaps'         => ['label' => 'Doc Gaps',          'icon' => 'alert-triangle'],
            'faq-topics'       => ['label' => 'FAQ Topics',        'icon' => 'help-circle'],
        ],
        'SETTINGS' => [
            'timing-penalties' => ['label' => 'Timing Penalties', 'icon' => 'sliders'],
            'system-message'   => ['label' => 'System Message',   'icon' => 'message-square'],
            'knowledge-base'   => ['label' => 'Knowledge Base',   'icon' => 'book'],
            'live-audit'       => ['label' => 'Live Audit',       'icon' => 'zap'],
            'api-config'       => ['label' => 'API Config',       'icon' => 'key'],
        ],
    ];

    /**
     * Conditionally add reset nav item if AUDIT_RESET is enabled
     */
    private function maybe_add_reset_nav() {
        if (Pages\Reset::is_enabled()) {
            $this->nav_sections['DANGER ZONE'] = [
                'reset' => ['label' => 'Reset Database', 'icon' => 'alert-triangle'],
            ];
        }
    }

    /**
     * Map old tab= params to new section= params for backward compatibility
     */
    private $tab_to_section = [
        'calendar'         => 'calendar',
        'settings'         => 'shift-settings',
        'timing-penalties' => 'timing-penalties',
        'audits'           => 'audits',
        'analytics'        => 'analytics',
        'agents'           => 'agents',
        'agent-performance'=> 'agent-reports',
        'system-message'   => 'system-message',
        'api-config'       => 'api-config',
    ];

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->maybe_add_reset_nav();
        $this->init_pages();
        $this->register_hooks();
    }

    private function init_pages() {
        $this->pages = [
            'dashboard'        => new Pages\Dashboard($this->database),
            'all_audits'       => new Pages\AllAudits($this->database),
            'agents'           => new Pages\Agents($this->database),
            'agent_performance'=> new Pages\AgentPerformance($this->database),
            'calendar'         => new Pages\Calendar($this->database),
            'settings'         => new Pages\Settings($this->database),
            'analytics'        => new Pages\Analytics($this->database),
            'system_message'   => new Pages\SystemMessage($this->database),
            'api_config'       => new Pages\ApiConfig($this->database),
            'timing_settings'  => new Pages\TimingSettings($this->database),
            'flagged_tickets'  => new Pages\FlaggedTickets($this->database),
            'teams'            => new Pages\Teams($this->database),
            'handoff_report'   => new Pages\HandoffReport($this->database),
            'knowledge_base'   => new Pages\KnowledgeBase($this->database),
            'live_audit'       => new Pages\LiveAuditSettings($this->database),
            'my_performance'   => new Pages\MyPerformance($this->database),
            'my_schedule'      => new Pages\MySchedule($this->database),
            'compare'          => new Pages\CompareBenchmark($this->database),
            'sla'              => new Pages\SlaDashboard($this->database),
            'doc_gaps'         => new Pages\DocGaps($this->database),
            'faq_topics'       => new Pages\FaqTopics($this->database),
            'audit_queue'      => new Pages\AuditQueue($this->database),
            'time_machine'     => new Pages\TimeMachine($this->database),
            'reset'            => new Pages\Reset($this->database),
        ];
    }

    private function register_hooks() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_head', [$this, 'enqueue_assets']);
    }

    public function register_menu() {
        // Use view_own_audits as minimum capability (agents, leads, admins all have it)
        $min_cap = current_user_can('view_team_audits') ? 'view_team_audits' : 'view_own_audits';
        $icon_url = SUPPORT_OPS_PLUGIN_URL . 'assets/images/icon.svg';
        add_menu_page(
            'Support Ops & AI Auditor',
            'Support Ops',
            $min_cap,
            'ai-ops',
            [$this, 'render_main_page'],
            $icon_url,
            30
        );

        add_submenu_page(
            'ai-ops',
            'Dashboard',
            'Dashboard',
            $min_cap,
            'ai-ops',
            [$this, 'render_main_page']
        );
    }

    public function enqueue_assets() {
        $assets = new Assets();
        $assets->enqueue();
    }

    /**
     * Resolve current section from URL params
     */
    private function get_current_section() {
        // Support new section= param
        if (!empty($_GET['section'])) {
            return sanitize_text_field($_GET['section']);
        }
        // Backward compat: map old tab= param
        if (!empty($_GET['tab'])) {
            $tab = sanitize_text_field($_GET['tab']);
            return $this->tab_to_section[$tab] ?? 'dashboard';
        }
        // Agents default to their portal
        if (AccessControl::is_agent()) {
            return 'my-performance';
        }
        return 'dashboard';
    }

    /**
     * Render main page with sidebar layout
     */
    public function render_main_page() {
        $section = $this->get_current_section();

        echo '<div class="ops-layout">';
        $this->render_sidebar($section);
        echo '<div class="ops-main-content">';
        $this->render_section($section);
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render sidebar navigation
     */
    private function render_sidebar($current_section) {
        $logo_url = SUPPORT_OPS_PLUGIN_URL . 'assets/images/logo.svg?v=' . SUPPORT_OPS_VERSION;
        $flagged_count = $this->get_flagged_count();
        $queue_count = $this->get_queue_count();
        $pending_requests_count = AccessControl::is_admin() ? $this->get_pending_requests_count() : 0;
        $pending_appeals_count = $this->get_pending_appeals_count();
        $pending_leaves_count = (AccessControl::is_admin() || AccessControl::is_lead()) ? $this->get_pending_leaves_count() : 0;

        echo '<aside class="ops-sidebar">';

        // Header with logo
        echo '<div class="ops-sidebar-header">';
        echo '<img src="' . esc_url($logo_url) . '" alt="Support Ops" class="ops-sidebar-logo">';
        echo '<span class="ops-sidebar-version">v' . SUPPORT_OPS_VERSION . '</span>';

        // Show team badge for leads
        if (AccessControl::is_lead()) {
            $team_names = AccessControl::get_team_names();
            if (!empty($team_names)) {
                echo '<span class="ops-sidebar-team-badge">' . esc_html(implode(', ', $team_names)) . '</span>';
            }
        }

        echo '</div>';

        // Navigation
        echo '<nav class="ops-sidebar-nav">';

        foreach ($this->nav_sections as $section_label => $items) {
            // Check if any items in this section are accessible
            $visible_items = array_filter($items, function ($key) {
                return AccessControl::can_access($key);
            }, ARRAY_FILTER_USE_KEY);

            if (empty($visible_items)) {
                continue; // Skip entire section if no items are visible
            }

            echo '<div class="ops-nav-section">' . esc_html($section_label) . '</div>';

            foreach ($items as $key => $item) {
                if (!AccessControl::can_access($key)) {
                    continue;
                }

                $active = ($key === $current_section) ? ' active' : '';
                $url = admin_url('admin.php?page=ai-ops&section=' . $key);

                echo '<a href="' . esc_url($url) . '" class="ops-nav-item' . $active . '">';
                echo '<span class="ops-nav-icon">' . $this->get_icon($item['icon']) . '</span>';
                echo '<span>' . esc_html($item['label']) . '</span>';

                // Badge counts
                if ($key === 'flagged') {
                    $total_flagged = $flagged_count + $pending_requests_count + $pending_appeals_count;
                    if ($total_flagged > 0) {
                        echo '<span class="ops-nav-badge">' . intval($total_flagged) . '</span>';
                    }
                } elseif ($key === 'audit-queue' && $queue_count > 0) {
                    echo '<span class="ops-nav-badge">' . intval($queue_count) . '</span>';
                } elseif ($key === 'calendar' && $pending_leaves_count > 0) {
                    echo '<span class="ops-nav-badge">' . intval($pending_leaves_count) . '</span>';
                }

                echo '</a>';
            }
        }

        echo '</nav>';

        // Footer
        echo '<div class="ops-sidebar-footer">Support Ops & AI Auditor</div>';

        echo '</aside>';
    }

    /**
     * Render the active section content
     */
    private function render_section($section) {
        // Block access to restricted sections
        if (!AccessControl::can_access($section)) {
            $section = AccessControl::is_agent() ? 'my-performance' : 'dashboard';
        }

        switch ($section) {
            case 'my-performance':
                $this->render_page_header('My Performance', 'Your personal audit scores and trends');
                $this->pages['my_performance']->render();
                break;

            case 'my-schedule':
                $this->render_page_header('My Schedule', 'Your shifts, leaves, and holidays');
                $this->pages['my_schedule']->render();
                break;

            case 'dashboard':
                $this->render_page_header('Dashboard', 'Overview of your support operations');
                $this->pages['dashboard']->render();
                break;

            case 'flagged':
                $this->render_page_header('Flagged Tickets', 'Tickets that need your attention');
                $this->pages['flagged_tickets']->render();
                break;

            case 'agents':
                $this->render_page_header('Agents', 'Manage your support team agents');
                $this->pages['agents']->render();
                break;

            case 'teams':
                $this->render_page_header('Teams & Products', 'Organize agents into teams and map products');
                $this->pages['teams']->render();
                break;

            case 'calendar':
                $this->render_page_header('Shift Calendar', 'Visual monthly shift schedule');
                $this->pages['calendar']->render();
                break;

            case 'shift-settings':
                $this->render_page_header('Shift Settings', 'Define shift types and time ranges');
                $this->pages['settings']->render();
                break;

            case 'handoffs':
                $this->render_page_header('Handoff Report', 'Track agent shift-end handoff compliance');
                $this->pages['handoff_report']->render();
                break;

            case 'audit-queue':
                $this->render_page_header('Audit Queue', 'Monitor and manage the AI processing pipeline');
                $this->pages['audit_queue']->render();
                break;

            case 'audits':
                $this->render_page_header('All Audits', 'Review AI audit results for all tickets');
                $this->pages['all_audits']->render();
                break;

            case 'agent-reports':
                $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'list';
                $agent_email = isset($_GET['agent']) ? sanitize_email($_GET['agent']) : '';
                if ($view === 'detail' && $agent_email) {
                    $this->pages['agent_performance']->render_detail($agent_email);
                } else {
                    $this->render_page_header('Agent Reports', 'Individual agent performance scores');
                    $this->pages['agent_performance']->render_list();
                }
                break;

            case 'compare':
                $this->render_page_header('Compare & Benchmark', 'Side-by-side performance comparison');
                $this->pages['compare']->render();
                break;

            case 'sla':
                $this->render_page_header('SLA Dashboard', 'Response time analytics and SLA breach tracking');
                $this->pages['sla']->render();
                break;

            case 'analytics':
                $this->render_page_header('Analytics', 'Team-wide analytics and trends');
                $this->pages['analytics']->render();
                break;

            case 'time-machine':
                $this->render_page_header('Time Machine', 'Go back to any date and see a full support operations snapshot');
                $this->pages['time_machine']->render();
                break;

            case 'doc-gaps':
                $this->render_page_header('Documentation Gaps', 'AI-identified knowledge base gaps that need documentation');
                $this->pages['doc_gaps']->render();
                break;

            case 'faq-topics':
                $this->render_page_header('FAQ Topics', 'AI-suggested FAQ articles based on recurring questions');
                $this->pages['faq_topics']->render();
                break;

            case 'timing-penalties':
                $this->render_page_header('Timing Penalties', 'Configure delay rules and tag exclusions');
                $this->pages['timing_settings']->render();
                break;

            case 'system-message':
                $this->render_page_header('System Message', 'Edit the AI prompt for auditing');
                $this->pages['system_message']->render();
                break;

            case 'knowledge-base':
                $this->render_page_header('Knowledge Base', 'Register documentation URLs and analyze coverage gaps');
                $this->pages['knowledge_base']->render();
                break;

            case 'live-audit':
                $this->render_page_header('Live Audit', 'Configure real-time auditing on agent responses');
                $this->pages['live_audit']->render();
                break;

            case 'api-config':
                $this->render_page_header('API Config', 'Security tokens and endpoint reference');
                $this->pages['api_config']->render();
                break;

            case 'reset':
                $this->render_page_header('Reset Database', 'Drop all plugin tables and options');
                $this->pages['reset']->render();
                break;

            default:
                $this->render_page_header('Dashboard', 'Overview of your support operations');
                $this->pages['dashboard']->render();
                break;
        }
    }

    /**
     * Render page header with title and subtitle
     */
    private function render_page_header($title, $subtitle = '') {
        echo '<div class="ops-page-header">';
        echo '<div>';
        echo '<h1 class="ops-page-title">' . esc_html($title) . '</h1>';
        if ($subtitle) {
            echo '<p class="ops-page-subtitle">' . esc_html($subtitle) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render coming soon placeholder for Phase 2/3 features
     */
    private function render_coming_soon($feature, $description) {
        echo '<div class="ops-card" style="text-align:center;padding:60px 40px;">';
        echo '<div style="font-size:48px;margin-bottom:16px;opacity:0.3;">&#128736;</div>';
        echo '<h3 style="font-size:20px;margin-bottom:8px;">' . esc_html($feature) . '</h3>';
        echo '<p style="color:var(--color-text-secondary);max-width:400px;margin:0 auto;">' . esc_html($description) . '</p>';
        echo '<span class="status-badge pending" style="margin-top:16px;">Coming Soon</span>';
        echo '</div>';
    }

    /**
     * Get count of unreviewed flagged tickets
     */
    private function get_flagged_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_flagged_tickets';
        // Table may not exist yet (Phase 2)
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$exists) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'needs_review'");
    }

    private function get_queue_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending', 'processing')");
    }

    private function get_pending_requests_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_override_requests';
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$exists) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
    }

    private function get_pending_leaves_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_agent_leaves';
        $team_filter = AccessControl::sql_agent_filter('agent_email');
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending' {$team_filter}");
    }

    private function get_pending_appeals_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audit_appeals';
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$exists) {
            return 0;
        }
        $team_filter = AccessControl::sql_agent_filter('agent_email');
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending' {$team_filter}");
    }

    /**
     * SVG icons for sidebar navigation
     */
    private function get_icon($name) {
        $icons = [
            'grid' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
            'flag' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
            'users' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'layers' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
            'calendar' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'clock' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'clipboard' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>',
            'bar-chart' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>',
            'trending-up' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
            'sliders' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
            'message-square' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
            'book' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            'key' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
            'repeat' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
            'zap' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            'shield' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            'git-compare' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M13 6h3a2 2 0 0 1 2 2v7"/><path d="M11 18H8a2 2 0 0 1-2-2V9"/></svg>',
            'activity' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            'loader' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>',
            'alert-triangle' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'help-circle' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        ];

        return $icons[$name] ?? '';
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
