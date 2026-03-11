<?php
/**
 * Shift AJAX Handler
 *
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Services\ShiftProcessor;
use SupportOps\Admin\AccessControl;

class ShiftHandler {

    private $database;
    private $shift_processor;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->shift_processor = new ShiftProcessor($database);
    }

    public function save_single() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        $agent_email = sanitize_email($_POST['agent_email'] ?? '');

        // Team scoping: leads can only save shifts for their team's agents
        if (AccessControl::is_lead() && $agent_email) {
            $team_emails = AccessControl::get_team_agent_emails();
            if (!in_array($agent_email, $team_emails, true)) {
                wp_send_json_error('Agent not in your team');
            }
        }

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
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id = intval($_POST['id'] ?? 0);

        if ($id <= 0) {
            wp_send_json_error('Invalid shift ID');
        }

        // Team scoping: leads can only delete shifts for their team's agents
        if (AccessControl::is_lead()) {
            $shift_email = $wpdb->get_var($wpdb->prepare(
                "SELECT agent_email FROM {$this->database->get_table('agent_shifts')} WHERE id = %d",
                $id
            ));
            $team_emails = AccessControl::get_team_agent_emails();
            if (!in_array($shift_email, $team_emails, true)) {
                wp_send_json_error('Agent not in your team');
            }
        }

        $wpdb->delete(
            $this->database->get_table('agent_shifts'),
            ['id' => $id]
        );

        wp_send_json_success();
    }

    /**
     * Copy shifts from one week to another week
     */
    public function copy_week() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $source_start = sanitize_text_field($_POST['source_start'] ?? '');
        $target_start = sanitize_text_field($_POST['target_start'] ?? '');

        if (!$source_start || !$target_start) {
            wp_send_json_error('Source and target week start dates are required');
        }

        $source_end = date('Y-m-d', strtotime($source_start . ' +6 days'));
        $shifts_table = $this->database->get_table('agent_shifts');
        $team_filter = AccessControl::sql_agent_filter('s.agent_email');

        // Get source week shifts
        $source_shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT s.* FROM {$shifts_table} s
             WHERE DATE(s.shift_start) BETWEEN %s AND %s {$team_filter}
             ORDER BY s.shift_start",
            $source_start, $source_end
        ));

        if (empty($source_shifts)) {
            wp_send_json_error('No shifts found in source week');
        }

        $day_diff = (strtotime($target_start) - strtotime($source_start)) / 86400;
        $copied = 0;

        foreach ($source_shifts as $shift) {
            $new_start = date('Y-m-d H:i:s', strtotime($shift->shift_start . " +{$day_diff} days"));
            $new_end = date('Y-m-d H:i:s', strtotime($shift->shift_end . " +{$day_diff} days"));
            $new_date = date('Y-m-d', strtotime($new_start));

            // Delete existing shift for this agent on this day
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$shifts_table} WHERE agent_email = %s AND shift_start LIKE %s",
                $shift->agent_email, $new_date . '%'
            ));

            $wpdb->insert($shifts_table, [
                'agent_email' => $shift->agent_email,
                'shift_def_id' => $shift->shift_def_id,
                'shift_start' => $new_start,
                'shift_end' => $new_end,
                'shift_type' => $shift->shift_type,
                'shift_color' => $shift->shift_color,
            ]);
            $copied++;
        }

        wp_send_json_success(['copied' => $copied]);
    }

    /**
     * Save current week as a named template
     */
    public function save_template() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized — admin only');
        }

        global $wpdb;

        $name = sanitize_text_field($_POST['template_name'] ?? '');
        $week_start = sanitize_text_field($_POST['week_start'] ?? '');

        if (!$name || !$week_start) {
            wp_send_json_error('Template name and week start date are required');
        }

        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
        $shifts_table = $this->database->get_table('agent_shifts');

        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT agent_email, shift_def_id, shift_type, shift_color,
                    DAYOFWEEK(shift_start) as dow,
                    TIME(shift_start) as start_time,
                    TIME(shift_end) as end_time
             FROM {$shifts_table}
             WHERE DATE(shift_start) BETWEEN %s AND %s
             ORDER BY shift_start",
            $week_start, $week_end
        ));

        if (empty($shifts)) {
            wp_send_json_error('No shifts found in this week');
        }

        $template_data = [];
        foreach ($shifts as $s) {
            $template_data[] = [
                'agent_email' => $s->agent_email,
                'shift_def_id' => intval($s->shift_def_id),
                'shift_type' => $s->shift_type,
                'shift_color' => $s->shift_color,
                'dow' => intval($s->dow), // 1=Sunday, 7=Saturday
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
            ];
        }

        // Save as WP option
        $templates = get_option('ais_shift_templates', []);
        $templates[$name] = [
            'created_at' => current_time('mysql'),
            'shifts' => $template_data,
        ];
        update_option('ais_shift_templates', $templates);

        wp_send_json_success(['name' => $name, 'shift_count' => count($template_data)]);
    }

    /**
     * Apply a saved template to a target week
     */
    public function apply_template() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $name = sanitize_text_field($_POST['template_name'] ?? '');
        $target_start = sanitize_text_field($_POST['target_start'] ?? '');

        if (!$name || !$target_start) {
            wp_send_json_error('Template name and target week start are required');
        }

        $templates = get_option('ais_shift_templates', []);
        if (!isset($templates[$name])) {
            wp_send_json_error('Template not found');
        }

        $shifts_table = $this->database->get_table('agent_shifts');
        $template = $templates[$name]['shifts'];
        $target_sunday = date('Y-m-d', strtotime('last sunday', strtotime($target_start . ' +1 day')));
        $applied = 0;

        foreach ($template as $shift) {
            // dow: 1=Sunday offset
            $day_offset = $shift['dow'] - 1; // 0-indexed from Sunday
            $target_date = date('Y-m-d', strtotime($target_sunday . " +{$day_offset} days"));

            $new_start = $target_date . ' ' . $shift['start_time'];
            $new_end_date = $target_date;
            // Handle overnight shifts
            if ($shift['end_time'] < $shift['start_time']) {
                $new_end_date = date('Y-m-d', strtotime($target_date . ' +1 day'));
            }
            $new_end = $new_end_date . ' ' . $shift['end_time'];

            // Delete existing
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$shifts_table} WHERE agent_email = %s AND shift_start LIKE %s",
                $shift['agent_email'], $target_date . '%'
            ));

            $wpdb->insert($shifts_table, [
                'agent_email' => $shift['agent_email'],
                'shift_def_id' => $shift['shift_def_id'],
                'shift_start' => $new_start,
                'shift_end' => $new_end,
                'shift_type' => $shift['shift_type'],
                'shift_color' => $shift['shift_color'],
            ]);
            $applied++;
        }

        wp_send_json_success(['applied' => $applied]);
    }

    /**
     * Get list of saved shift templates
     */
    public function get_templates() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        $templates = get_option('ais_shift_templates', []);
        $result = [];
        foreach ($templates as $name => $data) {
            $result[] = [
                'name' => $name,
                'shift_count' => count($data['shifts']),
                'created_at' => $data['created_at'],
            ];
        }
        wp_send_json_success($result);
    }

    /**
     * Delete a saved shift template
     */
    public function delete_template() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized — admin only');
        }

        $name = sanitize_text_field($_POST['template_name'] ?? '');
        if (!$name) {
            wp_send_json_error('Template name required');
        }

        $templates = get_option('ais_shift_templates', []);
        unset($templates[$name]);
        update_option('ais_shift_templates', $templates);

        wp_send_json_success();
    }
}
