<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Object Cache Pro compatibility and read-only inspection. Does not touch the
 * drop-in, alter configuration, scan Redis keys directly for values, or flush.
 */
final class PFC_Object_Cache_Pro {
    const FLUSH_OPTION = 'pfc_ocp_flush_history';
    const MAX_FLUSH_LOG = 24;
    const SNAPSHOT_OPTION = 'pfc_ocp_group_snapshots';
    const MAX_GROUP_SNAPSHOTS = 5;
    private static $pending = array();
    private static $shutdown_registered = false;

    /** Restrict any exported flush caller to code-like identifiers, not stack text. */
    private static function safe_caller_name( $frame ) {
        if ( ! is_array( $frame ) ) { return 'unavailable'; }
        $class = $frame['class'] ?? '';
        $function = $frame['function'] ?? '';
        if ( ! is_string( $class ) || ! is_string( $function ) || '' === $function ) { return 'unavailable'; }
        $method = '' !== $class ? $class . '::' . $function : $function;
        if ( strlen( $method ) > 128 ) { return 'unavailable'; }
        // Remove namespace and method separators before validating each code identifier.
        $plain = str_replace( array( '\\', '::', '->' ), '', $method );
        if ( '' === $plain || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $plain ) ) { return 'unavailable'; }
        return str_replace( '->', '::', $method );
    }

    private static function flush_source( $file ) {
        if ( ! is_string( $file ) || '' === $file ) { return 'unavailable'; }
        $file = str_replace( '\\', '/', $file );
        if ( defined( 'WP_PLUGIN_DIR' ) ) {
            $base = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' ) . '/';
            if ( 0 === strpos( $file, $base ) ) {
                $relative = substr( $file, strlen( $base ) );
                if ( preg_match( '~^[a-zA-Z0-9_./-]{1,180}$~D', $relative ) && false === strpos( $relative, '..' ) ) {
                    return 'plugins/' . $relative;
                }
            }
        }
        if ( preg_match( '~/(themes/[a-zA-Z0-9_-]+/[a-zA-Z0-9_./-]{1,150})$~D', $file, $m ) && false === strpos( $m[1], '..' ) ) {
            return $m[1];
        }
        return 'unavailable';
    }

    /** A native, argument-free trace takes precedence over inferred runtime frames. */
    private static function flush_caller_evidence( $trace ) {
        if ( is_array( $trace ) ) {
            foreach ( array_slice( $trace, 1, 5 ) as $f ) {
                if ( ! is_array( $f ) ) { continue; }
                $source = self::flush_source( $f['file'] ?? '' );
                if ( 'unavailable' === $source ) { continue; }
                $caller = self::safe_caller_name( $f );
                if ( 'unavailable' === $caller ) { continue; }
                return array( 'caller' => $caller, 'source' => $source, 'confidence' => 'native_argument_free_trace' );
            }
        }
        // Some OCP releases or callers do not pass a usable trace. Look at only
        // a few argument-free frames and keep only plugin/theme relative sources.
        foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ), 2 ) as $f ) {
            $source = self::flush_source( $f['file'] ?? '' );
            if ( 'unavailable' === $source || 0 === strpos( $source, 'plugins/performance-console/' ) ) { continue; }
            $caller = self::safe_caller_name( $f );
            if ( 'unavailable' !== $caller ) {
                return array( 'caller' => $caller, 'source' => $source, 'confidence' => 'bounded_runtime_trace' );
            }
        }
        return array( 'caller' => 'unavailable', 'source' => 'unavailable', 'confidence' => 'none' );
    }

    public static function detect( $read_dropin = false ) {
        global $wp_object_cache;
        $class = is_object( $wp_object_cache ) ? strtolower( get_class( $wp_object_cache ) ) : '';
        $active = (array) get_option( 'active_plugins', array() );
        $network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
        $plugin = in_array( 'object-cache-pro/object-cache-pro.php', $active, true ) || isset( $network['object-cache-pro/object-cache-pro.php'] );
        $dropin = '';
        if ( $read_dropin && defined( 'WP_CONTENT_DIR' ) && is_readable( WP_CONTENT_DIR . '/object-cache.php' ) ) {
            $head = @file_get_contents( WP_CONTENT_DIR . '/object-cache.php', false, null, 0, 3072 );
            $dropin = is_string( $head ) ? strtolower( $head ) : '';
        }
        $identified = $plugin || false !== strpos( $class, 'rediscachepro' ) || false !== strpos( $class, 'objectcachepro' ) || false !== strpos( $dropin, 'object cache pro' ) || false !== strpos( $dropin, 'rediscachepro' );
        return array(
            'status' => $identified ? 'detected' : ( defined( 'WP_REDIS_CONFIG' ) ? 'unconfirmed' : 'not_detected' ),
            'plugin_active' => $plugin,
            'dropin_recognized' => false !== strpos( $dropin, 'object cache pro' ) || false !== strpos( $dropin, 'rediscachepro' ),
            'persistent_cache' => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : null,
        );
    }

    /** Flush listeners are passive and never change the decision or the group. */
    public static function init() {
        if ( 'detected' !== self::detect()['status'] ) { return; }
        add_filter( 'pre_objectcache_flush', array( __CLASS__, 'observe_full_flush' ), 20, 2 );
        add_filter( 'pre_objectcache_flush_group', array( __CLASS__, 'observe_group_flush' ), 20, 3 );
    }
    public static function observe_full_flush( $decision, $trace = array() ) {
        self::record_flush( 'full', '', $trace );
        return $decision;
    }
    public static function observe_group_flush( $decision, $group = '', $trace = array() ) {
        self::record_flush( 'group', $group, $trace );
        return $decision;
    }
    private static function record_flush( $type, $group, $trace ) {
        if ( count( self::$pending ) >= 8 ) { return; }
        $evidence = self::flush_caller_evidence( $trace );
        $file = $evidence['source'];
        $owner = 'unknown';
        if ( preg_match( '~^plugins/([a-z0-9_-]{1,70})/~iD', $file, $m ) ) {
            $owner = 'plugin:' . strtolower( $m[1] );
        } elseif ( preg_match( '~^themes/([a-z0-9_-]{1,70})/~iD', $file, $m ) ) {
            $owner = 'theme:' . strtolower( $m[1] );
        }
        $name = $evidence['caller'];
        self::$pending[] = array(
            'at_utc' => gmdate( 'c' ), 'type' => $type, 'component' => $owner,
            'caller' => $name, 'caller_source' => $evidence['source'],
            'caller_confidence' => $evidence['confidence'],
            'group_id' => 'group' === $type && is_scalar( $group ) ? substr( hash_hmac( 'sha256', (string) $group, wp_salt( 'auth' ) ), 0, 16 ) : null,
            'execution' => 'attempt_observed_not_success_confirmed',
        );
        if ( ! self::$shutdown_registered ) {
            self::$shutdown_registered = true;
            register_shutdown_function( array( __CLASS__, 'persist_flushes' ) );
        }
    }
    public static function persist_flushes() {
        if ( ! self::$pending ) { return; }
        $old = get_option( self::FLUSH_OPTION, array() );
        $rows = array_slice( array_merge( (array) $old, self::$pending ), -self::MAX_FLUSH_LOG );
        update_option( self::FLUSH_OPTION, $rows, false );
        self::$pending = array();
    }
    public static function flush_history() {
        $rows = get_option( self::FLUSH_OPTION, array() );
        return array_slice( is_array( $rows ) ? $rows : array(), -self::MAX_FLUSH_LOG );
    }

    /** Repeated attempts at a single anonymized group over a short window. */
    public static function flush_bursts( $history ) {
        $buckets = array();
        foreach ( array_slice( (array) $history, -self::MAX_FLUSH_LOG ) as $row ) {
            if ( ! is_array( $row ) || 'group' !== ( $row['type'] ?? '' ) || ! preg_match( '/^[a-f0-9]{16}$/D', (string) ( $row['group_id'] ?? '' ) ) ) { continue; }
            $at = strtotime( (string) ( $row['at_utc'] ?? '' ) );
            if ( ! $at ) { continue; }
            $component = (string) ( $row['component'] ?? 'unknown' );
            $key = $component . ':' . $row['group_id'];
            if ( ! isset( $buckets[ $key ] ) || $at - $buckets[ $key ]['last_timestamp'] > 60 ) {
                $buckets[ $key ] = array( 'component' => $component, 'group_id' => $row['group_id'], 'attempts' => 0, 'first_timestamp' => $at, 'last_timestamp' => $at );
            }
            ++$buckets[ $key ]['attempts'];
            $buckets[ $key ]['last_timestamp'] = $at;
        }
        $out = array();
        foreach ( $buckets as $item ) {
            if ( $item['attempts'] < 2 ) { continue; }
            $out[] = array( 'component' => $item['component'], 'group_id' => $item['group_id'], 'attempts' => $item['attempts'], 'window_seconds' => $item['last_timestamp'] - $item['first_timestamp'], 'completion' => 'not_confirmed' );
        }
        return array_slice( $out, 0, 8 );
    }


    /** Distinguish old persisted flush attempts from activity in the current interval. */
    public static function flush_freshness( $history, $now = null ) {
        $now = null === $now ? time() : (int) $now;
        $recent = 0;
        $latest_age = null;
        $legacy = 0;
        foreach ( (array) $history as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $timestamp = strtotime( (string) ( $row['at_utc'] ?? '' ) );
            if ( false === $timestamp || $timestamp > $now + 60 ) { continue; }
            $age = max( 0, $now - $timestamp );
            $latest_age = null === $latest_age ? $age : min( $latest_age, $age );
            if ( $age <= 600 ) { ++$recent; }
            if ( 'none' === ( $row['caller_confidence'] ?? 'none' ) && 'unavailable' !== ( $row['caller'] ?? 'unavailable' ) ) { ++$legacy; }
        }
        return array( 'attempts_last_10_minutes' => $recent, 'latest_event_age_seconds' => $latest_age,
            'unverified_legacy_caller_rows' => $legacy, 'scope' => 'passive_hook_attempts_only' );
    }

    /** Fixed-category triage never claims to have replicated proprietary OCP checks. */
    public static function tracking_triage( $tracking, $acl, $detected ) {
        $status = (string) ( $tracking['status'] ?? 'not_tested' );
        $category = (string) ( $tracking['failure_category'] ?? 'unknown' );
        $error = in_array( $status, array( 'native_error', 'reported_error' ), true );
        $native = 'native_health' === ( $tracking['confidence'] ?? '' );
        $checks = (array) ( $acl['tests'] ?? array() );
        $critical = array();
        foreach ( array( 'PING', 'INFO', 'SCAN' ) as $command ) {
            $critical[ $command ] = $checks[ $command ]['status'] ?? 'not_tested';
        }
        $issue = $error ? ( 'unknown' === $category ? 'native_error_reason_not_exposed' : 'native_error_category_observed' ) : 'no_confirmed_native_error';
        $next = $error ? ( 'unknown' === $category ? 'inspect_native_diagnostics_for_exact_failure' : 'confirm_native_failed_operation_before_changes' ) : 'monitor_native_status';
        return array(
            'status' => $issue, 'native_evidence' => $native,
            'reported_failure_category' => $error ? $category : 'not_applicable',
            'provider_detected' => 'detected' === ( $detected['status'] ?? '' ),
            'read_only_capabilities' => $critical,
            'optional_acl_denials_are_causal_evidence' => false,
            'exact_failed_command_known' => false,
            'next_action' => $next,
        );
    }

    public static function inspect( $groups = false, $group_memory = false ) {
        $detected = self::detect( true );
        $config = defined( 'WP_REDIS_CONFIG' ) && is_array( WP_REDIS_CONFIG ) ? WP_REDIS_CONFIG : array();
        $native = self::native_tracking_evidence( $detected );
        $report = array(
            'captured_at_utc' => gmdate( 'c' ),
            'detection' => $detected,
            'health_checks' => self::health_checks( $detected ),
            'key_tracking_diagnostics' => $native + array(
                'configured_tracking' => isset( $config['tracking'] ) ? (bool) $config['tracking'] : null,
                'explanation' => 'The native Key Tracking field is separate from Redis CLIENT TRACKINGINFO. Diagnostic ACL denials are not proof of a tracking failure.',
            ),
            'acl_diagnostics' => self::acl_probe( $detected ),
            'cache_group_metrics' => self::groups( $detected, (bool) $groups, (bool) $group_memory ),
            'flush_history' => self::flush_history(),
            'serialization_benchmarks' => self::serialization_benchmark(),
            'cache_debug' => array(
                'wp_debug' => defined( 'WP_DEBUG' ) ? (bool) WP_DEBUG : null,
                'savequeries' => defined( 'SAVEQUERIES' ) ? (bool) SAVEQUERIES : null,
                'ocp_debug' => array_key_exists( 'debug', $config ) ? (bool) $config['debug'] : null,
                'ocp_save_commands' => array_key_exists( 'save_commands', $config ) ? (bool) $config['save_commands'] : null,
            ),
            'capabilities' => array(
                'operation_call_stacks' => 'not_available_without_supported_dropin_command_log',
                'cache_group_count' => $groups ? 'native_ocp_rest_probe_attempted' : 'opt_in_not_run',
                'per_key_sizes' => 'not_collected',
                'flush_observation' => 'passive_pre_flush_hooks_attempts_only',
                'native_tracking_check' => 'not_reproduced_unless_registered_site_health_test',
            ),
        );
        $tracking = (array) ( $report['health_checks']['tracking'] ?? array() );
        if ( 'wordpress_site_health_info' !== ( $report['key_tracking_diagnostics']['source'] ?? '' ) && in_array( $tracking['status'] ?? '', array( 'good', 'recommended', 'critical' ), true ) ) {
            $report['key_tracking_diagnostics']['status'] = 'good' === $tracking['status'] ? 'native_ok' : ( 'critical' === $tracking['status'] ? 'native_error' : 'native_warning' );
            $report['key_tracking_diagnostics']['source'] = 'registered_wordpress_site_health_test';
            $report['key_tracking_diagnostics']['confidence'] = 'native_health';
            $report['key_tracking_diagnostics']['failure_category'] = $tracking['failure_category'] ?? 'unknown';
        }
        $report['key_tracking_triage'] = self::tracking_triage( $report['key_tracking_diagnostics'], $report['acl_diagnostics'], $detected );
        $manual = self::manual_tracking_report();
        if ( $manual ) {
            $report['key_tracking_triage']['administrator_report'] = array(
                'category' => in_array( $manual['operator_category'] ?? '', self::reported_tracking_categories(), true ) ? $manual['operator_category'] : 'unknown',
                'command' => in_array( $manual['operator_command'] ?? '', self::reported_tracking_commands(), true ) ? $manual['operator_command'] : 'NOT_IDENTIFIED',
                'confidence' => 'administrator_reported',
            );
        }
        $report['flush_bursts'] = self::flush_bursts( $report['flush_history'] );
        $report['flush_freshness'] = self::flush_freshness( $report['flush_history'] );
        if ( $groups && 'complete' === ( $report['cache_group_metrics']['status'] ?? '' ) ) {
            $report['cache_group_growth'] = self::record_group_snapshot( $report['cache_group_metrics'] );
        } else {
            $report['cache_group_growth'] = array( 'status' => 'not_tested', 'rows' => array() );
        }
        $report['recommendations'] = self::recommendations( $report );
        return $report;
    }

    /** The provider's Site Health INFO section is not necessarily a registered status test. */
    public static function native_tracking_from_info( $sections ) {
        if ( ! is_array( $sections ) ) { return array( 'status' => 'not_exposed', 'source' => 'none' ); }
        $names = array( 'objectcacheredistrackingkeys', 'objectcachetrackingkeys', 'redistrackingkeys', 'keytracking', 'trackingkeys' );
        foreach ( array_slice( $sections, 0, 70, true ) as $section => $data ) {
            if ( ! is_array( $data ) ) { continue; }
            $section_norm = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $section ) );
            $section_ok = false !== strpos( $section_norm, 'objectcache' ) || false !== strpos( $section_norm, 'redis' );
            if ( ! $section_ok ) { continue; }
            $fields = (array) ( $data['fields'] ?? array() );
            foreach ( array_slice( $fields, 0, 125, true ) as $key => $field ) {
                $label = is_array( $field ) ? (string) ( $field['label'] ?? '' ) : '';
                $key_norm = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $key ) );
                $label_norm = strtolower( preg_replace( '/[^a-z0-9]/i', '', $label ) );
                if ( ! in_array( $key_norm, $names, true ) && ! in_array( $label_norm, $names, true ) ) { continue; }
                $value = is_array( $field ) ? ( $field['value'] ?? null ) : $field;
                $status = self::native_tracking_value( $value );
                $plain = is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
                $detail = preg_match( '/^(error|fail(?:ed)?|warning|ok|good|enabled|disabled)$/iD', $plain ) ? 'status_only' : 'unverified_detail_present';
                return array( 'status' => $status, 'source' => 'wordpress_site_health_info', 'field_found' => true,
                    'detail_level' => $detail,
                    'failure_category' => 'native_error' === $status ? self::native_failure_category( $value ) : 'not_applicable' );
            }
        }
        return array( 'status' => 'not_exposed', 'source' => 'none', 'field_found' => false );
    }

    /** Return only a fixed diagnostic category, never the native error string. */
    private static function native_failure_category( $value ) {
        if ( ! is_scalar( $value ) ) { return 'unknown'; }
        $message = strtolower( substr( strip_tags( (string) $value ), 0, 400 ) );
        if ( preg_match( '/noperm|permission denied|not allowed|no permissions/i', $message ) ) { return 'acl_denied'; }
        if ( preg_match( '/noauth|wrongpass|invalid credentials|authentication failed/i', $message ) ) { return 'authentication'; }
        if ( preg_match( '/unknown command|unsupported command|not supported/i', $message ) ) { return 'unsupported_command'; }
        if ( preg_match( '/timed? out|connection refused|connection reset|broken pipe/i', $message ) ) { return 'connection_or_timeout'; }
        if ( preg_match( '/oom|out of memory|maxmemory/i', $message ) ) { return 'memory_pressure'; }
        if ( preg_match( '/read.?only/i', $message ) ) { return 'read_only'; }
        return 'unknown';
    }

    private static function native_tracking_value( $value ) {
        if ( is_bool( $value ) ) { return $value ? 'native_ok' : 'native_disabled_or_unavailable'; }
        if ( ! is_scalar( $value ) ) { return 'native_inconclusive'; }
        $word = strtolower( trim( strip_tags( (string) $value ) ) );
        if ( strlen( $word ) > 200 ) { $word = substr( $word, 0, 200 ); }
        if ( preg_match( '/\b(error|fail(?:ed|ure)?|broken|critical|denied)\b/i', $word ) ) { return 'native_error'; }
        if ( preg_match( '/\b(warn(?:ing)?|recommend(?:ed)?|degrad(?:ed)?)\b/i', $word ) ) { return 'native_warning'; }
        if ( preg_match( '/\b(disabled|off|inactive|unsupported|not available)\b/i', $word ) ) { return 'native_disabled_or_unavailable'; }
        if ( preg_match( '/\b(ok|good|healthy|pass(?:ed)?|success|enabled|working)\b/i', $word ) ) { return 'native_ok'; }
        return 'native_inconclusive';
    }

    private static function native_tracking_evidence( array $detected ) {
        if ( 'detected' !== ( $detected['status'] ?? '' ) ) {
            return array( 'status' => 'not_available', 'source' => 'none', 'confidence' => 'none' );
        }
        $evidence = array( 'status' => 'needs_native_health_result', 'source' => 'none', 'confidence' => 'unavailable' );
        // Only native WordPress's read-only Site Health Info filter is queried.
        // The raw returned structure is never logged, stored, or included in AI exports.
        try {
            $info = apply_filters( 'debug_information', array() );
            $native = self::native_tracking_from_info( $info );
            if ( ! in_array( $native['status'], array( 'not_exposed', 'native_inconclusive' ), true ) ) {
                return array( 'status' => $native['status'], 'source' => $native['source'], 'confidence' => 'native_health',
                    'failure_category' => $native['failure_category'] ?? 'unknown',
                    'detail_level' => $native['detail_level'] ?? 'unknown' );
            }
        } catch ( Throwable $ignored ) { $evidence['info_probe'] = 'read_error'; }
        // A manual status is explicitly labelled user-reported, never native confirmed.
        $manual = self::manual_tracking_report();
        if ( $manual ) {
            $evidence['status'] = $manual['status'];
            $evidence['source'] = 'administrator_reported_site_health';
            $evidence['confidence'] = 'user_reported';
            $evidence['reported_at_utc'] = $manual['reported_at_utc'];
        }
        return $evidence;
    }

    /** Store only a small enum, not arbitrary pasted Site Health/CLI diagnostics. */
    public static function reported_tracking_categories() {
        return array( 'unknown', 'acl_denied', 'authentication', 'unsupported_command', 'connection_or_timeout', 'memory_pressure', 'read_only', 'other' );
    }
    public static function reported_tracking_commands() {
        return array( 'NOT_IDENTIFIED', 'SCAN', 'KEYS', 'EVAL', 'EVALSHA', 'SET', 'GET', 'HSET', 'DEL', 'UNLINK', 'INFO', 'EXPIRE', 'CLIENT', 'ACL', 'SADD', 'SREM', 'SMEMBERS', 'SSCAN', 'SISMEMBER', 'ZADD', 'ZREM', 'HGET', 'HDEL', 'EXISTS' );
    }
    /** Classify one native CLI failure line entirely in-memory. Never return or persist it. */
    public static function classify_native_error_line( $line ) {
        $raw = is_string( $line ) ? substr( $line, 0, 512 ) : '';
        $category = self::native_failure_category( $raw );
        $command = 'NOT_IDENTIFIED';
        // Only infer the command when the error line explicitly names a command.
        if ( preg_match( '/(?:command\s*[\x22\x27:]?\s*|run\s+the\s+)([A-Za-z]+)\b/i', $raw, $m ) ) {
            $candidate = strtoupper( $m[1] );
            if ( in_array( $candidate, self::reported_tracking_commands(), true ) ) { $command = $candidate; }
        }
        return array( 'category' => $category, 'command' => $command,
            'confidence' => 'administrator_supplied_line_not_provider_verified',
            'raw_text_stored' => false );
    }

    public static function set_manual_tracking_report( $value, $category = 'unknown', $command = 'NOT_IDENTIFIED' ) {
        $allowed = array( 'reported_error', 'reported_warning', 'reported_ok', 'reported_disabled' );
        if ( ! in_array( $value, $allowed, true ) ) { return false; }
        if ( ! in_array( $category, self::reported_tracking_categories(), true ) || ! in_array( $command, self::reported_tracking_commands(), true ) ) { return false; }
        // Fixed enums only; no raw native diagnostic text, key, value or credentials.
        set_transient( 'pfc_ocp_native_tracking_' . get_current_user_id(), array(
            'status' => $value, 'reported_at_utc' => gmdate( 'c' ),
            'operator_category' => $category, 'operator_command' => $command,
        ), DAY_IN_SECONDS );
        return true;
    }
    private static function manual_tracking_report() {
        if ( ! function_exists( 'get_transient' ) || ! function_exists( 'get_current_user_id' ) ) { return array(); }
        $report = get_transient( 'pfc_ocp_native_tracking_' . get_current_user_id() );
        return is_array( $report ) && in_array( $report['status'] ?? '', array( 'reported_error', 'reported_warning', 'reported_ok', 'reported_disabled' ), true ) ? $report : array();
    }

    /** Aggregate, anonymized group snapshots. No raw groups, keys or cache values persisted. */
    public static function record_group_snapshot( array $report ) {
        if ( 'complete' !== ( $report['status'] ?? '' ) ) { return array( 'status' => 'not_tested', 'rows' => array() ); }
        $rows = array();
        foreach ( array_slice( (array) ( $report['snapshot_rows'] ?? array() ), 0, 100 ) as $entry ) {
            if ( ! is_array( $entry ) || ! preg_match( '/^[a-f0-9]{16}$/D', (string) ( $entry['group_id'] ?? '' ) ) ) { continue; }
            $rows[ $entry['group_id'] ] = array( 'group_id' => $entry['group_id'], 'group' => $entry['group'], 'keys' => $entry['keys'], 'bytes' => $entry['bytes'] );
        }
        if ( ! $rows ) { return array( 'status' => 'empty', 'rows' => array() ); }
        $old = get_option( self::SNAPSHOT_OPTION, array() );
        $old = is_array( $old ) ? array_slice( $old, -self::MAX_GROUP_SNAPSHOTS + 1 ) : array();
        $previous = $old ? end( $old ) : null;
        $current = array( 'at' => time(), 'memory' => ! empty( $report['memory_included'] ), 'groups' => $rows );
        $changes = array();
        if ( is_array( $previous ) ) {
            foreach ( $rows as $id => $row ) {
                if ( ! isset( $previous['groups'][ $id ] ) ) { continue; }
                $oldrow = $previous['groups'][ $id ];
                $changes[] = array(
                    'group_id' => $id, 'group' => $row['group'],
                    'delta_keys' => is_numeric( $row['keys'] ) && is_numeric( $oldrow['keys'] ) ? $row['keys'] - $oldrow['keys'] : null,
                    'delta_bytes' => $current['memory'] && ! empty( $previous['memory'] ) && is_numeric( $row['bytes'] ) && is_numeric( $oldrow['bytes'] ) ? $row['bytes'] - $oldrow['bytes'] : null,
                    'keys_per_hour' => ( $current['at'] - (int) ( $previous['at'] ?? 0 ) ) >= 300 && is_numeric( $row['keys'] ) && is_numeric( $oldrow['keys'] ) ? round( ( $row['keys'] - $oldrow['keys'] ) * 3600 / ( $current['at'] - (int) ( $previous['at'] ?? 0 ) ), 1 ) : null,
                );
            }
            usort( $changes, static function( $a, $b ) { return abs( (int) ( $b['delta_bytes'] ?? $b['delta_keys'] ?? 0 ) ) <=> abs( (int) ( $a['delta_bytes'] ?? $a['delta_keys'] ?? 0 ) ); } );
        }
        $old[] = $current;
        update_option( self::SNAPSHOT_OPTION, $old, false );
        return array( 'status' => $previous ? 'compared' : 'baseline_recorded',
            'elapsed_seconds' => $previous ? max( 0, $current['at'] - (int) ( $previous['at'] ?? 0 ) ) : null,
            'groups_compared' => count( $changes ), 'rows' => array_slice( $changes, 0, 12 ),
            'note' => 'Differences between administrator-triggered inventory snapshots are not eviction rates or proof of a memory leak.' );
    }

    private static function health_checks( array $detection ) {
        $result = array( 'status' => $detection['status'], 'tracking' => array( 'status' => 'not_exposed' ) );
        if ( 'detected' !== $detection['status'] ) { return $result; }
        // Site Health tests are the only supported WordPress test registry.
        // Never call an arbitrary Object Cache Pro internal method by guessing a signature.
        $tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
        $direct = is_array( $tests ) ? (array) ( $tests['direct'] ?? array() ) : array();
        foreach ( array( 'objectcache_redis_tracking_keys', 'objectcache_tracking_keys', 'redis_tracking_keys' ) as $key ) {
            if ( ! isset( $direct[ $key ]['test'] ) || ! is_callable( $direct[ $key ]['test'] ) ) { continue; }
            try {
                $response = call_user_func( $direct[ $key ]['test'] );
                $state = is_array( $response ) ? (string) ( $response['status'] ?? '' ) : '';
                $valid = in_array( $state, array( 'good', 'recommended', 'critical' ), true );
                $result['tracking'] = array( 'status' => $valid ? $state : 'inconclusive', 'test_registered' => true,
                    'failure_category' => 'critical' === $state ? self::native_failure_category( $response['description'] ?? '' ) : 'not_applicable' );
            } catch ( Throwable $ignored ) { $result['tracking'] = array( 'status' => 'test_error', 'test_registered' => true ); }
            break;
        }
        return $result;
    }

    private static function error_class( $error ) {
        $msg = strtoupper( (string) $error );
        if ( false !== strpos( $msg, 'NOPERM' ) || false !== strpos( $msg, 'PERMISSION' ) ) { return 'acl_denied'; }
        if ( false !== strpos( $msg, 'NOAUTH' ) || false !== strpos( $msg, 'WRONGPASS' ) || false !== strpos( $msg, 'AUTH' ) ) { return 'auth'; }
        if ( false !== strpos( $msg, 'UNKNOWN COMMAND' ) || false !== strpos( $msg, 'DISABLED' ) ) { return 'unsupported_or_disabled'; }
        if ( false !== strpos( $msg, 'READONLY' ) ) { return 'read_only'; }
        return 'unknown';
    }
    private static function acl_probe( array $detection ) {
        $out = array( 'status' => 'not_run', 'tests' => array(), 'note' => 'Read-only checks; no ACL edits, flushes or key values.' );
        if ( 'detected' !== $detection['status'] ) { $out['status'] = 'not_applicable'; return $out; }
        if ( ! class_exists( 'Redis' ) ) { $out['status'] = 'phpredis_missing'; return $out; }
        $s = PFC_Cache_Diagnostics::connection_settings_for_tests();
        if ( 'complex' === $s['topology'] || '' === $s['host'] ) { $out['status'] = 'unsupported_connection'; return $out; }
        $redis = new Redis();
        try {
            if ( ! $redis->connect( $s['host'], $s['port'], 0.4 ) ) { throw new RuntimeException( 'connect failed' ); }
            if ( defined( 'Redis::OPT_READ_TIMEOUT' ) ) { $redis->setOption( Redis::OPT_READ_TIMEOUT, 0.4 ); }
            if ( $s['password'] !== null && $s['password'] !== '' ) {
                $auth = $s['username'] ? array( $s['username'], $s['password'] ) : $s['password'];
                if ( ! $redis->auth( $auth ) ) { throw new RuntimeException( 'auth failed' ); }
            }
            if ( $s['database'] && ! $redis->select( $s['database'] ) ) { throw new RuntimeException( 'select failed' ); }
            $checks = array(
                'PING' => static function ( $r ) { return $r->ping(); },
                'INFO' => static function ( $r ) { return $r->info( 'server' ); },
                'SCAN' => static function ( $r ) { $iterator = null; return $r->scan( $iterator, null, 1 ); },
                'ACL_WHOAMI' => static function ( $r ) { return $r->rawCommand( 'ACL', 'WHOAMI' ); },
                'CLIENT_TRACKINGINFO' => static function ( $r ) { return $r->rawCommand( 'CLIENT', 'TRACKINGINFO' ); },
            );
            foreach ( $checks as $name => $fn ) {
                try {
                    if ( method_exists( $redis, 'clearLastError' ) ) { $redis->clearLastError(); }
                    $value = $fn( $redis ); // Never store command response, usernames, keys, server info or errors.
                    $error = method_exists( $redis, 'getLastError' ) ? $redis->getLastError() : '';
                    $out['tests'][ $name ] = array( 'status' => $error ? self::error_class( $error ) : ( false === $value ? 'inconclusive' : 'allowed' ) );
                } catch ( Throwable $e ) { $out['tests'][ $name ] = array( 'status' => self::error_class( $e->getMessage() ) ); }
            }
            $out['status'] = 'complete';
        } catch ( Throwable $e ) { $out['status'] = self::error_class( $e->getMessage() ); }
        finally { try { $redis->close(); } catch ( Throwable $ignored ) {} }
        return $out;
    }

    /** Native REST group inventory only; no direct SCAN, no keys or values retained. */
    private static function groups( array $detected, $run, $with_memory ) {
        $r = array( 'status' => 'not_run', 'memory_included' => false, 'rows' => array(), 'snapshot_rows' => array(), 'omitted' => 0 );
        if ( ! $run ) { return $r; }
        if ( 'detected' !== $detected['status'] || ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) { $r['status'] = 'not_supported'; return $r; }
        if ( ! current_user_can( 'objectcache_manage' ) && ! current_user_can( 'manage_options' ) ) { $r['status'] = 'insufficient_permissions'; return $r; }
        try {
            $req = new WP_REST_Request( 'GET', '/objectcache/v1/groups' );
            if ( $with_memory ) { $req->set_param( 'memory', 1 ); }
            $response = rest_do_request( $req );
            if ( is_wp_error( $response ) ) { $r['status'] = 'request_error'; return $r; }
            $code = (int) $response->get_status();
            if ( $code >= 400 ) { $r['status'] = 401 === $code || 403 === $code ? 'permission_denied' : 'endpoint_error'; return $r; }
            $data = $response->get_data();
            if ( ! is_array( $data ) ) { $r['status'] = 'unexpected_response'; return $r; }
            foreach ( $data as $item ) {
                if ( ! is_array( $item ) ) { continue; }
                $name = (string) ( $item['group'] ?? '' );
                $public = in_array( $name, array( 'options', 'posts', 'post_meta', 'terms', 'term_meta', 'users', 'user_meta', 'default', 'transient', 'site-transient', 'site-options', 'site-lookup', 'comment', 'comments', 'counts' ), true ) ? $name : 'other';
                $entry = array(
                    'group' => $public,
                    'group_id' => substr( hash_hmac( 'sha256', $name, wp_salt( 'auth' ) ), 0, 16 ),
                    'keys' => isset( $item['keys'] ) && is_numeric( $item['keys'] ) ? max( 0, (int) $item['keys'] ) : null,
                    'bytes' => $with_memory && isset( $item['bytes'] ) && is_numeric( $item['bytes'] ) ? max( 0, (int) $item['bytes'] ) : null,
                );
                if ( count( $r['snapshot_rows'] ) < 100 ) { $r['snapshot_rows'][] = $entry; }
                if ( count( $r['rows'] ) < 25 ) { $r['rows'][] = $entry; } else { $r['omitted']++; }
            }
            $r['status'] = 'complete';
            $r['memory_included'] = (bool) $with_memory;
        } catch ( Throwable $ignored ) { $r['status'] = 'request_error'; }
        return $r;
    }

    public static function serialization_benchmark() {
        $data = array( 'name' => str_repeat( 'a', 512 ), 'items' => array_fill( 0, 30, array( 'id' => 12345, 'label' => 'Performance Console sample', 'active' => true ) ) );
        $result = array( 'iterations' => 25, 'payload' => 'synthetic', 'methods' => array(), 'compression' => array() );
        foreach ( array( 'php' => array( 'serialize', 'unserialize' ), 'igbinary' => array( 'igbinary_serialize', 'igbinary_unserialize' ) ) as $label => $pair ) {
            if ( ! function_exists( $pair[0] ) || ! function_exists( $pair[1] ) ) { $result['methods'][ $label ] = array( 'status' => 'unavailable' ); continue; }
            $times = array(); $size = null;
            for ( $i = 0; $i < 25; $i++ ) {
                $start = hrtime( true );
                $packed = call_user_func( $pair[0], $data );
                $roundtrip = call_user_func( $pair[1], $packed );
                $times[] = ( hrtime( true ) - $start ) / 1000000;
                $size = is_string( $packed ) ? strlen( $packed ) : null;
                if ( $roundtrip !== $data ) { break; }
            }
            sort( $times, SORT_NUMERIC );
            $result['methods'][ $label ] = array( 'status' => isset( $roundtrip ) && $roundtrip === $data ? 'ok' : 'mismatch', 'median_roundtrip_ms' => round( $times[(int)floor(count($times)/2)], 5 ), 'bytes' => $size );
        }
        $raw = serialize( $data );
        foreach ( array( 'lz4' => array( 'lz4_compress', 'lz4_uncompress' ), 'zlib' => array( 'gzcompress', 'gzuncompress' ) ) as $label => $pair ) {
            if ( ! function_exists( $pair[0] ) || ! function_exists( $pair[1] ) ) { $result['compression'][ $label ] = array( 'status' => 'unavailable' ); continue; }
            try {
                $times = array(); $size = 0; $verified = true;
                for ( $i = 0; $i < 25; $i++ ) {
                    $start = hrtime( true ); $c = call_user_func( $pair[0], $raw ); $decoded = call_user_func( $pair[1], $c );
                    $times[] = ( hrtime( true ) - $start ) / 1000000;
                    if ( $decoded !== $raw ) { $verified = false; break; }
                    $size = strlen( $c );
                }
                sort( $times, SORT_NUMERIC );
                $result['compression'][ $label ] = array( 'status' => $verified ? 'ok' : 'mismatch', 'median_roundtrip_ms' => round( $times[(int)floor(count($times)/2)], 5 ), 'bytes' => $size, 'source_bytes' => strlen( $raw ) );
            } catch ( Throwable $ignored ) { $result['compression'][ $label ] = array( 'status' => 'unavailable' ); }
        }
        return $result;
    }

    private static function recommendations( array $r ) {
        $advice = array();
        if ( 'detected' !== ( $r['detection']['status'] ?? '' ) ) { $advice[] = array( 'priority' => 'info', 'action' => 'verify_provider', 'message' => 'Object Cache Pro is not confirmed as the active drop-in; these checks do not diagnose a different provider.' ); return $advice; }
        $kt = $r['key_tracking_diagnostics']['status'] ?? 'needs_native_health_result';
        if ( in_array( $kt, array( 'native_error', 'reported_error' ), true ) ) {
            $advice[] = array( 'priority' => 'p0', 'action' => 'investigate_native_tracking', 'message' => 'Key Tracking reports an error. Compare Object Cache Pro Site Health Info and its native CLI diagnostics; do not infer the cause from CLIENT TRACKINGINFO denial or change ACLs automatically.' );
        } elseif ( 'needs_native_health_result' === $kt ) {
            $advice[] = array( 'priority' => 'p0', 'action' => 'check_native_tracking', 'message' => 'Key Tracking is not exposed through the current Site Health integration. Check Tools > Site Health > Info, or run the native Object Cache Pro CLI diagnostic and record the Key Tracking status only.' );
        }
        foreach ( (array) ( $r['acl_diagnostics']['tests'] ?? array() ) as $name => $row ) {
            if ( 'acl_denied' === ( $row['status'] ?? '' ) ) {
                $optional = in_array( $name, array( 'ACL_WHOAMI', 'CLIENT_TRACKINGINFO' ), true );
                $advice[] = array( 'priority' => $optional ? 'info' : 'p1', 'action' => 'review_acl',
                    'message' => $optional ? 'Optional Redis diagnostic command ' . $name . ' is restricted. This is not evidence that Object Cache Pro Key Tracking is broken and does not justify changing ACLs.' : 'Read-only Redis command ' . $name . ' was denied. Confirm whether native Object Cache Pro diagnostics require it before considering a minimal permission change.' );
            }
        }
        if ( ! empty( $r['cache_debug']['ocp_save_commands'] ) || ! empty( $r['cache_debug']['ocp_debug'] ) ) { $advice[] = array( 'priority' => 'p1', 'action' => 'review_debug', 'message' => 'Object Cache Pro debug or command logging appears enabled. Review configuration before disabling it, especially when active diagnostics are underway.' ); }
        if ( count( $r['flush_history'] ) >= 3 ) { $advice[] = array( 'priority' => 'p1', 'action' => 'review_flushes', 'message' => 'Several flush attempts were observed since this monitor was installed. Review caller evidence; hook observation does not prove a flush completed.' ); }
        $advice[] = array( 'priority' => 'p0', 'action' => 'profile_callbacks', 'message' => 'Use signed deep PHP profiling to attribute WordPress cache lookup counter changes to callback scopes. Per-operation Redis timings are unavailable without supported drop-in instrumentation.' );
        return array_slice( $advice, 0, 10 );
    }

    /** Deliberately allowlisted export: never copy raw OCP plugin diagnostics. */
    public static function export( array $source, array $profile = array() ) {
        $d = (array) ( $source['object_cache_pro'] ?? array() );
        $det = (array) ( $d['detection'] ?? array() );
        $acl = (array) ( $d['acl_diagnostics'] ?? array() );
        $group = (array) ( $d['cache_group_metrics'] ?? array() );
        $serial = (array) ( $d['serialization_benchmarks'] ?? array() );
        $out = array(
            'detection' => array(
                'status' => self::safe_status( $det['status'] ?? 'not_tested' ),
                'plugin_active' => isset( $det['plugin_active'] ) ? (bool) $det['plugin_active'] : null,
                'dropin_recognized' => isset( $det['dropin_recognized'] ) ? (bool) $det['dropin_recognized'] : null,
                'persistent_cache' => isset( $det['persistent_cache'] ) ? (bool) $det['persistent_cache'] : null,
            ),
            'health_checks' => array(
                'status' => self::safe_status( $d['health_checks']['status'] ?? 'not_tested' ),
                'tracking' => array( 'status' => self::safe_status( $d['health_checks']['tracking']['status'] ?? 'not_tested' ) ),
            ),
            'key_tracking_diagnostics' => array(
                'status' => self::safe_status( $d['key_tracking_diagnostics']['status'] ?? 'not_tested' ),
                'source' => self::safe_status( $d['key_tracking_diagnostics']['source'] ?? 'none' ),
                'confidence' => self::safe_status( $d['key_tracking_diagnostics']['confidence'] ?? 'unavailable' ),
                'failure_category' => self::safe_status( $d['key_tracking_diagnostics']['failure_category'] ?? 'unknown' ),
                'detail_level' => in_array( $d['key_tracking_diagnostics']['detail_level'] ?? '', array( 'status_only', 'unverified_detail_present' ), true ) ? $d['key_tracking_diagnostics']['detail_level'] : 'not_exposed',
                'configured_tracking' => isset( $d['key_tracking_diagnostics']['configured_tracking'] ) ? (bool) $d['key_tracking_diagnostics']['configured_tracking'] : null,
            ),
            'key_tracking_triage' => array(
                'status' => self::safe_status( $d['key_tracking_triage']['status'] ?? 'not_tested' ),
                'native_evidence' => ! empty( $d['key_tracking_triage']['native_evidence'] ),
                'reported_failure_category' => self::safe_status( $d['key_tracking_triage']['reported_failure_category'] ?? 'unknown' ),
                'provider_detected' => ! empty( $d['key_tracking_triage']['provider_detected'] ),
                'read_only_capabilities' => array(),
                'optional_acl_denials_are_causal_evidence' => false,
                'exact_failed_command_known' => false,
                'next_action' => self::safe_status( $d['key_tracking_triage']['next_action'] ?? 'unknown' ),
                'administrator_report' => array(
                    'category' => in_array( $d['key_tracking_triage']['administrator_report']['category'] ?? '', self::reported_tracking_categories(), true ) ? $d['key_tracking_triage']['administrator_report']['category'] : 'unknown',
                    'command' => in_array( $d['key_tracking_triage']['administrator_report']['command'] ?? '', self::reported_tracking_commands(), true ) ? $d['key_tracking_triage']['administrator_report']['command'] : 'NOT_IDENTIFIED',
                    'confidence' => isset( $d['key_tracking_triage']['administrator_report'] ) ? 'administrator_reported' : 'none',
                ),
            ),
            'acl_diagnostics' => array( 'status' => self::safe_status( $acl['status'] ?? 'not_tested' ), 'tests' => array() ),
            'cache_group_metrics' => array( 'status' => self::safe_status( $group['status'] ?? 'not_tested' ), 'memory_included' => ! empty( $group['memory_included'] ), 'rows' => array(), 'omitted' => max( 0, (int) ( $group['omitted'] ?? 0 ) ) ),
            'cache_group_growth' => array( 'status' => self::safe_status( $d['cache_group_growth']['status'] ?? 'not_tested' ), 'elapsed_seconds' => self::safe_number( $d['cache_group_growth']['elapsed_seconds'] ?? null ), 'rows' => array() ),
            'cache_call_attribution' => array( 'mode' => 'callback_scope_counters_not_individual_redis_calls', 'rows' => array(), 'populated_rows' => 0, 'coverage' => 'not_collected' ),
            'cache_overhead_breakdown' => array(
                'status' => 'comparison_only_not_exclusive_php_processing',
                'wp_api_get_median_ms' => self::safe_time( $source['object_cache']['get']['median_ms'] ?? null ),
                'direct_redis_get_median_ms' => self::safe_time( $source['redis']['get']['median_ms'] ?? null ),
            ),
            'slow_operations' => array(
                'status' => 'synthetic_only_not_production_slow_calls',
                'wp_api_get_max_ms' => self::safe_time( $source['object_cache']['get']['max_ms'] ?? null ),
                'wp_api_set_max_ms' => self::safe_time( $source['object_cache']['set']['max_ms'] ?? null ),
            ),
            'flush_history' => array(), 'flush_bursts' => array(),
            'flush_freshness' => array( 'attempts_last_10_minutes' => null, 'latest_event_age_seconds' => null, 'unverified_legacy_caller_rows' => 0, 'scope' => 'passive_hook_attempts_only' ),
            'serialization_benchmarks' => array( 'status' => $serial ? 'synthetic' : 'not_tested', 'iterations' => max( 0, min( 100, (int) ( $serial['iterations'] ?? 0 ) ) ), 'methods' => array(), 'compression' => array() ),
            'recommendations' => array(),
            'capabilities' => array(
                'operation_call_stacks' => 'not_available_without_supported_dropin_command_log',
                'per_key_sizes' => 'not_collected',
                'flush_observation' => 'passive_pre_flush_hooks_attempts_only',
            ),
            'cache_debug' => array(),
        );
        foreach ( array( 'PING', 'INFO', 'SCAN' ) as $name ) {
            $out['key_tracking_triage']['read_only_capabilities'][ $name ] = self::safe_status( $d['key_tracking_triage']['read_only_capabilities'][ $name ] ?? 'not_tested' );
        }
        foreach ( array( 'PING', 'INFO', 'SCAN', 'ACL_WHOAMI', 'CLIENT_TRACKINGINFO' ) as $name ) {
            $out['acl_diagnostics']['tests'][ $name ] = array( 'status' => self::safe_status( $acl['tests'][ $name ]['status'] ?? 'not_tested' ) );
        }
        foreach ( array_slice( (array) ( $group['rows'] ?? array() ), 0, 25 ) as $r ) {
            if ( ! is_array( $r ) ) { continue; }
            $groupid = (string) ( $r['group_id'] ?? '' );
            if ( ! preg_match( '/^[a-f0-9]{16}$/D', $groupid ) ) { continue; }
            $label = (string) ( $r['group'] ?? '' );
            $out['cache_group_metrics']['rows'][] = array( 'group' => in_array( $label, array( 'options', 'posts', 'post_meta', 'terms', 'term_meta', 'users', 'user_meta', 'default', 'transient', 'site-transient', 'site-options', 'site-lookup', 'comment', 'comments', 'counts' ), true ) ? $label : 'other', 'group_id' => $groupid, 'keys' => self::safe_number( $r['keys'] ?? null ), 'bytes' => self::safe_number( $r['bytes'] ?? null ) );
        }
        foreach ( array_slice( (array) ( $d['cache_group_growth']['rows'] ?? array() ), 0, 12 ) as $g ) {
            if ( ! is_array( $g ) || ! preg_match( '/^[a-f0-9]{16}$/D', (string) ( $g['group_id'] ?? '' ) ) ) { continue; }
            $out['cache_group_growth']['rows'][] = array( 'group_id' => $g['group_id'], 'group' => in_array( $g['group'] ?? '', array( 'options', 'posts', 'post_meta', 'terms', 'term_meta', 'users', 'user_meta', 'default', 'transient', 'site-transient', 'site-options' ), true ) ? $g['group'] : 'other', 'delta_keys' => self::safe_signed( $g['delta_keys'] ?? null ), 'delta_bytes' => self::safe_signed( $g['delta_bytes'] ?? null ), 'keys_per_hour' => self::safe_rate( $g['keys_per_hour'] ?? null ) );
        }
        foreach ( array_slice( (array) ( $d['flush_history'] ?? array() ), -20 ) as $h ) {
            if ( ! is_array( $h ) ) { continue; }
            $component = (string) ( $h['component'] ?? '' );
            if ( ! preg_match( '/^(plugin|theme):[a-z0-9_-]{1,70}$/iD', $component ) ) { $component = 'unknown'; }
            $caller = (string) ( $h['caller'] ?? '' );
            if ( 'unavailable' !== $caller && self::safe_caller_name( array( 'function' => $caller ) ) === 'unavailable' ) { $caller = 'unavailable'; }
            $groupid = (string) ( $h['group_id'] ?? '' );
            $out['flush_history'][] = array(
                'at_utc' => preg_match( '/^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9:]+\+00:00$/D', (string) ( $h['at_utc'] ?? '' ) ) ? $h['at_utc'] : null,
                'type' => 'group' === ( $h['type'] ?? '' ) ? 'group' : 'full',
                'component' => $component, 'caller' => $caller,
                'caller_confidence' => self::safe_status( $h['caller_confidence'] ?? 'none' ),
                'caller_source' => self::safe_flush_source( $h['caller_source'] ?? '' ),
                'group_id' => preg_match( '/^[a-f0-9]{16}$/D', $groupid ) ? $groupid : null,
                'execution' => 'attempt_observed_not_success_confirmed',
            );
        }
        // An earlier diagnostic may be exported much later. Recompute current
        // freshness from persisted event timestamps rather than reusing its age.
        $fresh = array_key_exists( 'flush_history', $d ) && is_array( $d['flush_history'] )
            ? self::flush_freshness( $d['flush_history'] )
            : (array) ( $d['flush_freshness'] ?? array() );
        $out['flush_freshness'] = array(
            'attempts_last_10_minutes' => self::safe_number( $fresh['attempts_last_10_minutes'] ?? null ),
            'latest_event_age_seconds' => self::safe_number( $fresh['latest_event_age_seconds'] ?? null ),
            'unverified_legacy_caller_rows' => self::safe_number( $fresh['unverified_legacy_caller_rows'] ?? null ),
            'scope' => 'passive_hook_attempts_only',
        );
        foreach ( array_slice( (array) ( $d['flush_bursts'] ?? array() ), 0, 8 ) as $burst ) {
            if ( ! is_array( $burst ) || ! preg_match( '/^[a-f0-9]{16}$/D', (string) ( $burst['group_id'] ?? '' ) ) ) { continue; }
            $component = (string) ( $burst['component'] ?? '' );
            if ( ! preg_match( '/^(plugin|theme):[a-z0-9_-]{1,70}$/iD', $component ) ) { $component = 'unknown'; }
            $out['flush_bursts'][] = array( 'component' => $component, 'group_id' => $burst['group_id'],
                'attempts' => min( 24, max( 0, (int) ( $burst['attempts'] ?? 0 ) ) ),
                'window_seconds' => min( 60, max( 0, (int) ( $burst['window_seconds'] ?? 0 ) ) ), 'completion' => 'not_confirmed' );
        }
        foreach ( array( 'methods' => array( 'php', 'igbinary' ), 'compression' => array( 'lz4', 'zlib' ) ) as $kind => $names ) {
            foreach ( $names as $name ) {
                $c = (array) ( $serial[ $kind ][ $name ] ?? array() );
                $out['serialization_benchmarks'][ $kind ][ $name ] = array( 'status' => self::safe_status( $c['status'] ?? 'unavailable' ), 'median_roundtrip_ms' => self::safe_time( $c['median_roundtrip_ms'] ?? null ), 'bytes' => self::safe_number( $c['bytes'] ?? null ) );
            }
        }
        foreach ( array( 'wp_debug', 'savequeries', 'ocp_debug', 'ocp_save_commands' ) as $name ) {
            $out['cache_debug'][ $name ] = isset( $d['cache_debug'][ $name ] ) ? (bool) $d['cache_debug'][ $name ] : null;
        }
        foreach ( array_slice( (array) ( $d['recommendations'] ?? array() ), 0, 10 ) as $rec ) {
            if ( ! is_array( $rec ) ) { continue; }
            $key = (string) ( $rec['action'] ?? '' );
            $allowed = array( 'verify_provider', 'check_native_tracking', 'investigate_native_tracking', 'review_acl', 'review_debug', 'review_flushes', 'profile_callbacks' );
            if ( ! in_array( $key, $allowed, true ) ) { continue; }
            $out['recommendations'][] = array( 'action' => $key, 'priority' => in_array( $rec['priority'] ?? '', array( 'p0', 'p1', 'info' ), true ) ? $rec['priority'] : 'info' );
        }
        foreach ( array_slice( (array) ( $profile['runs'] ?? array() ), 0, 5 ) as $i => $run ) {
            $list = (array) ( $run['callback_profile']['rows'] ?? array() );
            // Raw profiler data uses cache_get_lookups/inclusive_ms, not the
            // renamed keys in PFC_AI_Export::callback_profile(). Support both
            // representations for earlier saved profiles and regression fixtures.
            usort( $list, static function ( $a, $b ) {
                return (int) ( $b['cache_get_lookups'] ?? $b['cache_get_lookups_during_scope'] ?? 0 )
                    <=> (int) ( $a['cache_get_lookups'] ?? $a['cache_get_lookups_during_scope'] ?? 0 );
            } );
            foreach ( array_slice( $list, 0, 10 ) as $item ) {
                $component = (string) ( $item['component'] ?? '' );
                if ( ! preg_match( '/^(plugin|theme):[a-z0-9_-]{1,70}$/iD', $component ) && 'core:wordpress' !== $component ) { $component = 'unattributed'; }
                $hook = (string) ( $item['hook'] ?? '' );
                if ( ! in_array( $hook, array( 'wp', 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'the_content', 'template_redirect', 'bricks/frontend/render_data', 'bricks/frontend/render_element', 'bricks/element/render' ), true ) ) { $hook = 'other'; }
                $out['cache_call_attribution']['rows'][] = array( 'run' => $i + 1, 'component' => $component, 'hook' => $hook, 'calls' => self::safe_number( $item['calls'] ?? null ), 'scope_get_lookups' => self::safe_number( $item['cache_get_lookups'] ?? $item['cache_get_lookups_during_scope'] ?? null ), 'scope_inclusive_php_ms' => self::safe_time( $item['inclusive_ms'] ?? $item['inclusive_php_ms'] ?? null ) );
            }
        }
        $populated = 0;
        foreach ( $out['cache_call_attribution']['rows'] as $row ) {
            if ( null !== $row['scope_get_lookups'] && null !== $row['scope_inclusive_php_ms'] ) { $populated++; }
        }
        $out['cache_call_attribution']['populated_rows'] = $populated;
        $out['cache_call_attribution']['coverage'] = $populated ? 'measured_callback_scopes' : 'not_collected';
        return $out;
    }
    private static function safe_flush_source( $source ) {
        $source = (string) $source;
        if ( strlen( $source ) > 180 || false !== strpos( $source, '..' ) ) { return 'unavailable'; }
        return preg_match( '~^(?:plugins|themes)/[A-Za-z0-9_./-]{1,165}$~D', $source ) ? $source : 'unavailable';
    }
    private static function safe_time( $value ) { return is_numeric( $value ) ? round( max( 0, min( 10000000, (float) $value ) ), 5 ) : null; }
    private static function safe_number( $value ) { return is_numeric( $value ) ? max( 0, min( 2000000000000, (int) $value ) ) : null; }
    private static function safe_signed( $value ) {
        return is_numeric( $value ) && is_finite( (float) $value ) ? max( -1000000000000, min( 1000000000000, (int) $value ) ) : null;
    }

    private static function safe_rate( $value ) {
        return is_numeric( $value ) && is_finite( (float) $value )
            ? round( max( -1000000000, min( 1000000000, (float) $value ) ), 1 ) : null;
    }

    private static function safe_status( $value ) {
        $allowed = array( 'detected', 'unconfirmed', 'not_detected', 'not_tested', 'not_available', 'not_run', 'not_supported', 'not_applicable', 'not_exposed', 'needs_native_health_result', 'good', 'recommended', 'critical', 'inconclusive', 'test_error', 'unknown', 'allowed', 'acl_denied', 'auth', 'read_only', 'unsupported_or_disabled', 'phpredis_missing', 'unsupported_connection', 'complete', 'request_error', 'endpoint_error', 'permission_denied', 'insufficient_permissions', 'unexpected_response', 'ok', 'unavailable', 'mismatch', 'synthetic', 'not_collected', 'native_ok', 'native_error', 'native_warning', 'native_disabled_or_unavailable', 'native_inconclusive', 'reported_error', 'reported_warning', 'reported_ok', 'reported_disabled', 'wordpress_site_health_info', 'registered_wordpress_site_health_test', 'administrator_reported_site_health', 'native_health', 'user_reported', 'none', 'not_exposed', 'baseline_recorded', 'compared', 'empty', 'read_error', 'authentication', 'unsupported_command', 'connection_or_timeout', 'memory_pressure', 'native_error_reason_not_exposed', 'native_error_category_observed', 'no_confirmed_native_error', 'inspect_native_diagnostics_for_exact_failure', 'confirm_native_failed_operation_before_changes', 'monitor_native_status', 'native_argument_free_trace', 'bounded_runtime_trace', 'administrator_reported', 'other' );
        return in_array( $value, $allowed, true ) ? $value : 'unknown';
    }

}
