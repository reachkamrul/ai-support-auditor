<?php
/**
 * Access Control — Role detection and team-based filtering
 *
 * @package SupportOps\Admin
 */

namespace SupportOps\Admin;

class AccessControl {

    private static $cache = null;

    /**
     * Sections completely hidden from team leads
     */
    private static $admin_only_sections = [
        'timing-penalties',
        'system-message',
        'api-config',
    ];

    /**
     * Sections visible but read-only for leads (view only, no editing)
     */
    private static $read_only_sections = [
        'teams',
        'shift-settings',
    ];

    /**
     * Get cached context for current user
     */
    private static function get_context() {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [
            'role' => null,
            'team_ids' => [],
            'team_names' => [],
            'agent_emails' => [],
            'agent_email' => null,
        ];

        // WordPress admins see everything
        if (current_user_can('manage_options')) {
            self::$cache['role'] = 'admin';
            return self::$cache;
        }

        // Check if current user is a linked team lead
        if (!current_user_can('view_team_audits')) {
            return self::$cache;
        }

        global $wpdb;
        $wp_user_id = get_current_user_id();
        if (!$wp_user_id) {
            return self::$cache;
        }

        // Find agent record linked to this WP user
        $agent = $wpdb->get_row($wpdb->prepare(
            "SELECT email, role FROM {$wpdb->prefix}ais_agents WHERE wp_user_id = %d AND is_active = 1",
            $wp_user_id
        ));

        if (!$agent) {
            return self::$cache;
        }

        self::$cache['role'] = 'lead';
        self::$cache['agent_email'] = $agent->email;

        // Get team IDs this agent belongs to
        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT tm.team_id, t.name
             FROM {$wpdb->prefix}ais_team_members tm
             INNER JOIN {$wpdb->prefix}ais_teams t ON tm.team_id = t.id
             WHERE tm.agent_email = %s",
            $agent->email
        ));

        foreach ($teams as $team) {
            self::$cache['team_ids'][] = (int) $team->team_id;
            self::$cache['team_names'][] = $team->name;
        }

        // Get all agent emails in those teams
        if (!empty(self::$cache['team_ids'])) {
            $placeholders = implode(',', array_fill(0, count(self::$cache['team_ids']), '%d'));
            $emails = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT agent_email FROM {$wpdb->prefix}ais_team_members WHERE team_id IN ($placeholders)",
                ...self::$cache['team_ids']
            ));
            self::$cache['agent_emails'] = $emails ?: [];
        }

        return self::$cache;
    }

    /**
     * Get current user's role: 'admin', 'lead', or null
     */
    public static function get_role() {
        return self::get_context()['role'];
    }

    /**
     * Is current user a full admin?
     */
    public static function is_admin() {
        return self::get_role() === 'admin';
    }

    /**
     * Is current user a team lead (not full admin)?
     */
    public static function is_lead() {
        return self::get_role() === 'lead';
    }

    /**
     * Get team IDs the current lead manages. Empty array for admins (no filtering).
     */
    public static function get_team_ids() {
        return self::get_context()['team_ids'];
    }

    /**
     * Get team names for display
     */
    public static function get_team_names() {
        return self::get_context()['team_names'];
    }

    /**
     * Get all agent emails in the lead's team(s).
     * Returns empty array for admins (meaning: no filtering, show all).
     */
    public static function get_team_agent_emails() {
        return self::get_context()['agent_emails'];
    }

    /**
     * Can the current user access this section?
     */
    public static function can_access($section) {
        if (self::is_admin()) {
            return true;
        }
        if (!self::get_role()) {
            return false;
        }
        return !in_array($section, self::$admin_only_sections, true);
    }

    /**
     * Is this section read-only for the current user?
     */
    public static function is_read_only($section) {
        if (self::is_admin()) {
            return false;
        }
        return in_array($section, self::$read_only_sections, true);
    }

    /**
     * Build a SQL IN clause for team agent emails.
     * Returns empty string if admin (no filter needed).
     * Returns something like: AND ae.agent_email IN ('a@b.com','c@d.com')
     *
     * @param string $column The column name (e.g., 'ae.agent_email')
     * @return string SQL fragment or empty string
     */
    public static function sql_agent_filter($column = 'ae.agent_email') {
        if (self::is_admin()) {
            return '';
        }

        $emails = self::get_team_agent_emails();
        if (empty($emails)) {
            return " AND 1=0"; // No team = no results
        }

        global $wpdb;
        $placeholders = implode(',', array_map(function ($e) use ($wpdb) {
            return $wpdb->prepare('%s', $e);
        }, $emails));

        return " AND {$column} IN ({$placeholders})";
    }

    /**
     * Build a SQL IN clause for team IDs.
     * Returns empty string if admin.
     */
    public static function sql_team_filter($column = 'tm.team_id') {
        if (self::is_admin()) {
            return '';
        }

        $team_ids = self::get_team_ids();
        if (empty($team_ids)) {
            return " AND 1=0";
        }

        $ids = implode(',', array_map('intval', $team_ids));
        return " AND {$column} IN ({$ids})";
    }

    /**
     * Get team filter for admin dropdown. Returns selected team_id or 0 (all).
     * For leads, always returns their team. For admins, reads from request.
     */
    public static function get_selected_team_id() {
        if (self::is_lead()) {
            $team_ids = self::get_team_ids();
            return !empty($team_ids) ? $team_ids[0] : 0;
        }
        return isset($_GET['filter_team']) ? intval($_GET['filter_team']) : 0;
    }

    /**
     * Get all teams for dropdown filter
     */
    public static function get_all_teams() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name, color FROM {$wpdb->prefix}ais_teams ORDER BY name"
        );
    }

    /**
     * Can the current user override audit scores?
     * True for admins + agents with can_override=1
     */
    public static function can_override_scores() {
        if (self::is_admin()) {
            return true;
        }

        $ctx = self::get_context();
        if (!$ctx['agent_email']) {
            return false;
        }

        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT can_override FROM {$wpdb->prefix}ais_agents WHERE email = %s",
            $ctx['agent_email']
        ));
    }

    /**
     * Reset cache (useful for testing)
     */
    public static function reset() {
        self::$cache = null;
    }
}
