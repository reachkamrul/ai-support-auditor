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
use SupportOps\Admin\Pages\TimingSettings;

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
                            // --- Timing penalty settings ---
                            $timing_settings = TimingSettings::get_settings();
                            $timing_enabled = !empty($timing_settings['enabled']);
                            $timing_tag_excluded = false;

                            // Check tag exclusions if timing is enabled and exclusion tags are configured
                            if ($timing_enabled && !empty($timing_settings['excluded_tag_ids'])) {
                                $excluded_ids = array_map('intval', $timing_settings['excluded_tag_ids']);
                                $placeholders = implode(',', array_fill(0, count($excluded_ids), '%d'));
                                $tag_match = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->prefix}fs_tag_pivot
                                     WHERE source_type = 'ticket_tag'
                                     AND source_id = %d
                                     AND tag_id IN ($placeholders)",
                                    array_merge([$ticket_id], $excluded_ids)
                                ));
                                if (intval($tag_match) > 0) {
                                    $timing_tag_excluded = true;
                                }
                            }

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

                                if (!$timing_enabled) {
                                    // Master toggle OFF → no timing penalties
                                    $ev['timing_score'] = 0;
                                } elseif ($timing_tag_excluded) {
                                    // Ticket has an excluded tag → skip timing penalties
                                    $ev['timing_score'] = 0;
                                } elseif (!$shift_data_exists) {
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

                            // Store timing override notes in audit JSON for tracking
                            if (!$timing_enabled) {
                                $audit['audit_summary']['timing_override'] = 'disabled';
                            } elseif ($timing_tag_excluded) {
                                $audit['audit_summary']['timing_override'] = 'tag_excluded';
                            }
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

                        // Extract exclude_from_stats from AI response
                        $exclude = !empty($audit['audit_summary']['exclude_from_stats']) ? 1 : 0;
                        $exclude_reason = sanitize_text_field($audit['audit_summary']['exclude_reason'] ?? '');
                        $update_data['exclude_from_stats'] = $exclude;
                        $update_data['exclude_reason'] = $exclude_reason;

                        // Detect audit type from pending record
                        $pending_record = $wpdb->get_row($wpdb->prepare(
                            "SELECT audit_type, last_response_count FROM {$wpdb->prefix}ais_audits
                             WHERE ticket_id = %d AND status = 'pending'
                             ORDER BY id DESC LIMIT 1",
                            $ticket_id
                        ));
                        $audit_type = $pending_record->audit_type ?? 'full';

                        // For incremental audits, merge with existing data
                        if ($audit_type === 'incremental') {
                            $audit = $this->merge_incremental_audit($wpdb, $ticket_id, $audit);
                            $update_data['audit_response'] = json_encode($audit, JSON_UNESCAPED_UNICODE);
                        }

                        // Recalculate overall score after merge
                        if ($audit_type === 'incremental' && !empty($audit['agent_evaluations'])) {
                            $total_agent_score = 0;
                            $agent_count = count($audit['agent_evaluations']);
                            foreach ($audit['agent_evaluations'] as $ev) {
                                $total_agent_score += ($ev['timing_score'] ?? 0) + ($ev['resolution_score'] ?? 0) + ($ev['communication_score'] ?? 0);
                            }
                            $ai_score = $agent_count > 0 ? round($total_agent_score / $agent_count) : 0;
                            $ai_score = max(-200, min(100, $ai_score));
                            $update_data['overall_score'] = $ai_score;
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

                        // Auto-flag tickets needing attention
                        $this->auto_flag_ticket($wpdb, $ticket_id, $ai_score, $audit);

                        // Handoff compliance scoring
                        try {
                            $assignment_parser = new \SupportOps\Services\AssignmentParser();
                            $handoff_scorer = new \SupportOps\Services\HandoffScorer();
                            $assignment_history = $assignment_parser->parse_assignment_history($ticket_id);

                            if (count($assignment_history) > 1) {
                                $handoff_scores = $handoff_scorer->score($ticket_id, $assignment_history);

                                // Inject handoff_score into evaluations for EvaluationSaver
                                foreach ($audit['agent_evaluations'] as &$ev) {
                                    $email = $ev['agent_email'] ?? '';
                                    if (isset($handoff_scores[$email])) {
                                        $ev['handoff_score'] = $handoff_scores[$email]['handoff_score'];
                                    }
                                }
                                unset($ev);

                                // Store assignment history in audit JSON
                                $audit['assignment_history'] = $assignment_history;
                                $update_data['audit_response'] = json_encode($audit, JSON_UNESCAPED_UNICODE);

                                // Update handoff_score in already-saved evaluations
                                foreach ($handoff_scores as $email => $score_data) {
                                    if ($score_data['handoff_score'] !== null) {
                                        $wpdb->update(
                                            $wpdb->prefix . 'ais_agent_evaluations',
                                            ['handoff_score' => $score_data['handoff_score']],
                                            ['ticket_id' => $ticket_id, 'agent_email' => $email]
                                        );
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            error_log('Handoff scoring failed for ticket ' . $ticket_id . ': ' . $e->getMessage());
                        }
                    }
                } catch (\Exception $e) {
                    error_log('API Error: Failed to process audit_response - ' . $e->getMessage());
                }
            }
            
            // Update latest pending/processing audit
            $pending_audit = $wpdb->get_row($wpdb->prepare(
                "SELECT id, processing_started_at FROM {$wpdb->prefix}ais_audits
                 WHERE ticket_id = %d AND status IN ('pending', 'processing')
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

                // Track processing duration
                if (!empty($pending_audit->processing_started_at)) {
                    $duration = time() - strtotime($pending_audit->processing_started_at);
                    $wpdb->update(
                        $wpdb->prefix . 'ais_audits',
                        ['processing_duration_seconds' => max(0, $duration)],
                        ['id' => $pending_audit->id]
                    );
                }
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
        $table = $wpdb->prefix . 'ais_audits';

        $limit = intval($request->get_param('limit') ?: 1);

        // Stale recovery: reset processing audits older than 10 minutes
        $wpdb->query(
            "UPDATE {$table}
             SET status = 'pending', processing_started_at = NULL
             WHERE status = 'processing'
             AND processing_started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.ticket_id, a.status, a.created_at, a.audit_type, a.audit_version, a.last_response_count
             FROM {$table} a
             INNER JOIN (
                 SELECT ticket_id, MAX(id) as max_id
                 FROM {$table}
                 WHERE status = 'pending'
                    OR (status = 'failed' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
                 GROUP BY ticket_id
             ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
             ORDER BY
                CASE
                    WHEN a.status = 'pending' AND a.audit_type = 'final' THEN 0
                    WHEN a.status = 'pending' AND a.audit_type = 'incremental' THEN 1
                    WHEN a.status = 'pending' AND a.audit_type = 'full' THEN 2
                    WHEN a.status = 'failed' THEN 3
                    ELSE 4
                END,
                a.created_at ASC
             LIMIT %d",
            $limit
        ));

        // Atomically mark selected rows as processing
        if (!empty($results)) {
            $ids = array_map(function($r) { return intval($r->id); }, $results);
            $id_list = implode(',', $ids);
            $wpdb->query(
                "UPDATE {$table}
                 SET status = 'processing', processing_started_at = NOW()
                 WHERE id IN ({$id_list}) AND status IN ('pending', 'failed')"
            );
        }

        $pending = [];
        foreach ($results as $r) {
            $item = [
                'ticket_id'  => $r->ticket_id,
                'audit_id'   => $r->id,
                'retry'      => ($r->status === 'failed'),
                'audit_type' => $r->audit_type ?: 'full',
            ];

            // For incremental audits, include previous audit context
            if ($r->audit_type === 'incremental') {
                $prev = $this->get_previous_audit_context($wpdb, $r->ticket_id);
                if ($prev) {
                    $item['last_response_count'] = intval($prev['audited_response_count']);
                    $item['previous_context'] = $prev;
                } else {
                    // No previous audit found — upgrade to full audit
                    $item['audit_type'] = 'full';
                }
            }

            $pending[] = $item;
        }

        return $pending;
    }

    /**
     * Build compact previous audit context for incremental audits
     */
    private function get_previous_audit_context($wpdb, $ticket_id) {
        // Get latest successful audit
        $prev_audit = $wpdb->get_row($wpdb->prepare(
            "SELECT id, overall_score, overall_sentiment, audit_response, last_response_count
             FROM {$wpdb->prefix}ais_audits
             WHERE ticket_id = %s AND status = 'success'
             ORDER BY id DESC LIMIT 1",
            $ticket_id
        ));

        if (!$prev_audit || empty($prev_audit->audit_response)) {
            return null;
        }

        $audit = json_decode($prev_audit->audit_response, true);
        if (!$audit) {
            return null;
        }

        // Build compact summary
        $agent_scores = [];
        if (!empty($audit['agent_evaluations'])) {
            foreach ($audit['agent_evaluations'] as $ev) {
                $email = $ev['agent_email'] ?? '';
                if ($email) {
                    $agent_scores[$email] = [
                        'timing' => $ev['timing_score'] ?? 0,
                        'resolution' => $ev['resolution_score'] ?? 0,
                        'communication' => $ev['communication_score'] ?? 0,
                    ];
                }
            }
        }

        $problems = [];
        if (!empty($audit['problem_contexts'])) {
            foreach ($audit['problem_contexts'] as $pc) {
                $problems[] = ($pc['issue_description'] ?? '') . ' (' . ($pc['severity'] ?? '') . ')';
            }
        }

        return [
            'overall_score'          => intval($prev_audit->overall_score),
            'overall_sentiment'      => $prev_audit->overall_sentiment ?: 'Mixed',
            'executive_summary'      => $audit['audit_summary']['executive_summary'] ?? '',
            'agent_scores'           => $agent_scores,
            'problems_found'         => $problems,
            'audited_response_count' => intval($prev_audit->last_response_count),
        ];
    }
    
    /**
     * Get queue statistics for the admin queue page
     */
    public function get_queue_stats($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';

        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"
        );

        $processing = $wpdb->get_row(
            "SELECT ticket_id, audit_type, processing_started_at
             FROM {$table} WHERE status = 'processing'
             ORDER BY processing_started_at ASC LIMIT 1"
        );

        $avg_duration = (float) $wpdb->get_var(
            "SELECT AVG(processing_duration_seconds) FROM {$table}
             WHERE processing_duration_seconds IS NOT NULL
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        $completed_today = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'success' AND DATE(created_at) = CURDATE()"
        );

        $failed_last_hour = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );

        return [
            'pending_count'          => $pending_count,
            'processing'             => $processing ? [
                'ticket_id'       => $processing->ticket_id,
                'audit_type'      => $processing->audit_type,
                'started_at'      => $processing->processing_started_at,
                'elapsed_seconds' => time() - strtotime($processing->processing_started_at),
            ] : null,
            'avg_processing_seconds' => round($avg_duration),
            'estimated_wait_minutes' => $pending_count > 0 && $avg_duration > 0
                ? round(($pending_count * $avg_duration) / 60, 1) : 0,
            'completed_today'        => $completed_today,
            'failed_last_hour'       => $failed_last_hour,
        ];
    }

    /**
     * Merge incremental audit data with existing successful audit
     *
     * Agent evaluations: weighted merge (30% old + 70% new) for existing agents, add new agents
     * Problem contexts: keep existing + append new (deduplicate by issue_description)
     * Contributions: replace with new values
     * Summary: use new audit's summary
     */
    private function merge_incremental_audit($wpdb, $ticket_id, $new_audit) {
        $prev_audit = $wpdb->get_row($wpdb->prepare(
            "SELECT audit_response FROM {$wpdb->prefix}ais_audits
             WHERE ticket_id = %d AND status = 'success'
             ORDER BY id DESC LIMIT 1",
            $ticket_id
        ));

        if (!$prev_audit || empty($prev_audit->audit_response)) {
            return $new_audit;
        }

        $old = json_decode($prev_audit->audit_response, true);
        if (!$old) {
            return $new_audit;
        }

        // Merge agent evaluations
        if (!empty($new_audit['agent_evaluations'])) {
            $old_evals = [];
            foreach (($old['agent_evaluations'] ?? []) as $ev) {
                $old_evals[$ev['agent_email'] ?? ''] = $ev;
            }

            $merged_evals = [];
            $seen_emails = [];

            foreach ($new_audit['agent_evaluations'] as $new_ev) {
                $email = $new_ev['agent_email'] ?? '';
                $seen_emails[$email] = true;

                if (isset($old_evals[$email])) {
                    // Weighted merge: 30% old + 70% new
                    $old_ev = $old_evals[$email];
                    $new_ev['timing_score'] = round(0.3 * ($old_ev['timing_score'] ?? 0) + 0.7 * ($new_ev['timing_score'] ?? 0));
                    $new_ev['resolution_score'] = round(0.3 * ($old_ev['resolution_score'] ?? 0) + 0.7 * ($new_ev['resolution_score'] ?? 0));
                    $new_ev['communication_score'] = round(0.3 * ($old_ev['communication_score'] ?? 0) + 0.7 * ($new_ev['communication_score'] ?? 0));
                    $new_ev['overall_agent_score'] = $new_ev['timing_score'] + $new_ev['resolution_score'] + $new_ev['communication_score'];

                    // Merge reply counts
                    $new_ev['reply_count'] = max($new_ev['reply_count'] ?? 0, $old_ev['reply_count'] ?? 0);

                    // Merge achievements and improvements (deduplicate)
                    $old_achievements = $old_ev['key_achievements'] ?? [];
                    $new_achievements = $new_ev['key_achievements'] ?? [];
                    $new_ev['key_achievements'] = array_values(array_unique(array_merge($old_achievements, $new_achievements)));

                    $old_improvements = $old_ev['areas_for_improvement'] ?? [];
                    $new_improvements = $new_ev['areas_for_improvement'] ?? [];
                    $new_ev['areas_for_improvement'] = array_values(array_unique(array_merge($old_improvements, $new_improvements)));

                    // Merge response breakdowns
                    $old_breakdown = $old_ev['response_breakdown'] ?? [];
                    $new_breakdown = $new_ev['response_breakdown'] ?? [];
                    $new_ev['response_breakdown'] = array_merge($old_breakdown, $new_breakdown);
                }

                $merged_evals[] = $new_ev;
            }

            // Add old agents not in new audit (they still contributed)
            foreach ($old_evals as $email => $old_ev) {
                if (!isset($seen_emails[$email])) {
                    $merged_evals[] = $old_ev;
                }
            }

            $new_audit['agent_evaluations'] = $merged_evals;
        }

        // Merge problem contexts (deduplicate by issue_description)
        if (!empty($old['problem_contexts'])) {
            $existing_issues = [];
            foreach (($new_audit['problem_contexts'] ?? []) as $pc) {
                $existing_issues[strtolower($pc['issue_description'] ?? '')] = true;
            }

            foreach ($old['problem_contexts'] as $old_pc) {
                $key = strtolower($old_pc['issue_description'] ?? '');
                if (!isset($existing_issues[$key])) {
                    $new_audit['problem_contexts'][] = $old_pc;
                }
            }
        }

        return $new_audit;
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
     * Calculate timing penalty from delay duration using configurable rules.
     * Reads rules from TimingSettings; falls back to defaults if not configured.
     */
    private function calculate_timing_penalty($delay_hours) {
        $settings = TimingSettings::get_settings();
        $rules = $settings['delay_rules'];
        // Rules are sorted by hours ascending
        usort($rules, function($a, $b) { return $a['hours'] - $b['hours']; });
        foreach ($rules as $rule) {
            if ($delay_hours <= $rule['hours']) {
                return intval($rule['penalty']);
            }
        }
        return intval($settings['default_penalty']);
    }

    /**
     * Auto-flag tickets that need manager attention.
     */
    private function auto_flag_ticket($wpdb, $ticket_id, $score, $audit) {
        $table = $wpdb->prefix . 'ais_flagged_tickets';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        // Get audit_id for linking
        $audit_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ais_audits WHERE ticket_id = %d ORDER BY id DESC LIMIT 1",
            $ticket_id
        ));

        // Remove old flags for this ticket (re-audit = fresh flags)
        $wpdb->delete($table, ['ticket_id' => $ticket_id]);

        // Defense in depth: Skip if no agent evaluations (0 agent replies)
        $agent_evals = $audit['agent_evaluations'] ?? [];
        if (empty($agent_evals)) {
            return;
        }

        // PRIMARY: AI-recommended flag — the AI is the judge
        if (!empty($audit['flag_recommendation'])) {
            $flag_rec = $audit['flag_recommendation'];
            if (($flag_rec['should_flag'] ?? false) === true) {
                $wpdb->insert($table, [
                    'ticket_id'    => $ticket_id,
                    'audit_id'     => $audit_id,
                    'flag_type'    => 'ai_recommended',
                    'flag_details' => json_encode([
                        'reason'   => substr($flag_rec['reason'] ?? '', 0, 500),
                        'severity' => $flag_rec['severity'] ?? 'Medium',
                        'category' => $flag_rec['category'] ?? 'general',
                    ]),
                    'created_at'   => current_time('mysql'),
                ]);
            }
        }

        // FALLBACK: Flag Critical problem_contexts even if AI didn't explicitly flag
        // (defense in depth — Critical issues should never be missed)
        foreach ($audit['problem_contexts'] ?? [] as $pc) {
            if (($pc['severity'] ?? '') === 'Critical') {
                // Check if AI already flagged this ticket
                $already_flagged = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d",
                    $ticket_id
                ));
                if (!$already_flagged) {
                    $wpdb->insert($table, [
                        'ticket_id'    => $ticket_id,
                        'audit_id'     => $audit_id,
                        'flag_type'    => 'problem_context',
                        'flag_details' => json_encode([
                            'severity'    => 'Critical',
                            'category'    => $pc['category'] ?? '',
                            'description' => substr($pc['issue_description'] ?? '', 0, 200),
                        ]),
                        'created_at'   => current_time('mysql'),
                    ]);
                }
                break;
            }
        }
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