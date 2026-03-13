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
        'doc_central_meta',
        'flagged_tickets',
        'teams',
        'team_members',
        'team_products',
        'handoff_events',
        'holidays',
        'holiday_duty',
        'agent_leaves',
        'calendar_extras',
        'audit_reviews',
        'score_overrides',
        'audit_appeals'
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
            last_response_count int DEFAULT 0,
            audit_version int DEFAULT 1,
            audit_type varchar(20) DEFAULT 'full',
            processing_started_at datetime DEFAULT NULL,
            processing_duration_seconds int DEFAULT NULL,
            exclude_from_stats tinyint(1) DEFAULT 0,
            exclude_reason varchar(100) DEFAULT NULL,
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
            role varchar(20) DEFAULT 'agent',
            wp_user_id bigint(20) DEFAULT NULL,
            can_override tinyint(1) DEFAULT 0,
            fluent_agent_id int(11) DEFAULT NULL,
            avatar_url varchar(500) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            last_synced datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY fluent_id (fluent_agent_id),
            KEY wp_user (wp_user_id)
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
            handoff_score int(4) DEFAULT NULL,
            overall_agent_score int(4) DEFAULT 0,
            contribution_percentage int(3) DEFAULT 0,
            reply_count int(11) DEFAULT 0,
            reasoning text,
            shift_compliance longtext,
            response_breakdown longtext,
            key_achievements longtext,
            areas_for_improvement longtext,
            exclude_from_stats tinyint(1) DEFAULT 0,
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
        
        // 9. Doc Central Meta (Knowledge Base)
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_doc_central_meta (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_name varchar(100),
            doc_url varchar(500),
            doc_title varchar(255) DEFAULT NULL,
            category varchar(100) DEFAULT NULL,
            tags text DEFAULT NULL,
            added_by varchar(255) DEFAULT NULL,
            pinecone_namespace varchar(100),
            last_scraped datetime,
            chunk_count int(11),
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY doc_url (doc_url),
            KEY product_name (product_name),
            KEY category (category)
        ) $charset_collate;");
        
        // 10. Flagged Tickets
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_flagged_tickets (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ticket_id varchar(50) NOT NULL,
            audit_id bigint(20) DEFAULT NULL,
            flag_type varchar(30) NOT NULL,
            flag_details text DEFAULT NULL,
            status varchar(20) DEFAULT 'needs_review' NOT NULL,
            reviewer_notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            reviewed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY status (status),
            KEY flag_type (flag_type)
        ) $charset_collate;");

        // 11. Teams
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_teams (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            color varchar(20) DEFAULT '#3b82f6',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;");

        // 12. Team Members
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_team_members (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            team_id bigint(20) NOT NULL,
            agent_email varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY team_agent (team_id, agent_email)
        ) $charset_collate;");

        // 13. Team Products
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_team_products (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            team_id bigint(20) NOT NULL,
            product_id int(11) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY team_product (team_id, product_id)
        ) $charset_collate;");

        // 14. Handoff Events
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_handoff_events (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ticket_id varchar(50) NOT NULL,
            agent_email varchar(100) NOT NULL,
            shift_end datetime DEFAULT NULL,
            reassigned_at datetime DEFAULT NULL,
            handoff_score int(4) DEFAULT 0,
            gap_hours decimal(6,2) DEFAULT 0,
            reason text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY agent_email (agent_email),
            KEY created_at (created_at)
        ) $charset_collate;");

        // 15. Holidays
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_holidays (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            date_start date NOT NULL,
            date_end date NOT NULL,
            type varchar(20) DEFAULT 'government',
            year int(4) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY year (year),
            KEY date_start (date_start)
        ) $charset_collate;");

        // 16. Holiday Duty
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_holiday_duty (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            holiday_id bigint(20) NOT NULL,
            date date NOT NULL,
            agent_email varchar(100) NOT NULL,
            shift_type varchar(50) NOT NULL,
            comp_off_date date DEFAULT NULL,
            comp_off_status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY holiday_id (holiday_id),
            KEY agent_email (agent_email),
            KEY date (date)
        ) $charset_collate;");

        // 17. Agent Leaves
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_agent_leaves (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            agent_email varchar(100) NOT NULL,
            date_start date NOT NULL,
            date_end date NOT NULL,
            leave_type varchar(30) NOT NULL,
            reason text DEFAULT NULL,
            status varchar(20) DEFAULT 'approved',
            created_by varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY agent_email (agent_email),
            KEY date_start (date_start),
            KEY status (status)
        ) $charset_collate;");

        // 18. Calendar Extras
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_calendar_extras (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            date date NOT NULL,
            agent_email varchar(100) NOT NULL,
            shift_type varchar(50) NOT NULL,
            action_type varchar(20) DEFAULT 'add',
            note text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY date (date),
            KEY agent_email (agent_email)
        ) $charset_collate;");

        // 19. Audit Reviews
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_audit_reviews (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            audit_id bigint(20) NOT NULL,
            ticket_id varchar(50) NOT NULL,
            reviewer_email varchar(255) NOT NULL,
            review_status varchar(20) NOT NULL,
            summary_agree tinyint(1) DEFAULT 1,
            evaluations_review longtext DEFAULT NULL,
            problems_review longtext DEFAULT NULL,
            general_notes text DEFAULT NULL,
            reviewed_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY audit_id (audit_id),
            KEY ticket_id (ticket_id),
            KEY reviewer_email (reviewer_email)
        ) $charset_collate;");

        // 20. Score Overrides
        dbDelta("CREATE TABLE {$wpdb->prefix}ais_score_overrides (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            audit_id bigint(20) NOT NULL,
            ticket_id varchar(50) NOT NULL,
            agent_email varchar(100) NOT NULL,
            field_name varchar(50) NOT NULL,
            old_value int(4) NOT NULL,
            new_value int(4) NOT NULL,
            override_by varchar(255) NOT NULL,
            reason text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY audit_id (audit_id),
            KEY ticket_id (ticket_id),
            KEY agent_email (agent_email)
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

        // Auto-migrate ais_doc_central_meta table
        $this->migrate_doc_central_meta();

        // Auto-migrate ais_audit_reviews table
        $this->migrate_audit_reviews_table();

        // Auto-create new tables if missing (flagged_tickets, teams, etc.)
        $this->ensure_new_tables();
        
        // Run setup if core tables are missing (fresh install) or version mismatch
        $core_table = $wpdb->prefix . 'ais_agents';
        $core_exists = $wpdb->get_var("SHOW TABLES LIKE '{$core_table}'") === $core_table;
        if (!$core_exists) {
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

        // Add live audit columns
        $col_lrc = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'last_response_count'");
        if (empty($col_lrc)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN last_response_count int DEFAULT 0");
        }
        $col_av = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'audit_version'");
        if (empty($col_av)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN audit_version int DEFAULT 1");
        }
        $col_at = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'audit_type'");
        if (empty($col_at)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN audit_type varchar(20) DEFAULT 'full'");
        }

        // Add exclude_from_stats columns
        $col_efs = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'exclude_from_stats'");
        if (empty($col_efs)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN exclude_from_stats tinyint(1) DEFAULT 0");
        }
        $col_er = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'exclude_reason'");
        if (empty($col_er)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN exclude_reason varchar(100) DEFAULT NULL");
        }

        // Add queue management columns
        $col_psa = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'processing_started_at'");
        if (empty($col_psa)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN processing_started_at datetime DEFAULT NULL");
        }
        $col_pds = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'processing_duration_seconds'");
        if (empty($col_pds)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN processing_duration_seconds int DEFAULT NULL");
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
        if (!in_array('role', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN role varchar(20) DEFAULT 'agent' AFTER title");
        }
        if (!in_array('wp_user_id', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN wp_user_id bigint(20) DEFAULT NULL AFTER role");
        }
        if (!in_array('last_synced', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN last_synced datetime DEFAULT NULL AFTER is_active");
        }
        if (!in_array('created_at', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP AFTER last_synced");
        }
        if (!in_array('can_override', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN can_override tinyint(1) DEFAULT 0 AFTER wp_user_id");
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

        // Add handoff_score column
        $col3 = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'handoff_score'");
        if (empty($col3)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN handoff_score int(4) DEFAULT NULL AFTER communication_score");
        }

        // Add exclude_from_stats column
        $col_efs = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'exclude_from_stats'");
        if (empty($col_efs)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN exclude_from_stats tinyint(1) DEFAULT 0");
        }
    }

    /**
     * Migrate doc_central_meta table — add KB columns
     */
    private function migrate_doc_central_meta() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_doc_central_meta';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return;
        }

        $cols = $wpdb->get_col("SHOW COLUMNS FROM $table");

        if (!in_array('doc_title', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN doc_title varchar(255) DEFAULT NULL AFTER doc_url");
        }
        if (!in_array('category', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN category varchar(100) DEFAULT NULL AFTER doc_title");
        }
        if (!in_array('tags', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN tags text DEFAULT NULL AFTER category");
        }
        if (!in_array('added_by', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN added_by varchar(255) DEFAULT NULL AFTER tags");
        }
        if (!in_array('created_at', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP");
        }
    }

    /**
     * Migrate audit_reviews table — add notes columns
     */
    private function migrate_audit_reviews_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ais_audit_reviews';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return;
        }

        $cols = $wpdb->get_col("SHOW COLUMNS FROM $table");

        if (!in_array('evaluations_notes', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN evaluations_notes text DEFAULT NULL AFTER evaluations_review");
        }
        if (!in_array('problems_notes', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN problems_notes text DEFAULT NULL AFTER problems_review");
        }
    }

    /**
     * Ensure new tables exist (for upgrades from older versions)
     */
    private function ensure_new_tables() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $wpdb->get_charset_collate();

        $new_tables = [
            'ais_flagged_tickets' => "CREATE TABLE {$wpdb->prefix}ais_flagged_tickets (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                ticket_id varchar(50) NOT NULL,
                audit_id bigint(20) DEFAULT NULL,
                flag_type varchar(30) NOT NULL,
                flag_details text DEFAULT NULL,
                status varchar(20) DEFAULT 'needs_review' NOT NULL,
                reviewer_notes text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                reviewed_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY ticket_id (ticket_id),
                KEY status (status),
                KEY flag_type (flag_type)
            ) $charset_collate;",
            'ais_teams' => "CREATE TABLE {$wpdb->prefix}ais_teams (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                color varchar(20) DEFAULT '#3b82f6',
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;",
            'ais_team_members' => "CREATE TABLE {$wpdb->prefix}ais_team_members (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                team_id bigint(20) NOT NULL,
                agent_email varchar(100) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY team_agent (team_id, agent_email)
            ) $charset_collate;",
            'ais_team_products' => "CREATE TABLE {$wpdb->prefix}ais_team_products (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                team_id bigint(20) NOT NULL,
                product_id int(11) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY team_product (team_id, product_id)
            ) $charset_collate;",
            'ais_handoff_events' => "CREATE TABLE {$wpdb->prefix}ais_handoff_events (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                ticket_id varchar(50) NOT NULL,
                agent_email varchar(100) NOT NULL,
                shift_end datetime DEFAULT NULL,
                reassigned_at datetime DEFAULT NULL,
                handoff_score int(4) DEFAULT 0,
                gap_hours decimal(6,2) DEFAULT 0,
                reason text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY ticket_id (ticket_id),
                KEY agent_email (agent_email),
                KEY created_at (created_at)
            ) $charset_collate;",
            'ais_holidays' => "CREATE TABLE {$wpdb->prefix}ais_holidays (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                date_start date NOT NULL,
                date_end date NOT NULL,
                type varchar(20) DEFAULT 'government',
                year int(4) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY year (year),
                KEY date_start (date_start)
            ) $charset_collate;",
            'ais_holiday_duty' => "CREATE TABLE {$wpdb->prefix}ais_holiday_duty (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                holiday_id bigint(20) NOT NULL,
                date date NOT NULL,
                agent_email varchar(100) NOT NULL,
                shift_type varchar(50) NOT NULL,
                comp_off_date date DEFAULT NULL,
                comp_off_status varchar(20) DEFAULT 'pending',
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY holiday_id (holiday_id),
                KEY agent_email (agent_email),
                KEY date (date)
            ) $charset_collate;",
            'ais_agent_leaves' => "CREATE TABLE {$wpdb->prefix}ais_agent_leaves (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                agent_email varchar(100) NOT NULL,
                date_start date NOT NULL,
                date_end date NOT NULL,
                leave_type varchar(30) NOT NULL,
                reason text DEFAULT NULL,
                status varchar(20) DEFAULT 'approved',
                created_by varchar(255) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY agent_email (agent_email),
                KEY date_start (date_start),
                KEY status (status)
            ) $charset_collate;",
            'ais_calendar_extras' => "CREATE TABLE {$wpdb->prefix}ais_calendar_extras (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                date date NOT NULL,
                agent_email varchar(100) NOT NULL,
                shift_type varchar(50) NOT NULL,
                action_type varchar(20) DEFAULT 'add',
                note text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY date (date),
                KEY agent_email (agent_email)
            ) $charset_collate;",
            'ais_audit_reviews' => "CREATE TABLE {$wpdb->prefix}ais_audit_reviews (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                audit_id bigint(20) NOT NULL,
                ticket_id varchar(50) NOT NULL,
                reviewer_email varchar(255) NOT NULL,
                review_status varchar(20) NOT NULL,
                summary_agree tinyint(1) DEFAULT 1,
                evaluations_review longtext DEFAULT NULL,
                problems_review longtext DEFAULT NULL,
                general_notes text DEFAULT NULL,
                reviewed_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY audit_id (audit_id),
                KEY ticket_id (ticket_id),
                KEY reviewer_email (reviewer_email)
            ) $charset_collate;",
            'ais_score_overrides' => "CREATE TABLE {$wpdb->prefix}ais_score_overrides (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                audit_id bigint(20) NOT NULL,
                ticket_id varchar(50) NOT NULL,
                agent_email varchar(100) NOT NULL,
                field_name varchar(50) NOT NULL,
                old_value int(4) NOT NULL,
                new_value int(4) NOT NULL,
                override_by varchar(255) NOT NULL,
                reason text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY audit_id (audit_id),
                KEY ticket_id (ticket_id),
                KEY agent_email (agent_email)
            ) $charset_collate;",
            'ais_audit_appeals' => "CREATE TABLE {$wpdb->prefix}ais_audit_appeals (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                ticket_id varchar(50) NOT NULL,
                eval_id bigint(20) NOT NULL,
                agent_email varchar(100) NOT NULL,
                appeal_type varchar(30) DEFAULT 'score_dispute' NOT NULL,
                disputed_field varchar(50) DEFAULT NULL,
                current_score int(4) DEFAULT NULL,
                reason text NOT NULL,
                status varchar(20) DEFAULT 'pending' NOT NULL,
                resolved_by varchar(255) DEFAULT NULL,
                resolution_notes text DEFAULT NULL,
                resolved_at datetime DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY ticket_id (ticket_id),
                KEY agent_email (agent_email),
                KEY status (status)
            ) $charset_collate;",
            'ais_override_requests' => "CREATE TABLE {$wpdb->prefix}ais_override_requests (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                audit_id bigint(20) NOT NULL,
                ticket_id varchar(50) NOT NULL,
                agent_email varchar(100) NOT NULL,
                field_name varchar(50) NOT NULL,
                current_value int(4) NOT NULL,
                suggested_value int(4) NOT NULL,
                requested_by varchar(255) NOT NULL,
                request_notes text DEFAULT NULL,
                status varchar(20) DEFAULT 'pending' NOT NULL,
                resolved_by varchar(255) DEFAULT NULL,
                resolution_notes text DEFAULT NULL,
                resolved_at datetime DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY audit_id (audit_id),
                KEY agent_email (agent_email),
                KEY status (status)
            ) $charset_collate;",
        ];

        foreach ($new_tables as $name => $sql) {
            $full_name = $wpdb->prefix . $name;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$full_name}'") !== $full_name) {
                dbDelta($sql);
            }
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