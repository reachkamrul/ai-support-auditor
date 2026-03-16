<?php
/**
 * Database Reset Page
 *
 * Only accessible when AUDIT_RESET is defined as 'TRUE' in wp-config.php
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class Reset {

    private $database;

    /**
     * All plugin tables (without prefix)
     */
    private $tables = [
        'ais_audits',
        'ais_agents',
        'ais_agent_shifts',
        'ais_shift_definitions',
        'ais_agent_evaluations',
        'ais_agent_contributions',
        'ais_problem_contexts',
        'ais_topic_stats',
        'ais_doc_central_meta',
        'ais_flagged_tickets',
        'ais_teams',
        'ais_team_members',
        'ais_team_products',
        'ais_handoff_events',
        'ais_holidays',
        'ais_holiday_duty',
        'ais_agent_leaves',
        'ais_calendar_extras',
        'ais_audit_reviews',
        'ais_score_overrides',
        'ais_override_requests',
        'ais_audit_appeals',
    ];

    /**
     * Plugin options to clean up
     */
    private $options = [
        'ai_audit_secret_token',
        'ai_audit_system_message',
        'ai_audit_n8n_url',
        'ai_audit_fs_api_url',
        'ai_audit_fs_api_user',
        'ai_audit_fs_api_pass',
        'ai_audit_fs_api_connected',
        'ai_audit_db_version',
    ];

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    /**
     * Check if reset mode is enabled
     */
    public static function is_enabled() {
        return defined('AUDIT_RESET') && AUDIT_RESET === 'TRUE';
    }

    public function render() {
        if (!self::is_enabled()) {
            echo '<div class="ops-card"><p>Reset mode is not enabled.</p></div>';
            return;
        }

        if (!current_user_can('manage_options')) {
            echo '<div class="ops-card"><p>Only administrators can reset the database.</p></div>';
            return;
        }

        // Handle reset actions
        $this->handle_actions();

        global $wpdb;
        $prefix = $wpdb->prefix;
        ?>

        <div class="ops-card" style="border-left:4px solid #ef4444;">
            <h3 style="color:#ef4444;">Database Reset</h3>
            <p style="margin:0 0 20px;color:var(--color-text-secondary);font-size:var(--font-size-sm);">
                This will permanently delete all plugin data. This action cannot be undone.
                Remove <code>define('AUDIT_RESET', 'TRUE');</code> from wp-config.php when done.
            </p>

            <div style="padding:20px;border-radius:var(--radius-md);background:var(--color-bg-subtle);border:1px solid var(--color-border);margin-bottom:20px;">
                <h4 style="margin:0 0 12px;font-size:var(--font-size-base);">Tables to be dropped</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;">
                    <?php foreach ($this->tables as $table):
                        $full = $prefix . $table;
                        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full}'");
                    ?>
                        <div style="font-size:var(--font-size-xs);font-family:monospace;padding:4px 8px;border-radius:4px;background:<?php echo $exists ? '#fef2f2' : '#f0fdf4'; ?>;color:<?php echo $exists ? '#991b1b' : '#166534'; ?>;">
                            <?php echo esc_html($table); ?>
                            <span style="float:right;"><?php echo $exists ? 'exists' : 'missing'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="padding:20px;border-radius:var(--radius-md);background:var(--color-bg-subtle);border:1px solid var(--color-border);margin-bottom:20px;">
                <h4 style="margin:0 0 12px;font-size:var(--font-size-base);">Options to be deleted</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:6px;">
                    <?php foreach ($this->options as $opt):
                        $val = get_option($opt, '__NOT_SET__');
                        $has = ($val !== '__NOT_SET__');
                    ?>
                        <div style="font-size:var(--font-size-xs);font-family:monospace;padding:4px 8px;border-radius:4px;background:<?php echo $has ? '#fef2f2' : '#f0fdf4'; ?>;color:<?php echo $has ? '#991b1b' : '#166534'; ?>;">
                            <?php echo esc_html($opt); ?>
                            <span style="float:right;"><?php echo $has ? 'set' : 'empty'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <form method="post" onsubmit="return confirm('DROP ALL TABLES and DELETE ALL OPTIONS?\n\nThis will permanently erase ALL plugin data.\n\nType RESET in the next prompt to confirm.');">
                    <?php wp_nonce_field('audit_reset_all'); ?>
                    <input type="hidden" name="reset_action" value="drop_all">
                    <button type="submit" class="ops-btn danger">Drop All Tables & Delete Options</button>
                </form>

                <form method="post" onsubmit="return confirm('Drop all tables only? Options (API keys, tokens) will be kept.');">
                    <?php wp_nonce_field('audit_reset_all'); ?>
                    <input type="hidden" name="reset_action" value="drop_tables">
                    <button type="submit" class="ops-btn danger" style="background:#f97316;">Drop Tables Only</button>
                </form>

                <form method="post" onsubmit="return confirm('Rebuild all tables from scratch? Existing tables will be dropped first.');">
                    <?php wp_nonce_field('audit_reset_all'); ?>
                    <input type="hidden" name="reset_action" value="rebuild">
                    <button type="submit" class="ops-btn primary">Drop & Rebuild Tables</button>
                </form>
            </div>
        </div>

        <?php
    }

    private function handle_actions() {
        if (empty($_POST['reset_action'])) {
            return;
        }

        check_admin_referer('audit_reset_all');

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field($_POST['reset_action']);

        switch ($action) {
            case 'drop_all':
                $this->drop_all_tables();
                $this->delete_all_options();
                $this->remove_custom_roles();
                echo '<div class="notice notice-success is-dismissible"><p><strong>All tables dropped, options deleted, and custom roles removed.</strong></p></div>';
                break;

            case 'drop_tables':
                $this->drop_all_tables();
                echo '<div class="notice notice-success is-dismissible"><p><strong>All plugin tables dropped. Options preserved.</strong></p></div>';
                break;

            case 'rebuild':
                $this->drop_all_tables();
                $this->database->setup();
                echo '<div class="notice notice-success is-dismissible"><p><strong>All tables dropped and rebuilt from schema.</strong></p></div>';
                break;
        }
    }

    private function drop_all_tables() {
        global $wpdb;
        $prefix = $wpdb->prefix;

        foreach ($this->tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
        }
    }

    private function delete_all_options() {
        foreach ($this->options as $opt) {
            delete_option($opt);
        }
    }

    private function remove_custom_roles() {
        remove_role('support_lead');
        remove_role('support_agent');

        // Remove custom caps from admin role
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->remove_cap('view_team_audits');
            $admin_role->remove_cap('view_own_audits');
        }
    }
}
