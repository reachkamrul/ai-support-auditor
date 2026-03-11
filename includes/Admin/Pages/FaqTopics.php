<?php
/**
 * FAQ Topics Page
 *
 * Full-page list of AI-identified FAQ candidates from ais_topic_stats.
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Admin\AccessControl;

class FaqTopics {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        $days = isset($_GET['days']) ? intval($_GET['days']) : 0;
        $date_filter = '';
        $date_from_date = '';
        if ($days > 0) {
            $date_from_date = date('Y-m-d', strtotime("-{$days} days"));
            $date_filter = $wpdb->prepare(" AND DATE(ts.last_seen) >= %s", $date_from_date);
        }

        // Fetch all FAQ candidates
        $results = $wpdb->get_results(
            "SELECT
                ts.topic_label,
                ts.category,
                ts.ticket_count,
                ts.last_seen
             FROM {$wpdb->prefix}ais_topic_stats ts
             WHERE ts.is_faq_candidate = 1{$date_filter}
             ORDER BY ts.ticket_count DESC"
        );

        // Doc search index
        $doc_index = $this->get_doc_search_index();

        // Summary stats
        $total_candidates = count($results);
        $with_docs = 0;
        $without_docs = 0;
        $highest_frequency = 0;

        $rows = [];
        foreach ($results as $result) {
            $label = $result->topic_label ?: $result->category;
            if (!$label) {
                continue;
            }

            $has_doc = $this->check_doc_match($label, $doc_index);
            if ($has_doc) {
                $with_docs++;
            } else {
                $without_docs++;
            }

            $count = intval($result->ticket_count);
            if ($count > $highest_frequency) {
                $highest_frequency = $count;
            }

            $rows[] = [
                'label'     => $label,
                'category'  => $result->category ?: '-',
                'count'     => $count,
                'last_seen' => $result->last_seen,
                'has_doc'   => $has_doc,
            ];
        }

        $this->render_filters($days);
        $this->render_summary_stats($total_candidates, $with_docs, $without_docs, $highest_frequency);
        $this->render_table($rows);
    }

    private function render_filters($days) {
        $base_url = admin_url('admin.php?page=ai-ops&section=faq-topics');
        ?>
        <div class="ops-card" style="margin-bottom:24px;">
            <div class="analytics-header">
                <h3>FAQ Candidates</h3>
                <div class="analytics-filters">
                    <a href="<?php echo esc_url(add_query_arg('days', '7', $base_url)); ?>" class="ops-btn <?php echo $days === 7 ? 'primary' : 'secondary'; ?>">7 Days</a>
                    <a href="<?php echo esc_url(add_query_arg('days', '30', $base_url)); ?>" class="ops-btn <?php echo $days === 30 ? 'primary' : 'secondary'; ?>">30 Days</a>
                    <a href="<?php echo esc_url(add_query_arg('days', '90', $base_url)); ?>" class="ops-btn <?php echo $days === 90 ? 'primary' : 'secondary'; ?>">90 Days</a>
                    <a href="<?php echo esc_url(add_query_arg('days', '365', $base_url)); ?>" class="ops-btn <?php echo $days === 365 ? 'primary' : 'secondary'; ?>">1 Year</a>
                    <a href="<?php echo esc_url($base_url); ?>" class="ops-btn <?php echo $days === 0 ? 'primary' : 'secondary'; ?>">All Time</a>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_summary_stats($total, $with_docs, $without_docs, $highest_frequency) {
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total FAQ Candidates</div>
                <div class="stat-value"><?php echo number_format($total); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">With Existing Docs</div>
                <div class="stat-value" style="color:var(--color-success);"><?php echo number_format($with_docs); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Without Docs</div>
                <div class="stat-value" style="color:var(--color-error);"><?php echo number_format($without_docs); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Highest Frequency</div>
                <div class="stat-value"><?php echo number_format($highest_frequency); ?></div>
            </div>
        </div>
        <?php
    }

    private function render_table($rows) {
        ?>
        <div class="ops-card">
            <div class="analytics-card-header">
                <h3>All FAQ Candidates</h3>
            </div>
            <?php if (empty($rows)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No FAQ candidates identified yet</div>
                </div>
            <?php else: ?>
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Topic Label</th>
                            <th>Category</th>
                            <th width="100">Frequency</th>
                            <th width="120">Last Seen</th>
                            <th width="100">Doc Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td style="color:var(--color-text-primary);font-weight:500;"><?php echo esc_html($row['label']); ?></td>
                                <td style="color:var(--color-text-secondary);"><?php echo esc_html($row['category']); ?></td>
                                <td>
                                    <?php
                                    $count = $row['count'];
                                    $badge_class = $count >= 5 ? 'failed' : ($count >= 3 ? '' : 'success');
                                    ?>
                                    <span class="status-badge <?php echo $badge_class; ?>"><?php echo $count; ?>x</span>
                                </td>
                                <td style="color:var(--color-text-secondary);font-size:13px;">
                                    <?php echo $row['last_seen'] ? esc_html(date('M j, Y', strtotime($row['last_seen']))) : '-'; ?>
                                </td>
                                <td>
                                    <?php if ($row['has_doc']): ?>
                                        <span style="color:var(--color-success);font-weight:600;font-size:12px;" title="<?php echo esc_attr($row['has_doc']); ?>">Exists</span>
                                    <?php else: ?>
                                        <span style="color:var(--color-error);font-size:12px;">Missing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get doc search index from ais_doc_central_meta
     */
    private function get_doc_search_index() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_doc_central_meta';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        $docs = $wpdb->get_results("SELECT doc_title, category, tags, product_name, status FROM {$table} WHERE status != 'archived'");
        $index = [];
        foreach ($docs as $doc) {
            $index[] = [
                'searchable' => strtolower(($doc->doc_title ?: '') . ' ' . ($doc->category ?: '') . ' ' . ($doc->tags ?: '') . ' ' . ($doc->product_name ?: '')),
                'title' => $doc->doc_title ?: $doc->product_name,
            ];
        }
        return $index;
    }

    /**
     * Check if a topic/gap text has a matching doc
     * Returns doc title if matched, false otherwise
     */
    private function check_doc_match($text, $doc_index) {
        if (empty($doc_index)) return false;

        $tokens = array_filter(preg_split('/[\s_\-\/,]+/', strtolower($text)));

        foreach ($doc_index as $di) {
            $match_count = 0;
            foreach ($tokens as $token) {
                if (strlen($token) >= 3 && strpos($di['searchable'], $token) !== false) {
                    $match_count++;
                }
            }
            $threshold = count($tokens) <= 2 ? 1 : 2;
            if ($match_count >= $threshold) {
                return $di['title'];
            }
        }
        return false;
    }
}
