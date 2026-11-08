<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bricks query lifecycle inspector, activated only by signed deep callback probes.
 *
 * Reads official Bricks filter arguments and WP_Query public counters without
 * changing query variables, query results, rendered HTML or existing filters.
 * Identifiers are HMACed in AI exports; short Bricks element IDs are retained
 * only in the administrator-local diagnostic payload for locating the element.
 */
final class PFC_Bricks_Inspector {
    const MAX_QUERIES = 120;
    const MAX_RENDER_INVOCATIONS = 700;
    const MAX_RENDER_GROUPS = 90;
    const MAX_EXPORT_ROWS = 35;
    const MAX_ELEMENT_HINTS = 80;
    const MAX_ACCESS_EVENTS = 12000;
    const MAX_ACCESS_GROUPS = 60;
    const MAX_ACCESS_EXPORT_GROUPS = 12;
    const MAX_ACCESS_OBJECTS_PER_GROUP = 128;

    private static $enabled = false;
    private static $started = array();
    private static $samples = array();
    private static $sample_by_object_id = array();
    private static $query_count = 0;
    private static $reused_query_object_ids = 0;
    private static $query_dropped = 0;
    private static $the_posts_completed = 0;
    private static $result_hook_completed = 0;
    private static $result_matched = 0;
    private static $result_unmatched = 0;
    private static $element_hints = array();
    private static $render_stack = array();
    private static $render_rows = array();
    private static $render_count = 0;
    private static $render_dropped = 0;
    private static $render_aggregated = 0;
    private static $fired = array();
    private static $overhead_ms = 0.0;
    private static $access_scope_stack = array();
    private static $access_scopes = array();
    private static $access_events = 0;
    private static $access_events_skipped = 0;
    private static $access_group_count = 0;

    public static function render_hooks() {
        return array( 'bricks/frontend/render_element', 'bricks/element/render', 'bricks/frontend/render_data' );
    }

    public static function init() {
        if ( self::$enabled || ! defined( 'PFC_DEEP_DIAGNOSTIC' ) || ! PFC_DEEP_DIAGNOSTIC ||
             ! defined( 'PFC_PHP_CALLBACK_DIAGNOSTIC' ) || ! PFC_PHP_CALLBACK_DIAGNOSTIC ||
             ( defined( 'PFC_SAVE_DIAGNOSTIC' ) && PFC_SAVE_DIAGNOSTIC ) ) { return; }
        self::$enabled = true;
        add_action( 'pre_get_posts', array( __CLASS__, 'query_start' ), PHP_INT_MIN + 1, 1 );
        add_filter( 'the_posts', array( __CLASS__, 'query_end' ), PHP_INT_MAX - 1, 2 );
        // Official Bricks hooks: capture an exact WP_Query object/element match.
        add_filter( 'bricks/query/result', array( __CLASS__, 'bricks_result' ), PHP_INT_MAX - 1, 2 );
        add_filter( 'bricks/posts/query_vars', array( __CLASS__, 'bricks_query_vars' ), PHP_INT_MAX - 1, 4 );
        // WordPress metadata and option accessor filters are read-only probes.
        // Their first argument must always be returned unchanged. Nothing about
        // the object ID, option value, metadata value or query is retained.
        add_filter( 'get_post_metadata', array( __CLASS__, 'observe_post_metadata' ), 10, 5 );
        add_filter( 'get_term_metadata', array( __CLASS__, 'observe_term_metadata' ), 10, 5 );
        add_filter( 'get_user_metadata', array( __CLASS__, 'observe_user_metadata' ), 10, 5 );
        add_filter( 'pre_option', array( __CLASS__, 'observe_option' ), 10, 3 );
        foreach ( self::render_hooks() as $hook ) {
            add_filter( $hook, array( __CLASS__, 'render_start' ), PHP_INT_MIN + 1, 2 );
            add_filter( $hook, array( __CLASS__, 'render_end' ), PHP_INT_MAX - 1, 2 );
        }
    }

    private static function hmac( $value ) {
        $salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'not-exported' );
        return substr( hash_hmac( 'sha256', (string) $value, (string) $salt ), 0, 16 );
    }

    private static function safe_scalar( $value, $max = 35 ) {
        if ( ! is_scalar( $value ) || is_bool( $value ) ) { return ''; }
        $value = (string) $value;
        return strlen( $value ) <= $max && 1 === preg_match( '/^[a-zA-Z0-9_-]+$/D', $value ) ? $value : '';
    }

    private static function local_element_id( $value ) {
        $value = self::safe_scalar( $value, 20 );
        if ( 0 === strpos( $value, 'brxe-' ) ) { $value = substr( $value, 5 ); }
        return strlen( $value ) >= 4 && strlen( $value ) <= 16 ? $value : '';
    }

    public static function bricks_query_vars( $vars, $settings = null, $element_id = null, $element_name = null ) {
        if ( self::$enabled && count( self::$element_hints ) < self::MAX_ELEMENT_HINTS ) {
            $id = self::local_element_id( $element_id );
            if ( $id ) { self::$element_hints[ $id ] = true; }
        }
        // No query variable or element setting is changed, including custom fields.
        return $vars;
    }

    /** Called by the bounded callback profiler for the two exact Bricks owners. */
    public static function enter_initialization_scope( $scope ) {
        if ( ! self::$enabled || ! in_array( $scope, array( 'assets', 'elements' ), true ) ) { return; }
        self::$access_scope_stack[] = $scope;
        if ( ! isset( self::$access_scopes[ $scope ] ) ) {
            self::$access_scopes[ $scope ] = array(
                'post_metadata' => 0, 'term_metadata' => 0, 'user_metadata' => 0,
                'options' => 0, 'groups' => array(), 'other_keys' => 0,
                'limit_skipped' => 0,
            );
        }
    }

    public static function exit_initialization_scope( $scope ) {
        if ( ! self::$enabled || ! self::$access_scope_stack ) { return; }
        // Keep the frame stack consistent in nested callback/exception paths.
        if ( end( self::$access_scope_stack ) === $scope ) { array_pop( self::$access_scope_stack ); }
    }

    private static function observe_access( $kind, $key, $object_id = null ) {
        if ( ! self::$enabled || ! self::$access_scope_stack ) { return; }
        $scope = end( self::$access_scope_stack );
        if ( ! isset( self::$access_scopes[ $scope ] ) ) { return; }
        if ( self::$access_events >= self::MAX_ACCESS_EVENTS ) {
            ++self::$access_events_skipped;
            ++self::$access_scopes[ $scope ]['limit_skipped'];
            return;
        }
        ++self::$access_events;
        ++self::$access_scopes[ $scope ][ $kind ];
        // Only a bounded set of distinct accessor keys gets a keyed HMAC.
        // Keys are not values; nonetheless names resembling secrets are masked.
        // Local labels only remain in a signed administrator-requested profile.
        if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_:-]{1,80}$/D', $key ) ) {
            ++self::$access_scopes[ $scope ]['other_keys'];
            return;
        }
        $token = $kind . ':' . $key;
        $fingerprint = self::hmac( $token );
        if ( ! isset( self::$access_scopes[ $scope ]['groups'][ $fingerprint ] ) && self::$access_group_count >= self::MAX_ACCESS_GROUPS ) {
            ++self::$access_scopes[ $scope ]['other_keys'];
            return;
        }
        if ( ! isset( self::$access_scopes[ $scope ]['groups'][ $fingerprint ] ) ) {
            $label = preg_match( '/pass|secret|token|cookie|nonce|session|auth|salt|credential|private|license|api[_-]?key|access[_-]?key|jwt|password/i', $key ) ? '[masked]' : $key;
            $callsite = self::accessor_callsite();
            self::$access_scopes[ $scope ]['groups'][ $fingerprint ] = array( 'kind' => $kind, 'key_group' => $fingerprint, 'calls' => 0,
                'key_local' => $label, 'callsite_local' => $callsite, 'distinct_object_hashes' => array(), 'distinct_object_limit_reached' => false, 'repeat_object_hits_sampled' => 0 );
            ++self::$access_group_count;
        }
        ++self::$access_scopes[ $scope ]['groups'][ $fingerprint ]['calls'];
        if ( ( 'post_metadata' === $kind || 'term_metadata' === $kind || 'user_metadata' === $kind ) && is_numeric( $object_id ) && (int)$object_id > 0 ) {
            $entry =& self::$access_scopes[ $scope ]['groups'][ $fingerprint ];
            $id = self::hmac( $kind . ':' . (int)$object_id );
            if ( isset( $entry['distinct_object_hashes'][ $id ] ) ) { ++$entry['repeat_object_hits_sampled']; }
            elseif ( count( $entry['distinct_object_hashes'] ) < self::MAX_ACCESS_OBJECTS_PER_GROUP ) { $entry['distinct_object_hashes'][ $id ] = true; }
            else { $entry['distinct_object_limit_reached'] = true; }
            unset( $entry );
        }
    }

    /** One bounded call-site snapshot per distinct key, never a full trace. */
    private static function accessor_callsite() {
        if ( ! function_exists( 'debug_backtrace' ) || ! class_exists( 'PFC_SQL_Inspector' ) ) { return 'unavailable'; }
        foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 13 ), 3 ) as $frame ) {
            if ( ! is_array( $frame ) || empty( $frame['file'] ) ) { continue; }
            $relative = PFC_SQL_Inspector::relative_source( $frame['file'] );
            if ( ! $relative || false !== strpos( $relative, 'performance-console/' ) ) { continue; }
            return $relative . ':' . max( 0, (int) ( $frame['line'] ?? 0 ) );
        }
        return 'unavailable';
    }

    public static function observe_post_metadata( $check, $object_id = null, $meta_key = '', $single = false, $meta_type = null ) {
        self::observe_access( 'post_metadata', $meta_key, $object_id );
        return $check;
    }
    public static function observe_term_metadata( $check, $object_id = null, $meta_key = '', $single = false, $meta_type = null ) {
        self::observe_access( 'term_metadata', $meta_key, $object_id );
        return $check;
    }
    public static function observe_user_metadata( $check, $object_id = null, $meta_key = '', $single = false, $meta_type = null ) {
        self::observe_access( 'user_metadata', $meta_key, $object_id );
        return $check;
    }
    public static function observe_option( $pre_option, $option = '', $default = false ) {
        self::observe_access( 'options', $option );
        return $pre_option;
    }

    /** Aggregated accessor evidence, not WP_Object_Cache or Redis command attribution. */
    private static function accessor_summary() {
        $out = array();
        foreach ( array( 'assets', 'elements' ) as $scope ) {
            if ( ! isset( self::$access_scopes[ $scope ] ) ) { continue; }
            $data = self::$access_scopes[ $scope ];
            $groups = array_values( $data['groups'] );
            foreach ( $groups as &$row ) {
                $row['distinct_objects_sampled'] = count( $row['distinct_object_hashes'] ?? array() );
                unset( $row['distinct_object_hashes'] );
            }
            unset( $row );
            usort( $groups, static function( $a, $b ) { return $b['calls'] <=> $a['calls']; } );
            $out[] = array(
                'scope' => $scope,
                'post_metadata_calls' => $data['post_metadata'],
                'term_metadata_calls' => $data['term_metadata'],
                'user_metadata_calls' => $data['user_metadata'],
                'option_calls' => $data['options'],
                'hashed_accessor_groups_observed' => count( $groups ),
                'other_or_untracked_keys' => $data['other_keys'],
                'samples_skipped_at_limit' => $data['limit_skipped'],
                'top_accessor_groups' => array_slice( $groups, 0, self::MAX_ACCESS_EXPORT_GROUPS ),
            );
        }
        return array( 'enabled' => self::$enabled, 'scope' => 'bounded-metadata-and-option-filter-observations',
            'event_limit' => self::MAX_ACCESS_EVENTS, 'observed_events' => self::$access_events,
            'events_skipped_at_limit' => self::$access_events_skipped,
            'scopes' => $out,
            'note' => 'These are WordPress metadata and option filter invocations while the selected Bricks callback executes. Repeated sampled key/object accesses indicate the same hook arguments recurring, not redundant Redis operations. Distinct objects are capped at 128 per group and repeated hits after the cap are incomplete. A call can overlap child queries; these are not all cache lookups or Redis commands.',
        );
    }

    private static function query_shape( $query ) {
        $v = isset( $query->query_vars ) && is_array( $query->query_vars ) ? $query->query_vars : array();
        $types = isset( $v['post_type'] ) ? (array) $v['post_type'] : array( 'post' );
        $safe_types = array();
        foreach ( array_slice( $types, 0, 8 ) as $type ) {
            $type = self::safe_scalar( $type );
            if ( '' !== $type ) { $safe_types[] = $type; }
        }
        sort( $safe_types );
        $limit = isset( $v['posts_per_page'] ) && is_numeric( $v['posts_per_page'] ) ? (int) $v['posts_per_page'] : null;
        return array(
            'post_types' => array_slice( $safe_types, 0, 5 ),
            'posts_per_page' => null === $limit ? null : max( -1, min( 1000, $limit ) ),
            'meta_clauses' => isset( $v['meta_query'] ) && is_array( $v['meta_query'] ) ? min( 100, count( $v['meta_query'] ) ) : 0,
            'tax_clauses' => isset( $v['tax_query'] ) && is_array( $v['tax_query'] ) ? min( 100, count( $v['tax_query'] ) ) : 0,
            'has_search' => ! empty( $v['s'] ),
            'has_post_in' => ! empty( $v['post__in'] ),
            'main_query' => is_callable( array( $query, 'is_main_query' ) ) ? (bool) $query->is_main_query() : false,
        );
    }

    private static function bricks_origin() {
        $frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 45 );
        $base = defined( 'WP_CONTENT_DIR' ) ? rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/' : '';
        $origin = null;
        $parent_scope = 'other-bricks';
        foreach ( $frames as $frame ) {
            $class = (string) ( $frame['class'] ?? '' );
            $file = str_replace( '\\', '/', (string) ( $frame['file'] ?? '' ) );
            $source = $base && 0 === strpos( $file, $base ) ? substr( $file, strlen( $base ) ) : '';
            if ( 'themes/bricks/includes/assets.php' === $source ) { $parent_scope = 'assets'; }
            elseif ( 'themes/bricks/includes/elements.php' === $source && 'assets' !== $parent_scope ) { $parent_scope = 'elements'; }
            if ( $origin || ( 0 !== strpos( $class, 'Bricks\\' ) && 0 !== strpos( $source, 'themes/bricks/' ) ) ) { continue; }
            $function = (string) ( $frame['function'] ?? '' );
            if ( ! self::safe_scalar( $function, 70 ) ) { $function = 'unknown'; }
            if ( ! preg_match( '~^themes/bricks/[a-zA-Z0-9_./-]{1,150}$~D', $source ) ) { $source = ''; }
            $origin = array( 'source' => $source, 'function' => $function );
        }
        return $origin ? array( 'origin' => $origin, 'parent_scope' => $parent_scope ) : null;
    }

    private static function make_object_ref( $query ) {
        // Do not keep potentially large WP_Query result sets alive until shutdown.
        return class_exists( 'WeakReference' ) ? WeakReference::create( $query ) : $query;
    }

    private static function matches_object_ref( $reference, $object ) {
        return $reference instanceof WeakReference ? $reference->get() === $object : $reference === $object;
    }

    private static function sql_snapshot() {
        global $wpdb;
        return isset( $wpdb->queries ) && is_array( $wpdb->queries ) ? count( $wpdb->queries ) : null;
    }

    public static function query_start( $query ) {
        if ( ! self::$enabled || ! is_object( $query ) ) { return; }
        $began = microtime( true );
        $trace = self::bricks_origin();
        if ( null === $trace ) { self::$overhead_ms += ( microtime( true ) - $began ) * 1000; return; }
        if ( self::$query_count >= self::MAX_QUERIES ) { ++self::$query_dropped; self::$overhead_ms += ( microtime( true ) - $began ) * 1000; return; }
        $id = spl_object_id( $query );
        if ( isset( self::$started[ $id ] ) ) {
            // WordPress may skip the_posts; after an orphaned WP_Query is freed,
            // PHP can reuse its object ID for a later query. Never attribute an
            // old start to the new object or silently discard that coverage gap.
            ++self::$reused_query_object_ids;
            unset( self::$started[ $id ] );
        }
        $shape = self::query_shape( $query );
        $v = isset( $query->query_vars ) && is_array( $query->query_vars ) ? $query->query_vars : array();
        $element_group = '';
        // These optional query-var hints are not trusted as actual Bricks IDs.
        // Keep their HMAC only. Raw local IDs require the official Bricks hook.
        foreach ( array( 'bricks_element_id', 'bricks_query_id', 'bricks_query_loop_id' ) as $candidate ) {
            if ( ! empty( $v[ $candidate ] ) ) {
                $marker = self::safe_scalar( $v[ $candidate ], 80 );
                if ( $marker ) { $element_group = self::hmac( $marker ); break; }
            }
        }
        self::$started[ $id ] = array( 'sequence' => self::$query_count + 1, 'object_ref' => self::make_object_ref( $query ), 'started' => microtime( true ),
            'sql_index' => self::sql_snapshot(), 'shape' => $shape,
            'shape_group' => self::hmac( wp_json_encode( $shape ) ),
            'element_group' => $element_group,
            'element_id_local' => '', 'origin' => $trace['origin'],
            'parent_scope' => $trace['parent_scope'], 'cache_start' => PFC_Request_Cache::snapshot(),
            'suppress_filters_at_start' => ! empty( $v['suppress_filters'] ) );
        ++self::$query_count;
        self::$overhead_ms += ( microtime( true ) - $began ) * 1000;
    }

    /** Close a query once, recording the completion hook; never change result content. */
    private static function finish_query( $id, $posts, $method ) {
        if ( ! isset( self::$started[ $id ] ) ) { return; }
        $at = microtime( true );
        $entry = self::$started[ $id ];
        unset( self::$started[ $id ] );
        $duration = max( 0, ( $at - $entry['started'] ) * 1000 );
        $to = self::sql_snapshot();
        $sql_ms = null;
        $sql_queries = null;
        if ( null !== $entry['sql_index'] && null !== $to && $to >= $entry['sql_index'] ) {
            $sql_queries = $to - $entry['sql_index'];
            global $wpdb;
            $sql_ms = 0.0;
            for ( $i = $entry['sql_index']; $i < $to && $i < $entry['sql_index'] + 250; $i++ ) {
                $sql_ms += (float) ( $wpdb->queries[ $i ][1] ?? 0 ) * 1000;
            }
        }
        $delta = PFC_Request_Cache::delta( (array) $entry['cache_start'], PFC_Request_Cache::snapshot() );
        $sequence = $entry['sequence'];
        self::$samples[ $sequence ] = array(
            'object_ref' => $entry['object_ref'], 'shape_group' => $entry['shape_group'], 'shape' => $entry['shape'],
            'element_group' => $entry['element_group'], 'element_id_local' => $entry['element_id_local'],
            'origin' => $entry['origin'], 'parent_scope' => $entry['parent_scope'],
            'elapsed_ms' => $duration, 'sql_ms' => $sql_ms, 'sql_queries' => $sql_queries,
            'cache_lookups' => $delta['lookups'], 'result_count' => is_array( $posts ) ? count( $posts ) : 0,
            'completion_method' => $method, 'matched_bricks_result' => false,
        );
        self::$sample_by_object_id[ $id ] = $sequence;
        if ( 'the_posts' === $method ) { ++self::$the_posts_completed; }
        else { ++self::$result_hook_completed; }
        self::$overhead_ms += ( microtime( true ) - $at ) * 1000;
    }

    public static function query_end( $posts, $query = null ) {
        if ( self::$enabled && is_object( $query ) ) {
            $id = spl_object_id( $query );
            if ( isset( self::$started[ $id ] ) && self::matches_object_ref( self::$started[ $id ]['object_ref'], $query ) ) {
                self::finish_query( $id, $posts, 'the_posts' );
            }
        }
        return $posts;
    }

    public static function bricks_result( $result, $bricks_query = null ) {
        if ( ! self::$enabled || ! is_object( $result ) || ! ( $result instanceof WP_Query ) ) { return $result; }
        $id = spl_object_id( $result );
        if ( isset( self::$started[ $id ] ) && self::matches_object_ref( self::$started[ $id ]['object_ref'], $result ) ) {
            // the_posts can be skipped by suppress_filters or a different WP_Query path.
            // The time to this Bricks result hook is an upper bound, not a the_posts timing.
            self::finish_query( $id, (array) ( $result->posts ?? array() ), 'bricks_result' );
        }
        $sequence = self::$sample_by_object_id[ $id ] ?? 0;
        if ( ! isset( self::$samples[ $sequence ] ) || ! self::matches_object_ref( self::$samples[ $sequence ]['object_ref'], $result ) ) {
            ++self::$result_unmatched;
            return $result;
        }
        if ( ! self::$samples[ $sequence ]['matched_bricks_result'] ) { ++self::$result_matched; }
        self::$samples[ $sequence ]['matched_bricks_result'] = true;
        $element_id = is_object( $bricks_query ) ? self::local_element_id( $bricks_query->element_id ?? null ) : '';
        if ( $element_id ) {
            self::$samples[ $sequence ]['element_group'] = self::hmac( $element_id );
            self::$samples[ $sequence ]['element_id_local'] = $element_id;
        }
        return $result;
    }

    private static function render_context( $first, $second ) {
        $object = is_object( $second ) ? $second : ( is_object( $first ) ? $first : null );
        $fields = $object ? get_object_vars( $object ) : ( is_array( $second ) ? $second : array() );
        $type = '';
        foreach ( array( 'name', 'type', 'element_type' ) as $field ) {
            if ( isset( $fields[ $field ] ) ) { $type = self::safe_scalar( $fields[ $field ] ); if ( $type ) { break; } }
        }
        $id = '';
        foreach ( array( 'id', 'element_id' ) as $field ) {
            if ( isset( $fields[ $field ] ) ) { $id = self::local_element_id( $fields[ $field ] ); if ( $id ) { break; } }
        }
        return array( 'type' => $type ?: 'unknown', 'element_group' => $id ? self::hmac( $id ) : '' );
    }

    public static function render_start( $value = null, $context = null ) {
        if ( ! self::$enabled ) { return $value; }
        $hook = current_filter();
        if ( ! in_array( $hook, self::render_hooks(), true ) ) { return $value; }
        self::$fired[ $hook ] = (int) ( self::$fired[ $hook ] ?? 0 ) + 1;
        if ( self::$render_count >= self::MAX_RENDER_INVOCATIONS ) { ++self::$render_dropped; return $value; }
        $at = microtime( true );
        self::$render_stack[ $hook ][] = array( 'started' => $at, 'context' => self::render_context( $value, $context ),
            'cache' => PFC_Request_Cache::snapshot() );
        ++self::$render_count;
        self::$overhead_ms += ( microtime( true ) - $at ) * 1000;
        return $value;
    }

    public static function render_end( $value = null, $context = null ) {
        if ( ! self::$enabled ) { return $value; }
        $hook = current_filter();
        if ( empty( self::$render_stack[ $hook ] ) ) { return $value; }
        $at = microtime( true );
        $entry = array_pop( self::$render_stack[ $hook ] );
        $ctx = $entry['context'];
        $key = $hook . ':' . $ctx['type'] . ':' . $ctx['element_group'];
        if ( ! isset( self::$render_rows[ $key ] ) && count( self::$render_rows ) >= self::MAX_RENDER_GROUPS ) {
            // Preserve coverage as a hook-level aggregate instead of discarding
            // every subsequent element, with at most three overflow rows.
            $key = $hook . ':other-elements:';
            $ctx = array( 'type' => 'other-elements', 'element_group' => '' );
            ++self::$render_aggregated;
        }
        if ( ! isset( self::$render_rows[ $key ] ) ) {
            self::$render_rows[ $key ] = array( 'hook' => $hook, 'element_type' => $ctx['type'], 'element_group' => $ctx['element_group'],
                'calls' => 0, 'elapsed_ms' => 0.0, 'max_ms' => 0.0, 'cache_lookups' => null );
        }
        $row = &self::$render_rows[ $key ];
        $ms = max( 0, ( $at - $entry['started'] ) * 1000 );
        ++$row['calls'];
        $row['elapsed_ms'] += $ms;
        $row['max_ms'] = max( $row['max_ms'], $ms );
        $delta = PFC_Request_Cache::delta( (array) $entry['cache'], PFC_Request_Cache::snapshot() );
        if ( null !== $delta['lookups'] ) { $row['cache_lookups'] = (int) ( $row['cache_lookups'] ?? 0 ) + max( 0, (int) $delta['lookups'] ); }
        unset( $row );
        self::$overhead_ms += ( microtime( true ) - $at ) * 1000;
        return $value;
    }

    /**
     * A query can finish without either filter firing. Classify what is still
     * observable at shutdown, without accessing SQL, post contents or IDs.
     * Categories are evidence/hypotheses, never proof of query failure.
     */
    private static function unclosed_query_evidence() {
        $counts = array( 'filters_suppressed_candidate' => 0, 'object_released' => 0,
            'results_present_without_hook' => 0, 'no_result_state_observed' => 0, 'reused_object_id' => self::$reused_query_object_ids );
        $groups = array();
        foreach ( self::$started as $entry ) {
            $ref = $entry['object_ref'] ?? null;
            $query = $ref instanceof WeakReference ? $ref->get() : $ref;
            $suppressed = ! empty( $entry['suppress_filters_at_start'] );
            if ( is_object( $query ) && isset( $query->query_vars ) && is_array( $query->query_vars ) ) {
                $suppressed = $suppressed || ! empty( $query->query_vars['suppress_filters'] );
            }
            if ( $suppressed ) { $classification = 'filters_suppressed_candidate'; }
            elseif ( ! is_object( $query ) ) { $classification = 'object_released'; }
            elseif ( ( isset( $query->posts ) && is_array( $query->posts ) && count( $query->posts ) > 0 ) ||
                ( isset( $query->post_count ) && (int) $query->post_count > 0 ) ) { $classification = 'results_present_without_hook'; }
            else { $classification = 'no_result_state_observed'; }
            ++$counts[ $classification ];
            $scope = in_array( $entry['parent_scope'] ?? '', array( 'assets', 'elements', 'other-bricks' ), true ) ? $entry['parent_scope'] : 'other-bricks';
            $shape = (array) ( $entry['shape'] ?? array() );
            $group = (string) ( $entry['shape_group'] ?? '' );
            $key = $scope . ':' . $classification . ':' . $group;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array( 'parent_scope' => $scope, 'category' => $classification,
                    'shape_group' => $group, 'shape' => $shape, 'calls' => 0 );
            }
            ++$groups[ $key ]['calls'];
        }
        return array( 'categories' => $counts, 'groups' => array_slice( array_values( $groups ), 0, 20 ),
            'groups_omitted' => max( 0, count( $groups ) - 20 ) );
    }

    /**
     * Reconcile the observed Bricks query scopes with the independently timed
     * parent callbacks. Remaining time is a comparison, not exclusive PHP time:
     * instrumentation and nested/unseen work may overlap.
     */
    public static function with_initialization_breakdown( array $bricks, array $callbacks ) {
        if ( empty( $bricks['enabled'] ) || empty( $callbacks['enabled'] ) ) { return $bricks; }
        $scopes = array(
            'assets' => array( 'file' => 'themes/bricks/includes/assets.php', 'hook' => 'wp_enqueue_scripts' ),
            'elements' => array( 'file' => 'themes/bricks/includes/elements.php', 'hook' => 'wp' ),
        );
        $observed = array();
        foreach ( (array) ( $bricks['parent_callback_query_scopes'] ?? array() ) as $row ) {
            if ( in_array( $row['scope'] ?? '', array_keys( $scopes ), true ) ) { $observed[ $row['scope'] ] = $row; }
        }
        $out = array();
        foreach ( $scopes as $name => $match ) {
            foreach ( (array) ( $callbacks['rows'] ?? array() ) as $callback ) {
                if ( ( $callback['file'] ?? '' ) !== $match['file'] || ( $callback['hook'] ?? '' ) !== $match['hook'] ||
                     ( $callback['component'] ?? '' ) !== 'theme:bricks' ) { continue; }
                $phase = $observed[ $name ] ?? array();
                $inclusive = (float) ( $callback['inclusive_ms'] ?? 0 );
                $query_ms = (float) ( $phase['observed_query_elapsed_ms'] ?? 0 );
                $out[] = array( 'scope' => $name,
                    'callback_file' => $match['file'],
                    'callback_line' => max( 0, (int) ( $callback['line'] ?? 0 ) ),
                    'callback_inclusive_ms' => round( $inclusive, 3 ),
                    'observed_child_query_ms' => round( $query_ms, 3 ),
                    'observed_child_sql_ms' => round( (float) ( $phase['observed_sql_ms'] ?? 0 ), 3 ),
                    'child_queries' => max( 0, (int) ( $phase['query_calls'] ?? 0 ) ),
                    'callback_minus_observed_queries_ms' => $query_ms <= $inclusive ? round( $inclusive - $query_ms, 3 ) : null,
                    'cache_get_lookups_during_callback' => isset( $callback['cache_get_lookups'] ) ? max( 0, (int) $callback['cache_get_lookups'] ) : null,
                    'cache_get_lookups_during_child_queries' => isset( $phase['cache_lookups'] ) ? max( 0, (int) $phase['cache_lookups'] ) : null );
                break;
            }
        }
        $bricks['initialization_breakdown'] = $out;
        return $bricks;
    }

    /**
     * Administrator-only review data from the first verified run and the
     * following runs. No timing is projected beyond this one signed sample.
     * Raw element IDs are never copied into the AI export.
     */
    public static function priority_review_rows( array $runs ) {
        $first = isset( $runs[0]['bricks_profile'] ) && is_array( $runs[0]['bricks_profile'] )
            ? $runs[0]['bricks_profile'] : array();
        $out = array();
        foreach ( (array) ( $first['query_groups'] ?? array() ) as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['element_id_local'] ) || ( $entry['parent_scope'] ?? '' ) !== 'assets' ) { continue; }
            $shape = (array) ( $entry['shape'] ?? array() );
            $later_sql = array();
            $later_elapsed = array();
            for ( $i = 1; $i < count( $runs ); $i++ ) {
                $match_sql = 0.0;
                $match_elapsed = 0.0;
                $matched = false;
                foreach ( (array) ( $runs[ $i ]['bricks_profile']['query_groups'] ?? array() ) as $later ) {
                    if ( ! is_array( $later ) || ( $later['element_group'] ?? null ) !== ( $entry['element_group'] ?? null ) ||
                         ( $later['shape_group'] ?? null ) !== ( $entry['shape_group'] ?? null ) ||
                         ( $later['parent_scope'] ?? '' ) !== 'assets' ) { continue; }
                    if ( ! is_numeric( $later['sql_ms'] ?? null ) ) { continue; }
                    $match_sql += (float) $later['sql_ms'];
                    $match_elapsed += (float) ( $later['elapsed_ms'] ?? 0 );
                    $matched = true;
                }
                if ( $matched ) { $later_sql[] = $match_sql; $later_elapsed[] = $match_elapsed; }
            }
            $signals = array();
            if ( count( (array) ( $shape['post_types'] ?? array() ) ) > 1 ) { $signals[] = 'Confirm mixed post-type taxonomy behaviour'; }
            if ( (int) ( $shape['tax_clauses'] ?? 0 ) > 1 ) { $signals[] = 'Review taxonomy AND/OR complexity'; }
            if ( (int) ( $shape['posts_per_page'] ?? 0 ) >= 7 ) { $signals[] = 'Confirm result count and pagination need'; }
            if ( ! $signals ) { $signals[] = 'Review ordering, pagination and cache behaviour'; }
            $out[] = array(
                'element_id_local' => $entry['element_id_local'],
                'shape' => $shape,
                'first_sql_ms' => is_numeric( $entry['sql_ms'] ?? null ) ? round( (float) $entry['sql_ms'], 3 ) : null,
                'first_elapsed_ms' => is_numeric( $entry['elapsed_ms'] ?? null ) ? round( (float) $entry['elapsed_ms'], 3 ) : null,
                'later_median_sql_ms' => $later_sql ? round( (float) PFC_Utils::median( $later_sql ), 3 ) : null,
                'later_median_elapsed_ms' => $later_elapsed ? round( (float) PFC_Utils::median( $later_elapsed ), 3 ) : null,
                'following_runs_matched' => count( $later_sql ),
                'signals' => $signals,
            );
        }
        usort( $out, static function( $a, $b ) {
            return (float) ( $b['first_sql_ms'] ?? 0 ) <=> (float) ( $a['first_sql_ms'] ?? 0 );
        } );
        return array_slice( $out, 0, 15 );
    }

    public static function summary() {
        if ( ! self::$enabled ) { return array( 'enabled' => false ); }
        $query_rows = array();
        $phase_rows = array();
        $identified = 0;
        foreach ( self::$samples as $sample ) {
            if ( ! empty( $sample['matched_bricks_result'] ) && ! empty( $sample['element_id_local'] ) ) { ++$identified; }
            $key = $sample['shape_group'] . ':' . $sample['element_group'] . ':' . $sample['parent_scope'] . ':' . $sample['completion_method'];
            if ( ! isset( $query_rows[ $key ] ) ) {
                $query_rows[ $key ] = array( 'shape_group' => $sample['shape_group'], 'element_group' => $sample['element_group'],
                    'element_id_local' => $sample['element_id_local'], 'origin' => $sample['origin'], 'shape' => $sample['shape'],
                    'parent_scope' => $sample['parent_scope'], 'completion_method' => $sample['completion_method'],
                    'calls' => 0, 'elapsed_ms' => 0.0, 'max_ms' => 0.0, 'sql_ms' => null, 'sql_queries' => null,
                    'cache_lookups' => null, 'result_count' => 0 );
            }
            $row = & $query_rows[ $key ];
            ++$row['calls'];
            $row['elapsed_ms'] += $sample['elapsed_ms'];
            $row['max_ms'] = max( $row['max_ms'], $sample['elapsed_ms'] );
            $row['result_count'] += $sample['result_count'];
            if ( null !== $sample['sql_ms'] ) { $row['sql_ms'] = (float) ( $row['sql_ms'] ?? 0 ) + $sample['sql_ms']; }
            if ( null !== $sample['sql_queries'] ) { $row['sql_queries'] = (int) ( $row['sql_queries'] ?? 0 ) + $sample['sql_queries']; }
            if ( null !== $sample['cache_lookups'] ) { $row['cache_lookups'] = (int) ( $row['cache_lookups'] ?? 0 ) + max( 0, (int) $sample['cache_lookups'] ); }
            unset( $row );
            $scope = $sample['parent_scope'];
            if ( ! isset( $phase_rows[ $scope ] ) ) { $phase_rows[ $scope ] = array( 'scope' => $scope, 'query_calls' => 0, 'observed_query_elapsed_ms' => 0.0, 'observed_sql_ms' => 0.0, 'cache_lookups' => 0 ); }
            ++$phase_rows[ $scope ]['query_calls'];
            $phase_rows[ $scope ]['observed_query_elapsed_ms'] += $sample['elapsed_ms'];
            $phase_rows[ $scope ]['observed_sql_ms'] += (float) ( $sample['sql_ms'] ?? 0 );
            $phase_rows[ $scope ]['cache_lookups'] += (int) ( $sample['cache_lookups'] ?? 0 );
        }
        $unclosed = self::unclosed_query_evidence();
        $queries = array_values( $query_rows );
        usort( $queries, static function ( $a, $b ) { return $b['elapsed_ms'] <=> $a['elapsed_ms']; } );
        foreach ( $queries as &$q ) { foreach ( array( 'elapsed_ms', 'max_ms', 'sql_ms' ) as $f ) { if ( null !== $q[$f] ) { $q[$f] = round( $q[$f], 3 ); } } } unset( $q );
        foreach ( $phase_rows as &$phase ) { $phase['observed_query_elapsed_ms'] = round( $phase['observed_query_elapsed_ms'], 3 ); $phase['observed_sql_ms'] = round( $phase['observed_sql_ms'], 3 ); } unset( $phase );
        $renders = array_values( self::$render_rows );
        usort( $renders, static function ( $a, $b ) { return $b['elapsed_ms'] <=> $a['elapsed_ms']; } );
        foreach ( $renders as &$r ) { foreach ( array( 'elapsed_ms', 'max_ms' ) as $f ) { $r[$f] = round( $r[$f], 3 ); } } unset( $r );
        return array(
            'enabled' => true, 'scope' => 'signed-callback-profile-only',
            'observed_bricks_queries' => self::$query_count,
            'query_groups' => array_slice( $queries, 0, self::MAX_EXPORT_ROWS ),
            'query_rows_omitted' => max( 0, count( $queries ) - self::MAX_EXPORT_ROWS ),
            'query_samples_dropped' => self::$query_dropped,
            // An unclosed scope is an instrumentation coverage gap, not a failed query.
            'unfinished_queries' => count( self::$started ) + self::$reused_query_object_ids,
            'query_completion' => array( 'the_posts' => self::$the_posts_completed,
                'bricks_result_fallback' => self::$result_hook_completed,
                'bricks_result_object_matched' => self::$result_matched,
                'bricks_result_object_unmatched' => self::$result_unmatched,
                'no_completion_hook' => count( self::$started ) + self::$reused_query_object_ids,
                'reused_object_id_gaps' => self::$reused_query_object_ids ),
            'exact_element_attributions' => $identified,
            'initialization_accessor_profile' => self::accessor_summary(),
            'unclosed_query_evidence' => $unclosed,
            'observed_bricks_element_ids_local' => array_slice( array_keys( self::$element_hints ), 0, self::MAX_ELEMENT_HINTS ),
            'parent_callback_query_scopes' => array_values( $phase_rows ),
            'render_hook_fires' => self::$fired, 'render_groups' => array_slice( $renders, 0, self::MAX_EXPORT_ROWS ),
            'render_rows_omitted' => max( 0, count( $renders ) - self::MAX_EXPORT_ROWS ),
            'render_completed_scopes' => self::$render_count,
            'render_samples_dropped' => self::$render_dropped,
            'render_samples_aggregated' => self::$render_aggregated,
            'bookkeeping_lower_bound_ms' => round( self::$overhead_ms, 3 ),
            'notes' => 'Only bounded WordPress accessor hooks are observed inside selected Bricks callbacks, not Redis commands. Exact element attribution requires the same WP_Query object in the Bricks result filter. Short raw element IDs are stored only locally for administrators and are stripped from AI exports. Query closure at the Bricks result hook is an upper-bound elapsed time, not a the_posts interval. Unclosed queries are instrumentation gaps; suppressed filters and retained results are only candidate explanations, not proof of why a hook was skipped. Parent scope query totals can overlap other PHP callback timing. Render-hook timing measures callbacks on hooks, not full element rendering. Cache counters do not measure Redis operations.',
        );
    }
}
