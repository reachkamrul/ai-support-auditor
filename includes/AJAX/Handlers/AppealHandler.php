<?php
/**
 * Audit Appeal AJAX Handler
 *
 * Agents submit appeals on audit scores; leads/admins resolve them.
 *
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class AppealHandler {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    /**
     * Agent submits an appeal on a specific evaluation
     */
    public function submit_appeal() {
        if (!current_user_can('view_own_audits') && !current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $ticket_id = sanitize_text_field($_POST['ticket_id'] ?? '');
        $eval_id = intval($_POST['eval_id'] ?? 0);
        $appeal_type = sanitize_text_field($_POST['appeal_type'] ?? 'score_dispute');
        $disputed_field = sanitize_text_field($_POST['disputed_field'] ?? '');
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$ticket_id || !$eval_id || !$reason) {
            wp_send_json_error('Ticket ID, evaluation ID, and reason are required');
        }

        $agent_email = AccessControl::get_agent_email();
        if (!$agent_email) {
            wp_send_json_error('Your account is not linked to an agent profile');
        }

        // Verify the evaluation belongs to this agent
        $eval = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_agent_evaluations WHERE id = %d AND agent_email = %s",
            $eval_id, $agent_email
        ));

        if (!$eval) {
            wp_send_json_error('Evaluation not found or does not belong to you');
        }

        // Check for existing pending appeal
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ais_audit_appeals
             WHERE eval_id = %d AND agent_email = %s AND status = 'pending'",
            $eval_id, $agent_email
        ));

        if ($existing) {
            wp_send_json_error('You already have a pending appeal for this evaluation');
        }

        $current_score = null;
        if ($disputed_field && isset($eval->$disputed_field)) {
            $current_score = intval($eval->$disputed_field);
        }

        $wpdb->insert($wpdb->prefix . 'ais_audit_appeals', [
            'ticket_id' => $ticket_id,
            'eval_id' => $eval_id,
            'agent_email' => $agent_email,
            'appeal_type' => $appeal_type,
            'disputed_field' => $disputed_field,
            'current_score' => $current_score,
            'reason' => $reason,
            'status' => 'pending',
        ]);

        wp_send_json_success(['id' => $wpdb->insert_id]);
    }

    /**
     * Get appeals for the current agent
     */
    public function get_my_appeals() {
        if (!current_user_can('view_own_audits') && !current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $agent_email = AccessControl::get_agent_email();

        $appeals = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, ae.overall_agent_score, ae.timing_score, ae.resolution_score, ae.communication_score
             FROM {$wpdb->prefix}ais_audit_appeals a
             LEFT JOIN {$wpdb->prefix}ais_agent_evaluations ae ON a.eval_id = ae.id
             WHERE a.agent_email = %s
             ORDER BY a.created_at DESC LIMIT 20",
            $agent_email
        ));

        wp_send_json_success($appeals);
    }

    /**
     * Get pending appeals for lead/admin review
     */
    public function get_pending_appeals() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $team_filter = AccessControl::sql_agent_filter('a.agent_email');

        $appeals = $wpdb->get_results(
            "SELECT a.*, ae.overall_agent_score, ae.timing_score, ae.resolution_score, ae.communication_score,
                    ae.agent_name
             FROM {$wpdb->prefix}ais_audit_appeals a
             LEFT JOIN {$wpdb->prefix}ais_agent_evaluations ae ON a.eval_id = ae.id
             WHERE a.status = 'pending' {$team_filter}
             ORDER BY a.created_at ASC"
        );

        wp_send_json_success($appeals);
    }

    /**
     * Lead/admin resolves an appeal
     */
    public function resolve_appeal() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $appeal_id = intval($_POST['appeal_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? ''); // approved or rejected
        $resolution_notes = sanitize_textarea_field($_POST['resolution_notes'] ?? '');

        if (!$appeal_id || !in_array($status, ['approved', 'rejected'])) {
            wp_send_json_error('Invalid appeal ID or status');
        }

        // Team scoping
        if (AccessControl::is_lead()) {
            $appeal_email = $wpdb->get_var($wpdb->prepare(
                "SELECT agent_email FROM {$wpdb->prefix}ais_audit_appeals WHERE id = %d",
                $appeal_id
            ));
            $team_emails = AccessControl::get_team_agent_emails();
            if (!in_array($appeal_email, $team_emails, true)) {
                wp_send_json_error('Appeal not in your team');
            }
        }

        $current_user = wp_get_current_user();

        $wpdb->update(
            $wpdb->prefix . 'ais_audit_appeals',
            [
                'status' => $status,
                'resolved_by' => $current_user->user_email,
                'resolution_notes' => $resolution_notes,
                'resolved_at' => current_time('mysql'),
            ],
            ['id' => $appeal_id]
        );

        wp_send_json_success();
    }
}
