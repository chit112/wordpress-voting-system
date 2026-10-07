<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CIV_Public
{
    public function register()
    {
        add_action('init', array($this, 'register_assets_and_block'));
        add_action('admin_init', array($this, 'add_privacy_policy_content'));
        add_shortcode('civ_voting', array($this, 'voting_shortcode'));
        add_shortcode('civ_results', array($this, 'results_shortcode'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'handle_route'));
    }

    public function add_privacy_policy_content()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $content = '<p>' . esc_html__('Community Idea Voting sets an HTTP-only first-party cookie named civ_visitor to bind a browser to its anonymous voting matchups. The plugin stores a keyed hash of this cookie with matchup responses and does not store the visitor IP address. The cookie expires after one year. Votes, submitted ideas, moderation history, and imported result records remain on this site until the site administrator removes the plugin data.', 'community-idea-voting') . '</p>';
        wp_add_privacy_policy_content(
            esc_html__('Community Idea Voting', 'community-idea-voting'),
            wp_kses_post($content)
        );
    }

    public static function add_rewrite_rules()
    {
        add_rewrite_rule('^community-ideas/([0-9]+)/results/?$', 'index.php?civ_survey_id=$matches[1]&civ_view=results', 'top');
        add_rewrite_rule('^community-ideas/([0-9]+)/?$', 'index.php?civ_survey_id=$matches[1]&civ_view=vote', 'top');
        flush_rewrite_rules();
    }

    public function query_vars($vars)
    {
        $vars[] = 'civ_survey_id';
        $vars[] = 'civ_view';
        return $vars;
    }

    public function register_assets_and_block()
    {
        wp_register_script(
            'civ-front',
            CIV_URL . 'assets/voting.js',
            array(),
            CIV_VERSION,
            true
        );
        wp_register_style('civ-front', CIV_URL . 'assets/voting.css', array(), CIV_VERSION);
        wp_localize_script('civ-front', 'CIVConfig', array(
            'restRoot' => esc_url_raw(rest_url(CIV_REST::REST_NAMESPACE . '/')),
            'strings' => array(
                'loading' => __('Loading a pair of ideas…', 'community-idea-voting'),
                'loadError' => __('We could not load a pair. Please try again.', 'community-idea-voting'),
                'voteError' => __('Your response could not be recorded. Please try again.', 'community-idea-voting'),
                'thanks' => __('Thanks for weighing in.', 'community-idea-voting'),
                'progress' => __('Responses recorded this visit: %d', 'community-idea-voting'),
                'retry' => __('Try again', 'community-idea-voting'),
                'skip' => __("I can't decide", 'community-idea-voting'),
                'submitIdea' => __('Suggest an idea', 'community-idea-voting'),
                'ideaPlaceholder' => __('Share an idea (up to 1000 characters)', 'community-idea-voting'),
                'submit' => __('Submit for review', 'community-idea-voting'),
                'pending' => __('Thanks! Your idea is awaiting administrator approval.', 'community-idea-voting'),
                'submissionError' => __('Your idea could not be submitted. Please try again.', 'community-idea-voting'),
                'comparisons' => __('comparisons', 'community-idea-voting'),
                'lowData' => __('Few comparisons so far; this position is uncertain.', 'community-idea-voting'),
                'historical' => __('Historical snapshot (not live votes)', 'community-idea-voting'),
                'noResults' => __('A ranking is not available until approved ideas have been compared.', 'community-idea-voting'),
            ),
        ));
        wp_register_script(
            'civ-block-editor',
            CIV_URL . 'assets/block-editor.js',
            array('wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'),
            CIV_VERSION,
            true
        );
        register_block_type('civ/voting', array(
            'api_version' => 2,
            'attributes' => array(
                'surveyId' => array('type' => 'number', 'default' => 0),
            ),
            'editor_script' => 'civ-block-editor',
            'render_callback' => array($this, 'render_block'),
        ));
    }

    private function enqueue_front_assets()
    {
        wp_enqueue_script('civ-front');
        wp_enqueue_style('civ-front');
    }

    public function voting_shortcode($attributes)
    {
        $attributes = shortcode_atts(array('survey' => 0), $attributes, 'civ_voting');
        $survey_id = absint($attributes['survey']);
        $survey = CIV_DB::survey($survey_id);
        if (!$survey || $survey->status !== 'open' || $survey->kind !== 'live') {
            return '<p class="civ-message">' . esc_html__('This survey is not open for voting.', 'community-idea-voting') . '</p>';
        }
        $this->enqueue_front_assets();
        ob_start();
        ?>
        <section class="civ-voting" data-civ-voting data-survey-id="<?php echo (int) $survey_id; ?>">
            <h2 class="civ-title"><?php echo esc_html($survey->title); ?></h2>
            <p class="civ-question"><?php echo esc_html($survey->question); ?></p>
            <p class="civ-status" data-civ-status role="status" aria-live="polite"><?php echo esc_html__('Loading a pair of ideas…', 'community-idea-voting'); ?></p>
            <p class="civ-progress" data-civ-progress aria-live="polite"></p>
            <div class="civ-pair" data-civ-pair></div>
            <div class="civ-controls" data-civ-controls hidden>
                <button type="button" class="civ-button civ-skip" data-civ-skip><?php echo esc_html__("I can't decide", 'community-idea-voting'); ?></button>
            </div>
            <details class="civ-submit">
                <summary><?php echo esc_html__('Suggest an idea', 'community-idea-voting'); ?></summary>
                <form data-civ-submit-form>
                    <label>
                        <span><?php echo esc_html__('Your idea', 'community-idea-voting'); ?></span>
                        <textarea name="idea" minlength="3" maxlength="1000" required></textarea>
                    </label>
                    <button type="submit" class="civ-button"><?php echo esc_html__('Submit for review', 'community-idea-voting'); ?></button>
                    <p class="civ-status" data-civ-submit-status role="status" aria-live="polite"></p>
                </form>
            </details>
            <p class="civ-footnote"><?php echo esc_html__('Suggestions are reviewed by an administrator before they can be voted on.', 'community-idea-voting'); ?></p>
        </section>
        <?php
        return ob_get_clean();
    }

    public function results_shortcode($attributes)
    {
        $attributes = shortcode_atts(array('survey' => 0), $attributes, 'civ_results');
        $survey_id = absint($attributes['survey']);
        $survey = CIV_DB::survey($survey_id);
        if (!$survey) {
            return '<p class="civ-message">' . esc_html__('Survey not found.', 'community-idea-voting') . '</p>';
        }
        $this->enqueue_front_assets();
        return '<section class="civ-results" data-civ-results data-survey-id="' . (int) $survey_id . '"><h2>' .
            esc_html($survey->title) . '</h2><p>' . esc_html($survey->question) .
            '</p><p class="civ-status" data-civ-results-status role="status" aria-live="polite">' .
            esc_html__('Loading results…', 'community-idea-voting') . '</p><div data-civ-results-list></div></section>';
    }

    public function render_block($attributes)
    {
        $survey_id = isset($attributes['surveyId']) ? absint($attributes['surveyId']) : 0;
        if (!$survey_id) {
            return '<p class="civ-message">' . esc_html__('Choose a survey in the block settings.', 'community-idea-voting') . '</p>';
        }
        return $this->voting_shortcode(array('survey' => $survey_id));
    }

    public function handle_route()
    {
        $survey_id = absint(get_query_var('civ_survey_id'));
        if (!$survey_id) {
            return;
        }
        $survey = CIV_DB::survey($survey_id);
        $view = sanitize_key(get_query_var('civ_view'));
        if (!$survey || !in_array($view, array('vote', 'results'), true)) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            return;
        }
        if ($view === 'vote' && ($survey->status !== 'open' || $survey->kind !== 'live')) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            return;
        }

        status_header(200);
        get_header();
        echo '<main class="civ-standalone">';
        echo do_shortcode($view === 'results' ? '[civ_results survey="' . $survey_id . '"]' : '[civ_voting survey="' . $survey_id . '"]');
        echo '</main>';
        get_footer();
        exit;
    }
}
