<?php
/**
 * My Schedule — Agent Self-Service Portal
 *
 * Full month calendar with clear working/off-day distinction,
 * click-to-request-leave popup, leaves list, and comp-off balance.
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

        // Month navigation
        $nav_month = isset($_GET['cal_month']) ? intval($_GET['cal_month']) : intval(date('m'));
        $nav_year = isset($_GET['cal_year']) ? intval($_GET['cal_year']) : intval(date('Y'));
        $month_start = sprintf('%04d-%02d-01', $nav_year, $nav_month);
        $month_end = date('Y-m-t', strtotime($month_start));
        $today = date('Y-m-d');

        // All shifts for the displayed month
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, sd.name as shift_name, sd.color as shift_color
             FROM {$wpdb->prefix}ais_agent_shifts s
             LEFT JOIN {$wpdb->prefix}ais_shift_definitions sd ON s.shift_def_id = sd.id
             WHERE s.agent_email = %s AND DATE(s.shift_start) >= %s AND DATE(s.shift_start) <= %s
             ORDER BY s.shift_start ASC",
            $agent_email, $month_start, $month_end
        ));

        // Index shifts by date
        $shifts_by_date = [];
        foreach ($shifts as $s) {
            $d = date('Y-m-d', strtotime($s->shift_start));
            $shifts_by_date[$d][] = $s;
        }

        // All leaves for displayed month (any status)
        $leaves = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_agent_leaves
             WHERE agent_email = %s AND date_start <= %s AND date_end >= %s
             ORDER BY date_start ASC",
            $agent_email, $month_end, $month_start
        ));

        // Index leaves by date
        $leaves_by_date = [];
        $leave_labels = ['full_day' => 'Full Day', 'half_day' => 'Half Day', 'emergency' => 'Emergency', 'personal' => 'Personal', 'sick' => 'Sick', 'compensation' => 'Comp-off'];
        foreach ($leaves as $l) {
            $d1 = max($month_start, $l->date_start);
            $d2 = min($month_end, $l->date_end);
            $cur = $d1;
            while ($cur <= $d2) {
                $leaves_by_date[$cur][] = $l;
                $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
            }
        }

        // Holidays for displayed month
        $holidays = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ais_holidays
             WHERE start_date <= %s AND end_date >= %s
             ORDER BY start_date ASC",
            $month_end, $month_start
        ));

        // Index holidays by date
        $holidays_by_date = [];
        foreach ($holidays as $h) {
            $d1 = max($month_start, $h->start_date);
            $d2 = min($month_end, $h->end_date);
            $cur = $d1;
            while ($cur <= $d2) {
                $holidays_by_date[$cur] = $h;
                $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
            }
        }

        // Comp-offs (with date worked)
        $comp_offs = $wpdb->get_results($wpdb->prepare(
            "SELECT hd.*, h.name as holiday_name
             FROM {$wpdb->prefix}ais_holiday_duty hd
             LEFT JOIN {$wpdb->prefix}ais_holidays h ON hd.holiday_id = h.id
             WHERE hd.agent_email = %s
             ORDER BY hd.comp_off_date DESC",
            $agent_email
        ));

        // Comp-off leaves to merge into leaves list
        $comp_off_leaves = [];
        foreach ($comp_offs as $c) {
            if (!empty($c->comp_off_date)) {
                $comp_off_leaves[] = $c;
            }
        }

        // Upcoming leaves count (for KPI)
        $upcoming_leaves_count = 0;
        foreach ($leaves as $l) {
            if ($l->date_start >= $today && $l->status === 'approved') {
                $upcoming_leaves_count++;
            }
        }

        $this->render_page($shifts_by_date, $leaves, $leaves_by_date, $holidays, $holidays_by_date, $comp_offs, $comp_off_leaves, $agent_email, $nav_year, $nav_month, $today, $upcoming_leaves_count, $leave_labels);
    }

    private function render_page($shifts_by_date, $leaves, $leaves_by_date, $holidays, $holidays_by_date, $comp_offs, $comp_off_leaves, $agent_email, $year, $month, $today, $upcoming_leaves, $leave_labels) {
        $month_start = sprintf('%04d-%02d-01', $year, $month);
        $month_end = date('Y-m-t', strtotime($month_start));
        $days_in_month = intval(date('t', strtotime($month_start)));
        $first_dow = intval(date('w', strtotime($month_start))); // 0=Sun
        $month_label = date('F Y', strtotime($month_start));

        // Prev/next month URLs
        $base_url = admin_url('admin.php');
        $prev_m = $month - 1;
        $prev_y = $year;
        if ($prev_m < 1) { $prev_m = 12; $prev_y--; }
        $next_m = $month + 1;
        $next_y = $year;
        if ($next_m > 12) { $next_m = 1; $next_y++; }

        $nonce = wp_create_nonce('ais_calendar_nonce');
        ?>
        <style>
            /* Calendar Grid */
            .my-cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
            .my-cal-nav h3 { margin:0; font-size:16px; font-weight:700; }
            .my-cal-nav .ops-btn { height:30px; padding:0 12px; font-size:12px; }
            .my-cal { width:100%; border-collapse:collapse; table-layout:fixed; }
            .my-cal th { padding:8px 4px; font-size:11px; font-weight:600; color:var(--color-text-tertiary); text-align:center; text-transform:uppercase; letter-spacing:0.5px; }
            .my-cal td { height:80px; padding:6px; vertical-align:top; border:1px solid var(--color-border); background:var(--color-bg-subtle); cursor:pointer; transition:all 0.15s ease; position:relative; }
            .my-cal td:hover { box-shadow:inset 0 0 0 2px var(--color-primary); }
            .my-cal td.outside { background:var(--color-bg-subtle); opacity:0.3; cursor:default; }
            .my-cal td.outside:hover { box-shadow:none; }
            .my-cal td.working-day { background:#f0fdf4; border-color:#bbf7d0; }
            .my-cal td.today { border:2px solid #3b82f6; }
            .my-cal td.today.working-day { border-color:#3b82f6; }
            .my-cal td.holiday-cell { background:#fff7ed; border-color:#fed7aa; }
            .my-cal td.leave-cell { background:#fef2f2; border-color:#fecaca; }
            .my-cal .day-num { font-size:12px; font-weight:600; color:var(--color-text-secondary); margin-bottom:2px; display:flex; align-items:center; gap:4px; }
            .my-cal td.today .day-num { color:#2563eb; font-weight:700; }
            .my-cal td.working-day .day-num { color:#15803d; }
            .my-cal .cal-shift-label { display:block; padding:1px 5px; border-radius:3px; font-size:9px; font-weight:600; color:#fff; margin-bottom:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .my-cal .cal-holiday-label { display:block; font-size:9px; font-weight:600; color:#c2410c; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .my-cal .cal-leave-label { display:block; font-size:9px; font-weight:600; color:#dc2626; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .my-cal .cal-leave-label.pending { color:#a16207; }
            .my-cal .today-badge { font-size:8px; background:#3b82f6; color:#fff; padding:1px 4px; border-radius:3px; font-weight:700; letter-spacing:0.3px; }

            /* Legend */
            .cal-legend { display:flex; gap:16px; flex-wrap:wrap; margin-top:12px; padding-top:12px; border-top:1px solid var(--color-border); }
            .cal-legend-item { display:flex; align-items:center; gap:6px; font-size:11px; color:var(--color-text-secondary); }
            .cal-legend-swatch { width:14px; height:14px; border-radius:3px; border:1px solid rgba(0,0,0,0.1); }

            /* Schedule Grid */
            .schedule-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px; }
            @media (max-width:900px) { .schedule-grid { grid-template-columns:1fr; } }

            /* Comp-off badge */
            .compoff-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; }
            .compoff-badge.confirmed { background:#dcfce7; color:#166534; }
            .compoff-badge.used { background:#f1f5f9; color:#64748b; }
            .compoff-badge.pending { background:#fef9c3; color:#854d0e; }

            /* Leave Request Modal */
            .leave-modal-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center; }
            .leave-modal-overlay.active { display:flex; }
            .leave-modal { background:#fff; border-radius:12px; padding:28px; width:420px; max-width:90vw; box-shadow:0 20px 60px rgba(0,0,0,0.3); position:relative; }
            .leave-modal h3 { margin:0 0 20px; font-size:16px; font-weight:700; color:var(--color-text); }
            .leave-modal-close { position:absolute; top:12px; right:16px; background:none; border:none; font-size:22px; cursor:pointer; color:var(--color-text-tertiary); padding:4px 8px; line-height:1; }
            .leave-modal-close:hover { color:var(--color-text); }
            .leave-modal .form-row { margin-bottom:16px; }
            .leave-modal .form-row label { display:block; font-size:12px; font-weight:600; color:var(--color-text-secondary); margin-bottom:6px; }
            .leave-modal .form-row input,
            .leave-modal .form-row select,
            .leave-modal .form-row textarea { width:100%; height:36px; border:1px solid var(--color-border); border-radius:6px; padding:0 10px; font-size:13px; box-sizing:border-box; }
            .leave-modal .form-row textarea { height:70px; padding:8px 10px; resize:vertical; }
            .leave-modal .form-row-inline { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
            .leave-modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
            .leave-modal-msg { display:none; padding:8px 12px; border-radius:6px; font-size:13px; margin-top:12px; }
        </style>

        <!-- KPI Row -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Shifts This Month</div>
                <div class="stat-value"><?php echo count($shifts_by_date); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Upcoming Leaves</div>
                <div class="stat-value"><?php echo $upcoming_leaves; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Comp-Offs Available</div>
                <div class="stat-value"><?php echo count(array_filter($comp_offs, function($c) { return $c->comp_off_status === 'confirmed'; })); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Holidays This Month</div>
                <div class="stat-value"><?php echo count($holidays); ?></div>
            </div>
        </div>

        <!-- Calendar -->
        <div class="ops-card" style="margin-bottom:24px;">
            <div class="my-cal-nav">
                <a href="<?php echo esc_url($base_url . '?page=ai-ops&section=my-schedule&cal_year=' . $prev_y . '&cal_month=' . $prev_m); ?>" class="ops-btn secondary">&larr; Prev</a>
                <h3><?php echo esc_html($month_label); ?></h3>
                <a href="<?php echo esc_url($base_url . '?page=ai-ops&section=my-schedule&cal_year=' . $next_y . '&cal_month=' . $next_m); ?>" class="ops-btn secondary">Next &rarr;</a>
            </div>
            <table class="my-cal">
                <thead>
                    <tr>
                        <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $total_cells = $first_dow + $days_in_month;
                $rows = ceil($total_cells / 7);
                for ($row = 0; $row < $rows; $row++):
                ?>
                    <tr>
                    <?php for ($col = 0; $col < 7; $col++):
                        $cell = $row * 7 + $col;
                        $day_num = $cell - $first_dow + 1;
                        if ($day_num < 1 || $day_num > $days_in_month):
                    ?>
                        <td class="outside"></td>
                    <?php else:
                        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day_num);
                        $is_today = ($date_str === $today);
                        $is_holiday = isset($holidays_by_date[$date_str]);
                        $has_leave = isset($leaves_by_date[$date_str]);
                        $has_shift = isset($shifts_by_date[$date_str]);

                        // Determine cell class priority: leave > holiday > working > off
                        $classes = [];
                        if ($is_today) $classes[] = 'today';
                        if ($has_leave) {
                            $classes[] = 'leave-cell';
                        } elseif ($is_holiday) {
                            $classes[] = 'holiday-cell';
                        } elseif ($has_shift) {
                            $classes[] = 'working-day';
                        }
                    ?>
                        <td<?php echo !empty($classes) ? ' class="' . implode(' ', $classes) . '"' : ''; ?> onclick="openLeaveModal('<?php echo esc_attr($date_str); ?>')">
                            <div class="day-num">
                                <?php echo $day_num; ?>
                                <?php if ($is_today): ?><span class="today-badge">TODAY</span><?php endif; ?>
                            </div>
                            <?php
                            // Holiday label
                            if ($is_holiday) {
                                echo '<span class="cal-holiday-label" title="' . esc_attr($holidays_by_date[$date_str]->name) . '">' . esc_html($holidays_by_date[$date_str]->name) . '</span>';
                            }
                            // Shift labels
                            if ($has_shift) {
                                foreach ($shifts_by_date[$date_str] as $s) {
                                    $color = $s->shift_color ?: '#3b82f6';
                                    $time = date('g:ia', strtotime($s->shift_start)) . '-' . date('g:ia', strtotime($s->shift_end));
                                    echo '<span class="cal-shift-label" style="background:' . esc_attr($color) . ';" title="' . esc_attr(($s->shift_name ?: $s->shift_type) . ' ' . $time) . '">' . esc_html($s->shift_name ?: $s->shift_type) . '</span>';
                                }
                            }
                            // Leave labels
                            if ($has_leave) {
                                foreach ($leaves_by_date[$date_str] as $l) {
                                    $label = $leave_labels[$l->leave_type] ?? ucfirst(str_replace('_', ' ', $l->leave_type));
                                    $pending_class = ($l->status === 'pending') ? ' pending' : '';
                                    $status_suffix = ($l->status === 'pending') ? ' (P)' : '';
                                    echo '<span class="cal-leave-label' . $pending_class . '" title="' . esc_attr($label . ' - ' . ucfirst($l->status)) . '">' . esc_html($label . $status_suffix) . '</span>';
                                }
                            }
                            ?>
                        </td>
                    <?php endif; ?>
                    <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>

            <!-- Legend -->
            <div class="cal-legend">
                <div class="cal-legend-item">
                    <div class="cal-legend-swatch" style="background:#f0fdf4; border-color:#bbf7d0;"></div>
                    Working Day
                </div>
                <div class="cal-legend-item">
                    <div class="cal-legend-swatch" style="background:var(--color-bg-subtle);"></div>
                    Off Day
                </div>
                <div class="cal-legend-item">
                    <div class="cal-legend-swatch" style="background:#fff7ed; border-color:#fed7aa;"></div>
                    Holiday
                </div>
                <div class="cal-legend-item">
                    <div class="cal-legend-swatch" style="background:#fef2f2; border-color:#fecaca;"></div>
                    Leave
                </div>
                <div class="cal-legend-item">
                    <div class="cal-legend-swatch" style="background:#3b82f6; border-color:#3b82f6;"></div>
                    Today
                </div>
            </div>
        </div>

        <div class="schedule-grid">
            <!-- My Leaves -->
            <div class="ops-card">
                <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">My Leaves</h3>
                <?php
                // Merge regular leaves + comp-off leaves for this month
                $all_leave_entries = [];

                // Regular leaves
                foreach ($leaves as $l) {
                    $all_leave_entries[] = [
                        'date_start' => $l->date_start,
                        'date_end'   => $l->date_end,
                        'type'       => $leave_labels[$l->leave_type] ?? ucfirst(str_replace('_', ' ', $l->leave_type)),
                        'status'     => $l->status,
                        'reason'     => isset($l->reason) ? $l->reason : '',
                        'is_compoff' => false,
                    ];
                }

                // Comp-off leaves (show as leave entries if comp_off_date falls in this month)
                foreach ($comp_off_leaves as $c) {
                    if ($c->comp_off_date >= $month_start && $c->comp_off_date <= $month_end) {
                        $all_leave_entries[] = [
                            'date_start' => $c->comp_off_date,
                            'date_end'   => $c->comp_off_date,
                            'type'       => 'Comp-off',
                            'status'     => $c->comp_off_status ?: 'pending',
                            'reason'     => 'Holiday duty: ' . ($c->holiday_name ?: 'Holiday'),
                            'is_compoff' => true,
                        ];
                    }
                }

                // Sort by date
                usort($all_leave_entries, function($a, $b) {
                    return strcmp($a['date_start'], $b['date_start']);
                });

                if (empty($all_leave_entries)): ?>
                    <div class="ops-empty-state" style="padding:20px;"><div class="ops-empty-state-title">No leaves this month</div></div>
                <?php else: ?>
                    <table class="audit-table">
                        <thead><tr><th>Date</th><th>Type</th><th>Reason</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($all_leave_entries as $entry):
                                $is_past = $entry['date_start'] < $today;
                                $date_display = date('D, M j', strtotime($entry['date_start']));
                                if ($entry['date_start'] !== $entry['date_end']) {
                                    $date_display .= ' - ' . date('M j', strtotime($entry['date_end']));
                                }
                                $status_class = 'pending';
                                if ($entry['status'] === 'approved' || $entry['status'] === 'confirmed' || $entry['status'] === 'used') {
                                    $status_class = 'success';
                                } elseif ($entry['status'] === 'rejected') {
                                    $status_class = 'failed';
                                }
                            ?>
                            <tr style="<?php echo $is_past ? 'opacity:0.5;' : ''; ?>">
                                <td style="font-size:12px;white-space:nowrap;"><?php echo esc_html($date_display); ?></td>
                                <td style="font-size:12px;"><?php echo esc_html($entry['type']); ?></td>
                                <td style="font-size:11px;color:var(--color-text-tertiary);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr($entry['reason']); ?>"><?php echo esc_html($entry['reason'] ?: '-'); ?></td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo esc_html(ucfirst($entry['status'])); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Comp-Off Balance -->
            <div class="ops-card">
                <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Comp-Off Balance</h3>
                <?php if (empty($comp_offs)): ?>
                    <div class="ops-empty-state" style="padding:20px;"><div class="ops-empty-state-title">No comp-offs earned</div></div>
                <?php else: ?>
                    <table class="audit-table">
                        <thead><tr><th>Holiday</th><th>Date Worked</th><th>Comp-Off Date</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($comp_offs as $c): ?>
                            <tr>
                                <td style="font-size:12px;"><?php echo esc_html($c->holiday_name ?: 'Holiday'); ?></td>
                                <td style="font-size:12px;"><?php echo !empty($c->date) ? date('D, M j', strtotime($c->date)) : '<em style="color:var(--color-text-tertiary);">N/A</em>'; ?></td>
                                <td style="font-size:12px;"><?php echo $c->comp_off_date ? date('D, M j', strtotime($c->comp_off_date)) : '<em style="color:var(--color-text-tertiary);">Not scheduled</em>'; ?></td>
                                <td><span class="compoff-badge <?php echo esc_attr($c->comp_off_status ?: 'pending'); ?>"><?php echo esc_html(ucfirst($c->comp_off_status ?: 'pending')); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Leave Request Modal -->
        <div class="leave-modal-overlay" id="leave-modal-overlay">
            <div class="leave-modal">
                <button class="leave-modal-close" onclick="closeLeaveModal()">&times;</button>
                <h3>Request Leave</h3>
                <div class="form-row form-row-inline">
                    <div>
                        <label>From</label>
                        <input type="date" id="leave-date-from">
                    </div>
                    <div>
                        <label>To</label>
                        <input type="date" id="leave-date-to">
                    </div>
                </div>
                <div class="form-row">
                    <label>Type</label>
                    <select id="leave-type">
                        <option value="full_day">Full Day</option>
                        <option value="half_day">Half Day</option>
                        <option value="emergency">Emergency</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Reason (optional)</label>
                    <textarea id="leave-reason" placeholder="Personal, Medical, etc."></textarea>
                </div>
                <div class="leave-modal-msg" id="leave-modal-msg"></div>
                <div class="leave-modal-actions">
                    <button onclick="closeLeaveModal()" class="ops-btn secondary">Cancel</button>
                    <button onclick="submitLeaveRequest()" class="ops-btn primary" id="leave-submit-btn">Submit Request</button>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Close modal on overlay click
            $('#leave-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    closeLeaveModal();
                }
            });

            // Close on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeLeaveModal();
                }
            });

            // Sync "from" date to "to" date if "to" is earlier
            $('#leave-date-from').on('change', function() {
                var from = $(this).val();
                var to = $('#leave-date-to').val();
                if (!to || to < from) {
                    $('#leave-date-to').val(from);
                }
            });
        });

        function openLeaveModal(dateStr) {
            document.getElementById('leave-date-from').value = dateStr;
            document.getElementById('leave-date-to').value = dateStr;
            document.getElementById('leave-type').value = 'full_day';
            document.getElementById('leave-reason').value = '';
            var msg = document.getElementById('leave-modal-msg');
            msg.style.display = 'none';
            msg.textContent = '';
            document.getElementById('leave-submit-btn').disabled = false;
            document.getElementById('leave-modal-overlay').classList.add('active');
        }

        function closeLeaveModal() {
            document.getElementById('leave-modal-overlay').classList.remove('active');
        }

        function submitLeaveRequest() {
            var dateFrom = document.getElementById('leave-date-from').value;
            var dateTo = document.getElementById('leave-date-to').value;
            var type = document.getElementById('leave-type').value;
            var reason = document.getElementById('leave-reason').value;
            var msg = document.getElementById('leave-modal-msg');
            var btn = document.getElementById('leave-submit-btn');

            if (!dateFrom) {
                msg.style.display = 'block';
                msg.style.background = '#fee2e2';
                msg.style.color = '#991b1b';
                msg.textContent = 'Please select a start date.';
                return;
            }

            if (!dateTo) {
                dateTo = dateFrom;
            }

            if (dateTo < dateFrom) {
                msg.style.display = 'block';
                msg.style.background = '#fee2e2';
                msg.style.color = '#991b1b';
                msg.textContent = '"To" date cannot be before "From" date.';
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Submitting...';

            jQuery.post(ajaxurl, {
                action: 'ai_ops_save_leave',
                _ajax_nonce: '<?php echo esc_js($nonce); ?>',
                agent_email: '<?php echo esc_js($agent_email); ?>',
                date_start: dateFrom,
                date_end: dateTo,
                leave_type: type,
                reason: reason
            }, function(response) {
                if (response.success) {
                    msg.style.display = 'block';
                    msg.style.background = '#dcfce7';
                    msg.style.color = '#166534';
                    msg.textContent = 'Leave request submitted successfully!';
                    setTimeout(function() { location.reload(); }, 1200);
                } else {
                    msg.style.display = 'block';
                    msg.style.background = '#fee2e2';
                    msg.style.color = '#991b1b';
                    msg.textContent = response.data || 'Failed to submit leave request.';
                    btn.disabled = false;
                    btn.textContent = 'Submit Request';
                }
            }).fail(function() {
                msg.style.display = 'block';
                msg.style.background = '#fee2e2';
                msg.style.color = '#991b1b';
                msg.textContent = 'Network error. Please try again.';
                btn.disabled = false;
                btn.textContent = 'Submit Request';
            });
        }
        </script>
        <?php
    }
}
