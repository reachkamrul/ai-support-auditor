<?php
/**
 * Standalone Backfill Script
 * Run this to backfill agent evaluations from existing audits
 * 
 * Usage: php run-backfill.php
 */

// Load WordPress
require_once(__DIR__ . '/../../../wp-load.php');

// Load the plugin autoloader
require_once(__DIR__ . '/ai-support-auditor.php');

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Services\BackfillService;

echo "\n=== AGENT EVALUATIONS BACKFILL ===\n\n";

// Initialize service
$database = new DatabaseManager();
$backfill_service = new BackfillService($database);

// Run backfill with verbose output
$stats = $backfill_service->backfill_agent_evaluations(true);

echo "\n=== RESULTS ===\n";
echo "Processed: {$stats['processed']}\n";
echo "Skipped: {$stats['skipped']}\n";
echo "Errors: {$stats['errors']}\n";
echo "Total evaluations inserted: {$stats['evaluations_inserted']}\n\n";
echo "✅ Done! Check Agent Performance Dashboard.\n\n";
