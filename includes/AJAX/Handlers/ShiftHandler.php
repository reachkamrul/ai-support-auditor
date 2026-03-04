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
}
