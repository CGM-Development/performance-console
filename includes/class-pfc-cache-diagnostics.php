<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * On-demand, bounded diagnostics. Never runs on public traffic or scheduled scans.
 * No cache values, Redis credentials, option values or exception details are logged.
 */
final class PFC_Cache_Diagnostics {
    const SAMPLE_COUNT = 12;
    const MAX_SECONDS = 6;

    public static function run() {
        $started = microtime( true );
        $result = array(
            'at' => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
            'persistent' => (bool) wp_using_ext_object_cache(),
            'configuration' => self::configuration(),
            'object_cache' => self::benchmark_object_cache( $started ),
            'redis' => array( 'status' => 'unavailable', 'reason' => 'Direct Redis measurement not attempted.' ),
            'autoload' => self::autoload(),
        );
        if ( microtime( true ) - $started < self::MAX_SECONDS - 1 ) {
            $result['redis'] = self::benchmark_redis( $started );
        } else {
            $result['redis']['reason'] = 'The bounded object-cache test used the measurement budget.';
        }
        $result['recommendations'] = self::recommendations( $result );
        return $result;
    }

    private static function configuration() {
        $host = defined( 'XSPEED_OC_HOST' ) ? (string) XSPEED_OC_HOST : ( defined( 'WP_REDIS_HOST' ) ? (string) WP_REDIS_HOST : '' );
        return array(
            'provider' => defined( 'XSPEED_OC_BACKEND' ) ? 'xSpeed' : ( defined( 'WP_REDIS_HOST' ) ? 'WP_REDIS' : 'unidentified' ),
            'local_host' => '' !== $host ? in_array( strtolower( $host ), array( '127.0.0.1', 'localhost', '::1' ), true ) : null,
            'persistent_connection' => defined( 'XSPEED_OC_PERSISTENT' ) ? (bool) XSPEED_OC_PERSISTENT : ( defined( 'WP_REDIS_PERSISTENT' ) ? (bool) WP_REDIS_PERSISTENT : null ),
            'phpredis_available' => class_exists( 'Redis' ),
        );
    }

    /** Unique keys prevent repeated keys from biasing this short WordPress API test. */
    private static function benchmark_object_cache( $started ) {
        $metrics = array( 'set' => array(), 'get' => array(), 'delete' => array() );
        $failures = 0;
        $completed = 0;
        for ( $i = 0; $i < self::SAMPLE_COUNT && microtime( true ) - $started < self::MAX_SECONDS - 1; $i++ ) {
            $key = 'pfc-benchmark-' . wp_generate_uuid4();
            $value = 'pfc:' . wp_generate_uuid4();
            $t = microtime( true );
            $ok = wp_cache_set( $key, $value, 'pfc-diagnostics', 45 );
            $metrics['set'][] = ( microtime( true ) - $t ) * 1000;
            $found = false;
            $t = microtime( true );
            $read = wp_cache_get( $key, 'pfc-diagnostics', true, $found );
            $metrics['get'][] = ( microtime( true ) - $t ) * 1000;
            $t = microtime( true );
            wp_cache_delete( $key, 'pfc-diagnostics' );
            $metrics['delete'][] = ( microtime( true ) - $t ) * 1000;
            if ( ! $ok || ! $found || $read !== $value ) { $failures++; }
            $completed++;
        }
        return array(
            'description' => 'WordPress cache API latency (includes PHP and drop-in overhead; may use an in-process cache)',
            'samples' => $completed,
            'failed' => $failures,
            'set' => self::summary( $metrics['set'] ),
            'get' => self::summary( $metrics['get'] ),
            'delete' => self::summary( $metrics['delete'] ),
        );
    }

    private static function benchmark_redis( $started ) {
        if ( ! class_exists( 'Redis' ) ) {
            return array( 'status' => 'unavailable', 'reason' => 'The PhpRedis extension is not installed. WordPress API measurements remain available.' );
        }
        $host = defined( 'XSPEED_OC_HOST' ) ? (string) XSPEED_OC_HOST : ( defined( 'WP_REDIS_HOST' ) ? (string) WP_REDIS_HOST : '' );
        if ( '' === $host ) {
            return array( 'status' => 'unavailable', 'reason' => 'No supported Redis host constant was found. No guessed connection was attempted.' );
        }
        $port = defined( 'XSPEED_OC_PORT' ) ? (int) XSPEED_OC_PORT : ( defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379 );
        $database = defined( 'XSPEED_OC_DATABASE' ) ? (int) XSPEED_OC_DATABASE : ( defined( 'WP_REDIS_DATABASE' ) ? (int) WP_REDIS_DATABASE : 0 );
        $password = defined( 'XSPEED_OC_PASSWORD' ) ? XSPEED_OC_PASSWORD : ( defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : null );
        $username = defined( 'XSPEED_OC_USERNAME' ) ? XSPEED_OC_USERNAME : ( defined( 'WP_REDIS_USERNAME' ) ? WP_REDIS_USERNAME : null );
        $connection = new Redis();
        try {
            $t = microtime( true );
            if ( ! $connection->connect( $host, $port, 0.5 ) ) { throw new RuntimeException( 'connection' ); }
            if ( defined( 'Redis::OPT_READ_TIMEOUT' ) ) { $connection->setOption( Redis::OPT_READ_TIMEOUT, 0.5 ); }
            $connect_ms = ( microtime( true ) - $t ) * 1000;
            if ( null !== $password && '' !== $password ) {
                $auth = $username ? array( (string) $username, (string) $password ) : $password;
                if ( ! $connection->auth( $auth ) ) { throw new RuntimeException( 'authentication' ); }
            }
            if ( 0 !== $database && ! $connection->select( $database ) ) { throw new RuntimeException( 'database' ); }
            $t = microtime( true );
            $connection->ping();
            $ping_ms = ( microtime( true ) - $t ) * 1000;
            $samples = array( 'set' => array(), 'get' => array(), 'delete' => array() );
            $failures = 0;
            for ( $i = 0; $i < self::SAMPLE_COUNT && microtime( true ) - $started < self::MAX_SECONDS; $i++ ) {
                $key = 'pfc:diagnostic:' . wp_generate_uuid4();
                $value = 'probe-' . wp_generate_uuid4();
                $t = microtime( true );
                $ok = $connection->setex( $key, 45, $value );
                $samples['set'][] = ( microtime( true ) - $t ) * 1000;
                $t = microtime( true );
                $read = $connection->get( $key );
                $samples['get'][] = ( microtime( true ) - $t ) * 1000;
                $t = microtime( true );
                $connection->del( $key );
                $samples['delete'][] = ( microtime( true ) - $t ) * 1000;
                if ( ! $ok || $read !== $value ) { $failures++; }
            }
            $info = array();
            if ( microtime( true ) - $started < self::MAX_SECONDS ) {
                try {
                    $raw = $connection->info();
                    foreach ( array( 'connected_clients', 'blocked_clients', 'used_memory', 'maxmemory', 'evicted_keys', 'keyspace_hits', 'keyspace_misses', 'instantaneous_ops_per_sec' ) as $field ) {
                        if ( isset( $raw[ $field ] ) && is_numeric( $raw[ $field ] ) ) { $info[ $field ] = (int) $raw[ $field ]; }
                    }
                } catch ( Throwable $ignored ) { /* INFO may be prohibited by ACL. */ }
            }
            return array(
                'status' => 'ok', 'connection_ms' => round( $connect_ms, 3 ), 'ping_ms' => round( $ping_ms, 3 ),
                'samples' => count( $samples['get'] ), 'failed' => $failures,
                'set' => self::summary( $samples['set'] ), 'get' => self::summary( $samples['get'] ), 'delete' => self::summary( $samples['delete'] ),
                'server_counters' => $info,
            );
        } catch ( Throwable $ignored ) {
            // Never expose exception content, hostnames, passwords or socket paths.
            return array( 'status' => 'unavailable', 'reason' => 'Direct Redis probe could not connect or authenticate. Check the drop-in configuration and PhpRedis ACLs.' );
        } finally {
            try { $connection->close(); } catch ( Throwable $ignored ) {}
        }
    }

    private static function summary( array $values ) {
        if ( ! $values ) { return array( 'n' => 0, 'median_ms' => null, 'p95_ms' => null, 'max_ms' => null ); }
        sort( $values, SORT_NUMERIC );
        $count = count( $values );
        $middle = (int) floor( $count / 2 );
        $median = $count % 2 ? $values[ $middle ] : ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
        return array( 'n' => $count, 'median_ms' => round( $median, 3 ), 'p95_ms' => round( $values[ (int) ceil( 0.95 * $count ) - 1 ], 3 ), 'max_ms' => round( $values[ $count - 1 ], 3 ) );
    }

    private static function autoload() {
        global $wpdb;
        $where = "autoload IN ('yes','on','auto-on','auto')";
        $total = $wpdb->get_row( "SELECT COUNT(*) AS option_count, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE {$where}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE {$where} ORDER BY bytes DESC LIMIT 15", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array( 'bytes' => (int) ( $total['bytes'] ?? 0 ), 'count' => (int) ( $total['option_count'] ?? 0 ), 'largest' => (array) $rows );
    }

    private static function recommendations( array $report ) {
        $advice = array();
        $wp = $report['object_cache'];
        $raw = $report['redis'];
        if ( empty( $report['persistent'] ) ) { $advice[] = 'WordPress is not reporting a persistent object cache. Verify the drop-in and connection first.'; }
        if ( ! empty( $wp['failed'] ) || ! empty( $raw['failed'] ) ) { $advice[] = 'A benchmark read-back failed. Check cache connectivity and errors before drawing latency conclusions.'; }
        if ( 'ok' === $raw['status'] && ( $raw['get']['median_ms'] ?? 0 ) >= 10 ) {
            $advice[] = 'Direct Redis GET latency is unusually high. Inspect Redis CPU, blocked clients, server load and network path.';
        } elseif ( 'ok' === $raw['status'] && ( $wp['get']['median_ms'] ?? 0 ) > 5 * max( 0.2, (float) ( $raw['get']['median_ms'] ?? 0 ) ) ) {
            $advice[] = 'The WordPress cache API is notably slower than a direct Redis GET. Review drop-in processing, serialization, PHP overhead and whether each operation actually reaches Redis.';
        } elseif ( 'unavailable' === $raw['status'] ) {
            $advice[] = 'Only WordPress-level timings are available, so Redis command latency cannot be isolated from PHP and object-cache overhead.';
        }
        if ( false === ( $report['configuration']['persistent_connection'] ?? null ) ) {
            $advice[] = 'Persistent Redis connections are disabled. Connection setup may add request overhead. Benchmark a staged change before enabling them in production.';
        }
        if ( ( $report['autoload']['bytes'] ?? 0 ) > 800 * 1024 ) {
            $advice[] = 'Autoloaded options exceed 800 KB. Review the largest options for unnecessary per-request loading; do not delete or disable autoload without verifying consumers.';
        }
        foreach ( (array) ( $report['autoload']['largest'] ?? array() ) as $row ) {
            if ( (int) ( $row['bytes'] ?? 0 ) >= 1048576 ) {
                $advice[] = 'Large autoloaded option: ' . sanitize_text_field( $row['option_name'] ) . ' (' . size_format( (int) $row['bytes'] ) . '). Check its owner and actual usage before changing autoload.';
                break;
            }
        }
        if ( ! $advice ) { $advice[] = 'No obvious single-operation bottleneck was found. Profile representative uncached frontend requests and compare query counts, cache calls and total PHP time.'; }
        return $advice;
    }
}
