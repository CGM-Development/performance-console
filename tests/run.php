<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function esc_url_raw( $url, $protocols = null ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? (string) $url : ''; }
function home_url( $path = '' ) { return 'http://example.test:8080' . $path; }

require_once dirname( __DIR__ ) . '/includes/class-pfc-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-db.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-change-impact.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-budget.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-cacheability.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-asset-ledger.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-attribution.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-bootstrap-timeline.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-database-health.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-database-repair.php';
require_once dirname( __DIR__ ) . '/includes/class-pfc-profiler.php';

$tests = array();
function test_case( string $name, callable $callback ): void { global $tests; $tests[] = array( $name, $callback ); }
function assert_same( $expected, $actual, string $message = '' ): void {
    if ( $expected !== $actual ) { throw new RuntimeException( $message ?: 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}

test_case( 'idle database daemons are not actionable', static function (): void {
    assert_same( false, PFC_Database_Health::is_actionable_process( 'Daemon', 'Waiting on empty queue', '' ) );
    assert_same( false, PFC_Database_Health::is_actionable_process( 'Sleep', '', '' ) );
    assert_same( true, PFC_Database_Health::is_actionable_process( 'Query', 'Sending data', 'SELECT * FROM wp_posts' ) );
} );

test_case( 'optional database capability failures are ignored', static function (): void {
    assert_same( true, PFC_Profiler::is_expected_diagnostic_error( 'SELECT * FROM information_schema.INNODB_LOCK_WAITS', "Unknown table 'INNODB_LOCK_WAITS'" ) );
    assert_same( false, PFC_Profiler::is_expected_diagnostic_error( 'SELECT * FROM wp_posts', 'Table is marked as crashed' ) );
} );

test_case( 'root-cause incidents group routes but preserve remote hosts', static function (): void {
    $first = PFC_Utils::incident_key( 'http', 'Outbound HTTP request failed from aawp', 'tools.keycdn.com/geo.json timed out' );
    $second = PFC_Utils::incident_key( 'http', 'Outbound HTTP request failed from aawp', 'tools.keycdn.com/geo.json timed out again' );
    $other = PFC_Utils::incident_key( 'http', 'Outbound HTTP request failed from aawp', 'api.example.org/geo failed' );
    assert_same( $first, $second );
    assert_same( false, $first === $other );
} );

test_case( 'RUM p75 uses histogram buckets', static function (): void {
    $row = array( 'metric' => 'lcp', 'samples' => 100, 'bucket_0' => 10, 'bucket_1' => 20, 'bucket_2' => 30, 'bucket_3' => 20, 'bucket_4' => 20 );
    assert_same( 1000, PFC_Utils::metric_percentile( $row, 0.75 ) );
} );

test_case( 'same-origin checks include scheme and port', static function (): void {
    assert_same( true, PFC_Utils::same_origin_url( 'http://example.test:8080/review/example/' ) );
    assert_same( false, PFC_Utils::same_origin_url( 'http://example.test/review/example/' ) );
    assert_same( false, PFC_Utils::same_origin_url( 'https://example.test:8080/review/example/' ) );
} );

test_case( 'median handles even and odd sample counts', static function (): void {
    assert_same( 3.0, PFC_Utils::median( array( 9, 1, 3 ) ) );
    assert_same( 2.5, PFC_Utils::median( array( 4, 1, 3, 2 ) ) );
} );

test_case( 'paired impact rejects timing noise', static function (): void {
    $analysis = PFC_Utils::analyze_paired_impact( array( 72, -70, 60, -65, 3 ), true );
    assert_same( false, $analysis['repeatable'] );
    assert_same( 'low', $analysis['confidence'] );
} );

test_case( 'paired impact recognizes a stable plugin cost', static function (): void {
    $analysis = PFC_Utils::analyze_paired_impact( array( 11, 10, 12, 9, 11 ), true );
    assert_same( 11.0, $analysis['delta'] );
    assert_same( true, $analysis['repeatable'] );
    assert_same( 'high', $analysis['confidence'] );
} );

test_case( 'save capture tokens are signed, scoped and expiring', static function (): void {
    $id = '12345678-1234-1234-1234-123456789abc';
    $value = PFC_Bootstrap::save_capture_value( 7, 'manual', 1600, $id, 'test-secret' );
    $capture = PFC_Bootstrap::parse_save_capture_value( $value, 'test-secret', 1000 );
    assert_same( 7, $capture['user_id'] );
    assert_same( 'manual', $capture['kind'] );
    assert_same( false, PFC_Bootstrap::parse_save_capture_value( $value . 'x', 'test-secret', 1000 ) );
    assert_same( false, PFC_Bootstrap::parse_save_capture_value( $value, 'test-secret', 1700 ) );
} );

test_case( 'save requests distinguish manual, REST and autosave traffic', static function (): void {
    $classic = PFC_Utils::save_request_context( 'POST', '/wp-admin/post.php', array( 'action' => 'editpost', 'post_ID' => 42, 'post_type' => 'post' ) );
    $rest = PFC_Utils::save_request_context( 'PUT', '/wp-json/wp/v2/posts/42', array() );
    $autosave = PFC_Utils::save_request_context( 'POST', '/wp-json/wp/v2/posts/42/autosaves', array() );
    assert_same( 'classic-editor', $classic['kind'] );
    assert_same( 'block-editor-rest', $rest['kind'] );
    assert_same( 'block-editor-autosave', $autosave['kind'] );
    assert_same( true, PFC_Utils::save_context_matches_capture( $classic, 'manual' ) );
    assert_same( false, PFC_Utils::save_context_matches_capture( $autosave, 'manual' ) );
    assert_same( false, PFC_Utils::save_request_context( 'GET', '/wp-json/wp/v2/posts/42', array() ) );
    assert_same( false, PFC_Utils::save_request_context( 'POST', '/wp-json/wp/v2/settings', array() ) );
} );

test_case( 'backup primary-key discovery uses portable SHOW INDEX syntax', static function (): void {
    $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-pfc-database-backup.php' );
    assert_same( false, false !== strpos( $source, "WHERE Key_name='PRIMARY' ORDER BY" ) );
} );

test_case( 'save success requires a matching write and successful response', static function (): void {
    $context = array( 'post_id' => 42 );
    assert_same( true, PFC_Profiler::save_outcome( $context, array( 42 => 'post' ), 302 )['successful'] );
    assert_same( false, PFC_Profiler::save_outcome( $context, array(), 200 )['successful'] );
    assert_same( false, PFC_Profiler::save_outcome( $context, array( 43 => 'post' ), 200 )['successful'] );
    assert_same( false, PFC_Profiler::save_outcome( $context, array( 42 => 'post' ), 403 )['successful'] );
    assert_same( false, PFC_Profiler::save_outcome( $context, array( 42 => 'post' ), 200, true )['successful'] );
} );

test_case( 'save comparisons reject different editors, posts, users and kinds', static function (): void {
    $context = array( 'post_id' => 42, 'post_type' => 'post', 'requested_kind' => 'manual', 'kind' => 'classic-editor', 'user_id' => 7 );
    assert_same( true, PFC_Profiler::comparable_save( $context, $context ) );
    foreach ( array_keys( $context ) as $key ) {
        $different = $context; $different[ $key ] = 'different';
        assert_same( false, PFC_Profiler::comparable_save( $context, $different ) );
    }
    assert_same( false, PFC_Profiler::comparable_save( array(), $context ) );
} );

test_case( 'save parser rejects taxonomy and nested non-save REST endpoints', static function (): void {
    foreach ( array( '/wp-json/wp/v2/categories/42', '/wp-json/wp/v2/tags/42', '/wp-json/wp/v2/posts/42/revisions', '/wp-json/wp/v2/posts/42/arbitrary' ) as $uri ) {
        assert_same( false, PFC_Utils::save_request_context( 'POST', $uri, array() ) );
    }
} );

foreach ( array(
    'inactive' => array( 'decided' => false, 'queries' => false, 'sampled' => false, 'secret_reads' => 0 ),
    'declined' => array( 'decided' => true, 'queries' => false, 'sampled' => false, 'secret_reads' => 0 ),
    'network' => array( 'decided' => true, 'queries' => false, 'sampled' => false, 'secret_reads' => 0 ),
    'sampled' => array( 'decided' => true, 'queries' => true, 'sampled' => true, 'secret_reads' => 0 ),
) as $scenario => $expected ) {
    test_case( 'production MU bootstrap: ' . $scenario, static function () use ( $scenario, $expected ): void {
        $output = array(); $code = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/bootstrap.php' ) . ' ' . escapeshellarg( $scenario ), $output, $code );
        assert_same( 0, $code );
        assert_same( $expected, json_decode( implode( '', $output ), true ) );
    } );
}

test_case( 'deep diagnostics load the secret on demand only', static function (): void {
    $output = array(); $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/bootstrap.php' ) . ' diag-secret', $output, $code );
    assert_same( 0, $code );
    assert_same( array( 'decided' => true, 'queries' => true, 'sampled' => false, 'secret_reads' => 1 ), json_decode( implode( '', $output ), true ) );
} );

test_case( 'autoload reviews require real frontend and admin coverage', static function (): void {
    assert_same( false, PFC_Utils::autoload_review_ready( array(), 30 ) );
    assert_same( false, PFC_Utils::autoload_review_ready( array( 'total' => 100, 'frontend' => 100, 'admin' => 0 ), 30 ) );
    assert_same( false, PFC_Utils::autoload_review_ready( array( 'total' => 100, 'frontend' => 20, 'admin' => 20 ), 6 ) );
    assert_same( true, PFC_Utils::autoload_review_ready( array( 'total' => 100, 'frontend' => 20, 'admin' => 20 ), 7 ) );
} );

test_case( 'plugin impact content assertions reject missing output', static function (): void {
    assert_same( true, PFC_Utils::response_contains_text( '<h1>Review &amp; test</h1>', 'Review & test' ) );
    assert_same( true, PFC_Utils::response_contains_text( "<h1>Review\n  test</h1>", 'Review test' ) );
    assert_same( false, PFC_Utils::response_contains_text( '<h1>Error</h1>', 'Review' ) );
    assert_same( true, PFC_Utils::response_contains_text( '<h1>Anything</h1>', '' ) );
} );

test_case( 'backup checksum detects same-size changes and rejects re-verification', static function (): void {
    $output = array(); $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/backup-integrity.php' ), $output, $code );
    assert_same( 0, $code );
    assert_same( array( 'intact' => true, 'changed' => false, 'reverify' => 'pfc_backup_changed' ), json_decode( implode( '', $output ), true ) );
} );

foreach ( array( 'scan-absent' => 'observing', 'scan-present' => 'open', 'failed-probe' => 'open' ) as $scenario => $expected ) {
    test_case( 'incident recheck: ' . $scenario, static function () use ( $scenario, $expected ): void {
        $output = array(); $code = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/incident-actions.php' ) . ' ' . escapeshellarg( $scenario ), $output, $code );
        assert_same( 0, $code );
        assert_same( $expected, implode( '', $output ) );
    } );
}

test_case( 'autoload protection is shared between REST and the repair centre', static function (): void {
    assert_same( true, PFC_Database_Repair::protected_option( 'blogname' ) );
    assert_same( true, PFC_Database_Repair::protected_option( 'pfc_secret' ) );
    assert_same( true, PFC_Database_Repair::protected_option( 'widget_block' ) );
    assert_same( false, PFC_Database_Repair::protected_option( 'woocommerce_currency' ) );
    $rest = file_get_contents( dirname( __DIR__ ) . '/includes/class-pfc-rest.php' );
    assert_same( true, false !== strpos( (string) $rest, 'PFC_Database_Repair::protected_option' ) );
} );

test_case( 'MU bootstrap install writes atomically', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-pfc-bootstrap.php' );
    assert_same( false, false !== strpos( $source, 'file_put_contents( self::path()' ) );
    assert_same( true, false !== strpos( $source, 'rename(' ) );
} );

test_case( 'uninstall removes every plugin data store', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
    foreach ( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ) as $suffix ) {
        assert_same( true, false !== strpos( $source, "'" . $suffix . "'" ) );
    }
    assert_same( true, false !== strpos( $source, 'wp_clear_scheduled_hook' ) );
    assert_same( true, false !== strpos( $source, '000-performance-console-bootstrap.php' ) );
} );

test_case( 'legacy rename map covers every pre-rename data store', static function (): void {
    assert_same( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ), PFC_DB::legacy_table_suffixes() );
    assert_same( array( 'wpi\_%', '\_transient\_wpi\_%', '\_transient\_timeout\_wpi\_%' ), PFC_DB::legacy_option_patterns() );
    assert_same( 'wpi_daily_maintenance', PFC_DB::legacy_cron_hook() );
} );

test_case( 'MU bootstrap install removes legacy wpi filenames', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-pfc-bootstrap.php' );
    assert_same( true, false !== strpos( $source, '000-wp-performance-inspector-bootstrap.php' ) );
    assert_same( true, false !== strpos( $source, 'wp-performance-inspector-bootstrap.php' ) );
    assert_same( true, false !== strpos( $source, 'legacy_paths' ) );
} );

test_case( 'uninstall removes both the pfc and legacy wpi data stores', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
    foreach ( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ) as $suffix ) {
        assert_same( true, false !== strpos( $source, "'" . $suffix . "'" ) );
    }
    assert_same( true, false !== strpos( $source, '{$wpdb->prefix}pfc_{$pfc_suffix}' ) );
    assert_same( true, false !== strpos( $source, '{$wpdb->prefix}wpi_{$pfc_suffix}' ) );
    assert_same( true, false !== strpos( $source, "'wpi_'" ) );
    assert_same( true, false !== strpos( $source, "wp_clear_scheduled_hook( 'wpi_daily_maintenance' )" ) );
    assert_same( true, false !== strpos( $source, '000-wp-performance-inspector-bootstrap.php' ) );
    assert_same( true, false !== strpos( $source, 'wpi-private-backups-' ) );
    assert_same( true, false !== strpos( $source, 'wpi-db-*.sql' ) );
} );

test_case( 'legacy menu slug redirects to the pfc page', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-pfc-admin.php' );
    assert_same( true, false !== strpos( $source, "'wpi' === \$_GET['page']" ) );
    assert_same( true, false !== strpos( $source, 'add_query_arg' ) );
} );

test_case( 'legacy wpi database backups stay verifiable after migration', static function (): void {
    $output = array(); $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/backup-legacy.php' ), $output, $code );
    assert_same( 0, $code );
    assert_same( 'accepted', implode( '', $output ) );
} );

test_case( 'change impact: a sustained improvement clears the noise floor', static function (): void {
    $before = array(); $after = array();
    for ( $i = 0; $i < 20; $i++ ) { $before[] = 800 + ( $i % 5 ) * 4; $after[] = 600 + ( $i % 5 ) * 4; }
    $result = PFC_Change_Impact::classify( $before, $after, 10.0 );
    assert_same( 'improved', $result['verdict'] );
    assert_same( -200.0, $result['delta'] );
    assert_same( true, in_array( $result['confidence'], array( 'medium', 'high' ), true ) );
} );

test_case( 'change impact: a sustained regression is reported as worse', static function (): void {
    $before = array(); $after = array();
    for ( $i = 0; $i < 20; $i++ ) { $before[] = 600 + ( $i % 4 ) * 5; $after[] = 900 + ( $i % 4 ) * 5; }
    $result = PFC_Change_Impact::classify( $before, $after, 10.0 );
    assert_same( 'worse', $result['verdict'] );
    assert_same( true, $result['delta'] > 0 );
} );

test_case( 'change impact: a shift inside the observed spread is not a result', static function (): void {
    $before = array(); $after = array();
    for ( $i = 0; $i < 20; $i++ ) { $before[] = $i % 2 ? 780 : 820; $after[] = 810; }
    $result = PFC_Change_Impact::classify( $before, $after, 10.0 );
    assert_same( 'no_measurable_change', $result['verdict'] );
    assert_same( 10.0, $result['delta'] );
} );

test_case( 'change impact: thin windows are never given a verdict', static function (): void {
    $result = PFC_Change_Impact::classify( array( 800, 810, 790 ), array( 600, 610, 590 ), 10.0 );
    assert_same( 'insufficient', $result['verdict'] );
    assert_same( 3, $result['before_samples'] );
} );

test_case( 'change impact: a change is not assessable before the settled window fills', static function (): void {
    $windows = PFC_Change_Impact::windows( '2026-01-01 00:00:00', strtotime( '2026-01-01 02:00:00 UTC' ) );
    assert_same( 'pending', $windows['state'] );
    $settled = PFC_Change_Impact::windows( '2026-01-01 00:00:00', strtotime( '2026-01-03 00:00:00 UTC' ) );
    assert_same( 'assessable', $settled['state'] );
} );

test_case( 'change impact: traffic mix drift is measured in percentage points', static function (): void {
    $drift = PFC_Change_Impact::mix_drift(
        array( 'front-page' => 0.60, 'singular:post' => 0.40 ),
        array( 'front-page' => 0.30, 'singular:post' => 0.70 )
    );
    assert_same( 30.0, $drift );
} );

test_case( 'budget thresholds clamp to bounds and drop unknown keys', static function (): void {
    $thresholds = PFC_Budget::normalise( array( 'lcp' => 999999, 'cls' => -3, 'nope' => 5 ) );
    assert_same( 600000.0, $thresholds['lcp'] );
    assert_same( 0.0, $thresholds['cls'] );
    assert_same( 800.0, $thresholds['ttfb'] );
    assert_same( false, array_key_exists( 'nope', $thresholds ) );
} );

test_case( 'budget evaluation fails only the breached budget', static function (): void {
    $measured = array( 'values' => array(
        'ttfb'          => array( 'value' => 700, 'samples' => 100, 'source' => 'rum' ),
        'lcp'           => array( 'value' => 3000, 'samples' => 100, 'source' => 'rum' ),
        'inp'           => array( 'value' => 150, 'samples' => 100, 'source' => 'rum' ),
        'cls'           => array( 'value' => 0.05, 'samples' => 100, 'source' => 'rum' ),
        'server_php_ms' => array( 'value' => 400, 'samples' => 50, 'source' => 'runs' ),
        'queries'       => array( 'value' => 40, 'samples' => 50, 'source' => 'runs' ),
    ) );
    $result = PFC_Budget::evaluate( $measured, PFC_Budget::DEFAULTS );
    assert_same( false, $result['pass'] );
    assert_same( array( 'lcp' ), $result['failed'] );
    $statuses = array();
    foreach ( $result['checks'] as $check ) { $statuses[ $check['key'] ] = $check['status']; }
    assert_same( 'fail', $statuses['lcp'] );
    assert_same( 'pass', $statuses['ttfb'] );
} );

test_case( 'budget with no traffic fails only when data is required', static function (): void {
    $measured = array( 'values' => array(
        'ttfb'          => array( 'value' => 700, 'samples' => 100, 'source' => 'rum' ),
        'inp'           => array( 'value' => 150, 'samples' => 100, 'source' => 'rum' ),
        'cls'           => array( 'value' => 0.05, 'samples' => 100, 'source' => 'rum' ),
        'server_php_ms' => array( 'value' => 400, 'samples' => 50, 'source' => 'runs' ),
        'queries'       => array( 'value' => 40, 'samples' => 50, 'source' => 'runs' ),
    ) );
    $strict = PFC_Budget::evaluate( $measured, PFC_Budget::DEFAULTS, true );
    assert_same( false, $strict['pass'] );
    assert_same( array( 'lcp' ), $strict['missing'] );
    $lenient = PFC_Budget::evaluate( $measured, PFC_Budget::DEFAULTS, false );
    assert_same( true, $lenient['pass'] );
} );

test_case( 'budget reports a disabled budget without failing it', static function (): void {
    $thresholds = PFC_Budget::normalise( array( 'lcp' => 0 ) );
    $checks = PFC_Budget::evaluate( array( 'values' => array() ), $thresholds, false );
    $lcp = null;
    foreach ( $checks['checks'] as $check ) { if ( 'lcp' === $check['key'] ) { $lcp = $check; } }
    assert_same( 'disabled', $lcp['status'] );
    assert_same( true, $checks['pass'] );
} );

test_case( 'cacheability keeps deterministic reads and rejects unsafe ones', static function (): void {
    assert_same( true, PFC_Cacheability::classify( 'SELECT * FROM wp_posts WHERE ID = ?' )['cacheable'] );
    assert_same( 'write', PFC_Cacheability::classify( 'UPDATE wp_posts SET post_title = ? WHERE ID = ?' )['reason'] );
    assert_same( 'not_read', PFC_Cacheability::classify( 'SHOW TABLES' )['reason'] );
    assert_same( 'locking_read', PFC_Cacheability::classify( 'SELECT * FROM wp_posts WHERE ID = ? FOR UPDATE' )['reason'] );
    assert_same( 'non_deterministic', PFC_Cacheability::classify( 'SELECT * FROM wp_posts WHERE post_date > NOW()' )['reason'] );
    assert_same( 'user_scoped', PFC_Cacheability::classify( 'SELECT * FROM wp_usermeta WHERE user_id = ?' )['reason'] );
    assert_same( 'found_rows', PFC_Cacheability::classify( 'SELECT SQL_CALC_FOUND_ROWS * FROM wp_posts' )['reason'] );
    assert_same( 'review_cte', PFC_Cacheability::classify( 'WITH recent AS (SELECT ID FROM wp_posts) SELECT * FROM recent' )['reason'] );
} );

test_case( 'bootstrap waterfall orders phases and computes shares', static function (): void {
    $waterfall = PFC_Bootstrap_Timeline::from_phases( array(
        'plugins_loaded_to_init'              => 100.5,
        'mu_plugin_bootstrap_to_muplugins_loaded' => 20.0,
        'muplugins_loaded_to_plugins_loaded'  => 80.0,
        'malformed_phase_key'                 => 999.0,
    ) );
    assert_same( 3, count( $waterfall['steps'] ) );
    assert_same( 'muplugins_loaded', $waterfall['steps'][0]['to'] );
    assert_same( 'init', $waterfall['steps'][2]['to'] );
    assert_same( 200.5, $waterfall['total_ms'] );
    assert_same( 'Init', $waterfall['largest']['label'] );
    assert_same( 50.1, $waterfall['steps'][2]['share'] );
} );

test_case( 'bootstrap footprint groups includes by component', static function (): void {
    $classifier = static function ( $file ) {
        if ( false !== strpos( $file, '/plugins/alpha/' ) ) { return array( 'type' => 'plugin', 'slug' => 'alpha' ); }
        return array( 'type' => 'theme', 'slug' => 'sampletheme' );
    };
    $footprint = PFC_Bootstrap_Timeline::footprint(
        array( '/srv/wp-content/plugins/alpha/a.php', '/srv/wp-content/plugins/alpha/b.php', '/srv/wp-content/themes/sampletheme/styles.php' ),
        $classifier
    );
    assert_same( 3, $footprint['totals']['files'] );
    $by_slug = array();
    foreach ( $footprint['components'] as $component ) { $by_slug[ $component['slug'] ] = $component; }
    assert_same( 2, $by_slug['alpha']['files'] );
    assert_same( 1, $by_slug['sampletheme']['files'] );
    assert_same( 'static_includes', $footprint['measured'] );
} );

test_case( 'asset ledger parses resources and resolves relative URLs', static function (): void {
    $html = '<!doctype html><html><head>'
        . '<link rel="stylesheet" href="/wp-content/themes/t/style.css">'
        . '<link rel="stylesheet" href="/print.css" media="print">'
        . '<link rel="stylesheet" href="https://cdn.example.net/a.css">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com">'
        . '<script src="/wp-includes/js/jquery.js"></script>'
        . '<script src="/deferred.js" defer></script>'
        . '<style>.a{color:red}</style>'
        . '</head><body>'
        . '<img src="/hero.jpg" loading="lazy">'
        . '<img src="img/thumb.jpg" width="300" height="200" srcset="/thumb-300.jpg 300w">'
        . '<script>var x = 1;</script>'
        . '</body></html>';
    $parsed = PFC_Asset_Ledger::parse( $html, 'https://example.test/2026/11/story/' );
    assert_same( 3, count( $parsed['stylesheets'] ) );
    assert_same( true, $parsed['stylesheets'][0]['blocking'] );
    assert_same( false, $parsed['stylesheets'][1]['blocking'] );
    assert_same( 'third-party', $parsed['stylesheets'][2]['origin'] );
    assert_same( 2, count( $parsed['scripts'] ) );
    assert_same( true, $parsed['scripts'][0]['blocking'] );
    assert_same( false, $parsed['scripts'][1]['blocking'] );
    assert_same( 2, count( $parsed['images'] ) );
    assert_same( 'https://example.test/hero.jpg', $parsed['images'][0]['src'] );
    assert_same( 'https://example.test/2026/11/story/img/thumb.jpg', $parsed['images'][1]['src'] );
    assert_same( true, $parsed['images'][1]['srcset'] );
    assert_same( array( 'cdn.example.net' ), $parsed['origins']['unprepared'] );
    assert_same( true, $parsed['inline_bytes']['style'] > 0 );
} );

test_case( 'asset ledger flags lazy LCP images, blocking scripts and cold origins', static function (): void {
    $html = '<html><head>'
        . '<link rel="stylesheet" href="/a.css"><link rel="stylesheet" href="/b.css"><link rel="stylesheet" href="/c.css">'
        . '<script src="/head.js"></script>'
        . '<link rel="stylesheet" href="https://cdn.example.net/x.css">'
        . '</head><body><img src="/hero.jpg" loading="lazy"></body></html>';
    $report = PFC_Asset_Ledger::audit( PFC_Asset_Ledger::parse( $html, 'https://example.test/' ) );
    $ids = array();
    foreach ( $report['findings'] as $finding ) { $ids[] = $finding['id']; }
    foreach ( array( 'lcp_lazy', 'lcp_no_fetchpriority', 'lcp_no_dimensions', 'render_blocking_css', 'head_scripts', 'no_preconnect' ) as $expected ) {
        assert_same( true, in_array( $expected, $ids, true ), 'missing finding: ' . $expected );
    }
    assert_same( true, $report['estimate_ms_total'] > 0 );
    assert_same( 'high', $report['findings'][0]['severity'] );
    assert_same( true, $report['render_blocking_css'] >= 4 );
} );

test_case( 'asset ledger does not flag a well-built page', static function (): void {
    $html = '<html><head>'
        . '<link rel="preconnect" href="https://cdn.example.net">'
        . '<link rel="stylesheet" href="/one.css">'
        . '<script src="/app.js" defer></script>'
        . '</head><body>'
        . '<img src="/hero.jpg" width="1200" height="630" fetchpriority="high" srcset="/hero-600.jpg 600w">'
        . '<img src="/second.jpg" width="800" height="450" loading="lazy" srcset="/second-400.jpg 400w">'
        . '</body></html>';
    $report = PFC_Asset_Ledger::audit( PFC_Asset_Ledger::parse( $html, 'https://example.test/' ) );
    $ids = array();
    foreach ( $report['findings'] as $finding ) { $ids[] = $finding['id']; }
    assert_same( false, in_array( 'lcp_lazy', $ids, true ) );
    assert_same( false, in_array( 'lcp_no_fetchpriority', $ids, true ) );
    assert_same( false, in_array( 'head_scripts', $ids, true ) );
    assert_same( false, in_array( 'no_preconnect', $ids, true ) );
    assert_same( false, in_array( 'images_no_dimensions', $ids, true ) );
} );

test_case( 'attribution sanitises client values and clamps sizes', static function (): void {
    $clean = PFC_Attribution::sanitize( array(
        'lcp_tag'         => 'img',
        'lcp_bytes'       => 999999999999,
        'lcp_same_origin' => 1,
        'inp_tag'         => 'button',
        'long_task_count' => 99999,
        'long_task_max'   => 999999,
        'unexpected'      => 'drop me',
        'lcp_url'         => 'https://example.test/secret',
    ) );
    assert_same( 'IMG', $clean['lcp_tag'] );
    assert_same( PFC_Attribution::MAX_BYTES, $clean['lcp_bytes'] );
    assert_same( 1, $clean['lcp_same_origin'] );
    assert_same( 'BUTTON', $clean['inp_tag'] );
    assert_same( PFC_Attribution::MAX_LONG_TASKS, $clean['long_task_count'] );
    assert_same( PFC_Attribution::MAX_LONG_TASK_MS, $clean['long_task_max'] );
    assert_same( false, array_key_exists( 'unexpected', $clean ) );
    assert_same( false, array_key_exists( 'lcp_url', $clean ) );
} );

test_case( 'attribution rejects unknown tags and non-positive values', static function (): void {
    $clean = PFC_Attribution::sanitize( array( 'lcp_tag' => '<script>alert(1)</script>', 'lcp_bytes' => 0, 'long_task_count' => -5 ) );
    assert_same( array(), $clean );
} );

$failures = 0;
foreach ( $tests as $test ) {
    list( $name, $callback ) = $test;
    try { $callback(); echo "PASS: {$name}\n"; }
    catch ( Throwable $error ) { $failures++; fwrite( STDERR, "FAIL: {$name}: {$error->getMessage()}\n" ); }
}
echo count( $tests ) . " tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
