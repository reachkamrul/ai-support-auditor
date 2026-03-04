<?php
/**
 * Agents Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class Agents {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function render() {
        global $wpdb;
        
        // Handle agent save
        if(isset($_POST['save_agent'])) {
            $this->save_agent($_POST);
            echo '<div class="notice notice-success is-dismissible"><p>Agent saved successfully.</p></div>';
        }
        
        // Handle delete
        if(isset($_GET['del_agent'])) {
            $agent_id = intval($_GET['del_agent']);
            $wpdb->delete($wpdb->prefix.'ais_agents', ['id'=>$agent_id]);
            echo '<div class="notice notice-success is-dismissible"><p>Agent deleted.</p></div>';
        }
        
        $agents = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ais_agents ORDER BY first_name ASC");
        
        $this->render_list($agents);
        $this->render_form($agents);
    }
    
    private function save_agent($post_data) {
        global $wpdb;
        
        $id = intval($post_data['id']);
        $data = [
            'first_name' => sanitize_text_field($post_data['fname']),
            'last_name' => sanitize_text_field($post_data['lname']),
            'email' => sanitize_email($post_data['email']),
            'title' => sanitize_text_field($post_data['title']),
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
                font-size: 13px;
                font-weight: 500;
                color: var(--color-text-tertiary);
            }
            
            .agent-stat-value {
                font-size: 24px;
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
                font-size: 14px;
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
                font-size: 14px;
                line-height: 1.4;
            }
            
            .agent-email-small {
                font-size: 12px;
                color: var(--color-text-tertiary);
                line-height: 1.3;
            }
            
            .agent-title-cell {
                color: var(--color-text-secondary);
                font-size: 13px;
                line-height: 1.4;
            }
            
            .agent-email-cell {
                font-family: var(--font-mono);
                font-size: 12px;
                color: var(--color-text-secondary);
            }
            
            .agent-fluent-id {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 4px 10px;
                background: var(--color-bg-subtle);
                border-radius: 6px;
                font-size: 11px;
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
                font-size: 12px;
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
                font-size: 48px;
                margin-bottom: 16px;
                opacity: 0.5;
            }
            
            .empty-state-text {
                font-size: 14px;
                margin-bottom: 8px;
            }
            
            .empty-state-subtext {
                font-size: 12px;
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
                    <p style="margin: 0; color: var(--color-text-secondary); font-size: 13px;">
                        Manage your support team members and their information
                    </p>
                </div>
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
                        </td>
                            <td style="text-align: right;">
                                <div class="agent-actions">
                            <button class='ops-btn secondary' 
                                    onclick='editAgent(<?php echo htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8'); ?>)'>
                                Edit
                            </button>
                            <a href='?page=ai-ops&tab=agents&del_agent=<?php echo $a->id; ?>' 
                               class='ops-btn danger' 
                                       onclick="return confirm('⚠️ Are you sure you want to delete this agent? This action cannot be undone.')">
                                Delete
                            </a>
                                </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
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
                font-size: 11px;
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
                font-size: 13px;
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
                font-size: 11px;
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
                font-size: 13px;
                color: var(--color-text-primary);
                text-transform: none;
                letter-spacing: 0;
                flex: 1;
            }
            
            .agent-checkbox-wrapper .checkbox-hint {
                font-size: 11px;
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
                font-size: 14px;
            }
            
            .form-section {
                margin-bottom: 32px;
            }
            
            .form-section:last-of-type {
                margin-bottom: 0;
            }
            
            .form-section-title {
                font-size: 13px;
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
        function editAgent(a) {
            document.getElementById('ag_id').value = a.id;
            document.getElementById('ag_fn').value = a.first_name || '';
            document.getElementById('ag_ln').value = a.last_name || '';
            document.getElementById('ag_em').value = a.email;
            document.getElementById('ag_title').value = a.title || '';
            document.getElementById('ag_fid').value = a.fluent_agent_id || '';
            document.getElementById('ag_avatar').value = a.avatar_url || '';
            document.getElementById('ag_active').checked = a.is_active == 1;
            document.getElementById('form-title').textContent = 'Edit Agent: ' + a.first_name;
            document.getElementById('form-mode').textContent = 'Editing';
            document.getElementById('form-mode').style.background = 'var(--color-warning-bg)';
            document.getElementById('form-mode').style.color = '#92400e';
            
            // Scroll to form with offset for better visibility
            setTimeout(function() {
                var formCard = document.getElementById('agent-form-card');
                if (formCard) {
                    var offset = 100; // Offset from top
                    var elementPosition = formCard.getBoundingClientRect().top;
                    var offsetPosition = elementPosition + window.pageYOffset - offset;
                    
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
            }, 100);
        }
        
        function resetAgentForm() {
            document.getElementById('ag_id').value = '';
            document.getElementById('ag_fn').value = '';
            document.getElementById('ag_ln').value = '';
            document.getElementById('ag_em').value = '';
            document.getElementById('ag_title').value = '';
            document.getElementById('ag_fid').value = '';
            document.getElementById('ag_avatar').value = '';
            document.getElementById('ag_active').checked = true;
            document.getElementById('form-title').textContent = 'Add New Agent';
            document.getElementById('form-mode').textContent = 'New Agent';
            document.getElementById('form-mode').style.background = 'var(--color-bg-subtle)';
            document.getElementById('form-mode').style.color = 'var(--color-text-secondary)';
        }
        </script>
        <?php
    }
}