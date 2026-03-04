<?php
/**
 * Handoff Report Page — Track agent shift-end handoff compliance
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class HandoffReport {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_handoff_events';

        // Check table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            echo '<div class="ops-card"><div class="ops-empty-state"><div class="ops-empty-state-title">Handoff tracking not active yet</div><div class="ops-empty-state-description">Data will appear here once tickets with reassignments are audited.</div></div></div>';
            return;
        }

        // Date filter
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30';
        $days = intval($period) ?: 30;
        $since = date('Y-m-d', strtotime("-{$days} days"));

        // Team filtering
        $team_agent_filter = AccessControl::sql_agent_filter('he.agent_email');

        // KPI data
        $total_events = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} he WHERE created_at >= %s{$team_agent_filter}", $since
        ));
        $good_handoffs = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} he WHERE handoff_score >= 0 AND created_at >= %s{$team_agent_filter}", $since
        ));
        $failed_handoffs = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} he WHERE handoff_score < 0 AND created_at >= %s{$team_agent_filter}", $since
        ));
        $compliance_rate = $total_events > 0 ? round(($good_handoffs / $total_events) * 100, 1) : 0;
        $avg_gap = $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(AVG(gap_hours), 1) FROM {$table} he WHERE gap_hours > 0 AND created_at >= %s{$team_agent_filter}", $since
        )) ?: 0;

        // Agent breakdown
        $agents = $wpdb->get_results($wpdb->prepare(
            "SELECT he.agent_email,
                    a.first_name, a.last_name,
                    COUNT(*) as total_events,
                    SUM(CASE WHEN he.handoff_score >= 0 THEN 1 ELSE 0 END) as good_handoffs,
                    SUM(CASE WHEN he.handoff_score < 0 THEN 1 ELSE 0 END) as failed_handoffs,
                    ROUND(SUM(CASE WHEN he.handoff_score >= 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as compliance_rate,
                    ROUND(AVG(CASE WHEN he.gap_hours > 0 THEN he.gap_hours ELSE NULL END), 1) as avg_gap_hours,
                    ROUND(AVG(he.handoff_score), 1) as avg_score
             FROM {$table} he
             LEFT JOIN {$wpdb->prefix}ais_agents a ON he.agent_email = a.email
             WHERE he.created_at >= %s{$team_agent_filter}
             GROUP BY he.agent_email
             ORDER BY compliance_rate ASC",
            $since
        ));

        // Recent failed handoffs
        $recent_failures = $wpdb->get_results($wpdb->prepare(
            "SELECT he.*, a.first_name, a.last_name
             FROM {$table} he
             LEFT JOIN {$wpdb->prefix}ais_agents a ON he.agent_email = a.email
             WHERE he.handoff_score < 0 AND he.created_at >= %s{$team_agent_filter}
             ORDER BY he.created_at DESC LIMIT 10",
            $since
        ));

        ?>
        <!-- Period Filter -->
        <div class="ops-period-filter">
            <?php foreach ([30 => '30 Days', 60 => '60 Days', 90 => '90 Days'] as $d => $label):
                $active = ($days === $d) ? 'primary' : 'secondary';
            ?>
                <a href="<?php echo admin_url('admin.php?page=ai-ops&section=handoffs&period=' . $d); ?>" class="ops-btn <?php echo $active; ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </div>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Compliance rate</div>
                <div class="stat-value <?php echo $compliance_rate >= 80 ? 'handoff-good' : ($compliance_rate >= 50 ? 'handoff-neutral' : 'handoff-bad'); ?>"><?php echo $compliance_rate; ?>%</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total handoff events</div>
                <div class="stat-value"><?php echo $total_events; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Failed handoffs</div>
                <div class="stat-value" style="<?php echo $failed_handoffs > 0 ? 'color:var(--color-error);' : ''; ?>"><?php echo $failed_handoffs; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg gap (failed)</div>
                <div class="stat-value"><?php echo $avg_gap ? $avg_gap . 'h' : '—'; ?></div>
            </div>
        </div>

        <!-- Agent Handoff Table -->
        <div class="ops-card" style="padding:0;overflow:hidden;margin-bottom:24px;">
            <div style="padding:20px 20px 0;">
                <h3>Agent handoff compliance</h3>
            </div>
            <?php if (empty($agents)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No handoff data yet</div>
                    <div class="ops-empty-state-description">Audited tickets with reassignments will populate this report.</div>
                </div>
            <?php else: ?>
                <table class="audit-table" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Total Events</th>
                            <th>Good</th>
                            <th>Failed</th>
                            <th>Compliance</th>
                            <th>Avg Gap</th>
                            <th>Avg Score</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($agents as $agent):
                        $name = trim(($agent->first_name ?: '') . ' ' . ($agent->last_name ?: '')) ?: $agent->agent_email;
                        $rate_class = $agent->compliance_rate >= 80 ? 'handoff-good' : ($agent->compliance_rate >= 50 ? 'handoff-neutral' : 'handoff-bad');
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($name); ?></strong><br><span style="font-size:11px;color:var(--color-text-tertiary);"><?php echo esc_html($agent->agent_email); ?></span></td>
                            <td><?php echo $agent->total_events; ?></td>
                            <td class="handoff-good"><?php echo $agent->good_handoffs; ?></td>
                            <td class="handoff-bad"><?php echo $agent->failed_handoffs; ?></td>
                            <td><strong class="<?php echo $rate_class; ?>"><?php echo $agent->compliance_rate; ?>%</strong></td>
                            <td><?php echo $agent->avg_gap_hours ? $agent->avg_gap_hours . 'h' : '—'; ?></td>
                            <td>
                                <span class="<?php echo $agent->avg_score >= 0 ? 'handoff-good' : 'handoff-bad'; ?>"><?php echo $agent->avg_score; ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Failed Handoffs -->
        <?php if (!empty($recent_failures)): ?>
        <div class="ops-card" style="padding:0;overflow:hidden;">
            <div style="padding:20px 20px 0;">
                <h3>Recent failed handoffs</h3>
            </div>
            <table class="audit-table" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Agent</th>
                        <th>Shift Ended</th>
                        <th>Reassigned At</th>
                        <th>Gap</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_failures as $f):
                    $name = trim(($f->first_name ?: '') . ' ' . ($f->last_name ?: '')) ?: $f->agent_email;
                ?>
                    <tr>
                        <td><strong>#<?php echo esc_html($f->ticket_id); ?></strong></td>
                        <td><?php echo esc_html($name); ?></td>
                        <td style="color:var(--color-text-tertiary);"><?php echo $f->shift_end ? date('M j, H:i', strtotime($f->shift_end)) : '—'; ?></td>
                        <td style="color:var(--color-text-tertiary);"><?php echo $f->reassigned_at ? date('M j, H:i', strtotime($f->reassigned_at)) : 'Never'; ?></td>
                        <td class="handoff-bad" style="font-weight:600;"><?php echo $f->gap_hours; ?>h</td>
                        <td style="font-size:12px;color:var(--color-text-secondary);max-width:250px;"><?php echo esc_html($f->reason); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php
    }
}
