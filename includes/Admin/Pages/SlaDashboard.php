<?php
/**
 * SLA Dashboard — Response Time & Timing Penalty Analytics
 *
 * Shows timing score distribution, SLA breach counts, worst-performing tickets,
 * and aging open tickets from FluentSupport.
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class SlaDashboard {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        $team_filter = AccessControl::sql_agent_filter('ae.agent_email');

        // KPI stats
        $kpis = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as total_evals,
                    ROUND(AVG(ae.timing_score), 1) as avg_timing,
                    SUM(CASE WHEN ae.timing_score < -30 THEN 1 ELSE 0 END) as severe_breaches,
                    SUM(CASE WHEN ae.timing_score < 0 AND ae.timing_score >= -30 THEN 1 ELSE 0 END) as minor_breaches,
                    SUM(CASE WHEN ae.timing_score = 0 THEN 1 ELSE 0 END) as on_time
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) >= %s {$team_filter}",
            $date_from
        ));

        // Timing score distribution (bucketed)
        $distribution = $wpdb->get_results($wpdb->prepare(
            "SELECT
                CASE
                    WHEN ae.timing_score = 0 THEN 'On Time (0)'
                    WHEN ae.timing_score >= -5 THEN 'Minor (-5)'
                    WHEN ae.timing_score >= -15 THEN 'Moderate (-15)'
                    WHEN ae.timing_score >= -30 THEN 'High (-30)'
                    WHEN ae.timing_score >= -50 THEN 'Severe (-50)'
                    ELSE 'Critical (-80+)'
                END as bucket,
                ae.timing_score as raw_bucket,
                COUNT(*) as cnt
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) >= %s {$team_filter}
             GROUP BY bucket, raw_bucket
             ORDER BY raw_bucket DESC",
            $date_from
        ));

        // Consolidate into proper buckets
        $buckets = ['On Time (0)' => 0, 'Minor (-5)' => 0, 'Moderate (-15)' => 0, 'High (-30)' => 0, 'Severe (-50)' => 0, 'Critical (-80+)' => 0];
        foreach ($distribution as $row) {
            $ts = intval($row->raw_bucket);
            if ($ts == 0) $buckets['On Time (0)'] += intval($row->cnt);
            elseif ($ts >= -5) $buckets['Minor (-5)'] += intval($row->cnt);
            elseif ($ts >= -15) $buckets['Moderate (-15)'] += intval($row->cnt);
            elseif ($ts >= -30) $buckets['High (-30)'] += intval($row->cnt);
            elseif ($ts >= -50) $buckets['Severe (-50)'] += intval($row->cnt);
            else $buckets['Critical (-80+)'] += intval($row->cnt);
        }

        // Per-agent timing performance
        $agent_timing = $wpdb->get_results($wpdb->prepare(
            "SELECT ae.agent_email, ae.agent_name,
                    COUNT(*) as evals,
                    ROUND(AVG(ae.timing_score), 1) as avg_timing,
                    SUM(CASE WHEN ae.timing_score < 0 THEN 1 ELSE 0 END) as breach_count,
                    MIN(ae.timing_score) as worst_timing
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) >= %s {$team_filter}
             GROUP BY ae.agent_email, ae.agent_name
             ORDER BY avg_timing ASC",
            $date_from
        ));

        // Daily timing trend
        $daily_timing = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(ae.created_at) as day,
                    ROUND(AVG(ae.timing_score), 1) as avg_timing,
                    SUM(CASE WHEN ae.timing_score < 0 THEN 1 ELSE 0 END) as breaches,
                    COUNT(*) as total
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) >= %s {$team_filter}
             GROUP BY DATE(ae.created_at)
             ORDER BY day ASC",
            $date_from
        ));

        // Worst timing tickets (most delayed)
        $worst_tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT ae.ticket_id, ae.agent_email, ae.agent_name, ae.timing_score, ae.created_at
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) >= %s AND ae.timing_score < -15 {$team_filter}
             ORDER BY ae.timing_score ASC
             LIMIT 15",
            $date_from
        ));

        // Aging open tickets from FluentSupport (if available)
        $aging_tickets = $this->get_aging_tickets();

        $base_url = admin_url('admin.php?page=ai-ops&section=sla');
        $this->render_page($days, $base_url, $kpis, $buckets, $agent_timing, $daily_timing, $worst_tickets, $aging_tickets);
    }

    private function get_aging_tickets() {
        if (!function_exists('FluentSupportApi')) return [];

        try {
            $api = \FluentSupportApi('tickets');
            $model = $api ? $api->getModel() : null;
            if (!$model) return [];

            $tickets = $model
                ->where('status', 'active')
                ->orderBy('last_customer_response', 'asc')
                ->limit(20)
                ->get();

            $aging = [];
            $now = current_time('timestamp');
            foreach ($tickets as $ticket) {
                $last_response = $ticket->last_customer_response ?: $ticket->created_at;
                $hours_waiting = round(($now - strtotime($last_response)) / 3600, 1);
                if ($hours_waiting > 2) {
                    $aging[] = (object)[
                        'id' => $ticket->id,
                        'title' => $ticket->title,
                        'customer_name' => $ticket->customer ? ($ticket->customer->first_name . ' ' . $ticket->customer->last_name) : 'Unknown',
                        'hours_waiting' => $hours_waiting,
                        'last_response' => $last_response,
                        'assigned_to' => $ticket->agent ? $ticket->agent->email : 'Unassigned',
                    ];
                }
            }
            return $aging;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function render_page($days, $base_url, $kpis, $buckets, $agent_timing, $daily_timing, $worst_tickets, $aging_tickets) {
        $breach_rate = $kpis->total_evals > 0 ? round((($kpis->severe_breaches + $kpis->minor_breaches) / $kpis->total_evals) * 100, 1) : 0;
        $on_time_rate = $kpis->total_evals > 0 ? round(($kpis->on_time / $kpis->total_evals) * 100, 1) : 0;
        ?>
        <style>
            .sla-severity { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; }
            .sla-severity.green { background:#dcfce7; color:#166534; }
            .sla-severity.yellow { background:#fef9c3; color:#854d0e; }
            .sla-severity.orange { background:#ffedd5; color:#9a3412; }
            .sla-severity.red { background:#fee2e2; color:#991b1b; }
        </style>

        <!-- Filters -->
        <div class="ops-card" style="margin-bottom:24px;">
            <div class="analytics-filters">
                <?php foreach ([7 => '7 Days', 30 => '30 Days', 60 => '60 Days', 90 => '90 Days'] as $d => $label): ?>
                    <a href="<?php echo esc_url(add_query_arg('days', $d, $base_url)); ?>"
                       class="ops-btn <?php echo $days == $d ? 'primary' : 'secondary'; ?>"
                       style="padding:0 16px;height:36px;font-size:13px;font-weight:500;border-radius:var(--radius-sm);"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">On-Time Rate</div>
                <div class="stat-value" style="<?php echo $on_time_rate >= 70 ? 'color:var(--color-success)' : ($on_time_rate >= 50 ? '' : 'color:var(--color-error)'); ?>"><?php echo $on_time_rate; ?>%</div>
                <div class="stat-change"><?php echo intval($kpis->on_time); ?> of <?php echo intval($kpis->total_evals); ?> evals</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg Timing Score</div>
                <div class="stat-value" style="<?php echo floatval($kpis->avg_timing) < -15 ? 'color:var(--color-error)' : ''; ?>"><?php echo $kpis->avg_timing ?: '0'; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">SLA Breaches</div>
                <div class="stat-value" style="color:var(--color-error);"><?php echo intval($kpis->severe_breaches) + intval($kpis->minor_breaches); ?></div>
                <div class="stat-change negative"><?php echo $breach_rate; ?>% breach rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Severe Delays</div>
                <div class="stat-value" style="color:var(--color-error);"><?php echo intval($kpis->severe_breaches); ?></div>
                <div class="stat-change">Timing score below -30</div>
            </div>
        </div>

        <!-- Charts row -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
            <div class="ops-card">
                <h3 style="font-size:15px;font-weight:600;margin:0 0 4px;">Timing Score Distribution</h3>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 16px;">How evaluations distribute across penalty levels</p>
                <div style="height:280px;"><canvas id="chart-sla-dist"></canvas></div>
            </div>
            <div class="ops-card">
                <h3 style="font-size:15px;font-weight:600;margin:0 0 4px;">Daily SLA Trend</h3>
                <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 16px;">Average timing score and breach count per day</p>
                <div style="height:280px;"><canvas id="chart-sla-trend"></canvas></div>
            </div>
        </div>

        <!-- Agent Timing Table -->
        <div class="ops-card" style="margin-bottom:24px;">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Agent Timing Performance</h3>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th style="text-align:center;">Evaluations</th>
                        <th style="text-align:center;">Avg Timing</th>
                        <th style="text-align:center;">Breaches</th>
                        <th style="text-align:center;">Breach Rate</th>
                        <th style="text-align:center;">Worst</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($agent_timing)): ?>
                        <tr><td colspan="6" class="ops-empty-state"><div class="ops-empty-state-title">No data</div></td></tr>
                    <?php else: foreach ($agent_timing as $at):
                        $br = $at->evals > 0 ? round(($at->breach_count / $at->evals) * 100) : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($at->agent_name ?: $at->agent_email); ?></strong></td>
                            <td style="text-align:center;"><?php echo $at->evals; ?></td>
                            <td style="text-align:center;">
                                <span class="sla-severity <?php echo $at->avg_timing == 0 ? 'green' : ($at->avg_timing >= -15 ? 'yellow' : ($at->avg_timing >= -30 ? 'orange' : 'red')); ?>">
                                    <?php echo $at->avg_timing; ?>
                                </span>
                            </td>
                            <td style="text-align:center;"><?php echo intval($at->breach_count); ?></td>
                            <td style="text-align:center;font-weight:600;color:<?php echo $br > 50 ? 'var(--color-error)' : ($br > 25 ? 'var(--color-warning)' : 'var(--color-text-primary)'); ?>;"><?php echo $br; ?>%</td>
                            <td style="text-align:center;"><span class="sla-severity red"><?php echo intval($at->worst_timing); ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Worst Tickets -->
        <?php if (!empty($worst_tickets)): ?>
        <div class="ops-card" style="margin-bottom:24px;">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Worst Delayed Tickets (timing &lt; -15)</h3>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Agent</th>
                        <th style="text-align:center;">Timing Score</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($worst_tickets as $wt): ?>
                    <tr>
                        <td><a href="<?php echo esc_url(admin_url('admin.php?page=fluent-support#/tickets/' . intval($wt->ticket_id))); ?>" target="_blank">#<?php echo esc_html($wt->ticket_id); ?></a></td>
                        <td><?php echo esc_html($wt->agent_name ?: $wt->agent_email); ?></td>
                        <td style="text-align:center;"><span class="sla-severity red"><?php echo intval($wt->timing_score); ?></span></td>
                        <td style="color:var(--color-text-secondary);font-size:12px;"><?php echo esc_html(wp_date('M j, g:ia', strtotime($wt->created_at))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Aging Open Tickets -->
        <?php if (!empty($aging_tickets)): ?>
        <div class="ops-card">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 4px;">Aging Open Tickets</h3>
            <p style="font-size:12px;color:var(--color-text-secondary);margin:0 0 16px;">Active tickets waiting 2+ hours for response</p>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Customer</th>
                        <th>Assigned To</th>
                        <th style="text-align:center;">Hours Waiting</th>
                        <th>Last Response</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($aging_tickets as $at):
                        $severity_class = $at->hours_waiting > 24 ? 'red' : ($at->hours_waiting > 8 ? 'orange' : ($at->hours_waiting > 4 ? 'yellow' : 'green'));
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(admin_url('admin.php?page=fluent-support#/tickets/' . intval($at->id))); ?>" target="_blank">#<?php echo esc_html($at->id); ?></a></td>
                        <td><?php echo esc_html($at->customer_name); ?></td>
                        <td><?php echo esc_html($at->assigned_to); ?></td>
                        <td style="text-align:center;"><span class="sla-severity <?php echo $severity_class; ?>"><?php echo $at->hours_waiting; ?>h</span></td>
                        <td style="color:var(--color-text-secondary);font-size:12px;"><?php echo esc_html(wp_date('M j, g:ia', strtotime($at->last_response))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <script>
        jQuery(document).ready(function($) {
            // Distribution chart
            new Chart(document.getElementById('chart-sla-dist'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_keys($buckets)); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_values($buckets)); ?>,
                        backgroundColor: ['#22c55e','#84cc16','#eab308','#f97316','#ef4444','#991b1b'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position:'right', labels: { usePointStyle:true, padding:12, font:{size:12} } }
                    },
                    cutout: '55%'
                }
            });

            // Daily trend chart
            new Chart(document.getElementById('chart-sla-trend'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($r) { return wp_date('M j', strtotime($r->day)); }, $daily_timing)); ?>,
                    datasets: [
                        {
                            label: 'Avg Timing Score',
                            data: <?php echo json_encode(array_map(function($r) { return floatval($r->avg_timing); }, $daily_timing)); ?>,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59,130,246,0.08)',
                            fill: true,
                            tension: 0.3,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Breaches',
                            data: <?php echo json_encode(array_map(function($r) { return intval($r->breaches); }, $daily_timing)); ?>,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239,68,68,0.3)',
                            type: 'bar',
                            yAxisID: 'y1',
                            borderRadius: 3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode:'index', intersect:false },
                    plugins: {
                        legend: { position:'bottom', labels: { usePointStyle:true, padding:12, font:{size:12} } }
                    },
                    scales: {
                        x: { grid:{display:false}, ticks:{font:{size:11}, maxRotation:45} },
                        y: { position:'left', grid:{color:'#f1f5f9'}, ticks:{font:{size:11}} },
                        y1: { position:'right', grid:{display:false}, ticks:{font:{size:11}}, beginAtZero:true }
                    }
                }
            });
        });
        </script>
        <?php
    }
}
