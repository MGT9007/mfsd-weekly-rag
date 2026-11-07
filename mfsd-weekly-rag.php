<?php
/**
 * Plugin Name: MFSD Weekly RAG + MBTI + DISC
 * Description: Weekly RAG (26) + MBTI (12) + DISC survey over 6 weeks with UM integration, AI summaries, and results storage.
 * Version: 8.0.0
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/mfsd-shared-helpers.php';
require_once __DIR__ . '/includes/class-mfsd-parent-tabs.php';

final class MFSD_Weekly_RAG {
    const VERSION = '8.0.0';
    const SG_SOURCE = 'weekly_rag';   // safeguarding + commitments source_task
    const STAGE_LABELS = array('not_started'=>'Not started','in_progress'=>'In progress','plan'=>"Choosing next week's plan",'completed'=>'Completed');

    /** Student-facing personality names (mirrors mfsd-personality-test). Codes are never given to the AI. */
    const PERSONALITY_NAMES = array(
        'ISTJ' => array('The Logistician', 'Sentinel'),  'ISFJ' => array('The Defender', 'Sentinel'),
        'ESTJ' => array('The Executive', 'Sentinel'),    'ESFJ' => array('The Consul', 'Sentinel'),
        'INFJ' => array('The Advocate', 'Diplomat'),     'INFP' => array('The Mediator', 'Diplomat'),
        'ENFJ' => array('The Protagonist', 'Diplomat'),  'ENFP' => array('The Campaigner', 'Diplomat'),
        'INTJ' => array('The Architect', 'Analyst'),     'INTP' => array('The Logician', 'Analyst'),
        'ENTJ' => array('The Commander', 'Analyst'),     'ENTP' => array('The Debater', 'Analyst'),
        'ISTP' => array('The Virtuoso', 'Explorer'),     'ISFP' => array('The Adventurer', 'Explorer'),
        'ESTP' => array('The Entrepreneur', 'Explorer'), 'ESFP' => array('The Entertainer', 'Explorer'),
    );

    /** Plain-English communication styles used instead of DISC letters. */
    const COMMUNICATION_STYLES = array(
        'D' => 'direct and results-focused',
        'I' => 'outgoing and enthusiastic',
        'S' => 'steady and supportive',
        'C' => 'careful and detail-focused',
    );

    /** Appended to every AI prompt — students must never see test names or letter codes. */
    const NO_CODES_RULE = "IMPORTANT: Never mention MBTI, Myers-Briggs, DISC, or any letter-code personality labels (e.g. INFJ, DI). Refer to personality only by the name given, if one is given.";
    const NONCE_ACTION = 'mfsd_rag_nonce';

    const TBL_QUESTIONS      = 'mfsd_rag_questions';
    const TBL_ANSWERS_RAG    = 'mfsd_rag_answers';
    const TBL_ANSWERS_MB     = 'mfsd_mbti_answers';
    const TBL_MB_RESULTS     = 'mfsd_mbti_results';
    const TBL_ANSWERS_DISC   = 'mfsd_disc_answers';
    const TBL_DISC_RESULTS   = 'mfsd_disc_results';
    const TBL_WEEK_SUMMARIES = 'mfsd_week_summaries';
    const TBL_RED_PLANS      = 'mfsd_rag_red_plans';

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('init',          array($this, 'assets'));
        add_shortcode('mfsd_rag',   array($this, 'shortcode'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu',    array($this, 'admin_menu'));
        add_action('init',          array($this, 'maybe_upgrade_db'));
        add_filter('mfsd_commitment_sources',   array($this, 'register_commitment_source'));
        add_filter('mfsd_safeguarding_sources', array($this, 'register_safeguarding_source'));
        add_action('mfsd_concern_flag_raised',  array($this, 'on_flag_raised'), 10, 3);
        add_action('admin_init',    array($this, 'save_admin_settings'));
        add_action('admin_init',    array($this, 'handle_question_actions'));
        add_filter('stevegpt_plugin_integration_slots', array($this, 'register_stevegpt_slots'));
    }

    // =========================================================================
    // DB INSTALL / UPGRADE
    // =========================================================================

    public function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $q   = $wpdb->prefix . self::TBL_QUESTIONS;
        $a   = $wpdb->prefix . self::TBL_ANSWERS_RAG;
        $mb  = $wpdb->prefix . self::TBL_ANSWERS_MB;
        $mbr = $wpdb->prefix . self::TBL_MB_RESULTS;
        $ws  = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        $rp  = $wpdb->prefix . self::TBL_RED_PLANS;

        dbDelta("CREATE TABLE $q (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          q_order INT NOT NULL DEFAULT 0,
          q_type ENUM('RAG','MBTI','DISC') NOT NULL DEFAULT 'RAG',
          q_text TEXT NOT NULL,
          red_label VARCHAR(16) NULL, amber_label VARCHAR(16) NULL, green_label VARCHAR(16) NULL,
          red_score INT DEFAULT 0, amber_score INT DEFAULT 0, green_score INT DEFAULT 0,
          disc_mapping JSON NULL,
          w1 TINYINT(1) DEFAULT 1, w2 TINYINT(1) DEFAULT 1, w3 TINYINT(1) DEFAULT 1,
          w4 TINYINT(1) DEFAULT 1, w5 TINYINT(1) DEFAULT 1, w6 TINYINT(1) DEFAULT 1,
          PRIMARY KEY (id), KEY idx_type (q_type), KEY idx_order (q_order)
        ) $charset;");

        dbDelta("CREATE TABLE $a (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL, week_num TINYINT NOT NULL, question_id BIGINT UNSIGNED NOT NULL,
          answer ENUM('R','A','G') NOT NULL, score INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id), KEY idx_user_week (user_id, week_num), KEY idx_user_question (user_id, question_id)
        ) $charset;");

        dbDelta("CREATE TABLE $mb (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL, week_num TINYINT NOT NULL, question_id BIGINT UNSIGNED NOT NULL,
          answer ENUM('R','A','G') NOT NULL, axis CHAR(1) NOT NULL, letter CHAR(1) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id), KEY idx_user_week (user_id, week_num)
        ) $charset;");

        dbDelta("CREATE TABLE $mbr (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL, week_num TINYINT NOT NULL, type4 CHAR(4) NOT NULL,
          details JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id), UNIQUE KEY uniq_user_week (user_id, week_num)
        ) $charset;");

        dbDelta("CREATE TABLE $ws (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL, week_num TINYINT NOT NULL,
          reds INT NOT NULL DEFAULT 0, ambers INT NOT NULL DEFAULT 0, greens INT NOT NULL DEFAULT 0,
          total_score INT NOT NULL DEFAULT 0, mbti_type CHAR(4) NULL, ai_summary LONGTEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id), UNIQUE KEY uniq_user_week (user_id, week_num), KEY idx_user (user_id)
        ) $charset;");

        dbDelta("CREATE TABLE $rp (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          week_num TINYINT NOT NULL,
          question_id BIGINT UNSIGNED NOT NULL,
          plan_text TEXT NOT NULL,
          word_count INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_user_week_q (user_id, week_num, question_id),
          KEY idx_user_q (user_id, question_id)
        ) $charset;");
    }

    public function register_commitment_source($sources) {
        $sources = is_array($sources) ? $sources : array();
        if (!isset($sources[self::SG_SOURCE])) $sources[self::SG_SOURCE] = array('label' => 'Weekly RAG', 'kind' => 'Action', 'icon' => '🚦');
        return $sources;
    }

    public function register_safeguarding_source($sources) {
        $sources = is_array($sources) ? $sources : array();
        $sources[self::SG_SOURCE] = 'Weekly RAG';
        return $sources;
    }

    /** A new or released flag for this child: every cached weekly parent summary must rebuild. */
    public function on_flag_raised($flag_id, $user_id = 0, $source = '') {
        if (!in_array((string) $source, array(self::SG_SOURCE, 'commitments'), true) || !(int) $user_id) return;
        global $wpdb; $ws = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        if ($this->has_column($ws, 'parent_ai_summary')) {
            $wpdb->query($wpdb->prepare("UPDATE $ws SET parent_ai_summary = NULL WHERE user_id = %d", (int) $user_id));
        }
    }

    private function has_column($table, $col) {
        global $wpdb;
        return (bool) $wpdb->get_row("SHOW COLUMNS FROM $table LIKE '" . esc_sql($col) . "'", ARRAY_A);
    }

    /** Runs on every request until the stored DB version matches (front end included — spec §3). */
    public function maybe_upgrade_db() {
        if (get_option('mfsd_rag_db_version', '') === self::VERSION) return;
        global $wpdb;
        $q  = $wpdb->prefix . self::TBL_QUESTIONS;
        $rp = $wpdb->prefix . self::TBL_RED_PLANS;

        if ($wpdb->get_var("SHOW TABLES LIKE '$q'") !== $q) return;

        $col = $wpdb->get_row("SHOW COLUMNS FROM $q LIKE 'q_type'", ARRAY_A);
        if ($col && strpos($col['Type'], 'DISC') === false) {
            $wpdb->query("ALTER TABLE $q MODIFY q_type ENUM('RAG','MBTI','DISC') NOT NULL DEFAULT 'RAG'");
        }
        if (!$wpdb->get_row("SHOW COLUMNS FROM $q LIKE 'disc_mapping'", ARRAY_A)) {
            $wpdb->query("ALTER TABLE $q ADD COLUMN disc_mapping JSON NULL AFTER green_score");
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '$rp'") !== $rp) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE $rp (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NOT NULL,
              week_num TINYINT NOT NULL,
              question_id BIGINT UNSIGNED NOT NULL,
              plan_text TEXT NOT NULL,
              word_count INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uniq_user_week_q (user_id, week_num, question_id),
              KEY idx_user_q (user_id, question_id)
            ) $charset;");
        }
        // 8.0.0 (spec §3) — additive only.
        $add = array(
            $rp => array('commitment_id' => 'BIGINT UNSIGNED NULL'),
            $wpdb->prefix . self::TBL_WEEK_SUMMARIES => array(
                'disc_type'                => 'VARCHAR(4) NULL',
                'steps_json'               => 'TEXT NULL',
                'short_term_plan_text'     => 'VARCHAR(140) NULL',
                'short_term_commitment_id' => 'BIGINT UNSIGNED NULL',
                'short_term_saved_at'      => 'DATETIME NULL',
                'parent_ai_summary'        => 'LONGTEXT NULL',
            ),
        );
        foreach ($add as $table => $cols) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) continue;
            foreach ($cols as $col => $def) {
                if (!$this->has_column($table, $col)) $wpdb->query("ALTER TABLE $table ADD COLUMN $col $def");
            }
        }
        update_option('mfsd_rag_db_version', self::VERSION);
    }

    // =========================================================================
    // AGE HELPERS
    // =========================================================================

    /**
     * Get a user's age from their ProfilePress DOB field (pp_dob).
     * Returns age in years as int, or null if not set / unparseable.
     */
    private function get_user_age($user_id) {
        $dob = get_user_meta($user_id, 'pp_dob', true);
        if (!$dob) return null;
        try {
            $birth_date = new DateTime($dob);
            $today      = new DateTime('today');
            return (int)$birth_date->diff($today)->y;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Returns a human-readable age string for use in AI prompts.
     * e.g. "aged 13" or falls back to "aged 10-14" if DOB not set.
     */
    private function get_age_description($user_id) {
        $age = $this->get_user_age($user_id);
        return $age ? "aged $age" : "aged 10-14";
    }

    /**
     * Word target for Red plans — respects admin setting and real age where available.
     */
    private function get_red_plan_word_target($user_id) {
        $mode = get_option('mfsd_rag_red_plan_mode', 'fixed-50');
        if ($mode === 'fixed-100') return 100;
        if ($mode === 'age-specific') {
            $age = $this->get_user_age($user_id);
            if ($age && $age >= 13) return 100;
            return 50;
        }
        return 50; // fixed-50 default
    }

    // =========================================================================
    // ASSETS / SHORTCODE
    // =========================================================================

    public function assets() {
        $h    = 'mfsd-weekly-rag';
        $base = plugin_dir_url(__FILE__);
        wp_register_script($h, $base . 'assets/mfsd-weekly-rag.js', array('wp-element'), self::VERSION, true);
        wp_register_style($h,  $base . 'assets/mfsd-weekly-rag.css', array(), self::VERSION);
    }

    public function shortcode($atts) {
        $week = 1;
        if (is_page()) {
            $title = get_the_title();
            if (preg_match('/Week\s*([1-6])\s*RAG/i', $title, $m)) $week = (int)$m[1];
        }

        // D9 (Mark): like every task, the RAG takes no free text while mfsd-safeguarding is inactive.
        if (is_user_logged_in() && !function_exists('mfsd_safeguarding_check') && !MFSD_Parent_Tabs::is_parent_view()) {
            return '<p style="text-align:center;padding:40px;color:#555;">Steve\'s having a quick break. Your answers are saved, so try again in a few minutes.</p>';
        }

        // ── Parent view (Course Progress) — skips the ordering gate, never writes (spec §10) ──
        if (is_user_logged_in() && MFSD_Parent_Tabs::is_parent_view()) {
            return $this->render_parent_view($week);
        }

        // ── Ordering gate ──────────────────────────────────────────────────
        if ( function_exists( 'mfsd_get_task_status' ) && get_option( 'mfsd_rag_course_management', '1' ) === '1' ) {
            $student_id = get_current_user_id();
            $task_slug  = 'rag_week_' . $week;
            $status     = mfsd_get_task_status( $student_id, $task_slug );

            if ( $status === 'locked' ) {
                if ( function_exists( 'mfsd_ordering_locked_message' ) ) {
                    return mfsd_ordering_locked_message( $task_slug );
                }
                return '<p style="text-align:center;padding:40px;color:#555;">This activity is not available yet. Please complete the previous activity first.</p>';
            }
            // 'available', 'in_progress', 'completed' all fall through.
            // in_progress is set on first answer (api_answer), not on page load,
            // because simply viewing the RAG is not meaningful as a started signal.
        }
        // ── End ordering gate ──────────────────────────────────────────────

        $commitments_on = $this->commitments_active();
        if ($commitments_on && function_exists('mfsd_commitments_enqueue_modal')) mfsd_commitments_enqueue_modal();
        wp_localize_script('mfsd-weekly-rag', 'MFSD_RAG_CFG', array(
            'restUrlQuestions'      => esc_url_raw(rest_url('mfsd-rag/v1/questions')),
            'restUrlAnswer'         => esc_url_raw(rest_url('mfsd-rag/v1/answer')),
            'restUrlSummary'        => esc_url_raw(rest_url('mfsd-rag/v1/summary')),
            'restUrlStatus'         => esc_url_raw(rest_url('mfsd-rag/v1/status')),
            'restUrlPrevious'       => esc_url_raw(rest_url('mfsd-rag/v1/previous-answer')),
            'restUrlGuidance'       => esc_url_raw(rest_url('mfsd-rag/v1/question-guidance')),
            'restUrlAllWeeks'       => esc_url_raw(rest_url('mfsd-rag/v1/all-weeks-summary')),
            'restUrlQuestionChat'   => esc_url_raw(rest_url('mfsd-rag/v1/question-chat')),
            'restUrlRedSuggestions' => esc_url_raw(rest_url('mfsd-rag/v1/red-suggestions')),
            'restUrlSaveRedPlan'    => esc_url_raw(rest_url('mfsd-rag/v1/red-plan')),
            'restUrlShortTerm'      => esc_url_raw(rest_url('mfsd-rag/v1/short-term-plan')),
            'commitments'           => $commitments_on,
            'shortTermMax'          => 140,
            'nonce'                 => wp_create_nonce('wp_rest'),
            'week'                  => $week,
            'ttsVoice'              => get_option('mfsd_rag_tts_voice', ''),
            'conversationMode'      => get_option('mfsd_rag_conversation_mode', 'polite'),
            'textReveal'            => get_option('mfsd_rag_text_reveal', 'block'),
            'redPlanMode'           => get_option('mfsd_rag_red_plan_mode', 'fixed-50'),
        ));
        wp_enqueue_script('mfsd-weekly-rag');
        wp_enqueue_style('mfsd-weekly-rag');
        $chatbot_id = get_option('mfsd_stevegpt_map_rag_chat', '');
        $chat_html  = $chatbot_id ? do_shortcode('[stevegpt_chatbot id="' . esc_attr($chatbot_id) . '"]') : '';
        return '<div id="mfsd-rag-root"></div>'
             . '<div id="mfsd-rag-chat-source" style="display:none">' . $chat_html . '</div>';
    }

    // =========================================================================
    // REST ROUTES
    // =========================================================================

    public function register_routes() {
        $std = array($this, 'check_permission');
        $routes = array(
            array('questions',         'READABLE',  'api_questions'),
            array('answer',            'CREATABLE', 'api_answer'),
            array('summary',           'CREATABLE', 'api_summary'),
            array('status',            'READABLE',  'api_status'),
            array('previous-answer',   'READABLE',  'api_previous_answer'),
            array('question-guidance', 'CREATABLE', 'api_question_guidance'),
            array('all-weeks-summary', 'READABLE',  'api_all_weeks_summary'),
            array('question-chat',     'CREATABLE', 'api_question_chat'),
            array('red-suggestions',   'CREATABLE', 'api_red_suggestions'),
            array('red-plan',          'CREATABLE', 'api_save_red_plan'),
            array('short-term-plan',   'CREATABLE', 'api_short_term_plan'),
            array('parent-summary',    'READABLE',  'api_parent_summary'),
        );
        foreach ($routes as $r) {
            register_rest_route('mfsd-rag/v1', '/' . $r[0], array(
                'methods'             => constant('WP_REST_Server::' . $r[1]),
                'callback'            => array($this, $r[2]),
                'permission_callback' => $std,
            ));
        }
        register_rest_route('mfsd-rag/v1', '/admin-reset-week', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_admin_reset_week'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
        register_rest_route('mfsd-rag/v1', '/admin-reset-cm-progress', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_admin_reset_cm_progress'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
    }

    public function check_permission($request) {
        if (!is_user_logged_in()) return new WP_Error('rest_forbidden', __('You must be logged in.'), array('status' => 401));
        if (in_array($request->get_method(), array('POST','PUT','DELETE'))) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) return new WP_Error('rest_forbidden', __('Invalid security token.'), array('status' => 403));
            // D9: no free text saved while mfsd-safeguarding is inactive (same rule as every task).
            if (!function_exists('mfsd_safeguarding_check')) {
                return new WP_Error('safeguarding_unavailable', "Steve's having a quick break. Your answers are saved, so try again in a few minutes.", array('status' => 503));
            }
        }
        return true;
    }

    // =========================================================================
    // REST CALLBACKS
    // =========================================================================

    public function api_questions($req) {
        global $wpdb;
        $week  = max(1, min(6, (int)$req->get_param('week')));
        $q     = $wpdb->prefix . self::TBL_QUESTIONS;
        $wkcol = 'w' . $week;
        $rows  = $wpdb->get_results("SELECT * FROM $q WHERE $wkcol=1 ORDER BY q_type='MBTI', q_order ASC", ARRAY_A);
        $rag = $mb = array();
        foreach ($rows as $r) { if ($r['q_type'] === 'RAG') $rag[] = $r; else $mb[] = $r; }
        $out = array(); $iR = $iM = 0;
        while ($iR < count($rag) || $iM < count($mb)) {
            for ($k = 0; $k < 2 && $iR < count($rag); $k++) $out[] = $rag[$iR++];
            if ($iM < count($mb)) $out[] = $mb[$iM++];
        }
        return new WP_REST_Response(array('ok' => true, 'questions' => $out), 200);
    }

    public function api_status($req) {
        global $wpdb;
        $week    = max(1, min(6, (int)$req->get_param('week')));
        $user_id = $this->get_current_um_user_id();
        if (!$user_id) return new WP_REST_Response(array('ok'=>true,'status'=>'not_started','can_start'=>false,'message'=>'Please log in'),200);

        $can_start = true; $blocking_week = null;
        if ($week > 1) { for ($w=1;$w<$week;$w++) { if ($this->get_total_answer_count($user_id,$w) < $this->get_expected_total_count($w)) { $can_start=false; $blocking_week=$w; break; } } }

        $rag_count   = $this->get_rag_answer_count($user_id,$week);
        $mbti_count  = $this->get_mbti_answer_count($user_id,$week);
        $total_count = $rag_count + $mbti_count;
        $expected_rag   = $this->get_expected_rag_count($week);
        $expected_mbti  = $this->get_expected_mbti_count($week);
        $expected_total = $expected_rag + $expected_mbti;

        $status = 'not_started'; $last_question_id = null;
        if ($total_count >= $expected_total) { $status = 'completed'; }
        elseif ($total_count > 0) {
            $status = 'in_progress';
            $a=$wpdb->prefix.self::TBL_ANSWERS_RAG; $mb=$wpdb->prefix.self::TBL_ANSWERS_MB;
            $lr=$wpdb->get_row($wpdb->prepare("SELECT question_id,created_at FROM $a WHERE user_id=%d AND week_num=%d ORDER BY created_at DESC LIMIT 1",$user_id,$week),ARRAY_A);
            $lm=$wpdb->get_row($wpdb->prepare("SELECT question_id,created_at FROM $mb WHERE user_id=%d AND week_num=%d ORDER BY created_at DESC LIMIT 1",$user_id,$week),ARRAY_A);
            if ($lr&&$lm) $last_question_id=strtotime($lr['created_at'])>strtotime($lm['created_at'])?$lr['question_id']:$lm['question_id'];
            elseif ($lr) $last_question_id=$lr['question_id'];
            elseif ($lm) $last_question_id=$lm['question_id'];
        }

        $answered_ids = array();
        if ($total_count > 0) {
            $a=$wpdb->prefix.self::TBL_ANSWERS_RAG; $mb=$wpdb->prefix.self::TBL_ANSWERS_MB;
            $ri=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM $a WHERE user_id=%d AND week_num=%d",$user_id,$week));
            $mi=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM $mb WHERE user_id=%d AND week_num=%d",$user_id,$week));
            $answered_ids=array_map('intval',array_merge($ri?:array(),$mi?:array()));
        }

        // Red answers with no plan yet
        $pending_red_plans = array();
        if (!empty($answered_ids)) {
            $a  = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            $rp = $wpdb->prefix . self::TBL_RED_PLANS;
            $red_qids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM $a WHERE user_id=%d AND week_num=%d AND answer='R'",$user_id,$week));
            foreach ($red_qids as $qid) {
                $has_plan = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rp WHERE user_id=%d AND week_num=%d AND question_id=%d",$user_id,$week,(int)$qid));
                if (!$has_plan) $pending_red_plans[] = (int)$qid;
            }
        }

        $stage = $this->week_stage($user_id, $week);
        $previous_week_summary=null; $intro_message=null;
        if ($week>1&&$status==='not_started') {
            $pw=$week-1; $a=$wpdb->prefix.self::TBL_ANSWERS_RAG;
            $pr=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$user_id,$pw),ARRAY_A);
            $mbr=$wpdb->prefix.self::TBL_MB_RESULTS;
            $pm=$wpdb->get_var($wpdb->prepare("SELECT type4 FROM $mbr WHERE user_id=%d AND week_num=%d",$user_id,$pw));
            if ($pr&&($pr['reds']>0||$pr['ambers']>0||$pr['greens']>0)) {
                $previous_week_summary=array('week'=>$pw,'reds'=>(int)$pr['reds'],'ambers'=>(int)$pr['ambers'],'greens'=>(int)$pr['greens'],'total_score'=>(int)$pr['total_score']); // 8.0.0: no personality code to the browser
                $username = function_exists('um_get_display_name') ? um_get_display_name($user_id) : get_userdata($user_id)->display_name;
                $age_desc = $this->get_age_description($user_id);
                $intro_prompt =
                            "You are SteveGPT speaking directly to $username ($age_desc). " .
                            "Last week Week $pw: {$pr['greens']} Greens, {$pr['ambers']} Ambers, {$pr['reds']} Reds" . ($this->personality_label($pm) ? " Personality: " . $this->personality_label($pm) : "") . ". " .
                            "Write a brief warm welcome for Week $week (3-4 sentences). " .
                            "Use 'you'/'your' only. NEVER say 'your child'. " .
                            "Pitch the language and vocabulary appropriately for someone $age_desc.";
                $intro_message = $this->steve_task('week_intro', $intro_prompt, array(
                    'student_name'  => $username,
                    'age_desc'      => $age_desc,
                    'week'          => $week,
                    'previous_week' => $pw,
                    'greens'        => (int) $pr['greens'],
                    'ambers'        => (int) $pr['ambers'],
                    'reds'          => (int) $pr['reds'],
                    'personality'   => $this->personality_label($pm),
                ), $user_id, $this->sg_ctx('summary', $user_id, array('field' => 'week_intro'))) ?: null;
            }
        }

        return new WP_REST_Response(array(
            'ok'=>true,'status'=>$status,
            'rag_count'=>(int)$rag_count,'mbti_count'=>(int)$mbti_count,'total_count'=>(int)$total_count,
            'expected_rag'=>$expected_rag,'expected_mbti'=>$expected_mbti,'expected_total'=>$expected_total,
            'week'=>$week,'user_id'=>$user_id,'can_start'=>$can_start,'blocking_week'=>$blocking_week,
            'last_question_id'=>$last_question_id?(int)$last_question_id:null,
            'answered_question_ids'=>$answered_ids,
            'pending_red_plans'=>$pending_red_plans,
            'previous_week_summary'=>$previous_week_summary,
            'intro_message'=>$intro_message,
            'stage'=>$stage['stage'],
            'stage_label'=>$stage['label'],
            'short_term_plan'=>$stage['short_term_plan'],
        ),200);
    }

    /**
     * Week stage for the student, Parent Portal and Course Progress (spec §6).
     * not_started | in_progress | plan ("Choosing next week's plan") | completed.
     */
    public function week_stage($uid, $week) {
        global $wpdb;
        $uid = (int) $uid; $week = max(1, min(6, (int) $week));
        $ws  = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ws WHERE user_id=%d AND week_num=%d", $uid, $week), ARRAY_A);
        $plan = ($row && !empty($row['short_term_saved_at'])) ? (string) $row['short_term_plan_text'] : null;
        if ($plan !== null) {
            $stage = 'completed';
        } elseif ($row && !empty($row['ai_summary']) && !array_key_exists('steps_json', $row)) {
            $stage = 'completed';                       // schema not upgraded yet: behave as 7.4.0
        } elseif ($row && !empty($row['ai_summary']) && $row['steps_json'] === null) {
            $stage = 'completed';                       // legacy: summary saved under 7.4.0, already completed
        } else {
            $count = $this->get_rag_answer_count($uid, $week) + $this->get_mbti_answer_count($uid, $week);
            $expected = $this->get_expected_total_count($week);
            if ($count === 0)                        $stage = 'not_started';
            elseif ($expected > 0 && $count >= $expected) $stage = 'plan';
            else                                     $stage = 'in_progress';
        }
        return array('stage' => $stage, 'label' => self::STAGE_LABELS[$stage], 'short_term_plan' => $plan);
    }

    private function get_rag_answer_count($u,$w)  { global $wpdb; $t=$wpdb->prefix.self::TBL_ANSWERS_RAG;  return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id=%d AND week_num=%d",$u,$w)); }
    private function get_mbti_answer_count($u,$w) { global $wpdb; $t=$wpdb->prefix.self::TBL_ANSWERS_MB;   return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id=%d AND week_num=%d",$u,$w)); }
    private function get_disc_answer_count($u,$w) { global $wpdb; $t=$wpdb->prefix.self::TBL_ANSWERS_DISC;  return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id=%d AND week_num=%d",$u,$w)); }
    private function get_total_answer_count($u,$w){ return $this->get_rag_answer_count($u,$w)+$this->get_mbti_answer_count($u,$w)+$this->get_disc_answer_count($u,$w); }
    private function get_expected_rag_count($w)   { global $wpdb; $q=$wpdb->prefix.self::TBL_QUESTIONS; return (int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE w$w=1 AND q_type='RAG'"); }
    private function get_expected_mbti_count($w)  { global $wpdb; $q=$wpdb->prefix.self::TBL_QUESTIONS; return (int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE w$w=1 AND q_type='MBTI'"); }
    private function get_expected_disc_count($w)  { global $wpdb; $q=$wpdb->prefix.self::TBL_QUESTIONS; return (int)$wpdb->get_var("SELECT COUNT(*) FROM $q WHERE w$w=1 AND q_type='DISC'"); }
    private function get_expected_total_count($w) { return $this->get_expected_rag_count($w)+$this->get_expected_mbti_count($w); }

    public function api_previous_answer($req) {
        global $wpdb;
        $week=(int)$req->get_param('week'); $qid=(int)$req->get_param('question_id'); $uid=$this->get_current_um_user_id();
        if (!$uid||!$qid||$week<=1) return new WP_REST_Response(array('ok'=>true,'previous'=>array()),200);
        $qt=$wpdb->prefix.self::TBL_QUESTIONS; $q=$wpdb->get_row($wpdb->prepare("SELECT q_type FROM $qt WHERE id=%d",$qid),ARRAY_A);
        if (!$q) return new WP_REST_Response(array('ok'=>true,'previous'=>array()),200);
        $t=($q['q_type']==='RAG')?$wpdb->prefix.self::TBL_ANSWERS_RAG:$wpdb->prefix.self::TBL_ANSWERS_MB;
        $prev=$wpdb->get_results($wpdb->prepare("SELECT week_num,answer FROM $t WHERE user_id=%d AND question_id=%d AND week_num<%d GROUP BY week_num ORDER BY week_num ASC",$uid,$qid,$week),ARRAY_A);
        return new WP_REST_Response(array('ok'=>true,'previous'=>$prev),200);
    }

    public function api_question_guidance($req) {
        global $wpdb;
        $week=(int)$req->get_param('week'); $qid=(int)$req->get_param('question_id'); $uid=$this->get_current_um_user_id();
        if (!$uid||!$qid) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid request'),400);
        $qt=$wpdb->prefix.self::TBL_QUESTIONS; $question=$wpdb->get_row($wpdb->prepare("SELECT * FROM $qt WHERE id=%d",$qid),ARRAY_A);
        if (!$question) return new WP_REST_Response(array('ok'=>false,'error'=>'Question not found'),404);

        $previous=array();
        if ($week>1&&$question['q_type']==='RAG') {
            $a=$wpdb->prefix.self::TBL_ANSWERS_RAG;
            $previous=$wpdb->get_results($wpdb->prepare("SELECT week_num,answer FROM $a WHERE user_id=%d AND question_id=%d AND week_num<%d GROUP BY week_num ORDER BY week_num ASC",$uid,$qid,$week),ARRAY_A);
        }

        $prev_red_plan = null;
        if ($week > 1) {
            $rp = $wpdb->prefix . self::TBL_RED_PLANS;
            $prev_red_plan = $wpdb->get_row($wpdb->prepare("SELECT week_num,plan_text FROM $rp WHERE user_id=%d AND question_id=%d AND week_num=%d",$uid,$qid,$week-1),ARRAY_A);
        }

        $guidance='';
        $username = function_exists('um_get_display_name') ? um_get_display_name($uid) : get_userdata($uid)->display_name;
        $age_desc = $this->get_age_description($uid);

        if ($question['q_type']==='MBTI') {
            $prompt  = "You are SteveGPT, a supportive AI coach speaking DIRECTLY TO $username ($age_desc) completing their own personality assessment.\n\n";
            $prompt .= "CRITICAL: Address $username as 'you'/'your' only. NEVER say 'your child' or third person.\n";
            $prompt .= "Pitch language and vocabulary appropriately for someone $age_desc.\n\n";
            $prompt .= "Question: \"{$question['q_text']}\"\n\n";
            $prompt .= "Write 2-3 sentences explaining what this personality question explores and how to answer (Red=doesn't describe you, Amber=sometimes, Green=describes you well). Remind them there are no right or wrong answers.";
        } else {
            $prompt  = "You are SteveGPT, a supportive AI coach speaking DIRECTLY TO $username ($age_desc) completing their own self-assessment.\n\n";
            $prompt .= "CRITICAL RULES:\n";
            $prompt .= "- Address $username as 'you'/'your' only. NEVER say 'your child' or use third person.\n";
            $prompt .= "- Pitch all language and vocabulary appropriately for someone $age_desc.\n\n";
            $prompt .= "Question: \"{$question['q_text']}\"\n\n";
            $prompt .= "Write 3-4 sentences explaining what to reflect on and how to answer (Red=struggling, Amber=mixed, Green=confident).";
            if (!empty($previous)) {
                $prompt .= "\nPrevious answers: ";
                foreach ($previous as $ans) $prompt .= "Week{$ans['week_num']}:".($ans['answer']==='R'?'Red':($ans['answer']==='A'?'Amber':'Green'))." ";
                $prompt .= "\nAcknowledge their progress, speaking directly to them.";
            }
            if ($prev_red_plan) {
                $prompt .= "\n\nIMPORTANT: Last week (Week {$prev_red_plan['week_num']}), $username made this plan to improve: \"{$prev_red_plan['plan_text']}\". Acknowledge this plan warmly — how did they get on?";
            }
        }
        $prev_text = '';
        foreach ((array) $previous as $ans) $prev_text .= "Week{$ans['week_num']}:".($ans['answer']==='R'?'Red':($ans['answer']==='A'?'Amber':'Green'))." ";
        $guidance = $this->steve_task('guidance', $prompt, array(
            'student_name'      => $username,
            'age_desc'          => $age_desc,
            'question_text'     => $question['q_text'],
            'question_type'     => $question['q_type'],
            'previous_answers'  => trim($prev_text),
            'previous_red_plan' => $prev_red_plan ? $prev_red_plan['plan_text'] : '',
        ), $uid, $this->sg_ctx('item', $uid, array('item' => $question['q_text'], 'field' => 'guidance', 'source_ref' => 'rag:' . (int) $week . ':q' . (int) $qid)));

        return new WP_REST_Response(array('ok'=>true,'guidance'=>$guidance,'question'=>$question['q_text'],'type'=>$question['q_type']),200);
    }

    public function api_all_weeks_summary($req) {
        global $wpdb; $uid=$this->get_current_um_user_id();
        if (!$uid) return new WP_REST_Response(array('ok'=>false,'error'=>'Not logged in'),403);
        $all=array(); $a=$wpdb->prefix.self::TBL_ANSWERS_RAG; $mbr=$wpdb->prefix.self::TBL_MB_RESULTS; $ws=$wpdb->prefix.self::TBL_WEEK_SUMMARIES;
        for ($w=1;$w<=6;$w++) {
            $exists=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ws WHERE user_id=%d AND week_num=%d",$uid,$w));
            if ($exists>0) { $rs=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$uid,$w),ARRAY_A); $all[$w]=array('week'=>$w,'rag'=>$rs,'completed'=>true); } // 8.0.0: no personality code to the browser
            else $all[$w]=array('week'=>$w,'completed'=>false);
        }
        return new WP_REST_Response(array('ok'=>true,'weeks'=>$all),200);
    }

    public function api_answer($request) {
        $uid=(int)get_current_user_id(); $wn=(int)$request->get_param('week'); $qid=(int)$request->get_param('question_id');
        if (!$wn||!$qid) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid data'),400);
        global $wpdb; $tq=$wpdb->prefix.self::TBL_QUESTIONS; $q=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tq WHERE id=%d",$qid),ARRAY_A);
        if (!$q) return new WP_REST_Response(array('ok'=>false,'error'=>'Question not found'),404);
        $inserted=false;
        if ($q['q_type']==='RAG') {
            $ans=strtoupper(sanitize_text_field($request->get_param('rag'))); if (!in_array($ans,array('R','A','G'))) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid answer'),400);
            $score=0; if ($ans==='R') $score=(int)$q['red_score']; elseif($ans==='A') $score=(int)$q['amber_score']; else $score=(int)$q['green_score'];
            $t=$wpdb->prefix.self::TBL_ANSWERS_RAG; $inserted=$wpdb->insert($t,array('user_id'=>$uid,'week_num'=>$wn,'question_id'=>$qid,'answer'=>$ans,'score'=>$score,'created_at'=>current_time('mysql')),array('%d','%d','%d','%s','%d','%s'));
        } elseif ($q['q_type']==='MBTI') {
            $ans=strtoupper(sanitize_text_field($request->get_param('rag'))); if (!in_array($ans,array('R','A','G'))) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid answer'),400);
            $md=$this->mbti_letter_for($qid,$ans); $t=$wpdb->prefix.self::TBL_ANSWERS_MB;
            $inserted=$wpdb->insert($t,array('user_id'=>$uid,'week_num'=>$wn,'question_id'=>$qid,'answer'=>$ans,'axis'=>$md[0],'letter'=>$md[1],'created_at'=>current_time('mysql')),array('%d','%d','%d','%s','%s','%s','%s'));
        } elseif ($q['q_type']==='DISC') {
            $da=(int)$request->get_param('disc_answer'); if ($da<1||$da>5) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid DISC answer'),400);
            $mapping=json_decode($q['disc_mapping'],true); if (!$mapping) return new WP_REST_Response(array('ok'=>false,'error'=>'DISC mapping missing'),400);
            $c=$da-3; $t=$wpdb->prefix.self::TBL_ANSWERS_DISC;
            $inserted=$wpdb->insert($t,array('user_id'=>$uid,'week_num'=>$wn,'question_id'=>$qid,'answer'=>$da,'d_contribution'=>$mapping['D']*$c,'i_contribution'=>$mapping['I']*$c,'s_contribution'=>$mapping['S']*$c,'c_contribution'=>$mapping['C']*$c,'created_at'=>current_time('mysql')),array('%d','%d','%d','%d','%d','%d','%d','%d','%s'));
        }
        if (false===$inserted) return new WP_REST_Response(array('ok'=>false,'error'=>'DB error: '.$wpdb->last_error),500);

        // ── Ordering: mark in_progress on first answer ─────────────────────
        if ( function_exists( 'mfsd_set_task_status' ) && get_option( 'mfsd_rag_course_management', '1' ) === '1' ) {
            $task_slug = 'rag_week_' . $wn;
            $current   = mfsd_get_task_status( $uid, $task_slug );
            if ( $current === 'available' ) {
                mfsd_set_task_status( $uid, $task_slug, 'in_progress' );
            }
        }
        // ──────────────────────────────────────────────────────────────────

        return new WP_REST_Response(array('ok'=>true,'message'=>'Saved','answer_id'=>$wpdb->insert_id),200);
    }

    public function api_red_suggestions($req) {
        global $wpdb;
        $week        = max(1, min(6, (int)$req->get_param('week')));
        $question_id = (int)$req->get_param('question_id');
        $user_id     = $this->get_current_um_user_id();
        if (!$user_id||!$question_id) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid request'),400);
        $qt=$wpdb->prefix.self::TBL_QUESTIONS; $question=$wpdb->get_row($wpdb->prepare("SELECT * FROM $qt WHERE id=%d",$question_id),ARRAY_A);
        if (!$question) return new WP_REST_Response(array('ok'=>false,'error'=>'Question not found'),404);

        $rp         = $wpdb->prefix.self::TBL_RED_PLANS;
        $prev_plans = $wpdb->get_results($wpdb->prepare("SELECT week_num,plan_text FROM $rp WHERE user_id=%d AND question_id=%d AND week_num<%d ORDER BY week_num DESC LIMIT 3",$user_id,$question_id,$week),ARRAY_A);

        $prev_answer = null;
        if ($week > 1) {
            $a = $wpdb->prefix.self::TBL_ANSWERS_RAG;
            $prev_answer = $wpdb->get_var($wpdb->prepare("SELECT answer FROM $a WHERE user_id=%d AND question_id=%d AND week_num=%d LIMIT 1",$user_id,$question_id,$week-1));
        }

        $word_target = $this->get_red_plan_word_target($user_id);
        $steve_intro = ''; $suggestions = array();

        $username = function_exists('um_get_display_name') ? um_get_display_name($user_id) : get_userdata($user_id)->display_name;
        $age_desc = $this->get_age_description($user_id);

        $prompt  = "You are SteveGPT, a supportive AI coach for $username ($age_desc) on their High Performance Pathway.\n";
        $prompt .= "Pitch all language and vocabulary appropriately for someone $age_desc.\n";
        $prompt .= "Address $username as 'you'. No asterisks or markdown.\n\n";
        $prompt .= "They just answered RED to: \"{$question['q_text']}\"\n\n";

        $last_plan = !empty($prev_plans) ? $prev_plans[0] : null;
        if ($last_plan && $prev_answer === 'R') {
            $prompt .= "CONTEXT: Last week (Week {$last_plan['week_num']}) they made this plan:\n\"{$last_plan['plan_text']}\"\nHowever they have answered Red again. Write a warm intro (2-3 sentences) acknowledging they tried, gently asking what got in the way, and setting up a better plan this time.\n\n";
        } elseif ($last_plan && $prev_answer !== 'R') {
            $prompt .= "CONTEXT: They had improved previously but returned to Red. Write a warm intro (2-3 sentences) acknowledging this can happen and encouraging them to make a plan.\n\n";
        } else {
            $prompt .= "Write a warm intro (2-3 sentences): thank them for being honest, say a Red means they're self-aware, set up that you'll suggest ideas.\n\n";
        }
        $prompt .= "Then give exactly 3 practical suggestions to help them improve by next week.\n";
        $prompt .= "Format EXACTLY as:\nINTRO: [intro text]\nSUGGESTION_1: [suggestion]\nSUGGESTION_2: [suggestion]\nSUGGESTION_3: [suggestion]\n\n";
        $prompt .= "Each suggestion 1-2 sentences, practical, age-appropriate for someone $age_desc.";

        $last_plan_text = $last_plan ? $last_plan['plan_text'] : '';
        $response = $this->steve_task('red_suggestions', $prompt, array(
            'student_name'   => $username,
            'age_desc'       => $age_desc,
            'question_text'  => $question['q_text'],
            'last_plan'      => $last_plan_text,
            'previous_answer'=> (string) $prev_answer,
        ), $user_id, $this->sg_ctx('suggestion', $user_id, array('item' => $question['q_text'], 'field' => 'red_suggestions', 'source_ref' => 'rag:' . $week . ':q' . $question_id)));
        foreach (explode("\n",$response) as $line) {
            $line = trim($line);
            if (strpos($line,'INTRO:')===0) $steve_intro = trim(substr($line,6));
            elseif (preg_match('/^SUGGESTION_\d+:\s*(.+)/',$line,$m)) $suggestions[] = trim($m[1]);
        }
        if (!$steve_intro) $steve_intro = "Thanks for being honest — a Red means you know where to grow! Let's make a plan together.";
        if (empty($suggestions)) $suggestions = array("Try practising this skill for just 5 minutes each day this week.","Talk to someone you trust about this.","Set one specific goal you can achieve by next week.");

        return new WP_REST_Response(array('ok'=>true,'steve_intro'=>$steve_intro,'suggestions'=>$suggestions,'prev_plans'=>$prev_plans,'prev_answer'=>$prev_answer,'word_target'=>$word_target,'question'=>$question['q_text']),200);
    }

    public function api_save_red_plan($req) {
        global $wpdb;
        $week        = max(1, min(6, (int)$req->get_param('week')));
        $question_id = (int)$req->get_param('question_id');
        $plan_text   = sanitize_textarea_field($req->get_param('plan_text'));
        $user_id     = $this->get_current_um_user_id();
        if (!$user_id||!$question_id||!$plan_text) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid request'),400);
        $word_count = self::word_count(strip_tags($plan_text));
        $rp=$wpdb->prefix.self::TBL_RED_PLANS;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $rp WHERE user_id=%d AND week_num=%d AND question_id=%d", $user_id, $week, $question_id));
        if ($existing) $wpdb->update($rp, array('plan_text'=>$plan_text,'word_count'=>$word_count), array('id'=>(int)$existing));
        else $wpdb->insert($rp, array('user_id'=>$user_id,'week_num'=>$week,'question_id'=>$question_id,'plan_text'=>$plan_text,'word_count'=>$word_count), array('%d','%d','%d','%s','%d'));

        $ref = 'rag:' . $week . ':q' . $question_id;
        $qt  = $wpdb->prefix . self::TBL_QUESTIONS;
        $q_text = (string) $wpdb->get_var($wpdb->prepare("SELECT q_text FROM $qt WHERE id=%d", $question_id));

        // Safeguarding keyword check on save (Prompt Layer §7; spec §8.1). The child sees no difference.
        // D4 (Mark's decision 2): when mfsd-commitments is active it checks the card text itself
        // (source 'commitments'); the RAG only checks the plan when commitments is off. One flag, not two.
        if (function_exists('mfsd_safeguarding_check') && !$this->commitments_active()) {
            mfsd_safeguarding_check($user_id, self::SG_SOURCE, 'Week ' . $week . ' Red Plan: ' . $this->short($q_text, 120), $plan_text,
                array('field' => 'red_plan', 'source_ref' => $ref));
        }

        // Commitment card (Commitments §4.2 / §13.1). Never blocks the save.
        $commitment_id = null;
        if (function_exists('mfsd_create_commitment')) {
            $cid = mfsd_create_commitment($user_id, array(
                'type'        => 'action',
                'source_task' => self::SG_SOURCE,
                'source_ref'  => $ref,
                'title'       => $this->red_plan_title($plan_text, $q_text, $user_id, $ref),
                'detail'      => $plan_text,
            ));
            if (is_wp_error($cid)) {
                error_log('[MFSD RAG] commitment create failed (' . $ref . '): ' . $cid->get_error_message());
                // No card means Commitments never checked the words, so check them here (review).
                if (function_exists('mfsd_safeguarding_check')) {
                    mfsd_safeguarding_check($user_id, self::SG_SOURCE, 'Week ' . $week . ' Red Plan: ' . $this->short($q_text, 120), $plan_text,
                        array('field' => 'red_plan', 'source_ref' => $ref));
                }
            } elseif ($cid) {
                $commitment_id = (int) $cid;
                $wpdb->update($rp, array('commitment_id' => $commitment_id), array('user_id'=>$user_id,'week_num'=>$week,'question_id'=>$question_id));
            }
        }
        $this->clear_parent_summary($user_id, $week);
        return new WP_REST_Response(array('ok'=>true,'word_count'=>$word_count,'commitment_id'=>$commitment_id),200);
    }

    /** Card title (spec §4): first sentence ≤140 chars → red_plan_title slot → first 137 chars + "…". */
    private function red_plan_title($plan, $q_text, $uid, $ref) {
        $plan  = trim(preg_replace('/\s+/', ' ', (string) $plan));
        $first = preg_split('/(?<=[.!?])\s+/', $plan, 2)[0];
        if ($first !== '' && mb_strlen($first) <= 140) return $first;
        $t = $this->steve_task('red_plan_title',
            "Turn this student's plan into a short card title they will recognise as their own words: max 12 words, no quotes, no emoji, starts with a verb.\nQuestion: \"$q_text\"\nPlan: \"$plan\"\nReply with the title only.",
            array('question_text' => $q_text, 'plan_text' => $plan), (int) $uid,
            $this->sg_ctx('suggestion', $uid, array('item' => 'Red Plan title', 'field' => 'red_plan_title', 'source_ref' => $ref)));
        $t = trim(str_replace(array('"', '“', '”'), '', strtok(trim($t), "\n") ?: ''));
        if ($t !== '' && mb_strlen($t) <= 140) return $t;
        return mb_substr($plan, 0, 137) . '…';
    }

    /** Words = whitespace-separated tokens, so "5 mins" and "Year 7" count (str_word_count ignores digits). Matches the JS count. */
    private static function word_count($text) {
        return count(preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY));
    }

    private function short($text, $len) {
        $text = trim((string) $text);
        return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
    }

    private function commitments_active() {
        return function_exists('mfsd_create_commitment') && function_exists('mfsd_get_open_commitments');
    }

    private function clear_parent_summary($uid, $week) {
        global $wpdb; $ws = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        if ($this->has_column($ws, 'parent_ai_summary')) {
            $wpdb->query($wpdb->prepare("UPDATE $ws SET parent_ai_summary = NULL WHERE user_id=%d AND week_num=%d", (int) $uid, (int) $week));
        }
    }

    /** Safeguarding context for SteveGPT_Chatbot::query() (Prompt Layer §3.2). user_id is always the CHILD. */
    private function sg_ctx($kind, $child_id, array $extra = array()) {
        return array_merge(array('kind' => $kind, 'source_task' => self::SG_SOURCE, 'user_id' => (int) $child_id), $extra);
    }

    public function api_question_chat($req) {
        global $wpdb;
        $week            = max(1, min(6, (int)$req->get_param('week')));
        $question_id     = (int)$req->get_param('question_id');
        $msg             = sanitize_text_field($req->get_param('message'));
        $is_red_followup = (bool)$req->get_param('is_red_followup');
        $uid             = $this->get_current_um_user_id();
        if (!$uid||!$question_id||!$msg) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid request'),400);
        $qt=$wpdb->prefix.self::TBL_QUESTIONS; $q=$wpdb->get_row($wpdb->prepare("SELECT * FROM $qt WHERE id=%d",$question_id),ARRAY_A);
        if (!$q) return new WP_REST_Response(array('ok'=>false,'error'=>'Question not found'),404);
        $prev=array(); if($week>1&&$q['q_type']==='RAG'){$a=$wpdb->prefix.self::TBL_ANSWERS_RAG;$prev=$wpdb->get_results($wpdb->prepare("SELECT week_num,answer FROM $a WHERE user_id=%d AND question_id=%d AND week_num<%d ORDER BY week_num ASC",$uid,$question_id,$week),ARRAY_A);}

        $resp='';
        $username = function_exists('um_get_display_name') ? um_get_display_name($uid) : get_userdata($uid)->display_name;
        $age_desc = $this->get_age_description($uid);

        if ($is_red_followup) {
            $p  = "You are SteveGPT, a supportive AI coach for $username ($age_desc).\n";
            $p .= "They answered RED to: \"{$q['q_text']}\"\n";
            $p .= "They are working on a plan to improve to Amber. Give practical, encouraging suggestions.\n";
            $p .= "Address $username as 'you'. NEVER say 'your child'. Pitch language for someone $age_desc. 2-3 sentences.\n\nStudent: $msg";
        } else {
            $p  = "You are SteveGPT, a supportive AI coach speaking DIRECTLY TO $username ($age_desc), Week $week, High Performance Pathway.\n";
            $p .= "CRITICAL: Address $username as 'you'/'your'. NEVER say 'your child'. Pitch language for someone $age_desc.\n";
            $p .= "Question: \"{$q['q_text']}\"\n";
            if ($q['q_type']==='MBTI') $p .= "Personality question (Red=doesn't describe you, Amber=sometimes, Green=describes you well).\n";
            else { $p.="RAG question (Red=struggling, Amber=mixed, Green=confident).\n"; if(!empty($prev)){$p.="Previous: ";foreach($prev as $pa)$p.="W{$pa['week_num']}:".($pa['answer']==='R'?'R':($pa['answer']==='A'?'A':'G'))." ";} }
            $p .= "2-3 sentences, warm.\n\nStudent: $msg";
        }
        $prev_text = '';
        foreach ((array) $prev as $pa) $prev_text .= "W{$pa['week_num']}:{$pa['answer']} ";
        $resp = $this->steve_task($is_red_followup ? 'red_chat' : 'question_chat', $p, array(
            'student_name'     => $username,
            'age_desc'         => $age_desc,
            'week'             => $week,
            'question_text'    => $q['q_text'],
            'question_type'    => $q['q_type'],
            'previous_answers' => trim($prev_text),
            'message'          => $msg,
        ), $uid, $this->sg_ctx('chat', $uid, array('item' => $q['q_text'], 'item_text' => $msg, 'field' => $is_red_followup ? 'red_chat' : 'question_chat', 'source_ref' => 'rag:' . $week . ':q' . $question_id)));
        if ($resp === '') $resp = "I'm having trouble connecting right now. Please try again.";

        return new WP_REST_Response(array('ok'=>true,'response'=>$resp),200);
    }

    public function api_summary($req) {
        global $wpdb;
        $week=max(1,min(6,(int)$req->get_param('week'))); $uid=$this->get_current_um_user_id();
        if (!$uid) return new WP_REST_Response(array('ok'=>false,'error'=>'Not logged in'),403);

        $ws=$wpdb->prefix.self::TBL_WEEK_SUMMARIES; $cached=$wpdb->get_row($wpdb->prepare("SELECT * FROM $ws WHERE user_id=%d AND week_num=%d",$uid,$week),ARRAY_A); $use_cache=(get_option('mfsd_rag_cache_summaries','1')==='1');
        $a=$wpdb->prefix.self::TBL_ANSWERS_RAG;
        $pw=array(); if($week>1){for($w=1;$w<$week;$w++){$pr=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$uid,$w),ARRAY_A);$pm=$wpdb->get_var($wpdb->prepare("SELECT type4 FROM {$wpdb->prefix}mfsd_mbti_results WHERE user_id=%d AND week_num=%d",$uid,$w));if($pr&&($pr['reds']>0||$pr['ambers']>0||$pr['greens']>0))$pw[]=array('week'=>$w,'rag'=>$pr,'mbti'=>$pm);}}
        // 8.0.0: personality codes never reach the browser.
        $pw_out = array_map(function ($x) { unset($x['mbti']); return $x; }, $pw);

        if ($use_cache&&$cached&&!empty($cached['ai_summary'])) {
            return new WP_REST_Response(array('ok'=>true,'week'=>$week,'rag'=>array('reds'=>(int)$cached['reds'],'ambers'=>(int)$cached['ambers'],'greens'=>(int)$cached['greens'],'total_score'=>(int)$cached['total_score']),'ai'=>$cached['ai_summary'],'previous_weeks'=>$pw_out,'cached'=>true) + $this->plan_payload($uid,$week,$cached),200);
        }

        $agg=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$uid,$week),ARRAY_A);
        if (!$agg) $agg=array('reds'=>0,'ambers'=>0,'greens'=>0,'total_score'=>0);
        $mb=$wpdb->prefix.self::TBL_ANSWERS_MB; $letters=$wpdb->get_results($wpdb->prepare("SELECT axis,letter,COUNT(*) c FROM $mb WHERE user_id=%d AND week_num=%d GROUP BY axis,letter",$uid,$week),ARRAY_A); $type=$this->mbti_type_from_counts($letters);
        if ($type){$mbr=$wpdb->prefix.self::TBL_MB_RESULTS;$wpdb->replace($mbr,array('user_id'=>$uid,'week_num'=>$week,'type4'=>$type,'details'=>wp_json_encode($letters)),array('%d','%d','%s','%s'));}
        $disc_type=$disc_scores=null; if($this->get_expected_disc_count($week)>0){$dr=$this->calculate_disc_results($uid,$week);if($dr){$disc_type=$dr['disc_type'];$disc_scores=$dr['disc_scores'];}}
        $mtu=$type; if(!$mtu&&!empty($pw)){foreach(array_reverse($pw) as $p){if(!empty($p['mbti'])){$mtu=$p['mbti'];break;}}}
        $djr=$this->dream_jobs($uid);

        // Red plan follow-up context (8.0.0: with the card's status when there is one)
        $plan_context = '';
        if ($week > 1) {
            $rp=$wpdb->prefix.self::TBL_RED_PLANS;
            $prev_week_plans=$wpdb->get_results($wpdb->prepare("SELECT rp.*,q.q_text FROM $rp rp JOIN {$wpdb->prefix}".self::TBL_QUESTIONS." q ON q.id=rp.question_id WHERE rp.user_id=%d AND rp.week_num=%d",$uid,$week-1),ARRAY_A);
            if (!empty($prev_week_plans)) {
                $plan_context="\n===RED PLAN FOLLOW-UP===\nLast week the student made improvement plans for Red questions:\n\n";
                foreach ($prev_week_plans as $plan) {
                    $twa=$wpdb->get_var($wpdb->prepare("SELECT answer FROM $a WHERE user_id=%d AND question_id=%d AND week_num=%d LIMIT 1",$uid,$plan['question_id'],$week));
                    $outcome='not yet answered';
                    if ($twa==='G') $outcome='improved to GREEN — great success!';
                    elseif ($twa==='A') $outcome='improved to AMBER — good progress!';
                    elseif ($twa==='R') $outcome='still Red — plan needs revisiting';
                    $card_line = $this->card_status_line(isset($plan['commitment_id']) ? (int)$plan['commitment_id'] : 0, $uid);
                    $plan_context.="Question: \"{$plan['q_text']}\"\nPlan: \"{$plan['plan_text']}\"\nThis week: $outcome\n".($card_line!==''?"Card: $card_line\n":'')."\n";
                }
                $plan_context.="Celebrate Red→Green warmly, for Red→Amber ask what worked, for Red→Red ask what got in the way and encourage them.\n";
            }
        }
        $commitments_block = $this->commitments_block($uid, $week, false);

        $aiIntro='';
        $username = function_exists('um_get_display_name') ? um_get_display_name($uid) : get_userdata($uid)->display_name;
        $age_desc = $this->get_age_description($uid);

        $p  = "You are SteveGPT, a supportive coach speaking DIRECTLY TO $username ($age_desc) about their High Performance Pathway.\n";
        $p .= "CRITICAL: Address $username as 'you'/'your'. NEVER say 'your child'. Pitch all language for someone $age_desc.\n\n";
        $p .= "WEEK $week RESULTS:\nRAG: {$agg['reds']}R {$agg['ambers']}A {$agg['greens']}G (Score:{$agg['total_score']})\n";
        $personality_label = $this->personality_label($mtu);
        $comm_style        = $this->communication_style_label($disc_type);
        if ($personality_label) $p .= "Personality type: $personality_label" . ($type ? " (this week)" : " (previous weeks)") . "\n";
        if ($comm_style) $p .= "Communication style: $comm_style\n";
        if (!empty($pw)){$p.="\nPROGRESS:\n";foreach($pw as $wpw)$p.="Week{$wpw['week']}: {$wpw['rag']['reds']}R/{$wpw['rag']['ambers']}A/{$wpw['rag']['greens']}G".($this->personality_label($wpw['mbti']) ? " Personality: " . $this->personality_label($wpw['mbti']) : "")."\n";}
        if (!empty($djr)){$p.="\nDREAM JOBS:\n";foreach(array_slice($djr,0,5) as $i=>$j)$p.=($i+1).". $j\n";}
        $p .= $plan_context;
        $p .= $commitments_block;
        $p .= "Write a warm, insightful summary: celebrate strengths, note progress, reflect on their personality type and communication style (by name only), acknowledge development areas, give 2-3 actionable steps.\n";
        $p .= "UK context. Bullet points. Apply Steve's Solutions Mindset: No Failure only Feedback; Smooth sea never made a skilled sailor; You never lose you win or learn.\n";
        $aiIntro = $this->steve_task('summary', $p, array(
            'student_name' => $username,
            'age_desc'     => $age_desc,
            'week'         => $week,
            'reds'         => (int) $agg['reds'],
            'ambers'       => (int) $agg['ambers'],
            'greens'       => (int) $agg['greens'],
            'total_score'  => (int) $agg['total_score'],
            'personality'         => $personality_label,
            'communication_style' => $comm_style,
            'dream_jobs'   => !empty($djr) ? implode(', ', array_slice($djr, 0, 5)) : '',
            'plan_context' => $plan_context,
            'commitments'  => $commitments_block,
        ), $uid, $this->sg_ctx('summary', $uid, array('field' => 'week_summary')), self::STEP_RULE);

        $steps = $this->extract_steps($aiIntro);
        $row = $cached;
        if (!empty($aiIntro)) {
            // 8.0.0: update-or-insert, never replace() — replace() would wipe the saved plan (spec §3).
            $this->upsert_summary($uid, $week, array('reds'=>(int)$agg['reds'],'ambers'=>(int)$agg['ambers'],'greens'=>(int)$agg['greens'],'total_score'=>(int)$agg['total_score'],'mbti_type'=>$type,'disc_type'=>$disc_type,'ai_summary'=>$aiIntro,'steps_json'=>wp_json_encode($steps),'parent_ai_summary'=>null));
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ws WHERE user_id=%d AND week_num=%d",$uid,$week),ARRAY_A);
            // Task completion moved to the plan step (api_short_term_plan, spec §5).
        } elseif (!$cached) {
            // Steve unavailable: keep the counts so the plan step can still be completed (no AI text cached).
            $this->upsert_summary($uid, $week, array('reds'=>(int)$agg['reds'],'ambers'=>(int)$agg['ambers'],'greens'=>(int)$agg['greens'],'total_score'=>(int)$agg['total_score'],'mbti_type'=>$type,'disc_type'=>$disc_type,'steps_json'=>'[]'));
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ws WHERE user_id=%d AND week_num=%d",$uid,$week),ARRAY_A);
        }
        return new WP_REST_Response(array('ok'=>true,'week'=>$week,'rag'=>$agg,'ai'=>$aiIntro,'previous_weeks'=>$pw_out,'cached'=>false) + $this->plan_payload($uid,$week,$row ?: array('steps_json'=>wp_json_encode($steps))),200);
    }

    const STEP_RULE = "Finish with 2-3 lines, each starting STEP:, each one small specific thing they could do next week (max 15 words each). Put nothing after the STEP: lines.";

    /** Pulls STEP: lines out of the summary text (by reference). Max 3. */
    private function extract_steps(&$text) {
        $steps = array();
        if (preg_match_all('/^\s*(?:[-•*]\s*)?\**STEP\**\s*:\**\s*(.+)$/miu', (string) $text, $m)) {
            foreach ($m[1] as $st) { $st = trim($st, " \t*\"“”"); if ($st !== '' && count($steps) < 3) $steps[] = mb_substr($st, 0, 140); }
        }
        $text = trim(preg_replace('/^\s*(?:[-•*]\s*)?\**STEP\**\s*:.*$\n?/miu', '', (string) $text));
        return $steps;
    }

    private function upsert_summary($uid, $week, array $data) {
        global $wpdb; $ws = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        foreach (array('disc_type','steps_json','parent_ai_summary') as $c) { if (array_key_exists($c, $data) && !$this->has_column($ws, $c)) unset($data[$c]); }
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $ws WHERE user_id=%d AND week_num=%d", (int) $uid, (int) $week));
        if ($id) return $wpdb->update($ws, $data, array('id' => (int) $id));
        return $wpdb->insert($ws, array('user_id' => (int) $uid, 'week_num' => (int) $week) + $data);
    }

    /** Plan-step fields shared by the fresh and cached summary responses. */
    private function plan_payload($uid, $week, $row) {
        $steps = array();
        if (!empty($row['steps_json'])) { $d = json_decode($row['steps_json'], true); if (is_array($d)) $steps = array_values($d); }
        $stage = $this->week_stage($uid, $week);
        return array(
            'steps'           => $steps,
            'short_term_plan' => !empty($row['short_term_saved_at']) ? (string) $row['short_term_plan_text'] : null,
            'stage'           => $stage['stage'],
            'stage_label'     => $stage['label'],
        );
    }

    /** Top dream jobs: Dream & Junk Jobs getter (DJJ §4.4), else the 7.4.0 table read. */
    private function dream_jobs($uid) {
        if (function_exists('mfsd_get_dream_junk_jobs')) {
            $d = mfsd_get_dream_junk_jobs((int) $uid);
            if (is_array($d) && !empty($d['dream_ranked'])) return array_values(array_slice((array) $d['dream_ranked'], 0, 5));
        }
        global $wpdb; $djt=$wpdb->prefix.'mfsd_ai_dream_jobs_results';
        if ($wpdb->get_var("SHOW TABLES LIKE '$djt'")==$djt) {
            $djd=$wpdb->get_row($wpdb->prepare("SELECT ranking_json FROM $djt WHERE user_id=%d ORDER BY updated_at DESC LIMIT 1",$uid),ARRAY_A);
            if ($djd&&!empty($djd['ranking_json'])) { $r = json_decode($djd['ranking_json'],true); if (is_array($r)) return $r; }
        }
        return null;
    }

    /** "checked in — waiting for parent" etc. for the plan follow-up lines; '' when no card. */
    private function card_status_line($cid, $uid) {
        if (!$cid || !function_exists('mfsd_get_commitments')) return '';
        foreach (mfsd_get_commitments((int) $uid) as $c) {
            if ((int) $c['id'] !== (int) $cid) continue;
            $map = array('open'=>'still open','carried'=>'carried forward','not_yet'=>'not started yet','checked_in'=>'checked in — waiting for their parent to see it','validated'=>'done and seen by their parent','dropped'=>'dropped','expired'=>'expired');
            return $map[$c['status']] ?? (string) $c['status'];
        }
        return '';
    }

    /**
     * === COMMITMENTS THIS WEEK === block (Commitments §13.2). Child's words only, no personality data.
     * "Waiting for validation" is parent-only. Returns '' when commitments is inactive or there's nothing to say.
     */
    private function commitments_block($uid, $week, $for_parent) {
        if (!function_exists('mfsd_get_commitments_for_summary')) return '';
        // B2 (review): the parent summary is cached per child and shown to every linked parent, so it uses
        // the parent-safe feed — no card a parent hasn't unlocked yet (Commitments 2.0.0).
        if ($for_parent && !function_exists('mfsd_get_commitments_for_parent_summary')) return '';
        $f = $for_parent ? mfsd_get_commitments_for_parent_summary((int) $uid, (int) $week, 0) : mfsd_get_commitments_for_summary((int) $uid, (int) $week);
        $line = function ($c) { return '"' . $c['title'] . '"' . (!empty($c['label']) ? ' (' . $c['label'] . ')' : ''); };
        $out = '';
        if (!empty($f['created'])) {
            $out .= "Cards created this week:\n";
            foreach ($f['created'] as $src => $cards) foreach ((array) $cards as $c) $out .= '- ' . $line($c) . "\n";
        }
        if (!empty($f['reviewed'])) {
            $names = array('carry' => 'started / keeping it going', 'not_yet' => 'not yet', 'drop' => 'dropped');
            $out .= "Reviewed this week:\n";
            foreach ($f['reviewed'] as $c) $out .= '- ' . $line($c) . ': ' . ($names[$c['outcome']] ?? $c['outcome']) . "\n";
        }
        if (!empty($f['validated'])) {
            $out .= "Done and seen by a parent:\n";
            foreach ($f['validated'] as $c) $out .= '- ' . $line($c) . (!empty($c['auto']) ? ' (confirmed automatically)' : '') . "\n";
        }
        if ($for_parent && !empty($f['waiting'])) {
            $out .= "Waiting for the parent to see (checked in by the child):\n";
            foreach ($f['waiting'] as $c) $out .= '- ' . $line($c) . "\n";
        }
        if (!empty($f['red_repeat'])) {
            $out .= "Red again (handle with care, Solutions Mindset):\n";
            foreach ($f['red_repeat'] as $c) $out .= '- ' . $line($c) . ': red ' . (int) $c['weeks_running'] . " weeks running\n";
        }
        if ($out === '') return '';
        $how = $for_parent
            ? "Explain each in plain words, how the parent can help, and that they can mark a card as seen once their child has done it."
            : "Reflect these back warmly (e.g. \"This week you set three small steps…\"); celebrate what was done.";
        return "\n=== COMMITMENTS THIS WEEK ===\n" . $out . $how . "\n";
    }

    // =========================================================================
    // 8.0.0 — PLAN FOR NEXT WEEK (spec §5)
    // =========================================================================

    public function api_short_term_plan($req) {
        global $wpdb;
        $week = max(1, min(6, (int) $req->get_param('week')));
        $uid  = $this->get_current_um_user_id();
        $text = trim(preg_replace('/\s+/', ' ', sanitize_text_field((string) $req->get_param('plan_text'))));
        if (!$uid) return new WP_REST_Response(array('ok'=>false,'error'=>'Not logged in'),403);
        if ($text === '' || self::word_count($text) < 3) return new WP_REST_Response(array('ok'=>false,'error'=>'Write a few words about your plan.'),400);
        $text = mb_substr($text, 0, 140);

        $ws  = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ws WHERE user_id=%d AND week_num=%d", $uid, $week), ARRAY_A);
        if (!$row) return new WP_REST_Response(array('ok'=>false,'error'=>'Finish this week\'s questions first.'),409);

        $ref = 'rag:' . $week . ':short_term';
        if (function_exists('mfsd_safeguarding_check') && !$this->commitments_active()) { // D4: see Red Plan save
            mfsd_safeguarding_check($uid, self::SG_SOURCE, 'Week ' . $week . ' plan for next week', $text, array('field' => 'short_term', 'source_ref' => $ref));
        }

        $cid = !empty($row['short_term_commitment_id']) ? (int) $row['short_term_commitment_id'] : null;
        if (function_exists('mfsd_create_commitment')) {
            $res = mfsd_create_commitment($uid, array('type' => 'action', 'source_task' => self::SG_SOURCE, 'source_ref' => $ref, 'title' => $text, 'detail' => ''));
            if (is_wp_error($res)) {
                error_log('[MFSD RAG] short-term card failed (' . $ref . '): ' . $res->get_error_message());
                if (function_exists('mfsd_safeguarding_check')) { // no card, so Commitments never checked it (review)
                    mfsd_safeguarding_check($uid, self::SG_SOURCE, 'Week ' . $week . ' plan for next week', $text, array('field' => 'short_term', 'source_ref' => $ref));
                }
            } elseif ($res) $cid = (int) $res;
        }

        $wpdb->update($ws, array(
            'short_term_plan_text'     => $text,
            'short_term_commitment_id' => $cid,
            'short_term_saved_at'      => current_time('mysql'),
            'parent_ai_summary'        => null,
        ), array('id' => (int) $row['id']));

        // ── Ordering: the week is complete once the plan is saved (moved from the summary) ──
        if (function_exists('mfsd_set_task_status') && get_option('mfsd_rag_course_management', '1') === '1') {
            mfsd_set_task_status($uid, 'rag_week_' . $week, 'completed');
        }

        // End-of-course note, shown once (spec §9).
        $last = (int) get_option('mfsd_commitments_last_course_week', 3);
        $course_end = false;
        if ($this->commitments_active() && $week === $last && !get_user_meta($uid, 'mfsd_rag_course_end_seen', true)) {
            update_user_meta($uid, 'mfsd_rag_course_end_seen', current_time('mysql'));
            $course_end = true;
        }

        return new WP_REST_Response(array(
            'ok'              => true,
            'commitment_id'   => $cid,
            'short_term_plan' => $text,
            'cards'           => $this->week_cards($uid, $week),
            'parent_name'     => $this->parent_name($uid),
            'course_end'      => $course_end,
            'stage'           => 'completed',
            'stage_label'     => self::STAGE_LABELS['completed'],
        ), 200);
    }

    /** The cards this week's RAG created (red plans + short-term), for the end screen. */
    private function week_cards($uid, $week) {
        if (!function_exists('mfsd_get_open_commitments')) return array();
        $out = array();
        foreach (mfsd_get_open_commitments((int) $uid, array('source_task' => self::SG_SOURCE)) as $c) {
            if (strpos((string) $c['source_ref'], 'rag:' . (int) $week . ':') !== 0) continue;
            $out[] = array('id' => (int) $c['id'], 'title' => (string) $c['title'], 'short_term' => substr((string) $c['source_ref'], -11) === ':short_term');
        }
        return $out;
    }

    private function parent_name($uid) {
        if (class_exists('MFSD_MC_Store') && method_exists('MFSD_MC_Store', 'parent_label')) return (string) MFSD_MC_Store::parent_label((int) $uid);
        return 'your parent';
    }

    // =========================================================================
    // 8.0.0 — PARENT VIEW (spec §10)
    // =========================================================================

    private function render_parent_view($week) {
        $viewer = (int) get_current_user_id();
        $sid    = MFSD_Parent_Tabs::requested_student_id();
        if (!$sid) return '<p style="text-align:center;padding:40px;color:#555;">Open this week from your child\'s Course Progress page to see their results.</p>';
        if (is_wp_error(MFSD_Parent_Tabs::assert_linked($viewer, $sid))) {
            return '<p style="text-align:center;padding:40px;color:#555;">You don\'t have access to this student\'s results.</p>';
        }
        $child = MFSD_Parent_Tabs::child_first_name($sid);
        $root  = 'mfsd-rag-parent-' . (int) $sid . '-' . (int) $week;
        $url   = add_query_arg(array('week' => (int) $week, 'student_id' => (int) $sid), rest_url('mfsd-rag/v1/parent-summary'));
        $parent_html = '<div class="mfsd-pt__loading" id="' . esc_attr($root) . '">Steve is preparing your parent summary…</div>'
            . '<script>(function(){var el=document.getElementById(' . wp_json_encode($root) . ');if(!el)return;'
            . 'fetch(' . wp_json_encode(esc_url_raw($url)) . ',{credentials:"same-origin",headers:{"X-WP-Nonce":' . wp_json_encode(wp_create_nonce('wp_rest')) . '}})'
            . '.then(function(r){return r.json();}).then(function(d){el.outerHTML=(d&&d.html)?d.html:"<p>Steve\'s parent summary isn\'t available right now. Please try again in a few minutes.</p>";})'
            . '.catch(function(){el.textContent="Steve\'s parent summary isn\'t available right now. Please try again in a few minutes.";});})();</script>';
        return MFSD_Parent_Tabs::render_tabs($this->parent_child_view_html($sid, $week, $child), $parent_html, !empty($this->parent_flags($sid)), $child);
    }

    /** Parent-visible open flags: this task, plus the commitments "several Red Plans" signal (spec §10, D5). */
    private function parent_flags($sid) {
        $flags = $this->open_flags($sid, self::SG_SOURCE);
        $cards = array_flip(array_map('strval', $this->rag_card_ids($sid)));
        foreach ($this->open_flags($sid, 'commitments') as $f) {
            // The several-Red-Plans signal, plus any flag Commitments raised on one of this child's RAG cards (D4).
            if (($f['trigger_type'] ?? '') === 'signal' || isset($cards[(string) ($f['source_ref'] ?? '')])) $flags[] = $f;
        }
        return $flags;
    }

    /**
     * B3 (review): parent-visible flags that are NOT CLOSED (open, seen or talked). mfsd_get_concern_flags(…, true)
     * means status 'open' only, so a flag dropped out once a parent marked it seen. Unreleased moderation excluded.
     */
    private function open_flags($sid, $task) {
        if (function_exists('mfsd_get_open_concern_flags')) return mfsd_get_open_concern_flags((int) $sid, (string) $task);
        if (!function_exists('mfsd_get_concern_flags')) return array();
        return array_values(array_filter(mfsd_get_concern_flags((int) $sid, (string) $task), function ($f) { return ($f['status'] ?? 'open') !== 'closed'; }));
    }

    /** Commitment ids of this child's RAG cards (Red Plans + plans for next week). */
    private function rag_card_ids($sid) {
        global $wpdb;
        $rp = $wpdb->prefix . self::TBL_RED_PLANS; $ws = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        $a = $wpdb->get_col($wpdb->prepare("SELECT commitment_id FROM $rp WHERE user_id=%d AND commitment_id IS NOT NULL", $sid)) ?: array();
        $b = $wpdb->get_col($wpdb->prepare("SELECT short_term_commitment_id FROM $ws WHERE user_id=%d AND short_term_commitment_id IS NOT NULL", $sid)) ?: array();
        return array_values(array_filter(array_map('intval', array_merge($a, $b))));
    }

    private function withheld($sid, $ref, $commitment_id = 0) {
        if (!function_exists('mfsd_safeguarding_is_withheld')) return false;
        if (mfsd_safeguarding_is_withheld((int) $sid, self::SG_SOURCE, (string) $ref)) return true;
        // D4: when Commitments checked the text, a held card is keyed by its commitment id.
        return $commitment_id && mfsd_safeguarding_is_withheld((int) $sid, 'commitments', (string) (int) $commitment_id);
    }

    /** Red plans + short-term plan for the parent, withheld items marked (spec §8.3). */
    private function parent_week_items($sid, $week) {
        global $wpdb;
        $rp = $wpdb->prefix . self::TBL_RED_PLANS; $qt = $wpdb->prefix . self::TBL_QUESTIONS; $ws = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        $plans = $wpdb->get_results($wpdb->prepare("SELECT rp.question_id, rp.plan_text, rp.commitment_id, q.q_text FROM $rp rp LEFT JOIN $qt q ON q.id = rp.question_id WHERE rp.user_id=%d AND rp.week_num=%d ORDER BY rp.id ASC", $sid, $week), ARRAY_A) ?: array();
        foreach ($plans as &$p) $p['withheld'] = $this->withheld($sid, 'rag:' . $week . ':q' . (int) $p['question_id'], (int) $p['commitment_id']);
        unset($p);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ws WHERE user_id=%d AND week_num=%d", $sid, $week), ARRAY_A);
        $st  = ($row && !empty($row['short_term_saved_at'])) ? (string) $row['short_term_plan_text'] : '';
        return array('row' => $row, 'plans' => $plans, 'short_term' => $st, 'short_term_withheld' => $st !== '' && $this->withheld($sid, 'rag:' . $week . ':short_term', (int) ($row['short_term_commitment_id'] ?? 0)));
    }

    /** "{Child}'s view": what the child saw, read-only. */
    private function parent_child_view_html($sid, $week, $child) {
        $it  = $this->parent_week_items($sid, $week);
        $row = $it['row'];
        $looked = '<em>This answer is being looked at.</em>';
        $h  = '<div class="mfsd-pt__parent mfsd-rag-childview">';
        if (!$row) {
            $stage = $this->week_stage($sid, $week);
            return $h . '<p>' . esc_html($child) . ' hasn\'t finished Week ' . (int) $week . ' yet (' . esc_html(strtolower($stage['label'])) . ').</p></div>';
        }
        $h .= '<div class="mfsd-pt__section"><h3>Week ' . (int) $week . ' results</h3><p>🟢 Greens: ' . (int) $row['greens'] . ' &nbsp; 🟠 Ambers: ' . (int) $row['ambers'] . ' &nbsp; 🔴 Reds: ' . (int) $row['reds'] . '</p></div>';
        if (!empty($row['ai_summary'])) {
            $txt = (string) $row['ai_summary']; $this->extract_steps($txt);
            $h .= '<div class="mfsd-pt__section"><h3>Steve\'s summary</h3>' . wpautop(esc_html($txt)) . '</div>';
        }
        if ($it['plans']) {
            $h .= '<div class="mfsd-pt__section"><h3>Red Plans</h3><ul>';
            foreach ($it['plans'] as $p) $h .= '<li><strong>' . esc_html($p['q_text']) . '</strong><br>' . ($p['withheld'] ? $looked : esc_html($p['plan_text'])) . '</li>';
            $h .= '</ul></div>';
        }
        $h .= '<div class="mfsd-pt__section"><h3>Plan for next week</h3><p>'
            . ($it['short_term'] === '' ? esc_html($child) . ' is still choosing next week\'s plan.' : ($it['short_term_withheld'] ? $looked : esc_html($it['short_term'])))
            . '</p></div>';
        return $h . '</div>';
    }

    public function api_parent_summary($req) {
        $viewer = (int) get_current_user_id();
        $sid    = absint($req->get_param('student_id'));
        $week   = max(1, min(6, (int) $req->get_param('week')));
        $linked = MFSD_Parent_Tabs::assert_linked($viewer, $sid);
        if (is_wp_error($linked)) return $linked;
        $flags = $this->parent_flags($sid);
        return new WP_REST_Response(array('ok' => true, 'html' => $this->parent_panel_html($sid, $week, $flags, $viewer), 'has_open_flag' => !empty($flags)), 200);
    }

    private function parent_panel_html($sid, $week, array $flags, $viewer) {
        global $wpdb;
        $child    = MFSD_Parent_Tabs::child_first_name($sid);
        $fallback = MFSD_Parent_Tabs::concern_fallback_html($flags, $child);
        $lead     = $flags ? MFSD_Parent_Tabs::CONCERN_TITLE : '';
        $it       = $this->parent_week_items($sid, $week);
        $row      = $it['row'];
        // The week is finished when the plan is saved (or it was completed under 7.4.0) — not when ai_summary
        // exists: an empty child summary (Steve down, or saving off) used to leave this message up for good (review).
        $stage = $this->week_stage($sid, $week);
        if (!$row || $stage['stage'] !== 'completed') {
            $msg = ($row && $stage['stage'] === 'plan')
                ? esc_html($child) . ' is choosing their plan for next week, the last step. Steve\'s parent summary will appear here once they have.'
                : esc_html($child) . ' hasn\'t finished Week ' . (int) $week . ' yet. Steve\'s parent summary will appear here once they have.';
            return '<div class="mfsd-pt__parent">' . $fallback . '<p>' . $msg . '</p></div>';
        }
        $text = (string) ($row['parent_ai_summary'] ?? '');
        if ($text === '') {
            $text = $this->generate_parent_summary($sid, $week, $it, $flags, $child);
            if ($text !== '') $wpdb->update($wpdb->prefix . self::TBL_WEEK_SUMMARIES, array('parent_ai_summary' => $text), array('id' => (int) $row['id']));
        }
        if ($text === '') {
            return '<div class="mfsd-pt__parent">' . $fallback . '<p>Steve\'s parent summary isn\'t available right now. Please try again in a few minutes.</p></div>';
        }
        return MFSD_Parent_Tabs::build_parent_html($text, $lead, $fallback);
    }

    private function generate_parent_summary($sid, $week, array $it, array $flags, $child) {
        global $wpdb;
        $row = $it['row'];
        $age = function_exists('mfsd_get_student_age') ? mfsd_get_student_age((int) $sid) : null;
        $prev = '';
        $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
        for ($w = 1; $w < $week; $w++) {
            $pr = $wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') r, SUM(answer='A') am, SUM(answer='G') g FROM $a WHERE user_id=%d AND week_num=%d", $sid, $w), ARRAY_A);
            if ($pr && ($pr['r'] || $pr['am'] || $pr['g'])) $prev .= "Week $w: {$pr['g']} Green, {$pr['am']} Amber, {$pr['r']} Red\n";
        }
        $plans = '';
        foreach ($it['plans'] as $p) $plans .= '- Red on "' . $p['q_text'] . '". Their plan: "' . ($p['withheld'] ? '(not shown)' : $p['plan_text']) . "\"\n";
        $st = $it['short_term'] === '' ? '(not chosen yet)' : ($it['short_term_withheld'] ? '(not shown)' : $it['short_term']);
        $commitments = $this->commitments_block($sid, $week, true);

        $concerns = '';
        if ($flags) {
            // Parent-visible flags only: a 'moderation' one here has been released, so it is handled as urgent.
            $urgent = (bool) array_intersect(array('urgent', 'moderation'), array_column($flags, 'severity'));
            foreach ($flags as $f) {
                $sev = in_array($f['severity'], array('urgent', 'moderation'), true) ? 'urgent' : 'concern';
                $concerns .= "- $sev — {$f['item_text']}" . (!empty($f['reason_text']) ? ': "' . $f['reason_text'] . '"' : '') . "\n";
            }
            if ($urgent) $concerns .= "(At least one is urgent.)\n";
        }

        $p  = "You are SteveGPT, Steve Sallis's coach, writing for the PARENT of {$child}" . ($age ? ", aged {$age}" : '') . ". ";
        $p .= "Clear, warm adult language, UK English, about 300 words. Don't assume anything about family structure, money or circumstances. Never mention personality types or codes.\n\n";
        $p .= "Their child has just done the Week $week RAG check-in: they rate themselves Red (struggling), Amber (mixed) or Green (confident) on the same questions each week, write a plan for each Red, and choose one plan for next week.\n\n";
        $p .= "THIS WEEK: {$row['greens']} Green, {$row['ambers']} Amber, {$row['reds']} Red.\n";
        if ($prev !== '') $p .= "EARLIER WEEKS:\n$prev";
        if ($plans !== '') $p .= "\nRED PLANS (child's words):\n$plans";
        $p .= "\nPLAN FOR NEXT WEEK: \"$st\"\n";
        $p .= $commitments;
        $p .= "\nUse these section markers, in this order:\n";
        if ($flags) $p .= "[SECTION:Something to be aware of] (ALWAYS FIRST)\n";
        $p .= "[SECTION:This week] [SECTION:Their plans] " . ($commitments !== '' ? "[SECTION:Commitments] " : '') . "[SECTION:How you can help]\n";
        $p .= "Handle repeated reds with care, using Steve's Solutions Mindset (no failure, only feedback). Never quote anything shown as (not shown).\n";

        $out = $this->steve_task('parent_summary', $p, array(
            'child_name' => $child, 'age' => $age ? (string) $age : '', 'week' => (int) $week,
            'reds' => (int) $row['reds'], 'ambers' => (int) $row['ambers'], 'greens' => (int) $row['greens'],
            'previous_weeks' => $prev, 'red_plans' => $plans, 'short_term_plan' => $st,
            'commitments' => $commitments, 'concerns' => $concerns,
        ), (int) $sid, $this->sg_ctx('parent_summary', $sid, array('flags' => array_map(function ($f) { if (($f['severity'] ?? '') === 'moderation') $f['severity'] = 'urgent'; return $f; }, $flags), 'field' => 'parent_summary')));
        if ($out === '') return '';
        $note = MFSD_Parent_Tabs::extract_parent_note($out);
        if ($note !== '' && function_exists('mfsd_concern_save_parent_note')) mfsd_concern_save_parent_note((int) $sid, self::SG_SOURCE, $note);
        return trim(preg_replace('/\[\s*(?!SECTION\b)[A-Z_]{2,}\s*:[^\]]*\]/i', '', $out));
    }

    private function calculate_disc_results($uid,$week) {
        global $wpdb; $disc=$wpdb->prefix.self::TBL_ANSWERS_DISC;
        $answers=$wpdb->get_results($wpdb->prepare("SELECT d_contribution,i_contribution,s_contribution,c_contribution FROM $disc WHERE user_id=%d AND week_num=%d",$uid,$week),ARRAY_A);
        if (empty($answers)) return null;
        $rd=$ri=$rs=$rc=0; foreach($answers as $a){$rd+=(int)$a['d_contribution'];$ri+=(int)$a['i_contribution'];$rs+=(int)$a['s_contribution'];$rc+=(int)$a['c_contribution'];}
        $q=$wpdb->prefix.self::TBL_QUESTIONS; $dqs=$wpdb->get_results("SELECT disc_mapping FROM $q WHERE w{$week}=1 AND q_type='DISC' AND disc_mapping IS NOT NULL",ARRAY_A);
        $mp=0; foreach($dqs as $dq){$m=json_decode($dq['disc_mapping'],true);if($m)$mp+=max(abs($m['D'])*2,abs($m['I'])*2,abs($m['S'])*2,abs($m['C'])*2);}
        if ($mp==0) return null;
        $nd=max(0,min(100,(($rd+$mp)/(2*$mp))*100)); $ni=max(0,min(100,(($ri+$mp)/(2*$mp))*100)); $ns=max(0,min(100,(($rs+$mp)/(2*$mp))*100)); $nc=max(0,min(100,(($rc+$mp)/(2*$mp))*100));
        $total=$nd+$ni+$ns+$nc; if($total>0){$pd=($nd/$total)*100;$pi=($ni/$total)*100;$ps=($ns/$total)*100;$pc=($nc/$total)*100;}else{$pd=$pi=$ps=$pc=25;}
        $sc=array('D'=>$nd,'I'=>$ni,'S'=>$ns,'C'=>$nc); arsort($sc); $tk=array_keys($sc); $ps2=$tk[0]; if(count($tk)>1&&abs($sc[$tk[0]]-$sc[$tk[1]])<20)$ps2=$tk[0].$tk[1];
        $dr=$wpdb->prefix.self::TBL_DISC_RESULTS; $wpdb->replace($dr,array('user_id'=>$uid,'week_num'=>$week,'d_score'=>$rd,'i_score'=>$ri,'s_score'=>$rs,'c_score'=>$rc,'d_normalized'=>round($nd,2),'i_normalized'=>round($ni,2),'s_normalized'=>round($ns,2),'c_normalized'=>round($nc,2),'d_percent'=>round($pd,2),'i_percent'=>round($pi,2),'s_percent'=>round($ps,2),'c_percent'=>round($pc,2),'primary_style'=>$ps2),array('%d','%d','%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s'));
        return array('disc_type'=>$ps2,'disc_scores'=>array('D'=>array('normalized'=>round($nd,2),'percent'=>round($pd,2)),'I'=>array('normalized'=>round($ni,2),'percent'=>round($pi,2)),'S'=>array('normalized'=>round($ns,2),'percent'=>round($ps,2)),'C'=>array('normalized'=>round($nc,2),'percent'=>round($pc,2))));
    }

    private function mbti_letter_for($qid,$ans) {
        $map=array(1=>array('E/I',array('R'=>'E','A'=>'E','G'=>'I')),2=>array('E/I',array('R'=>'E','A'=>'E','G'=>'I')),3=>array('E/I',array('R'=>'E','A'=>'E','G'=>'I')),4=>array('S/N',array('R'=>'N','A'=>'N','G'=>'S')),5=>array('S/N',array('R'=>'N','A'=>'N','G'=>'S')),6=>array('S/N',array('R'=>'N','A'=>'N','G'=>'S')),7=>array('T/F',array('R'=>'T','A'=>'T','G'=>'F')),8=>array('T/F',array('R'=>'T','A'=>'T','G'=>'F')),9=>array('T/F',array('R'=>'T','A'=>'T','G'=>'F')),10=>array('J/P',array('R'=>'P','A'=>'P','G'=>'J')),11=>array('J/P',array('R'=>'P','A'=>'P','G'=>'J')),12=>array('J/P',array('R'=>'P','A'=>'P','G'=>'J')));
        global $wpdb; $qt=$wpdb->prefix.self::TBL_QUESTIONS; $q=$wpdb->get_row($wpdb->prepare("SELECT q_order FROM $qt WHERE id=%d",$qid),ARRAY_A); $qo=isset($q['q_order'])?(int)$q['q_order']:0;
        $ax=$lt='X'; if($qo&&isset($map[$qo])){$ax=$map[$qo][0];$lt=isset($map[$qo][1][$ans])?$map[$qo][1][$ans]:'X';}
        $ac='X'; if(strpos($ax,'E/I')!==false)$ac='E'; elseif(strpos($ax,'S/N')!==false)$ac='S'; elseif(strpos($ax,'T/F')!==false)$ac='T'; elseif(strpos($ax,'J/P')!==false)$ac='J';
        return array($ac,$lt);
    }

    private function mbti_type_from_counts($rows) {
        $c=array('E'=>0,'I'=>0,'S'=>0,'N'=>0,'T'=>0,'F'=>0,'J'=>0,'P'=>0);
        foreach($rows as $r){$L=strtoupper($r['letter']??'');$cnt=(int)($r['c']??0);if(isset($c[$L]))$c[$L]+=$cnt;}
        if(array_sum($c)===0) return '';
        return(($c['E']>=$c['I'])?'E':'I').(($c['S']>=$c['N'])?'S':'N').(($c['T']>=$c['F'])?'T':'F').(($c['J']>=$c['P'])?'J':'P');
    }

    private function get_current_um_user_id() {
        if (function_exists('um_profile_id')){$p=um_profile_id();if($p)return(int)$p;}
        return (int)get_current_user_id();
    }

    public function api_admin_reset_week($req) {
        global $wpdb; if(!current_user_can('manage_options'))return new WP_REST_Response(array('ok'=>false,'error'=>'Forbidden'),403);
        $uid=(int)$req->get_param('user_id'); $week=max(1,min(6,(int)$req->get_param('week')));
        if(!$uid||!$week)return new WP_REST_Response(array('ok'=>false,'error'=>'Missing params'),400);
        $del=0;
        // 8.0.0: an open short-term card from this week is dropped (red-plan cards are left alone).
        $wst=$wpdb->prefix.self::TBL_WEEK_SUMMARIES;
        if ($this->has_column($wst,'short_term_commitment_id') && function_exists('mfsd_update_commitment')) {
            $stc=(int)$wpdb->get_var($wpdb->prepare("SELECT short_term_commitment_id FROM $wst WHERE user_id=%d AND week_num=%d",$uid,$week));
            if ($stc) { $r=mfsd_update_commitment($stc,array('status'=>'dropped'),$uid); if (is_wp_error($r)) error_log('[MFSD RAG] reset: card '.$stc.' not dropped: '.$r->get_error_message()); }
        }
        $tables=array(
            $wpdb->prefix.self::TBL_ANSWERS_RAG    =>array('user_id'=>$uid,'week_num'=>$week),
            $wpdb->prefix.self::TBL_ANSWERS_MB     =>array('user_id'=>$uid,'week_num'=>$week),
            $wpdb->prefix.self::TBL_MB_RESULTS     =>array('user_id'=>$uid,'week_num'=>$week),
            $wpdb->prefix.self::TBL_WEEK_SUMMARIES =>array('user_id'=>$uid,'week_num'=>$week),
            $wpdb->prefix.self::TBL_RED_PLANS      =>array('user_id'=>$uid,'week_num'=>$week),
        );
        foreach(array($wpdb->prefix.self::TBL_ANSWERS_DISC,$wpdb->prefix.self::TBL_DISC_RESULTS) as $t){if($wpdb->get_var("SHOW TABLES LIKE '$t'")==$t)$tables[$t]=array('user_id'=>$uid,'week_num'=>$week);}
        foreach($tables as $t=>$w){$r=$wpdb->delete($t,$w,array('%d','%d'));if($r!==false)$del+=$r;}
        return new WP_REST_Response(array('ok'=>true,'deleted'=>$del,'message'=>"Week $week reset for user ID $uid. $del records removed."),200);
    }

    public function api_admin_reset_cm_progress($req) {
        if (!current_user_can('manage_options')) return new WP_REST_Response(array('ok'=>false,'error'=>'Forbidden'),403);
        $uid       = (int)$req->get_param('user_id');
        $task_slug = sanitize_key($req->get_param('task_slug'));
        if (!$uid || !$task_slug) return new WP_REST_Response(array('ok'=>false,'error'=>'Missing params'),400);
        global $wpdb;
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'mfsd_task_progress',
            array('student_id' => $uid, 'task_slug' => $task_slug),
            array('%d', '%s')
        );
        $u    = get_user_by('id', $uid);
        $name = $u ? $u->display_name : 'User ' . $uid;
        return new WP_REST_Response(array(
            'ok'      => true,
            'deleted' => (int) $deleted,
            'message' => "Ordering progress for {$task_slug} reset for {$name}.",
        ), 200);
    }

    // =========================================================================
    // ADMIN
    // =========================================================================

    public function save_admin_settings() {
        if (!isset($_POST['mfsd_rag_admin_nonce'])) return;
        if (!wp_verify_nonce($_POST['mfsd_rag_admin_nonce'], 'mfsd_rag_admin_save')) return;
        if (!current_user_can('manage_options')) return;
        update_option('mfsd_rag_cache_summaries',  isset($_POST['mfsd_rag_cache_summaries']) ? '1' : '0');
        update_option('mfsd_rag_course_management', isset($_POST['mfsd_rag_course_management']) ? '1' : '0');
        update_option('mfsd_rag_tts_voice',        sanitize_text_field($_POST['mfsd_rag_tts_voice'] ?? ''));
        update_option('mfsd_rag_conversation_mode',in_array($_POST['mfsd_rag_conversation_mode']??'',['polite','normal']) ? $_POST['mfsd_rag_conversation_mode'] : 'polite');
        $reveal=$_POST['mfsd_rag_text_reveal']??'block'; update_option('mfsd_rag_text_reveal',in_array($reveal,['block','sentence','word'])?$reveal:'block');
        $rpm=$_POST['mfsd_rag_red_plan_mode']??'fixed-50'; update_option('mfsd_rag_red_plan_mode',in_array($rpm,['fixed-50','fixed-100','age-specific'])?$rpm:'fixed-50');
        if (isset($_POST['mfsd_rag_question_weeks'])) {
            global $wpdb; $q_table=$wpdb->prefix.self::TBL_QUESTIONS; $all_ids=$wpdb->get_col("SELECT id FROM $q_table");
            foreach ($all_ids as $qid) { $qid=(int)$qid; $weeks=$_POST['mfsd_rag_question_weeks'][$qid]??[]; $wpdb->update($q_table,['w1'=>in_array(1,array_map('intval',$weeks))?1:0,'w2'=>in_array(2,array_map('intval',$weeks))?1:0,'w3'=>in_array(3,array_map('intval',$weeks))?1:0,'w4'=>in_array(4,array_map('intval',$weeks))?1:0,'w5'=>in_array(5,array_map('intval',$weeks))?1:0,'w6'=>in_array(6,array_map('intval',$weeks))?1:0],['id'=>$qid],['%d','%d','%d','%d','%d','%d'],['%d']); }
        }
        add_action('admin_notices', function() { echo '<div class="notice notice-success is-dismissible"><p><strong>MFSD RAG settings saved.</strong></p></div>'; });
    }

    public function handle_question_actions() {
        if (!isset($_POST['mfsd_rag_question_action'])) return;
        if (!wp_verify_nonce($_POST['mfsd_rag_question_nonce']??'','mfsd_rag_question_crud')) return;
        if (!current_user_can('manage_options')) return;
        global $wpdb; $q_table=$wpdb->prefix.self::TBL_QUESTIONS; $action=sanitize_text_field($_POST['mfsd_rag_question_action']); $qtype=sanitize_text_field($_POST['qtype']??'RAG');
        $base=add_query_arg(['page'=>'mfsd-rag','tab'=>'questions','qtype'=>$qtype],admin_url('admin.php'));
        if ($action==='delete') { $wpdb->delete($q_table,['id'=>(int)$_POST['question_id']],['%d']); wp_redirect(add_query_arg('msg','deleted',$base)); exit; }
        if ($action==='add'||$action==='edit') {
            $data=['q_order'=>(int)($_POST['q_order']??0),'q_type'=>sanitize_text_field($_POST['q_type']??'RAG'),'q_text'=>sanitize_textarea_field($_POST['q_text']??''),'w1'=>isset($_POST['w1'])?1:0,'w2'=>isset($_POST['w2'])?1:0,'w3'=>isset($_POST['w3'])?1:0,'w4'=>isset($_POST['w4'])?1:0,'w5'=>isset($_POST['w5'])?1:0,'w6'=>isset($_POST['w6'])?1:0];
            $fmt=['%d','%s','%s','%d','%d','%d','%d','%d','%d'];
            if ($data['q_type']==='RAG'){$data['red_score']=(int)($_POST['red_score']??0);$data['amber_score']=(int)($_POST['amber_score']??0);$data['green_score']=(int)($_POST['green_score']??0);array_push($fmt,'%d','%d','%d');}
            if ($data['q_type']==='DISC'){$data['disc_mapping']=json_encode(['D'=>(float)($_POST['disc_d']??0),'I'=>(float)($_POST['disc_i']??0),'S'=>(float)($_POST['disc_s']??0),'C'=>(float)($_POST['disc_c']??0)]);$fmt[]='%s';}
            if ($action==='add') $wpdb->insert($q_table,$data,$fmt);
            else $wpdb->update($q_table,$data,['id'=>(int)$_POST['question_id']],$fmt,['%d']);
            wp_redirect(add_query_arg('msg',$action==='add'?'added':'updated',$base)); exit;
        }
    }

    public function register_stevegpt_slots(array $slots): array {
        $slots[] = [
            'plugin' => 'Weekly RAG',
            'role'   => 'RAG chat assistant',
            'option' => 'mfsd_stevegpt_map_rag_chat',
            'tokens' => [],
        ];
        $task_slots = [
            'week_intro'      => ['Week intro',          ['student_name', 'age_desc', 'week', 'previous_week', 'greens', 'ambers', 'reds', 'personality']],
            'guidance'        => ['Question guidance',   ['student_name', 'age_desc', 'question_text', 'question_type', 'previous_answers', 'previous_red_plan']],
            'red_suggestions' => ['Red plan suggestions (must output INTRO:/SUGGESTION_1-3: lines)', ['student_name', 'age_desc', 'question_text', 'last_plan', 'previous_answer']],
            'question_chat'   => ['Question chat',       ['student_name', 'age_desc', 'week', 'question_text', 'question_type', 'previous_answers', 'message']],
            'red_chat'        => ['Red plan chat',       ['student_name', 'age_desc', 'week', 'question_text', 'question_type', 'previous_answers', 'message']],
            'summary'         => ['Week summary (STEP: lines are added automatically)', ['student_name', 'age_desc', 'week', 'reds', 'ambers', 'greens', 'total_score', 'personality', 'communication_style', 'dream_jobs', 'plan_context', 'commitments']],
            'red_plan_title'  => ['Red Plan card title (one line, max 12 words)', ['question_text', 'plan_text']],
            'parent_summary'  => ['Weekly parent summary ([SECTION:] markers, PARENT_NOTE: line)', ['child_name', 'age', 'week', 'reds', 'ambers', 'greens', 'previous_weeks', 'red_plans', 'short_term_plan', 'commitments', 'concerns']],
        ];
        foreach ($task_slots as $key => $def) {
            $slots[] = [
                'plugin' => 'Weekly RAG',
                'role'   => $def[0],
                'option' => 'mfsd_stevegpt_map_rag_' . $key,
                'tokens' => $def[1],
            ];
        }
        return $slots;
    }

    /**
     * SteveGPT task call (MFSD chatbot implementation pattern — task mode).
     * Reads the chatbot from mfsd_stevegpt_map_rag_{$slot}. If the chatbot has a prompt
     * template the tokens are rendered into it; otherwise the built-in prompt is sent.
     * Returns '' when the slot is not configured or on error.
     */
    /** "The Advocate (Diplomat family)" for a 4-letter code, or '' if unknown. */
    private function personality_label($code): string {
        $code = strtoupper(trim((string) $code));
        return isset(self::PERSONALITY_NAMES[$code])
            ? self::PERSONALITY_NAMES[$code][0] . ' (' . self::PERSONALITY_NAMES[$code][1] . ' family)'
            : '';
    }

    /** "outgoing and enthusiastic, and also steady and supportive" for e.g. 'IS', or '' if unknown. */
    private function communication_style_label($disc_type): string {
        $letters = str_split(strtoupper(preg_replace('/[^DISCdisc]/', '', (string) $disc_type)));
        $styles  = array();
        foreach ($letters as $l) {
            if (isset(self::COMMUNICATION_STYLES[$l])) $styles[] = self::COMMUNICATION_STYLES[$l];
        }
        if (!$styles) return '';
        return count($styles) > 1 ? $styles[0] . ', and also ' . $styles[1] : $styles[0];
    }

    /**
     * $ctx: safeguarding context (Prompt Layer §3.2), passed as query()'s 'safeguarding' option so SteveGPT
     * 14.0.0+ appends the block and strips markers. $append is added after a template render too (e.g. STEP rule).
     */
    private function steve_task(string $slot, string $fallback_prompt, array $tokens, int $user_id, array $ctx = array(), string $append = ''): string {
        $chatbot_id = get_option('mfsd_stevegpt_map_rag_' . $slot, '');
        if (!$chatbot_id || !class_exists('SteveGPT_Chatbot')) return '';

        try {
            $chatbot = SteveGPT_Chatbot::get($chatbot_id);
            $config  = $chatbot->get_config();
            $prompt  = !empty($config['prompt_template']) ? $chatbot->render_prompt($tokens) : $fallback_prompt;
            if ($append !== '') $prompt .= "\n\n" . $append;
            $prompt .= "\n\n" . self::NO_CODES_RULE;
            return (string) $chatbot->query($prompt, $user_id, $ctx ? array('safeguarding' => $ctx) : array());
        } catch (Exception $e) {
            error_log('[MFSD RAG] SteveGPT task error (' . $slot . '): ' . $e->getMessage());
            return '';
        }
    }

    public function admin_menu() {
        add_menu_page('MFSD RAG','MFSD RAG','manage_options','mfsd-rag',array($this,'admin_page'),'dashicons-forms',66);
    }

    public function admin_page() {
        global $wpdb;
        $active_tab=$_GET['tab']??'settings'; $active_qtype=$_GET['qtype']??'RAG'; $msg=$_GET['msg']??'';
        $cache_on=get_option('mfsd_rag_cache_summaries','1')==='1'; $tts_voice=get_option('mfsd_rag_tts_voice',''); $conv_mode=get_option('mfsd_rag_conversation_mode','polite'); $text_reveal=get_option('mfsd_rag_text_reveal','block'); $red_plan_mode=get_option('mfsd_rag_red_plan_mode','fixed-50');
        $reset_url=esc_url_raw(rest_url('mfsd-rag/v1/admin-reset-week')); $nonce_rest=wp_create_nonce('wp_rest'); $users=get_users(['orderby'=>'display_name','order'=>'ASC','number'=>500]);
        $q_table=$wpdb->prefix.self::TBL_QUESTIONS; $questions_all=$wpdb->get_results("SELECT * FROM $q_table ORDER BY q_type ASC, q_order ASC",ARRAY_A); $q_by_type=['RAG'=>[],'MBTI'=>[],'DISC'=>[]]; foreach($questions_all as $q){$t=$q['q_type'];if(isset($q_by_type[$t]))$q_by_type[$t][]=$q;}
        ?>
        <div class="wrap"><h1>🎯 MFSD Weekly RAG — Admin</h1>
        <?php if($msg):?><div class="notice notice-success is-dismissible"><p><?php echo $msg==='deleted'?'Question deleted.':($msg==='added'?'Question added.':'Question updated.');?></p></div><?php endif;?>
        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'settings'],admin_url('admin.php')));?>" class="nav-tab <?php echo $active_tab==='settings'?'nav-tab-active':'';?>">⚙️ Settings</a>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'questions','qtype'=>'RAG'],admin_url('admin.php')));?>" class="nav-tab <?php echo $active_tab==='questions'?'nav-tab-active':'';?>">📋 Questions</a>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'reset'],admin_url('admin.php')));?>" class="nav-tab <?php echo $active_tab==='reset'?'nav-tab-active':'';?>">🔄 Student Reset</a>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'course-management'],admin_url('admin.php')));?>" class="nav-tab <?php echo $active_tab==='course-management'?'nav-tab-active':'';?>">📋 Course Management</a>
        </nav>
        <?php if($active_tab==='settings'):?>
        <form method="post" action=""><?php wp_nonce_field('mfsd_rag_admin_save','mfsd_rag_admin_nonce');?>
            <h2 class="title">📋 Summary Settings</h2>
            <table class="form-table">
                <tr><th>Summary Caching</th><td><label><input type="checkbox" name="mfsd_rag_cache_summaries" value="1" <?php checked($cache_on);?>> <strong>Save &amp; reuse AI summaries</strong></label><p class="description">Checked = save and reuse. Unchecked = regenerate each time.</p></td></tr>
                <tr>
                    <th>Course Management</th>
                    <td>
                        <label><input type="checkbox" name="mfsd_rag_course_management" value="1" <?php checked(get_option('mfsd_rag_course_management','1'),'1');?>> <strong>Enable course ordering &amp; completion tracking</strong></label>
                        <p class="description">
                            <strong>When checked:</strong> Task locking, in-progress and completion states are tracked via MFSD Course Manager. Task slugs are <code>rag_week_1</code>, <code>rag_week_2</code>, <code>rag_week_3</code> matching the week in the page title.<br>
                            <strong>When unchecked:</strong> Ordering logic is bypassed entirely — useful for testing without affecting student progress records.
                            <?php if (!function_exists('mfsd_get_task_status')): ?><br><span style="color:#d63638;">⚠️ MFSD Ordering Utility plugin is not active.</span><?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>
            <h2 class="title">🎙 Voice Settings</h2>
            <table class="form-table"><tr><th><label for="mfsd_rag_tts_voice">Preferred TTS Voice</label></th><td>
                <input type="text" id="mfsd_rag_tts_voice" name="mfsd_rag_tts_voice" value="<?php echo esc_attr($tts_voice);?>" class="regular-text" placeholder="e.g. Google UK English Female">
                <div style="margin-top:12px;padding:16px;background:#f6f7f7;border:1px solid #ddd;border-radius:6px;max-width:620px;"><strong>🔍 Available voices:</strong><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;"><select id="mfsd-voice-preview-select" style="flex:1;min-width:260px;padding:6px;"><option value="">— loading —</option></select><button type="button" id="mfsd-voice-preview-btn" class="button button-secondary">▶ Preview</button><button type="button" id="mfsd-voice-use-btn" class="button button-primary">✔ Use this voice</button></div><p id="mfsd-voice-copied" style="display:none;color:green;margin:8px 0 0;font-weight:600;">✔ Copied!</p></div>
            </td></tr></table>
            <h2 class="title">📝 AI Text Reveal Style</h2>
            <table class="form-table"><tr><th>How AI text appears</th><td><fieldset>
                <label style="display:block;margin-bottom:8px;"><input type="radio" name="mfsd_rag_text_reveal" value="block" <?php checked($text_reveal,'block');?>> <strong>Whole block</strong></label>
                <label style="display:block;margin-bottom:8px;"><input type="radio" name="mfsd_rag_text_reveal" value="sentence" <?php checked($text_reveal,'sentence');?>> <strong>Sentence by sentence</strong></label>
                <label style="display:block;"><input type="radio" name="mfsd_rag_text_reveal" value="word" <?php checked($text_reveal,'word');?>> <strong>Word by word</strong></label>
            </fieldset></td></tr></table>
            <h2 class="title">🗣 Conversation Mode</h2>
            <table class="form-table"><tr><th>Mic behaviour during AI reply</th><td><fieldset>
                <label style="display:block;margin-bottom:8px;"><input type="radio" name="mfsd_rag_conversation_mode" value="polite" <?php checked($conv_mode,'polite');?>> <strong>Polite mode</strong> — mic off while AI speaks.</label>
                <label style="display:block;"><input type="radio" name="mfsd_rag_conversation_mode" value="normal" <?php checked($conv_mode,'normal');?>> <strong>Normal mode</strong> — mic stays open; speaking interrupts AI.</label>
            </fieldset></td></tr></table>
            <h2 class="title">🔴 Red Plan Word Target</h2>
            <table class="form-table"><tr><th>Words required for Red plans</th><td><fieldset>
                <label style="display:block;margin-bottom:8px;"><input type="radio" name="mfsd_rag_red_plan_mode" value="fixed-50" <?php checked($red_plan_mode,'fixed-50');?>> <strong>Fixed 50 words</strong> — all students.</label>
                <label style="display:block;margin-bottom:8px;"><input type="radio" name="mfsd_rag_red_plan_mode" value="fixed-100" <?php checked($red_plan_mode,'fixed-100');?>> <strong>Fixed 100 words</strong> — all students.</label>
                <label style="display:block;"><input type="radio" name="mfsd_rag_red_plan_mode" value="age-specific" <?php checked($red_plan_mode,'age-specific');?>> <strong>Age-specific</strong> — 50 words ages 11–12, 100 words ages 13–14 (reads <code>pp_dob</code> from ProfilePress).</label>
            </fieldset><p class="description" style="margin-top:8px;">Age-specific mode also pitches all AI language to the student's exact age throughout the RAG session.</p>
            </td></tr></table>
            <?php submit_button('Save Settings');?>
        </form>
        <?php elseif($active_tab==='questions'):?>
        <div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid #ccd0d4;">
            <?php foreach(['RAG','MBTI','DISC'] as $type):?>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'questions','qtype'=>$type],admin_url('admin.php')));?>" style="padding:8px 18px;border:1px solid #ccd0d4;border-bottom:<?php echo $active_qtype===$type?'2px solid #fff':'1px solid #ccd0d4';?>;border-radius:4px 4px 0 0;background:<?php echo $active_qtype===$type?'#fff':'#f0f0f1';?>;text-decoration:none;font-weight:<?php echo $active_qtype===$type?'600':'400';?>;color:<?php echo $active_qtype===$type?'#1d2327':'#50575e';?>;margin-bottom:-1px;">
                <?php echo $type;?> <span style="background:#ddd;border-radius:10px;padding:1px 7px;font-size:12px;"><?php echo count($q_by_type[$type]);?></span>
            </a>
            <?php endforeach;?>
        </div>
        <?php $cq=$q_by_type[$active_qtype];?>
        <form method="post" action=""><?php wp_nonce_field('mfsd_rag_admin_save','mfsd_rag_admin_nonce');?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><h3 style="margin:0;"><?php echo $active_qtype;?> Questions (<?php echo count($cq);?>)</h3><button type="button" id="mfsd-show-add-form" class="button button-primary">+ Add New <?php echo $active_qtype;?> Question</button></div>
        <table class="widefat striped" style="margin-bottom:16px;"><thead><tr>
            <th style="width:35px;">#</th><th>Question</th>
            <?php if($active_qtype==='RAG'):?><th style="width:100px;text-align:center;">R/A/G scores</th><?php endif;?>
            <?php if($active_qtype==='DISC'):?><th style="width:120px;text-align:center;">D/I/S/C</th><?php endif;?>
            <?php for($w=1;$w<=6;$w++):?><th style="text-align:center;width:64px;">W<?php echo $w;?><br><a href="#" class="mfsd-toggle-col" data-type="<?php echo $active_qtype;?>" data-week="<?php echo $w;?>" style="font-size:10px;text-decoration:none;">±</a></th><?php endfor;?>
            <th style="width:110px;text-align:center;">Actions</th>
        </tr></thead><tbody>
        <?php if(empty($cq)):?><tr><td colspan="10" style="text-align:center;color:#999;padding:20px;">No <?php echo $active_qtype;?> questions yet.</td></tr><?php endif;?>
        <?php foreach($cq as $q):?>
        <tr id="row-<?php echo $q['id'];?>">
            <td style="color:#999;font-size:13px;"><?php echo (int)$q['q_order'];?></td>
            <td style="font-size:13px;"><?php echo esc_html(wp_trim_words($q['q_text'],18,'…'));?></td>
            <?php if($active_qtype==='RAG'):?><td style="text-align:center;font-size:12px;color:#666;"><?php echo (int)$q['red_score'].'/'.(int)$q['amber_score'].'/'.(int)$q['green_score'];?></td><?php endif;?>
            <?php if($active_qtype==='DISC'):?><?php $dm=$q['disc_mapping']?json_decode($q['disc_mapping'],true):['D'=>0,'I'=>0,'S'=>0,'C'=>0];?><td style="text-align:center;font-size:11px;color:#666;">D<?php echo $dm['D'];?> I<?php echo $dm['I'];?> S<?php echo $dm['S'];?> C<?php echo $dm['C'];?></td><?php endif;?>
            <?php for($w=1;$w<=6;$w++):?><td style="text-align:center;"><input type="checkbox" name="mfsd_rag_question_weeks[<?php echo(int)$q['id'];?>][]" value="<?php echo $w;?>" class="mfsd-week-check mfsd-type-<?php echo $active_qtype;?>-w<?php echo $w;?>" <?php checked((int)$q['w'.$w],1);?>></td><?php endfor;?>
            <td style="text-align:center;">
                <button type="button" class="button button-small mfsd-edit-btn" data-id="<?php echo $q['id'];?>" style="margin-right:4px;">Edit</button>
                <form method="post" action="" style="display:inline;" onsubmit="return confirm('Delete this question?');"><?php wp_nonce_field('mfsd_rag_question_crud','mfsd_rag_question_nonce');?><input type="hidden" name="mfsd_rag_question_action" value="delete"><input type="hidden" name="question_id" value="<?php echo $q['id'];?>"><input type="hidden" name="qtype" value="<?php echo $active_qtype;?>"><button type="submit" class="button button-small" style="color:#d63638;border-color:#d63638;">Delete</button></form>
            </td>
        </tr>
        <tr id="edit-row-<?php echo $q['id'];?>" style="display:none;background:#fffbe6;"><td colspan="10" style="padding:16px;">
            <form method="post" action=""><?php wp_nonce_field('mfsd_rag_question_crud','mfsd_rag_question_nonce');?><input type="hidden" name="mfsd_rag_question_action" value="edit"><input type="hidden" name="question_id" value="<?php echo $q['id'];?>"><input type="hidden" name="q_type" value="<?php echo $active_qtype;?>"><input type="hidden" name="qtype" value="<?php echo $active_qtype;?>"><?php $this->render_question_form_fields($q,$active_qtype);?>
            <div style="margin-top:12px;"><button type="submit" class="button button-primary">Save Changes</button> <button type="button" class="button mfsd-cancel-edit" data-id="<?php echo $q['id'];?>" style="margin-left:8px;">Cancel</button></div></form>
        </td></tr>
        <?php endforeach;?>
        </tbody></table>
        <?php if(!empty($cq)):?><input type="submit" class="button button-secondary" value="Save Week Configuration"><?php endif;?>
        </form>
        <div id="mfsd-add-form" style="display:none;margin-top:20px;padding:20px;background:#f0f8ff;border:1px solid #b8daff;border-radius:6px;">
            <h3 style="margin-top:0;">Add New <?php echo $active_qtype;?> Question</h3>
            <form method="post" action=""><?php wp_nonce_field('mfsd_rag_question_crud','mfsd_rag_question_nonce');?><input type="hidden" name="mfsd_rag_question_action" value="add"><input type="hidden" name="q_type" value="<?php echo $active_qtype;?>"><input type="hidden" name="qtype" value="<?php echo $active_qtype;?>"><?php $this->render_question_form_fields(null,$active_qtype);?>
            <div style="margin-top:12px;"><button type="submit" class="button button-primary">Add Question</button> <button type="button" id="mfsd-cancel-add" class="button" style="margin-left:8px;">Cancel</button></div></form>
        </div>
        <?php elseif($active_tab==='reset'):?>
        <h2>🔄 Reset a Student's Week</h2>
        <p>Permanently deletes all answers, MBTI/DISC results, red plans, and cached AI summary. <strong>Cannot be undone.</strong></p>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;max-width:540px;">
            <table class="form-table" style="margin:0;">
                <tr><th style="padding:8px 0;"><label for="mfsd-reset-user">Student</label></th><td style="padding:8px 0;"><select id="mfsd-reset-user" style="width:100%;max-width:320px;padding:6px;"><option value="">— select a student —</option><?php foreach($users as $u):?><option value="<?php echo esc_attr($u->ID);?>"><?php echo esc_html($u->display_name.' ('.$u->user_email.')');?></option><?php endforeach;?></select></td></tr>
                <tr><th style="padding:8px 0;"><label for="mfsd-reset-week">Week</label></th><td style="padding:8px 0;"><select id="mfsd-reset-week" style="width:120px;padding:6px;"><?php for($w=1;$w<=6;$w++):?><option value="<?php echo $w;?>">Week <?php echo $w;?></option><?php endfor;?></select></td></tr>
            </table>
            <div style="margin-top:16px;display:flex;gap:10px;align-items:center;"><button type="button" id="mfsd-reset-btn" class="button button-secondary" style="border-color:#d63638;color:#d63638;">🗑 Reset Week</button><span id="mfsd-reset-status" style="font-size:14px;"></span></div>
        </div>
        <?php elseif($active_tab==='course-management'):?>
        <h2>📋 Course Management</h2>

        <?php if (!function_exists('mfsd_get_task_status')): ?>
        <div style="background:#fff8e5;border:1px solid #f0c036;border-left:4px solid #f0c036;padding:12px 16px;border-radius:4px;margin-bottom:16px;font-size:13px;">
            ⚠️ <strong>MFSD Ordering Utility plugin is not active.</strong> Course management features will not function until it is activated.
        </div>
        <?php endif; ?>

        <p>The Course Management toggle is in the <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'settings'],admin_url('admin.php')));?>">Settings tab</a> under Summary Settings.</p>

        <h3>Student Ordering Status</h3>
        <p style="color:#666;font-size:13px;">Shows all students who have an ordering progress record for any RAG week.</p>
        <?php
        $cm_rows = [];
        if (function_exists('mfsd_get_task_order_row')) {
            $cm_rows = $wpdb->get_results(
                "SELECT p.student_id, p.task_slug, p.status, p.started_date, p.completed_date,
                        u.display_name
                 FROM   {$wpdb->prefix}mfsd_task_progress p
                 JOIN   {$wpdb->users} u ON u.ID = p.student_id
                 WHERE  p.task_slug LIKE 'rag_week_%'
                 ORDER  BY u.display_name ASC, p.task_slug ASC",
                ARRAY_A
            );
        }
        ?>
        <?php if (empty($cm_rows)): ?>
            <p style="color:#999;">No students have ordering progress records for RAG weeks yet.</p>
        <?php else: ?>
        <table class="widefat striped" style="max-width:760px;margin-bottom:24px;">
            <thead><tr>
                <th>Student</th>
                <th>Task</th>
                <th>Ordering Status</th>
                <th>Started</th>
                <th>Completed</th>
            </tr></thead>
            <tbody>
            <?php foreach ($cm_rows as $r):
                $badge_colour = $r['status']==='completed' ? '#d1fae5;color:#065f46'
                              : ($r['status']==='in_progress' ? '#fff3cd;color:#92400e' : '#f3f4f6;color:#6b7280');
            ?>
            <tr>
                <td><strong><?php echo esc_html($r['display_name']); ?></strong></td>
                <td><code><?php echo esc_html($r['task_slug']); ?></code></td>
                <td><span style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;background:<?php echo $badge_colour; ?>"><?php echo esc_html($r['status']); ?></span></td>
                <td style="font-size:12px;color:#666;"><?php echo $r['started_date'] ? esc_html(substr($r['started_date'],0,10)) : '—'; ?></td>
                <td style="font-size:12px;color:#666;"><?php echo $r['completed_date'] ? esc_html(substr($r['completed_date'],0,10)) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h3>Reset Student Ordering Progress</h3>
        <p style="color:#666;font-size:13px;">Resets a student's ordering progress for a specific RAG week back to <em>not started</em>. RAG answer data is managed in the <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'reset'],admin_url('admin.php')));?>">Student Reset tab</a>.</p>

        <?php $all_students = get_users(['orderby'=>'display_name','order'=>'ASC','number'=>500]); ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;max-width:540px;">
            <table class="form-table" style="margin:0;">
                <tr>
                    <th style="padding:8px 0;"><label for="cm-reset-user">Student</label></th>
                    <td style="padding:8px 0;">
                        <select id="cm-reset-user" style="width:100%;max-width:320px;padding:6px;">
                            <option value="">— select a student —</option>
                            <?php foreach ($all_students as $u): ?>
                                <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th style="padding:8px 0;"><label for="cm-reset-week">RAG Week</label></th>
                    <td style="padding:8px 0;">
                        <select id="cm-reset-week" style="width:140px;padding:6px;">
                            <?php for ($w=1;$w<=6;$w++): ?>
                                <option value="<?php echo $w; ?>">Week <?php echo $w; ?> RAG</option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
                <button type="button" id="cm-ordering-reset-btn" class="button button-secondary" style="border-color:#d63638;color:#d63638;">↺ Reset Ordering Progress</button>
                <span id="cm-reset-status" style="font-size:14px;"></span>
            </div>
        </div>

        <script>
        (function(){
            const btn = document.getElementById('cm-ordering-reset-btn');
            if (!btn) return;
            btn.addEventListener('click', async function() {
                const uid  = document.getElementById('cm-reset-user').value;
                const week = document.getElementById('cm-reset-week').value;
                const st   = document.getElementById('cm-reset-status');
                if (!uid) { st.textContent = '⚠ Please select a student.'; st.style.color = '#d63638'; return; }
                const slug = 'rag_week_' + week;
                const name = document.getElementById('cm-reset-user').options[document.getElementById('cm-reset-user').selectedIndex].textContent;
                if (!confirm('Reset ordering progress for ' + slug + ' for ' + name + '?\n\nThis only removes the ordering record — RAG answer data is unaffected.')) return;
                btn.disabled = true; st.textContent = 'Resetting…'; st.style.color = '#666';
                try {
                    const r = await fetch('<?php echo esc_url_raw(rest_url('mfsd-rag/v1/admin-reset-cm-progress')); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' },
                        body: JSON.stringify({ user_id: parseInt(uid), task_slug: slug })
                    });
                    const d = await r.json();
                    if (d.ok) { st.textContent = '✔ ' + d.message; st.style.color = 'green'; }
                    else      { st.textContent = '✘ ' + (d.error || 'Unknown error'); st.style.color = '#d63638'; }
                } catch(e) { st.textContent = '✘ ' + e.message; st.style.color = '#d63638'; }
                finally { btn.disabled = false; }
            });
        })();
        </script>

        <?php endif;?>
        </div>
        <script>
        (function(){
            const vs=document.getElementById('mfsd-voice-preview-select'),pb=document.getElementById('mfsd-voice-preview-btn'),ub=document.getElementById('mfsd-voice-use-btn'),vf=document.getElementById('mfsd_rag_tts_voice'),cm=document.getElementById('mfsd-voice-copied');
            if(vs&&vf){function lv(){const vv=speechSynthesis.getVoices().filter(v=>v.lang.startsWith('en'));if(!vv.length)return;vs.innerHTML='';const s=vf.value.trim();vv.forEach(v=>{const o=document.createElement('option');o.value=v.name;o.textContent=v.name+' ('+v.lang+')';if(v.name===s)o.selected=true;vs.appendChild(o);});}lv();speechSynthesis.onvoiceschanged=lv;
            pb.addEventListener('click',()=>{speechSynthesis.cancel();const v=speechSynthesis.getVoices().find(v=>v.name===vs.value);const u=new SpeechSynthesisUtterance("Hi! I'm SteveGPT your AI coach. How does this voice sound?");u.rate=0.92;u.pitch=1.05;if(v)u.voice=v;speechSynthesis.speak(u);});
            ub.addEventListener('click',()=>{vf.value=vs.value;cm.style.display='block';setTimeout(()=>cm.style.display='none',3000);});}
            document.querySelectorAll('.mfsd-toggle-col').forEach(l=>l.addEventListener('click',e=>{e.preventDefault();const b=document.querySelectorAll('.mfsd-type-'+l.dataset.type+'-w'+l.dataset.week);const off=Array.from(b).some(x=>!x.checked);b.forEach(x=>x.checked=off);}));
            document.querySelectorAll('.mfsd-edit-btn').forEach(b=>b.addEventListener('click',()=>{const r=document.getElementById('edit-row-'+b.dataset.id);r.style.display=r.style.display==='none'?'':'none';}));
            document.querySelectorAll('.mfsd-cancel-edit').forEach(b=>b.addEventListener('click',()=>{document.getElementById('edit-row-'+b.dataset.id).style.display='none';}));
            const sa=document.getElementById('mfsd-show-add-form'),af=document.getElementById('mfsd-add-form'),ca=document.getElementById('mfsd-cancel-add');
            if(sa&&af)sa.addEventListener('click',()=>af.style.display=af.style.display==='none'?'block':'none');
            if(ca&&af)ca.addEventListener('click',()=>af.style.display='none');
            const rb=document.getElementById('mfsd-reset-btn');
            if(rb)rb.addEventListener('click',async function(){const uid=document.getElementById('mfsd-reset-user').value,week=document.getElementById('mfsd-reset-week').value,st=document.getElementById('mfsd-reset-status');if(!uid){st.textContent='⚠ Please select a student.';st.style.color='#d63638';return;}const nm=document.getElementById('mfsd-reset-user').options[document.getElementById('mfsd-reset-user').selectedIndex].textContent;if(!confirm('Reset Week '+week+' for '+nm+'?\n\nPermanently deletes ALL answers, results and red plans.'))return;this.disabled=true;st.textContent='Resetting…';st.style.color='#666';try{const r=await fetch('<?php echo $reset_url;?>',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo $nonce_rest;?>'},body:JSON.stringify({user_id:parseInt(uid),week:parseInt(week)})});const d=await r.json();if(d.ok){st.textContent='✔ '+d.message;st.style.color='green';}else{st.textContent='✘ '+(d.error||'Unknown error');st.style.color='#d63638';}}catch(e){st.textContent='✘ '+e.message;st.style.color='#d63638';}finally{this.disabled=false;}});
        })();
        </script>
        <?php
    }

    private function render_question_form_fields($q,$type) {
        $e=$q!==null; $wc=[]; for($w=1;$w<=6;$w++)$wc[$w]=$e?(int)$q['w'.$w]:1;
        $dm=($e&&$type==='DISC'&&$q['disc_mapping'])?json_decode($q['disc_mapping'],true):['D'=>0,'I'=>0,'S'=>0,'C'=>0];
        ?><table class="form-table" style="margin:0;">
        <tr><th style="width:120px;padding:6px 0;">Order #</th><td style="padding:6px 0;"><input type="number" name="q_order" value="<?php echo $e?(int)$q['q_order']:'';?>" style="width:80px;" required></td></tr>
        <tr><th style="padding:6px 0;">Question text</th><td style="padding:6px 0;"><textarea name="q_text" rows="3" style="width:100%;max-width:700px;" required><?php echo $e?esc_textarea($q['q_text']):'';?></textarea></td></tr>
        <?php if($type==='RAG'):?><tr><th style="padding:6px 0;">Scores R/A/G</th><td style="padding:6px 0;">Red:<input type="number" name="red_score" value="<?php echo $e?(int)$q['red_score']:0;?>" style="width:60px;margin:0 8px;"> Amber:<input type="number" name="amber_score" value="<?php echo $e?(int)$q['amber_score']:2;?>" style="width:60px;margin:0 8px;"> Green:<input type="number" name="green_score" value="<?php echo $e?(int)$q['green_score']:4;?>" style="width:60px;margin:0 8px;"></td></tr><?php endif;?>
        <?php if($type==='DISC'):?><tr><th style="padding:6px 0;">DISC Mapping</th><td style="padding:6px 0;">D:<input type="number" name="disc_d" value="<?php echo $dm['D'];?>" step="0.1" style="width:60px;margin:0 8px;"> I:<input type="number" name="disc_i" value="<?php echo $dm['I'];?>" step="0.1" style="width:60px;margin:0 8px;"> S:<input type="number" name="disc_s" value="<?php echo $dm['S'];?>" step="0.1" style="width:60px;margin:0 8px;"> C:<input type="number" name="disc_c" value="<?php echo $dm['C'];?>" step="0.1" style="width:60px;margin:0 8px;"></td></tr><?php endif;?>
        <?php if($type==='MBTI'):?><tr><th style="padding:6px 0;">Axis note</th><td style="padding:6px 0;color:#666;font-size:13px;">Order 1–3=E/I, 4–6=S/N, 7–9=T/F, 10–12=J/P.</td></tr><?php endif;?>
        <tr><th style="padding:6px 0;">Active weeks</th><td style="padding:6px 0;"><?php for($w=1;$w<=6;$w++):?><label style="margin-right:12px;"><input type="checkbox" name="w<?php echo $w;?>" value="1" <?php checked($wc[$w],1);?>> Week <?php echo $w;?></label><?php endfor;?></td></tr>
        </table><?php
    }
}

MFSD_Weekly_RAG::instance();

if (!function_exists('mfsd_rag_week_stage')) {
    /**
     * Week stage for Parent Portal / Course Progress (spec §6).
     * @return array{stage:string,label:string,short_term_plan:?string} stage: not_started|in_progress|plan|completed;
     *         'plan' → "Choosing next week's plan".
     */
    function mfsd_rag_week_stage(int $user_id, int $week): array {
        return MFSD_Weekly_RAG::instance()->week_stage($user_id, $week);
    }
}