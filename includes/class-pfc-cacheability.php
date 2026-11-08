<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Query cacheability advisor.
 *
 * Duplicate-query detection already tells an operator that a statement runs
 * many times. That is not the same as telling them it is safe to cache: a read
 * that depends on NOW(), a row lock or the current user must not be served from
 * a shared cache. This class separates "repeats and costs time" from "repeats,
 * costs time, and is deterministic", then estimates what caching would save.
 */
final class PFC_Cacheability {
    const MIN_CALLS = 2;
    const MIN_SAVED_MS = 10;
    const MAX_PATTERNS = 60;

    /** Statements and functions whose result cannot be reused safely. */
    const NON_DETERMINISTIC = '/\b(NOW|CURDATE|CURTIME|CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|UNIX_TIMESTAMP|SYSDATE|RAND|RANDOM|UUID|LAST_INSERT_ID|CONNECTION_ID|CURRENT_USER|SESSION_USER|SYSTEM_USER|USER|DATABASE|SCHEMA|VERSION|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|SLEEP|BENCHMARK)\s*\(/i';

    /** Tables whose rows are scoped to the current visitor or session. */
    const USER_SCOPED = '/\b(?:[a-z0-9_]*_)?(?:usermeta|users|session|sessions|woocommerce_sessions|queue|mailqueue)\b/i';

    const REASONS = array(
        'read'              => 'Deterministic read: the same inputs may be served from cache.',
        'write'             => 'Not a read; caching would change write behaviour.',
        'not_read'          => 'Not a read statement.',
        'locking_read'      => 'Reads under a row lock cannot be shared between requests.',
        'non_deterministic' => 'Uses a time-, session- or server-dependent function, so the same inputs can return different rows.',
        'user_scoped'       => 'Reads visitor- or session-scoped rows; caching it could leak one user\'s data to another.',
        'found_rows'        => 'SQL_CALC_FOUND_ROWS result is bound to the connection that ran it.',
        'review_cte'        => 'Starts with a CTE; confirm it is not recursive before caching.',
        'empty'             => 'Empty pattern.',
    );

    /**
     * Decide whether a normalized statement may be cached.
     *
     * Pure and conservative: an unknown construct is reported as not cacheable.
     * The cost of a false negative is a missed optimization; the cost of a false
     * positive is stale or leaked data.
     *
     * @return array{cacheable:bool,kind:string,reason:string}
     */
    public static function classify( $normalized_sql ) {
        $sql = trim( (string) $normalized_sql );
        $not = static function ( $reason ) {
            return array( 'cacheable' => false, 'kind' => 'none', 'reason' => $reason, 'explanation' => self::REASONS[ $reason ] ?? '' );
        };
        if ( '' === $sql ) { return $not( 'empty' ); }
        if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|RENAME|GRANT|SET|CALL|LOAD)\b/i', $sql ) ) { return $not( 'write' ); }
        if ( ! preg_match( '/^\s*(SELECT|WITH)\b/i', $sql ) ) { return $not( 'not_read' ); }
        if ( preg_match( '/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $sql ) ) { return $not( 'locking_read' ); }
        if ( preg_match( '/\bSQL_CALC_FOUND_ROWS\b/i', $sql ) ) { return $not( 'found_rows' ); }
        if ( preg_match( '/^\s*WITH\b/i', $sql ) ) { return $not( 'review_cte' ); }
        if ( preg_match( self::NON_DETERMINISTIC, $sql ) ) { return $not( 'non_deterministic' ); }
        if ( preg_match( self::USER_SCOPED, $sql ) ) { return $not( 'user_scoped' ); }
        return array(
            'cacheable'   => true,
            'kind'        => 'transient',
            'reason'      => 'read',
            'explanation' => self::REASONS['read'],
        );
    }

    /**
     * Rank cacheable repeated reads by the time caching would plausibly save.
     *
     * "Plausibly" is deliberate: the estimate assumes one execution per request
     * is unavoidable and every further execution is removable. Real caching
     * also pays serialization and lookup costs, so this is an upper bound on
     * the benefit, not a promise.
     */
    public static function analyze( $days = 7, $limit = 40 ) {
        global $wpdb;
        $days = max( 1, min( 30, (int) $days ) );
        $limit = max( 1, min( self::MAX_PATTERNS, (int) $limit ) );
        $queries = PFC_Utils::table( 'queries' );
        $runs = PFC_Utils::table( 'runs' );
        $from = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $sql = "SELECT q.pattern_hash, MAX(q.normalized_sql) normalized_sql, SUM(q.count) calls, SUM(q.total_ms) total_ms,
                MAX(q.max_ms) max_ms, COUNT(DISTINCT q.run_id) runs, MAX(q.component_type) component_type, MAX(q.component_slug) component_slug
            FROM {$queries} q INNER JOIN {$runs} r ON r.id=q.run_id
            WHERE r.created_at >= %s
            GROUP BY q.pattern_hash
            HAVING SUM(q.count) >= %d
            ORDER BY SUM(q.total_ms) DESC
            LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $from, self::MIN_CALLS, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $out = array( 'window_days' => $days, 'rows' => array(), 'saved_ms_total' => 0.0, 'cacheable_count' => 0, 'reviewed_count' => 0 );
        foreach ( (array) $rows as $row ) {
            $out['reviewed_count']++;
            $classification = self::classify( (string) $row['normalized_sql'] );
            if ( empty( $classification['cacheable'] ) ) {
                $out['rows'][] = array(
                    'pattern_hash' => (string) $row['pattern_hash'],
                    'sql'          => PFC_Utils::truncate( (string) $row['normalized_sql'], 600 ),
                    'calls'        => (int) $row['calls'],
                    'runs'         => (int) $row['runs'],
                    'total_ms'     => round( (float) $row['total_ms'], 1 ),
                    'component'    => ( 'plugin' === (string) $row['component_type'] || 'theme' === (string) $row['component_type'] ) ? (string) $row['component_slug'] : (string) $row['component_type'],
                    'cacheable'    => false,
                    'kind'         => 'none',
                    'reason'       => $classification['reason'],
                    'explanation'  => $classification['explanation'],
                    'saved_ms'     => 0.0,
                );
                continue;
            }
            $calls = max( 1, (int) $row['calls'] );
            $run_count = max( 1, (int) $row['runs'] );
            $average = (float) $row['total_ms'] / $calls;
            $removable = max( 0, $calls - $run_count );
            $saved = $removable * $average;
            // A pattern repeated inside a single request is best served by a
            // request-scoped cache; one repeated across requests needs a real
            // expiring store with explicit invalidation.
            $kind = $removable > 0 ? ( $run_count <= 1 ? 'request_static' : 'transient' ) : 'transient';
            if ( $saved < self::MIN_SAVED_MS && $calls < 3 ) { continue; }
            $out['cacheable_count']++;
            $out['saved_ms_total'] += $saved;
            $out['rows'][] = array(
                'pattern_hash' => (string) $row['pattern_hash'],
                'sql'          => PFC_Utils::truncate( (string) $row['normalized_sql'], 600 ),
                'calls'        => $calls,
                'runs'         => $run_count,
                'total_ms'     => round( (float) $row['total_ms'], 1 ),
                'average_ms'   => round( $average, 2 ),
                'component'    => ( 'plugin' === (string) $row['component_type'] || 'theme' === (string) $row['component_type'] ) ? (string) $row['component_slug'] : (string) $row['component_type'],
                'cacheable'    => true,
                'kind'         => $kind,
                'reason'       => $classification['reason'],
                'explanation'  => $classification['explanation'],
                'saved_ms'     => round( $saved, 1 ),
            );
        }
        $out['saved_ms_total'] = round( $out['saved_ms_total'], 1 );
        usort( $out['rows'], static function ( $a, $b ) { return (float) $b['saved_ms'] <=> (float) $a['saved_ms']; } );
        return $out;
    }

    /**
     * Guidance for one pattern. Deliberately does not emit code that touches
     * plugin internals it cannot see.
     */
    public static function recommendation( array $row ) {
        if ( empty( $row['cacheable'] ) ) {
            return array(
                'action' => 'review',
                'text'   => 'Not recommended for caching: ' . (string) ( $row['explanation'] ?? '' ),
            );
        }
        $kind = (string) ( $row['kind'] ?? 'transient' );
        if ( 'request_static' === $kind ) {
            return array(
                'action' => 'request_static',
                'text'   => 'Runs ' . (int) $row['calls'] . ' times per request. Memoize the result for the life of the request (a static array or wp_cache_set with the request-scoped group), so the second and later calls avoid the database round trip.',
            );
        }
        return array(
            'action' => 'transient',
            'text'   => 'Repeats across requests. Cache the result in the object cache or a transient keyed on the query inputs, and invalidate it on the writes that change those rows. Verify that the cached window is acceptable for this content before enabling it.',
        );
    }
}
