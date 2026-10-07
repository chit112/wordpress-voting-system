<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CIV_REST
{
    const REST_NAMESPACE = 'community-idea-voting/v1';
    const SESSION_COOKIE = 'civ_visitor';

    public function register()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route(self::REST_NAMESPACE, '/surveys/(?P<id>\d+)/matchup', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_matchup'),
            'permission_callback' => '__return_true',
            'args' => array('id' => array('sanitize_callback' => 'absint')),
        ));
        register_rest_route(self::REST_NAMESPACE, '/matchups/respond', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'respond'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/surveys/(?P<id>\d+)/ideas', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'submit_idea'),
            'permission_callback' => '__return_true',
            'args' => array('id' => array('sanitize_callback' => 'absint')),
        ));
        register_rest_route(self::REST_NAMESPACE, '/surveys/(?P<id>\d+)/results', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_results'),
            'permission_callback' => '__return_true',
            'args' => array('id' => array('sanitize_callback' => 'absint')),
        ));
    }

    private function session_hash($create = false)
    {
        if (empty($_COOKIE[self::SESSION_COOKIE])) {
            if (!$create) {
                return '';
            }

            $token = wp_generate_password(64, false, false);
            $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
            $set = setcookie(self::SESSION_COOKIE, $token, array(
                'expires' => time() + YEAR_IN_SECONDS,
                'path' => $path,
                'domain' => $domain,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
            if (!$set) {
                return new WP_Error('civ_cookie_failed', __('Anonymous voting could not start in this browser. Please enable first-party cookies and try again.', 'community-idea-voting'), array('status' => 500));
            }
            $_COOKIE[self::SESSION_COOKIE] = $token;
        }

        return hash_hmac('sha256', sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE])), wp_salt('auth'));
    }

    private function same_origin()
    {
        $origin = '';
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $origin = esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN']));
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $origin = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
        }

        if (!$origin) {
            return false;
        }

        $origin_host = wp_parse_url($origin, PHP_URL_HOST);
        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $origin_scheme = wp_parse_url($origin, PHP_URL_SCHEME);
        $site_scheme = wp_parse_url(home_url('/'), PHP_URL_SCHEME);
        $origin_port = wp_parse_url($origin, PHP_URL_PORT);
        $site_port = wp_parse_url(home_url('/'), PHP_URL_PORT);
        if (!$origin_host || !$site_host || strtolower($origin_host) !== strtolower($site_host)) {
            return false;
        }
        $origin_port = $origin_port ? (int) $origin_port : (strtolower((string) $origin_scheme) === 'https' ? 443 : 80);
        $site_port = $site_port ? (int) $site_port : (strtolower((string) $site_scheme) === 'https' ? 443 : 80);
        return strtolower((string) $origin_scheme) === strtolower((string) $site_scheme) && $origin_port === $site_port;
    }

    private function check_origin()
    {
        if (!$this->same_origin()) {
            return new WP_Error('civ_origin_invalid', __('Request origin could not be verified.', 'community-idea-voting'), array('status' => 403));
        }
        return true;
    }

    public function get_matchup($request)
    {
        global $wpdb;

        $survey_id = absint($request['id']);
        $survey = CIV_DB::survey($survey_id);
        if (!$survey || $survey->status !== 'open' || $survey->kind !== 'live') {
            return new WP_Error('civ_survey_unavailable', __('This survey is not open for voting.', 'community-idea-voting'), array('status' => 404));
        }

        $session_hash = $this->session_hash(true);
        if (is_wp_error($session_hash)) {
            return $session_hash;
        }
        $issued_recently = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . CIV_DB::table('matchups') . ' WHERE survey_id = %d AND session_hash = %s AND issued_at >= %s',
            $survey_id,
            $session_hash,
            gmdate('Y-m-d H:i:s', time() - MINUTE_IN_SECONDS)
        ));
        if ($issued_recently === null || $wpdb->last_error) {
            return new WP_Error('civ_matchup_storage_failed', __('A matchup could not be issued. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        if ((int) $issued_recently >= 40) {
            return new WP_Error('civ_matchup_rate_limited', __('Please wait a moment before requesting more matchups.', 'community-idea-voting'), array('status' => 429));
        }
        $ideas_table = CIV_DB::table('ideas');
        $matchups_table = CIV_DB::table('matchups');
        $ideas = $wpdb->get_results($wpdb->prepare(
            "SELECT i.id, i.idea_text, COALESCE(exposure.count, 0) AS exposure
             FROM {$ideas_table} i
             LEFT JOIN (
                 SELECT idea_id, COUNT(*) AS count
                 FROM (
                     SELECT first_idea_id AS idea_id FROM {$matchups_table} WHERE survey_id = %d AND responded_at IS NOT NULL
                     UNION ALL
                     SELECT second_idea_id AS idea_id FROM {$matchups_table} WHERE survey_id = %d AND responded_at IS NOT NULL
                 ) AS served
                 GROUP BY idea_id
             ) AS exposure ON exposure.idea_id = i.id
             WHERE i.survey_id = %d AND i.status = 'active'
             ORDER BY COALESCE(exposure.count, 0) ASC, RAND()
             LIMIT 24",
            $survey_id,
            $survey_id,
            $survey_id
        ));

        if (!is_array($ideas)) {
            return new WP_Error('civ_matchup_storage_failed', __('A matchup could not be issued. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        if (count($ideas) < 2) {
            return new WP_Error('civ_not_enough_ideas', __('This survey needs at least two approved ideas before voting can begin.', 'community-idea-voting'), array('status' => 409));
        }

        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT first_idea_id, second_idea_id FROM {$matchups_table}
             WHERE survey_id = %d AND session_hash = %s
             ORDER BY issued_at DESC LIMIT 20",
            $survey_id,
            $session_hash
        ));
        if (!is_array($recent)) {
            return new WP_Error('civ_matchup_storage_failed', __('A matchup could not be issued. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        $recent_pairs = array();
        foreach ($recent as $row) {
            $recent_pairs[$this->pair_key($row->first_idea_id, $row->second_idea_id)] = true;
        }

        $candidates = array();
        $lowest_exposure = PHP_INT_MAX;
        $idea_count = count($ideas);
        for ($first = 0; $first < $idea_count; $first++) {
            for ($second = $first + 1; $second < $idea_count; $second++) {
                $key = $this->pair_key($ideas[$first]->id, $ideas[$second]->id);
                $score = (int) $ideas[$first]->exposure + (int) $ideas[$second]->exposure;
                if (isset($recent_pairs[$key])) {
                    $score += 1000000;
                }
                if ($score < $lowest_exposure) {
                    $lowest_exposure = $score;
                    $candidates = array();
                }
                if ($score === $lowest_exposure) {
                    $candidates[] = array($ideas[$first], $ideas[$second]);
                }
            }
        }

        if (!$candidates) {
            return new WP_Error('civ_matchup_unavailable', __('A new matchup could not be selected. Please try again.', 'community-idea-voting'), array('status' => 503));
        }

        $pair = $candidates[wp_rand(0, count($candidates) - 1)];
        if (wp_rand(0, 1)) {
            $pair = array($pair[1], $pair[0]);
        }
        $raw_token = wp_generate_password(64, false, false);
        $inserted = $wpdb->insert(
            $matchups_table,
            array(
                'survey_id' => $survey_id,
                'first_idea_id' => (int) $pair[0]->id,
                'second_idea_id' => (int) $pair[1]->id,
                'token_hash' => hash_hmac('sha256', $raw_token, wp_salt('auth')),
                'session_hash' => $session_hash,
                'issued_at' => CIV_DB::now(),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s')
        );

        if (!$inserted) {
            return new WP_Error('civ_matchup_storage_failed', __('A matchup could not be issued. Please try again.', 'community-idea-voting'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'token' => $raw_token,
            'ideas' => array(
                array('id' => (int) $pair[0]->id, 'text' => $pair[0]->idea_text),
                array('id' => (int) $pair[1]->id, 'text' => $pair[1]->idea_text),
            ),
        ));
    }

    private function pair_key($first, $second)
    {
        $ids = array((int) $first, (int) $second);
        sort($ids, SORT_NUMERIC);
        return $ids[0] . ':' . $ids[1];
    }

    public function respond($request)
    {
        global $wpdb;

        $origin = $this->check_origin();
        if (is_wp_error($origin)) {
            return $origin;
        }

        $session_hash = $this->session_hash();
        if (is_wp_error($session_hash)) {
            return $session_hash;
        }
        $token = sanitize_text_field((string) $request->get_param('token'));
        $winner = $request->get_param('winner');
        $skip = rest_sanitize_boolean($request->get_param('skip'));
        if (!$session_hash || strlen($token) < 32 || ($skip && !empty($winner)) || (!$skip && !absint($winner))) {
            return new WP_Error('civ_response_invalid', __('The response is incomplete or invalid.', 'community-idea-voting'), array('status' => 400));
        }

        $table = CIV_DB::table('matchups');
        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('civ_transaction_failed', __('Voting is temporarily unavailable. Please try again.', 'community-idea-voting'), array('status' => 503));
        }
        $matchup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE token_hash = %s AND session_hash = %s FOR UPDATE",
            hash_hmac('sha256', $token, wp_salt('auth')),
            $session_hash
        ));

        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('civ_matchup_storage_failed', __('Your response could not be verified. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        if (!$matchup) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('civ_matchup_expired', __('This matchup is no longer available. Please get another pair.', 'community-idea-voting'), array('status' => 409));
        }

        $winner_id = $skip ? null : absint($winner);
        if ($matchup->responded_at !== null) {
            $same_response = $matchup->response_kind === ($skip ? 'skip' : 'vote') &&
                ($skip ? $matchup->winner_idea_id === null : (int) $matchup->winner_idea_id === $winner_id);
            if (!$same_response) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('civ_matchup_expired', __('This matchup has already received a different response.', 'community-idea-voting'), array('status' => 409));
            }
            if ($wpdb->query('COMMIT') === false) {
                return new WP_Error('civ_response_storage_failed', __('Your response could not be confirmed. Please try again.', 'community-idea-voting'), array('status' => 500));
            }
            return rest_ensure_response(array('recorded' => true, 'replayed' => true));
        }

        $survey = $wpdb->get_row($wpdb->prepare(
            'SELECT status, kind FROM ' . CIV_DB::table('surveys') . ' WHERE id = %d FOR UPDATE',
            (int) $matchup->survey_id
        ));
        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('civ_response_storage_failed', __('Your response could not be verified. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        if (!$survey || $survey->status !== 'open' || $survey->kind !== 'live') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('civ_survey_unavailable', __('This survey is no longer open for voting.', 'community-idea-voting'), array('status' => 409));
        }

        if (!$skip && !in_array($winner_id, array((int) $matchup->first_idea_id, (int) $matchup->second_idea_id), true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('civ_winner_invalid', __('Choose one of the ideas shown.', 'community-idea-voting'), array('status' => 400));
        }

        $ids = array((int) $matchup->first_idea_id, (int) $matchup->second_idea_id);
        sort($ids, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $active_ideas = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM " . CIV_DB::table('ideas') . " WHERE survey_id = %d AND status = 'active' AND id IN ({$placeholders}) FOR UPDATE",
            array_merge(array((int) $matchup->survey_id), $ids)
        ));
        if (!is_array($active_ideas) || $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('civ_response_storage_failed', __('Your response could not be verified. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        if (count($active_ideas) !== 2) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('civ_ideas_unavailable', __('One of these ideas is no longer active.', 'community-idea-voting'), array('status' => 409));
        }

        $updated = $wpdb->update(
            $table,
            array(
                'response_kind' => $skip ? 'skip' : 'vote',
                'winner_idea_id' => $winner_id,
                'responded_at' => CIV_DB::now(),
            ),
            array('id' => (int) $matchup->id, 'responded_at' => null),
            array('%s', '%d', '%s'),
            array('%d', '%s')
        );

        if ($updated !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('civ_response_storage_failed', __('Your response was not recorded. Please try again.', 'community-idea-voting'), array('status' => 500));
        }

        if ($wpdb->query('COMMIT') === false) {
            return new WP_Error('civ_response_storage_failed', __('Your response could not be confirmed. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        return rest_ensure_response(array('recorded' => true));
    }

    public function submit_idea($request)
    {
        global $wpdb;

        $origin = $this->check_origin();
        if (is_wp_error($origin)) {
            return $origin;
        }
        $survey_id = absint($request['id']);
        $survey = CIV_DB::survey($survey_id);
        if (!$survey || $survey->status !== 'open' || $survey->kind !== 'live') {
            return new WP_Error('civ_survey_unavailable', __('This survey is not open for submissions.', 'community-idea-voting'), array('status' => 404));
        }
        $session_hash = $this->session_hash();
        if (is_wp_error($session_hash)) {
            return $session_hash;
        }
        $text = sanitize_textarea_field((string) $request->get_param('idea'));
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if (!$session_hash || $length < 3 || $length > 1000) {
            return new WP_Error('civ_idea_invalid', __('Enter an idea between 3 and 1000 characters.', 'community-idea-voting'), array('status' => 400));
        }

        $ideas_table = CIV_DB::table('ideas');
        $recent_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ideas_table} WHERE submitted_by = %s AND created_at >= %s",
            $session_hash,
            gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));
        if ($recent_count === null || $wpdb->last_error) {
            return new WP_Error('civ_idea_storage_failed', __('Your idea could not be submitted. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        if ((int) $recent_count >= 5) {
            return new WP_Error('civ_submission_limit', __('You have reached the daily idea-submission limit.', 'community-idea-voting'), array('status' => 429));
        }

        $duplicate = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$ideas_table} WHERE survey_id = %d AND LOWER(idea_text) = LOWER(%s) AND status IN ('active','pending') LIMIT 1",
            $survey_id,
            $text
        ));
        if ($wpdb->last_error) {
            return new WP_Error('civ_idea_storage_failed', __('Your idea could not be submitted. Please try again.', 'community-idea-voting'), array('status' => 500));
        }
        if ($duplicate) {
            return new WP_Error('civ_idea_duplicate', __('A similar idea has already been submitted.', 'community-idea-voting'), array('status' => 409));
        }

        $idea_id = CIV_DB::add_idea($survey_id, $text, 'pending', $session_hash);
        if (!$idea_id) {
            return new WP_Error('civ_idea_storage_failed', __('Your idea could not be submitted. Please try again.', 'community-idea-voting'), array('status' => 500));
        }

        return new WP_REST_Response(array(
            'submitted' => true,
            'message' => __('Thanks! Your idea is awaiting administrator approval.', 'community-idea-voting'),
        ), 201);
    }

    public function get_results($request)
    {
        global $wpdb;

        $survey_id = absint($request['id']);
        $survey = CIV_DB::survey($survey_id);
        if (!$survey) {
            return new WP_Error('civ_survey_not_found', __('Survey not found.', 'community-idea-voting'), array('status' => 404));
        }

        $ideas_table = CIV_DB::table('ideas');
        $matchups_table = CIV_DB::table('matchups');
        $ideas = $wpdb->get_results($wpdb->prepare(
            "SELECT id, idea_text FROM {$ideas_table} WHERE survey_id = %d AND status = 'active' ORDER BY id ASC",
            $survey_id
        ));
        $comparisons = $wpdb->get_results($wpdb->prepare(
            "SELECT first_idea_id, second_idea_id,
                    SUM(CASE WHEN winner_idea_id = first_idea_id THEN 1 ELSE 0 END) AS first_wins,
                    COUNT(*) AS total
             FROM {$matchups_table} WHERE survey_id = %d AND response_kind = 'vote'
             GROUP BY first_idea_id, second_idea_id",
            $survey_id
        ));
        if (!is_array($ideas) || !is_array($comparisons)) {
            return new WP_Error('civ_results_storage_failed', __('Results could not be loaded. Please try again later.', 'community-idea-voting'), array('status' => 500));
        }
        $ranking = $this->calculate_ranking($ideas, $comparisons);
        $historical = $wpdb->get_results($wpdb->prepare(
            'SELECT idea_text, score, comparison_count, source, source_timestamp FROM ' . CIV_DB::table('historical_results') . ' WHERE survey_id = %d AND idea_status = \'active\' ORDER BY score DESC, id ASC',
            $survey_id
        ));
        if (!is_array($historical)) {
            return new WP_Error('civ_results_storage_failed', __('Results could not be loaded. Please try again later.', 'community-idea-voting'), array('status' => 500));
        }
        if ($survey->kind === 'historical') {
            $ranking = array();
        }

        return rest_ensure_response(array(
            'title' => $survey->title,
            'question' => $survey->question,
            'status' => $survey->status,
            'kind' => $survey->kind,
            'ranking' => $ranking,
            'historical' => $historical,
            'score_note' => __('Score estimates the chance of beating a randomly selected active idea based on recorded pairwise choices. Low comparison counts mean rankings are uncertain; this is not a representative poll.', 'community-idea-voting'),
        ));
    }

    private function calculate_ranking($ideas, $comparisons)
    {
        $ids = array();
        $texts = array();
        $counts = array();
        $ratings = array();
        foreach ($ideas as $idea) {
            $id = (int) $idea->id;
            $ids[] = $id;
            $texts[$id] = $idea->idea_text;
            $counts[$id] = 0;
            $ratings[$id] = 0.0;
        }

        if (count($ids) < 2) {
            return array();
        }

        $games = array();
        foreach ($comparisons as $comparison) {
            $first = (int) $comparison->first_idea_id;
            $second = (int) $comparison->second_idea_id;
            $first_wins = (int) $comparison->first_wins;
            $total = (int) $comparison->total;
            if (!isset($ratings[$first], $ratings[$second]) || $total < 1 || $first_wins < 0 || $first_wins > $total) {
                continue;
            }
            $key = $first . ':' . $second;
            $games[$key] = array('first' => $first, 'second' => $second, 'first_wins' => $first_wins, 'total' => $total);
            $counts[$first] += $total;
            $counts[$second] += $total;
        }

        for ($iteration = 0; $iteration < 300; $iteration++) {
            $gradient = array_fill_keys($ids, 0.0);
            foreach ($games as $game) {
                $probability = $this->logistic($ratings[$game['first']] - $ratings[$game['second']]);
                $error = $game['first_wins'] - ($game['total'] * $probability);
                $gradient[$game['first']] += $error;
                $gradient[$game['second']] -= $error;
            }
            foreach ($ids as $id) {
                $gradient[$id] -= 0.05 * $ratings[$id];
                $ratings[$id] += 0.03 * $gradient[$id];
                $ratings[$id] = max(-12.0, min(12.0, $ratings[$id]));
            }
        }

        $ranking = array();
        foreach ($ids as $id) {
            $score = 0.0;
            foreach ($ids as $other_id) {
                if ($id !== $other_id) {
                    $score += $this->logistic($ratings[$id] - $ratings[$other_id]);
                }
            }
            $ranking[] = array(
                'id' => $id,
                'text' => $texts[$id],
                'score' => round($score / (count($ids) - 1), 4),
                'comparisons' => $counts[$id],
                'low_data' => $counts[$id] < 5,
            );
        }
        usort($ranking, function ($first, $second) {
            if ($first['score'] === $second['score']) {
                return $second['comparisons'] <=> $first['comparisons'];
            }
            return $second['score'] <=> $first['score'];
        });
        return $ranking;
    }

    private function logistic($value)
    {
        $value = max(-30.0, min(30.0, $value));
        return 1.0 / (1.0 + exp(-$value));
    }
}
