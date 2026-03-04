<?php
/**
 * Teams & Products Page — Team CRUD, agent assignment, product mapping
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class Teams {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle_post();
        }

        // Handle delete
        if (!empty($_GET['delete_team'])) {
            $this->delete_team(intval($_GET['delete_team']));
        }

        // Fetch data
        $teams = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ais_teams ORDER BY name");
        $agents = $wpdb->get_results("SELECT email, first_name, last_name FROM {$wpdb->prefix}ais_agents WHERE is_active = 1 ORDER BY first_name");
        $products = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fs_products ORDER BY title");

        // Load team members and products for each team
        $team_data = [];
        foreach ($teams as $team) {
            $members = $wpdb->get_col($wpdb->prepare(
                "SELECT agent_email FROM {$wpdb->prefix}ais_team_members WHERE team_id = %d",
                $team->id
            ));
            $prods = $wpdb->get_col($wpdb->prepare(
                "SELECT product_id FROM {$wpdb->prefix}ais_team_products WHERE team_id = %d",
                $team->id
            ));
            $team_data[$team->id] = [
                'team'     => $team,
                'members'  => $members,
                'products' => $prods,
            ];
        }

        // Editing?
        $editing = null;
        if (!empty($_GET['edit_team'])) {
            $edit_id = intval($_GET['edit_team']);
            $editing = $team_data[$edit_id] ?? null;
        }

        ?>
        <!-- Team Cards -->
        <?php if (!empty($teams)): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(350px, 1fr));gap:20px;margin-bottom:24px;">
            <?php foreach ($teams as $team):
                $data = $team_data[$team->id];
                $member_count = count($data['members']);
                $product_count = count($data['products']);
            ?>
            <div class="ops-card" style="border-left:4px solid <?php echo esc_attr($team->color); ?>;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <div>
                        <h3 style="margin:0 0 4px;">
                            <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr($team->color); ?>;margin-right:8px;vertical-align:middle;"></span>
                            <?php echo esc_html($team->name); ?>
                        </h3>
                        <p style="margin:0;font-size:13px;color:var(--color-text-secondary);">
                            <?php echo $member_count; ?> agent<?php echo $member_count !== 1 ? 's' : ''; ?> &middot;
                            <?php echo $product_count; ?> product<?php echo $product_count !== 1 ? 's' : ''; ?>
                        </p>
                    </div>
                    <div style="display:flex;gap:4px;">
                        <a href="?page=ai-ops&section=teams&edit_team=<?php echo $team->id; ?>" class="ops-btn secondary" style="font-size:11px;height:28px;padding:0 8px;">Edit</a>
                        <a href="?page=ai-ops&section=teams&delete_team=<?php echo $team->id; ?>" class="ops-btn danger" style="font-size:11px;height:28px;padding:0 8px;" onclick="return confirm('Delete this team?')">Delete</a>
                    </div>
                </div>

                <?php if ($member_count > 0): ?>
                <div style="margin-top:12px;">
                    <div style="font-size:11px;font-weight:600;color:var(--color-text-tertiary);text-transform:uppercase;margin-bottom:6px;">Agents</div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <?php
                        foreach ($data['members'] as $email) {
                            $agent = null;
                            foreach ($agents as $a) {
                                if ($a->email === $email) { $agent = $a; break; }
                            }
                            $name = $agent ? trim($agent->first_name . ' ' . $agent->last_name) : $email;
                            echo '<span style="display:inline-block;padding:3px 8px;background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:var(--radius-pill);font-size:11px;">' . esc_html($name) . '</span>';
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($product_count > 0): ?>
                <div style="margin-top:10px;">
                    <div style="font-size:11px;font-weight:600;color:var(--color-text-tertiary);text-transform:uppercase;margin-bottom:6px;">Products</div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <?php
                        foreach ($data['products'] as $pid) {
                            $pname = '';
                            foreach ($products as $p) {
                                if ($p->id == $pid) { $pname = $p->title; break; }
                            }
                            echo '<span style="display:inline-block;padding:3px 8px;background:' . esc_attr($team->color) . '22;border:1px solid ' . esc_attr($team->color) . '44;border-radius:var(--radius-pill);font-size:11px;color:var(--color-text-primary);">' . esc_html($pname ?: "Product #$pid") . '</span>';
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="ops-card" style="text-align:center;padding:40px;">
                <p style="color:var(--color-text-tertiary);">No teams created yet. Use the form below to create your first team.</p>
            </div>
        <?php endif; ?>

        <!-- Add / Edit Team Form -->
        <div class="ops-card" id="team-form">
            <h3>
                <?php if ($editing): ?>
                    <span style="background:var(--color-warning-bg);color:#92400e;padding:2px 8px;border-radius:4px;font-size:11px;margin-right:8px;">EDITING</span>
                    <?php echo esc_html($editing['team']->name); ?>
                <?php else: ?>
                    Create New Team
                <?php endif; ?>
            </h3>

            <form method="post">
                <?php wp_nonce_field('teams_save'); ?>
                <input type="hidden" name="team_action" value="save">
                <?php if ($editing): ?>
                    <input type="hidden" name="team_id" value="<?php echo $editing['team']->id; ?>">
                <?php endif; ?>

                <div style="display:grid;grid-template-columns:1fr auto;gap:16px;margin-bottom:16px;">
                    <div class="form-group" style="margin:0;">
                        <label>Team Name</label>
                        <input type="text" name="team_name" class="ops-input" required
                               value="<?php echo $editing ? esc_attr($editing['team']->name) : ''; ?>"
                               placeholder="e.g. Team A">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Color</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="color" name="team_color"
                                   value="<?php echo $editing ? esc_attr($editing['team']->color) : '#3b82f6'; ?>"
                                   style="width:38px;height:38px;border:1px solid var(--color-border);border-radius:var(--radius-sm);cursor:pointer;">
                        </div>
                    </div>
                </div>

                <!-- Agent Assignment -->
                <div class="form-group">
                    <label>Assign Agents</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;padding:12px;background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:var(--radius-sm);min-height:44px;">
                        <?php
                        $selected_members = $editing ? $editing['members'] : [];
                        foreach ($agents as $a):
                            $checked = in_array($a->email, $selected_members) ? 'checked' : '';
                            $name = trim($a->first_name . ' ' . $a->last_name) ?: $a->email;
                        ?>
                            <label style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:var(--color-bg);border:1px solid var(--color-border);border-radius:var(--radius-pill);font-size:12px;cursor:pointer;transition:all 0.15s;">
                                <input type="checkbox" name="team_members[]" value="<?php echo esc_attr($a->email); ?>" <?php echo $checked; ?> style="margin:0;">
                                <?php echo esc_html($name); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Product Mapping -->
                <div class="form-group">
                    <label>Map Products</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;padding:12px;background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:var(--radius-sm);min-height:44px;">
                        <?php
                        $selected_products = $editing ? $editing['products'] : [];
                        foreach ($products as $p):
                            $checked = in_array($p->id, $selected_products) ? 'checked' : '';
                        ?>
                            <label style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:var(--color-bg);border:1px solid var(--color-border);border-radius:var(--radius-pill);font-size:12px;cursor:pointer;transition:all 0.15s;">
                                <input type="checkbox" name="team_products[]" value="<?php echo intval($p->id); ?>" <?php echo $checked; ?> style="margin:0;">
                                <?php echo esc_html($p->title); ?>
                            </label>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?>
                            <span style="color:var(--color-text-tertiary);font-size:12px;">No FluentSupport products found</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display:flex;gap:8px;margin-top:16px;">
                    <button type="submit" class="ops-btn primary"><?php echo $editing ? 'Update Team' : 'Create Team'; ?></button>
                    <?php if ($editing): ?>
                        <a href="?page=ai-ops&section=teams" class="ops-btn secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
    }

    private function handle_post() {
        global $wpdb;

        if (empty($_POST['team_action']) || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'teams_save')) {
            return;
        }

        $team_id    = intval($_POST['team_id'] ?? 0);
        $team_name  = sanitize_text_field($_POST['team_name'] ?? '');
        $team_color = sanitize_hex_color($_POST['team_color'] ?? '#3b82f6') ?: '#3b82f6';
        $members    = array_map('sanitize_email', $_POST['team_members'] ?? []);
        $products   = array_map('intval', $_POST['team_products'] ?? []);

        if (empty($team_name)) return;

        $table_teams    = $wpdb->prefix . 'ais_teams';
        $table_members  = $wpdb->prefix . 'ais_team_members';
        $table_products = $wpdb->prefix . 'ais_team_products';

        if ($team_id) {
            // Update existing team
            $wpdb->update($table_teams, [
                'name'  => $team_name,
                'color' => $team_color,
            ], ['id' => $team_id]);
        } else {
            // Create new team
            $wpdb->insert($table_teams, [
                'name'       => $team_name,
                'color'      => $team_color,
                'created_at' => current_time('mysql'),
            ]);
            $team_id = $wpdb->insert_id;
        }

        if (!$team_id) return;

        // Sync members
        $wpdb->delete($table_members, ['team_id' => $team_id]);
        foreach ($members as $email) {
            if ($email) {
                $wpdb->insert($table_members, [
                    'team_id'     => $team_id,
                    'agent_email' => $email,
                    'created_at'  => current_time('mysql'),
                ]);
            }
        }

        // Sync products
        $wpdb->delete($table_products, ['team_id' => $team_id]);
        foreach ($products as $pid) {
            if ($pid > 0) {
                $wpdb->insert($table_products, [
                    'team_id'    => $team_id,
                    'product_id' => $pid,
                    'created_at' => current_time('mysql'),
                ]);
            }
        }

        // Redirect to clean URL
        wp_redirect(admin_url('admin.php?page=ai-ops&section=teams'));
        exit;
    }

    private function delete_team($team_id) {
        global $wpdb;

        if (!$team_id || !current_user_can('manage_options')) return;

        $wpdb->delete($wpdb->prefix . 'ais_team_members',  ['team_id' => $team_id]);
        $wpdb->delete($wpdb->prefix . 'ais_team_products', ['team_id' => $team_id]);
        $wpdb->delete($wpdb->prefix . 'ais_teams',         ['id' => $team_id]);

        wp_redirect(admin_url('admin.php?page=ai-ops&section=teams'));
        exit;
    }
}
