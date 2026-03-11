<?php
/**
 * Compare & Benchmark Page
 *
 * Side-by-side agent vs agent, team vs team, month vs month comparison.
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class CompareBenchmark {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : 'agents';
        $days = isset($_GET['days']) ? intval($_GET['days']) : 0;
        $date_from = $days > 0 ? date('Y-m-d', strtotime("-{$days} days")) : '2000-01-01';
        $date_to = date('Y-m-d');

        $team_filter = AccessControl::sql_agent_filter('ae.agent_email');

        // Agents list
        $agents = $wpdb->get_results(
            "SELECT DISTINCT ae.agent_email, ae.agent_name
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE 1=1 {$team_filter}
             ORDER BY ae.agent_name ASC"
        );

        // Teams list (admin only)
        $teams = [];
        if (AccessControl::is_admin()) {
            $teams = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}ais_teams ORDER BY name ASC");
        }

        $base_url = admin_url('admin.php?page=ai-ops&section=compare');
        $this->render_filters($mode, $days, $agents, $teams, $base_url);

        switch ($mode) {
            case 'agents':
                $this->render_agent_comparison($days, $date_from, $agents, $team_filter);
                break;
            case 'teams':
                $this->render_team_comparison($days, $date_from);
                break;
            case 'periods':
                $this->render_period_comparison($days, $team_filter);
                break;
        }
    }

    private function render_filters($mode, $days, $agents, $teams, $base_url) {
        ?>
        <style>
            .compare-filters { display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
            .compare-mode-tabs { display:flex; gap:4px; background:var(--color-bg-subtle); border-radius:var(--radius-sm); padding:3px; }
            .compare-mode-tabs a { padding:6px 16px; font-size:13px; font-weight:500; border-radius:var(--radius-sm); text-decoration:none; color:var(--color-text-secondary); transition:all .2s; }
            .compare-mode-tabs a.active { background:#fff; color:var(--color-text-primary); box-shadow:0 1px 3px rgba(0,0,0,.1); }
            .compare-side { flex:1; min-width:0; }
            .compare-grid { display:grid; grid-template-columns:1fr 60px 1fr; gap:0; margin-bottom:24px; align-items:start; }
            .compare-vs { display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:800; color:var(--color-text-tertiary); padding-top:60px; }
            .compare-stat-row { display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid var(--color-border); }
            .compare-stat-label { font-size:13px; color:var(--color-text-secondary); }
            .compare-stat-value { font-size:16px; font-weight:700; color:var(--color-text-primary); }
            .compare-stat-value.better { color:var(--color-success); }
            .compare-stat-value.worse { color:var(--color-error); }
            .compare-header { font-size:16px; font-weight:700; color:var(--color-text-primary); margin-bottom:4px; }
            .compare-subheader { font-size:12px; color:var(--color-text-secondary); margin-bottom:16px; }
            .compare-bar-container { margin-bottom:24px; }
            .compare-bar-row { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
            .compare-bar-label { width:140px; font-size:13px; color:var(--color-text-secondary); text-align:right; flex-shrink:0; }
            .compare-bar-track { flex:1; height:28px; background:var(--color-bg-subtle); border-radius:var(--radius-sm); position:relative; overflow:hidden; }
            .compare-bar-fill { height:100%; border-radius:var(--radius-sm); display:flex; align-items:center; justify-content:flex-end; padding:0 8px; font-size:11px; font-weight:700; color:#fff; min-width:30px; transition:width .5s ease; }
            @media (max-width:900px) { .compare-grid { grid-template-columns:1fr; } .compare-vs { padding:16px 0; } }
        </style>

        <div class="ops-card" style="margin-bottom:24px;">
            <div class="compare-filters">
                <div class="compare-mode-tabs">
                    <a href="<?php echo esc_url(add_query_arg(['mode' => 'agents', 'days' => $days], $base_url)); ?>" class="<?php echo $mode === 'agents' ? 'active' : ''; ?>">Agent vs Agent</a>
                    <?php if (AccessControl::is_admin()): ?>
                    <a href="<?php echo esc_url(add_query_arg(['mode' => 'teams', 'days' => $days], $base_url)); ?>" class="<?php echo $mode === 'teams' ? 'active' : ''; ?>">Team vs Team</a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(add_query_arg(['mode' => 'periods', 'days' => $days], $base_url)); ?>" class="<?php echo $mode === 'periods' ? 'active' : ''; ?>">This Month vs Last</a>
                </div>
                <div class="analytics-filters">
                    <?php foreach ([30 => '30 Days', 60 => '60 Days', 90 => '90 Days', 0 => 'All Time'] as $d => $label): ?>
                        <a href="<?php echo esc_url(add_query_arg(['days' => $d, 'mode' => $mode], $base_url)); ?>"
                           class="ops-btn <?php echo $days == $d ? 'primary' : 'secondary'; ?>"
                           style="padding:0 14px;height:34px;font-size:13px;font-weight:500;border-radius:var(--radius-sm);"><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_agent_comparison($days, $date_from, $agents, $team_filter) {
        global $wpdb;

        $agent_a = isset($_GET['agent_a']) ? sanitize_email($_GET['agent_a']) : '';
        $agent_b = isset($_GET['agent_b']) ? sanitize_email($_GET['agent_b']) : '';

        // Auto-select first two if none selected
        if (!$agent_a && count($agents) >= 1) $agent_a = $agents[0]->agent_email;
        if (!$agent_b && count($agents) >= 2) $agent_b = $agents[1]->agent_email;

        $base_url = admin_url('admin.php?page=ai-ops&section=compare&mode=agents&days=' . $days);
        ?>
        <div class="ops-card" style="margin-bottom:24px;padding:16px 20px;">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="font-size:13px;font-weight:600;color:var(--color-text-secondary);">Compare:</label>
                <select id="agent-a-select" style="min-width:200px;height:34px;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:0 10px;font-size:13px;">
                    <?php foreach ($agents as $a): ?>
                        <option value="<?php echo esc_attr($a->agent_email); ?>" <?php selected($agent_a, $a->agent_email); ?>>
                            <?php echo esc_html($a->agent_name ?: $a->agent_email); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span style="font-weight:700;color:var(--color-text-tertiary);">vs</span>
                <select id="agent-b-select" style="min-width:200px;height:34px;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:0 10px;font-size:13px;">
                    <?php foreach ($agents as $a): ?>
                        <option value="<?php echo esc_attr($a->agent_email); ?>" <?php selected($agent_b, $a->agent_email); ?>>
                            <?php echo esc_html($a->agent_name ?: $a->agent_email); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button onclick="doCompare()" class="ops-btn primary" style="height:34px;padding:0 20px;font-size:13px;">Compare</button>
            </div>
        </div>

        <script>
        function doCompare() {
            var a = document.getElementById('agent-a-select').value;
            var b = document.getElementById('agent-b-select').value;
            if (a === b) { alert('Please select two different agents to compare.'); return; }
            var url = '<?php echo admin_url("admin.php"); ?>' + '?page=ai-ops&section=compare&mode=agents&days=<?php echo intval($days); ?>';
            window.location.href = url + '&agent_a=' + encodeURIComponent(a) + '&agent_b=' + encodeURIComponent(b);
        }
        </script>

        <?php
        if (!$agent_a || !$agent_b) {
            echo '<div class="ops-card"><div class="ops-empty-state"><div class="ops-empty-state-title">Select two agents to compare</div></div></div>';
            return;
        }
        if ($agent_a === $agent_b) {
            echo '<div class="ops-card"><div class="ops-empty-state"><div class="ops-empty-state-title">Please select two different agents to compare</div></div></div>';
            return;
        }

        $stats_a = $this->get_agent_stats($agent_a, $date_from);
        $stats_b = $this->get_agent_stats($agent_b, $date_from);

        if (!$stats_a || !$stats_b) {
            echo '<div class="ops-card"><div class="ops-empty-state"><div class="ops-empty-state-title">No data available for one or both agents in this period</div></div></div>';
            return;
        }

        $metrics = [
            'Overall Score' => ['avg_overall_score', true],
            'Timing Score' => ['avg_timing', true],
            'Resolution Score' => ['avg_resolution', true],
            'Communication Score' => ['avg_communication', true],
            'Tickets Handled' => ['total_tickets', true],
            'Total Replies' => ['total_replies', true],
        ];
        ?>

        <div class="compare-grid">
            <div class="compare-side ops-card">
                <div class="compare-header"><?php echo esc_html($stats_a->agent_name ?: $agent_a); ?></div>
                <div class="compare-subheader"><?php echo esc_html($agent_a); ?></div>
                <?php foreach ($metrics as $label => list($key, $higher_better)): ?>
                    <?php
                    $va = floatval($stats_a->$key);
                    $vb = floatval($stats_b->$key);
                    $class = '';
                    if ($va != $vb) {
                        $class = ($higher_better ? ($va > $vb) : ($va < $vb)) ? 'better' : 'worse';
                    }
                    ?>
                    <div class="compare-stat-row">
                        <span class="compare-stat-label"><?php echo $label; ?></span>
                        <span class="compare-stat-value <?php echo $class; ?>"><?php echo $va; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="compare-vs">VS</div>

            <div class="compare-side ops-card">
                <div class="compare-header"><?php echo esc_html($stats_b->agent_name ?: $agent_b); ?></div>
                <div class="compare-subheader"><?php echo esc_html($agent_b); ?></div>
                <?php foreach ($metrics as $label => list($key, $higher_better)): ?>
                    <?php
                    $va = floatval($stats_a->$key);
                    $vb = floatval($stats_b->$key);
                    $class = '';
                    if ($va != $vb) {
                        $class = ($higher_better ? ($vb > $va) : ($vb < $va)) ? 'better' : 'worse';
                    }
                    ?>
                    <div class="compare-stat-row">
                        <span class="compare-stat-label"><?php echo $label; ?></span>
                        <span class="compare-stat-value <?php echo $class; ?>"><?php echo floatval($stats_b->$key); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Radar Chart -->
        <div class="ops-card" style="margin-bottom:24px;">
            <div style="max-width:500px;margin:0 auto;">
                <canvas id="chart-radar"></canvas>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            new Chart(document.getElementById('chart-radar'), {
                type: 'radar',
                data: {
                    labels: ['Timing', 'Resolution', 'Communication', 'Tickets', 'Replies'],
                    datasets: [
                        {
                            label: <?php echo json_encode($stats_a->agent_name ?: $agent_a); ?>,
                            data: [
                                <?php echo floatval($stats_a->avg_timing); ?>,
                                <?php echo floatval($stats_a->avg_resolution); ?>,
                                <?php echo floatval($stats_a->avg_communication); ?>,
                                <?php echo intval($stats_a->total_tickets); ?>,
                                <?php echo intval($stats_a->total_replies); ?>
                            ],
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59,130,246,0.15)',
                            pointBackgroundColor: '#3b82f6'
                        },
                        {
                            label: <?php echo json_encode($stats_b->agent_name ?: $agent_b); ?>,
                            data: [
                                <?php echo floatval($stats_b->avg_timing); ?>,
                                <?php echo floatval($stats_b->avg_resolution); ?>,
                                <?php echo floatval($stats_b->avg_communication); ?>,
                                <?php echo intval($stats_b->total_tickets); ?>,
                                <?php echo intval($stats_b->total_replies); ?>
                            ],
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34,197,94,0.15)',
                            pointBackgroundColor: '#22c55e'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position:'bottom', labels: { usePointStyle:true, padding:16, font:{size:12} } }
                    },
                    scales: {
                        r: { grid: { color:'#e2e8f0' }, pointLabels: { font:{size:12} } }
                    }
                }
            });
        });
        </script>
        <?php
    }

    private function render_team_comparison($days, $date_from) {
        global $wpdb;

        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.name, t.color,
                    ROUND(AVG(ae.overall_agent_score), 1) as avg_score,
                    ROUND(AVG(ae.timing_score), 1) as avg_timing,
                    ROUND(AVG(ae.resolution_score), 1) as avg_resolution,
                    ROUND(AVG(ae.communication_score), 1) as avg_communication,
                    COUNT(DISTINCT ae.ticket_id) as total_tickets,
                    COUNT(DISTINCT ae.agent_email) as agent_count
             FROM {$wpdb->prefix}ais_teams t
             INNER JOIN {$wpdb->prefix}ais_team_members tm ON t.id = tm.team_id
             INNER JOIN {$wpdb->prefix}ais_agent_evaluations ae ON tm.agent_email = ae.agent_email
             WHERE DATE(ae.created_at) >= %s
             GROUP BY t.id, t.name, t.color
             ORDER BY avg_score DESC",
            $date_from
        ));

        if (empty($teams)) {
            echo '<div class="ops-card"><div class="ops-empty-state"><div class="ops-empty-state-title">No team data available for this period</div></div></div>';
            return;
        }

        $max_score = 1;
        foreach ($teams as $t) { if (abs($t->avg_score) > $max_score) $max_score = abs($t->avg_score); }
        ?>

        <div class="ops-card" style="margin-bottom:24px;">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th style="text-align:center;">Agents</th>
                        <th style="text-align:center;">Tickets</th>
                        <th style="text-align:center;">Overall</th>
                        <th style="text-align:center;">Timing</th>
                        <th style="text-align:center;">Resolution</th>
                        <th style="text-align:center;">Communication</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teams as $t): ?>
                    <tr>
                        <td>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr($t->color ?: '#3b82f6'); ?>;margin-right:8px;"></span>
                            <strong><?php echo esc_html($t->name); ?></strong>
                        </td>
                        <td style="text-align:center;"><?php echo intval($t->agent_count); ?></td>
                        <td style="text-align:center;"><?php echo intval($t->total_tickets); ?></td>
                        <td style="text-align:center;"><span class="col-score <?php echo Dashboard::score_class(intval($t->avg_score)); ?>"><?php echo $t->avg_score; ?></span></td>
                        <td style="text-align:center;"><?php echo $t->avg_timing; ?></td>
                        <td style="text-align:center;"><?php echo $t->avg_resolution; ?></td>
                        <td style="text-align:center;"><?php echo $t->avg_communication; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Bar chart comparison -->
        <div class="ops-card">
            <div style="height:300px;">
                <canvas id="chart-team-compare"></canvas>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var teamNames = <?php echo json_encode(array_map(function($t) { return $t->name; }, $teams)); ?>;
            var teamColors = <?php echo json_encode(array_map(function($t) { return $t->color ?: '#3b82f6'; }, $teams)); ?>;

            new Chart(document.getElementById('chart-team-compare'), {
                type: 'bar',
                data: {
                    labels: ['Overall', 'Timing', 'Resolution', 'Communication'],
                    datasets: <?php
                        $datasets = [];
                        foreach ($teams as $i => $t) {
                            $datasets[] = [
                                'label' => $t->name,
                                'data' => [floatval($t->avg_score), floatval($t->avg_timing), floatval($t->avg_resolution), floatval($t->avg_communication)],
                                'backgroundColor' => ($t->color ?: '#3b82f6') . 'cc',
                                'borderRadius' => 4,
                            ];
                        }
                        echo json_encode($datasets);
                    ?>
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position:'bottom', labels: { usePointStyle:true, padding:16, font:{size:12} } } },
                    scales: {
                        x: { grid: { display:false } },
                        y: { grid: { color:'#f1f5f9' } }
                    }
                }
            });
        });
        </script>
        <?php
    }

    private function render_period_comparison($days, $team_filter) {
        global $wpdb;

        // Current period
        $current_from = date('Y-m-d', strtotime("-{$days} days"));
        $current_to = date('Y-m-d');

        // Previous period (same length)
        $prev_from = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
        $prev_to = date('Y-m-d', strtotime("-{$days} days"));

        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT ROUND(AVG(ae.overall_agent_score), 1) as avg_score,
                    ROUND(AVG(ae.timing_score), 1) as avg_timing,
                    ROUND(AVG(ae.resolution_score), 1) as avg_resolution,
                    ROUND(AVG(ae.communication_score), 1) as avg_communication,
                    COUNT(DISTINCT ae.ticket_id) as total_tickets,
                    COUNT(DISTINCT ae.agent_email) as active_agents,
                    SUM(ae.reply_count) as total_replies
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) BETWEEN %s AND %s {$team_filter}",
            $current_from, $current_to
        ));

        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT ROUND(AVG(ae.overall_agent_score), 1) as avg_score,
                    ROUND(AVG(ae.timing_score), 1) as avg_timing,
                    ROUND(AVG(ae.resolution_score), 1) as avg_resolution,
                    ROUND(AVG(ae.communication_score), 1) as avg_communication,
                    COUNT(DISTINCT ae.ticket_id) as total_tickets,
                    COUNT(DISTINCT ae.agent_email) as active_agents,
                    SUM(ae.reply_count) as total_replies
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) BETWEEN %s AND %s {$team_filter}",
            $prev_from, $prev_to
        ));

        // Top improvers
        $improvers = $wpdb->get_results($wpdb->prepare(
            "SELECT cur.agent_email, cur.agent_name, cur.avg_score as current_score, prev.avg_score as prev_score,
                    ROUND(cur.avg_score - prev.avg_score, 1) as improvement
             FROM (
                 SELECT agent_email, agent_name, AVG(overall_agent_score) as avg_score
                 FROM {$wpdb->prefix}ais_agent_evaluations
                 WHERE DATE(created_at) BETWEEN %s AND %s {$team_filter}
                 GROUP BY agent_email, agent_name
             ) cur
             INNER JOIN (
                 SELECT agent_email, AVG(overall_agent_score) as avg_score
                 FROM {$wpdb->prefix}ais_agent_evaluations
                 WHERE DATE(created_at) BETWEEN %s AND %s {$team_filter}
                 GROUP BY agent_email
             ) prev ON cur.agent_email = prev.agent_email
             ORDER BY improvement DESC
             LIMIT 10",
            $current_from, $current_to, $prev_from, $prev_to
        ));

        $metrics = [
            'Average Score' => ['avg_score', true],
            'Timing Score' => ['avg_timing', true],
            'Resolution Score' => ['avg_resolution', true],
            'Communication Score' => ['avg_communication', true],
            'Tickets Audited' => ['total_tickets', true],
            'Active Agents' => ['active_agents', true],
            'Total Replies' => ['total_replies', true],
        ];
        ?>

        <div class="compare-grid">
            <div class="compare-side ops-card">
                <div class="compare-header">Current Period</div>
                <div class="compare-subheader"><?php echo esc_html($current_from . ' → ' . $current_to); ?></div>
                <?php foreach ($metrics as $label => list($key, $higher_better)):
                    $vc = floatval($current->$key ?? 0);
                    $vp = floatval($previous->$key ?? 0);
                    $class = '';
                    if ($vc != $vp) { $class = ($higher_better ? ($vc > $vp) : ($vc < $vp)) ? 'better' : 'worse'; }
                ?>
                    <div class="compare-stat-row">
                        <span class="compare-stat-label"><?php echo $label; ?></span>
                        <span class="compare-stat-value <?php echo $class; ?>"><?php echo $vc; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="compare-vs">VS</div>

            <div class="compare-side ops-card">
                <div class="compare-header">Previous Period</div>
                <div class="compare-subheader"><?php echo esc_html($prev_from . ' → ' . $prev_to); ?></div>
                <?php foreach ($metrics as $label => list($key, $higher_better)):
                    $vc = floatval($current->$key ?? 0);
                    $vp = floatval($previous->$key ?? 0);
                    $class = '';
                    if ($vc != $vp) { $class = ($higher_better ? ($vp > $vc) : ($vp < $vc)) ? 'better' : 'worse'; }
                ?>
                    <div class="compare-stat-row">
                        <span class="compare-stat-label"><?php echo $label; ?></span>
                        <span class="compare-stat-value <?php echo $class; ?>"><?php echo $vp; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Top Improvers / Decliners -->
        <?php if (!empty($improvers)): ?>
        <div class="ops-card">
            <h3 style="font-size:16px;font-weight:600;margin:0 0 16px;">Top Movers (Improvement vs Previous Period)</h3>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th style="text-align:center;">Previous</th>
                        <th style="text-align:center;">Current</th>
                        <th style="text-align:center;">Change</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($improvers as $row): ?>
                    <tr>
                        <td><strong><?php echo esc_html($row->agent_name ?: $row->agent_email); ?></strong></td>
                        <td style="text-align:center;"><?php echo round($row->prev_score, 1); ?></td>
                        <td style="text-align:center;"><?php echo round($row->current_score, 1); ?></td>
                        <td style="text-align:center;">
                            <span style="font-weight:700;color:<?php echo floatval($row->improvement) >= 0 ? 'var(--color-success)' : 'var(--color-error)'; ?>;">
                                <?php echo floatval($row->improvement) >= 0 ? '+' : ''; ?><?php echo $row->improvement; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif;
    }

    private function get_agent_stats($email, $date_from) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT agent_name, agent_email,
                    ROUND(AVG(overall_agent_score), 1) as avg_overall_score,
                    ROUND(AVG(timing_score), 1) as avg_timing,
                    ROUND(AVG(resolution_score), 1) as avg_resolution,
                    ROUND(AVG(communication_score), 1) as avg_communication,
                    COUNT(DISTINCT ticket_id) as total_tickets,
                    SUM(reply_count) as total_replies
             FROM {$wpdb->prefix}ais_agent_evaluations
             WHERE agent_email = %s AND DATE(created_at) >= %s
             HAVING COUNT(*) > 0",
            $email, $date_from
        ));
    }
}
