<?php
/**
 * Plugin Name: MFSD Weekly RAG + MBTI
 * Description: Weekly RAG (26) + MBTI (12) survey over 6 weeks with UM integration, AI summaries, and results storage. 
 * Use shoprtcode [mfsd_rag] on a page titled "Week X RAG" (X=1..6) to render.
 * Version: 0.1.1
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

final class MFSD_Weekly_RAG {
    const VERSION = '0.1.1';
    const NONCE_ACTION = 'mfsd_rag_nonce';

    // DB table names (without prefix)
    const TBL_QUESTIONS = 'mfsd_rag_questions';
    const TBL_ANSWERS_RAG = 'mfsd_rag_answers';
    const TBL_ANSWERS_MB = 'mfsd_mbti_answers';
    const TBL_MB_RESULTS = 'mfsd_mbti_results';

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }
    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'install']);
        add_action('init', [$this,'assets']);
        add_shortcode('mfsd_rag', [$this,'shortcode']);
        add_action('rest_api_init', [$this,'register_routes']);
        add_action('admin_menu', [$this,'admin_menu']);
    }

    /** Create DB tables */
    public function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $q = $wpdb->prefix . self::TBL_QUESTIONS;
        $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
        $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
        $mbr = $wpdb->prefix . self::TBL_MB_RESULTS;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Questions config (RAG + MBTI)
        dbDelta("
        CREATE TABLE $q (
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
        ) $charset;
        ");

        // Weekly RAG answers
        dbDelta("
        CREATE TABLE $a (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          week_num TINYINT NOT NULL,
          question_id BIGINT UNSIGNED NOT NULL,
          answer ENUM('R','A','G') NOT NULL,
          score INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_user_week (user_id, week_num)
        ) $charset;
        ");

        // Weekly MBTI answers
        dbDelta("
        CREATE TABLE $mb (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          week_num TINYINT NOT NULL,
          question_id BIGINT UNSIGNED NOT NULL,
          answer ENUM('R','A','G') NOT NULL,
          axis CHAR(1) NOT NULL,   -- E/I, S/N, T/F, J/P (derived per Q)
          letter CHAR(1) NOT NULL, -- which letter this answer contributes to
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_user_week (user_id, week_num)
        ) $charset;
        ");

        // MBTI weekly result
        dbDelta("
        CREATE TABLE $mbr (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          week_num TINYINT NOT NULL,
          type4 CHAR(4) NOT NULL,  -- e.g., INFP
          details JSON NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_user_week (user_id, week_num)
        ) $charset;
        ");
    }

     /** Enqueue JS/CSS and pass config */
public function assets() {
    $h = 'mfsd-weekly-rag';
    $base = plugin_dir_url( __FILE__ );

    wp_register_script(
        $h,
        $base . 'assets/mfsd-weekly-rag.js',   // <-- FIXED PATH
        ['wp-element'],
        self::VERSION,
        true
    );

    wp_register_style(
        $h,
        $base . 'assets/mfsd-weekly-rag.css', // <-- FIXED PATH
        [],
        self::VERSION
    );

    wp_enqueue_script( $h );
    wp_enqueue_style( $h );
}


    /** Shortcode output */
    public function shortcode($atts) {
        // Figure week number from page title "Week X RAG"
        $week = 1;
        if (is_page()) {
            $title = get_the_title();
            if (preg_match('/Week\s*([1-6])\s*RAG/i', $title, $m)) {
                $week = (int)$m[1];
            }
        }
        // Pass config down
        $cfg = [
            'restUrl' => esc_url_raw( rest_url('mfsd/v1') ),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'week'    => $week,
            'user'    => $this->get_current_um_user_id(),
            'aiChatId'=> 'chatbot-vxk8pu', // for reference if you want the embedded chat
        ];
        wp_enqueue_script('mfsd-weekly-rag');
        wp_enqueue_style('mfsd-weekly-rag');
        wp_add_inline_script('mfsd-weekly-rag', 'window.MFSD_RAG_CFG='.wp_json_encode($cfg).';', 'before');

        // Render hidden AI chat source if needed later (same trick we used before)
        $chat_html = do_shortcode('[mwai_chatbot id="chatbot-vxk8pu"]');
        $out  = '<div id="mfsd-rag-root"></div>';
        $out .= '<div id="mfsd-rag-chat-source" style="display:none">'.$chat_html.'</div>';

        return $out;
    }

    /** REST routes */
    public function register_routes() {
        register_rest_route('mfsd/v1', '/questions', [
            'methods'  => 'GET',
            'callback' => [$this,'api_questions'],
            'permission_callback' => [$this,'must_be_logged_in'],
        ]);
        register_rest_route('mfsd/v1', '/answer', [
            'methods'  => 'POST',
            'callback' => [$this,'api_answer'],
            'permission_callback' => [$this,'must_be_logged_in'],
        ]);
        register_rest_route('mfsd/v1', '/summary', [
            'methods'  => 'POST',
            'callback' => [$this,'api_summary'],
            'permission_callback' => [$this,'must_be_logged_in'],
        ]);
    }

    public function must_be_logged_in() {
        return is_user_logged_in();
    }

    /** Get interleaved questions (RAG + MBTI) for a week, honoring active flags */
    public function api_questions(WP_REST_Request $req) : WP_REST_Response {
        global $wpdb;
        $week = max(1, min(6, (int)$req->get_param('week')));
        $q = $wpdb->prefix . self::TBL_QUESTIONS;

        // Active filter column e.g. w1..w6
        $wkcol = 'w'.$week;

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $q WHERE $wkcol=1 ORDER BY q_type='MBTI', q_order ASC"), ARRAY_A
        );

        // Split types
        $rag = array_values(array_filter($rows, fn($r)=>$r['q_type']==='RAG'));
        $mb  = array_values(array_filter($rows, fn($r)=>$r['q_type']==='MBTI'));

        // Interleave: after every 2 RAG insert 1 MBTI (no repeats)
        $out = [];
        $iR=0; $iM=0;
        while ($iR<count($rag) || $iM<count($mb)) {
            for ($k=0; $k<2 && $iR<count($rag); $k++) $out[] = $rag[$iR++];
            if ($iM<count($mb)) $out[] = $mb[$iM++];
        }

        return new WP_REST_Response(['ok'=>true,'questions'=>$out],200);
    }

    /** Save a single answer (RAG or MBTI), compute score/letter */
    public function api_answer(WP_REST_Request $req) : WP_REST_Response {
        try {
            check_ajax_referer(self::NONCE_ACTION,'_wpnonce');

            global $wpdb;
            $week       = max(1, min(6, (int)$req['week']));
            $questionId = (int)$req['question_id'];
            $answer     = strtoupper(sanitize_text_field($req['answer'] ?? '')); // R/A/G

            // Question info
            $q = $wpdb->prefix . self::TBL_QUESTIONS;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $q WHERE id=%d",$questionId), ARRAY_A);
            if (!$row) return new WP_REST_Response(['ok'=>false,'error'=>'Question not found'],404);

            $user_id = $this->get_current_um_user_id();
            if (!$user_id) return new WP_REST_Response(['ok'=>false,'error'=>'Not logged in'],403);

            if ($row['q_type']==='RAG') {
                $score = ($answer==='R') ? (int)$row['red_score'] : (($answer==='A') ? (int)$row['amber_score'] : (int)$row['green_score']);
                $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
                $wpdb->insert($a, [
                    'user_id'=>$user_id, 'week_num'=>$week, 'question_id'=>$questionId,
                    'answer'=>$answer, 'score'=>$score
                ], ['%d','%d','%d','%s','%d']);
                return new WP_REST_Response(['ok'=>true],200);
            } else {
                // MBTI letter mapping based on Appendix E (R/A/G -> letter).
                // Define question->axis + mapping. You can update later in DB if desired.
                [$axis,$letter] = $this->mbti_letter_for($questionId,$answer);
                $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
                $wpdb->insert($mb, [
                    'user_id'=>$user_id, 'week_num'=>$week, 'question_id'=>$questionId,
                    'answer'=>$answer, 'axis'=>$axis, 'letter'=>$letter
                ], ['%d','%d','%d','%s','%s','%s']);
                return new WP_REST_Response(['ok'=>true],200);
            }

        } catch (Throwable $e) {
            return new WP_REST_Response(['ok'=>false,'error'=>'Server error: '.$e->getMessage()],500);
        }
    }

    /** Compute weekly summary + MBTI type and store */
    public function api_summary(WP_REST_Request $req) : WP_REST_Response {
        try {
            check_ajax_referer(self::NONCE_ACTION,'_wpnonce');
            global $wpdb;
            $week = max(1, min(6, (int)$req['week']));
            $user_id = $this->get_current_um_user_id();
            if (!$user_id) return new WP_REST_Response(['ok'=>false,'error'=>'Not logged in'],403);

            // RAG aggregates
            $a = $wpdb->prefix . self::TBL_ANSWERS_RAG;
            $agg = $wpdb->get_row($wpdb->prepare("
              SELECT
                SUM(answer='R') AS reds,
                SUM(answer='A') AS ambers,
                SUM(answer='G') AS greens,
                SUM(score)      AS total_score
              FROM $a WHERE user_id=%d AND week_num=%d
            ", $user_id,$week), ARRAY_A) ?: ['reds'=>0,'ambers'=>0,'greens'=>0,'total_score'=>0];

            // MBTI compute type
            $mb = $wpdb->prefix . self::TBL_ANSWERS_MB;
            $letters = $wpdb->get_results($wpdb->prepare("
              SELECT axis, letter, COUNT(*) c FROM $mb
              WHERE user_id=%d AND week_num=%d
              GROUP BY axis, letter
            ", $user_id,$week), ARRAY_A);

            $type = $this->mbti_type_from_counts($letters);
            $mbr = $wpdb->prefix . self::TBL_MB_RESULTS;
            if ($type) {
                $wpdb->replace($mbr, [
                    'user_id'=>$user_id,
                    'week_num'=>$week,
                    'type4'=>$type,
                    'details'=> wp_json_encode($letters),
                ], ['%d','%d','%s','%s']);
            }

            // Optional AI: build intro / summary using MWAI if present
            $aiIntro = '';
            if (isset($GLOBALS['mwai'])) {
                try {
                    $mwai = $GLOBALS['mwai'];
                    $intro = "High Performance Pathway RAG + MBTI Weekly Tracker\nTask: Complete this RAG for 6 weeks.\nGreens=Strengths; Ambers=Mixed; Reds=Needs support.";
                    $aiPrompt = $intro . "\n\nSummarise this week's answers (R/A/G totals: "
                              . "{$agg['reds']} red, {$agg['ambers']} amber, {$agg['greens']} green; score {$agg['total_score']}). "
                              . "MBTI this week: ".($type?:'undetermined').". Keep tone warm, practical, UK context.";
                    $aiIntro = $mwai->simpleTextQuery($aiPrompt);
                } catch (Throwable $e) { $aiIntro=''; }
            }

            return new WP_REST_Response([
                'ok'=>true,
                'rag'=>$agg,
                'mbti'=>$type,
                'ai'=>$aiIntro
            ],200);

        } catch (Throwable $e) {
            return new WP_REST_Response(['ok'=>false,'error'=>'Server error: '.$e->getMessage()],500);
        }
    }

    /** Map MBTI for a question & answer R/A/G → letter (Appendix E) */
    private function mbti_letter_for($questionId,$answer) : array {
        // Minimal seed mapping based on your Appendix E (update IDs to match seeded data)
        // For the initial cut we assume QIDs 1001..1012 are MBTI in q_order 1..12.
        // You can move this mapping into the DB later.
        $map = [
            // q_order => [axis, ['R'=>'X','A'=>'X','G'=>'Y']]
            1  => ['E/I', ['R'=>'E','A'=>'E','G'=>'I']],
            2  => ['E/I', ['R'=>'E','A'=>'E','G'=>'I']],
            3  => ['E/I', ['R'=>'E','A'=>'E','G'=>'I']],

            4  => ['S/N', ['R'=>'N','A'=>'N','G'=>'S']],
            5  => ['S/N', ['R'=>'N','A'=>'N','G'=>'S']],
            6  => ['S/N', ['R'=>'N','A'=>'N','G'=>'S']],

            7  => ['T/F', ['R'=>'T','A'=>'T','G'=>'F']],
            8  => ['T/F', ['R'=>'T','A'=>'T','G'=>'F']],
            9  => ['T/F', ['R'=>'T','A'=>'T','G'=>'F']],

            10 => ['J/P', ['R'=>'P','A'=>'P','G'=>'J']],
            11 => ['J/P', ['R'=>'P','A'=>'P','G'=>'J']],
            12 => ['J/P', ['R'=>'P','A'=>'P','G'=>'J']],
        ];
        // Look up q_order for the MBTI question id:
        global $wpdb;
        $qtbl = $wpdb->prefix . self::TBL_QUESTIONS;
        $q = $wpdb->get_row($wpdb->prepare("SELECT q_order FROM $qtbl WHERE id=%d",$questionId), ARRAY_A);
        $qorder = (int)($q['q_order'] ?? 0);

        $axis = 'X';
        $letter = 'X';
        if ($qorder && isset($map[$qorder])) {
            $axis = $map[$qorder][0];
            $letter = $map[$qorder][1][ $answer ] ?? 'X';
        }
        // Normalize axis to single char bucket for counting
        $axis_char = match(true) {
            str_contains($axis,'E/I') => 'E',
            str_contains($axis,'S/N') => 'S',
            str_contains($axis,'T/F') => 'T',
            str_contains($axis,'J/P') => 'J',
            default => 'X'
        };
        return [$axis_char,$letter];
    }

    /** Build 4-letter type from counts */
    private function mbti_type_from_counts(array $rows) : string {
        // Aggregate letters by axis
        $c = ['E'=>0,'I'=>0,'S'=>0,'N'=>0,'T'=>0,'F'=>0,'J'=>0,'P'=>0];
        foreach ($rows as $r) {
            $L = strtoupper($r['letter'] ?? '');
            $cnt = (int)($r['c'] ?? 0);
            if (isset($c[$L])) $c[$L] += $cnt;
        }
        if (array_sum($c)===0) return '';
        $ei = ($c['E'] >= $c['I']) ? 'E' : 'I';
        $sn = ($c['S'] >= $c['N']) ? 'S' : 'N';
        $tf = ($c['T'] >= $c['F']) ? 'T' : 'F';
        $jp = ($c['J'] >= $c['P']) ? 'J' : 'P';
        return $ei.$sn.$tf.$jp;
    }

    /** UM aware user id */
    private function get_current_um_user_id() : int {
        // If Ultimate Member helper available, try that; else WP user
        if (function_exists('um_profile_id')) {
            $pid = um_profile_id();
            if ($pid) return (int)$pid;
        }
        return (int)get_current_user_id();
    }

    /** Minimal admin page to seed/view tables */
    public function admin_menu() {
        add_menu_page('MFSD RAG','MFSD RAG','manage_options','mfsd-rag',[$this,'admin_page'],'dashicons-forms',66);
    }
    public function admin_page() {
        echo '<div class="wrap"><h1>MFSD Weekly RAG</h1>';
        echo '<p>Initial cut. Use an SQL import (or a tiny seeder) to add the 26 RAG + 12 MBTI questions into <code>'.esc_html($GLOBALS['wpdb']->prefix.self::TBL_QUESTIONS).'</code>. You can later build a UI.</p>';
        echo '</div>';
    }
}
MFSD_Weekly_RAG::instance();
