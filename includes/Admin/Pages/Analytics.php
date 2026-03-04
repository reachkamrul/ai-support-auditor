<?php
/**
 * Analytics Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class Analytics {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function render() {
        global $wpdb;
        
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $date_from = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        // Fetch analytics data
        $agent_stats = $this->get_agent_stats($date_from);
        $problem_stats = $this->get_problem_stats($date_from);
        $doc_gaps = $this->get_doc_gaps($date_from);
        $faq_topics = $this->get_faq_topics($date_from);
        
        $total_audits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ais_audits 
             WHERE status = 'success' AND created_at >= %s",
            $date_from
        ));
        
        $avg_score = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(AVG(overall_agent_score), 0) 
             FROM {$wpdb->prefix}ais_agent_evaluations 
             WHERE created_at >= %s",
            $date_from
        ));
        
        $total_agents = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT agent_email) 
             FROM {$wpdb->prefix}ais_agent_evaluations 
             WHERE created_at >= %s",
            $date_from
        ));
        
        $this->render_filters($days);
        $this->render_summary_stats($total_audits, $avg_score, $total_agents, $problem_stats);
        $this->render_agent_leaderboard($agent_stats, $problem_stats, $date_from);
        $this->render_problem_categories($problem_stats);
        $this->render_doc_gaps($doc_gaps);
        $this->render_faq_recommendations($faq_topics);
    }
    
    private function get_agent_stats($date_from) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                ae.agent_email,
                ae.agent_name,
                COUNT(DISTINCT ae.ticket_id) as tickets_handled,
                ROUND(AVG(ae.overall_agent_score), 1) as avg_overall_score,
                ROUND(AVG(ae.timing_score), 1) as avg_timing_score,
                ROUND(AVG(ae.resolution_score), 1) as avg_resolution_score,
                ROUND(AVG(ae.communication_score), 1) as avg_communication_score,
                SUM(ae.reply_count) as total_replies
            FROM {$wpdb->prefix}ais_agent_evaluations ae
            WHERE ae.created_at >= %s
            GROUP BY ae.agent_email, ae.agent_name
            HAVING tickets_handled > 0
            ORDER BY avg_overall_score DESC, tickets_handled DESC
            LIMIT 10
        ", $date_from));
    }
    
    private function get_problem_stats($date_from) {
        global $wpdb;
        
        // Get problem categories from the problem_contexts table
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                pc.category,
                COUNT(DISTINCT pc.ticket_id) as count
             FROM {$wpdb->prefix}ais_problem_contexts pc
             INNER JOIN {$wpdb->prefix}ais_audits a ON pc.ticket_id = a.ticket_id
             WHERE a.status = 'success' 
             AND a.created_at >= %s
             AND pc.category IS NOT NULL
             AND pc.category != ''
             GROUP BY pc.category
             ORDER BY count DESC",
            $date_from
        ));
        
        $stats = [];
        foreach ($results as $result) {
            $stats[$result->category] = intval($result->count);
                }
        
        return $stats;
    }
    
    private function get_doc_gaps($date_from) {
        global $wpdb;
        
        // Get documentation gaps from topic_stats where doc update is needed
        // Also check problem_contexts to get recent gaps
        $date_from_date = date('Y-m-d', strtotime($date_from));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                ts.topic_label,
                ts.ticket_count,
                ts.category
             FROM {$wpdb->prefix}ais_topic_stats ts
             WHERE ts.is_doc_update_needed = 1
             AND DATE(ts.last_seen) >= %s
             ORDER BY ts.ticket_count DESC
             LIMIT 15",
            $date_from_date
        ));
        
        $gaps = [];
        foreach ($results as $result) {
            $label = $result->topic_label ?: $result->category;
            if ($label) {
                $gaps[$label] = intval($result->ticket_count);
                }
            }
        
        return $gaps;
    }
    
    private function get_faq_topics($date_from) {
        global $wpdb;
        
        // Get FAQ candidates from topic_stats where is_faq_candidate = 1
        $date_from_date = date('Y-m-d', strtotime($date_from));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                ts.topic_label,
                ts.ticket_count,
                ts.category
             FROM {$wpdb->prefix}ais_topic_stats ts
             WHERE ts.is_faq_candidate = 1
             AND DATE(ts.last_seen) >= %s
             ORDER BY ts.ticket_count DESC
             LIMIT 15",
            $date_from_date
        ));
        
        $topics = [];
        foreach ($results as $result) {
            $label = $result->topic_label ?: $result->category;
            if ($label) {
                $topics[$label] = intval($result->ticket_count);
                }
            }
        
        return $topics;
    }
    
    private function render_filters($days) {
        ?>
        <style>
            /* Analytics Page Specific Styles */
            .analytics-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 0;
                flex-wrap: wrap;
                gap: 16px;
            }
            
            .analytics-header h3 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: var(--color-text-primary);
            }
            
            .analytics-filters {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            
            .analytics-filters .ops-btn {
                padding: 0 16px;
                height: 36px;
                font-size: 13px;
                font-weight: 500;
                border-radius: var(--radius-sm);
                transition: all 0.2s ease;
            }
            
            .analytics-filters .ops-btn.secondary {
                background: var(--color-bg);
                color: var(--color-text-secondary);
                border: 1px solid var(--color-border);
            }
            
            .analytics-filters .ops-btn.secondary:hover {
                background: var(--color-bg-subtle);
                border-color: var(--color-border-strong);
                color: var(--color-text-primary);
            }
            
            .analytics-filters .ops-btn.primary {
                background: var(--color-primary);
                color: white;
                border: 1px solid var(--color-primary);
            }
            
            .analytics-filters .ops-btn.primary:hover {
                background: var(--color-primary-hover);
                border-color: var(--color-primary-hover);
            }
            
            /* stats-grid/stat-card overrides removed — using global styles */
            
            /* stat-card overrides removed — using global styles */
            
            .analytics-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                margin-bottom: 24px;
            }
            
            @media (max-width: 1200px) {
                .analytics-grid {
                    grid-template-columns: 1fr;
                }
            }
            
            .analytics-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 16px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--color-border);
            }
            
            .analytics-card-header h3 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--color-text-primary);
            }
            
            .analytics-card-description {
                color: var(--color-text-secondary);
                font-size: 13px;
                line-height: 1.5;
                margin-bottom: 16px;
            }
            
            .leaderboard-link {
                font-size: 12px;
                padding: 6px 12px;
                height: 28px;
                text-decoration: none;
            }
            
            .rank-cell {
                font-weight: 700;
                font-size: 16px;
                width: 60px;
                text-align: center;
            }
            
            .rank-medal {
                font-size: 20px;
            }
            
            .agent-name-cell {
                font-weight: 600;
                color: var(--color-text-primary);
            }
            
            /* empty-state removed — using global .ops-empty-state */
        </style>
        
        <div class="ops-card" style="margin-bottom:24px;">
            <div class="analytics-header">
                <h3>Analytics Dashboard</h3>
                <div class="analytics-filters">
                    <a href="?page=ai-ops&section=analytics&days=7" class="ops-btn <?php echo $days==7?'primary':'secondary'; ?>">7 Days</a>
                    <a href="?page=ai-ops&section=analytics&days=30" class="ops-btn <?php echo $days==30?'primary':'secondary'; ?>">30 Days</a>
                    <a href="?page=ai-ops&section=analytics&days=90" class="ops-btn <?php echo $days==90?'primary':'secondary'; ?>">90 Days</a>
                    <a href="?page=ai-ops&section=analytics&days=365" class="ops-btn <?php echo $days==365?'primary':'secondary'; ?>">1 Year</a>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function render_summary_stats($total_audits, $avg_score, $total_agents, $problem_stats) {
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total audits</div>
                <div class="stat-value"><?php echo number_format($total_audits); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Average score</div>
                <div class="stat-value" style="<?php echo $avg_score < 0 ? 'color: var(--color-error);' : ''; ?>">
                    <?php echo $avg_score > 0 ? round($avg_score) : ($total_audits > 0 ? '0' : '-'); ?>
                </div>
                <?php if ($avg_score > 0 || $total_audits > 0): ?>
                    <?php if ($avg_score >= 70): ?>
                        <div class="stat-change positive">▲ Excellent</div>
                    <?php elseif ($avg_score >= 50): ?>
                        <div class="stat-change">→ Good</div>
                    <?php elseif ($avg_score > 0): ?>
                        <div class="stat-change">→ Fair</div>
                    <?php else: ?>
                        <div class="stat-change negative">▼ Below Baseline</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Agents</div>
                <div class="stat-value"><?php echo number_format($total_agents); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Problem Types</div>
                <div class="stat-value"><?php echo count($problem_stats); ?></div>
            </div>
        </div>
        <?php
    }
    
    private function render_agent_leaderboard($agent_stats, $problem_stats, $date_from) {
        ?>
        <div class="analytics-grid">
            <div class="ops-card">
                <div class="analytics-card-header">
                <h3>Top performing agents</h3>
                    <a href="<?php echo admin_url('admin.php?page=ai-ops&section=agent-performance'); ?>" class="ops-btn secondary leaderboard-link">
                        View Full Dashboard →
                    </a>
                </div>
                <p class="analytics-card-description">Ranked by overall performance score</p>
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Agent</th>
                            <th style="text-align:center;">Score</th>
                            <th style="text-align:center;">Tickets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agent_stats)): ?>
                            <tr>
                                <td colspan="4" class="ops-empty-state">
                                    <div class="ops-empty-state-title">No data available</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $rank = 1; foreach ($agent_stats as $stat): ?>
                                <tr>
                                    <td class="rank-cell">
                                        <?php 
                                        if ($rank == 1) echo '<span class="rank-medal">🥇</span>';
                                        elseif ($rank == 2) echo '<span class="rank-medal">🥈</span>';
                                        elseif ($rank == 3) echo '<span class="rank-medal">🥉</span>';
                                        else echo "#$rank";
                                        ?>
                                    </td>
                                    <td>
                                        <span class="agent-name-cell"><?php echo esc_html($stat->agent_name); ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php
                                        $score = intval($stat->avg_overall_score);
                                        $score_class = Dashboard::score_class($score);
                                        ?>
                                        <span class="col-score <?php echo $score_class; ?>"><?php echo $score; ?></span>
                                    </td>
                                    <td style="text-align:center;font-weight:700;color:var(--color-text-primary);">
                                        <?php echo number_format($stat->tickets_handled); ?>
                                    </td>
                                </tr>
                            <?php $rank++; endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php $this->render_problem_categories_inline($problem_stats); ?>
        </div>
        <?php
    }
    
    private function render_problem_categories_inline($problem_stats) {
        ?>
        <div class="ops-card">
            <div class="analytics-card-header">
            <h3>Common Problem Categories</h3>
            </div>
            <?php if (empty($problem_stats)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No problems identified yet</div>
                </div>
            <?php else: ?>
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Problem Category</th>
                            <th width="80">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $shown = 0; foreach ($problem_stats as $category => $count): ?>
                            <?php if ($shown++ >= 10) break; ?>
                            <tr>
                                <td style="color:var(--color-text-primary);"><?php echo esc_html($category); ?></td>
                                <td><span class="status-badge"><?php echo $count; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_problem_categories($problem_stats) {
        // Handled inline above
    }
    
    private function render_doc_gaps($doc_gaps) {
        ?>
        <div class="ops-card" style="margin-bottom:24px;">
            <div class="analytics-card-header">
            <h3>Documentation gaps</h3>
            </div>
            <p class="analytics-card-description">AI-identified knowledge base gaps that need documentation</p>
            <?php if (empty($doc_gaps)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No documentation gaps identified yet</div>
                </div>
            <?php else: ?>
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Gap Description</th>
                            <th width="100">Frequency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $shown = 0; foreach ($doc_gaps as $gap => $count): ?>
                            <?php if ($shown++ >= 15) break; ?>
                            <tr>
                                <td style="color:var(--color-text-primary);"><?php echo esc_html($gap); ?></td>
                                <td><span class="status-badge <?php echo $count >= 5 ? 'failed' : ($count >= 3 ? '' : 'success'); ?>"><?php echo $count; ?>x</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_faq_recommendations($faq_topics) {
        ?>
        <div class="ops-card">
            <div class="analytics-card-header">
            <h3>Recommended FAQ topics</h3>
            </div>
            <p class="analytics-card-description">AI-suggested FAQ articles based on recurring customer questions</p>
            <?php if (empty($faq_topics)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No FAQ recommendations yet</div>
                </div>
            <?php else: ?>
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>FAQ Topic</th>
                            <th width="100">Frequency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $shown = 0; foreach ($faq_topics as $faq => $count): ?>
                            <?php if ($shown++ >= 15) break; ?>
                            <tr>
                                <td style="color:var(--color-text-primary);"><?php echo esc_html($faq); ?></td>
                                <td><span class="status-badge <?php echo $count >= 5 ? 'failed' : ($count >= 3 ? '' : 'success'); ?>"><?php echo $count; ?>x</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}