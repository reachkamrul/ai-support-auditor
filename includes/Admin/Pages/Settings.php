<?php
/**
 * Settings Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class Settings {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function render() {
        global $wpdb;
        
        if(isset($_POST['save_shift_def'])) {
            $this->save_shift_definition($_POST);
        }
        
        if(isset($_GET['del_def'])) {
            $shift_defs_table = $this->database->get_table('shift_definitions');
            $wpdb->delete($shift_defs_table, ['id'=>$_GET['del_def']]);
        }
        
        $shift_defs_table = $this->database->get_table('shift_definitions');
        $defs = $wpdb->get_results("SELECT * FROM {$shift_defs_table}");
        ?>
        
        <style>
            /* Settings Page Specific Styles */
            .settings-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 20px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--color-border);
            }
            
            .settings-header h3 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--color-text-primary);
            }
            
            .shift-type-row {
                transition: all 0.2s ease;
            }
            
            .shift-type-row:hover {
                background: var(--color-bg-subtle);
            }
            
            .shift-time-cell {
                font-family: var(--font-mono);
                font-size: 13px;
                color: var(--color-text-secondary);
                font-weight: 500;
            }
            
            .shift-color-preview {
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            
            .shift-color-box {
                width: 40px;
                height: 24px;
                border-radius: var(--radius-sm);
                border: 2px solid var(--color-border);
                box-shadow: var(--shadow-xs);
            }
            
            .shift-color-code {
                font-family: var(--font-mono);
                font-size: 11px;
                color: var(--color-text-secondary);
                font-weight: 600;
            }
            
            .shift-actions {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            
            .shift-actions .ops-btn {
                font-size: 12px;
                padding: 0 14px;
                height: 32px;
                min-width: 70px;
            }
            
            .shift-form-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 20px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--color-border);
            }
            
            .shift-form-header h3 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--color-text-primary);
            }
            
            .shift-form-mode {
                font-size: 11px;
                font-weight: 500;
                color: var(--color-text-secondary);
                padding: 5px 12px;
                background: var(--color-bg-subtle);
                border-radius: var(--radius-pill);
            }
            
            .shift-form-row {
                display: flex;
                gap: 16px;
                align-items: flex-end;
                flex-wrap: wrap;
            }
            
            .shift-form-group {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            
            .shift-form-group label {
                font-size: 13px;
                font-weight: 500;
                color: var(--color-text-secondary);
            }
            
            .shift-form-group.name {
                flex: 2;
                min-width: 200px;
            }
            
            .shift-form-group.time {
                flex: 1;
                min-width: 120px;
            }
            
            .shift-form-group.color {
                flex: 1;
                min-width: 140px;
            }
            
            .shift-form-group.actions {
                flex: 0 0 auto;
            }
            
            .shift-color-input-wrapper {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .shift-color-picker {
                width: 50px;
                height: 38px;
                border: 1px solid var(--color-border);
                border-radius: var(--radius-sm);
                cursor: pointer;
                padding: 2px;
                background: var(--color-bg);
            }
            
            .shift-color-picker::-webkit-color-swatch-wrapper {
                padding: 0;
            }
            
            .shift-color-picker::-webkit-color-swatch {
                border: none;
                border-radius: calc(var(--radius-sm) - 2px);
            }
            
            .shift-color-display {
                font-family: var(--font-mono);
                font-size: 12px;
                color: var(--color-text-secondary);
                font-weight: 600;
                padding: 0 8px;
            }
            
            .empty-shifts {
                text-align: center;
                padding: 60px 20px;
                color: var(--color-text-secondary);
            }
            
            .empty-shifts-text {
                font-size: 14px;
                margin: 0;
            }
        </style>
        
        <div class="ops-card">
            <div class="settings-header">
            <h3>Shift Types</h3>
            </div>
            <?php if (empty($defs)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No shift types defined</div>
                    <div class="ops-empty-state-description">Add your first shift type below.</div>
                </div>
            <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Time</th>
                        <th>Color</th>
                            <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($defs as $d): ?>
                        <tr class="shift-type-row">
                            <td style="font-weight:600;color:var(--color-text-primary);"><?php echo esc_html($d->name); ?></td>
                            <td class="shift-time-cell"><?php echo substr($d->start_time,0,5) . " - " . substr($d->end_time,0,5); ?></td>
                            <td>
                                <div class="shift-color-preview">
                                    <div class="shift-color-box" style="background:<?php echo esc_attr($d->color); ?>;"></div>
                                    <span class="shift-color-code"><?php echo esc_html($d->color); ?></span>
                                </div>
                            </td>
                            <td style="text-align:right;">
                                <div class="shift-actions">
                            <button class='ops-btn secondary' onclick='editDef(<?php echo json_encode($d); ?>)'>Edit</button>
                                    <a href='?page=ai-ops&tab=settings&del_def=<?php echo $d->id; ?>' 
                                       class='ops-btn danger' 
                                       onclick="return confirm('⚠️ Are you sure you want to delete this shift type? This will remove it from all assigned shifts.')">Delete</a>
                                </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        
        <div class="ops-card">
            <div class="shift-form-header">
                <h3 id="shift-form-title">Add New Shift Type</h3>
                <span class="shift-form-mode" id="shift-form-mode">New Shift</span>
            </div>
            <form method="post">
                    <input type="hidden" name="id" id="def_id">
                <div class="shift-form-row">
                    <div class="shift-form-group name">
                        <label>Name</label>
                        <input type="text" name="name" id="def_name" placeholder="e.g., Day Shift" class="ops-input" required>
                    </div>
                    <div class="shift-form-group time">
                        <label>Start Time</label>
                        <input type="time" name="start_time" id="def_start" class="ops-input" required>
                    </div>
                    <div class="shift-form-group time">
                        <label>End Time</label>
                        <input type="time" name="end_time" id="def_end" class="ops-input" required>
                    </div>
                    <div class="shift-form-group color">
                        <label>Color</label>
                        <div class="shift-color-input-wrapper">
                            <input type="color" name="color" id="def_color" value="#e0f2fe" class="shift-color-picker">
                            <span class="shift-color-display" id="color-display">#e0f2fe</span>
                        </div>
                    </div>
                    <div class="shift-form-group actions">
                        <label>&nbsp;</label>
                        <button name="save_shift_def" class="ops-btn primary" type="submit">Save</button>
                    </div>
                </div>
            </form>
        </div>
        
        <script>
        function editDef(d){ 
            document.getElementById('def_id').value = d.id; 
            document.getElementById('def_name').value = d.name; 
            document.getElementById('def_start').value = d.start_time; 
            document.getElementById('def_end').value = d.end_time; 
            document.getElementById('def_color').value = d.color;
            document.getElementById('color-display').textContent = d.color;
            document.getElementById('shift-form-title').textContent = 'Edit Shift Type: ' + d.name;
            document.getElementById('shift-form-mode').textContent = 'Editing';
            document.getElementById('shift-form-mode').style.background = 'var(--color-warning-bg)';
            document.getElementById('shift-form-mode').style.color = '#92400e';
            
            // Scroll to form
            setTimeout(function() {
                var formCard = document.querySelector('.ops-card:last-child');
                if (formCard) {
                    var offset = 100;
                    var elementPosition = formCard.getBoundingClientRect().top;
                    var offsetPosition = elementPosition + window.pageYOffset - offset;
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
            }, 100);
        }
        
        // Update color display when color picker changes
        document.getElementById('def_color').addEventListener('input', function(e) {
            document.getElementById('color-display').textContent = e.target.value;
        });
        
        // Reset form when saved
        document.querySelector('form').addEventListener('submit', function() {
            setTimeout(function() {
                if (!document.getElementById('def_id').value) {
                    document.getElementById('def_id').value = '';
                    document.getElementById('def_name').value = '';
                    document.getElementById('def_start').value = '';
                    document.getElementById('def_end').value = '';
                    document.getElementById('def_color').value = '#e0f2fe';
                    document.getElementById('color-display').textContent = '#e0f2fe';
                    document.getElementById('shift-form-title').textContent = 'Add New Shift Type';
                    document.getElementById('shift-form-mode').textContent = 'New Shift';
                    document.getElementById('shift-form-mode').style.background = 'var(--color-bg-subtle)';
                    document.getElementById('shift-form-mode').style.color = 'var(--color-text-secondary)';
                }
            }, 500);
        });
        </script>
        <?php
    }
    
    private function save_shift_definition($data) {
        global $wpdb;
        
        $shift_defs_table = $this->database->get_table('shift_definitions');
        
        $d = [
            'name' => sanitize_text_field($data['name']),
            'start_time' => sanitize_text_field($data['start_time']),
            'end_time' => sanitize_text_field($data['end_time']),
            'color' => sanitize_text_field($data['color'])
        ];
        
        if(!empty($data['id'])) {
            $wpdb->update($shift_defs_table, $d, ['id'=>$data['id']]);
        } else {
            $wpdb->insert($shift_defs_table, $d);
        }
    }
}