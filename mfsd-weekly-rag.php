<?php
/**
 * Plugin Name: MFSD Weekly RAG + MBTI + DISC
 * Description: Weekly RAG (26) + MBTI (12) + DISC survey over 6 weeks with UM integration, AI summaries, and results storage.
 * Version: 1.11.0
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

final class MFSD_Weekly_RAG {
    const VERSION = '1.11.0';
   const NONCE_ACTION = 'mfsd_rag_nonce';

    const TBL_QUESTIONS = 'mfsd_rag_questions';
    const TBL_ANSWERS_RAG = 'mfsd_rag_answers';
    const TBL_ANSWERS_MB = 'mfsd_mbti_answers';
    const TBL_MB_RESULTS = 'mfsd_mbti_results';
    const TBL_ANSWERS_DISC = 'mfsd_disc_answers';
    const TBL_DISC_RESULTS = 'mfsd_disc_results';
    const TBL_WEEK_SUMMARIES = 'mfsd_week_summaries';

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('init', array($this,'assets'));
        add_shortcode('mfsd_rag', array($this,'shortcode'));
        add_action('rest_api_init', array($this,'register_routes'));
        add_action('admin_menu', array($this,'admin_menu'));
    }

    public function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $q = $wpdb->prefix . self::TBL_QUESTIONS;
        $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
        $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
        $mbr = $wpdb->prefix . self::TBL_MB_RESULTS;
        $ws = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE $q (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          q_order INT NOT NULL DEFAULT 0,
          q_type ENUM('RAG','MBTI') NOT NULL DEFAULT 'RAG',
          q_text TEXT NOT NULL,
          red_label VARCHAR(16) NULL,
          amber_label VARCHAR(16) NULL,
          green_label VARCHAR(16) NULL,
          red_score INT DEFAULT 0,
          amber_score INT DEFAULT 0,
          green_score INT DEFAULT 0,
          w1 TINYINT(1) DEFAULT 1,
          w2 TINYINT(1) DEFAULT 1,
          w3 TINYINT(1) DEFAULT 1,
          w4 TINYINT(1) DEFAULT 1,
          w5 TINYINT(1) DEFAULT 1,
          w6 TINYINT(1) DEFAULT 1,
          PRIMARY KEY (id),
          KEY idx_type (q_type),
          KEY idx_order (q_order)
        ) $charset;");

        dbDelta("CREATE TABLE $a (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          week_num TINYINT NOT NULL,
          question_id BIGINT UNSIGNED NOT NULL,
          answer ENUM('R','A','G') NOT NULL,
          score INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_user_week (user_id, week_num),
          KEY idx_user_question (user_id, question_id)
        ) $charset;");

        dbDelta("CREATE TABLE $mb (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          week_num TINYINT NOT NULL,
          question_id BIGINT UNSIGNED NOT NULL,
          answer ENUM('R','A','G') NOT NULL,
          axis CHAR(1) NOT NULL,
          letter CHAR(1) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_user_week (user_id, week_num)
        ) $charset;");

        dbDelta("CREATE TABLE $mbr (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          week_num TINYINT NOT NULL,
          type4 CHAR(4) NOT NULL,
          details JSON NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_user_week (user_id, week_num)
        ) $charset;");

        dbDelta("CREATE TABLE $ws (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          week_num TINYINT NOT NULL,
          reds INT NOT NULL DEFAULT 0,
          ambers INT NOT NULL DEFAULT 0,
          greens INT NOT NULL DEFAULT 0,
          total_score INT NOT NULL DEFAULT 0,
          mbti_type CHAR(4) NULL,
          ai_summary LONGTEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_user_week (user_id, week_num),
          KEY idx_user (user_id)
        ) $charset;");
    }

    public function assets() {
        $h = 'mfsd-weekly-rag';
        $base = plugin_dir_url(__FILE__);

        wp_register_script($h, $base . 'assets/mfsd-weekly-rag.js', array('wp-element'), self::VERSION, true);
        wp_register_style($h, $base . 'assets/mfsd-weekly-rag.css', array(), self::VERSION);

        // Don't enqueue here - let shortcode handle it
        // This way we can set the week number before the script runs
    }

    public function shortcode($atts) {
        $week = 1;
        if (is_page()) {
            $title = get_the_title();
            error_log('MFSD RAG: Page title is: ' . $title);
            
            if (preg_match('/Week\s*([1-6])\s*RAG/i', $title, $m)) {
                $week = (int) $m[1];
                error_log('MFSD RAG: Extracted week number: ' . $week);
            } else {
                error_log('MFSD RAG: Could not extract week from title, using default week 1');
            }
        }

        // CRITICAL: Set the config BEFORE enqueueing the script
        wp_localize_script('mfsd-weekly-rag', 'MFSD_RAG_CFG', array(
            'restUrlQuestions'    => esc_url_raw(rest_url('mfsd/v1/questions')),
            'restUrlAnswer'       => esc_url_raw(rest_url('mfsd/v1/answer')),
            'restUrlSummary'      => esc_url_raw(rest_url('mfsd/v1/summary')),
            'restUrlStatus'       => esc_url_raw(rest_url('mfsd/v1/status')),
            'restUrlPrevious'     => esc_url_raw(rest_url('mfsd/v1/previous-answer')),
            'restUrlGuidance'     => esc_url_raw(rest_url('mfsd/v1/question-guidance')),
            'restUrlAllWeeks'     => esc_url_raw(rest_url('mfsd/v1/all-weeks-summary')),
            'restUrlQuestionChat' => esc_url_raw(rest_url('mfsd/v1/question-chat')),
            'nonce'               => wp_create_nonce('wp_rest'),
            'week'                => $week,
        ));

        wp_enqueue_script('mfsd-weekly-rag');
        wp_enqueue_style('mfsd-weekly-rag');

        error_log('MFSD RAG: Localized script with week: ' . $week);

        $chat_html = do_shortcode('[mwai_chatbot id="chatbot-vxk8pu"]');

        return '<div id="mfsd-rag-root"></div>'
             . '<div id="mfsd-rag-chat-source" style="display:none">' . $chat_html . '</div>';
    }

    public function register_routes() {
        register_rest_route('mfsd/v1', '/questions', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_questions'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd/v1', '/answer', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_answer'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd/v1', '/summary', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_summary'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd/v1', '/status', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_status'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd/v1', '/previous-answer', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_previous_answer'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd/v1', '/question-guidance', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_question_guidance'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd/v1', '/all-weeks-summary', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_all_weeks_summary'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        register_rest_route('mfsd/v1', '/question-chat', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'api_question_chat'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    public function check_permission($request) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', __('You must be logged in.'), array('status' => 401));
        }
        
        if (in_array($request->get_method(), array('POST', 'PUT', 'DELETE'))) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error('rest_forbidden', __('Invalid security token.'), array('status' => 403));
            }
        }
        
        return true;
    }

    public function api_questions($req) {
        global $wpdb;
        $week = max(1, min(6, (int)$req->get_param('week')));
        $q = $wpdb->prefix . self::TBL_QUESTIONS;
        $wkcol = 'w' . $week;

        $rows = $wpdb->get_results("SELECT * FROM $q WHERE $wkcol=1 ORDER BY q_type='MBTI', q_order ASC", ARRAY_A);

        $rag = array();
        $mb = array();
        foreach ($rows as $r) {
            if ($r['q_type'] === 'RAG') {
                $rag[] = $r;
            } else {
                $mb[] = $r;
            }
        }

        $out = array();
        $iR = 0;
        $iM = 0;
        while ($iR < count($rag) || $iM < count($mb)) {
            for ($k = 0; $k < 2 && $iR < count($rag); $k++) {
                $out[] = $rag[$iR++];
            }
            if ($iM < count($mb)) {
                $out[] = $mb[$iM++];
            }
        }

        return new WP_REST_Response(array('ok' => true, 'questions' => $out), 200);
    }

    public function api_status($req) {
        global $wpdb;
        $week = max(1, min(6, (int)$req->get_param('week')));
        $user_id = $this->get_current_um_user_id();
        
        error_log("MFSD RAG Status Check: user_id=$user_id, week=$week");
        
        if (!$user_id) {
            error_log("MFSD RAG Status: No user ID found");
            return new WP_REST_Response(array(
                'ok' => true, 
                'status' => 'not_started',
                'can_start' => false,
                'message' => 'Please log in'
            ), 200);
        }

        // Check if previous week is completed (for weeks 2-6)
        $can_start = true;
        $blocking_week = null;
        
        if ($week > 1) {
            // Check all previous weeks are completed
            for ($w = 1; $w < $week; $w++) {
                $prev_total = $this->get_total_answer_count($user_id, $w);
                $expected_total = $this->get_expected_total_count($w);
                
                if ($prev_total < $expected_total) {
                    $can_start = false;
                    $blocking_week = $w;
                    break;
                }
            }
        }

        // Get answer counts for this week
        $rag_count = $this->get_rag_answer_count($user_id, $week);
        $mbti_count = $this->get_mbti_answer_count($user_id, $week);
        $total_count = $rag_count + $mbti_count;
        
        // Get expected question counts
        $expected_rag = $this->get_expected_rag_count($week);
        $expected_mbti = $this->get_expected_mbti_count($week);
        $expected_total = $expected_rag + $expected_mbti;
        
        error_log("MFSD RAG Status: Week $week - RAG: $rag_count/$expected_rag, MBTI: $mbti_count/$expected_mbti, Total: $total_count/$expected_total");

        $status = 'not_started';
        $last_question_id = null;
        
        if ($total_count >= $expected_total) {
            $status = 'completed';
        } elseif ($total_count > 0) {
            $status = 'in_progress';
            
            // Get the last answered question ID (check both tables)
            $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
            
            $last_rag = $wpdb->get_row($wpdb->prepare(
                "SELECT question_id, created_at FROM $a 
                 WHERE user_id=%d AND week_num=%d 
                 ORDER BY created_at DESC LIMIT 1",
                $user_id, $week
            ), ARRAY_A);
            
            $last_mbti = $wpdb->get_row($wpdb->prepare(
                "SELECT question_id, created_at FROM $mb 
                 WHERE user_id=%d AND week_num=%d 
                 ORDER BY created_at DESC LIMIT 1",
                $user_id, $week
            ), ARRAY_A);
            
            // Compare timestamps to find which was answered last
            if ($last_rag && $last_mbti) {
                if (strtotime($last_rag['created_at']) > strtotime($last_mbti['created_at'])) {
                    $last_question_id = $last_rag['question_id'];
                } else {
                    $last_question_id = $last_mbti['question_id'];
                }
            } elseif ($last_rag) {
                $last_question_id = $last_rag['question_id'];
            } elseif ($last_mbti) {
                $last_question_id = $last_mbti['question_id'];
            }
        }
        
        // Get ALL answered question IDs for this week (both RAG and MBTI)
        $answered_ids = array();
        if ($total_count > 0) {
            $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
            
            $rag_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT question_id FROM $a WHERE user_id=%d AND week_num=%d",
                $user_id, $week
            ));
            
            $mbti_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT question_id FROM $mb WHERE user_id=%d AND week_num=%d",
                $user_id, $week
            ));
            
            $answered_ids = array_merge($rag_ids ?: array(), $mbti_ids ?: array());
            $answered_ids = array_map('intval', $answered_ids);
        }
        
        error_log("MFSD RAG Status: Answered question IDs for week $week: " . implode(', ', $answered_ids));
        
        // Get previous week summary for weeks 2-6
        $previous_week_summary = null;
        $intro_message = null;
        
        if ($week > 1 && $status === 'not_started') {
            $prev_week = $week - 1;
            $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            
            // Get previous week's RAG results
            $prev_rag = $wpdb->get_row($wpdb->prepare("
                SELECT
                    SUM(answer='R') AS reds,
                    SUM(answer='A') AS ambers,
                    SUM(answer='G') AS greens,
                    SUM(score) AS total_score
                FROM $a WHERE user_id=%d AND week_num=%d
            ", $user_id, $prev_week), ARRAY_A);
            
            // Get previous week's MBTI type
            $mbr = $wpdb->prefix . self::TBL_MB_RESULTS;
            $prev_mbti = $wpdb->get_var($wpdb->prepare(
                "SELECT type4 FROM $mbr WHERE user_id=%d AND week_num=%d",
                $user_id, $prev_week
            ));
            
            if ($prev_rag && ($prev_rag['reds'] > 0 || $prev_rag['ambers'] > 0 || $prev_rag['greens'] > 0)) {
                $previous_week_summary = array(
                    'week' => $prev_week,
                    'reds' => (int)$prev_rag['reds'],
                    'ambers' => (int)$prev_rag['ambers'],
                    'greens' => (int)$prev_rag['greens'],
                    'total_score' => (int)$prev_rag['total_score'],
                    'mbti_type' => $prev_mbti
                );
                
                // Generate AI intro message
                if (isset($GLOBALS['mwai'])) {
                    try {
                        $mwai = $GLOBALS['mwai'];
                        $username = um_get_display_name($user_id);
                        
                        $prompt = "You are a supportive coach speaking to $username, aged 12-14, about their High Performance Pathway progress.\n\n";
                        $prompt .= "Last week (Week $prev_week), they completed a self-assessment with these results:\n";
                        $prompt .= "- Greens (strengths): {$prev_rag['greens']}\n";
                        $prompt .= "- Ambers (mixed/uncertain): {$prev_rag['ambers']}\n";
                        $prompt .= "- Reds (needs support): {$prev_rag['reds']}\n";
                        
                        if ($prev_mbti) {
                            $prompt .= "- MBTI type: $prev_mbti\n";
                        }
                        
                        $prompt .= "\nWrite a brief, warm welcome message for Week $week that:\n";
                        $prompt .= "1. Acknowledges their Week $prev_week results (be specific about what stood out)\n";
                        $prompt .= "2. Explains what the RAG colours mean in your own encouraging words\n";
                        $prompt .= "3. Sets a positive, motivating tone for starting Week $week\n\n";
                        
                        $prompt .= "CRITICAL: Address them directly using 'you' and 'your'. Keep it to 3-4 sentences max.\n";
                        $prompt .= "Be warm, encouraging, and age-appropriate for 12-14 year olds.";
                        
                        $intro_message = $mwai->simpleTextQuery($prompt);
                    } catch (Exception $e) {
                        error_log('MFSD RAG: Intro message error: ' . $e->getMessage());
                    }
                }
            }
        }
        
        return new WP_REST_Response(array(
            'ok' => true, 
            'status' => $status,
            'rag_count' => (int)$rag_count,
            'mbti_count' => (int)$mbti_count,
            'total_count' => (int)$total_count,
            'expected_rag' => $expected_rag,
            'expected_mbti' => $expected_mbti,
            'expected_total' => $expected_total,
            'week' => $week,
            'user_id' => $user_id,
            'can_start' => $can_start,
            'blocking_week' => $blocking_week,
            'last_question_id' => $last_question_id ? (int)$last_question_id : null,
            'answered_question_ids' => $answered_ids,
            'previous_week_summary' => $previous_week_summary,
            'intro_message' => $intro_message
        ), 200);
    }

    private function get_rag_answer_count($user_id, $week) {
        global $wpdb;
        $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $a WHERE user_id=%d AND week_num=%d",
            $user_id, $week
        ));
    }

    private function get_mbti_answer_count($user_id, $week) {
        global $wpdb;
        $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $mb WHERE user_id=%d AND week_num=%d",
            $user_id, $week
        ));
    }

    private function get_disc_answer_count($user_id, $week) {
        global $wpdb;
        $disc = $wpdb->prefix . self::TBL_ANSWERS_DISC;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $disc WHERE user_id=%d AND week_num=%d",
            $user_id, $week
        ));
    }

    private function get_expected_disc_count($week) {
        global $wpdb;
        $q = $wpdb->prefix . self::TBL_QUESTIONS;
        $wkcol = 'w' . $week;
        
        return (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $q WHERE $wkcol=1 AND q_type='DISC'"
        );
    }

    private function get_total_answer_count($user_id, $week) {
        return $this->get_rag_answer_count($user_id, $week) + 
               $this->get_mbti_answer_count($user_id, $week) +
               $this->get_disc_answer_count($user_id, $week);
    }

    private function get_expected_rag_count($week) {
        global $wpdb;
        $q = $wpdb->prefix . self::TBL_QUESTIONS;
        $wkcol = 'w' . $week;
        
        return (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $q WHERE $wkcol=1 AND q_type='RAG'"
        );
    }

    private function get_expected_mbti_count($week) {
        global $wpdb;
        $q = $wpdb->prefix . self::TBL_QUESTIONS;
        $wkcol = 'w' . $week;
        
        return (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $q WHERE $wkcol=1 AND q_type='MBTI'"
        );
    }

    private function get_expected_total_count($week) {
        return $this->get_expected_rag_count($week) + 
               $this->get_expected_mbti_count($week);
    }

    private function get_expected_question_count($week) {
        // Keep for backward compatibility, returns total
        return $this->get_expected_total_count($week);
    }

    public function api_previous_answer($req) {
        global $wpdb;
        $week = max(1, min(6, (int)$req->get_param('week')));
        $question_id = (int)$req->get_param('question_id');
        $user_id = $this->get_current_um_user_id();
        
        error_log("MFSD RAG Previous: user=$user_id, question=$question_id, current_week=$week");
        
        if (!$user_id || !$question_id || $week <= 1) {
            return new WP_REST_Response(array('ok' => true, 'previous' => array()), 200);
        }

        // Get the question to check if it's RAG or MBTI
        $q_table = $wpdb->prefix . self::TBL_QUESTIONS;
        $question = $wpdb->get_row($wpdb->prepare(
            "SELECT q_type, q_order FROM $q_table WHERE id=%d", $question_id
        ), ARRAY_A);

        if (!$question) {
            return new WP_REST_Response(array('ok' => true, 'previous' => array()), 200);
        }

        $previous = array();

        if ($question['q_type'] === 'RAG') {
            // RAG questions - get from RAG answers table
            $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            $previous = $wpdb->get_results($wpdb->prepare(
                "SELECT week_num, answer 
                 FROM $a 
                 WHERE user_id=%d AND question_id=%d AND week_num < %d 
                 GROUP BY week_num
                 ORDER BY week_num ASC",
                $user_id, $question_id, $week
            ), ARRAY_A);
        } else {
            // MBTI questions - get from MBTI answers table
            $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
            $previous = $wpdb->get_results($wpdb->prepare(
                "SELECT week_num, answer 
                 FROM $mb 
                 WHERE user_id=%d AND question_id=%d AND week_num < %d 
                 GROUP BY week_num
                 ORDER BY week_num ASC",
                $user_id, $question_id, $week
            ), ARRAY_A);
        }

        error_log("MFSD RAG Previous: Found " . count($previous) . " previous answers");
        foreach ($previous as $p) {
            error_log("  Week {$p['week_num']}: {$p['answer']}");
        }

        return new WP_REST_Response(array('ok' => true, 'previous' => $previous), 200);
    }

    public function api_question_guidance($req) {
        global $wpdb;
        $week = max(1, min(6, (int)$req->get_param('week')));
        $question_id = (int)$req->get_param('question_id');
        $user_id = $this->get_current_um_user_id();
        
        if (!$user_id || !$question_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid request'), 400);
        }

        // Get the question
        $q_table = $wpdb->prefix . self::TBL_QUESTIONS;
        $question = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $q_table WHERE id=%d", $question_id
        ), ARRAY_A);

        if (!$question) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Question not found'), 404);
        }

        // Get previous answers (only for RAG questions)
        $previous = array();
        if ($week > 1 && $question['q_type'] === 'RAG') {
            $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            $previous = $wpdb->get_results($wpdb->prepare(
                "SELECT week_num, answer FROM $a 
                 WHERE user_id=%d AND question_id=%d AND week_num < %d 
                 GROUP BY week_num
                 ORDER BY week_num ASC",
                $user_id, $question_id, $week
            ), ARRAY_A);
        }

        // Generate AI guidance
        $guidance = '';
        if (isset($GLOBALS['mwai'])) {
            try {
                $mwai = $GLOBALS['mwai'];
                $username = um_get_display_name($user_id);
                
                if ($question['q_type'] === 'MBTI') {
                    // MBTI question guidance
                    $prompt = "You are a supportive coach speaking directly to $username, a student completing a personality assessment.\n\n";
                    $prompt .= "The question is: \"{$question['q_text']}\"\n\n";
                    $prompt .= "Write a brief, practical explanation that:\n";
                    $prompt .= "1. Explains what this question is exploring about their personality\n";
                    $prompt .= "2. Helps them understand how to answer honestly:\n";
                    $prompt .= "   - Red = This doesn't describe you\n";
                    $prompt .= "   - Amber = Sometimes, or you're unsure\n";
                    $prompt .= "   - Green = This describes you well\n\n";
                    $prompt .= "Remind them there are no right or wrong answers - just honest self-reflection.\n\n";
                    $prompt .= "IMPORTANT: Address $username directly using 'you' and 'your' throughout. Speak TO them, not ABOUT them.\n";
                    $prompt .= "Keep the response concise (2-3 sentences), warm, and encouraging.\n";
                    $prompt .= "Example opening: 'This question is asking you to reflect on...' NOT 'This question is asking $username to reflect...'";
                } else {
                    // RAG question guidance
                    $prompt = "You are a supportive coach speaking directly to $username, a student completing a self-assessment.\n\n";
                    $prompt .= "The question is: \"{$question['q_text']}\"\n\n";
                    $prompt .= "Write a brief, practical explanation that:\n";
                    $prompt .= "1. Explains what this question is asking them to reflect on\n";
                    $prompt .= "2. Helps them understand how to answer:\n";
                    $prompt .= "   - Red = You're struggling or need support\n";
                    $prompt .= "   - Amber = You have mixed feelings or are uncertain\n";
                    $prompt .= "   - Green = You feel confident, this is a strength\n";
                    
                    if (!empty($previous)) {
                        $prompt .= "\n\nContext: In previous weeks, $username answered:\n";
                        foreach ($previous as $ans) {
                            $label = ($ans['answer'] === 'R') ? 'Red (struggling)' : 
                                    (($ans['answer'] === 'A') ? 'Amber (mixed)' : 'Green (confident)');
                            $prompt .= "Week {$ans['week_num']}: $label\n";
                        }
                        $prompt .= "\nAcknowledge their previous responses and what progress or patterns you notice, speaking directly to them about their journey.\n";
                    }
                    
                    $prompt .= "\n\nIMPORTANT: Address $username directly using 'you' and 'your' throughout. Speak TO them, not ABOUT them.\n";
                    $prompt .= "Keep the response concise (3-4 sentences), warm, encouraging, and practical.\n";
                    $prompt .= "Example opening: 'This question is asking you to reflect on...' NOT 'This question is asking $username to reflect...'\n";
                    $prompt .= "When discussing their situation, say 'when you faced difficulties' NOT 'when he/she faced difficulties'.";
                }
                
                $guidance = $mwai->simpleTextQuery($prompt);
            } catch (Exception $e) {
                error_log('MFSD RAG: AI guidance error: ' . $e->getMessage());
                $guidance = '';
            }
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'guidance' => $guidance,
            'question' => $question['q_text'],
            'type' => $question['q_type']
        ), 200);
    }

    public function api_all_weeks_summary($req) {
        global $wpdb;
        $user_id = $this->get_current_um_user_id();
        
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Not logged in'), 403);
        }

   $all_weeks = array();
$a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
$mbr = $wpdb->prefix . self::TBL_MB_RESULTS;
$ws = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;

for ($w = 1; $w <= 6; $w++) {
    // Check if week summary exists (only created when all questions answered)
    $summary_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $ws WHERE user_id=%d AND week_num=%d",
        $user_id, $w
    ));
    
    $is_completed = ($summary_exists > 0);
    
    if ($is_completed) {
        // Get RAG stats for this week
        $rag_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                SUM(answer='R') AS reds,
                SUM(answer='A') AS ambers,
                SUM(answer='G') AS greens,
                SUM(score) AS total_score
            FROM $a WHERE user_id=%d AND week_num=%d
        ", $user_id, $w), ARRAY_A);

        // Get MBTI type for this week
        $mbti = $wpdb->get_var($wpdb->prepare(
            "SELECT type4 FROM $mbr WHERE user_id=%d AND week_num=%d",
            $user_id, $w
        ));

        $all_weeks[$w] = array(
            'week' => $w,
            'rag' => $rag_stats,
            'mbti' => $mbti ? $mbti : null,
            'completed' => true
        );
    } else {
        $all_weeks[$w] = array(
            'week' => $w,
            'completed' => false
                );
            }
        }

        return new WP_REST_Response(array('ok' => true, 'weeks' => $all_weeks), 200);
    }

    public function api_answer($request) {
        $user_id = get_current_user_id();
        $week_num = (int) $request->get_param('week');
        $question_id = (int) $request->get_param('question_id');
        $answer = strtoupper(sanitize_text_field($request->get_param('rag')));

        if (!$week_num || !$question_id) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid data'), 400);
        }

        global $wpdb;
        $table_questions = $wpdb->prefix . self::TBL_QUESTIONS;
        
        $question = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_questions WHERE id = %d", $question_id), ARRAY_A);

        if (!$question) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Question not found'), 404);
        }

         if ($question['q_type'] === 'RAG') {
           // RAG-specific validation
            $answer = strtoupper(sanitize_text_field($request->get_param('rag')));
             if (!in_array($answer, array('R', 'A', 'G'))) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid RAG answer'), 400);
            }
                
            $score = 0;
            if ($answer === 'R') $score = (int) $question['red_score'];
            elseif ($answer === 'A') $score = (int) $question['amber_score'];
            elseif ($answer === 'G') $score = (int) $question['green_score'];

            $table_rag = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            $inserted = $wpdb->insert($table_rag, array(
                'user_id'     => $user_id,
                'week_num'    => $week_num,
                'question_id' => $question_id,
                'answer'      => $answer,
                'score'       => $score,
                'created_at'  => current_time('mysql'),
            ), array('%d', '%d', '%d', '%s', '%d', '%s'));

        } elseif ($question['q_type'] === 'MBTI') {
            $answer = strtoupper(sanitize_text_field($request->get_param('rag')));
            if (!in_array($answer, array('R', 'A', 'G'))) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid MBTI answer'), 400);
            }
            $mbti_data = $this->mbti_letter_for($question_id, $answer);
            $axis = $mbti_data[0];
            $letter = $mbti_data[1];

            $table_mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
            $inserted = $wpdb->insert($table_mb, array(
                'user_id'     => $user_id,
                'week_num'    => $week_num,
                'question_id' => $question_id,
                'answer'      => $answer,
                'axis'        => $axis,
                'letter'      => $letter,
                'created_at'  => current_time('mysql'),
            ), array('%d', '%d', '%d', '%s', '%s', '%s', '%s'));
            
        } elseif ($question['q_type'] === 'DISC') {
            // DISC uses numeric answer (1-5 scale from frontend)
            $disc_answer = (int) $request->get_param('disc_answer');
            if ($disc_answer < 1 || $disc_answer > 5) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid DISC answer'), 400);
            }
            
            // Get disc_mapping from question
            $mapping = json_decode($question['disc_mapping'], true);
            if (!$mapping) {
                return new WP_REST_Response(array('ok' => false, 'error' => 'DISC mapping missing'), 400);
            }
            
            // Calculate contributions (answer 1-5, minus 3 = -2 to +2)
            $contribution = $disc_answer - 3;
            $d_contrib = $mapping['D'] * $contribution;
            $i_contrib = $mapping['I'] * $contribution;
            $s_contrib = $mapping['S'] * $contribution;
            $c_contrib = $mapping['C'] * $contribution;
            
            $table_disc = $wpdb->prefix . self::TBL_ANSWERS_DISC;
            $inserted = $wpdb->insert($table_disc, array(
                'user_id'         => $user_id,
                'week_num'        => $week_num,
                'question_id'     => $question_id,
                'answer'          => $disc_answer,
                'd_contribution'  => $d_contrib,
                'i_contribution'  => $i_contrib,
                's_contribution'  => $s_contrib,
                'c_contribution'  => $c_contrib,
                'created_at'      => current_time('mysql'),
            ), array('%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s'));
        }

        if (false === $inserted) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'DB error: ' . $wpdb->last_error), 500);
        }

        return new WP_REST_Response(array('ok' => true, 'message' => 'Saved', 'answer_id' => $wpdb->insert_id), 200);
    }

private function calculate_disc_results($user_id, $week) {
        global $wpdb;
        $disc = $wpdb->prefix . self::TBL_ANSWERS_DISC;
        
        // Get all DISC answers for this week
        $answers = $wpdb->get_results($wpdb->prepare("
            SELECT d_contribution, i_contribution, s_contribution, c_contribution
            FROM $disc WHERE user_id=%d AND week_num=%d
        ", $user_id, $week), ARRAY_A);
        
        if (empty($answers)) {
            return null;
        }
        
        // Calculate raw scores
        $raw_d = 0;
        $raw_i = 0;
        $raw_s = 0;
        $raw_c = 0;
        
        foreach ($answers as $ans) {
            $raw_d += (int)$ans['d_contribution'];
            $raw_i += (int)$ans['i_contribution'];
            $raw_s += (int)$ans['s_contribution'];
            $raw_c += (int)$ans['c_contribution'];
        }
        
        // Calculate max possible score
        $q = $wpdb->prefix . self::TBL_QUESTIONS;
        $wkcol = 'w' . $week;
        $disc_questions = $wpdb->get_results("
            SELECT disc_mapping FROM $q 
            WHERE $wkcol=1 AND q_type='DISC' AND disc_mapping IS NOT NULL
        ", ARRAY_A);
        
        $max_possible = 0;
        foreach ($disc_questions as $dq) {
            $mapping = json_decode($dq['disc_mapping'], true);
            if ($mapping) {
                $max_d = abs($mapping['D']) * 2;
                $max_i = abs($mapping['I']) * 2;
                $max_s = abs($mapping['S']) * 2;
                $max_c = abs($mapping['C']) * 2;
                $max_possible += max($max_d, $max_i, $max_s, $max_c);
            }
        }
        
        if ($max_possible == 0) {
            return null;
        }
        
        // Normalize to 0-100
        $norm_d = (($raw_d + $max_possible) / (2 * $max_possible)) * 100;
        $norm_i = (($raw_i + $max_possible) / (2 * $max_possible)) * 100;
        $norm_s = (($raw_s + $max_possible) / (2 * $max_possible)) * 100;
        $norm_c = (($raw_c + $max_possible) / (2 * $max_possible)) * 100;
        
        // Ensure within bounds
        $norm_d = max(0, min(100, $norm_d));
        $norm_i = max(0, min(100, $norm_i));
        $norm_s = max(0, min(100, $norm_s));
        $norm_c = max(0, min(100, $norm_c));
        
        // Calculate relative percentages
        $total = $norm_d + $norm_i + $norm_s + $norm_c;
        
        if ($total > 0) {
            $pct_d = ($norm_d / $total) * 100;
            $pct_i = ($norm_i / $total) * 100;
            $pct_s = ($norm_s / $total) * 100;
            $pct_c = ($norm_c / $total) * 100;
        } else {
            $pct_d = $pct_i = $pct_s = $pct_c = 25;
        }
        
        // Determine primary style
        $scores = array(
            'D' => $norm_d,
            'I' => $norm_i,
            'S' => $norm_s,
            'C' => $norm_c
        );
        arsort($scores);
        $top_keys = array_keys($scores);
        
        $primary_style = $top_keys[0];
        if (count($top_keys) > 1 && abs($scores[$top_keys[0]] - $scores[$top_keys[1]]) < 20) {
            $primary_style = $top_keys[0] . $top_keys[1];
        }
        
        // Save results
        $discr = $wpdb->prefix . self::TBL_DISC_RESULTS;
        $wpdb->replace($discr, array(
            'user_id' => $user_id,
            'week_num' => $week,
            'd_score' => $raw_d,
            'i_score' => $raw_i,
            's_score' => $raw_s,
            'c_score' => $raw_c,
            'd_normalized' => round($norm_d, 2),
            'i_normalized' => round($norm_i, 2),
            's_normalized' => round($norm_s, 2),
            'c_normalized' => round($norm_c, 2),
            'd_percent' => round($pct_d, 2),
            'i_percent' => round($pct_i, 2),
            's_percent' => round($pct_s, 2),
            'c_percent' => round($pct_c, 2),
            'primary_style' => $primary_style
        ), array('%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', 
                 '%s', '%s', '%s', '%s', '%s'));
        
        return array(
            'disc_type' => $primary_style,
            'disc_scores' => array(
                'D' => array('normalized' => round($norm_d, 2), 'percent' => round($pct_d, 2)),
                'I' => array('normalized' => round($norm_i, 2), 'percent' => round($pct_i, 2)),
                'S' => array('normalized' => round($norm_s, 2), 'percent' => round($pct_s, 2)),
                'C' => array('normalized' => round($norm_c, 2), 'percent' => round($pct_c, 2))
            )
        );
    }

    public function api_summary($req) {
        global $wpdb;
        $week = max(1, min(6, (int)$req->get_param('week')));
        $user_id = $this->get_current_um_user_id();
        
        if (!$user_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Not logged in'), 403);
        }

        // Check for cached summary first
        $ws = $wpdb->prefix . self::TBL_WEEK_SUMMARIES;
        $cached = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $ws WHERE user_id=%d AND week_num=%d",
            $user_id, $week
        ), ARRAY_A);

/* TEMPORARILY DISABLED FOR PROMPT TUNING 
        if ($cached && !empty($cached['ai_summary'])) {
            // Return cached summary
            error_log("MFSD RAG: Returning cached summary for week $week, user $user_id");
            
            // Get previous weeks for display
            $previous_weeks = array();
            if ($week > 1) {
                $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
                for ($w = 1; $w < $week; $w++) {
                    $prev_rag = $wpdb->get_row($wpdb->prepare("
                        SELECT
                            SUM(answer='R') AS reds,
                            SUM(answer='A') AS ambers,
                            SUM(answer='G') AS greens,
                            SUM(score) AS total_score
                        FROM $a WHERE user_id=%d AND week_num=%d
                    ", $user_id, $w), ARRAY_A);

                    $prev_mbti = $wpdb->get_var($wpdb->prepare(
                        "SELECT type4 FROM {$wpdb->prefix}mfsd_mbti_results WHERE user_id=%d AND week_num=%d",
                        $user_id, $w
                    ));

                    if ($prev_rag && ($prev_rag['reds'] > 0 || $prev_rag['ambers'] > 0 || $prev_rag['greens'] > 0)) {
                        $previous_weeks[] = array(
                            'week' => $w,
                            'rag' => $prev_rag,
                            'mbti' => $prev_mbti
                        );
                    }
                }
            }
            
            return new WP_REST_Response(array(
                'ok'   => true,
                'week' => $week,
                'rag'  => array(
                    'reds' => (int)$cached['reds'],
                    'ambers' => (int)$cached['ambers'],
                    'greens' => (int)$cached['greens'],
                    'total_score' => (int)$cached['total_score']
                ),
                'mbti' => $cached['mbti_type'],
                'disc_type' => isset($cached['disc_type']) ? $cached['disc_type'] : null,
                'disc_scores' => null, // Not stored in cache, would need to recalculate
                'ai'   => $cached['ai_summary'],
                'previous_weeks' => $previous_weeks,
                'cached' => true
            ), 200);
        }
        END TEMPORARILY DISABLED */
        error_log("MFSD RAG: Generating new summary for week $week, user $user_id");

        // Generate summary (existing code)
        $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
        $agg = $wpdb->get_row($wpdb->prepare("
          SELECT
            SUM(answer='R') AS reds,
            SUM(answer='A') AS ambers,
            SUM(answer='G') AS greens,
            SUM(score) AS total_score
          FROM $a WHERE user_id=%d AND week_num=%d
        ", $user_id, $week), ARRAY_A);

        if (!$agg) {
            $agg = array('reds' => 0, 'ambers' => 0, 'greens' => 0, 'total_score' => 0);
        }

        $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
        $letters = $wpdb->get_results($wpdb->prepare("
          SELECT axis, letter, COUNT(*) c FROM $mb
          WHERE user_id=%d AND week_num=%d
          GROUP BY axis, letter
        ", $user_id, $week), ARRAY_A);

        $type = $this->mbti_type_from_counts($letters);
        
        if ($type) {
            $mbr = $wpdb->prefix . self::TBL_MB_RESULTS;
            $wpdb->replace($mbr, array(
                'user_id'  => $user_id,
                'week_num' => $week,
                'type4'    => $type,
                'details'  => wp_json_encode($letters),
            ), array('%d', '%d', '%s', '%s'));
        }
        
        // Calculate DISC if needed
        $disc_type = null;
        $disc_scores = null;
        $expected_disc = $this->get_expected_disc_count($week);
        if ($expected_disc > 0) {
            $disc_result = $this->calculate_disc_results($user_id, $week);
            if ($disc_result) {
                $disc_type = $disc_result['disc_type'];
                $disc_scores = $disc_result['disc_scores'];
            }
        }
       
        // Get MBTI from this week OR previous weeks
        $mbti_type_to_use = $type;  // Start with current week's MBTI
        if (!$mbti_type_to_use && !empty($previous_weeks)) {
            // Find most recent MBTI from previous weeks
            foreach (array_reverse($previous_weeks) as $pw) {
                if (!empty($pw['mbti'])) {
                    $mbti_type_to_use = $pw['mbti'];
                    break;
                }
            }
        }

        // Get previous weeks' data for comparison
        $previous_weeks = array();
        if ($week > 1) {
            for ($w = 1; $w < $week; $w++) {
                $prev_rag = $wpdb->get_row($wpdb->prepare("
                    SELECT
                        SUM(answer='R') AS reds,
                        SUM(answer='A') AS ambers,
                        SUM(answer='G') AS greens,
                        SUM(score) AS total_score
                    FROM $a WHERE user_id=%d AND week_num=%d
                ", $user_id, $w), ARRAY_A);

                $prev_mbti = $wpdb->get_var($wpdb->prepare(
                    "SELECT type4 FROM {$wpdb->prefix}mfsd_mbti_results WHERE user_id=%d AND week_num=%d",
                    $user_id, $w
                ));

                if ($prev_rag && ($prev_rag['reds'] > 0 || $prev_rag['ambers'] > 0 || $prev_rag['greens'] > 0)) {
                    $previous_weeks[] = array(
                        'week' => $w,
                        'rag' => $prev_rag,
                        'mbti' => $prev_mbti
                    );
                }
            }
        }

        // Get dream job and career ranking data
        $dream_job_table = $wpdb->prefix . 'mfsd_ai_dream_jobs_results';
        $dream_jobs_ranking = null;
        if ($wpdb->get_var("SHOW TABLES LIKE '$dream_job_table'") == $dream_job_table) {
            $dream_jobs_data = $wpdb->get_row($wpdb->prepare(
                "SELECT ranking_json FROM $dream_job_table WHERE user_id=%d ORDER BY updated_at DESC LIMIT 1",
                $user_id
            ), ARRAY_A);
            
            if ($dream_jobs_data && !empty($dream_jobs_data['ranking_json'])) {
                $dream_jobs_ranking = json_decode($dream_jobs_data['ranking_json'], true);
            }
        }

        $aiIntro = '';
        if (isset($GLOBALS['mwai'])) {
            try {
                $mwai = $GLOBALS['mwai'];
                $username = um_get_display_name($user_id);
                
                $aiPrompt = "You are a supportive coach speaking to $username (aged 12-14) about their High Performance Pathway progress.\n\n";
$aiPrompt .= "===WEEK $week RESULTS===\n";
$aiPrompt .= "RAG Assessment: {$agg['reds']} Reds, {$agg['ambers']} Ambers, {$agg['greens']} Greens (Total Score: {$agg['total_score']})\n";

// Include MBTI (current or from previous weeks)
if ($mbti_type_to_use) {
    if ($type) {
        $aiPrompt .= "MBTI Personality Type: $mbti_type_to_use (assessed this week)\n";
    } else {
        $aiPrompt .= "MBTI Personality Type: $mbti_type_to_use (from previous weeks)\n";
    }
}

// Include DISC prominently if available
if ($disc_type) {
    $aiPrompt .= "DISC Personality Style: $disc_type (assessed this week)\n";
    $aiPrompt .= "DISC Breakdown: D={$disc_scores['D']['percent']}%, I={$disc_scores['I']['percent']}%, S={$disc_scores['S']['percent']}%, C={$disc_scores['C']['percent']}%\n";
}

$aiPrompt .= "\n";

// Add previous weeks comparison
if (!empty($previous_weeks)) {
    $aiPrompt .= "===PROGRESS OVER TIME===\n";
    foreach ($previous_weeks as $pw) {
        $aiPrompt .= "Week {$pw['week']}: {$pw['rag']['reds']}R / {$pw['rag']['ambers']}A / {$pw['rag']['greens']}G (Score: {$pw['rag']['total_score']})";
        if ($pw['mbti']) {
            $aiPrompt .= " | MBTI: {$pw['mbti']}";
        }
        $aiPrompt .= "\n";
    }
    $aiPrompt .= "\n";
}

// Add career information
if (!empty($dream_jobs_ranking) && is_array($dream_jobs_ranking)) {
    $aiPrompt .= "Their Dream Jobs (ranked):\n";
    foreach (array_slice($dream_jobs_ranking, 0, 5) as $i => $job) {
        $aiPrompt .= ($i + 1) . ". $job\n";
    }
    $aiPrompt .= "\n";
}

$aiPrompt .= "===YOUR TASK===\n";
$aiPrompt .= "Write a warm, insightful summary that:\n";
$aiPrompt .= "1. Celebrates their strengths (Greens) specifically\n";

if (!empty($previous_weeks)) {
    $aiPrompt .= "2. Highlights progress or trends compared to previous weeks\n";
} else {
    $aiPrompt .= "3. Focuses on this week's results and what they show\n";
}

// Personality assessment instructions
if ($disc_type && $mbti_type_to_use) {
    // Both DISC and MBTI available - compare them
    $aiPrompt .= "4. IMPORTANT: Explain their DISC style ($disc_type) in detail - what does this mean for how they work and communicate?\n";
    $aiPrompt .= "5. Compare DISC ($disc_type) to MBTI ($mbti_type_to_use) - how do these personality insights work together?\n";
    $aiPrompt .= "6. Acknowledges areas for development (Ambers/Reds) with encouragement\n";
    if (!empty($dream_jobs_ranking)) {
        $aiPrompt .= "7. Connects their DISC style and MBTI type to their dream jobs\n";
    }
    $aiPrompt .= "8. Provides 2-3 specific, actionable next steps for this week, in a bullet list\n\n";
} elseif ($disc_type) {
    // Only DISC available
    $aiPrompt .= "7. IMPORTANT: Explain their DISC style ($disc_type) in detail - what does this mean for their strengths and how they work?\n";
    $aiPrompt .= "8. Acknowledges areas for development (Ambers/Reds) with encouragement\n";
    if (!empty($dream_jobs_ranking)) {
        $aiPrompt .= "7. Connects their DISC style to their dream jobs\n";
    }
    $aiPrompt .= "8. Provides 2-3 specific, actionable next steps for this week, in a bullet list\n\n";
} elseif ($mbti_type_to_use) {
    // Only MBTI available
    if ($type) {
        $aiPrompt .= "7. Explains their MBTI type ($mbti_type_to_use) and key strengths\n";
    } else {
        $aiPrompt .= "7. References their MBTI type ($mbti_type_to_use) from previous weeks\n";
    }
    $aiPrompt .= "8. Acknowledges areas for development (Ambers/Reds) with encouragement\n";
    if (!empty($dream_jobs_ranking)) {
        $aiPrompt .= "7. Connects their personality to their dream jobs\n";
    }
    $aiPrompt .= "8. Provides 2-3 specific, actionable next steps for this week, in a bullet list\n\n";
} else {
    // No personality assessments
    $aiPrompt .= "3. Acknowledges areas for development (Ambers/Reds) with encouragement\n";
    if (!empty($dream_jobs_ranking)) {
        $aiPrompt .= "4. Connects their results to their dream jobs\n";
    }
    $aiPrompt .= "8. Provides 2-3 specific, actionable next steps for this week, in a bullet list\n\n";
}

$aiPrompt .= "CRITICAL: Address them directly using 'you' and 'your'. Be encouraging, specific, and practical.\n";
//$aiPrompt .= "Use UK context. Keep to 4-5 paragraphs max. Use bullet points to help annotate points through the summary..\n";
$aiPrompt .= "Use UK context. Use bullet points to help annotate points through the summary..\n";
$aiPrompt .= "Use Steve's Solutions Mindset principles to help empasise a growth mindset and positive attitude throughout the summary.\n";
$aiPrompt .= "The principles are: 1.Say to yourself What is the solution to every problem I face?, 2.If you have a solutions mindset marginal gains will occur, \n";
$aiPrompt .= "3.There is no Failure only Feedback, 4.A smooth sea, never made a skilled sailor, 5.• If one person can do it, anyone can do it, \n";
$aiPrompt .= "6.Happiness is a journey, not an outcome, 7.You never lose…you either win or learn, 8.Character over Calibre is the best way to succeed, \n";
$aiPrompt .= "9.The person with the most passion has the greatest impact, 10.Hard work beats talent, when talent does not work hard,\n";
$aiPrompt .= "11.Everybody knows more than somebody, 12.Be the person your dog thinks you are, 13.It is nice to be important, but more important to be nice. \n";

                $aiIntro = $mwai->simpleTextQuery($aiPrompt);
            } catch (Exception $e) { 
                error_log('MFSD RAG: AI summary generation error: ' . $e->getMessage());
                $aiIntro = ''; 
            }
        }

        // Save summary to database for future use
        if (!empty($aiIntro)) {
            $wpdb->replace($ws, array(
                'user_id' => $user_id,
                'week_num' => $week,
                'reds' => (int)$agg['reds'],
                'ambers' => (int)$agg['ambers'],
                'greens' => (int)$agg['greens'],
                'total_score' => (int)$agg['total_score'],
                'mbti_type' => $type,
                'disc_type' => $disc_type,
                'ai_summary' => $aiIntro
            ), array('%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'));
            
            error_log("MFSD RAG: Saved summary to cache for week $week, user $user_id");
        }

       return new WP_REST_Response(array(
            'ok'   => true,
            'week' => $week,
            'rag'  => $agg,
            'mbti' => $type,
            'disc_type' => $disc_type,
            'disc_scores' => $disc_scores,
            'ai'   => $aiIntro,
            'previous_weeks' => $previous_weeks,
            'cached' => false
        ), 200);
    }

    private function mbti_letter_for($questionId, $answer) {
        $map = array(
            1  => array('E/I', array('R' => 'E', 'A' => 'E', 'G' => 'I')),
            2  => array('E/I', array('R' => 'E', 'A' => 'E', 'G' => 'I')),
            3  => array('E/I', array('R' => 'E', 'A' => 'E', 'G' => 'I')),
            4  => array('S/N', array('R' => 'N', 'A' => 'N', 'G' => 'S')),
            5  => array('S/N', array('R' => 'N', 'A' => 'N', 'G' => 'S')),
            6  => array('S/N', array('R' => 'N', 'A' => 'N', 'G' => 'S')),
            7  => array('T/F', array('R' => 'T', 'A' => 'T', 'G' => 'F')),
            8  => array('T/F', array('R' => 'T', 'A' => 'T', 'G' => 'F')),
            9  => array('T/F', array('R' => 'T', 'A' => 'T', 'G' => 'F')),
            10 => array('J/P', array('R' => 'P', 'A' => 'P', 'G' => 'J')),
            11 => array('J/P', array('R' => 'P', 'A' => 'P', 'G' => 'J')),
            12 => array('J/P', array('R' => 'P', 'A' => 'P', 'G' => 'J')),
        );
        
        global $wpdb;
        $qtbl = $wpdb->prefix . self::TBL_QUESTIONS;
        $q = $wpdb->get_row($wpdb->prepare("SELECT q_order FROM $qtbl WHERE id=%d", $questionId), ARRAY_A);
        $qorder = isset($q['q_order']) ? (int)$q['q_order'] : 0;

        $axis = 'X';
        $letter = 'X';
        
        if ($qorder && isset($map[$qorder])) {
            $axis = $map[$qorder][0];
            $letter = isset($map[$qorder][1][$answer]) ? $map[$qorder][1][$answer] : 'X';
        }
        
        $axis_char = 'X';
        if (strpos($axis, 'E/I') !== false) $axis_char = 'E';
        elseif (strpos($axis, 'S/N') !== false) $axis_char = 'S';
        elseif (strpos($axis, 'T/F') !== false) $axis_char = 'T';
        elseif (strpos($axis, 'J/P') !== false) $axis_char = 'J';
        
        return array($axis_char, $letter);
    }

    private function mbti_type_from_counts($rows) {
        $c = array('E' => 0, 'I' => 0, 'S' => 0, 'N' => 0, 'T' => 0, 'F' => 0, 'J' => 0, 'P' => 0);
        
        foreach ($rows as $r) {
            $L = strtoupper(isset($r['letter']) ? $r['letter'] : '');
            $cnt = isset($r['c']) ? (int)$r['c'] : 0;
            if (isset($c[$L])) {
                $c[$L] += $cnt;
            }
        }
        
        if (array_sum($c) === 0) return '';
        
        $ei = ($c['E'] >= $c['I']) ? 'E' : 'I';
        $sn = ($c['S'] >= $c['N']) ? 'S' : 'N';
        $tf = ($c['T'] >= $c['F']) ? 'T' : 'F';
        $jp = ($c['J'] >= $c['P']) ? 'J' : 'P';
        
        return $ei . $sn . $tf . $jp;
    }

    private function get_current_um_user_id() {
        if (function_exists('um_profile_id')) {
            $pid = um_profile_id();
            if ($pid) return (int)$pid;
        }
        return (int)get_current_user_id();
    }

    public function api_question_chat($req) {
        global $wpdb;
        $week = max(1, min(6, (int)$req->get_param('week')));
        $question_id = (int)$req->get_param('question_id');
        $user_message = sanitize_text_field($req->get_param('message'));
        $user_id = $this->get_current_um_user_id();
        
        if (!$user_id || !$question_id || !$user_message) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid request'), 400);
        }

        // Get the question
        $q_table = $wpdb->prefix . self::TBL_QUESTIONS;
        $question = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $q_table WHERE id=%d", $question_id
        ), ARRAY_A);

        if (!$question) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Question not found'), 404);
        }

        // Get previous answers for context
        $previous = array();
        if ($week > 1 && $question['q_type'] === 'RAG') {
            $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            $previous = $wpdb->get_results($wpdb->prepare(
                "SELECT week_num, answer FROM $a 
                 WHERE user_id=%d AND question_id=%d AND week_num < %d 
                 ORDER BY week_num ASC",
                $user_id, $question_id, $week
            ), ARRAY_A);
        }

        // Generate AI response with context
        $response = '';
        if (isset($GLOBALS['mwai'])) {
            try {
                $mwai = $GLOBALS['mwai'];
                $username = um_get_display_name($user_id);
                
                // Build context-aware system prompt
                $systemPrompt = "You are a supportive AI coach speaking directly to $username (a 12-14 year old student) about Week $week of their High Performance Pathway program. ";
                $systemPrompt .= "They are currently reflecting on this question: \"{$question['q_text']}\"\n\n";
                
                if ($question['q_type'] === 'MBTI') {
                    $systemPrompt .= "This is an MBTI personality assessment question. Your role is to:\n";
                    $systemPrompt .= "- Help them understand what the question is exploring about their personality\n";
                    $systemPrompt .= "- Guide them to answer honestly (Red = doesn't describe you, Amber = sometimes/unsure, Green = describes you well)\n";
                    $systemPrompt .= "- Remind them there are no right or wrong answers\n";
                } else {
                    $systemPrompt .= "This is a RAG self-assessment question about their skills and readiness. Your role is to:\n";
                    $systemPrompt .= "- Help them reflect on their current level (Red = struggling/need support, Amber = mixed/uncertain, Green = confident/strength)\n";
                    $systemPrompt .= "- Provide practical suggestions if they're unsure\n";
                    $systemPrompt .= "- Encourage growth mindset thinking\n";
                    
                    if (!empty($previous)) {
                        $systemPrompt .= "\nFor context, in previous weeks they answered:\n";
                        foreach ($previous as $ans) {
                            $label = ($ans['answer'] === 'R') ? 'Red (struggling)' : 
                                    (($ans['answer'] === 'A') ? 'Amber (mixed)' : 'Green (confident)');
                            $systemPrompt .= "Week {$ans['week_num']}: $label\n";
                        }
                    }
                }
                
                $systemPrompt .= "\nCRITICAL: Address $username directly using 'you' and 'your'. Say 'when you faced...' NOT 'when $username faced...' or 'when he/she faced...'\n";
                $systemPrompt .= "Keep responses concise (2-3 sentences), warm, age-appropriate, and always relate back to THIS specific question. ";
                $systemPrompt .= "Don't go off-topic or discuss unrelated subjects. Focus on helping them answer THIS question thoughtfully.";
                
                // Use AI Engine to generate response
                $fullPrompt = $systemPrompt . "\n\nStudent asks: " . $user_message;
                $response = $mwai->simpleTextQuery($fullPrompt);
                
            } catch (Exception $e) {
                error_log('MFSD RAG: Chat error: ' . $e->getMessage());
                $response = "I'm having trouble connecting right now. Please try asking your question again in a moment.";
            }
        } else {
            $response = "AI assistance is currently unavailable.";
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'response' => $response
        ), 200);
    }

    public function admin_menu() {
        add_menu_page('MFSD RAG', 'MFSD RAG', 'manage_options', 'mfsd-rag', array($this, 'admin_page'), 'dashicons-forms', 66);
    }
    
    public function admin_page() {
        echo '<div class="wrap"><h1>MFSD Weekly RAG</h1>';
        echo '<p>Add questions to <code>' . esc_html($GLOBALS['wpdb']->prefix . self::TBL_QUESTIONS) . '</code></p>';
        echo '</div>';
    }
}

MFSD_Weekly_RAG::instance();