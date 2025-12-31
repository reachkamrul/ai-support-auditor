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
        
        foreach ($audit_data['problem_contexts'] as $context) {
            $slug = sanitize_title($context['issue_description'] ?? 'unknown');
            
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ais_topic_stats WHERE topic_slug = %s",
                $slug
            ));
            
            if ($existing) {
                $new_count = $existing->ticket_count + 1;
                $wpdb->update(
                    $wpdb->prefix . 'ais_topic_stats',
                    [
                        'ticket_count' => $new_count,
                        'last_seen' => current_time('mysql'),
                        'is_faq_candidate' => ($new_count >= 10) ? 1 : 0
                    ],
                    ['id' => $existing->id]
                );
            } else {
                $wpdb->insert($wpdb->prefix . 'ais_topic_stats', [
                    'topic_slug' => $slug,
                    'topic_label' => sanitize_text_field($context['issue_description'] ?? ''),
                    'category' => sanitize_text_field($context['category'] ?? ''),
                    'ticket_count' => 1,
                    'first_seen' => current_time('mysql'),
                    'last_seen' => current_time('mysql')
                ]);
            }
        }
    }
}