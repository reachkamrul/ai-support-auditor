<?php
/**
 * Flagged Tickets Page — Tickets needing manager attention
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

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

        $total = (int) $wpdb->get_var(
            $params
                ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} f WHERE {$where_sql}", ...$params)
                : "SELECT COUNT(*) FROM {$table} f WHERE {$where_sql}"
        );

        $offset = ($page_num - 1) * $this->per_page;
        $total_pages = max(1, ceil($total / $this->per_page));

        $all_params = $params;
        $all_params[] = $this->per_page;
        $all_params[] = $offset;

        $flags = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, a.overall_score, a.overall_sentiment
             FROM {$table} f
             LEFT JOIN {$wpdb->prefix}ais_audits a ON f.audit_id = a.id
             WHERE {$where_sql}
             ORDER BY FIELD(f.status, 'needs_review', 'reviewed', 'dismissed'), f.created_at DESC
             LIMIT %d OFFSET %d",
            ...$all_params
        ));

        // Summary counts
        $count_review  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='needs_review'");
        $count_reviewed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='reviewed'");
        $count_dismissed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='dismissed'");

        ?>
        <!-- Summary Stats -->
        <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);margin-bottom:24px;">
            <div class="stat-card" <?php echo $count_review > 0 ? 'style="border-color:var(--color-error);"' : ''; ?>>
                <div class="stat-label">Needs Review</div>
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
                <p style="color:var(--color-text-tertiary);text-align:center;padding:40px 0;">
                    <?php echo $filter_status === 'needs_review' ? 'No tickets need review. You\'re all caught up!' : 'No flagged tickets match your filters.'; ?>
                </p>
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
                                    <a href="<?php echo admin_url('admin.php?page=ai-ops&section=audits&search=' . $f->ticket_id); ?>" class="ops-btn secondary" style="font-size:11px;height:28px;padding:0 8px;" title="View Audit">View</a>
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
            <div style="display:flex;justify-content:center;gap:4px;margin-top:16px;">
                <?php
                $base = admin_url('admin.php?page=ai-ops&section=flagged&flag_type=' . urlencode($filter_type) . '&flag_status=' . urlencode($filter_status));
                for ($p = 1; $p <= $total_pages; $p++):
                    $active = $p === $page_num ? 'primary' : 'secondary';
                ?>
                    <a href="<?php echo $base . '&pg=' . $p; ?>" class="ops-btn <?php echo $active; ?>" style="height:30px;min-width:30px;padding:0 8px;font-size:12px;"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
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
