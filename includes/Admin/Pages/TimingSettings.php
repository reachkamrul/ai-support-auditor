<?php
/**
 * Timing Penalty Settings Page
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class TimingSettings {

    private $database;

    const OPTION_KEY = 'ai_audit_timing_settings';

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    /**
     * Get timing settings with defaults fallback.
     * Static so AuditEndpoint can call it directly.
     */
    public static function get_settings() {
        $settings = get_option(self::OPTION_KEY, null);
        $defaults = self::get_defaults();

        if ($settings === null || !is_array($settings)) {
            return $defaults;
        }

        return array_merge($defaults, $settings);
    }

    private static function get_defaults() {
        return [
            'enabled' => true,
            'delay_rules' => [
                ['hours' => 4,  'penalty' => 0],
                ['hours' => 8,  'penalty' => -5],
                ['hours' => 12, 'penalty' => -15],
                ['hours' => 24, 'penalty' => -30],
                ['hours' => 48, 'penalty' => -50],
            ],
            'default_penalty' => -80,
            'excluded_tag_ids' => [],
            'updated_at' => null,
        ];
    }

    public function render() {
        if (isset($_POST['save_timing_settings'])) {
            check_admin_referer('timing_settings_nonce');
            $this->save_settings($_POST);
            echo '<div class="notice notice-success is-dismissible"><p><strong>Timing penalty settings saved.</strong></p></div>';
        }

        $settings = self::get_settings();
        $tags = $this->get_available_tags();

        $this->render_styles();
        echo '<form method="post" id="timing-settings-form">';
        wp_nonce_field('timing_settings_nonce');

        $this->render_master_toggle($settings);
        $this->render_delay_rules($settings);
        $this->render_tag_exclusions($settings, $tags);
        $this->render_save_bar($settings);

        echo '</form>';
        $this->render_scripts();
    }

    private function save_settings($post_data) {
        $settings = [];

        $settings['enabled'] = isset($post_data['timing_enabled']);

        $hours_list = isset($post_data['rule_hours']) ? (array) $post_data['rule_hours'] : [];
        $penalty_list = isset($post_data['rule_penalty']) ? (array) $post_data['rule_penalty'] : [];

        $rules = [];
        for ($i = 0; $i < count($hours_list); $i++) {
            $h = floatval($hours_list[$i]);
            $p = intval($penalty_list[$i]);
            if ($h > 0) {
                $rules[] = [
                    'hours' => $h,
                    'penalty' => max(-200, min(0, $p)),
                ];
            }
        }

        usort($rules, function($a, $b) {
            return $a['hours'] <=> $b['hours'];
        });

        $settings['delay_rules'] = $rules;
        $settings['default_penalty'] = max(-200, min(0, intval($post_data['default_penalty'] ?? -80)));

        $tag_ids = isset($post_data['excluded_tags']) ? (array) $post_data['excluded_tags'] : [];
        $settings['excluded_tag_ids'] = array_map('intval', array_filter($tag_ids));

        $settings['updated_at'] = current_time('mysql');

        update_option(self::OPTION_KEY, $settings);
    }

    private function get_available_tags() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, title, slug FROM {$wpdb->prefix}fs_taggables ORDER BY title ASC"
        ) ?: [];
    }

    private function render_styles() {
        ?>
        <style>
            .timing-toggle-wrapper {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .toggle-switch {
                position: relative;
                width: 48px;
                height: 26px;
                display: inline-block;
            }
            .toggle-switch input { opacity: 0; width: 0; height: 0; }
            .toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0; left: 0; right: 0; bottom: 0;
                background: #cbd5e1;
                border-radius: 26px;
                transition: 0.3s;
            }
            .toggle-slider::before {
                content: "";
                position: absolute;
                height: 20px; width: 20px;
                left: 3px; bottom: 3px;
                background: white;
                border-radius: 50%;
                transition: 0.3s;
            }
            .toggle-switch input:checked + .toggle-slider { background: var(--color-success, #22c55e); }
            .toggle-switch input:checked + .toggle-slider::before { transform: translateX(22px); }
            .toggle-label {
                font-size: var(--font-size-base);
                font-weight: 500;
                color: var(--color-text-secondary, #64748b);
            }
            .toggle-status-badge {
                font-size: var(--font-size-xs);
                font-weight: 500;
                padding: 4px 10px;
                border-radius: 999px;
            }
            .toggle-status-badge.active { background: #dcfce7; color: #065f46; }
            .toggle-status-badge.inactive { background: #fee2e2; color: #991b1b; }
            .section-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 16px;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--color-border, #e2e8f0);
            }
            .section-header h3 { margin: 0; font-size: var(--font-size-md); font-weight: 600; }
            .rule-row {
                display: flex;
                gap: 10px;
                align-items: center;
                padding: 8px 0;
                border-bottom: 1px solid var(--color-border, #e2e8f0);
            }
            .rule-row:last-child { border-bottom: none; }
            .rule-row .ops-input { width: 110px; }
            .rule-label { font-size: var(--font-size-sm); color: var(--color-text-secondary, #64748b); min-width: 40px; }
            .rule-remove {
                background: none;
                border: none;
                color: #ef4444;
                cursor: pointer;
                font-size: var(--font-size-lg);
                padding: 4px 8px;
                border-radius: 4px;
            }
            .rule-remove:hover { background: #fee2e2; }
            .default-penalty-row {
                display: flex;
                gap: 10px;
                align-items: center;
                padding: 12px 0;
                margin-top: 8px;
                border-top: 2px solid var(--color-border, #e2e8f0);
            }
            .default-penalty-label { font-size: var(--font-size-sm); font-weight: 600; }
            .info-box {
                padding: 10px 14px;
                background: #eff6ff;
                border: 1px solid #bfdbfe;
                border-left: 3px solid var(--color-primary, #3b82f6);
                border-radius: 4px;
                margin-top: 14px;
                font-size: var(--font-size-sm);
                color: var(--color-text-secondary, #64748b);
                line-height: 1.5;
            }
            .tag-chips {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-top: 12px;
            }
            .tag-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 5px 12px;
                background: var(--color-bg-subtle, #f8fafc);
                border: 1px solid var(--color-border, #e2e8f0);
                border-radius: 999px;
                font-size: var(--font-size-xs);
                font-weight: 500;
            }
            .tag-chip .remove {
                cursor: pointer;
                color: #94a3b8;
                font-size: var(--font-size-base);
            }
            .tag-chip .remove:hover { color: #ef4444; }
            .empty-tags {
                color: #94a3b8;
                font-size: var(--font-size-sm);
                font-style: italic;
                padding: 16px;
                text-align: center;
            }
            .save-bar {
                display: flex;
                gap: 12px;
                align-items: center;
                margin-top: 20px;
                padding-top: 16px;
                border-top: 1px solid var(--color-border, #e2e8f0);
            }
            .save-bar .last-saved {
                margin-left: auto;
                font-size: var(--font-size-sm);
                color: var(--color-text-secondary, #64748b);
            }
            .tag-add-row {
                display: flex;
                gap: 8px;
                align-items: center;
                margin-top: 8px;
            }
            .tag-add-row select { max-width: 300px; }
        </style>
        <?php
    }

    private function render_master_toggle($settings) {
        $enabled = $settings['enabled'];
        ?>
        <div class="ops-card">
            <div class="section-header">
                <h3>Timing Penalty Settings</h3>
                <span class="toggle-status-badge <?php echo $enabled ? 'active' : 'inactive'; ?>" id="toggle-badge">
                    <?php echo $enabled ? 'Active' : 'Disabled'; ?>
                </span>
            </div>
            <div class="timing-toggle-wrapper">
                <label class="toggle-switch">
                    <input type="checkbox" name="timing_enabled" value="1" <?php checked($enabled); ?>
                        onchange="var b=document.getElementById('toggle-badge');b.textContent=this.checked?'Active':'Disabled';b.className='toggle-status-badge '+(this.checked?'active':'inactive')">
                    <span class="toggle-slider"></span>
                </label>
                <span class="toggle-label">Enable timing penalties</span>
            </div>
            <div class="info-box">
                When disabled, all agents receive timing_score = 0 regardless of response delays.
                Only new audits are affected — existing data is not changed.
            </div>
        </div>
        <?php
    }

    private function render_delay_rules($settings) {
        $rules = $settings['delay_rules'];
        $default_penalty = $settings['default_penalty'];
        ?>
        <div class="ops-card">
            <div class="section-header">
                <h3>Delay Penalty Rules</h3>
                <button type="button" class="ops-btn secondary" onclick="addRule()">+ Add Rule</button>
            </div>
            <p style="margin:0 0 12px;font-size:var(--font-size-sm);color:var(--color-text-secondary,#64748b)">
                Define penalty brackets by maximum delay hours. The agent's worst on-shift delay is matched against these brackets (evaluated shortest to longest).
            </p>
            <div id="rules-container">
                <?php foreach ($rules as $rule): ?>
                <div class="rule-row">
                    <span class="rule-label">Up to</span>
                    <input type="number" name="rule_hours[]" value="<?php echo esc_attr($rule['hours']); ?>" class="ops-input" step="0.5" min="0" placeholder="Hours">
                    <span class="rule-label">hours =</span>
                    <input type="number" name="rule_penalty[]" value="<?php echo esc_attr($rule['penalty']); ?>" class="ops-input" max="0" min="-200" placeholder="Penalty">
                    <span class="rule-label">points</span>
                    <button type="button" class="rule-remove" onclick="removeRule(this)" title="Remove">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="default-penalty-row">
                <span class="default-penalty-label">Beyond last bracket:</span>
                <input type="number" name="default_penalty" value="<?php echo esc_attr($default_penalty); ?>" class="ops-input" style="width:110px" max="0" min="-200">
                <span class="rule-label">points</span>
            </div>
            <div class="info-box">
                Example: If worst on-shift delay is 10h and the bracket "Up to 12 hours = -15" exists, the penalty is -15.
                If it exceeds all brackets, the "Beyond last bracket" penalty applies.
            </div>
        </div>
        <?php
    }

    private function render_tag_exclusions($settings, $tags) {
        $excluded_ids = $settings['excluded_tag_ids'];
        ?>
        <div class="ops-card">
            <div class="section-header">
                <h3>Tag Exclusions</h3>
            </div>
            <p style="margin:0 0 12px;font-size:var(--font-size-sm);color:var(--color-text-secondary,#64748b)">
                Tickets with any of these FluentSupport tags will be exempt from timing penalties.
                All agents on the ticket get timing_score = 0 with an exclusion note in the audit.
            </p>
            <?php if (empty($tags)): ?>
                <div class="empty-tags">
                    No FluentSupport tags found. Create tags in FluentSupport and they will appear here.
                </div>
            <?php else: ?>
                <div class="tag-add-row">
                    <select id="tag-selector" class="ops-input">
                        <option value="">-- Choose a tag --</option>
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?php echo esc_attr($tag->id); ?>"
                                data-title="<?php echo esc_attr($tag->title); ?>"
                                <?php echo in_array($tag->id, $excluded_ids) ? 'disabled' : ''; ?>>
                                <?php echo esc_html($tag->title); ?> (<?php echo esc_html($tag->slug); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="ops-btn secondary" onclick="addTag()">+ Add</button>
                </div>
            <?php endif; ?>
            <div class="tag-chips" id="tag-list">
                <?php foreach ($excluded_ids as $tag_id):
                    $tag_title = "Tag #$tag_id";
                    foreach ($tags as $t) {
                        if ($t->id == $tag_id) { $tag_title = $t->title; break; }
                    }
                ?>
                    <div class="tag-chip" data-tag-id="<?php echo esc_attr($tag_id); ?>">
                        <input type="hidden" name="excluded_tags[]" value="<?php echo esc_attr($tag_id); ?>">
                        <span><?php echo esc_html($tag_title); ?></span>
                        <span class="remove" onclick="removeTag(this)">&times;</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_save_bar($settings) {
        ?>
        <div class="save-bar">
            <button name="save_timing_settings" class="ops-btn primary" type="submit">Save Timing Settings</button>
            <button type="button" class="ops-btn secondary" onclick="resetDefaults()">Reset to Defaults</button>
            <?php if ($settings['updated_at']): ?>
                <span class="last-saved">Last saved: <strong><?php echo esc_html($settings['updated_at']); ?></strong></span>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_scripts() {
        $defaults = json_encode(self::get_defaults());
        ?>
        <script>
        function addRule() {
            var c = document.getElementById('rules-container');
            var row = document.createElement('div');
            row.className = 'rule-row';
            row.innerHTML =
                '<span class="rule-label">Up to</span>' +
                '<input type="number" name="rule_hours[]" value="" class="ops-input" step="0.5" min="0" placeholder="Hours">' +
                '<span class="rule-label">hours =</span>' +
                '<input type="number" name="rule_penalty[]" value="" class="ops-input" max="0" min="-200" placeholder="Penalty">' +
                '<span class="rule-label">points</span>' +
                '<button type="button" class="rule-remove" onclick="removeRule(this)" title="Remove">&times;</button>';
            c.appendChild(row);
            row.querySelector('input[name="rule_hours[]"]').focus();
        }
        function removeRule(btn) {
            if (document.querySelectorAll('.rule-row').length <= 1) {
                alert('You must have at least one delay rule.');
                return;
            }
            btn.closest('.rule-row').remove();
        }
        function addTag() {
            var sel = document.getElementById('tag-selector');
            if (!sel || !sel.value) return;
            var tagId = sel.value;
            var tagTitle = sel.options[sel.selectedIndex].dataset.title;
            if (document.querySelector('.tag-chip[data-tag-id="' + tagId + '"]')) return;
            var chip = document.createElement('div');
            chip.className = 'tag-chip';
            chip.dataset.tagId = tagId;
            chip.innerHTML =
                '<input type="hidden" name="excluded_tags[]" value="' + tagId + '">' +
                '<span>' + tagTitle + '</span>' +
                '<span class="remove" onclick="removeTag(this)">&times;</span>';
            document.getElementById('tag-list').appendChild(chip);
            sel.options[sel.selectedIndex].disabled = true;
            sel.value = '';
        }
        function removeTag(btn) {
            var chip = btn.closest('.tag-chip');
            var tagId = chip.dataset.tagId;
            chip.remove();
            var sel = document.getElementById('tag-selector');
            if (sel) {
                for (var i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].value === tagId) { sel.options[i].disabled = false; break; }
                }
            }
        }
        function resetDefaults() {
            if (!confirm('Reset all timing settings to default values?')) return;
            var d = <?php echo $defaults; ?>;
            document.querySelector('input[name="timing_enabled"]').checked = d.enabled;
            var b = document.getElementById('toggle-badge');
            b.textContent = d.enabled ? 'Active' : 'Disabled';
            b.className = 'toggle-status-badge ' + (d.enabled ? 'active' : 'inactive');
            var c = document.getElementById('rules-container');
            c.innerHTML = '';
            d.delay_rules.forEach(function(r) {
                var row = document.createElement('div');
                row.className = 'rule-row';
                row.innerHTML =
                    '<span class="rule-label">Up to</span>' +
                    '<input type="number" name="rule_hours[]" value="' + r.hours + '" class="ops-input" step="0.5" min="0">' +
                    '<span class="rule-label">hours =</span>' +
                    '<input type="number" name="rule_penalty[]" value="' + r.penalty + '" class="ops-input" max="0" min="-200">' +
                    '<span class="rule-label">points</span>' +
                    '<button type="button" class="rule-remove" onclick="removeRule(this)">&times;</button>';
                c.appendChild(row);
            });
            document.querySelector('input[name="default_penalty"]').value = d.default_penalty;
            document.getElementById('tag-list').innerHTML = '';
            var sel = document.getElementById('tag-selector');
            if (sel) { for (var i = 0; i < sel.options.length; i++) sel.options[i].disabled = false; }
        }
        </script>
        <?php
    }
}
