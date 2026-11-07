<?php
/**
 * MFSD_Parent_Tabs — shared parent view for task end screens.
 *
 * Platform rule (DREAM_JUNK_JOBS_TECH_SPEC_v1_2_0 §14.3): the child sees their own results;
 * a parent coming through Course Progress sees two tabs — "{Child}'s view" (exactly what the
 * child sees, read-only, including flagged entries) and "Parent view" (Steve's adult assessment).
 * Parents see everything, skip the ordering gate and never write task status.
 *
 * Copied UNCHANGED into life-wheel and mfsd-dream-life (class_exists guard).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'MFSD_Parent_Tabs' ) ) {

class MFSD_Parent_Tabs {

    const CONCERN_TITLE = 'Something to be aware of';

    private static $styles_printed = false;

    /** ?student_id= from the Course Progress link (0 if absent). */
    public static function requested_student_id(): int {
        return isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;
    }

    /** Parent role, or ?student_id= given and not the current user (Word Association pattern). */
    public static function is_parent_view(): bool {
        $uid = get_current_user_id();
        if ( ! $uid ) return false;
        $requested = self::requested_student_id();
        return get_user_meta( $uid, 'mfsd_role', true ) === 'parent'
            || ( $requested > 0 && $requested !== $uid );
    }

    /**
     * True if $parent_id is actively linked to $student_id (MFSD admins always pass).
     * @return true|WP_Error 403 when not linked.
     */
    public static function assert_linked( int $parent_id, int $student_id ) {
        if ( $parent_id <= 0 || $student_id <= 0 ) {
            return new WP_Error( 'forbidden', 'You are not linked to this student.', array( 'status' => 403 ) );
        }
        if ( user_can( $parent_id, 'manage_options' ) ) return true;
        global $wpdb;
        $linked = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mfsd_parent_student_links
             WHERE parent_user_id = %d AND student_user_id = %d AND link_status = 'active'",
            $parent_id, $student_id
        ) );
        return $linked > 0 ? true
            : new WP_Error( 'forbidden', 'You are not linked to this student.', array( 'status' => 403 ) );
    }

    /** First name for the "{Child}'s view" tab label. */
    public static function child_first_name( int $student_id ): string {
        $u = get_userdata( $student_id );
        if ( ! $u ) return 'Your child';
        $first = trim( (string) get_user_meta( $student_id, 'first_name', true ) );
        if ( $first === '' ) {
            $parts = preg_split( '/\s+/', trim( $u->display_name ) );
            $first = $parts[0] ?? '';
        }
        return $first !== '' ? $first : 'Your child';
    }

    /**
     * Tab bar + two panels. An amber dot shows on the Parent view tab when there's an open flag.
     */
    public static function render_tabs( string $child_html, string $parent_html, bool $has_open_flag, string $child_name = '' ): string {
        $child_label = ( $child_name !== '' ? $child_name : 'Your child' ) . "'s view";
        $uid         = 'mfsd-pt-' . wp_rand( 1000, 999999 );
        ob_start();
        self::print_styles();
        ?>
        <div class="mfsd-pt" id="<?php echo esc_attr( $uid ); ?>">
            <div class="mfsd-pt__bar" role="tablist">
                <button type="button" class="mfsd-pt__tab is-active" role="tab" aria-selected="true" data-pt="child"><?php echo esc_html( $child_label ); ?></button>
                <button type="button" class="mfsd-pt__tab" role="tab" aria-selected="false" data-pt="parent">Parent view<?php if ( $has_open_flag ) : ?><span class="mfsd-pt__dot" aria-label="Something to be aware of"></span><?php endif; ?></button>
            </div>
            <div class="mfsd-pt__panel is-active" data-pt-panel="child"><?php echo $child_html; // phpcs:ignore -- plugin-rendered ?></div>
            <div class="mfsd-pt__panel" data-pt-panel="parent"><?php echo $parent_html; // phpcs:ignore -- kses'd by build_parent_html() ?></div>
        </div>
        <script>
        (function(){var r=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!r)return;
        r.querySelectorAll('.mfsd-pt__tab').forEach(function(t){t.addEventListener('click',function(){
        r.querySelectorAll('.mfsd-pt__tab').forEach(function(x){var on=x===t;x.classList.toggle('is-active',on);x.setAttribute('aria-selected',on?'true':'false');});
        r.querySelectorAll('.mfsd-pt__panel').forEach(function(p){p.classList.toggle('is-active',p.getAttribute('data-pt-panel')===t.getAttribute('data-pt'));});
        r.dispatchEvent(new CustomEvent('mfsd-pt-change',{detail:t.getAttribute('data-pt')}));});});})();
        </script>
        <?php
        return ob_get_clean();
    }

    /** Cached in the task's own parent_ai_summary column; $builder() returns the text to cache ('' = don't cache). */
    public static function get_or_generate_parent_summary( string $table, int $user_id, callable $builder ): string {
        global $wpdb;
        $cached = (string) $wpdb->get_var( $wpdb->prepare( "SELECT parent_ai_summary FROM $table WHERE user_id = %d LIMIT 1", $user_id ) );
        if ( $cached !== '' ) return $cached;
        $text = (string) call_user_func( $builder );
        if ( $text !== '' ) {
            $wpdb->update( $table, array( 'parent_ai_summary' => $text ), array( 'user_id' => $user_id ) );
        }
        return $text;
    }

    public static function clear_parent_summary( string $table, int $user_id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "UPDATE $table SET parent_ai_summary = NULL WHERE user_id = %d", $user_id ) );
    }

    /** Pulls the final "PARENT_NOTE: …" line out of the AI text. Returns the note ('' if none). */
    public static function extract_parent_note( string &$text ): string {
        $note = '';
        if ( preg_match( '/^\s*PARENT_NOTE\s*:\s*(.+)$/mi', $text, $m ) ) {
            $note = trim( $m[1] );
        }
        $text = trim( preg_replace( '/^\s*PARENT_NOTE\s*:.*$/mi', '', $text ) );
        return strtolower( $note ) === 'none' ? '' : $note;
    }

    /** Splits "[SECTION:Title]" text into [['title'=>..., 'body'=>...], ...]. */
    public static function parse_sections( string $text ): array {
        $parts = preg_split( '/\[\s*SECTION\s*:\s*([^\]]+)\]/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        $out   = array();
        $lead  = trim( $parts[0] ?? '' );
        if ( $lead !== '' ) $out[] = array( 'title' => '', 'body' => $lead );
        for ( $i = 1; $i < count( $parts ); $i += 2 ) {
            $out[] = array( 'title' => trim( $parts[ $i ] ), 'body' => trim( $parts[ $i + 1 ] ?? '' ) );
        }
        return $out;
    }

    /**
     * Renders the parent summary. When $lead_title is given and the AI didn't put that section
     * first, $lead_fallback_html is shown first instead — a concern is never lost to an AI failure.
     */
    public static function build_parent_html( string $text, string $lead_title = '', string $lead_fallback_html = '' ): string {
        $sections = self::parse_sections( $text );
        $html     = '';
        $has_lead = false;
        foreach ( $sections as $i => $s ) {
            if ( $lead_title !== '' && strcasecmp( $s['title'], $lead_title ) === 0 ) {
                $is_first = ( $i === 0 || ( $i === 1 && $sections[0]['title'] === '' ) );
                if ( ! $is_first ) continue; // AI put it later — the hard-coded block leads instead
                $has_lead = true;
            }
            $cls   = ( $lead_title !== '' && strcasecmp( $s['title'], $lead_title ) === 0 ) ? ' mfsd-pt__section--aware' : '';
            $html .= '<div class="mfsd-pt__section' . $cls . '">';
            if ( $s['title'] !== '' ) $html .= '<h3>' . esc_html( $s['title'] ) . '</h3>';
            $html .= self::paragraphs( $s['body'] ) . '</div>';
        }
        if ( $lead_title !== '' && ! $has_lead && $lead_fallback_html !== '' ) {
            $html = $lead_fallback_html . $html;
        }
        return '<div class="mfsd-pt__parent">' . $html . '</div>';
    }

    /** Hard-coded "Something to be aware of" block listing the flagged text (§14.3). */
    public static function concern_fallback_html( array $flags, string $child_name ): string {
        if ( empty( $flags ) ) return '';
        $urgent = false;
        $items  = '';
        foreach ( $flags as $f ) {
            // Callers pass parent-visible flags only, so 'moderation' here means released: treat as urgent.
            if ( in_array( $f['severity'] ?? '', array( 'urgent', 'moderation' ), true ) ) $urgent = true;
            $items .= '<li><strong>' . esc_html( $f['item_text'] ) . '</strong>';
            if ( ! empty( $f['reason_text'] ) ) $items .= ': <em>"' . esc_html( $f['reason_text'] ) . '"</em>';
            $items .= '</li>';
        }
        $name = esc_html( $child_name !== '' ? $child_name : 'Your child' );
        $html  = '<div class="mfsd-pt__section mfsd-pt__section--aware"><h3>' . esc_html( self::CONCERN_TITLE ) . '</h3>';
        $html .= '<p>' . $name . ' wrote something in this task that may be worth a gentle conversation:</p><ul>' . $items . '</ul>';
        $html .= '<p>Try to stay calm and curious rather than worried — it may be nothing, and it helps not to jump to conclusions. A few ways to start:</p><ul>'
               . '<li>Pick a relaxed moment (a walk, a car journey) and ask an open question about how things are going.</li>'
               . '<li>Mention that you saw their answers and you\'re proud they were honest, then ask what they meant.</li>'
               . '<li>Let them know they can always come to you, or to a trusted adult at school.</li></ul>';
        if ( $urgent ) {
            $html .= '<p><strong>If you are worried about their safety:</strong> speak to your GP or call NHS 111 for advice, and call 999 in an emergency. '
                   . 'Childline (0800 1111) and the NSPCC helpline (0808 800 5000) offer free, confidential support.</p>';
        }
        return $html . '</div>';
    }

    private static function paragraphs( string $body ): string {
        $body = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', esc_html( $body ) );
        $out  = '';
        foreach ( preg_split( '/\n\s*\n/', $body ) as $p ) {
            $p = trim( $p );
            if ( $p === '' ) continue;
            $lines = array_map( 'trim', explode( "\n", $p ) );
            $is_list = count( $lines ) > 1 && count( array_filter( $lines, function ( $l ) { return preg_match( '/^([-•*]|\d+[.)])\s+/', $l ); } ) ) === count( $lines );
            if ( $is_list ) {
                $out .= '<ul>' . implode( '', array_map( function ( $l ) { return '<li>' . preg_replace( '/^([-•*]|\d+[.)])\s+/', '', $l ) . '</li>'; }, $lines ) ) . '</ul>';
            } else {
                $out .= '<p>' . implode( '<br>', $lines ) . '</p>';
            }
        }
        return wp_kses_post( $out );
    }

    private static function print_styles(): void {
        if ( self::$styles_printed ) return;
        self::$styles_printed = true;
        ?>
        <style>
        .mfsd-pt{max-width:1000px;margin:0 auto;font-family:'Nunito',sans-serif}
        .mfsd-pt__bar{display:flex;gap:6px;margin:0 0 16px;padding:6px;border-radius:12px;background:#0F1628;border:1px solid rgba(0,212,255,.25)}
        .mfsd-pt__tab{flex:1 1 0;min-width:0;position:relative;padding:12px 10px;border:0;border-radius:8px;background:transparent;color:#B8C0D0;font:600 15px/1.2 'Exo 2',sans-serif;cursor:pointer}
        .mfsd-pt__tab.is-active{background:#00D4FF;color:#0A0E1A}
        .mfsd-pt__dot{display:inline-block;width:10px;height:10px;margin-left:8px;border-radius:50%;background:#F59E0B;vertical-align:middle;box-shadow:0 0 0 2px rgba(245,158,11,.3)}
        .mfsd-pt__panel{display:none}.mfsd-pt__panel.is-active{display:block}
        .mfsd-pt__parent{background:#fff;color:#1F2937;border-radius:12px;padding:20px;border:1px solid #E5E7EB}
        .mfsd-pt__section{margin:0 0 18px}.mfsd-pt__section:last-child{margin-bottom:0}
        .mfsd-pt__section h3{margin:0 0 8px;font:700 18px/1.3 'Exo 2',sans-serif;color:#111827}
        .mfsd-pt__section p,.mfsd-pt__section li{font-size:15px;line-height:1.6;color:#374151}
        .mfsd-pt__section ul{margin:0 0 10px 20px;padding:0}
        .mfsd-pt__section--aware{background:#FFFBEB;border:1px solid #F59E0B;border-left:5px solid #F59E0B;border-radius:10px;padding:14px 16px}
        .mfsd-pt__loading{padding:30px;text-align:center;color:#6B7280}
        @media (max-width:480px){.mfsd-pt__tab{font-size:13px;padding:10px 6px}.mfsd-pt__parent{padding:14px}}
        </style>
        <?php
    }
}

}
