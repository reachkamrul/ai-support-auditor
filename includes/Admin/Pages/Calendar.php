<?php
/**
 * Calendar Page
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
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->shift_processor = new ShiftProcessor($database);
    }
    
    public function render() {
        global $wpdb;
        
        $mo = isset($_GET['mo']) ? intval($_GET['mo']) : 0;
        $now = new \DateTime();
        if ($mo != 0) {
            $now->modify("$mo month");
        }
        $start = new \DateTime($now->format('Y-m-01'));
        $end = new \DateTime($now->format('Y-m-t'));
        
        $is_read_only = AccessControl::is_read_only('calendar');

        // Handle bulk shift generation (admin only)
        if (!$is_read_only && isset($_POST['generate_shifts'])) {
            $this->shift_processor->process($_POST);
            echo '<div class="notice notice-success is-dismissible"><p>Schedule Updated.</p></div>';
        }

        // Get shifts for the month
        $shifts_table = $this->database->get_table('agent_shifts');
        $agents_table = $this->database->get_table('agents');
        $shift_defs_table = $this->database->get_table('shift_definitions');

        // Team filtering for leads
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
        
        // Organize shifts by day
        $cal = [];
        foreach ($raw as $s) {
            $day = intval(substr($s->shift_start, 8, 2));
            $cal[$day][] = $s;
        }
        
        // Get agents (team-filtered for leads)
        $agent_team_filter = AccessControl::sql_agent_filter('email');
        $agents = $wpdb->get_results(
            "SELECT * FROM {$agents_table} WHERE 1=1{$agent_team_filter} ORDER BY first_name ASC"
        );
        
        $shift_defs_table = $this->database->get_table('shift_definitions');
        $shift_defs = $wpdb->get_results(
            "SELECT * FROM {$shift_defs_table} ORDER BY name ASC"
        );
        
        $this->render_calendar($start, $end, $cal, $mo, $agents, $shift_defs);
    }
    
    private function render_calendar($start, $end, $cal, $mo, $agents, $shift_defs) {
        ?>
        <style>
            /* Calendar Page Specific Styles */
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
            
            .cal-date {
                font-weight: 600;
                font-size: 14px;
                color: var(--color-text-primary);
                margin-bottom: 8px;
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
            
            /* ── Day Modal ── */
            #day-modal {
                display: none;
                position: fixed;
                top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 9999;
                backdrop-filter: blur(4px);
            }

            .day-modal-content {
                background: var(--color-bg);
                width: 480px;
                max-width: 92%;
                max-height: 85vh;
                margin: 8vh auto;
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

            /* ── Header ── */
            .day-modal-header {
                padding: 20px 24px;
                border-bottom: 1px solid var(--color-border);
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: var(--color-bg);
                position: sticky;
                top: 0; z-index: 1;
            }

            .day-modal-header h3 {
                margin: 0;
                font-size: 15px;
                font-weight: 600;
                color: var(--color-text-primary);
                letter-spacing: -0.01em;
            }

            .day-modal-close {
                background: none; border: none;
                font-size: 20px;
                color: var(--color-text-tertiary);
                cursor: pointer; line-height: 1;
                width: 32px; height: 32px;
                display: flex; align-items: center; justify-content: center;
                border-radius: 8px;
                transition: all 0.15s ease;
            }
            .day-modal-close:hover {
                color: var(--color-text-primary);
                background: var(--color-bg-subtle);
            }

            /* ── Shift list ── */
            #modal-list {
                max-height: 260px;
                overflow-y: auto;
                padding: 16px 24px 0;
            }
            #modal-list::-webkit-scrollbar { width: 6px; }
            #modal-list::-webkit-scrollbar-track { background: transparent; }
            #modal-list::-webkit-scrollbar-thumb { background: var(--color-border); border-radius: 3px; }

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
            .shift-list-item:hover {
                border-color: var(--color-border-strong);
            }

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

            /* ── Divider between list & form ── */
            .day-modal-divider {
                height: 1px;
                background: var(--color-border);
                margin: 0 24px;
            }

            /* ── Add shift form ── */
            .day-modal-form {
                padding: 20px 24px 24px;
                background: var(--color-bg);
            }

            .day-modal-form-title {
                font-size: 11px;
                font-weight: 500;
                color: var(--color-text-tertiary);
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin-bottom: 14px;
            }

            .day-modal-form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .day-modal-form .form-group {
                margin-bottom: 0;
            }

            .day-modal-form .form-group label {
                display: block;
                font-size: 12px;
                font-weight: 500;
                color: var(--color-text-secondary);
                margin-bottom: 6px;
            }

            .day-modal-form .form-group label span {
                color: var(--color-error);
            }

            .day-modal-form .ops-input,
            .day-modal-form .select2-container .select2-selection--single {
                height: 36px !important;
                font-size: 13px !important;
                border-radius: 8px !important;
            }

            .day-modal-actions {
                display: flex;
                gap: 8px;
                justify-content: flex-end;
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px solid var(--color-border);
            }

            .day-modal-actions .ops-btn {
                height: 34px;
                font-size: 13px;
                border-radius: 8px;
            }
        </style>
        
        <div class="ops-card">
            <div class="calendar-header">
                <div class="calendar-nav">
                    <a href="?page=ai-ops&tab=calendar&mo=<?php echo $mo-1; ?>" class="ops-btn secondary">«</a>
                    <span class="calendar-month"><?php echo $start->format('F Y'); ?></span>
                    <a href="?page=ai-ops&tab=calendar&mo=<?php echo $mo+1; ?>" class="ops-btn secondary">»</a>
                </div>
                <?php if (!$is_read_only): ?>
                <button class="ops-btn primary bulk-schedule-btn" onclick="jQuery('#bulk-gen').slideToggle()">+ Bulk Schedule</button>
                <?php endif; ?>
            </div>

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
                    $shifts = $cal[$d] ?? [];
                    $json = htmlspecialchars(json_encode($shifts), ENT_QUOTES, 'UTF-8');
                    echo "<div class='cal-cell' onclick='openDay(event, $d, $json)'><span class='cal-date'>$d</span>";
                    foreach (array_slice($shifts, 0, 4) as $s) {
                        // Use current shift definition color if available, otherwise fall back to stored color
                        $c = !empty($s->current_shift_color) ? $s->current_shift_color : ($s->shift_color ?: '#eee');
                        $n = $s->first_name ?: 'User';
                        echo "<div class='shift-pill' style='background:$c' onclick='event.stopPropagation(); return false;'>" . esc_html($n) . "</div>";
                    }
                    echo "</div>";
                }
                ?>
            </div>
        </div>
        
        <div id="day-modal" onclick="if(event.target.id==='day-modal'){jQuery('#day-modal').fadeOut(); document.body.classList.remove('modal-open'); isOpeningModal = false;}">
            <div class="day-modal-content">
                <div class="day-modal-header">
                    <h3 id="modal-title">Manage Shift</h3>
                    <button class="day-modal-close" onclick="jQuery('#day-modal').fadeOut(); document.body.classList.remove('modal-open'); if(typeof isOpeningModal !== 'undefined') isOpeningModal = false;">&times;</button>
                </div>
                
                <div id="modal-list"></div>

                <?php if (!$is_read_only): ?>
                <div class="day-modal-form">
                    <input type="hidden" id="edit-id">
                    <input type="hidden" id="active-date">

                    <div class="day-modal-form-title">Add shift</div>
                    <div class="day-modal-form-grid">
                        <div class="form-group">
                            <label>Agent <span>*</span></label>
                            <select id="modal-agent" required class="ops-input">
                                <option value="">Select agent...</option>
                                <?php foreach($agents as $a): ?>
                                    <option value="<?php echo esc_attr($a->email); ?>"><?php echo esc_html($a->first_name . ' ' . $a->last_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Shift type <span>*</span></label>
                            <select id="modal-def" required class="ops-input">
                                <option value="">Select shift...</option>
                                <?php foreach($shift_defs as $sd): ?>
                                    <option value="<?php echo esc_attr($sd->id); ?>"><?php echo esc_html($sd->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="day-modal-actions">
                        <button class="ops-btn secondary" onclick="jQuery('#day-modal').fadeOut(); document.body.classList.remove('modal-open');">Cancel</button>
                        <button class="ops-btn primary" onclick="saveShift()">Add Shift</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php $this->render_scripts($start); ?>
        <?php
    }
    
    private function render_scripts($start) {
        ?>
        <script>
        jQuery(document).ready(function($){
            var curY = <?php echo $start->format('Y'); ?>;
            var curM = <?php echo $start->format('m'); ?>;
            
            // Initialize Select2 and Flatpickr
            $('.select2-searchable').select2({ 
                width:'100%',
                placeholder: 'Select an agent...'
            });
            $('.select2-simple').select2({ 
                width:'100%', 
                minimumResultsForSearch: Infinity,
                placeholder: 'Select a shift...'
            });
            $('.flatpickr-range').flatpickr({ 
                mode:"range",
                placeholder: "Select date range..."
            });
            
            // Prevent double-opening with a flag
            var isOpeningModal = false;
            
            window.openDay = function(e, d, shifts) {
                // Prevent double-opening
                if (isOpeningModal || $('#day-modal').is(':visible')) {
                    return;
                }
                
                // Set flag to prevent duplicate calls
                isOpeningModal = true;
                
                // Stop event propagation to prevent bubbling
                if (e && e.stopPropagation) {
                    e.stopPropagation();
                    e.preventDefault();
                }
                
                var dateStr = curY + '-' + String(curM).padStart(2,'0') + '-' + String(d).padStart(2,'0');
                $('#active-date').val(dateStr);
                $('#modal-title').text(dateStr);
                
                // Reset form
                $('#modal-agent').val('').trigger('change');
                $('#modal-def').val('').trigger('change');
                $('#edit-id').val('');
                
                renderList(shifts);
                document.body.classList.add('modal-open');
                $('#day-modal').fadeIn(300, function() {
                    // Reset flag after animation completes
                    setTimeout(function() {
                        isOpeningModal = false;
                    }, 350);
                });
                
                // Initialize select2 if not already initialized
                if (!$('#modal-agent').hasClass('select2-hidden-accessible')) {
                    $('#modal-agent').select2({
                        dropdownParent:$('#day-modal'),
                        placeholder: 'Choose an agent...',
                        allowClear: false
                    });
                }
                if (!$('#modal-def').hasClass('select2-hidden-accessible')) {
                    $('#modal-def').select2({
                        dropdownParent:$('#day-modal'),
                        placeholder: 'Choose a shift...',
                        allowClear: false
                    });
                }
            };
            
            var calReadOnly = <?php echo $is_read_only ? 'true' : 'false'; ?>;
            window.renderList = function(shifts) {
                var h = '';
                if (shifts.length > 0) {
                    shifts.forEach(function(s){
                        h += '<div class="shift-list-item">';
                        h += '<span class="shift-list-name">' + (s.first_name || 'User') + '</span>';
                        h += '<span class="shift-list-type">' + s.shift_type + '</span>';
                        if (!calReadOnly) {
                            h += '<button class="shift-list-delete" onclick="delS(' + s.id + ')" title="Remove">&times;</button>';
                        }
                        h += '</div>';
                    });
                } else {
                    h = '<div style="padding:20px 0 8px;text-align:center;"><div style="font-size:13px;color:var(--color-text-tertiary);">No shifts on this day</div></div>';
                }
                $('#modal-list').html(h);
            };
            
            window.saveShift = function() {
                var agent = $('#modal-agent').val();
                var shift = $('#modal-def').val();
                
                // Validation
                if (!agent || agent === '') {
                    alert('⚠️ Please select an agent');
                    $('#modal-agent').focus();
                    return false;
                }
                if (!shift || shift === '') {
                    alert('⚠️ Please select a shift');
                    $('#modal-def').focus();
                    return false;
                }
                
                // Show loading state
                var btn = event.target;
                var originalText = btn.textContent;
                btn.textContent = 'Saving...';
                btn.disabled = true;
                
                $.post(ajaxurl, {
                    action:'ai_ops_save_single',
                    id:$('#edit-id').val(),
                    date:$('#active-date').val(),
                    agent_email:agent,
                    shift_def_id:shift
                }, function(){
                    location.reload();
                }).fail(function() {
                    btn.textContent = originalText;
                    btn.disabled = false;
                    alert('❌ Error saving shift. Please try again.');
                });
            };
            
            // ESC key to close modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#day-modal').is(':visible')) {
                    $('#day-modal').fadeOut();
                    document.body.classList.remove('modal-open');
                    isOpeningModal = false;
                }
            });

            window.delS = function(id) {
                if(confirm('🗑️ Are you sure you want to delete this shift?\n\nThis action cannot be undone.')) {
                    $.post(ajaxurl, {action:'ai_ops_delete', id:id}, function(){
                        location.reload();
                    }).fail(function() {
                        alert('❌ Error deleting shift. Please try again.');
                    });
                }
            };
        });
        </script>
        <?php
    }
}

