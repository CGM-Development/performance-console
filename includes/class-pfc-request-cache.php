<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only evidence for one real WordPress request.
 *
 * Cache hit/miss counters are a WordPress API view. They are NOT Redis network
 * calls or cumulative Redis time. A cache drop-in may not expose any counters.
 */
final class PFC_Request_Cache {
    /** Only inspect public numeric counters. Never call stats() (it can echo HTML). */
    public static function snapshot() {
        global $wp_object_cache;
        if ( ! is_object( $wp_object_cache ) ) { return array( 'available' => false ); }
        $out = array( 'available' => false );
        foreach ( array( 'hits' => array( 'cache_hits', 'hits' ), 'misses' => array( 'cache_misses', 'misses' ) ) as $name => $candidates ) {
            foreach ( $candidates as $property ) {
                if ( isset( $wp_object_cache->$property ) && is_numeric( $wp_object_cache->$property ) ) {
                    $out[ $name ] = max( 0, (int) $wp_object_cache->$property );
                    $out['available'] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /** Report honest instrumentation capability; no monkey-patching wp_cache_get(). */
    public static function capability() {
        $snapshot = self::snapshot();
        return array(
            'mode' => ! empty( $snapshot['available'] ) && isset( $snapshot['hits'], $snapshot['misses'] ) ? 'counter-deltas' : 'no-public-counters',
            'per_operation_callsite_available' => false,
            'note' => 'WordPress does not provide a generic per-cache-call hook. Callback scopes can observe public hit/miss counter changes only; these may include uninstrumented nested work and are not network/Redis timings.',
        );
    }

    /** NULL rather than zero for unmeasured or reset/wrapped counters. */
    public static function delta( array $start, array $end ) {
        $out = array( 'available' => false, 'hits' => null, 'misses' => null, 'lookups' => null, 'hit_rate_percent' => null );
        foreach ( array( 'hits', 'misses' ) as $name ) {
            if ( ! isset( $start[ $name ], $end[ $name ] ) || ! is_numeric( $start[ $name ] ) || ! is_numeric( $end[ $name ] ) || $end[ $name ] < $start[ $name ] ) { continue; }
            $out[ $name ] = (int) $end[ $name ] - (int) $start[ $name ];
            $out['available'] = true;
        }
        if ( null !== $out['hits'] && null !== $out['misses'] ) {
            $out['lookups'] = $out['hits'] + $out['misses'];
            if ( $out['lookups'] > 0 ) { $out['hit_rate_percent'] = round( 100 * $out['hits'] / $out['lookups'], 1 ); }
        }
        return $out;
    }

    /**
     * Capture a compact breakdown from a signed profile or a sampled request.
     * Phase labels mean time spans, not plugin attribution or Redis time.
     */
    public static function report( $start, $marks, $end ) {
        $start = is_array( $start ) ? $start : array();
        $marks = is_array( $marks ) ? $marks : array();
        $end = is_array( $end ) ? $end : array();
        $overall = self::delta( $start, $end );
        $phases = array();
        $prior = $start;
        $prior_name = ! empty( $start['available'] ) && isset( $marks['plugins_loaded'] ) ? 'MU bootstrap / early PHP' : 'profiler start';
        foreach ( array( 'plugins_loaded', 'init', 'wp_loaded', 'wp', 'template_redirect', 'wp_head', 'wp_footer' ) as $phase ) {
            if ( ! isset( $marks[ $phase ] ) || ! is_array( $marks[ $phase ] ) ) { continue; }
            $snapshot = $marks[ $phase ];
            $change = self::delta( $prior, $snapshot );
            if ( $change['available'] ) {
                $phases[] = array( 'from' => $prior_name, 'to' => $phase, 'hits' => $change['hits'], 'misses' => $change['misses'], 'lookups' => $change['lookups'] );
            }
            $prior = $snapshot;
            $prior_name = $phase;
        }
        $last = self::delta( $prior, $end );
        if ( $last['available'] ) { $phases[] = array( 'from' => $prior_name, 'to' => 'profiler shutdown', 'hits' => $last['hits'], 'misses' => $last['misses'], 'lookups' => $last['lookups'] ); }
        return array(
            'available' => $overall['available'],
            'scope' => ! empty( $start['available'] ) ? 'mu-bootstrap-to-profiler-shutdown' : 'counters-unavailable-or-late',
            'hits' => $overall['hits'],
            'misses' => $overall['misses'],
            'lookups' => $overall['lookups'],
            'hit_rate_percent' => $overall['hit_rate_percent'],
            'phases' => array_slice( $phases, 0, 8 ),
            'note' => 'Hit/miss counters may include in-process operations. They do not measure Redis network calls or Redis time; SET/DELETE calls are not counted. Intervals are inclusive of any plugin/theme code executed during that phase.',
        );
    }

    /** Only for confirmed, nonce-protected, same-origin private requests. */
    public static function probe_details( $probe_id ) {
        global $wpdb;
        if ( ! preg_match( '/^[a-f0-9-]{36}$/i', (string) $probe_id ) ) { return array(); }
        $table = PFC_Utils::table( 'runs' );
        $run = $wpdb->get_row( $wpdb->prepare( "SELECT id,payload FROM {$table} WHERE probe_id=%s ORDER BY id DESC LIMIT 1", $probe_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $run ) { return array(); }
        $payload = json_decode( (string) ( $run['payload'] ?? '' ), true );
        $payload = is_array( $payload ) ? $payload : array();
        $cache = isset( $payload['cache_request'] ) && is_array( $payload['cache_request'] ) ? $payload['cache_request'] : array( 'available' => false );
        $queries = PFC_Utils::table( 'queries' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT component_type,component_slug,COUNT(*) AS patterns, SUM(total_ms) AS total_ms, SUM(count) AS queries FROM {$queries} WHERE run_id=%d GROUP BY component_type,component_slug ORDER BY total_ms DESC LIMIT 8", (int) $run['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $components = array();
        foreach ( (array) $rows as $row ) {
            $components[] = array(
                'component' => sanitize_key( (string) ( $row['component_type'] ?? '' ) ) . ':' . sanitize_key( (string) ( $row['component_slug'] ?? '' ) ),
                'sql_ms' => round( (float) ( $row['total_ms'] ?? 0 ), 2 ),
                'queries' => (int) ( $row['queries'] ?? 0 ),
                'patterns' => (int) ( $row['patterns'] ?? 0 ),
            );
        }
        // Pattern fingerprints and timing only. Never retrieve normalized_sql,
        // source_file, raw SQL, EXPLAIN payloads or option values into this report.
        $patterns = $wpdb->get_results( $wpdb->prepare( "SELECT pattern_hash,component_type,component_slug,`count`,total_ms,max_ms FROM {$queries} WHERE run_id=%d ORDER BY count DESC, total_ms DESC LIMIT 20", (int) $run['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array(
            'cache' => $cache,
            'sql_components' => $components,
            'sql_patterns' => PFC_Workload_Advisor::sql_patterns( (array) $patterns ),
            'sql_callsites' => (array) ( $payload['sql_callsites'] ?? array() ),
            'sql_callsites_truncated' => ! empty( $payload['query_details_truncated'] ) || ! empty( $payload['query_traces_at_limit'] ),
            'http_requests' => PFC_Workload_Advisor::http_requests( (array) ( $payload['http'] ?? array() ) ),
            'http_callsites' => PFC_HTTP_Inspector::summary( (array) ( $payload['http'] ?? array() ) ),
            'http_truncated' => ! empty( $payload['http_truncated'] ),
            'phase_timings' => PFC_Workload_Advisor::phase_timings( (array) ( $payload['phases'] ?? array() ) ),
            'callback_profile' => (array) ( $payload['callback_profile'] ?? array( 'enabled' => false ) ),
            'bricks_profile' => (array) ( $payload['bricks_profile'] ?? array( 'enabled' => false ) ),
            'cache_attribution_capability' => self::capability(),
            'sql_timing_available' => ! empty( $payload['query_timing_available'] ),
        );
    }

    public static function guidance( array $runs ) {
        $messages = array();
        if ( ! $runs ) { return array( 'No verified server-side requests. Check signed diagnostic headers, caching/CDN bypass and MU bootstrap status.' ); }
        $known = array_filter( $runs, static function ( $r ) { return ! empty( $r['cache']['available'] ); } );
        if ( ! $known ) {
            return array( 'The active object-cache drop-in does not expose hit/miss counters (or their values reset). This report cannot infer Redis call counts. PHP, SQL and HTTP profiling remains available.' );
        }
        $last = end( $known );
        if ( (int) ( $last['cache']['lookups'] ?? 0 ) >= 500 ) {
            $messages[] = 'At least 500 cache GET lookups were observed on one request. Compare routes and phase intervals; these are not necessarily Redis commands.';
        }
        if ( isset( $last['cache']['hit_rate_percent'] ) && null !== $last['cache']['hit_rate_percent'] && $last['cache']['hit_rate_percent'] < 65 && (int) $last['cache']['lookups'] > 100 ) {
            $messages[] = 'The sampled cache hit ratio is below 65%. Investigate cold caches, invalidation churn, or object groups excluded from persistence.';
        }
        $messages[] = 'Signed deep profiling enables query and hook tracing, so PHP timings include some diagnostic overhead. Compare equivalent routes and repeated runs rather than treating them as untouched production timings.';
        $messages[] = 'A 0.05 ms synthetic Redis GET is not a measured cost for each real cache lookup. Do not multiply synthetic command timings by WordPress hit/miss counters.';
        $messages[] = 'Use the SQL component table to investigate database-heavy plugins; it does not attribute cache calls or cache duration to plugins.';
        $messages[] = 'Optional callback-scope cache counters describe operations that occurred while a callback ran, not Redis network calls or proof that the callback initiated each operation.';
        return $messages;
    }
}
