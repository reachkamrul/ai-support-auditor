<?php
/**
 * Agents Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class Agents {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function render() {
        global $wpdb;
        $is_read_only = AccessControl::is_read_only('agents');

        // Handle agent save
        if(!$is_read_only && isset($_POST['save_agent'])) {
            if ($this->can_modify_agent($_POST['email'] ?? '', intval($_POST['id'] ?? 0))) {
                $this->save_agent($_POST);
                echo '<div class="notice notice-success is-dismissible"><p>Agent saved successfully.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>You can only edit agents in your team.</p></div>';
            }
        }

        // Handle delete
        if(!$is_read_only && isset($_GET['del_agent'])) {
            $agent_id = intval($_GET['del_agent']);
            $agent_email = $wpdb->get_var($wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}ais_agents WHERE id=%d", $agent_id
            ));
            if ($this->can_modify_agent($agent_email ?: '', $agent_id)) {
                $wpdb->delete($wpdb->prefix.'ais_agents', ['id'=>$agent_id]);
                echo '<div class="notice notice-success is-dismissible"><p>Agent deleted.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>You can only delete agents in your team.</p></div>';
            }
        }

        // Team filtering for leads
        $team_emails = AccessControl::get_team_agent_emails();
        if (!empty($team_emails)) {
            $escaped = implode(',', array_map(function ($e) use ($wpdb) {
                return $wpdb->prepare('%s', $e);
            }, $team_emails));
            $agents = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ais_agents WHERE email IN ({$escaped}) ORDER BY first_name ASC");
        } else {
            $agents = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ais_agents ORDER BY first_name ASC");
        }

        $this->render_list($agents);
        if (!$is_read_only) {
            $this->render_form($agents);
        }
    }
    
    /**
     * Check if current user can modify this agent (admin=all, lead=team only)
     */
    private function can_modify_agent($email, $agent_id = 0) {
        if (AccessControl::is_admin()) {
            return true;
        }
        if (!AccessControl::is_lead()) {
            return false;
        }
        // For new agents (id=0), leads can add
        if ($agent_id === 0 && empty($email)) {
            return true;
        }
        // Check if agent email is in lead's team
        $team_emails = AccessControl::get_team_agent_emails();
        // For existing agent being edited, check old email
        if ($agent_id > 0 && empty($email)) {
            global $wpdb;
            $email = $wpdb->get_var($wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}ais_agents WHERE id=%d", $agent_id
            ));
        }
        return in_array($email, $team_emails, true);
    }

    private function save_agent($post_data) {
        global $wpdb;

        $id = intval($post_data['id']);
        $role = in_array($post_data['role'] ?? 'agent', ['agent', 'lead']) ? $post_data['role'] : 'agent';
        $email = sanitize_email($post_data['email']);
        $create_wp = !empty($post_data['create_wp_account']) && $post_data['create_wp_account'] === '1';
        $wp_user_id = !empty($post_data['wp_user_id']) ? intval($post_data['wp_user_id']) : null;

        // Create WP account if requested and no existing link
        if ($create_wp && !$wp_user_id && $email) {
            $wp_user_id = $this->create_wp_account($email, $post_data['fname'] ?? '', $post_data['lname'] ?? '', $role);
            if (is_wp_error($wp_user_id)) {
                echo '<div class="notice notice-error is-dismissible"><p>WP account creation failed: ' . esc_html($wp_user_id->get_error_message()) . '</p></div>';
                $wp_user_id = null;
            }
        }

        // Sync WP role if linked to an existing user and role changed
        if ($wp_user_id && $id > 0) {
            $old_role = $wpdb->get_var($wpdb->prepare(
                "SELECT role FROM {$wpdb->prefix}ais_agents WHERE id=%d", $id
            ));
            if ($old_role && $old_role !== $role) {
                $this->sync_wp_role($wp_user_id, $role);
            }
        }

        $data = [
            'first_name' => sanitize_text_field($post_data['fname']),
            'last_name' => sanitize_text_field($post_data['lname']),
            'email' => $email,
            'title' => sanitize_text_field($post_data['title']),
            'role' => $role,
            'wp_user_id' => $wp_user_id,
            'fluent_agent_id' => !empty($post_data['fluent_id']) ? intval($post_data['fluent_id']) : null,
            'avatar_url' => esc_url_raw($post_data['avatar_url']),
            'is_active' => isset($post_data['is_active']) ? 1 : 0
        ];

        if($id > 0) {
            $old_email = $wpdb->get_var($wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}ais_agents WHERE id=%d",
                $id
            ));
            $wpdb->update($wpdb->prefix.'ais_agents', $data, ['id'=>$id]);

            if($old_email && $old_email !== $data['email']) {
                $wpdb->update(
                    $wpdb->prefix.'ais_agent_shifts',
                    ['agent_email'=>$data['email']],
                    ['agent_email'=>$old_email]
                );
            }
        } else {
            $wpdb->insert($wpdb->prefix.'ais_agents', $data);
        }
    }

    /**
     * Create a WordPress user account for an agent
     */
    private function create_wp_account($email, $first_name, $last_name, $role) {
        // Check if a WP user with this email already exists
        $existing = get_user_by('email', $email);
        if ($existing) {
            // Link to existing user, sync their role
            $this->sync_wp_role($existing->ID, $role);
            return $existing->ID;
        }

        // Generate username from email (part before @)
        $username = sanitize_user(strstr($email, '@', true), true);
        if (username_exists($username)) {
            $username = $username . '_' . wp_rand(100, 999);
        }

        $password = wp_generate_password(16, true, true);
        $wp_role = ($role === 'lead') ? 'support_lead' : 'support_agent';

        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => sanitize_text_field($first_name),
            'last_name' => sanitize_text_field($last_name),
            'display_name' => trim(sanitize_text_field($first_name) . ' ' . sanitize_text_field($last_name)),
            'role' => $wp_role,
        ]);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Send password reset email so agent can set their own password
        wp_new_user_notification($user_id, null, 'user');

        return $user_id;
    }

    /**
     * Sync WP user role when agent role changes
     */
    private function sync_wp_role($wp_user_id, $agent_role) {
        $user = get_user_by('ID', $wp_user_id);
        if (!$user) return;

        // Don't touch administrators
        if (in_array('administrator', $user->roles, true)) return;

        $new_wp_role = ($agent_role === 'lead') ? 'support_lead' : 'support_agent';
        $user->set_role($new_wp_role);
    }
    
    private function render_list($agents) {
        ?>
        <style>
            /* Agents Page Specific Styles */
            .agents-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                margin-bottom: 24px;
                flex-wrap: wrap;
                gap: 16px;
            }
            
            .agents-header > div:first-child {
                flex: 1;
                min-width: 300px;
            }
            
            .agents-stats {
                display: flex;
                gap: 24px;
                flex-wrap: wrap;
            }
            
            .agent-stat {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            
            .agent-stat-label {
                font-size: var(--font-size-sm);
                font-weight: 500;
                color: var(--color-text-tertiary);
            }
            
            .agent-stat-value {
                font-size: var(--font-size-2xl);
                font-weight: 700;
                color: var(--color-text-primary);
                line-height: 1.2;
            }
            
            .agent-row {
                transition: all 0.2s ease;
            }
            
            .agent-row:hover {
                background: var(--color-bg-subtle) !important;
            }
            
            .agent-name-cell {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .agent-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                object-fit: cover;
                border: 2px solid var(--color-border);
                flex-shrink: 0;
            }
            
            .agent-avatar-fallback {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: var(--color-primary);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 600;
                font-size: var(--font-size-base);
                flex-shrink: 0;
                border: 2px solid var(--color-border);
            }
            
            .agent-name-wrapper {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            
            .agent-name {
                font-weight: 600;
                color: var(--color-text-primary);
                font-size: var(--font-size-base);
                line-height: 1.4;
            }
            
            .agent-email-small {
                font-size: var(--font-size-xs);
                color: var(--color-text-tertiary);
                line-height: 1.3;
            }
            
            .agent-title-cell {
                color: var(--color-text-secondary);
                font-size: var(--font-size-sm);
                line-height: 1.4;
            }
            
            .agent-email-cell {
                font-family: var(--font-mono);
                font-size: var(--font-size-xs);
                color: var(--color-text-secondary);
            }
            
            .agent-fluent-id {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 4px 10px;
                background: var(--color-bg-subtle);
                border-radius: 6px;
                font-size: var(--font-size-xs);
                font-weight: 600;
                color: var(--color-text-secondary);
                font-family: var(--font-mono);
            }
            
            .agent-actions {
                display: flex;
                gap: 8px;
                align-items: center;
                justify-content: flex-end;
            }
            
            .agent-actions .ops-btn {
                font-size: var(--font-size-xs);
                padding: 0 16px;
                height: 32px;
                min-width: 80px;
                width: 80px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                box-sizing: border-box;
            }
            
            .empty-state {
                text-align: center;
                padding: 60px 20px;
                color: var(--color-text-secondary);
            }
            
            .empty-state-icon {
                font-size: var(--font-size-2xl);
                margin-bottom: 16px;
                opacity: 0.5;
            }
            
            .empty-state-text {
                font-size: var(--font-size-base);
                margin-bottom: 8px;
            }
            
            .empty-state-subtext {
                font-size: var(--font-size-xs);
                color: var(--color-text-tertiary);
            }
            
            /* Table cell padding adjustments */
            .audit-table tbody td {
                padding: 16px;
                vertical-align: middle;
            }
            
            .audit-table thead th {
                padding: 14px 16px;
            }
        </style>
        
        <div class="ops-card">
            <div class="agents-header">
                <div>
                    <h3 style="margin: 0 0 4px 0;">Support Team Agents</h3>
                    <p style="margin: 0; color: var(--color-text-secondary); font-size: var(--font-size-sm);">
                        Manage your support team members and their information
                    </p>
                </div>
                <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
                    <div class="agents-stats">
                        <div class="agent-stat">
                            <span class="agent-stat-label">Total agents</span>
                            <span class="agent-stat-value"><?php echo count($agents); ?></span>
                        </div>
                        <div class="agent-stat">
                            <span class="agent-stat-label">Active</span>
                            <span class="agent-stat-value" style="color: var(--color-success);">
                                <?php echo count(array_filter($agents, function($a) { return $a->is_active; })); ?>
                            </span>
                        </div>
                    </div>
                    <?php if (AccessControl::is_admin() && get_option('ai_audit_fs_api_connected')): ?>
                    <button type="button" class="ops-btn secondary" id="sync-agents-btn" onclick="syncAgentsFromFS()" style="height:36px;white-space:nowrap;">
                        Sync from FluentSupport
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($agents)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No agents found</div>
                    <div class="ops-empty-state-description">Add your first agent using the form below</div>
                </div>
            <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Title</th>
                        <th>Email</th>
                        <th>Fluent ID</th>
                        <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($agents as $a): ?>
                        <tr class="agent-row">
                        <td>
                                <div class="agent-name-cell">
                                    <?php 
                                    $initials = strtoupper(substr($a->first_name, 0, 1) . substr($a->last_name, 0, 1));
                                    if($a->avatar_url): ?>
                                <img src="<?php echo esc_url($a->avatar_url); ?>" 
                                             class="agent-avatar"
                                             alt="<?php echo esc_attr($a->first_name . ' ' . $a->last_name); ?>"
                                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="agent-avatar-fallback" style="display:none;">
                                            <?php echo esc_html($initials); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="agent-avatar-fallback">
                                            <?php echo esc_html($initials); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="agent-name-wrapper">
                                        <span class="agent-name"><?php echo esc_html($a->first_name . ' ' . $a->last_name); ?></span>
                                        <span class="agent-email-small"><?php echo esc_html($a->email); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="agent-title-cell"><?php echo esc_html($a->title ?: '-'); ?></span>
                            </td>
                            <td>
                                <span class="agent-email-cell"><?php echo esc_html($a->email); ?></span>
                            </td>
                            <td>
                                <?php if($a->fluent_agent_id): ?>
                                    <span class="agent-fluent-id">#<?php echo esc_html($a->fluent_agent_id); ?></span>
                                <?php else: ?>
                                    <span style="color: var(--color-text-tertiary);">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $a->is_active ? 'success' : 'failed'; ?>">
                                <?php echo $a->is_active ? 'Active' : 'Inactive'; ?>
                            </span>
                            <?php if (!empty($a->role) && $a->role === 'lead'): ?>
                                <span class="status-badge warning" style="margin-left:4px;">Lead</span>
                            <?php endif; ?>
                        </td>
                            <td style="text-align: right;">
                                <?php if (!AccessControl::is_read_only('agents')): ?>
                                <div class="agent-actions">
                            <button class='ops-btn secondary'
                                    onclick='editAgent(<?php echo htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8'); ?>)'>
                                Edit
                            </button>
                            <a href='?page=ai-ops&section=agents&del_agent=<?php echo $a->id; ?>'
                               class='ops-btn danger'
                                       onclick="return confirm('Are you sure you want to delete this agent? This action cannot be undone.')">
                                Delete
                            </a>
                                </div>
                                <?php else: ?>
                                <span style="color:var(--color-text-tertiary);font-size:var(--font-size-xs);">View only</span>
                                <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php if (AccessControl::is_admin() && get_option('ai_audit_fs_api_connected')): ?>
        <!-- Import modal -->
        <div id="fs-import-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:var(--color-bg);border-radius:var(--radius-lg);max-width:700px;width:95%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="padding:20px 24px;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h3 style="margin:0;font-size:var(--font-size-md);">Import Agents from FluentSupport</h3>
                        <p id="fs-import-subtitle" style="margin:4px 0 0;font-size:var(--font-size-xs);color:var(--color-text-tertiary);">Loading agents...</p>
                    </div>
                    <button onclick="closeImportModal()" style="background:none;border:none;font-size:var(--font-size-xl);cursor:pointer;color:var(--color-text-tertiary);padding:4px 8px;">&times;</button>
                </div>
                <div id="fs-import-body" style="padding:20px 24px;overflow-y:auto;flex:1;">
                    <div style="text-align:center;padding:40px;color:var(--color-text-tertiary);">Loading...</div>
                </div>
                <div id="fs-import-footer" style="display:none;padding:16px 24px;border-top:1px solid var(--color-border);display:flex;gap:12px;align-items:center;">
                    <button class="ops-btn primary" id="fs-do-import" onclick="importSelectedAgents()">Import Selected</button>
                    <button class="ops-btn secondary" onclick="closeImportModal()">Cancel</button>
                    <span id="fs-import-status" style="font-size:var(--font-size-xs);margin-left:auto;"></span>
                </div>
            </div>
        </div>

        <script>
        var fsAgentsData = [];

        function syncAgentsFromFS() {
            document.getElementById('fs-import-overlay').style.display = 'flex';
            document.getElementById('fs-import-body').innerHTML = '<div style="text-align:center;padding:40px;color:var(--color-text-tertiary);">Loading agents from FluentSupport...</div>';
            document.getElementById('fs-import-footer').style.display = 'none';
            document.getElementById('fs-import-subtitle').textContent = 'Loading agents...';

            jQuery.post(ajaxurl, {
                action: 'ai_ops_fetch_fs_agents',
                nonce: '<?php echo wp_create_nonce('ai_ops_nonce'); ?>'
            }, function(res) {
                if (res.success) {
                    fsAgentsData = res.data.agents;
                    renderImportList(res.data.agents, res.data.existing_emails);
                } else {
                    document.getElementById('fs-import-body').innerHTML = '<div style="text-align:center;padding:40px;color:var(--color-error);">' + (res.data || 'Failed to fetch agents') + '</div>';
                }
            }).fail(function() {
                document.getElementById('fs-import-body').innerHTML = '<div style="text-align:center;padding:40px;color:var(--color-error);">Request failed</div>';
            });
        }

        function renderImportList(agents, existingEmails) {
            if (!agents.length) {
                document.getElementById('fs-import-body').innerHTML = '<div style="text-align:center;padding:40px;color:var(--color-text-tertiary);">No agents found in FluentSupport.</div>';
                return;
            }

            var newCount = 0;
            var html = '<div style="margin-bottom:12px;display:flex;gap:12px;align-items:center;">' +
                '<label style="font-size:var(--font-size-sm);cursor:pointer;display:flex;align-items:center;gap:6px;">' +
                '<input type="checkbox" id="fs-select-all" onchange="toggleAllFS(this.checked)" style="width:16px;height:16px;accent-color:var(--color-primary);"> Select all new' +
                '</label></div>';

            html += '<table class="audit-table" style="margin:0;"><thead><tr>' +
                '<th style="width:40px;"></th><th>Name</th><th>Email</th><th>Fluent ID</th><th>Status</th>' +
                '</tr></thead><tbody>';

            for (var i = 0; i < agents.length; i++) {
                var a = agents[i];
                var exists = existingEmails.indexOf(a.email) > -1;
                var name = (a.first_name || '') + ' ' + (a.last_name || '');
                if (!exists) newCount++;

                html += '<tr style="' + (exists ? 'opacity:0.5;' : '') + '">' +
                    '<td><input type="checkbox" class="fs-agent-check" data-idx="' + i + '" ' +
                    (exists ? 'disabled title="Already imported"' : '') +
                    ' style="width:16px;height:16px;accent-color:var(--color-primary);"></td>' +
                    '<td style="font-weight:500;">' + escHtml(name.trim()) + '</td>' +
                    '<td style="font-family:monospace;font-size:var(--font-size-xs);">' + escHtml(a.email || '') + '</td>' +
                    '<td><span style="font-family:monospace;font-size:var(--font-size-xs);">#' + (a.id || '-') + '</span></td>' +
                    '<td>' + (exists ?
                        '<span class="status-badge">Already imported</span>' :
                        '<span class="status-badge success">New</span>') +
                    '</td></tr>';
            }
            html += '</tbody></table>';

            document.getElementById('fs-import-body').innerHTML = html;
            document.getElementById('fs-import-subtitle').textContent = agents.length + ' agents found, ' + newCount + ' new';
            document.getElementById('fs-import-footer').style.display = 'flex';
            document.getElementById('fs-import-status').textContent = '';
            updateImportBtnCount();
        }

        function toggleAllFS(checked) {
            var boxes = document.querySelectorAll('.fs-agent-check:not(:disabled)');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = checked;
            updateImportBtnCount();
        }

        function updateImportBtnCount() {
            var checked = document.querySelectorAll('.fs-agent-check:checked').length;
            document.getElementById('fs-do-import').textContent = 'Import Selected (' + checked + ')';
            document.getElementById('fs-do-import').disabled = checked === 0;
        }

        // Attach change listener for checkboxes
        jQuery(document).on('change', '.fs-agent-check', updateImportBtnCount);

        function importSelectedAgents() {
            var checked = document.querySelectorAll('.fs-agent-check:checked');
            if (!checked.length) return;

            var selected = [];
            for (var i = 0; i < checked.length; i++) {
                selected.push(fsAgentsData[parseInt(checked[i].dataset.idx)]);
            }

            var btn = document.getElementById('fs-do-import');
            btn.disabled = true;
            btn.textContent = 'Importing...';
            document.getElementById('fs-import-status').textContent = '';

            jQuery.post(ajaxurl, {
                action: 'ai_ops_import_fs_agents',
                nonce: '<?php echo wp_create_nonce('ai_ops_nonce'); ?>',
                agents: JSON.stringify(selected)
            }, function(res) {
                if (res.success) {
                    document.getElementById('fs-import-status').innerHTML = '<span style="color:var(--color-success);">' + res.data.message + '</span>';
                    btn.textContent = 'Done!';
                    setTimeout(function() { location.reload(); }, 1200);
                } else {
                    document.getElementById('fs-import-status').innerHTML = '<span style="color:var(--color-error);">' + (res.data || 'Import failed') + '</span>';
                    btn.textContent = 'Import Selected';
                    btn.disabled = false;
                }
            }).fail(function() {
                btn.textContent = 'Import Selected';
                btn.disabled = false;
            });
        }

        function closeImportModal() {
            document.getElementById('fs-import-overlay').style.display = 'none';
        }

        function escHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        </script>
        <?php endif; ?>

        <?php
    }

    private function render_form($agents) {
        ?>
        <style>
            /* Agent Form Styles */
            .agent-form-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 24px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--color-border);
            }
            
            .agent-form-header h3 {
                margin: 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .agent-form-header .form-mode {
                font-size: var(--font-size-xs);
                font-weight: 500;
                color: var(--color-text-secondary);
                padding: 5px 12px;
                background: var(--color-bg-subtle);
                border-radius: var(--radius-pill);
            }
            
            .agent-form {
                margin-top: 0;
            }
            
            .agent-form .form-row {
                margin-bottom: 0;
            }
            
            .agent-form .form-row > div {
                flex: 1;
                min-width: 0;
                margin-bottom: 20px;
            }
            
            .agent-form .form-group {
                margin-bottom: 24px;
            }
            
            .agent-form label {
                display: block;
                font-weight: 500;
                font-size: var(--font-size-sm);
                margin-bottom: 8px;
                color: var(--color-text-secondary);
            }
            
            .agent-form label.required::after {
                content: " *";
                color: var(--color-error);
            }
            
            .agent-form small {
                display: block;
                margin-top: 6px;
                font-size: var(--font-size-xs);
                color: var(--color-text-tertiary);
                line-height: 1.5;
            }
            
            .agent-form .ops-input {
                margin-bottom: 0;
            }
            
            .agent-form input[type="email"],
            .agent-form input[type="number"],
            .agent-form input[type="text"],
            .agent-form input[type="url"] {
                border: 1px solid var(--color-border) !important;
                background: var(--color-bg) !important;
            }
            
            .agent-form input[type="email"]:focus,
            .agent-form input[type="number"]:focus,
            .agent-form input[type="text"]:focus,
            .agent-form input[type="url"]:focus {
                border-color: var(--color-primary) !important;
                box-shadow: 0 0 0 3px var(--color-primary-light) !important;
                outline: none !important;
            }
            
            .agent-form input[type="email"]:hover,
            .agent-form input[type="number"]:hover,
            .agent-form input[type="text"]:hover,
            .agent-form input[type="url"]:hover {
                border-color: var(--color-border-strong) !important;
            }
            
            .agent-checkbox-wrapper {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 16px;
                background: var(--color-bg-subtle);
                border: 1px solid var(--color-border);
                border-radius: var(--radius-sm);
                cursor: pointer;
                transition: all 0.2s ease;
            }
            
            .agent-checkbox-wrapper:hover {
                background: var(--color-bg-hover);
                border-color: var(--color-border-strong);
            }
            
            .agent-checkbox-wrapper input[type="checkbox"] {
                width: 18px;
                height: 18px;
                cursor: pointer;
                accent-color: var(--color-primary);
                margin-top: 2px;
                flex-shrink: 0;
            }
            
            .agent-checkbox-wrapper > label {
                margin: 0;
                cursor: pointer;
                font-weight: 500;
                font-size: var(--font-size-sm);
                color: var(--color-text-primary);
                text-transform: none;
                letter-spacing: 0;
                flex: 1;
            }
            
            .agent-checkbox-wrapper .checkbox-hint {
                font-size: var(--font-size-xs);
                color: var(--color-text-tertiary);
                margin-top: 4px;
                line-height: 1.5;
            }
            
            .agent-form-actions {
                display: flex;
                gap: 12px;
                align-items: center;
                margin-top: 32px;
                padding-top: 24px;
                border-top: 1px solid var(--color-border);
            }
            
            .agent-form-actions .ops-btn {
                padding: 0 16px;
                height: 38px;
            }
            
            .agent-form-actions .ops-btn span {
                opacity: 1;
                font-size: var(--font-size-base);
            }
            
            .form-section {
                margin-bottom: 32px;
            }
            
            .form-section:last-of-type {
                margin-bottom: 0;
            }
            
            .form-section-title {
                font-size: var(--font-size-sm);
                font-weight: 500;
                color: var(--color-text-tertiary);
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .form-section-title::after {
                content: "";
                flex: 1;
                height: 1px;
                background: var(--color-border);
            }
        </style>
        
        <div class="ops-card" id="agent-form-card">
            <div class="agent-form-header">
            <h3 id="form-title">Add New Agent</h3>
                <span class="form-mode" id="form-mode">New Agent</span>
            </div>
            
            <form method="post" class="agent-form">
                <input type="hidden" name="id" id="ag_id">
                
                <div class="form-section">
                    <div class="form-section-title">Basic Information</div>
                <div class="form-row">
                    <div>
                            <label class="required">First Name</label>
                        <input name="fname" id="ag_fn" placeholder="John" class="ops-input" required>
                    </div>
                    <div>
                        <label>Last Name</label>
                        <input name="lname" id="ag_ln" placeholder="Doe" class="ops-input">
                    </div>
                </div>
                
                <div class="form-row">
                    <div>
                            <label class="required">Email</label>
                        <input type="email" name="email" id="ag_em" placeholder="john@example.com" class="ops-input" required>
                            <small>Used for shift assignments and performance tracking</small>
                    </div>
                    <div>
                        <label>Title</label>
                        <input name="title" id="ag_title" placeholder="Senior Support Agent" class="ops-input">
                            <small>Job title or role</small>
                        </div>
                    </div>
                </div>
                
                <?php if (AccessControl::is_admin()): ?>
                <div class="form-section">
                    <div class="form-section-title">Role & Access</div>
                    <div class="form-row">
                        <div>
                            <label>Role</label>
                            <select name="role" id="ag_role" class="ops-input">
                                <option value="agent">Agent</option>
                                <option value="lead">Lead</option>
                            </select>
                            <small>Leads can view their team's audit reports</small>
                        </div>
                        <div>
                            <label>WordPress Account</label>
                            <div id="wp-account-status" style="margin-bottom:8px;">
                                <span id="wp-linked-info" style="display:none;padding:8px 12px;background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:var(--radius-sm);font-size:var(--font-size-xs);color:var(--color-text-secondary);">
                                    Linked to WP User #<span id="wp-linked-id"></span>
                                    <button type="button" onclick="unlinkWpAccount()" style="margin-left:8px;background:none;border:none;color:var(--color-error);cursor:pointer;font-size:var(--font-size-xs);text-decoration:underline;">Unlink</button>
                                </span>
                                <span id="wp-not-linked" style="display:none;padding:8px 12px;background:#fef3c7;border:1px solid #fcd34d;border-radius:var(--radius-sm);font-size:var(--font-size-xs);color:#92400e;">
                                    No WP account — agent cannot log in to the dashboard
                                </span>
                            </div>
                            <input type="hidden" name="wp_user_id" id="ag_wp_user" value="">
                            <input type="hidden" name="create_wp_account" id="ag_create_wp" value="0">
                            <div id="wp-create-section" style="display:none;">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 12px;background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:var(--radius-sm);">
                                    <input type="checkbox" id="ag_create_wp_check" onchange="document.getElementById('ag_create_wp').value = this.checked ? '1' : '0';" style="width:16px;height:16px;accent-color:var(--color-primary);">
                                    <span style="font-size:var(--font-size-sm);font-weight:500;color:var(--color-text-primary);">Create WP account on save</span>
                                </label>
                                <small>Creates a WordPress user with the selected role. Password reset email will be sent to the agent's email.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-section">
                    <div class="form-section-title">Integration & Profile</div>
                <div class="form-row">
                    <div>
                        <label>FluentSupport ID</label>
                        <input type="number" name="fluent_id" id="ag_fid" placeholder="Optional" class="ops-input">
                            <small>Link this agent to their FluentSupport profile</small>
                    </div>
                    <div>
                        <label>Avatar URL</label>
                        <input name="avatar_url" id="ag_avatar" placeholder="https://example.com/avatar.jpg" class="ops-input">
                            <small>Direct URL to agent's profile picture</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <div class="form-section-title">Status</div>
                    <div class="agent-checkbox-wrapper">
                        <input type="checkbox" name="is_active" id="ag_active" checked>
                        <label for="ag_active" style="display: flex; flex-direction: column; gap: 2px;">
                            <span>Active Agent</span>
                            <span class="checkbox-hint">Can be assigned to shifts and will appear in performance reports</span>
                    </label>
                    </div>
                </div>
                
                <div class="agent-form-actions">
                    <button name="save_agent" class="ops-btn primary" type="submit">
                        Save Agent
                    </button>
                    <button type="button" class="ops-btn secondary" onclick="resetAgentForm()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
        
        <script>
        function updateWpStatus(wpUserId) {
            var linked = document.getElementById('wp-linked-info');
            var notLinked = document.getElementById('wp-not-linked');
            var createSection = document.getElementById('wp-create-section');
            if (!linked) return; // admin-only elements
            if (wpUserId && parseInt(wpUserId) > 0) {
                linked.style.display = 'inline-block';
                notLinked.style.display = 'none';
                createSection.style.display = 'none';
                document.getElementById('wp-linked-id').textContent = wpUserId;
            } else {
                linked.style.display = 'none';
                notLinked.style.display = 'inline-block';
                createSection.style.display = 'block';
            }
        }

        function unlinkWpAccount() {
            if (!confirm('Unlink this WP account? The agent will lose dashboard access.')) return;
            document.getElementById('ag_wp_user').value = '';
            updateWpStatus(null);
        }

        function editAgent(a) {
            document.getElementById('ag_id').value = a.id;
            document.getElementById('ag_fn').value = a.first_name || '';
            document.getElementById('ag_ln').value = a.last_name || '';
            document.getElementById('ag_em').value = a.email;
            document.getElementById('ag_title').value = a.title || '';
            var roleEl = document.getElementById('ag_role');
            if (roleEl) roleEl.value = a.role || 'agent';
            document.getElementById('ag_wp_user').value = a.wp_user_id || '';
            document.getElementById('ag_fid').value = a.fluent_agent_id || '';
            document.getElementById('ag_avatar').value = a.avatar_url || '';
            document.getElementById('ag_active').checked = a.is_active == 1;
            document.getElementById('form-title').textContent = 'Edit Agent: ' + a.first_name;
            document.getElementById('form-mode').textContent = 'Editing';
            document.getElementById('form-mode').style.background = 'var(--color-warning-bg)';
            document.getElementById('form-mode').style.color = '#92400e';

            // Reset create checkbox
            var createCheck = document.getElementById('ag_create_wp_check');
            if (createCheck) { createCheck.checked = false; document.getElementById('ag_create_wp').value = '0'; }
            updateWpStatus(a.wp_user_id);

            setTimeout(function() {
                var formCard = document.getElementById('agent-form-card');
                if (formCard) {
                    var offset = 100;
                    var elementPosition = formCard.getBoundingClientRect().top;
                    var offsetPosition = elementPosition + window.pageYOffset - offset;
                    window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                }
            }, 100);
        }

        function resetAgentForm() {
            document.getElementById('ag_id').value = '';
            document.getElementById('ag_fn').value = '';
            document.getElementById('ag_ln').value = '';
            document.getElementById('ag_em').value = '';
            document.getElementById('ag_title').value = '';
            var roleEl = document.getElementById('ag_role');
            if (roleEl) roleEl.value = 'agent';
            document.getElementById('ag_wp_user').value = '';
            document.getElementById('ag_fid').value = '';
            document.getElementById('ag_avatar').value = '';
            document.getElementById('ag_active').checked = true;
            document.getElementById('form-title').textContent = 'Add New Agent';
            document.getElementById('form-mode').textContent = 'New Agent';
            document.getElementById('form-mode').style.background = 'var(--color-bg-subtle)';
            document.getElementById('form-mode').style.color = 'var(--color-text-secondary)';

            var createCheck = document.getElementById('ag_create_wp_check');
            if (createCheck) { createCheck.checked = false; document.getElementById('ag_create_wp').value = '0'; }
            updateWpStatus(null);
        }
        </script>
        <?php
    }
}