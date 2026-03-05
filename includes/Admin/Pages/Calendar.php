<?php
/**
 * Calendar Page — Enhanced with Holidays, Leaves, Comp-offs, Staffing
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Services\ShiftProcessor;
use SupportOps\Admin\AccessControl;

class Calendar {

    private $database;
    private $shift_processor;

    /** Staffing minimums by day-of-week (0=Sun … 6=Sat) */
    private static $staffing_mins = [
        0 => ['morning' => 3, 'afternoon' => 2, 'night' => 1],
        1 => ['morning' => 4, 'afternoon' => 3, 'night' => 1],
        2 => ['morning' => 5, 'afternoon' => 3, 'night' => 1],
        3 => ['morning' => 5, 'afternoon' => 3, 'night' => 1],
        4 => ['morning' => 5, 'afternoon' => 3, 'night' => 1],
        5 => ['morning' => 4, 'afternoon' => 2, 'night' => 1],
        6 => ['morning' => 3, 'afternoon' => 2, 'night' => 1],
    ];

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->shift_processor = new ShiftProcessor($database);
    }

    private function categorize_shift($shift_type) {
        $t = strtolower($shift_type);
        if (strpos($t, 'morning') !== false || strpos($t, 'day') !== false) return 'morning';
        if (strpos($t, 'afternoon') !== false || strpos($t, 'evening') !== false) return 'afternoon';
        if (strpos($t, 'night') !== false) return 'night';
        return 'morning';
    }

    public function render() {
        global $wpdb;

        $mo = isset($_GET['mo']) ? intval($_GET['mo']) : 0;
        $now = new \DateTime();
        if ($mo != 0) {
            $now->modify("$mo month");
        }
        $start = new \DateTime($now->format('Y-m-01'));
        $end   = new \DateTime($now->format('Y-m-t'));

        $is_read_only = AccessControl::is_read_only('calendar');
        $is_admin     = AccessControl::is_admin();

        // Handle bulk shift generation (admin only)
        if (!$is_read_only && isset($_POST['generate_shifts'])) {
            $this->shift_processor->process($_POST);
            echo '<div class="notice notice-success is-dismissible"><p>Schedule Updated.</p></div>';
        }

        // ── Table references ──
        $shifts_table    = $this->database->get_table('agent_shifts');
        $agents_table    = $this->database->get_table('agents');
        $shift_defs_table = $this->database->get_table('shift_definitions');

        // ── Shifts ──
        $shift_team_filter = AccessControl::sql_agent_filter('s.agent_email');
        $raw = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, a.first_name, sd.color as current_shift_color
             FROM {$shifts_table} s
             LEFT JOIN {$agents_table} a ON s.agent_email=a.email
             LEFT JOIN {$shift_defs_table} sd ON s.shift_def_id=sd.id
             WHERE s.shift_start BETWEEN %s AND %s{$shift_team_filter}
             ORDER BY s.shift_start ASC",
            $start->format('Y-m-d 00:00:00'),
            $end->format('Y-m-d 23:59:59')
        ));

        $cal = [];
        foreach ($raw as $s) {
            $day = intval(substr($s->shift_start, 8, 2));
            $cal[$day][] = $s;
        }

        // ── Agents & Shift Definitions ──
        $agent_team_filter = AccessControl::sql_agent_filter('email');
        $agents = $wpdb->get_results(
            "SELECT * FROM {$agents_table} WHERE 1=1{$agent_team_filter} ORDER BY first_name ASC"
        );
        $shift_defs = $wpdb->get_results(
            "SELECT * FROM {$shift_defs_table} ORDER BY name ASC"
        );

        // ── Holidays for the month ──
        $holidays_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->database->get_table('holidays')}
             WHERE date_start <= %s AND date_end >= %s ORDER BY date_start",
            $end->format('Y-m-d'), $start->format('Y-m-d')
        ));

        $holidays = [];
        foreach ($holidays_raw as $h) {
            $hs = max(strtotime($start->format('Y-m-d')), strtotime($h->date_start));
            $he = min(strtotime($end->format('Y-m-d')), strtotime($h->date_end));
            for ($ts = $hs; $ts <= $he; $ts += 86400) {
                $holidays[intval(date('j', $ts))] = $h;
            }
        }

        // ── Leaves for the month ──
        $leave_filter = AccessControl::sql_agent_filter('l.agent_email');
        $leaves_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, a.first_name, a.last_name
             FROM {$this->database->get_table('agent_leaves')} l
             LEFT JOIN {$agents_table} a ON l.agent_email = a.email
             WHERE l.status = 'approved' AND l.date_start <= %s AND l.date_end >= %s{$leave_filter}
             ORDER BY a.first_name",
            $end->format('Y-m-d'), $start->format('Y-m-d')
        ));

        $leaves = [];
        foreach ($leaves_raw as $l) {
            $ls = max(strtotime($start->format('Y-m-d')), strtotime($l->date_start));
            $le = min(strtotime($end->format('Y-m-d')), strtotime($l->date_end));
            for ($ts = $ls; $ts <= $le; $ts += 86400) {
                $leaves[intval(date('j', $ts))][] = $l;
            }
        }

        // ── Comp-offs for the month ──
        $comp_offs_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT hd.*, a.first_name
             FROM {$this->database->get_table('holiday_duty')} hd
             LEFT JOIN {$agents_table} a ON hd.agent_email = a.email
             WHERE hd.comp_off_date BETWEEN %s AND %s
               AND hd.comp_off_status IN ('confirmed','used')",
            $start->format('Y-m-d'), $end->format('Y-m-d')
        ));
        $comp_offs = [];
        foreach ($comp_offs_raw as $co) {
            $comp_offs[intval(date('j', strtotime($co->comp_off_date)))][] = $co;
        }

        // ── Calendar extras for the month ──
        $extras_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, a.first_name
             FROM {$this->database->get_table('calendar_extras')} e
             LEFT JOIN {$agents_table} a ON e.agent_email = a.email
             WHERE e.date BETWEEN %s AND %s ORDER BY e.shift_type, a.first_name",
            $start->format('Y-m-d'), $end->format('Y-m-d')
        ));
        $extras = [];
        foreach ($extras_raw as $ex) {
            $extras[intval(date('j', strtotime($ex->date)))][] = $ex;
        }

        // ── All holidays for year (holiday modal) ──
        $year = $start->format('Y');
        $all_holidays = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->database->get_table('holidays')} WHERE year = %d ORDER BY date_start",
            $year
        ));

        // ── All leaves for month (leave modal) ──
        $all_leaves = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, a.first_name, a.last_name
             FROM {$this->database->get_table('agent_leaves')} l
             LEFT JOIN {$agents_table} a ON l.agent_email = a.email
             WHERE l.status = 'approved' AND l.date_start <= %s AND l.date_end >= %s{$leave_filter}
             ORDER BY l.date_start DESC",
            $end->format('Y-m-d'), $start->format('Y-m-d')
        ));

        $this->render_calendar(
            $start, $end, $cal, $mo, $agents, $shift_defs,
            $holidays, $leaves, $comp_offs, $extras,
            $all_holidays, $all_leaves, $is_read_only, $is_admin
        );
    }

    private function render_calendar(
        $start, $end, $cal, $mo, $agents, $shift_defs,
        $holidays, $leaves, $comp_offs, $extras,
        $all_holidays, $all_leaves, $is_read_only, $is_admin
    ) {
        ?>
        <style>
            /* ── Calendar Page Styles ── */
            .calendar-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 24px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--color-border);
                flex-wrap: wrap;
                gap: 16px;
            }
            .calendar-nav {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .calendar-nav .ops-btn {
                padding: 0 12px;
                height: 36px;
                min-width: 40px;
                font-size: 16px;
                font-weight: 600;
            }
            .calendar-month {
                font-size: 20px;
                font-weight: 700;
                color: var(--color-text-primary);
                min-width: 180px;
                text-align: center;
            }
            .calendar-actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .bulk-schedule-btn {
                padding: 0 16px;
                height: 36px;
            }
            #bulk-gen {
                display: none;
                background: var(--color-bg-subtle);
                padding: 24px;
                margin-bottom: 24px;
                border-radius: var(--radius-md);
                border: 1px solid var(--color-border);
            }
            #bulk-gen .form-row {
                align-items: flex-end;
                gap: 16px;
            }
            #bulk-gen .form-row > div {
                flex: 1;
                min-width: 0;
            }
            #bulk-gen .form-row > div:last-child {
                flex: 0 0 auto;
            }
            #bulk-gen label {
                display: block;
                font-size: 13px;
                font-weight: 500;
                color: var(--color-text-secondary);
                margin-bottom: 8px;
            }
            #bulk-gen .ops-input,
            #bulk-gen .select2-container .select2-selection--single {
                height: 38px !important;
                border: 1px solid var(--color-border) !important;
                border-radius: var(--radius-sm) !important;
                font-size: 14px !important;
                padding: 0 12px !important;
                background: var(--color-bg) !important;
                transition: all 0.15s ease !important;
            }
            #bulk-gen .ops-input:focus,
            #bulk-gen .select2-container--focus .select2-selection--single {
                border-color: var(--color-primary) !important;
                box-shadow: 0 0 0 3px var(--color-primary-light) !important;
                outline: none !important;
            }
            #bulk-gen .ops-input:hover,
            #bulk-gen .select2-container:hover .select2-selection--single {
                border-color: var(--color-border-strong) !important;
            }
            #bulk-gen .select2-container .select2-selection--single .select2-selection__rendered {
                line-height: 38px !important;
                padding-left: 0 !important;
                color: var(--color-text-primary) !important;
            }
            #bulk-gen .select2-container .select2-selection--single .select2-selection__arrow {
                height: 36px !important;
                right: 8px !important;
            }
            #bulk-gen .flatpickr-input {
                height: 38px !important;
                line-height: 38px !important;
            }
            #bulk-gen .flatpickr-input::placeholder {
                color: var(--color-text-tertiary) !important;
            }

            /* ── Calendar Grid ── */
            .cal-grid {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                gap: 1px;
                background: var(--color-border);
                border: 1px solid var(--color-border);
                border-radius: var(--radius-md);
                overflow: hidden;
            }
            .cal-head {
                background: transparent;
                padding: 12px 8px;
                text-align: center;
                font-weight: 500;
                font-size: 13px;
                color: var(--color-text-tertiary);
                border-bottom: 1px solid var(--color-border);
            }
            .cal-cell {
                background: var(--color-bg);
                min-height: 120px;
                padding: 10px;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                flex-direction: column;
                position: relative;
            }
            .cal-cell:hover {
                background: var(--color-bg-hover);
                box-shadow: inset 0 0 0 2px var(--color-primary-light);
            }
            .cal-cell.empty {
                background: var(--color-bg-subtle);
                cursor: default;
            }
            .cal-cell.empty:hover {
                background: var(--color-bg-subtle);
                box-shadow: none;
            }
            .cal-cell.is-holiday {
                border-left: 3px solid #e67e22;
            }
            .cal-cell.is-understaffed {
                border-right: 3px solid var(--color-error);
            }
            .cal-date {
                font-weight: 600;
                font-size: 14px;
                color: var(--color-text-primary);
                margin-bottom: 6px;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .cal-holiday-badge {
                font-size: 10px;
                font-weight: 500;
                background: #fef3e2;
                color: #b45309;
                padding: 1px 6px;
                border-radius: 4px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 100%;
                display: block;
                margin-bottom: 4px;
            }
            .cal-off-badge {
                font-size: 10px;
                font-weight: 500;
                background: #fef2f2;
                color: #b91c1c;
                padding: 1px 6px;
                border-radius: 4px;
                display: inline-block;
                margin-bottom: 4px;
            }
            .cal-alert-badge {
                font-size: 10px;
                font-weight: 600;
                color: var(--color-error);
                position: absolute;
                top: 8px;
                right: 8px;
            }
            .shift-pill {
                font-size: 11px;
                padding: 4px 8px;
                border-radius: 4px;
                margin-bottom: 4px;
                color: #333;
                font-weight: 500;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                cursor: pointer;
                transition: opacity 0.2s ease;
                border: 1px solid rgba(0,0,0,0.1);
            }
            .shift-pill:hover {
                opacity: 0.8;
            }

            /* ── Generic Modal Overlay ── */
            .cal-modal-overlay {
                display: none;
                position: fixed;
                top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 9999;
                backdrop-filter: blur(4px);
            }
            .cal-modal-box {
                background: var(--color-bg);
                max-width: 92%;
                max-height: 85vh;
                margin: 6vh auto;
                border-radius: 16px;
                padding: 0;
                box-shadow: var(--shadow-overlay, 0 24px 48px rgba(0,0,0,.16));
                overflow: hidden;
                display: flex;
                flex-direction: column;
                animation: modalSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes modalSlideUp {
                from { transform: translateY(16px) scale(0.98); opacity: 0; }
                to   { transform: translateY(0) scale(1); opacity: 1; }
            }
            .cal-modal-header {
                padding: 20px 24px;
                border-bottom: 1px solid var(--color-border);
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: var(--color-bg);
                position: sticky;
                top: 0; z-index: 1;
            }
            .cal-modal-header h3 {
                margin: 0;
                font-size: 15px;
                font-weight: 600;
                color: var(--color-text-primary);
                letter-spacing: -0.01em;
            }
            .cal-modal-close {
                background: none; border: none;
                font-size: 20px;
                color: var(--color-text-tertiary);
                cursor: pointer; line-height: 1;
                width: 32px; height: 32px;
                display: flex; align-items: center; justify-content: center;
                border-radius: 8px;
                transition: all 0.15s ease;
            }
            .cal-modal-close:hover {
                color: var(--color-text-primary);
                background: var(--color-bg-subtle);
            }
            .cal-modal-body {
                overflow-y: auto;
                padding: 20px 24px;
                flex: 1;
            }
            .cal-modal-body::-webkit-scrollbar { width: 6px; }
            .cal-modal-body::-webkit-scrollbar-track { background: transparent; }
            .cal-modal-body::-webkit-scrollbar-thumb { background: var(--color-border); border-radius: 3px; }
            .cal-modal-footer {
                padding: 16px 24px;
                border-top: 1px solid var(--color-border);
                display: flex; gap: 8px; justify-content: flex-end;
            }

            /* ── Day Modal ── */
            #day-modal .cal-modal-box { width: 560px; }

            .day-section { margin-bottom: 20px; }
            .day-section:last-child { margin-bottom: 0; }
            .day-section-title {
                font-size: 12px;
                font-weight: 600;
                color: var(--color-text-secondary);
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .day-holiday-banner {
                background: #fef3e2;
                border: 1px solid #fbbf24;
                border-radius: 10px;
                padding: 10px 14px;
                margin-bottom: 16px;
                font-size: 13px;
                font-weight: 500;
                color: #92400e;
            }
            .day-staffing-bar {
                display: flex;
                gap: 12px;
                margin-bottom: 16px;
                flex-wrap: wrap;
            }
            .staffing-chip {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 6px 12px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 500;
                border: 1px solid var(--color-border);
                background: var(--color-bg-subtle);
            }
            .staffing-chip.ok { border-color: #86efac; background: #f0fdf4; color: #166534; }
            .staffing-chip.warn { border-color: #fbbf24; background: #fffbeb; color: #92400e; }
            .staffing-chip.danger { border-color: #fca5a5; background: #fef2f2; color: #991b1b; }

            .shift-list-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 10px 14px;
                margin-bottom: 6px;
                background: var(--color-bg-subtle);
                border-radius: 10px;
                border: 1px solid var(--color-border);
                transition: all 0.15s ease;
            }
            .shift-list-item:last-child { margin-bottom: 0; }
            .shift-list-item:hover { border-color: var(--color-border-strong); }
            .shift-list-name {
                font-weight: 500;
                color: var(--color-text-primary);
                font-size: 13px;
                flex: 1;
            }
            .shift-list-type {
                background: var(--color-primary-light);
                color: var(--color-primary);
                padding: 3px 10px;
                border-radius: var(--radius-pill);
                font-size: 11px;
                font-weight: 500;
                white-space: nowrap;
            }
            .shift-list-delete {
                color: var(--color-text-tertiary);
                border: none;
                background: transparent;
                padding: 4px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 16px;
                line-height: 1;
                transition: all 0.15s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 28px; height: 28px;
            }
            .shift-list-delete:hover {
                background: var(--color-error-bg);
                color: var(--color-error);
            }

            .off-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 14px;
                margin-bottom: 4px;
                font-size: 13px;
                color: var(--color-text-secondary);
                background: #fef2f2;
                border-radius: 8px;
            }
            .off-item .off-name { font-weight: 500; color: var(--color-text-primary); flex: 1; }
            .off-item .off-reason {
                font-size: 11px;
                background: rgba(0,0,0,0.06);
                padding: 2px 8px;
                border-radius: 4px;
            }

            /* ── Add-shift / quick-action form ── */
            .day-form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .day-form-grid label {
                display: block;
                font-size: 12px;
                font-weight: 500;
                color: var(--color-text-secondary);
                margin-bottom: 6px;
            }
            .day-form-grid label span { color: var(--color-error); }
            .day-form-grid .ops-input,
            .day-form-grid select {
                width: 100%;
                height: 36px;
                font-size: 13px;
                border-radius: 8px;
                border: 1px solid var(--color-border);
                padding: 0 10px;
                background: var(--color-bg);
            }
            .day-form-actions {
                display: flex;
                gap: 8px;
                justify-content: flex-end;
                margin-top: 12px;
            }
            .day-form-actions .ops-btn {
                height: 34px;
                font-size: 13px;
                border-radius: 8px;
            }
            .day-quick-actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .day-quick-actions .ops-btn {
                height: 30px;
                font-size: 12px;
                border-radius: 6px;
                padding: 0 12px;
            }

            /* Inline expandable forms */
            .inline-form { display: none; margin-top: 12px; }
            .inline-form.open { display: block; }

            /* ── Holiday & Leave Modals ── */
            #holiday-modal .cal-modal-box { width: 620px; }
            #leave-modal .cal-modal-box { width: 680px; }

            .modal-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
                margin-bottom: 16px;
            }
            .modal-table th {
                text-align: left;
                font-weight: 500;
                color: var(--color-text-secondary);
                padding: 8px 10px;
                border-bottom: 1px solid var(--color-border);
                font-size: 12px;
            }
            .modal-table td {
                padding: 8px 10px;
                border-bottom: 1px solid var(--color-border);
                color: var(--color-text-primary);
            }
            .modal-table tr:last-child td { border-bottom: none; }
            .modal-table .type-badge {
                font-size: 11px;
                padding: 2px 8px;
                border-radius: 4px;
                font-weight: 500;
            }
            .modal-table .type-badge.govt { background: #dbeafe; color: #1e40af; }
            .modal-table .type-badge.company { background: #f3e8ff; color: #6b21a8; }
            .modal-table .type-badge.personal { background: #fef3e2; color: #b45309; }
            .modal-table .type-badge.sick { background: #fef2f2; color: #b91c1c; }
            .modal-table .type-badge.compensation { background: #ecfdf5; color: #065f46; }
            .modal-form-row {
                display: flex;
                gap: 12px;
                align-items: flex-end;
                margin-bottom: 16px;
                flex-wrap: wrap;
            }
            .modal-form-row > div { flex: 1; min-width: 120px; }
            .modal-form-row label {
                display: block;
                font-size: 12px;
                font-weight: 500;
                color: var(--color-text-secondary);
                margin-bottom: 6px;
            }
            .modal-form-row input,
            .modal-form-row select {
                width: 100%;
                height: 36px;
                font-size: 13px;
                border-radius: 8px;
                border: 1px solid var(--color-border);
                padding: 0 10px;
                background: var(--color-bg);
            }
            .modal-section-title {
                font-size: 12px;
                font-weight: 600;
                color: var(--color-text-secondary);
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 1px solid var(--color-border);
            }
            .empty-state {
                text-align: center;
                padding: 24px;
                color: var(--color-text-tertiary);
                font-size: 13px;
            }
            .cal-loading {
                text-align: center;
                padding: 40px 20px;
                color: var(--color-text-tertiary);
                font-size: 13px;
            }
        </style>

        <div class="ops-card">
            <!-- ── Header ── -->
            <div class="calendar-header">
                <div class="calendar-nav">
                    <a href="?page=ai-ops&tab=calendar&mo=<?php echo $mo-1; ?>" class="ops-btn secondary">&laquo;</a>
                    <span class="calendar-month"><?php echo $start->format('F Y'); ?></span>
                    <a href="?page=ai-ops&tab=calendar&mo=<?php echo $mo+1; ?>" class="ops-btn secondary">&raquo;</a>
                </div>
                <div class="calendar-actions">
                    <?php if ($is_admin): ?>
                    <button class="ops-btn secondary" onclick="openHolidayModal()">Manage Holidays</button>
                    <?php endif; ?>
                    <button class="ops-btn secondary" onclick="openLeaveModal()">Leave Management</button>
                    <?php if (!$is_read_only): ?>
                    <button class="ops-btn primary bulk-schedule-btn" onclick="jQuery('#bulk-gen').slideToggle()">+ Bulk Schedule</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Bulk Schedule Form ── -->
            <div id="bulk-gen">
                <form method="post">
                    <div class="form-row">
                        <div>
                            <label>Agent</label>
                            <select name="agent_email" class="select2-searchable ops-input" style="width:100%;">
                                <option value="">Select...</option>
                                <?php foreach($agents as $a): ?>
                                    <option value="<?php echo esc_attr($a->email); ?>"><?php echo esc_html($a->first_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Dates</label>
                            <input type="text" name="date_range" class="flatpickr-range ops-input" placeholder="Select date range...">
                        </div>
                        <div>
                            <label>Shift</label>
                            <select name="shift_def_id" class="select2-simple ops-input" style="width:100%;">
                                <option value="">Select...</option>
                                <?php foreach($shift_defs as $sd): ?>
                                    <option value="<?php echo esc_attr($sd->id); ?>"><?php echo esc_html($sd->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button name="generate_shifts" class="ops-btn primary">Apply</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ── Calendar Grid ── -->
            <div class="cal-grid">
                <div class="cal-head">Sun</div>
                <div class="cal-head">Mon</div>
                <div class="cal-head">Tue</div>
                <div class="cal-head">Wed</div>
                <div class="cal-head">Thu</div>
                <div class="cal-head">Fri</div>
                <div class="cal-head">Sat</div>
                <?php
                $dow = $start->format('w');
                for ($i = 0; $i < $dow; $i++) {
                    echo '<div class="cal-cell empty"></div>';
                }

                for ($d = 1; $d <= intval($end->format('t')); $d++) {
                    $shifts   = $cal[$d] ?? [];
                    $holiday  = $holidays[$d] ?? null;
                    $dayLeaves = $leaves[$d] ?? [];
                    $dayCompOffs = $comp_offs[$d] ?? [];
                    $dayExtras = $extras[$d] ?? [];
                    $offCount = count($dayLeaves) + count($dayCompOffs);

                    // Calculate staffing status
                    $dateObj = new \DateTime($start->format('Y-m') . '-' . str_pad($d, 2, '0', STR_PAD_LEFT));
                    $dayOfWeek = intval($dateObj->format('w'));
                    $mins = self::$staffing_mins[$dayOfWeek];
                    $counts = ['morning' => 0, 'afternoon' => 0, 'night' => 0];
                    foreach ($shifts as $s) {
                        $cat = $this->categorize_shift($s->shift_type);
                        $counts[$cat]++;
                    }
                    $understaffed = false;
                    foreach ($mins as $cat => $min) {
                        if ($counts[$cat] < $min) { $understaffed = true; break; }
                    }

                    $dateStr = $dateObj->format('Y-m-d');
                    $classes = 'cal-cell';
                    if ($holiday) $classes .= ' is-holiday';
                    if ($understaffed) $classes .= ' is-understaffed';

                    echo "<div class='{$classes}' onclick='openDay(event, \"{$dateStr}\")'>";
                    echo "<span class='cal-date'>{$d}</span>";

                    // Holiday badge
                    if ($holiday) {
                        echo "<div class='cal-holiday-badge'>" . esc_html($holiday->name) . "</div>";
                    }

                    // Shift pills (max 4)
                    foreach (array_slice($shifts, 0, 4) as $s) {
                        $c = !empty($s->current_shift_color) ? $s->current_shift_color : ($s->shift_color ?: '#eee');
                        $n = $s->first_name ?: 'User';
                        echo "<div class='shift-pill' style='background:{$c}' onclick='event.stopPropagation();'>" . esc_html($n) . "</div>";
                    }
                    if (count($shifts) > 4) {
                        echo "<div style='font-size:11px;color:var(--color-text-tertiary);'>+" . (count($shifts) - 4) . " more</div>";
                    }

                    // Off badge
                    if ($offCount > 0) {
                        echo "<div class='cal-off-badge'>{$offCount} off</div>";
                    }

                    // Understaffed indicator
                    if ($understaffed) {
                        echo "<span class='cal-alert-badge' title='Understaffed'>&#9888;</span>";
                    }

                    echo "</div>";
                }
                ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════ -->
        <!-- Day Detail Modal                                  -->
        <!-- ══════════════════════════════════════════════════ -->
        <div id="day-modal" class="cal-modal-overlay" onclick="if(event.target===this) closeModal('day-modal')">
            <div class="cal-modal-box">
                <div class="cal-modal-header">
                    <h3 id="day-modal-title">Loading...</h3>
                    <button class="cal-modal-close" onclick="closeModal('day-modal')">&times;</button>
                </div>
                <div class="cal-modal-body" id="day-modal-body">
                    <div class="cal-loading">Loading day details...</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════ -->
        <!-- Holiday Management Modal                          -->
        <!-- ══════════════════════════════════════════════════ -->
        <?php if ($is_admin): ?>
        <div id="holiday-modal" class="cal-modal-overlay" onclick="if(event.target===this) closeModal('holiday-modal')">
            <div class="cal-modal-box">
                <div class="cal-modal-header">
                    <h3>Manage Holidays &mdash; <?php echo esc_html($start->format('Y')); ?></h3>
                    <button class="cal-modal-close" onclick="closeModal('holiday-modal')">&times;</button>
                </div>
                <div class="cal-modal-body">
                    <div class="modal-section-title">Add Holiday</div>
                    <div class="modal-form-row">
                        <div style="flex:2"><label>Name</label><input type="text" id="hol-name" placeholder="e.g. Independence Day"></div>
                        <div><label>Start</label><input type="date" id="hol-start"></div>
                        <div><label>End</label><input type="date" id="hol-end"></div>
                        <div style="min-width:100px"><label>Type</label>
                            <select id="hol-type">
                                <option value="government">Government</option>
                                <option value="company">Company</option>
                            </select>
                        </div>
                        <div style="flex:0 0 auto;align-self:flex-end"><button class="ops-btn primary" onclick="saveHoliday()" style="height:36px">Add</button></div>
                    </div>
                    <div class="modal-section-title">Existing Holidays</div>
                    <div id="holiday-list">
                        <?php if (empty($all_holidays)): ?>
                            <div class="empty-state">No holidays added for <?php echo esc_html($start->format('Y')); ?></div>
                        <?php else: ?>
                        <table class="modal-table">
                            <thead><tr><th>Name</th><th>Dates</th><th>Type</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach($all_holidays as $h): ?>
                                <tr id="hol-row-<?php echo $h->id; ?>">
                                    <td><?php echo esc_html($h->name); ?></td>
                                    <td><?php
                                        echo date('M j', strtotime($h->date_start));
                                        if ($h->date_start !== $h->date_end) echo ' - ' . date('M j', strtotime($h->date_end));
                                    ?></td>
                                    <td><span class="type-badge <?php echo $h->type === 'government' ? 'govt' : 'company'; ?>"><?php echo esc_html(ucfirst($h->type)); ?></span></td>
                                    <td><button class="shift-list-delete" onclick="deleteHoliday(<?php echo $h->id; ?>)" title="Delete">&times;</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════ -->
        <!-- Leave Management Modal                            -->
        <!-- ══════════════════════════════════════════════════ -->
        <div id="leave-modal" class="cal-modal-overlay" onclick="if(event.target===this) closeModal('leave-modal')">
            <div class="cal-modal-box">
                <div class="cal-modal-header">
                    <h3>Leave Management</h3>
                    <button class="cal-modal-close" onclick="closeModal('leave-modal')">&times;</button>
                </div>
                <div class="cal-modal-body">
                    <?php if (!$is_read_only): ?>
                    <div class="modal-section-title">Add Leave</div>
                    <div class="modal-form-row">
                        <div style="flex:2"><label>Agent</label>
                            <select id="leave-agent">
                                <option value="">Select agent...</option>
                                <?php foreach($agents as $a): ?>
                                    <option value="<?php echo esc_attr($a->email); ?>"><?php echo esc_html($a->first_name . ' ' . $a->last_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Type</label>
                            <select id="leave-type">
                                <option value="personal">Personal</option>
                                <option value="sick">Sick</option>
                                <option value="compensation">Comp-off</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-form-row">
                        <div><label>From</label><input type="date" id="leave-start"></div>
                        <div><label>To</label><input type="date" id="leave-end"></div>
                        <div style="flex:2"><label>Reason</label><input type="text" id="leave-reason" placeholder="Optional"></div>
                        <div style="flex:0 0 auto;align-self:flex-end"><button class="ops-btn primary" onclick="saveLeaveModal()" style="height:36px">Add</button></div>
                    </div>
                    <?php endif; ?>

                    <div class="modal-section-title" style="display:flex;justify-content:space-between;align-items:center;">
                        Current Leaves
                        <a href="<?php echo admin_url('admin-post.php?action=export_leave_csv&year=' . $start->format('Y')); ?>" class="ops-btn secondary" style="height:28px;font-size:11px;padding:0 10px;">Export CSV</a>
                    </div>
                    <div id="leave-list">
                        <?php if (empty($all_leaves)): ?>
                            <div class="empty-state">No approved leaves this month</div>
                        <?php else: ?>
                        <table class="modal-table">
                            <thead><tr><th>Agent</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach($all_leaves as $l):
                                $name = trim(($l->first_name ?? '') . ' ' . ($l->last_name ?? '')) ?: $l->agent_email;
                                $days = (strtotime($l->date_end) - strtotime($l->date_start)) / 86400 + 1;
                            ?>
                                <tr id="leave-row-<?php echo $l->id; ?>">
                                    <td><?php echo esc_html($name); ?></td>
                                    <td><span class="type-badge <?php echo esc_attr($l->leave_type); ?>"><?php echo esc_html(ucfirst($l->leave_type)); ?></span></td>
                                    <td><?php echo date('M j', strtotime($l->date_start)); ?></td>
                                    <td><?php echo date('M j', strtotime($l->date_end)); ?></td>
                                    <td><?php echo intval($days); ?></td>
                                    <?php if (!$is_read_only): ?>
                                    <td><button class="shift-list-delete" onclick="deleteLeaveModal(<?php echo $l->id; ?>)" title="Delete">&times;</button></td>
                                    <?php else: ?>
                                    <td></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php $this->render_scripts($start, $agents, $shift_defs, $is_read_only, $is_admin); ?>
        <?php
    }

    private function render_scripts($start, $agents, $shift_defs, $is_read_only, $is_admin) {
        $agents_json = json_encode(array_map(function($a) {
            return ['email' => $a->email, 'name' => trim($a->first_name . ' ' . ($a->last_name ?? ''))];
        }, $agents));
        $shift_defs_json = json_encode(array_map(function($sd) {
            return ['id' => $sd->id, 'name' => $sd->name];
        }, $shift_defs));
        ?>
        <script>
        jQuery(document).ready(function($){
            var curY = <?php echo $start->format('Y'); ?>;
            var curM = <?php echo $start->format('m'); ?>;
            var calReadOnly = <?php echo $is_read_only ? 'true' : 'false'; ?>;
            var calIsAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
            var agentsList = <?php echo $agents_json; ?>;
            var shiftDefs = <?php echo $shift_defs_json; ?>;

            var staffingMins = {
                0: {morning:3, afternoon:2, night:1},
                1: {morning:4, afternoon:3, night:1},
                2: {morning:5, afternoon:3, night:1},
                3: {morning:5, afternoon:3, night:1},
                4: {morning:5, afternoon:3, night:1},
                5: {morning:4, afternoon:2, night:1},
                6: {morning:3, afternoon:2, night:1}
            };

            function catShift(type) {
                var t = (type||'').toLowerCase();
                if (t.indexOf('morning') >= 0 || t.indexOf('day') >= 0) return 'morning';
                if (t.indexOf('afternoon') >= 0 || t.indexOf('evening') >= 0) return 'afternoon';
                if (t.indexOf('night') >= 0) return 'night';
                return 'morning';
            }
            function shiftIcon(cat) {
                return {morning:'&#9728;&#65039;', afternoon:'&#127750;', night:'&#127769;'}[cat] || '&#9728;&#65039;';
            }

            // ── Initialize UI ──
            $('.select2-searchable').select2({ width:'100%', placeholder:'Select an agent...' });
            $('.select2-simple').select2({ width:'100%', minimumResultsForSearch:Infinity, placeholder:'Select a shift...' });
            $('.flatpickr-range').flatpickr({ mode:"range", placeholder:"Select date range..." });

            // ── Modal helpers ──
            window.closeModal = function(id) {
                $('#' + id).fadeOut();
                document.body.classList.remove('modal-open');
            };

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('.cal-modal-overlay:visible').fadeOut();
                    document.body.classList.remove('modal-open');
                }
            });

            // ══════════════════════════════════════════════════
            //  DAY MODAL
            // ══════════════════════════════════════════════════
            var activeDate = '';

            window.openDay = function(e, dateStr) {
                if (e) { e.stopPropagation(); e.preventDefault(); }
                if ($('#day-modal').is(':visible')) return;

                activeDate = dateStr;

                // Format title
                var parts = dateStr.split('-');
                var dateObj = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
                var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                $('#day-modal-title').text(days[dateObj.getDay()] + ', ' + months[dateObj.getMonth()] + ' ' + dateObj.getDate() + ', ' + parts[0]);
                $('#day-modal-body').html('<div class="cal-loading">Loading day details...</div>');

                document.body.classList.add('modal-open');
                $('#day-modal').fadeIn(250);

                // Fetch day details via AJAX
                $.post(ajaxurl, {
                    action: 'ai_ops_get_day_details',
                    date: dateStr
                }, function(resp) {
                    if (resp.success) {
                        renderDayModal(resp.data, dateObj);
                    } else {
                        $('#day-modal-body').html('<div class="empty-state">Error loading details</div>');
                    }
                }).fail(function() {
                    $('#day-modal-body').html('<div class="empty-state">Failed to load</div>');
                });
            };

            function renderDayModal(data, dateObj) {
                var h = '';

                // Holiday banner
                if (data.holiday) {
                    h += '<div class="day-holiday-banner">' + escHtml(data.holiday.name) + ' (' + escHtml(data.holiday.type) + ')</div>';
                }

                // Staffing summary
                var dow = dateObj.getDay();
                var mins = staffingMins[dow] || {morning:3, afternoon:2, night:1};
                var counts = {morning:0, afternoon:0, night:0};
                (data.shifts || []).forEach(function(s){ counts[catShift(s.shift_type)]++; });
                // Add extras
                (data.extras || []).forEach(function(ex){
                    if (ex.action_type === 'add') counts[catShift(ex.shift_type)]++;
                    if (ex.action_type === 'remove') counts[catShift(ex.shift_type)]--;
                });

                h += '<div class="day-staffing-bar">';
                ['morning','afternoon','night'].forEach(function(cat){
                    var c = counts[cat] || 0;
                    var m = mins[cat] || 0;
                    var cls = c >= m ? 'ok' : (c >= m-1 ? 'warn' : 'danger');
                    h += '<div class="staffing-chip ' + cls + '">' + shiftIcon(cat) + ' ' + c + '/' + m + '</div>';
                });
                h += '</div>';

                // Shifts section
                h += '<div class="day-section">';
                h += '<div class="day-section-title">Shifts</div>';
                if (data.shifts && data.shifts.length) {
                    data.shifts.forEach(function(s){
                        h += '<div class="shift-list-item">';
                        h += '<span class="shift-list-name">' + escHtml(s.first_name || s.agent_email || 'User') + '</span>';
                        h += '<span class="shift-list-type">' + escHtml(s.shift_type) + '</span>';
                        if (!calReadOnly) {
                            h += '<button class="shift-list-delete" onclick="delS(' + s.id + ')" title="Remove">&times;</button>';
                        }
                        h += '</div>';
                    });
                } else {
                    h += '<div style="padding:8px 0;font-size:13px;color:var(--color-text-tertiary);">No shifts on this day</div>';
                }
                h += '</div>';

                // Off Today section (leaves + comp-offs)
                var offItems = [];
                (data.leaves || []).forEach(function(l){
                    offItems.push({name: (l.first_name || '') + ' ' + (l.last_name || ''), reason: l.leave_type, id: l.id, type:'leave'});
                });
                (data.comp_offs || []).forEach(function(co){
                    offItems.push({name: co.first_name || co.agent_email, reason: 'Comp-off', id: co.id, type:'comp_off'});
                });

                if (offItems.length > 0) {
                    h += '<div class="day-section">';
                    h += '<div class="day-section-title">Off Today (' + offItems.length + ')</div>';
                    offItems.forEach(function(item){
                        h += '<div class="off-item">';
                        h += '<span class="off-name">' + escHtml(item.name.trim()) + '</span>';
                        h += '<span class="off-reason">' + escHtml(item.reason) + '</span>';
                        if (!calReadOnly && item.type === 'leave') {
                            h += '<button class="shift-list-delete" onclick="deleteLeaveDay(' + item.id + ')" title="Remove">&times;</button>';
                        }
                        h += '</div>';
                    });
                    h += '</div>';
                }

                // Extras section
                if (data.extras && data.extras.length) {
                    h += '<div class="day-section">';
                    h += '<div class="day-section-title">Extra Assignments</div>';
                    data.extras.forEach(function(ex){
                        h += '<div class="shift-list-item">';
                        h += '<span class="shift-list-name">' + escHtml(ex.first_name || ex.agent_email) + '</span>';
                        h += '<span class="shift-list-type">' + escHtml(ex.shift_type) + ' (' + escHtml(ex.action_type) + ')</span>';
                        if (calIsAdmin) {
                            h += '<button class="shift-list-delete" onclick="deleteExtra(' + ex.id + ')" title="Remove">&times;</button>';
                        }
                        h += '</div>';
                    });
                    h += '</div>';
                }

                // Holiday Duty section
                if (data.holiday && calIsAdmin) {
                    h += '<div class="day-section">';
                    h += '<div class="day-section-title">Holiday Duty Assignments</div>';
                    if (data.holiday_duty && data.holiday_duty.length) {
                        data.holiday_duty.forEach(function(hd){
                            h += '<div class="shift-list-item">';
                            h += '<span class="shift-list-name">' + escHtml(hd.first_name || hd.agent_email) + '</span>';
                            h += '<span class="shift-list-type">' + escHtml(hd.shift_type) + '</span>';
                            if (hd.comp_off_date) {
                                h += '<span style="font-size:11px;color:var(--color-text-secondary);">CO: ' + hd.comp_off_date + '</span>';
                            }
                            h += '</div>';
                        });
                    } else {
                        h += '<div style="font-size:13px;color:var(--color-text-tertiary);padding:8px 0;">No duty assigned yet</div>';
                    }
                    h += '<div style="margin-top:10px"><button class="ops-btn secondary" onclick="toggleDutyForm()" style="height:30px;font-size:12px">Assign Duty</button></div>';
                    h += '<div id="duty-form" class="inline-form">';
                    h += buildDutyForm(data.holiday);
                    h += '</div>';
                    h += '</div>';
                }

                // Quick actions
                if (!calReadOnly) {
                    h += '<div class="day-section">';
                    h += '<div class="day-section-title">Quick Actions</div>';
                    h += '<div class="day-quick-actions">';
                    h += '<button class="ops-btn secondary" onclick="toggleInlineForm(\'add-shift-form\')">Add Shift</button>';
                    h += '<button class="ops-btn secondary" onclick="toggleInlineForm(\'add-leave-form\')">Add Leave</button>';
                    if (calIsAdmin) {
                        h += '<button class="ops-btn secondary" onclick="toggleInlineForm(\'add-extra-form\')">Add Extra</button>';
                    }
                    h += '</div>';

                    // Inline Add Shift form
                    h += '<div id="add-shift-form" class="inline-form">';
                    h += '<div class="day-form-grid">';
                    h += '<div><label>Agent <span>*</span></label><select id="modal-agent" class="ops-input"><option value="">Select...</option>';
                    agentsList.forEach(function(a){ h += '<option value="' + escAttr(a.email) + '">' + escHtml(a.name) + '</option>'; });
                    h += '</select></div>';
                    h += '<div><label>Shift <span>*</span></label><select id="modal-def" class="ops-input"><option value="">Select...</option>';
                    shiftDefs.forEach(function(sd){ h += '<option value="' + sd.id + '">' + escHtml(sd.name) + '</option>'; });
                    h += '</select></div>';
                    h += '</div>';
                    h += '<div class="day-form-actions"><button class="ops-btn primary" onclick="saveShift()">Add Shift</button></div>';
                    h += '</div>';

                    // Inline Add Leave form
                    h += '<div id="add-leave-form" class="inline-form">';
                    h += '<div class="day-form-grid">';
                    h += '<div><label>Agent <span>*</span></label><select id="day-leave-agent" class="ops-input"><option value="">Select...</option>';
                    agentsList.forEach(function(a){ h += '<option value="' + escAttr(a.email) + '">' + escHtml(a.name) + '</option>'; });
                    h += '</select></div>';
                    h += '<div><label>Type</label><select id="day-leave-type" class="ops-input"><option value="personal">Personal</option><option value="sick">Sick</option><option value="compensation">Comp-off</option></select></div>';
                    h += '</div>';
                    h += '<div style="margin-top:8px"><label style="display:block;font-size:12px;font-weight:500;color:var(--color-text-secondary);margin-bottom:6px;">Reason</label><input type="text" id="day-leave-reason" class="ops-input" placeholder="Optional" style="width:100%;height:36px;border-radius:8px;"></div>';
                    h += '<div class="day-form-actions"><button class="ops-btn primary" onclick="saveDayLeave()">Add Leave</button></div>';
                    h += '</div>';

                    // Inline Add Extra form (admin)
                    if (calIsAdmin) {
                        h += '<div id="add-extra-form" class="inline-form">';
                        h += '<div class="day-form-grid">';
                        h += '<div><label>Agent <span>*</span></label><select id="day-extra-agent" class="ops-input"><option value="">Select...</option>';
                        agentsList.forEach(function(a){ h += '<option value="' + escAttr(a.email) + '">' + escHtml(a.name) + '</option>'; });
                        h += '</select></div>';
                        h += '<div><label>Shift <span>*</span></label><select id="day-extra-shift" class="ops-input"><option value="">Select...</option>';
                        shiftDefs.forEach(function(sd){ h += '<option value="' + escHtml(sd.name) + '">' + escHtml(sd.name) + '</option>'; });
                        h += '</select></div>';
                        h += '</div>';
                        h += '<div style="margin-top:8px"><div class="day-form-grid"><div><label>Action</label><select id="day-extra-action" class="ops-input"><option value="add">Add (extra duty)</option><option value="remove">Remove (day off)</option></select></div><div><label>Note</label><input type="text" id="day-extra-note" class="ops-input" placeholder="Optional" style="height:36px;border-radius:8px;"></div></div></div>';
                        h += '<div class="day-form-actions"><button class="ops-btn primary" onclick="saveExtra()">Save Extra</button></div>';
                        h += '</div>';
                    }
                    h += '</div>';
                }

                $('#day-modal-body').html(h);
            }

            function buildDutyForm(holiday) {
                var h = '<div style="margin-top:8px;">';
                ['morning','afternoon','night'].forEach(function(cat){
                    h += '<div style="margin-bottom:10px;">';
                    h += '<div style="font-size:12px;font-weight:500;margin-bottom:6px;">' + shiftIcon(cat) + ' ' + cat.charAt(0).toUpperCase() + cat.slice(1) + '</div>';
                    h += '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
                    agentsList.forEach(function(a){
                        h += '<label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">';
                        h += '<input type="checkbox" class="duty-cb" data-email="' + escAttr(a.email) + '" data-shift="' + cat + '"> ' + escHtml(a.name);
                        h += '</label>';
                    });
                    h += '</div></div>';
                });
                h += '<div style="margin-top:8px;"><label style="font-size:12px;font-weight:500;">Comp-off dates (per agent):</label>';
                h += '<div id="duty-comp-offs" style="margin-top:6px;font-size:12px;color:var(--color-text-secondary);">Select agents above first</div>';
                h += '</div>';
                h += '<div class="day-form-actions"><button class="ops-btn primary" onclick="saveDuty(' + holiday.id + ')">Save Duty</button></div>';
                h += '</div>';
                return h;
            }

            // Update comp-off inputs when duty checkboxes change
            $(document).on('change', '.duty-cb', function(){
                var checked = [];
                $('.duty-cb:checked').each(function(){
                    var email = $(this).data('email');
                    if (checked.indexOf(email) < 0) checked.push(email);
                });
                if (checked.length === 0) {
                    $('#duty-comp-offs').html('Select agents above first');
                    return;
                }
                var h = '';
                checked.forEach(function(email){
                    var name = '';
                    agentsList.forEach(function(a){ if(a.email===email) name = a.name; });
                    h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">';
                    h += '<span style="min-width:100px;">' + escHtml(name) + ':</span>';
                    h += '<input type="date" class="comp-off-date" data-email="' + escAttr(email) + '" style="height:28px;font-size:12px;border:1px solid var(--color-border);border-radius:6px;padding:0 8px;">';
                    h += '</div>';
                });
                $('#duty-comp-offs').html(h);
            });

            window.toggleInlineForm = function(id) {
                var $f = $('#' + id);
                if ($f.hasClass('open')) {
                    $f.removeClass('open');
                } else {
                    // Close others
                    $('.inline-form').removeClass('open');
                    $f.addClass('open');
                }
            };
            window.toggleDutyForm = function() { toggleInlineForm('duty-form'); };

            // ── Save shift ──
            window.saveShift = function() {
                var agent = $('#modal-agent').val();
                var shift = $('#modal-def').val();
                if (!agent) { alert('Please select an agent'); return; }
                if (!shift) { alert('Please select a shift'); return; }

                $.post(ajaxurl, {
                    action:'ai_ops_save_single',
                    date: activeDate,
                    agent_email: agent,
                    shift_def_id: shift
                }, function(){ location.reload(); }).fail(function(){ alert('Error saving shift'); });
            };

            // ── Delete shift ──
            window.delS = function(id) {
                if (!confirm('Delete this shift?')) return;
                $.post(ajaxurl, {action:'ai_ops_delete', id:id}, function(){ location.reload(); }).fail(function(){ alert('Error deleting shift'); });
            };

            // ── Save day-level leave ──
            window.saveDayLeave = function() {
                var agent = $('#day-leave-agent').val();
                if (!agent) { alert('Please select an agent'); return; }
                $.post(ajaxurl, {
                    action: 'ai_ops_save_leave',
                    agent_email: agent,
                    date_start: activeDate,
                    date_end: activeDate,
                    leave_type: $('#day-leave-type').val(),
                    reason: $('#day-leave-reason').val()
                }, function(resp){
                    if (resp.success) location.reload();
                    else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error saving leave'); });
            };

            // ── Delete leave from day modal ──
            window.deleteLeaveDay = function(id) {
                if (!confirm('Delete this leave?')) return;
                $.post(ajaxurl, {action:'ai_ops_delete_leave', id:id}, function(resp){
                    if (resp.success) location.reload();
                    else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error deleting leave'); });
            };

            // ── Save extra ──
            window.saveExtra = function() {
                var agent = $('#day-extra-agent').val();
                var shift = $('#day-extra-shift').val();
                if (!agent || !shift) { alert('Agent and shift are required'); return; }
                $.post(ajaxurl, {
                    action: 'ai_ops_save_extra',
                    date: activeDate,
                    agent_email: agent,
                    shift_type: shift,
                    action_type: $('#day-extra-action').val(),
                    note: $('#day-extra-note').val()
                }, function(resp){
                    if (resp.success) location.reload();
                    else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error saving extra'); });
            };

            // ── Delete extra ──
            window.deleteExtra = function(id) {
                if (!confirm('Delete this extra assignment?')) return;
                $.post(ajaxurl, {action:'ai_ops_delete_extra', id:id}, function(resp){
                    if (resp.success) location.reload();
                    else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error deleting extra'); });
            };

            // ── Save holiday duty ──
            window.saveDuty = function(holidayId) {
                var duties = [];
                var compOffs = {};
                $('.duty-cb:checked').each(function(){
                    duties.push({agent_email: $(this).data('email'), shift_type: $(this).data('shift')});
                });
                $('.comp-off-date').each(function(){
                    if ($(this).val()) compOffs[$(this).data('email')] = $(this).val();
                });
                if (duties.length === 0) { alert('Select at least one agent'); return; }
                $.post(ajaxurl, {
                    action: 'ai_ops_save_holiday_duty',
                    holiday_id: holidayId,
                    date: activeDate,
                    duties: duties,
                    comp_offs: compOffs
                }, function(resp){
                    if (resp.success) location.reload();
                    else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error saving duty'); });
            };

            // ══════════════════════════════════════════════════
            //  HOLIDAY MODAL
            // ══════════════════════════════════════════════════
            window.openHolidayModal = function() {
                document.body.classList.add('modal-open');
                $('#holiday-modal').fadeIn(250);
            };

            window.saveHoliday = function() {
                var name = $('#hol-name').val().trim();
                var ds = $('#hol-start').val();
                var de = $('#hol-end').val();
                if (!name || !ds || !de) { alert('Name, start, and end date are required'); return; }
                $.post(ajaxurl, {
                    action: 'ai_ops_save_holiday',
                    name: name,
                    date_start: ds,
                    date_end: de,
                    type: $('#hol-type').val()
                }, function(resp){
                    if (resp.success) location.reload();
                    else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error saving holiday'); });
            };

            window.deleteHoliday = function(id) {
                if (!confirm('Delete this holiday and all related duty assignments?')) return;
                $.post(ajaxurl, {action:'ai_ops_delete_holiday', id:id}, function(resp){
                    if (resp.success) {
                        $('#hol-row-' + id).fadeOut(function(){ $(this).remove(); });
                    } else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error deleting holiday'); });
            };

            // ══════════════════════════════════════════════════
            //  LEAVE MODAL
            // ══════════════════════════════════════════════════
            window.openLeaveModal = function() {
                document.body.classList.add('modal-open');
                $('#leave-modal').fadeIn(250);
            };

            window.saveLeaveModal = function() {
                var agent = $('#leave-agent').val();
                var ds = $('#leave-start').val();
                var de = $('#leave-end').val();
                if (!agent || !ds || !de) { alert('Agent, start, and end date are required'); return; }
                $.post(ajaxurl, {
                    action: 'ai_ops_save_leave',
                    agent_email: agent,
                    date_start: ds,
                    date_end: de,
                    leave_type: $('#leave-type').val(),
                    reason: $('#leave-reason').val()
                }, function(resp){
                    if (resp.success) location.reload();
                    else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error saving leave'); });
            };

            window.deleteLeaveModal = function(id) {
                if (!confirm('Delete this leave?')) return;
                $.post(ajaxurl, {action:'ai_ops_delete_leave', id:id}, function(resp){
                    if (resp.success) {
                        $('#leave-row-' + id).fadeOut(function(){ $(this).remove(); });
                    } else alert(resp.data || 'Error');
                }).fail(function(){ alert('Error deleting leave'); });
            };

            // ── Helpers ──
            function escHtml(s) { return $('<span>').text(s||'').html(); }
            function escAttr(s) { return (s||'').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
        });
        </script>
        <?php
    }
}
