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
            echo '<div class="wrap"><h1>Backfill Agent Evaluations</h1>';
            echo '<div style="background:#fff; padding:20px; border:1px solid #ccc; margin:20px 0;"><pre>';
            $stats = $this->backfill_service->backfill_agent_evaluations(true);
            echo '</pre></div>';
            echo '<a href="?page=ai-ops&tab=agent-performance" class="button button-primary">View Agent Performance Dashboard</a>';
            echo '</div>';
            return;
        }
        
        $stats = $this->backfill_service->get_stats();
        $this->render_page($stats);
    }
    
    private function render_page($stats) {
        ?>
        <div class="wrap">
            <h1>🔄 Backfill Agent Evaluations</h1>
            
            <div class="notice notice-info">
                <p><strong>What does this do?</strong></p>
                <p>This tool extracts agent evaluation data from previously completed audits and populates the Agent Performance Dashboard.</p>
            </div>
            
            <div class="card" style="max-width: 600px; margin: 20px 0;">
                <h2>Current Status</h2>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>Total Completed Audits:</strong></td>
                            <td><?php echo number_format($stats['total_audits']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Tickets with Evaluations:</strong></td>
                            <td><?php echo number_format($stats['existing_evaluations']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Pending Backfill:</strong></td>
                            <td><strong style="color: <?php echo $stats['pending_backfill'] > 0 ? '#d63638' : '#00a32a'; ?>;">
                                <?php echo number_format($stats['pending_backfill']); ?> tickets
                            </strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <?php if ($stats['pending_backfill'] > 0): ?>
                <div class="notice notice-warning">
                    <p><strong>⚠️ Ready to backfill <?php echo number_format($stats['pending_backfill']); ?> tickets</strong></p>
                    <p>This will extract agent evaluations from existing audit data and populate the Agent Performance Dashboard.</p>
                </div>
                
                <form method="post" onsubmit="return confirm('This will process <?php echo $stats['pending_backfill']; ?> audits. Continue?');">
                    <?php wp_nonce_field('ai_ops_backfill'); ?>
                    <button type="submit" name="run_backfill" class="button button-primary button-hero" style="margin: 20px 0;">
                        🚀 Run Backfill Now
                    </button>
                </form>
            <?php else: ?>
                <div class="notice notice-success">
                    <p><strong>✅ All caught up!</strong></p>
                    <p>All completed audits already have agent evaluations. No backfill needed.</p>
                </div>
                
                <a href="?page=ai-ops&tab=agent-performance" class="button button-primary">View Agent Performance Dashboard</a>
            <?php endif; ?>
            
            <div class="card" style="max-width: 600px; margin: 20px 0;">
                <h3>📝 Notes</h3>
                <ul>
                    <li>✅ Safe to run multiple times (skips already processed tickets)</li>
                    <li>⚡ Takes ~1 second per 100 tickets</li>
                    <li>🔄 Can be interrupted and resumed</li>
                    <li>📊 Only processes audits with agent_evaluations in the response</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

