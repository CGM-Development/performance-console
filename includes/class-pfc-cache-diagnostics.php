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

    /** Internal-only connection settings, never serialized into a report. */
    public static function connection_settings_for_tests() { return self::connection_settings(); }

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


    /**
     * Use the cache provider's configured Redis namespace when testing temporary
     * keys. ACLs can allow ~site:* while denying arbitrary pfc:diagnostic:* keys.
     * Return only the source of the namespace to the UI, never its raw value.
     */
    private static function key_namespace() {
        // Object Cache Pro may specify a different prefix in WP_REDIS_CONFIG.
        // Give the provider's explicit prefix precedence over fallback salts.
        foreach ( array( 'WP_REDIS_CONFIG', 'OBJECTCACHE_CONFIG' ) as $constant ) {
            if ( defined( $constant ) && is_array( constant( $constant ) ) && ! empty( constant( $constant )['prefix'] ) && is_string( constant( $constant )['prefix'] ) ) {
                return array( 'prefix' => constant( $constant )['prefix'], 'source' => $constant . ' prefix' );
            }
        }
        foreach ( array( 'WP_REDIS_PREFIX', 'WP_CACHE_KEY_SALT' ) as $constant ) {
            if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
                return array( 'prefix' => constant( $constant ), 'source' => $constant );
            }
        }
        return array( 'prefix' => '', 'source' => 'none' );
    }

    /** Translate error types into safe, finite categories; never store raw Redis errors. */
    private static function failure_kind( $message ) {
        $message = strtoupper( (string) $message );
        if ( false !== strpos( $message, 'NOPERM' ) || false !== strpos( $message, 'NO PERMISSION' ) || false !== strpos( $message, 'PERMISSION DENIED' ) ) { return 'acl'; }
        if ( false !== strpos( $message, 'READONLY' ) ) { return 'read_only'; }
        if ( false !== strpos( $message, 'OOM ' ) || false !== strpos( $message, 'OUT OF MEMORY' ) ) { return 'memory_limit'; }
        if ( false !== strpos( $message, 'UNKNOWN COMMAND' ) ) { return 'command_disabled'; }
        if ( false !== strpos( $message, 'NOAUTH' ) || false !== strpos( $message, 'WRONGPASS' ) ) { return 'auth'; }
        if ( false !== strpos( $message, 'MOVED ' ) || false !== strpos( $message, 'ASK ' ) || false !== strpos( $message, 'CLUSTERDOWN' ) ) { return 'cluster'; }
        if ( false !== strpos( $message, 'TIMEOUT' ) || false !== strpos( $message, 'TIMED OUT' ) || false !== strpos( $message, 'CONNECTION' ) ) { return 'transport'; }
        return 'unknown';
    }

    private static function safe_failure_message( $kind, $stage, $prefix_source ) {
        $hint = array(
            'acl' => 'Redis ACL denied a command or key. Check SET/GET/DEL permissions and the allowed key patterns for the WordPress cache user.',
            'read_only' => 'The connection is to a read-only Redis node. Verify the cache drop-in is writing to the primary.',
            'memory_limit' => 'Redis refused a write because of a memory limit. Check memory, eviction settings and server logs.',
            'command_disabled' => 'A Redis command is disabled or unavailable. Review the cache provider command policy.',
            'auth' => 'A command requires credentials or an ACL user different from those used for this probe.',
            'cluster' => 'Redis returned a cluster routing response. A direct single-node benchmark is not representative.',
            'transport' => 'Redis connection timed out or failed during the probe.',
            'unknown' => 'The direct command was refused or failed. Review the Redis log or ACL policy; raw server error details are intentionally hidden.',
        );
        $message = $hint[ $kind ] ?? $hint['unknown'];
        if ( 'none' === $prefix_source && in_array( $stage, array( 'SET', 'GET', 'DEL' ), true ) ) {
            $message .= ' No Redis key prefix was configured, so the temporary test key may be outside an allowed ACL key pattern.';
        }
        return $message;
    }

    /**
     * Direct Redis command timings. Preserve connection and PING evidence even if
     * a later command is denied. All test keys expire in 45 seconds.
     */
    private static function benchmark_redis( $started ) {
        if ( ! class_exists( 'Redis' ) ) {
            return array( 'status' => 'unavailable', 'stage' => 'client', 'reason' => 'PhpRedis is unavailable; no direct command comparison is possible.' );
        }
        $settings = self::connection_settings();
        if ( 'complex' === $settings['topology'] ) {
            return array( 'status' => 'unavailable', 'stage' => 'topology', 'reason' => 'Redis cluster or Sentinel configuration detected; a single connection would give misleading results.' );
        }
        if ( '' === $settings['host'] ) {
            return array( 'status' => 'unavailable', 'stage' => 'configuration', 'reason' => 'No supported standalone Redis endpoint was found in WordPress configuration.' );
        }

        $namespace = self::key_namespace();
        $samples = array( 'set' => array(), 'get' => array(), 'delete' => array() );
        $result = array(
            'status' => 'unavailable', 'stage' => 'connect', 'connection_ms' => null, 'ping_ms' => null,
            'key_prefix_source' => $namespace['source'], 'set_command' => 'SET EX', 'keyspace_verification' => 'candidate_prefix_not_verified_against_dropin', 'samples' => 0,
            'failed' => 0, 'set' => self::summary( array() ),
            'get' => self::summary( array() ), 'delete' => self::summary( array() ),
            'server_counters' => array(),
        );
        $redis = new Redis();
        $stage = 'connect';
        $current_key = '';
        $key_was_written = false;
        $failure = 'unknown';
        try {
            $start = microtime( true );
            if ( ! $redis->connect( $settings['host'], $settings['port'], 0.5 ) ) { throw new RuntimeException( 'connection failed' ); }
            if ( defined( 'Redis::OPT_READ_TIMEOUT' ) ) { $redis->setOption( Redis::OPT_READ_TIMEOUT, 0.5 ); }
            $result['connection_ms'] = round( ( microtime( true ) - $start ) * 1000, 3 );
            $stage = 'auth';
            if ( null !== $settings['password'] && '' !== $settings['password'] ) {
                $credentials = $settings['username'] ? array( (string) $settings['username'], (string) $settings['password'] ) : $settings['password'];
                if ( ! $redis->auth( $credentials ) ) { throw new RuntimeException( 'authentication refused' ); }
            }
            $stage = 'database';
            if ( 0 !== $settings['database'] && ! $redis->select( $settings['database'] ) ) { throw new RuntimeException( 'database refused' ); }
            $stage = 'PING';
            $start = microtime( true );
            if ( false === $redis->ping() ) { throw new RuntimeException( 'PING refused' ); }
            $result['ping_ms'] = round( ( microtime( true ) - $start ) * 1000, 3 );
            // INFO is independent of write capability. Collect a safe snapshot
            // before SET so an ACL-limited temporary key cannot erase valid
            // Redis memory/pressure evidence.
            try {
                $info = $redis->info();
                if ( is_array( $info ) ) {
                    foreach ( array( 'connected_clients', 'blocked_clients', 'used_memory', 'maxmemory', 'evicted_keys', 'keyspace_hits', 'keyspace_misses', 'instantaneous_ops_per_sec' ) as $name ) {
                        if ( isset( $info[ $name ] ) && is_numeric( $info[ $name ] ) ) { $result['server_counters'][ $name ] = (int) $info[ $name ]; }
                    }
                }
            } catch ( Throwable $ignored ) { /* INFO permission is optional. */ }

            for ( $i = 0; $i < self::SAMPLE_COUNT && microtime( true ) - $started < self::MAX_SECONDS; $i++ ) {
                $current_key = $namespace['prefix'] . 'pfc:diagnostic:' . wp_generate_uuid4();
                $value = 'probe-' . wp_generate_uuid4();
                $stage = 'SET';
                if ( method_exists( $redis, 'clearLastError' ) ) { $redis->clearLastError(); }
                $start = microtime( true );
                // Redis SET with EX mirrors the command used by many object-cache
                // implementations. SETEX may be denied independently by an ACL.
                if ( ! $redis->set( $current_key, $value, array( 'ex' => 45 ) ) ) {
                    throw new RuntimeException( 'SET refused' );
                }
                $key_was_written = true;
                $samples['set'][] = ( microtime( true ) - $start ) * 1000;

                $stage = 'GET';
                $start = microtime( true );
                $got = $redis->get( $current_key );
                $samples['get'][] = ( microtime( true ) - $start ) * 1000;
                if ( $got !== $value ) { throw new RuntimeException( 'GET did not return the expected value' ); }

                $stage = 'DEL';
                $start = microtime( true );
                $deleted = $redis->del( $current_key );
                $samples['delete'][] = ( microtime( true ) - $start ) * 1000;
                if ( false === $deleted ) { throw new RuntimeException( 'DEL refused' ); }
                $key_was_written = false;
                $current_key = '';
                $result['samples']++;
            }
            $result['status'] = 'ok';
            $result['result_category'] = 'success';
            $result['stage'] = 'complete';
            $stage = 'INFO';
            if ( microtime( true ) - $started < self::MAX_SECONDS ) {
                try {
                    $info = $redis->info();
                    if ( is_array( $info ) ) {
                        foreach ( array( 'connected_clients', 'blocked_clients', 'used_memory', 'maxmemory', 'evicted_keys', 'keyspace_hits', 'keyspace_misses', 'instantaneous_ops_per_sec' ) as $name ) {
                            if ( isset( $info[ $name ] ) && is_numeric( $info[ $name ] ) ) { $result['server_counters'][ $name ] = (int) $info[ $name ]; }
                        }
                    }
                } catch ( Throwable $ignored ) { /* INFO may be intentionally disabled. */ }
            }
        } catch ( Throwable $error ) {
            $message = (string) $error->getMessage();
            if ( method_exists( $redis, 'getLastError' ) ) {
                try {
                    $server_error = $redis->getLastError();
                    if ( is_string( $server_error ) && '' !== $server_error ) { $message .= ' ' . $server_error; }
                } catch ( Throwable $ignored ) { /* Never expose Redis errors. */ }
            }
            $failure = self::failure_kind( $message );
            $result['status'] = 'unavailable';
            $result['stage'] = $stage;
            $result['failure_kind'] = $failure;
            $result['result_category'] = $failure;
            $result['failed'] = 1;
            $result['reason'] = self::safe_failure_message( $failure, $stage, $namespace['source'] );
        } finally {
            // Best effort cleanup of a unique test key only; even if an ACL denies
            // DEL, successful SET always used a short TTL.
            if ( $key_was_written && '' !== $current_key ) {
                try { $redis->del( $current_key ); } catch ( Throwable $ignored ) {}
            }
            try { $redis->close(); } catch ( Throwable $ignored ) {}
        }
        $result['set'] = self::summary( $samples['set'] );
        $result['get'] = self::summary( $samples['get'] );
        $result['delete'] = self::summary( $samples['delete'] );
        return $result;
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
            if ( ! empty( $raw['ping_ms'] ) ) {
                $advice[] = 'A direct Redis connection and PING succeeded, but ' . (string) ( $raw['stage'] ?? 'a subsequent command' ) . ' failed. Review the reported ACL/key-prefix/command category before modifying Redis connection settings.';
            } else {
                $advice[] = 'Only WordPress-level timings are available, so Redis command latency cannot yet be isolated from PHP and object-cache overhead.';
            }
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
