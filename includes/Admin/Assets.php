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
            /* MODERN SAAS DESIGN SYSTEM v3.0 */

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

                /* Semantic Colors — softer backgrounds */
                --color-success: #16a34a;
                --color-success-bg: #f0fdf4;
                --color-success-text: #15803d;
                --color-warning: #d97706;
                --color-warning-bg: #fffbeb;
                --color-warning-text: #92400e;
                --color-error: #dc2626;
                --color-error-bg: #fef2f2;
                --color-error-text: #991b1b;
                --color-info: #3b82f6;
                --color-info-bg: #eff6ff;
                --color-info-text: #1d4ed8;

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

                /* Shadows — lighter */
                --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.04);
                --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
                --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
                --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
                --shadow-overlay: 0 25px 50px -12px rgba(0, 0, 0, 0.15);

                /* Typography */
                --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                --font-mono: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, monospace;
                --font-size-xs: 11px;
                --font-size-sm: 13px;
                --font-size-base: 14px;
                --font-size-md: 15px;
                --font-size-lg: 18px;
                --font-size-xl: 22px;
                --font-size-2xl: 28px;

                /* Transitions */
                --transition-fast: 150ms ease;
                --transition-base: 200ms ease;
                --transition-slow: 300ms ease;
            }

            /* ===== GLOBAL STYLES ===== */
            
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
                box-shadow: none;
                padding: var(--space-lg);
                margin-bottom: var(--space-lg);
                border-radius: var(--radius-lg);
                transition: border-color var(--transition-fast);
                overflow-x: auto;
            }
            .ops-card:hover {
                border-color: var(--color-border-strong);
            }
            .ops-card h3 {
                margin: 0 0 var(--space-md) 0;
                font-size: var(--font-size-md);
                font-weight: 600;
                color: var(--color-text-primary);
                letter-spacing: -0.01em;
            }

            /* Card header — flex between title and actions */
            .ops-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: var(--space-md);
                gap: var(--space-md);
            }
            .ops-card-header h3 { margin: 0; }

            /* Card accent variants */
            .ops-card--accent-left { border-left: 3px solid var(--color-primary); }
            .ops-card--accent-success { border-left: 3px solid var(--color-success); }
            .ops-card--accent-warning { border-left: 3px solid var(--color-warning); }
            .ops-card--accent-error { border-left: 3px solid var(--color-error); }
            
            /* ===== INPUT COMPONENT ===== */
            .ops-input {
                padding: 0 12px;
                border: 1px solid var(--color-border);
                border-radius: var(--radius-sm);
                height: 36px;
                line-height: 1;
                width: 100%;
                box-sizing: border-box;
                font-size: var(--font-size-base);
                font-family: var(--font-sans);
                color: var(--color-text-primary);
                background: var(--color-bg);
                transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
            }
            .ops-input::placeholder {
                color: var(--color-text-tertiary);
            }
            .ops-input:hover {
                border-color: var(--color-border-strong);
            }
            .ops-input:focus {
                border-color: var(--color-primary);
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
                outline: none;
            }
            
            /* ===== BUTTON COMPONENT ===== */
            .ops-btn {
                height: 36px;
                padding: 0 14px;
                border-radius: var(--radius-sm);
                font-weight: 500;
                cursor: pointer;
                border: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                font-size: var(--font-size-sm);
                font-family: var(--font-sans);
                text-decoration: none;
                transition: all var(--transition-fast);
                white-space: nowrap;
                box-shadow: none;
            }

            .ops-btn.primary {
                background: var(--color-primary);
                color: white;
                border: 1px solid var(--color-primary);
            }
            .ops-btn.primary:hover {
                background: var(--color-primary-hover);
                border-color: var(--color-primary-hover);
            }

            .ops-btn.secondary {
                background: var(--color-bg);
                color: var(--color-text-secondary);
                border: 1px solid var(--color-border);
            }
            .ops-btn.secondary:hover {
                background: var(--color-bg-subtle);
                border-color: var(--color-border-strong);
                color: var(--color-text-primary);
            }
            
            /* ===== TABLE COMPONENT ===== */
            .audit-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                font-size: var(--font-size-base);
            }
            .audit-table th {
                text-align: left;
                padding: 12px 16px;
                background: transparent;
                border-bottom: 1px solid var(--color-border);
                font-weight: 500;
                color: var(--color-text-tertiary);
                font-size: var(--font-size-sm);
                text-transform: none;
                letter-spacing: 0;
            }
            .audit-table tbody tr {
                transition: background-color var(--transition-fast);
            }
            .audit-table tbody tr:hover {
                background: var(--color-bg-subtle);
            }
            .audit-table tbody tr:not(:last-child) td {
                border-bottom: 1px solid var(--color-border);
            }
            .audit-table td {
                padding: 14px 16px;
                vertical-align: middle;
                color: var(--color-text-primary);
            }
            
            /* ===== STATUS BADGES ===== */
            .status-badge {
                padding: 3px 10px;
                border-radius: var(--radius-pill);
                font-weight: 500;
                font-size: var(--font-size-xs);
                text-transform: none;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                text-align: center;
                line-height: 1.4;
                letter-spacing: 0;
            }
            .status-badge.success {
                background: var(--color-success-bg);
                color: var(--color-success-text);
            }
            .status-badge.failed {
                background: var(--color-error-bg);
                color: var(--color-error-text);
            }
            .status-badge.pending {
                background: var(--color-warning-bg);
                color: var(--color-warning-text);
            }
            .status-badge.warning {
                background: var(--color-warning-bg);
                color: var(--color-warning-text);
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
                background: transparent;
                padding: 12px 8px;
                text-align: center;
                font-weight: 500;
                font-size: var(--font-size-sm);
                color: var(--color-text-tertiary);
                text-transform: none;
                letter-spacing: 0;
                border-bottom: 1px solid var(--color-border);
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
                font-weight: 500;
                font-size: var(--font-size-sm);
                margin-bottom: 6px;
                color: var(--color-text-secondary);
                text-transform: none;
                letter-spacing: 0;
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
                font-size: var(--font-size-sm);
                font-weight: 500;
                color: var(--color-text-tertiary);
                text-transform: none;
                letter-spacing: 0;
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
                background: rgba(0, 0, 0, 0.5);
                z-index: 9999;
                backdrop-filter: blur(4px);
                animation: modalFadeIn var(--transition-base);
            }
            @keyframes modalFadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            .audit-modal-content {
                background: var(--color-bg);
                width: 90%;
                max-width: 800px;
                max-height: 85vh;
                margin: 7.5vh auto;
                border-radius: var(--radius-lg);
                padding: 0;
                box-shadow: var(--shadow-overlay);
                overflow: hidden;
                display: flex;
                flex-direction: column;
                animation: modalSlideUp var(--transition-slow) ease;
            }
            @keyframes modalSlideUp {
                from { transform: translateY(16px) scale(0.98); opacity: 0; }
                to { transform: translateY(0) scale(1); opacity: 1; }
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
                border-radius: var(--radius-lg);
                padding: 20px;
                box-shadow: none;
                transition: border-color var(--transition-fast);
            }

            .stat-card:hover {
                border-color: var(--color-border-strong);
            }

            .stat-label {
                font-size: var(--font-size-sm);
                font-weight: 500;
                color: var(--color-text-tertiary);
                text-transform: none;
                letter-spacing: 0;
                margin-bottom: var(--space-sm);
            }

            .stat-value {
                font-size: var(--font-size-2xl);
                font-weight: 700;
                color: var(--color-text-primary);
                line-height: 1.1;
                margin-bottom: 0;
            }

            .stat-change {
                font-size: var(--font-size-xs);
                font-weight: 500;
                color: var(--color-text-tertiary);
                margin-top: 6px;
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
                background: #b91c1c;
                border-color: #b91c1c;
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

            /* ===== SIDEBAR LAYOUT ===== */
            .ops-layout {
                display: flex;
                min-height: calc(100vh - 32px);
                margin: 0;
                font-family: var(--font-sans);
            }

            .ops-sidebar {
                width: 250px;
                background: #1e293b;
                flex-shrink: 0;
                position: sticky;
                top: 32px;
                height: calc(100vh - 32px);
                overflow-y: auto;
                z-index: 100;
                display: flex;
                flex-direction: column;
            }

            .ops-sidebar-header {
                padding: 20px 20px 16px;
                border-bottom: 1px solid #334155;
            }

            .ops-sidebar-logo {
                width: 180px;
                height: auto;
                display: block;
                margin-bottom: 8px;
                filter: brightness(0) invert(1);
            }

            .ops-sidebar-version {
                display: inline-block;
                background: #0ea5e9;
                color: #fff;
                font-size: 10px;
                padding: 2px 8px;
                border-radius: 10px;
                font-weight: 500;
            }

            .ops-sidebar-nav {
                flex: 1;
                padding: 12px 0;
                overflow-y: auto;
            }

            .ops-nav-section {
                padding: 16px 20px 6px;
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 1.2px;
                color: #64748b;
            }

            .ops-nav-section:first-child {
                padding-top: 4px;
            }

            .ops-nav-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 9px 20px;
                color: #94a3b8;
                text-decoration: none;
                font-size: 13px;
                font-weight: 500;
                transition: all 0.15s ease;
                border-left: 3px solid transparent;
                cursor: pointer;
            }

            .ops-nav-item:hover {
                color: #e2e8f0;
                background: #334155;
            }

            .ops-nav-item:focus {
                color: #e2e8f0;
                background: #334155;
                outline: none;
            }

            .ops-nav-item.active {
                color: #ffffff;
                background: #334155;
                border-left-color: #3b82f6;
                font-weight: 600;
            }

            .ops-nav-icon {
                width: 18px;
                height: 18px;
                flex-shrink: 0;
                opacity: 0.7;
            }

            .ops-nav-item.active .ops-nav-icon {
                opacity: 1;
            }

            .ops-nav-badge {
                margin-left: auto;
                background: #ef4444;
                color: #fff;
                font-size: 10px;
                font-weight: 700;
                padding: 2px 7px;
                border-radius: 10px;
                min-width: 18px;
                text-align: center;
                line-height: 1.4;
            }

            .ops-main-content {
                flex: 1;
                padding: 24px 32px;
                background: var(--color-bg-subtle);
                min-width: 0;
                overflow-x: hidden;
            }

            .ops-page-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 24px;
            }

            .ops-page-title {
                font-size: var(--font-size-xl);
                font-weight: 600;
                color: var(--color-text-primary);
                margin: 0;
                letter-spacing: -0.025em;
                line-height: 1.3;
            }

            .ops-page-subtitle {
                font-size: var(--font-size-sm);
                color: var(--color-text-tertiary);
                margin-top: 6px;
                font-weight: 400;
                line-height: 1.5;
            }

            /* Sidebar footer */
            .ops-sidebar-footer {
                padding: 12px 20px;
                border-top: 1px solid #334155;
                font-size: 11px;
                color: #64748b;
            }

            /* Watchdog: orphan detection widget */
            .watchdog-alert { border-left: 3px solid var(--color-error); }
            .watchdog-clear { border-left: 3px solid var(--color-success); }
            .badge-offshift {
                background: var(--color-error-bg);
                color: var(--color-error-text);
                font-size: 10px;
                padding: 2px 6px;
                border-radius: var(--radius-pill);
                font-weight: 500;
                text-transform: none;
            }
            .badge-onshift {
                background: var(--color-success-bg);
                color: var(--color-success-text);
                font-size: 10px;
                padding: 2px 6px;
                border-radius: var(--radius-pill);
                font-weight: 500;
                text-transform: none;
            }
            .queue-count {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 24px;
                height: 20px;
                border-radius: var(--radius-pill);
                font-size: 11px;
                font-weight: 700;
                padding: 0 6px;
            }
            .queue-count.low { background: var(--color-success-bg, #dcfce7); color: #065f46; }
            .queue-count.medium { background: var(--color-warning-bg, #fef9c3); color: #92400e; }
            .queue-count.high { background: var(--color-error-bg, #fef2f2); color: #991b1b; }
            .handoff-good { color: var(--color-success, #16a34a); }
            .handoff-neutral { color: var(--color-text-secondary, #6b7280); }
            .handoff-bad { color: var(--color-error, #dc2626); }

            /* Override old wrapper styles when using sidebar layout */
            .ops-layout .ops-main-content .ops-wrapper {
                max-width: none;
                padding: 0;
            }

            /* Remove WP admin padding/margins so our layout fills edge-to-edge */
            body.toplevel_page_ai-ops #wpcontent {
                padding-left: 0;
                overflow-x: hidden;
            }
            body.toplevel_page_ai-ops #wpbody-content {
                overflow: visible;
            }
            body.toplevel_page_ai-ops .wrap {
                margin: 0;
            }

            /* Hide ALL WP/plugin admin notices on our pages */
            body.toplevel_page_ai-ops .notice,
            body.toplevel_page_ai-ops .updated,
            body.toplevel_page_ai-ops .update-nag,
            body.toplevel_page_ai-ops .error:not(.ops-card),
            body.toplevel_page_ai-ops .is-dismissible,
            body.toplevel_page_ai-ops #wpbody-content > .notice,
            body.toplevel_page_ai-ops #wpbody-content > .updated,
            body.toplevel_page_ai-ops #wpbody-content > div.error,
            body.toplevel_page_ai-ops #wpbody-content > div.update-nag {
                display: none !important;
            }

            /* ===== UTILITY COMPONENTS ===== */

            /* Empty state */
            .ops-empty-state {
                text-align: center;
                padding: 48px 24px;
            }
            .ops-empty-state-icon {
                width: 48px;
                height: 48px;
                margin: 0 auto 16px;
                color: var(--color-text-tertiary);
                opacity: 0.4;
            }
            .ops-empty-state-title {
                font-size: var(--font-size-md);
                font-weight: 500;
                color: var(--color-text-secondary);
                margin-bottom: 8px;
            }
            .ops-empty-state-description {
                font-size: var(--font-size-sm);
                color: var(--color-text-tertiary);
                max-width: 360px;
                margin: 0 auto;
                line-height: 1.5;
            }

            /* Pagination */
            .ops-pagination {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 0;
                margin-top: var(--space-md);
            }
            .ops-pagination-info {
                font-size: var(--font-size-sm);
                color: var(--color-text-tertiary);
            }
            .ops-pagination-links {
                display: flex;
                gap: 2px;
            }
            .ops-pagination-links .ops-btn {
                height: 32px;
                min-width: 32px;
                padding: 0 10px;
                font-size: var(--font-size-sm);
            }

            /* Grid utilities */
            .ops-grid-2 {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: var(--space-lg);
                margin-bottom: var(--space-lg);
            }
            .ops-grid-auto {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                gap: var(--space-lg);
                margin-bottom: var(--space-lg);
            }

            /* Chip */
            .ops-chip {
                display: inline-block;
                padding: 3px 10px;
                background: var(--color-bg-subtle);
                border: 1px solid var(--color-border);
                border-radius: var(--radius-pill);
                font-size: var(--font-size-xs);
                color: var(--color-text-secondary);
                line-height: 1.4;
            }

            /* Filter bar */
            .ops-filter-bar {
                padding: 16px 20px;
                background: var(--color-bg);
                border-bottom: 1px solid var(--color-border);
                border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            }

            /* Period filter */
            .ops-period-filter {
                display: flex;
                gap: 4px;
                margin-bottom: var(--space-md);
            }

            /* Section label — only place uppercase is used */
            .ops-section-label {
                font-size: var(--font-size-xs);
                font-weight: 600;
                color: var(--color-text-tertiary);
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin-bottom: 12px;
            }

            /* Sticky save bar for forms */
            .ops-save-bar {
                position: sticky;
                bottom: 0;
                background: var(--color-bg);
                border-top: 1px solid var(--color-border);
                padding: 16px 24px;
                display: flex;
                align-items: center;
                gap: 12px;
                z-index: 10;
                margin: 24px -24px -24px;
                border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            }

            /* Token display */
            .ops-token-display {
                font-family: var(--font-mono);
                font-size: var(--font-size-sm);
                padding: 12px 16px;
                background: var(--color-bg-subtle);
                border: 1px solid var(--color-border);
                border-radius: var(--radius-sm);
                word-break: break-all;
            }

            /* Body scroll lock for modals */
            body.modal-open {
                overflow: hidden;
            }

            @media (max-width: 1100px) {
                .ops-grid-2 { grid-template-columns: 1fr; }
            }

            /* ===== RESPONSIVE: collapse sidebar on small screens ===== */
            @media (max-width: 960px) {
                .ops-sidebar {
                    width: 56px;
                    overflow: visible;
                }
                .ops-sidebar-header {
                    padding: 12px;
                    text-align: center;
                }
                .ops-sidebar-logo,
                .ops-sidebar-version,
                .ops-nav-section,
                .ops-sidebar-footer {
                    display: none;
                }
                .ops-nav-item {
                    padding: 12px;
                    justify-content: center;
                }
                .ops-nav-item span:not(.ops-nav-icon):not(.ops-nav-badge) {
                    display: none;
                }
                .ops-nav-item .ops-nav-badge {
                    position: absolute;
                    top: 4px;
                    right: 4px;
                    padding: 1px 5px;
                    font-size: 9px;
                }
                .ops-main-content {
                    padding: 16px;
                }
            }
        </style>
        <?php
    }
}