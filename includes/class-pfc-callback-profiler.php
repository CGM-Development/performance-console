<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicit, signed-request-only callback timing.
 *
 * This is a bounded instrumented sample, not a transparent PHP profiler.
 * Wrapping can affect callback identity in uncommon code, so the feature is
 * never enabled on ordinary traffic. Reference-taking / reference-returning
 * callbacks are not wrapped. No callback arguments or return values are saved.
 */
final class PFC_Callback_Profiler {
    const MAX_WRAPPED = 240;
    const MAX_ROWS = 240;
    const MAX_PER_HOOK = 65;
    private static $started = false;
    private static $wrapping = false;
    private static $origins = array();
    private static $rows = array();
    private static $stack = array();
    private static $wrapped = 0;
    private static $skipped_reference = 0;
    private static $skipped_unknown = 0;
    private static $skipped_limit = 0;
    private static $wrapped_by_hook = array();
    private static $bookkeeping_ms = 0.0;
    private static $setup_ms = 0.0;
    private static $fired_hooks = array();

    public static function hooks() {
        return array(
            'wp', 'template_redirect', 'wp_enqueue_scripts', 'wp_head', 'wp_footer',
            'the_content', 'pre_get_posts', 'bricks/frontend/render_data',
            'bricks/frontend/render_element', 'bricks/element/render',
            'bricks/posts/query_vars',
        );
    }

    public static function init() {
        if ( self::$started || ! defined( 'PFC_DEEP_DIAGNOSTIC' ) || ! PFC_DEEP_DIAGNOSTIC ||
            ! defined( 'PFC_PHP_CALLBACK_DIAGNOSTIC' ) || ! PFC_PHP_CALLBACK_DIAGNOSTIC ||
            ( defined( 'PFC_SAVE_DIAGNOSTIC' ) && PFC_SAVE_DIAGNOSTIC ) ) { return; }
        self::$started = true;
        foreach ( self::hooks() as $hook ) {
            // A filter that returns its original first argument is safe for both
            // apply_filters() and do_action(); an action sentinel returning null
            // would corrupt a filter value.
            add_filter( $hook, array( __CLASS__, 'instrument_hook' ), PHP_INT_MIN, 1 );
        }
    }

    public static function instrument_hook( $value = null ) {
        $hook = current_filter();
        if ( self::$wrapping || ! in_array( $hook, self::hooks(), true ) ) { return $value; }
        self::$fired_hooks[ $hook ] = ( self::$fired_hooks[ $hook ] ?? 0 ) + 1;
        if ( self::$wrapped >= self::MAX_WRAPPED || count( self::$rows ) >= self::MAX_ROWS ) { ++self::$skipped_limit; return $value; }
        global $wp_filter;
        if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) { return $value; }
        $began = microtime( true );
        self::$wrapping = true;
        try {
            foreach ( $wp_filter[ $hook ]->callbacks as $priority => &$callbacks ) {
                foreach ( $callbacks as $id => &$registered ) {
                    $callback = $registered['function'] ?? null;
                    if ( ! is_callable( $callback ) || self::own_callback( $callback ) || isset( self::$origins[ $hook ][ $priority ][ $id ] ) ) { continue; }
                    if ( self::$wrapped >= self::MAX_WRAPPED || (int) ( self::$wrapped_by_hook[ $hook ] ?? 0 ) >= self::MAX_PER_HOOK ) { ++self::$skipped_limit; continue; }
                    $definition = self::describe( $callback );
                    if ( ! $definition ) { ++self::$skipped_unknown; continue; }
                    if ( $definition['reference'] ) { ++self::$skipped_reference; continue; }
                    $key = md5( $hook . '|' . $priority . '|' . (string) $id );
                    $definition['hook'] = $hook;
                    $definition['priority'] = (int) $priority;
                    self::$origins[ $hook ][ $priority ][ $id ] = $key;
                    self::$rows[ $key ] = $definition + array(
                        'calls' => 0, 'inclusive_ms' => 0.0, 'self_ms' => 0.0,
                        'max_ms' => 0.0, 'cache_get_lookups' => null,
                    );
                    $registered['function'] = static function ( ...$args ) use ( $callback, $key ) {
                        PFC_Callback_Profiler::begin( $key );
                        try {
                            return call_user_func_array( $callback, $args );
                        } finally {
                            // Only diagnostic bookkeeping is swallowed; any
                            // exception thrown by the original callback escapes.
                            try { PFC_Callback_Profiler::finish( $key ); } catch ( Throwable $ignored ) {}
                        }
                    };
                    ++self::$wrapped;
                    self::$wrapped_by_hook[ $hook ] = (int) ( self::$wrapped_by_hook[ $hook ] ?? 0 ) + 1;
                    if ( count( self::$rows ) >= self::MAX_ROWS ) { break 2; }
                }
                unset( $registered );
            }
            unset( $callbacks );
        } finally {
            self::$wrapping = false;
            self::$setup_ms += ( microtime( true ) - $began ) * 1000;
        }
        return $value;
    }

    private static function own_callback( $cb ) {
        if ( is_array( $cb ) && isset( $cb[0] ) ) {
            return __CLASS__ === $cb[0] || ( is_object( $cb[0] ) && $cb[0] instanceof self ) ||
                'PFC_Profiler' === $cb[0] || 'PFC_Bricks_Inspector' === $cb[0];
        }
        return is_string( $cb ) && ( 0 === strpos( $cb, __CLASS__ . '::' ) || 0 === strpos( $cb, 'PFC_Profiler::' ) || 0 === strpos( $cb, 'PFC_Bricks_Inspector::' ) );
    }

    private static function describe( $cb ) {
        try {
            if ( is_array( $cb ) ) {
                $ref = new ReflectionMethod( $cb[0], $cb[1] );
                $label = ( is_object( $cb[0] ) ? get_class( $cb[0] ) : $cb[0] ) . '::' . $cb[1];
            } elseif ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
                $ref = new ReflectionMethod( $cb );
                $label = $cb;
            } elseif ( is_object( $cb ) && ! $cb instanceof Closure ) {
                $ref = new ReflectionMethod( $cb, '__invoke' );
                $label = get_class( $cb ) . '::__invoke';
            } else {
                $ref = new ReflectionFunction( $cb );
                $label = $ref->isClosure() ? 'closure' : $ref->getName();
            }
            $by_ref = $ref->returnsReference();
            foreach ( $ref->getParameters() as $p ) { if ( $p->isPassedByReference() ) { $by_ref = true; break; } }
            $file = (string) $ref->getFileName();
            $owner = PFC_Utils::component_from_file( $file );
            $component = sanitize_key( (string) $owner['type'] ) . ':' . sanitize_key( (string) $owner['slug'] );
            $relative = '';
            $path = str_replace( '\\', '/', $file );
            $base = str_replace( '\\', '/', WP_CONTENT_DIR ) . '/';
            if ( 0 === strpos( $path, $base ) ) { $relative = substr( $path, strlen( $base ) ); }
            // Never store filesystem paths outside wp-content or arbitrary names.
            if ( ! preg_match( '~^(plugins|themes|mu-plugins)/[a-zA-Z0-9_./-]{1,200}$~D', $relative ) ) { $relative = ''; }
            return array(
                'component' => $component,
                'callback' => sanitize_text_field( substr( $label, 0, 150 ) ),
                'file' => $relative,
                'line' => max( 0, (int) $ref->getStartLine() ),
                'reference' => $by_ref,
            );
        } catch ( Throwable $e ) { return null; }
    }

    public static function begin( $key ) {
        $started = microtime( true );
        $cache = PFC_Request_Cache::snapshot();
        self::$stack[] = array( 'key' => $key, 'at' => $started, 'child_ms' => 0.0,
            'cache_start' => $cache, 'child_lookups' => 0 );
        // Scope entry is tied to the actual callback invocation, not just the
        // presence of a Bricks filename somewhere in a PHP stack trace.
        if ( isset( self::$rows[ $key ] ) ) {
            $row = self::$rows[ $key ];
            if ( ( $row['component'] ?? '' ) === 'theme:bricks' && class_exists( 'PFC_Bricks_Inspector', false ) ) {
                if ( ( $row['file'] ?? '' ) === 'themes/bricks/includes/assets.php' && ( $row['hook'] ?? '' ) === 'wp_enqueue_scripts' ) {
                    PFC_Bricks_Inspector::enter_initialization_scope( 'assets' );
                } elseif ( ( $row['file'] ?? '' ) === 'themes/bricks/includes/elements.php' && ( $row['hook'] ?? '' ) === 'wp' ) {
                    PFC_Bricks_Inspector::enter_initialization_scope( 'elements' );
                }
            }
        }
        self::$bookkeeping_ms += ( microtime( true ) - $started ) * 1000;
    }

    public static function finish( $key ) {
        $finished = microtime( true );
        $cache = PFC_Request_Cache::snapshot();
        $frame = array_pop( self::$stack );
        if ( ! is_array( $frame ) || $key !== $frame['key'] || ! isset( self::$rows[ $key ] ) ) { return; }
        $owner = self::$rows[ $key ];
        if ( ( $owner['component'] ?? '' ) === 'theme:bricks' && class_exists( 'PFC_Bricks_Inspector', false ) ) {
            if ( ( $owner['file'] ?? '' ) === 'themes/bricks/includes/assets.php' && ( $owner['hook'] ?? '' ) === 'wp_enqueue_scripts' ) {
                PFC_Bricks_Inspector::exit_initialization_scope( 'assets' );
            } elseif ( ( $owner['file'] ?? '' ) === 'themes/bricks/includes/elements.php' && ( $owner['hook'] ?? '' ) === 'wp' ) {
                PFC_Bricks_Inspector::exit_initialization_scope( 'elements' );
            }
        }
        $inclusive = max( 0.0, ( $finished - $frame['at'] ) * 1000 );
        $exclusive = max( 0.0, $inclusive - $frame['child_ms'] );
        $delta = PFC_Request_Cache::delta( (array) $frame['cache_start'], $cache );
        $lookups = null !== $delta['lookups'] ? max( 0, (int) $delta['lookups'] - $frame['child_lookups'] ) : null;
        $row = &self::$rows[ $key ];
        ++$row['calls'];
        $row['inclusive_ms'] += $inclusive;
        $row['self_ms'] += $exclusive;
        $row['max_ms'] = max( $row['max_ms'], $inclusive );
        if ( null !== $lookups ) { $row['cache_get_lookups'] = (int) ( $row['cache_get_lookups'] ?? 0 ) + $lookups; }
        unset( $row );
        $parent = count( self::$stack ) - 1;
        if ( $parent >= 0 ) {
            self::$stack[ $parent ]['child_ms'] += $inclusive;
            if ( null !== $delta['lookups'] ) { self::$stack[ $parent ]['child_lookups'] += (int) $delta['lookups']; }
        }
        self::$bookkeeping_ms += ( microtime( true ) - $finished ) * 1000;
    }

    public static function summary() {
        $rows = array_values( array_filter( self::$rows, static function ( $r ) { return $r['calls'] > 0; } ) );
        usort( $rows, static function ( $a, $b ) { return $b['self_ms'] <=> $a['self_ms']; } );
        $out = array();
        foreach ( array_slice( $rows, 0, 40 ) as $row ) {
            unset( $row['reference'] );
            foreach ( array( 'inclusive_ms', 'self_ms', 'max_ms' ) as $metric ) { $row[ $metric ] = round( $row[ $metric ], 3 ); }
            $out[] = $row;
        }
        return array(
            'enabled' => self::$started,
            'scope' => 'allowlisted-callbacks-only',
            'hooks_fired' => count( self::$fired_hooks ),
            'callbacks_wrapped' => self::$wrapped,
            'wrapped_by_hook' => self::$wrapped_by_hook,
            'reference_callbacks_skipped' => self::$skipped_reference,
            'unresolved_callbacks_skipped' => self::$skipped_unknown,
            'callbacks_skipped_limit' => self::$skipped_limit,
            'bookkeeping_lower_bound_ms' => round( self::$bookkeeping_ms + self::$setup_ms, 3 ),
            'rows' => $out,
            'truncated' => count( $rows ) > 40 || self::$skipped_limit > 0,
            'note' => 'Only allowlisted hook callbacks are timed; inclusive times overlap for nested callbacks. Self times remove timed nested callbacks only. Cache GET counters measured during a callback can include uninstrumented nested work and are NOT Redis calls or Redis latency. This instrumentation adds overhead; bookkeeping is only a lower-bound estimate.',
        );
    }
}
