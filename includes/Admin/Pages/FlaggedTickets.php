<?php
/**
 * Flagged Tickets Page — Tickets needing manager attention
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class FlaggedTickets {

    private $database;
    private $per_page = 25;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_flagged_tickets';

        // Handle actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['flagged_action'])) {
            $this->handle_action();
        }

        // Filters
        $filter_type   = isset($_GET['flag_type']) ? sanitize_text_field($_GET['flag_type']) : '';
        $filter_status = isset($_GET['flag_status']) ? sanitize_text_field($_GET['flag_status']) : 'needs_review';
        $page_num      = max(1, intval($_GET['pg'] ?? 1));

        // Team filtering
        $team_join = '';
        $team_where = '';
        $team_emails = AccessControl::get_team_agent_emails();
        if (!empty($team_emails)) {
            $escaped = implode(',', array_map(function ($e) use ($wpdb) {
                return $wpdb->prepare('%s', $e);
            }, $team_emails));
            $team_join = " INNER JOIN {$wpdb->prefix}ais_agent_evaluations ae_team ON a.ticket_id = ae_team.ticket_id";
            $team_where = " AND ae_team.agent_email IN ({$escaped})";
        }

        // Build query
        $where = ['1=1'];
        $params = [];

        if ($filter_type) {
            $where[] = 'f.flag_type = %s';
            $params[] = $filter_type;
        }
        if ($filter_status) {
            $where[] = 'f.status = %s';
            $params[] = $filter_status;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(DISTINCT f.id) FROM {$table} f
            LEFT JOIN {$wpdb->prefix}ais_audits a ON f.audit_id = a.id
            {$team_join}
            WHERE {$where_sql}{$team_where}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
            : $wpdb->get_var($count_sql));

        $offset = ($page_num - 1) * $this->per_page;
        $total_pages = max(1, ceil($total / $this->per_page));

        $all_params = $params;
        $all_params[] = $this->per_page;
        $all_params[] = $offset;

        $flags = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT f.*, a.overall_score, a.overall_sentiment
             FROM {$table} f
             LEFT JOIN {$wpdb->prefix}ais_audits a ON f.audit_id = a.id
             {$team_join}
             WHERE {$where_sql}{$team_where}
             ORDER BY FIELD(f.status, 'needs_review', 'reviewed', 'dismissed'), f.created_at DESC
             LIMIT %d OFFSET %d",
            ...$all_params
        ));

        // Summary counts (team-filtered)
        if (!empty($team_emails)) {
            $count_base = "SELECT COUNT(DISTINCT f.id) FROM {$table} f
                LEFT JOIN {$wpdb->prefix}ais_audits a ON f.audit_id = a.id
                {$team_join}
                WHERE f.status = %s{$team_where}";
            $count_review   = (int) $wpdb->get_var($wpdb->prepare($count_base, 'needs_review'));
            $count_reviewed = (int) $wpdb->get_var($wpdb->prepare($count_base, 'reviewed'));
            $count_dismissed = (int) $wpdb->get_var($wpdb->prepare($count_base, 'dismissed'));
        } else {
            $count_review  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='needs_review'");
            $count_reviewed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='reviewed'");
            $count_dismissed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='dismissed'");
        }

        ?>
        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card <?php echo $count_review > 0 ? 'ops-card--accent-error' : ''; ?>">
                <div class="stat-label">Needs review</div>
                <div class="stat-value" style="<?php echo $count_review > 0 ? 'color:var(--color-error);' : ''; ?>"><?php echo $count_review; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Reviewed</div>
                <div class="stat-value"><?php echo $count_reviewed; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Dismissed</div>
                <div class="stat-value" style="color:var(--color-text-tertiary);"><?php echo $count_dismissed; ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="ops-card" style="padding:16px;">
            <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="page" value="ai-ops">
                <input type="hidden" name="section" value="flagged">
                <div class="audit-filter-group">
                    <label>Flag Type</label>
                    <select name="flag_type" class="ops-input" style="width:160px;">
                        <option value="">All Types</option>
                        <option value="low_score" <?php selected($filter_type, 'low_score'); ?>>Low Score</option>
                        <option value="problem_context" <?php selected($filter_type, 'problem_context'); ?>>Problem Context</option>
                        <option value="long_delay" <?php selected($filter_type, 'long_delay'); ?>>Long Delay</option>
                    </select>
                </div>
                <div class="audit-filter-group">
                    <label>Status</label>
                    <select name="flag_status" class="ops-input" style="width:160px;">
                        <option value="">All</option>
                        <option value="needs_review" <?php selected($filter_status, 'needs_review'); ?>>Needs Review</option>
                        <option value="reviewed" <?php selected($filter_status, 'reviewed'); ?>>Reviewed</option>
                        <option value="dismissed" <?php selected($filter_status, 'dismissed'); ?>>Dismissed</option>
                    </select>
                </div>
                <button type="submit" class="ops-btn primary">Filter</button>
                <a href="<?php echo admin_url('admin.php?page=ai-ops&section=flagged'); ?>" class="ops-btn secondary">Reset</a>
            </form>
        </div>

        <!-- Flagged Tickets Table -->
        <div class="ops-card" style="padding:0;overflow:hidden;">
            <?php if (empty($flags)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title"><?php echo $filter_status === 'needs_review' ? 'All caught up' : 'No results'; ?></div>
                    <div class="ops-empty-state-description"><?php echo $filter_status === 'needs_review' ? 'No tickets need review right now.' : 'No flagged tickets match your filters.'; ?></div>
                </div>
            <?php else: ?>
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Flag</th>
                            <th>Details</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($flags as $f): ?>
                        <?php $details = json_decode($f->flag_details, true) ?: []; ?>
                        <tr>
                            <td><strong>#<?php echo esc_html($f->ticket_id); ?></strong></td>
                            <td><?php echo $this->flag_badge($f->flag_type); ?></td>
                            <td style="font-size:12px;color:var(--color-text-secondary);max-width:250px;">
                                <?php echo esc_html($this->format_details($f->flag_type, $details)); ?>
                            </td>
                            <td>
                                <span class="col-score <?php echo Dashboard::score_class(intval($f->overall_score)); ?>">
                                    <?php echo intval($f->overall_score); ?>
                                </span>
                            </td>
                            <td><?php echo $this->status_badge($f->status); ?></td>
                            <td style="color:var(--color-text-tertiary);font-size:12px;"><?php echo date('M j, H:i', strtotime($f->created_at)); ?></td>
                            <td>
                                <div style="display:flex;gap:4px;">
                                    <a href="<?php echo admin_url('admin.php?page=ai-ops&section=audits&audit_search=' . $f->ticket_id); ?>" class="ops-btn secondary" style="font-size:11px;height:28px;padding:0 8px;" title="View Audit">View</a>
                                    <?php if ($f->status === 'needs_review'): ?>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field('flagged_action_' . $f->id); ?>
                                            <input type="hidden" name="flagged_action" value="review">
                                            <input type="hidden" name="flag_id" value="<?php echo $f->id; ?>">
                                            <button type="submit" class="ops-btn primary" style="font-size:11px;height:28px;padding:0 8px;">Reviewed</button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field('flagged_action_' . $f->id); ?>
                                            <input type="hidden" name="flagged_action" value="dismiss">
                                            <input type="hidden" name="flag_id" value="<?php echo $f->id; ?>">
                                            <button type="submit" class="ops-btn secondary" style="font-size:11px;height:28px;padding:0 8px;">Dismiss</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php if (!empty($f->reviewer_notes)): ?>
                            <tr><td colspan="7" style="padding:4px 16px 12px;font-size:12px;color:var(--color-text-secondary);background:var(--color-bg-subtle);">
                                <strong>Note:</strong> <?php echo esc_html($f->reviewer_notes); ?>
                            </td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="ops-pagination" style="justify-content:center;">
                <?php
                $base = admin_url('admin.php?page=ai-ops&section=flagged&flag_type=' . urlencode($filter_type) . '&flag_status=' . urlencode($filter_status));
                for ($p = 1; $p <= $total_pages; $p++):
                    $active = $p === $page_num ? 'primary' : 'secondary';
                ?>
                    <a href="<?php echo $base . '&pg=' . $p; ?>" class="ops-btn <?php echo $active; ?>" style="height:32px;min-width:32px;padding:0 10px;"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <!-- Pending Agent Appeals -->
        <?php $this->render_pending_appeals(); ?>
        <?php
    }

    private function render_pending_appeals() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audit_appeals';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

        $team_filter = AccessControl::sql_agent_filter('a.agent_email');
        $appeals = $wpdb->get_results(
            "SELECT a.*, ae.agent_name, ae.overall_agent_score, ae.timing_score, ae.resolution_score, ae.communication_score
             FROM {$table} a
             LEFT JOIN {$wpdb->prefix}ais_agent_evaluations ae ON a.eval_id = ae.id
             WHERE a.status = 'pending' {$team_filter}
             ORDER BY a.created_at ASC"
        );

        if (empty($appeals)) return;
        ?>
        <div class="ops-card" style="margin-top:24px;">
            <h3 style="font-size:16px;font-weight:600;margin:0 0 16px;">
                Pending Agent Appeals
                <span class="ops-nav-badge" style="margin-left:8px;"><?php echo count($appeals); ?></span>
            </h3>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Ticket</th>
                        <th>Disputed</th>
                        <th>Current Score</th>
                        <th>Reason</th>
                        <th>Date</th>
                        <th style="width:200px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appeals as $ap): ?>
                    <tr id="appeal-row-<?php echo intval($ap->id); ?>">
                        <td><strong><?php echo esc_html($ap->agent_name ?: $ap->agent_email); ?></strong></td>
                        <td><a href="<?php echo esc_url(admin_url('admin.php?page=fluent-support#/tickets/' . intval($ap->ticket_id))); ?>" target="_blank">#<?php echo esc_html($ap->ticket_id); ?></a></td>
                        <td style="font-size:12px;"><?php echo esc_html($ap->disputed_field ? str_replace('_', ' ', $ap->disputed_field) : 'General'); ?></td>
                        <td style="text-align:center;"><?php echo $ap->current_score !== null ? intval($ap->current_score) : '-'; ?></td>
                        <td style="font-size:12px;color:var(--color-text-secondary);max-width:250px;"><?php echo esc_html(substr($ap->reason, 0, 150)); ?></td>
                        <td style="font-size:12px;color:var(--color-text-secondary);"><?php echo date('M j', strtotime($ap->created_at)); ?></td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <input type="text" id="appeal-note-<?php echo intval($ap->id); ?>" placeholder="Notes..." style="height:28px;border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:0 8px;font-size:11px;flex:1;">
                                <button class="ops-btn primary" style="height:28px;font-size:11px;padding:0 10px;" onclick="resolveAppeal(<?php echo intval($ap->id); ?>, 'approved')">Approve</button>
                                <button class="ops-btn secondary" style="height:28px;font-size:11px;padding:0 10px;" onclick="resolveAppeal(<?php echo intval($ap->id); ?>, 'rejected')">Reject</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        function resolveAppeal(id, status) {
            var notes = document.getElementById('appeal-note-' + id).value;
            jQuery.post(ajaxurl, {
                action: 'ai_audit_resolve_appeal',
                _ajax_nonce: '<?php echo wp_create_nonce('ais_appeal_nonce'); ?>',
                appeal_id: id,
                status: status,
                resolution_notes: notes
            }, function(res) {
                if (res.success) {
                    var row = document.getElementById('appeal-row-' + id);
                    row.style.background = status === 'approved' ? '#dcfce7' : '#fee2e2';
                    row.querySelector('td:last-child').innerHTML = '<span class="status-badge ' + (status === 'approved' ? 'success' : 'failed') + '">' + status + '</span>';
                } else {
                    alert(res.data || 'Error');
                }
            });
        }
        </script>
        <?php
    }

    private function handle_action() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_flagged_tickets';

        $flag_id = intval($_POST['flag_id'] ?? 0);
        $action  = sanitize_text_field($_POST['flagged_action']);

        if (!$flag_id || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'flagged_action_' . $flag_id)) {
            return;
        }

        if ($action === 'review') {
            $wpdb->update($table, [
                'status'      => 'reviewed',
                'reviewed_at' => current_time('mysql'),
            ], ['id' => $flag_id]);
        } elseif ($action === 'dismiss') {
            $wpdb->update($table, [
                'status'      => 'dismissed',
                'reviewed_at' => current_time('mysql'),
            ], ['id' => $flag_id]);
        }
    }

    private function flag_badge($type) {
        $map = [
            'low_score'       => ['Low Score',   'failed'],
            'problem_context' => ['Problem',     'warning'],
            'long_delay'      => ['Long Delay',  'pending'],
        ];
        $info = $map[$type] ?? ['Unknown', 'pending'];
        return '<span class="status-badge ' . $info[1] . '">' . $info[0] . '</span>';
    }

    private function status_badge($status) {
        $map = [
            'needs_review' => ['Needs Review', 'failed'],
            'reviewed'     => ['Reviewed',     'success'],
            'dismissed'    => ['Dismissed',    'pending'],
        ];
        $info = $map[$status] ?? ['Unknown', 'pending'];
        return '<span class="status-badge ' . $info[1] . '">' . $info[0] . '</span>';
    }

    private function format_details($type, $details) {
        switch ($type) {
            case 'low_score':
                return 'Score ' . ($details['score'] ?? '?') . ' below threshold ' . ($details['threshold'] ?? '40');
            case 'problem_context':
                return ($details['severity'] ?? '') . ': ' . ($details['category'] ?? '') . ' — ' . substr($details['description'] ?? '', 0, 80);
            case 'long_delay':
                return 'Agent ' . ($details['agent_name'] ?? '') . ' timing: ' . ($details['timing_score'] ?? '?');
            default:
                return json_encode($details);
        }
    }
}
