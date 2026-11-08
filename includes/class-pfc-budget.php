<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Performance budgets.
 *
 * A budget turns the diagnostic vocabulary into a pass/fail contract so a
 * regression can stop a deploy instead of being discovered by a reader. The
 * evaluation is pure: it takes measurements and thresholds and returns a
 * verdict, which keeps the WP-CLI gate honest and lets the same logic drive the
 * admin panel.
 */
final class PFC_Budget {
    /**
     * Default budgets, deliberately set at "needs work" rather than "excellent".
     * These follow the Core Web Vitals thresholds people already recognise, plus
     * two server-side guards that catch a regression before it reaches users.
     */
    const DEFAULTS = array(
        'ttfb'          => 800,
        'lcp'           => 2500,
        'inp'           => 200,
        'cls'           => 0.1,
        'server_php_ms' => 600,
        'queries'       => 60,
    );

    const UNITS = array(
        'ttfb'          => 'ms',
        'lcp'           => 'ms',
        'inp'           => 'ms',
        'cls'           => 'score',
        'server_php_ms' => 'ms',
        'queries'       => 'count',
    );

    const LABELS = array(
        'ttfb'          => 'TTFB p75',
        'lcp'           => 'LCP p75',
        'inp'           => 'INP p75',
        'cls'           => 'CLS p75',
        'server_php_ms' => 'Server PHP median',
        'queries'       => 'Queries per request',
    );

    /** Smaller measurements are always better, so the comparison is uniform. */
    const MIN_SAMPLES = 5;

    public static function labels() { return self::LABELS; }
    public static function units() { return self::UNITS; }

    /** Hard bounds. A zero disables that check. */
    public static function bounds() {
        return array(
            'ttfb'          => array( 0, 600000 ),
            'lcp'           => array( 0, 600000 ),
            'inp'           => array( 0, 600000 ),
            'cls'           => array( 0, 10 ),
            'server_php_ms' => array( 0, 600000 ),
            'queries'       => array( 0, 10000 ),
        );
    }

    /**
     * Validate and clamp a set of thresholds.
     *
     * Unknown keys are dropped rather than stored, so a typo in a CI flag cannot
     * silently create a check that never runs.
     */
    public static function normalise( array $input ) {
        $bounds = self::bounds();
        $out = array();
        foreach ( self::DEFAULTS as $key => $default ) {
            $value = array_key_exists( $key, $input ) ? $input[ $key ] : $default;
            if ( ! is_numeric( $value ) ) { $value = $default; }
            list( $min, $max ) = $bounds[ $key ];
            $out[ $key ] = round( max( (float) $min, min( (float) $max, (float) $value ) ), 3 );
        }
        return $out;
    }

    /** Effective thresholds: stored option, then filter, then explicit overrides. */
    public static function thresholds( array $overrides = array() ) {
        $stored = get_option( 'pfc_budgets', array() );
        $thresholds = self::normalise( is_array( $stored ) ? $stored : array() );
        $thresholds = self::normalise( (array) apply_filters( 'pfc_budgets', $thresholds ) );
        if ( $overrides ) { $thresholds = self::normalise( array_merge( $thresholds, $overrides ) ); }
        return $thresholds;
    }

    public static function save( array $input ) {
        $thresholds = self::normalise( $input );
        update_option( 'pfc_budgets', $thresholds, false );
        return $thresholds;
    }

    /**
     * Measure the current traffic against the budgets.
     *
     * @param int    $days        Window length, clamped to the retention window.
     * @param string $route_group Optional single route group.
     */
    public static function measure( $days = 1, $route_group = '' ) {
        global $wpdb;
        $days = max( 1, min( 30, (int) $days ) );
        $route_group = sanitize_text_field( (string) $route_group );
        $from = time() - $days * DAY_IN_SECONDS;
        $values = array();

        $metrics_table = PFC_Utils::table( 'metrics' );
        foreach ( array( 'ttfb', 'lcp', 'inp', 'cls' ) as $metric ) {
            $where = 'metric_version=2 AND metric=%s AND bucket >= %s';
            $args = array( $metric, gmdate( 'Y-m-d H:00:00', $from ) );
            if ( '' !== $route_group ) { $where .= ' AND route_group=%s'; $args[] = $route_group; }
            $sql = "SELECT metric,SUM(samples) samples,SUM(value_sum) value_sum,MAX(value_max) value_max,
                    SUM(bucket_0) bucket_0,SUM(bucket_1) bucket_1,SUM(bucket_2) bucket_2,SUM(bucket_3) bucket_3,
                    SUM(bucket_4) bucket_4,SUM(bucket_5) bucket_5,SUM(bucket_6) bucket_6,SUM(bucket_7) bucket_7
                FROM {$metrics_table} WHERE {$where}";
            $row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $samples = (int) ( $row['samples'] ?? 0 );
            if ( $samples < self::MIN_SAMPLES ) {
                $values[ $metric ] = array( 'value' => null, 'samples' => $samples, 'source' => 'rum' );
                continue;
            }
            $row['metric'] = $metric;
            $values[ $metric ] = array(
                'value'   => round( (float) PFC_Utils::metric_percentile( $row, 0.75 ), 3 ),
                'samples' => $samples,
                'source'  => 'rum',
            );
        }

        $runs_table = PFC_Utils::table( 'runs' );
        $run_sql = "SELECT php_ms,query_count FROM {$runs_table} WHERE mode='sample' AND created_at >= %s ORDER BY id DESC LIMIT 2000";
        $runs = $wpdb->get_results( $wpdb->prepare( $run_sql, gmdate( 'Y-m-d H:i:s', $from ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $php = array();
        $queries = array();
        foreach ( (array) $runs as $run ) {
            $php[] = (float) $run['php_ms'];
            $queries[] = (int) $run['query_count'];
        }
        $values['server_php_ms'] = array(
            'value'   => count( $php ) >= self::MIN_SAMPLES ? round( PFC_Utils::median( $php ), 3 ) : null,
            'samples' => count( $php ),
            'source'  => 'runs',
        );
        $values['queries'] = array(
            'value'   => count( $queries ) >= self::MIN_SAMPLES ? round( self::percentile( $queries, 0.75 ), 1 ) : null,
            'samples' => count( $queries ),
            'source'  => 'runs',
        );

        return array(
            'window'      => array( 'from' => $from, 'to' => time(), 'days' => $days ),
            'route_group' => $route_group,
            'values'      => $values,
        );
    }

    /** Nearest-rank percentile over a small integer list. */
    public static function percentile( array $values, $percentile = 0.75 ) {
        $values = array_values( array_filter( array_map( 'floatval', $values ), 'is_finite' ) );
        if ( ! $values ) { return 0.0; }
        sort( $values, SORT_NUMERIC );
        $index = (int) ceil( count( $values ) * max( 0, min( 1, (float) $percentile ) ) ) - 1;
        return (float) $values[ max( 0, min( count( $values ) - 1, $index ) ) ];
    }

    /**
     * Compare measurements to thresholds.
     *
     * Pure. Returns one check per budget with a closed status vocabulary:
     * pass, fail, no_data, disabled.
     *
     * @param array $measured   Output of measure(), or any same-shaped array.
     * @param array $thresholds Output of thresholds().
     * @param bool  $require_data When true a budget with no traffic fails.
     */
    public static function evaluate( array $measured, array $thresholds, $require_data = true ) {
        $values = (array) ( $measured['values'] ?? array() );
        $checks = array();
        $failed = array();
        $missing = array();
        foreach ( self::DEFAULTS as $key => $default ) {
            $limit = (float) ( $thresholds[ $key ] ?? $default );
            $label = self::LABELS[ $key ] ?? $key;
            $unit = self::UNITS[ $key ] ?? '';
            $sample = (array) ( $values[ $key ] ?? array() );
            $value = $sample['value'] ?? null;
            $check = array(
                'key'     => $key,
                'label'   => $label,
                'unit'    => $unit,
                'limit'   => $limit,
                'value'   => null === $value ? null : (float) $value,
                'samples' => (int) ( $sample['samples'] ?? 0 ),
                'source'  => (string) ( $sample['source'] ?? '' ),
                'status'  => 'no_data',
            );
            if ( $limit <= 0 ) {
                $check['status'] = 'disabled';
            } elseif ( null === $value || $check['samples'] < self::MIN_SAMPLES ) {
                $check['status'] = 'no_data';
                $missing[] = $key;
            } elseif ( $check['value'] <= $limit ) {
                $check['status'] = 'pass';
            } else {
                $check['status'] = 'fail';
                $failed[] = $key;
            }
            $checks[] = $check;
        }
        $pass = empty( $failed ) && ( ! $require_data || empty( $missing ) );
        return array(
            'pass'         => $pass,
            'failed'       => $failed,
            'missing'      => $missing,
            'checks'       => $checks,
            'require_data' => (bool) $require_data,
        );
    }

    /** Measure and evaluate in one call. */
    public static function report( $days = 1, $route_group = '', array $overrides = array(), $require_data = true ) {
        $measured = self::measure( $days, $route_group );
        $thresholds = self::thresholds( $overrides );
        $result = self::evaluate( $measured, $thresholds, $require_data );
        $result['measured'] = $measured;
        $result['thresholds'] = $thresholds;
        return $result;
    }

    /** Human-readable value with its unit, for CLI tables and the admin panel. */
    public static function format_value( $key, $value ) {
        if ( null === $value ) { return '—'; }
        $unit = self::UNITS[ $key ] ?? '';
        if ( 'score' === $unit ) { return number_format( (float) $value, 3 ); }
        if ( 'count' === $unit ) { return number_format( (float) $value, 1 ); }
        return number_format( (float) $value, 0 ) . ' ms';
    }

    /**
     * One-line verdict for a check, used by the CLI gate and the admin badge.
     *
     * A failing check reports the observed value and the budget so the message
     * is actionable without opening the dashboard.
     */
    public static function describe( array $check ) {
        if ( 'disabled' === $check['status'] ) { return $check['label'] . ': disabled'; }
        if ( 'no_data' === $check['status'] ) { return $check['label'] . ': no traffic in window (' . (int) $check['samples'] . ' samples)'; }
        $observed = self::format_value( $check['key'], $check['value'] );
        $limit = self::format_value( $check['key'], $check['limit'] );
        if ( 'fail' === $check['status'] ) { return $check['label'] . ': ' . $observed . ' exceeds the ' . $limit . ' budget'; }
        return $check['label'] . ': ' . $observed . ' within the ' . $limit . ' budget';
    }
}
