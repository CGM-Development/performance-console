<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded and redacted SQL call-site attribution for signed deep profiles.
 * Never accepts SQL text, arguments, URLs or absolute filesystem paths in its
 * stored/exported summaries. Raw traces live only until profiler shutdown.
 */
final class PFC_SQL_Inspector {
    private const MAX_SITES_PER_PATTERN = 6;

    /**
     * Identify the source statement and its owning PHP callable from a query
     * filter backtrace. The frame with the plugin file is often wpdb::get_var;
     * the following stack frame contains the plugin method which invoked it.
     */
    public static function from_trace( array $trace, $hook = '' ) {
        $site = array( 'file' => '', 'line' => 0, 'callable' => 'unknown', 'hook' => self::safe_hook( $hook ), 'attributed' => false );
        foreach ( array_slice( $trace, 0, 32 ) as $i => $frame ) {
            if ( ! is_array( $frame ) || empty( $frame['file'] ) ) { continue; }
            $path = (string) $frame['file'];
            $component = PFC_Utils::component_from_file( $path );
            if ( 'core' === $component['type'] || ( 'plugin' === $component['type'] && 'performance-console' === $component['slug'] ) ) { continue; }
            $relative = self::relative_source( $path );
            if ( '' === $relative ) { continue; }
            $site['file'] = $relative;
            $site['line'] = max( 0, (int) ( $frame['line'] ?? 0 ) );
            $site['attributed'] = true;
            // Pick the next actual caller; the current frame's function is
            // frequently wpdb::query/get_results, not the plugin method.
            foreach ( array_slice( $trace, $i + 1, 3 ) as $caller ) {
                if ( ! is_array( $caller ) ) { continue; }
                $method = self::safe_callable( (string) ( $caller['class'] ?? '' ), (string) ( $caller['function'] ?? '' ) );
                if ( 'unknown' !== $method && ! preg_match( '/^(wpdb|WP_Hook|PFC_SQL_Inspector)(::|->)/i', $method ) ) {
                    $site['callable'] = $method;
                    break;
                }
            }
            break;
        }
        return $site;
    }

    /** Only component-relative, public-source code locations, no server roots. */
    public static function relative_source( $file ) {
        $file = wp_normalize_path( (string) $file );
        $roots = array(
            'plugins/' => wp_normalize_path( WP_PLUGIN_DIR ),
            'mu-plugins/' => defined( 'WPMU_PLUGIN_DIR' ) ? wp_normalize_path( WPMU_PLUGIN_DIR ) : '',
            'themes/' => wp_normalize_path( get_theme_root() ),
        );
        foreach ( $roots as $label => $root ) {
            $root = rtrim( (string) $root, '/' );
            if ( '' !== $root && 0 === strpos( $file, $root . '/' ) ) {
                $relative = substr( $file, strlen( $root ) + 1 );
                if ( '' !== $relative && ! preg_match( '~(?:^|/)\.\.?(/|$)~', $relative ) ) {
                    return self::safe_relative( $label . $relative );
                }
            }
        }
        return '';
    }

    private static function safe_relative( $path ) {
        $path = (string) $path;
        if ( strlen( $path ) > 240 || ! preg_match( '~^(plugins|mu-plugins|themes)/[a-zA-Z0-9_./@-]+\.(?:php|inc)$~D', $path ) || false !== strpos( $path, '..' ) ) { return ''; }
        return $path;
    }

    public static function safe_callable( $class, $function ) {
        $class = trim( (string) $class );
        $function = trim( (string) $function );
        if ( '' === $function || strlen( $function ) > 150 || strlen( $class ) > 150 ) { return 'unknown'; }
        $name = $class ? $class . '::' . $function : $function;
        // Function names are static identifiers; never accept runtime arguments.
        return preg_match( '/^[a-zA-Z_\\\\\x80-\xff][a-zA-Z0-9_\\\\:\x80-\xff{}-]{0,239}$/D', $name ) ? $name : 'unknown';
    }

    public static function safe_hook( $hook ) {
        $hook = (string) $hook;
        return '' !== $hook && strlen( $hook ) <= 100 && preg_match( '~^[a-zA-Z0-9_./:-]+$~D', $hook ) ? $hook : 'unavailable';
    }

    /**
     * Aggregate at shutdown, after SQL execution time is known. Multiple
     * callsites for the same normalized query remain separate.
     */
    public static function add_site( array &$pattern, array $site, $ms ) {
        if ( empty( $site['attributed'] ) || empty( $site['file'] ) ) {
            $pattern['unattributed_count'] = (int) ( $pattern['unattributed_count'] ?? 0 ) + 1;
            return;
        }
        $key = md5( implode( '|', array( $site['file'], $site['line'], $site['callable'], $site['hook'] ) ) );
        if ( ! isset( $pattern['sites'] ) ) { $pattern['sites'] = array(); }
        if ( ! isset( $pattern['sites'][ $key ] ) ) {
            if ( count( $pattern['sites'] ) >= self::MAX_SITES_PER_PATTERN ) {
                $pattern['sites_dropped'] = (int) ( $pattern['sites_dropped'] ?? 0 ) + 1;
                return;
            }
            $pattern['sites'][ $key ] = array( 'file' => $site['file'], 'line' => $site['line'], 'callable' => $site['callable'], 'hook' => $site['hook'], 'count' => 0, 'total_ms' => 0, 'max_ms' => 0 );
        }
        $row =& $pattern['sites'][ $key ];
        $row['count']++;
        $row['total_ms'] += max( 0, (float) $ms );
        $row['max_ms'] = max( $row['max_ms'], (float) $ms );
    }

    /** Maximum 40 patterns, 6 callsites each; no query text or absolute paths. */
    public static function summary( array $patterns ) {
        uasort( $patterns, static function ( $a, $b ) {
            $a_score = max( (float) ( $a['total'] ?? 0 ), (float) ( $a['count'] ?? 0 ) * 0.5 );
            $b_score = max( (float) ( $b['total'] ?? 0 ), (float) ( $b['count'] ?? 0 ) * 0.5 );
            return $b_score <=> $a_score;
        } );
        $out = array();
        foreach ( array_slice( $patterns, 0, 40, true ) as $hash => $pattern ) {
            if ( ! preg_match( '/^[a-f0-9]{32}$/D', (string) $hash ) ) { continue; }
            $component = (array) ( $pattern['component'] ?? array() );
            $sites = array_values( (array) ( $pattern['sites'] ?? array() ) );
            usort( $sites, static function ( $a, $b ) { return (float) $b['total_ms'] <=> (float) $a['total_ms']; } );
            $out[] = array(
                'pattern_id' => substr( $hash, 0, 12 ),
                'component' => sanitize_key( (string) ( $component['type'] ?? 'core' ) ) . ':' . sanitize_key( (string) ( $component['slug'] ?? 'wordpress' ) ),
                'count' => max( 0, (int) ( $pattern['count'] ?? 0 ) ),
                'total_ms' => round( max( 0, (float) ( $pattern['total'] ?? 0 ) ), 2 ),
                'sites' => array_map( static function ( $s ) {
                    return array( 'file' => (string) $s['file'], 'line' => (int) $s['line'], 'callable' => (string) $s['callable'], 'hook' => (string) $s['hook'], 'count' => (int) $s['count'], 'total_ms' => round( (float) $s['total_ms'], 2 ), 'max_ms' => round( (float) $s['max_ms'], 2 ) );
                }, array_slice( $sites, 0, self::MAX_SITES_PER_PATTERN ) ),
                'unattributed_count' => (int) ( $pattern['unattributed_count'] ?? 0 ),
                'other_sites_count' => (int) ( $pattern['sites_dropped'] ?? 0 ),
            );
        }
        return $out;
    }

    /** Strict allowlist at the AI boundary: only code identifiers and relative paths. */
    public static function export_safe( array $rows ) {
        $out = array();
        foreach ( array_slice( $rows, 0, 20 ) as $row ) {
            if ( ! is_array( $row ) || ! preg_match( '/^[a-f0-9]{12}$/D', (string) ( $row['pattern_id'] ?? '' ) ) ) { continue; }
            $sites = array();
            foreach ( array_slice( (array) ( $row['sites'] ?? array() ), 0, 3 ) as $site ) {
                if ( ! is_array( $site ) ) { continue; }
                $file = self::safe_relative( (string) ( $site['file'] ?? '' ) );
                if ( '' === $file ) { continue; }
                $function = (string) ( $site['callable'] ?? 'unknown' );
                if ( 'unknown' !== $function && 'unknown' === self::safe_callable( '', $function ) ) { $function = 'unknown'; }
                $sites[] = array(
                    'source' => $file,
                    'line' => max( 0, (int) ( $site['line'] ?? 0 ) ),
                    'function' => $function,
                    'hook' => self::safe_hook( $site['hook'] ?? '' ),
                    'calls' => max( 0, (int) ( $site['count'] ?? 0 ) ),
                    'sql_ms' => round( max( 0, (float) ( $site['total_ms'] ?? 0 ) ), 2 ),
                );
            }
            $out[] = array(
                'pattern_id' => $row['pattern_id'],
                'component' => preg_match( '/^(plugin|theme|mu-plugin|core):[a-z0-9_-]{1,80}$/D', (string) ( $row['component'] ?? '' ) ) ? $row['component'] : 'core:unknown',
                'calls' => max( 0, (int) ( $row['count'] ?? 0 ) ),
                'sql_ms' => round( max( 0, (float) ( $row['total_ms'] ?? 0 ) ), 2 ),
                'call_sites' => $sites,
                'unattributed_calls' => max( 0, (int) ( $row['unattributed_count'] ?? 0 ) ),
                'other_callsites_omitted' => max( 0, (int) ( $row['other_sites_count'] ?? 0 ) ),
            );
        }
        return $out;
    }
}
