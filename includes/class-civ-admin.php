<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CIV_Admin
{
    public function register()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_post_civ_save_survey', array($this, 'save_survey'));
        add_action('admin_post_civ_add_seed', array($this, 'add_seed'));
        add_action('admin_post_civ_moderate_idea', array($this, 'moderate_idea'));
        add_action('admin_post_civ_export', array($this, 'export'));
        add_action('admin_post_civ_import', array($this, 'import'));
    }

    public function add_menu()
    {
        add_menu_page(
            __('Community voting', 'community-idea-voting'),
            __('Community voting', 'community-idea-voting'),
            'manage_options',
            'civ-dashboard',
            array($this, 'render_page'),
            'dashicons-format-chat'
        );
    }

    private function require_admin()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage community voting.', 'community-idea-voting'), '', array('response' => 403));
        }
    }

    private function redirect($message)
    {
        wp_safe_redirect(add_query_arg(
            array('page' => 'civ-dashboard', 'civ_notice' => $message),
            admin_url('admin.php')
        ));
        exit;
    }

    public function render_page()
    {
        $this->require_admin();
        global $wpdb;
        $surveys = $wpdb->get_results('SELECT * FROM ' . CIV_DB::table('surveys') . ' ORDER BY updated_at DESC');
        $ideas = $wpdb->get_results(
            'SELECT i.*, s.title AS survey_title FROM ' . CIV_DB::table('ideas') . ' i
             LEFT JOIN ' . CIV_DB::table('surveys') . ' s ON s.id = i.survey_id
             WHERE i.status = \'pending\' ORDER BY i.created_at ASC LIMIT 200'
        );
        $managed_ideas = $wpdb->get_results(
            'SELECT i.*, s.title AS survey_title FROM ' . CIV_DB::table('ideas') . ' i
             LEFT JOIN ' . CIV_DB::table('surveys') . ' s ON s.id = i.survey_id
             WHERE i.status IN (\'active\',\'inactive\',\'rejected\') ORDER BY i.updated_at DESC LIMIT 200'
        );
        $audit_log = $wpdb->get_results(
            'SELECT a.*, s.title AS survey_title FROM ' . CIV_DB::table('audit_log') . ' a
             LEFT JOIN ' . CIV_DB::table('surveys') . ' s ON s.id = a.survey_id
             ORDER BY a.created_at DESC LIMIT 100'
        );
        ?>
        <div class="wrap civ-admin">
            <h1><?php echo esc_html__('Community idea voting', 'community-idea-voting'); ?></h1>
            <?php if (!empty($_GET['civ_notice'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['civ_notice']))); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__('Surveys', 'community-idea-voting'); ?></h2>
            <?php if ($surveys) : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php echo esc_html__('Title / question', 'community-idea-voting'); ?></th>
                        <th><?php echo esc_html__('Status and pages', 'community-idea-voting'); ?></th>
                        <th><?php echo esc_html__('Survey controls', 'community-idea-voting'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($surveys as $survey) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($survey->title); ?></strong><br><?php echo esc_html($survey->question); ?></td>
                            <td><?php echo esc_html($survey->kind === 'historical' ? __('Historical snapshot', 'community-idea-voting') : ucfirst($survey->status)); ?><br>
                                <?php if ($survey->kind === 'live') : ?>
                                    <a href="<?php echo esc_url($this->survey_url($survey->id)); ?>"><?php echo esc_html__('Survey page', 'community-idea-voting'); ?></a> ·
                                <?php endif; ?>
                                <a href="<?php echo esc_url($this->survey_url($survey->id, true)); ?>"><?php echo esc_html__('Results page', 'community-idea-voting'); ?></a><br>
                                <?php if ($survey->kind === 'live') : ?><code>[civ_voting survey="<?php echo (int) $survey->id; ?>"]</code><br><?php endif; ?>
                                <code>[civ_results survey="<?php echo (int) $survey->id; ?>"]</code>
                            </td>
                            <td>
                                <?php if ($survey->kind === 'live') : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="civ_save_survey">
                                    <input type="hidden" name="survey_id" value="<?php echo (int) $survey->id; ?>">
                                    <?php wp_nonce_field('civ_save_survey_' . (int) $survey->id); ?>
                                    <label class="screen-reader-text" for="civ-status-<?php echo (int) $survey->id; ?>"><?php echo esc_html__('Survey status', 'community-idea-voting'); ?></label>
                                    <select id="civ-status-<?php echo (int) $survey->id; ?>" name="status">
                                        <option value="open" <?php selected($survey->status, 'open'); ?>><?php echo esc_html__('Open', 'community-idea-voting'); ?></option>
                                        <option value="closed" <?php selected($survey->status, 'closed'); ?>><?php echo esc_html__('Closed', 'community-idea-voting'); ?></option>
                                    </select>
                                    <button class="button"><?php echo esc_html__('Save', 'community-idea-voting'); ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="civ-admin-inline">
                                    <input type="hidden" name="action" value="civ_add_seed">
                                    <input type="hidden" name="survey_id" value="<?php echo (int) $survey->id; ?>">
                                    <?php wp_nonce_field('civ_add_seed_' . (int) $survey->id); ?>
                                    <label class="screen-reader-text" for="civ-seed-<?php echo (int) $survey->id; ?>"><?php echo esc_html__('Add an approved seed idea', 'community-idea-voting'); ?></label>
                                    <input id="civ-seed-<?php echo (int) $survey->id; ?>" type="text" name="idea" maxlength="1000" required placeholder="<?php echo esc_attr__('Add approved idea…', 'community-idea-voting'); ?>">
                                    <button class="button"><?php echo esc_html__('Add idea', 'community-idea-voting'); ?></button>
                                </form>
                                <details>
                                    <summary><?php echo esc_html__('Edit title or question', 'community-idea-voting'); ?></summary>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="civ_save_survey">
                                        <input type="hidden" name="survey_id" value="<?php echo (int) $survey->id; ?>">
                                        <?php wp_nonce_field('civ_save_survey_' . (int) $survey->id); ?>
                                        <p><label><?php echo esc_html__('Title', 'community-idea-voting'); ?><br>
                                            <input type="text" name="title" maxlength="190" value="<?php echo esc_attr($survey->title); ?>" required></label></p>
                                        <p><label><?php echo esc_html__('Question', 'community-idea-voting'); ?><br>
                                            <textarea name="question" rows="3" maxlength="2000" required><?php echo esc_textarea($survey->question); ?></textarea></label></p>
                                        <button class="button"><?php echo esc_html__('Save title and question', 'community-idea-voting'); ?></button>
                                    </form>
                                </details>
                                <?php else : ?>
                                    <p><?php echo esc_html__('Imported snapshots are closed and read-only.', 'community-idea-voting'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('No surveys yet. Create one below.', 'community-idea-voting'); ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Create a survey', 'community-idea-voting'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="civ-admin-form">
                <input type="hidden" name="action" value="civ_save_survey">
                <?php wp_nonce_field('civ_save_survey_new'); ?>
                <p><label for="civ-title"><?php echo esc_html__('Title', 'community-idea-voting'); ?></label><br>
                    <input id="civ-title" name="title" type="text" class="regular-text" maxlength="190" required></p>
                <p><label for="civ-question"><?php echo esc_html__('Question or prompt', 'community-idea-voting'); ?></label><br>
                    <textarea id="civ-question" name="question" class="large-text" rows="3" maxlength="2000" required></textarea></p>
                <button class="button button-primary"><?php echo esc_html__('Create open survey', 'community-idea-voting'); ?></button>
            </form>

            <h2><?php echo esc_html__('Pending ideas', 'community-idea-voting'); ?></h2>
            <?php if ($ideas) : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Idea', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Survey', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Submitted', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Moderate', 'community-idea-voting'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($ideas as $idea) : ?>
                        <tr>
                            <td><?php echo esc_html($idea->idea_text); ?></td>
                            <td><?php echo esc_html($idea->survey_title ? $idea->survey_title : __('Survey removed', 'community-idea-voting')); ?></td>
                            <td><?php echo esc_html(get_date_from_gmt($idea->created_at, get_option('date_format') . ' ' . get_option('time_format'))); ?></td>
                            <td>
                                <?php $this->moderation_link($idea->id, 'active', __('Approve', 'community-idea-voting')); ?>
                                <?php $this->moderation_link($idea->id, 'rejected', __('Reject', 'community-idea-voting')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('No ideas are awaiting review.', 'community-idea-voting'); ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Approved, inactive, and rejected ideas', 'community-idea-voting'); ?></h2>
            <?php if ($managed_ideas) : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Idea', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Survey', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Status', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Action', 'community-idea-voting'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($managed_ideas as $idea) : ?>
                        <tr>
                            <td><?php echo esc_html($idea->idea_text); ?></td>
                            <td><?php echo esc_html($idea->survey_title ? $idea->survey_title : __('Survey removed', 'community-idea-voting')); ?></td>
                            <td><?php echo esc_html(ucfirst($idea->status)); ?></td>
                            <td>
                                <?php if ($idea->status === 'active') : ?>
                                    <?php $this->moderation_link($idea->id, 'inactive', __('Deactivate', 'community-idea-voting')); ?>
                                <?php else : ?>
                                    <?php $this->moderation_link($idea->id, 'active', __('Restore / approve', 'community-idea-voting')); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('No moderated ideas yet.', 'community-idea-voting'); ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Recent audit history', 'community-idea-voting'); ?></h2>
            <?php if ($audit_log) : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('When', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Survey', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Event', 'community-idea-voting'); ?></th><th><?php echo esc_html__('State change', 'community-idea-voting'); ?></th><th><?php echo esc_html__('Details', 'community-idea-voting'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($audit_log as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html(get_date_from_gmt($entry->created_at, get_option('date_format') . ' ' . get_option('time_format'))); ?></td>
                            <td><?php echo esc_html($entry->survey_title ? $entry->survey_title : __('Survey removed', 'community-idea-voting')); ?></td>
                            <td><?php echo esc_html($entry->event); ?></td>
                            <td><?php echo esc_html(trim($entry->previous_state . ' → ' . $entry->new_state)); ?></td>
                            <td><?php echo esc_html($entry->details); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('No administrative actions recorded yet.', 'community-idea-voting'); ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Historical results import', 'community-idea-voting'); ?></h2>
            <p><?php echo esc_html__('Import the documented canonical CSV format. Each import creates a closed historical survey; imported scores remain separate from live rankings.', 'community-idea-voting'); ?></p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="civ_import">
                <?php wp_nonce_field('civ_import_history'); ?>
                <input type="file" name="history_csv" accept=".csv,text/csv" required>
                <label><?php echo esc_html__('Also seed approved ideas into an open survey (optional)', 'community-idea-voting'); ?>
                    <select name="seed_survey_id">
                        <option value="0"><?php echo esc_html__('Do not seed another survey', 'community-idea-voting'); ?></option>
                        <?php foreach ($surveys as $survey) : ?>
                            <?php if ($survey->status === 'open') : ?>
                                <option value="<?php echo (int) $survey->id; ?>"><?php echo esc_html($survey->title); ?> (<?php echo (int) $survey->id; ?>)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button"><?php echo esc_html__('Import historical CSV', 'community-idea-voting'); ?></button>
            </form>
            <p><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=civ_export'), 'civ_export')); ?>" class="button"><?php echo esc_html__('Export all survey data', 'community-idea-voting'); ?></a></p>
        </div>
        <?php
    }

    private function survey_url($survey_id, $results = false)
    {
        return home_url('/community-ideas/' . absint($survey_id) . ($results ? '/results' : '') . '/');
    }

    private function moderation_link($idea_id, $status, $label)
    {
        $url = add_query_arg(array(
            'action' => 'civ_moderate_idea',
            'idea_id' => (int) $idea_id,
            'status' => $status,
        ), admin_url('admin-post.php'));
        echo '<a class="button button-small" href="' . esc_url(wp_nonce_url($url, 'civ_moderate_' . (int) $idea_id)) . '">' . esc_html($label) . '</a> ';
    }

    public function save_survey()
    {
        $this->require_admin();
        global $wpdb;
        $survey_id = isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0;
        check_admin_referer('civ_save_survey_' . ($survey_id ? $survey_id : 'new'));
        if ($survey_id) {
            $current = CIV_DB::survey($survey_id);
            if (!$current) {
                wp_die(esc_html__('Survey not found.', 'community-idea-voting'), '', array('response' => 404));
            }
            if ($current->kind !== 'live') {
                wp_die(esc_html__('Historical snapshots are read-only.', 'community-idea-voting'), '', array('response' => 403));
            }
            if (isset($_POST['title'], $_POST['question'])) {
                $title = sanitize_text_field(wp_unslash($_POST['title']));
                $question = sanitize_textarea_field(wp_unslash($_POST['question']));
                $title_length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
                $question_length = function_exists('mb_strlen') ? mb_strlen($question) : strlen($question);
                if ($title === '' || $title_length > 190 || $question === '' || $question_length > 2000) {
                    wp_die(esc_html__('Enter a title and question.', 'community-idea-voting'), '', array('response' => 400));
                }
                if ($wpdb->query('START TRANSACTION') === false) {
                    wp_die(esc_html__('The survey could not be updated right now.', 'community-idea-voting'), '', array('response' => 503));
                }
                $updated = $wpdb->update(
                    CIV_DB::table('surveys'),
                    array('title' => $title, 'question' => $question, 'updated_at' => CIV_DB::now()),
                    array('id' => $survey_id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
                $changed = $current->title !== $title || $current->question !== $question;
                if ($updated === false || ($changed && !CIV_DB::audit($survey_id, 0, 'survey_edited', '', '', 'Survey title or question changed.'))) {
                    $wpdb->query('ROLLBACK');
                    wp_die(esc_html__('The survey could not be updated or recorded.', 'community-idea-voting'), '', array('response' => 500));
                }
                if ($wpdb->query('COMMIT') === false) {
                    wp_die(esc_html__('The survey update could not be confirmed.', 'community-idea-voting'), '', array('response' => 500));
                }
            } else {
                $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
                if (!in_array($status, array('open', 'closed'), true)) {
                    wp_die(esc_html__('Invalid survey status.', 'community-idea-voting'), '', array('response' => 400));
                }
                if ($wpdb->query('START TRANSACTION') === false) {
                    wp_die(esc_html__('The survey could not be updated right now.', 'community-idea-voting'), '', array('response' => 503));
                }
                $updated = $wpdb->update(
                    CIV_DB::table('surveys'),
                    array('status' => $status, 'updated_at' => CIV_DB::now()),
                    array('id' => $survey_id),
                    array('%s', '%s'),
                    array('%d')
                );
                if ($updated === false || ($current->status !== $status && !CIV_DB::audit($survey_id, 0, 'survey_status', $current->status, $status))) {
                    $wpdb->query('ROLLBACK');
                    wp_die(esc_html__('The survey could not be updated or recorded.', 'community-idea-voting'), '', array('response' => 500));
                }
                if ($wpdb->query('COMMIT') === false) {
                    wp_die(esc_html__('The survey status update could not be confirmed.', 'community-idea-voting'), '', array('response' => 500));
                }
            }
        } else {
            $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
            $question = isset($_POST['question']) ? sanitize_textarea_field(wp_unslash($_POST['question'])) : '';
            $title_length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
            $question_length = function_exists('mb_strlen') ? mb_strlen($question) : strlen($question);
            if ($title === '' || $title_length > 190 || $question === '' || $question_length > 2000) {
                wp_die(esc_html__('Enter a title and question.', 'community-idea-voting'), '', array('response' => 400));
            }
            if ($wpdb->query('START TRANSACTION') === false) {
                wp_die(esc_html__('The survey could not be created right now.', 'community-idea-voting'), '', array('response' => 503));
            }
            $survey_id = CIV_DB::create_survey($title, $question);
            if (!$survey_id || !CIV_DB::audit($survey_id, 0, 'survey_created', '', 'open')) {
                $wpdb->query('ROLLBACK');
                wp_die(esc_html__('The survey could not be created.', 'community-idea-voting'), '', array('response' => 500));
            }
            if ($wpdb->query('COMMIT') === false) {
                wp_die(esc_html__('Survey creation could not be confirmed.', 'community-idea-voting'), '', array('response' => 500));
            }
        }
        $this->redirect(__('Survey saved.', 'community-idea-voting'));
    }

    public function add_seed()
    {
        $this->require_admin();
        $survey_id = isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0;
        check_admin_referer('civ_add_seed_' . $survey_id);
        $survey = CIV_DB::survey($survey_id);
        $text = isset($_POST['idea']) ? sanitize_textarea_field(wp_unslash($_POST['idea'])) : '';
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if (!$survey || $survey->kind !== 'live' || $length < 3 || $length > 1000) {
            wp_die(esc_html__('Choose a survey and enter an idea.', 'community-idea-voting'), '', array('response' => 400));
        }
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            wp_die(esc_html__('The idea could not be added right now.', 'community-idea-voting'), '', array('response' => 503));
        }
        $idea_id = CIV_DB::add_idea($survey_id, $text, 'active');
        if (!$idea_id || !CIV_DB::audit($survey_id, $idea_id, 'idea_added', '', 'active')) {
            $wpdb->query('ROLLBACK');
            wp_die(esc_html__('The idea could not be added.', 'community-idea-voting'), '', array('response' => 500));
        }
        if ($wpdb->query('COMMIT') === false) {
            wp_die(esc_html__('Idea addition could not be confirmed.', 'community-idea-voting'), '', array('response' => 500));
        }
        $this->redirect(__('Approved idea added.', 'community-idea-voting'));
    }

    public function moderate_idea()
    {
        $this->require_admin();
        global $wpdb;
        $idea_id = isset($_GET['idea_id']) ? absint($_GET['idea_id']) : 0;
        check_admin_referer('civ_moderate_' . $idea_id);
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        if (!$idea_id || !in_array($status, array('active', 'rejected', 'inactive'), true)) {
            wp_die(esc_html__('Invalid moderation request.', 'community-idea-voting'), '', array('response' => 400));
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            wp_die(esc_html__('Moderation is unavailable right now.', 'community-idea-voting'), '', array('response' => 503));
        }
        $idea = $wpdb->get_row($wpdb->prepare(
            'SELECT id, survey_id, status FROM ' . CIV_DB::table('ideas') . ' WHERE id = %d FOR UPDATE',
            $idea_id
        ));
        if (!$idea) {
            $wpdb->query('ROLLBACK');
            wp_die(esc_html__('Idea not found.', 'community-idea-voting'), '', array('response' => 404));
        }
        $valid_transition = (
            ($idea->status === 'pending' && in_array($status, array('active', 'rejected'), true)) ||
            ($idea->status === 'active' && $status === 'inactive') ||
            (in_array($idea->status, array('inactive', 'rejected'), true) && $status === 'active')
        );
        if (!$valid_transition) {
            $wpdb->query('ROLLBACK');
            wp_die(esc_html__('That moderation transition is not allowed.', 'community-idea-voting'), '', array('response' => 409));
        }
        $updated = $wpdb->update(
            CIV_DB::table('ideas'),
            array(
                'status' => $status,
                'updated_at' => CIV_DB::now(),
                'moderated_by' => get_current_user_id(),
                'moderated_at' => CIV_DB::now(),
            ),
            array('id' => $idea_id, 'status' => $idea->status),
            array('%s', '%s', '%d', '%s'),
            array('%d', '%s')
        );
        if ($updated !== 1 || !CIV_DB::audit($idea->survey_id, $idea_id, 'idea_moderated', $idea->status, $status)) {
            $wpdb->query('ROLLBACK');
            wp_die(esc_html__('The idea could not be moderated.', 'community-idea-voting'), '', array('response' => 500));
        }
        if ($wpdb->query('COMMIT') === false) {
            wp_die(esc_html__('The moderation update could not be confirmed.', 'community-idea-voting'), '', array('response' => 500));
        }
        $this->redirect(__('Idea moderation saved.', 'community-idea-voting'));
    }

    public function export()
    {
        $this->require_admin();
        check_admin_referer('civ_export');
        global $wpdb;
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="community-idea-voting-export-' . gmdate('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        if (!$output) {
            wp_die(esc_html__('Export output could not be opened.', 'community-idea-voting'));
        }
        $this->write_csv_row($output, array('record_type', 'survey_id', 'survey_title', 'question', 'survey_status', 'idea_id', 'idea_text', 'idea_status', 'matchup_id', 'first_idea_id', 'second_idea_id', 'response_kind', 'winner_idea_id', 'score', 'comparison_count', 'source', 'source_timestamp', 'created_at', 'event', 'previous_state', 'new_state', 'actor_user_id', 'survey_kind'));

        foreach ($wpdb->get_results('SELECT * FROM ' . CIV_DB::table('surveys') . ' ORDER BY id') as $survey) {
            $this->write_csv_row($output, array('survey', $survey->id, $survey->title, $survey->question, $survey->status, '', '', '', '', '', '', '', '', '', '', '', '', $survey->created_at, '', '', '', '', $survey->kind));
        }
        foreach ($wpdb->get_results('SELECT * FROM ' . CIV_DB::table('ideas') . ' ORDER BY id') as $idea) {
            $this->write_csv_row($output, array('idea', $idea->survey_id, '', '', '', $idea->id, $idea->idea_text, $idea->status, '', '', '', '', '', '', '', '', '', $idea->created_at, '', '', '', '', ''));
        }
        foreach ($wpdb->get_results('SELECT * FROM ' . CIV_DB::table('matchups') . ' ORDER BY id') as $matchup) {
            $this->write_csv_row($output, array('comparison', $matchup->survey_id, '', '', '', '', '', '', $matchup->id, $matchup->first_idea_id, $matchup->second_idea_id, $matchup->response_kind, $matchup->winner_idea_id, '', '', '', '', $matchup->issued_at, '', '', '', '', ''));
        }
        foreach ($wpdb->get_results('SELECT * FROM ' . CIV_DB::table('historical_results') . ' ORDER BY id') as $row) {
            $this->write_csv_row($output, array('historical_result', $row->survey_id, '', '', '', '', $row->idea_text, $row->idea_status, '', '', '', '', '', $row->score, $row->comparison_count, $row->source, $row->source_timestamp, $row->imported_at, '', '', '', '', ''));
        }
        foreach ($wpdb->get_results('SELECT * FROM ' . CIV_DB::table('audit_log') . ' ORDER BY id') as $entry) {
            $this->write_csv_row($output, array('audit_event', $entry->survey_id, '', '', '', $entry->idea_id, '', '', '', '', '', '', '', '', '', '', '', $entry->created_at, $entry->event, $entry->previous_state, $entry->new_state, $entry->user_id, ''));
        }
        fclose($output);
        exit;
    }

    public function import()
    {
        $this->require_admin();
        check_admin_referer('civ_import_history');
        if (
            empty($_FILES['history_csv']['tmp_name']) ||
            !isset($_FILES['history_csv']['error']) ||
            (int) $_FILES['history_csv']['error'] !== UPLOAD_ERR_OK ||
            !isset($_FILES['history_csv']['size']) ||
            (int) $_FILES['history_csv']['size'] > 5 * MB_IN_BYTES ||
            !is_uploaded_file($_FILES['history_csv']['tmp_name'])
        ) {
            wp_die(esc_html__('Upload a CSV file smaller than 5 MB.', 'community-idea-voting'), '', array('response' => 400));
        }

        $handle = fopen($_FILES['history_csv']['tmp_name'], 'r');
        if (!$handle) {
            wp_die(esc_html__('The uploaded CSV could not be read.', 'community-idea-voting'), '', array('response' => 400));
        }
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if ($headers && isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        $required = array('survey_title', 'question', 'idea', 'idea_status', 'score', 'comparison_count', 'source', 'source_timestamp');
        if (!$headers || array_diff($required, $headers)) {
            fclose($handle);
            wp_die(esc_html__('CSV headers do not match the documented historical import format.', 'community-idea-voting'), '', array('response' => 400));
        }
        $positions = array_flip($headers);
        $rows = array();
        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($values === array(null) || count($values) < count($headers)) {
                continue;
            }
            $row = array();
            foreach ($required as $key) {
                $row[$key] = trim((string) $values[$positions[$key]]);
            }
            $row['survey_title'] = sanitize_text_field($row['survey_title']);
            $row['question'] = sanitize_textarea_field($row['question']);
            $row['idea'] = sanitize_textarea_field($row['idea']);
            $row['idea_status'] = sanitize_key($row['idea_status']);
            $row['source'] = sanitize_text_field($row['source']);
            $title_length = function_exists('mb_strlen') ? mb_strlen($row['survey_title']) : strlen($row['survey_title']);
            $question_length = function_exists('mb_strlen') ? mb_strlen($row['question']) : strlen($row['question']);
            $idea_length = function_exists('mb_strlen') ? mb_strlen($row['idea']) : strlen($row['idea']);
            if ($row['survey_title'] === '' || $title_length > 190 || $row['question'] === '' || $question_length > 2000 || $row['idea'] === '' || $idea_length > 1000 || !in_array($row['idea_status'], array('approved', 'active', 'pending', 'rejected'), true)) {
                fclose($handle);
                wp_die(esc_html__('Each row needs a title (max 190 characters), question (max 2000), idea (max 1000), and valid idea_status (approved, active, pending, or rejected).', 'community-idea-voting'), '', array('response' => 400));
            }
            if ($row['score'] !== '' && (!is_numeric($row['score']) || (float) $row['score'] < 0 || (float) $row['score'] > 1)) {
                fclose($handle);
                wp_die(esc_html__('Scores must be probabilities between 0 and 1, or blank.', 'community-idea-voting'), '', array('response' => 400));
            }
            if ($row['comparison_count'] !== '' && (!ctype_digit($row['comparison_count']) || (int) $row['comparison_count'] > 2147483647)) {
                fclose($handle);
                wp_die(esc_html__('Comparison counts must be non-negative whole numbers.', 'community-idea-voting'), '', array('response' => 400));
            }
            $source_length = function_exists('mb_strlen') ? mb_strlen($row['source']) : strlen($row['source']);
            if ($source_length > 190) {
                fclose($handle);
                wp_die(esc_html__('Source names must be 190 characters or fewer.', 'community-idea-voting'), '', array('response' => 400));
            }
            $timestamp = $this->parse_source_timestamp($row['source_timestamp']);
            if ($timestamp === false) {
                fclose($handle);
                wp_die(esc_html__('Source timestamps must use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.', 'community-idea-voting'), '', array('response' => 400));
            }
            $row['source_timestamp'] = $timestamp;
            $rows[] = $row;
            if (count($rows) > 5000) {
                fclose($handle);
                wp_die(esc_html__('The CSV contains too many rows (maximum 5,000).', 'community-idea-voting'), '', array('response' => 400));
            }
        }
        fclose($handle);
        if (!$rows) {
            wp_die(esc_html__('The CSV contains no result rows.', 'community-idea-voting'), '', array('response' => 400));
        }

        global $wpdb;
        $groups = array();
        foreach ($rows as $row) {
            $key = hash('sha256', $row['survey_title'] . "\n" . $row['question']);
            if (!isset($groups[$key])) {
                $groups[$key] = array('title' => $row['survey_title'], 'question' => $row['question'], 'rows' => array());
            }
            $groups[$key]['rows'][] = $row;
        }
        $seed_survey_id = isset($_POST['seed_survey_id']) ? absint($_POST['seed_survey_id']) : 0;
        if ($seed_survey_id) {
            $seed_survey = CIV_DB::survey($seed_survey_id);
            if (!$seed_survey || $seed_survey->status !== 'open' || $seed_survey->kind !== 'live') {
                wp_die(esc_html__('Choose an open survey for approved idea seeding.', 'community-idea-voting'), '', array('response' => 400));
            }
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            wp_die(esc_html__('The import could not start a database transaction.', 'community-idea-voting'), '', array('response' => 500));
        }
        foreach ($groups as $group) {
            $survey_id = CIV_DB::create_survey($group['title'], $group['question'], 'closed', 'historical');
            if (!$survey_id) {
                $wpdb->query('ROLLBACK');
                wp_die(esc_html__('A historical survey could not be created; no import was saved.', 'community-idea-voting'), '', array('response' => 500));
            }
            if (!CIV_DB::audit($survey_id, 0, 'history_imported', '', 'closed', 'Imported historical snapshot with ' . count($group['rows']) . ' rows.')) {
                $wpdb->query('ROLLBACK');
                wp_die(esc_html__('Import audit history could not be saved; no import was saved.', 'community-idea-voting'), '', array('response' => 500));
            }
            foreach ($group['rows'] as $row) {
                $approved = in_array($row['idea_status'], array('approved', 'active'), true);
                if ($approved && !CIV_DB::add_idea($survey_id, $row['idea'], 'active')) {
                    $wpdb->query('ROLLBACK');
                    wp_die(esc_html__('An imported idea could not be saved; no import was saved.', 'community-idea-voting'), '', array('response' => 500));
                }
                if ($approved && $seed_survey_id) {
                    $already_seeded = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM " . CIV_DB::table('ideas') . " WHERE survey_id = %d AND LOWER(idea_text) = LOWER(%s) AND status IN ('active','pending') LIMIT 1",
                        $seed_survey_id,
                        $row['idea']
                    ));
                    if (!$already_seeded) {
                        $seeded_id = CIV_DB::add_idea($seed_survey_id, $row['idea'], 'active');
                        if (!$seeded_id || !CIV_DB::audit($seed_survey_id, $seeded_id, 'history_seeded', '', 'active', 'Copied an approved imported idea into the selected live survey.')) {
                            $wpdb->query('ROLLBACK');
                            wp_die(esc_html__('An approved idea could not be seeded; no import was saved.', 'community-idea-voting'), '', array('response' => 500));
                        }
                    }
                }
                $inserted = $wpdb->insert(
                    CIV_DB::table('historical_results'),
                    array(
                        'survey_id' => $survey_id,
                        'idea_text' => $row['idea'],
                        'idea_status' => $approved ? 'active' : $row['idea_status'],
                        'score' => $row['score'] === '' ? null : (float) $row['score'],
                        'comparison_count' => $row['comparison_count'] === '' ? 0 : (int) $row['comparison_count'],
                        'source' => $row['source'] === '' ? 'legacy survey export' : $row['source'],
                        'source_timestamp' => $row['source_timestamp'],
                        'imported_at' => CIV_DB::now(),
                    ),
                    array('%d', '%s', '%f', '%d', '%s', '%s', '%s')
                );
                if (!$inserted) {
                    $wpdb->query('ROLLBACK');
                    wp_die(esc_html__('A historical result could not be saved; no import was saved.', 'community-idea-voting'), '', array('response' => 500));
                }
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            wp_die(esc_html__('The import could not be confirmed.', 'community-idea-voting'), '', array('response' => 500));
        }
        $this->redirect(__('Historical results imported as closed surveys.', 'community-idea-voting'));
    }

    private function parse_source_timestamp($value)
    {
        if ($value === '') {
            return null;
        }
        $value = sanitize_text_field($value);
        $format = strlen($value) === 10 ? '!Y-m-d' : '!Y-m-d H:i:s';
        $expected = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
        $date = DateTime::createFromFormat($format, $value, new DateTimeZone('UTC'));
        $errors = DateTime::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format($expected) !== $value) {
            return false;
        }
        return strlen($value) === 10 ? $value . ' 00:00:00' : $value;
    }

    private function write_csv_row($output, $values)
    {
        foreach ($values as &$value) {
            if (is_string($value) && preg_match('/^[\x00-\x20]*[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, $values);
    }
}
