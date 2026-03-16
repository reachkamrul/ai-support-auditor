<?php
/**
 * Time Machine — Historical Support Operations Snapshot
 *
 * Go back to any date and see: ticket counts, agent activity, quality scores,
 * shift coverage, problems found, and workload distribution.
 *
 * Data sources:
 * - ais_audits / ais_agent_evaluations / ais_problem_contexts (our audit data)
 * - FluentSupport PHP API (live ticket data when available)
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class TimeMachine {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        $this->render_styles();
        $this->render_controls();
        echo '<div id="tm-results" style="display:none;"></div>';
        echo '<div id="tm-loading" style="display:none;text-align:center;padding:60px;">';
        echo '<div class="tm-spinner"></div><p style="color:var(--color-text-secondary);margin-top:12px;">Loading snapshot...</p>';
        echo '</div>';
        $this->render_scripts();
    }

    private function render_styles() {
        ?>
        <style>
        .tm-kpi-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:12px; }
        .tm-kpi-card { background:var(--color-bg-secondary); border-radius:8px; padding:16px; text-align:center; }
        .tm-kpi-value { font-size:var(--font-size-2xl); font-weight:700; line-height:1.2; }
        .tm-kpi-label { font-size:var(--font-size-xs); color:var(--color-text-secondary); margin-top:4px; }
        .tm-table { width:100%; border-collapse:collapse; font-size:var(--font-size-sm); }
        .tm-table th { text-align:left; padding:10px 12px; border-bottom:2px solid var(--color-border); font-weight:600; font-size:var(--font-size-xs); text-transform:uppercase; color:var(--color-text-secondary); letter-spacing:0.5px; }
        .tm-table td { padding:10px 12px; border-bottom:1px solid var(--color-border); }
        .tm-table tbody tr:hover { background:var(--color-bg-secondary); }
        .tm-spinner { width:32px; height:32px; border:3px solid var(--color-border); border-top-color:var(--color-primary); border-radius:50%; animation:tm-spin 0.8s linear infinite; margin:0 auto; }
        @keyframes tm-spin { to { transform:rotate(360deg); } }
        </style>
        <?php
    }

    private function render_controls() {
        $today = current_time('Y-m-d');
        ?>
        <div class="ops-card" style="margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div>
                    <label style="font-size:var(--font-size-xs);font-weight:600;color:var(--color-text-secondary);display:block;margin-bottom:4px;">Date</label>
                    <input type="date" id="tm-date" class="ops-input" value="<?php echo esc_attr($today); ?>" max="<?php echo esc_attr($today); ?>" style="width:170px;">
                </div>
                <div>
                    <label style="font-size:var(--font-size-xs);font-weight:600;color:var(--color-text-secondary);display:block;margin-bottom:4px;">End Date (optional range)</label>
                    <input type="date" id="tm-date-end" class="ops-input" value="" max="<?php echo esc_attr($today); ?>" style="width:170px;" placeholder="Same as start">
                </div>
                <div>
                    <label style="font-size:var(--font-size-xs);font-weight:600;color:var(--color-text-secondary);display:block;margin-bottom:4px;">Quick Select</label>
                    <div style="display:flex;gap:6px;">
                        <button class="ops-btn secondary tm-preset" data-days="0">Today</button>
                        <button class="ops-btn secondary tm-preset" data-days="1">Yesterday</button>
                        <button class="ops-btn secondary tm-preset" data-days="7">Last 7 Days</button>
                        <button class="ops-btn secondary tm-preset" data-days="30">Last 30 Days</button>
                    </div>
                </div>
                <div style="margin-left:auto;align-self:flex-end;">
                    <button id="tm-go" class="ops-btn primary" onclick="tmLoad()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;margin-right:4px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Load Snapshot
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($) {

            // Preset buttons
            $('.tm-preset').on('click', function() {
                var days = parseInt($(this).data('days'));
                var today = new Date();
                if (days === 0) {
                    $('#tm-date').val(formatDate(today));
                    $('#tm-date-end').val('');
                } else if (days === 1) {
                    var yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    $('#tm-date').val(formatDate(yesterday));
                    $('#tm-date-end').val('');
                } else {
                    var start = new Date(today);
                    start.setDate(start.getDate() - days + 1);
                    $('#tm-date').val(formatDate(start));
                    $('#tm-date-end').val(formatDate(today));
                }
                tmLoad();
            });

            function formatDate(d) {
                return d.getFullYear() + '-' +
                    String(d.getMonth() + 1).padStart(2, '0') + '-' +
                    String(d.getDate()).padStart(2, '0');
            }

            // Auto-load today on page load
            tmLoad();
        });

        function tmLoad() {
            var $ = jQuery;
            var dateFrom = $('#tm-date').val();
            var dateTo = $('#tm-date-end').val() || dateFrom;
            if (!dateFrom) return;

            $('#tm-results').hide();
            $('#tm-loading').show();

            $.post(ajaxurl, {
                action: 'ai_ops_time_machine',
                nonce: '<?php echo wp_create_nonce('ai_ops_nonce'); ?>',
                date_from: dateFrom,
                date_to: dateTo
            }, function(res) {
                $('#tm-loading').hide();
                if (res.success) {
                    renderTimeMachine(res.data);
                    $('#tm-results').show();
                } else {
                    $('#tm-results').html('<div class="ops-card" style="color:var(--color-danger);">Error: ' + (res.data || 'Unknown error') + '</div>').show();
                }
            }).fail(function() {
                $('#tm-loading').hide();
                $('#tm-results').html('<div class="ops-card" style="color:var(--color-danger);">Request failed.</div>').show();
            });
        }

        function renderTimeMachine(d) {
            var $ = jQuery;
            var isRange = d.date_from !== d.date_to;
            var dateLabel = isRange ? d.date_from + ' to ' + d.date_to : d.date_from;
            var html = '';

            // -- Date Header --
            html += '<div style="margin-bottom:16px;display:flex;align-items:center;gap:10px;">';
            html += '<span style="font-size:var(--font-size-xl);font-weight:700;color:var(--color-text-primary);">' + dateLabel + '</span>';
            if (isRange) {
                var days = Math.round((new Date(d.date_to) - new Date(d.date_from)) / 86400000) + 1;
                html += '<span style="background:var(--color-bg-tertiary);padding:2px 10px;border-radius:12px;font-size:var(--font-size-xs);color:var(--color-text-secondary);">' + days + ' days</span>';
            }
            html += '</div>';

            // -- KPI Cards --
            html += '<div class="tm-kpi-grid" style="margin-bottom:20px;">';
            html += kpiCard('Audited Tickets', d.kpis.audited_tickets, '', '#6366f1');
            html += kpiCard('Avg Audit Score', d.kpis.avg_audit_score !== null ? d.kpis.avg_audit_score : '—', '/100', scoreColor(d.kpis.avg_audit_score));
            html += kpiCard('Agent Evaluations', d.kpis.total_evaluations, '', '#6366f1');
            html += kpiCard('Avg Agent Score', d.kpis.avg_agent_score !== null ? d.kpis.avg_agent_score : '—', '', scoreColor(d.kpis.avg_agent_score, true));
            html += kpiCard('Problems Found', d.kpis.problems_found, '', d.kpis.problems_found > 0 ? '#ef4444' : '#22c55e');
            html += kpiCard('Active Agents', d.kpis.active_agents, '', '#6366f1');
            html += '</div>';

            // -- FluentSupport Live Data (if available) --
            if (d.fluent && d.fluent.available) {
                html += '<div class="ops-card" style="margin-bottom:20px;">';
                html += '<h3 style="margin:0 0 16px;font-size:var(--font-size-md);font-weight:600;">FluentSupport Ticket Activity</h3>';
                html += '<div class="tm-kpi-grid">';
                html += kpiCard('New Tickets', d.fluent.new_tickets, '', '#3b82f6');
                html += kpiCard('Closed Tickets', d.fluent.closed_tickets, '', '#22c55e');
                html += kpiCard('Active (Open)', d.fluent.active_tickets, '', '#f59e0b');
                html += kpiCard('Total Responses', d.fluent.total_responses, '', '#6366f1');
                html += kpiCard('Unassigned', d.fluent.unassigned_tickets, '', d.fluent.unassigned_tickets > 0 ? '#ef4444' : '#22c55e');
                html += kpiCard('Awaiting Reply', d.fluent.awaiting_reply, '', d.fluent.awaiting_reply > 5 ? '#f59e0b' : '#22c55e');
                html += '</div>';
                html += '</div>';
            }

            // -- Agent Performance Table --
            if (d.agents && d.agents.length > 0) {
                html += '<div class="ops-card" style="margin-bottom:20px;">';
                html += '<h3 style="margin:0 0 16px;font-size:var(--font-size-md);font-weight:600;">Agent Performance</h3>';
                html += '<div style="overflow-x:auto;">';
                html += '<table class="tm-table">';
                html += '<thead><tr>';
                html += '<th>Agent</th>';
                html += '<th style="text-align:center;">Tickets</th>';
                html += '<th style="text-align:center;">Avg Score</th>';
                html += '<th style="text-align:center;">Timing</th>';
                html += '<th style="text-align:center;">Resolution</th>';
                html += '<th style="text-align:center;">Communication</th>';
                html += '<th style="text-align:center;">Replies</th>';
                html += '<th style="text-align:center;">Problems</th>';
                html += '</tr></thead>';
                html += '<tbody>';
                d.agents.forEach(function(a) {
                    html += '<tr>';
                    html += '<td><strong>' + esc(a.agent_name) + '</strong><br><span style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);">' + esc(a.agent_email) + '</span></td>';
                    html += '<td style="text-align:center;">' + a.ticket_count + '</td>';
                    html += '<td style="text-align:center;"><span style="color:' + scoreColor(a.avg_overall, true) + ';font-weight:600;">' + a.avg_overall + '</span></td>';
                    html += '<td style="text-align:center;"><span style="color:' + timingColor(a.avg_timing) + ';">' + a.avg_timing + '</span></td>';
                    html += '<td style="text-align:center;">' + a.avg_resolution + '</td>';
                    html += '<td style="text-align:center;">' + a.avg_communication + '</td>';
                    html += '<td style="text-align:center;">' + a.total_replies + '</td>';
                    html += '<td style="text-align:center;">' + (a.problem_count > 0 ? '<span style="color:#ef4444;font-weight:600;">' + a.problem_count + '</span>' : '0') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div></div>';
            }

            // -- Problem Breakdown --
            if (d.problems && d.problems.length > 0) {
                html += '<div class="ops-card" style="margin-bottom:20px;">';
                html += '<h3 style="margin:0 0 16px;font-size:var(--font-size-md);font-weight:600;">Problem Categories</h3>';
                html += '<div style="display:flex;flex-wrap:wrap;gap:12px;">';
                d.problems.forEach(function(p) {
                    var color = problemColor(p.category);
                    html += '<div style="background:' + color + '15;border:1px solid ' + color + '30;border-radius:8px;padding:12px 16px;min-width:180px;">';
                    html += '<div style="font-size:var(--font-size-xl);font-weight:700;color:' + color + ';">' + p.count + '</div>';
                    html += '<div style="font-size:var(--font-size-xs);color:var(--color-text-secondary);">' + esc(p.category) + '</div>';
                    html += '</div>';
                });
                html += '</div></div>';
            }

            // -- Shift Coverage --
            if (d.shifts && d.shifts.length > 0) {
                html += '<div class="ops-card" style="margin-bottom:20px;">';
                html += '<h3 style="margin:0 0 16px;font-size:var(--font-size-md);font-weight:600;">Shift Coverage</h3>';
                html += '<div style="display:flex;flex-wrap:wrap;gap:12px;">';
                d.shifts.forEach(function(s) {
                    html += '<div style="background:var(--color-bg-secondary);border-radius:8px;padding:12px 16px;min-width:150px;">';
                    html += '<div style="font-size:var(--font-size-sm);font-weight:600;color:var(--color-text-primary);margin-bottom:4px;">' + esc(s.shift_type) + '</div>';
                    html += '<div style="font-size:var(--font-size-xl);font-weight:700;color:var(--color-primary);">' + s.agent_count + '</div>';
                    html += '<div style="font-size:var(--font-size-xs);color:var(--color-text-tertiary);">agents scheduled</div>';
                    html += '</div>';
                });
                html += '</div></div>';
            }

            // -- Daily Breakdown (for ranges) --
            if (d.daily && d.daily.length > 1) {
                html += '<div class="ops-card" style="margin-bottom:20px;">';
                html += '<h3 style="margin:0 0 16px;font-size:var(--font-size-md);font-weight:600;">Daily Breakdown</h3>';
                html += '<div style="overflow-x:auto;">';
                html += '<table class="tm-table">';
                html += '<thead><tr><th>Date</th><th style="text-align:center;">Audits</th><th style="text-align:center;">Avg Score</th><th style="text-align:center;">Problems</th><th style="text-align:center;">Agents Active</th></tr></thead>';
                html += '<tbody>';
                d.daily.forEach(function(day) {
                    html += '<tr>';
                    html += '<td>' + day.date + '</td>';
                    html += '<td style="text-align:center;">' + day.audits + '</td>';
                    html += '<td style="text-align:center;"><span style="color:' + scoreColor(day.avg_score) + ';">' + (day.avg_score || '—') + '</span></td>';
                    html += '<td style="text-align:center;">' + (day.problems > 0 ? '<span style="color:#ef4444;">' + day.problems + '</span>' : '0') + '</td>';
                    html += '<td style="text-align:center;">' + day.active_agents + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div></div>';
            }

            // -- Worst Tickets --
            if (d.worst_tickets && d.worst_tickets.length > 0) {
                html += '<div class="ops-card" style="margin-bottom:20px;">';
                html += '<h3 style="margin:0 0 16px;font-size:var(--font-size-md);font-weight:600;">Lowest Scoring Tickets</h3>';
                html += '<div style="overflow-x:auto;">';
                html += '<table class="tm-table">';
                html += '<thead><tr><th>Ticket</th><th>Score</th><th>Sentiment</th><th>Agents</th></tr></thead>';
                html += '<tbody>';
                d.worst_tickets.forEach(function(t) {
                    html += '<tr>';
                    html += '<td><a href="<?php echo admin_url('admin.php?page=ai-ops&section=audits'); ?>&search=' + t.ticket_id + '" style="color:var(--color-primary);font-weight:600;">#' + t.ticket_id + '</a></td>';
                    html += '<td><span style="color:' + scoreColor(t.score) + ';font-weight:600;">' + t.score + '</span></td>';
                    html += '<td>' + esc(t.sentiment || '—') + '</td>';
                    html += '<td style="font-size:var(--font-size-xs);">' + esc(t.agents) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div></div>';
            }

            // -- FluentSupport Agent Response Stats --
            if (d.fluent && d.fluent.agent_responses && d.fluent.agent_responses.length > 0) {
                html += '<div class="ops-card" style="margin-bottom:20px;">';
                html += '<h3 style="margin:0 0 16px;font-size:var(--font-size-md);font-weight:600;">FluentSupport Response Activity</h3>';
                html += '<div style="overflow-x:auto;">';
                html += '<table class="tm-table">';
                html += '<thead><tr><th>Agent</th><th style="text-align:center;">Responses</th><th style="text-align:center;">Interactions</th><th style="text-align:center;">Closed</th></tr></thead>';
                html += '<tbody>';
                d.fluent.agent_responses.forEach(function(ar) {
                    html += '<tr>';
                    html += '<td>' + esc(ar.name) + '</td>';
                    html += '<td style="text-align:center;">' + ar.responses + '</td>';
                    html += '<td style="text-align:center;">' + ar.interactions + '</td>';
                    html += '<td style="text-align:center;">' + ar.closed + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div></div>';
            }

            $('#tm-results').html(html);
        }

        function kpiCard(label, value, suffix, color) {
            return '<div class="tm-kpi-card">' +
                '<div class="tm-kpi-value" style="color:' + (color || 'var(--color-text-primary)') + ';">' + value + '<span style="font-size:var(--font-size-base);font-weight:400;color:var(--color-text-tertiary);">' + (suffix || '') + '</span></div>' +
                '<div class="tm-kpi-label">' + label + '</div>' +
                '</div>';
        }

        function scoreColor(score, isAgent) {
            if (score === null || score === '—') return 'var(--color-text-tertiary)';
            var n = parseFloat(score);
            if (isAgent) {
                if (n >= 20) return '#22c55e';
                if (n >= 0) return '#f59e0b';
                return '#ef4444';
            }
            if (n >= 70) return '#22c55e';
            if (n >= 40) return '#f59e0b';
            return '#ef4444';
        }

        function timingColor(score) {
            var n = parseFloat(score);
            if (n === 0) return '#22c55e';
            if (n >= -15) return '#f59e0b';
            return '#ef4444';
        }

        function problemColor(cat) {
            var colors = {
                'HR Violation': '#ef4444',
                'Ticket Gaming / Metric Manipulation': '#f97316',
                'Technical Inaccuracy': '#eab308',
                'Process Failure': '#6366f1',
                'Policy Violation': '#ec4899'
            };
            return colors[cat] || '#6366f1';
        }

        function esc(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
        </script>
        <?php
    }
}
