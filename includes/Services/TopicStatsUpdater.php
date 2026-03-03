<?php
/**
 * Topic Stats Updater Service
 * 
 * @package SupportOps\Services
 */

namespace SupportOps\Services;

use SupportOps\Database\Manager as DatabaseManager;

class TopicStatsUpdater {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function update($audit_data) {
        global $wpdb;

        if (empty($audit_data['problem_contexts'])) {
            return;
        }

        // Read AI's strategic assessment
        $ai_faq = false;
        $ai_doc_update = false;
        if (!empty($audit_data['ops_strategic']) && is_array($audit_data['ops_strategic'])) {
            $ai_faq = !empty($audit_data['ops_strategic']['faq_candidate']);
            $ai_doc_update = !empty($audit_data['ops_strategic']['doc_update_required']);
        }

        foreach ($audit_data['problem_contexts'] as $context) {
            if (empty($context['issue_description'])) {
                continue;
            }

            $slug = sanitize_title($context['issue_description']);

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ais_topic_stats WHERE topic_slug = %s",
                $slug
            ));

            if ($existing) {
                $new_count = $existing->ticket_count + 1;
                $is_faq = ($new_count >= 10 || $ai_faq) ? 1 : 0;
                $is_doc = ($ai_doc_update || $existing->is_doc_update_needed) ? 1 : 0;

                $wpdb->update(
                    $wpdb->prefix . 'ais_topic_stats',
                    [
                        'ticket_count' => $new_count,
                        'last_seen' => current_time('mysql'),
                        'category' => sanitize_text_field($context['category'] ?? $existing->category),
                        'is_faq_candidate' => $is_faq,
                        'is_doc_update_needed' => $is_doc
                    ],
                    ['id' => $existing->id]
                );
            } else {
                $wpdb->insert($wpdb->prefix . 'ais_topic_stats', [
                    'topic_slug' => $slug,
                    'topic_label' => sanitize_text_field($context['issue_description']),
                    'category' => sanitize_text_field($context['category'] ?? ''),
                    'ticket_count' => 1,
                    'first_seen' => current_time('mysql'),
                    'last_seen' => current_time('mysql'),
                    'is_faq_candidate' => $ai_faq ? 1 : 0,
                    'is_doc_update_needed' => $ai_doc_update ? 1 : 0
                ]);
            }
        }
    }
}