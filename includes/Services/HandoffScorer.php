<?php
/**
 * Handoff Scorer — Evaluate agent handoff compliance when tickets are audited
 *
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

class HandoffScorer {

    /**
     * Score handoff compliance for each agent involved in a ticket
     *
     * @param int   $ticket_id
     * @param array $assignment_history From AssignmentParser::parse_assignment_history()
     * @return array Scores keyed by agent_email: ['email' => ['handoff_score'=>int, 'reason'=>str, ...]]
     */
    public function score($ticket_id, $assignment_history) {
        global $wpdb;

        if (count($assignment_history) < 2) {
            return []; // No handoffs to score
        }

        // Get ticket close time
        $closed_at = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(resolved_at, updated_at) FROM {$wpdb->prefix}fs_tickets WHERE id = %d",
            $ticket_id
        ));

        $scores = [];
        $table = $wpdb->prefix . 'ais_handoff_events';

        // Delete old handoff events for this ticket (re-audit)
        $wpdb->delete($table, ['ticket_id' => $ticket_id]);

        for ($i = 0; $i < count($assignment_history); $i++) {
            $event = $assignment_history[$i];
            $next = $assignment_history[$i + 1] ?? null;
            $email = $event['agent_email'];

            if (!$email) continue;

            $window_start = $event['assigned_at'];
            $window_end = $next ? $next['assigned_at'] : $closed_at;

            // Find all shifts that ended during this agent's assignment window
            $shifts_during_window = $wpdb->get_results($wpdb->prepare(
                "SELECT shift_end FROM {$wpdb->prefix}ais_agent_shifts
                 WHERE agent_email = %s AND shift_end > %s AND shift_end < %s
                 ORDER BY shift_end ASC",
                $email, $window_start, $window_end
            ));

            if (empty($shifts_during_window)) {
                // Agent's shift didn't end during their assignment window.
                // Either: resolved during shift, or no shift data
                $has_any_shifts = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ais_agent_shifts WHERE agent_email = %s LIMIT 1",
                    $email
                ));

                if (!$has_any_shifts) {
                    // No shift data — can't score
                    $scores[$email] = [
                        'handoff_score' => null,
                        'reason' => 'No shift data available',
                        'shift_end' => null,
                        'reassigned_at' => $next ? $next['assigned_at'] : null,
                        'gap_hours' => 0,
                    ];
                } else {
                    // Shift didn't end during window — resolved during shift or reassigned during shift
                    $scores[$email] = [
                        'handoff_score' => 0,
                        'reason' => $next ? 'Reassigned during active shift' : 'Ticket resolved during shift',
                        'shift_end' => null,
                        'reassigned_at' => $next ? $next['assigned_at'] : null,
                        'gap_hours' => 0,
                    ];
                }
                continue;
            }

            // Agent's shift ended while they held the ticket
            // Check the FIRST shift that ended during their window
            $shift_end = $shifts_during_window[0]->shift_end;

            if ($next) {
                // There was a reassignment — check if it happened before shift end
                $reassigned_at = $next['assigned_at'];

                if (strtotime($reassigned_at) <= strtotime($shift_end)) {
                    // Reassigned BEFORE shift ended = good handoff
                    $scores[$email] = [
                        'handoff_score' => 5,
                        'reason' => 'Reassigned before shift ended',
                        'shift_end' => $shift_end,
                        'reassigned_at' => $reassigned_at,
                        'gap_hours' => 0,
                    ];
                } else {
                    // Reassigned AFTER shift ended = failed handoff
                    $gap = (strtotime($reassigned_at) - strtotime($shift_end)) / 3600;
                    $scores[$email] = [
                        'handoff_score' => -10,
                        'reason' => sprintf('Shift ended at %s, reassigned %s later',
                            date('H:i', strtotime($shift_end)),
                            $this->format_hours($gap)
                        ),
                        'shift_end' => $shift_end,
                        'reassigned_at' => $reassigned_at,
                        'gap_hours' => round($gap, 2),
                    ];
                }
            } else {
                // This was the LAST agent — ticket was resolved after their shift ended
                if ($closed_at && strtotime($closed_at) > strtotime($shift_end)) {
                    $gap = (strtotime($closed_at) - strtotime($shift_end)) / 3600;
                    $scores[$email] = [
                        'handoff_score' => -10,
                        'reason' => sprintf('Shift ended at %s, ticket resolved %s later without handoff',
                            date('H:i', strtotime($shift_end)),
                            $this->format_hours($gap)
                        ),
                        'shift_end' => $shift_end,
                        'reassigned_at' => null,
                        'gap_hours' => round($gap, 2),
                    ];
                } else {
                    $scores[$email] = [
                        'handoff_score' => 0,
                        'reason' => 'Ticket resolved before shift ended',
                        'shift_end' => $shift_end,
                        'reassigned_at' => null,
                        'gap_hours' => 0,
                    ];
                }
            }

            // Store handoff event
            $score_data = $scores[$email];
            if ($score_data['handoff_score'] !== null) {
                $wpdb->insert($table, [
                    'ticket_id'     => $ticket_id,
                    'agent_email'   => $email,
                    'shift_end'     => $score_data['shift_end'],
                    'reassigned_at' => $score_data['reassigned_at'],
                    'handoff_score' => $score_data['handoff_score'],
                    'gap_hours'     => $score_data['gap_hours'],
                    'reason'        => $score_data['reason'],
                    'created_at'    => current_time('mysql'),
                ]);
            }
        }

        return $scores;
    }

    /**
     * Format hours into human-readable string
     */
    private function format_hours($hours) {
        if ($hours < 1) {
            return round($hours * 60) . 'm';
        }
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}
