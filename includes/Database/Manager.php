<?php
/**
 * Database Manager
 * 
 * @package SupportOps\Database
 */

namespace SupportOps\Database;

class Manager {
    
    /**
     * Table names
     */
    private $tables = [
        'audits',
        'agent_shifts',
        'agents',
        'shift_definitions',
        'topic_stats',
        'agent_contributions',
        'agent_evaluations',
        'problem_contexts',
        'doc_central_meta'
    ];
    
    /**
     * Setup database tables
     */
    public function setup() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // 1. Audit Results
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_audits (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ticket_id varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'pending' NOT NULL,
            overall_score int(3) DEFAULT 0,
            overall_sentiment varchar(20) DEFAULT NULL,
            error_message text DEFAULT NULL,
            raw_json longtext DEFAULT NULL,
            audit_response longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY status (status)
        ) $charset_collate;");
        
        // 2. Shifts
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_agent_shifts (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            agent_email varchar(100) NOT NULL,
            shift_def_id int(11) NOT NULL,
            shift_start datetime NOT NULL,
            shift_end datetime NOT NULL,
            shift_type varchar(50),
            shift_color varchar(20),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY agent_time (agent_email, shift_start)
        ) $charset_collate;");
        
        // 3. Agents
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_agents (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            first_name varchar(100),
            last_name varchar(100),
            email varchar(100) NOT NULL,
            title varchar(100) DEFAULT NULL,
            fluent_agent_id int(11) DEFAULT NULL,
            avatar_url varchar(500) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            last_synced datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY fluent_id (fluent_agent_id)
        ) $charset_collate;");
        
        // 4. Shift Definitions
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_shift_definitions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(50) NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            color varchar(20) DEFAULT '#e0f2fe',
            PRIMARY KEY (id)
        ) $charset_collate;");
        
        // 5. Topic Stats
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_topic_stats (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            topic_slug varchar(100) NOT NULL,
            topic_label varchar(200),
            category varchar(50),
            ticket_count int(11) DEFAULT 1,
            first_seen date,
            last_seen date,
            is_faq_candidate tinyint(1) DEFAULT 0,
            is_doc_update_needed tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY topic_slug (topic_slug),
            KEY ticket_count (ticket_count)
        ) $charset_collate;");
        
        // 6. Agent Contributions
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_agent_contributions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ticket_id varchar(50) NOT NULL,
            agent_email varchar(100) NOT NULL,
            contribution_percentage int(3),
            reply_count int(11),
            quality_score int(3),
            reasoning text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id),
            KEY agent_email (agent_email)
        ) $charset_collate;");
        
        // 7. Agent Evaluations
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_agent_evaluations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ticket_id varchar(50) NOT NULL,
            agent_email varchar(100) NOT NULL,
            agent_name varchar(200),
            timing_score int(4) DEFAULT 0,
            resolution_score int(4) DEFAULT 0,
            communication_score int(4) DEFAULT 0,
            overall_agent_score int(4) DEFAULT 0,
            contribution_percentage int(3) DEFAULT 0,
            reply_count int(11) DEFAULT 0,
            reasoning text,
            shift_compliance longtext,
            response_breakdown longtext,
            key_achievements longtext,
            areas_for_improvement longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id),
            KEY agent_email (agent_email),
            KEY created_at (created_at)
        ) $charset_collate;");
        
        // 8. Problem Contexts
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_problem_contexts (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ticket_id varchar(50) NOT NULL,
            problem_slug varchar(100),
            issue_description text,
            category varchar(50),
            severity varchar(20),
            responsible_agent varchar(100),
            agent_marking int(4),
            reasoning text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id),
            KEY problem_slug (problem_slug),
            KEY category (category)
        ) $charset_collate;");
        
        // 9. Doc Central Meta
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_doc_central_meta (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_name varchar(100),
            doc_url varchar(500),
            pinecone_namespace varchar(100),
            last_scraped datetime,
            chunk_count int(11),
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY doc_url (doc_url)
        ) $charset_collate;");
        
        // Seed shift definitions
        $this->seed_shift_definitions();
        
        // Generate security token
        if (!get_option('ai_audit_secret_token')) {
            update_option('ai_audit_secret_token', wp_generate_password(32, true, true));
        }
        
        // Set database version
        update_option('ai_audit_db_version', '10.0');
    }
    
    /**
     * Auto-repair database (migrations)
     */
    public function auto_repair() {
        global $wpdb;
        
        // Auto-migrate ais_audits table
        $this->migrate_audits_table();
        
        // Auto-migrate ais_agents table
        $this->migrate_agents_table();

        // Auto-migrate ais_agent_evaluations table
        $this->migrate_evaluations_table();
        
        // Check version and run setup if needed
        $current_version = get_option('ai_audit_db_version', '22.1');
        if (version_compare($current_version, '10.0', '<')) {
            $this->setup();
        }
    }
    
    /**
     * Migrate audits table
     */
    private function migrate_audits_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audits';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return;
        }
        
        // Add audit_response column
        $col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'audit_response'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN audit_response longtext DEFAULT NULL");
        }
        
        // Add raw_json column
        $col2 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'raw_json'");
        if (empty($col2)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN raw_json longtext DEFAULT NULL");
        }
        
        // Add status index
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'status'");
        if (empty($indexes)) {
            $wpdb->query("ALTER TABLE $table ADD INDEX status (status)");
        }
        
        // Add overall_sentiment column
        $col3 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'overall_sentiment'");
        if (empty($col3)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN overall_sentiment varchar(20) DEFAULT NULL AFTER overall_score");
        }

        // Remove UNIQUE constraint on ticket_id
        $unique_indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'ticket_id' AND Non_unique = 0");
        if (!empty($unique_indexes)) {
            $wpdb->query("ALTER TABLE $table DROP INDEX ticket_id");
            $wpdb->query("ALTER TABLE $table ADD INDEX ticket_id (ticket_id)");
        }
    }
    
    /**
     * Migrate agents table
     */
    private function migrate_agents_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_agents';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return;
        }
        
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $table");
        
        if (!in_array('title', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN title varchar(100) DEFAULT NULL AFTER email");
        }
        if (!in_array('fluent_agent_id', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN fluent_agent_id int(11) DEFAULT NULL AFTER title");
        }
        if (!in_array('avatar_url', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN avatar_url varchar(500) DEFAULT NULL AFTER fluent_agent_id");
        }
        if (!in_array('last_synced', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN last_synced datetime DEFAULT NULL AFTER is_active");
        }
        if (!in_array('created_at', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP AFTER last_synced");
        }
    }
    
    /**
     * Migrate evaluations table — drop dead columns
     */
    private function migrate_evaluations_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_agent_evaluations';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return;
        }

        // Drop dead overall_score column (real score is overall_agent_score)
        $col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'overall_score'");
        if (!empty($col)) {
            $wpdb->query("ALTER TABLE $table DROP COLUMN overall_score");
        }

        // Drop dead evaluation_data column
        $col2 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'evaluation_data'");
        if (!empty($col2)) {
            $wpdb->query("ALTER TABLE $table DROP COLUMN evaluation_data");
        }
    }

    /**
     * Seed shift definitions
     */
    private function seed_shift_definitions() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ais_shift_definitions';
        
        if ($wpdb->get_var("SELECT id FROM $table LIMIT 1")) {
            return; // Already seeded
        }
        
        $wpdb->insert($table, [
            'name' => 'Day Shift',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'color' => '#dcfce7'
        ]);
        
        $wpdb->insert($table, [
            'name' => 'Evening Shift',
            'start_time' => '15:00',
            'end_time' => '00:00',
            'color' => '#f1f5f9'
        ]);
        
        $wpdb->insert($table, [
            'name' => 'Deal Shift',
            'start_time' => '19:00',
            'end_time' => '04:00',
            'color' => '#fef2f2'
        ]);
    }
    
    /**
     * Get table name with prefix
     */
    public function get_table($name) {
        global $wpdb;
        return $wpdb->prefix . 'ais_' . $name;
    }
}