<?php
/**
 * Backfill Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Services\BackfillService;

class Backfill {
    
    private $database;
    private $backfill_service;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->backfill_service = new BackfillService($database);
    }
    
    public function render() {
        // Check if form submitted
        if (isset($_POST['run_backfill']) && check_admin_referer('ai_ops_backfill')) {
            echo '<div class="ops-card"><h3>Backfill results</h3>';
            echo '<div style="background:var(--color-bg-subtle);padding:16px;border:1px solid var(--color-border);border-radius:var(--radius-sm);margin:16px 0;"><pre style="margin:0;font-size:var(--font-size-xs);line-height:1.6;">';
            $stats = $this->backfill_service->backfill_agent_evaluations(true);
            echo '</pre></div>';
            echo '<a href="?page=ai-ops&section=agent-performance" class="ops-btn primary">View Agent Performance</a>';
            echo '</div>';
            return;
        }
        
        $stats = $this->backfill_service->get_stats();
        $this->render_page($stats);
    }
    
    private function render_page($stats) {
        ?>
        <div class="ops-card">
            <h3>Backfill agent evaluations</h3>
            <p style="margin:0 0 20px;color:var(--color-text-secondary);font-size:var(--font-size-sm);">
                Extract agent evaluation data from previously completed audits and populate the Agent Performance Dashboard.
            </p>

            <table class="audit-table" style="margin-bottom:24px;">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total completed audits</td>
                        <td><?php echo number_format($stats['total_audits']); ?></td>
                    </tr>
                    <tr>
                        <td>Tickets with evaluations</td>
                        <td><?php echo number_format($stats['existing_evaluations']); ?></td>
                    </tr>
                    <tr>
                        <td>Pending backfill</td>
                        <td><strong style="color: <?php echo $stats['pending_backfill'] > 0 ? 'var(--color-error)' : 'var(--color-success)'; ?>;">
                            <?php echo number_format($stats['pending_backfill']); ?> tickets
                        </strong></td>
                    </tr>
                </tbody>
            </table>

            <?php if ($stats['pending_backfill'] > 0): ?>
                <div style="padding:12px 16px;background:var(--color-warning-bg);border:1px solid var(--color-border);border-left:3px solid #f59e0b;border-radius:var(--radius-sm);margin-bottom:20px;">
                    <p style="margin:0 0 4px;font-weight:600;">Ready to backfill <?php echo number_format($stats['pending_backfill']); ?> tickets</p>
                    <p style="margin:0;font-size:var(--font-size-sm);color:var(--color-text-secondary);">This will extract agent evaluations from existing audit data.</p>
                </div>

                <form method="post" onsubmit="return confirm('This will process <?php echo $stats['pending_backfill']; ?> audits. Continue?');">
                    <?php wp_nonce_field('ai_ops_backfill'); ?>
                    <button type="submit" name="run_backfill" class="ops-btn primary">
                        Run Backfill Now
                    </button>
                </form>
            <?php else: ?>
                <div class="ops-empty-state" style="padding:32px;">
                    <div class="ops-empty-state-title">All caught up</div>
                    <div class="ops-empty-state-description">All completed audits already have agent evaluations. No backfill needed.</div>
                </div>
                <div style="text-align:center;margin-top:16px;">
                    <a href="?page=ai-ops&section=agent-performance" class="ops-btn primary">View Agent Performance</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="ops-card">
            <h3>Notes</h3>
            <ul style="margin:12px 0 0;padding-left:20px;font-size:var(--font-size-sm);color:var(--color-text-secondary);line-height:1.8;">
                <li>Safe to run multiple times (skips already processed tickets)</li>
                <li>Takes ~1 second per 100 tickets</li>
                <li>Can be interrupted and resumed</li>
                <li>Only processes audits with agent_evaluations in the response</li>
            </ul>
        </div>
        <?php
    }
}

