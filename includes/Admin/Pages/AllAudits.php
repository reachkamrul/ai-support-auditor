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
        $filter_flag = isset($_GET['flag_status']) ? sanitize_text_field($_GET['flag_status']) : '';

        // Handle flagged ticket actions (reviewed/dismissed)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['flagged_action'])) {
            $this->handle_flagged_action();
        }

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
        if ($filter_status && $filter_status !== 'all') {
            $where .= " AND a.status = %s";
            $params[] = $filter_status;
        } elseif ($filter_status !== 'all') {
            $where .= " AND a.status IN ('success', 'failed')";
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

        // Flag filter — only match flags on the current (latest) audit
        $flag_join = '';
        $flag_where = '';
        if ($filter_flag) {
            $flag_join = " INNER JOIN {$wpdb->prefix}ais_flagged_tickets ft ON a.id = ft.audit_id";
            $flag_where = $wpdb->prepare(" AND ft.status = %s", $filter_flag);
        }

        $count_sql = "SELECT COUNT(DISTINCT a.ticket_id) FROM {$wpdb->prefix}ais_audits a
            INNER JOIN (
                SELECT ticket_id, MAX(id) as max_id
                FROM {$wpdb->prefix}ais_audits GROUP BY ticket_id
            ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
            {$team_join}{$review_join}{$flag_join}
            {$where}{$team_where}{$review_where}{$flag_where}";

        $total = $params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql);
        $total_pages = max(1, ceil($total / $this->per_page));

        // Data query — include review data + flag data
        $data_sql = "SELECT DISTINCT a.*, ar.reviewer_email, ar.review_status as reviewed_status,
                        ar.reviewed_at, ar_agent.first_name as reviewer_name,
                        ft2.id as flag_id, ft2.flag_type, ft2.flag_details, ft2.status as flag_status
                     FROM {$wpdb->prefix}ais_audits a
            INNER JOIN (
                SELECT ticket_id, MAX(id) as max_id
                FROM {$wpdb->prefix}ais_audits GROUP BY ticket_id
            ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
            {$team_join}{$review_join}{$flag_join}
            LEFT JOIN {$wpdb->prefix}ais_agents ar_agent ON ar.reviewer_email = ar_agent.email
            LEFT JOIN {$wpdb->prefix}ais_flagged_tickets ft2 ON a.id = ft2.audit_id
            {$where}{$team_where}{$review_where}{$flag_where}
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
                            <option value="" <?php selected($filter_status, ''); ?>>Completed</option>
                            <option value="success" <?php selected($filter_status, 'success'); ?>>Success</option>
                            <option value="failed" <?php selected($filter_status, 'failed'); ?>>Failed</option>
                            <option value="pending" <?php selected($filter_status, 'pending'); ?>>Pending</option>
                            <option value="processing" <?php selected($filter_status, 'processing'); ?>>Processing</option>
                            <option value="all" <?php selected($filter_status, 'all'); ?>>All</option>
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
                    <div class="audit-filter-group narrow">
                        <label>Flagged</label>
                        <select name="flag_status" class="ops-input">
                            <option value="">All</option>
                            <option value="needs_review" <?php selected($filter_flag, 'needs_review'); ?>>Needs Review</option>
                            <option value="reviewed" <?php selected($filter_flag, 'reviewed'); ?>>Reviewed</option>
                            <option value="dismissed" <?php selected($filter_flag, 'dismissed'); ?>>Dismissed</option>
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
                        <?php if ($filter_flag): ?>
                        <th width="120">Flag</th>
                        <?php endif; ?>
                        <th>Summary</th>
                        <th width="<?php echo $filter_flag ? '240' : '180'; ?>" style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody id="audit-rows">
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="<?php echo (AccessControl::is_admin() ? 8 : 7) + ($filter_flag ? 1 : 0); ?>" class="empty-audits">
                                <p class="empty-audits-text">No audits found. Audits will appear here once tickets are processed.</p>
                            </td>
                        </tr>
                    <?php else:
                        foreach ($results as $row) {
                            $this->render_row($row, $filter_flag);
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
                    if ($filter_flag) $base_url .= '&flag_status=' . urlencode($filter_flag);

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

        <?php $this->render_pending_appeals(); ?>

        <?php AuditModal::render_modal_html(); ?>

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

            /* Modal styles now in AuditModal::render_modal_styles() */

            /* Review badge in table (AllAudits-specific) */
            .review-badge {
                display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill);
                font-size: 11px; font-weight: 600;
            }
            .review-badge.reviewed {
                background: var(--color-success-bg); color: #065f46;
            }
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
        $next_time = wp_date('H:i', $next_run);

        $type_label = 'Full';
        if ($audit_type === 'incremental') $type_label = 'Incremental';
        elseif ($audit_type === 'final') $type_label = 'Final';

        return sprintf('In queue — %s audit, next run ~%s', $type_label, $next_time);
    }

    private function render_row($row, $filter_flag = '') {
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

        // Flag column
        $flag_col = '';
        if ($filter_flag && !empty($row->flag_type)) {
            $flag_map = [
                'low_score'       => ['Low Score',   'failed'],
                'problem_context' => ['Problem',     'warning'],
                'long_delay'      => ['Long Delay',  'pending'],
                'ai_recommended'  => ['AI Flagged',  'warning'],
            ];
            $fi = $flag_map[$row->flag_type] ?? ['Flag', 'pending'];
            $flag_col = "<td><span class='status-badge {$fi[1]}'>{$fi[0]}</span></td>";
        } elseif ($filter_flag) {
            $flag_col = "<td>—</td>";
        }

        // Flag action buttons
        $flag_actions = '';
        if ($filter_flag && !empty($row->flag_id) && $row->flag_status === 'needs_review') {
            $nonce = wp_create_nonce('flagged_action_' . intval($row->flag_id));
            $flag_actions = "
                <form method='post' style='display:inline;'>
                    <input type='hidden' name='_wpnonce' value='{$nonce}'>
                    <input type='hidden' name='flagged_action' value='review'>
                    <input type='hidden' name='flag_id' value='" . intval($row->flag_id) . "'>
                    <button type='submit' class='ops-btn primary' style='font-size:11px;height:28px;padding:0 8px;'>Reviewed</button>
                </form>
                <form method='post' style='display:inline;'>
                    <input type='hidden' name='_wpnonce' value='{$nonce}'>
                    <input type='hidden' name='flagged_action' value='dismiss'>
                    <input type='hidden' name='flag_id' value='" . intval($row->flag_id) . "'>
                    <button type='submit' class='ops-btn secondary' style='font-size:11px;height:28px;padding:0 8px;'>Dismiss</button>
                </form>";
        }

        echo "<tr id='row-{$row->ticket_id}'>
            {$checkbox}
            <td style='font-weight:600;color:var(--color-text-primary);'>#{$row->ticket_id}</td>
            <td><span class='status-badge {$row->status}'>" . ucfirst($row->status) . "</span>" . (!empty($row->exclude_from_stats) ? " <span class='status-badge pending' title='" . esc_attr($row->exclude_reason ?? 'Excluded from stats') . "' style='font-size:9px;padding:2px 6px;min-width:auto;'>Excluded</span>" : "") . "</td>
            <td style='text-align:center;'><span class='col-score {$score_class}'>{$score_display}</span>{$sentiment_badge}</td>
            <td style='text-align:center;'>{$review_badge}</td>
            {$flag_col}
            <td class='col-summary'>" . esc_html(substr($sum, 0, 100)) . "...<textarea id='json-{$row->ticket_id}' class='json-storage' style='display:none'>" . esc_textarea($j) . "</textarea></td>
            <td style='text-align:right;padding-right:16px;'>
                <button class='ops-btn secondary btn-view' data-id='{$row->ticket_id}' data-audit-id='{$audit_id}'>View</button>
                " . ($filter_flag ? '' : "<button class='ops-btn primary btn-force' data-id='{$row->ticket_id}'>Re-Audit</button>") . "
                {$flag_actions}
            </td>
        </tr>";
    }

    private function handle_flagged_action() {
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
                        <td>#<?php echo esc_html($ap->ticket_id); ?></td>
                        <td style="font-size:12px;"><?php echo esc_html($ap->disputed_field ? str_replace('_', ' ', $ap->disputed_field) : 'General'); ?></td>
                        <td style="text-align:center;"><?php echo $ap->current_score !== null ? intval($ap->current_score) : '-'; ?></td>
                        <td style="font-size:12px;color:var(--color-text-secondary);max-width:250px;"><?php echo esc_html(substr($ap->reason, 0, 150)); ?></td>
                        <td style="font-size:12px;color:var(--color-text-secondary);"><?php echo wp_date('M j', strtotime($ap->created_at)); ?></td>
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

    private function render_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($){
            <?php AuditModal::render_modal_js(); ?>


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

            // ---- View Modal (uses shared openAuditModal from AuditModal) ----
            $(document).on('click', '.btn-view', function(e){
                e.preventDefault();
                var ticketId = $(this).data('id');
                var auditId = $(this).data('audit-id') || 0;
                var txt = window.auditDataStore[ticketId] || $(this).closest('tr').find('.json-storage').val() || $('#json-'+ticketId).val();

                openAuditModal(ticketId, auditId, txt);

                // Load review/override data (AllAudits-specific)
                if ((canReview || isAdmin) && currentAuditId) {
                    fetchReviewData(currentAuditId);
                }
                if ((canReview || canOverride) && currentAuditId) {
                    fetchOverrideRequests(currentAuditId);
                }
            });

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


        });
        </script>
        <?php
    }
}