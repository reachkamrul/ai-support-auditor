<?php
/**
 * Agent Performance Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class AgentPerformance {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function render_list() {
        global $wpdb;
        
        // Get filters
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '';
        if ($period && is_numeric($period)) {
            $date_from = date('Y-m-d', strtotime('-' . intval($period) . ' days'));
            $date_to = date('Y-m-d');
        } else {
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');
        }
        $score_min = isset($_GET['score_min']) && $_GET['score_min'] !== '' ? intval($_GET['score_min']) : null;
        $score_max = isset($_GET['score_max']) && $_GET['score_max'] !== '' ? intval($_GET['score_max']) : null;
        $selected_agent = isset($_GET['filter_agent']) ? sanitize_email($_GET['filter_agent']) : '';
        
        // Build query
        $query = "
            SELECT 
                ae.agent_email,
                ae.agent_name,
                COUNT(DISTINCT ae.ticket_id) as total_tickets,
                ROUND(AVG(ae.overall_agent_score), 1) as avg_overall_score,
                ROUND(AVG(ae.timing_score), 1) as avg_timing_score,
                ROUND(AVG(ae.resolution_score), 1) as avg_resolution_score,
                ROUND(AVG(ae.communication_score), 1) as avg_communication_score,
                SUM(ae.reply_count) as total_replies
            FROM {$wpdb->prefix}ais_agent_evaluations ae
            WHERE DATE(ae.created_at) BETWEEN %s AND %s AND ae.exclude_from_stats = 0
        ";

        $params = [$date_from, $date_to];

        // Team filtering
        $team_agent_filter = AccessControl::sql_agent_filter('ae.agent_email');
        $query .= $team_agent_filter;

        if ($selected_agent) {
            $query .= " AND ae.agent_email = %s";
            $params[] = $selected_agent;
        }

        if ($score_min !== null) {
            $query .= " AND ae.overall_agent_score >= %d";
            $params[] = $score_min;
        }
        if ($score_max !== null) {
            $query .= " AND ae.overall_agent_score <= %d";
            $params[] = $score_max;
        }

        $query .= " GROUP BY ae.agent_email, ae.agent_name ORDER BY avg_overall_score DESC";

        $agents = $wpdb->get_results($wpdb->prepare($query, $params));

        // Calculate rankings
        $rank = 1;
        foreach ($agents as $agent) {
            $agent->rank = $rank++;
        }

        // Get all agents for filter (team-scoped for leads)
        $agent_list_filter = AccessControl::sql_agent_filter('agent_email');
        $all_agents = $wpdb->get_results("
            SELECT DISTINCT agent_email, agent_name
            FROM {$wpdb->prefix}ais_agent_evaluations
            WHERE 1=1 {$agent_list_filter}
            ORDER BY agent_name ASC
        ");
        
        ?>
        <style>
            /* Agent Performance Page Specific Styles */
            .agent-filters-card {
                margin-top: 0;
                margin-bottom: 24px;
            }
            
            .agent-filters-form {
                display: flex;
                gap: 10px;
                align-items: flex-end;
                flex-wrap: nowrap;
            }
            
            .agent-filters-form .audit-filter-group {
                display: flex;
                flex-direction: column;
                gap: 8px;
                flex-shrink: 0;
            }
            
            .agent-filters-form .audit-filter-group label {
                font-size: 12px;
                font-weight: 600;
                color: var(--color-text-secondary);
                letter-spacing: 0;
            }
            
            .agent-filters-form .ops-input {
                height: 38px;
                min-width: 0;
                border: 1px solid var(--color-border);
                border-radius: var(--radius-sm);
                font-size: 14px;
                padding: 0 12px;
                background: var(--color-bg);
                transition: all 0.15s ease;
            }
            
            .agent-filters-form .ops-input:focus {
                border-color: var(--color-primary);
                box-shadow: 0 0 0 3px var(--color-primary-light);
                outline: none;
            }
            
            .agent-filters-form .ops-input:hover {
                border-color: var(--color-border-strong);
            }
            
            .agent-filters-form select.ops-input {
                width: 160px;
                min-width: 160px;
            }
            
            .agent-filters-form input[type="date"].ops-input {
                width: 130px;
                min-width: 130px;
            }
            
            .agent-filters-form input[type="number"].ops-input {
                width: 80px;
                min-width: 80px;
            }
            
            .agent-filters-form input[type="text"].ops-input {
                width: 180px;
                min-width: 180px;
            }
            
            .agent-filters-form input[type="number"].ops-input::placeholder {
                color: var(--color-text-tertiary);
            }
            
            .agent-filters-actions {
                display: flex;
                gap: 8px;
                align-items: flex-end;
                flex-shrink: 0;
            }
            
            .agent-filters-actions .ops-btn {
                height: 38px;
                padding: 0 16px;
                white-space: nowrap;
            }
            
            .agent-list-card {
                margin-top: 0;
            }
            
            .agent-row {
                transition: all 0.2s ease;
            }
            
            .agent-row:hover {
                background: var(--color-bg-subtle);
            }
            
            .agent-name-cell {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            
            .agent-name-cell strong {
                font-weight: 600;
                color: var(--color-text-primary);
                font-size: 14px;
            }
            
            .agent-name-cell small {
                color: var(--color-text-tertiary);
                font-size: 12px;
            }
            
            .score-cell {
                text-align: center;
                font-weight: 600;
            }
            
            .score-positive {
                color: var(--color-success);
            }
            
            .score-negative {
                color: var(--color-error);
            }
            
            .agent-actions-cell {
                text-align: center;
            }
            
            .agent-actions-cell .ops-btn {
                height: 32px;
                padding: 0 16px;
                font-size: 12px;
            }
            
            .empty-agents {
                text-align: center;
                padding: 60px 20px;
                color: var(--color-text-secondary);
            }
            
            .empty-agents-text {
                font-size: 14px;
                margin: 0;
            }
            
            .sortable {
                cursor: pointer;
                user-select: none;
                position: relative;
            }
            
            .sortable:hover {
                background: var(--color-bg-hover);
            }
            
            .sort-indicator {
                font-size: 10px;
                margin-left: 4px;
                opacity: 0.5;
            }
            
            .sortable.sort-asc .sort-indicator::after {
                content: ' ↑';
                opacity: 1;
            }
            
            .sortable.sort-desc .sort-indicator::after {
                content: ' ↓';
                opacity: 1;
            }
        </style>
        
        <div class="wrap ops-wrapper">
            <!-- Filters -->
            <div class="ops-card agent-filters-card">
                <form method="get" class="agent-filters-form">
                    <input type="hidden" name="page" value="ai-ops">
                    <input type="hidden" name="tab" value="agent-performance">
                    
                    <div class="audit-filter-group">
                        <label>Search Agent</label>
                        <input type="text" 
                               id="agent-search" 
                               class="ops-input" 
                               placeholder="Search by name or email..." 
                               style="width: 250px;"
                               value="<?php echo isset($_GET['search']) ? esc_attr($_GET['search']) : ''; ?>">
                    </div>
                    
                    <div class="audit-filter-group" style="display: none;">
                        <label>Agent</label>
                        <select name="filter_agent" class="ops-input" id="filter-agent-select">
                            <option value="">All Agents</option>
                            <?php foreach ($all_agents as $ag): ?>
                                <option value="<?php echo esc_attr($ag->agent_email); ?>" <?php selected($selected_agent, $ag->agent_email); ?>>
                                    <?php echo esc_html($ag->agent_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="audit-filter-group">
                        <label>Time Period</label>
                        <select name="period" class="ops-input" id="period-selector" style="width: 180px;">
                            <option value="">Custom Range</option>
                            <option value="7" <?php selected($period, '7'); ?>>Last 7 days</option>
                            <option value="30" <?php selected($period, '30'); ?>>Last 30 days</option>
                            <option value="90" <?php selected($period, '90'); ?>>Last 3 months</option>
                            <option value="365" <?php selected($period, '365'); ?>>Last year</option>
                        </select>
                    </div>
                    
                    <div class="audit-filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" class="ops-input" value="<?php echo esc_attr($date_from); ?>">
                    </div>
                    
                    <div class="audit-filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" class="ops-input" value="<?php echo esc_attr($date_to); ?>">
                    </div>
                    
                    <div class="audit-filter-group">
                        <label>Min Score</label>
                        <input type="number" name="score_min" class="ops-input" value="<?php echo esc_attr($score_min); ?>" placeholder="Min">
                    </div>
                    
                    <div class="audit-filter-group">
                        <label>Max Score</label>
                        <input type="number" name="score_max" class="ops-input" value="<?php echo esc_attr($score_max); ?>" placeholder="Max">
                    </div>
                    
                    <div class="agent-filters-actions">
                        <label style="visibility: hidden; height: 0; margin: 0; padding: 0;">Actions</label>
                        <div style="display: flex; gap: 8px; align-items: flex-end;">
                        <button type="submit" class="ops-btn primary">Apply Filters</button>
                            <a href="<?php echo admin_url('admin.php?page=ai-ops&tab=agent-performance'); ?>" class="ops-btn secondary">Reset</a>
                        <a href="<?php echo admin_url('admin-post.php?action=export_agent_data&date_from=' . esc_attr($date_from) . '&date_to=' . esc_attr($date_to)); ?>" 
                               class="ops-btn secondary" 
                               style="width: 38px; padding: 0; display: flex; align-items: center; justify-content: center;"
                               title="Export to CSV">
                                📥
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Summary Stats -->
            <?php if (count($agents) > 0): 
                // Count distinct tickets across all agents (not sum, as multiple agents can work on same ticket)
                $total_tickets_query = "
                    SELECT COUNT(DISTINCT ticket_id) as total_tickets
                    FROM {$wpdb->prefix}ais_agent_evaluations
                    WHERE DATE(created_at) BETWEEN %s AND %s
                ";
                $total_tickets_params = [$date_from, $date_to];
                
                if ($selected_agent) {
                    $total_tickets_query .= " AND agent_email = %s";
                    $total_tickets_params[] = $selected_agent;
                }
                
                if ($score_min !== null) {
                    $total_tickets_query .= " AND overall_agent_score >= %d";
                    $total_tickets_params[] = $score_min;
                }
                if ($score_max !== null) {
                    $total_tickets_query .= " AND overall_agent_score <= %d";
                    $total_tickets_params[] = $score_max;
                }
                
                $total_tickets_result = $wpdb->get_var($wpdb->prepare($total_tickets_query, $total_tickets_params));
                $total_tickets = $total_tickets_result ? intval($total_tickets_result) : 0;
                
                $avg_team_score = round(array_sum(array_column($agents, 'avg_overall_score')) / count($agents), 1);
            ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Agents</div>
                    <div class="stat-value"><?php echo count($agents); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Unique Tickets</div>
                    <div class="stat-value"><?php echo $total_tickets; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Avg Team Score</div>
                    <div class="stat-value"><?php echo $avg_team_score; ?></div>
                    <?php if ($avg_team_score >= 70): ?>
                        <div class="stat-change positive">▲ Excellent</div>
                    <?php elseif ($avg_team_score >= 50): ?>
                        <div class="stat-change">→ Good</div>
                    <?php else: ?>
                        <div class="stat-change negative">▼ Needs Improvement</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Agent List -->
            <div class="ops-card agent-list-card">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">Rank</th>
                            <th style="width: 25%;" class="sortable" data-sort="agent">Agent <span class="sort-indicator">⇅</span></th>
                            <th style="text-align: center;" class="sortable" data-sort="overall">Overall Score <span class="sort-indicator">⇅</span></th>
                            <th style="text-align: center;" class="sortable" data-sort="timing">Timing <span class="sort-indicator">⇅</span></th>
                            <th style="text-align: center;" class="sortable" data-sort="resolution">Resolution <span class="sort-indicator">⇅</span></th>
                            <th style="text-align: center;" class="sortable" data-sort="communication">Communication <span class="sort-indicator">⇅</span></th>
                            <th style="text-align: center;" class="sortable" data-sort="tickets">Tickets <span class="sort-indicator">⇅</span></th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($agents) === 0): ?>
                            <tr>
                                <td colspan="8" class="empty-agents">
                                    <p class="empty-agents-text">No agents found for the selected filters.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($agents as $agent): ?>
                            <tr class="agent-row" 
                                data-agent-name="<?php echo strtolower(esc_attr($agent->agent_name)); ?>"
                                data-agent-email="<?php echo strtolower(esc_attr($agent->agent_email)); ?>"
                                data-overall="<?php echo esc_attr($agent->avg_overall_score); ?>"
                                data-timing="<?php echo esc_attr($agent->avg_timing_score); ?>"
                                data-resolution="<?php echo esc_attr($agent->avg_resolution_score); ?>"
                                data-communication="<?php echo esc_attr($agent->avg_communication_score); ?>"
                                data-tickets="<?php echo esc_attr($agent->total_tickets); ?>">
                                <td style="text-align: center; font-weight: 700; color: var(--color-text-primary);">
                                    <?php 
                                    $rank = isset($agent->rank) ? $agent->rank : 0;
                                    if ($rank == 1) {
                                        echo '<span style="color: #fbbf24;">🥇</span>';
                                    } elseif ($rank == 2) {
                                        echo '<span style="color: #94a3b8;">🥈</span>';
                                    } elseif ($rank == 3) {
                                        echo '<span style="color: #cd7f32;">🥉</span>';
                                    } else {
                                        echo '#' . $rank;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="agent-name-cell">
                                        <strong><?php echo esc_html($agent->agent_name); ?></strong>
                                        <small><?php echo esc_html($agent->agent_email); ?></small>
                                    </div>
                                </td>
                                <td class="score-cell">
                                    <?php
                                    $score = intval($agent->avg_overall_score);
                                    $score_class = Dashboard::score_class($score);
                                    ?>
                                    <span class="col-score <?php echo $score_class; ?>"><?php echo $score; ?></span>
                                </td>
                                <td class="score-cell">
                                    <span class="<?php echo intval($agent->avg_timing_score) >= 0 ? 'score-positive' : 'score-negative'; ?>">
                                        <?php echo intval($agent->avg_timing_score) > 0 ? '+' : ''; ?><?php echo intval($agent->avg_timing_score); ?>
                                    </span>
                                </td>
                                <td class="score-cell">
                                    <span class="<?php echo intval($agent->avg_resolution_score) >= 0 ? 'score-positive' : 'score-negative'; ?>">
                                        <?php echo intval($agent->avg_resolution_score) > 0 ? '+' : ''; ?><?php echo intval($agent->avg_resolution_score); ?>
                                    </span>
                                </td>
                                <td class="score-cell">
                                    <span class="<?php echo intval($agent->avg_communication_score) >= 0 ? 'score-positive' : 'score-negative'; ?>">
                                        <?php echo intval($agent->avg_communication_score) > 0 ? '+' : ''; ?><?php echo intval($agent->avg_communication_score); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;font-weight:700;color:var(--color-text-primary);">
                                    <?php echo intval($agent->total_tickets); ?>
                                </td>
                                <td class="agent-actions-cell">
                                    <a href="<?php echo admin_url('admin.php?page=ai-ops&tab=agent-performance&view=detail&agent=' . urlencode($agent->agent_email)); ?>" 
                                       class="ops-btn secondary">
                                        View Details
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const periodSelector = document.getElementById('period-selector');
            if (periodSelector) {
                periodSelector.addEventListener('change', function() {
                    if (this.value) {
                        const days = parseInt(this.value);
                        const dateTo = new Date().toISOString().split('T')[0];
                        const dateFrom = new Date(Date.now() - days * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                        const dateFromInput = document.querySelector('input[name="date_from"]');
                        const dateToInput = document.querySelector('input[name="date_to"]');
                        if (dateFromInput && dateToInput) {
                            dateFromInput.value = dateFrom;
                            dateToInput.value = dateTo;
                            document.querySelector('form.agent-filters-form').submit();
                        }
                    }
                });
            }
            
            // Search functionality
            const searchInput = document.getElementById('agent-search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('.agent-row');
                    rows.forEach(row => {
                        const name = row.getAttribute('data-agent-name') || '';
                        const email = row.getAttribute('data-agent-email') || '';
                        if (name.includes(searchTerm) || email.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
            
            // Column sorting
            let currentSort = { column: null, direction: 'asc' };
            document.querySelectorAll('.sortable').forEach(header => {
                header.addEventListener('click', function() {
                    const column = this.getAttribute('data-sort');
                    const rows = Array.from(document.querySelectorAll('.agent-row'));
                    
                    // Remove sort indicators
                    document.querySelectorAll('.sortable').forEach(h => {
                        h.classList.remove('sort-asc', 'sort-desc');
                    });
                    
                    // Determine sort direction
                    if (currentSort.column === column && currentSort.direction === 'asc') {
                        currentSort.direction = 'desc';
                        this.classList.add('sort-desc');
                    } else {
                        currentSort.direction = 'asc';
                        this.classList.add('sort-asc');
                    }
                    currentSort.column = column;
                    
                    // Sort rows
                    rows.sort((a, b) => {
                        let aVal, bVal;
                        if (column === 'agent') {
                            aVal = a.getAttribute('data-agent-name');
                            bVal = b.getAttribute('data-agent-name');
                        } else {
                            aVal = parseFloat(a.getAttribute('data-' + column)) || 0;
                            bVal = parseFloat(b.getAttribute('data-' + column)) || 0;
                        }
                        
                        if (currentSort.direction === 'asc') {
                            return aVal > bVal ? 1 : aVal < bVal ? -1 : 0;
                        } else {
                            return aVal < bVal ? 1 : aVal > bVal ? -1 : 0;
                        }
                    });
                    
                    // Re-append sorted rows
                    const tbody = document.querySelector('.audit-table tbody');
                    rows.forEach(row => tbody.appendChild(row));
                });
            });
        });
        </script>
        <?php
    }
    
    public function render_detail($agent_email) {
        global $wpdb;

        // Team access check: leads can only view their team's agents
        if (AccessControl::is_lead()) {
            $team_emails = AccessControl::get_team_agent_emails();
            if (!in_array($agent_email, $team_emails, true)) {
                echo '<div class="ops-card"><div class="ops-empty-state"><div class="ops-empty-state-title">Access denied</div><div class="ops-empty-state-description">You can only view agents in your team.</div></div></div>';
                return;
            }
        }

        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');
        $active_tab = isset($_GET['detail_tab']) ? sanitize_text_field($_GET['detail_tab']) : 'overview';
        
        // Get agent summary
        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT 
                ae.agent_name,
                ae.agent_email,
                COUNT(DISTINCT ae.ticket_id) as total_tickets,
                ROUND(AVG(ae.overall_agent_score), 1) as avg_overall_score,
                ROUND(AVG(ae.timing_score), 1) as avg_timing_score,
                ROUND(AVG(ae.resolution_score), 1) as avg_resolution_score,
                ROUND(AVG(ae.communication_score), 1) as avg_communication_score,
                ROUND(AVG(ae.contribution_percentage), 1) as avg_contribution_percentage,
                SUM(ae.reply_count) as total_replies
            FROM {$wpdb->prefix}ais_agent_evaluations ae
            WHERE ae.agent_email = %s AND DATE(ae.created_at) BETWEEN %s AND %s AND ae.exclude_from_stats = 0
            GROUP BY ae.agent_email, ae.agent_name
        ", $agent_email, $date_from, $date_to));
        
        if (!$summary) {
            echo '<div class="wrap"><h1>Agent not found</h1><a href="' . admin_url('admin.php?page=ai-ops&tab=agent-performance') . '">← Back to list</a></div>';
            return;
        }
        
        // Get tickets with all fields
        $tickets = $wpdb->get_results($wpdb->prepare("
            SELECT 
                ae.ticket_id,
                ae.overall_agent_score,
                ae.timing_score,
                ae.resolution_score,
                ae.communication_score,
                ae.contribution_percentage,
                ae.reply_count,
                ae.reasoning,
                ae.shift_compliance,
                ae.response_breakdown,
                ae.key_achievements,
                ae.areas_for_improvement,
                ae.created_at
            FROM {$wpdb->prefix}ais_agent_evaluations ae
            WHERE ae.agent_email = %s AND DATE(ae.created_at) BETWEEN %s AND %s
            ORDER BY ae.created_at DESC
        ", $agent_email, $date_from, $date_to));
        
        // Get audit data for tickets
        $audit_data = [];
        if (!empty($tickets)) {
            $ticket_ids = array_column($tickets, 'ticket_id');
            $placeholders = implode(',', array_fill(0, count($ticket_ids), '%s'));
            $audits = $wpdb->get_results($wpdb->prepare("
                SELECT ticket_id, audit_response, raw_json
                FROM {$wpdb->prefix}ais_audits
                WHERE ticket_id IN ($placeholders)
                AND id IN (
                    SELECT MAX(id) FROM {$wpdb->prefix}ais_audits
                    WHERE ticket_id IN ($placeholders)
                    GROUP BY ticket_id
                )
            ", array_merge($ticket_ids, $ticket_ids)));
            
            foreach ($audits as $audit) {
                $audit_data[$audit->ticket_id] = !empty($audit->audit_response) ? $audit->audit_response : $audit->raw_json;
            }
        }
        
        // Get latest insights (from most recent evaluation)
        $latest_insights = $wpdb->get_row($wpdb->prepare("
            SELECT 
                key_achievements,
                areas_for_improvement,
                reasoning,
                shift_compliance,
                response_breakdown
            FROM {$wpdb->prefix}ais_agent_evaluations
            WHERE agent_email = %s AND DATE(created_at) BETWEEN %s AND %s
            ORDER BY created_at DESC
            LIMIT 1
        ", $agent_email, $date_from, $date_to));
        
        ?>
        <style>
            /* Agent Detail Page Specific Styles */
            .agent-detail-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 24px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--color-border);
                flex-wrap: wrap;
                gap: 16px;
            }
            
            .agent-detail-nav {
                display: flex;
                gap: 12px;
                align-items: center;
            }
            
            .agent-detail-title {
                display: flex;
                align-items: center;
                gap: 16px;
            }
            
            .agent-detail-avatar {
                width: 56px;
                height: 56px;
                border-radius: 50%;
                background: var(--color-primary);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 20px;
                flex-shrink: 0;
                border: 3px solid var(--color-border);
            }
            
            .agent-detail-info {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            
            .agent-detail-title h1 {
                margin: 0;
                font-size: 24px;
                font-weight: 700;
                color: var(--color-text-primary);
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .agent-detail-title h1::before {
                content: "👤";
                font-size: 20px;
                opacity: 0.8;
            }
            
            .agent-detail-email {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: var(--color-text-secondary);
                font-size: 13px;
                margin: 0;
                padding: 6px 12px;
                background: var(--color-bg-subtle);
                border-radius: var(--radius-sm);
                border: 1px solid var(--color-border);
                font-family: var(--font-mono);
                font-weight: 500;
                width: fit-content;
            }
            
            .agent-detail-email::before {
                content: "✉️";
                font-size: 14px;
                opacity: 0.7;
            }
            
            .agent-detail-filters {
                margin-bottom: 24px;
            }
            
            .agent-detail-filters-form {
                display: flex;
                gap: 16px;
                align-items: flex-end;
                flex-wrap: wrap;
            }
            
            .agent-detail-filters-form .audit-filter-group {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            
            .agent-detail-filters-form .audit-filter-group label {
                font-size: 12px;
                font-weight: 600;
                color: var(--color-text-secondary);
                letter-spacing: 0;
            }
            
            .agent-detail-filters-form .ops-input {
                height: 38px;
                width: 150px;
                border: 1px solid var(--color-border);
                border-radius: var(--radius-sm);
                font-size: 14px;
                padding: 0 12px;
                background: var(--color-bg);
                transition: all 0.15s ease;
            }
            
            .agent-detail-filters-form .ops-input:focus {
                border-color: var(--color-primary);
                box-shadow: 0 0 0 3px var(--color-primary-light);
                outline: none;
            }
            
            .agent-detail-filters-form .ops-input:hover {
                border-color: var(--color-border-strong);
            }
            
            .agent-summary-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 20px;
                margin: 24px 0;
            }
            
            .agent-summary-card {
                text-align: center;
                padding: 24px;
                background: var(--color-bg);
                border: 1px solid var(--color-border);
                border-radius: var(--radius-md);
                box-shadow: var(--shadow-xs);
                transition: all 0.2s ease;
            }
            
            .agent-summary-card:hover {
                box-shadow: var(--shadow-sm);
                border-color: var(--color-border-strong);
                transform: translateY(-2px);
            }
            
            .agent-summary-label {
                font-size: 12px;
                color: var(--color-text-secondary);
                letter-spacing: 0;
                margin-bottom: 12px;
                font-weight: 600;
            }
            
            .agent-summary-value {
                font-size: 32px;
                font-weight: 700;
                color: var(--color-text-primary);
                line-height: 1.2;
            }
            
            .agent-tickets-preview {
                margin-top: 0;
            }
            
            .agent-tickets-preview h2 {
                margin: 0 0 20px 0;
                font-size: 18px;
                font-weight: 600;
                color: var(--color-text-primary);
            }
            
            .view-all-link {
                margin-top: 20px;
            }
        </style>
        
        <div class="wrap ops-wrapper">
            <div class="agent-detail-header">
                <div class="agent-detail-title">
                    <div class="agent-detail-avatar">
                        <?php 
                        $initials = '';
                        $name_parts = explode(' ', $summary->agent_name);
                        if (count($name_parts) >= 2) {
                            $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[1], 0, 1));
                        } else {
                            $initials = strtoupper(substr($summary->agent_name, 0, 2));
                        }
                        echo esc_html($initials);
                        ?>
                    </div>
                    <div class="agent-detail-info">
                        <h1><?php echo esc_html($summary->agent_name); ?></h1>
                        <p class="agent-detail-email"><?php echo esc_html($summary->agent_email); ?></p>
                    </div>
                </div>
                <div class="agent-detail-nav">
                    <a href="<?php echo admin_url('admin.php?page=ai-ops&tab=agent-performance'); ?>" class="ops-btn secondary">← Back to All Agents</a>
                <a href="<?php echo admin_url('admin-post.php?action=export_agent_data&agent=' . urlencode($agent_email) . '&date_from=' . esc_attr($date_from) . '&date_to=' . esc_attr($date_to)); ?>" 
                   class="ops-btn secondary">
                        Export Data
                </a>
                </div>
            </div>
            
            <!-- Date Filter -->
            <div class="ops-card agent-detail-filters">
                <form method="get" class="agent-detail-filters-form">
                    <input type="hidden" name="page" value="ai-ops">
                    <input type="hidden" name="tab" value="agent-performance">
                    <input type="hidden" name="view" value="detail">
                    <input type="hidden" name="agent" value="<?php echo esc_attr($agent_email); ?>">
                    <input type="hidden" name="detail_tab" value="<?php echo esc_attr($active_tab); ?>">
                    
                    <div class="audit-filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" class="ops-input" value="<?php echo esc_attr($date_from); ?>">
                    </div>
                    
                    <div class="audit-filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" class="ops-input" value="<?php echo esc_attr($date_to); ?>">
                    </div>
                    
                    <div class="audit-filter-group">
                        <button type="submit" class="ops-btn primary">Apply</button>
                    </div>
                </form>
            </div>
            
            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper" style="margin: 20px 0;">
                <a href="?page=ai-ops&tab=agent-performance&view=detail&agent=<?php echo urlencode($agent_email); ?>&detail_tab=overview&date_from=<?php echo esc_attr($date_from); ?>&date_to=<?php echo esc_attr($date_to); ?>" 
                   class="nav-tab <?php echo $active_tab == 'overview' ? 'nav-tab-active' : ''; ?>">
                    Overview
                </a>
                <a href="?page=ai-ops&tab=agent-performance&view=detail&agent=<?php echo urlencode($agent_email); ?>&detail_tab=tickets&date_from=<?php echo esc_attr($date_from); ?>&date_to=<?php echo esc_attr($date_to); ?>" 
                   class="nav-tab <?php echo $active_tab == 'tickets' ? 'nav-tab-active' : ''; ?>">
                    🎫 Tickets
                </a>
                <a href="?page=ai-ops&tab=agent-performance&view=detail&agent=<?php echo urlencode($agent_email); ?>&detail_tab=insights&date_from=<?php echo esc_attr($date_from); ?>&date_to=<?php echo esc_attr($date_to); ?>" 
                   class="nav-tab <?php echo $active_tab == 'insights' ? 'nav-tab-active' : ''; ?>">
                    💡 Insights
                </a>
            </nav>
            
            <?php if ($active_tab == 'overview'): ?>
                <?php $this->render_overview_tab($summary, $tickets, $latest_insights, $audit_data); ?>
            <?php elseif ($active_tab == 'tickets'): ?>
                <?php $this->render_tickets_tab($tickets, $audit_data); ?>
            <?php elseif ($active_tab == 'insights'): ?>
                <?php $this->render_insights_tab($latest_insights, $summary); ?>
            <?php endif; ?>
            
            <?php AuditModal::render_modal_html(); ?>

            <script>
            jQuery(document).ready(function($){
                <?php AuditModal::render_modal_js(); ?>

                // Store audit data for modal
                <?php foreach ($audit_data as $ticket_id => $data): ?>
                window.auditDataStore['<?php echo esc_js($ticket_id); ?>'] = <?php echo json_encode($data); ?>;
                <?php endforeach; ?>

                // Handle View Audit button clicks — open modal with parsed view
                $(document).on('click', '.btn-view-audit', function(e){
                    e.preventDefault();
                    var ticketId = $(this).data('id');
                    var txt = window.auditDataStore[ticketId] || '';
                    openAuditModal(ticketId, 0, txt);
                });
            });
            </script>
        </div>
        <?php
    }
    
    private function render_overview_tab($summary, $tickets, $latest_insights = null, $audit_data = []) {
        global $wpdb;
        
        // Get score trends for chart (last 30 days, grouped by week)
        $trends_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                AVG(overall_agent_score) as avg_score,
                AVG(timing_score) as avg_timing,
                AVG(resolution_score) as avg_resolution,
                AVG(communication_score) as avg_communication
            FROM {$wpdb->prefix}ais_agent_evaluations
            WHERE agent_email = %s
            AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND exclude_from_stats = 0
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", $summary->agent_email));
        
        // Get team average for comparison
        $team_avg = $wpdb->get_row($wpdb->prepare("
            SELECT 
                AVG(overall_agent_score) as avg_overall,
                AVG(timing_score) as avg_timing,
                AVG(resolution_score) as avg_resolution,
                AVG(communication_score) as avg_communication
            FROM {$wpdb->prefix}ais_agent_evaluations
            WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND exclude_from_stats = 0
        "));
        
        // Prepare chart data
        $chart_labels = [];
        $chart_overall = [];
        $chart_timing = [];
        $chart_resolution = [];
        $chart_communication = [];
        
        foreach ($trends_data as $trend) {
            $chart_labels[] = date('M j', strtotime($trend->date));
            $chart_overall[] = round($trend->avg_score, 1);
            $chart_timing[] = round($trend->avg_timing, 1);
            $chart_resolution[] = round($trend->avg_resolution, 1);
            $chart_communication[] = round($trend->avg_communication, 1);
        }
        
        ?>
        <!-- Summary Cards -->
        <div class="agent-summary-grid">
            <div class="agent-summary-card">
                <div class="agent-summary-label">Overall Score</div>
                <div class="agent-summary-value" style="color: var(--color-primary);"><?php echo intval($summary->avg_overall_score); ?></div>
            </div>
            <div class="agent-summary-card">
                <div class="agent-summary-label">Timing Score</div>
                <div class="agent-summary-value" style="color: <?php echo intval($summary->avg_timing_score) >= 0 ? 'var(--color-success)' : 'var(--color-error)'; ?>;">
                    <?php echo intval($summary->avg_timing_score) > 0 ? '+' : ''; ?><?php echo intval($summary->avg_timing_score); ?>
                </div>
            </div>
            <div class="agent-summary-card">
                <div class="agent-summary-label">Resolution Score</div>
                <div class="agent-summary-value" style="color: <?php echo intval($summary->avg_resolution_score) >= 0 ? 'var(--color-success)' : 'var(--color-error)'; ?>;">
                    <?php echo intval($summary->avg_resolution_score) > 0 ? '+' : ''; ?><?php echo intval($summary->avg_resolution_score); ?>
                </div>
            </div>
            <div class="agent-summary-card">
                <div class="agent-summary-label">Communication</div>
                <div class="agent-summary-value" style="color: <?php echo intval($summary->avg_communication_score) >= 0 ? 'var(--color-success)' : 'var(--color-error)'; ?>;">
                    <?php echo intval($summary->avg_communication_score) > 0 ? '+' : ''; ?><?php echo intval($summary->avg_communication_score); ?>
                </div>
            </div>
            <div class="agent-summary-card">
                <div class="agent-summary-label">Total Tickets</div>
                <div class="agent-summary-value" style="color: var(--color-primary);"><?php echo intval($summary->total_tickets); ?></div>
            </div>
            <?php if (isset($summary->avg_contribution_percentage) && $summary->avg_contribution_percentage > 0): ?>
            <div class="agent-summary-card">
                <div class="agent-summary-label">Avg Contribution</div>
                <div class="agent-summary-value" style="color: var(--color-info);"><?php echo round($summary->avg_contribution_percentage, 1); ?>%</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Score Trends Chart -->
        <?php if (count($chart_labels) > 0): ?>
        <div class="ops-card" style="margin-bottom: 24px;">
            <h2 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: var(--color-text-primary);">Score Trends (Last 30 Days)</h2>
            <canvas id="scoreTrendsChart" style="max-height: 300px;"></canvas>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('scoreTrendsChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($chart_labels); ?>,
                        datasets: [{
                            label: 'Overall Score',
                            data: <?php echo json_encode($chart_overall); ?>,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4
                        }, {
                            label: 'Timing',
                            data: <?php echo json_encode($chart_timing); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4
                        }, {
                            label: 'Resolution',
                            data: <?php echo json_encode($chart_resolution); ?>,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            tension: 0.4
                        }, {
                            label: 'Communication',
                            data: <?php echo json_encode($chart_communication); ?>,
                            borderColor: '#8b5cf6',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
        });
        </script>
        
        <!-- Team Comparison Chart -->
        <?php if ($team_avg): ?>
        <div class="ops-card" style="margin-bottom: 24px;">
            <h2 style="margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: var(--color-text-primary);">Agent vs Team Average</h2>
            <canvas id="teamComparisonChart" style="max-height: 300px;"></canvas>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx2 = document.getElementById('teamComparisonChart');
            if (ctx2) {
                new Chart(ctx2, {
                    type: 'bar',
                    data: {
                        labels: ['Overall Score', 'Timing', 'Resolution', 'Communication'],
                        datasets: [{
                            label: 'This Agent',
                            data: [
                                <?php echo round($summary->avg_overall_score, 1); ?>,
                                <?php echo round($summary->avg_timing_score, 1); ?>,
                                <?php echo round($summary->avg_resolution_score, 1); ?>,
                                <?php echo round($summary->avg_communication_score, 1); ?>
                            ],
                            backgroundColor: '#3b82f6',
                            borderColor: '#2563eb',
                            borderWidth: 1
                        }, {
                            label: 'Team Average',
                            data: [
                                <?php echo round($team_avg->avg_overall, 1); ?>,
                                <?php echo round($team_avg->avg_timing, 1); ?>,
                                <?php echo round($team_avg->avg_resolution, 1); ?>,
                                <?php echo round($team_avg->avg_communication, 1); ?>
                            ],
                            backgroundColor: '#94a3b8',
                            borderColor: '#64748b',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }
        });
        </script>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Recent Tickets Preview -->
        <div class="ops-card agent-tickets-preview">
            <h2>Recent Tickets (Last 5)</h2>
            <?php 
            $recent_tickets = array_slice($tickets, 0, 5);
            $recent_audit_data = [];
            if (!empty($audit_data)) {
                foreach ($recent_tickets as $ticket) {
                    if (isset($audit_data[$ticket->ticket_id])) {
                        $recent_audit_data[$ticket->ticket_id] = $audit_data[$ticket->ticket_id];
                    }
                }
            }
            $this->render_tickets_table($recent_tickets, $recent_audit_data); 
            ?>
            <?php if (count($tickets) > 5): ?>
                <div class="view-all-link">
                    <a href="?page=ai-ops&tab=agent-performance&view=detail&agent=<?php echo urlencode($summary->agent_email); ?>&detail_tab=tickets" class="ops-btn secondary">
                        View All <?php echo count($tickets); ?> Tickets →
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_tickets_tab($tickets) {
        ?>
        <div class="ops-card agent-tickets-preview">
            <h2>All Tickets (<?php echo count($tickets); ?> total)</h2>
            <?php $this->render_tickets_table($tickets); ?>
        </div>
        <?php
    }
    
    private function render_insights_tab($latest_insights, $summary) {
        ?>
        <div class="ops-card">
            <h2>AI-Generated Insights</h2>
            <p style="color: var(--color-text-secondary); margin-bottom: 24px;">Based on the most recent evaluation</p>
            
            <?php if ($latest_insights && !empty($latest_insights->key_achievements)): ?>
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <span>✅</span> Key Achievements
                </h3>
                <div style="padding: 16px; background: var(--color-success-bg); border-left: 4px solid var(--color-success); border-radius: var(--radius-sm); color: var(--color-text-primary); line-height: 1.6;">
                    <?php echo nl2br(esc_html($latest_insights->key_achievements)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($latest_insights && !empty($latest_insights->areas_for_improvement)): ?>
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <span>📈</span> Areas for Improvement
                </h3>
                <div style="padding: 16px; background: var(--color-warning-bg); border-left: 4px solid var(--color-warning); border-radius: var(--radius-sm); color: var(--color-text-primary); line-height: 1.6;">
                    <?php echo nl2br(esc_html($latest_insights->areas_for_improvement)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($latest_insights && !empty($latest_insights->reasoning)): ?>
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <span>🧠</span> AI Reasoning
                </h3>
                <div style="padding: 16px; background: var(--color-bg-subtle); border: 1px solid var(--color-border); border-radius: var(--radius-sm); color: var(--color-text-primary); line-height: 1.6; font-family: var(--font-sans);">
                    <?php echo nl2br(esc_html($latest_insights->reasoning)); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($latest_insights && !empty($latest_insights->shift_compliance)): ?>
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <span>⏰</span> Shift Compliance
                </h3>
                <div style="padding: 16px; background: var(--color-info-bg); border-left: 4px solid var(--color-info); border-radius: var(--radius-sm); color: var(--color-text-primary); line-height: 1.6;">
                    <?php 
                    $shift_data = json_decode($latest_insights->shift_compliance, true);
                    if (is_array($shift_data)) {
                        echo '<pre style="margin: 0; font-family: var(--font-mono); font-size: 13px;">' . esc_html(print_r($shift_data, true)) . '</pre>';
                    } else {
                        echo nl2br(esc_html($latest_insights->shift_compliance));
                    }
                    ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($latest_insights && !empty($latest_insights->response_breakdown)): ?>
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 16px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <span>📊</span> Response Breakdown
                </h3>
                <div style="padding: 16px; background: var(--color-bg-subtle); border: 1px solid var(--color-border); border-radius: var(--radius-sm); color: var(--color-text-primary); line-height: 1.6; font-family: var(--font-mono); font-size: 13px;">
                    <?php 
                    $breakdown = json_decode($latest_insights->response_breakdown, true);
                    if (is_array($breakdown)) {
                        echo '<pre style="margin: 0;">' . esc_html(print_r($breakdown, true)) . '</pre>';
                    } else {
                        echo nl2br(esc_html($latest_insights->response_breakdown));
                    }
                    ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!$latest_insights || (empty($latest_insights->key_achievements) && empty($latest_insights->areas_for_improvement) && empty($latest_insights->reasoning))): ?>
            <div style="padding: 40px; text-align: center; color: var(--color-text-secondary);">
                <p>No insights available for this agent in the selected date range.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_tickets_table($tickets) {
        ?>
        <table class="audit-table">
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Date</th>
                    <th style="text-align: center;">Overall</th>
                    <th style="text-align: center;">Timing</th>
                    <th style="text-align: center;">Resolution</th>
                    <th style="text-align: center;">Communication</th>
                    <th style="text-align: center;">Contribution</th>
                    <th style="text-align: center;">Replies</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr>
                        <td colspan="9" class="empty-agents">
                            <p class="empty-agents-text">No tickets found for this agent.</p>
                        </td>
                    </tr>
                <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <tr class="agent-row">
                        <td><strong style="color:var(--color-text-primary);">#<?php echo esc_html($ticket->ticket_id); ?></strong></td>
                        <td style="color:var(--color-text-secondary);"><?php echo wp_date('Y-m-d', strtotime($ticket->created_at)); ?></td>
                        <td class="score-cell">
                        <?php
                        $score = intval($ticket->overall_agent_score);
                        $score_class = Dashboard::score_class($score);
                        ?>
                        <span class="col-score <?php echo $score_class; ?>"><?php echo $score; ?></span>
                    </td>
                        <td class="score-cell">
                            <span class="<?php echo intval($ticket->timing_score) >= 0 ? 'score-positive' : 'score-negative'; ?>">
                        <?php echo intval($ticket->timing_score) > 0 ? '+' : ''; ?><?php echo intval($ticket->timing_score); ?>
                            </span>
                    </td>
                        <td class="score-cell">
                            <span class="<?php echo intval($ticket->resolution_score) >= 0 ? 'score-positive' : 'score-negative'; ?>">
                        <?php echo intval($ticket->resolution_score) > 0 ? '+' : ''; ?><?php echo intval($ticket->resolution_score); ?>
                            </span>
                    </td>
                        <td class="score-cell">
                            <span class="<?php echo intval($ticket->communication_score) >= 0 ? 'score-positive' : 'score-negative'; ?>">
                        <?php echo intval($ticket->communication_score) > 0 ? '+' : ''; ?><?php echo intval($ticket->communication_score); ?>
                            </span>
                    </td>
                        <td style="text-align: center;font-weight:600;color:var(--color-text-primary);">
                            <?php 
                            if (isset($ticket->contribution_percentage) && $ticket->contribution_percentage > 0) {
                                echo round($ticket->contribution_percentage, 1) . '%';
                            } else {
                                echo '-';
                            }
                            ?>
                    </td>
                        <td style="text-align: center;font-weight:600;color:var(--color-text-primary);"><?php echo intval($ticket->reply_count); ?></td>
                        <td>
                            <button class="ops-btn secondary btn-view-audit" 
                                    data-id="<?php echo esc_attr($ticket->ticket_id); ?>" 
                                    style="height: 32px; padding: 0 14px; font-size: 12px; cursor: pointer;">
                            View Audit
                            </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}