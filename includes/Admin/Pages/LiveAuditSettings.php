<?php
/**
 * Live Audit Settings Page
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class LiveAuditSettings {

    private $database;

    const OPTION_KEY = 'ai_audit_live_settings';

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    /**
     * Get live audit settings with defaults fallback.
     * Static so other classes can call it directly.
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
            'enabled' => false,
            'trigger_mode' => 'every_reply',    // 'every_reply' | 'first_and_close' | 'every_nth'
            'milestone_interval' => 3,           // For 'every_nth' mode
            'min_interval_minutes' => 15,        // Throttle: min minutes between audits per ticket
            'n8n_webhook_path' => '',            // Collector webhook path (UUID only)
            'n8n_batch_webhook_path' => '',      // Batch workflow force-trigger webhook path (UUID only)
            'fluent_support_url' => '',          // FluentSupport admin URL (e.g. https://support.wpmanageninja.com/wp-admin/)
            'updated_at' => null,
        ];
    }

    public function render() {
        if (isset($_POST['save_live_audit_settings'])) {
            check_admin_referer('live_audit_settings_nonce');
            $this->save_settings($_POST);
            echo '<div class="notice notice-success is-dismissible"><p><strong>Live audit settings saved.</strong></p></div>';
        }

        $settings = self::get_settings();

        $this->render_styles();
        echo '<form method="post" id="live-audit-settings-form">';
        wp_nonce_field('live_audit_settings_nonce');

        $this->render_master_toggle($settings);

        $display = $settings['enabled'] ? '' : 'display:none;';
        echo '<div id="la-dependent-sections" style="' . $display . '">';
        $this->render_trigger_mode($settings);
        $this->render_throttle($settings);
        $this->render_info();
        $this->render_webhook_setup();
        echo '</div>';

        $this->render_save_bar($settings);

        echo '</form>';
    }

    private function save_settings($post_data) {
        $settings = [];

        $settings['enabled'] = isset($post_data['live_audit_enabled']);
        $settings['trigger_mode'] = sanitize_text_field($post_data['trigger_mode'] ?? 'every_reply');

        if (!in_array($settings['trigger_mode'], ['every_reply', 'first_and_close', 'every_nth'])) {
            $settings['trigger_mode'] = 'every_reply';
        }

        $settings['milestone_interval'] = max(2, min(20, intval($post_data['milestone_interval'] ?? 3)));
        $settings['min_interval_minutes'] = max(5, min(120, intval($post_data['min_interval_minutes'] ?? 15)));
        $settings['n8n_webhook_path'] = sanitize_text_field($post_data['n8n_webhook_path'] ?? '');
        $settings['n8n_batch_webhook_path'] = sanitize_text_field($post_data['n8n_batch_webhook_path'] ?? '');
        $settings['fluent_support_url'] = esc_url_raw($post_data['fluent_support_url'] ?? '');
        $settings['updated_at'] = current_time('mysql');

        update_option(self::OPTION_KEY, $settings);
    }

    private function render_styles() {
        ?>
        <style>
            .la-toggle-wrapper {
                display: flex; align-items: center; gap: 12px;
            }
            .la-toggle-switch {
                position: relative; width: 48px; height: 26px; display: inline-block;
            }
            .la-toggle-switch input { opacity: 0; width: 0; height: 0; }
            .la-toggle-slider {
                position: absolute; cursor: pointer;
                top: 0; left: 0; right: 0; bottom: 0;
                background: #cbd5e1; border-radius: 26px; transition: 0.3s;
            }
            .la-toggle-slider::before {
                content: ""; position: absolute;
                height: 20px; width: 20px;
                left: 3px; bottom: 3px;
                background: white; border-radius: 50%; transition: 0.3s;
            }
            .la-toggle-switch input:checked + .la-toggle-slider { background: var(--color-success, #22c55e); }
            .la-toggle-switch input:checked + .la-toggle-slider::before { transform: translateX(22px); }
            .la-toggle-label {
                font-size: var(--font-size-base); font-weight: 500;
                color: var(--color-text-secondary, #64748b);
            }
            .la-badge {
                font-size: var(--font-size-xs); font-weight: 500;
                padding: 4px 10px; border-radius: 999px;
            }
            .la-badge.active { background: #dcfce7; color: #065f46; }
            .la-badge.inactive { background: #fee2e2; color: #991b1b; }

            .la-section-header {
                display: flex; align-items: center; justify-content: space-between;
                margin-bottom: 16px; padding-bottom: 12px;
                border-bottom: 1px solid var(--color-border, #e2e8f0);
            }
            .la-section-header h3 { margin: 0; font-size: var(--font-size-md); font-weight: 600; }

            .la-radio-group { display: flex; flex-direction: column; gap: 12px; }
            .la-radio-item {
                display: flex; align-items: flex-start; gap: 10px;
                padding: 12px 16px; background: var(--color-bg-subtle, #f8fafc);
                border: 1px solid var(--color-border, #e2e8f0);
                border-radius: var(--radius-md, 8px); cursor: pointer;
                transition: border-color 0.2s;
            }
            .la-radio-item:hover { border-color: var(--color-primary, #3b82f6); }
            .la-radio-item.selected {
                border-color: var(--color-primary, #3b82f6);
                background: #eff6ff;
            }
            .la-radio-item input[type="radio"] { margin-top: 2px; }
            .la-radio-content { flex: 1; }
            .la-radio-label { font-size: var(--font-size-base); font-weight: 600; display: block; }
            .la-radio-desc {
                font-size: var(--font-size-xs); color: var(--color-text-tertiary, #94a3b8);
                margin-top: 2px;
            }
            .la-radio-extra {
                display: inline-flex; align-items: center; gap: 6px;
                margin-top: 6px;
            }
            .la-radio-extra label { font-size: var(--font-size-xs); color: var(--color-text-secondary); }
            .la-radio-extra input { width: 60px; }

            .la-throttle-row {
                display: flex; align-items: center; gap: 10px;
            }
            .la-throttle-row label { font-size: var(--font-size-base); font-weight: 500; }
            .la-throttle-row .ops-input { width: 80px; }
            .la-throttle-row .unit { font-size: var(--font-size-sm); color: var(--color-text-secondary); }

            .la-info-box {
                padding: 12px 16px; background: #eff6ff;
                border: 1px solid #bfdbfe; border-left: 3px solid var(--color-primary, #3b82f6);
                border-radius: 4px; font-size: var(--font-size-sm);
                color: var(--color-text-secondary, #64748b); line-height: 1.6;
            }
            .la-info-box strong { color: var(--color-text-primary, #1e293b); }

            .la-save-bar {
                display: flex; gap: 12px; align-items: center;
                margin-top: 20px; padding-top: 16px;
                border-top: 1px solid var(--color-border, #e2e8f0);
            }
            .la-save-bar .last-saved {
                margin-left: auto; font-size: var(--font-size-sm);
                color: var(--color-text-secondary, #64748b);
            }

            .la-setup-step {
                display: flex; gap: 12px; padding: 14px 0;
                border-bottom: 1px solid var(--color-border, #e2e8f0);
            }
            .la-setup-step:last-child { border-bottom: none; }
            .la-step-num {
                width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
                background: var(--color-primary, #3b82f6); color: #fff;
                font-size: var(--font-size-sm); font-weight: 700;
                display: flex; align-items: center; justify-content: center;
            }
            .la-step-content { flex: 1; }
            .la-step-title { font-size: var(--font-size-base); font-weight: 600; margin-bottom: 4px; }
            .la-step-desc { font-size: var(--font-size-xs); color: var(--color-text-tertiary, #94a3b8); line-height: 1.5; }
            .la-copy-row {
                display: flex; align-items: center; gap: 8px; margin-top: 8px;
            }
            .la-copy-input {
                flex: 1; padding: 8px 12px; font-size: var(--font-size-xs); font-family: monospace;
                background: var(--color-bg-subtle, #f1f5f9); border: 1px solid var(--color-border, #e2e8f0);
                border-radius: var(--radius-md, 6px); color: var(--color-text-primary, #1e293b);
                cursor: text; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .la-copy-btn {
                padding: 7px 14px; font-size: var(--font-size-xs); font-weight: 500; cursor: pointer;
                background: var(--color-bg-subtle, #f1f5f9); border: 1px solid var(--color-border, #e2e8f0);
                border-radius: var(--radius-md, 6px); color: var(--color-text-secondary, #64748b);
                transition: all 0.15s;
            }
            .la-copy-btn:hover { background: #e2e8f0; }
            .la-copy-btn.copied { background: #dcfce7; color: #065f46; border-color: #86efac; }
            .la-code-block {
                width: 100% !important; padding: 12px 14px !important; font-size: var(--font-size-xs) !important;
                font-family: 'SF Mono', 'Menlo', 'Consolas', monospace !important;
                background: #1e293b !important; color: #e2e8f0 !important;
                border: 1px solid #334155 !important;
                border-radius: var(--radius-md, 6px); line-height: 1.6;
                resize: vertical; tab-size: 4; white-space: pre; overflow-x: auto;
                -webkit-text-fill-color: #e2e8f0 !important;
                opacity: 1 !important;
            }
            .la-warning-box {
                padding: 12px 16px; background: #fef9c3;
                border: 1px solid #fde68a; border-left: 3px solid #f59e0b;
                border-radius: 4px; font-size: var(--font-size-sm);
                color: #92400e; line-height: 1.6; margin-top: 12px;
            }
            .la-step-num.verified {
                background: var(--color-success, #22c55e);
            }
            .la-step-num.pending {
                background: #94a3b8;
            }
            .la-step-status {
                display: inline-flex; align-items: center; gap: 6px;
                margin-top: 8px; font-size: var(--font-size-xs); padding: 4px 10px;
                border-radius: 6px;
            }
            .la-step-status.connected {
                background: #dcfce7; color: #065f46;
            }
            .la-step-status.waiting {
                background: #f1f5f9; color: #64748b;
            }
            .la-step-status .la-status-dot {
                width: 7px; height: 7px; border-radius: 50%; display: inline-block;
            }
            .la-step-status.connected .la-status-dot { background: #22c55e; }
            .la-step-status.waiting .la-status-dot { background: #94a3b8; }
            .la-test-btn {
                padding: 6px 14px; font-size: var(--font-size-xs); font-weight: 500; cursor: pointer;
                background: #eff6ff; border: 1px solid #bfdbfe;
                border-radius: var(--radius-md, 6px); color: #1e40af;
                transition: all 0.15s; margin-top: 8px; display: inline-flex;
                align-items: center; gap: 6px;
            }
            .la-test-btn:hover { background: #dbeafe; }
            .la-test-btn:disabled { opacity: 0.5; cursor: not-allowed; }
            .la-test-btn .la-spinner {
                width: 12px; height: 12px; border: 2px solid #bfdbfe;
                border-top-color: #1e40af; border-radius: 50%;
                animation: la-spin 0.6s linear infinite; display: none;
            }
            @keyframes la-spin { to { transform: rotate(360deg); } }
        </style>
        <?php
    }

    private function render_master_toggle($settings) {
        $enabled = $settings['enabled'];
        ?>
        <div class="ops-card">
            <div class="la-section-header">
                <h3>Live Audit</h3>
                <span class="la-badge <?php echo $enabled ? 'active' : 'inactive'; ?>" id="la-toggle-badge">
                    <?php echo $enabled ? 'Active' : 'Disabled'; ?>
                </span>
            </div>
            <div class="la-toggle-wrapper">
                <label class="la-toggle-switch">
                    <input type="checkbox" name="live_audit_enabled" value="1" <?php checked($enabled); ?>
                        onchange="var b=document.getElementById('la-toggle-badge');b.textContent=this.checked?'Active':'Disabled';b.className='la-badge '+(this.checked?'active':'inactive');document.getElementById('la-dependent-sections').style.display=this.checked?'':'none'">
                    <span class="la-toggle-slider"></span>
                </label>
                <span class="la-toggle-label">Enable live auditing</span>
            </div>
            <div class="la-info-box" style="margin-top:14px;">
                When enabled, audits trigger automatically on agent responses — not just on ticket close.
                Incremental audits send only <strong>new responses</strong> to Gemini with a summary of the previous audit, saving API tokens.
                A <strong>final full audit</strong> always runs when the ticket closes for maximum accuracy.
            </div>
        </div>
        <?php
    }

    private function render_trigger_mode($settings) {
        $mode = $settings['trigger_mode'];
        $interval = $settings['milestone_interval'];
        ?>
        <div class="ops-card">
            <div class="la-section-header">
                <h3>Trigger Mode</h3>
            </div>
            <p style="margin:0 0 12px;font-size:var(--font-size-sm);color:var(--color-text-secondary);">
                Choose when incremental audits should fire for open tickets.
            </p>
            <div class="la-radio-group" id="trigger-mode-group">
                <label class="la-radio-item <?php echo $mode === 'every_reply' ? 'selected' : ''; ?>">
                    <input type="radio" name="trigger_mode" value="every_reply" <?php checked($mode, 'every_reply'); ?>
                        onchange="updateRadioSelection()">
                    <div class="la-radio-content">
                        <span class="la-radio-label">Every agent reply</span>
                        <span class="la-radio-desc">Most thorough. Each agent response triggers an incremental audit (subject to throttle).</span>
                    </div>
                </label>
                <label class="la-radio-item <?php echo $mode === 'first_and_close' ? 'selected' : ''; ?>">
                    <input type="radio" name="trigger_mode" value="first_and_close" <?php checked($mode, 'first_and_close'); ?>
                        onchange="updateRadioSelection()">
                    <div class="la-radio-content">
                        <span class="la-radio-label">First response + close only</span>
                        <span class="la-radio-desc">Minimal API usage. Audit after the first agent reply, then final audit on close.</span>
                    </div>
                </label>
                <label class="la-radio-item <?php echo $mode === 'every_nth' ? 'selected' : ''; ?>">
                    <input type="radio" name="trigger_mode" value="every_nth" <?php checked($mode, 'every_nth'); ?>
                        onchange="updateRadioSelection()">
                    <div class="la-radio-content">
                        <span class="la-radio-label">Every Nth response + close</span>
                        <span class="la-radio-desc">Balanced. Audit at milestones (e.g., every 3rd reply), plus final on close.</span>
                        <div class="la-radio-extra" id="nth-interval-row" style="<?php echo $mode !== 'every_nth' ? 'display:none;' : ''; ?>">
                            <label>Every</label>
                            <input type="number" name="milestone_interval" value="<?php echo esc_attr($interval); ?>"
                                class="ops-input" min="2" max="20" step="1">
                            <label>responses</label>
                        </div>
                    </div>
                </label>
            </div>
        </div>
        <script>
        function updateRadioSelection() {
            var items = document.querySelectorAll('.la-radio-item');
            items.forEach(function(item) {
                var radio = item.querySelector('input[type="radio"]');
                item.classList.toggle('selected', radio.checked);
            });
            var nthRow = document.getElementById('nth-interval-row');
            var nthRadio = document.querySelector('input[value="every_nth"]');
            nthRow.style.display = nthRadio.checked ? '' : 'none';
        }
        </script>
        <?php
    }

    private function render_throttle($settings) {
        $minutes = $settings['min_interval_minutes'];
        ?>
        <div class="ops-card">
            <div class="la-section-header">
                <h3>Throttling</h3>
            </div>
            <p style="margin:0 0 12px;font-size:var(--font-size-sm);color:var(--color-text-secondary);">
                Prevent excessive API usage by limiting how frequently a single ticket can be re-audited.
            </p>
            <div class="la-throttle-row">
                <label>Minimum interval between audits per ticket:</label>
                <input type="number" name="min_interval_minutes" value="<?php echo esc_attr($minutes); ?>"
                    class="ops-input" min="5" max="120" step="5">
                <span class="unit">minutes</span>
            </div>
            <div class="la-info-box" style="margin-top:14px;">
                If multiple agent replies happen within the throttle window, only one audit is queued.
                The final full audit on ticket close always runs regardless of throttle.
            </div>
        </div>
        <?php
    }

    private function render_info() {
        ?>
        <div class="ops-card">
            <div class="la-section-header">
                <h3>How It Works</h3>
            </div>
            <div style="font-size:var(--font-size-sm);line-height:1.8;color:var(--color-text-secondary);">
                <p style="margin:0 0 8px;"><strong>1. Agent replies</strong> to an open ticket</p>
                <p style="margin:0 0 8px;"><strong>2. FluentSupport webhook</strong> fires &rarr; N8N collector receives it</p>
                <p style="margin:0 0 8px;"><strong>3. N8N calls</strong> this plugin's API to queue the audit (throttle + trigger mode checked)</p>
                <p style="margin:0 0 8px;"><strong>4. N8N batch workflow</strong> picks up the pending audit (every 5 min)</p>
                <p style="margin:0 0 8px;"><strong>5. Gemini receives</strong> only NEW responses + previous audit summary (saves tokens)</p>
                <p style="margin:0 0 8px;"><strong>6. Results merge</strong> with existing audit data &mdash; scores update in real-time</p>
                <p style="margin:0;"><strong>7. Ticket closes</strong> &rarr; final full audit runs for maximum accuracy</p>
            </div>
        </div>
        <?php
    }

    private function render_webhook_setup() {
        $settings = self::get_settings();
        $n8n_url = get_option('ai_audit_n8n_url', 'https://team.junior.ninja');
        $webhook_uuid = $settings['n8n_webhook_path'] ?: '0076dfb2-1ffb-4f68-8b65-cc6af87f04a6';
        $webhook_url = rtrim($n8n_url, '/') . '/webhook/' . $webhook_uuid;

        $webhook_status = \SupportOps\Services\LiveAuditTrigger::get_webhook_status();
        $has_closed = !empty($webhook_status['ticket_closed']);
        $has_agent  = !empty($webhook_status['agent_response']);

        $mu_plugin_code = <<<'MUCODE'
<?php
/**
 * Plugin Name: AI Audit – Agent Response Webhook
 * Description: Fires an outgoing webhook to N8N when an agent replies to a FluentSupport ticket.
 * Version: 1.0
 */

add_action('fluent_support/response_added_by_agent', function ($response, $ticket, $person) {
    $webhook_url = '%%WEBHOOK_URL%%';

    wp_remote_post($webhook_url, [
        'timeout' => 5,
        'blocking' => false,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'ticket' => [
                'id' => $ticket->id,
                'response_count' => $ticket->response_count ?? 0,
                'status' => $ticket->status ?? 'open',
            ],
            'event' => 'agent_response',
        ]),
    ]);
}, 20, 3);
MUCODE;

        $mu_plugin_code = str_replace('%%WEBHOOK_URL%%', $webhook_url, $mu_plugin_code);

        ?>
        <div class="ops-card">
            <div class="la-section-header">
                <h3>FluentSupport Webhook Setup</h3>
                <?php
                if ($has_closed && $has_agent) {
                    echo '<span class="la-badge active">Connected</span>';
                } elseif ($has_closed || $has_agent) {
                    echo '<span class="la-badge" style="background:#fef9c3;color:#92400e;">Partial</span>';
                } else {
                    echo '<span class="la-badge" style="background:#fef9c3;color:#92400e;">Required</span>';
                }
                ?>
            </div>
            <p style="margin:0 0 14px;font-size:var(--font-size-sm);color:var(--color-text-secondary);line-height:1.6;">
                FluentSupport must send webhooks to N8N so that live audits can be triggered.
                Follow these steps on your <strong>FluentSupport server</strong>.
            </p>

            <div style="padding:14px 16px;background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:var(--radius-md);margin-bottom:16px;">
                <label style="font-size:var(--font-size-xs);font-weight:600;display:block;margin-bottom:6px;">N8N Collector Webhook UUID</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <code style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);white-space:nowrap;"><?php echo esc_html(rtrim($n8n_url, '/')); ?>/webhook/</code>
                    <input type="text" name="n8n_webhook_path" class="ops-input" value="<?php echo esc_attr($webhook_uuid); ?>"
                        style="flex:1;font-family:monospace;font-size:var(--font-size-xs);" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                </div>
                <small style="color:var(--color-text-tertiary);font-size:var(--font-size-xs);">Copy the UUID from your N8N Collector workflow's Webhook trigger node.</small>
            </div>

            <div style="padding:14px 16px;background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:var(--radius-md);margin-bottom:16px;">
                <label style="font-size:var(--font-size-xs);font-weight:600;display:block;margin-bottom:6px;">N8N Batch Workflow Force-Trigger UUID</label>
                <?php $batch_uuid = $settings['n8n_batch_webhook_path'] ?: '7394145a-6afd-4386-ae70-21b012cf904f'; ?>
                <div style="display:flex;gap:8px;align-items:center;">
                    <code style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);white-space:nowrap;"><?php echo esc_html(rtrim($n8n_url, '/')); ?>/webhook/</code>
                    <input type="text" name="n8n_batch_webhook_path" class="ops-input" value="<?php echo esc_attr($batch_uuid); ?>"
                        style="flex:1;font-family:monospace;font-size:var(--font-size-xs);" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                </div>
                <small style="color:var(--color-text-tertiary);font-size:var(--font-size-xs);">Copy the UUID from your N8N Batch workflow's Force Webhook trigger node. Used to immediately trigger batch processing.</small>
            </div>

            <div style="padding:14px 16px;background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:var(--radius-md);margin-bottom:16px;">
                <label style="font-size:var(--font-size-xs);font-weight:600;display:block;margin-bottom:6px;">FluentSupport Admin URL</label>
                <?php $fs_url = $settings['fluent_support_url'] ?: ''; ?>
                <input type="url" name="fluent_support_url" class="ops-input" value="<?php echo esc_attr($fs_url); ?>"
                    style="width:100%;font-family:monospace;font-size:var(--font-size-xs);" placeholder="https://support.wpmanageninja.com/wp-admin/">
                <small style="color:var(--color-text-tertiary);font-size:var(--font-size-xs);">Base wp-admin URL of the FluentSupport server. Used for "View Ticket" links. Leave empty to use this site's admin URL.</small>
            </div>

            <!-- Step 1: Ticket Closed (native workflow) -->
            <div class="la-setup-step">
                <span class="la-step-num <?php echo $has_closed ? 'verified' : ''; ?>"><?php echo $has_closed ? '&#10003;' : '1'; ?></span>
                <div class="la-step-content">
                    <div class="la-step-title">Create "Ticket Closed" Workflow <span class="la-badge active" style="font-size:var(--font-size-xs);padding:2px 8px;">Native</span></div>
                    <div class="la-step-desc">
                        Go to <strong>FluentSupport &rarr; Workflow Automations &rarr; Create Workflow</strong>.<br>
                        Trigger: <strong>On Ticket Closed</strong><br>
                        Action: <strong>Trigger Outgoing Webhook</strong> &rarr; paste the URL below.<br>
                        Check: <strong>Ticket Information</strong> (must include ticket ID and response count).
                    </div>
                    <div class="la-copy-row">
                        <input type="text" class="la-copy-input" value="<?php echo esc_attr($webhook_url); ?>" readonly id="la-webhook-url-1">
                        <button type="button" class="la-copy-btn" onclick="laCopy('la-webhook-url-1', this)">Copy</button>
                    </div>
                    <?php if ($has_closed): ?>
                        <div class="la-step-status connected">
                            <span class="la-status-dot"></span>
                            Last received: <?php echo esc_html($webhook_status['ticket_closed']); ?>
                        </div>
                    <?php else: ?>
                        <div class="la-step-status waiting">
                            <span class="la-status-dot"></span>
                            Waiting for first ticket_closed event&hellip;
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 2: Agent Response (mu-plugin) -->
            <div class="la-setup-step">
                <span class="la-step-num <?php echo $has_agent ? 'verified' : ''; ?>"><?php echo $has_agent ? '&#10003;' : '2'; ?></span>
                <div class="la-step-content">
                    <div class="la-step-title">Install "Agent Response" Webhook <span class="la-badge" style="background:#eff6ff;color:#1e40af;font-size:var(--font-size-xs);padding:2px 8px;">mu-plugin</span></div>
                    <div class="la-step-desc">
                        FluentSupport's workflow automations <strong>do not have</strong> an "Agent Replied" trigger.
                        To capture agent responses, place the mu-plugin below on the FluentSupport server:
                    </div>
                    <div style="margin-top:8px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:var(--font-size-xs);font-weight:600;color:var(--color-text-secondary);">
                                File: <code>wp-content/mu-plugins/ai-audit-agent-webhook.php</code>
                            </span>
                            <button type="button" class="la-copy-btn" onclick="laCopy('la-mu-plugin-code', this)">Copy Code</button>
                        </div>
                        <textarea id="la-mu-plugin-code" class="la-code-block" readonly rows="18"><?php echo esc_textarea($mu_plugin_code); ?></textarea>
                    </div>
                    <?php if ($has_agent): ?>
                        <div class="la-step-status connected">
                            <span class="la-status-dot"></span>
                            Last received: <?php echo esc_html($webhook_status['agent_response']); ?>
                        </div>
                    <?php else: ?>
                        <div class="la-step-status waiting">
                            <span class="la-status-dot"></span>
                            Waiting for first agent_response event&hellip;
                        </div>
                    <?php endif; ?>
                    <div class="la-info-box" style="margin-top:8px;">
                        <strong>Why mu-plugin?</strong> FluentSupport fires the <code>fluent_support/response_added_by_agent</code> PHP hook
                        when agents reply, but this hook is not exposed in the Workflow Automations UI. A mu-plugin hooks into it directly
                        and sends a non-blocking webhook to N8N. It loads automatically &mdash; no activation needed.
                    </div>
                </div>
            </div>

            <!-- Step 3: Test N8N Connection -->
            <div class="la-setup-step">
                <span class="la-step-num" id="la-test-step-num">3</span>
                <div class="la-step-content">
                    <div class="la-step-title">Test N8N Connection</div>
                    <div class="la-step-desc" style="line-height:1.7;">
                        Send a test payload to the N8N collector webhook to verify it's reachable and active.
                    </div>
                    <button type="button" class="la-test-btn" id="la-test-webhook-btn" onclick="laTestWebhook()">
                        <span class="la-spinner" id="la-test-spinner"></span>
                        <span id="la-test-label">Test Connection</span>
                    </button>
                    <div id="la-test-result" style="margin-top:8px;display:none;"></div>
                </div>
            </div>

            <div class="la-warning-box">
                <strong>Important:</strong> This plugin does NOT require FluentSupport on the same WordPress installation.
                Both steps above are configured on the <strong>FluentSupport server</strong>, not here.
                The mu-plugin is a standalone 15-line file with zero dependencies on this audit plugin.
            </div>
        </div>
        <script>
        function laCopy(inputId, btn) {
            var el = document.getElementById(inputId);
            var text = el.value !== undefined ? el.value : el.textContent;
            navigator.clipboard.writeText(text).then(function() {
                btn.textContent = 'Copied!';
                btn.classList.add('copied');
                setTimeout(function() {
                    btn.textContent = 'Copy';
                    btn.classList.remove('copied');
                }, 2000);
            });
        }

        function laTestWebhook() {
            var btn = document.getElementById('la-test-webhook-btn');
            var spinner = document.getElementById('la-test-spinner');
            var label = document.getElementById('la-test-label');
            var result = document.getElementById('la-test-result');
            var stepNum = document.getElementById('la-test-step-num');

            btn.disabled = true;
            spinner.style.display = 'inline-block';
            label.textContent = 'Testing...';
            result.style.display = 'none';

            var fd = new FormData();
            fd.append('action', 'ai_audit_test_webhook');
            fd.append('nonce', '<?php echo wp_create_nonce('ai_ops_nonce'); ?>');

            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    spinner.style.display = 'none';
                    btn.disabled = false;

                    if (data.success) {
                        label.textContent = 'Test Connection';
                        stepNum.textContent = '\u2713';
                        stepNum.classList.add('verified');
                        result.innerHTML = '<div class="la-step-status connected"><span class="la-status-dot"></span>N8N webhook is reachable and responding.</div>';
                    } else {
                        label.textContent = 'Retry';
                        stepNum.classList.remove('verified');
                        stepNum.textContent = '3';
                        var msg = data.data && data.data.message ? data.data.message : 'Connection failed';
                        result.innerHTML = '<div class="la-step-status" style="background:#fee2e2;color:#991b1b;"><span class="la-status-dot" style="background:#ef4444;"></span>' + msg + '</div>';
                    }
                    result.style.display = 'block';
                })
                .catch(function() {
                    spinner.style.display = 'none';
                    btn.disabled = false;
                    label.textContent = 'Retry';
                    result.innerHTML = '<div class="la-step-status" style="background:#fee2e2;color:#991b1b;"><span class="la-status-dot" style="background:#ef4444;"></span>Request failed. Check browser console.</div>';
                    result.style.display = 'block';
                });
        }
        </script>
        <?php
    }

    private function render_save_bar($settings) {
        ?>
        <div class="la-save-bar">
            <button name="save_live_audit_settings" class="ops-btn primary" type="submit">Save Settings</button>
            <?php if ($settings['updated_at']): ?>
                <span class="last-saved">Last saved: <strong><?php echo esc_html($settings['updated_at']); ?></strong></span>
            <?php endif; ?>
        </div>
        <?php
    }
}
