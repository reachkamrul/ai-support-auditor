<?php
/**
 * API Manager
 * 
 * @package SupportOps\API
 */

namespace SupportOps\API;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\API\Endpoints;
use SupportOps\API\Middleware\TokenVerification;

class Manager {
    
    /**
     * Database manager
     */
    private $database;
    
    /**
     * Token verification middleware
     */
    private $token_verifier;
    
    /**
     * API endpoints
     */
    private $endpoints = [];
    
    /**
     * Constructor
     */
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->token_verifier = new TokenVerification();
        $this->init_endpoints();
    }
    
    /**
     * Initialize endpoint handlers
     */
    private function init_endpoints() {
        $this->endpoints = [
            'audit' => new Endpoints\AuditEndpoint($this->database),
            'shift' => new Endpoints\ShiftEndpoint($this->database),
            'agent' => new Endpoints\AgentEndpoint($this->database),
            'system_message' => new Endpoints\SystemMessageEndpoint($this->database),
            'live_audit' => new Endpoints\LiveAuditEndpoint()
        ];
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        $namespace = 'ai-audit/v1';
        
        // Audit endpoints
        register_rest_route($namespace, '/save-result', [
            'methods' => 'POST',
            'callback' => [$this->endpoints['audit'], 'save_result'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);
        
        register_rest_route($namespace, '/get-pending', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['audit'], 'get_pending'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);
        
        register_rest_route($namespace, '/get-ticket-with-responses', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['audit'], 'get_ticket_with_responses'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);

        register_rest_route($namespace, '/queue-stats', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['audit'], 'get_queue_stats'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);
        
        // Shift endpoints
        register_rest_route($namespace, '/get-shift-context', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['shift'], 'get_context'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);
        
        register_rest_route($namespace, '/check-shift', [
            'methods' => 'POST',
            'callback' => [$this->endpoints['shift'], 'check_shift'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);
        
        register_rest_route($namespace, '/check-shifts-batch', [
            'methods' => 'POST',
            'callback' => [$this->endpoints['shift'], 'check_shifts_batch'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);
        
        // System message endpoints
        register_rest_route($namespace, '/get-system-message', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['system_message'], 'get_message'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);
        
        register_rest_route($namespace, '/save-system-message', [
            'methods' => 'POST',
            'callback' => [$this->endpoints['system_message'], 'save_message'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);
        
        register_rest_route($namespace, '/test-system-message', [
            'methods' => 'POST',
            'callback' => [$this->endpoints['system_message'], 'test_message'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);

        // Live audit endpoint (called by N8N when FluentSupport fires webhook)
        register_rest_route($namespace, '/queue-live-audit', [
            'methods' => 'POST',
            'callback' => [$this->endpoints['live_audit'], 'queue'],
            'permission_callback' => [$this->token_verifier, 'verify']
        ]);

        // Agent endpoints (admin only)
        register_rest_route($namespace, '/agents', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['agent'], 'get_all'],
            'permission_callback' => '__return_true' // Admin check in callback
        ]);
        
        register_rest_route($namespace, '/agents/(?P<email>[^/]+)', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['agent'], 'get_detail'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route($namespace, '/agents/(?P<email>[^/]+)/trend', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['agent'], 'get_trend'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route($namespace, '/agents/(?P<email>[^/]+)/compare', [
            'methods' => 'GET',
            'callback' => [$this->endpoints['agent'], 'get_comparison'],
            'permission_callback' => '__return_true'
        ]);
    }
}