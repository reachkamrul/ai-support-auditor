<?php
/**
 * Time Machine AJAX Handler
 *
 * Fetches historical snapshot data from audit tables + FluentSupport.
 *
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class TimeMachineHandler {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    /**
     * Main handler — returns full snapshot for a date/range
     */
    public function load_snapshot() {
        check_ajax_referer('ai_ops_nonce', 'nonce');

        if (!current_user_can('view_team_audits') && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? $date_from);

        if (!$date_from || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            wp_send_json_error('Invalid date');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $date_to = $date_from;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;
        $team_filter = AccessControl::sql_agent_filter('ae.agent_email');
        $team_filter_pc = AccessControl::sql_agent_filter('pc.responsible_agent');

        $data = [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'kpis'      => $this->get_kpis($date_from, $date_to, $team_filter),
            'agents'    => $this->get_agent_performance($date_from, $date_to, $team_filter),
            'problems'  => $this->get_problem_breakdown($date_from, $date_to, $team_filter_pc),
            'shifts'    => $this->get_shift_coverage($date_from, $date_to),
            'daily'     => $this->get_daily_breakdown($date_from, $date_to, $team_filter),
            'worst_tickets' => $this->get_worst_tickets($date_from, $date_to, $team_filter),
            'fluent'    => $this->get_fluent_data($date_from, $date_to),
        ];

        wp_send_json_success($data);
    }

    /**
     * KPI summary cards
     */
    private function get_kpis($date_from, $date_to, $team_filter) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Audited ticket count
        $ticket_filter = '';
        if ($team_filter) {
            $ticket_filter = " AND a.ticket_id IN (SELECT DISTINCT ticket_id FROM {$p}ais_agent_evaluations ae WHERE 1=1 {$team_filter})";
        }

        $audited = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ais_audits a
             WHERE a.status = 'success' AND DATE(a.created_at) BETWEEN %s AND %s {$ticket_filter}",
            $date_from, $date_to
        ));

        $avg_audit = $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(AVG(a.overall_score), 1) FROM {$p}ais_audits a
             WHERE a.status = 'success' AND a.exclude_from_stats = 0 AND DATE(a.created_at) BETWEEN %s AND %s {$ticket_filter}",
            $date_from, $date_to
        ));

        // Agent evaluation stats
        $eval_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as total,
                    ROUND(AVG(ae.overall_agent_score), 1) as avg_score,
                    COUNT(DISTINCT ae.agent_email) as active_agents
             FROM {$p}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) BETWEEN %s AND %s AND ae.exclude_from_stats = 0 {$team_filter}",
            $date_from, $date_to
        ));

        // Problem count
        $team_filter_pc = AccessControl::sql_agent_filter('pc.responsible_agent');
        $problems = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ais_problem_contexts pc
             WHERE DATE(pc.created_at) BETWEEN %s AND %s {$team_filter_pc}",
            $date_from, $date_to
        ));

        return [
            'audited_tickets'   => $audited,
            'avg_audit_score'   => $avg_audit,
            'total_evaluations' => (int) ($eval_stats->total ?? 0),
            'avg_agent_score'   => $eval_stats->avg_score ?? null,
            'active_agents'     => (int) ($eval_stats->active_agents ?? 0),
            'problems_found'    => $problems,
        ];
    }

    /**
     * Per-agent performance table
     */
    private function get_agent_performance($date_from, $date_to, $team_filter) {
        global $wpdb;
        $p = $wpdb->prefix;

        $agents = $wpdb->get_results($wpdb->prepare(
            "SELECT ae.agent_email, ae.agent_name,
                    COUNT(DISTINCT ae.ticket_id) as ticket_count,
                    ROUND(AVG(ae.overall_agent_score), 1) as avg_overall,
                    ROUND(AVG(ae.timing_score), 1) as avg_timing,
                    ROUND(AVG(ae.resolution_score), 1) as avg_resolution,
                    ROUND(AVG(ae.communication_score), 1) as avg_communication,
                    SUM(ae.reply_count) as total_replies
             FROM {$p}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) BETWEEN %s AND %s AND ae.exclude_from_stats = 0 {$team_filter}
             GROUP BY ae.agent_email, ae.agent_name
             ORDER BY avg_overall DESC",
            $date_from, $date_to
        ));

        // Get problem counts per agent
        $team_filter_pc = AccessControl::sql_agent_filter('pc.responsible_agent');
        $problem_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT pc.responsible_agent as agent_email, COUNT(*) as cnt
             FROM {$p}ais_problem_contexts pc
             WHERE DATE(pc.created_at) BETWEEN %s AND %s AND pc.responsible_agent IS NOT NULL {$team_filter_pc}
             GROUP BY pc.responsible_agent",
            $date_from, $date_to
        ));
        $pc_map = [];
        foreach ($problem_counts as $pc) {
            $pc_map[$pc->agent_email] = (int) $pc->cnt;
        }

        $result = [];
        foreach ($agents as $a) {
            $result[] = [
                'agent_email'      => $a->agent_email,
                'agent_name'       => $a->agent_name,
                'ticket_count'     => (int) $a->ticket_count,
                'avg_overall'      => $a->avg_overall,
                'avg_timing'       => $a->avg_timing,
                'avg_resolution'   => $a->avg_resolution,
                'avg_communication'=> $a->avg_communication,
                'total_replies'    => (int) $a->total_replies,
                'problem_count'    => $pc_map[$a->agent_email] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Problem categories breakdown
     */
    private function get_problem_breakdown($date_from, $date_to, $team_filter_pc) {
        global $wpdb;
        $p = $wpdb->prefix;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT pc.category, COUNT(*) as count
             FROM {$p}ais_problem_contexts pc
             WHERE DATE(pc.created_at) BETWEEN %s AND %s {$team_filter_pc}
             GROUP BY pc.category
             ORDER BY count DESC",
            $date_from, $date_to
        ));
    }

    /**
     * Shift coverage for the date(s)
     */
    private function get_shift_coverage($date_from, $date_to) {
        global $wpdb;
        $p = $wpdb->prefix;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.shift_type, COUNT(DISTINCT s.agent_email) as agent_count
             FROM {$p}ais_agent_shifts s
             WHERE DATE(s.shift_start) BETWEEN %s AND %s
             GROUP BY s.shift_type
             ORDER BY s.shift_type",
            $date_from, $date_to
        ));
    }

    /**
     * Daily breakdown (for multi-day ranges)
     */
    private function get_daily_breakdown($date_from, $date_to, $team_filter) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Only return daily breakdown for ranges > 1 day
        if ($date_from === $date_to) {
            return [];
        }

        $ticket_filter = '';
        if ($team_filter) {
            $ticket_filter = " AND a.ticket_id IN (SELECT DISTINCT ticket_id FROM {$p}ais_agent_evaluations ae WHERE 1=1 {$team_filter})";
        }

        $audits_daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(a.created_at) as day,
                    COUNT(*) as audits,
                    ROUND(AVG(a.overall_score), 1) as avg_score
             FROM {$p}ais_audits a
             WHERE a.status = 'success' AND a.exclude_from_stats = 0 AND DATE(a.created_at) BETWEEN %s AND %s {$ticket_filter}
             GROUP BY DATE(a.created_at)
             ORDER BY day ASC",
            $date_from, $date_to
        ));

        // Problems per day
        $team_filter_pc = AccessControl::sql_agent_filter('pc.responsible_agent');
        $problems_daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(pc.created_at) as day, COUNT(*) as cnt
             FROM {$p}ais_problem_contexts pc
             WHERE DATE(pc.created_at) BETWEEN %s AND %s {$team_filter_pc}
             GROUP BY DATE(pc.created_at)",
            $date_from, $date_to
        ));
        $pd_map = [];
        foreach ($problems_daily as $pd) {
            $pd_map[$pd->day] = (int) $pd->cnt;
        }

        // Active agents per day
        $agents_daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(ae.created_at) as day, COUNT(DISTINCT ae.agent_email) as cnt
             FROM {$p}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) BETWEEN %s AND %s {$team_filter}
             GROUP BY DATE(ae.created_at)",
            $date_from, $date_to
        ));
        $ad_map = [];
        foreach ($agents_daily as $ad) {
            $ad_map[$ad->day] = (int) $ad->cnt;
        }

        $result = [];
        foreach ($audits_daily as $row) {
            $result[] = [
                'date'          => $row->day,
                'audits'        => (int) $row->audits,
                'avg_score'     => $row->avg_score,
                'problems'      => $pd_map[$row->day] ?? 0,
                'active_agents' => $ad_map[$row->day] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Worst scoring tickets
     */
    private function get_worst_tickets($date_from, $date_to, $team_filter) {
        global $wpdb;
        $p = $wpdb->prefix;

        $ticket_filter = '';
        if ($team_filter) {
            $ticket_filter = " AND a.ticket_id IN (SELECT DISTINCT ticket_id FROM {$p}ais_agent_evaluations ae WHERE 1=1 {$team_filter})";
        }

        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT a.ticket_id, a.overall_score, a.overall_sentiment
             FROM {$p}ais_audits a
             WHERE a.status = 'success' AND DATE(a.created_at) BETWEEN %s AND %s {$ticket_filter}
             ORDER BY a.overall_score ASC
             LIMIT 10",
            $date_from, $date_to
        ));

        $result = [];
        foreach ($tickets as $t) {
            // Get agent names for this ticket
            $agents = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT agent_name FROM {$p}ais_agent_evaluations WHERE ticket_id = %s",
                $t->ticket_id
            ));
            $result[] = [
                'ticket_id' => $t->ticket_id,
                'score'     => (int) $t->overall_score,
                'sentiment' => $t->overall_sentiment,
                'agents'    => implode(', ', $agents),
            ];
        }

        return $result;
    }

    /**
     * FluentSupport data via REST API
     */
    private function get_fluent_data($date_from, $date_to) {
        $base_url = get_option('ai_audit_fs_api_url', '');
        $user     = get_option('ai_audit_fs_api_user', '');
        $pass     = get_option('ai_audit_fs_api_pass', '');

        if (!$base_url || !$user || !$pass) {
            return ['available' => false, 'reason' => 'FluentSupport API not configured'];
        }

        try {
            $data = ['available' => true];

            // -- Ticket growth (new tickets per day in range) --
            $growth = $this->fs_api_get($base_url, $user, $pass, '/reports/tickets-growth', [
                'date_range' => [$date_from, $date_to],
            ]);
            $data['new_tickets'] = 0;
            if ($growth && isset($growth['stats'])) {
                foreach ($growth['stats'] as $day) {
                    $data['new_tickets'] += (int) ($day['count'] ?? $day['total'] ?? 0);
                }
            }

            // -- Closed tickets (resolve growth) --
            $resolved = $this->fs_api_get($base_url, $user, $pass, '/reports/tickets-resolve-growth', [
                'date_range' => [$date_from, $date_to],
            ]);
            $data['closed_tickets'] = 0;
            if ($resolved && isset($resolved['stats'])) {
                foreach ($resolved['stats'] as $day) {
                    $data['closed_tickets'] += (int) ($day['count'] ?? $day['total'] ?? 0);
                }
            }

            // -- Response growth (total responses in range) --
            $responses = $this->fs_api_get($base_url, $user, $pass, '/reports/response-growth', [
                'date_range' => [$date_from, $date_to],
            ]);
            $data['total_responses'] = 0;
            if ($responses && isset($responses['stats'])) {
                foreach ($responses['stats'] as $day) {
                    $data['total_responses'] += (int) ($day['count'] ?? $day['total'] ?? 0);
                }
            }

            // -- Active (open) tickets: query with status filter, per_page=1 to just get total --
            $active = $this->fs_api_get($base_url, $user, $pass, '/tickets', [
                'per_page'    => 1,
                'filter_type' => 'simple',
                'filters'     => ['status_type' => 'open'],
            ]);
            $data['active_tickets'] = (int) ($active['tickets']['total'] ?? 0);

            // -- Unassigned tickets --
            $unassigned = $this->fs_api_get($base_url, $user, $pass, '/tickets', [
                'per_page'    => 1,
                'filter_type' => 'simple',
                'filters'     => ['status_type' => 'open'],
                'advanced_filters' => wp_json_encode([
                    ['source' => ['tickets', 'agent_id'], 'operator' => 'is_null', 'value' => ''],
                ]),
            ]);
            $data['unassigned_tickets'] = (int) ($unassigned['tickets']['total'] ?? 0);

            // -- Awaiting reply (customer waiting) --
            $awaiting = $this->fs_api_get($base_url, $user, $pass, '/tickets', [
                'per_page'    => 1,
                'filter_type' => 'simple',
                'filters'     => ['status_type' => 'open', 'waiting_for_reply' => 'yes'],
            ]);
            $data['awaiting_reply'] = (int) ($awaiting['tickets']['total'] ?? 0);

            // -- Agent summary (responses, interactions, closed per agent) --
            $agents_summary = $this->fs_api_get($base_url, $user, $pass, '/reports/agents-summary', [
                'from' => $date_from,
                'to'   => $date_to,
            ]);
            $data['agent_responses'] = [];
            if ($agents_summary && is_array($agents_summary)) {
                // The agents-summary endpoint returns array of agent stats
                $agent_list = $agents_summary['agents'] ?? $agents_summary;
                if (is_array($agent_list)) {
                    foreach ($agent_list as $agent) {
                        if (!is_array($agent)) continue;
                        $data['agent_responses'][] = [
                            'name'         => trim(($agent['first_name'] ?? '') . ' ' . ($agent['last_name'] ?? '')),
                            'email'        => $agent['email'] ?? '',
                            'responses'    => (int) ($agent['total_responses'] ?? $agent['responses'] ?? 0),
                            'interactions' => (int) ($agent['total_interactions'] ?? $agent['interactions'] ?? 0),
                            'closed'       => (int) ($agent['closed_tickets'] ?? $agent['closed'] ?? 0),
                        ];
                    }
                }
            }

            return $data;

        } catch (\Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test FluentSupport API connection
     */
    public function test_fs_api() {
        check_ajax_referer('ai_ops_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $url  = sanitize_text_field($_POST['url'] ?? '');
        $user = sanitize_text_field($_POST['user'] ?? '');
        $pass = sanitize_text_field($_POST['pass'] ?? '');

        // Use saved password if not provided
        if (!$pass) {
            $pass = get_option('ai_audit_fs_api_pass', '');
        }

        if (!$url || !$user || !$pass) {
            wp_send_json_error('URL, username, and password are required');
        }

        $result = $this->fs_api_get($url, $user, $pass, '/reports', []);

        if ($result === null) {
            wp_send_json_error('Could not connect. Check URL and credentials.');
        }

        wp_send_json_success([
            'message' => 'Connected successfully!',
            'data'    => $result,
        ]);
    }

    /**
     * Fetch agents from FluentSupport API (for selection UI)
     */
    public function fetch_fs_agents() {
        check_ajax_referer('ai_ops_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $base_url = get_option('ai_audit_fs_api_url', '');
        $user     = get_option('ai_audit_fs_api_user', '');
        $pass     = get_option('ai_audit_fs_api_pass', '');

        if (!$base_url || !$user || !$pass) {
            wp_send_json_error('FluentSupport API not connected. Go to API Config to set it up.');
        }

        $result = $this->fs_api_request($base_url, $user, $pass, '/agents', ['per_page' => 100]);

        if (is_string($result)) {
            // Error message returned
            wp_send_json_error($result);
        }

        if ($result === null) {
            wp_send_json_error('Failed to fetch agents from FluentSupport.');
        }

        $agents_list = $result['agents']['data'] ?? $result['data'] ?? $result['agents'] ?? $result;

        if (!is_array($agents_list)) {
            wp_send_json_error('Unexpected response format from FluentSupport.');
        }

        // Clean up the data we send to the frontend
        $agents = [];
        foreach ($agents_list as $agent) {
            if (!is_array($agent)) continue;
            $email = sanitize_email($agent['email'] ?? '');
            if (!$email) continue;
            $agents[] = [
                'id'         => intval($agent['id'] ?? 0),
                'first_name' => sanitize_text_field($agent['first_name'] ?? ''),
                'last_name'  => sanitize_text_field($agent['last_name'] ?? ''),
                'email'      => $email,
                'title'      => sanitize_text_field($agent['title'] ?? $agent['designation'] ?? ''),
                'avatar'     => esc_url_raw($agent['avatar'] ?? $agent['photo'] ?? ''),
            ];
        }

        // Get existing emails for comparison
        global $wpdb;
        $table = $wpdb->prefix . 'ais_agents';
        $existing = ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table)
            ? $wpdb->get_col("SELECT email FROM {$table}")
            : [];

        wp_send_json_success([
            'agents'          => $agents,
            'existing_emails' => $existing,
        ]);
    }

    /**
     * Import selected agents from FluentSupport
     */
    public function import_fs_agents() {
        check_ajax_referer('ai_ops_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $raw = wp_unslash($_POST['agents'] ?? '');
        $agents = json_decode($raw, true);

        if (!is_array($agents) || empty($agents)) {
            wp_send_json_error('No agents selected.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ais_agents';

        // Auto-create table if missing (fresh install)
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            $this->database->setup();
        }

        $imported = 0;

        foreach ($agents as $agent) {
            $email = sanitize_email($agent['email'] ?? '');
            if (!$email) continue;

            // Skip if already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE email = %s", $email
            ));
            if ($exists) continue;

            $wpdb->insert($table, [
                'first_name'      => sanitize_text_field($agent['first_name'] ?? ''),
                'last_name'       => sanitize_text_field($agent['last_name'] ?? ''),
                'email'           => $email,
                'title'           => sanitize_text_field($agent['title'] ?? '') ?: null,
                'role'            => 'agent',
                'fluent_agent_id' => intval($agent['id'] ?? 0) ?: null,
                'avatar_url'      => esc_url_raw($agent['avatar'] ?? '') ?: null,
                'is_active'       => 1,
                'last_synced'     => current_time('mysql'),
            ]);
            $imported++;
        }

        wp_send_json_success([
            'imported' => $imported,
            'message'  => "{$imported} agent" . ($imported !== 1 ? 's' : '') . " imported successfully.",
        ]);
    }

    /**
     * Build a local-bypass URL and headers for same-server FluentSupport requests.
     *
     * If the FS API target resolves to the same server (localhost), rewrite the URL
     * to http://127.0.0.1 with a Host header so we skip Cloudflare entirely.
     */
    private function build_fs_request($base_url, $user, $pass, $endpoint, $params = []) {
        $url = rtrim($base_url, '/') . '/wp-json/fluent-support/v2' . $endpoint;

        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $parsed = wp_parse_url($base_url);
        $host   = $parsed['host'] ?? '';

        $headers = [
            'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass),
            'Accept'        => 'application/json',
            'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        ];

        // Check if the target host resolves to this server — bypass Cloudflare via localhost
        $is_local = false;
        if ($host && $host !== 'localhost') {
            $local_ips = ['127.0.0.1', '::1'];
            $server_ip = gethostbyname(gethostname());
            if ($server_ip) {
                $local_ips[] = $server_ip;
            }
            $resolved = gethostbyname($host);
            $is_local = in_array($resolved, $local_ips, true);
        } elseif ($host === 'localhost') {
            $is_local = true;
        }

        if ($is_local && $host) {
            // Rewrite URL to go through localhost, add Host header to bypass Cloudflare
            $url = str_replace($parsed['scheme'] . '://' . $host, 'http://127.0.0.1', $url);
            $headers['Host'] = $host;
        }

        return ['url' => $url, 'headers' => $headers];
    }

    /**
     * Make a GET request to the FluentSupport REST API
     */
    private function fs_api_get($base_url, $user, $pass, $endpoint, $params = []) {
        $req = $this->build_fs_request($base_url, $user, $pass, $endpoint, $params);

        $response = wp_remote_get($req['url'], [
            'timeout'   => 15,
            'headers'   => $req['headers'],
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    /**
     * Make a GET request with detailed error reporting (for user-facing actions)
     *
     * @return array|string|null Array on success, error string on failure, null on unknown
     */
    private function fs_api_request($base_url, $user, $pass, $endpoint, $params = []) {
        $req = $this->build_fs_request($base_url, $user, $pass, $endpoint, $params);

        $response = wp_remote_get($req['url'], [
            'timeout'   => 30,
            'headers'   => $req['headers'],
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return 'Connection error: ' . $response->get_error_message();
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 401 || $code === 403) {
            return 'Authentication failed (HTTP ' . $code . '). Check your API credentials in API Config.';
        }

        if ($code < 200 || $code >= 300) {
            return 'FluentSupport returned HTTP ' . $code . '. URL: ' . $base_url;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
}
