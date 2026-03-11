<?php
/**
 * Calendar AJAX Handler — Holidays, Leaves, Extras, Holiday Duty
 *
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class CalendarHandler {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    // ── Holidays ──────────────────────────────────────────

    public function save_holiday() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id         = intval($_POST['id'] ?? 0);
        $name       = sanitize_text_field($_POST['name'] ?? '');
        $date_start = sanitize_text_field($_POST['date_start'] ?? '');
        $date_end   = sanitize_text_field($_POST['date_end'] ?? '');
        $type       = sanitize_text_field($_POST['type'] ?? 'government');

        if (empty($name) || empty($date_start) || empty($date_end)) {
            wp_send_json_error('Name, start date, and end date are required');
        }

        $year = intval(date('Y', strtotime($date_start)));

        $data = [
            'name'       => $name,
            'date_start' => $date_start,
            'date_end'   => $date_end,
            'type'       => $type,
            'year'       => $year,
        ];

        $table = $this->database->get_table('holidays');

        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $wpdb->insert($table, $data);
            $id = $wpdb->insert_id;
        }

        wp_send_json_success(['id' => $id]);
    }

    public function delete_holiday() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error('Invalid holiday ID');
        }

        // Delete related duty assignments
        $wpdb->delete($this->database->get_table('holiday_duty'), ['holiday_id' => $id]);
        $wpdb->delete($this->database->get_table('holidays'), ['id' => $id]);

        wp_send_json_success();
    }

    // ── Holiday Duty ─────────────────────────────────────

    public function save_holiday_duty() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $holiday_id = intval($_POST['holiday_id'] ?? 0);
        $date       = sanitize_text_field($_POST['date'] ?? '');
        $duties     = $_POST['duties'] ?? []; // [{agent_email, shift_type}]
        $comp_offs  = $_POST['comp_offs'] ?? []; // {agent_email: date}

        if (!$holiday_id || empty($date)) {
            wp_send_json_error('Holiday ID and date are required');
        }

        $table = $this->database->get_table('holiday_duty');

        // Clear existing duty for this holiday + date
        $wpdb->delete($table, ['holiday_id' => $holiday_id, 'date' => $date]);

        // Insert new assignments
        if (is_array($duties)) {
            foreach ($duties as $duty) {
                $email = sanitize_email($duty['agent_email'] ?? '');
                $shift = sanitize_text_field($duty['shift_type'] ?? '');
                if (empty($email) || empty($shift)) continue;

                $comp_off_date = null;
                if (isset($comp_offs[$email]) && !empty($comp_offs[$email])) {
                    $comp_off_date = sanitize_text_field($comp_offs[$email]);
                }

                $wpdb->insert($table, [
                    'holiday_id'     => $holiday_id,
                    'date'           => $date,
                    'agent_email'    => $email,
                    'shift_type'     => $shift,
                    'comp_off_date'  => $comp_off_date,
                    'comp_off_status' => $comp_off_date ? 'confirmed' : 'pending',
                ]);
            }
        }

        wp_send_json_success();
    }

    // ── Leaves ────────────────────────────────────────────

    public function save_leave() {
        if (!current_user_can('view_team_audits') && !current_user_can('view_own_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $agent_email = sanitize_email($_POST['agent_email'] ?? '');
        $date_start  = sanitize_text_field($_POST['date_start'] ?? ($_POST['leave_date'] ?? ''));
        $date_end    = sanitize_text_field($_POST['date_end'] ?? ($_POST['leave_date'] ?? ''));
        $leave_type  = sanitize_text_field($_POST['leave_type'] ?? 'personal');
        $reason      = sanitize_text_field($_POST['reason'] ?? '');

        if (empty($agent_email) || empty($date_start) || empty($date_end)) {
            wp_send_json_error('Agent, start date, and end date are required');
        }

        // Agents can only submit leave for themselves
        if (AccessControl::is_agent()) {
            $own_email = AccessControl::get_agent_email();
            if ($agent_email !== $own_email) {
                wp_send_json_error('You can only request leave for yourself');
            }
        }

        // Team scoping for leads
        if (AccessControl::is_lead()) {
            $team_emails = AccessControl::get_team_agent_emails();
            if (!in_array($agent_email, $team_emails, true)) {
                wp_send_json_error('Agent not in your team');
            }
        }

        // Get current user email for created_by
        $current_user = wp_get_current_user();
        $created_by = $current_user->user_email;

        $table = $this->database->get_table('agent_leaves');

        // Agent self-service: pending approval. Lead/admin: auto-approved.
        $status = AccessControl::is_agent() ? 'pending' : 'approved';

        $wpdb->insert($table, [
            'agent_email' => $agent_email,
            'date_start'  => $date_start,
            'date_end'    => $date_end,
            'leave_type'  => $leave_type,
            'reason'      => $reason,
            'status'      => $status,
            'created_by'  => $created_by,
        ]);

        wp_send_json_success(['id' => $wpdb->insert_id]);
    }

    public function delete_leave() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error('Invalid leave ID');
        }

        // Team scoping for leads
        if (AccessControl::is_lead()) {
            $leave_email = $wpdb->get_var($wpdb->prepare(
                "SELECT agent_email FROM {$this->database->get_table('agent_leaves')} WHERE id = %d",
                $id
            ));
            $team_emails = AccessControl::get_team_agent_emails();
            if (!in_array($leave_email, $team_emails, true)) {
                wp_send_json_error('Agent not in your team');
            }
        }

        $wpdb->delete($this->database->get_table('agent_leaves'), ['id' => $id]);

        wp_send_json_success();
    }

    public function resolve_leave() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        if ($id <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
            wp_send_json_error('Invalid parameters');
        }

        // Team scoping for leads
        if (AccessControl::is_lead()) {
            $leave_email = $wpdb->get_var($wpdb->prepare(
                "SELECT agent_email FROM {$this->database->get_table('agent_leaves')} WHERE id = %d",
                $id
            ));
            $team_emails = AccessControl::get_team_agent_emails();
            if (!in_array($leave_email, $team_emails, true)) {
                wp_send_json_error('Agent not in your team');
            }
        }

        $wpdb->update(
            $this->database->get_table('agent_leaves'),
            ['status' => $status],
            ['id' => $id]
        );

        wp_send_json_success();
    }

    // ── Calendar Extras ──────────────────────────────────

    public function save_extra() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $date        = sanitize_text_field($_POST['date'] ?? '');
        $agent_email = sanitize_email($_POST['agent_email'] ?? '');
        $shift_type  = sanitize_text_field($_POST['shift_type'] ?? '');
        $action_type = sanitize_text_field($_POST['action_type'] ?? 'add');
        $note        = sanitize_text_field($_POST['note'] ?? '');

        if (empty($date) || empty($agent_email) || empty($shift_type)) {
            wp_send_json_error('Date, agent, and shift type are required');
        }

        $table = $this->database->get_table('calendar_extras');

        $wpdb->insert($table, [
            'date'         => $date,
            'agent_email'  => $agent_email,
            'shift_type'   => $shift_type,
            'action_type'  => $action_type,
            'note'         => $note,
        ]);

        wp_send_json_success(['id' => $wpdb->insert_id]);
    }

    public function delete_extra() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error('Invalid extra ID');
        }

        $wpdb->delete($this->database->get_table('calendar_extras'), ['id' => $id]);

        wp_send_json_success();
    }

    // ── Day Details (GET all data for a date) ────────────

    public function get_day_details() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $date = sanitize_text_field($_POST['date'] ?? '');
        if (empty($date)) {
            wp_send_json_error('Date is required');
        }

        $agent_filter = AccessControl::sql_agent_filter('agent_email');

        // Shifts for this date
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, a.first_name, a.last_name
             FROM {$this->database->get_table('agent_shifts')} s
             LEFT JOIN {$wpdb->prefix}ais_agents a ON s.agent_email = a.email
             WHERE DATE(s.shift_start) = %s {$agent_filter}
             ORDER BY s.shift_type, a.first_name",
            $date
        ));

        // Leaves overlapping this date
        $leaves = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, a.first_name, a.last_name
             FROM {$this->database->get_table('agent_leaves')} l
             LEFT JOIN {$wpdb->prefix}ais_agents a ON l.agent_email = a.email
             WHERE l.status = 'approved' AND %s BETWEEN l.date_start AND l.date_end {$agent_filter}
             ORDER BY a.first_name",
            $date
        ));

        // Holiday for this date
        $holiday = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->database->get_table('holidays')}
             WHERE %s BETWEEN date_start AND date_end",
            $date
        ));

        // Holiday duty if it's a holiday
        $holiday_duty = [];
        if ($holiday) {
            $holiday_duty = $wpdb->get_results($wpdb->prepare(
                "SELECT hd.*, a.first_name, a.last_name
                 FROM {$this->database->get_table('holiday_duty')} hd
                 LEFT JOIN {$wpdb->prefix}ais_agents a ON hd.agent_email = a.email
                 WHERE hd.holiday_id = %d AND hd.date = %s
                 ORDER BY hd.shift_type, a.first_name",
                $holiday->id, $date
            ));
        }

        // Extras for this date
        $extras = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, a.first_name, a.last_name
             FROM {$this->database->get_table('calendar_extras')} e
             LEFT JOIN {$wpdb->prefix}ais_agents a ON e.agent_email = a.email
             WHERE e.date = %s
             ORDER BY e.shift_type, a.first_name",
            $date
        ));

        // Comp-offs for this date (agents who earned a comp-off for this date)
        $comp_offs = $wpdb->get_results($wpdb->prepare(
            "SELECT hd.*, a.first_name, a.last_name
             FROM {$this->database->get_table('holiday_duty')} hd
             LEFT JOIN {$wpdb->prefix}ais_agents a ON hd.agent_email = a.email
             WHERE hd.comp_off_date = %s AND hd.comp_off_status IN ('confirmed','used')
             ORDER BY a.first_name",
            $date
        ));

        wp_send_json_success([
            'date'         => $date,
            'shifts'       => $shifts,
            'leaves'       => $leaves,
            'holiday'      => $holiday,
            'holiday_duty' => $holiday_duty,
            'extras'       => $extras,
            'comp_offs'    => $comp_offs,
        ]);
    }

    // ── Export Leaves as CSV ──────────────────────────────

    public function export_leave_csv() {
        if (!current_user_can('view_team_audits')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $year = intval($_GET['year'] ?? date('Y'));
        $agent_filter = AccessControl::sql_agent_filter('l.agent_email');

        $leaves = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, a.first_name, a.last_name,
                    t.name as team_name
             FROM {$this->database->get_table('agent_leaves')} l
             LEFT JOIN {$wpdb->prefix}ais_agents a ON l.agent_email = a.email
             LEFT JOIN {$wpdb->prefix}ais_team_members tm ON l.agent_email = tm.agent_email
             LEFT JOIN {$wpdb->prefix}ais_teams t ON tm.team_id = t.id
             WHERE YEAR(l.date_start) = %d AND l.status = 'approved' {$agent_filter}
             ORDER BY l.date_start",
            $year
        ));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=support-leave-' . $year . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Agent', 'Email', 'Team', 'Type', 'Start Date', 'End Date', 'Days', 'Reason', 'Status']);

        foreach ($leaves as $l) {
            $name = trim(($l->first_name ?? '') . ' ' . ($l->last_name ?? '')) ?: $l->agent_email;
            $days = (strtotime($l->date_end) - strtotime($l->date_start)) / 86400 + 1;
            fputcsv($output, [
                $name,
                $l->agent_email,
                $l->team_name ?? '',
                $l->leave_type,
                $l->date_start,
                $l->date_end,
                $days,
                $l->reason ?? '',
                $l->status,
            ]);
        }

        fclose($output);
        exit;
    }
}
