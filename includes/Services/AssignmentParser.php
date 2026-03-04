<?php
/**
 * Assignment Parser — Parse FluentSupport activity logs for ticket assignment history
 *
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

class AssignmentParser {

    /**
     * Parse assignment history for a ticket from FluentSupport internal_info conversations
     *
     * @param int $ticket_id
     * @return array Assignment events: [['agent_email'=>..., 'assigned_at'=>..., 'assigned_by_email'=>..., 'source'=>'initial|reassignment'], ...]
     */
    public function parse_assignment_history($ticket_id) {
        global $wpdb;

        $events = [];

        // Step 1: Get initial assignment from fs_tickets
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT t.agent_id, t.created_at, p.email, p.first_name, p.last_name
             FROM {$wpdb->prefix}fs_tickets t
             LEFT JOIN {$wpdb->prefix}fs_persons p ON t.agent_id = p.id
             WHERE t.id = %d",
            $ticket_id
        ));

        // We'll track the current agent as we parse events
        $initial_agent_email = $ticket && $ticket->email ? $ticket->email : null;

        // Step 2: Get all internal_info conversations mentioning "assigned"
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, content, created_at, person_id
             FROM {$wpdb->prefix}fs_conversations
             WHERE ticket_id = %d AND conversation_type = 'internal_info' AND content LIKE '%%assigned%%'
             ORDER BY created_at ASC",
            $ticket_id
        ));

        if (empty($logs) && $initial_agent_email) {
            // No reassignments — single agent owned the ticket
            $events[] = [
                'agent_email' => $initial_agent_email,
                'agent_name'  => $ticket ? trim($ticket->first_name . ' ' . $ticket->last_name) : '',
                'assigned_at' => $ticket->created_at,
                'assigned_by_email' => null,
                'source' => 'initial',
            ];
            return $events;
        }

        // Step 3: Parse each activity log
        // Format: "[Name] assigned [Name] in this ticket"
        foreach ($logs as $log) {
            $clean = strip_tags($log->content);

            // Pattern: "AgentA assigned AgentB in this ticket"
            if (preg_match('/^(.+?)\s+assigned\s+(.+?)\s+in this ticket/i', $clean, $m)) {
                $assigner_name = trim($m[1]);
                $assignee_name = trim($m[2]);

                $assigner_email = $this->resolve_agent_email($assigner_name);
                $assignee_email = $this->resolve_agent_email($assignee_name);

                if ($assignee_email) {
                    $events[] = [
                        'agent_email' => $assignee_email,
                        'agent_name'  => $assignee_name,
                        'assigned_at' => $log->created_at,
                        'assigned_by_email' => $assigner_email,
                        'source' => 'reassignment',
                    ];
                }
            }
        }

        // If we found reassignment events but no initial, prepend the first known agent
        if (!empty($events) && $events[0]['source'] === 'reassignment' && $initial_agent_email) {
            // The initial agent was whoever held it before the first reassignment
            // We need to figure out who that was — check if the first reassignment's assigner is the initial agent
            $first_assignee = $events[0]['agent_email'];
            if ($first_assignee !== $initial_agent_email) {
                // The initial agent is different from the first reassignment target
                array_unshift($events, [
                    'agent_email' => $initial_agent_email,
                    'agent_name'  => $ticket ? trim($ticket->first_name . ' ' . $ticket->last_name) : '',
                    'assigned_at' => $ticket->created_at,
                    'assigned_by_email' => null,
                    'source' => 'initial',
                ]);
            }
        }

        // If no events at all but we have initial agent
        if (empty($events) && $initial_agent_email) {
            $events[] = [
                'agent_email' => $initial_agent_email,
                'agent_name'  => $ticket ? trim($ticket->first_name . ' ' . $ticket->last_name) : '',
                'assigned_at' => $ticket->created_at,
                'assigned_by_email' => null,
                'source' => 'initial',
            ];
        }

        return $events;
    }

    /**
     * Build responsibility windows — who was responsible for the ticket when
     *
     * @param int $ticket_id
     * @return array Windows: [['agent_email'=>..., 'window_start'=>..., 'window_end'=>..., 'type'=>'assigned|orphan_gap'], ...]
     */
    public function build_responsibility_windows($ticket_id) {
        global $wpdb;

        $history = $this->parse_assignment_history($ticket_id);
        if (empty($history)) {
            return [];
        }

        // Get ticket close time
        $closed_at = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(resolved_at, updated_at) FROM {$wpdb->prefix}fs_tickets WHERE id = %d",
            $ticket_id
        ));
        if (!$closed_at) {
            $closed_at = current_time('mysql');
        }

        $windows = [];

        for ($i = 0; $i < count($history); $i++) {
            $event = $history[$i];
            $next = $history[$i + 1] ?? null;

            $window_start = $event['assigned_at'];
            $window_end = $next ? $next['assigned_at'] : $closed_at;

            $windows[] = [
                'agent_email'  => $event['agent_email'],
                'agent_name'   => $event['agent_name'],
                'window_start' => $window_start,
                'window_end'   => $window_end,
                'type'         => 'assigned',
            ];
        }

        return $windows;
    }

    /**
     * Resolve an agent name to email address
     */
    private function resolve_agent_email($name) {
        global $wpdb;

        if (empty($name)) return null;

        // If it's already an email
        if (filter_var($name, FILTER_VALIDATE_EMAIL)) {
            return $name;
        }

        // Try fs_persons (FluentSupport agents)
        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}fs_persons
             WHERE CONCAT(first_name, ' ', last_name) = %s AND person_type = 'agent'
             LIMIT 1",
            $name
        ));
        if ($email) return $email;

        // Try ais_agents (our plugin's agents)
        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}ais_agents
             WHERE CONCAT(first_name, ' ', last_name) = %s
             LIMIT 1",
            $name
        ));
        if ($email) return $email;

        // Try partial match (first name only)
        $parts = explode(' ', $name, 2);
        if (count($parts) >= 2) {
            $email = $wpdb->get_var($wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}fs_persons
                 WHERE first_name = %s AND last_name = %s AND person_type = 'agent'
                 LIMIT 1",
                $parts[0], $parts[1]
            ));
            if ($email) return $email;
        }

        return null;
    }
}
