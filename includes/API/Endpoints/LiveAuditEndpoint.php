<?php
/**
 * Live Audit API Endpoint
 *
 * Handles /queue-live-audit — called by N8N when FluentSupport fires a webhook
 * on agent response or ticket close. This decouples the audit plugin from
 * requiring FluentSupport on the same WordPress installation.
 *
 * @package SupportOps\API\Endpoints
 */

namespace SupportOps\API\Endpoints;

use SupportOps\Services\LiveAuditTrigger;

class LiveAuditEndpoint {

    private $trigger;

    public function __construct() {
        $this->trigger = new LiveAuditTrigger();
    }

    /**
     * POST /queue-live-audit
     *
     * Expected JSON body:
     * {
     *   "ticket_id": 123,
     *   "response_count": 5,
     *   "event_type": "agent_response"   // or "ticket_closed"
     * }
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function queue($request) {
        $data = $request->get_json_params();

        $ticket_id = isset($data['ticket_id']) ? intval($data['ticket_id']) : 0;
        if ($ticket_id <= 0) {
            return new \WP_Error('missing_ticket_id', 'ticket_id is required', ['status' => 400]);
        }

        $response_count = isset($data['response_count']) ? intval($data['response_count']) : 0;
        $event_type = isset($data['event_type']) ? sanitize_text_field($data['event_type']) : 'agent_response';

        if (!in_array($event_type, ['agent_response', 'ticket_closed'], true)) {
            $event_type = 'agent_response';
        }

        $result = $this->trigger->handle_event($ticket_id, $response_count, $event_type);

        return rest_ensure_response($result);
    }
}
