<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Analyze a small set of verified, signed diagnostic requests.
 * Classification is evidence-based. Nothing in this class modifies site data.
 */
final class PFC_Workload_Advisor {
    /**
     * Bounded HTTP summary from a stored request payload. Do not persist or display
     * request paths/querystrings, headers, bodies, or transport exception text.
     */
    public static function http_requests( array $requests ) {
        $out = array();
        $site_host = function_exists( 'wp_parse_url' ) && function_exists( 'home_url' ) ? strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) : '';
        foreach ( array_slice( $requests, 0, 100 ) as $r ) {
            if ( ! is_array( $r ) ) { continue; }
            $c = isset( $r['component'] ) && is_array( $r['component'] ) ? $r['component'] : array();
            $component = self::component( (string) ( $c['type'] ?? 'core' ), (string) ( $c['slug'] ?? 'wordpress' ) );
            $host = strtolower( (string) ( $r['host'] ?? '' ) );
            $type = in_array( $r['target_type'] ?? '', array( 'external', 'same-site', 'unknown' ), true ) ? $r['target_type'] : ( '' === $host ? 'unknown' : ( $site_host && $site_host === $host ? 'same-site' : 'external' ) );
            $status = (int) ( $r['status'] ?? 0 );
            $out[] = array(
                'component' => $component,
                'target_type' => $type,
                'method' => in_array( strtoupper( (string) ( $r['method'] ?? 'GET' ) ), array( 'GET','POST','PUT','PATCH','DELETE','HEAD' ), true ) ? strtoupper( (string) $r['method'] ) : 'OTHER',
                'blocking' => ! empty( $r['blocking'] ),
                'duration_ms' => round( max( 0, (float) ( $r['ms'] ?? 0 ) ), 2 ),
                'status' => $status >= 100 && $status <= 599 ? $status : null,
                'failed' => ! empty( $r['failed'] ) || ! empty( $r['error'] ) || 0 === $status || $status >= 400,
                'target_group_id' => preg_match( '/^[a-f0-9]{16}$/D', (string) ( $r['target_group'] ?? '' ) ) ? $r['target_group'] : null,
            );
        }
        usort( $out, static function ( $a, $b ) { return $b['duration_ms'] <=> $a['duration_ms']; } );
        return array_slice( $out, 0, 20 );
    }

    /** Bounded lifecycle durations already captured by the early MU bootstrap. */
    public static function phase_timings( array $phases ) {
        $out = array();
        foreach ( array_slice( $phases, 0, 30, true ) as $label => $ms ) {
            if ( ! is_string( $label ) || ! preg_match( '/^[a-z_]{2,40}_to_[a-z_]{2,40}$/D', $label ) || ! is_numeric( $ms ) || (float) $ms < 0 || (float) $ms > 600000 ) { continue; }
            $out[] = array( 'interval' => $label, 'duration_ms' => round( (float) $ms, 2 ) );
        }
        usort( $out, static function ( $a, $b ) { return $b['duration_ms'] <=> $a['duration_ms']; } );
        return array_slice( $out, 0, 12 );
    }

    /** The original query pattern identifier is a fingerprint only, never SQL text. */
    public static function sql_patterns( array $rows ) {
        $out = array();
        foreach ( array_slice( $rows, 0, 20 ) as $r ) {
            if ( ! is_array( $r ) ) { continue; }
            $hash = (string) ( $r['pattern_hash'] ?? '' );
            if ( ! preg_match( '/^[a-f0-9]{32}$/D', $hash ) ) { continue; }
            $out[] = array(
                'pattern_id' => substr( $hash, 0, 12 ),
                'component' => self::component( (string) ( $r['component_type'] ?? '' ), (string) ( $r['component_slug'] ?? '' ) ),
                'count' => max( 0, (int) ( $r['count'] ?? 0 ) ),
                'total_ms' => round( max( 0, (float) ( $r['total_ms'] ?? 0 ) ), 2 ),
                'max_ms' => round( max( 0, (float) ( $r['max_ms'] ?? 0 ) ), 2 ),
            );
        }
        return $out;
    }

    private static function component( $type, $slug ) {
        $type = in_array( $type, array( 'plugin','theme','mu-plugin','core' ), true ) ? $type : 'core';
        $slug = strtolower( $slug );
        $slug = preg_match( '/^[a-z0-9_-]{1,80}$/D', $slug ) ? $slug : 'unknown';
        return $type . ':' . $slug;
    }

    /**
     * First profiled request versus subsequent verified runs. This is not a
     * measurement of true cache warmth; signed requests bypass page cache.
     */
    public static function first_vs_following_sql( array $runs ) {
        $runs = array_values( array_filter( array_slice( $runs, 0, 5 ), 'is_array' ) );
        if ( count( $runs ) < 3 ) { return array( 'available' => false, 'reason' => 'need_three_verified_runs' ); }
        foreach ( $runs as $run ) {
            if ( empty( $run['sql_timing_available'] ) || ! isset( $run['db_ms'] ) || ! is_numeric( $run['db_ms'] ) ) {
                return array( 'available' => false, 'reason' => 'sql_timing_unavailable' );
            }
        }
        $first = max( 0, (float) $runs[0]['db_ms'] );
        $later = array_map( static function ( $r ) { return max( 0, (float) $r['db_ms'] ); }, array_slice( $runs, 1 ) );
        sort( $later, SORT_NUMERIC );
        $n = count( $later );
        $median = $n % 2 ? $later[ (int) floor( $n / 2 ) ] : ( $later[$n/2-1] + $later[$n/2] ) / 2;
        $later_ids = array();
        foreach ( array_slice( $runs, 1 ) as $run ) {
            foreach ( (array) ( $run['sql_patterns'] ?? array() ) as $row ) {
                if ( preg_match( '/^[a-f0-9]{12}$/D', (string) ( $row['pattern_id'] ?? '' ) ) ) { $later_ids[ $row['pattern_id'] ] = true; }
            }
        }
        $only = array();
        foreach ( (array) ( $runs[0]['sql_patterns'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) || ! preg_match( '/^[a-f0-9]{12}$/D', (string) ( $row['pattern_id'] ?? '' ) ) || isset( $later_ids[ $row['pattern_id'] ] ) ) { continue; }
            $ms = (float) ( $row['total_ms'] ?? 0 );
            if ( $ms < 10 ) { continue; }
            $component = (string) ( $row['component'] ?? '' );
            $only[] = array(
                'pattern_id' => $row['pattern_id'],
                'component' => preg_match( '/^(plugin|theme|mu-plugin|core):[a-z0-9_-]{1,80}$/D', $component ) ? $component : 'core:unknown',
                'first_sql_ms' => round( $ms, 2 ),
            );
        }
        usort( $only, static function ( $a, $b ) { return $b['first_sql_ms'] <=> $a['first_sql_ms']; } );
        return array(
            'available' => true,
            'first_sql_ms' => round( $first, 2 ),
            'later_median_sql_ms' => round( $median, 2 ),
            'first_minus_later_ms' => round( $first - $median, 2 ),
            'first_only_patterns' => array_slice( $only, 0, 6 ),
            'patterns_may_be_truncated' => true,
            'interpretation' => 'Observed first versus later signed requests only; not proof of cold-cache causation.',
        );
    }

    /**
     * Recommendations reflect at least two observed diagnostic runs where possible.
     * "fix" values are navigation/action categories, not an arbitrary write API.
     */
    public static function findings( array $runs, array $scan = array() ) {
        $issues = array();
        $runs = array_values( array_filter( array_slice( $runs, 0, 5 ), 'is_array' ) );
        if ( ! $runs ) { return array(); }
        $request_count = count( $runs );
        $http_slow = array();
        $lookups = array();
        $sql_repeat = array();
        $sql_costs = array();
        $max_sql = 0;
        foreach ( $runs as $run ) {
            $http = (array) ( $run['http_requests'] ?? array() );
            $slow = 0;
            foreach ( $http as $item ) { if ( ! empty( $item['blocking'] ) ) { $slow += (float) ( $item['duration_ms'] ?? 0 ); } }
            $http_slow[] = round( $slow, 1 );
            $cache = (array) ( $run['cache'] ?? array() );
            if ( isset( $cache['lookups'] ) && is_numeric( $cache['lookups'] ) ) { $lookups[] = (int) $cache['lookups']; }
            $max_sql = max( $max_sql, (float) ( $run['db_ms'] ?? 0 ) );
            foreach ( (array) ( $run['sql_components'] ?? array() ) as $c ) {
                if ( ! is_array( $c ) ) { continue; }
                $component = (string) ( $c['component'] ?? '' );
                $ms = (float) ( $c['sql_ms'] ?? 0 );
                if ( ! preg_match( '/^(plugin|theme|mu-plugin):[a-z0-9_-]{1,80}$/D', $component ) || $ms < 25 ) { continue; }
                if ( ! isset( $sql_costs[ $component ] ) ) { $sql_costs[ $component ] = array( 'runs' => 0, 'max_ms' => 0 ); }
                $sql_costs[ $component ]['runs']++;
                $sql_costs[ $component ]['max_ms'] = max( $ms, $sql_costs[ $component ]['max_ms'] );
            }
            $heavy = array();
            foreach ( (array) ( $run['sql_patterns'] ?? array() ) as $p ) {
                if ( (int) ( $p['count'] ?? 0 ) >= 20 ) {
                    $component = (string) ( $p['component'] ?? 'core:unknown' );
                    $heavy[ $component ] = max( (int) ( $heavy[ $component ] ?? 0 ), (int) $p['count'] );
                }
            }
            foreach ( $heavy as $component => $count ) {
                if ( ! isset( $sql_repeat[ $component ] ) ) { $sql_repeat[ $component ] = array( 'runs' => 0, 'max' => 0 ); }
                $sql_repeat[ $component ]['runs']++;
                $sql_repeat[ $component ]['max'] = max( $count, $sql_repeat[ $component ]['max'] );
            }
        }
        $rate_limited = array();
        foreach ( $runs as $run ) {
            $per_run = array();
            foreach ( (array) ( $run['http_requests'] ?? array() ) as $request ) {
                if ( ! is_array( $request ) || 429 !== (int) ( $request['status'] ?? 0 ) ) { continue; }
                $component = (string) ( $request['component'] ?? '' );
                if ( ! preg_match( '/^(plugin|theme|mu-plugin):[a-z0-9_-]{1,80}$/D', $component ) ) { continue; }
                $per_run[ $component ] = (int) ( $per_run[ $component ] ?? 0 ) + 1;
            }
            foreach ( $per_run as $component => $count ) {
                if ( ! isset( $rate_limited[ $component ] ) ) { $rate_limited[ $component ] = array( 'runs' => 0, 'calls' => 0 ); }
                $rate_limited[ $component ]['runs']++;
                $rate_limited[ $component ]['calls'] += $count;
            }
        }
        $need_repeat = $request_count >= 2 ? 2 : 1;
        $http_over = count( array_filter( $http_slow, static function ( $v ) { return $v >= 500; } ) );
        if ( $http_over >= $need_repeat ) {
            $issues[] = self::finding( 'http-blocking', 'high', 'Blocking outbound HTTP is slowing requests',
                'Observed at least 500 ms of summed blocking HTTP API time on ' . $http_over . ' of ' . $request_count . ' verified requests; these durations are not guaranteed to be exclusive.',
                'Review the per-request HTTP component list. For non-essential remote requests, configure the owning integration to cache results or queue work asynchronously. Test on staging before changing behaviour.', 'http', 'guided' );
        }
        foreach ( $rate_limited as $component => $stats ) {
            if ( $stats['runs'] < $need_repeat ) { continue; }
            $issues[] = self::finding( 'http-rate-limit-' . $component, 'high', 'Repeated HTTP 429 rate limits: ' . $component,
                'Remote service returned 429 on ' . $stats['calls'] . ' captured calls across ' . $stats['runs'] . ' of ' . $request_count . ' signed requests.',
                'Identify the originating PHP callsite and remote integration. Verify provider quotas, product-data caching, retry/backoff settings and scheduled refresh. Do not automatically block or replay third-party requests; equal target-group IDs do not prove retries.', 'http', 'guided' );
        }
        $first_warm = self::first_vs_following_sql( $runs );
        if ( ! empty( $first_warm['available'] ) && (float) $first_warm['first_minus_later_ms'] >= 250 && (float) $first_warm['first_sql_ms'] >= 2 * max( 1, (float) $first_warm['later_median_sql_ms'] ) ) {
            $leader = (array) ( $first_warm['first_only_patterns'][0] ?? array() );
            $source = (string) ( $leader['component'] ?? 'unattributed' );
            $issues[] = self::finding( 'sql-first-request-spike', 'warning', 'First signed request SQL spike',
                'First SQL ' . $first_warm['first_sql_ms'] . ' ms versus later median ' . $first_warm['later_median_sql_ms'] . ' ms. Largest first-only fingerprint component: ' . $source . '.',
                'Compare SQL fingerprints and Bricks template query loops in subsequent runs and repeat under similar conditions. This is not proof of a cold-cache effect; investigate query plans before changing indexes.', 'sql', 'guided' );
        }
        if ( count( $lookups ) >= $need_repeat && count( array_filter( $lookups, static function ( $n ) { return $n >= 10000; } ) ) >= $need_repeat ) {
            $issues[] = self::finding( 'cache-lookups', 'high', 'Very high WordPress cache GET workload',
                'Observed ' . number_format( min( $lookups ) ) . '–' . number_format( max( $lookups ) ) . ' cache GET lookups per profiled request; these include in-process hits and are not Redis command counts.',
                'Examine cache lookup phases and use the existing one-request plugin-impact test on staging. Avoid generic cache purges; reduce repeated reads and frontend query loops in the responsible code.', 'cache', 'guided' );
        }
        uasort( $sql_repeat, static function ( $a, $b ) { return $b['max'] <=> $a['max']; } );
        foreach ( array_slice( $sql_repeat, 0, 3, true ) as $component => $stats ) {
            if ( $stats['runs'] < $need_repeat ) { continue; }
            $special = array(
                'plugin:wpcodebox2' => 'Review frontend snippet execution conditions and repeated data access in WPCodeBox 2. Restrict snippets to relevant routes, and cache stable results within a request only after checking snippet semantics.',
                'plugin:cgm-image-renamer' => 'Inspect image renaming/thumbnail-maintenance hooks. Move maintenance scans and rebuilds to admin-only actions or bounded background jobs instead of frontend rendering.',
                'plugin:keystone-seo' => 'Inspect frontend metadata/schema assembly and option/meta lookup repetition; reuse cached values where they stay valid across the request.',
                'theme:bricks' => 'Audit Bricks template query loops and relationship queries, particularly nested homepage loops. Check object caching and query result reuse for each template.',
            );
            $guidance = $special[ $component ] ?? 'Check whether the same table/options lookup is repeated inside a loop. Use per-request memoization or correct query caching only after validating returned data.';
            $issues[] = self::finding( 'sql-repeat-' . $component, 'warning', 'Repeated SQL pattern from ' . $component,
                'At least one SQL pattern ran up to ' . $stats['max'] . ' times in a request, recurring across ' . $stats['runs'] . ' verified requests.',
                $guidance . ' Do not blindly deduplicate queries.', 'sql', 'guided' );
        }
        uasort( $sql_costs, static function ( $a, $b ) { return $b['max_ms'] <=> $a['max_ms']; } );
        foreach ( array_slice( $sql_costs, 0, 3, true ) as $component => $stats ) {
            if ( $stats['runs'] < $need_repeat || ( isset( $sql_repeat[ $component ] ) && $sql_repeat[ $component ]['runs'] >= $need_repeat ) ) { continue; }
            $recommendation = 'Inspect the slowest normalized query fingerprints for this component and compare an isolated plugin-impact test before rewriting queries or altering indexes.';
            if ( 'plugin:cgm-image-renamer' === $component ) { $recommendation = 'Audit whether image/thumbnail-maintenance queries are running on ordinary frontend routes. Move maintenance scans to explicit admin actions or background jobs after checking the plugin integration.'; }
            elseif ( 'plugin:keystone-seo' === $component ) { $recommendation = 'Audit frontend SEO/schema query sources; cache stable lookup results per request and verify invalidation after content changes.'; }
            $issues[] = self::finding( 'sql-cost-' . $component, 'warning', 'Recurring SQL time from ' . $component,
                'Attributed SQL work reached ' . round( $stats['max_ms'], 1 ) . ' ms in a request, with at least 25 ms observed on ' . $stats['runs'] . ' verified requests.',
                $recommendation, 'sql', 'guided' );
        }
        if ( $max_sql >= 500 ) {
            $issues[] = self::finding( 'sql-spike', 'warning', 'SQL execution time spiked during profiling',
                'At least one verified request spent ' . round( $max_sql, 1 ) . ' ms executing SQL; compare cold and warm runs and the slowest pattern IDs.',
                'Inspect the SQL pattern timings and query plans before changing indexes. Existing database repair tools require an explicit backup and preflight.', 'sql', 'guided' );
        }
        $options = isset( $scan['database_health']['options'] ) && is_array( $scan['database_health']['options'] ) ? $scan['database_health']['options'] : array();
        if ( (int) ( $options['expired_transients'] ?? 0 ) > 0 && empty( $options['expired_transients_capped'] ) ) {
            $issues[] = self::finding( 'expired-transients', 'info', 'Expired database transients can be cleaned safely',
                'Last system scan found ' . (int) $options['expired_transients'] . ' expired transient timeout rows (scan snapshot, not live verification).',
                'Preview the database maintenance results, then optionally run a bounded cleanup of expired transient values only. It is not a proven fix for HTTP or cache lookup overhead.', 'transients', 'bounded_cleanup' );
        }
        if ( (int) ( $options['autoload_bytes'] ?? 0 ) > 800 * 1024 ) {
            $issues[] = self::finding( 'autoload', 'warning', 'Large WordPress autoload payload',
                'System scan reported ' . number_format( (int) $options['autoload_bytes'] ) . ' bytes of autoloaded options.',
                'Review the largest options and observed access evidence. Disable autoload for a specific confirmed candidate only after backup; Performance Console records and supports rollback of autoload changes.', 'autoload', 'guided' );
        }
        return array_slice( $issues, 0, 10 );
    }

    private static function finding( $id, $severity, $title, $evidence, $recommendation, $action, $repair ) {
        return compact( 'id', 'severity', 'title', 'evidence', 'recommendation', 'action', 'repair' );
    }
}
