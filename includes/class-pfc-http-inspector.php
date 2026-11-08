<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Opt-in HTTP callsite and rate-limit evidence. Never persists URLs, domain
 * names, query strings, headers, request bodies, or complete stack traces.
 */
final class PFC_HTTP_Inspector {
    /**
     * Group requests by METHOD + host + path only. Query parameters are never
     * included; this is a *target group*, NOT proof of identical/retried calls.
     * A site-specific secret prevents offline lookup of common URLs.
     */
    public static function target_group( $url, $method, $secret = '' ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( '' === $host ) { return null; }
        if ( '' === $secret ) {
            $secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
        }
        if ( '' === $secret ) { return null; }
        return substr( hash_hmac( 'sha256', strtoupper( (string) $method ) . "\0" . $host . "\0" . $path, $secret ), 0, 16 );
    }

    public static function parent_hook( array $stack ) {
        foreach ( array_reverse( $stack ) as $name ) {
            if ( ! is_string( $name ) || in_array( $name, array( 'http_request_args', 'http_api_debug', 'pre_http_request', 'all' ), true ) ) { continue; }
            $hook = PFC_SQL_Inspector::safe_hook( $name );
            if ( 'unavailable' !== $hook ) { return $hook; }
        }
        return 'unavailable';
    }

    public static function start_context( $url, array $args, array $trace, array $stack = array(), $secret = '' ) {
        $method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $local = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $site = PFC_SQL_Inspector::from_trace( $trace, self::parent_hook( $stack ) );
        return array(
            'target_group' => self::target_group( $url, $method, $secret ),
            'target_type' => '' === $host ? 'unknown' : ( '' !== $local && $host === $local ? 'same-site' : 'external' ),
            'site' => self::safe_site( $site ),
        );
    }

    /** Strict sanitization for persisted and exported call-site metadata. */
    public static function safe_site( $site ) {
        $site = is_array( $site ) ? $site : array();
        $file = (string) ( $site['file'] ?? '' );
        if ( strlen( $file ) > 240 || ! preg_match( '~^(plugins|mu-plugins|themes)/[a-zA-Z0-9_./@-]+\.(?:php|inc)$~D', $file ) || false !== strpos( $file, '..' ) ) { $file = ''; }
        $callable = (string) ( $site['callable'] ?? 'unknown' );
        if ( 'unknown' !== $callable && 'unknown' === PFC_SQL_Inspector::safe_callable( '', $callable ) ) { $callable = 'unknown'; }
        return array(
            'file' => $file,
            'line' => '' === $file ? 0 : max( 0, (int) ( $site['line'] ?? 0 ) ),
            'callable' => $callable,
            'hook' => PFC_SQL_Inspector::safe_hook( $site['hook'] ?? '' ),
            'attributed' => '' !== $file,
        );
    }

    /** Sanitize an individual recorded event. No URLs, exceptions, or secrets. */
    public static function safe_event( array $row ) {
        $component = (array) ( $row['component'] ?? array() );
        $type = (string) ( $component['type'] ?? 'core' );
        $slug = (string) ( $component['slug'] ?? 'unknown' );
        if ( ! in_array( $type, array( 'plugin', 'theme', 'mu-plugin', 'core' ), true ) ) { $type = 'core'; }
        if ( ! preg_match( '/^[a-z0-9_-]{1,80}$/D', $slug ) ) { $slug = 'unknown'; }
        $target = (string) ( $row['target_group'] ?? '' );
        return array(
            'component' => $type . ':' . $slug,
            'target_group_id' => preg_match( '/^[a-f0-9]{16}$/D', $target ) ? $target : null,
            'target_type' => in_array( $row['target_type'] ?? '', array( 'external', 'same-site', 'unknown' ), true ) ? $row['target_type'] : 'unknown',
            'method' => in_array( strtoupper( (string) ( $row['method'] ?? 'GET' ) ), array( 'GET','POST','PUT','PATCH','DELETE','HEAD' ), true ) ? strtoupper( (string) ( $row['method'] ?? 'GET' ) ) : 'OTHER',
            'blocking' => ! empty( $row['blocking'] ),
            'duration_ms' => round( max( 0, min( 600000, (float) ( $row['ms'] ?? 0 ) ) ), 2 ),
            'status' => isset( $row['status'] ) && (int) $row['status'] >= 100 && (int) $row['status'] <= 599 ? (int) $row['status'] : null,
            'failed' => ! empty( $row['failed'] ) || ! empty( $row['error'] ) || empty( $row['status'] ) || (int) ( $row['status'] ?? 0 ) >= 400,
            'site' => self::safe_site( $row['site'] ?? array() ),
        );
    }

    /**
     * Compact per-request groups. A group may contain different query strings;
     * the report must never call them definite retries.
     */
    public static function summary( array $events ) {
        $groups = array();
        foreach ( array_slice( $events, 0, 100 ) as $raw ) {
            if ( ! is_array( $raw ) ) { continue; }
            $r = self::safe_event( $raw );
            $s = $r['site'];
            $key = implode( '|', array( $r['component'], $r['target_group_id'] ?: 'unknown', $r['method'], $s['file'], $s['line'], $s['callable'], $s['hook'] ) );
            if ( ! isset( $groups[ $key ] ) ) {
                if ( count( $groups ) >= 40 ) { continue; }
                $groups[ $key ] = array(
                    'component' => $r['component'], 'target_group_id' => $r['target_group_id'],
                    'target_type' => $r['target_type'], 'method' => $r['method'],
                    'source' => $s['file'] ?: null, 'line' => $s['line'],
                    'function' => $s['callable'], 'hook' => $s['hook'],
                    'calls' => 0, 'blocking_calls' => 0, 'failed_calls' => 0,
                    'rate_limited_429' => 0, 'no_status_failures' => 0,
                    'total_ms' => 0, 'slowest_ms' => 0,
                );
            }
            $g =& $groups[ $key ];
            $g['calls']++;
            if ( $r['blocking'] ) { $g['blocking_calls']++; }
            if ( $r['failed'] ) { $g['failed_calls']++; }
            if ( 429 === $r['status'] ) { $g['rate_limited_429']++; }
            if ( null === $r['status'] && $r['failed'] ) { $g['no_status_failures']++; }
            $g['total_ms'] += $r['duration_ms'];
            $g['slowest_ms'] = max( $g['slowest_ms'], $r['duration_ms'] );
            unset( $g );
        }
        $out = array_values( $groups );
        usort( $out, static function ( $a, $b ) { return ( $b['rate_limited_429'] * 10000 + $b['total_ms'] ) <=> ( $a['rate_limited_429'] * 10000 + $a['total_ms'] ); } );
        foreach ( $out as &$group ) {
            $group['total_ms'] = round( $group['total_ms'], 2 );
            $group['slowest_ms'] = round( $group['slowest_ms'], 2 );
        }
        unset( $group );
        return array_slice( $out, 0, 20 );
    }

    /** All external export fields explicitly enumerated; no direct untrusted row copy. */
    public static function export_safe( array $groups ) {
        $out = array();
        foreach ( array_slice( $groups, 0, 15 ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $site = self::safe_site( array(
                'file' => $row['source'] ?? '', 'line' => $row['line'] ?? 0,
                'callable' => $row['function'] ?? 'unknown', 'hook' => $row['hook'] ?? '',
            ) );
            $comp = (string) ( $row['component'] ?? '' );
            $target = (string) ( $row['target_group_id'] ?? '' );
            $method = (string) ( $row['method'] ?? '' );
            $out[] = array(
                'component' => preg_match( '/^(core|plugin|theme|mu-plugin):[a-z0-9_-]{1,80}$/D', $comp ) ? $comp : 'core:unknown',
                'target_group_id' => preg_match( '/^[a-f0-9]{16}$/D', $target ) ? $target : null,
                'target_type' => in_array( $row['target_type'] ?? '', array( 'external', 'same-site', 'unknown' ), true ) ? $row['target_type'] : 'unknown',
                'method' => in_array( $method, array( 'GET','POST','PUT','PATCH','DELETE','HEAD','OTHER' ), true ) ? $method : 'OTHER',
                'source' => $site['file'] ?: null, 'line' => $site['line'],
                'function' => $site['callable'], 'hook' => $site['hook'],
                'calls' => max( 0, (int) ( $row['calls'] ?? 0 ) ),
                'blocking_calls' => max( 0, (int) ( $row['blocking_calls'] ?? 0 ) ),
                'failed_calls' => max( 0, (int) ( $row['failed_calls'] ?? 0 ) ),
                'rate_limited_429' => max( 0, (int) ( $row['rate_limited_429'] ?? 0 ) ),
                'no_status_failures' => max( 0, (int) ( $row['no_status_failures'] ?? 0 ) ),
                'total_ms' => round( max( 0, (float) ( $row['total_ms'] ?? 0 ) ), 2 ),
                'slowest_ms' => round( max( 0, (float) ( $row['slowest_ms'] ?? 0 ) ), 2 ),
            );
        }
        return $out;
    }
}
