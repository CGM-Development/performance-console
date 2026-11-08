<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bootstrap waterfall.
 *
 * Answers "where did the time before the first byte go". Two honest halves:
 *
 * 1. Measured phase deltas captured by the profiler between WordPress lifecycle
 *    hooks, from the MU bootstrap to wp_footer.
 * 2. The static footprint each plugin and theme contributes to the request:
 *    how many PHP files it includes and how many bytes they occupy.
 *
 * There is deliberately no per-plugin include timing. PHP has no hook that
 * reports how long another plugin's require took, and measuring it with an
 * include wrapper cannot observe code that is not ours. The waterfall therefore
 * separates measured phase time from static footprint, and never adds the two
 * together or presents footprint as duration.
 */
final class PFC_Bootstrap_Timeline {
    const ORDER = array(
        'mu_plugin_bootstrap', 'muplugins_loaded', 'plugins_loaded', 'setup_theme', 'after_setup_theme',
        'init', 'wp_loaded', 'wp', 'template_redirect', 'wp_head', 'wp_footer',
    );

    const LABELS = array(
        'muplugins_loaded'   => 'MU plugins loaded',
        'plugins_loaded'     => 'Plugins loaded',
        'setup_theme'        => 'Setup theme',
        'after_setup_theme'  => 'After setup theme',
        'init'               => 'Init',
        'wp_loaded'          => 'WP loaded',
        'wp'                 => 'Main query',
        'template_redirect'  => 'Template redirect',
        'wp_head'            => 'wp_head',
        'wp_footer'          => 'wp_footer',
    );

    const MAX_COMPONENTS = 60;

    /**
     * Turn the profiler's phase-delta map into an ordered waterfall.
     *
     * @param array $phases Map of "from_to" => milliseconds.
     * @return array{steps:array,total_ms:float,largest:array|null}
     */
    public static function from_phases( array $phases ) {
        $steps = array();
        foreach ( $phases as $key => $ms ) {
            $parts = explode( '_to_', (string) $key );
            if ( 2 !== count( $parts ) ) { continue; }
            $from = $parts[0];
            $to = $parts[1];
            $from_index = array_search( $from, self::ORDER, true );
            $to_index = array_search( $to, self::ORDER, true );
            if ( false === $from_index || false === $to_index || $to_index <= $from_index ) { continue; }
            $steps[] = array(
                'from'  => $from,
                'to'    => $to,
                'label' => self::LABELS[ $to ] ?? $to,
                'ms'    => round( max( 0, (float) $ms ), 2 ),
                'order' => $to_index,
            );
        }
        usort( $steps, static function ( $a, $b ) { return (int) $a['order'] <=> (int) $b['order']; } );
        $total = 0.0;
        foreach ( $steps as $step ) { $total += (float) $step['ms']; }
        $largest = null;
        $with_share = array();
        foreach ( $steps as $step ) {
            $step['share'] = $total > 0 ? round( 100 * (float) $step['ms'] / $total, 1 ) : 0.0;
            if ( null === $largest || $step['ms'] > $largest['ms'] ) { $largest = $step; }
            $with_share[] = $step;
        }
        return array(
            'steps'    => $with_share,
            'total_ms' => round( $total, 2 ),
            'largest'  => $largest,
        );
    }

    /**
     * Static cost of the loaded code, grouped by plugin, theme and core.
     *
     * @param array|null    $files      Defaults to the current request's includes.
     * @param callable|null $classifier Maps a path to component_from_file()'s shape.
     */
    public static function footprint( array $files = null, $classifier = null ) {
        if ( null === $files ) {
            $files = function_exists( 'get_included_files' ) ? (array) get_included_files() : array();
        }
        if ( ! is_callable( $classifier ) ) {
            $classifier = array( 'PFC_Utils', 'component_from_file' );
        }
        $components = array();
        $total_bytes = 0;
        foreach ( $files as $file ) {
            if ( ! is_string( $file ) || '' === $file ) { continue; }
            $component = call_user_func( $classifier, $file );
            if ( ! is_array( $component ) ) { continue; }
            $type = sanitize_key( (string) ( $component['type'] ?? 'core' ) );
            $slug = (string) ( $component['slug'] ?? '' );
            if ( '' === $slug ) { $slug = 'unknown'; }
            $key = $type . ':' . $slug;
            if ( ! isset( $components[ $key ] ) ) {
                $components[ $key ] = array( 'type' => $type, 'slug' => $slug, 'files' => 0, 'bytes' => 0 );
            }
            $components[ $key ]['files']++;
            if ( is_readable( $file ) ) {
                $size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( is_int( $size ) && $size > 0 ) {
                    $components[ $key ]['bytes'] += $size;
                    $total_bytes += $size;
                }
            }
        }
        uasort( $components, static function ( $a, $b ) { return (int) $b['bytes'] <=> (int) $a['bytes']; } );
        $out = array_values( $components );
        $truncated = count( $out ) > self::MAX_COMPONENTS;
        if ( $truncated ) { $out = array_slice( $out, 0, self::MAX_COMPONENTS ); }
        $total_files = 0;
        foreach ( $out as $component ) { $total_files += (int) $component['files']; }
        return array(
            'components'  => $out,
            'totals'      => array( 'files' => $total_files, 'bytes' => $total_bytes ),
            'truncated'   => $truncated,
            'measured'    => 'static_includes',
        );
    }

    /**
     * Newest deep run payload, which carries the phases and footprint recorded
     * on the profiled request rather than on this admin request.
     */
    public static function latest_deep_payload() {
        global $wpdb;
        $rows = $wpdb->get_col( 'SELECT payload FROM ' . PFC_Utils::table( 'runs' ) . " WHERE mode='sample' AND payload IS NOT NULL ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( (array) $rows as $payload ) {
            $decoded = json_decode( (string) $payload, true );
            if ( ! is_array( $decoded ) ) { continue; }
            if ( ! empty( $decoded['deep'] ) && ! empty( $decoded['phases'] ) ) { return $decoded; }
        }
        return array();
    }

    /** Full report: measured waterfall plus the recorded static footprint. */
    public static function report( array $payload = array() ) {
        if ( ! $payload ) { $payload = self::latest_deep_payload(); }
        $waterfall = self::from_phases( (array) ( $payload['phases'] ?? array() ) );
        $footprint = (array) ( $payload['plugin_footprint'] ?? array() );
        return array(
            'waterfall'      => $waterfall,
            'footprint'      => $footprint,
            'measurement'    => (string) ( $payload['measurement_scope'] ?? '' ),
            'included_files' => (int) ( $payload['included_files'] ?? 0 ),
            'available'      => ! empty( $waterfall['steps'] ),
        );
    }
}
