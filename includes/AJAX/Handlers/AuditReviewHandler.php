<?php
/**
 * Audit Review AJAX Handler — Lead reviews + score overrides
 *
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class AuditReviewHandler {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    /**
     * Save a lead's review of an audit
     */
    public function save_review() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $audit_id      = intval($_POST['audit_id'] ?? 0);
        $ticket_id     = sanitize_text_field($_POST['ticket_id'] ?? '');
        $review_status = sanitize_text_field($_POST['review_status'] ?? 'agree');
        $summary_agree = intval($_POST['summary_agree'] ?? 1);
        $evals_review  = sanitize_text_field($_POST['evaluations_review'] ?? '');
        $evals_notes   = sanitize_textarea_field($_POST['evaluations_notes'] ?? '');
        $probs_review  = sanitize_text_field($_POST['problems_review'] ?? '');
        $probs_notes   = sanitize_textarea_field($_POST['problems_notes'] ?? '');
        $general_notes = sanitize_textarea_field($_POST['general_notes'] ?? '');

        if (!$audit_id || !$ticket_id) {
            wp_send_json_error('Audit ID and ticket ID are required');
        }

        $current_user = wp_get_current_user();
        $reviewer_email = $current_user->user_email;

        $table = $this->database->get_table('audit_reviews');

        // Check if review already exists for this audit by this reviewer
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE audit_id = %d AND reviewer_email = %s",
            $audit_id, $reviewer_email
        ));

        $data = [
            'audit_id'           => $audit_id,
            'ticket_id'          => $ticket_id,
            'reviewer_email'     => $reviewer_email,
            'review_status'      => $review_status,
            'summary_agree'      => $summary_agree,
            'evaluations_review' => $evals_review,
            'evaluations_notes'  => $evals_notes,
            'problems_review'    => $probs_review,
            'problems_notes'     => $probs_notes,
            'general_notes'      => $general_notes,
            'reviewed_at'        => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $data);
        }

        wp_send_json_success(['message' => 'Review saved']);
    }

    /**
     * Get review data for an audit
     */
    public function get_review() {
        if (!current_user_can('view_team_audits') && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $audit_id = intval($_POST['audit_id'] ?? 0);
        if (!$audit_id) {
            wp_send_json_error('Audit ID required');
        }

        $table = $this->database->get_table('audit_reviews');

        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, a.first_name, a.last_name
             FROM {$table} r
             LEFT JOIN {$this->database->get_table('agents')} a ON r.reviewer_email = a.email
             WHERE r.audit_id = %d
             ORDER BY r.reviewed_at DESC
             LIMIT 1",
            $audit_id
        ));

        // Get score overrides for this audit
        $overrides = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, a.first_name as override_by_name
             FROM {$this->database->get_table('score_overrides')} o
             LEFT JOIN {$this->database->get_table('agents')} a ON o.override_by = a.email
             WHERE o.audit_id = %d
             ORDER BY o.created_at DESC",
            $audit_id
        ));

        wp_send_json_success([
            'review'    => $review,
            'overrides' => $overrides,
        ]);
    }

    /**
     * Override a score (admin or can_override agents only)
     */
    public function save_override() {
        if (!AccessControl::can_override_scores()) {
            wp_send_json_error('You do not have permission to override scores');
        }

        global $wpdb;

        $audit_id    = intval($_POST['audit_id'] ?? 0);
        $ticket_id   = sanitize_text_field($_POST['ticket_id'] ?? '');
        $agent_email = sanitize_email($_POST['agent_email'] ?? '');
        $field_name  = sanitize_text_field($_POST['field_name'] ?? '');
        $new_value   = intval($_POST['new_value'] ?? 0);
        $reason      = sanitize_textarea_field($_POST['reason'] ?? '');

        $allowed_fields = ['timing_score', 'resolution_score', 'communication_score'];
        if (!$audit_id || !$ticket_id || !$agent_email || !in_array($field_name, $allowed_fields)) {
            wp_send_json_error('Invalid parameters');
        }

        $eval_table = $this->database->get_table('agent_evaluations');
        $override_table = $this->database->get_table('score_overrides');
        $audit_table = $this->database->get_table('audits');

        // Get current value from evaluations
        $eval = $wpdb->get_row($wpdb->prepare(
            "SELECT id, timing_score, resolution_score, communication_score
             FROM {$eval_table}
             WHERE ticket_id = %s AND agent_email = %s
             ORDER BY id DESC LIMIT 1",
            $ticket_id, $agent_email
        ));

        if (!$eval) {
            wp_send_json_error('Agent evaluation not found');
        }

        $old_value = intval($eval->$field_name);

        if ($old_value === $new_value) {
            wp_send_json_error('Value unchanged');
        }

        $current_user = wp_get_current_user();
        $override_by = $current_user->user_email;

        // 1. Log the override
        $wpdb->insert($override_table, [
            'audit_id'    => $audit_id,
            'ticket_id'   => $ticket_id,
            'agent_email' => $agent_email,
            'field_name'  => $field_name,
            'old_value'   => $old_value,
            'new_value'   => $new_value,
            'override_by' => $override_by,
            'reason'      => $reason,
        ]);

        // 2. Update the evaluation
        $wpdb->update($eval_table, [$field_name => $new_value], ['id' => $eval->id]);

        // 3. Recalculate overall_agent_score
        $timing      = $field_name === 'timing_score' ? $new_value : intval($eval->timing_score);
        $resolution  = $field_name === 'resolution_score' ? $new_value : intval($eval->resolution_score);
        $communication = $field_name === 'communication_score' ? $new_value : intval($eval->communication_score);
        $overall_agent = $timing + $resolution + $communication;

        $wpdb->update($eval_table, ['overall_agent_score' => $overall_agent], ['id' => $eval->id]);

        // Note: audit overall_score is NOT recalculated — it's the AI's assessment
        // and is separate from individual agent score sums

        wp_send_json_success([
            'message'             => 'Score overridden',
            'new_overall_agent'   => $overall_agent,
            'old_value'           => $old_value,
            'new_value'           => $new_value,
        ]);
    }

    /**
     * Lead requests a score override (pending admin approval)
     */
    public function request_override() {
        if (!current_user_can('view_team_audits')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $audit_id        = intval($_POST['audit_id'] ?? 0);
        $ticket_id       = sanitize_text_field($_POST['ticket_id'] ?? '');
        $agent_email     = sanitize_email($_POST['agent_email'] ?? '');
        $field_name      = sanitize_text_field($_POST['field_name'] ?? '');
        $suggested_value = intval($_POST['suggested_value'] ?? 0);
        $request_notes   = sanitize_textarea_field($_POST['request_notes'] ?? '');

        $allowed_fields = ['timing_score', 'resolution_score', 'communication_score'];
        if (!$audit_id || !$ticket_id || !$agent_email || !in_array($field_name, $allowed_fields)) {
            wp_send_json_error('Invalid parameters');
        }

        if (!$request_notes) {
            wp_send_json_error('Please provide a reason for the review request');
        }

        // Team scoping: leads can only request for their team's agents
        $team_emails = AccessControl::get_team_agent_emails();
        if (!empty($team_emails) && !in_array($agent_email, $team_emails, true)) {
            wp_send_json_error('Agent not in your team');
        }

        // Get current value
        $eval_table = $this->database->get_table('agent_evaluations');
        $eval = $wpdb->get_row($wpdb->prepare(
            "SELECT id, timing_score, resolution_score, communication_score
             FROM {$eval_table}
             WHERE ticket_id = %s AND agent_email = %s
             ORDER BY id DESC LIMIT 1",
            $ticket_id, $agent_email
        ));

        if (!$eval) {
            wp_send_json_error('Agent evaluation not found');
        }

        $current_value = intval($eval->$field_name);

        if ($current_value === $suggested_value) {
            wp_send_json_error('Suggested value is the same as current');
        }

        // Check for existing pending request on same field
        $request_table = $this->database->get_table('override_requests');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$request_table}
             WHERE audit_id = %d AND agent_email = %s AND field_name = %s AND status = 'pending'",
            $audit_id, $agent_email, $field_name
        ));

        if ($existing) {
            wp_send_json_error('A pending request already exists for this field');
        }

        $current_user = wp_get_current_user();

        $wpdb->insert($request_table, [
            'audit_id'        => $audit_id,
            'ticket_id'       => $ticket_id,
            'agent_email'     => $agent_email,
            'field_name'      => $field_name,
            'current_value'   => $current_value,
            'suggested_value' => $suggested_value,
            'requested_by'    => $current_user->user_email,
            'request_notes'   => $request_notes,
            'status'          => 'pending',
        ]);

        wp_send_json_success(['message' => 'Review request submitted']);
    }

    /**
     * Admin approves or rejects an override request
     */
    public function resolve_override_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Only admins can resolve override requests');
        }

        global $wpdb;

        $request_id      = intval($_POST['request_id'] ?? 0);
        $resolution       = sanitize_text_field($_POST['resolution'] ?? '');
        $resolution_notes = sanitize_textarea_field($_POST['resolution_notes'] ?? '');

        if (!$request_id || !in_array($resolution, ['approved', 'rejected'])) {
            wp_send_json_error('Invalid parameters');
        }

        $request_table = $this->database->get_table('override_requests');
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$request_table} WHERE id = %d AND status = 'pending'",
            $request_id
        ));

        if (!$request) {
            wp_send_json_error('Request not found or already resolved');
        }

        $current_user = wp_get_current_user();

        // Update request status
        $wpdb->update($request_table, [
            'status'           => $resolution,
            'resolved_by'      => $current_user->user_email,
            'resolution_notes' => $resolution_notes,
            'resolved_at'      => current_time('mysql'),
        ], ['id' => $request_id]);

        $response_data = [
            'message' => $resolution === 'approved' ? 'Request approved and score updated' : 'Request rejected',
        ];

        // If approved, apply the score change
        if ($resolution === 'approved') {
            $eval_table = $this->database->get_table('agent_evaluations');
            $override_table = $this->database->get_table('score_overrides');
            $audit_table = $this->database->get_table('audits');

            $eval = $wpdb->get_row($wpdb->prepare(
                "SELECT id, timing_score, resolution_score, communication_score
                 FROM {$eval_table}
                 WHERE ticket_id = %s AND agent_email = %s
                 ORDER BY id DESC LIMIT 1",
                $request->ticket_id, $request->agent_email
            ));

            if ($eval) {
                $field_name = $request->field_name;
                $old_value = intval($eval->$field_name);
                $new_value = intval($request->suggested_value);

                // Log to score_overrides (audit trail)
                $reason = 'Lead request by ' . $request->requested_by . ': ' . $request->request_notes;
                if ($resolution_notes) {
                    $reason .= ' | Admin: ' . $resolution_notes;
                }

                $wpdb->insert($override_table, [
                    'audit_id'    => $request->audit_id,
                    'ticket_id'   => $request->ticket_id,
                    'agent_email' => $request->agent_email,
                    'field_name'  => $field_name,
                    'old_value'   => $old_value,
                    'new_value'   => $new_value,
                    'override_by' => $request->requested_by,
                    'reason'      => $reason,
                ]);

                // Update evaluation
                $wpdb->update($eval_table, [$field_name => $new_value], ['id' => $eval->id]);

                // Recalculate overall_agent_score
                $timing        = $field_name === 'timing_score' ? $new_value : intval($eval->timing_score);
                $resolution_s  = $field_name === 'resolution_score' ? $new_value : intval($eval->resolution_score);
                $communication = $field_name === 'communication_score' ? $new_value : intval($eval->communication_score);
                $overall_agent = $timing + $resolution_s + $communication;

                $wpdb->update($eval_table, ['overall_agent_score' => $overall_agent], ['id' => $eval->id]);

                // Note: audit overall_score is NOT recalculated — it's the AI's assessment

                $response_data['new_overall_agent'] = $overall_agent;
                $response_data['old_value']         = $old_value;
                $response_data['new_value']         = $new_value;
            }
        }

        wp_send_json_success($response_data);
    }

    /**
     * Get override requests for an audit
     */
    public function get_override_requests() {
        if (!current_user_can('view_team_audits') && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $audit_id = intval($_POST['audit_id'] ?? 0);
        if (!$audit_id) {
            wp_send_json_error('Audit ID required');
        }

        $request_table = $this->database->get_table('override_requests');

        $requests = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, a.first_name as requester_name, ra.first_name as resolver_name
             FROM {$request_table} r
             LEFT JOIN {$this->database->get_table('agents')} a ON r.requested_by = a.email
             LEFT JOIN {$this->database->get_table('agents')} ra ON r.resolved_by = ra.email
             WHERE r.audit_id = %d
             ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.created_at DESC",
            $audit_id
        ));

        wp_send_json_success(['requests' => $requests]);
    }

    /**
     * Delete audit(s) and all related data (admin only)
     */
    public function delete_audit() {
        check_ajax_referer('ai_ops_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Only administrators can delete audits');
        }

        global $wpdb;

        $audit_ids = isset($_POST['audit_ids']) ? array_map('intval', (array) $_POST['audit_ids']) : [];
        $audit_ids = array_filter($audit_ids, function($id) { return $id > 0; });

        if (empty($audit_ids)) {
            wp_send_json_error('No audit IDs provided');
        }

        $placeholders = implode(',', array_fill(0, count($audit_ids), '%d'));

        // Get ticket_ids for these audits (needed for cleaning related tables)
        $audit_table = $this->database->get_table('audits');
        $audits = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ticket_id FROM {$audit_table} WHERE id IN ({$placeholders})",
            ...$audit_ids
        ));

        if (empty($audits)) {
            wp_send_json_error('No audits found');
        }

        $ticket_ids = array_unique(array_column($audits, 'ticket_id'));
        $found_audit_ids = array_column($audits, 'id');

        $audit_ph = implode(',', array_fill(0, count($found_audit_ids), '%d'));
        $ticket_ph = implode(',', array_fill(0, count($ticket_ids), '%s'));

        // Delete from all related tables
        // 1. Agent evaluations
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->database->get_table('agent_evaluations')} WHERE ticket_id IN ({$ticket_ph})",
            ...$ticket_ids
        ));

        // 2. Agent contributions
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->database->get_table('agent_contributions')} WHERE ticket_id IN ({$ticket_ph})",
            ...$ticket_ids
        ));

        // 3. Problem contexts
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->database->get_table('problem_contexts')} WHERE ticket_id IN ({$ticket_ph})",
            ...$ticket_ids
        ));

        // 4. Audit reviews
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->database->get_table('audit_reviews')} WHERE audit_id IN ({$audit_ph})",
            ...$found_audit_ids
        ));

        // 5. Score overrides
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->database->get_table('score_overrides')} WHERE audit_id IN ({$audit_ph})",
            ...$found_audit_ids
        ));

        // 6. Override requests
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->database->get_table('override_requests')} WHERE audit_id IN ({$audit_ph})",
            ...$found_audit_ids
        ));

        // 7. Audit appeals
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->database->get_table('audit_appeals')} WHERE audit_id IN ({$audit_ph})",
            ...$found_audit_ids
        ));

        // 8. Flagged tickets
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->database->get_table('flagged_tickets')} WHERE ticket_id IN ({$ticket_ph})",
            ...$ticket_ids
        ));

        // 9. Delete ALL audit records for these ticket_ids (including older versions)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$audit_table} WHERE ticket_id IN ({$ticket_ph})",
            ...$ticket_ids
        ));

        $count = count($found_audit_ids);
        wp_send_json_success([
            'message' => $count . ' audit(s) and all related data deleted',
            'deleted_ids' => $found_audit_ids,
            'ticket_ids' => $ticket_ids,
        ]);
    }
}
