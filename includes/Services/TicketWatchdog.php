<?php
/**
 * Ticket Watchdog — Cron job that scans for orphaned tickets (assigned agent off-shift)
 *
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

class TicketWatchdog {

    const TRANSIENT_KEY = 'ais_watchdog_snapshot';
    const TRANSIENT_TTL = 20 * 60; // 20 minutes
    const TIMEZONE = 'Asia/Dhaka'; // Shifts are stored in this timezone

    /**
     * Main scan — called by WP cron every 15 minutes
     */
    public function scan() {
        global $wpdb;

        // Use Asia/Dhaka timezone — shifts are stored in local time, not UTC
        $tz = new \DateTimeZone(self::TIMEZONE);
        $now = (new \DateTime('now', $tz))->format('Y-m-d H:i:s');

        // Step 1: Get all open tickets with assigned agents
        $open_tickets = $wpdb->get_results(
            "SELECT t.id, t.title, t.product_id, t.agent_id, t.status, t.updated_at,
                    p.email AS agent_email, p.first_name, p.last_name
             FROM {$wpdb->prefix}fs_tickets t
             JOIN {$wpdb->prefix}fs_persons p ON t.agent_id = p.id
             WHERE t.status IN ('new', 'active', 'waiting') AND t.agent_id IS NOT NULL
             ORDER BY t.updated_at DESC"
        );

        // Step 2: Get currently on-shift agents
        $on_shift_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.agent_email, a.first_name, a.last_name, s.shift_type, s.shift_color
             FROM {$wpdb->prefix}ais_agent_shifts s
             LEFT JOIN {$wpdb->prefix}ais_agents a ON s.agent_email = a.email
             WHERE s.shift_start <= %s AND s.shift_end >= %s",
            $now, $now
        ));

        $on_shift_emails = [];
        $on_shift_agents = [];
        foreach ($on_shift_rows as $row) {
            $on_shift_emails[] = $row->agent_email;
            $on_shift_agents[] = [
                'email'      => $row->agent_email,
                'name'       => trim($row->first_name . ' ' . $row->last_name),
                'shift_type' => $row->shift_type,
                'shift_color' => $row->shift_color,
            ];
        }

        // Step 3: Get agents that have ANY shift records (vs truly unknown agents)
        $agents_with_shifts = [];
        if (!empty($open_tickets)) {
            $unique_agent_emails = array_unique(array_column($open_tickets, 'agent_email'));
            foreach ($unique_agent_emails as $email) {
                $has_shifts = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ais_agent_shifts WHERE agent_email = %s LIMIT 1",
                    $email
                ));
                if ($has_shifts > 0) {
                    $agents_with_shifts[] = $email;
                }
            }
        }

        // Step 4: Identify orphaned tickets
        $orphaned = [];
        foreach ($open_tickets as $t) {
            // Skip if agent is on shift
            if (in_array($t->agent_email, $on_shift_emails)) continue;

            // Skip if agent has no shift records at all (unknown schedule)
            if (!in_array($t->agent_email, $agents_with_shifts)) continue;

            // Agent is OFF-shift — find when their last shift ended
            $last_shift_end = $wpdb->get_var($wpdb->prepare(
                "SELECT shift_end FROM {$wpdb->prefix}ais_agent_shifts
                 WHERE agent_email = %s AND shift_end < %s
                 ORDER BY shift_end DESC LIMIT 1",
                $t->agent_email, $now
            ));

            $orphan_since = $last_shift_end ?: $t->updated_at;
            $hours_orphaned = (strtotime($now) - strtotime($orphan_since)) / 3600;

            // Find suggested replacement
            $suggestion = $this->suggest_replacement($t->product_id, $on_shift_emails, $t->agent_email);

            $orphaned[] = [
                'ticket_id'            => $t->id,
                'title'                => $t->title,
                'status'               => $t->status,
                'product_id'           => $t->product_id,
                'assigned_agent_email' => $t->agent_email,
                'assigned_agent_name'  => trim($t->first_name . ' ' . $t->last_name),
                'orphan_since'         => $orphan_since,
                'hours_orphaned'       => round($hours_orphaned, 1),
                'suggested_agent_email' => $suggestion['email'] ?? null,
                'suggested_agent_name'  => $suggestion['name'] ?? null,
                'suggested_reason'      => $suggestion['reason'] ?? null,
            ];
        }

        // Sort orphans: longest orphaned first
        usort($orphaned, function ($a, $b) {
            return $b['hours_orphaned'] <=> $a['hours_orphaned'];
        });

        // Step 5: Build queue balance (open ticket counts per on-shift agent)
        $queue_balance = [];
        if (!empty($on_shift_emails)) {
            // Get open ticket counts per agent
            $agent_counts = $wpdb->get_results(
                "SELECT p.email, COUNT(t.id) as open_count
                 FROM {$wpdb->prefix}fs_persons p
                 LEFT JOIN {$wpdb->prefix}fs_tickets t
                    ON t.agent_id = p.id AND t.status IN ('new', 'active', 'waiting')
                 WHERE p.email IN ('" . implode("','", array_map('esc_sql', $on_shift_emails)) . "')
                 GROUP BY p.email"
            );

            $count_map = [];
            foreach ($agent_counts as $ac) {
                $count_map[$ac->email] = intval($ac->open_count);
            }

            foreach ($on_shift_agents as $agent) {
                $queue_balance[] = array_merge($agent, [
                    'open_count' => $count_map[$agent['email']] ?? 0,
                ]);
            }

            // Sort by open_count descending (overloaded first)
            usort($queue_balance, function ($a, $b) {
                return $b['open_count'] <=> $a['open_count'];
            });
        }

        // Step 6: Store snapshot in transient
        $snapshot = [
            'orphaned_tickets' => $orphaned,
            'queue_balance'    => $queue_balance,
            'on_shift_count'   => count($on_shift_emails),
            'scanned_at'       => $now,
            'total_open'       => count($open_tickets),
        ];

        set_transient(self::TRANSIENT_KEY, $snapshot, self::TRANSIENT_TTL);

        return $snapshot;
    }

    /**
     * Get the latest watchdog snapshot
     */
    public static function get_snapshot() {
        return get_transient(self::TRANSIENT_KEY) ?: null;
    }

    /**
     * Suggest a replacement agent for an orphaned ticket
     */
    private function suggest_replacement($product_id, $on_shift_emails, $exclude_email) {
        global $wpdb;

        if (empty($on_shift_emails)) {
            return ['email' => null, 'name' => null, 'reason' => 'No agents on shift'];
        }

        $candidates = [];

        // Priority 1: Same team/product agents who are on shift
        if ($product_id) {
            $team_agents = $wpdb->get_results($wpdb->prepare(
                "SELECT tm.agent_email, a.first_name, a.last_name
                 FROM {$wpdb->prefix}ais_team_products tp
                 JOIN {$wpdb->prefix}ais_team_members tm ON tp.team_id = tm.team_id
                 LEFT JOIN {$wpdb->prefix}ais_agents a ON tm.agent_email = a.email
                 WHERE tp.product_id = %d",
                $product_id
            ));

            foreach ($team_agents as $ta) {
                if (in_array($ta->agent_email, $on_shift_emails) && $ta->agent_email !== $exclude_email) {
                    $candidates[] = [
                        'email' => $ta->agent_email,
                        'name'  => trim($ta->first_name . ' ' . $ta->last_name),
                        'priority' => 'team',
                    ];
                }
            }
        }

        // Priority 2: Any on-shift agent (fallback)
        if (empty($candidates)) {
            foreach ($on_shift_emails as $email) {
                if ($email === $exclude_email) continue;
                $agent = $wpdb->get_row($wpdb->prepare(
                    "SELECT first_name, last_name FROM {$wpdb->prefix}ais_agents WHERE email = %s",
                    $email
                ));
                $candidates[] = [
                    'email' => $email,
                    'name'  => $agent ? trim($agent->first_name . ' ' . $agent->last_name) : $email,
                    'priority' => 'any',
                ];
            }
        }

        if (empty($candidates)) {
            return ['email' => null, 'name' => null, 'reason' => 'No available agents'];
        }

        // Pick the one with the fewest open tickets
        $best = null;
        $lowest_count = PHP_INT_MAX;

        foreach ($candidates as $c) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fs_tickets t
                 JOIN {$wpdb->prefix}fs_persons p ON t.agent_id = p.id
                 WHERE p.email = %s AND t.status IN ('new', 'active', 'waiting')",
                $c['email']
            ));

            if ($count < $lowest_count) {
                $lowest_count = $count;
                $best = $c;
            }
        }

        $reason = $best['priority'] === 'team'
            ? "Same team, {$lowest_count} open tickets (lowest)"
            : "{$lowest_count} open tickets (lowest on shift)";

        return [
            'email'  => $best['email'],
            'name'   => $best['name'],
            'reason' => $reason,
        ];
    }
}
