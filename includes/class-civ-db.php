<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CIV_DB
{
    const SCHEMA_VERSION = '2';

    public static function table($name)
    {
        global $wpdb;

        $allowed = array('surveys', 'ideas', 'matchups', 'historical_results', 'audit_log');
        if (!in_array($name, $allowed, true)) {
            return '';
        }

        return $wpdb->prefix . 'civ_' . $name;
    }

    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $surveys = self::table('surveys');
        $ideas = self::table('ideas');
        $matchups = self::table('matchups');
        $history = self::table('historical_results');
        $audit = self::table('audit_log');

        $queries = array(
            "CREATE TABLE {$surveys} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                title varchar(190) NOT NULL,
                question text NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'open',
                kind varchar(20) NOT NULL DEFAULT 'live',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status_updated (status,updated_at)
            ) ENGINE=InnoDB {$collate};",
            "CREATE TABLE {$ideas} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                survey_id bigint(20) unsigned NOT NULL,
                idea_text text NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                submitted_by char(64) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                moderated_by bigint(20) unsigned NOT NULL DEFAULT 0,
                moderated_at datetime NULL,
                PRIMARY KEY  (id),
                KEY survey_status (survey_id,status),
                KEY submitted_by_time (submitted_by,created_at)
            ) ENGINE=InnoDB {$collate};",
            "CREATE TABLE {$matchups} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                survey_id bigint(20) unsigned NOT NULL,
                first_idea_id bigint(20) unsigned NOT NULL,
                second_idea_id bigint(20) unsigned NOT NULL,
                token_hash char(64) NOT NULL,
                session_hash char(64) NOT NULL,
                response_kind varchar(10) NULL,
                winner_idea_id bigint(20) unsigned NULL,
                issued_at datetime NOT NULL,
                responded_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY survey_issued (survey_id,issued_at),
                KEY session_issued (session_hash,issued_at),
                KEY first_idea (first_idea_id),
                KEY second_idea (second_idea_id)
            ) ENGINE=InnoDB {$collate};",
            "CREATE TABLE {$history} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                survey_id bigint(20) unsigned NOT NULL,
                idea_text text NOT NULL,
                idea_status varchar(20) NOT NULL DEFAULT 'active',
                score decimal(8,6) NULL,
                comparison_count int(10) unsigned NOT NULL DEFAULT 0,
                source varchar(190) NOT NULL DEFAULT '',
                source_timestamp datetime NULL,
                imported_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY survey_imported (survey_id,imported_at)
            ) ENGINE=InnoDB {$collate};",
            "CREATE TABLE {$audit} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                survey_id bigint(20) unsigned NOT NULL,
                idea_id bigint(20) unsigned NOT NULL DEFAULT 0,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                event varchar(40) NOT NULL,
                previous_state varchar(20) NOT NULL DEFAULT '',
                new_state varchar(20) NOT NULL DEFAULT '',
                details text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY survey_created (survey_id,created_at),
                KEY idea_created (idea_id,created_at)
            ) ENGINE=InnoDB {$collate};",
        );

        foreach ($queries as $query) {
            dbDelta($query);
        }

        foreach (array($surveys, $ideas, $matchups, $history, $audit) as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($wpdb->last_error || $found !== $table) {
                wp_die(esc_html__('Community Idea Voting could not create all required database tables. Check the database permissions and try again.', 'community-idea-voting'));
            }
        }

        update_option('civ_schema_version', self::SCHEMA_VERSION, false);
        return get_option('civ_schema_version') === self::SCHEMA_VERSION;
    }

    public static function maybe_upgrade()
    {
        if (get_option('civ_schema_version') !== self::SCHEMA_VERSION) {
            self::install();
        }
    }

    public static function now()
    {
        return current_time('mysql', true);
    }

    public static function survey($survey_id)
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table('surveys') . ' WHERE id = %d', $survey_id)
        );
    }

    public static function create_survey($title, $question, $status = 'open', $kind = 'live')
    {
        global $wpdb;

        if (!in_array($status, array('open', 'closed'), true) || !in_array($kind, array('live', 'historical'), true)) {
            return 0;
        }
        $now = self::now();
        $inserted = $wpdb->insert(
            self::table('surveys'),
            array(
                'title' => $title,
                'question' => $question,
                'status' => $status,
                'kind' => $kind,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function add_idea($survey_id, $text, $status, $submitted_by = '')
    {
        global $wpdb;

        $now = self::now();
        $inserted = $wpdb->insert(
            self::table('ideas'),
            array(
                'survey_id' => (int) $survey_id,
                'idea_text' => $text,
                'status' => $status,
                'submitted_by' => $submitted_by,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function audit($survey_id, $idea_id, $event, $previous_state = '', $new_state = '', $details = '')
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            self::table('audit_log'),
            array(
                'survey_id' => (int) $survey_id,
                'idea_id' => (int) $idea_id,
                'user_id' => get_current_user_id(),
                'event' => sanitize_key($event),
                'previous_state' => sanitize_key($previous_state),
                'new_state' => sanitize_key($new_state),
                'details' => sanitize_textarea_field($details),
                'created_at' => self::now(),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        return (bool) $inserted;
    }
}
