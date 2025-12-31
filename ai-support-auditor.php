<?php
/**
 * Plugin Name: Support Ops & AI Auditor (v10.0 - Enterprise Edition - OOP)
 * Description: 360° Support Operations Platform with Shift Management, AI Auditing, Agent Attribution, and Knowledge Gap Detection.
 * Version: 10.0-OOP
 */

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('SUPPORT_OPS_VERSION', '10.0');
define('SUPPORT_OPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUPPORT_OPS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('N8N_FORCE_URL', 'https://team.junior.ninja/webhook/force-audit');

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'SupportOps\\';
    $base_dir = SUPPORT_OPS_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize plugin
SupportOps\Plugin::get_instance();