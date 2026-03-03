<?php
/**
 * Dashboard Page
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class Dashboard {

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
        global $wpdb;

        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $this->per_page;

        // Server-side filters
        $filter_status = isset($_GET['audit_status']) ? sanitize_text_field($_GET['audit_status']) : '';
        $filter_search = isset($_GET['audit_search']) ? sanitize_text_field($_GET['audit_search']) : '';

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

        $count_sql = "SELECT COUNT(DISTINCT a.ticket_id) FROM {$wpdb->prefix}ais_audits a
            INNER JOIN (
                SELECT ticket_id, MAX(id) as max_id
                FROM {$wpdb->prefix}ais_audits GROUP BY ticket_id
            ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
            $where";

        $total = $params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql);
        $total_pages = max(1, ceil($total / $this->per_page));

        // Data query
        $data_sql = "SELECT a.* FROM {$wpdb->prefix}ais_audits a
            INNER JOIN (
                SELECT ticket_id, MAX(id) as max_id
                FROM {$wpdb->prefix}ais_audits GROUP BY ticket_id
            ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
            $where
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
                    <input type="hidden" name="tab" value="audits">
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
                    <div class="audit-filter-group" style="justify-content:flex-end;">
                        <label>&nbsp;</label>
                        <button type="submit" class="ops-btn primary" style="height:38px;">Filter</button>
                    </div>
                </form>
            </div>

            <div class="audit-table-wrapper">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th width="80">ID</th>
                        <th width="100">Status</th>
                        <th width="80">Score</th>
                        <th>Summary</th>
                        <th width="180" style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody id="audit-rows">
                    <?php if (empty($results)): ?>
                        <tr>
                            <td colspan="5" class="empty-audits">
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
                    $base_url = admin_url('admin.php?page=ai-ops&tab=audits');
                    if ($filter_status) $base_url .= '&audit_status=' . urlencode($filter_status);
                    if ($filter_search) $base_url .= '&audit_search=' . urlencode($filter_search);

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
                background: var(--color-bg-subtle);
                border-bottom: 1px solid var(--color-border);
                border-radius: var(--radius-md) var(--radius-md) 0 0;
            }
            .audit-filters {
                display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end;
            }
            .audit-filter-group {
                display: flex; flex-direction: column; gap: 8px;
            }
            .audit-filter-group label {
                font-size: 12px; font-weight: 600; color: var(--color-text-secondary);
                text-transform: uppercase; letter-spacing: 0.5px;
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
            .empty-audits { text-align: center; padding: 60px 20px; color: var(--color-text-secondary); }
            .empty-audits-text { font-size: 14px; margin: 0; }

            /* Pagination */
            .audit-pagination {
                display: flex; align-items: center; justify-content: space-between;
                padding: 16px 20px; border-top: 1px solid var(--color-border);
                background: var(--color-bg-subtle);
            }
            .pagination-info { font-size: 13px; color: var(--color-text-secondary); }
            .pagination-links { display: flex; gap: 4px; }

            /* Modal */
            .audit-modal {
                display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); z-index: 9999; backdrop-filter: blur(4px);
                animation: fadeIn 0.2s ease;
            }
            @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
            .audit-modal-content {
                background: var(--color-bg); width: 90%; max-width: 960px; max-height: 90vh;
                margin: 5vh auto; border-radius: var(--radius-lg); padding: 0;
                box-shadow: var(--shadow-lg); overflow: hidden;
                display: flex; flex-direction: column; animation: slideUp 0.3s ease;
            }
            @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
            .audit-modal-header {
                padding: 20px 24px; border-bottom: 1px solid var(--color-border);
                display: flex; align-items: center; justify-content: space-between;
                background: var(--color-bg-subtle); flex-shrink: 0;
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
            .ar-section-title {
                font-size: 13px; font-weight: 700; color: var(--color-text-secondary);
                text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;
                padding-bottom: 8px; border-bottom: 1px solid var(--color-border);
            }
            .ar-score-grid {
                display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px;
            }
            .ar-score-card {
                background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                border-radius: var(--radius-md); padding: 16px; text-align: center;
            }
            .ar-score-label { font-size: 11px; font-weight: 600; color: var(--color-text-tertiary); text-transform: uppercase; margin-bottom: 6px; }
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
            .ar-agent-score-item .label { font-size: 10px; font-weight: 600; color: var(--color-text-tertiary); text-transform: uppercase; }
            .ar-agent-score-item .value { font-size: 18px; font-weight: 700; }
            .ar-mini-table { width: 100%; font-size: 13px; border-collapse: collapse; }
            .ar-mini-table th {
                text-align: left; font-size: 11px; font-weight: 600; color: var(--color-text-tertiary);
                text-transform: uppercase; padding: 6px 8px; border-bottom: 1px solid var(--color-border);
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
        </style>
        <?php
    }

    private function render_row($row) {
        $j = !empty($row->audit_response) ? $row->audit_response : $row->raw_json;

        if (empty($j) || $j === 'null') {
            $now = current_time('timestamp');
            $next_hour = (date('G', $now) + 1) % 24;
            $j = json_encode(['status' => 'pending', 'message' => sprintf("Scheduled for %02d:00 (hourly batch)", $next_hour)]);
        }

        $d = json_decode($j, true);

        if ($row->status == 'failed') {
            $sum = $row->error_message ?: 'Audit failed';
        } elseif ($row->status == 'pending') {
            $next_hour = (date('G', current_time('timestamp')) + 1) % 24;
            $sum = sprintf("Scheduled for %02d:00 (hourly batch)", $next_hour);
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

        echo "<tr id='row-{$row->ticket_id}'>
            <td style='font-weight:600;color:var(--color-text-primary);'>#{$row->ticket_id}</td>
            <td><span class='status-badge {$row->status}'>" . strtoupper($row->status) . "</span></td>
            <td style='text-align:center;'><span class='col-score {$score_class}'>{$score_display}</span>{$sentiment_badge}</td>
            <td class='col-summary'>" . esc_html(substr($sum, 0, 100)) . "...<textarea id='json-{$row->ticket_id}' class='json-storage' style='display:none'>" . esc_textarea($j) . "</textarea></td>
            <td style='text-align:right;padding-right:16px;'>
                <button class='ops-btn secondary btn-view' data-id='{$row->ticket_id}'>View</button>
                <button class='ops-btn primary btn-force' data-id='{$row->ticket_id}'>Re-Audit</button>
            </td>
        </tr>";
    }

    private function render_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($){
            window.auditDataStore = window.auditDataStore || {};
            var showingJson = false;

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
                    problems.forEach(function(p) {
                        var sev = (p.severity || 'low').toLowerCase();
                        h += '<tr><td>' + escHtml(p.issue_description || '') + '</td>';
                        h += '<td>' + escHtml(p.category || '') + '</td>';
                        h += '<td><span class="ar-badge ' + sev + '">' + escHtml(p.severity || '') + '</span></td></tr>';
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
                    h += '<div style="margin-top:12px;"><strong style="font-size:11px;color:var(--color-text-secondary);text-transform:uppercase;">Achievements:</strong><ul style="margin:4px 0 0 16px;font-size:13px;">';
                    achievements.forEach(function(a) { h += '<li style="color:var(--color-success);">' + escHtml(a) + '</li>'; });
                    h += '</ul></div>';
                }

                // Areas for improvement
                var improvements = ev.areas_for_improvement || [];
                if (improvements.length) {
                    h += '<div style="margin-top:8px;"><strong style="font-size:11px;color:var(--color-text-secondary);text-transform:uppercase;">Areas for Improvement:</strong><ul style="margin:4px 0 0 16px;font-size:13px;">';
                    improvements.forEach(function(a) { h += '<li style="color:var(--color-warning);">' + escHtml(a) + '</li>'; });
                    h += '</ul></div>';
                }

                // Reasoning
                if (ev.reasoning) {
                    h += '<div style="margin-top:8px;font-size:12px;color:var(--color-text-tertiary);font-style:italic;">' + escHtml(ev.reasoning) + '</div>';
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
                        row.find('.status-badge').first().attr('class', 'status-badge pending').text('PENDING');
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
                row.find('.status-badge').first().attr('class', 'status-badge '+data.status).text(data.status.toUpperCase());

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
                var txt = window.auditDataStore[ticketId] || $(this).closest('tr').find('.json-storage').val() || $('#json-'+ticketId).val();

                if(!txt || txt === '' || txt === 'null') {
                    $('#modal-body').attr('class', 'modal-body-parsed').html('<div style="text-align:center;padding:40px;color:var(--color-text-secondary);">No audit data available yet.</div>');
                } else {
                    try {
                        var parsed = JSON.parse(txt);
                        window._currentAuditJson = txt;
                        var html = buildParsedView(parsed, ticketId);
                        $('#modal-body').attr('class', 'modal-body-parsed').html(html);
                        $('#modal-title').text('Audit Report — Ticket #' + ticketId);
                    } catch(e) {
                        $('#modal-body').attr('class', 'modal-body-parsed json-viewer').text(txt);
                    }
                }

                $('#audit-modal').fadeIn(200);
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

            $('.close-modal').click(function(){ $('#audit-modal').fadeOut(); });
            $('#audit-modal').click(function(e){ if($(e.target).is('#audit-modal')) $(this).fadeOut(); });
            $(document).keyup(function(e) { if (e.key === "Escape") $('#audit-modal').fadeOut(); });
        });
        </script>
        <?php
    }
}