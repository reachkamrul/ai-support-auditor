<?php
/**
 * Score Trends — Historical Charts
 *
 * Line charts for agent scores over time, team averages, problem category trends.
 * Uses Chart.js (already loaded globally via Assets.php).
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class ScoreTrends {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        $selected_agent = isset($_GET['agent']) ? sanitize_email($_GET['agent']) : '';

        $team_filter = AccessControl::sql_agent_filter('ae.agent_email');

        // Get list of agents for dropdown
        $agents = $wpdb->get_results(
            "SELECT DISTINCT ae.agent_email, ae.agent_name
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE 1=1 {$team_filter}
             ORDER BY ae.agent_name ASC"
        );

        // Daily score averages (all agents in scope)
        $daily_scores = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(ae.created_at) as day,
                    ROUND(AVG(ae.overall_agent_score), 1) as avg_score,
                    ROUND(AVG(ae.timing_score), 1) as avg_timing,
                    ROUND(AVG(ae.resolution_score), 1) as avg_resolution,
                    ROUND(AVG(ae.communication_score), 1) as avg_communication,
                    COUNT(DISTINCT ae.ticket_id) as tickets
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) >= %s {$team_filter}
             GROUP BY DATE(ae.created_at)
             ORDER BY day ASC",
            $date_from
        ));

        // Per-agent daily scores (if agent selected)
        $agent_daily = [];
        if ($selected_agent) {
            $agent_daily = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(ae.created_at) as day,
                        ROUND(AVG(ae.overall_agent_score), 1) as avg_score,
                        ROUND(AVG(ae.timing_score), 1) as avg_timing,
                        ROUND(AVG(ae.resolution_score), 1) as avg_resolution,
                        ROUND(AVG(ae.communication_score), 1) as avg_communication
                 FROM {$wpdb->prefix}ais_agent_evaluations ae
                 WHERE ae.agent_email = %s AND DATE(ae.created_at) >= %s
                 GROUP BY DATE(ae.created_at)
                 ORDER BY day ASC",
                $selected_agent, $date_from
            ));
        }

        // Weekly problem category trend
        $problem_trend = $wpdb->get_results($wpdb->prepare(
            "SELECT YEARWEEK(a.created_at, 1) as yw,
                    MIN(DATE(a.created_at)) as week_start,
                    pc.category,
                    COUNT(*) as cnt
             FROM {$wpdb->prefix}ais_problem_contexts pc
             INNER JOIN {$wpdb->prefix}ais_audits a ON pc.ticket_id = a.ticket_id
             WHERE a.status = 'success' AND DATE(a.created_at) >= %s
                   AND pc.category IS NOT NULL AND pc.category != ''
             GROUP BY yw, pc.category
             ORDER BY yw ASC",
            $date_from
        ));

        // Build problem trend data grouped by week
        $weeks = [];
        $categories = [];
        foreach ($problem_trend as $row) {
            $week_label = date('M j', strtotime($row->week_start));
            $weeks[$week_label] = $week_label;
            $categories[$row->category][$week_label] = intval($row->cnt);
        }

        // Per-team weekly average (for team comparison)
        $team_weekly = [];
        if (AccessControl::is_admin()) {
            $team_weekly = $wpdb->get_results($wpdb->prepare(
                "SELECT MIN(DATE(ae.created_at)) as week_start,
                        t.name as team_name,
                        ROUND(AVG(ae.overall_agent_score), 1) as avg_score
                 FROM {$wpdb->prefix}ais_agent_evaluations ae
                 INNER JOIN {$wpdb->prefix}ais_team_members tm ON ae.agent_email = tm.agent_email
                 INNER JOIN {$wpdb->prefix}ais_teams t ON tm.team_id = t.id
                 WHERE DATE(ae.created_at) >= %s
                 GROUP BY YEARWEEK(ae.created_at, 1), t.id, t.name
                 ORDER BY week_start ASC",
                $date_from
            ));
        }

        // Build team trend data
        $team_weeks = [];
        $team_names = [];
        foreach ($team_weekly as $row) {
            $wl = date('M j', strtotime($row->week_start));
            $team_weeks[$wl] = $wl;
            $team_names[$row->team_name][$wl] = floatval($row->avg_score);
        }

        $this->render_page($days, $selected_agent, $agents, $daily_scores, $agent_daily, $weeks, $categories, $team_weeks, $team_names);
    }

    private function render_page($days, $selected_agent, $agents, $daily_scores, $agent_daily, $weeks, $categories, $team_weeks, $team_names) {
        $base_url = admin_url('admin.php?page=ai-ops&section=score-trends');
        ?>
        <style>
            .trends-filters { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
            .trends-chart-grid { display:grid; grid-template-columns:1fr; gap:24px; margin-bottom:24px; }
            .trends-chart-grid.two-col { grid-template-columns:1fr 1fr; }
            @media (max-width:1200px) { .trends-chart-grid.two-col { grid-template-columns:1fr; } }
            .chart-container { position:relative; height:320px; }
            .chart-title { font-size:15px; font-weight:600; color:var(--color-text-primary); margin-bottom:4px; }
            .chart-subtitle { font-size:12px; color:var(--color-text-secondary); margin-bottom:16px; }
        </style>

        <!-- Filters -->
        <div class="ops-card" style="margin-bottom:24px;">
            <div class="trends-filters">
                <div class="analytics-filters">
                    <?php foreach ([7 => '7 Days', 30 => '30 Days', 60 => '60 Days', 90 => '90 Days', 365 => '1 Year'] as $d => $label): ?>
                        <a href="<?php echo esc_url(add_query_arg(['days' => $d, 'agent' => $selected_agent], $base_url)); ?>"
                           class="ops-btn <?php echo $days == $d ? 'primary' : 'secondary'; ?>"
                           style="padding:0 16px;height:36px;font-size:13px;font-weight:500;border-radius:var(--radius-sm);"><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </div>
                <select id="trends-agent-select" style="min-width:220px;height:36px;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:0 10px;font-size:13px;">
                    <option value="">All Agents (Team Average)</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?php echo esc_attr($a->agent_email); ?>" <?php selected($selected_agent, $a->agent_email); ?>>
                            <?php echo esc_html($a->agent_name ?: $a->agent_email); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Overall Score Trend -->
        <div class="trends-chart-grid">
            <div class="ops-card">
                <div class="chart-title">Overall Score Trend <?php echo $selected_agent ? '— ' . esc_html($selected_agent) : '— Team Average'; ?></div>
                <div class="chart-subtitle">Daily average of overall agent scores</div>
                <div class="chart-container">
                    <canvas id="chart-overall"></canvas>
                </div>
            </div>
        </div>

        <!-- Score Breakdown (Timing / Resolution / Communication) -->
        <div class="trends-chart-grid">
            <div class="ops-card">
                <div class="chart-title">Score Breakdown <?php echo $selected_agent ? '— ' . esc_html($selected_agent) : '— Team Average'; ?></div>
                <div class="chart-subtitle">Daily average by score component</div>
                <div class="chart-container">
                    <canvas id="chart-breakdown"></canvas>
                </div>
            </div>
        </div>

        <!-- Problem Categories Trend + Team Comparison -->
        <div class="trends-chart-grid two-col">
            <div class="ops-card">
                <div class="chart-title">Problem Category Trends</div>
                <div class="chart-subtitle">Weekly issue counts by category</div>
                <div class="chart-container">
                    <canvas id="chart-problems"></canvas>
                </div>
            </div>
            <?php if (!empty($team_names)): ?>
            <div class="ops-card">
                <div class="chart-title">Team Comparison</div>
                <div class="chart-subtitle">Weekly average score by team</div>
                <div class="chart-container">
                    <canvas id="chart-teams"></canvas>
                </div>
            </div>
            <?php else: ?>
            <div class="ops-card">
                <div class="chart-title">Ticket Volume</div>
                <div class="chart-subtitle">Daily audited tickets</div>
                <div class="chart-container">
                    <canvas id="chart-volume"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Agent selector
            $('#trends-agent-select').on('change', function() {
                var agent = $(this).val();
                var url = '<?php echo admin_url('admin.php'); ?>' + '?page=ai-ops&section=score-trends&days=<?php echo $days; ?>';
                if (agent) url += '&agent=' + encodeURIComponent(agent);
                window.location.href = url;
            });

            var chartDefaults = {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16, font: { size: 12 } } },
                    tooltip: { backgroundColor: '#1e293b', titleFont: { size: 13 }, bodyFont: { size: 12 }, padding: 12, cornerRadius: 8 }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#94a3b8', maxRotation: 45 } },
                    y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 }, color: '#94a3b8' } }
                }
            };

            // Data from PHP
            var dailyLabels = <?php echo json_encode(array_map(function($r) { return date('M j', strtotime($r->day)); }, $daily_scores)); ?>;
            var dailyOverall = <?php echo json_encode(array_map(function($r) { return floatval($r->avg_score); }, $daily_scores)); ?>;
            var dailyTiming = <?php echo json_encode(array_map(function($r) { return floatval($r->avg_timing); }, $daily_scores)); ?>;
            var dailyResolution = <?php echo json_encode(array_map(function($r) { return floatval($r->avg_resolution); }, $daily_scores)); ?>;
            var dailyCommunication = <?php echo json_encode(array_map(function($r) { return floatval($r->avg_communication); }, $daily_scores)); ?>;
            var dailyTickets = <?php echo json_encode(array_map(function($r) { return intval($r->tickets); }, $daily_scores)); ?>;

            <?php if ($selected_agent && !empty($agent_daily)): ?>
            var agentLabels = <?php echo json_encode(array_map(function($r) { return date('M j', strtotime($r->day)); }, $agent_daily)); ?>;
            var agentOverall = <?php echo json_encode(array_map(function($r) { return floatval($r->avg_score); }, $agent_daily)); ?>;
            var agentTiming = <?php echo json_encode(array_map(function($r) { return floatval($r->avg_timing); }, $agent_daily)); ?>;
            var agentResolution = <?php echo json_encode(array_map(function($r) { return floatval($r->avg_resolution); }, $agent_daily)); ?>;
            var agentCommunication = <?php echo json_encode(array_map(function($r) { return floatval($r->avg_communication); }, $agent_daily)); ?>;
            <?php endif; ?>

            // ---- Chart 1: Overall Score Trend ----
            <?php if ($selected_agent && !empty($agent_daily)): ?>
            new Chart(document.getElementById('chart-overall'), {
                type: 'line',
                data: {
                    labels: agentLabels,
                    datasets: [
                        { label: 'Agent Score', data: agentOverall, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.08)', fill: true, tension: 0.3, pointRadius: 3 },
                        { label: 'Team Average', data: (function() {
                            var map = {}; dailyLabels.forEach(function(l,i){ map[l] = dailyOverall[i]; });
                            return agentLabels.map(function(l){ return map[l] || null; });
                        })(), borderColor: '#94a3b8', borderDash: [6,3], tension: 0.3, pointRadius: 0 }
                    ]
                },
                options: chartDefaults
            });
            <?php else: ?>
            new Chart(document.getElementById('chart-overall'), {
                type: 'line',
                data: {
                    labels: dailyLabels,
                    datasets: [
                        { label: 'Team Average Score', data: dailyOverall, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.08)', fill: true, tension: 0.3, pointRadius: 3 }
                    ]
                },
                options: chartDefaults
            });
            <?php endif; ?>

            // ---- Chart 2: Score Breakdown ----
            <?php if ($selected_agent && !empty($agent_daily)): ?>
            var breakdownLabels = agentLabels;
            var bTiming = agentTiming, bResolution = agentResolution, bCommunication = agentCommunication;
            <?php else: ?>
            var breakdownLabels = dailyLabels;
            var bTiming = dailyTiming, bResolution = dailyResolution, bCommunication = dailyCommunication;
            <?php endif; ?>

            new Chart(document.getElementById('chart-breakdown'), {
                type: 'line',
                data: {
                    labels: breakdownLabels,
                    datasets: [
                        { label: 'Timing', data: bTiming, borderColor: '#ef4444', tension: 0.3, pointRadius: 2 },
                        { label: 'Resolution', data: bResolution, borderColor: '#22c55e', tension: 0.3, pointRadius: 2 },
                        { label: 'Communication', data: bCommunication, borderColor: '#f59e0b', tension: 0.3, pointRadius: 2 }
                    ]
                },
                options: chartDefaults
            });

            // ---- Chart 3: Problem Category Trends (weekly stacked bar) ----
            var problemWeeks = <?php echo json_encode(array_values($weeks)); ?>;
            var problemColors = ['#ef4444','#f59e0b','#8b5cf6','#06b6d4','#ec4899','#84cc16'];
            var problemDatasets = [];
            <?php $ci = 0; foreach ($categories as $cat => $week_data): ?>
            problemDatasets.push({
                label: <?php echo json_encode($cat); ?>,
                data: problemWeeks.map(function(w) {
                    var map = <?php echo json_encode($week_data); ?>;
                    return map[w] || 0;
                }),
                backgroundColor: problemColors[<?php echo $ci % 6; ?>],
                borderRadius: 4
            });
            <?php $ci++; endforeach; ?>

            new Chart(document.getElementById('chart-problems'), {
                type: 'bar',
                data: { labels: problemWeeks, datasets: problemDatasets },
                options: Object.assign({}, chartDefaults, {
                    scales: Object.assign({}, chartDefaults.scales, {
                        x: Object.assign({}, chartDefaults.scales.x, { stacked: true }),
                        y: Object.assign({}, chartDefaults.scales.y, { stacked: true, beginAtZero: true })
                    })
                })
            });

            // ---- Chart 4: Team Comparison or Volume ----
            <?php if (!empty($team_names)): ?>
            var teamWeeks = <?php echo json_encode(array_values($team_weeks)); ?>;
            var teamColors = ['#3b82f6','#22c55e','#f59e0b','#8b5cf6','#ef4444','#06b6d4'];
            var teamDatasets = [];
            <?php $ti = 0; foreach ($team_names as $tn => $week_data): ?>
            teamDatasets.push({
                label: <?php echo json_encode($tn); ?>,
                data: teamWeeks.map(function(w) {
                    var map = <?php echo json_encode($week_data); ?>;
                    return map[w] || null;
                }),
                borderColor: teamColors[<?php echo $ti % 6; ?>],
                tension: 0.3,
                pointRadius: 3
            });
            <?php $ti++; endforeach; ?>

            new Chart(document.getElementById('chart-teams'), {
                type: 'line',
                data: { labels: teamWeeks, datasets: teamDatasets },
                options: chartDefaults
            });
            <?php else: ?>
            new Chart(document.getElementById('chart-volume'), {
                type: 'bar',
                data: {
                    labels: dailyLabels,
                    datasets: [
                        { label: 'Tickets Audited', data: dailyTickets, backgroundColor: 'rgba(59,130,246,0.6)', borderRadius: 4 }
                    ]
                },
                options: Object.assign({}, chartDefaults, { scales: Object.assign({}, chartDefaults.scales, { y: Object.assign({}, chartDefaults.scales.y, { beginAtZero: true }) }) })
            });
            <?php endif; ?>
        });
        </script>
        <?php
    }
}
