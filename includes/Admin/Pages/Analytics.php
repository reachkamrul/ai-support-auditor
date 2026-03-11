<?php
/**
 * Analytics Page
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class Analytics {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $date_from = date('Y-m-d H:i:s', strtotime("-$days days"));
        $date_from_date = date('Y-m-d', strtotime("-$days days"));
        $selected_agent = isset($_GET['agent']) ? sanitize_email($_GET['agent']) : '';

        // Team filtering
        $agent_filter = AccessControl::sql_agent_filter('ae.agent_email');
        $ticket_filter = '';
        $team_emails = AccessControl::get_team_agent_emails();
        if (!empty($team_emails)) {
            $escaped = implode(',', array_map(function ($e) use ($wpdb) {
                return $wpdb->prepare('%s', $e);
            }, $team_emails));
            $ticket_filter = " AND ticket_id IN (SELECT DISTINCT ticket_id FROM {$wpdb->prefix}ais_agent_evaluations WHERE agent_email IN ({$escaped}))";
        }

        $team_filter = AccessControl::sql_agent_filter('ae.agent_email');

        // Fetch analytics data
        $agent_stats = $this->get_agent_stats($date_from);
        $problem_stats = $this->get_problem_stats($date_from);

        $total_audits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ais_audits
             WHERE status = 'success' AND created_at >= %s{$ticket_filter}",
            $date_from
        ));

        $avg_score = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(AVG(overall_agent_score), 0)
             FROM {$wpdb->prefix}ais_agent_evaluations
             WHERE created_at >= %s" . AccessControl::sql_agent_filter('agent_email'),
            $date_from
        ));

        $total_agents = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT agent_email)
             FROM {$wpdb->prefix}ais_agent_evaluations
             WHERE created_at >= %s" . AccessControl::sql_agent_filter('agent_email'),
            $date_from
        ));

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
            $date_from_date
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
                $selected_agent, $date_from_date
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
            $date_from_date
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
                $date_from_date
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

        $this->render_filters($days, $selected_agent, $agents);
        $this->render_summary_stats($total_audits, $avg_score, $total_agents, $problem_stats);
        $this->render_agent_leaderboard($agent_stats, $problem_stats, $date_from);
        $this->render_problem_categories($problem_stats);
        $this->render_charts($days, $selected_agent, $daily_scores, $agent_daily, $weeks, $categories, $team_weeks, $team_names);
    }

    private function get_agent_stats($date_from) {
        global $wpdb;

        $team_filter = AccessControl::sql_agent_filter('ae.agent_email');
        return $wpdb->get_results($wpdb->prepare("
            SELECT
                ae.agent_email,
                ae.agent_name,
                COUNT(DISTINCT ae.ticket_id) as tickets_handled,
                ROUND(AVG(ae.overall_agent_score), 1) as avg_overall_score,
                ROUND(AVG(ae.timing_score), 1) as avg_timing_score,
                ROUND(AVG(ae.resolution_score), 1) as avg_resolution_score,
                ROUND(AVG(ae.communication_score), 1) as avg_communication_score,
                SUM(ae.reply_count) as total_replies
            FROM {$wpdb->prefix}ais_agent_evaluations ae
            WHERE ae.created_at >= %s{$team_filter}
            GROUP BY ae.agent_email, ae.agent_name
            HAVING tickets_handled > 0
            ORDER BY avg_overall_score DESC, tickets_handled DESC
            LIMIT 10
        ", $date_from));
    }

    private function get_problem_stats($date_from) {
        global $wpdb;

        // Get problem categories from the problem_contexts table
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                pc.category,
                COUNT(DISTINCT pc.ticket_id) as count
             FROM {$wpdb->prefix}ais_problem_contexts pc
             INNER JOIN {$wpdb->prefix}ais_audits a ON pc.ticket_id = a.ticket_id
             WHERE a.status = 'success'
             AND a.created_at >= %s
             AND pc.category IS NOT NULL
             AND pc.category != ''
             GROUP BY pc.category
             ORDER BY count DESC",
            $date_from
        ));

        $stats = [];
        foreach ($results as $result) {
            $stats[$result->category] = intval($result->count);
                }

        return $stats;
    }

    private function render_filters($days, $selected_agent, $agents) {
        ?>
        <style>
            /* Analytics Page Specific Styles */
            .analytics-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 0;
                flex-wrap: wrap;
                gap: 16px;
            }

            .analytics-header h3 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: var(--color-text-primary);
            }

            .analytics-filters {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }

            .analytics-filters .ops-btn {
                padding: 0 16px;
                height: 36px;
                font-size: 13px;
                font-weight: 500;
                border-radius: var(--radius-sm);
                transition: all 0.2s ease;
            }

            .analytics-filters .ops-btn.secondary {
                background: var(--color-bg);
                color: var(--color-text-secondary);
                border: 1px solid var(--color-border);
            }

            .analytics-filters .ops-btn.secondary:hover {
                background: var(--color-bg-subtle);
                border-color: var(--color-border-strong);
                color: var(--color-text-primary);
            }

            .analytics-filters .ops-btn.primary {
                background: var(--color-primary);
                color: white;
                border: 1px solid var(--color-primary);
            }

            .analytics-filters .ops-btn.primary:hover {
                background: var(--color-primary-hover);
                border-color: var(--color-primary-hover);
            }

            /* stats-grid/stat-card overrides removed — using global styles */

            /* stat-card overrides removed — using global styles */

            .analytics-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                margin-bottom: 24px;
            }

            @media (max-width: 1200px) {
                .analytics-grid {
                    grid-template-columns: 1fr;
                }
            }

            .analytics-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 16px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--color-border);
            }

            .analytics-card-header h3 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--color-text-primary);
            }

            .analytics-card-description {
                color: var(--color-text-secondary);
                font-size: 13px;
                line-height: 1.5;
                margin-bottom: 16px;
            }

            .leaderboard-link {
                font-size: 12px;
                padding: 6px 12px;
                height: 28px;
                text-decoration: none;
            }

            .rank-cell {
                font-weight: 700;
                font-size: 16px;
                width: 60px;
                text-align: center;
            }

            .rank-medal {
                font-size: 20px;
            }

            .agent-name-cell {
                font-weight: 600;
                color: var(--color-text-primary);
            }

            /* empty-state removed — using global .ops-empty-state */

            /* Score Trends Chart Styles */
            .trends-chart-grid { display:grid; grid-template-columns:1fr; gap:24px; margin-bottom:24px; }
            .trends-chart-grid.two-col { grid-template-columns:1fr 1fr; }
            @media (max-width:1200px) { .trends-chart-grid.two-col { grid-template-columns:1fr; } }
            .chart-container { position:relative; height:320px; }
            .chart-title { font-size:15px; font-weight:600; color:var(--color-text-primary); margin-bottom:4px; }
            .chart-subtitle { font-size:12px; color:var(--color-text-secondary); margin-bottom:16px; }
        </style>

        <div class="ops-card" style="margin-bottom:24px;">
            <div class="analytics-header">
                <h3>Analytics Dashboard</h3>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div class="analytics-filters">
                        <a href="?page=ai-ops&section=analytics&days=7<?php echo $selected_agent ? '&agent=' . urlencode($selected_agent) : ''; ?>" class="ops-btn <?php echo $days==7?'primary':'secondary'; ?>">7 Days</a>
                        <a href="?page=ai-ops&section=analytics&days=30<?php echo $selected_agent ? '&agent=' . urlencode($selected_agent) : ''; ?>" class="ops-btn <?php echo $days==30?'primary':'secondary'; ?>">30 Days</a>
                        <a href="?page=ai-ops&section=analytics&days=90<?php echo $selected_agent ? '&agent=' . urlencode($selected_agent) : ''; ?>" class="ops-btn <?php echo $days==90?'primary':'secondary'; ?>">90 Days</a>
                        <a href="?page=ai-ops&section=analytics&days=365<?php echo $selected_agent ? '&agent=' . urlencode($selected_agent) : ''; ?>" class="ops-btn <?php echo $days==365?'primary':'secondary'; ?>">1 Year</a>
                    </div>
                    <select id="analytics-agent-select" style="min-width:220px;height:36px;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:0 10px;font-size:13px;">
                        <option value="">All Agents (Team Average)</option>
                        <?php foreach ($agents as $a): ?>
                            <option value="<?php echo esc_attr($a->agent_email); ?>" <?php selected($selected_agent, $a->agent_email); ?>>
                                <?php echo esc_html($a->agent_name ?: $a->agent_email); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_summary_stats($total_audits, $avg_score, $total_agents, $problem_stats) {
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total audits</div>
                <div class="stat-value"><?php echo number_format($total_audits); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Average score</div>
                <div class="stat-value" style="<?php echo $avg_score < 0 ? 'color: var(--color-error);' : ''; ?>">
                    <?php echo $avg_score > 0 ? round($avg_score) : ($total_audits > 0 ? '0' : '-'); ?>
                </div>
                <?php if ($avg_score > 0 || $total_audits > 0): ?>
                    <?php if ($avg_score >= 70): ?>
                        <div class="stat-change positive">▲ Excellent</div>
                    <?php elseif ($avg_score >= 50): ?>
                        <div class="stat-change">→ Good</div>
                    <?php elseif ($avg_score > 0): ?>
                        <div class="stat-change">→ Fair</div>
                    <?php else: ?>
                        <div class="stat-change negative">▼ Below Baseline</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Agents</div>
                <div class="stat-value"><?php echo number_format($total_agents); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Problem Types</div>
                <div class="stat-value"><?php echo count($problem_stats); ?></div>
            </div>
        </div>
        <?php
    }

    private function render_agent_leaderboard($agent_stats, $problem_stats, $date_from) {
        ?>
        <div class="analytics-grid">
            <div class="ops-card">
                <div class="analytics-card-header">
                <h3>Top performing agents</h3>
                    <a href="<?php echo admin_url('admin.php?page=ai-ops&section=agent-performance'); ?>" class="ops-btn secondary leaderboard-link">
                        View Full Dashboard →
                    </a>
                </div>
                <p class="analytics-card-description">Ranked by overall performance score</p>
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Agent</th>
                            <th style="text-align:center;">Score</th>
                            <th style="text-align:center;">Tickets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agent_stats)): ?>
                            <tr>
                                <td colspan="4" class="ops-empty-state">
                                    <div class="ops-empty-state-title">No data available</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $rank = 1; foreach ($agent_stats as $stat): ?>
                                <tr>
                                    <td class="rank-cell">
                                        <?php
                                        if ($rank == 1) echo '<span class="rank-medal">🥇</span>';
                                        elseif ($rank == 2) echo '<span class="rank-medal">🥈</span>';
                                        elseif ($rank == 3) echo '<span class="rank-medal">🥉</span>';
                                        else echo "#$rank";
                                        ?>
                                    </td>
                                    <td>
                                        <span class="agent-name-cell"><?php echo esc_html($stat->agent_name); ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php
                                        $score = intval($stat->avg_overall_score);
                                        $score_class = Dashboard::score_class($score);
                                        ?>
                                        <span class="col-score <?php echo $score_class; ?>"><?php echo $score; ?></span>
                                    </td>
                                    <td style="text-align:center;font-weight:700;color:var(--color-text-primary);">
                                        <?php echo number_format($stat->tickets_handled); ?>
                                    </td>
                                </tr>
                            <?php $rank++; endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php $this->render_problem_categories_inline($problem_stats); ?>
        </div>
        <?php
    }

    private function render_problem_categories_inline($problem_stats) {
        ?>
        <div class="ops-card">
            <div class="analytics-card-header">
            <h3>Common Problem Categories</h3>
            </div>
            <?php if (empty($problem_stats)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No problems identified yet</div>
                </div>
            <?php else: ?>
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Problem Category</th>
                            <th width="80">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $shown = 0; foreach ($problem_stats as $category => $count): ?>
                            <?php if ($shown++ >= 10) break; ?>
                            <tr>
                                <td style="color:var(--color-text-primary);"><?php echo esc_html($category); ?></td>
                                <td><span class="status-badge"><?php echo $count; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_problem_categories($problem_stats) {
        // Handled inline above
    }

    private function render_charts($days, $selected_agent, $daily_scores, $agent_daily, $weeks, $categories, $team_weeks, $team_names) {
        ?>
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
            $('#analytics-agent-select').on('change', function() {
                var agent = $(this).val();
                var url = '<?php echo admin_url('admin.php'); ?>' + '?page=ai-ops&section=analytics&days=<?php echo $days; ?>';
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