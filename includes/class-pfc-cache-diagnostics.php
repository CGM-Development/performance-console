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


    /**
     * Resolve common Redis drop-in settings without ever adding credentials or
     * connection strings to a report. Does not guess endpoints.
     */
    private static function connection_settings() {
        $config = array();
        $provider = 'unidentified';
        if ( defined( 'XSPEED_OC_BACKEND' ) ) {
            $provider = 'xSpeed';
        } elseif ( defined( 'OBJECTCACHE_CONFIG' ) && is_array( OBJECTCACHE_CONFIG ) ) {
            $provider = 'OBJECTCACHE_CONFIG';
            $config = OBJECTCACHE_CONFIG;
        } elseif ( defined( 'WP_REDIS_CONFIG' ) && is_array( WP_REDIS_CONFIG ) ) {
            $provider = 'WP_REDIS_CONFIG';
            $config = WP_REDIS_CONFIG;
        } elseif ( defined( 'WP_REDIS_HOST' ) ) {
            $provider = 'WP_REDIS constants';
        }

        // A single raw connection cannot accurately represent a clustered
        // or sentinel-backed WordPress cache. Skip rather than misdiagnose it.
        $topology = 'single';
        if ( ! empty( $config['cluster'] ) || ! empty( $config['clusters'] ) || ! empty( $config['servers'] ) || ! empty( $config['sentinels'] ) || ! empty( $config['replication'] ) ||
            ( defined( 'WP_REDIS_CLUSTER' ) && WP_REDIS_CLUSTER ) ||
            ( defined( 'WP_REDIS_SERVERS' ) && WP_REDIS_SERVERS ) ) {
            $topology = 'complex';
        }

        $host = (string) ( $config['path'] ?? $config['socket'] ?? $config['host'] ?? '' );
        $scheme = strtolower( (string) ( $config['scheme'] ?? ( defined( 'WP_REDIS_SCHEME' ) ? WP_REDIS_SCHEME : 'tcp' ) ) );
        $port = (int) ( $config['port'] ?? ( defined( 'XSPEED_OC_PORT' ) ? XSPEED_OC_PORT : ( defined( 'WP_REDIS_PORT' ) ? WP_REDIS_PORT : 6379 ) ) );
        $db = (int) ( $config['database'] ?? ( defined( 'XSPEED_OC_DATABASE' ) ? XSPEED_OC_DATABASE : ( defined( 'WP_REDIS_DATABASE' ) ? WP_REDIS_DATABASE : 0 ) ) );
        $password = $config['password'] ?? ( defined( 'XSPEED_OC_PASSWORD' ) ? XSPEED_OC_PASSWORD : ( defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : null ) );
        $username = $config['username'] ?? ( defined( 'XSPEED_OC_USERNAME' ) ? XSPEED_OC_USERNAME : ( defined( 'WP_REDIS_USERNAME' ) ? WP_REDIS_USERNAME : null ) );

        if ( isset( $config['url'] ) && is_string( $config['url'] ) && '' !== $config['url'] ) {
            $parsed = parse_url( $config['url'] );
            if ( is_array( $parsed ) ) {
                $scheme = strtolower( (string) ( $parsed['scheme'] ?? $scheme ) );
                if ( ! empty( $parsed['host'] ) ) { $host = (string) $parsed['host']; }
                if ( isset( $parsed['port'] ) ) { $port = (int) $parsed['port']; }
                if ( isset( $parsed['user'] ) ) { $username = rawurldecode( $parsed['user'] ); }
                if ( isset( $parsed['pass'] ) ) { $password = rawurldecode( $parsed['pass'] ); }
                if ( ! empty( $parsed['path'] ) && preg_match( '~^/\\d+$~', $parsed['path'] ) ) {
                    $db = (int) substr( $parsed['path'], 1 );
                }
                if ( ! empty( $parsed['query'] ) ) {
                    $query = array();
                    parse_str( $parsed['query'], $query );
                    if ( isset( $query['database'] ) && is_numeric( $query['database'] ) ) { $db = (int) $query['database']; }
                }
            }
        }
        if ( '' === $host ) {
            $host = defined( 'XSPEED_OC_HOST' ) ? (string) XSPEED_OC_HOST : ( defined( 'WP_REDIS_HOST' ) ? (string) WP_REDIS_HOST : '' );
        }
        if ( '' === $host && defined( 'WP_REDIS_PATH' ) ) { $host = (string) WP_REDIS_PATH; }

        if ( preg_match( '~^(tcp|redis|tls|rediss)://~i', $host, $parts ) ) {
            $scheme = strtolower( $parts[1] );
            $host = preg_replace( '~^(tcp|redis|tls|rediss)://~i', '', $host );
        }
        if ( 'unix' === $scheme || ( '' !== $host && '/' === substr( $host, 0, 1 ) ) ) {
            $scheme = 'unix';
            $port = 0;
        } elseif ( in_array( $scheme, array( 'rediss', 'tls' ), true ) ) {
            $scheme = 'tls';
            if ( '' !== $host ) { $host = 'tls://' . $host; }
        } else {
            $scheme = 'tcp';
        }
        $persistent = $config['persistent'] ?? ( defined( 'XSPEED_OC_PERSISTENT' ) ? XSPEED_OC_PERSISTENT : ( defined( 'WP_REDIS_PERSISTENT' ) ? WP_REDIS_PERSISTENT : null ) );
        $host_display = preg_replace( '~^tls://~', '', $host );
        return array(
            'provider' => $provider,
            'topology' => $topology,
            'transport' => $scheme,
            'host' => $host,
            'port' => $port,
            'database' => max( 0, $db ),
            'username' => $username,
            'password' => $password,
            'local_host' => in_array( strtolower( $host_display ), array( 'localhost', '127.0.0.1', '::1' ), true ),
            'persistent' => null === $persistent ? null : (bool) $persistent,
        );
    }

    private static function configuration() {
        $s = self::connection_settings();
        return array(
            'provider' => $s['provider'],
            'transport' => $s['transport'],
            'topology' => $s['topology'],
            'local_host' => $s['local_host'],
            'persistent_connection' => $s['persistent'],
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


    /** Raw Redis benchmark using the detected standalone connection configuration. */
    private static function benchmark_redis( $started ) {
        if ( ! class_exists( 'Redis' ) ) {
            return array( 'status' => 'unavailable', 'stage' => 'client', 'reason' => 'PhpRedis is unavailable, so a direct command comparison cannot be made.' );
        }
        $s = self::connection_settings();
        if ( 'complex' === $s['topology'] ) {
            return array( 'status' => 'unavailable', 'stage' => 'topology', 'reason' => 'Redis clustering, replication or Sentinel was detected; a single direct connection would be misleading.' );
        }
        if ( '' === $s['host'] ) {
            return array( 'status' => 'unavailable', 'stage' => 'configuration', 'reason' => 'No supported standalone Redis endpoint was found in the WordPress configuration.' );
        }
        $connection = new Redis();
        $stage = 'connect';
        try {
            $t = microtime( true );
            if ( ! $connection->connect( $s['host'], $s['port'], 0.5 ) ) { throw new RuntimeException( 'connect' ); }
            if ( defined( 'Redis::OPT_READ_TIMEOUT' ) ) { $connection->setOption( Redis::OPT_READ_TIMEOUT, 0.5 ); }
            $connect_ms = ( microtime( true ) - $t ) * 1000;
            $stage = 'auth';
            if ( null !== $s['password'] && '' !== $s['password'] ) {
                $auth = $s['username'] ? array( (string) $s['username'], (string) $s['password'] ) : $s['password'];
                if ( ! $connection->auth( $auth ) ) { throw new RuntimeException( 'auth' ); }
            }
            $stage = 'database';
            if ( 0 !== $s['database'] && ! $connection->select( $s['database'] ) ) { throw new RuntimeException( 'select' ); }
            $stage = 'ping';
            $t = microtime( true );
            $connection->ping();
            $ping_ms = ( microtime( true ) - $t ) * 1000;
            $samples = array( 'set' => array(), 'get' => array(), 'delete' => array() );
            $failures = 0;
            $stage = 'commands';
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
                'set' => self::summary( $samples['set'] ), 'get' => self::summary( $samples['get'] ),
                'delete' => self::summary( $samples['delete'] ), 'server_counters' => $info,
            );
        } catch ( Throwable $ignored ) {
            // No raw Redis exception text, credentials, or endpoint details are returned.
            $safe_reasons = array(
                'connect' => 'Redis endpoint could not be reached using the detected transport.',
                'auth' => 'Redis authentication failed. Check the authentication configuration or ACL username.',
                'database' => 'The configured Redis database could not be selected.',
                'ping' => 'Redis refused PING. Verify that authentication is complete and the ACL permits this command.',
                'commands' => 'The direct temporary-key commands failed or were blocked by the Redis ACL.',
            );
            return array( 'status' => 'unavailable', 'stage' => $stage, 'reason' => $safe_reasons[ $stage ] ?? 'Redis request failed.' );
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
