<?php
/**
 * All Audits Page (formerly Dashboard)
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class AllAudits {

    private $database;
    private $per_page = 20;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    /**
     * Unified score color logic — used across all pages
     */
    public static function score_class($score) {
        if ($score < 0) return 'score-negative';
        if ($score < 40) return 'score-negative';
        if ($score < 60) return 'score-warning';
        if ($score < 80) return 'score-ok';
        return 'score-good';
    }

    public function render() {
        nocache_headers();
        global $wpdb;

        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $this->per_page;

        // Server-side filters
        $filter_status = isset($_GET['audit_status']) ? sanitize_text_field($_GET['audit_status']) : '';
        $filter_search = isset($_GET['audit_search']) ? sanitize_text_field($_GET['audit_search']) : (isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '');
        $filter_team = AccessControl::get_selected_team_id();
        $filter_review = isset($_GET['review_status']) ? sanitize_text_field($_GET['review_status']) : '';

        // Team filtering — build ticket_id subquery
        $team_join = '';
        $team_where = '';
        if ($filter_team > 0) {
            $team_join = " INNER JOIN {$wpdb->prefix}ais_agent_evaluations ae_team ON a.ticket_id = ae_team.ticket_id
                           INNER JOIN {$wpdb->prefix}ais_team_members tm_team ON ae_team.agent_email = tm_team.agent_email";
            $team_where = $wpdb->prepare(" AND tm_team.team_id = %d", $filter_team);
        } elseif (AccessControl::is_lead()) {
            $team_emails = AccessControl::get_team_agent_emails();
            if (!empty($team_emails)) {
                $escaped = implode(',', array_map(function ($e) use ($wpdb) {
                    return $wpdb->prepare('%s', $e);
                }, $team_emails));
                $team_join = " INNER JOIN {$wpdb->prefix}ais_agent_evaluations ae_team ON a.ticket_id = ae_team.ticket_id";
                $team_where = " AND ae_team.agent_email IN ({$escaped})";
            }
        }

        // Count query
        $where = "WHERE 1=1";
        $params = [];
        if ($filter_status) {
            $where .= " AND a.status = %s";
            $params[] = $filter_status;
        }
        if ($filter_search) {
            $where .= " AND (a.ticket_id LIKE %s OR a.audit_response LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($filter_search) . '%';
            $params[] = '%' . $wpdb->esc_like($filter_search) . '%';
        }

        // Review filter
        $review_join = " LEFT JOIN {$wpdb->prefix}ais_audit_reviews ar ON a.id = ar.audit_id";
        $review_where = '';
        if ($filter_review === 'reviewed') {
            $review_where = " AND ar.id IS NOT NULL";
        } elseif ($filter_review === 'unreviewed') {
            $review_where = " AND ar.id IS NULL";
        }

        $count_sql = "SELECT COUNT(DISTINCT a.ticket_id) FROM {$wpdb->prefix}ais_audits a
            INNER JOIN (
                SELECT ticket_id, MAX(id) as max_id
                FROM {$wpdb->prefix}ais_audits GROUP BY ticket_id
            ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
            {$team_join}{$review_join}
            {$where}{$team_where}{$review_where}";

        $total = $params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql);
        $total_pages = max(1, ceil($total / $this->per_page));

        // Data query — include review data
        $data_sql = "SELECT DISTINCT a.*, ar.reviewer_email, ar.review_status as reviewed_status,
                        ar.reviewed_at, ar_agent.first_name as reviewer_name
                     FROM {$wpdb->prefix}ais_audits a
            INNER JOIN (
                SELECT ticket_id, MAX(id) as max_id
                FROM {$wpdb->prefix}ais_audits GROUP BY ticket_id
            ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
            {$team_join}{$review_join}
            LEFT JOIN {$wpdb->prefix}ais_agents ar_agent ON ar.reviewer_email = ar_agent.email
            {$where}{$team_where}{$review_where}
            ORDER BY a.created_at DESC
            LIMIT %d OFFSET %d";
        $data_params = array_merge($params, [$this->per_page, $offset]);
        $results = $wpdb->get_results($wpdb->prepare($data_sql, $data_params));

        $this->render_styles();
        ?>

        <div class="ops-card" style="padding:0; overflow:hidden;">
            <div class="audits-filters-wrapper">
                <form class="audit-filters" method="get">
                    <input type="hidden" name="page" value="ai-ops">
                    <input type="hidden" name="section" value="audits">
                    <div class="audit-filter-group wide">
                        <label>Search Ticket</label>
                        <input type="text" name="audit_search" class="ops-input" placeholder="Ticket ID or Summary..." value="<?php echo esc_attr($filter_search); ?>">
                    </div>
                    <div class="audit-filter-group narrow">
                        <label>Status</label>
                        <select name="audit_status" class="ops-input">
                            <option value="">All</option>
                            <option value="pending" <?php selected($filter_status, 'pending'); ?>>Pending</option>
                            <option value="success" <?php selected($filter_status, 'success'); ?>>Success</option>
                            <option value="failed" <?php selected($filter_status, 'failed'); ?>>Failed</option>
                        </select>
                    </div>
                    <div class="audit-filter-group narrow">
                        <label>Reviewed</label>
                        <select name="review_status" class="ops-input">
                            <option value="">All</option>
                            <option value="unreviewed" <?php selected($filter_review, 'unreviewed'); ?>>Unreviewed</option>
                            <option value="reviewed" <?php selected($filter_review, 'reviewed'); ?>>Reviewed</option>
                        </select>
                    </div>
                    <?php $all_teams = AccessControl::get_all_teams(); if (!empty($all_teams)): ?>
                    <div class="audit-filter-group narrow">
                        <label>Team</label>
                        <select name="filter_team" class="ops-input" <?php echo AccessControl::is_lead() ? 'disabled' : ''; ?>>
                            <option value="0">All teams</option>
                            <?php foreach ($all_teams as $team): ?>
                                <option value="<?php echo intval($team->id); ?>" <?php selected($filter_team, intval($team->id)); ?>><?php echo esc_html($team->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (AccessControl::is_lead()): ?>
                            <input type="hidden" name="filter_team" value="<?php echo $filter_team; ?>">
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="audit-filter-group" style="justify-content:flex-end;">
                        <label>&nbsp;</label>
                        <button type="submit" class="ops-btn primary">Filter</button>
                    </div>
                </form>
            </div>

            <?php if (AccessControl::is_admin()): ?>
            <div id="bulk-actions-bar" style="display:none;padding:10px 20px;background:var(--color-bg-subtle);border-bottom:1px solid var(--color-border);align-items:center;gap:12px;">
                <span id="selected-count" style="font-size:13px;color:var(--color-text-secondary);">0 selected</span>
                <button id="btn-bulk-delete" class="ops-btn" style="background:#dc3545;border-color:#dc3545;color:#fff;height:28px;font-size:11px;padding:0 12px;">Delete Selected</button>
            </div>
            <?php endif; ?>

            <div class="audit-table-wrapper">
            <table class="audit-table">
                <thead>
                    <tr>
                        <?php if (AccessControl::is_admin()): ?>
                        <th width="30"><input type="checkbox" id="select-all-audits" title="Select all"></th>
                        <?php endif; ?>
                        <th width="80">ID</th>
                        <th width="100">Status</th>
                        <th width="80">Score</th>
                        <th width="110">Reviewed</th>
                        <th>Summary</th>
                        <th width="180" style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody id="audit-rows">
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="<?php echo AccessControl::is_admin() ? 8 : 7; ?>" class="empty-audits">
                                <p class="empty-audits-text">No audits found. Audits will appear here once tickets are processed.</p>
                            </td>
                        </tr>
                    <?php else:
                        foreach ($results as $row) {
                            $this->render_row($row);
                        }
                    endif; ?>
                </tbody>
            </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="audit-pagination">
                <span class="pagination-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total; ?> audits)</span>
                <div class="pagination-links">
                    <?php
                    $base_url = admin_url('admin.php?page=ai-ops&section=audits');
                    if ($filter_status) $base_url .= '&audit_status=' . urlencode($filter_status);
                    if ($filter_search) $base_url .= '&audit_search=' . urlencode($filter_search);
                    if ($filter_team) $base_url .= '&filter_team=' . intval($filter_team);
                    if ($filter_review) $base_url .= '&review_status=' . urlencode($filter_review);

                    if ($page > 1): ?>
                        <a href="<?php echo esc_url($base_url . '&paged=' . ($page - 1)); ?>" class="ops-btn secondary" style="height:32px;font-size:12px;padding:0 12px;">&laquo; Prev</a>
                    <?php endif;

                    // Show page numbers
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="ops-btn primary" style="height:32px;font-size:12px;padding:0 12px;cursor:default;"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo esc_url($base_url . '&paged=' . $i); ?>" class="ops-btn secondary" style="height:32px;font-size:12px;padding:0 12px;"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor;

                    if ($page < $total_pages): ?>
                        <a href="<?php echo esc_url($base_url . '&paged=' . ($page + 1)); ?>" class="ops-btn secondary" style="height:32px;font-size:12px;padding:0 12px;">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div id="audit-modal" class="audit-modal">
            <div class="audit-modal-content">
                <div class="audit-modal-header">
                    <h2 id="modal-title">Audit Details</h2>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <a id="btn-view-ticket" href="#" target="_blank" class="ops-btn secondary" style="height:28px;font-size:11px;padding:0 10px;text-decoration:none;display:none;">View Ticket &nearr;</a>
                        <button id="btn-toggle-json" class="ops-btn secondary" style="height:28px;font-size:11px;padding:0 10px;">Raw JSON</button>
                        <span class="close-modal">&times;</span>
                    </div>
                </div>
                <div id="modal-body" class="modal-body-parsed"></div>
            </div>
        </div>

        <?php $this->render_scripts(); ?>
        <?php
    }

    private function render_styles() {
        ?>
        <style>
            .audits-filters-wrapper {
                padding: 20px;
                background: var(--color-bg);
                border-bottom: 1px solid var(--color-border);
                border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            }
            .audit-filters {
                display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end;
            }
            .audit-filter-group {
                display: flex; flex-direction: column; gap: 8px;
            }
            .audit-filter-group label {
                font-size: 13px; font-weight: 500; color: var(--color-text-tertiary);
            }
            .audit-filter-group.wide { flex: 2; min-width: 250px; }
            .audit-filter-group.narrow { flex: 1; min-width: 150px; }
            .audit-table-wrapper { overflow-x: auto; }
            .audit-table { margin: 0; }
            .col-summary {
                color: var(--color-text-secondary); font-size: 13px; line-height: 1.5; max-width: 500px;
            }
            .btn-view, .btn-force {
                font-size: 12px; padding: 0 12px; height: 32px; margin-left: 6px;
            }
            .btn-view { min-width: 70px; }
            .btn-force { min-width: 90px; }
            .btn-view:disabled, .btn-force:disabled { opacity: 0.6; cursor: not-allowed; }
            .btn-force:disabled { background: var(--color-text-tertiary); border-color: var(--color-text-tertiary); }
            .empty-audits { text-align: center; padding: 48px 24px; color: var(--color-text-secondary); }
            .empty-audits-text { font-size: var(--font-size-base); margin: 0; color: var(--color-text-tertiary); }

            /* Pagination */
            .audit-pagination {
                display: flex; align-items: center; justify-content: space-between;
                padding: 16px 20px; border-top: 1px solid var(--color-border);
            }
            .pagination-info { font-size: 13px; color: var(--color-text-tertiary); }
            .pagination-links { display: flex; gap: 2px; }

            /* Modal — inherits global .audit-modal styles, page-specific overrides */
            .audit-modal-content {
                max-width: 880px;
            }
            .audit-modal-header {
                padding: 20px 24px; border-bottom: 1px solid var(--color-border);
                display: flex; align-items: center; justify-content: space-between;
                background: var(--color-bg-subtle); flex-shrink: 0;
                position: sticky; top: 0; z-index: 1;
            }
            .audit-modal-header h2 { margin: 0; font-size: 18px; font-weight: 600; }
            .close-modal {
                cursor: pointer; font-size: 24px; font-weight: 300; line-height: 1;
                color: var(--color-text-tertiary); width: 32px; height: 32px;
                display: flex; align-items: center; justify-content: center;
                border-radius: var(--radius-sm); background: transparent;
            }
            .close-modal:hover { color: var(--color-text-primary); background: var(--color-bg-hover); }

            /* Parsed modal body */
            .modal-body-parsed {
                padding: 24px; overflow-y: auto; max-height: calc(90vh - 80px);
            }
            .modal-body-parsed::-webkit-scrollbar { width: 8px; }
            .modal-body-parsed::-webkit-scrollbar-track { background: var(--color-bg); }
            .modal-body-parsed::-webkit-scrollbar-thumb { background: var(--color-border-strong); border-radius: 4px; }

            /* Parsed audit sections */
            .ar-section { margin-bottom: 24px; }
            .ar-section:last-child { margin-bottom: 0; }
            .ar-section + .ar-section { padding-top: 24px; border-top: 1px solid var(--color-border); }
            .ar-section-title {
                font-size: 13px; font-weight: 600; color: var(--color-text-secondary);
                margin-bottom: 12px;
                padding-bottom: 8px; border-bottom: 1px solid var(--color-border);
            }
            .ar-score-grid {
                display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px;
            }
            .ar-score-card {
                background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                border-radius: var(--radius-md); padding: 16px; text-align: center;
            }
            .ar-score-label { font-size: 11px; font-weight: 500; color: var(--color-text-tertiary); margin-bottom: 6px; }
            .ar-score-value { font-size: 28px; font-weight: 700; line-height: 1.2; }
            .ar-score-value.score-good { color: var(--color-success); }
            .ar-score-value.score-ok { color: var(--color-info); }
            .ar-score-value.score-warning { color: var(--color-warning); }
            .ar-score-value.score-negative { color: var(--color-error); }
            .ar-summary-text { font-size: 14px; line-height: 1.7; color: var(--color-text-primary); }
            .ar-agent-card {
                background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px;
            }
            .ar-agent-header {
                display: flex; align-items: center; justify-content: space-between;
                margin-bottom: 12px;
            }
            .ar-agent-name { font-size: 15px; font-weight: 700; color: var(--color-text-primary); }
            .ar-agent-email { font-size: 12px; color: var(--color-text-tertiary); }
            .ar-agent-scores {
                display: flex; gap: 16px; flex-wrap: wrap;
            }
            .ar-agent-score-item {
                display: flex; flex-direction: column; align-items: center; gap: 2px;
            }
            .ar-agent-score-item .label { font-size: 10px; font-weight: 500; color: var(--color-text-tertiary); }
            .ar-agent-score-item .value { font-size: 18px; font-weight: 700; }
            .ar-mini-table { width: 100%; font-size: 13px; border-collapse: collapse; }
            .ar-mini-table th {
                text-align: left; font-size: 11px; font-weight: 500; color: var(--color-text-tertiary);
                padding: 6px 8px; border-bottom: 1px solid var(--color-border);
            }
            .ar-mini-table td { padding: 6px 8px; border-bottom: 1px solid var(--color-border); color: var(--color-text-primary); }
            .ar-badge {
                display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill);
                font-size: 11px; font-weight: 600;
            }
            .ar-badge.critical { background: var(--color-error-bg); color: #991b1b; }
            .ar-badge.high { background: #fef3c7; color: #92400e; }
            .ar-badge.medium { background: var(--color-info-bg); color: #1e40af; }
            .ar-badge.low { background: var(--color-bg-subtle); color: var(--color-text-secondary); }
            .ar-contrib-bar {
                height: 8px; border-radius: 4px; background: var(--color-border);
                overflow: hidden; margin-top: 4px;
            }
            .ar-contrib-fill {
                height: 100%; border-radius: 4px; background: var(--color-primary);
            }
            .ar-tags { display: flex; gap: 6px; flex-wrap: wrap; }
            .ar-tag {
                font-size: 11px; padding: 3px 10px; border-radius: var(--radius-pill);
                background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                color: var(--color-text-secondary);
            }

            /* JSON fallback view */
            .json-viewer {
                background: var(--color-bg-subtle); padding: 24px; font-family: var(--font-mono);
                font-size: 12px; white-space: pre-wrap; word-wrap: break-word;
                max-height: calc(90vh - 80px); overflow-y: auto; color: var(--color-text-primary); line-height: 1.6;
            }

            /* Review badge in table */
            .review-badge {
                display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill);
                font-size: 11px; font-weight: 600;
            }
            .review-badge.reviewed {
                background: var(--color-success-bg); color: #065f46;
            }

            /* Review panel in modal */
            .ar-review-panel {
                margin-top: 24px; padding: 20px; background: var(--color-bg);
                border: 2px solid var(--color-primary); border-radius: var(--radius-md);
            }
            .ar-review-panel-title {
                font-size: 14px; font-weight: 700; color: var(--color-primary); margin-bottom: 16px;
                display: flex; align-items: center; gap: 8px;
            }
            .ar-review-section {
                margin-bottom: 16px; padding: 12px; background: var(--color-bg-subtle);
                border-radius: var(--radius-sm); border: 1px solid var(--color-border);
            }
            .ar-review-section-label {
                font-size: 12px; font-weight: 600; color: var(--color-text-secondary); margin-bottom: 8px;
            }
            .ar-review-btns {
                display: flex; gap: 8px; margin-bottom: 8px;
            }
            .ar-review-btns .rbtn {
                padding: 4px 14px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 600;
                cursor: pointer; border: 1px solid var(--color-border); background: var(--color-bg);
                color: var(--color-text-secondary); transition: all 0.15s;
            }
            .ar-review-btns .rbtn.active-agree {
                background: var(--color-success-bg); border-color: var(--color-success); color: #065f46;
            }
            .ar-review-btns .rbtn.active-disagree {
                background: var(--color-error-bg); border-color: var(--color-error); color: #991b1b;
            }
            .ar-review-btns .rbtn:hover { opacity: 0.8; }
            .ar-review-note {
                width: 100%; padding: 8px 10px; font-size: 12px; border: 1px solid var(--color-border);
                border-radius: var(--radius-sm); background: var(--color-bg); color: var(--color-text-primary);
                resize: vertical; min-height: 36px; font-family: inherit;
            }
            .ar-review-submit-row {
                display: flex; gap: 12px; align-items: center; margin-top: 16px;
            }
            .ar-review-saved {
                font-size: 12px; color: var(--color-success); font-weight: 600; display: none;
            }

            /* Score override panel */
            .ar-override-panel {
                margin-top: 10px; padding: 12px; background: #fffbeb; border: 1px solid #f59e0b;
                border-radius: var(--radius-sm);
            }
            .ar-override-title {
                font-size: 12px; font-weight: 700; color: #92400e; margin-bottom: 10px;
            }
            .ar-override-row {
                display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
            }
            .ar-override-row label {
                font-size: 12px; font-weight: 500; min-width: 120px; color: var(--color-text-secondary);
            }
            .ar-override-row input[type="number"] {
                width: 70px; padding: 4px 8px; font-size: 13px; border: 1px solid var(--color-border);
                border-radius: var(--radius-sm); text-align: center;
            }
            .ar-override-row .old-val {
                font-size: 11px; color: var(--color-text-tertiary);
            }
            .ar-override-reason {
                width: 100%; padding: 6px 10px; font-size: 12px; border: 1px solid var(--color-border);
                border-radius: var(--radius-sm); margin-top: 4px; font-family: inherit;
            }
            .ar-override-trail {
                margin-top: 10px; font-size: 11px; color: var(--color-text-tertiary);
            }
            .ar-override-trail-item {
                padding: 4px 0; border-bottom: 1px solid var(--color-border);
            }
            .ar-override-trail-item:last-child { border-bottom: none; }

            /* Lead request panel */
            .ar-request-panel {
                margin-top: 10px; padding: 12px; background: #eff6ff; border: 1px solid #3b82f6;
                border-radius: var(--radius-sm);
            }
            .ar-request-panel .ar-override-title {
                color: #1e40af;
            }

            /* Shift compliance stats */
            .ar-shift-stat {
                display: flex; flex-direction: column; align-items: center;
                padding: 6px 14px; background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                border-radius: var(--radius-sm); min-width: 80px;
            }
            .ar-shift-stat-val { font-size: 16px; font-weight: 700; color: var(--color-text-primary); }
            .ar-shift-stat-label { font-size: 10px; color: var(--color-text-tertiary); margin-top: 2px; }

            /* Shift data notes */
            .ar-shift-note {
                font-size: 12px; color: var(--color-text-secondary); padding: 6px 12px;
                background: #fffbeb; border: 1px solid #fcd34d; border-radius: var(--radius-sm);
            }

            /* Response timeline toggle */
            .ar-section-toggle { cursor: pointer; user-select: none; }
            .ar-toggle-arrow { font-size: 10px; color: var(--color-text-tertiary); display: inline-block; transition: transform 0.2s; }
            .ar-toggle-arrow.open { transform: rotate(180deg); }
        </style>
        <?php
    }

    private function get_pending_message($row) {
        $now = current_time('timestamp');
        $audit_type = $row->audit_type ?? 'full';
        $created = strtotime($row->created_at);

        // N8N batch runs every 5 minutes for all audit types
        $interval = 5 * 60;
        $next_run = $created + $interval;
        while ($next_run < $now) {
            $next_run += $interval;
        }
        $next_time = date('H:i', $next_run);

        $type_label = 'Full';
        if ($audit_type === 'incremental') $type_label = 'Incremental';
        elseif ($audit_type === 'final') $type_label = 'Final';

        return sprintf('In queue — %s audit, next run ~%s', $type_label, $next_time);
    }

    private function render_row($row) {
        $j = !empty($row->audit_response) ? $row->audit_response : $row->raw_json;

        if (empty($j) || $j === 'null') {
            $j = json_encode(['status' => 'pending', 'message' => $this->get_pending_message($row)]);
        }

        $d = json_decode($j, true);

        if ($row->status == 'failed') {
            $sum = $row->error_message ?: 'Audit failed';
        } elseif ($row->status == 'pending') {
            $sum = $this->get_pending_message($row);
        } elseif (!empty($d['audit_summary']['executive_summary'])) {
            $sum = $d['audit_summary']['executive_summary'];
        } elseif (!empty($d['summary'])) {
            $sum = $d['summary'];
        } else {
            $sum = 'Processed';
        }

        if (is_array($sum)) {
            $sum = !empty($sum) ? (string)reset($sum) : 'Processed';
        }
        $sum = (string)$sum;

        $score_display = "-";
        $score_class = "";
        if ($row->overall_score !== null && $row->status === 'success') {
            $score_display = $row->overall_score;
            $score_class = self::score_class(intval($row->overall_score));
        }

        $sentiment_badge = '';
        if (!empty($row->overall_sentiment)) {
            $s = strtolower($row->overall_sentiment);
            $badge_class = $s === 'positive' ? 'success' : ($s === 'negative' ? 'failed' : 'warning');
            $sentiment_badge = " <span class='status-badge {$badge_class}' style='font-size:9px;padding:2px 6px;min-width:auto;'>" . esc_html($row->overall_sentiment) . "</span>";
        }

        // Review badge
        $review_badge = '<span style="color:var(--color-text-tertiary);font-size:12px;">—</span>';
        if (!empty($row->reviewer_name)) {
            $review_badge = '<span class="review-badge reviewed" title="' . esc_attr($row->reviewed_status) . '">' . esc_html($row->reviewer_name) . '</span>';
        } elseif (!empty($row->reviewer_email)) {
            $short = explode('@', $row->reviewer_email)[0];
            $review_badge = '<span class="review-badge reviewed">' . esc_html($short) . '</span>';
        }

        $audit_id = intval($row->id);

        $checkbox = '';
        if (AccessControl::is_admin()) {
            $checkbox = "<td><input type='checkbox' class='audit-select' value='{$audit_id}' data-ticket='{$row->ticket_id}'></td>";
        }

        echo "<tr id='row-{$row->ticket_id}'>
            {$checkbox}
            <td style='font-weight:600;color:var(--color-text-primary);'>#{$row->ticket_id}</td>
            <td><span class='status-badge {$row->status}'>" . ucfirst($row->status) . "</span></td>
            <td style='text-align:center;'><span class='col-score {$score_class}'>{$score_display}</span>{$sentiment_badge}</td>
            <td style='text-align:center;'>{$review_badge}</td>
            <td class='col-summary'>" . esc_html(substr($sum, 0, 100)) . "...<textarea id='json-{$row->ticket_id}' class='json-storage' style='display:none'>" . esc_textarea($j) . "</textarea></td>
            <td style='text-align:right;padding-right:16px;'>
                <button class='ops-btn secondary btn-view' data-id='{$row->ticket_id}' data-audit-id='{$audit_id}'>View</button>
                <button class='ops-btn primary btn-force' data-id='{$row->ticket_id}'>Re-Audit</button>
            </td>
        </tr>";
    }

    private function render_scripts() {
        $can_override = AccessControl::can_override_scores() ? 'true' : 'false';
        $is_lead = AccessControl::is_lead() ? 'true' : 'false';
        $is_admin = AccessControl::is_admin() ? 'true' : 'false';
        ?>
        <script>
        jQuery(document).ready(function($){
            window.auditDataStore = window.auditDataStore || {};
            var showingJson = false;
            var canOverride = <?php echo $can_override; ?>;
            var canReview = <?php echo $is_lead; ?>;
            var isAdmin = <?php echo $is_admin; ?>;
            var currentAuditId = 0;
            var currentTicketId = '';

            // ---- Score color helper (matches PHP logic) ----
            function scoreClass(s) {
                s = parseInt(s);
                if (s < 0) return 'score-negative';
                if (s < 40) return 'score-negative';
                if (s < 60) return 'score-warning';
                if (s < 80) return 'score-ok';
                return 'score-good';
            }

            // ---- Build parsed HTML from audit JSON ----
            function buildParsedView(data, ticketId) {
                var h = '';
                var a = data.audit_summary || {};

                // Score cards row
                h += '<div class="ar-section"><div class="ar-score-grid">';
                h += scoreCard('Overall Score', a.overall_score, true);
                h += scoreCard('Sentiment', a.overall_sentiment, false);
                h += '</div></div>';

                // Executive summary
                if (a.executive_summary) {
                    h += '<div class="ar-section"><div class="ar-section-title">Executive Summary</div>';
                    h += '<div class="ar-summary-text">' + escHtml(a.executive_summary) + '</div></div>';
                }

                // Shift data notes
                var shiftNotes = (a.shift_data_notes || data.audit_summary && data.audit_summary.shift_data_notes) || [];
                if (shiftNotes.length > 0) {
                    h += '<div class="ar-section"><div style="display:flex;flex-wrap:wrap;gap:8px;">';
                    shiftNotes.forEach(function(sn) {
                        var icon = sn.status === 'no_shift_data' ? '&#9888;' : '&#9989;';
                        h += '<div class="ar-shift-note">' + icon + ' <strong>' + escHtml(sn.agent_name || sn.agent || '') + ':</strong> ' + escHtml(sn.note || '') + '</div>';
                    });
                    h += '</div></div>';
                }

                // Agent evaluations
                var evals = data.agent_evaluations || [];
                if (evals.length > 0) {
                    h += '<div class="ar-section"><div class="ar-section-title">Agent Evaluations (' + evals.length + ')</div>';
                    evals.forEach(function(ev) {
                        h += buildAgentCard(ev);
                    });
                    h += '</div>';
                }

                // Agent contributions
                var contribs = data.agent_contributions || [];
                if (contribs.length > 0) {
                    h += '<div class="ar-section"><div class="ar-section-title">Agent Contributions</div>';
                    contribs.forEach(function(c) {
                        var pct = parseInt(c.contribution_percentage || c.percentage || 0);
                        h += '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">';
                        h += '<span style="font-weight:600;font-size:13px;min-width:180px;">' + escHtml(c.agent_email || '') + '</span>';
                        h += '<div class="ar-contrib-bar" style="flex:1;"><div class="ar-contrib-fill" style="width:' + pct + '%;"></div></div>';
                        h += '<span style="font-weight:700;font-size:13px;min-width:40px;text-align:right;">' + pct + '%</span>';
                        if (c.reply_count) h += '<span style="font-size:11px;color:var(--color-text-tertiary);">(' + c.reply_count + ' replies)</span>';
                        h += '</div>';
                    });
                    h += '</div>';
                }

                // Problem contexts
                var problems = data.problem_contexts || [];
                if (problems.length > 0) {
                    h += '<div class="ar-section"><div class="ar-section-title">Problems Found (' + problems.length + ')</div>';
                    h += '<table class="ar-mini-table"><thead><tr><th>Issue</th><th>Category</th><th>Severity</th></tr></thead><tbody>';
                    problems.forEach(function(p, idx) {
                        var sev = (p.severity || 'low').toLowerCase();
                        var agents = p.handling_agents || [];
                        var hasDetails = agents.length > 0;
                        h += '<tr' + (hasDetails ? ' style="cursor:pointer;" onclick="jQuery(\'#prob-detail-' + idx + '\').slideToggle(200)" title="Click for details"' : '') + '>';
                        h += '<td>' + escHtml(p.issue_description || '') + (hasDetails ? ' <span style="color:var(--color-text-tertiary);font-size:11px;">&#9660;</span>' : '') + '</td>';
                        h += '<td>' + escHtml(p.category || '') + '</td>';
                        h += '<td><span class="ar-badge ' + sev + '">' + escHtml(p.severity || '') + '</span></td></tr>';
                        if (hasDetails) {
                            h += '<tr id="prob-detail-' + idx + '" style="display:none;"><td colspan="3" style="padding:8px 12px;background:var(--color-bg-subtle);font-size:12px;">';
                            agents.forEach(function(a) {
                                h += '<div style="padding:6px 10px;background:var(--color-bg);border:1px solid var(--color-border);border-radius:6px;margin-bottom:6px;">';
                                h += '<strong>' + escHtml(a.agent_id || '') + '</strong>';
                                if (a.marking !== undefined) h += ' <span style="margin-left:4px;" class="' + scoreClass(parseInt(a.marking)) + '">(marking: ' + a.marking + ')</span>';
                                if (a.reasoning) h += '<div style="color:var(--color-text-secondary);font-style:italic;margin-top:4px;">' + escHtml(a.reasoning) + '</div>';
                                h += '</div>';
                            });
                            h += '</td></tr>';
                        }
                    });
                    h += '</tbody></table></div>';
                }

                // Knowledge base analytics
                var cats = data.problem_categories || a.problem_categories || [];
                var gaps = data.documentation_gaps || a.documentation_gaps || [];
                var faqs = data.recommended_faq || a.recommended_faq || [];
                if (cats.length || gaps.length || faqs.length) {
                    h += '<div class="ar-section"><div class="ar-section-title">Knowledge Base Analytics</div>';
                    if (cats.length) {
                        h += '<div style="margin-bottom:12px;"><strong style="font-size:12px;color:var(--color-text-secondary);">Categories:</strong><div class="ar-tags" style="margin-top:6px;">';
                        cats.forEach(function(c) { h += '<span class="ar-tag">' + escHtml(c) + '</span>'; });
                        h += '</div></div>';
                    }
                    if (gaps.length) {
                        h += '<div style="margin-bottom:12px;"><strong style="font-size:12px;color:var(--color-text-secondary);">Documentation Gaps:</strong><ul style="margin:6px 0 0 16px;font-size:13px;color:var(--color-text-primary);">';
                        gaps.forEach(function(g) { h += '<li>' + escHtml(g) + '</li>'; });
                        h += '</ul></div>';
                    }
                    if (faqs.length) {
                        h += '<div><strong style="font-size:12px;color:var(--color-text-secondary);">Recommended FAQ:</strong><ul style="margin:6px 0 0 16px;font-size:13px;color:var(--color-text-primary);">';
                        faqs.forEach(function(f) { h += '<li>' + escHtml(f) + '</li>'; });
                        h += '</ul></div>';
                    }
                    h += '</div>';
                }

                // Lead: Review notes + Mark as Reviewed
                if (canReview && currentAuditId) {
                    h += '<div id="review-panel" style="margin-top:16px;">';
                    h += '<textarea class="ar-review-note" id="review-general-notes" placeholder="Review notes (optional)..." style="min-height:60px;margin-bottom:10px;"></textarea>';
                    h += '<div style="display:flex;align-items:center;gap:12px;">';
                    h += '<button class="ops-btn primary" onclick="markAsReviewed()" id="btn-mark-reviewed" style="padding:8px 24px;">Mark as Reviewed</button>';
                    h += '<span class="ar-review-saved" id="review-saved-msg" style="display:none;">Marked as reviewed!</span>';
                    h += '<span id="reviewed-by-info" style="font-size:12px;color:var(--color-text-tertiary);"></span>';
                    h += '</div>';
                    h += '</div>';
                }

                // Admin: read-only review summary (loaded via AJAX)
                if (isAdmin && currentAuditId) {
                    h += '<div id="admin-review-summary" style="display:none;"></div>';
                }

                return h;
            }

            function scoreCard(label, value, isNumeric) {
                var cls = '';
                if (isNumeric && value !== undefined && value !== null) {
                    cls = scoreClass(value);
                } else if (!isNumeric && value) {
                    var v = value.toLowerCase();
                    cls = v === 'positive' ? 'score-good' : (v === 'negative' ? 'score-negative' : 'score-warning');
                }
                var display = (value !== undefined && value !== null) ? value : '-';
                return '<div class="ar-score-card"><div class="ar-score-label">' + escHtml(label) + '</div><div class="ar-score-value ' + cls + '">' + escHtml(String(display)) + '</div></div>';
            }

            function buildAgentCard(ev) {
                var h = '<div class="ar-agent-card">';
                h += '<div class="ar-agent-header"><div><span class="ar-agent-name">' + escHtml(ev.agent_name || 'Unknown') + '</span>';
                h += '<br><span class="ar-agent-email">' + escHtml(ev.agent_email || '') + '</span></div>';
                h += '<div style="font-size:11px;color:var(--color-text-tertiary);">' + (ev.reply_count || 0) + ' replies &middot; ' + (ev.contribution_percentage || 0) + '% contribution</div></div>';

                // Sub-scores
                h += '<div class="ar-agent-scores">';
                h += agentScoreItem('Timing', ev.timing_score);
                h += agentScoreItem('Resolution', ev.resolution_score);
                h += agentScoreItem('Communication', ev.communication_score);
                h += agentScoreItem('Overall', ev.overall_agent_score);
                h += '</div>';

                // Key achievements
                var achievements = ev.key_achievements || [];
                if (achievements.length) {
                    h += '<div style="margin-top:12px;"><strong style="font-size:11px;color:var(--color-text-secondary);">Achievements:</strong><ul style="margin:4px 0 0 16px;font-size:13px;">';
                    achievements.forEach(function(a) { h += '<li style="color:var(--color-success);">' + escHtml(a) + '</li>'; });
                    h += '</ul></div>';
                }

                // Areas for improvement
                var improvements = ev.areas_for_improvement || [];
                if (improvements.length) {
                    h += '<div style="margin-top:8px;"><strong style="font-size:11px;color:var(--color-text-secondary);">Areas for Improvement:</strong><ul style="margin:4px 0 0 16px;font-size:13px;">';
                    improvements.forEach(function(a) { h += '<li style="color:var(--color-warning);">' + escHtml(a) + '</li>'; });
                    h += '</ul></div>';
                }

                // Reasoning
                if (ev.reasoning) {
                    h += '<div style="margin-top:8px;font-size:12px;color:var(--color-text-tertiary);font-style:italic;">' + escHtml(ev.reasoning) + '</div>';
                }

                // Shift Compliance summary
                var sc = ev.shift_compliance;
                if (sc) {
                    h += '<div style="margin-top:12px;display:flex;gap:16px;flex-wrap:wrap;">';
                    h += '<div class="ar-shift-stat"><span class="ar-shift-stat-val">' + (sc.on_shift_responses || 0) + '</span><span class="ar-shift-stat-label">On-shift replies</span></div>';
                    h += '<div class="ar-shift-stat"><span class="ar-shift-stat-val">' + (sc.off_shift_responses || 0) + '</span><span class="ar-shift-stat-label">Off-shift replies</span></div>';
                    h += '<div class="ar-shift-stat"><span class="ar-shift-stat-val ' + ((sc.delays_while_on_shift || 0) > 0 ? 'score-negative' : '') + '">' + (sc.delays_while_on_shift || 0) + '</span><span class="ar-shift-stat-label">On-shift delays</span></div>';
                    h += '<div class="ar-shift-stat"><span class="ar-shift-stat-val">' + (sc.delays_while_off_shift || 0) + '</span><span class="ar-shift-stat-label">Off-shift delays</span></div>';
                    h += '</div>';
                }

                // Response Breakdown (collapsible)
                var rb = ev.response_breakdown || [];
                if (rb.length > 0) {
                    h += '<div style="margin-top:12px;">';
                    h += '<div class="ar-section-toggle" onclick="jQuery(this).next().slideToggle(200);jQuery(this).find(\'.ar-toggle-arrow\').toggleClass(\'open\');">';
                    h += '<strong style="font-size:11px;color:var(--color-text-secondary);cursor:pointer;">Response Timeline (' + rb.length + ')</strong>';
                    h += ' <span class="ar-toggle-arrow">&#9660;</span></div>';
                    h += '<div class="ar-response-timeline" style="display:none;margin-top:8px;">';
                    h += '<table class="ar-mini-table" style="font-size:12px;"><thead><tr>';
                    h += '<th>#</th><th>Time</th><th>Since Prev</th><th>Shift</th><th>Quality</th><th>Resolution</th><th>Note</th>';
                    h += '</tr></thead><tbody>';
                    rb.forEach(function(r) {
                        var ts = r.timestamp || '';
                        // Format timestamp to readable
                        try { var d = new Date(ts); ts = d.toLocaleString('en-GB', {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}); } catch(e) {}
                        var qCls = r.quality_score > 0 ? 'score-good' : (r.quality_score < 0 ? 'score-negative' : '');
                        var qPrefix = r.quality_score > 0 ? '+' : '';
                        var shiftIcon = r.was_on_shift ? '<span style="color:var(--color-success);">On</span>' : '<span style="color:var(--color-text-tertiary);">Off</span>';
                        var resIcon = r.moved_toward_resolution ? '<span style="color:var(--color-success);">Yes</span>' : '<span style="color:var(--color-text-tertiary);">No</span>';
                        h += '<tr>';
                        h += '<td style="font-weight:600;">' + (r.response_number || '') + '</td>';
                        h += '<td style="white-space:nowrap;">' + ts + '</td>';
                        h += '<td>' + escHtml(r.time_since_previous || '') + '</td>';
                        h += '<td>' + shiftIcon + '</td>';
                        h += '<td class="' + qCls + '" style="font-weight:600;">' + qPrefix + (r.quality_score || 0) + '</td>';
                        h += '<td>' + resIcon + '</td>';
                        h += '<td style="font-size:11px;color:var(--color-text-secondary);max-width:250px;">' + escHtml(r.brief_note || '') + '</td>';
                        h += '</tr>';
                    });
                    h += '</tbody></table></div></div>';
                }

                // Score override panel (admin/can_override only)
                if (canOverride && currentAuditId && ev.agent_email) {
                    var email = ev.agent_email;
                    var safeEmail = email.replace(/[^a-zA-Z0-9]/g, '_');
                    h += '<div class="ar-override-panel" id="override-' + safeEmail + '">';
                    h += '<div class="ar-override-title">Override Scores</div>';

                    var fields = [
                        {name: 'timing_score', label: 'Timing', val: parseInt(ev.timing_score || 0)},
                        {name: 'resolution_score', label: 'Resolution', val: parseInt(ev.resolution_score || 0)},
                        {name: 'communication_score', label: 'Communication', val: parseInt(ev.communication_score || 0)}
                    ];
                    fields.forEach(function(f) {
                        h += '<div class="ar-override-row">';
                        h += '<label>' + f.label + ':</label>';
                        h += '<input type="number" id="ov-' + safeEmail + '-' + f.name + '" value="' + f.val + '" min="-200" max="100">';
                        h += '<span class="old-val">(AI: ' + f.val + ')</span>';
                        h += '<button class="ops-btn secondary" style="height:26px;font-size:11px;padding:0 10px;" onclick="saveOverride(\'' + escHtml(email) + '\',\'' + f.name + '\',\'' + safeEmail + '\',' + f.val + ')">Save</button>';
                        h += '</div>';
                    });
                    h += '<input type="text" class="ar-override-reason" id="ov-reason-' + safeEmail + '" placeholder="Reason for override...">';

                    // Override trail placeholder — filled by fetchReviewData
                    h += '<div class="ar-override-trail" id="ov-trail-' + safeEmail + '"></div>';

                    // Pending override requests (admin view) — filled by fetchOverrideRequests
                    h += '<div class="ar-pending-requests" id="pending-req-' + safeEmail + '"></div>';
                    h += '</div>';
                }

                // Lead: Request Score Review (if lead but NOT canOverride)
                if (canReview && !canOverride && currentAuditId && ev.agent_email) {
                    var email = ev.agent_email;
                    var safeEmail = email.replace(/[^a-zA-Z0-9]/g, '_');
                    h += '<div class="ar-request-panel" id="req-panel-' + safeEmail + '">';
                    h += '<div class="ar-override-title" style="cursor:pointer;" onclick="toggleRequestForm(\'' + safeEmail + '\')">';
                    h += 'Request Score Review <span style="font-size:11px;color:var(--color-text-tertiary);">&#9660;</span></div>';
                    h += '<div class="ar-request-form" id="req-form-' + safeEmail + '" style="display:none;">';
                    h += '<div class="ar-override-row"><label>Field:</label>';
                    h += '<select id="req-field-' + safeEmail + '" class="ops-input" style="width:160px;height:28px;font-size:12px;" onchange="updateReqCurrentVal(\'' + safeEmail + '\',' + JSON.stringify({
                        timing_score: parseInt(ev.timing_score || 0),
                        resolution_score: parseInt(ev.resolution_score || 0),
                        communication_score: parseInt(ev.communication_score || 0)
                    }) + ')">';
                    h += '<option value="timing_score">Timing (' + parseInt(ev.timing_score || 0) + ')</option>';
                    h += '<option value="resolution_score">Resolution (' + parseInt(ev.resolution_score || 0) + ')</option>';
                    h += '<option value="communication_score">Communication (' + parseInt(ev.communication_score || 0) + ')</option>';
                    h += '</select></div>';
                    h += '<div class="ar-override-row"><label>Suggest:</label>';
                    h += '<input type="number" id="req-val-' + safeEmail + '" min="-200" max="100" placeholder="New score" style="width:80px;">';
                    h += '</div>';
                    h += '<div class="ar-override-row" style="flex-direction:column;align-items:stretch;">';
                    h += '<textarea id="req-notes-' + safeEmail + '" class="ar-review-note" placeholder="Why should this score be changed?" style="min-height:50px;margin-top:4px;"></textarea>';
                    h += '</div>';
                    h += '<button class="ops-btn primary" style="height:28px;font-size:11px;padding:0 12px;margin-top:8px;" onclick="submitOverrideRequest(\'' + escHtml(email) + '\',\'' + safeEmail + '\')">Submit Request</button>';
                    h += '<span class="ar-req-msg" id="req-msg-' + safeEmail + '" style="display:none;margin-left:8px;font-size:11px;color:var(--color-success);">Submitted!</span>';
                    h += '</div>';

                    // Show existing requests by this lead — filled by fetchOverrideRequests
                    h += '<div class="ar-my-requests" id="my-req-' + safeEmail + '"></div>';
                    h += '</div>';
                }

                h += '</div>';
                return h;
            }

            function agentScoreItem(label, value) {
                var v = parseInt(value || 0);
                var cls = scoreClass(v);
                var prefix = v > 0 ? '+' : '';
                return '<div class="ar-agent-score-item"><span class="label">' + label + '</span><span class="value ' + cls + '">' + prefix + v + '</span></div>';
            }

            function escHtml(str) {
                if (!str) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(String(str)));
                return div.innerHTML;
            }

            // ---- Re-Audit ----
            $(document).on('click', '.btn-force', function(e){
                e.preventDefault();
                var btn=$(this), tid=btn.data('id'), row=$('#row-'+tid);
                btn.text('...').prop('disabled',true);

                $.post(ajaxurl, {action:'ai_audit_force', ticket_id:tid}, function(res){
                    if(res.success){
                        row.find('.status-badge').first().attr('class', 'status-badge pending').text('Pending');
                        row.find('.col-summary').html('Audit in progress...<textarea id="json-' + tid + '" class="json-storage" style="display:none"></textarea>');

                        var attempts=0, maxAttempts=60;
                        var poller = setInterval(function(){
                            attempts++;
                            $.post(ajaxurl, {action:'ai_audit_check_status', ticket_id:tid}, function(res){
                                var data = res.success ? res.data : res;
                                if(data && (data.status === 'success' || data.status === 'failed')){
                                    clearInterval(poller);
                                    btn.text('Re-Audit').prop('disabled',false);
                                    updateRowUI(row, data, tid);
                                } else if(attempts >= maxAttempts){
                                    clearInterval(poller);
                                    setTimeout(function(){ location.reload(); }, 2000);
                                }
                            });
                        }, 3000);
                    } else {
                        alert('Error: ' + res.data);
                        btn.text('Re-Audit').prop('disabled',false);
                    }
                });
            });

            function updateRowUI(row, data, tid){
                row.find('.status-badge').first().attr('class', 'status-badge '+data.status).text(data.status.charAt(0).toUpperCase() + data.status.slice(1));

                var src = data.audit_response ? data.audit_response : data.raw_json;
                if(typeof src === 'object') src = JSON.stringify(src);

                if(src && src !== 'null' && src !== '') {
                    window.auditDataStore[tid] = src;
                    row.find('.json-storage').val(src);
                }

                if(data.status === 'success'){
                    var sc = parseInt(data.overall_score);
                    row.find('.col-score').attr('class', 'col-score ' + scoreClass(sc)).text(sc);
                    try {
                       var p = typeof src === 'string' ? JSON.parse(src) : src;
                       var sum = p.audit_summary ? p.audit_summary.executive_summary : (p.summary || 'Audited.');
                       row.find('.col-summary').html(escHtml(String(sum).substring(0,100)) + '...<textarea id="json-' + tid + '" class="json-storage" style="display:none">' + (src||'') + '</textarea>');
                    } catch(e){
                       row.find('.col-summary').html('Audited - view for details<textarea id="json-' + tid + '" class="json-storage" style="display:none">' + (src||'') + '</textarea>');
                    }
                } else if(data.status === 'failed'){
                    row.find('.col-summary').html(escHtml(data.error_message || 'Audit failed') + '<textarea id="json-' + tid + '" class="json-storage" style="display:none"></textarea>');
                }
            }

            // ---- View Modal ----
            $(document).on('click', '.btn-view', function(e){
                e.preventDefault();
                showingJson = false;
                $('#btn-toggle-json').text('Raw JSON');

                var ticketId = $(this).data('id');
                currentAuditId = $(this).data('audit-id') || 0;
                currentTicketId = ticketId;

                var txt = window.auditDataStore[ticketId] || $(this).closest('tr').find('.json-storage').val() || $('#json-'+ticketId).val();

                if(!txt || txt === '' || txt === 'null') {
                    $('#modal-body').attr('class', 'modal-body-parsed').html('<div style="text-align:center;padding:40px;color:var(--color-text-secondary);">No audit data available yet.</div>');
                } else {
                    try {
                        var parsed = JSON.parse(txt);
                        window._currentAuditJson = txt;
                        window._currentAuditParsed = parsed;
                        var html = buildParsedView(parsed, ticketId);
                        $('#modal-body').attr('class', 'modal-body-parsed').html(html);
                        $('#modal-title').text('Audit Report — Ticket #' + ticketId);
                        // Show View Ticket link
                        if (ticketId) {
                            <?php
                            $live_settings = LiveAuditSettings::get_settings();
                            $fs_base = !empty($live_settings['fluent_support_url'])
                                ? rtrim($live_settings['fluent_support_url'], '/') . '/admin.php?page=fluent-support#/tickets/'
                                : admin_url('admin.php?page=fluent-support#/tickets/');
                            ?>
                            $('#btn-view-ticket').attr('href', '<?php echo esc_js($fs_base); ?>' + ticketId + '/view').show();
                        }

                        // Load existing review data
                        if ((canReview || isAdmin) && currentAuditId) {
                            fetchReviewData(currentAuditId);
                        }

                        // Load override requests
                        if ((canReview || canOverride) && currentAuditId) {
                            fetchOverrideRequests(currentAuditId);
                        }
                    } catch(e) {
                        $('#modal-body').attr('class', 'modal-body-parsed json-viewer').text(txt);
                    }
                }

                $('#audit-modal').fadeIn(200);
                document.body.classList.add('modal-open');
            });

            // Toggle JSON / Parsed
            $('#btn-toggle-json').click(function(){
                if (!window._currentAuditJson) return;
                showingJson = !showingJson;
                if (showingJson) {
                    $(this).text('Parsed View');
                    var pretty = JSON.stringify(JSON.parse(window._currentAuditJson), null, 2);
                    $('#modal-body').attr('class', 'modal-body-parsed json-viewer').text(pretty);
                } else {
                    $(this).text('Raw JSON');
                    var parsed = JSON.parse(window._currentAuditJson);
                    $('#modal-body').attr('class', 'modal-body-parsed').html(buildParsedView(parsed, ''));
                }
            });

            function closeAuditModal() {
                $('#audit-modal').fadeOut();
                document.body.classList.remove('modal-open');
                currentAuditId = 0;
                currentTicketId = '';
                $('#btn-view-ticket').hide();
            }
            $('.close-modal').click(closeAuditModal);
            $('#audit-modal').click(function(e){ if($(e.target).is('#audit-modal')) closeAuditModal(); });
            $(document).keyup(function(e) { if (e.key === "Escape") closeAuditModal(); });

            // ---- Review Functions ----

            // Fetch existing review data for this audit
            function fetchReviewData(auditId) {
                $.post(ajaxurl, {action: 'ai_audit_get_review', audit_id: auditId}, function(res) {
                    if (!res.success) return;
                    var data = res.data;

                    if (data.review) {
                        var r = data.review;
                        var reviewerName = r.first_name || r.reviewer_email;
                        var reviewedAt = r.reviewed_at || '';

                        // Lead view: populate notes and show "already reviewed" state
                        if (canReview) {
                            $('#review-general-notes').val(r.general_notes || '');
                            $('#btn-mark-reviewed').text('Update Review').removeClass('primary').addClass('secondary');
                            $('#reviewed-by-info').html('Reviewed by ' + escHtml(reviewerName) + ' on ' + escHtml(reviewedAt));
                        }

                        // Admin view: show review summary
                        if (isAdmin) {
                            renderAdminReviewSummary(r);
                        }
                    } else if (isAdmin) {
                        $('#admin-review-summary').html(
                            '<div style="padding:12px 16px;border:1px dashed var(--color-border);border-radius:8px;color:var(--color-text-tertiary);font-size:13px;margin-top:16px;">Not yet reviewed by a team lead.</div>'
                        ).show();
                    }

                    // Populate override trails
                    if (data.overrides && data.overrides.length > 0) {
                        var trailsByAgent = {};
                        data.overrides.forEach(function(o) {
                            var safeEmail = o.agent_email.replace(/[^a-zA-Z0-9]/g, '_');
                            if (!trailsByAgent[safeEmail]) trailsByAgent[safeEmail] = [];
                            trailsByAgent[safeEmail].push(o);
                        });

                        Object.keys(trailsByAgent).forEach(function(safeEmail) {
                            var $trail = $('#ov-trail-' + safeEmail);
                            if ($trail.length === 0) return;

                            var trailHtml = '<div style="margin-top:6px;font-size:11px;font-weight:600;color:var(--color-text-secondary);">Override History:</div>';
                            trailsByAgent[safeEmail].forEach(function(o) {
                                var byName = o.override_by_name || o.override_by;
                                trailHtml += '<div class="ar-override-trail-item">';
                                trailHtml += escHtml(o.created_at) + ' — ' + escHtml(byName) + ' changed ' + escHtml(o.field_name) + ': ' + o.old_value + ' → ' + o.new_value;
                                if (o.reason) trailHtml += '<br><span style="color:var(--color-text-secondary);margin-left:12px;">"' + escHtml(o.reason) + '"</span>';
                                trailHtml += '</div>';
                            });
                            $trail.html(trailHtml);
                        });
                    }
                });
            }

            // Render read-only review summary for admins
            function renderAdminReviewSummary(r) {
                var reviewerName = r.first_name || r.reviewer_email || 'Unknown';
                var reviewedAt = r.reviewed_at || '';

                var h = '<div class="ar-review-panel" style="border-color:var(--color-success, #16a34a);margin-top:16px;">';
                h += '<div class="ar-review-panel-title" style="color:var(--color-success, #16a34a);">Reviewed by ' + escHtml(reviewerName) + ' <span style="font-size:11px;font-weight:400;color:var(--color-text-tertiary);">on ' + escHtml(reviewedAt) + '</span></div>';

                if (r.general_notes) {
                    h += '<div style="background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:8px;padding:10px 14px;font-size:13px;color:var(--color-text-secondary);">';
                    h += escHtml(r.general_notes);
                    h += '</div>';
                }

                h += '</div>';
                $('#admin-review-summary').html(h).show();
            }

            // Mark audit as reviewed
            window.markAsReviewed = function() {
                var $btn = $('#btn-mark-reviewed');
                $btn.prop('disabled', true).text('Saving...');

                $.post(ajaxurl, {
                    action: 'ai_audit_save_review',
                    audit_id: currentAuditId,
                    ticket_id: currentTicketId,
                    review_status: 'reviewed',
                    summary_agree: 1,
                    general_notes: $('#review-general-notes').val()
                }, function(res) {
                    $btn.prop('disabled', false).text('Update Review').removeClass('primary').addClass('secondary');
                    if (res.success) {
                        $('#review-saved-msg').fadeIn().delay(2000).fadeOut();
                        var $row = $('#row-' + currentTicketId);
                        $row.find('.review-badge, td:nth-child(4) span').first()
                            .attr('class', 'review-badge reviewed')
                            .text('You');
                    } else {
                        alert('Error: ' + (res.data || 'Unknown error'));
                    }
                });
            };

            // Save a score override
            // Toggle lead request form
            window.toggleRequestForm = function(safeEmail) {
                $('#req-form-' + safeEmail).slideToggle(200);
            };

            // Update current value display when field changes
            window.updateReqCurrentVal = function(safeEmail, scores) {
                var field = $('#req-field-' + safeEmail).val();
                // Current value is embedded in the option label
            };

            // Lead submits override request
            window.submitOverrideRequest = function(agentEmail, safeEmail) {
                var fieldName = $('#req-field-' + safeEmail).val();
                var suggestedValue = parseInt($('#req-val-' + safeEmail).val());
                var notes = $('#req-notes-' + safeEmail).val();

                if (isNaN(suggestedValue)) {
                    alert('Please enter a suggested score');
                    return;
                }
                if (!notes) {
                    alert('Please provide a reason for the request');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'ai_audit_request_override',
                    audit_id: currentAuditId,
                    ticket_id: currentTicketId,
                    agent_email: agentEmail,
                    field_name: fieldName,
                    suggested_value: suggestedValue,
                    request_notes: notes
                }, function(res) {
                    if (res.success) {
                        $('#req-msg-' + safeEmail).fadeIn().delay(3000).fadeOut();
                        $('#req-val-' + safeEmail).val('');
                        $('#req-notes-' + safeEmail).val('');
                        // Refresh requests display
                        fetchOverrideRequests(currentAuditId);
                    } else {
                        alert('Error: ' + (res.data || 'Unknown error'));
                    }
                });
            };

            // Admin resolves (approve/reject) an override request
            window.resolveOverrideRequest = function(requestId, resolution) {
                var notes = $('#resolve-notes-' + requestId).val();

                $.post(ajaxurl, {
                    action: 'ai_audit_resolve_override_request',
                    request_id: requestId,
                    resolution: resolution,
                    resolution_notes: notes
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        // Refresh requests + scores
                        fetchOverrideRequests(currentAuditId);
                        if (resolution === 'approved' && res.data.new_audit_score !== undefined) {
                            var sc = parseInt(res.data.new_audit_score);
                            var $row = $('#row-' + currentTicketId);
                            $row.find('.col-score').attr('class', 'col-score ' + scoreClass(sc)).text(sc);
                        }
                    } else {
                        alert('Error: ' + (res.data || 'Unknown error'));
                    }
                });
            };

            // Fetch override requests for an audit
            function fetchOverrideRequests(auditId) {
                if (!auditId) return;
                $.post(ajaxurl, {
                    action: 'ai_audit_get_override_requests',
                    audit_id: auditId
                }, function(res) {
                    if (!res.success) return;
                    var requests = res.data.requests || [];
                    if (!requests.length) return;

                    var byAgent = {};
                    requests.forEach(function(r) {
                        var safe = r.agent_email.replace(/[^a-zA-Z0-9]/g, '_');
                        if (!byAgent[safe]) byAgent[safe] = [];
                        byAgent[safe].push(r);
                    });

                    var fieldLabels = {timing_score: 'Timing', resolution_score: 'Resolution', communication_score: 'Communication'};

                    Object.keys(byAgent).forEach(function(safeEmail) {
                        var reqs = byAgent[safeEmail];

                        // Admin view: pending requests
                        if (canOverride) {
                            var $container = $('#pending-req-' + safeEmail);
                            if ($container.length) {
                                var pendingReqs = reqs.filter(function(r) { return r.status === 'pending'; });
                                var resolvedReqs = reqs.filter(function(r) { return r.status !== 'pending'; });
                                var ph = '';

                                if (pendingReqs.length > 0) {
                                    ph += '<div style="margin-top:12px;border-top:1px solid var(--color-border);padding-top:10px;">';
                                    ph += '<div style="font-size:12px;font-weight:600;color:var(--color-warning);margin-bottom:8px;">Pending Review Requests (' + pendingReqs.length + ')</div>';
                                    pendingReqs.forEach(function(r) {
                                        var reqName = r.requester_name || r.requested_by;
                                        ph += '<div style="background:var(--color-bg-subtle);border:1px solid var(--color-border);border-radius:8px;padding:10px;margin-bottom:8px;">';
                                        ph += '<div style="font-size:12px;"><strong>' + escHtml(reqName) + '</strong> requests <strong>' + (fieldLabels[r.field_name] || r.field_name) + '</strong>: ';
                                        ph += '<span class="' + scoreClass(parseInt(r.current_value)) + '">' + r.current_value + '</span>';
                                        ph += ' &rarr; <span class="' + scoreClass(parseInt(r.suggested_value)) + '">' + r.suggested_value + '</span></div>';
                                        if (r.request_notes) {
                                            ph += '<div style="font-size:12px;color:var(--color-text-secondary);margin:6px 0;font-style:italic;">"' + escHtml(r.request_notes) + '"</div>';
                                        }
                                        ph += '<div style="display:flex;gap:6px;align-items:center;margin-top:8px;">';
                                        ph += '<input type="text" id="resolve-notes-' + r.id + '" placeholder="Notes (optional)" style="flex:1;height:26px;font-size:11px;padding:0 8px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-bg);">';
                                        ph += '<button class="ops-btn primary" style="height:26px;font-size:11px;padding:0 10px;" onclick="resolveOverrideRequest(' + r.id + ',\'approved\')">Approve</button>';
                                        ph += '<button class="ops-btn secondary" style="height:26px;font-size:11px;padding:0 10px;" onclick="resolveOverrideRequest(' + r.id + ',\'rejected\')">Reject</button>';
                                        ph += '</div></div>';
                                    });
                                    ph += '</div>';
                                }

                                if (resolvedReqs.length > 0) {
                                    ph += '<div style="margin-top:8px;">';
                                    ph += '<div style="font-size:11px;color:var(--color-text-tertiary);margin-bottom:4px;">Past Requests</div>';
                                    resolvedReqs.forEach(function(r) {
                                        var reqName = r.requester_name || r.requested_by;
                                        var statusColor = r.status === 'approved' ? 'var(--color-success)' : 'var(--color-error)';
                                        var statusLabel = r.status === 'approved' ? 'Approved' : 'Rejected';
                                        ph += '<div style="font-size:11px;color:var(--color-text-tertiary);padding:4px 0;border-bottom:1px solid var(--color-border);">';
                                        ph += escHtml(reqName) + ': ' + (fieldLabels[r.field_name] || r.field_name) + ' ' + r.current_value + ' &rarr; ' + r.suggested_value;
                                        ph += ' <span style="color:' + statusColor + ';font-weight:600;">' + statusLabel + '</span>';
                                        if (r.resolution_notes) ph += ' — ' + escHtml(r.resolution_notes);
                                        ph += '</div>';
                                    });
                                    ph += '</div>';
                                }

                                $container.html(ph);
                            }
                        }

                        // Lead view: show own requests status
                        if (canReview && !canOverride) {
                            var $myReqs = $('#my-req-' + safeEmail);
                            if ($myReqs.length) {
                                var mh = '';
                                reqs.forEach(function(r) {
                                    var statusColor = r.status === 'pending' ? 'var(--color-warning)' : (r.status === 'approved' ? 'var(--color-success)' : 'var(--color-error)');
                                    var statusLabel = r.status.charAt(0).toUpperCase() + r.status.slice(1);
                                    mh += '<div style="font-size:11px;padding:6px 0;border-bottom:1px solid var(--color-border);color:var(--color-text-secondary);">';
                                    mh += (fieldLabels[r.field_name] || r.field_name) + ': ' + r.current_value + ' &rarr; ' + r.suggested_value;
                                    mh += ' <span style="color:' + statusColor + ';font-weight:600;">' + statusLabel + '</span>';
                                    if (r.resolution_notes) mh += '<br><span style="color:var(--color-text-tertiary);margin-left:8px;">Admin: ' + escHtml(r.resolution_notes) + '</span>';
                                    mh += '</div>';
                                });
                                if (mh) {
                                    $myReqs.html('<div style="margin-top:8px;"><div style="font-size:11px;font-weight:600;color:var(--color-text-tertiary);margin-bottom:4px;">Your Requests</div>' + mh + '</div>');
                                }
                            }
                        }
                    });
                });
            }

            window.saveOverride = function(agentEmail, fieldName, safeEmail, oldVal) {
                var $input = $('#ov-' + safeEmail + '-' + fieldName);
                var newVal = parseInt($input.val());
                var reason = $('#ov-reason-' + safeEmail).val();

                if (newVal === oldVal) {
                    alert('Value unchanged');
                    return;
                }

                if (!reason) {
                    alert('Please provide a reason for the override');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'ai_audit_save_override',
                    audit_id: currentAuditId,
                    ticket_id: currentTicketId,
                    agent_email: agentEmail,
                    field_name: fieldName,
                    new_value: newVal,
                    reason: reason
                }, function(res) {
                    if (res.success) {
                        var d = res.data;
                        // Update old-val display
                        $input.siblings('.old-val').text('(was: ' + oldVal + ' → ' + newVal + ')');

                        // Update the score in the row
                        var $row = $('#row-' + currentTicketId);
                        if (d.new_audit_score !== undefined) {
                            var sc = parseInt(d.new_audit_score);
                            $row.find('.col-score').attr('class', 'col-score ' + scoreClass(sc)).text(sc);
                        }

                        // Add to trail
                        var $trail = $('#ov-trail-' + safeEmail);
                        var trailItem = '<div class="ar-override-trail-item">Just now — You changed ' + escHtml(fieldName) + ': ' + oldVal + ' → ' + newVal + '<br><span style="color:var(--color-text-secondary);margin-left:12px;">"' + escHtml(reason) + '"</span></div>';
                        $trail.prepend(trailItem);

                        // Clear reason
                        $('#ov-reason-' + safeEmail).val('');

                        alert('Score overridden successfully');
                    } else {
                        alert('Error: ' + (res.data || 'Unknown error'));
                    }
                });
            };

            // ---- Bulk delete (admin only) ----
            if (isAdmin) {
                function updateBulkBar() {
                    var count = $('.audit-select:checked').length;
                    if (count > 0) {
                        $('#bulk-actions-bar').css('display', 'flex');
                        $('#selected-count').text(count + ' selected');
                    } else {
                        $('#bulk-actions-bar').hide();
                    }
                }

                $(document).on('change', '#select-all-audits', function() {
                    $('.audit-select').prop('checked', this.checked);
                    updateBulkBar();
                });

                $(document).on('change', '.audit-select', function() {
                    if (!this.checked) $('#select-all-audits').prop('checked', false);
                    updateBulkBar();
                });

                $(document).on('click', '#btn-bulk-delete', function() {
                    var ids = [];
                    var ticketIds = [];
                    $('.audit-select:checked').each(function() {
                        ids.push($(this).val());
                        ticketIds.push($(this).data('ticket'));
                    });
                    if (!ids.length) return;

                    if (!confirm('Are you sure you want to delete ' + ids.length + ' audit(s)?\n\nThis will permanently remove all related data including agent scores, reviews, overrides, and flagged entries.\n\nTicket IDs: ' + ticketIds.join(', '))) {
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Deleting...');

                    $.post(ajaxurl, {
                        action: 'ai_audit_delete',
                        nonce: '<?php echo wp_create_nonce("ai_ops_nonce"); ?>',
                        audit_ids: ids
                    }, function(res) {
                        if (res.success) {
                            // Remove rows from table
                            (res.data.ticket_ids || []).forEach(function(tid) {
                                $('#row-' + tid).fadeOut(300, function() { $(this).remove(); });
                            });
                            $('#select-all-audits').prop('checked', false);
                            updateBulkBar();
                            alert(res.data.message);
                        } else {
                            alert('Error: ' + (res.data || 'Unknown error'));
                        }
                        $btn.prop('disabled', false).text('Delete Selected');
                    }).fail(function() {
                        alert('Request failed');
                        $btn.prop('disabled', false).text('Delete Selected');
                    });
                });
            }

            // ---- Auto-open modal from URL param (e.g. from Flagged page) ----
            var urlParams = new URLSearchParams(window.location.search);
            var autoOpen = urlParams.get('auto_open');
            if (autoOpen) {
                function tryAutoOpen(attempts) {
                    var $btn = jQuery('.btn-view[data-id="' + autoOpen + '"]');
                    if ($btn.length) {
                        $btn.first().trigger('click');
                    } else if (attempts < 10) {
                        setTimeout(function() { tryAutoOpen(attempts + 1); }, 200);
                    }
                }
                setTimeout(function() { tryAutoOpen(0); }, 500);
            }
        });
        </script>
        <?php
    }
}