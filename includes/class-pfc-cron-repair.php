<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Guided WP-Cron clean-up.
 *
 * WP-Cron backlog is already detected. This class separates the two things an
 * operator can actually fix: the same event registered several times, and events
 * rescheduled faster than a sane interval. Every action is explicit, records the
 * exact schedule it removed, and can be replayed.
 *
 * Single events are never touched, and Performance Console's own maintenance
 * hook is protected.
 */
final class PFC_Cron_Repair {
    const SHORT_INTERVAL = 300;
    const CHANGE_TYPE = 'cron_deduped';
    const PROTECTED = array( 'pfc_daily_maintenance', 'wpi_daily_maintenance' );

    /**
     * Pure: classify a cron array.
     *
     * Input shape is WordPress's: [ timestamp => [ hook => [ key => [schedule, args] ] ] ].
     */
    public static function analysis( array $cron, $short_interval = self::SHORT_INTERVAL ) {
        $hooks = array();
        foreach ( $cron as $timestamp => $events ) {
            foreach ( (array) $events as $hook => $entries ) {
                $hook = (string) $hook;
                if ( ! isset( $hooks[ $hook ] ) ) {
                    $hooks[ $hook ] = array( 'hook' => $hook, 'timestamps' => array(), 'events' => 0, 'schedules' => array() );
                }
                foreach ( (array) $entries as $entry ) {
                    $hooks[ $hook ]['timestamps'][] = (int) $timestamp;
                    $hooks[ $hook ]['events']++;
                    $schedule = (string) ( $entry['schedule'] ?? '' );
                    if ( '' !== $schedule ) { $hooks[ $hook ]['schedules'][ $schedule ] = true; }
                }
            }
        }

        $rows = array();
        foreach ( $hooks as $data ) {
            $timestamps = array_values( array_unique( $data['timestamps'] ) );
            sort( $timestamps );
            $gaps = array();
            for ( $i = 1; $i < count( $timestamps ); $i++ ) { $gaps[] = $timestamps[ $i ] - $timestamps[ $i - 1 ]; }
            $minimum_gap = $gaps ? (int) min( $gaps ) : 0;
            $rows[] = array(
                'hook'              => $data['hook'],
                'events'            => (int) $data['events'],
                'timestamps'        => count( $timestamps ),
                'min_gap'           => $minimum_gap,
                'duplicate_same_time' => max( 0, (int) $data['events'] - count( $timestamps ) ),
                'multiple_timestamps' => count( $timestamps ) > 1,
                'short_interval'    => $minimum_gap > 0 && $minimum_gap < (int) $short_interval,
                'schedules'         => array_keys( $data['schedules'] ),
                'protected'         => in_array( $data['hook'], self::PROTECTED, true ),
            );
        }
        usort( $rows, static function ( $a, $b ) {
            $by_events = (int) $b['events'] <=> (int) $a['events'];
            return 0 !== $by_events ? $by_events : strcmp( (string) $a['hook'], (string) $b['hook'] );
        } );

        $total = 0;
        $duplicates = 0;
        $short = 0;
        foreach ( $rows as $row ) {
            $total += (int) $row['events'];
            if ( $row['multiple_timestamps'] ) { $duplicates++; }
            if ( $row['short_interval'] ) { $short++; }
        }
        return array(
            'available'         => true,
            'hooks'             => $rows,
            'total_events'      => $total,
            'duplicate_hooks'   => $duplicates,
            'short_interval_hooks' => $short,
            'short_interval'    => (int) $short_interval,
        );
    }

    public static function snapshot() {
        if ( ! function_exists( '_get_cron_array' ) ) {
            return array( 'available' => false, 'hooks' => array(), 'total_events' => 0, 'duplicate_hooks' => 0, 'short_interval_hooks' => 0 );
        }
        $cron = _get_cron_array();
        return self::analysis( is_array( $cron ) ? $cron : array() );
    }

    /** Every scheduled occurrence of one hook, grouped by argument identity. */
    private static function occurrences( $hook ) {
        $cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
        $found = array();
        foreach ( (array) $cron as $timestamp => $events ) {
            if ( ! isset( $events[ $hook ] ) ) { continue; }
            foreach ( (array) $events[ $hook ] as $key => $entry ) {
                $found[] = array(
                    'timestamp' => (int) $timestamp,
                    'key'       => (string) $key,
                    'schedule'  => (string) ( $entry['schedule'] ?? '' ),
                    'args'      => (array) ( $entry['args'] ?? array() ),
                );
            }
        }
        usort( $found, static function ( $a, $b ) { return (int) $a['timestamp'] <=> (int) $b['timestamp']; } );
        return $found;
    }

    /**
     * Keep the earliest occurrence of each distinct argument set and remove the
     * rest.
     */
    public static function dedupe( $hook, $confirmed = false ) {
        $hook = sanitize_text_field( (string) $hook );
        if ( '' === $hook ) { return new WP_Error( 'pfc_cron_hook', 'Provide a hook name.' ); }
        if ( in_array( $hook, self::PROTECTED, true ) ) {
            return new WP_Error( 'pfc_cron_protected', 'Performance Console does not modify its own maintenance schedule.' );
        }
        if ( ! $confirmed ) {
            return new WP_Error( 'pfc_cron_confirm', 'Confirm the schedule change before removing events.' );
        }
        $occurrences = self::occurrences( $hook );
        if ( count( $occurrences ) < 2 ) {
            return array( 'ok' => true, 'removed' => 0, 'change_id' => 0, 'message' => 'That hook has a single scheduled occurrence; nothing to collapse.' );
        }

        $keep = array();
        $remove = array();
        foreach ( $occurrences as $occurrence ) {
            if ( ! isset( $keep[ $occurrence['key'] ] ) ) { $keep[ $occurrence['key'] ] = $occurrence; continue; }
            $remove[] = $occurrence;
        }
        if ( ! $remove ) {
            return array( 'ok' => true, 'removed' => 0, 'change_id' => 0, 'message' => 'Each scheduled occurrence has a distinct argument set; there is nothing to collapse.' );
        }

        $removed = 0;
        foreach ( $remove as $occurrence ) {
            if ( wp_unschedule_event( $occurrence['timestamp'], $hook, $occurrence['args'] ) ) { $removed++; }
        }
        $change_id = PFC_Change_Impact::record( self::CHANGE_TYPE, $hook, $occurrences, array( 'kept' => array_values( $keep ), 'removed' => $removed ) );
        PFC_Change_Impact::flush_cache();
        return array(
            'ok'        => true,
            'removed'   => $removed,
            'change_id' => $change_id,
            'message'   => $removed . ' duplicate occurrence(s) of ' . $hook . ' removed; the earliest of each argument set was kept.',
        );
    }

    /** Re-schedule exactly what a dedupe removed. */
    public static function undo( $change_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PFC_Utils::table( 'changes' ) . ' WHERE id=%d AND change_type=%s', (int) $change_id, self::CHANGE_TYPE ), ARRAY_A );
        if ( ! $row ) { return new WP_Error( 'pfc_cron_undo_missing', 'No cron de-duplication matches that change ID.' ); }
        $before = PFC_Change_Impact::decode( $row['before_value'] );
        $hook = (string) $row['object_name'];
        $restored = 0;
        foreach ( (array) $before as $occurrence ) {
            if ( ! is_array( $occurrence ) || ! isset( $occurrence['timestamp'] ) ) { continue; }
            $timestamp = (int) $occurrence['timestamp'];
            $args = (array) ( $occurrence['args'] ?? array() );
            $schedule = (string) ( $occurrence['schedule'] ?? '' );
            if ( 'single' === $schedule || '' === $schedule ) {
                wp_schedule_single_event( $timestamp, $hook, $args );
            } else {
                wp_schedule_event( $timestamp, $schedule, $hook, $args );
            }
            $restored++;
        }
        PFC_Change_Impact::record( 'cron_restored', $hook, '', array( 'restored' => $restored ) );
        return array( 'ok' => true, 'restored' => $restored, 'message' => $restored . ' occurrence(s) of ' . $hook . ' scheduled again.' );
    }
}
