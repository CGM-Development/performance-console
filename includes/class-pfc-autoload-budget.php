<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Autoload budget.
 *
 * Every autoloaded option is read on every request, from the alloptions cache
 * or from a fresh query when that cache is cold. Autoload bloat is one of the
 * most common causes of a slow TTFB that no profiler attributes to a plugin,
 * because the cost is spread across the bootstrap rather than one query.
 *
 * This class measures the budget, ranks offenders with evidence, and can turn
 * autoload off in a recorded, reversible batch. It never guesses: an option with
 * no observed reads is reported as "no evidence", not "unused".
 */
final class PFC_Autoload_Budget {
    const BUDGET_BYTES = 1048576; // 1 MB of autoloaded option data is already generous.
    const BUDGET_COUNT = 400;
    const MAX_OFFENDERS = 25;
    const CHANGE_TYPE = 'autoload_bulk_off';

    /** WordPress has used yes/no and then on/off/auto-on/auto-off across versions. */
    public static function is_autoloaded( $value ) {
        return in_array( strtolower( trim( (string) $value ) ), array( 'yes', 'on', 'auto-on', '1', 'true' ), true );
    }

    /** Pure: compare observed totals with the budget. */
    public static function status( $bytes, $count, $budget_bytes = self::BUDGET_BYTES, $budget_count = self::BUDGET_COUNT ) {
        $bytes = max( 0, (int) $bytes );
        $count = max( 0, (int) $count );
        $budget_bytes = max( 1, (int) $budget_bytes );
        return array(
            'bytes'         => $bytes,
            'count'         => $count,
            'budget_bytes'  => $budget_bytes,
            'budget_count'  => (int) $budget_count,
            'bytes_ratio'   => round( $bytes / $budget_bytes, 2 ),
            'count_ratio'   => $budget_count > 0 ? round( $count / $budget_count, 2 ) : 0.0,
            'over_bytes'    => $bytes > $budget_bytes,
            'over_count'    => $budget_count > 0 && $count > $budget_count,
        );
    }

    public static function summary() {
        global $wpdb;
        $row = $wpdb->get_row( "SELECT COUNT(*) option_count, COALESCE(SUM(LENGTH(option_value)),0) bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $status = self::status( (int) ( $row['bytes'] ?? 0 ), (int) ( $row['option_count'] ?? 0 ) );
        $status['table'] = $wpdb->options;
        return $status;
    }

    /**
     * Largest autoloaded options, with whatever access evidence the sample
     * ledger holds. Zero hits means "not observed", never "unused".
     */
    public static function offenders( $limit = self::MAX_OFFENDERS ) {
        global $wpdb;
        $limit = max( 1, min( self::MAX_OFFENDERS, (int) $limit ) );
        $usage = PFC_Utils::table( 'option_usage' );
        $sql = "SELECT o.option_name, o.autoload, LENGTH(o.option_value) bytes, COALESCE(u.hits,0) hits, COALESCE(u.sampled_requests,0) sampled_requests
            FROM {$wpdb->options} o LEFT JOIN {$usage} u ON u.option_name=o.option_name
            WHERE o.autoload IN ('yes','on','auto-on')
            ORDER BY bytes DESC LIMIT {$limit}";
        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[] = array(
                'option_name'      => (string) $row['option_name'],
                'autoload'         => (string) $row['autoload'],
                'bytes'            => (int) $row['bytes'],
                'hits'             => (int) $row['hits'],
                'sampled_requests' => (int) $row['sampled_requests'],
                'evidence'         => (int) $row['hits'] > 0 ? 'observed' : 'no evidence',
                'protected'        => class_exists( 'PFC_Database_Repair' ) && PFC_Database_Repair::protected_option( (string) $row['option_name'] ),
            );
        }
        return $out;
    }

    /**
     * Turn autoload off for an explicit list, recording the previous values.
     *
     * @return array|WP_Error
     */
    public static function disable( array $names, $confirmed = false ) {
        global $wpdb;
        if ( ! $confirmed ) {
            return new WP_Error( 'pfc_autoload_confirm', 'Confirm the autoload change only after reviewing which options lose autoload.' );
        }
        $names = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $names ) ) ) );
        if ( ! $names ) { return new WP_Error( 'pfc_autoload_names', 'Provide at least one option name.' ); }

        $before = array();
        $changed = array();
        $skipped = array();
        foreach ( $names as $name ) {
            if ( '' === $name ) { continue; }
            if ( class_exists( 'PFC_Database_Repair' ) && PFC_Database_Repair::protected_option( $name ) ) { $skipped[] = $name . ' (protected)'; continue; }
            $current = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( null === $current ) { $skipped[] = $name . ' (missing)'; continue; }
            if ( ! self::is_autoloaded( $current ) ) { $skipped[] = $name . ' (already off)'; continue; }
            $before[ $name ] = (string) $current;
            if ( function_exists( 'wp_set_option_autoload' ) ) {
                $ok = wp_set_option_autoload( $name, false );
            } else {
                $ok = false !== $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ) );
                if ( $ok ) {
                    wp_cache_delete( 'alloptions', 'options' );
                    wp_cache_delete( $name, 'options' );
                }
            }
            if ( $ok ) { $changed[ $name ] = 'no'; } else { unset( $before[ $name ] ); $skipped[] = $name . ' (write failed)'; }
        }
        if ( ! $changed ) {
            return array( 'ok' => true, 'changed' => 0, 'skipped' => $skipped, 'change_id' => 0, 'message' => 'No option needed changing.' );
        }
        $change_id = PFC_Change_Impact::record( self::CHANGE_TYPE, 'autoload', $before, $changed );
        PFC_Change_Impact::flush_cache();
        return array(
            'ok'        => true,
            'changed'   => count( $changed ),
            'skipped'   => $skipped,
            'change_id' => $change_id,
            'message'   => count( $changed ) . ' option(s) no longer autoload. Verify the site after the next cold cache; each one is restored by its undo.',
        );
    }

    /** Restore the autoload values a batch changed. */
    public static function undo( $change_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PFC_Utils::table( 'changes' ) . ' WHERE id=%d AND change_type=%s', (int) $change_id, self::CHANGE_TYPE ), ARRAY_A );
        if ( ! $row ) { return new WP_Error( 'pfc_autoload_undo_missing', 'No autoload batch matches that change ID.' ); }
        $before = PFC_Change_Impact::decode( $row['before_value'] );
        $restored = 0;
        foreach ( (array) $before as $name => $value ) {
            $name = sanitize_text_field( (string) $name );
            if ( '' === $name ) { continue; }
            if ( self::is_autoloaded( $value ) ) {
                if ( function_exists( 'wp_set_option_autoload' ) ) { wp_set_option_autoload( $name, true ); }
                else {
                    $wpdb->update( $wpdb->options, array( 'autoload' => 'yes' ), array( 'option_name' => $name ) );
                    wp_cache_delete( 'alloptions', 'options' );
                }
                $restored++;
            }
        }
        PFC_Change_Impact::record( 'autoload_restored', 'autoload', '', array( 'restored' => $restored ) );
        return array( 'ok' => true, 'restored' => $restored, 'message' => $restored . ' option(s) autoloading again.' );
    }
}
