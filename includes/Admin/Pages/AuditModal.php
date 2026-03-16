<?php
/**
 * Shared Audit Modal — reusable modal + JS rendering functions
 *
 * Used by AllAudits, AgentPerformance, and any page that needs to display
 * parsed audit reports in a popup modal.
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Admin\AccessControl;

class AuditModal {

    /**
     * Render the modal HTML container.
     */
    public static function render_modal_html() {
        self::render_modal_styles();
        ?>
        <div id="audit-modal" class="audit-modal">
            <div class="audit-modal-content">
                <div class="audit-modal-header">
                    <h2 id="modal-title">Audit Details</h2>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <a id="btn-view-ticket" href="#" target="_blank" class="ops-btn secondary" style="height:28px;font-size:var(--font-size-xs);padding:0 10px;text-decoration:none;display:none;">View Ticket &nearr;</a>
                        <button id="btn-toggle-json" class="ops-btn secondary" style="height:28px;font-size:var(--font-size-xs);padding:0 10px;">Raw JSON</button>
                        <span class="close-modal">&times;</span>
                    </div>
                </div>
                <div id="modal-body" class="modal-body-parsed"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render modal CSS styles.
     */
    public static function render_modal_styles() {
        ?>
        <style>
            /* Modal container */
            .audit-modal-content { max-width: 880px; }
            .audit-modal-header {
                padding: 20px 24px; border-bottom: 1px solid var(--color-border);
                display: flex; align-items: center; justify-content: space-between;
                background: var(--color-bg-subtle); flex-shrink: 0;
                position: sticky; top: 0; z-index: 1;
            }
            .audit-modal-header h2 { margin: 0; font-size: var(--font-size-lg); font-weight: 600; }
            .close-modal {
                cursor: pointer; font-size: var(--font-size-2xl); font-weight: 300; line-height: 1;
                color: var(--color-text-tertiary); width: 32px; height: 32px;
                display: flex; align-items: center; justify-content: center;
                border-radius: var(--radius-sm); background: transparent;
            }
            .close-modal:hover { color: var(--color-text-primary); background: var(--color-bg-hover); }

            /* Parsed modal body */
            .modal-body-parsed { padding: 24px; overflow-y: auto; max-height: calc(90vh - 80px); }
            .modal-body-parsed::-webkit-scrollbar { width: 8px; }
            .modal-body-parsed::-webkit-scrollbar-track { background: var(--color-bg); }
            .modal-body-parsed::-webkit-scrollbar-thumb { background: var(--color-border-strong); border-radius: 4px; }

            /* Parsed audit sections */
            .ar-section { margin-bottom: 24px; }
            .ar-section:last-child { margin-bottom: 0; }
            .ar-section + .ar-section { padding-top: 24px; border-top: 1px solid var(--color-border); }
            .ar-section-title {
                font-size: var(--font-size-sm); font-weight: 600; color: var(--color-text-secondary);
                margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--color-border);
            }
            .ar-score-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
            .ar-score-card {
                background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                border-radius: var(--radius-md); padding: 16px; text-align: center;
            }
            .ar-score-label { font-size: var(--font-size-xs); font-weight: 500; color: var(--color-text-tertiary); margin-bottom: 6px; }
            .ar-score-value { font-size: var(--font-size-2xl); font-weight: 700; line-height: 1.2; }
            .ar-score-value.score-good { color: var(--color-success); }
            .ar-score-value.score-ok { color: var(--color-info); }
            .ar-score-value.score-warning { color: var(--color-warning); }
            .ar-score-value.score-negative { color: var(--color-error); }
            .ar-summary-text { font-size: var(--font-size-base); line-height: 1.7; color: var(--color-text-primary); }

            /* Agent cards */
            .ar-agent-card {
                background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px;
            }
            .ar-agent-header {
                display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;
            }
            .ar-agent-name { font-size: var(--font-size-md); font-weight: 700; color: var(--color-text-primary); }
            .ar-agent-email { font-size: var(--font-size-xs); color: var(--color-text-tertiary); }
            .ar-agent-scores { display: flex; gap: 16px; flex-wrap: wrap; }
            .ar-agent-score-item { display: flex; flex-direction: column; align-items: center; gap: 2px; }
            .ar-agent-score-item .label { font-size: var(--font-size-xs); font-weight: 500; color: var(--color-text-tertiary); }
            .ar-agent-score-item .value { font-size: var(--font-size-lg); font-weight: 700; }

            /* Mini table */
            .ar-mini-table { width: 100%; font-size: var(--font-size-sm); border-collapse: collapse; }
            .ar-mini-table th {
                text-align: left; font-size: var(--font-size-xs); font-weight: 500; color: var(--color-text-tertiary);
                padding: 6px 8px; border-bottom: 1px solid var(--color-border);
            }
            .ar-mini-table td { padding: 6px 8px; border-bottom: 1px solid var(--color-border); color: var(--color-text-primary); }

            /* Badges */
            .ar-badge { display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill); font-size: var(--font-size-xs); font-weight: 600; }
            .ar-badge.critical { background: var(--color-error-bg); color: #991b1b; }
            .ar-badge.high { background: #fef3c7; color: #92400e; }
            .ar-badge.medium { background: var(--color-info-bg); color: #1e40af; }
            .ar-badge.low { background: var(--color-bg-subtle); color: var(--color-text-secondary); }

            /* Contribution bars */
            .ar-contrib-bar { height: 8px; border-radius: 4px; background: var(--color-border); overflow: hidden; margin-top: 4px; }
            .ar-contrib-fill { height: 100%; border-radius: 4px; background: var(--color-primary); }

            /* Tags */
            .ar-tags { display: flex; gap: 6px; flex-wrap: wrap; }
            .ar-tag {
                font-size: var(--font-size-xs); padding: 3px 10px; border-radius: var(--radius-pill);
                background: var(--color-bg-subtle); border: 1px solid var(--color-border); color: var(--color-text-secondary);
            }

            /* JSON fallback view */
            .json-viewer {
                background: var(--color-bg-subtle); padding: 24px; font-family: var(--font-mono);
                font-size: var(--font-size-xs); white-space: pre-wrap; word-wrap: break-word;
                max-height: calc(90vh - 80px); overflow-y: auto; color: var(--color-text-primary); line-height: 1.6;
            }

            /* Review note */
            .ar-review-note {
                width: 100%; padding: 8px 10px; font-size: var(--font-size-xs); border: 1px solid var(--color-border);
                border-radius: var(--radius-sm); background: var(--color-bg); color: var(--color-text-primary);
                resize: vertical; min-height: 36px; font-family: inherit;
            }
            .ar-review-saved { font-size: var(--font-size-xs); color: var(--color-success); font-weight: 600; display: none; }

            /* Score override panel */
            .ar-override-panel {
                margin-top: 10px; padding: 12px; background: #fffbeb; border: 1px solid #f59e0b; border-radius: var(--radius-sm);
            }
            .ar-override-title { font-size: var(--font-size-xs); font-weight: 700; color: #92400e; margin-bottom: 10px; }
            .ar-override-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
            .ar-override-row label { font-size: var(--font-size-xs); font-weight: 500; min-width: 120px; color: var(--color-text-secondary); }
            .ar-override-row input[type="number"] {
                width: 70px; padding: 4px 8px; font-size: var(--font-size-sm); border: 1px solid var(--color-border);
                border-radius: var(--radius-sm); text-align: center;
            }
            .ar-override-row .old-val { font-size: var(--font-size-xs); color: var(--color-text-tertiary); }
            .ar-override-reason {
                width: 100%; padding: 6px 10px; font-size: var(--font-size-xs); border: 1px solid var(--color-border);
                border-radius: var(--radius-sm); margin-top: 4px; font-family: inherit;
            }
            .ar-override-trail { margin-top: 10px; font-size: var(--font-size-xs); color: var(--color-text-tertiary); }
            .ar-override-trail-item { padding: 4px 0; border-bottom: 1px solid var(--color-border); }
            .ar-override-trail-item:last-child { border-bottom: none; }

            /* Lead request panel */
            .ar-request-panel { margin-top: 10px; padding: 12px; background: #eff6ff; border: 1px solid #3b82f6; border-radius: var(--radius-sm); }
            .ar-request-panel .ar-override-title { color: #1e40af; }

            /* Shift compliance stats */
            .ar-shift-stat {
                display: flex; flex-direction: column; align-items: center;
                padding: 6px 14px; background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                border-radius: var(--radius-sm); min-width: 80px;
            }
            .ar-shift-stat-val { font-size: var(--font-size-md); font-weight: 700; color: var(--color-text-primary); }
            .ar-shift-stat-label { font-size: var(--font-size-xs); color: var(--color-text-tertiary); margin-top: 2px; }

            /* Shift data notes */
            .ar-shift-note {
                font-size: var(--font-size-xs); color: var(--color-text-secondary); padding: 6px 12px;
                background: #fffbeb; border: 1px solid #fcd34d; border-radius: var(--radius-sm);
            }

            /* Response timeline toggle */
            .ar-section-toggle { cursor: pointer; user-select: none; }
            .ar-toggle-arrow { font-size: var(--font-size-xs); color: var(--color-text-tertiary); display: inline-block; transition: transform 0.2s; }
            .ar-toggle-arrow.open { transform: rotate(180deg); }
        </style>
        <?php
    }

    /**
     * Render the shared JS functions for parsing and displaying audit JSON.
     * Must be called inside a <script> jQuery(document).ready(function($){ ... }) block.
     *
     * @param array $options Optional config: 'fs_base' for View Ticket URL
     */
    public static function render_modal_js($options = []) {
        $can_override = AccessControl::can_override_scores() ? 'true' : 'false';
        $is_lead = AccessControl::is_lead() ? 'true' : 'false';
        $is_admin = AccessControl::is_admin() ? 'true' : 'false';

        $live_settings = LiveAuditSettings::get_settings();
        $fs_base = !empty($live_settings['fluent_support_url'])
            ? rtrim($live_settings['fluent_support_url'], '/') . '/admin.php?page=fluent-support#/tickets/'
            : admin_url('admin.php?page=fluent-support#/tickets/');
        ?>
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

                // Excluded from stats banner
                if (a.exclude_from_stats) {
                    var ctx = a.ticket_context ? ' | Context: ' + escHtml(a.ticket_context) : '';
                    var reason = a.exclude_reason ? ' | Reason: ' + escHtml(a.exclude_reason) : '';
                    h += '<div class="ar-section" style="background:var(--color-info-bg, #eff6ff);border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;">';
                    h += '<strong>&#x2139; This audit is excluded from agent performance averages.</strong>' + ctx + reason;
                    h += '</div>';
                }

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
                        h += '<span style="font-weight:600;font-size:var(--font-size-sm);min-width:180px;">' + escHtml(c.agent_email || '') + '</span>';
                        h += '<div class="ar-contrib-bar" style="flex:1;"><div class="ar-contrib-fill" style="width:' + pct + '%;"></div></div>';
                        h += '<span style="font-weight:700;font-size:var(--font-size-sm);min-width:40px;text-align:right;">' + pct + '%</span>';
                        if (c.reply_count) h += '<span style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);">(' + c.reply_count + ' replies)</span>';
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
                        h += '<td>' + escHtml(p.issue_description || '') + (hasDetails ? ' <span style="color:var(--color-text-tertiary);font-size:var(--font-size-xs);">&#9660;</span>' : '') + '</td>';
                        h += '<td>' + escHtml(p.category || '') + '</td>';
                        h += '<td><span class="ar-badge ' + sev + '">' + escHtml(p.severity || '') + '</span></td></tr>';
                        if (hasDetails) {
                            h += '<tr id="prob-detail-' + idx + '" style="display:none;"><td colspan="3" style="padding:8px 12px;background:var(--color-bg-subtle);font-size:var(--font-size-xs);">';
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
                        h += '<div style="margin-bottom:12px;"><strong style="font-size:var(--font-size-xs);color:var(--color-text-secondary);">Categories:</strong><div class="ar-tags" style="margin-top:6px;">';
                        cats.forEach(function(c) { h += '<span class="ar-tag">' + escHtml(c) + '</span>'; });
                        h += '</div></div>';
                    }
                    if (gaps.length) {
                        h += '<div style="margin-bottom:12px;"><strong style="font-size:var(--font-size-xs);color:var(--color-text-secondary);">Documentation Gaps:</strong><ul style="margin:6px 0 0 16px;font-size:var(--font-size-sm);color:var(--color-text-primary);">';
                        gaps.forEach(function(g) { h += '<li>' + escHtml(g) + '</li>'; });
                        h += '</ul></div>';
                    }
                    if (faqs.length) {
                        h += '<div><strong style="font-size:var(--font-size-xs);color:var(--color-text-secondary);">Recommended FAQ:</strong><ul style="margin:6px 0 0 16px;font-size:var(--font-size-sm);color:var(--color-text-primary);">';
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
                    h += '<span id="reviewed-by-info" style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);"></span>';
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
                h += '<div style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);">' + (ev.reply_count || 0) + ' replies &middot; ' + (ev.contribution_percentage || 0) + '% contribution</div></div>';

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
                    h += '<div style="margin-top:12px;"><strong style="font-size:var(--font-size-xs);color:var(--color-text-secondary);">Achievements:</strong><ul style="margin:4px 0 0 16px;font-size:var(--font-size-sm);">';
                    achievements.forEach(function(a) { h += '<li style="color:var(--color-success);">' + escHtml(a) + '</li>'; });
                    h += '</ul></div>';
                }

                // Areas for improvement
                var improvements = ev.areas_for_improvement || [];
                if (improvements.length) {
                    h += '<div style="margin-top:8px;"><strong style="font-size:var(--font-size-xs);color:var(--color-text-secondary);">Areas for Improvement:</strong><ul style="margin:4px 0 0 16px;font-size:var(--font-size-sm);">';
                    improvements.forEach(function(a) { h += '<li style="color:var(--color-warning);">' + escHtml(a) + '</li>'; });
                    h += '</ul></div>';
                }

                // Reasoning
                if (ev.reasoning) {
                    h += '<div style="margin-top:8px;font-size:var(--font-size-xs);color:var(--color-text-tertiary);font-style:italic;">' + escHtml(ev.reasoning) + '</div>';
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
                    h += '<strong style="font-size:var(--font-size-xs);color:var(--color-text-secondary);cursor:pointer;">Response Timeline (' + rb.length + ')</strong>';
                    h += ' <span class="ar-toggle-arrow">&#9660;</span></div>';
                    h += '<div class="ar-response-timeline" style="display:none;margin-top:8px;">';
                    h += '<table class="ar-mini-table" style="font-size:var(--font-size-xs);"><thead><tr>';
                    h += '<th>#</th><th>Time</th><th>Since Prev</th><th>Shift</th><th>Quality</th><th>Resolution</th><th>Note</th>';
                    h += '</tr></thead><tbody>';
                    rb.forEach(function(r) {
                        var ts = r.timestamp || '';
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
                        h += '<td style="font-size:var(--font-size-xs);color:var(--color-text-secondary);max-width:250px;">' + escHtml(r.brief_note || '') + '</td>';
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
                        h += '<button class="ops-btn secondary" style="height:26px;font-size:var(--font-size-xs);padding:0 10px;" onclick="saveOverride(\'' + escHtml(email) + '\',\'' + f.name + '\',\'' + safeEmail + '\',' + f.val + ')">Save</button>';
                        h += '</div>';
                    });
                    h += '<input type="text" class="ar-override-reason" id="ov-reason-' + safeEmail + '" placeholder="Reason for override...">';

                    // Override trail placeholder
                    h += '<div class="ar-override-trail" id="ov-trail-' + safeEmail + '"></div>';

                    // Pending override requests (admin view)
                    h += '<div class="ar-pending-requests" id="pending-req-' + safeEmail + '"></div>';
                    h += '</div>';
                }

                // Lead: Request Score Review (if lead but NOT canOverride)
                if (canReview && !canOverride && currentAuditId && ev.agent_email) {
                    var email = ev.agent_email;
                    var safeEmail = email.replace(/[^a-zA-Z0-9]/g, '_');
                    h += '<div class="ar-request-panel" id="req-panel-' + safeEmail + '">';
                    h += '<div class="ar-override-title" style="cursor:pointer;" onclick="toggleRequestForm(\'' + safeEmail + '\')">';
                    h += 'Request Score Review <span style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);">&#9660;</span></div>';
                    h += '<div class="ar-request-form" id="req-form-' + safeEmail + '" style="display:none;">';
                    h += '<div class="ar-override-row"><label>Field:</label>';
                    h += '<select id="req-field-' + safeEmail + '" class="ops-input" style="width:160px;height:28px;font-size:var(--font-size-xs);" onchange="updateReqCurrentVal(\'' + safeEmail + '\',' + JSON.stringify({
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
                    h += '<button class="ops-btn primary" style="height:28px;font-size:var(--font-size-xs);padding:0 12px;margin-top:8px;" onclick="submitOverrideRequest(\'' + escHtml(email) + '\',\'' + safeEmail + '\')">Submit Request</button>';
                    h += '<span class="ar-req-msg" id="req-msg-' + safeEmail + '" style="display:none;margin-left:8px;font-size:var(--font-size-xs);color:var(--color-success);">Submitted!</span>';
                    h += '</div>';

                    // Show existing requests by this lead
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

            // ---- Open modal with parsed audit data ----
            function openAuditModal(ticketId, auditId, jsonText) {
                showingJson = false;
                $('#btn-toggle-json').text('Raw JSON');
                currentAuditId = auditId || 0;
                currentTicketId = ticketId;

                if (!jsonText || jsonText === '' || jsonText === 'null') {
                    $('#modal-body').attr('class', 'modal-body-parsed').html('<div style="text-align:center;padding:40px;color:var(--color-text-secondary);">No audit data available yet.</div>');
                } else {
                    try {
                        var parsed = JSON.parse(jsonText);
                        window._currentAuditJson = jsonText;
                        window._currentAuditParsed = parsed;
                        var html = buildParsedView(parsed, ticketId);
                        $('#modal-body').attr('class', 'modal-body-parsed').html(html);
                        $('#modal-title').text('Audit Report — Ticket #' + ticketId);
                        if (ticketId) {
                            $('#btn-view-ticket').attr('href', '<?php echo esc_js($fs_base); ?>' + ticketId + '/view').show();
                        }
                    } catch(e) {
                        $('#modal-body').attr('class', 'modal-body-parsed json-viewer').text(jsonText);
                    }
                }

                $('#audit-modal').fadeIn(200);
                document.body.classList.add('modal-open');
            }

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

            // Toggle JSON / Parsed
            $('#btn-toggle-json').click(function(){
                if (!window._currentAuditJson) return;
                showingJson = !showingJson;
                if (showingJson) {
                    $(this).text('Parsed View');
                    $('#modal-body').attr('class', 'modal-body-parsed json-viewer').text(JSON.stringify(window._currentAuditParsed, null, 2));
                } else {
                    $(this).text('Raw JSON');
                    var html = buildParsedView(window._currentAuditParsed, currentTicketId);
                    $('#modal-body').attr('class', 'modal-body-parsed').html(html);
                }
            });
        <?php
    }
}
