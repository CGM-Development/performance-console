<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Privacy-conscious, allowlisted exports of diagnostic measurements for external AI tools.
 * This is intentionally NOT a dump of cached profiler payloads, SQL, options, or PHP configuration.
 * Only runs when an administrator explicitly downloads a report.
 */
final class PFC_AI_Export {
    const SCHEMA_VERSION = '2.3';

    /**
     * Build a self-contained JSON-ready report from already collected results.
     * No additional probes, database scans or outbound requests are performed.
     */
    public static function build( $diagnostic, $profile, $scan = array(), $scope = 'cache' ) {
        $diagnostic = is_array( $diagnostic ) ? $diagnostic : array();
        $profile = is_array( $profile ) ? $profile : array();
        $scan = is_array( $scan ) ? $scan : array();
        $scope = 'scan' === $scope ? 'scan' : 'cache';
        if ( 'scan' === $scope && ! $scan ) { return array(); }
        if ( 'cache' === $scope && ! $diagnostic && ! $profile ) { return array(); }

        $out = array(
            'format' => 'performance-console-ai-diagnostics',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at_utc' => gmdate( 'c' ),
            'source' => array(
                'plugin' => 'Performance Console',
                'version' => defined( 'PFC_VERSION' ) ? PFC_VERSION : 'unknown',
                'export' => 'administrator-requested-json',
            ),
            'privacy' => array(
                'mode' => 'redacted',
                'excluded' => array(
                    'site domain and full URLs', 'IP addresses', 'request query strings',
                    'WordPress and Redis credentials', 'Redis key prefixes and key values',
                    'SQL statements and literal values', 'absolute filesystem paths and raw stack traces', 'database option values',
                    'user identities', 'cookies, nonces and tokens',
                    'unfiltered plugin diagnostics and error logs',
                ),
                'note' => 'Contains selected technical metrics, plugin names and code identifiers/relative source paths only. Review the file before sharing it with an external AI service.',
            ),
            'analysis_request' => array(
                'question' => 'scan' === $scope
                    ? 'Which measured site-level database, autoload, cron or object-cache factors deserve further performance investigation?'
                    : 'What measured WordPress, Redis, object-cache or SQL factors could explain the reported 80-100 ms cache-related timing, and what should be checked next?',
                'instructions' => array(
                    'Separate measured results from hypotheses; do not claim that a single Redis command costs 80-100 ms without evidence.',
                    'Compare direct Redis command times with WordPress cache API measurements and verified request timings.',
                    'Cache hit/miss counters are not Redis network calls or cumulative Redis latency; SET/DELETE calls are not counted by those counters.',
                    'Note that short synthetic benchmarks and signed request profiles may not represent ordinary production traffic.',
                    'Prioritize non-destructive checks and propose ways to validate improvements before modifying production settings.',
                    'Treat null or absent measurements as unavailable, not as zero.',
                    'Individual HTTP durations are WordPress HTTP API timing, not browser requests; their sum is not necessarily exclusive PHP time.',
                    'SQL pattern IDs are anonymized fingerprints, not the text of SQL queries.',
                    'HTTP target-group IDs use a site-secret HMAC of method, host and path without query strings; sharing a group does not prove identical requests or retries.',
                    'First-request versus later-request SQL comparisons describe this short signed sample only, not confirmed cache warmth or cause.',
                    'Bricks query groups are HMACs of limited query-shape metadata. Group membership is not a unique query or element identity.',
                    'Bricks element identity is only exact if an observed WP_Query result object matches the Bricks query result filter. Short Bricks element IDs are never exported.',
                    'Unclosed WordPress query scopes mean no completion hook was observed, not that a query failed. Fallback result-hook timing is an upper bound.',
                    'Unclosed query categories are evidence only: suppressed filters are a candidate cause, object release and result state do not prove success or failure.',
                    'Initialization callback minus observed child-query time is a rough, non-exclusive difference; nested scopes and instrumented work can overlap.',
                    'Bricks initialization accessor samples are selected WordPress metadata and option hooks, not all cache lookups or Redis calls.',
                    'An alternating signed standard/deep paired profile is a small observational diagnostic, not guaranteed profiler overhead.',
                    'Object Cache Pro health and ACL results are read-only checks; missing native check results and denied optional commands do not independently prove Key Tracking failure.',
                    'Flush history reports pre-flush attempts and not confirmed completed Redis FLUSHDB or group invalidations.',
                    'Object Cache Pro group inventory is opt-in and may be costly; group names are allowlisted or anonymized and no cache keys or values are exported.',
                    'Cache call attribution is from WordPress callback scope counter deltas, not individual cache operations or Redis network commands.',
                    'Native Key Tracking may come from WordPress Site Health Info, a registered native test, or an administrator-reported status. Reports are not proof of a specific Redis ACL cause.',
                    'Cache-group growth compares opted-in, anonymized snapshots and does not prove an eviction rate or a memory leak.',
                    'A native Key Tracking status-only Error leaves the failure category unknown. Administrator-supplied single diagnostic lines are parsed in memory and never exported as text.',
                    'Flush freshness distinguishes recent observed attempts from historical attempts and does not prove the flush succeeded.',
                    'Key Tracking triage cannot identify the exact failing native command when the provider only reports Error. Optional CLIENT TRACKINGINFO denial is not causal evidence.',
                    'Flush burst counts represent observed pre-flush hook attempts, not confirmed cache invalidation. Repeated Bricks key/object filter events are not individually timed Redis operations.',
                ),
            ),
            'included' => array(
                'synthetic_cache_test' => 'cache' === $scope && ! empty( $diagnostic ),
                'verified_request_profile' => 'cache' === $scope && ! empty( $profile['runs'] ),
                'paired_diagnostic_comparison' => 'cache' === $scope && ! empty( $profile['mode_comparison'] ),
                'site_scan_summary' => ! empty( $scan ),
            ),
        );
        if ( 'cache' === $scope ) {
            if ( $diagnostic ) { $out['synthetic_cache_test'] = self::diagnostic( $diagnostic ); }
            $out['object_cache_pro'] = PFC_Object_Cache_Pro::export( $diagnostic, $profile );
            if ( ! empty( $profile['runs'] ) ) { $out['real_request_profile'] = self::profile( $profile ); }
            if ( ! empty( $profile['mode_comparison'] ) && is_array( $profile['mode_comparison'] ) ) {
                $out['paired_diagnostic_comparison'] = self::paired_comparison_export( $profile['mode_comparison'] );
            }
        }
        if ( $scan ) { $out['site_scan_summary'] = self::scan( $scan ); }
        return $out;
    }

    /** Allowlist measurements and safe categorical information, not Redis internals. */
    private static function diagnostic( array $d ) {
        $config = (array) ( $d['configuration'] ?? array() );
        $wp = (array) ( $d['object_cache'] ?? array() );
        $redis = (array) ( $d['redis'] ?? array() );
        $autoload = (array) ( $d['autoload'] ?? array() );
        $options = array();
        foreach ( array_slice( (array) ( $autoload['largest'] ?? array() ), 0, 15 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $options[] = array(
                'name' => self::safe_option_name( $row['option_name'] ?? '' ),
                'bytes' => self::nonnegative( $row['bytes'] ?? null ),
                'autoload' => self::category( $row['autoload'] ?? null, array( 'yes', 'on', 'auto', 'auto-on', 'no', 'off', 'auto-off' ) ),
            );
        }
        $counter_keys = array( 'connected_clients', 'blocked_clients', 'used_memory', 'maxmemory', 'evicted_keys', 'keyspace_hits', 'keyspace_misses', 'instantaneous_ops_per_sec' );
        $counters = array();
        foreach ( $counter_keys as $key ) {
            if ( isset( $redis['server_counters'][ $key ] ) ) { $counters[ $key ] = self::nonnegative( $redis['server_counters'][ $key ] ); }
        }
        return array(
            'captured_at_utc' => self::utc_string( $d['at'] ?? '' ),
            'persistent_object_cache_active' => isset( $d['persistent'] ) ? (bool) $d['persistent'] : null,
            'configuration' => array(
                'provider' => self::category( $config['provider'] ?? '', array( 'xSpeed', 'WP_REDIS', 'WP_REDIS constants', 'WP_REDIS_CONFIG', 'OBJECTCACHE_CONFIG', 'unidentified' ) ),
                'transport' => self::category( $config['transport'] ?? '', array( 'tcp', 'tls', 'unix' ) ),
                'topology' => self::category( $config['topology'] ?? '', array( 'single', 'complex' ) ),
                'local_host' => isset( $config['local_host'] ) ? (bool) $config['local_host'] : null,
                'persistent_connection' => isset( $config['persistent_connection'] ) ? (bool) $config['persistent_connection'] : null,
                'phpredis_available' => isset( $config['phpredis_available'] ) ? (bool) $config['phpredis_available'] : null,
            ),
            'wordpress_cache_api' => array(
                'sample_count' => self::nonnegative( $wp['samples'] ?? null ),
                'failed_readbacks' => self::nonnegative( $wp['failed'] ?? null ),
                'set' => self::timing( (array) ( $wp['set'] ?? array() ) ),
                'get' => self::timing( (array) ( $wp['get'] ?? array() ) ),
                'delete' => self::timing( (array) ( $wp['delete'] ?? array() ) ),
                'interpretation' => 'WordPress cache API timings include PHP and cache-drop-in overhead; they may include in-process cache hits.',
            ),
            'direct_redis' => array(
                'status' => self::category( $redis['status'] ?? '', array( 'ok', 'unavailable' ) ),
                'stage' => self::category( $redis['stage'] ?? '', array( 'complete', 'connect', 'auth', 'database', 'ping', 'commands', 'client', 'topology', 'configuration', 'SET', 'GET', 'DEL' ) ),
                'result_category' => self::category( $redis['result_category'] ?? $redis['failure_kind'] ?? '', array( 'success', 'acl', 'read_only', 'memory_limit', 'command_disabled', 'auth', 'cluster', 'transport', 'unknown' ) ),
                'key_prefix_source' => self::category( $redis['key_prefix_source'] ?? '', array( 'none', 'WP_REDIS_PREFIX', 'WP_CACHE_KEY_SALT', 'WP_REDIS_CONFIG prefix', 'OBJECTCACHE_CONFIG prefix' ) ),
                'connection_ms' => self::decimal( $redis['connection_ms'] ?? null ),
                'ping_ms' => self::decimal( $redis['ping_ms'] ?? null ),
                'sample_count' => self::nonnegative( $redis['samples'] ?? null ),
                'failed_readbacks' => self::nonnegative( $redis['failed'] ?? null ),
                'set' => self::timing( (array) ( $redis['set'] ?? array() ) ),
                'get' => self::timing( (array) ( $redis['get'] ?? array() ) ),
                'delete' => self::timing( (array) ( $redis['delete'] ?? array() ) ),
                'server_counters_snapshot' => $counters,
                'keyspace_verification' => self::category( $redis['keyspace_verification'] ?? '', array( 'candidate_prefix_not_verified_against_dropin' ) ),
                'interpretation' => 'Direct Redis command timings are synthetic short-key tests, not cumulative real-request Redis latency.',
            ),
            'autoload' => array(
                'total_bytes' => self::nonnegative( $autoload['bytes'] ?? null ),
                'option_count' => self::nonnegative( $autoload['count'] ?? null ),
                'largest_15' => $options,
                'note' => 'Option values are excluded. Names may be masked if they resemble credentials.',
            ),
        );
    }

    /** A verified 3-5 request sample, without the original URL or query parameters. */
    private static function profile( array $profile ) {
        $meta = (array) ( $profile['probe_meta'] ?? array() );
        $raw_runs = (array) ( $profile['runs'] ?? array() );
        $runs = array();
        $php_values = array();
        $sql_values = array();
        foreach ( array_slice( $raw_runs, 0, 5 ) as $index => $run ) {
            if ( ! is_array( $run ) ) { continue; }
            $cache = (array) ( $run['cache'] ?? array() );
            $phases = array();
            foreach ( array_slice( (array) ( $cache['phases'] ?? array() ), 0, 8 ) as $phase ) {
                if ( ! is_array( $phase ) ) { continue; }
                $phases[] = array(
                    'from' => self::phase_name( $phase['from'] ?? '' ),
                    'to' => self::phase_name( $phase['to'] ?? '' ),
                    'hits' => self::nonnegative( $phase['hits'] ?? null ),
                    'misses' => self::nonnegative( $phase['misses'] ?? null ),
                    'lookups' => self::nonnegative( $phase['lookups'] ?? null ),
                );
            }
            $components = array();
            foreach ( array_slice( (array) ( $run['sql_components'] ?? array() ), 0, 8 ) as $component ) {
                if ( ! is_array( $component ) ) { continue; }
                $components[] = array(
                    'component' => self::safe_component( $component['component'] ?? '' ),
                    'sql_ms' => self::decimal( $component['sql_ms'] ?? null ),
                    'queries' => self::nonnegative( $component['queries'] ?? null ),
                    'patterns' => self::nonnegative( $component['patterns'] ?? null ),
                );
            }
            $http_calls = array();
            foreach ( array_slice( (array) ( $run['http_requests'] ?? array() ), 0, 20 ) as $http ) {
                if ( ! is_array( $http ) ) { continue; }
                $http_calls[] = array(
                    'component' => self::safe_component( $http['component'] ?? '' ),
                    'target_type' => self::category( $http['target_type'] ?? '', array( 'external', 'same-site', 'unknown' ) ),
                    'method' => self::category( $http['method'] ?? '', array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OTHER' ) ),
                    'blocking' => ! empty( $http['blocking'] ),
                    'duration_ms' => self::decimal( $http['duration_ms'] ?? null ),
                    'status' => is_numeric( $http['status'] ?? null ) ? (int) $http['status'] : null,
                    'failed' => ! empty( $http['failed'] ),
                    'target_group_id' => preg_match( '/^[a-f0-9]{16}$/D', (string) ( $http['target_group_id'] ?? '' ) ) ? $http['target_group_id'] : null,
                );
            }
            $patterns = array();
            foreach ( array_slice( (array) ( $run['sql_patterns'] ?? array() ), 0, 20 ) as $p ) {
                if ( ! is_array( $p ) || ! preg_match( '/^[a-f0-9]{12}$/D', (string) ( $p['pattern_id'] ?? '' ) ) ) { continue; }
                $patterns[] = array(
                    'pattern_id' => $p['pattern_id'],
                    'component' => self::safe_component( $p['component'] ?? '' ),
                    'count' => self::nonnegative( $p['count'] ?? null ),
                    'total_ms' => self::decimal( $p['total_ms'] ?? null ),
                    'max_ms' => self::decimal( $p['max_ms'] ?? null ),
                );
            }
            $phase_timings = array();
            foreach ( array_slice( (array) ( $run['phase_timings'] ?? array() ), 0, 12 ) as $phase ) {
                if ( ! is_array( $phase ) || ! preg_match( '/^[a-z_]{2,40}_to_[a-z_]{2,40}$/D', (string) ( $phase['interval'] ?? '' ) ) ) { continue; }
                $phase_timings[] = array( 'interval' => $phase['interval'], 'duration_ms' => self::decimal( $phase['duration_ms'] ?? null ) );
            }
            $php_ms = self::decimal( $run['php_ms'] ?? null );
            $sql_ms = ! empty( $run['sql_timing_available'] ) ? self::decimal( $run['db_ms'] ?? null ) : null;
            if ( null !== $php_ms ) { $php_values[] = $php_ms; }
            if ( null !== $sql_ms ) { $sql_values[] = $sql_ms; }
            $runs[] = array(
                'run_number' => count( $runs ) + 1,
                'php_ms' => $php_ms,
                'sql_ms' => $sql_ms,
                'sql_timing_available' => ! empty( $run['sql_timing_available'] ),
                'http_ms' => self::decimal( $run['http_ms'] ?? null ),
                'sql_query_count' => self::nonnegative( $run['queries'] ?? null ),
                'cache' => array(
                    'counters_available' => ! empty( $cache['available'] ),
                    'scope' => self::category( $cache['scope'] ?? '', array( 'mu-bootstrap-to-profiler-shutdown', 'counters-unavailable-or-late' ) ),
                    'hits' => self::nonnegative( $cache['hits'] ?? null ),
                    'misses' => self::nonnegative( $cache['misses'] ?? null ),
                    'get_lookups' => self::nonnegative( $cache['lookups'] ?? null ),
                    'hit_rate_percent' => self::decimal( $cache['hit_rate_percent'] ?? null ),
                    'phases' => $phases,
                ),
                'top_sql_components' => $components,
                'outbound_http_requests' => $http_calls,
                'http_callsite_inspector' => PFC_HTTP_Inspector::export_safe( (array) ( $run['http_callsites'] ?? array() ) ),
                'http_callsites_truncated' => ! empty( $run['http_truncated'] ),
                'repeated_sql_patterns' => $patterns,
                'sql_callsite_inspector' => PFC_SQL_Inspector::export_safe( (array) ( $run['sql_callsites'] ?? array() ) ),
                'sql_callsite_traces_truncated' => ! empty( $run['sql_callsites_truncated'] ),
                'php_phase_timings' => $phase_timings,
                'php_callback_profile' => self::callback_profile( (array) ( $run['callback_profile'] ?? array() ) ),
                'bricks_query_render_profile' => self::bricks_profile( (array) ( $run['bricks_profile'] ?? array() ) ),
                'cache_attribution_capability' => self::cache_capability( (array) ( $run['cache_attribution_capability'] ?? array() ) ),
            );
        }
        $raw_url = (string) ( $profile['url'] ?? '' );
        $path = (string) parse_url( $raw_url, PHP_URL_PATH );
        $segments = trim( $path, '/' );
        $depth = '' === $segments ? 0 : min( 100, count( array_filter( explode( '/', $segments ), 'strlen' ) ) );
        return array(
            'captured_at_utc' => isset( $profile['at'] ) && is_numeric( $profile['at'] ) ? gmdate( 'c', (int) $profile['at'] ) : null,
            'route' => array( 'kind' => 0 === $depth ? 'homepage' : 'public_route', 'path_depth' => $depth, 'full_url_exported' => false ),
            'verification' => array(
                'requested' => self::nonnegative( $meta['requested'] ?? null ),
                'verified' => self::nonnegative( $meta['verified'] ?? null ),
                'http_errors' => self::nonnegative( $meta['http_errors'] ?? null ),
                'bad_status' => self::nonnegative( $meta['bad_status'] ?? null ),
                'redirects' => self::nonnegative( $meta['redirects'] ?? null ),
                'probe_mismatch' => self::nonnegative( $meta['probe_mismatch'] ?? null ),
                'server_missing' => self::nonnegative( $meta['server_missing'] ?? null ),
                'cache_bypassed' => isset( $meta['cache_bypassed'] ) ? (bool) $meta['cache_bypassed'] : null,
            ),
            'medians' => array(
                'php_ms' => self::median( $php_values ),
                'sql_ms' => self::median( $sql_values ),
            ),
            'verified_runs' => $runs,
            'first_vs_following_sql' => PFC_Workload_Advisor::first_vs_following_sql( $raw_runs ),
            'workload_findings' => self::safe_findings( (array) ( $profile['findings'] ?? array() ) ),
            'notes' => array(
                'Signed deep profiles add diagnostic overhead and should not be treated as uninstrumented production timings.',
                'Callback and Bricks query/render profiling are separately opt-in. Instrumented elapsed times may overlap SQL and parent callbacks, and bookkeeping is only a lower bound on overhead.',
                'Cache hit/miss lookups are WordPress API counters, not Redis commands or measured Redis time.',
                'Request-phase intervals may include code from multiple plugins and the theme; they do not assign cache calls to a specific component.',
                'Top SQL components are query attribution only, not object-cache attribution.',
                'Callsite file names are component-relative source paths and function identifiers only, not absolute filesystem paths or query contents.',
                'SQL call-site timings measure SQL execution only and not exclusive PHP function time; missing/truncated traces are incomplete attribution.',
                'Outbound HTTP entries show component, safe call site and target-group HMAC, never host, path, URL or errors.',
                'Repeated requests to the same target group are NOT proof of retries: URL query parameters are excluded and response type is not unique.',
                'First-vs-following SQL evidence is observational; page-cache bypass, server load and database cache state can differ.',
                'Recommendations are rule-based and not proof of a unique root cause.',
            ),
        );
    }

    /** Export only explicitly allowlisted metadata; never query text, IDs or content. */
    private static function bricks_profile( array $profile ) {
        if ( empty( $profile['enabled'] ) ) { return array( 'enabled' => false, 'reason' => 'not_requested' ); }
        $query_rows = array();
        foreach ( array_slice( (array) ( $profile['query_groups'] ?? array() ), 0, 20 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $group = (string) ( $row['shape_group'] ?? '' );
            if ( ! preg_match( '/^[a-f0-9]{16}$/D', $group ) ) { continue; }
            $origin = (array) ( $row['origin'] ?? array() );
            $file = (string) ( $origin['source'] ?? '' );
            if ( ! preg_match( '~^themes/bricks/[a-zA-Z0-9_./-]{1,150}$~D', $file ) ) { $file = ''; }
            $function = (string) ( $origin['function'] ?? '' );
            if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]{0,69}$/D', $function ) ) { $function = 'unknown'; }
            $shape = (array) ( $row['shape'] ?? array() );
            $post_types = array();
            foreach ( array_slice( (array) ( $shape['post_types'] ?? array() ), 0, 5 ) as $type ) {
                if ( is_string( $type ) && preg_match( '/^[a-zA-Z0-9_-]{1,35}$/D', $type ) ) { $post_types[] = $type; }
            }
            $element = (string) ( $row['element_group'] ?? '' );
            $query_rows[] = array(
                'query_shape_group' => $group,
                'element_group' => preg_match( '/^[a-f0-9]{16}$/D', $element ) ? $element : null,
                'source_file' => $file,
                'source_function' => $function,
                'post_types' => $post_types,
                'posts_per_page' => is_numeric( $shape['posts_per_page'] ?? null ) ? max( -1, min( 1000, (int) $shape['posts_per_page'] ) ) : null,
                'meta_clauses' => self::nonnegative( $shape['meta_clauses'] ?? null ),
                'tax_clauses' => self::nonnegative( $shape['tax_clauses'] ?? null ),
                'has_search' => ! empty( $shape['has_search'] ),
                'has_post_in' => ! empty( $shape['has_post_in'] ),
                'main_query' => ! empty( $shape['main_query'] ),
                'parent_scope' => self::category( $row['parent_scope'] ?? '', array( 'assets', 'elements', 'other-bricks' ) ),
                'completion_method' => self::category( $row['completion_method'] ?? '', array( 'the_posts', 'bricks_result' ) ),
                'calls' => self::nonnegative( $row['calls'] ?? null ),
                'elapsed_ms' => self::decimal( $row['elapsed_ms'] ?? null ),
                'max_ms' => self::decimal( $row['max_ms'] ?? null ),
                'sql_ms' => self::decimal( $row['sql_ms'] ?? null ),
                'sql_queries' => self::nonnegative( $row['sql_queries'] ?? null ),
                'cache_get_lookups_during_scope' => self::nonnegative( $row['cache_lookups'] ?? null ),
                'result_count' => self::nonnegative( $row['result_count'] ?? null ),
            );
        }
        $render_rows = array();
        foreach ( array_slice( (array) ( $profile['render_groups'] ?? array() ), 0, 20 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $hook = self::category( $row['hook'] ?? '', PFC_Bricks_Inspector::render_hooks() );
            if ( ! $hook ) { continue; }
            $type = (string) ( $row['element_type'] ?? '' );
            $element = (string) ( $row['element_group'] ?? '' );
            $render_rows[] = array(
                'hook' => $hook,
                'element_type' => preg_match( '/^[a-zA-Z0-9_-]{1,35}$/D', $type ) ? $type : 'unknown',
                'element_group' => preg_match( '/^[a-f0-9]{16}$/D', $element ) ? $element : null,
                'calls' => self::nonnegative( $row['calls'] ?? null ),
                'elapsed_ms' => self::decimal( $row['elapsed_ms'] ?? null ),
                'max_ms' => self::decimal( $row['max_ms'] ?? null ),
                'cache_get_lookups_during_scope' => self::nonnegative( $row['cache_lookups'] ?? null ),
            );
        }
        $hook_fires = array();
        foreach ( PFC_Bricks_Inspector::render_hooks() as $hook ) {
            $hook_fires[ $hook ] = self::nonnegative( ( (array) ( $profile['render_hook_fires'] ?? array() ) )[ $hook ] ?? 0 );
        }
        $completion = (array) ( $profile['query_completion'] ?? array() );
        $completion_out = array();
        foreach ( array( 'the_posts', 'bricks_result_fallback', 'bricks_result_object_matched', 'bricks_result_object_unmatched', 'no_completion_hook', 'reused_object_id_gaps' ) as $key ) {
            $completion_out[ $key ] = self::nonnegative( $completion[ $key ] ?? null );
        }
        $phases = array();
        foreach ( array_slice( (array) ( $profile['parent_callback_query_scopes'] ?? array() ), 0, 3 ) as $phase ) {
            if ( ! is_array( $phase ) ) { continue; }
            $scope = self::category( $phase['scope'] ?? '', array( 'assets', 'elements', 'other-bricks' ) );
            if ( ! $scope ) { continue; }
            $phases[] = array(
                'scope' => $scope,
                'query_calls' => self::nonnegative( $phase['query_calls'] ?? null ),
                'observed_query_elapsed_ms' => self::decimal( $phase['observed_query_elapsed_ms'] ?? null ),
                'observed_sql_ms' => self::decimal( $phase['observed_sql_ms'] ?? null ),
                'cache_get_lookups_during_scope' => self::nonnegative( $phase['cache_lookups'] ?? null ),
            );
        }
        return array(
            'enabled' => true,
            'scope' => 'signed-opt-in-queries-and-observed-Bricks-hooks',
            'observed_bricks_queries' => self::nonnegative( $profile['observed_bricks_queries'] ?? null ),
            'query_groups' => $query_rows,
            'query_rows_omitted' => self::nonnegative( $profile['query_rows_omitted'] ?? null ),
            'query_samples_dropped' => self::nonnegative( $profile['query_samples_dropped'] ?? null ),
            'unfinished_queries' => self::nonnegative( $profile['unfinished_queries'] ?? null ),
            'query_completion' => $completion_out,
            'exact_element_attributions' => self::nonnegative( $profile['exact_element_attributions'] ?? null ),
            'observed_bricks_element_hint_count' => count( (array) ( $profile['observed_bricks_element_ids_local'] ?? array() ) ),
            'parent_callback_query_scopes' => $phases,
            'initialization_breakdown' => self::bricks_initialization_export( (array) ( $profile['initialization_breakdown'] ?? array() ) ),
            'initialization_accessor_profile' => self::bricks_accessor_export( (array) ( $profile['initialization_accessor_profile'] ?? array() ) ),
            'unclosed_query_evidence' => self::bricks_unclosed_export( (array) ( $profile['unclosed_query_evidence'] ?? array() ) ),
            'render_samples_aggregated' => self::nonnegative( $profile['render_samples_aggregated'] ?? null ),
            'render_completed_scopes' => self::nonnegative( $profile['render_completed_scopes'] ?? null ),
            'render_hook_fires' => $hook_fires,
            'render_groups' => $render_rows,
            'render_rows_omitted' => self::nonnegative( $profile['render_rows_omitted'] ?? null ),
            'render_samples_dropped' => self::nonnegative( $profile['render_samples_dropped'] ?? null ),
            'bookkeeping_lower_bound_ms' => self::decimal( $profile['bookkeeping_lower_bound_ms'] ?? null ),
            'notes' => 'Initialization accessor samples are bounded WordPress metadata and option filters, not Redis commands, and may overlap child queries. Exact query element attribution requires the same WP_Query object in a Bricks result filter. Raw short element IDs remain only in the administrator-local diagnostic, never AI export. Bricks-result fallback elapsed is an upper bound. No completion hook is a coverage gap, not a query failure. Hook timing and query timing may overlap. Query shapes and unclosed-query groups are anonymized; no SQL, post contents or cache values are exported. Callback minus child-query timing is only a diagnostic comparison.',
        );
    }

    /** Allowlisted evidence only: no raw element IDs, query variables or SQL. */
    private static function bricks_unclosed_export( array $raw ) {
        $categories = array();
        $allowed = array( 'filters_suppressed_candidate', 'object_released',
            'results_present_without_hook', 'no_result_state_observed', 'reused_object_id' );
        foreach ( $allowed as $key ) { $categories[ $key ] = self::nonnegative( ( (array) ( $raw['categories'] ?? array() ) )[ $key ] ?? 0 ); }
        $groups = array();
        foreach ( array_slice( (array) ( $raw['groups'] ?? array() ), 0, 20 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $scope = self::category( $row['parent_scope'] ?? '', array( 'assets', 'elements', 'other-bricks' ) );
            $category = self::category( $row['category'] ?? '', $allowed );
            $hash = (string) ( $row['shape_group'] ?? '' );
            if ( ! $scope || ! $category || ! preg_match( '/^[a-f0-9]{16}$/D', $hash ) ) { continue; }
            $shape = (array) ( $row['shape'] ?? array() );
            $types = array();
            foreach ( array_slice( (array) ( $shape['post_types'] ?? array() ), 0, 5 ) as $type ) {
                if ( is_string( $type ) && preg_match( '/^[a-zA-Z0-9_-]{1,35}$/D', $type ) ) { $types[] = $type; }
            }
            $groups[] = array( 'parent_scope' => $scope, 'category' => $category,
                'query_shape_group' => $hash, 'post_types' => $types,
                'posts_per_page' => is_numeric( $shape['posts_per_page'] ?? null ) ? max( -1, min( 1000, (int) $shape['posts_per_page'] ) ) : null,
                'calls' => self::nonnegative( $row['calls'] ?? null ),
                    'repeat_object_hits_sampled' => self::nonnegative( $row['repeat_object_hits_sampled'] ?? null ),
                    'object_sample_limit_reached' => ! empty( $row['distinct_object_limit_reached'] ) );
        }
        return array( 'categories' => $categories, 'groups' => $groups,
            'groups_omitted' => self::nonnegative( $raw['groups_omitted'] ?? 0 ) );
    }

    /** No raw option/meta names, IDs, values, paths or arbitrary labels. */
    private static function bricks_accessor_export( array $raw ) {
        if ( empty( $raw['enabled'] ) ) { return array( 'enabled' => false ); }
        $allowed_kinds = array( 'post_metadata', 'term_metadata', 'user_metadata', 'options' );
        $scopes = array();
        foreach ( array_slice( (array) ( $raw['scopes'] ?? array() ), 0, 2 ) as $scope ) {
            if ( ! is_array( $scope ) ) { continue; }
            $name = self::category( $scope['scope'] ?? '', array( 'assets', 'elements' ) );
            if ( ! $name ) { continue; }
            $groups = array();
            foreach ( array_slice( (array) ( $scope['top_accessor_groups'] ?? array() ), 0, 12 ) as $row ) {
                if ( ! is_array( $row ) ) { continue; }
                $kind = self::category( $row['kind'] ?? '', $allowed_kinds );
                $group = (string) ( $row['key_group'] ?? '' );
                if ( ! $kind || ! preg_match( '/^[a-f0-9]{16}$/D', $group ) ) { continue; }
                $groups[] = array( 'kind' => $kind, 'key_group' => $group,
                    'calls' => self::nonnegative( $row['calls'] ?? null ) );
            }
            $safe = array( 'scope' => $name );
            foreach ( array( 'post_metadata_calls', 'term_metadata_calls', 'user_metadata_calls',
                'option_calls', 'hashed_accessor_groups_observed', 'other_or_untracked_keys',
                'samples_skipped_at_limit' ) as $key ) {
                $safe[ $key ] = self::nonnegative( $scope[ $key ] ?? null );
            }
            $safe['top_accessor_groups'] = $groups;
            $scopes[] = $safe;
        }
        return array( 'enabled' => true, 'scope' => 'bounded-metadata-and-option-filter-observations',
            'event_limit' => self::nonnegative( $raw['event_limit'] ?? null ),
            'observed_events' => self::nonnegative( $raw['observed_events'] ?? null ),
            'events_skipped_at_limit' => self::nonnegative( $raw['events_skipped_at_limit'] ?? null ),
            'scopes' => $scopes,
            'notes' => 'Read-only scoped accessor filter counts only. Metadata/option values, object IDs and keys are excluded. These counts are neither all cache API operations nor measured Redis commands.' );
    }

    private static function paired_comparison_export( array $raw ) {
        $out = array(
            'requested_pairs' => self::nonnegative( $raw['requested_pairs'] ?? null ),
            'valid_pairs' => self::nonnegative( $raw['valid_pairs'] ?? null ),
            'incomplete_pairs' => self::nonnegative( $raw['incomplete_pairs'] ?? null ),
            'comparable' => ! empty( $raw['comparable'] ),
            'standard_median_php_ms' => self::decimal( $raw['standard_median_php_ms'] ?? null ),
            'deep_median_php_ms' => self::decimal( $raw['deep_median_php_ms'] ?? null ),
            'paired_median_deep_minus_standard_ms' => self::signed_decimal( $raw['paired_median_deep_minus_standard_ms'] ?? null ),
        );
        $out['paired_deltas_ms'] = array();
        foreach ( array_slice( (array) ( $raw['paired_deltas_ms'] ?? array() ), 0, 5 ) as $v ) {
            $delta = self::signed_decimal( $v );
            if ( null !== $delta ) { $out['paired_deltas_ms'][] = $delta; }
        }
        $out['notes'] = 'Alternating-order signed comparison, only small observational sample. A cold first request, changing content and different load may bias results; not a guaranteed profiler overhead correction.';
        return $out;
    }

    private static function signed_decimal( $value ) {
        return is_numeric( $value ) && is_finite( (float) $value ) ? round( max( -1000000, min( 1000000, (float) $value ) ), 3 ) : null;
    }

    private static function bricks_initialization_export( array $rows ) {
        $out = array();
        foreach ( array_slice( $rows, 0, 2 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $scope = self::category( $row['scope'] ?? '', array( 'assets', 'elements' ) );
            if ( ! $scope ) { continue; }
            $out[] = array( 'scope' => $scope,
                'callback_inclusive_ms' => self::decimal( $row['callback_inclusive_ms'] ?? null ),
                'observed_child_query_ms' => self::decimal( $row['observed_child_query_ms'] ?? null ),
                'observed_child_sql_ms' => self::decimal( $row['observed_child_sql_ms'] ?? null ),
                'child_queries' => self::nonnegative( $row['child_queries'] ?? null ),
                'callback_minus_observed_queries_ms' => self::decimal( $row['callback_minus_observed_queries_ms'] ?? null ),
                'cache_get_lookups_during_callback' => self::nonnegative( $row['cache_get_lookups_during_callback'] ?? null ),
                'cache_get_lookups_during_child_queries' => self::nonnegative( $row['cache_get_lookups_during_child_queries'] ?? null ) );
        }
        return $out;
    }

    private static function cache_capability( array $capability ) {
        return array(
            'mode' => self::category( $capability['mode'] ?? '', array( 'counter-deltas', 'no-public-counters' ) ),
            'per_operation_callsite_available' => false,
            'notes' => 'WordPress exposes no generic cache API call-site interception. Callback-scope counter deltas do not identify Redis calls or network time.',
        );
    }

    /** Explicit allowlist for signed-only callback profiles. No full paths or arguments. */
    private static function callback_profile( array $profile ) {
        if ( empty( $profile['enabled'] ) ) { return array( 'enabled' => false, 'reason' => 'not_requested' ); }
        $rows = array();
        foreach ( array_slice( (array) ( $profile['rows'] ?? array() ), 0, 30 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $hook = (string) ( $row['hook'] ?? '' );
            $file = (string) ( $row['file'] ?? '' );
            $label = (string) ( $row['callback'] ?? '' );
            if ( ! in_array( $hook, PFC_Callback_Profiler::hooks(), true ) ) { continue; }
            if ( '' !== $file && ! preg_match( '~^(plugins|themes|mu-plugins)/[a-zA-Z0-9_./-]{1,200}$~D', $file ) ) { $file = ''; }
            if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_\\:]{0,149}$/D', $label ) ) { $label = 'redacted'; }
            $rows[] = array(
                'hook' => $hook,
                'component' => self::safe_component( $row['component'] ?? '' ),
                'function' => $label,
                'file' => $file,
                'line' => self::nonnegative( $row['line'] ?? null ),
                'calls' => self::nonnegative( $row['calls'] ?? null ),
                'self_php_ms' => self::decimal( $row['self_ms'] ?? null ),
                'inclusive_php_ms' => self::decimal( $row['inclusive_ms'] ?? null ),
                'cache_get_lookups_during_scope' => self::nonnegative( $row['cache_get_lookups'] ?? null ),
            );
        }
        return array(
            'enabled' => true,
            'scope' => 'allowlisted-callbacks-only',
            'callbacks_wrapped' => self::nonnegative( $profile['callbacks_wrapped'] ?? null ),
            'reference_callbacks_skipped' => self::nonnegative( $profile['reference_callbacks_skipped'] ?? null ),
            'callbacks_skipped_limit' => self::nonnegative( $profile['callbacks_skipped_limit'] ?? null ),
            'bookkeeping_lower_bound_ms' => self::decimal( $profile['bookkeeping_lower_bound_ms'] ?? null ),
            'rows' => $rows,
            'truncated' => ! empty( $profile['truncated'] ),
            'note' => 'Times are instrumented measurements only for allowlisted callbacks. Nested callback times may overlap. Cache lookups are counter changes within callback scopes, not Redis network operations or latency. Bookkeeping is only a lower bound on overhead.',
        );
    }

    /** Rebuild rule-based findings from a strict allowlist to avoid arbitrary text. */
    private static function safe_findings( array $items ) {
        $out = array();
        foreach ( array_slice( $items, 0, 10 ) as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $id = (string) ( $item['id'] ?? '' );
            $action = self::category( $item['action'] ?? '', array( 'http', 'cache', 'sql', 'transients', 'autoload' ) );
            if ( ! preg_match( '/^[a-z0-9:_-]{1,100}$/D', $id ) || ! $action ) { continue; }
            $out[] = array(
                'id' => $id,
                'severity' => self::category( $item['severity'] ?? '', array( 'critical', 'high', 'warning', 'info' ) ),
                'action' => $action,
                'repair_mode' => self::category( $item['repair'] ?? '', array( 'guided', 'bounded_cleanup' ) ),
            );
        }
        return $out;
    }

    /** A narrow Site Scan summary, never the full saved report or raw findings. */
    private static function scan( array $scan ) {
        $opt = (array) ( $scan['database_health']['options'] ?? array() );
        return array(
            'captured_at_utc' => self::utc_string( $scan['generated_at'] ?? '' ),
            'mode' => self::category( $scan['mode'] ?? '', array( 'production-safe', 'deep' ) ),
            'database_bytes' => self::nonnegative( $scan['database']['total_size'] ?? null ),
            'autoload_bytes' => self::nonnegative( $scan['autoload']['total_bytes'] ?? ( $opt['autoload_bytes'] ?? null ) ),
            'autoload_option_count' => self::nonnegative( $opt['autoload_count'] ?? null ),
            'active_plugins' => self::nonnegative( $scan['system']['plugins']['active_count'] ?? null ),
            'cron_overdue' => self::nonnegative( $scan['cron']['overdue'] ?? null ),
            'persistent_object_cache' => isset( $scan['cache']['persistent'] ) ? (bool) $scan['cache']['persistent'] : null,
            'database_integrity_problem_count' => count( (array) ( $scan['database_health']['integrity']['problems'] ?? array() ) ),
            'note' => 'Summary only. Query text, logs, plugin configuration, site addresses, and database contents are excluded.',
        );
    }

    private static function timing( array $data ) {
        return array(
            'n' => self::nonnegative( $data['n'] ?? null ),
            'median_ms' => self::decimal( $data['median_ms'] ?? null ),
            'p95_ms' => self::decimal( $data['p95_ms'] ?? null ),
            'max_ms' => self::decimal( $data['max_ms'] ?? null ),
        );
    }

    private static function nonnegative( $value ) {
        return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 ? (int) $value : null;
    }

    private static function decimal( $value ) {
        return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 ? round( (float) $value, 3 ) : null;
    }

    private static function median( array $values ) {
        if ( ! $values ) { return null; }
        sort( $values, SORT_NUMERIC );
        $middle = (int) floor( count( $values ) / 2 );
        return round( count( $values ) % 2 ? $values[ $middle ] : ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2, 3 );
    }

    private static function category( $value, array $allowlist ) {
        return is_string( $value ) && in_array( $value, $allowlist, true ) ? $value : null;
    }

    private static function phase_name( $value ) {
        return self::category( $value, array( 'MU bootstrap / early PHP', 'profiler start', 'plugins_loaded', 'init', 'wp_loaded', 'wp', 'template_redirect', 'wp_head', 'wp_footer', 'profiler shutdown' ) );
    }

    private static function safe_option_name( $value ) {
        $value = (string) $value;
        if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,100}$/D', $value ) ||
            preg_match( '/(?:password|secret|api[_-]?key|auth[_-]?token|access[_-]?token|private[_-]?key|jwt|nonce|session|sk[_-]live)/i', $value ) ||
            preg_match( '/[a-f0-9]{32,}/i', $value ) ) {
            return '[masked-option-name]';
        }
        return $value;
    }

    private static function safe_component( $value ) {
        $value = (string) $value;
        return preg_match( '/^(core|plugin|theme|mu-plugin):[a-z0-9_-]{1,80}$/D', $value ) ? $value : '[masked-component]';
    }

    private static function utc_string( $value ) {
        $value = (string) $value;
        // Date labels are expected from this plugin and never copied raw if malformed.
        return preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?: UTC)?)?$/D', $value ) ? $value : null;
    }
}
