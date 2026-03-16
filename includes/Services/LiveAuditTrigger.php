<?php
/**
 * Live Audit Trigger — API-based audit queueing service
 *
 * This service handles queueing of live/incremental audits via REST API.
 * It does NOT depend on FluentSupport being installed on the same server.
 * N8N calls the /queue-live-audit endpoint which delegates to this service.
 *
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Admin\Pages\LiveAuditSettings;

class LiveAuditTrigger {

    /**
     * Queue a live audit for an agent response event.
     * Called by the REST API endpoint /queue-live-audit.
     *
     * @param int    $ticket_id      The ticket ID
     * @param int    $response_count Total response count in the ticket
     * @param string $event_type     'agent_response' or 'ticket_closed'
     * @return array Result with status message
     */
    public function handle_event($ticket_id, $response_count, $event_type = 'agent_response') {
        // Track webhook receipt for status display
        self::record_webhook_receipt($event_type);

        $settings = LiveAuditSettings::get_settings();

        if (!$settings['enabled']) {
            return ['queued' => false, 'reason' => 'live_audit_disabled'];
        }

        $ticket_id = intval($ticket_id);
        $response_count = intval($response_count);

        if ($event_type === 'ticket_closed') {
            $this->queue_final_audit($ticket_id, $response_count);
            return ['queued' => true, 'audit_type' => 'final'];
        }

        // Agent response event — check trigger mode
        if ($settings['trigger_mode'] === 'first_and_close') {
            if ($this->has_existing_audit($ticket_id)) {
                return ['queued' => false, 'reason' => 'first_and_close_already_audited'];
            }
        } elseif ($settings['trigger_mode'] === 'every_nth') {
            $existing = $this->get_latest_successful_audit($ticket_id);
            if ($existing) {
                $new_responses = $response_count - intval($existing->last_response_count);
                if ($new_responses < $settings['milestone_interval']) {
                    return ['queued' => false, 'reason' => 'every_nth_not_reached', 'new_responses' => $new_responses, 'interval' => $settings['milestone_interval']];
                }
            }
        }
        // 'every_reply' → always proceed

        // Throttle: prevent duplicate/excessive queuing
        // For 'every_reply': only block if there's already a pending audit (avoid duplicates)
        // For other modes: also block if a successful audit completed recently
        if ($this->has_pending_audit($ticket_id)) {
            return ['queued' => false, 'reason' => 'pending_audit_exists'];
        }
        if ($settings['trigger_mode'] !== 'every_reply' && $this->has_recent_success($ticket_id, $settings['min_interval_minutes'])) {
            return ['queued' => false, 'reason' => 'throttled'];
        }

        $audit_type = $this->queue_audit($ticket_id, $response_count);
        $this->notify_n8n_processor();
        return ['queued' => true, 'audit_type' => $audit_type];
    }

    /**
     * Queue an audit (full for first, incremental for subsequent)
     *
     * @return string The audit type that was queued
     */
    private function queue_audit($ticket_id, $response_count) {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';

        // Cancel any failed audits for this ticket — superseded by the new queue entry
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'cancelled' WHERE ticket_id = %s AND status = 'failed'",
            $ticket_id
        ));

        $existing = $this->get_latest_successful_audit($ticket_id);

        if ($existing) {
            $wpdb->insert($table, [
                'ticket_id'           => $ticket_id,
                'status'              => 'pending',
                'audit_type'          => 'incremental',
                'audit_version'       => intval($existing->audit_version) + 1,
                'last_response_count' => $response_count,
                'created_at'          => current_time('mysql'),
            ]);
            return 'incremental';
        } else {
            $wpdb->insert($table, [
                'ticket_id'           => $ticket_id,
                'status'              => 'pending',
                'audit_type'          => 'full',
                'audit_version'       => 1,
                'last_response_count' => $response_count,
                'created_at'          => current_time('mysql'),
            ]);
            return 'full';
        }
    }

    /**
     * Queue a final full audit (on ticket close)
     */
    private function queue_final_audit($ticket_id, $response_count) {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';

        // Cancel any pending or failed audits for this ticket
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'cancelled' WHERE ticket_id = %s AND status IN ('pending', 'failed')",
            $ticket_id
        ));

        $existing = $this->get_latest_successful_audit($ticket_id);

        $wpdb->insert($table, [
            'ticket_id'           => $ticket_id,
            'status'              => 'pending',
            'audit_type'          => 'final',
            'audit_version'       => ($existing ? intval($existing->audit_version) + 1 : 1),
            'last_response_count' => $response_count,
            'created_at'          => current_time('mysql'),
        ]);

        $this->notify_n8n_processor();
    }

    /**
     * Ping N8N force-audit webhook to trigger immediate processing.
     * Non-blocking — fires and forgets so the API response isn't delayed.
     */
    private function notify_n8n_processor() {
        $n8n_url = get_option('ai_audit_n8n_url', 'https://team.junior.ninja');
        $live_settings = \SupportOps\Admin\Pages\LiveAuditSettings::get_settings();
        $batch_uuid = $live_settings['n8n_batch_webhook_path'] ?: '7394145a-6afd-4386-ae70-21b012cf904f';
        $webhook_url = rtrim($n8n_url, '/') . '/webhook/' . $batch_uuid;

        wp_remote_post($webhook_url, [
            'timeout'  => 2,
            'blocking' => false,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode(['trigger' => 'queue_notify']),
        ]);
    }

    /**
     * Check if ticket has ANY existing audit (any status)
     */
    private function has_existing_audit($ticket_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %s AND status IN ('success', 'pending') LIMIT 1",
            $ticket_id
        ));
    }

    /**
     * Get the latest successful audit for a ticket
     */
    private function get_latest_successful_audit($ticket_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ticket_id = %s AND status = 'success' ORDER BY id DESC LIMIT 1",
            $ticket_id
        ));
    }

    /**
     * Record that a webhook was received, for UI status display.
     */
    public static function record_webhook_receipt($event_type) {
        $status = get_option('ai_audit_webhook_status', []);
        $status[$event_type] = current_time('mysql');
        update_option('ai_audit_webhook_status', $status);
    }

    /**
     * Get webhook receipt status for UI display.
     */
    public static function get_webhook_status() {
        return get_option('ai_audit_webhook_status', []);
    }

    /**
     * Check if ticket already has a pending audit queued
     */
    private function has_pending_audit($ticket_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %s AND status IN ('pending', 'processing') LIMIT 1",
            $ticket_id
        ));
    }

    /**
     * Check if ticket had a successful audit within the throttle window
     */
    private function has_recent_success($ticket_id, $min_minutes) {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE ticket_id = %s
             AND status = 'success'
             AND created_at > DATE_SUB(NOW(), INTERVAL %d MINUTE)
             LIMIT 1",
            $ticket_id, $min_minutes
        ));
    }
}
