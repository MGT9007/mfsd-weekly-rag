<?php
/**
 * MFSD shared helpers — student age, minimum words, SteveGPT helpers.
 *
 * The concern-flag functions that used to live here (mfsd-concern-flags.php, Dream & Junk Jobs §14.1–14.2)
 * moved to the central MFSD Safeguarding plugin (mfsd-safeguarding v1.0.0). Do NOT re-add them:
 * this file loads before mfsd-safeguarding (alphabetical plugin order) and would shadow it.
 *
 * Copied unchanged into ai-junk-jobs, life-wheel and mfsd-dream-life; every function is
 * function_exists()-guarded so whichever plugin loads first defines it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ────────────────────────────────────────────────────────────────────
   Student age — pp_dob first, then dob (fixes the old date_of_birth bug)
   ──────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'mfsd_get_student_age' ) ) {
    function mfsd_get_student_age( int $user_id ): ?int {
        $dob = get_user_meta( $user_id, 'pp_dob', true );
        if ( empty( $dob ) ) $dob = get_user_meta( $user_id, 'dob', true );
        if ( empty( $dob ) || ! is_string( $dob ) ) return null;
        $dob  = trim( $dob );
        $date = null;
        foreach ( array( 'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd-M-Y', 'd-M-y', 'Y/m/d' ) as $fmt ) {
            $d = DateTime::createFromFormat( '!' . $fmt, $dob );
            if ( $d && $d->format( $fmt ) === $dob ) { $date = $d; break; }
        }
        if ( ! $date ) {
            try { $date = new DateTime( $dob ); } catch ( Exception $e ) { return null; }
        }
        $age = (int) $date->diff( new DateTime( 'today' ) )->y;
        return ( $age > 3 && $age < 100 ) ? $age : null;
    }
}

if ( ! function_exists( 'mfsd_min_words_for_age' ) ) {
    /** Ages 10–11 / 12 / 13 / 14+ → 20 / 30 / 50 / 100 words; unknown age → 30 (Mark, 23 Oct 2026). */
    function mfsd_min_words_for_age( ?int $age ): int {
        if ( $age === null ) return 30;
        if ( $age >= 14 ) return 100;
        if ( $age >= 13 ) return 50;
        if ( $age >= 12 ) return 30;
        return 20;
    }
}

/* ────────────────────────────────────────────────────────────────────
   14.4  SteveGPT helpers
   ──────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'mfsd_steve_chatbot' ) ) {
    /**
     * The SteveGPT chatbot mapped to $option, or null when SteveGPT is unavailable
     * (class missing, mapping empty, or chatbot record missing/inactive).
     */
    function mfsd_steve_chatbot( string $option ) {
        if ( ! class_exists( 'SteveGPT_Chatbot' ) ) return null;
        $id = (string) get_option( $option, '' );
        if ( $id === '' ) return null;
        try {
            return SteveGPT_Chatbot::get( $id );
        } catch ( Exception $e ) {
            error_log( 'MFSD SteveGPT mapping ' . $option . ' unusable: ' . $e->getMessage() );
            return null;
        }
    }
}

if ( ! function_exists( 'mfsd_steve_query' ) ) {
    /**
     * One task query; returns '' on failure so the caller can use its fallback text.
     * $ctx: optional safeguarding context (Safeguarding Prompt Layer §3.2). When given, SteveGPT 14.0.0+
     * appends the safeguarding block and strips/acts on [CONCERN:] markers itself.
     */
    function mfsd_steve_query( $chatbot, string $prompt, int $user_id, string $log_label = '', array $ctx = array() ): string {
        if ( ! $chatbot ) return '';
        try {
            $options = $ctx ? array( 'safeguarding' => $ctx ) : array();
            return trim( (string) $chatbot->query( $prompt, $user_id, $options ) );
        } catch ( Exception $e ) {
            error_log( 'MFSD SteveGPT call failed' . ( $log_label ? " ($log_label)" : '' ) . ': ' . $e->getMessage() );
            return '';
        }
    }
}

if ( ! function_exists( 'mfsd_steve_chat_context' ) ) {
    /** Strips ", [, ] and newlines so the string is safe in a [stevegpt_chatbot context=""] attribute. */
    function mfsd_steve_chat_context( string $text ): string {
        $text = str_replace( array( '"', '[', ']', "\r", "\n" ), array( '', '', '', '', ' ' ), $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }
}

if ( ! function_exists( 'mfsd_steve_missing_mappings' ) ) {
    /** Which of these mapping options are empty (for the admin notice). */
    function mfsd_steve_missing_mappings( array $options ): array {
        $missing = array();
        foreach ( $options as $opt ) {
            if ( (string) get_option( $opt, '' ) === '' ) $missing[] = $opt;
        }
        return $missing;
    }
}
