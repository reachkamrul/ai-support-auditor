<?php
/**
 * Assets Manager
 * 
 * @package SupportOps\Admin
 */

namespace SupportOps\Admin;

class Assets {
    
    /**
     * Enqueue all assets
     */
    public function enqueue() {
        $this->enqueue_external_libraries();
        $this->enqueue_styles();
    }
    
    /**
     * Enqueue external libraries
     */
    private function enqueue_external_libraries() {
        // Select2
        echo '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />';
        echo '<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>';
        
        // Flatpickr
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">';
        echo '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';
        
        // Chart.js
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
    }
    
    /**
     * Enqueue custom styles
     */
    private function enqueue_styles() {
        ?>
        <style>
            /* 🎨 MODERN SAAS DESIGN SYSTEM v2.0 */
            
            /* ===== DESIGN TOKENS ===== */
            :root {
                /* Colors - Professional Blue Palette */
                --color-primary: #3b82f6;
                --color-primary-hover: #2563eb;
                --color-primary-light: #dbeafe;
                
                /* Neutrals */
                --color-bg: #ffffff;
                --color-bg-subtle: #f8fafc;
                --color-bg-hover: #f1f5f9;
                --color-border: #e2e8f0;
                --color-border-strong: #cbd5e1;
                
                /* Text */
                --color-text-primary: #0f172a;
                --color-text-secondary: #475569;
                --color-text-tertiary: #94a3b8;
                
                /* Semantic Colors */
                --color-success: #10b981;
                --color-success-bg: #d1fae5;
                --color-warning: #f59e0b;
                --color-warning-bg: #fef3c7;
                --color-error: #ef4444;
                --color-error-bg: #fee2e2;
                --color-info: #3b82f6;
                --color-info-bg: #dbeafe;
                
                /* Spacing */
                --space-xs: 4px;
                --space-sm: 8px;
                --space-md: 16px;
                --space-lg: 24px;
                --space-xl: 32px;
                
                /* Border Radius */
                --radius-sm: 6px;
                --radius-md: 8px;
                --radius-lg: 12px;
                --radius-pill: 999px;
                
                /* Shadows */
                --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.05);
                --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
                --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
                --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
                
                /* Typography */
                --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                --font-mono: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, monospace;
            }

            /* ===== GLOBAL STYLES ===== */
            /* Hide WordPress admin notices on all plugin pages */
            .wrap .notice,
            .wrap .updated,
            .wrap .error,
            .wrap .warning,
            .wrap .info {
                display: none !important;
            }
            
            /* Plugin Logo Styles */
            .ops-logo {
                width: 240px;
                height: 48px;
                flex-shrink: 0;
                object-fit: contain;
                display: block;
            }
            
            .ops-header {
                display: flex;
                align-items: center;
                gap: 24px;
                margin-top: 24px;
                margin-bottom: 24px;
                padding-bottom: 20px;
                border-bottom: 1px solid var(--color-border);
            }
            
            .ops-header .ops-nav-tabs {
                flex: 1;
                margin-bottom: 0;
                border-bottom: none;
            }
            
            .ops-header .ops-nav-tabs .nav-tab {
                margin-bottom: 0;
                margin-left: 0;
            }
            
            .ops-wrapper { 
                font-family: var(--font-sans);
                max-width: 1400px; 
                margin: 0 auto;
                padding: 0 var(--space-lg);
                color: var(--color-text-primary);
            }
            
            /* ===== CARD COMPONENT ===== */
            .ops-card { 
                background: var(--color-bg);
                border: 1px solid var(--color-border);
                box-shadow: var(--shadow-xs);
                padding: var(--space-lg);
                margin-bottom: var(--space-lg);
                border-radius: var(--radius-md);
                transition: all 0.2s ease;
            }
            .ops-card:hover {
                box-shadow: var(--shadow-sm);
                border-color: var(--color-border-strong);
            }
            .ops-card h3 {
                margin: 0 0 var(--space-md) 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--color-text-primary);
                letter-spacing: -0.01em;
            }
            
            /* ===== INPUT COMPONENT ===== */
            .ops-input { 
                padding: 0 12px;
                border: 1px solid var(--color-border);
                border-radius: var(--radius-sm);
                height: 38px;
                line-height: 1;
                width: 100%;
                box-sizing: border-box;
                font-size: 14px;
                font-family: var(--font-sans);
                color: var(--color-text-primary);
                background: var(--color-bg);
                transition: all 0.15s ease;
            }
            .ops-input:hover {
                border-color: var(--color-border-strong);
            }
            .ops-input:focus { 
                border-color: var(--color-primary);
                box-shadow: 0 0 0 3px var(--color-primary-light);
                outline: none;
            }
            
            /* ===== BUTTON COMPONENT ===== */
            .ops-btn { 
                height: 38px;
                padding: 0 16px;
                border-radius: var(--radius-sm);
                font-weight: 500;
                cursor: pointer;
                border: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                font-size: 14px;
                font-family: var(--font-sans);
                text-decoration: none;
                transition: all 0.15s ease;
                white-space: nowrap;
                box-shadow: var(--shadow-xs);
            }
            
            .ops-btn.primary { 
                background: var(--color-primary);
                color: white;
                border: 1px solid var(--color-primary);
            }
            .ops-btn.primary:hover { 
                background: var(--color-primary-hover);
                border-color: var(--color-primary-hover);
                box-shadow: var(--shadow-sm);
                transform: translateY(-1px);
            }
            
            .ops-btn.secondary { 
                background: var(--color-bg);
                color: var(--color-text-primary);
                border: 1px solid var(--color-border);
            }
            .ops-btn.secondary:hover { 
                background: var(--color-bg-subtle);
                border-color: var(--color-border-strong);
                transform: translateY(-1px);
            }
            
            /* ===== TABLE COMPONENT ===== */
            .audit-table { 
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 14px;
            }
            .audit-table th { 
                text-align: left;
                padding: 12px 16px;
                background: var(--color-bg-subtle);
                border-bottom: 2px solid var(--color-border);
                font-weight: 600;
                color: var(--color-text-secondary);
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .audit-table tbody tr {
                transition: background-color 0.1s ease;
                border-bottom: 1px solid var(--color-border);
            }
            .audit-table tbody tr:hover { 
                background: var(--color-bg-subtle);
            }
            .audit-table td { 
                padding: 14px 16px;
                vertical-align: middle;
            }
            
            /* ===== STATUS BADGES ===== */
            .status-badge { 
                padding: 4px 10px;
                border-radius: var(--radius-pill);
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                display: inline-block;
                min-width: 70px;
                text-align: center;
            }
            .status-badge.success { 
                background: var(--color-success-bg);
                color: #065f46;
            }
            .status-badge.failed { 
                background: var(--color-error-bg);
                color: #991b1b;
            }
            .status-badge.pending { 
                background: var(--color-warning-bg);
                color: #92400e;
            }
            .status-badge.warning { 
                background: var(--color-warning-bg);
                color: #92400e;
            }
            
            /* ===== CALENDAR GRID ===== */
            .cal-grid {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                gap: 1px;
                background: var(--color-border);
                border: 1px solid var(--color-border);
                border-radius: var(--radius-md);
                overflow: hidden;
            }
            
            .cal-head {
                background: var(--color-bg-subtle);
                padding: 12px 8px;
                text-align: center;
                font-weight: 600;
                font-size: 12px;
                color: var(--color-text-secondary);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border-bottom: 2px solid var(--color-border);
            }
            
            .cal-cell {
                background: var(--color-bg);
                min-height: 100px;
                padding: 8px;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                flex-direction: column;
                position: relative;
            }
            
            .cal-cell:hover {
                background: var(--color-bg-hover);
                box-shadow: inset 0 0 0 2px var(--color-primary-light);
            }
            
            .cal-cell.empty {
                background: var(--color-bg-subtle);
                cursor: default;
            }
            
            .cal-cell.empty:hover {
                background: var(--color-bg-subtle);
                box-shadow: none;
            }
            
            .cal-date {
                font-weight: 600;
                font-size: 14px;
                color: var(--color-text-primary);
                margin-bottom: 4px;
            }
            
            .shift-pill {
                font-size: 11px;
                padding: 4px 8px;
                border-radius: 4px;
                margin-bottom: 4px;
                color: #333;
                font-weight: 500;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                cursor: pointer;
                transition: opacity 0.2s ease;
            }
            
            .shift-pill:hover {
                opacity: 0.8;
            }
            
            /* ===== FORM ROW ===== */
            .form-row {
                display: flex;
                gap: var(--space-md);
                align-items: flex-start;
                flex-wrap: wrap;
            }
            
            .form-group {
                margin-bottom: var(--space-md);
            }
            
            .form-group label {
                display: block;
                font-weight: 600;
                font-size: 12px;
                margin-bottom: 6px;
                color: var(--color-text-secondary);
            }
            
            /* ===== SCORE COLORS ===== */
            .col-score {
                font-weight: 700;
                font-size: 14px;
            }
            
            .col-score.score-good {
                color: var(--color-success);
            }
            
            .col-score.score-ok {
                color: var(--color-info);
            }
            
            .col-score.score-warning {
                color: var(--color-warning);
            }
            
            .col-score.score-negative {
                color: var(--color-error);
            }
            
            /* ===== FILTERS ===== */
            .audit-filters {
                display: flex;
                gap: var(--space-md);
                flex-wrap: wrap;
            }
            
            .audit-filter-group {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            
            .audit-filter-group label {
                font-size: 12px;
                font-weight: 600;
                color: var(--color-text-secondary);
            }
            
            .audit-filter-group.wide {
                flex: 2;
                min-width: 200px;
            }
            
            .audit-filter-group.narrow {
                flex: 1;
                min-width: 150px;
            }
            
            /* ===== MODAL ===== */
            .audit-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.6);
                z-index: 9999;
                backdrop-filter: blur(2px);
            }
            
            .audit-modal-content {
                background: var(--color-bg);
                width: 90%;
                max-width: 800px;
                max-height: 90vh;
                margin: 5vh auto;
                border-radius: var(--radius-lg);
                padding: var(--space-xl);
                box-shadow: var(--shadow-lg);
                overflow-y: auto;
            }
            
            .json-viewer {
                background: var(--color-bg-subtle);
                padding: var(--space-md);
                border-radius: var(--radius-sm);
                font-family: var(--font-mono);
                font-size: 12px;
                white-space: pre-wrap;
                word-wrap: break-word;
                max-height: 60vh;
                overflow-y: auto;
            }
            
            /* ===== STATS GRID ===== */
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: var(--space-md);
                margin-bottom: var(--space-lg);
            }
            
            .stat-card {
                background: var(--color-bg);
                border: 1px solid var(--color-border);
                border-radius: var(--radius-md);
                padding: var(--space-lg);
                box-shadow: var(--shadow-xs);
                transition: all 0.2s ease;
            }
            
            .stat-card:hover {
                box-shadow: var(--shadow-sm);
                border-color: var(--color-border-strong);
                transform: translateY(-2px);
            }
            
            .stat-label {
                font-size: 12px;
                font-weight: 600;
                color: var(--color-text-secondary);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: var(--space-sm);
            }
            
            .stat-value {
                font-size: 32px;
                font-weight: 700;
                color: var(--color-text-primary);
                line-height: 1.2;
                margin-bottom: var(--space-xs);
            }
            
            .stat-change {
                font-size: 11px;
                font-weight: 600;
                color: var(--color-text-secondary);
                margin-top: var(--space-xs);
            }
            
            .stat-change.positive {
                color: var(--color-success);
            }
            
            .stat-change.negative {
                color: var(--color-error);
            }
            
            /* ===== SUMMARY COLUMN ===== */
            .col-summary {
                color: var(--color-text-secondary);
                font-size: 13px;
                line-height: 1.5;
            }
            
            /* ===== BUTTON VARIANTS ===== */
            .ops-btn.danger {
                background: var(--color-error);
                color: white;
                border: 1px solid var(--color-error);
            }
            
            .ops-btn.danger:hover {
                background: #dc2626;
                border-color: #dc2626;
                box-shadow: var(--shadow-sm);
                transform: translateY(-1px);
            }
            
            .btn-view,
            .btn-force {
                font-size: 12px;
            }
            
            /* ===== MODAL CLOSE BUTTON ===== */
            .close-modal {
                cursor: pointer;
                float: right;
                font-size: 20px;
                font-weight: 300;
                line-height: 1;
                color: var(--color-text-tertiary);
                transition: color 0.2s ease;
            }
            
            .close-modal:hover {
                color: var(--color-text-primary);
            }
            
            /* ===== JSON STORAGE (HIDDEN) ===== */
            .json-storage {
                display: none;
            }
            
            /* ===== NAV TABS ===== */
            .nav-tab-wrapper {
                margin-bottom: var(--space-lg);
                border-bottom: 1px solid var(--color-border);
            }
            
            .nav-tab {
                display: inline-block;
                padding: 10px 15px;
                margin-right: 2px;
                text-decoration: none;
                color: var(--color-text-secondary);
                background: transparent;
                border: 1px solid transparent;
                border-bottom: none;
                border-radius: var(--radius-sm) var(--radius-sm) 0 0;
                transition: all 0.2s ease;
            }
            
            .nav-tab:hover {
                color: var(--color-primary);
                background: var(--color-bg-subtle);
            }
            
            .nav-tab.nav-tab-active {
                color: var(--color-primary);
                background: var(--color-bg);
                border-color: var(--color-border);
                border-bottom-color: var(--color-bg);
                margin-bottom: -1px;
            }
        </style>
        <?php
    }
}