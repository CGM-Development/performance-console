<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Opt-in runtime levers.
 *
 * Two well-understood, reversible changes that reduce work the site does not
 * need: the Heartbeat API interval (and disabling it for logged-out visitors)
 * and a cap on post revisions.
 *
 * Both are off by default and neither touches a file, a drop-in or a table.
 * Every toggle is recorded before and after so it can be reverted, and the
 * change-impact ledger will measure whether it actually helped.
 */
final class PFC_Runtime_Levers {
    const OPTION = 'pfc_runtime_levers';
    const CHANGE_TYPE = 'runtime_levers';
    const HEARTBEAT_MIN = 15;
    const HEARTBEAT_MAX = 120;
    const REVISIONS_MIN = 0;
    const REVISIONS_MAX = 20;

    const DEFAULTS = array(
        'heartbeat' => array( 'enabled' => false, 'interval' => 60 ),
        'revisions' => array( 'enabled' => false, 'keep' => 5 ),
    );

    /**
     * Coerce a value to boolean without the `(bool) 'false' === true` trap that
     * made REST autoload toggles ambiguous. Accepts form-encoded strings.
     */
    public static function truthy( $value ) {
        if ( is_bool( $value ) ) { return $value; }
        if ( is_int( $value ) || is_float( $value ) ) { return 0 !== (int) $value; }
        if ( is_string( $value ) ) {
            $normalized = strtolower( trim( $value ) );
            if ( in_array( $normalized, array( 'false', '0', 'no', 'off', '' ), true ) ) { return false; }
            if ( in_array( $normalized, array( 'true', '1', 'yes', 'on' ), true ) ) { return true; }
        }
        return (bool) $value;
    }

    /** Pure: clamp and coerce any input into the stored shape. */
    public static function normalise( array $input ) {
        $out = self::DEFAULTS;
        $heartbeat = isset( $input['heartbeat'] ) && is_array( $input['heartbeat'] ) ? $input['heartbeat'] : array();
        if ( array_key_exists( 'enabled', $heartbeat ) ) { $out['heartbeat']['enabled'] = self::truthy( $heartbeat['enabled'] ); }
        if ( isset( $heartbeat['interval'] ) && is_numeric( $heartbeat['interval'] ) ) {
            $out['heartbeat']['interval'] = (int) max( self::HEARTBEAT_MIN, min( self::HEARTBEAT_MAX, (int) $heartbeat['interval'] ) );
        }
        $revisions = isset( $input['revisions'] ) && is_array( $input['revisions'] ) ? $input['revisions'] : array();
        if ( array_key_exists( 'enabled', $revisions ) ) { $out['revisions']['enabled'] = self::truthy( $revisions['enabled'] ); }
        if ( isset( $revisions['keep'] ) && is_numeric( $revisions['keep'] ) ) {
            $out['revisions']['keep'] = (int) max( self::REVISIONS_MIN, min( self::REVISIONS_MAX, (int) $revisions['keep'] ) );
        }
        return $out;
    }

    public static function state() {
        $stored = get_option( self::OPTION, array() );
        return self::normalise( is_array( $stored ) ? $stored : array() );
    }

    /** Whether anything is currently on, for cheap call-site checks. */
    public static function any_enabled() {
        $state = self::state();
        return ! empty( $state['heartbeat']['enabled'] ) || ! empty( $state['revisions']['enabled'] );
    }

    /**
     * Persist a lever configuration.
     *
     * @param bool $confirmed Explicit acknowledgement; the levers change admin
     *                        behaviour, so they are never applied implicitly.
     */
    public static function save( array $input, $confirmed = false ) {
        if ( ! $confirmed ) {
            return new WP_Error( 'pfc_levers_confirm', 'Confirm the change before applying a runtime lever.' );
        }
        $before = self::state();
        $after = self::normalise( $input );
        if ( $after === $before ) {
            return array( 'ok' => true, 'changed' => false, 'state' => $after, 'change_id' => 0, 'message' => 'Configuration is unchanged.' );
        }
        update_option( self::OPTION, $after, true );
        $change_id = PFC_Change_Impact::record( self::CHANGE_TYPE, 'heartbeat+revisions', $before, $after );
        PFC_Change_Impact::flush_cache();
        return array(
            'ok'        => true,
            'changed'   => true,
            'state'     => $after,
            'change_id' => $change_id,
            'message'   => 'Runtime levers updated. The change is recorded so it can be measured and reverted.',
        );
    }

    /** Revert one recorded lever change. */
    public static function undo( $change_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PFC_Utils::table( 'changes' ) . ' WHERE id=%d AND change_type=%s', (int) $change_id, self::CHANGE_TYPE ), ARRAY_A );
        if ( ! $row || '' === (string) $row['before_value'] ) {
            return new WP_Error( 'pfc_levers_undo_missing', 'No lever change matches that ID.' );
        }
        $before = self::normalise( PFC_Change_Impact::decode( $row['before_value'] ) );
        update_option( self::OPTION, $before, true );
        PFC_Change_Impact::record( 'runtime_levers_undo', 'heartbeat+revisions', self::state(), $before );
        PFC_Change_Impact::flush_cache();
        return array( 'ok' => true, 'state' => $before, 'message' => 'Runtime levers reverted to their previous configuration.' );
    }

    /**
     * Register the filters. Called on every request; a disabled lever adds
     * nothing at all.
     */
    public static function apply() {
        $state = self::state();
        if ( ! empty( $state['heartbeat']['enabled'] ) ) {
            $interval = (int) $state['heartbeat']['interval'];
            add_filter( 'heartbeat_settings', static function ( $settings ) use ( $interval ) {
                if ( is_array( $settings ) ) { $settings['interval'] = $interval; }
                return $settings;
            } );
            // Logged-out visitors have no editor to keep in sync, so the admin-ajax
            // heartbeat is pure overhead there.
            add_action( 'wp_enqueue_scripts', static function () {
                if ( ! is_user_logged_in() ) { wp_deregister_script( 'heartbeat' ); }
            }, 1 );
        }
        if ( ! empty( $state['revisions']['enabled'] ) ) {
            $keep = (int) $state['revisions']['keep'];
            add_filter( 'wp_revisions_to_keep', static function ( $count, $post ) use ( $keep ) {
                return min( (int) $count, $keep );
            }, 10, 2 );
        }
    }

    /** Human summary for CLI and admin output. */
    public static function describe( array $state = null ) {
        $state = null === $state ? self::state() : $state;
        $lines = array();
        $lines[] = ! empty( $state['heartbeat']['enabled'] )
            ? 'Heartbeat throttled to ' . (int) $state['heartbeat']['interval'] . 's and disabled for logged-out visitors.'
            : 'Heartbeat unchanged.';
        $lines[] = ! empty( $state['revisions']['enabled'] )
            ? 'Revisions capped at ' . (int) $state['revisions']['keep'] . ' per post.'
            : 'Revisions unchanged.';
        return $lines;
    }
}
