<?php
/**
 * Audit Endpoint
 * 
 * @package SupportOps\API\Endpoints
 */

namespace SupportOps\API\Endpoints;

use SupportOps\Database\Manager as DatabaseManager;
use SupportOps\Services\ContributionSaver;
use SupportOps\Services\EvaluationSaver;
use SupportOps\Services\ProblemContextSaver;
use SupportOps\Services\TopicStatsUpdater;

class AuditEndpoint {
    
    private $database;
    private $contribution_saver;
    private $evaluation_saver;
    private $problem_saver;
    private $topic_updater;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
        $this->contribution_saver = new ContributionSaver($database);
        $this->evaluation_saver = new EvaluationSaver($database);
        $this->problem_saver = new ProblemContextSaver($database);
        $this->topic_updater = new TopicStatsUpdater($database);
    }
    
    public function save_result($request) {
        global $wpdb;
        
        try {
            // Log request details for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $headers = $request->get_headers();
                error_log('API save_result - Request method: ' . $request->get_method());
                error_log('API save_result - Request headers: ' . print_r($headers, true));
                error_log('API save_result - Request body: ' . $request->get_body());
            }
            
            // Get request data
            $data = $request->get_json_params();
            
            // Log incoming request for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('API save_result called with data: ' . print_r($data, true));
            }
            
            // Validate ticket_id
            $ticket_id = isset($data['ticket_id']) ? intval($data['ticket_id']) : null;
            if (!$ticket_id || $ticket_id <= 0) {
                error_log('API Error: Missing or invalid ticket_id');
                return new \WP_Error(
                    'missing_ticket_id', 
                    'ticket_id is required and must be a positive integer', 
                    ['status' => 400]
                );
            }
            
            $save_warnings = [];

            // Validate status
            $status = isset($data['status']) ? sanitize_text_field($data['status']) : 'success';
            if (!in_array($status, ['pending', 'success', 'failed'])) {
                $status = 'success';
            }
            
            // Prepare update data
            $update_data = [
                'status' => $status,
                'overall_score' => isset($data['score']) ? max(-200, min(100, intval($data['score']))) : null,
                'error_message' => isset($data['error_message']) ? sanitize_text_field($data['error_message']) : null
            ];
            
            // Handle raw_json
            if (!empty($data['raw_json'])) {
                try {
                    $update_data['raw_json'] = is_string($data['raw_json']) 
                        ? $data['raw_json'] 
                        : json_encode($data['raw_json'], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    error_log('API Error: Failed to encode raw_json - ' . $e->getMessage());
                }
            }
            
            // Handle audit_response
            if (!empty($data['audit_response'])) {
                try {
                    $update_data['audit_response'] = is_string($data['audit_response']) 
                        ? $data['audit_response'] 
                        : json_encode($data['audit_response'], JSON_UNESCAPED_UNICODE);
                        
                    // Parse and save related data
                    $audit = $this->parse_audit_response($data['audit_response']);

                    if ($audit && is_array($audit)) {
                        // Count Critical HR violations and High-severity issues for score enforcement
                        $critical_hr_count = 0;
                        $high_count = 0;
                        $medium_count = 0;
                        foreach ($audit['problem_contexts'] ?? [] as $pc) {
                            $sev = $pc['severity'] ?? '';
                            $cat = $pc['category'] ?? '';
                            if ($sev === 'Critical' && $cat === 'HR Violation') {
                                $critical_hr_count++;
                            } elseif ($sev === 'High') {
                                $high_count++;
                            } elseif ($sev === 'Medium') {
                                $medium_count++;
                            }
                        }

                        // Recalculate overall_score using rubric formula
                        $ai_score = intval($audit['audit_summary']['overall_score'] ?? 0);
                        if ($critical_hr_count > 0 || $high_count > 0 || $medium_count > 0) {
                            $calculated = 100
                                - ($critical_hr_count * 100)
                                - ($high_count * 30)
                                - ($medium_count * 15);
                            // Allow up to +20 recovery if AI score suggests positive elements
                            if ($ai_score > $calculated) {
                                $calculated = min($calculated + 20, $ai_score);
                            }
                            // Critical HR = score must be ≤ 0
                            if ($critical_hr_count > 0) {
                                $calculated = min($calculated, 0);
                            }
                            $ai_score = max(-200, $calculated);
                            $audit['audit_summary']['overall_score'] = $ai_score;
                        }
                        $update_data['overall_score'] = $ai_score;

                        // Enforce sentiment based on corrected score
                        if ($ai_score < 0) {
                            $update_data['overall_sentiment'] = 'Negative';
                        } elseif ($ai_score <= 50) {
                            $update_data['overall_sentiment'] = 'Mixed';
                        } else {
                            $update_data['overall_sentiment'] = 'Positive';
                        }
                        // Sync sentiment in audit JSON blob for consistency
                        $audit['audit_summary']['overall_sentiment'] = $update_data['overall_sentiment'];

                        // Post-process AI scores for accuracy
                        if (!empty($audit['agent_evaluations'])) {
                            // Count Critical HR violations per agent from problem_contexts
                            $hr_violations_by_agent = [];
                            foreach ($audit['problem_contexts'] ?? [] as $pc) {
                                if (($pc['category'] ?? '') === 'HR Violation'
                                    && ($pc['severity'] ?? '') === 'Critical') {
                                    foreach ($pc['handling_agents'] ?? [] as $ha) {
                                        $aid = $ha['agent_id'] ?? '';
                                        if ($aid) {
                                            $hr_violations_by_agent[$aid] = ($hr_violations_by_agent[$aid] ?? 0) + 1;
                                        }
                                    }
                                }
                            }

                            // Track agents with missing shift data
                            $shift_data_notes = [];

                            foreach ($audit['agent_evaluations'] as &$ev) {
                                // --- TIMING_SCORE: Responsibility-based, threshold-enforced ---
                                $ai_timing = isset($ev['timing_score']) ? intval($ev['timing_score']) : 0;
                                $ai_timing = max(-100, min(0, $ai_timing)); // basic clamp

                                // Parse response_breakdown to find worst on-shift delay
                                $max_on_shift_delay_hours = 0;
                                $has_on_shift_data = false;
                                foreach ($ev['response_breakdown'] ?? [] as $rb) {
                                    if (!empty($rb['was_on_shift'])) {
                                        $has_on_shift_data = true;
                                        $delay_hours = $this->parse_delay_hours($rb['time_since_previous'] ?? '0');
                                        if ($delay_hours > $max_on_shift_delay_hours) {
                                            $max_on_shift_delay_hours = $delay_hours;
                                        }
                                    }
                                }

                                // Check shift data availability via database
                                $agent_email = $ev['agent_email'] ?? '';
                                $shift_data_exists = true;
                                if ($agent_email) {
                                    $timestamps = array_column($ev['response_breakdown'] ?? [], 'timestamp');
                                    if (!empty($timestamps)) {
                                        $earliest = min($timestamps);
                                        $latest = max($timestamps);
                                        $shift_count = $wpdb->get_var($wpdb->prepare(
                                            "SELECT COUNT(*) FROM {$wpdb->prefix}ais_agent_shifts
                                             WHERE agent_email = %s
                                             AND shift_end >= %s
                                             AND shift_start <= %s",
                                            $agent_email, $earliest, $latest
                                        ));
                                        if (intval($shift_count) === 0) {
                                            $shift_data_exists = false;
                                            $shift_data_notes[] = [
                                                'agent' => $agent_email,
                                                'agent_name' => $ev['agent_name'] ?? '',
                                                'period' => $earliest . ' to ' . $latest,
                                                'status' => 'no_shift_data',
                                                'note' => 'Timing score excused — no shift records found for this period'
                                            ];
                                        }
                                    }
                                }

                                // Calculate our threshold-based penalty ceiling
                                $threshold_penalty = $this->calculate_timing_penalty($max_on_shift_delay_hours);

                                if (!$shift_data_exists) {
                                    // Shift data missing → excuse timing entirely
                                    $ev['timing_score'] = 0;
                                } else {
                                    // Always use our calculated penalty from response_breakdown delays.
                                    // AI's timing_score is unreliable (Gemini ignores prose rules),
                                    // so we enforce thresholds from actual on-shift delay data.
                                    $ev['timing_score'] = $threshold_penalty;
                                }

                                // Clamp resolution_score (0 to 100)
                                // Cap resolution proportional to contribution
                                if (isset($ev['resolution_score'])) {
                                    $res = intval($ev['resolution_score']);
                                    $contrib = intval($ev['contribution_percentage'] ?? 0);
                                    if ($contrib <= 5 && $res > 5) {
                                        $res = 5;
                                    } elseif ($contrib <= 10 && $res > 10) {
                                        $res = 10;
                                    } elseif ($contrib <= 20 && $res > 20) {
                                        $res = 20;
                                    }
                                    $ev['resolution_score'] = max(0, min(100, $res));
                                }

                                // Clamp communication_score (-60 to +30)
                                if (isset($ev['communication_score'])) {
                                    $comm = intval($ev['communication_score']);

                                    // Cap communication for low-reply agents (≤2 replies = baseline only)
                                    $reply_count = intval($ev['reply_count'] ?? 0);
                                    if ($reply_count <= 2 && $comm > 10) {
                                        $comm = 10; // 1-2 routine responses can't earn more than +10
                                    }

                                    // If agent has Critical HR violations but communication_score is positive,
                                    // auto-correct: each HR violation = -25 per rubric checklist
                                    $agent_email = $ev['agent_email'] ?? '';
                                    $hr_count = $hr_violations_by_agent[$agent_email] ?? 0;
                                    if ($hr_count > 0 && $comm > 0) {
                                        $comm = min($comm, 30 - ($hr_count * 25));
                                    }

                                    $ev['communication_score'] = max(-60, min(30, $comm));
                                }

                                // Recalculate overall_agent_score
                                $ev['overall_agent_score'] = intval($ev['timing_score'] ?? 0)
                                    + intval($ev['resolution_score'] ?? 0)
                                    + intval($ev['communication_score'] ?? 0);
                            }
                            unset($ev);

                            // Store shift data notes in audit JSON for tracking
                            if (!empty($shift_data_notes)) {
                                $audit['audit_summary']['shift_data_notes'] = $shift_data_notes;
                            }

                            // Remove customer HR violations and product-bug problem_contexts
                            $audit['problem_contexts'] = array_values(array_filter(
                                $audit['problem_contexts'] ?? [],
                                function($pc) {
                                    // Filter out product bugs — "Technical Inaccuracy" is not a valid
                                    // agent performance category (removed from schema). Always filter it.
                                    $cat = $pc['category'] ?? '';
                                    if ($cat === 'Technical Inaccuracy') {
                                        return false;
                                    }

                                    // Keep everything except customer-related HR violations
                                    if ($cat === 'HR Violation') {
                                        $desc = strtolower($pc['issue_description'] ?? '');
                                        if (strpos($desc, 'customer') === 0 || strpos($desc, 'the customer') !== false) {
                                            if (strpos($desc, 'agent') === false) {
                                                return false;
                                            }
                                        }
                                    }
                                    return true;
                                }
                            ));

                            $update_data['audit_response'] = json_encode($audit, JSON_UNESCAPED_UNICODE);
                        }

                        $savers = [
                            'contributions' => [$this->contribution_saver, 'save', [$ticket_id, $audit]],
                            'evaluations'   => [$this->evaluation_saver, 'save', [$ticket_id, $audit]],
                            'problems'      => [$this->problem_saver, 'save', [$ticket_id, $audit]],
                            'topics'        => [$this->topic_updater, 'update', [$audit]],
                        ];

                        foreach ($savers as $name => [$saver, $method, $args]) {
                            try {
                                call_user_func_array([$saver, $method], $args);
                            } catch (\Exception $e) {
                                $msg = "Failed to save {$name} for ticket {$ticket_id}: " . $e->getMessage();
                                error_log('API Error: ' . $msg);
                                $save_warnings[] = $msg;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log('API Error: Failed to process audit_response - ' . $e->getMessage());
                }
            }
            
            // Update latest pending audit
            $pending_audit = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ais_audits 
                 WHERE ticket_id = %d AND status = 'pending' 
                 ORDER BY created_at DESC LIMIT 1",
                $ticket_id
            ));
            
            $audit_id = null;
            
            if ($pending_audit) {
                $result = $wpdb->update(
                    $wpdb->prefix . 'ais_audits',
                    $update_data,
                    ['id' => $pending_audit->id]
                );
                
                if ($result === false) {
                    error_log('API Error: Database update failed - ' . $wpdb->last_error);
                    return new \WP_Error(
                        'database_error',
                        'Failed to update audit record: ' . $wpdb->last_error,
                        ['status' => 500]
                    );
                }
                
                $audit_id = $pending_audit->id;
            } else {
                if ($status === 'pending') {
                    $result = $wpdb->insert(
                        $wpdb->prefix . 'ais_audits',
                        array_merge($update_data, ['ticket_id' => $ticket_id])
                    );
                    
                    if ($result === false) {
                        error_log('API Error: Database insert failed - ' . $wpdb->last_error);
                        return new \WP_Error(
                            'database_error',
                            'Failed to insert audit record: ' . $wpdb->last_error,
                            ['status' => 500]
                        );
                    }
                    
                    $audit_id = $wpdb->insert_id;
                } else {
                    $latest_audit = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}ais_audits 
                         WHERE ticket_id = %d 
                         ORDER BY created_at DESC LIMIT 1",
                        $ticket_id
                    ));
                    
                    if ($latest_audit) {
                        $result = $wpdb->update(
                            $wpdb->prefix . 'ais_audits',
                            $update_data,
                            ['id' => $latest_audit->id]
                        );
                        
                        if ($result === false) {
                            error_log('API Error: Database update failed - ' . $wpdb->last_error);
                            return new \WP_Error(
                                'database_error',
                                'Failed to update audit record: ' . $wpdb->last_error,
                                ['status' => 500]
                            );
                        }
                        
                        $audit_id = $latest_audit->id;
                    } else {
                        $result = $wpdb->insert(
                            $wpdb->prefix . 'ais_audits',
                            array_merge($update_data, ['ticket_id' => $ticket_id])
                        );
                        
                        if ($result === false) {
                            error_log('API Error: Database insert failed - ' . $wpdb->last_error);
                            return new \WP_Error(
                                'database_error',
                                'Failed to insert audit record: ' . $wpdb->last_error,
                                ['status' => 500]
                            );
                        }
                        
                        $audit_id = $wpdb->insert_id;
                    }
                }
            }
            
            $response = [
                'status' => 'saved',
                'ticket_id' => $ticket_id,
                'audit_id' => $audit_id
            ];

            if (!empty($save_warnings)) {
                $response['status'] = 'saved_with_warnings';
                $response['warnings'] = $save_warnings;
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('API save_result success: ' . json_encode($response));
            }
            
            return $response;
            
        } catch (\Exception $e) {
            error_log('API Exception in save_result: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return new \WP_Error(
                'server_error',
                'An error occurred while saving audit result: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
    
    public function get_pending($request) {
        global $wpdb;
        
        $limit = intval($request->get_param('limit') ?: 10);
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT a.ticket_id, a.status, a.created_at
             FROM {$wpdb->prefix}ais_audits a
             INNER JOIN (
                 SELECT ticket_id, MAX(id) as max_id
                 FROM {$wpdb->prefix}ais_audits
                 WHERE status = 'pending' 
                    OR (status = 'failed' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
                 GROUP BY ticket_id
             ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
             ORDER BY 
                CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END,
                a.created_at ASC 
             LIMIT %d",
            $limit
        ));
        
        return array_map(function($r) { 
            return [
                'ticket_id' => $r->ticket_id, 
                'retry' => ($r->status === 'failed')
            ]; 
        }, $results);
    }
    
    /**
     * Parse delay duration string to hours.
     * Handles formats: "6h 26m 32s", "2 hours", "1 day 3h", "0 hours", "3 days", etc.
     */
    private function parse_delay_hours($time_str) {
        $hours = 0;
        $time_str = strtolower(trim($time_str));
        if (preg_match('/(\d+)\s*d(ay)?s?/', $time_str, $m)) {
            $hours += intval($m[1]) * 24;
        }
        if (preg_match('/(\d+)\s*h(our|r)?s?/', $time_str, $m)) {
            $hours += intval($m[1]);
        }
        if (preg_match('/(\d+)\s*m(in(ute)?)?s?/', $time_str, $m)) {
            $hours += intval($m[1]) / 60.0;
        }
        return $hours;
    }

    /**
     * Calculate timing penalty from delay duration using v2 thresholds.
     * Returns 0 to -80 (never positive, never below -80 for single delay).
     */
    private function calculate_timing_penalty($delay_hours) {
        if ($delay_hours <= 4) return 0;
        if ($delay_hours <= 8) return -5;
        if ($delay_hours <= 12) return -15;
        if ($delay_hours <= 24) return -30;
        if ($delay_hours <= 48) return -50;
        return -80;
    }

    private function parse_audit_response($audit_response) {
        if (is_array($audit_response) || is_object($audit_response)) {
            return is_object($audit_response) ? (array)$audit_response : $audit_response;
        } elseif (is_string($audit_response)) {
            return json_decode($audit_response, true);
        }
        return null;
    }
    
    /**
     * Get ticket with responses for N8N workflow
     * This endpoint provides ticket data with responses included in the format N8N expects
     * Uses FluentSupport PHP API (same as TranscriptBuilder)
     * 
     * @param \WP_REST_Request $request Request object
     * @return array|WP_Error Ticket data with responses or error
     */
    public function get_ticket_with_responses($request) {
        $ticket_id = intval($request->get_param('ticket_id'));
        
        if (!$ticket_id || $ticket_id <= 0) {
            return new \WP_Error(
                'invalid_ticket_id',
                'ticket_id is required and must be a positive integer',
                ['status' => 400]
            );
        }
        
        if (!function_exists('FluentSupportApi')) {
            return new \WP_Error(
                'fluent_support_not_available',
                'FluentSupport plugin is not active',
                ['status' => 500]
            );
        }
        
        try {
            // Use FluentSupport PHP API (same approach as TranscriptBuilder)
            $api = FluentSupportApi('tickets');
            $ticket = $api->getTicket($ticket_id);
            
            if (!$ticket) {
                return new \WP_Error(
                    'ticket_not_found',
                    'Ticket not found',
                    ['status' => 404]
                );
            }
            
            // Helper function to format date
            $format_date = function($date) {
                if (empty($date)) {
                    return '';
                }
                if (is_object($date)) {
                    if (isset($date->date)) {
                        return $date->date;
                    } elseif (method_exists($date, 'format')) {
                        return $date->format('Y-m-d H:i:s');
                    } elseif (method_exists($date, '__toString')) {
                        return (string)$date;
                    }
                }
                if (is_array($date) && isset($date['date'])) {
                    return $date['date'];
                }
                if (is_string($date)) {
                    return $date;
                }
                return '';
            };
            
            // Format ticket data
            $formatted_ticket_data = [
                'ticket' => [
                    'id' => $ticket->id,
                    'title' => isset($ticket->title) ? $ticket->title : '',
                    'status' => isset($ticket->status) ? $ticket->status : '',
                    'created_at' => $format_date(isset($ticket->created_at) ? $ticket->created_at : ''),
                    'updated_at' => $format_date(isset($ticket->updated_at) ? $ticket->updated_at : ''),
                    'content' => isset($ticket->content) ? $ticket->content : '',
                ],
                'responses' => []
            ];
            
            // Get responses - try multiple methods
            $responses = [];
            if (isset($ticket->responses) && is_array($ticket->responses)) {
                $responses = $ticket->responses;
            } else {
                try {
                    $response_api = FluentSupportApi('responses');
                    if (method_exists($response_api, 'getResponses')) {
                        $responses = $response_api->getResponses(['ticket_id' => $ticket_id]);
                    } elseif (method_exists($response_api, 'getTicketResponses')) {
                        $responses = $response_api->getTicketResponses($ticket_id);
                    }
                } catch (\Exception $e) {
                    error_log('Could not fetch responses via API: ' . $e->getMessage());
                }
            }
            
            // Format responses to match N8N's expected structure
            if ($responses && is_array($responses)) {
                foreach ($responses as $response) {
                    $response_obj = is_object($response) ? $response : (object)$response;
                    
                    // Get person data
                    $person = null;
                    $person_id = isset($response_obj->person_id) ? $response_obj->person_id : null;
                    
                    if (isset($response_obj->person)) {
                        $person = is_object($response_obj->person) ? $response_obj->person : (object)$response_obj->person;
                    } elseif ($person_id) {
                        try {
                            $person_api = FluentSupportApi('persons');
                            if (method_exists($person_api, 'getPerson')) {
                                $person = $person_api->getPerson($person_id);
                            }
                        } catch (\Exception $e) {
                            // Person not found, continue
                        }
                    }
                    
                    // Build person data
                    $person_data = null;
                    if ($person) {
                        $person_obj = is_object($person) ? $person : (object)$person;
                        $first_name = isset($person_obj->first_name) ? $person_obj->first_name : '';
                        $last_name = isset($person_obj->last_name) ? $person_obj->last_name : '';
                        
                        $person_data = [
                            'id' => isset($person_obj->id) ? $person_obj->id : null,
                            'email' => isset($person_obj->email) ? $person_obj->email : null,
                            'first_name' => $first_name,
                            'last_name' => $last_name,
                            'name' => isset($person_obj->name) ? $person_obj->name : null,
                            'person_type' => isset($person_obj->person_type) ? $person_obj->person_type : 
                                           (isset($person_obj->type) ? $person_obj->type : null),
                        ];
                        
                        $person_data['full_name'] = trim($first_name . ' ' . $last_name);
                        if (empty($person_data['full_name']) && $person_data['name']) {
                            $person_data['full_name'] = $person_data['name'];
                        }
                    }
                    
                    // Format response
                    $created_at = isset($response_obj->created_at) ? $response_obj->created_at : 
                                 (isset($response_obj->created) ? $response_obj->created : 
                                 (isset($response_obj->timestamp) ? $response_obj->timestamp : ''));
                    $created_at = $format_date($created_at);
                    
                    $formatted_response = [
                        'id' => isset($response_obj->id) ? $response_obj->id : null,
                        'content' => isset($response_obj->content) ? $response_obj->content : 
                                    (isset($response_obj->message) ? $response_obj->message : ''),
                        'created_at' => $created_at,
                        'created' => $created_at,
                        'timestamp' => $created_at,
                        'person_id' => $person_id,
                        'type' => isset($response_obj->type) ? $response_obj->type : null,
                        'person' => $person_data
                    ];
                    
                    $formatted_ticket_data['responses'][] = $formatted_response;
                }
            }
            
            return $formatted_ticket_data;
            
        } catch (\Exception $e) {
            error_log('API Error in get_ticket_with_responses: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return new \WP_Error(
                'server_error',
                'An error occurred while fetching ticket: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }
}