<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Change-impact ledger.
 *
 * Every optimization that Performance Console performs, and every plugin/theme
 * change WordPress already reports, lands in the changes table. This class pairs
 * that timestamp with the traffic that preceded and followed it so an operator
 * can see whether a change actually helped instead of assuming it did.
 *
 * The comparison is deliberately conservative. A change is only called an
 * improvement or a regression when both windows carry enough samples and the
 * median shift clears a noise floor derived from the window's own spread, so a
 * quiet week of low traffic cannot be reported as a 40% win.
 */
final class PFC_Change_Impact {
    /** Days of traffic sampled on each side of a change. */
    const WINDOW_DAYS = 7;
    /** Traffic immediately after a change is transitional; skip it. */
    const SETTLE_MINUTES = 60;
    const MIN_RUN_SAMPLES = 12;
    const MIN_GROUP_SAMPLES = 10;
    const MIN_GROUPS = 2;
    const MIN_METRIC_SAMPLES = 30;
    const MIN_RELATIVE = 0.05;

    /** Field metrics carried by the metrics table, lower is always better. */
    const FIELD_METRICS = array( 'ttfb', 'lcp', 'inp', 'cls' );

    /**
     * Smallest shift worth reporting per metric. A 5 ms TTFB move is noise on a
     * real site, while 0.05 CLS is a visible layout problem, so the floors are
     * not interchangeable.
     */
    const ABSOLUTE_FLOOR = array( 'server_php_ms' => 10.0, 'ttfb' => 20.0, 'lcp' => 50.0, 'inp' => 20.0, 'cls' => 0.02 );

    const LABELS = array(
        'server_php_ms' => 'Server PHP median',
        'ttfb'          => 'TTFB p75',
        'lcp'           => 'LCP p75',
        'inp'           => 'INP p75',
        'cls'           => 'CLS p75',
    );

    public static function labels() { return self::LABELS; }

    public static function absolute_floor( $metric ) {
        return (float) ( self::ABSOLUTE_FLOOR[ $metric ] ?? 10.0 );
    }

    /**
     * Persist one change.
     *
     * @param string       $change_type Short machine key, e.g. 'autoload_off'.
     * @param string       $object_name The plugin, option, table or hook touched.
     * @param mixed        $before      Scalar or array describing the prior state.
     * @param mixed        $after       Scalar or array describing the new state.
     * @return int Inserted change ID, or 0 when the write failed.
     */
    public static function record( $change_type, $object_name, $before = '', $after = '', array $meta = array() ) {
        global $wpdb;
        $change_type = sanitize_key( (string) $change_type );
        if ( '' === $change_type ) { return 0; }
        if ( $meta ) {
            $after = array( 'value' => $after, 'meta' => $meta );
        }
        $ok = $wpdb->insert(
            PFC_Utils::table( 'changes' ),
            array(
                'created_at'   => PFC_Utils::now_mysql(),
                'change_type'  => $change_type,
                'object_name'  => sanitize_text_field( (string) $object_name ),
                'before_value' => self::encode( $before ),
                'after_value'  => self::encode( $after ),
                'user_id'      => get_current_user_id(),
            )
        );
        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    private static function encode( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) { return (string) wp_json_encode( $value ); }
        return (string) $value;
    }

    /** Decode a stored before/after value back to an array when it was JSON. */
    public static function decode( $value ) {
        $value = (string) $value;
        if ( '' === $value ) { return array(); }
        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : array( 'value' => $value );
    }

    /**
     * The two observation windows around a change.
     *
     * @return array{before:array{0:int,1:int},after:array{0:int,1:int},state:string}
     */
    public static function windows( $created_at, $now = null ) {
        $now = null === $now ? time() : (int) $now;
        $created = strtotime( (string) $created_at . ' UTC' );
        if ( ! $created ) {
            return array( 'before' => array( 0, 0 ), 'after' => array( 0, 0 ), 'state' => 'unknown' );
        }
        $before_start = $created - self::WINDOW_DAYS * DAY_IN_SECONDS;
        $after_start  = $created + self::SETTLE_MINUTES * MINUTE_IN_SECONDS;
        $after_end    = min( $now, $after_start + self::WINDOW_DAYS * DAY_IN_SECONDS );
        // A change is only assessable once the settled window has a full day of
        // traffic; before that any verdict would be a guess.
        $state = ( $after_end - $after_start ) >= DAY_IN_SECONDS ? 'assessable' : 'pending';
        return array(
            'before' => array( $before_start, $created ),
            'after'  => array( $after_start, $after_end ),
            'state'  => $state,
        );
    }

    /** Median absolute deviation. A robust spread measure for skewed timings. */
    public static function mad( array $values ) {
        $values = array_values( array_filter( array_map( 'floatval', $values ), 'is_finite' ) );
        if ( count( $values ) < 2 ) { return 0.0; }
        $median = PFC_Utils::median( $values );
        $deviations = array_map( static function ( $value ) use ( $median ) { return abs( $value - $median ); }, $values );
        return PFC_Utils::median( $deviations );
    }

    /**
     * Compare two sample windows.
     *
     * Pure: no database, no WordPress. The verdict vocabulary is closed so the
     * UI and the CLI cannot disagree about what "improved" means.
     *
     * @return array Verdict, confidence and the numbers behind them.
     */
    public static function classify( array $before, array $after, $absolute_floor = 10.0, $min_samples = self::MIN_RUN_SAMPLES, $min_relative = self::MIN_RELATIVE ) {
        $before = array_values( array_filter( array_map( 'floatval', $before ), 'is_finite' ) );
        $after  = array_values( array_filter( array_map( 'floatval', $after ), 'is_finite' ) );
        $result = array(
            'verdict'         => 'insufficient',
            'confidence'      => 'low',
            'before_median'   => round( PFC_Utils::median( $before ), 3 ),
            'after_median'    => round( PFC_Utils::median( $after ), 3 ),
            'delta'           => 0.0,
            'percent'         => 0.0,
            'noise_floor'     => 0.0,
            'before_samples'  => count( $before ),
            'after_samples'   => count( $after ),
        );
        if ( count( $before ) < $min_samples || count( $after ) < $min_samples ) { return $result; }
        if ( $result['before_median'] <= 0 ) { return $result; }

        $delta = $result['after_median'] - $result['before_median'];
        $noise = max( (float) $absolute_floor, 2.0 * self::mad( $before ) );
        $percent = 100 * $delta / $result['before_median'];
        $result['delta'] = round( $delta, 3 );
        $result['percent'] = round( $percent, 1 );
        $result['noise_floor'] = round( $noise, 3 );

        if ( abs( $delta ) <= $noise || abs( $percent ) < $min_relative * 100 ) {
            $result['verdict'] = 'no_measurable_change';
            return $result;
        }
        $result['verdict'] = $delta < 0 ? 'improved' : 'worse';
        $bigger_window = min( count( $before ), count( $after ) ) >= 2 * $min_samples;
        if ( $bigger_window && abs( $delta ) >= 2 * $noise ) { $result['confidence'] = 'high'; }
        elseif ( abs( $delta ) >= 1.5 * $noise ) { $result['confidence'] = 'medium'; }
        return $result;
    }

    /** Raw sampled PHP timings in a window. */
    public static function run_samples( array $window ) {
        global $wpdb;
        if ( ! $window[0] || ! $window[1] ) { return array(); }
        $rows = $wpdb->get_col( $wpdb->prepare( 'SELECT php_ms FROM ' . PFC_Utils::table( 'runs' ) . " WHERE mode='sample' AND created_at >= %s AND created_at < %s ORDER BY id DESC LIMIT 2000", gmdate( 'Y-m-d H:i:s', (int) $window[0] ), gmdate( 'Y-m-d H:i:s', (int) $window[1] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map( 'floatval', (array) $rows );
    }

    /**
     * Per-route-group p75 for a field metric.
     *
     * Comparing whole-site p75 across a change mixes traffic populations: a
     * release that triples mobile sessions can "regress" LCP without anything
     * being slower. Taking one p75 per route group and comparing the medians of
     * those groups keeps the populations comparable.
     */
    public static function group_percentile( $metric, array $window, $percentile = 0.75 ) {
        global $wpdb;
        if ( ! $window[0] || ! $window[1] ) { return array(); }
        $table = PFC_Utils::table( 'metrics' );
        $sql = "SELECT route_group,SUM(samples) samples,SUM(value_sum) value_sum,MAX(value_max) value_max,
                SUM(bucket_0) bucket_0,SUM(bucket_1) bucket_1,SUM(bucket_2) bucket_2,SUM(bucket_3) bucket_3,
                SUM(bucket_4) bucket_4,SUM(bucket_5) bucket_5,SUM(bucket_6) bucket_6,SUM(bucket_7) bucket_7
            FROM {$table}
            WHERE metric_version=2 AND metric=%s AND bucket >= %s AND bucket < %s
            GROUP BY route_group ORDER BY samples DESC LIMIT 50";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, sanitize_key( $metric ), gmdate( 'Y-m-d H:00:00', (int) $window[0] ), gmdate( 'Y-m-d H:00:00', (int) $window[1] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $out = array();
        foreach ( (array) $rows as $row ) {
            $samples = (int) ( $row['samples'] ?? 0 );
            if ( $samples < 1 ) { continue; }
            $row['metric'] = sanitize_key( $metric );
            $out[] = array(
                'route_group' => (string) ( $row['route_group'] ?? '' ),
                'samples'     => $samples,
                'value'       => round( (float) PFC_Utils::metric_percentile( $row, $percentile ), 3 ),
            );
        }
        return $out;
    }

    /** Reduce a group list to the values worth comparing plus the sample total. */
    private static function group_values( array $groups ) {
        $values = array();
        $samples = 0;
        foreach ( $groups as $group ) {
            $samples += (int) $group['samples'];
            if ( (int) $group['samples'] >= self::MIN_GROUP_SAMPLES ) { $values[] = (float) $group['value']; }
        }
        return array( $values, $samples );
    }

    /** Share of total samples held by each route group, for traffic-mix drift. */
    public static function group_mix( array $groups ) {
        $total = 0;
        foreach ( $groups as $group ) { $total += (int) $group['samples']; }
        if ( $total <= 0 ) { return array(); }
        $mix = array();
        foreach ( $groups as $group ) { $mix[ (string) $group['route_group'] ] = round( (int) $group['samples'] / $total, 4 ); }
        return $mix;
    }

    /**
     * Traffic-mix drift between windows, in percentage points.
     *
     * When the mix moves substantially the metric comparison is reported as
     * less trustworthy rather than silently accepted.
     */
    public static function mix_drift( array $before_mix, array $after_mix ) {
        $groups = array_unique( array_merge( array_keys( $before_mix ), array_keys( $after_mix ) ) );
        $drift = 0.0;
        foreach ( $groups as $group ) {
            $drift = max( $drift, abs( 100 * ( (float) ( $after_mix[ $group ] ?? 0 ) - (float) ( $before_mix[ $group ] ?? 0 ) ) ) );
        }
        return round( $drift, 1 );
    }

    /**
     * Build the full impact record for one stored change.
     *
     * @return array|null Null when the change does not exist.
     */
    public static function impact( $change_id, $now = null ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PFC_Utils::table( 'changes' ) . ' WHERE id=%d', (int) $change_id ), ARRAY_A );
        if ( ! $row ) { return null; }
        return self::impact_for_row( $row, $now );
    }

    public static function impact_for_row( array $row, $now = null ) {
        $windows = self::windows( $row['created_at'] ?? '', $now );
        $metrics = array();
        $evidence = array( 'state' => $windows['state'], 'windows' => $windows );

        if ( 'assessable' === $windows['state'] ) {
            $before_runs = self::run_samples( $windows['before'] );
            $after_runs  = self::run_samples( $windows['after'] );
            $metrics['server_php_ms'] = self::classify( $before_runs, $after_runs, self::absolute_floor( 'server_php_ms' ) );
            $metrics['server_php_ms']['total_samples'] = count( $before_runs ) + count( $after_runs );

            foreach ( self::FIELD_METRICS as $metric ) {
                $before_groups = self::group_percentile( $metric, $windows['before'] );
                $after_groups  = self::group_percentile( $metric, $windows['after'] );
                list( $before_values, $before_total ) = self::group_values( $before_groups );
                list( $after_values, $after_total )   = self::group_values( $after_groups );
                $comparison = self::classify( $before_values, $after_values, self::absolute_floor( $metric ), self::MIN_GROUPS, 0.10 );
                $comparison['total_samples'] = $before_total + $after_total;
                if ( $before_total + $after_total < self::MIN_METRIC_SAMPLES ) {
                    $comparison['verdict'] = 'insufficient';
                    $comparison['confidence'] = 'low';
                }
                $drift = self::mix_drift( self::group_mix( $before_groups ), self::group_mix( $after_groups ) );
                $comparison['mix_drift'] = $drift;
                if ( $drift > 25 && 'no_measurable_change' !== $comparison['verdict'] ) {
                    $comparison['confidence'] = 'low';
                    $comparison['note'] = 'Traffic mix shifted by ' . $drift . ' percentage points between windows; treat this comparison as indicative only.';
                }
                $metrics[ $metric ] = $comparison;
            }
        }

        return array(
            'change'    => $row,
            'evidence'  => $evidence,
            'metrics'   => $metrics,
            'verdict'   => self::summarise( $metrics, $windows['state'] ),
        );
    }

    /**
     * Collapse per-metric verdicts into one headline.
     *
     * A regression outranks an improvement: if one metric got meaningfully worse
     * the change needs review even when another metric improved.
     */
    public static function summarise( array $metrics, $state = 'assessable' ) {
        if ( 'assessable' !== $state ) { return 'pending'; }
        $improved = 0;
        $worse = 0;
        $measured = 0;
        foreach ( $metrics as $metric ) {
            $verdict = (string) ( $metric['verdict'] ?? 'insufficient' );
            if ( 'insufficient' === $verdict || 'pending' === $verdict ) { continue; }
            $measured++;
            if ( 'worse' === $verdict ) { $worse++; }
            elseif ( 'improved' === $verdict ) { $improved++; }
        }
        if ( 0 === $measured ) { return 'insufficient'; }
        if ( $worse > 0 ) { return 'worse'; }
        if ( $improved > 0 ) { return 'improved'; }
        return 'no_change';
    }

    /** Recent changes with their impact records. Cached briefly per user. */
    public static function recent( $limit = 8, $now = null ) {
        global $wpdb;
        $limit = max( 1, min( 20, (int) $limit ) );
        $table = PFC_Utils::table( 'changes' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $rows ) { return array(); }
        $cache_key = 'pfc_change_impact_' . get_current_user_id() . '_' . md5( (string) $rows[0]['id'] . '|' . $limit );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }
        $out = array();
        foreach ( $rows as $row ) { $out[] = self::impact_for_row( $row, $now ); }
        set_transient( $cache_key, $out, 5 * MINUTE_IN_SECONDS );
        return $out;
    }

    /** Forget the cached ledger after a new change is recorded. */
    public static function flush_cache() {
        global $wpdb;
        // Transients are keyed per user and change id, so the cheapest correct
        // invalidation is to drop every prefix match for this site.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_pfc_change_impact_' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_pfc_change_impact_' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Raise incidents for changes that measurably made the site slower.
     *
     * Confidence must be at least medium: a low-confidence comparison is the
     * kind of thing that generates an alert nobody trusts.
     */
    public static function generate_issues() {
        foreach ( self::recent( 8 ) as $record ) {
            if ( 'worse' !== ( $record['verdict'] ?? '' ) ) { continue; }
            foreach ( $record['metrics'] as $metric => $comparison ) {
                if ( 'worse' !== ( $comparison['verdict'] ?? '' ) || 'low' === ( $comparison['confidence'] ?? 'low' ) ) { continue; }
                $change = $record['change'];
                $label = self::LABELS[ $metric ] ?? $metric;
                $title = 'Change made ' . $label . ' worse';
                $message = 'After <code>' . esc_html( (string) $change['change_type'] . ' ' . (string) $change['object_name'] ) . '</code> on ' . esc_html( (string) $change['created_at'] ) . ' UTC, '
                    . $label . ' moved from ' . esc_html( (string) $comparison['before_median'] ) . ' to ' . esc_html( (string) $comparison['after_median'] )
                    . ' (' . ( $comparison['percent'] >= 0 ? '+' : '' ) . esc_html( (string) $comparison['percent'] ) . '%) across '
                    . esc_html( (string) ( $comparison['before_samples'] + $comparison['after_samples'] ) ) . ' samples.';
                PFC_Utils::issue(
                    'change',
                    'high',
                    $title,
                    $message,
                    ( $comparison['percent'] >= 0 ? '+' : '' ) . $comparison['percent'] . '% ' . $label,
                    'Re-run the same profile on the affected route group and compare against the pre-change window before reverting. Timing alone does not prove the change caused the shift.',
                    '',
                    array( 'confidence' => 'high' === $comparison['confidence'] ? 85 : 65, 'component' => sanitize_key( (string) $change['object_name'] ) )
                );
            }
        }
    }
}
