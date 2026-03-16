<?php
/**
 * Audit Queue Page — Monitor and manage the AI processing pipeline
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class AuditQueue {

    private $database;
    private $per_page = 30;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';

        // Handle actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['queue_action'])) {
            $this->handle_action();
        }

        // Auto-retry failed audits when queue is idle (no pending, no processing)
        $this->auto_retry_failed();

        // Stats
        $pending_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
        $processing = $wpdb->get_row(
            "SELECT id, ticket_id, audit_type, processing_started_at
             FROM {$table} WHERE status = 'processing'
             ORDER BY processing_started_at ASC LIMIT 1"
        );
        $avg_duration = (float) $wpdb->get_var(
            "SELECT AVG(processing_duration_seconds) FROM {$table}
             WHERE processing_duration_seconds IS NOT NULL
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $completed_today = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'success' AND DATE(created_at) = CURDATE()"
        );
        $failed_today = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND DATE(created_at) = CURDATE()"
        );

        $total_in_queue = $pending_count + ($processing ? 1 : 0);
        $est_wait = $pending_count > 0 && $avg_duration > 0
            ? round(($pending_count * $avg_duration) / 60, 1) : 0;

        // Format avg duration
        $avg_display = $avg_duration > 0
            ? floor($avg_duration / 60) . 'm ' . ($avg_duration % 60) . 's'
            : '--';

        // Queue items (pending, paginated) — compute version from count of previous audits
        $page_num = max(1, intval($_GET['pg'] ?? 1));
        $offset = ($page_num - 1) * $this->per_page;

        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.ticket_id, a.status, a.audit_type, a.created_at, a.processing_started_at,
                    (SELECT COUNT(*) FROM {$table} prev WHERE prev.ticket_id = a.ticket_id AND prev.id < a.id AND prev.status IN ('success','failed')) + 1 as computed_version
             FROM {$table} a
             WHERE a.status = 'pending'
             ORDER BY
                CASE a.audit_type
                    WHEN 'final' THEN 0
                    WHEN 'incremental' THEN 1
                    WHEN 'full' THEN 2
                    ELSE 3
                END,
                a.created_at ASC
             LIMIT %d OFFSET %d",
            $this->per_page, $offset
        ));
        $total_pages = max(1, ceil($pending_count / $this->per_page));

        // Recent completions
        $recent = $wpdb->get_results(
            "SELECT ticket_id, audit_type, overall_score, processing_duration_seconds, created_at
             FROM {$table}
             WHERE status = 'success' AND processing_duration_seconds IS NOT NULL
             ORDER BY created_at DESC LIMIT 10"
        );

        // Failed items available for retry (last 24h)
        $failed_items = $wpdb->get_results(
            "SELECT id, ticket_id, audit_type, created_at, error_message
             FROM {$table}
             WHERE status = 'failed'
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY created_at DESC LIMIT 20"
        );
        $failed_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'failed'
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        $this->render_styles();
        ?>

        <!-- Failed Alert Banner — prominent notice at the top -->
        <?php if ($failed_count > 0): ?>
        <div class="ops-card failed-alert-banner">
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="font-size:20px;">!</span>
                <div>
                    <strong><?php echo $failed_count; ?> failed audit<?php echo $failed_count !== 1 ? 's' : ''; ?> in the last 24 hours</strong>
                    <div style="font-size:12px;color:var(--color-text-secondary);margin-top:2px;">
                        These tickets were not audited. Review errors below and retry.
                        <?php if (!$processing && $pending_count === 0): ?>
                            <span style="color:var(--color-success);">Queue is idle — auto-retry will run on next page load.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <form method="post" style="flex-shrink:0;">
                <?php wp_nonce_field('queue_action_retry_all'); ?>
                <input type="hidden" name="queue_action" value="retry_all">
                <button type="submit" class="ops-btn primary" style="white-space:nowrap;">Retry All Failed</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card <?php echo $total_in_queue > 5 ? 'ops-card--accent-error' : ''; ?>">
                <div class="stat-label">Queue Depth</div>
                <div class="stat-value" style="<?php echo $total_in_queue > 5 ? 'color:var(--color-error);' : ''; ?>">
                    <?php echo $total_in_queue; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Processing</div>
                <div class="stat-value" style="font-size:18px;">
                    <?php if ($processing): ?>
                        <span style="color:var(--color-info);">#<?php echo esc_html($processing->ticket_id); ?></span>
                    <?php else: ?>
                        <span style="color:var(--color-success);">Idle</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg Processing</div>
                <div class="stat-value" style="font-size:18px;<?php echo $avg_duration > 120 ? 'color:var(--color-warning);' : ''; ?>">
                    <?php echo $avg_display; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Est. Wait</div>
                <div class="stat-value" style="font-size:18px;<?php echo $est_wait > 10 ? 'color:var(--color-warning);' : ''; ?>">
                    <?php echo $est_wait > 0 ? $est_wait . 'm' : '--'; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Completed Today</div>
                <div class="stat-value" style="color:var(--color-success);">
                    <?php echo $completed_today; ?>
                </div>
            </div>
            <div class="stat-card <?php echo $failed_today > 0 ? 'ops-card--accent-error' : ''; ?>">
                <div class="stat-label">Failed Today</div>
                <div class="stat-value" style="<?php echo $failed_today > 0 ? 'color:var(--color-error);' : ''; ?>">
                    <?php echo $failed_today; ?>
                </div>
            </div>
        </div>

        <!-- Currently Processing -->
        <?php if ($processing): ?>
            <?php
                $elapsed = time() - strtotime($processing->processing_started_at);
                $is_stale = $elapsed > 480;
            ?>
            <div class="ops-card queue-processing-card">
                <div>
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--color-text-tertiary);margin-bottom:4px;">Currently Processing</div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <strong style="font-size:18px;">#<?php echo esc_html($processing->ticket_id); ?></strong>
                        <?php echo $this->type_badge($processing->audit_type); ?>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div id="queue-elapsed"
                         class="queue-elapsed <?php echo $is_stale ? 'stale' : ''; ?>"
                         data-started="<?php echo strtotime($processing->processing_started_at); ?>">
                        <?php echo floor($elapsed / 60) . 'm ' . ($elapsed % 60) . 's'; ?>
                    </div>
                    <?php if ($is_stale): ?>
                        <div style="font-size:11px;color:var(--color-error);margin-top:4px;">
                            Stale — will auto-reset at 10m
                        </div>
                    <?php else: ?>
                        <div style="font-size:11px;color:var(--color-text-tertiary);margin-top:4px;">Elapsed</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php
            $active_tab = sanitize_text_field($_GET['qtab'] ?? 'pending');
            if (!in_array($active_tab, ['pending', 'failed', 'completed'])) $active_tab = 'pending';
            $tab_base = admin_url('admin.php?page=ai-ops&section=audit-queue');
        ?>

        <!-- Tabbed Queue Card -->
        <div class="ops-card" style="padding:0;overflow:hidden;">
            <!-- Tab Header -->
            <div class="queue-tabs">
                <a href="<?php echo $tab_base . '&qtab=pending'; ?>" class="queue-tab <?php echo $active_tab === 'pending' ? 'active' : ''; ?>">
                    Pending
                    <?php if ($pending_count > 0): ?>
                        <span class="queue-tab-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo $tab_base . '&qtab=failed'; ?>" class="queue-tab <?php echo $active_tab === 'failed' ? 'active' : ''; ?>" <?php echo $failed_count > 0 ? 'style="color:var(--color-error);"' : ''; ?>>
                    Failed
                    <?php if ($failed_count > 0): ?>
                        <span class="queue-tab-count error"><?php echo $failed_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo $tab_base . '&qtab=completed'; ?>" class="queue-tab <?php echo $active_tab === 'completed' ? 'active' : ''; ?>">
                    Completed
                </a>
                <div style="flex:1;"></div>
                <?php if ($active_tab === 'pending'): ?>
                    <span style="font-size:12px;color:var(--color-text-tertiary);padding-right:16px;align-self:center;">
                        Priority: Final &gt; Incremental &gt; Full
                    </span>
                <?php elseif ($active_tab === 'failed' && $failed_count > 0): ?>
                    <form method="post" style="padding-right:16px;align-self:center;">
                        <?php wp_nonce_field('queue_action_retry_all'); ?>
                        <input type="hidden" name="queue_action" value="retry_all">
                        <button type="submit" class="ops-btn primary" style="font-size:11px;height:28px;padding:0 10px;">Retry All</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- PENDING TAB -->
            <?php if ($active_tab === 'pending'): ?>
                <?php if (empty($queue_items)): ?>
                    <div class="ops-empty-state">
                        <div class="ops-empty-state-title">Queue is empty</div>
                        <div class="ops-empty-state-description">
                            <?php if ($processing): ?>
                                One audit is being processed. No more pending items.
                            <?php else: ?>
                                No audits are queued or being processed right now.
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th style="width:60px;">#</th>
                                <th>Ticket</th>
                                <th>Type</th>
                                <th>Version</th>
                                <th>Queued</th>
                                <th style="width:150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($queue_items as $idx => $item): ?>
                            <?php
                                $position = $offset + $idx + 1;
                                $queued_ts = strtotime($item->created_at);
                                $queued_time = wp_date('M j, g:i A', $queued_ts);
                            ?>
                            <tr>
                                <td>
                                    <span class="queue-position"><?php echo $position; ?></span>
                                </td>
                                <td><strong>#<?php echo esc_html($item->ticket_id); ?></strong></td>
                                <td><?php echo $this->type_badge($item->audit_type); ?></td>
                                <td style="color:var(--color-text-tertiary);">v<?php echo intval($item->computed_version); ?></td>
                                <td style="color:var(--color-text-tertiary);font-size:12px;"><?php echo $queued_time; ?></td>
                                <td>
                                    <div style="display:flex;gap:4px;">
                                        <?php if ($item->audit_type !== 'final'): ?>
                                            <form method="post" style="display:inline;">
                                                <?php wp_nonce_field('queue_action_' . $item->id); ?>
                                                <input type="hidden" name="queue_action" value="boost">
                                                <input type="hidden" name="audit_id" value="<?php echo $item->id; ?>">
                                                <button type="submit" class="ops-btn primary" style="font-size:11px;height:28px;padding:0 8px;" title="Boost to top priority">Boost</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field('queue_action_' . $item->id); ?>
                                            <input type="hidden" name="queue_action" value="cancel">
                                            <input type="hidden" name="audit_id" value="<?php echo $item->id; ?>">
                                            <button type="submit" class="ops-btn secondary" style="font-size:11px;height:28px;padding:0 8px;">Cancel</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="ops-pagination" style="justify-content:center;padding:12px;">
                            <?php
                            $base = $tab_base . '&qtab=pending';
                            for ($p = 1; $p <= $total_pages; $p++):
                                $active = $p === $page_num ? 'primary' : 'secondary';
                            ?>
                                <a href="<?php echo $base . '&pg=' . $p; ?>" class="ops-btn <?php echo $active; ?>" style="height:32px;min-width:32px;padding:0 10px;"><?php echo $p; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            <!-- FAILED TAB -->
            <?php elseif ($active_tab === 'failed'): ?>
                <?php if (empty($failed_items)): ?>
                    <div class="ops-empty-state">
                        <div class="ops-empty-state-title">No failed audits</div>
                        <div class="ops-empty-state-description">No audits have failed in the last 24 hours.</div>
                    </div>
                <?php else: ?>
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Type</th>
                                <th>Error</th>
                                <th>Failed At</th>
                                <th style="width:80px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($failed_items as $fi): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($fi->ticket_id); ?></strong></td>
                                <td><?php echo $this->type_badge($fi->audit_type); ?></td>
                                <td style="font-size:12px;color:var(--color-error);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?php echo esc_html($fi->error_message ?: 'Unknown error'); ?>
                                </td>
                                <td style="color:var(--color-text-tertiary);font-size:12px;">
                                    <?php echo human_time_diff(strtotime($fi->created_at), current_time('timestamp')); ?> ago
                                </td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('queue_action_' . $fi->id); ?>
                                        <input type="hidden" name="queue_action" value="retry">
                                        <input type="hidden" name="audit_id" value="<?php echo $fi->id; ?>">
                                        <button type="submit" class="ops-btn primary" style="font-size:11px;height:28px;padding:0 8px;">Retry</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <!-- COMPLETED TAB -->
            <?php else: ?>
                <?php if (empty($recent)): ?>
                    <div class="ops-empty-state">
                        <div class="ops-empty-state-title">No recent completions</div>
                        <div class="ops-empty-state-description">No audits have completed recently.</div>
                    </div>
                <?php else: ?>
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Type</th>
                                <th>Score</th>
                                <th>Processing Time</th>
                                <th>Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=ai-ops&section=audits&search=' . $r->ticket_id); ?>" style="color:var(--color-info);text-decoration:none;font-weight:600;">
                                        #<?php echo esc_html($r->ticket_id); ?>
                                    </a>
                                </td>
                                <td><?php echo $this->type_badge($r->audit_type); ?></td>
                                <td>
                                    <span class="col-score <?php echo Dashboard::score_class(intval($r->overall_score)); ?>">
                                        <?php echo intval($r->overall_score); ?>
                                    </span>
                                </td>
                                <td style="font-family:var(--font-mono, monospace);font-size:13px;">
                                    <?php echo intval($r->processing_duration_seconds); ?>s
                                </td>
                                <td style="color:var(--color-text-tertiary);font-size:12px;">
                                    <?php echo human_time_diff(strtotime($r->created_at), current_time('timestamp')); ?> ago
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            function updateElapsed() {
                var el = document.getElementById('queue-elapsed');
                if (!el) return;
                var started = parseInt(el.dataset.started, 10);
                var now = Math.floor(Date.now() / 1000);
                var elapsed = now - started;
                var m = Math.floor(elapsed / 60);
                var s = elapsed % 60;
                el.textContent = m + 'm ' + s + 's';
                if (elapsed > 480) {
                    el.classList.add('stale');
                }
            }
            if (document.getElementById('queue-elapsed')) {
                setInterval(updateElapsed, 1000);
            }
        })();
        </script>
        <?php
    }

    /**
     * Auto-retry failed audits when queue is idle (no pending, no processing)
     */
    private function auto_retry_failed() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';

        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending', 'processing')");
        if ($pending > 0) {
            return; // Queue is busy, don't auto-retry
        }

        // Re-queue up to 5 failed audits from the last 24h that haven't been retried yet
        $failed = $wpdb->get_results(
            "SELECT id FROM {$table}
             WHERE status = 'failed'
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY created_at ASC
             LIMIT 5"
        );

        foreach ($failed as $f) {
            $wpdb->update($table,
                ['status' => 'pending', 'processing_started_at' => null, 'created_at' => current_time('mysql')],
                ['id' => $f->id]
            );
        }
    }

    private function handle_action() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';

        $action = sanitize_text_field($_POST['queue_action']);

        // Retry All action — uses its own nonce
        if ($action === 'retry_all') {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'queue_action_retry_all')) {
                return;
            }
            if (!current_user_can('manage_options')) {
                return;
            }
            $wpdb->query(
                "UPDATE {$table}
                 SET status = 'pending', processing_started_at = NULL, created_at = NOW()
                 WHERE status = 'failed'
                 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            return;
        }

        $audit_id = intval($_POST['audit_id'] ?? 0);

        if (!$audit_id || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'queue_action_' . $audit_id)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        switch ($action) {
            case 'cancel':
                $wpdb->update($table,
                    ['status' => 'cancelled'],
                    ['id' => $audit_id, 'status' => 'pending']
                );
                break;

            case 'boost':
                $wpdb->update($table,
                    ['audit_type' => 'final'],
                    ['id' => $audit_id, 'status' => 'pending']
                );
                break;

            case 'retry':
                $wpdb->update($table,
                    ['status' => 'pending', 'processing_started_at' => null, 'created_at' => current_time('mysql')],
                    ['id' => $audit_id]
                );
                break;
        }
    }

    private function type_badge($type) {
        $map = [
            'final'       => ['Final',       'success'],
            'incremental' => ['Incremental', 'pending'],
            'full'        => ['Full',        'secondary'],
        ];
        $info = $map[$type] ?? ['Unknown', 'secondary'];
        $class = $info[1] === 'secondary' ? '' : $info[1];
        return '<span class="status-badge ' . $class . '">' . $info[0] . '</span>';
    }

    private function render_styles() {
        ?>
        <style>
            .queue-processing-card {
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-left: 4px solid var(--color-info, #3b82f6);
            }
            .queue-elapsed {
                font-size: 28px;
                font-weight: 700;
                font-family: var(--font-mono, monospace);
                color: var(--color-info, #3b82f6);
            }
            .queue-elapsed.stale {
                color: var(--color-error, #ef4444);
                animation: queue-pulse 1s infinite;
            }
            @keyframes queue-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            .queue-position {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
                border-radius: 50%;
                background: var(--color-bg-subtle, #f1f5f9);
                border: 1px solid var(--color-border, #e2e8f0);
                font-size: 12px;
                font-weight: 700;
                color: var(--color-text-secondary, #64748b);
            }
            .failed-alert-banner {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: var(--color-error-bg, #fef2f2);
                border: 1px solid var(--color-error, #ef4444);
                border-left: 4px solid var(--color-error, #ef4444);
                color: var(--color-error, #ef4444);
            }
            .queue-tabs {
                display: flex;
                align-items: stretch;
                border-bottom: 1px solid var(--color-border, #e2e8f0);
                background: var(--color-bg-subtle, #f8fafc);
            }
            .queue-tab {
                padding: 12px 20px;
                font-size: 13px;
                font-weight: 600;
                color: var(--color-text-secondary, #64748b);
                text-decoration: none;
                border-bottom: 2px solid transparent;
                display: flex;
                align-items: center;
                gap: 6px;
                transition: color 0.15s, border-color 0.15s;
            }
            .queue-tab:hover {
                color: var(--color-text-primary, #1e293b);
            }
            .queue-tab.active {
                color: var(--color-info, #3b82f6);
                border-bottom-color: var(--color-info, #3b82f6);
                background: var(--color-bg, #fff);
            }
            .queue-tab-count {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 700;
                background: var(--color-bg-subtle, #f1f5f9);
                color: var(--color-text-secondary, #64748b);
            }
            .queue-tab-count.error {
                background: var(--color-error, #ef4444);
                color: #fff;
            }
        </style>
        <?php
    }
}
