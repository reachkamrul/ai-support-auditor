<?php
/**
 * My Schedule — Agent Self-Service Portal
 *
 * Shows the logged-in agent their upcoming shifts, leaves, comp-offs.
 * Agents can also request leave via AJAX.
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class MySchedule {

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

        $today = date('Y-m-d');
        $month_start = date('Y-m-01');
        $month_end = date('Y-m-t');
        $next_month_end = date('Y-m-t', strtotime('+1 month'));

        // Upcoming shifts (today + next 30 days)
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, sd.name as shift_name, sd.color as shift_color
             FROM {$wpdb->prefix}ais_agent_shifts s
             LEFT JOIN {$wpdb->prefix}ais_shift_definitions sd ON s.shift_def_id = sd.id
             WHERE s.agent_email = %s AND DATE(s.shift_start) >= %s AND DATE(s.shift_start) <= %s
             ORDER BY s.shift_start ASC",
            $agent_email, $today, $next_month_end
        ));

        // Approved leaves
        $leaves = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_agent_leaves
             WHERE agent_email = %s AND leave_date >= %s
             ORDER BY leave_date ASC",
            $agent_email, $today
        ));

        // Past leaves (this month)
        $past_leaves = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_agent_leaves
             WHERE agent_email = %s AND leave_date >= %s AND leave_date < %s
             ORDER BY leave_date ASC",
            $agent_email, $month_start, $today
        ));

        // Comp-offs (from holiday duty)
        $comp_offs = $wpdb->get_results($wpdb->prepare(
            "SELECT hd.*, h.name as holiday_name
             FROM {$wpdb->prefix}ais_holiday_duty hd
             LEFT JOIN {$wpdb->prefix}ais_holidays h ON hd.holiday_id = h.id
             WHERE hd.agent_email = %s
             ORDER BY hd.comp_off_date DESC",
            $agent_email
        ));

        // Holidays this month + next
        $holidays = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_holidays
             WHERE start_date <= %s AND end_date >= %s
             ORDER BY start_date ASC",
            $next_month_end, $today
        ));

        // Agent info
        $agent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_agents WHERE email = %s", $agent_email
        ));

        $this->render_page($agent, $shifts, $leaves, $past_leaves, $comp_offs, $holidays, $agent_email);
    }

    private function render_page($agent, $shifts, $leaves, $past_leaves, $comp_offs, $holidays, $agent_email) {
        $agent_name = trim(($agent->first_name ?: '') . ' ' . ($agent->last_name ?: '')) ?: $agent_email;
        ?>
        <style>
            .schedule-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px; }
            @media (max-width:900px) { .schedule-grid { grid-template-columns:1fr; } }
            .shift-pill { display:inline-block; padding:3px 12px; border-radius:12px; font-size:11px; font-weight:600; color:#fff; }
            .leave-request-form { display:flex; gap:12px; align-items:end; flex-wrap:wrap; padding:16px; background:var(--color-bg-subtle); border-radius:var(--radius-md); margin-bottom:20px; }
            .leave-request-form label { font-size:12px; font-weight:600; color:var(--color-text-secondary); display:block; margin-bottom:4px; }
            .leave-request-form input, .leave-request-form select { height:34px; border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:0 10px; font-size:13px; }
            .compoff-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; }
            .compoff-badge.confirmed { background:#dcfce7; color:#166534; }
            .compoff-badge.used { background:#f1f5f9; color:#64748b; }
            .compoff-badge.pending { background:#fef9c3; color:#854d0e; }
        </style>

        <!-- KPI Row -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Upcoming Shifts</div>
                <div class="stat-value"><?php echo count($shifts); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Upcoming Leaves</div>
                <div class="stat-value"><?php echo count($leaves); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Comp-Offs Available</div>
                <div class="stat-value"><?php echo count(array_filter($comp_offs, function($c) { return $c->status === 'confirmed'; })); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Upcoming Holidays</div>
                <div class="stat-value"><?php echo count($holidays); ?></div>
            </div>
        </div>

        <!-- Request Leave -->
        <div class="ops-card" style="margin-bottom:24px;">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Request Leave</h3>
            <div class="leave-request-form">
                <div>
                    <label>Date</label>
                    <input type="date" id="leave-date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                </div>
                <div>
                    <label>Type</label>
                    <select id="leave-type">
                        <option value="full_day">Full Day</option>
                        <option value="half_day">Half Day</option>
                        <option value="emergency">Emergency</option>
                    </select>
                </div>
                <div>
                    <label>Reason (optional)</label>
                    <input type="text" id="leave-reason" placeholder="Personal, Medical, etc." style="min-width:200px;">
                </div>
                <button onclick="submitLeaveRequest()" class="ops-btn primary" style="height:34px;padding:0 20px;font-size:13px;">Submit Request</button>
            </div>
            <div id="leave-request-msg" style="display:none;padding:8px 16px;border-radius:var(--radius-sm);font-size:13px;margin-top:8px;"></div>
        </div>

        <div class="schedule-grid">
            <!-- Upcoming Shifts -->
            <div class="ops-card">
                <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Upcoming Shifts</h3>
                <?php if (empty($shifts)): ?>
                    <div class="ops-empty-state" style="padding:30px;"><div class="ops-empty-state-title">No upcoming shifts scheduled</div></div>
                <?php else: ?>
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Shift</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shifts as $s):
                                $is_today = date('Y-m-d', strtotime($s->shift_start)) === date('Y-m-d');
                            ?>
                            <tr<?php echo $is_today ? ' style="background:var(--color-primary-light);"' : ''; ?>>
                                <td style="font-weight:<?php echo $is_today ? '700' : '400'; ?>;">
                                    <?php echo date('D, M j', strtotime($s->shift_start)); ?>
                                    <?php if ($is_today): ?><span style="font-size:10px;color:var(--color-primary);"> TODAY</span><?php endif; ?>
                                </td>
                                <td><span class="shift-pill" style="background:<?php echo esc_attr($s->shift_color ?: '#3b82f6'); ?>;"><?php echo esc_html($s->shift_name ?: $s->shift_type); ?></span></td>
                                <td style="font-size:12px;color:var(--color-text-secondary);"><?php echo date('g:ia', strtotime($s->shift_start)); ?> - <?php echo date('g:ia', strtotime($s->shift_end)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Leaves + Comp-offs -->
            <div>
                <!-- Upcoming leaves -->
                <div class="ops-card" style="margin-bottom:24px;">
                    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">My Leaves</h3>
                    <?php $all_leaves = array_merge($past_leaves ?: [], $leaves ?: []); ?>
                    <?php if (empty($all_leaves)): ?>
                        <div class="ops-empty-state" style="padding:20px;"><div class="ops-empty-state-title">No leaves recorded</div></div>
                    <?php else: ?>
                        <table class="audit-table">
                            <thead><tr><th>Date</th><th>Type</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($all_leaves as $l):
                                    $is_past = $l->leave_date < date('Y-m-d');
                                ?>
                                <tr style="<?php echo $is_past ? 'opacity:0.5;' : ''; ?>">
                                    <td><?php echo date('D, M j', strtotime($l->leave_date)); ?></td>
                                    <td style="font-size:12px;"><?php echo esc_html($l->leave_type ?: 'Full Day'); ?></td>
                                    <td><span class="status-badge <?php echo ($l->status ?? 'approved') === 'approved' ? 'success' : 'pending'; ?>"><?php echo esc_html($l->status ?? 'approved'); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Comp-offs -->
                <div class="ops-card">
                    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Comp-Off Balance</h3>
                    <?php if (empty($comp_offs)): ?>
                        <div class="ops-empty-state" style="padding:20px;"><div class="ops-empty-state-title">No comp-offs earned</div></div>
                    <?php else: ?>
                        <table class="audit-table">
                            <thead><tr><th>Holiday Worked</th><th>Comp-Off Date</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($comp_offs as $c): ?>
                                <tr>
                                    <td style="font-size:12px;"><?php echo esc_html($c->holiday_name ?: 'Holiday'); ?></td>
                                    <td><?php echo $c->comp_off_date ? date('M j', strtotime($c->comp_off_date)) : '<em style="color:var(--color-text-tertiary);">Not scheduled</em>'; ?></td>
                                    <td><span class="compoff-badge <?php echo esc_attr($c->status ?: 'pending'); ?>"><?php echo esc_html($c->status ?: 'pending'); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Upcoming Holidays -->
        <?php if (!empty($holidays)): ?>
        <div class="ops-card">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Upcoming Holidays</h3>
            <table class="audit-table">
                <thead><tr><th>Holiday</th><th>Date</th><th>Duration</th></tr></thead>
                <tbody>
                    <?php foreach ($holidays as $h):
                        $start = strtotime($h->start_date);
                        $end = strtotime($h->end_date);
                        $dur = max(1, round(($end - $start) / 86400) + 1);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($h->name); ?></strong></td>
                        <td><?php echo date('D, M j', $start); ?><?php echo $dur > 1 ? ' - ' . date('M j', $end) : ''; ?></td>
                        <td><?php echo $dur; ?> day<?php echo $dur > 1 ? 's' : ''; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <script>
        function submitLeaveRequest() {
            var date = document.getElementById('leave-date').value;
            var type = document.getElementById('leave-type').value;
            var reason = document.getElementById('leave-reason').value;
            var msg = document.getElementById('leave-request-msg');

            if (!date) {
                msg.style.display = 'block';
                msg.style.background = '#fee2e2';
                msg.style.color = '#991b1b';
                msg.textContent = 'Please select a date.';
                return;
            }

            jQuery.post(ajaxurl, {
                action: 'ais_save_leave',
                _ajax_nonce: '<?php echo wp_create_nonce('ais_calendar_nonce'); ?>',
                agent_email: '<?php echo esc_js($agent_email); ?>',
                leave_date: date,
                leave_type: type,
                reason: reason
            }, function(response) {
                if (response.success) {
                    msg.style.display = 'block';
                    msg.style.background = '#dcfce7';
                    msg.style.color = '#166534';
                    msg.textContent = 'Leave request submitted for ' + date;
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    msg.style.display = 'block';
                    msg.style.background = '#fee2e2';
                    msg.style.color = '#991b1b';
                    msg.textContent = response.data || 'Failed to submit leave request.';
                }
            }).fail(function() {
                msg.style.display = 'block';
                msg.style.background = '#fee2e2';
                msg.style.color = '#991b1b';
                msg.textContent = 'Network error. Please try again.';
            });
        }
        </script>
        <?php
    }
}
