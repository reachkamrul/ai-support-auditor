<?php
/**
 * Agent Endpoint
 * 
 * @package SupportOps\API\Endpoints
 */

namespace SupportOps\API\Endpoints;

use SupportOps\Database\Manager as DatabaseManager;

class AgentEndpoint {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function get_all($request) {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('forbidden', 'Access denied', ['status' => 403]);
        }
        
        global $wpdb;
        
        $date_from = $request->get_param('date_from') ?: date('Y-m-d', strtotime('-30 days'));
        $date_to = $request->get_param('date_to') ?: date('Y-m-d');
        $per_page = intval($request->get_param('per_page') ?: 20);
        $page = intval($request->get_param('page') ?: 1);
        $offset = ($page - 1) * $per_page;
        
        $query = "
            SELECT 
                ae.agent_email,
                ae.agent_name,
                COUNT(DISTINCT ae.ticket_id) as total_tickets,
                ROUND(AVG(ae.overall_agent_score), 1) as avg_overall_score,
                ROUND(AVG(ae.timing_score), 1) as avg_timing_score,
                ROUND(AVG(ae.resolution_score), 1) as avg_resolution_score,
                ROUND(AVG(ae.communication_score), 1) as avg_communication_score
            FROM {$wpdb->prefix}ais_agent_evaluations ae
            WHERE DATE(ae.created_at) BETWEEN %s AND %s AND ae.exclude_from_stats = 0
            GROUP BY ae.agent_email, ae.agent_name
            ORDER BY avg_overall_score DESC
            LIMIT %d OFFSET %d
        ";
        
        $agents = $wpdb->get_results($wpdb->prepare($query, $date_from, $date_to, $per_page, $offset));
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ae.agent_email)
             FROM {$wpdb->prefix}ais_agent_evaluations ae
             WHERE DATE(ae.created_at) BETWEEN %s AND %s",
            $date_from, $date_to
        ));
        
        return rest_ensure_response([
            'agents' => $agents,
            'pagination' => [
                'total' => intval($total),
                'per_page' => $per_page,
                'current_page' => $page,
                'total_pages' => ceil($total / $per_page)
            ]
        ]);
    }
    
    public function get_detail($request) {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('forbidden', 'Access denied', ['status' => 403]);
        }
        
        global $wpdb;
        
        $agent_email = urldecode($request['email']);
        $date_from = $request->get_param('date_from') ?: date('Y-m-d', strtotime('-30 days'));
        $date_to = $request->get_param('date_to') ?: date('Y-m-d');
        
        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT 
                ae.agent_name,
                ae.agent_email,
                COUNT(DISTINCT ae.ticket_id) as total_tickets,
                ROUND(AVG(ae.overall_agent_score), 1) as avg_overall_score
            FROM {$wpdb->prefix}ais_agent_evaluations ae
            WHERE ae.agent_email = %s AND DATE(ae.created_at) BETWEEN %s AND %s AND ae.exclude_from_stats = 0
            GROUP BY ae.agent_email, ae.agent_name
        ", $agent_email, $date_from, $date_to));
        
        if (!$summary) {
            return new \WP_Error('not_found', 'Agent not found', ['status' => 404]);
        }
        
        return rest_ensure_response(['agent' => $summary]);
    }
    
    public function get_trend($request) {
        return ['message' => 'Trend endpoint - implement as needed'];
    }
    
    public function get_comparison($request) {
        return ['message' => 'Comparison endpoint - implement as needed'];
    }
}