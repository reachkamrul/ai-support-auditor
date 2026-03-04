<?php
/**
 * Dashboard Page — KPI overview, flagged tickets, recent audits
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class Dashboard {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        $now = current_time('Y-m-d');
        $thirty_ago = date('Y-m-d', strtotime('-30 days'));
        $sixty_ago  = date('Y-m-d', strtotime('-60 days'));

        // --- KPI Data ---
        $audits_30   = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ais_audits WHERE status='success' AND DATE(created_at) >= %s", $thirty_ago
        ));
        $audits_prev = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ais_audits WHERE status='success' AND DATE(created_at) BETWEEN %s AND %s", $sixty_ago, $thirty_ago
        ));

        $avg_score_30  = $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(AVG(overall_score),1) FROM {$wpdb->prefix}ais_audits WHERE status='success' AND DATE(created_at) >= %s", $thirty_ago
        ));
        $avg_score_prev = $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(AVG(overall_score),1) FROM {$wpdb->prefix}ais_audits WHERE status='success' AND DATE(created_at) BETWEEN %s AND %s", $sixty_ago, $thirty_ago
        ));

        $active_agents = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT agent_email) FROM {$wpdb->prefix}ais_agent_evaluations WHERE DATE(created_at) >= %s", $thirty_ago
        ));

        // Flagged tickets count
        $flagged_table = $wpdb->prefix . 'ais_flagged_tickets';
        $flagged_exists = $wpdb->get_var("SHOW TABLES LIKE '{$flagged_table}'");
        $flagged_count = 0;
        $flagged_tickets = [];
        if ($flagged_exists) {
            $flagged_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$flagged_table} WHERE status = 'needs_review'");
            $flagged_tickets = $wpdb->get_results(
                "SELECT f.*, a.overall_score, a.overall_sentiment
                 FROM {$flagged_table} f
                 LEFT JOIN {$wpdb->prefix}ais_audits a ON f.audit_id = a.id
                 WHERE f.status = 'needs_review'
                 ORDER BY f.created_at DESC LIMIT 5"
            );
        }

        // Watchdog snapshot (orphaned tickets + queue balance)
        $watchdog = \SupportOps\Services\TicketWatchdog::get_snapshot();

        // Recent audits
        $recent_audits = $wpdb->get_results(
            "SELECT ticket_id, overall_score, overall_sentiment, status, created_at
             FROM {$wpdb->prefix}ais_audits
             WHERE status = 'success'
             ORDER BY created_at DESC LIMIT 10"
        );

        // Agents on shift today
        $today_start = current_time('Y-m-d') . ' 00:00:00';
        $today_end   = current_time('Y-m-d') . ' 23:59:59';
        $on_shift_today = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.agent_email, a.first_name, a.last_name, s.shift_type, s.shift_color
             FROM {$wpdb->prefix}ais_agent_shifts s
             LEFT JOIN {$wpdb->prefix}ais_agents a ON s.agent_email = a.email
             WHERE s.shift_start <= %s AND s.shift_end >= %s
             ORDER BY s.shift_start",
            $today_end, $today_start
        ));

        // Trend helpers
        $audit_trend = $this->trend($audits_30, $audits_prev);
        $score_trend = $this->trend(floatval($avg_score_30), floatval($avg_score_prev));

        ?>
        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total audits (30d)</div>
                <div class="stat-value"><?php echo $audits_30; ?></div>
                <?php echo $audit_trend; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Average score</div>
                <div class="stat-value">
                    <span class="col-score <?php echo self::score_class(floatval($avg_score_30)); ?>"><?php echo $avg_score_30 ?? '—'; ?></span>
                </div>
                <?php echo $score_trend; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active agents (30d)</div>
                <div class="stat-value"><?php echo $active_agents; ?></div>
            </div>
            <div class="stat-card <?php echo $flagged_count > 0 ? 'ops-card--accent-error' : ''; ?>">
                <div class="stat-label">Flagged tickets</div>
                <div class="stat-value" style="<?php echo $flagged_count > 0 ? 'color:var(--color-error);' : ''; ?>"><?php echo $flagged_count; ?></div>
                <?php if ($flagged_count > 0): ?>
                    <a href="<?php echo admin_url('admin.php?page=ai-ops&section=flagged'); ?>" style="font-size:12px;">View all &rarr;</a>
                <?php else: ?>
                    <div class="stat-change positive">All clear</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Orphaned Tickets (Watchdog) -->
        <?php $orphan_count = $watchdog ? count($watchdog['orphaned_tickets']) : 0; ?>
        <div class="ops-card <?php echo $orphan_count > 0 ? 'watchdog-alert' : 'watchdog-clear'; ?>">
            <div class="ops-card-header">
                <div style="display:flex;align-items:center;gap:8px;">
                    <h3 style="margin:0;">Orphaned tickets</h3>
                    <?php if ($orphan_count > 0): ?><span class="status-badge failed"><?php echo $orphan_count; ?></span><?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <?php if ($watchdog): ?>
                        <span id="watchdog-last-scan" style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);">Last scanned: <?php echo $this->time_ago($watchdog['scanned_at']); ?></span>
                    <?php endif; ?>
                    <button type="button" id="watchdog-sync-btn" class="ops-btn secondary" style="height:30px;padding:0 10px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                        <span id="watchdog-sync-text">Sync Now</span>
                    </button>
                </div>
            </div>
            <?php if (!$watchdog): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">Watchdog not active yet</div>
                    <div class="ops-empty-state-description">It will scan every 15 minutes once the cron is running.</div>
                </div>
            <?php elseif ($orphan_count === 0): ?>
                <div style="color:var(--color-success);text-align:center;padding:12px 0;">
                    All <?php echo $watchdog['total_open']; ?> open tickets have on-shift agents assigned. <?php echo $watchdog['on_shift_count']; ?> agents on shift.
                </div>
            <?php else:
                $per_page = 10;
                $total_pages = ceil($orphan_count / $per_page);
            ?>
                <table class="audit-table" style="font-size:13px;">
                    <thead><tr><th>Ticket</th><th>Assigned Agent</th><th>Status</th><th>Orphaned</th><th>Suggested Replacement</th><th></th></tr></thead>
                    <tbody id="orphan-tbody">
                    <?php foreach ($watchdog['orphaned_tickets'] as $i => $o): ?>
                        <tr class="orphan-row" data-page="<?php echo floor($i / $per_page) + 1; ?>" <?php echo $i >= $per_page ? 'style="display:none;"' : ''; ?>>
                            <td><strong>#<?php echo esc_html($o['ticket_id']); ?></strong><br><span style="font-size:11px;color:var(--color-text-tertiary);"><?php echo esc_html(mb_strimwidth($o['title'], 0, 40, '...')); ?></span></td>
                            <td>
                                <?php echo esc_html($o['assigned_agent_name']); ?>
                                <span class="badge-offshift">OFF-SHIFT</span>
                            </td>
                            <td><span class="status-badge pending"><?php echo esc_html(ucfirst($o['status'])); ?></span></td>
                            <td style="color:var(--color-error);font-weight:600;"><?php echo $this->format_hours($o['hours_orphaned']); ?></td>
                            <td>
                                <?php if ($o['suggested_agent_name']): ?>
                                    <?php echo esc_html($o['suggested_agent_name']); ?>
                                    <span class="badge-onshift">ON-SHIFT</span>
                                    <br><span style="font-size:10px;color:var(--color-text-tertiary);"><?php echo esc_html($o['suggested_reason']); ?></span>
                                <?php else: ?>
                                    <span style="color:var(--color-text-tertiary);">No suggestion</span>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo admin_url('admin.php?page=fluent-support#/tickets/' . $o['ticket_id'] . '/view'); ?>" class="ops-btn secondary" style="font-size:11px;height:28px;padding:0 8px;" target="_blank">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($total_pages > 1): ?>
                <div class="ops-pagination" id="orphan-pagination" style="justify-content:center;">
                    <button type="button" id="orphan-prev" class="ops-btn secondary" style="height:30px;" disabled>&laquo; Prev</button>
                    <span id="orphan-page-info" class="ops-pagination-info">Page 1 of <?php echo $total_pages; ?> (<?php echo $orphan_count; ?> tickets)</span>
                    <button type="button" id="orphan-next" class="ops-btn secondary" style="height:30px;">Next &raquo;</button>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Two-column layout: Flagged Preview + On Shift Today -->
        <div class="ops-grid-2">

            <!-- Flagged Tickets Preview -->
            <div class="ops-card">
                <div class="ops-card-header">
                    <h3 style="margin:0;">Tickets needing attention</h3>
                    <?php if ($flagged_count > 0): ?>
                        <a href="<?php echo admin_url('admin.php?page=ai-ops&section=flagged'); ?>" class="ops-btn secondary" style="height:30px;">View all (<?php echo $flagged_count; ?>)</a>
                    <?php endif; ?>
                </div>
                <?php if (!$flagged_exists): ?>
                    <div class="ops-empty-state">
                        <div class="ops-empty-state-title">Not active yet</div>
                        <div class="ops-empty-state-description">Flagged tickets will appear here once the auto-flagging system is active.</div>
                    </div>
                <?php elseif (empty($flagged_tickets)): ?>
                    <div style="color:var(--color-success);text-align:center;padding:20px 0;">
                        No tickets need review right now.
                    </div>
                <?php else: ?>
                    <table class="audit-table" style="font-size:13px;">
                        <thead><tr><th>Ticket</th><th>Flag</th><th>Score</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($flagged_tickets as $ft): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($ft->ticket_id); ?></strong></td>
                                <td><?php echo $this->flag_badge($ft->flag_type); ?></td>
                                <td><span class="col-score <?php echo self::score_class(intval($ft->overall_score)); ?>"><?php echo intval($ft->overall_score); ?></span></td>
                                <td style="color:var(--color-text-tertiary);"><?php echo date('M j', strtotime($ft->created_at)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- On Shift Today + Queue Balance -->
            <div class="ops-card">
                <h3>On shift today</h3>
                <?php
                // Build queue map: prefer watchdog snapshot, fallback to live DB query
                $queue_data = $watchdog ? $watchdog['queue_balance'] : [];
                $queue_map = [];
                foreach ($queue_data as $qb) { $queue_map[$qb['email']] = $qb['open_count']; }
                // Fill gaps for on-shift agents not in watchdog snapshot
                if (!empty($on_shift_today)) {
                    $missing = [];
                    foreach ($on_shift_today as $a) {
                        if (!isset($queue_map[$a->agent_email])) { $missing[] = $a->agent_email; }
                    }
                    if (!empty($missing)) {
                        $live_counts = $wpdb->get_results(
                            "SELECT p.email, COUNT(t.id) as open_count
                             FROM {$wpdb->prefix}fs_persons p
                             LEFT JOIN {$wpdb->prefix}fs_tickets t ON t.agent_id = p.id AND t.status IN ('new','active','waiting')
                             WHERE p.email IN ('" . implode("','", array_map('esc_sql', $missing)) . "')
                             GROUP BY p.email"
                        );
                        foreach ($live_counts as $lc) { $queue_map[$lc->email] = intval($lc->open_count); }
                    }
                }
                ?>
                <?php if (empty($on_shift_today)): ?>
                    <div class="ops-empty-state">
                        <div class="ops-empty-state-title">No shifts scheduled</div>
                        <div class="ops-empty-state-description">No agents have shifts assigned for today.</div>
                    </div>
                <?php else: ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach ($on_shift_today as $agent):
                            $open_count = $queue_map[$agent->agent_email] ?? 0;
                            $q_class = 'low';
                            if ($open_count >= 7) $q_class = 'high';
                            elseif ($open_count >= 4) $q_class = 'medium';
                        ?>
                            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--color-bg-subtle);border-radius:var(--radius-sm);border:1px solid var(--color-border);">
                                <div style="width:32px;height:32px;border-radius:50%;background:var(--color-primary-light);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:var(--color-primary);">
                                    <?php echo strtoupper(substr($agent->first_name ?: $agent->agent_email, 0, 1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:13px;">
                                        <?php echo esc_html(trim(($agent->first_name ?: '') . ' ' . ($agent->last_name ?: '')) ?: $agent->agent_email); ?>
                                        <span class="queue-count <?php echo $q_class; ?>" title="Open tickets"><?php echo $open_count; ?></span>
                                    </div>
                                    <span class="shift-pill" style="background:<?php echo esc_attr($agent->shift_color ?: '#f1f5f9'); ?>;font-size:10px;padding:2px 6px;margin:0;"><?php echo esc_html($agent->shift_type); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Audits -->
        <div class="ops-card">
            <div class="ops-card-header">
                <h3 style="margin:0;">Recent audits</h3>
                <a href="<?php echo admin_url('admin.php?page=ai-ops&section=audits'); ?>" class="ops-btn secondary" style="height:30px;">View all &rarr;</a>
            </div>
            <?php if (empty($recent_audits)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No audits yet</div>
                    <div class="ops-empty-state-description">Audits will appear here once tickets are processed by the AI workflow.</div>
                </div>
            <?php else: ?>
                <table class="audit-table" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Ticket ID</th>
                            <th>Score</th>
                            <th>Sentiment</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_audits as $audit): ?>
                        <tr>
                            <td><strong>#<?php echo esc_html($audit->ticket_id); ?></strong></td>
                            <td><span class="col-score <?php echo self::score_class(intval($audit->overall_score)); ?>"><?php echo intval($audit->overall_score); ?></span></td>
                            <td>
                                <?php
                                $s = $audit->overall_sentiment ?: 'Unknown';
                                $sc = $s === 'Positive' ? 'success' : ($s === 'Negative' ? 'failed' : 'warning');
                                ?>
                                <span class="status-badge <?php echo $sc; ?>"><?php echo esc_html($s); ?></span>
                            </td>
                            <td style="color:var(--color-text-tertiary);"><?php echo date('M j, Y H:i', strtotime($audit->created_at)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        (function(){
            var btn = document.getElementById('watchdog-sync-btn');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var txt = document.getElementById('watchdog-sync-text');
                btn.disabled = true;
                txt.textContent = 'Syncing...';
                var fd = new FormData();
                fd.append('action', 'ai_watchdog_sync');
                fd.append('nonce', '<?php echo wp_create_nonce('ai_ops_nonce'); ?>');
                fetch(ajaxurl, {method:'POST', body:fd})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if (res.success) {
                            location.reload();
                        } else {
                            txt.textContent = 'Failed';
                            btn.disabled = false;
                            setTimeout(function(){ txt.textContent = 'Sync Now'; }, 2000);
                        }
                    })
                    .catch(function(){
                        txt.textContent = 'Error';
                        btn.disabled = false;
                        setTimeout(function(){ txt.textContent = 'Sync Now'; }, 2000);
                    });
            });
        })();
        // Orphan pagination
        (function(){
            var total = <?php echo $total_pages ?? 1; ?>, cur = 1;
            var prev = document.getElementById('orphan-prev');
            var next = document.getElementById('orphan-next');
            var info = document.getElementById('orphan-page-info');
            if (!prev || !next) return;
            function show(page) {
                cur = page;
                var rows = document.querySelectorAll('.orphan-row');
                rows.forEach(function(r){ r.style.display = r.getAttribute('data-page') == page ? '' : 'none'; });
                prev.disabled = (cur <= 1);
                next.disabled = (cur >= total);
                info.textContent = 'Page ' + cur + ' of ' + total + ' (<?php echo $orphan_count; ?> tickets)';
            }
            prev.addEventListener('click', function(){ if (cur > 1) show(cur - 1); });
            next.addEventListener('click', function(){ if (cur < total) show(cur + 1); });
        })();
        </script>
        <?php
    }

    private function trend($current, $previous) {
        if (!$previous || !$current) return '';
        $diff = $current - $previous;
        $pct  = $previous != 0 ? round(($diff / abs($previous)) * 100) : 0;
        $cls  = $diff >= 0 ? 'positive' : 'negative';
        $arrow = $diff >= 0 ? '&#9650;' : '&#9660;';
        return '<div class="stat-change ' . $cls . '">' . $arrow . ' ' . abs($pct) . '% vs prev 30d</div>';
    }

    private function flag_badge($type) {
        $map = [
            'low_score'       => ['Low Score',   'failed'],
            'problem_context' => ['Problem',     'warning'],
            'long_delay'      => ['Long Delay',  'pending'],
        ];
        $info = $map[$type] ?? ['Unknown', 'pending'];
        return '<span class="status-badge ' . $info[1] . '">' . $info[0] . '</span>';
    }

    public static function score_class($score) {
        if ($score < 0 || $score < 40)  return 'score-negative';
        if ($score < 60)                return 'score-warning';
        if ($score < 80)                return 'score-ok';
        return 'score-good';
    }

    private function time_ago($datetime) {
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return date('M j, H:i', strtotime($datetime));
    }

    private function format_hours($hours) {
        if ($hours < 1) return round($hours * 60) . 'm';
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}
