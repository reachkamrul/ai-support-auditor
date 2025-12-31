<?php
/**
 * Token Verification Middleware
 * 
 * @package SupportOps\API\Middleware
 */

namespace SupportOps\API\Middleware;

class TokenVerification {
    
    /**
     * Verify security token from request header
     * 
     * @param \WP_REST_Request $request Request object
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public function verify($request) {
        $token = $request->get_header('X-Audit-Token');
        $stored_token = get_option('ai_audit_secret_token');
        
        // Log token verification attempts for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown';
            error_log('API Token Verification - URI: ' . $request_uri);
            error_log('API Token Verification - Header present: ' . ($token ? 'yes' : 'no'));
            error_log('API Token Verification - Stored token present: ' . ($stored_token ? 'yes' : 'no'));
        }
        
        if (!$stored_token) {
            error_log('API Error: Security token not configured in WordPress options');
            return new \WP_Error(
                'no_token',
                'Security token not configured',
                ['status' => 500]
            );
        }
        
        if (empty($token)) {
            error_log('API Error: X-Audit-Token header is missing from request');
            return new \WP_Error(
                'missing_token',
                'X-Audit-Token header is required. Please include it in your request headers.',
                ['status' => 401]
            );
        }
        
        if ($token !== $stored_token) {
            error_log('API Error: Invalid security token provided. Token length: ' . strlen($token) . ', Expected length: ' . strlen($stored_token));
            return new \WP_Error(
                'invalid_token',
                'Invalid security token',
                ['status' => 403]
            );
        }
        
        return true;
    }
}