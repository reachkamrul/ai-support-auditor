<?php
/**
 * My Performance — Agent Self-Service Portal
 *
 * Shows the logged-in agent their own scores, trends, areas for improvement.
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class MyPerformance {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        $agent_email = AccessControl::get_agent_email();
        if (!$agent_email) {
            echo '<div class="ops-card"><div class="ops-empty-state"><div class="ops-empty-state-title">Your account is not linked to an agent profile</div><p>Please contact your admin to link your WordPress account to your agent record.</p></div></div>';
            return;
        }

        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $date_from = date('Y-m-d', strtotime("-{$days} days"));

        // Agent info
        $agent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_agents WHERE email = %s",
            $agent_email
        ));

        // Summary stats
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(DISTINCT ticket_id) as total_tickets,
                    ROUND(AVG(overall_agent_score), 1) as avg_score,
                    ROUND(AVG(timing_score), 1) as avg_timing,
                    ROUND(AVG(resolution_score), 1) as avg_resolution,
                    ROUND(AVG(communication_score), 1) as avg_communication,
                    SUM(reply_count) as total_replies
             FROM {$wpdb->prefix}ais_agent_evaluations
             WHERE agent_email = %s AND DATE(created_at) >= %s AND exclude_from_stats = 0",
            $agent_email, $date_from
        ));

        // Previous period for comparison
        $prev_from = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
        $prev_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT ROUND(AVG(overall_agent_score), 1) as avg_score,
                    ROUND(AVG(timing_score), 1) as avg_timing,
                    ROUND(AVG(resolution_score), 1) as avg_resolution,
                    ROUND(AVG(communication_score), 1) as avg_communication
             FROM {$wpdb->prefix}ais_agent_evaluations
             WHERE agent_email = %s AND DATE(created_at) BETWEEN %s AND %s AND exclude_from_stats = 0",
            $agent_email, $prev_from, $date_from
        ));

        // Daily trend
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as day,
                    ROUND(AVG(overall_agent_score), 1) as avg_score,
                    COUNT(DISTINCT ticket_id) as tickets
             FROM {$wpdb->prefix}ais_agent_evaluations
             WHERE agent_email = %s AND DATE(created_at) >= %s AND exclude_from_stats = 0
             GROUP BY DATE(created_at) ORDER BY day ASC",
            $agent_email, $date_from
        ));

        // Team average for comparison
        $team_avg = $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(AVG(overall_agent_score), 1)
             FROM {$wpdb->prefix}ais_agent_evaluations
             WHERE DATE(created_at) >= %s AND exclude_from_stats = 0",
            $date_from
        ));

        // Recent evaluations
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ticket_id, overall_agent_score, timing_score, resolution_score,
                    communication_score, reply_count, key_achievements, areas_for_improvement, created_at
             FROM {$wpdb->prefix}ais_agent_evaluations
             WHERE agent_email = %s AND DATE(created_at) >= %s
             ORDER BY created_at DESC LIMIT 15",
            $agent_email, $date_from
        ));

        // Flagged tickets
        $flagged = $wpdb->get_results($wpdb->prepare(
            "SELECT ft.ticket_id, ft.flag_type, ft.flag_reason, ft.status, ft.created_at
             FROM {$wpdb->prefix}ais_flagged_tickets ft
             INNER JOIN {$wpdb->prefix}ais_agent_evaluations ae ON ft.ticket_id = ae.ticket_id
             WHERE ae.agent_email = %s AND DATE(ft.created_at) >= %s
             ORDER BY ft.created_at DESC LIMIT 10",
            $agent_email, $date_from
        ));

        $base_url = admin_url('admin.php?page=ai-ops&section=my-performance');
        $this->render_page($agent, $stats, $prev_stats, $daily, $team_avg, $recent, $flagged, $days, $base_url);
    }

    private function render_page($agent, $stats, $prev_stats, $daily, $team_avg, $recent, $flagged, $days, $base_url) {
        $score_diff = ($stats->avg_score && $prev_stats->avg_score) ? round($stats->avg_score - $prev_stats->avg_score, 1) : null;
        ?>
        <style>
            .portal-header { display:flex; align-items:center; gap:20px; margin-bottom:24px; }
            .portal-avatar { width:56px; height:56px; border-radius:50%; background:var(--color-primary-light); color:var(--color-primary); display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:700; }
            .portal-name { font-size:20px; font-weight:700; color:var(--color-text-primary); }
            .portal-email { font-size:13px; color:var(--color-text-secondary); }
            .portal-vs-team { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
            .portal-vs-team.above { background:#dcfce7; color:#166534; }
            .portal-vs-team.below { background:#fee2e2; color:#991b1b; }
            .portal-vs-team.equal { background:#f1f5f9; color:#64748b; }
            .improvement-list { list-style:none; padding:0; margin:0; }
            .improvement-list li { padding:8px 0; border-bottom:1px solid var(--color-border); font-size:13px; color:var(--color-text-primary); display:flex; align-items:flex-start; gap:8px; }
            .improvement-list li:last-child { border-bottom:none; }
            .improvement-icon { width:18px; height:18px; flex-shrink:0; margin-top:1px; }
        </style>

        <!-- Agent Header -->
        <div class="ops-card">
            <div class="portal-header">
                <div class="portal-avatar">
                    <?php echo strtoupper(substr($agent->first_name ?: $agent->email, 0, 1)); ?>
                </div>
                <div>
                    <div class="portal-name"><?php echo esc_html(trim(($agent->first_name ?: '') . ' ' . ($agent->last_name ?: '')) ?: $agent->email); ?></div>
                    <div class="portal-email"><?php echo esc_html($agent->email); ?><?php if ($agent->title): ?> &middot; <?php echo esc_html($agent->title); ?><?php endif; ?></div>
                </div>
                <div style="margin-left:auto;">
                    <?php
                    $vs_class = 'equal';
                    $vs_text = 'At team average';
                    if ($stats->avg_score && $team_avg) {
                        $diff = $stats->avg_score - $team_avg;
                        if ($diff > 2) { $vs_class = 'above'; $vs_text = '+' . round($diff, 1) . ' above team avg'; }
                        elseif ($diff < -2) { $vs_class = 'below'; $vs_text = round($diff, 1) . ' below team avg'; }
                    }
                    ?>
                    <span class="portal-vs-team <?php echo $vs_class; ?>"><?php echo $vs_text; ?></span>
                </div>
            </div>
            <div class="analytics-filters" style="margin-top:12px;">
                <?php foreach ([7 => '7 Days', 30 => '30 Days', 60 => '60 Days', 90 => '90 Days'] as $d => $label): ?>
                    <a href="<?php echo esc_url(add_query_arg('days', $d, $base_url)); ?>"
                       class="ops-btn <?php echo $days == $d ? 'primary' : 'secondary'; ?>"
                       style="padding:0 14px;height:34px;font-size:13px;font-weight:500;border-radius:var(--radius-sm);"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="stats-grid" style="margin-top:24px;">
            <div class="stat-card">
                <div class="stat-label">My Score</div>
                <div class="stat-value"><?php echo $stats->avg_score ?: '-'; ?></div>
                <?php if ($score_diff !== null): ?>
                <div class="stat-change <?php echo $score_diff > 0 ? 'positive' : ($score_diff < 0 ? 'negative' : ''); ?>">
                    <?php echo $score_diff > 0 ? '▲ +' : ($score_diff < 0 ? '▼ ' : '→ '); ?><?php echo abs($score_diff); ?> vs prev period
                </div>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Tickets Handled</div>
                <div class="stat-value"><?php echo intval($stats->total_tickets); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Replies</div>
                <div class="stat-value"><?php echo intval($stats->total_replies); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Timing</div>
                <div class="stat-value" style="<?php echo floatval($stats->avg_timing) < -15 ? 'color:var(--color-error)' : ''; ?>"><?php echo $stats->avg_timing ?: '0'; ?></div>
            </div>
        </div>

        <!-- Score Trend Chart -->
        <div class="ops-card" style="margin-top:24px;">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">My Score Trend</h3>
            <div style="height:280px;"><canvas id="chart-my-trend"></canvas></div>
        </div>

        <!-- Score Breakdown -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px;">
            <div class="ops-card">
                <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Score Breakdown</h3>
                <div style="max-width:300px;margin:0 auto;"><canvas id="chart-my-radar"></canvas></div>
            </div>
            <div class="ops-card">
                <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Areas for Improvement</h3>
                <?php
                $improvements = [];
                foreach ($recent as $r) {
                    if (!empty($r->areas_for_improvement)) {
                        $items = json_decode($r->areas_for_improvement, true);
                        if (is_array($items)) {
                            foreach ($items as $item) {
                                $text = is_string($item) ? $item : ($item['description'] ?? $item['area'] ?? '');
                                if ($text && !in_array($text, $improvements)) $improvements[] = $text;
                            }
                        } elseif (is_string($r->areas_for_improvement)) {
                            $improvements[] = $r->areas_for_improvement;
                        }
                    }
                }
                $improvements = array_slice(array_unique($improvements), 0, 8);
                ?>
                <?php if (empty($improvements)): ?>
                    <div class="ops-empty-state" style="padding:30px;"><div class="ops-empty-state-title">No improvement areas flagged</div></div>
                <?php else: ?>
                    <ul class="improvement-list">
                        <?php foreach ($improvements as $imp): ?>
                        <li>
                            <span class="improvement-icon" style="color:var(--color-warning);">&#9888;</span>
                            <?php echo esc_html($imp); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Flagged Tickets (if any) -->
        <?php if (!empty($flagged)): ?>
        <div class="ops-card" style="margin-top:24px;">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Flagged Tickets</h3>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Flag Type</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($flagged as $f): ?>
                    <tr>
                        <td><strong>#<?php echo esc_html($f->ticket_id); ?></strong></td>
                        <td><span class="status-badge <?php echo $f->flag_type === 'low_score' ? 'failed' : ''; ?>"><?php echo esc_html(str_replace('_', ' ', $f->flag_type)); ?></span></td>
                        <td style="font-size:12px;color:var(--color-text-secondary);max-width:300px;"><?php echo esc_html(substr($f->flag_reason, 0, 120)); ?></td>
                        <td><span class="status-badge <?php echo $f->status === 'dismissed' ? 'success' : ($f->status === 'reviewed' ? '' : 'pending'); ?>"><?php echo esc_html($f->status); ?></span></td>
                        <td style="font-size:12px;color:var(--color-text-secondary);"><?php echo date('M j', strtotime($f->created_at)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Recent Evaluations -->
        <div class="ops-card" style="margin-top:24px;">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Recent Evaluations</h3>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th style="text-align:center;">Overall</th>
                        <th style="text-align:center;">Timing</th>
                        <th style="text-align:center;">Resolution</th>
                        <th style="text-align:center;">Communication</th>
                        <th style="text-align:center;">Replies</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent)): ?>
                        <tr><td colspan="8" class="ops-empty-state"><div class="ops-empty-state-title">No evaluations yet</div></td></tr>
                    <?php else: foreach ($recent as $r): ?>
                    <tr>
                        <td><strong>#<?php echo esc_html($r->ticket_id); ?></strong></td>
                        <td style="text-align:center;"><span class="col-score <?php echo Dashboard::score_class(intval($r->overall_agent_score)); ?>"><?php echo intval($r->overall_agent_score); ?></span></td>
                        <td style="text-align:center;"><?php echo intval($r->timing_score); ?></td>
                        <td style="text-align:center;"><?php echo intval($r->resolution_score); ?></td>
                        <td style="text-align:center;"><?php echo intval($r->communication_score); ?></td>
                        <td style="text-align:center;"><?php echo intval($r->reply_count); ?></td>
                        <td style="font-size:12px;color:var(--color-text-secondary);"><?php echo date('M j, g:ia', strtotime($r->created_at)); ?></td>
                        <td>
                            <button class="ops-btn secondary" style="font-size:11px;padding:2px 10px;height:24px;"
                                onclick="openAppealModal(<?php echo intval($r->id); ?>, '<?php echo esc_js($r->ticket_id); ?>')">
                                Appeal
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Appeal Modal -->
        <div id="appeal-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:none;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:var(--radius-lg);padding:24px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <h3 style="margin:0 0 16px;font-size:16px;font-weight:700;">Appeal Audit Score</h3>
                <input type="hidden" id="appeal-eval-id">
                <input type="hidden" id="appeal-ticket-id">
                <div style="margin-bottom:12px;">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:var(--color-text-secondary);">Which score do you disagree with?</label>
                    <select id="appeal-field" style="width:100%;height:34px;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:0 10px;font-size:13px;">
                        <option value="">General appeal (all scores)</option>
                        <option value="timing_score">Timing Score</option>
                        <option value="resolution_score">Resolution Score</option>
                        <option value="communication_score">Communication Score</option>
                        <option value="overall_agent_score">Overall Score</option>
                    </select>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:var(--color-text-secondary);">Why do you disagree? (required)</label>
                    <textarea id="appeal-reason" rows="4" style="width:100%;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:8px 10px;font-size:13px;resize:vertical;" placeholder="Explain why you believe this score is incorrect..."></textarea>
                </div>
                <div id="appeal-modal-msg" style="display:none;padding:8px 12px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:12px;"></div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button class="ops-btn secondary" onclick="closeAppealModal()" style="height:34px;font-size:13px;">Cancel</button>
                    <button class="ops-btn primary" onclick="submitAppeal()" style="height:34px;font-size:13px;">Submit Appeal</button>
                </div>
            </div>
        </div>

        <!-- My Appeals History -->
        <div class="ops-card" style="margin-top:24px;">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">My Appeals</h3>
            <div id="my-appeals-list">
                <div class="ops-empty-state" style="padding:20px;"><div class="ops-empty-state-title">Loading...</div></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Trend chart
            new Chart(document.getElementById('chart-my-trend'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($r) { return date('M j', strtotime($r->day)); }, $daily)); ?>,
                    datasets: [
                        {
                            label: 'My Score',
                            data: <?php echo json_encode(array_map(function($r) { return floatval($r->avg_score); }, $daily)); ?>,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59,130,246,0.08)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3
                        },
                        {
                            label: 'Team Average',
                            data: <?php echo json_encode(array_map(function() use ($team_avg) { return floatval($team_avg); }, $daily)); ?>,
                            borderColor: '#94a3b8',
                            borderDash: [6,3],
                            tension: 0,
                            pointRadius: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode:'index', intersect:false },
                    plugins: { legend: { position:'bottom', labels: { usePointStyle:true, padding:16, font:{size:12} } } },
                    scales: {
                        x: { grid:{display:false}, ticks:{font:{size:11}, maxRotation:45} },
                        y: { grid:{color:'#f1f5f9'}, ticks:{font:{size:11}} }
                    }
                }
            });

            // Radar chart
            new Chart(document.getElementById('chart-my-radar'), {
                type: 'radar',
                data: {
                    labels: ['Timing', 'Resolution', 'Communication'],
                    datasets: [{
                        label: 'My Scores',
                        data: [<?php echo floatval($stats->avg_timing); ?>, <?php echo floatval($stats->avg_resolution); ?>, <?php echo floatval($stats->avg_communication); ?>],
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59,130,246,0.15)',
                        pointBackgroundColor: '#3b82f6'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display:false } },
                    scales: { r: { grid:{color:'#e2e8f0'}, pointLabels:{font:{size:13}} } }
                }
            });
            // Appeal functions
            window.openAppealModal = function(evalId, ticketId) {
                $('#appeal-eval-id').val(evalId);
                $('#appeal-ticket-id').val(ticketId);
                $('#appeal-reason').val('');
                $('#appeal-field').val('');
                $('#appeal-modal-msg').hide();
                $('#appeal-modal').css('display', 'flex');
            };

            window.closeAppealModal = function() {
                $('#appeal-modal').hide();
            };

            window.submitAppeal = function() {
                var reason = $('#appeal-reason').val().trim();
                if (!reason) {
                    $('#appeal-modal-msg').css({display:'block', background:'#fee2e2', color:'#991b1b'}).text('Please provide a reason for your appeal.');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'ai_audit_submit_appeal',
                    _ajax_nonce: '<?php echo wp_create_nonce('ais_appeal_nonce'); ?>',
                    ticket_id: $('#appeal-ticket-id').val(),
                    eval_id: $('#appeal-eval-id').val(),
                    appeal_type: 'score_dispute',
                    disputed_field: $('#appeal-field').val(),
                    reason: reason
                }, function(res) {
                    if (res.success) {
                        $('#appeal-modal-msg').css({display:'block', background:'#dcfce7', color:'#166534'}).text('Appeal submitted. Your lead will review it.');
                        setTimeout(function() { closeAppealModal(); loadMyAppeals(); }, 1500);
                    } else {
                        $('#appeal-modal-msg').css({display:'block', background:'#fee2e2', color:'#991b1b'}).text(res.data || 'Failed to submit.');
                    }
                });
            };

            function loadMyAppeals() {
                $.post(ajaxurl, {
                    action: 'ai_audit_get_my_appeals',
                    _ajax_nonce: '<?php echo wp_create_nonce('ais_appeal_nonce'); ?>'
                }, function(res) {
                    var container = $('#my-appeals-list');
                    if (!res.success || !res.data.length) {
                        container.html('<div class="ops-empty-state" style="padding:20px;"><div class="ops-empty-state-title">No appeals submitted yet</div></div>');
                        return;
                    }
                    var html = '<table class="audit-table"><thead><tr><th>Ticket</th><th>Field</th><th>Reason</th><th>Status</th><th>Resolution</th><th>Date</th></tr></thead><tbody>';
                    res.data.forEach(function(a) {
                        var statusClass = a.status === 'approved' ? 'success' : (a.status === 'rejected' ? 'failed' : 'pending');
                        html += '<tr>';
                        html += '<td><strong>#' + a.ticket_id + '</strong></td>';
                        html += '<td style="font-size:12px;">' + (a.disputed_field ? a.disputed_field.replace('_', ' ') : 'General') + '</td>';
                        html += '<td style="font-size:12px;color:var(--color-text-secondary);max-width:250px;">' + (a.reason || '').substring(0, 100) + '</td>';
                        html += '<td><span class="status-badge ' + statusClass + '">' + a.status + '</span></td>';
                        html += '<td style="font-size:12px;color:var(--color-text-secondary);">' + (a.resolution_notes || '-') + '</td>';
                        html += '<td style="font-size:12px;color:var(--color-text-secondary);">' + (a.created_at || '').substring(0, 10) + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    container.html(html);
                });
            }
            loadMyAppeals();
        });
        </script>
        <?php
    }
}
