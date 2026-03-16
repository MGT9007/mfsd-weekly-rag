<?php
/**
 * Plugin Name: MFSD Weekly RAG + MBTI + DISC
 * Description: Weekly RAG (26) + MBTI (12) + DISC survey over 6 weeks with UM integration, AI summaries, and results storage.
 * Version: 6.0.0
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

final class MFSD_Weekly_RAG {
    const VERSION = '6.0.0';
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
        add_action('admin_init',    array($this, 'maybe_upgrade_db'));
        add_action('admin_init',    array($this, 'save_admin_settings'));
        add_action('admin_init',    array($this, 'handle_question_actions'));
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

    public function maybe_upgrade_db() {
        if (!is_admin()) return;
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
     * e.g. "aged 13" or falls back to "aged 11-14" if DOB not set.
     */
    private function get_age_description($user_id) {
        $age = $this->get_user_age($user_id);
        return $age ? "aged $age" : "aged 11-14";
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
        wp_localize_script('mfsd-weekly-rag', 'MFSD_RAG_CFG', array(
            'restUrlQuestions'      => esc_url_raw(rest_url('mfsd/v1/questions')),
            'restUrlAnswer'         => esc_url_raw(rest_url('mfsd/v1/answer')),
            'restUrlSummary'        => esc_url_raw(rest_url('mfsd/v1/summary')),
            'restUrlStatus'         => esc_url_raw(rest_url('mfsd/v1/status')),
            'restUrlPrevious'       => esc_url_raw(rest_url('mfsd/v1/previous-answer')),
            'restUrlGuidance'       => esc_url_raw(rest_url('mfsd/v1/question-guidance')),
            'restUrlAllWeeks'       => esc_url_raw(rest_url('mfsd/v1/all-weeks-summary')),
            'restUrlQuestionChat'   => esc_url_raw(rest_url('mfsd/v1/question-chat')),
            'restUrlRedSuggestions' => esc_url_raw(rest_url('mfsd/v1/red-suggestions')),
            'restUrlSaveRedPlan'    => esc_url_raw(rest_url('mfsd/v1/red-plan')),
            'nonce'                 => wp_create_nonce('wp_rest'),
            'week'                  => $week,
            'ttsVoice'              => get_option('mfsd_rag_tts_voice', ''),
            'conversationMode'      => get_option('mfsd_rag_conversation_mode', 'polite'),
            'textReveal'            => get_option('mfsd_rag_text_reveal', 'block'),
            'redPlanMode'           => get_option('mfsd_rag_red_plan_mode', 'fixed-50'),
        ));
        wp_enqueue_script('mfsd-weekly-rag');
        wp_enqueue_style('mfsd-weekly-rag');
        $chat_html = do_shortcode('[mwai_chatbot id="chatbot-vxk8pu"]');
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
        );
        foreach ($routes as $r) {
            register_rest_route('mfsd/v1', '/' . $r[0], array(
                'methods'             => constant('WP_REST_Server::' . $r[1]),
                'callback'            => array($this, $r[2]),
                'permission_callback' => $std,
            ));
        }
        register_rest_route('mfsd/v1', '/admin-reset-week', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_admin_reset_week'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
    }

    public function check_permission($request) {
        if (!is_user_logged_in()) return new WP_Error('rest_forbidden', __('You must be logged in.'), array('status' => 401));
        if (in_array($request->get_method(), array('POST','PUT','DELETE'))) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) return new WP_Error('rest_forbidden', __('Invalid security token.'), array('status' => 403));
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

        $previous_week_summary=null; $intro_message=null;
        if ($week>1&&$status==='not_started') {
            $pw=$week-1; $a=$wpdb->prefix.self::TBL_ANSWERS_RAG;
            $pr=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$user_id,$pw),ARRAY_A);
            $mbr=$wpdb->prefix.self::TBL_MB_RESULTS;
            $pm=$wpdb->get_var($wpdb->prepare("SELECT type4 FROM $mbr WHERE user_id=%d AND week_num=%d",$user_id,$pw));
            if ($pr&&($pr['reds']>0||$pr['ambers']>0||$pr['greens']>0)) {
                $previous_week_summary=array('week'=>$pw,'reds'=>(int)$pr['reds'],'ambers'=>(int)$pr['ambers'],'greens'=>(int)$pr['greens'],'total_score'=>(int)$pr['total_score'],'mbti_type'=>$pm);
                if (isset($GLOBALS['mwai'])) {
                    try {
                        $mwai    = $GLOBALS['mwai'];
                        $username = function_exists('um_get_display_name') ? um_get_display_name($user_id) : get_userdata($user_id)->display_name;
                        $age_desc = $this->get_age_description($user_id);
                        $intro_message = $mwai->simpleTextQuery(
                            "You are SteveGPT speaking directly to $username ($age_desc). " .
                            "Last week Week $pw: {$pr['greens']} Greens, {$pr['ambers']} Ambers, {$pr['reds']} Reds" . ($pm ? " MBTI:$pm" : "") . ". " .
                            "Write a brief warm welcome for Week $week (3-4 sentences). " .
                            "Use 'you'/'your' only. NEVER say 'your child'. " .
                            "Pitch the language and vocabulary appropriately for someone $age_desc."
                        );
                    } catch(Exception $e) {}
                }
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
            'intro_message'=>$intro_message
        ),200);
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
        if (isset($GLOBALS['mwai'])) {
            try {
                $mwai     = $GLOBALS['mwai'];
                $username = function_exists('um_get_display_name') ? um_get_display_name($uid) : get_userdata($uid)->display_name;
                $age_desc = $this->get_age_description($uid);

                if ($question['q_type']==='MBTI') {
                    $prompt  = "You are SteveGPT, a supportive AI coach speaking DIRECTLY TO $username ($age_desc) completing their own personality assessment.\n\n";
                    $prompt .= "CRITICAL: Address $username as 'you'/'your' only. NEVER say 'your child' or third person.\n";
                    $prompt .= "Pitch language and vocabulary appropriately for someone $age_desc.\n\n";
                    $prompt .= "Question: \"{$question['q_text']}\"\n\n";
                    $prompt .= "Write 2-3 sentences explaining what this MBTI question explores and how to answer (Red=doesn't describe you, Amber=sometimes, Green=describes you well). Remind them there are no right or wrong answers.";
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
                $guidance = $mwai->simpleTextQuery($prompt);
            } catch(Exception $e) { error_log('MFSD RAG guidance: '.$e->getMessage()); }
        }

        return new WP_REST_Response(array('ok'=>true,'guidance'=>$guidance,'question'=>$question['q_text'],'type'=>$question['q_type']),200);
    }

    public function api_all_weeks_summary($req) {
        global $wpdb; $uid=$this->get_current_um_user_id();
        if (!$uid) return new WP_REST_Response(array('ok'=>false,'error'=>'Not logged in'),403);
        $all=array(); $a=$wpdb->prefix.self::TBL_ANSWERS_RAG; $mbr=$wpdb->prefix.self::TBL_MB_RESULTS; $ws=$wpdb->prefix.self::TBL_WEEK_SUMMARIES;
        for ($w=1;$w<=6;$w++) {
            $exists=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ws WHERE user_id=%d AND week_num=%d",$uid,$w));
            if ($exists>0) { $rs=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$uid,$w),ARRAY_A); $mt=$wpdb->get_var($wpdb->prepare("SELECT type4 FROM $mbr WHERE user_id=%d AND week_num=%d",$uid,$w)); $all[$w]=array('week'=>$w,'rag'=>$rs,'mbti'=>$mt?:null,'completed'=>true); }
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

        if (isset($GLOBALS['mwai'])) {
            try {
                $mwai     = $GLOBALS['mwai'];
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

                $response = $mwai->simpleTextQuery($prompt);
                foreach (explode("\n",$response) as $line) {
                    $line = trim($line);
                    if (strpos($line,'INTRO:')===0) $steve_intro = trim(substr($line,6));
                    elseif (preg_match('/^SUGGESTION_\d+:\s*(.+)/',$line,$m)) $suggestions[] = trim($m[1]);
                }
                if (!$steve_intro) $steve_intro = "Thanks for being honest — a Red means you know where to grow! Let's make a plan together.";
                if (empty($suggestions)) $suggestions = array("Try practising this skill for just 5 minutes each day this week.","Talk to someone you trust about this.","Set one specific goal you can achieve by next week.");
            } catch(Exception $e) {
                error_log('MFSD RAG red suggestions: '.$e->getMessage());
                $steve_intro = "Thanks for being honest. Let's build a plan to move this forward!";
                $suggestions = array("Try a small daily practice related to this question.","Ask someone you trust for advice or support.","Set one specific goal you can achieve by next week.");
            }
        }

        return new WP_REST_Response(array('ok'=>true,'steve_intro'=>$steve_intro,'suggestions'=>$suggestions,'prev_plans'=>$prev_plans,'prev_answer'=>$prev_answer,'word_target'=>$word_target,'question'=>$question['q_text']),200);
    }

    public function api_save_red_plan($req) {
        global $wpdb;
        $week        = max(1, min(6, (int)$req->get_param('week')));
        $question_id = (int)$req->get_param('question_id');
        $plan_text   = sanitize_textarea_field($req->get_param('plan_text'));
        $user_id     = $this->get_current_um_user_id();
        if (!$user_id||!$question_id||!$plan_text) return new WP_REST_Response(array('ok'=>false,'error'=>'Invalid request'),400);
        $word_count = str_word_count(strip_tags($plan_text));
        $rp=$wpdb->prefix.self::TBL_RED_PLANS;
        $wpdb->replace($rp,array('user_id'=>$user_id,'week_num'=>$week,'question_id'=>$question_id,'plan_text'=>$plan_text,'word_count'=>$word_count),array('%d','%d','%d','%s','%d'));
        return new WP_REST_Response(array('ok'=>true,'word_count'=>$word_count),200);
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
        if (isset($GLOBALS['mwai'])) {
            try {
                $mwai     = $GLOBALS['mwai'];
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
                    if ($q['q_type']==='MBTI') $p .= "MBTI question (Red=doesn't describe you, Amber=sometimes, Green=describes you well).\n";
                    else { $p.="RAG question (Red=struggling, Amber=mixed, Green=confident).\n"; if(!empty($prev)){$p.="Previous: ";foreach($prev as $pa)$p.="W{$pa['week_num']}:".($pa['answer']==='R'?'R':($pa['answer']==='A'?'A':'G'))." ";} }
                    $p .= "2-3 sentences, warm.\n\nStudent: $msg";
                }
                $resp = $mwai->simpleTextQuery($p);
            } catch(Exception $e) { $resp = "I'm having trouble connecting right now. Please try again."; }
        } else { $resp = "AI assistance is currently unavailable."; }

        return new WP_REST_Response(array('ok'=>true,'response'=>$resp),200);
    }

    public function api_summary($req) {
        global $wpdb;
        $week=$week=max(1,min(6,(int)$req->get_param('week'))); $uid=$this->get_current_um_user_id();
        if (!$uid) return new WP_REST_Response(array('ok'=>false,'error'=>'Not logged in'),403);

        $ws=$wpdb->prefix.self::TBL_WEEK_SUMMARIES; $cached=$wpdb->get_row($wpdb->prepare("SELECT * FROM $ws WHERE user_id=%d AND week_num=%d",$uid,$week),ARRAY_A); $use_cache=(get_option('mfsd_rag_cache_summaries','1')==='1');
        if ($use_cache&&$cached&&!empty($cached['ai_summary'])) {
            $pw=array(); if($week>1){$a=$wpdb->prefix.self::TBL_ANSWERS_RAG;for($w=1;$w<$week;$w++){$pr=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$uid,$w),ARRAY_A);$pm=$wpdb->get_var($wpdb->prepare("SELECT type4 FROM {$wpdb->prefix}mfsd_mbti_results WHERE user_id=%d AND week_num=%d",$uid,$w));if($pr&&($pr['reds']>0||$pr['ambers']>0||$pr['greens']>0))$pw[]=array('week'=>$w,'rag'=>$pr,'mbti'=>$pm);}}
            return new WP_REST_Response(array('ok'=>true,'week'=>$week,'rag'=>array('reds'=>(int)$cached['reds'],'ambers'=>(int)$cached['ambers'],'greens'=>(int)$cached['greens'],'total_score'=>(int)$cached['total_score']),'mbti'=>$cached['mbti_type'],'disc_type'=>isset($cached['disc_type'])?$cached['disc_type']:null,'disc_scores'=>null,'ai'=>$cached['ai_summary'],'previous_weeks'=>$pw,'cached'=>true),200);
        }

        $a=$wpdb->prefix.self::TBL_ANSWERS_RAG; $agg=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$uid,$week),ARRAY_A);
        if (!$agg) $agg=array('reds'=>0,'ambers'=>0,'greens'=>0,'total_score'=>0);
        $mb=$wpdb->prefix.self::TBL_ANSWERS_MB; $letters=$wpdb->get_results($wpdb->prepare("SELECT axis,letter,COUNT(*) c FROM $mb WHERE user_id=%d AND week_num=%d GROUP BY axis,letter",$uid,$week),ARRAY_A); $type=$this->mbti_type_from_counts($letters);
        if ($type){$mbr=$wpdb->prefix.self::TBL_MB_RESULTS;$wpdb->replace($mbr,array('user_id'=>$uid,'week_num'=>$week,'type4'=>$type,'details'=>wp_json_encode($letters)),array('%d','%d','%s','%s'));}
        $disc_type=$disc_scores=null; if($this->get_expected_disc_count($week)>0){$dr=$this->calculate_disc_results($uid,$week);if($dr){$disc_type=$dr['disc_type'];$disc_scores=$dr['disc_scores'];}}
        $pw=array(); if($week>1){for($w=1;$w<$week;$w++){$pr=$wpdb->get_row($wpdb->prepare("SELECT SUM(answer='R') AS reds,SUM(answer='A') AS ambers,SUM(answer='G') AS greens,SUM(score) AS total_score FROM $a WHERE user_id=%d AND week_num=%d",$uid,$w),ARRAY_A);$pm=$wpdb->get_var($wpdb->prepare("SELECT type4 FROM {$wpdb->prefix}mfsd_mbti_results WHERE user_id=%d AND week_num=%d",$uid,$w));if($pr&&($pr['reds']>0||$pr['ambers']>0||$pr['greens']>0))$pw[]=array('week'=>$w,'rag'=>$pr,'mbti'=>$pm);}}
        $mtu=$type; if(!$mtu&&!empty($pw)){foreach(array_reverse($pw) as $p){if(!empty($p['mbti'])){$mtu=$p['mbti'];break;}}}
        $djr=null; $djt=$wpdb->prefix.'mfsd_ai_dream_jobs_results'; if($wpdb->get_var("SHOW TABLES LIKE '$djt'")==$djt){$djd=$wpdb->get_row($wpdb->prepare("SELECT ranking_json FROM $djt WHERE user_id=%d ORDER BY updated_at DESC LIMIT 1",$uid),ARRAY_A);if($djd&&!empty($djd['ranking_json']))$djr=json_decode($djd['ranking_json'],true);}

        // Red plan follow-up context
        $plan_context = '';
        if ($week > 1) {
            $rp=$wpdb->prefix.self::TBL_RED_PLANS;
            $prev_week_plans=$wpdb->get_results($wpdb->prepare("SELECT rp.question_id,rp.plan_text,rp.week_num,q.q_text FROM $rp rp JOIN {$wpdb->prefix}".self::TBL_QUESTIONS." q ON q.id=rp.question_id WHERE rp.user_id=%d AND rp.week_num=%d",$uid,$week-1),ARRAY_A);
            if (!empty($prev_week_plans)) {
                $plan_context="\n===RED PLAN FOLLOW-UP===\nLast week the student made improvement plans for Red questions:\n\n";
                foreach ($prev_week_plans as $plan) {
                    $twa=$wpdb->get_var($wpdb->prepare("SELECT answer FROM $a WHERE user_id=%d AND question_id=%d AND week_num=%d LIMIT 1",$uid,$plan['question_id'],$week));
                    $outcome='not yet answered';
                    if ($twa==='G') $outcome='improved to GREEN — great success!';
                    elseif ($twa==='A') $outcome='improved to AMBER — good progress!';
                    elseif ($twa==='R') $outcome='still Red — plan needs revisiting';
                    $plan_context.="Question: \"{$plan['q_text']}\"\nPlan: \"{$plan['plan_text']}\"\nThis week: $outcome\n\n";
                }
                $plan_context.="Celebrate Red→Green warmly, for Red→Amber ask what worked, for Red→Red ask what got in the way and encourage them.\n";
            }
        }

        $aiIntro='';
        if (isset($GLOBALS['mwai'])) {
            try {
                $mwai     = $GLOBALS['mwai'];
                $username = function_exists('um_get_display_name') ? um_get_display_name($uid) : get_userdata($uid)->display_name;
                $age_desc = $this->get_age_description($uid);

                $p  = "You are SteveGPT, a supportive coach speaking DIRECTLY TO $username ($age_desc) about their High Performance Pathway.\n";
                $p .= "CRITICAL: Address $username as 'you'/'your'. NEVER say 'your child'. Pitch all language for someone $age_desc.\n\n";
                $p .= "WEEK $week RESULTS:\nRAG: {$agg['reds']}R {$agg['ambers']}A {$agg['greens']}G (Score:{$agg['total_score']})\n";
                if ($mtu) $p .= "MBTI: $mtu" . ($type ? " (this week)" : " (previous weeks)") . "\n";
                if ($disc_type) $p .= "DISC: $disc_type — D={$disc_scores['D']['percent']}% I={$disc_scores['I']['percent']}% S={$disc_scores['S']['percent']}% C={$disc_scores['C']['percent']}%\n";
                if (!empty($pw)){$p.="\nPROGRESS:\n";foreach($pw as $wpw)$p.="Week{$wpw['week']}: {$wpw['rag']['reds']}R/{$wpw['rag']['ambers']}A/{$wpw['rag']['greens']}G".($wpw['mbti']?" MBTI:{$wpw['mbti']}":"")."\n";}
                if (!empty($djr)){$p.="\nDREAM JOBS:\n";foreach(array_slice($djr,0,5) as $i=>$j)$p.=($i+1).". $j\n";}
                $p .= $plan_context;
                $p .= "Write a warm, insightful summary: celebrate strengths, note progress, explain personality, acknowledge development areas, give 2-3 actionable steps.\n";
                $p .= "UK context. Bullet points. Apply Steve's Solutions Mindset: No Failure only Feedback; Smooth sea never made a skilled sailor; You never lose you win or learn.\n";
                $aiIntro = $mwai->simpleTextQuery($p);
            } catch(Exception $e) { error_log('MFSD RAG summary: '.$e->getMessage()); }
        }

        if (!empty($aiIntro)){$wpdb->replace($ws,array('user_id'=>$uid,'week_num'=>$week,'reds'=>(int)$agg['reds'],'ambers'=>(int)$agg['ambers'],'greens'=>(int)$agg['greens'],'total_score'=>(int)$agg['total_score'],'mbti_type'=>$type,'disc_type'=>$disc_type,'ai_summary'=>$aiIntro),array('%d','%d','%d','%d','%d','%d','%s','%s','%s'));}
        return new WP_REST_Response(array('ok'=>true,'week'=>$week,'rag'=>$agg,'mbti'=>$type,'disc_type'=>$disc_type,'disc_scores'=>$disc_scores,'ai'=>$aiIntro,'previous_weeks'=>$pw,'cached'=>false),200);
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

    // =========================================================================
    // ADMIN
    // =========================================================================

    public function save_admin_settings() {
        if (!isset($_POST['mfsd_rag_admin_nonce'])) return;
        if (!wp_verify_nonce($_POST['mfsd_rag_admin_nonce'], 'mfsd_rag_admin_save')) return;
        if (!current_user_can('manage_options')) return;
        update_option('mfsd_rag_cache_summaries',  isset($_POST['mfsd_rag_cache_summaries']) ? '1' : '0');
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

    public function admin_menu() {
        add_menu_page('MFSD RAG','MFSD RAG','manage_options','mfsd-rag',array($this,'admin_page'),'dashicons-forms',66);
    }

    public function admin_page() {
        global $wpdb;
        $active_tab=$_GET['tab']??'settings'; $active_qtype=$_GET['qtype']??'RAG'; $msg=$_GET['msg']??'';
        $cache_on=get_option('mfsd_rag_cache_summaries','1')==='1'; $tts_voice=get_option('mfsd_rag_tts_voice',''); $conv_mode=get_option('mfsd_rag_conversation_mode','polite'); $text_reveal=get_option('mfsd_rag_text_reveal','block'); $red_plan_mode=get_option('mfsd_rag_red_plan_mode','fixed-50');
        $reset_url=esc_url_raw(rest_url('mfsd/v1/admin-reset-week')); $nonce_rest=wp_create_nonce('wp_rest'); $users=get_users(['orderby'=>'display_name','order'=>'ASC','number'=>500]);
        $q_table=$wpdb->prefix.self::TBL_QUESTIONS; $questions_all=$wpdb->get_results("SELECT * FROM $q_table ORDER BY q_type ASC, q_order ASC",ARRAY_A); $q_by_type=['RAG'=>[],'MBTI'=>[],'DISC'=>[]]; foreach($questions_all as $q){$t=$q['q_type'];if(isset($q_by_type[$t]))$q_by_type[$t][]=$q;}
        ?>
        <div class="wrap"><h1>🎯 MFSD Weekly RAG — Admin</h1>
        <?php if($msg):?><div class="notice notice-success is-dismissible"><p><?php echo $msg==='deleted'?'Question deleted.':($msg==='added'?'Question added.':'Question updated.');?></p></div><?php endif;?>
        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'settings'],admin_url('admin.php')));?>" class="nav-tab <?php echo $active_tab==='settings'?'nav-tab-active':'';?>">⚙️ Settings</a>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'questions','qtype'=>'RAG'],admin_url('admin.php')));?>" class="nav-tab <?php echo $active_tab==='questions'?'nav-tab-active':'';?>">📋 Questions</a>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'mfsd-rag','tab'=>'reset'],admin_url('admin.php')));?>" class="nav-tab <?php echo $active_tab==='reset'?'nav-tab-active':'';?>">🔄 Student Reset</a>
        </nav>
        <?php if($active_tab==='settings'):?>
        <form method="post" action=""><?php wp_nonce_field('mfsd_rag_admin_save','mfsd_rag_admin_nonce');?>
            <h2 class="title">📋 Summary Settings</h2>
            <table class="form-table"><tr><th>Summary Caching</th><td><label><input type="checkbox" name="mfsd_rag_cache_summaries" value="1" <?php checked($cache_on);?>> <strong>Save &amp; reuse AI summaries</strong></label><p class="description">Checked = save and reuse. Unchecked = regenerate each time.</p></td></tr></table>
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