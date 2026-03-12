<?php
/**
 * API Config Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class ApiConfig {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function render() {
        $token = get_option('ai_audit_secret_token');
        
        if (isset($_POST['regenerate_token'])) {
            $new_token = wp_generate_password(32, true, true);
            update_option('ai_audit_secret_token', $new_token);
            $token = $new_token;
            echo '<div class="notice notice-success is-dismissible"><p><strong>New security token generated.</strong></p></div>';
        }
        
        // FluentSupport API settings
        if (isset($_POST['save_fs_api'])) {
            check_admin_referer('save_fs_api_nonce');
            update_option('ai_audit_fs_api_url', esc_url_raw(rtrim($_POST['fs_api_url'] ?? '', '/')));
            update_option('ai_audit_fs_api_user', sanitize_text_field($_POST['fs_api_user'] ?? ''));
            if (!empty($_POST['fs_api_pass'])) {
                update_option('ai_audit_fs_api_pass', sanitize_text_field($_POST['fs_api_pass']));
            }
            update_option('ai_audit_fs_api_connected', '1');
            echo '<div class="notice notice-success is-dismissible"><p><strong>FluentSupport API connected.</strong></p></div>';
        }
        if (isset($_POST['disconnect_fs_api'])) {
            check_admin_referer('save_fs_api_nonce');
            delete_option('ai_audit_fs_api_url');
            delete_option('ai_audit_fs_api_user');
            delete_option('ai_audit_fs_api_pass');
            delete_option('ai_audit_fs_api_connected');
            echo '<div class="notice notice-success is-dismissible"><p><strong>FluentSupport API disconnected.</strong></p></div>';
        }

        $base_url = get_site_url();
        $fs_api_url  = get_option('ai_audit_fs_api_url', '');
        $fs_api_user = get_option('ai_audit_fs_api_user', '');
        $fs_api_pass = get_option('ai_audit_fs_api_pass', '');
        ?>

        <div class="ops-card">
            <h3>FluentSupport API</h3>
            <p style="margin:0 0 16px;color:var(--color-text-secondary);font-size:13px;">
                Connect to FluentSupport REST API for Time Machine and other features.
            </p>

            <?php if ($fs_api_url && $fs_api_user && $fs_api_pass && get_option('ai_audit_fs_api_connected')): ?>
                <!-- Connected state -->
                <div style="padding:20px;border-radius:var(--radius-md);background:var(--color-bg-subtle);border:1px solid var(--color-border);">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                        <span style="width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block;"></span>
                        <span style="font-weight:600;color:var(--color-text-primary);">Connected</span>
                    </div>
                    <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:4px;">
                        <strong>URL:</strong> <?php echo esc_html($fs_api_url); ?>
                    </div>
                    <div style="font-size:13px;color:var(--color-text-secondary);margin-bottom:16px;">
                        <strong>User:</strong> <?php echo esc_html($fs_api_user); ?>
                    </div>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Disconnect FluentSupport API?')">
                        <?php wp_nonce_field('save_fs_api_nonce'); ?>
                        <button name="disconnect_fs_api" class="ops-btn danger" type="submit">Disconnect</button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Setup form -->
                <form method="post" id="fs-api-form">
                    <?php wp_nonce_field('save_fs_api_nonce'); ?>
                    <input type="hidden" name="save_fs_api" value="1">
                    <div style="display:grid;gap:12px;max-width:500px;">
                        <div>
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">API Base URL</label>
                            <input type="url" name="fs_api_url" class="ops-input" value="<?php echo esc_attr($fs_api_url); ?>" placeholder="https://support.junior.ninja" style="width:100%;" required>
                            <small style="color:var(--color-text-tertiary);">The WordPress site where FluentSupport is installed</small>
                        </div>
                        <div>
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Username</label>
                            <input type="text" name="fs_api_user" class="ops-input" value="<?php echo esc_attr($fs_api_user); ?>" placeholder="admin" style="width:100%;" required>
                        </div>
                        <div>
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Application Password</label>
                            <input type="password" name="fs_api_pass" class="ops-input" value="" placeholder="Paste application password" style="width:100%;" required>
                            <small style="color:var(--color-text-tertiary);">Generate at Users &rarr; Profile &rarr; Application Passwords</small>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button type="button" class="ops-btn primary" onclick="testAndConnect()">Test & Connect</button>
                            <span id="fs-test-result" style="font-size:12px;"></span>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <script>
        function testAndConnect() {
            var url = document.querySelector('input[name="fs_api_url"]').value;
            var user = document.querySelector('input[name="fs_api_user"]').value;
            var pass = document.querySelector('input[name="fs_api_pass"]').value;
            var result = document.getElementById('fs-test-result');
            if (!url || !user || !pass) { result.innerHTML = '<span style="color:var(--color-error);">All fields are required</span>'; return; }
            result.innerHTML = 'Testing connection...';
            jQuery.post(ajaxurl, {
                action: 'ai_ops_test_fs_api',
                nonce: '<?php echo wp_create_nonce('ai_ops_nonce'); ?>',
                url: url, user: user, pass: pass
            }, function(res) {
                if (res.success) {
                    result.innerHTML = '<span style="color:var(--color-success);">Connected! Saving...</span>';
                    document.getElementById('fs-api-form').submit();
                } else {
                    result.innerHTML = '<span style="color:var(--color-error);">' + (res.data || 'Connection failed. Check credentials.') + '</span>';
                }
            }).fail(function() {
                result.innerHTML = '<span style="color:var(--color-error);">Request failed</span>';
            });
        }
        </script>

        <div class="ops-card">
            <h3>API security</h3>
            <p style="margin:0 0 16px;color:var(--color-text-secondary);font-size:13px;">This security token is required for n8n to communicate with WordPress.</p>

            <div style="padding:20px;border-radius:var(--radius-md);margin-top:4px;background:var(--color-bg-subtle);border:1px solid var(--color-border);">
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500;">Current security token</h4>
                <div style="display:flex;gap:10px;align-items:center;margin-bottom:15px;">
                    <code data-token="<?php echo esc_attr($token); ?>" class="ops-token-display" style="flex:1;">
                        <?php echo esc_html($token); ?>
                    </code>
                    <button class="ops-btn secondary" onclick="copyToken()">Copy</button>
                </div>

                <form method="post" onsubmit="return confirm('Regenerating will break all existing n8n workflows. Continue?')">
                    <button name="regenerate_token" class="ops-btn danger">Regenerate Token</button>
                </form>
            </div>
        </div>
        
        <div class="ops-card">
            <h3>API endpoints</h3>
            <p style="margin-bottom:20px;">Base URL: <code><?php echo esc_html($base_url); ?>/wp-json/ai-audit/v1</code></p>
            <p style="margin-bottom:20px;color:#666;">All endpoints (except Agent endpoints) require <code>X-Audit-Token</code> header with the security token above.</p>
            
            <h4 style="margin-top:25px;margin-bottom:15px;">Audit endpoints</h4>
            <table class="audit-table" style="margin-bottom:30px;">
                <thead>
                    <tr>
                        <th width="200">Endpoint</th>
                        <th width="80">Method</th>
                        <th>Description</th>
                        <th width="120">Auth</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/save-result</code></td>
                        <td><span class="status-badge">POST</span></td>
                        <td>Save audit results from N8N after AI processing. Accepts ticket_id, status, score, raw_json, and audit_response.</td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                    <tr>
                        <td><code>/get-pending</code></td>
                        <td><span class="status-badge success">GET</span></td>
                        <td>Fetch pending tickets for batch processing. Query param: <code>limit</code> (default: 10). Returns list of ticket_ids needing audit.</td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                    <tr>
                        <td><code>/get-ticket-with-responses</code></td>
                        <td><span class="status-badge success">GET</span></td>
                        <td>Get ticket data with responses included. Query param: <code>ticket_id</code> (required). Returns ticket and responses in N8N-compatible format.</td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                </tbody>
            </table>
            
            <h4 style="margin-top:25px;margin-bottom:15px;">Shift endpoints</h4>
            <table class="audit-table" style="margin-bottom:30px;">
                <thead>
                    <tr>
                        <th width="200">Endpoint</th>
                        <th width="80">Method</th>
                        <th>Description</th>
                        <th width="120">Auth</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/get-shift-context</code></td>
                        <td><span class="status-badge success">GET</span></td>
                        <td>Get shift context for a specific ticket. Query param: <code>ticket_id</code> (required).</td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                    <tr>
                        <td><code>/check-shift</code></td>
                        <td><span class="status-badge">POST</span></td>
                        <td>Check if agent was on shift at specific datetime. Body: <code>{"agent_email": "...", "datetime": "Y-m-d H:i:s"}</code></td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                    <tr>
                        <td><code>/check-shifts-batch</code></td>
                        <td><span class="status-badge">POST</span></td>
                        <td>Batch check multiple agent shifts. Body: <code>{"checks": [{"agent_email": "...", "datetime": "..."}]}</code></td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                </tbody>
            </table>
            
            <h4 style="margin-top:25px;margin-bottom:15px;">System message endpoints</h4>
            <table class="audit-table" style="margin-bottom:30px;">
                <thead>
                    <tr>
                        <th width="200">Endpoint</th>
                        <th width="80">Method</th>
                        <th>Description</th>
                        <th width="120">Auth</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/get-system-message</code></td>
                        <td><span class="status-badge success">GET</span></td>
                        <td>Get current AI system message (prompt) used for audits. Returns message and updated_at timestamp.</td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                    <tr>
                        <td><code>/save-system-message</code></td>
                        <td><span class="status-badge">POST</span></td>
                        <td>Save/update AI system message. Body: <code>{"system_message": "..."}</code></td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                    <tr>
                        <td><code>/test-system-message</code></td>
                        <td><span class="status-badge">POST</span></td>
                        <td>Test system message with a real ticket. Body: <code>{"ticket_id": 123}</code>. Returns prepared message with transcript.</td>
                        <td><span class="status-badge">Token</span></td>
                    </tr>
                </tbody>
            </table>
            
            <h4 style="margin-top:25px;margin-bottom:15px;">Agent endpoints (admin only)</h4>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th width="200">Endpoint</th>
                        <th width="80">Method</th>
                        <th>Description</th>
                        <th width="120">Auth</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/agents</code></td>
                        <td><span class="status-badge success">GET</span></td>
                        <td>Get all agents with performance stats. Query params: <code>date_from</code>, <code>date_to</code>, <code>per_page</code>, <code>page</code>.</td>
                        <td><span class="status-badge warning">Admin</span></td>
                    </tr>
                    <tr>
                        <td><code>/agents/{email}</code></td>
                        <td><span class="status-badge success">GET</span></td>
                        <td>Get detailed agent information. URL param: <code>email</code> (URL encoded). Query params: <code>date_from</code>, <code>date_to</code>.</td>
                        <td><span class="status-badge warning">Admin</span></td>
                    </tr>
                    <tr>
                        <td><code>/agents/{email}/trend</code></td>
                        <td><span class="status-badge success">GET</span></td>
                        <td>Get agent performance trends over time. URL param: <code>email</code>. Query param: <code>days</code> (default: 30).</td>
                        <td><span class="status-badge warning">Admin</span></td>
                    </tr>
                    <tr>
                        <td><code>/agents/{email}/compare</code></td>
                        <td><span class="status-badge success">GET</span></td>
                        <td>Compare agent performance with others. URL param: <code>email</code>.</td>
                        <td><span class="status-badge warning">Admin</span></td>
                    </tr>
                </tbody>
            </table>
            
            <div style="margin-top:30px;padding:15px;background:var(--color-info-bg);border-left:3px solid var(--color-primary);border-radius:var(--radius-sm);">
                <h4 style="margin-top:0;">Usage example</h4>
                <pre style="background:#fff;padding:12px;border-radius:4px;overflow-x:auto;font-size:12px;margin:10px 0 0 0;"><code>curl -X POST "<?php echo esc_html($base_url); ?>/wp-json/ai-audit/v1/save-result" \
  -H "Content-Type: application/json" \
  -H "X-Audit-Token: <?php echo esc_html($token); ?>" \
  -d '{
    "ticket_id": "123",
    "status": "success",
    "score": 85,
    "audit_response": {
      "agent_evaluations": [...],
      "problem_contexts": [...]
    }
  }'</code></pre>
            </div>
        </div>
        
        <script>
        function copyToken() {
            const tokenElement = document.querySelector('[data-token]');
            const token = tokenElement ? tokenElement.dataset.token : '';
            navigator.clipboard.writeText(token).then(() => {
                alert('Token copied to clipboard.');
            });
        }
        </script>
        <?php
    }
}