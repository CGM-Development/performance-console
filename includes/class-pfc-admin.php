<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PFC_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_pfc_scan', array( __CLASS__, 'scan_post' ) );
        add_action( 'admin_post_pfc_cache_diagnose', array( __CLASS__, 'cache_diagnose_post' ) );
        add_action( 'admin_post_pfc_ocp_tracking_report', array( __CLASS__, 'ocp_tracking_report_post' ) );
        add_action( 'admin_post_pfc_cache_profile', array( __CLASS__, 'cache_profile_post' ) );
        add_action( 'admin_post_pfc_cache_compare', array( __CLASS__, 'cache_compare_post' ) );
        add_action( 'admin_post_pfc_http_self_test', array( __CLASS__, 'http_self_test_post' ) );
        add_action( 'admin_post_pfc_export_ai', array( __CLASS__, 'export_ai_post' ) );
        add_action( 'admin_post_pfc_bootstrap', array( __CLASS__, 'bootstrap_post' ) );
        add_action( 'admin_post_pfc_expired_transients', array( __CLASS__, 'expired_transients' ) );
        add_action( 'admin_post_pfc_db_fix', array( __CLASS__, 'database_fix_post' ) );
        add_action( 'admin_post_pfc_profile', array( __CLASS__, 'profile_post' ) );
        add_action( 'admin_post_pfc_save_capture', array( __CLASS__, 'save_capture_post' ) );
        add_action( 'admin_post_pfc_plugin_impact', array( __CLASS__, 'plugin_impact_post' ) );
        add_action( 'admin_post_pfc_settings', array( __CLASS__, 'settings_post' ) );
        add_action( 'admin_post_pfc_incident_action', array( __CLASS__, 'incident_action_post' ) );
        add_action( 'admin_post_pfc_backup_download', array( __CLASS__, 'backup_download_post' ) );
        add_action( 'admin_post_pfc_backup_delete', array( __CLASS__, 'backup_delete_post' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    public static function menu() {
        if ( isset( $_GET['page'] ) && 'wpi' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $args = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $args['page'] = 'pfc';
            wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
            exit;
        }
        add_menu_page( 'Performance Console', 'Performance', 'manage_options', 'pfc', array( __CLASS__, 'page' ), 'dashicons-performance', 80 );
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_pfc' !== $hook ) { return; }

        $css_file = PFC_DIR . 'assets/css/admin.css';
        $ui_file  = PFC_DIR . 'assets/js/admin-ui.js';
        $backup_file = PFC_DIR . 'assets/js/admin-backups.js';

        wp_enqueue_style( 'pfc-admin', PFC_URL . 'assets/css/admin.css', array(), file_exists( $css_file ) ? (string) filemtime( $css_file ) : PFC_VERSION );
        wp_enqueue_script( 'pfc-admin-ui', PFC_URL . 'assets/js/admin-ui.js', array(), file_exists( $ui_file ) ? (string) filemtime( $ui_file ) : PFC_VERSION, true );
        wp_enqueue_script( 'pfc-admin-backups', PFC_URL . 'assets/js/admin-backups.js', array(), file_exists( $backup_file ) ? (string) filemtime( $backup_file ) : PFC_VERSION, true );
        wp_localize_script( 'pfc-admin-backups', 'pfcBackupAdmin', array(
            'restRoot' => esc_url_raw( rest_url( 'pfc/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    public static function backup_download_post() {
        self::cap();
        $id = absint( $_GET['backup_id'] ?? 0 );
        check_admin_referer( 'pfc_backup_download_' . $id );
        $result = PFC_Database_Backup::download( $id );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        exit;
    }

    public static function backup_delete_post() {
        self::cap();
        $id = absint( $_POST['backup_id'] ?? 0 );
        check_admin_referer( 'pfc_backup_delete_' . $id );
        $result = PFC_Database_Backup::delete( $id );
        $message = is_wp_error( $result ) ? $result->get_error_message() : ( $result['message'] ?? 'Backup deleted.' );
        set_transient( 'pfc_backup_notice_' . get_current_user_id(), array( 'ok' => ! is_wp_error( $result ), 'message' => $message ), 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=database#database-backups' ) ); exit;
    }
    private static function cap() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'performance-console' ) ); }
        self::load_diagnostics();
    }

    private static function load_diagnostics() { PFC_REST::load_diagnostics(); }

    public static function scan_post() {
        self::cap(); check_admin_referer( 'pfc_scan' );
        $deep = ! empty( $_POST['deep'] );
        PFC_Scanner::scan( $deep, false );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=' . ( $deep ? 'database' : 'overview' ) . '&scanned=1&mode=' . ( $deep ? 'deep' : 'safe' ) . ( $deep ? '#database' : '#overview' ) ) ); exit;
    }

    /** Explicit, nonce-protected diagnostic. No Redis benchmark on normal traffic. */
    public static function cache_diagnose_post() {
        self::cap();
        check_admin_referer( 'pfc_cache_diagnose' );
        $result = PFC_Cache_Diagnostics::run();
        $ocp_groups = ! empty( $_POST['ocp_groups'] );
        $ocp_memory = $ocp_groups && ! empty( $_POST['ocp_group_memory'] );
        $result['object_cache_pro'] = PFC_Object_Cache_Pro::inspect( $ocp_groups, $ocp_memory );
        set_transient( 'pfc_cache_diagnostic_' . get_current_user_id(), $result, 15 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=cache#cache-diagnostics' ) );
        exit;
    }

    /** Record only a status selected from native Site Health, not pasted diagnostics. */
    public static function ocp_tracking_report_post() {
        self::cap();
        check_admin_referer( 'pfc_ocp_tracking_report' );
        $status = isset( $_POST['tracking_status'] ) && is_string( $_POST['tracking_status'] ) ? sanitize_key( wp_unslash( $_POST['tracking_status'] ) ) : '';
        $category = isset( $_POST['tracking_category'] ) && is_string( $_POST['tracking_category'] ) ? sanitize_key( wp_unslash( $_POST['tracking_category'] ) ) : 'unknown';
        $command = isset( $_POST['tracking_command'] ) && is_string( $_POST['tracking_command'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['tracking_command'] ) ) ) : 'NOT_IDENTIFIED';
        // Optional one-line native failure classifier: process in this request only,
        // never save, log, echo or export the supplied diagnostic text.
        $excerpt = isset( $_POST['tracking_error_line'] ) && is_string( $_POST['tracking_error_line'] ) ? wp_unslash( $_POST['tracking_error_line'] ) : '';
        if ( '' !== trim( $excerpt ) ) {
            $classified = PFC_Object_Cache_Pro::classify_native_error_line( $excerpt );
            if ( 'unknown' !== $classified['category'] ) { $category = $classified['category']; }
            if ( 'NOT_IDENTIFIED' !== $classified['command'] ) { $command = $classified['command']; }
        }
        unset( $excerpt, $classified );
        if ( PFC_Object_Cache_Pro::set_manual_tracking_report( $status, $category, $command ) ) {
            $key = 'pfc_cache_diagnostic_' . get_current_user_id();
            $old = get_transient( $key );
            if ( is_array( $old ) && ! empty( $old['object_cache_pro'] ) ) {
                $current = (string) ( $old['object_cache_pro']['key_tracking_diagnostics']['source'] ?? '' );
                $old['object_cache_pro']['key_tracking_triage']['administrator_report'] = array(
                    'category' => $category, 'command' => $command, 'confidence' => 'administrator_reported',
                );
                if ( 'wordpress_site_health_info' !== $current && 'registered_wordpress_site_health_test' !== $current ) {
                    $old['object_cache_pro']['key_tracking_diagnostics']['status'] = $status;
                    $old['object_cache_pro']['key_tracking_diagnostics']['source'] = 'administrator_reported_site_health';
                    $old['object_cache_pro']['key_tracking_diagnostics']['confidence'] = 'user_reported';
                }
                set_transient( $key, $old, 15 * MINUTE_IN_SECONDS );
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=cache#pfc-ocp-native-tracking' ) );
        exit;
    }

    /** Profile a real public route with authenticated, cache-bypassing probes. */
    public static function cache_profile_post() {
        self::cap();
        check_admin_referer( 'pfc_cache_profile' );
        $url = self::own_url( isset( $_POST['profile_url'] ) ? wp_unslash( $_POST['profile_url'] ) : home_url( '/' ) );
        if ( is_wp_error( $url ) ) { wp_die( esc_html( $url->get_error_message() ) ); }
        $runs = max( 1, min( 5, absint( $_POST['runs'] ?? 3 ) ) );
        $meta = array();
        $callbacks = ! empty( $_POST['php_callback_profile'] );
        self::timed_probe( $url, '', $runs, $meta, '', true, $callbacks );
        $meta['callback_profiling_requested'] = $callbacks;
        $details = array(
            'url' => $url,
            'at' => time(),
            'probe_meta' => $meta,
            'runs' => (array) ( $meta['cache_runs'] ?? array() ),
        );
        $details['guidance'] = PFC_Request_Cache::guidance( $details['runs'] );
        $details['findings'] = PFC_Workload_Advisor::findings( $details['runs'], PFC_Scanner::cached_scan() );
        // Keep the immediately previous verified profile for a before/after
        // comparison. No persistent history, no automatic server changes.
        $previous = get_transient( 'pfc_cache_profile_' . get_current_user_id() );
        if ( is_array( $previous ) && ! empty( $previous['runs'] ) ) {
            set_transient( 'pfc_cache_profile_previous_' . get_current_user_id(), $previous, 30 * MINUTE_IN_SECONDS );
        }
        set_transient( 'pfc_cache_profile_' . get_current_user_id(), $details, 30 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=cache#cache-request-profile' ) );
        exit;
    }

    /** Three alternating-order signed standard/deep pairs, run only by an admin. */
    public static function cache_compare_post() {
        self::cap();
        check_admin_referer( 'pfc_cache_compare' );
        $url = self::own_url( isset( $_POST['profile_url'] ) ? wp_unslash( $_POST['profile_url'] ) : home_url( '/' ) );
        if ( is_wp_error( $url ) ) { wp_die( esc_html( $url->get_error_message() ) ); }
        $pairs = array();
        for ( $i = 0; $i < 3; $i++ ) {
            $results = array();
            // Alternation reduces a simple first-mode/order bias. It does not
            // control for production traffic, content or database cache state.
            $order = 1 === $i ? array( 'deep', 'standard' ) : array( 'standard', 'deep' );
            foreach ( $order as $mode ) {
                $meta = array();
                self::timed_probe( $url, '', 1, $meta, '', true, 'deep' === $mode );
                $results[ $mode ] = 1 === (int) ( $meta['verified'] ?? 0 ) ? array(
                    'php_ms' => $meta['php_ms'][0] ?? null,
                    'sql_ms' => $meta['db_ms'][0] ?? null,
                    'status' => $meta['statuses'][0] ?? null,
                    'content_type' => $meta['content_types'][0] ?? '',
                    'body_bytes' => $meta['body_bytes'][0] ?? 0,
                    'callback_profile_enabled' => ! empty( $meta['cache_runs'][0]['callback_profile']['enabled'] ),
                ) : array();
            }
            $pairs[] = $results;
        }
        $summary = PFC_Profile_Comparison::summarize( $pairs );
        $summary['captured_at_utc'] = gmdate( 'c' );
        set_transient( 'pfc_cache_mode_comparison_' . get_current_user_id(), $summary, 20 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=cache#pfc-mode-comparison' ) );
        exit;
    }

    /** One explicit, bounded loopback; never runs from a public route. */
    public static function http_self_test_post() {
        self::cap();
        check_admin_referer( 'pfc_http_self_test' );
        $result = PFC_Profiler::http_collector_self_test();
        set_transient( 'pfc_http_self_test_' . get_current_user_id(), $result, 20 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=cache#pfc-http-self-test' ) );
        exit;
    }

    /** Download an allowlisted JSON diagnostic report. No new scan is triggered. */
    public static function export_ai_post() {
        self::cap();
        check_admin_referer( 'pfc_export_ai' );
        $scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) && 'scan' === sanitize_key( wp_unslash( $_POST['scope'] ) ) ? 'scan' : 'cache';
        $user_id = get_current_user_id();
        $profile = get_transient( 'pfc_cache_profile_' . $user_id );
        $profile = is_array( $profile ) ? $profile : array();
        if ( 'cache' === $scope ) {
            $compare = get_transient( 'pfc_cache_mode_comparison_' . $user_id );
            if ( is_array( $compare ) ) { $profile['mode_comparison'] = $compare; }
        }
        $report = PFC_AI_Export::build(
            get_transient( 'pfc_cache_diagnostic_' . $user_id ),
            $profile,
            PFC_Scanner::cached_scan(),
            $scope
        );
        if ( ! $report ) {
            wp_die( esc_html__( 'No recent results available to export. Run a diagnostic or system scan first.', 'performance-console' ), '', array( 'response' => 404 ) );
        }
        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( ! is_string( $json ) || '' === $json ) {
            wp_die( esc_html__( 'Unable to prepare the diagnostic export.', 'performance-console' ), '', array( 'response' => 500 ) );
        }
        if ( headers_sent() ) {
            wp_die( esc_html__( 'Output began before the diagnostic export. Disable PHP notices and retry.', 'performance-console' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="performance-console-ai-' . $scope . '-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Downloaded JSON is generated from explicitly allowlisted metrics.
        echo $json;
        exit;
    }

    public static function bootstrap_post() {
        self::cap(); check_admin_referer( 'pfc_bootstrap' );
        PFC_Bootstrap::install();
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=overview' ) ); exit;
    }

    public static function expired_transients() {
        self::cap(); check_admin_referer( 'pfc_transients' );
        if ( empty( $_POST['confirm_expired_cleanup'] ) || '1' !== (string) wp_unslash( $_POST['confirm_expired_cleanup'] ) ) {
            wp_die( esc_html__( 'Confirm bounded expired-transient cleanup before continuing.', 'performance-console' ), '', array( 'response' => 400 ) );
        }
        $result = PFC_Database_Repair::execute( 'cleanup_expired_transients', array( 'limit' => 1000 ), false );
        self::store_repair_result( $result, 'cleanup_expired_transients' );
        PFC_Scanner::scan( false, false );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=database&dbfixed=1#database-repair-centre' ) ); exit;
    }

    public static function database_fix_post() {
        self::cap(); check_admin_referer( 'pfc_db_fix' );
        $action = sanitize_key( wp_unslash( $_POST['repair_action'] ?? '' ) );
        $args = array();
        if ( isset( $_POST['type'] ) ) { $args['type'] = sanitize_key( wp_unslash( $_POST['type'] ) ); }
        if ( isset( $_POST['table'] ) ) { $args['table'] = sanitize_text_field( wp_unslash( $_POST['table'] ) ); }
        if ( isset( $_POST['index'] ) ) { $args['index'] = sanitize_text_field( wp_unslash( $_POST['index'] ) ); }
        if ( isset( $_POST['keep_index'] ) ) { $args['keep_index'] = sanitize_text_field( wp_unslash( $_POST['keep_index'] ) ); }
        if ( isset( $_POST['column'] ) ) { $args['column'] = sanitize_text_field( wp_unslash( $_POST['column'] ) ); }
        if ( isset( $_POST['option'] ) ) { $args['option'] = sanitize_text_field( wp_unslash( $_POST['option'] ) ); }
        if ( isset( $_POST['backup_confirmed'] ) ) { $args['backup_confirmed'] = '1'; }
        if ( isset( $_POST['backup_id'] ) ) { $args['backup_id'] = absint( $_POST['backup_id'] ); }
        if ( isset( $_POST['danger_confirmed'] ) ) { $args['danger_confirmed'] = '1'; }
        if ( isset( $_POST['data_loss_confirmed'] ) ) { $args['data_loss_confirmed'] = '1'; }
        if ( isset( $_POST['limit'] ) ) { $args['limit'] = max( 1, min( PFC_Database_Repair::MAX_BATCH, absint( $_POST['limit'] ) ) ); }
        if ( isset( $_POST['change_id'] ) ) { $args['change_id'] = absint( $_POST['change_id'] ); }
        if ( isset( $_POST['thread_id'] ) ) { $args['thread_id'] = absint( $_POST['thread_id'] ); }
        if ( isset( $_POST['transaction_id'] ) ) { $args['transaction_id'] = sanitize_text_field( wp_unslash( $_POST['transaction_id'] ) ); }
        if ( isset( $_POST['rollback_confirmed'] ) ) { $args['rollback_confirmed'] = '1'; }
        if ( isset( $_POST['high_rollback_confirmed'] ) ) { $args['high_rollback_confirmed'] = '1'; }
        $force_large = ! empty( $_POST['force_large'] ) && ! empty( $_POST['maintenance_confirmed'] );
        if ( $force_large ) {
            $args['maintenance_confirmed'] = '1';
            ignore_user_abort( true );
            @set_time_limit( 0 );
            if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }
        }
        $result = PFC_Database_Repair::execute( $action, $args, $force_large );
        self::store_repair_result( $result, $action );
        PFC_Scanner::scan( false, false );
        $target = is_wp_error( $result ) && 'pfc_innodb_busy' === $result->get_error_code() ? 'innodb-transaction-manager' : 'database-repair-centre';
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=database&dbfixed=1#' . $target ) ); exit;
    }

    private static function store_repair_result( $result, $action ) {
        if ( is_wp_error( $result ) ) {
            $payload = array( 'ok' => false, 'action' => $action, 'error_code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'at' => time() );
            $data = $result->get_error_data();
            if ( is_array( $data ) ) { $payload['error_data'] = $data; }
        } else {
            $payload = array_merge( array( 'ok' => true, 'action' => $action, 'at' => time() ), is_array( $result ) ? $result : array() );
        }
        set_transient( 'pfc_last_db_repair_' . get_current_user_id(), $payload, 10 * MINUTE_IN_SECONDS );
    }

    public static function profile_post() {
        self::cap(); check_admin_referer( 'pfc_profile' );
        $url = self::own_url( isset( $_POST['profile_url'] ) ? wp_unslash( $_POST['profile_url'] ) : home_url( '/' ) );
        if ( is_wp_error( $url ) ) { wp_die( esc_html( $url->get_error_message() ) ); }
        $runs = max( 1, min( 5, absint( $_POST['runs'] ?? 3 ) ) );
        $probe_meta = array();
        $times = self::timed_probe( $url, '', $runs, $probe_meta );
        set_transient( 'pfc_last_profile_' . get_current_user_id(), array( 'url' => $url, 'times' => $times, 'probe_meta' => $probe_meta, 'at' => time() ), 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=profiling&profiled=1#profiling' ) ); exit;
    }

    public static function save_capture_post() {
        self::cap(); check_admin_referer( 'pfc_save_capture' );
        $capture_action = sanitize_key( wp_unslash( $_POST['capture_action'] ?? 'arm' ) );
        if ( 'cancel' === $capture_action ) {
            PFC_Bootstrap::clear_save_capture();
            $notice = array( 'ok' => true, 'message' => __( 'The pending save capture was cancelled.', 'performance-console' ) );
        } else {
            $kind = sanitize_key( wp_unslash( $_POST['capture_kind'] ?? 'manual' ) );
            $result = PFC_Bootstrap::arm_save_capture( get_current_user_id(), $kind );
            $notice = is_wp_error( $result )
                ? array( 'ok' => false, 'message' => $result->get_error_message() )
                : array( 'ok' => true, 'message' => 'Capture armed. Save a post, page, product, or other content item in this browser within ten minutes.' );
        }
        set_transient( 'pfc_save_capture_notice_' . get_current_user_id(), $notice, 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=profiling#save-profiling' ) ); exit;
    }

    public static function plugin_impact_post() {
        self::cap(); check_admin_referer( 'pfc_plugin_impact' );
        $url = self::own_url( isset( $_POST['profile_url'] ) ? wp_unslash( $_POST['profile_url'] ) : home_url( '/' ) );
        if ( is_wp_error( $url ) ) { wp_die( esc_html( $url->get_error_message() ) ); }
        $plugin = sanitize_text_field( wp_unslash( $_POST['plugin'] ?? '' ) );
        $required_text = PFC_Utils::truncate( sanitize_text_field( wp_unslash( $_POST['required_text'] ?? '' ) ), 0, 200 );
        $active = (array) get_option( 'active_plugins', array() );
        $network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
        if ( ! in_array( $plugin, $active, true ) && ! in_array( $plugin, $network, true ) ) { wp_die( esc_html__( 'Select an active plugin.', 'performance-console' ) ); }
        if ( $plugin === PFC_BASENAME ) { wp_die( esc_html__( 'Performance Console cannot exclude itself from its own diagnostic request.', 'performance-console' ) ); }
        $baseline_meta = array(); $without_meta = array();
        $warmup_meta = array();
        self::timed_probe( $url, '', 1, $warmup_meta );
        self::timed_probe( $url, $plugin, 1, $warmup_meta );
        $baseline = array(); $without = array(); $paired_deltas = array();
        for ( $pair = 0; $pair < 5; $pair++ ) {
            $first = 0 === $pair % 2 ? '' : $plugin;
            $second = '' === $first ? $plugin : '';
            $pair_values = array( 'baseline' => null, 'without' => null );
            foreach ( array( $first, $second ) as $exclude ) {
                $probe_meta = array();
                $values = self::timed_probe( $url, $exclude, 1, $probe_meta, $required_text );
                if ( '' === $exclude ) { $baseline = array_merge( $baseline, $values ); $pair_values['baseline'] = $values ? (float) $values[0] : null; self::merge_probe_meta( $baseline_meta, $probe_meta ); }
                else { $without = array_merge( $without, $values ); $pair_values['without'] = $values ? (float) $values[0] : null; self::merge_probe_meta( $without_meta, $probe_meta ); }
            }
            if ( null !== $pair_values['baseline'] && null !== $pair_values['without'] ) { $paired_deltas[] = round( $pair_values['baseline'] - $pair_values['without'], 3 ); }
        }
        $result = array(
            'url' => $url, 'plugin' => $plugin, 'baseline' => $baseline, 'without' => $without, 'baseline_meta' => $baseline_meta, 'without_meta' => $without_meta,
            'content_assertion_enabled' => '' !== $required_text,
            'baseline_median' => PFC_Utils::median( $baseline ), 'without_median' => PFC_Utils::median( $without ), 'pair_deltas' => $paired_deltas, 'at' => time(),
        );
        $result['comparable'] = self::comparable_probes( $baseline_meta, $without_meta );
        $result = array_merge( $result, PFC_Utils::analyze_paired_impact( $paired_deltas, $result['comparable'] ) );
        set_transient( 'pfc_last_impact_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=profiling&impact=1#profiling' ) ); exit;
    }

    public static function settings_post() {
        self::cap(); check_admin_referer( 'pfc_settings' );
        $sample_percent = max( 0, min( 100, (float) ( $_POST['sample_percent'] ?? 0.02 ) ) );
        $rum_percent = max( 0, min( 100, (float) ( $_POST['rum_percent'] ?? 0.5 ) ) );
        $retention = max( 7, min( 365, absint( $_POST['retention_days'] ?? 30 ) ) );
        $runtime = get_option( 'pfc_runtime', array() );
        // A partially migrated or hand-edited option can be a scalar, and assigning
        // an array key to it was a fatal error on this save path.
        if ( ! is_array( $runtime ) ) { $runtime = array(); }
        $route_lines = preg_split( '/\r?\n/', (string) wp_unslash( $_POST['route_urls'] ?? '' ) );
        $route_urls = array();
        foreach ( (array) $route_lines as $route_url ) {
            $route_url = esc_url_raw( trim( $route_url ), array( 'http', 'https' ) );
            if ( $route_url && PFC_Utils::same_origin_url( $route_url ) ) { $route_urls[] = $route_url; }
        }
        $runtime['sample_rate'] = $sample_percent / 100;
        $runtime['rum_rate'] = $rum_percent / 100;
        $runtime['retention_days'] = $retention;
        $runtime['route_urls'] = array_slice( array_values( array_unique( $route_urls ) ), 0, 8 );
        update_option( 'pfc_runtime', $runtime, true );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=monitoring&settings=1#monitoring' ) ); exit;
    }

    public static function incident_action_post() {
        self::cap();
        $key = sanitize_text_field( wp_unslash( $_POST['incident_key'] ?? '' ) );
        check_admin_referer( 'pfc_incident_' . $key );
        $action = sanitize_key( wp_unslash( $_POST['incident_action'] ?? '' ) );
        $incident = PFC_Utils::incident( $key );
        if ( ! $incident ) { wp_die( esc_html__( 'That incident no longer exists.', 'performance-console' ) ); }

        $message = __( 'Incident updated.', 'performance-console' );
        if ( 'resolve' === $action ) { PFC_Utils::set_incident_status( $key, 'resolved' ); $message = __( 'Incident marked resolved.', 'performance-console' ); }
        elseif ( 'snooze' === $action ) { PFC_Utils::set_incident_status( $key, 'snoozed', 7 ); $message = __( 'Incident snoozed for seven days.', 'performance-console' ); }
        elseif ( 'accept' === $action ) { PFC_Utils::set_incident_status( $key, 'accepted' ); $message = __( 'Incident recorded as accepted risk.', 'performance-console' ); }
        elseif ( 'reopen' === $action ) { PFC_Utils::set_incident_status( $key, 'open' ); $message = __( 'Incident reopened.', 'performance-console' ); }
        elseif ( 'verify' === $action ) {
            PFC_Utils::set_incident_status( $key, 'verifying' );
            if ( 'save' === (string) ( $incident['area'] ?? '' ) ) {
                global $wpdb;
                $previous_payload = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . PFC_Utils::table( 'runs' ) . ' WHERE id=%d AND mode=%s', absint( $incident['last_run_id'] ?? 0 ), 'save' ) );
                $previous = json_decode( (string) $previous_payload, true );
                $context = (array) ( $previous['save_context'] ?? array() );
                $kind = in_array( ( $context['requested_kind'] ?? '' ), array( 'autosave', 'metabox' ), true ) ? $context['requested_kind'] : 'manual';
                $capture = PFC_Bootstrap::arm_save_capture( get_current_user_id(), $kind );
                PFC_Utils::set_incident_status( $key, 'open' );
                if ( ! is_wp_error( $capture ) ) { set_transient( 'pfc_save_verify_' . sanitize_key( (string) $capture['capture_id'] ), array( 'key' => $key, 'context' => $context ), 15 * MINUTE_IN_SECONDS ); }
                set_transient( 'pfc_save_capture_notice_' . get_current_user_id(), is_wp_error( $capture ) ? array( 'ok' => false, 'message' => $capture->get_error_message() ) : array( 'ok' => true, 'message' => sprintf( __( 'Recheck armed for %1$s: content ID %2$d. Use the same editor, content and account. A successful quick save moves the incident to observing, not resolved.', 'performance-console' ), $kind, absint( $context['post_id'] ?? 0 ) ) ), 10 * MINUTE_IN_SECONDS );
                wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=profiling#save-profiling' ) ); exit;
            }
            $route = '';
            foreach ( (array) ( $incident['routes'] ?? array() ) as $candidate ) {
                if ( filter_var( $candidate, FILTER_VALIDATE_URL ) ) { $route = $candidate; break; }
            }
            if ( 'frontend' === (string) ( $incident['area'] ?? '' ) && $route && PFC_Utils::same_origin_url( $route ) ) {
                // A shallow scan probes only a few recently published routes and
                // may never visit this incident's URL. Recheck this exact route.
                $page = PFC_Frontend_Health::inspect_single_route( $route );
                if ( ! empty( $page['inconclusive'] ) || empty( $page['ok'] ) ) {
                    PFC_Utils::set_incident_status( $key, 'open' );
                    $message = __( 'Route recheck was inconclusive. The incident remains open; check the response and retry.', 'performance-console' );
                } else {
                    PFC_Utils::begin_issue_collection( 'manual' );
                    PFC_Frontend_Health::generate_issues( array( 'pages' => array( $page ) ) );
                    $observed = PFC_Utils::collected_incidents();
                    PFC_Utils::end_issue_collection();
                    $reproduced = in_array( $key, $observed, true );
                    PFC_Utils::set_incident_status( $key, $reproduced ? 'open' : 'observing' );
                    $message = $reproduced
                        ? __( 'Exact-route HTTP recheck confirmed the slow response. Severity and evidence have been refreshed. Compare with signed PHP time for the same URL.', 'performance-console' )
                        : __( 'Slow-response threshold was not reproduced on this exact route. The incident is now observing, not resolved; other affected routes were not rechecked.', 'performance-console' );
                }
            } elseif ( 'scan' === (string) ( $incident['source'] ?? '' ) || 'legacy' === (string) ( $incident['source'] ?? '' ) ) {
                $scan = PFC_Scanner::scan( false, false );
                $reproduced = in_array( $key, (array) ( $scan['observed_incidents'] ?? array() ), true );
                PFC_Utils::set_incident_status( $key, $reproduced ? 'open' : 'observing' );
                $message = $reproduced ? __( 'Recheck confirmed that the incident is still occurring.', 'performance-console' ) : __( 'Not reproduced by this safe scan. The incident is now observing, not resolved: skipped or deep checks may still be needed.', 'performance-console' );
            } elseif ( $route ) {
                $url = self::own_url( $route );
                $meta = array();
                if ( ! is_wp_error( $url ) ) { self::timed_probe( $url, '', 3, $meta ); }
                $valid = ! is_wp_error( $url ) && 3 === (int) ( $meta['verified'] ?? 0 );
                $after = PFC_Utils::incident( $key );
                $reproduced = $after && 'open' === (string) $after['status'];
                if ( ! $reproduced ) { PFC_Utils::set_incident_status( $key, $valid ? 'observing' : 'open' ); }
                $message = ! $valid ? __( 'Recheck inconclusive: three verified site responses were not received. The incident remains open. Check loopback access and the diagnostic bootstrap, then retry.', 'performance-console' ) : ( $reproduced ? __( 'Recheck confirmed that the incident is still occurring.', 'performance-console' ) : __( 'Not reproduced in three anonymous route probes. The incident is observing; this does not verify logged-in or background requests.', 'performance-console' ) );
            } else {
                PFC_Utils::set_incident_status( $key, 'observing' );
                $message = __( 'This incident needs a new matching production sample. It is now observing and will reopen only if it recurs.', 'performance-console' );
            }
        } else { wp_die( esc_html__( 'Unknown incident action.', 'performance-console' ) ); }

        set_transient( 'pfc_incident_notice_' . get_current_user_id(), $message, 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=findings#findings' ) ); exit;
    }

    private static function own_url( $url ) {
        $url = trim( (string) $url );
        if ( ! wp_parse_url( $url, PHP_URL_HOST ) ) { $url = home_url( '/' . ltrim( $url, '/' ) ); }
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( ! $url ) { return new WP_Error( 'pfc_url', 'Enter a valid URL on this WordPress site.' ); }
        if ( self::url_origin( $url ) !== self::url_origin( home_url() ) ) { return new WP_Error( 'pfc_url_host', 'Diagnostics are restricted to the configured WordPress origin, including its scheme and port.' ); }
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( 0 === strpos( trailingslashit( $path ), trailingslashit( (string) wp_parse_url( admin_url(), PHP_URL_PATH ) ) ) ) { return new WP_Error( 'pfc_url_admin', 'Profile a public route. Admin pages require an authenticated browser session and would produce misleading loopback results.' ); }
        return $url;
    }

    private static function url_origin( $url ) {
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $port = (int) wp_parse_url( $url, PHP_URL_PORT );
        if ( ! $port ) { $port = 'https' === $scheme ? 443 : 80; }
        return $scheme . '://' . $host . ':' . $port;
    }

    private static function timed_probe( $url, $exclude, $runs, &$meta = null, $required_text = '', $capture_cache = false, $php_callbacks = false ) {
        $times = array();
        $meta = array( 'requested' => (int) $runs, 'verified' => 0, 'http_errors' => 0, 'bad_status' => 0, 'redirects' => 0, 'probe_mismatch' => 0, 'server_missing' => 0, 'statuses' => array(), 'content_types' => array(), 'body_bytes' => array(), 'wall_ms' => array(), 'php_ms' => array(), 'db_ms' => array(), 'query_counts' => array(), 'cache_bypassed' => true );
        for ( $i = 0; $i < $runs; $i++ ) {
            $probe_id = wp_generate_uuid4();
            $token = PFC_Bootstrap::token( $url, $exclude, $probe_id, $php_callbacks );
            $probe = add_query_arg( $token, $url );
            $start = microtime( true );
            $res = wp_remote_get( $probe, array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ) ) );
            $elapsed = round( ( microtime( true ) - $start ) * 1000, 1 );
            if ( is_wp_error( $res ) ) { $meta['http_errors']++; continue; }
            $header_probe = trim( (string) wp_remote_retrieve_header( $res, 'x-pfc-probe-id' ) );
            $header_exclude = trim( (string) wp_remote_retrieve_header( $res, 'x-pfc-excluded-plugin' ) );
            $verified = '1' === trim( (string) wp_remote_retrieve_header( $res, 'x-pfc-diagnostic' ) ) && hash_equals( $probe_id, $header_probe ) && hash_equals( $exclude ?: 'none', $header_exclude );
            if ( ! $verified ) { $meta['cache_bypassed'] = false; $meta['probe_mismatch']++; continue; }
            $status = (int) wp_remote_retrieve_response_code( $res );
            $meta['statuses'][] = $status;
            if ( $status >= 300 && $status < 400 ) { $meta['redirects']++; $meta['bad_status']++; continue; }
            if ( $status < 200 || $status >= 400 ) { $meta['bad_status']++; continue; }
            $body = (string) wp_remote_retrieve_body( $res );
            if ( ! PFC_Utils::response_contains_text( $body, $required_text ) ) { $meta['bad_status']++; continue; }
            $content_type = strtolower( trim( strtok( (string) wp_remote_retrieve_header( $res, 'content-type' ), ';' ) ) );
            if ( strlen( $body ) < 128 || '' === $content_type ) { $meta['bad_status']++; continue; }
            $meta['body_bytes'][] = strlen( $body );
            $meta['content_types'][] = $content_type;
            $server = PFC_Utils::probe_run( $probe_id, $exclude );
            if ( ! $server ) { $meta['server_missing']++; continue; }
            $meta['verified']++;
            $meta['wall_ms'][] = $elapsed;
            $meta['php_ms'][] = round( (float) $server['php_ms'], 1 );
            $meta['db_ms'][] = round( (float) $server['db_ms'], 1 );
            $meta['query_counts'][] = (int) $server['query_count'];
            if ( $capture_cache ) {
                $details = PFC_Request_Cache::probe_details( $probe_id );
                $meta['cache_runs'][] = array(
                    'php_ms' => round( (float) $server['php_ms'], 2 ),
                    'db_ms' => round( (float) $server['db_ms'], 2 ),
                    'sql_timing_available' => ! empty( $details['sql_timing_available'] ),
                    'http_ms' => round( (float) $server['http_ms'], 2 ),
                    'queries' => (int) $server['query_count'],
                    'cache' => (array) ( $details['cache'] ?? array( 'available' => false ) ),
                    'sql_components' => (array) ( $details['sql_components'] ?? array() ),
                    'sql_patterns' => (array) ( $details['sql_patterns'] ?? array() ),
                    'sql_callsites' => (array) ( $details['sql_callsites'] ?? array() ),
                    'sql_callsites_truncated' => ! empty( $details['sql_callsites_truncated'] ),
                    'http_requests' => (array) ( $details['http_requests'] ?? array() ),
                    'http_callsites' => (array) ( $details['http_callsites'] ?? array() ),
                    'http_truncated' => ! empty( $details['http_truncated'] ),
                    'phase_timings' => (array) ( $details['phase_timings'] ?? array() ),
                    'callback_profile' => (array) ( $details['callback_profile'] ?? array( 'enabled' => false ) ),
                    'bricks_profile' => (array) ( $details['bricks_profile'] ?? array( 'enabled' => false ) ),
                    'cache_attribution_capability' => (array) ( $details['cache_attribution_capability'] ?? array() ),
                );
            }
            $times[] = round( (float) $server['php_ms'], 1 );
        }
        return $times;
    }

    private static function merge_probe_meta( array &$target, array $source ) {
        if ( ! $target ) { $target = array( 'requested' => 0, 'verified' => 0, 'http_errors' => 0, 'bad_status' => 0, 'redirects' => 0, 'probe_mismatch' => 0, 'server_missing' => 0, 'statuses' => array(), 'content_types' => array(), 'body_bytes' => array(), 'wall_ms' => array(), 'php_ms' => array(), 'db_ms' => array(), 'query_counts' => array(), 'cache_bypassed' => true ); }
        foreach ( array( 'requested', 'verified', 'http_errors', 'bad_status', 'redirects', 'probe_mismatch', 'server_missing' ) as $key ) { $target[ $key ] += (int) ( $source[ $key ] ?? 0 ); }
        foreach ( array( 'statuses', 'content_types', 'body_bytes', 'wall_ms', 'php_ms', 'db_ms', 'query_counts' ) as $key ) { $target[ $key ] = array_merge( $target[ $key ], (array) ( $source[ $key ] ?? array() ) ); }
        $target['cache_bypassed'] = $target['cache_bypassed'] && ! empty( $source['cache_bypassed'] );
    }

    private static function comparable_probes( array $baseline, array $without ) {
        $base_types = array_values( array_unique( (array) ( $baseline['content_types'] ?? array() ) ) );
        $without_types = array_values( array_unique( (array) ( $without['content_types'] ?? array() ) ) );
        $base_bytes = PFC_Utils::median( array_map( 'floatval', (array) ( $baseline['body_bytes'] ?? array() ) ) );
        $without_bytes = PFC_Utils::median( array_map( 'floatval', (array) ( $without['body_bytes'] ?? array() ) ) );
        $size_ratio = $base_bytes > 0 ? $without_bytes / $base_bytes : 0;
        return $base_types && $base_types === $without_types && $size_ratio >= 0.65 && $size_ratio <= 1.35;
    }

    public static function page() {
        self::cap(); global $wpdb;
        $allowed_views = array( 'overview', 'findings', 'database', 'profiling', 'monitoring', 'system', 'cache' );
        $active_view = sanitize_key( wp_unslash( $_GET['view'] ?? 'overview' ) );
        if ( ! in_array( $active_view, $allowed_views, true ) ) { $active_view = 'overview'; }
        // Version-checked: a report cached by an older release is discarded rather
        // than rendered with a shape this release no longer expects.
        $scan = PFC_Scanner::cached_scan();
        $incident_filter = sanitize_key( wp_unslash( $_GET['incident_status'] ?? 'active' ) );
        $incident_statuses = array( 'active' => array( 'open', 'verifying' ), 'watching' => array( 'observing' ), 'snoozed' => array( 'snoozed' ), 'resolved' => array( 'resolved' ), 'accepted' => array( 'accepted' ), 'all' => array( 'open', 'verifying', 'observing', 'snoozed', 'resolved', 'accepted' ) );
        if ( ! isset( $incident_statuses[ $incident_filter ] ) ) { $incident_filter = 'active'; }
        $issues = in_array( $active_view, array( 'overview', 'findings' ), true ) ? PFC_Utils::incidents( 'overview' === $active_view ? $incident_statuses['active'] : $incident_statuses[ $incident_filter ] ) : array();
        $counts = PFC_Utils::incident_summary();
        $open_issue_total = array_sum( $counts );
        $runs = 'profiling' === $active_view ? $wpdb->get_results( 'SELECT * FROM ' . PFC_Utils::table( 'runs' ) . ' ORDER BY id DESC LIMIT 30' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $save_runs = 'profiling' === $active_view ? $wpdb->get_results( "SELECT * FROM " . PFC_Utils::table( 'runs' ) . " WHERE mode='save' ORDER BY id DESC LIMIT 30" ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $save_runs ) {
            $save_runs = array_values( array_filter( $save_runs, static function ( $run ) {
                $payload = json_decode( (string) $run->payload, true );
                return get_current_user_id() === (int) ( $payload['save_context']['user_id'] ?? 0 );
            } ) );
            $save_runs = array_slice( $save_runs, 0, 10 );
        }
        $latest_save = $save_runs[0] ?? null;
        $queries = 'profiling' === $active_view ? $wpdb->get_results( 'SELECT q.*,r.route,r.created_at FROM ' . PFC_Utils::table( 'queries' ) . ' q LEFT JOIN ' . PFC_Utils::table( 'runs' ) . ' r ON r.id=q.run_id ORDER BY q.id DESC LIMIT 80' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $recent_run_count = 'overview' === $active_view ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PFC_Utils::table( 'runs' ) . ' WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $query_pattern_count = 'overview' === $active_view ? (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT pattern_hash) FROM ' . PFC_Utils::table( 'queries' ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rum = 'monitoring' === $active_view ? $wpdb->get_results( $wpdb->prepare( 'SELECT metric,route_group,SUM(samples) samples,SUM(value_sum)/SUM(samples) average_value,MAX(value_max) value_max,SUM(bucket_0) bucket_0,SUM(bucket_1) bucket_1,SUM(bucket_2) bucket_2,SUM(bucket_3) bucket_3,SUM(bucket_4) bucket_4,SUM(bucket_5) bucket_5,SUM(bucket_6) bucket_6,SUM(bucket_7) bucket_7 FROM ' . PFC_Utils::table( 'metrics' ) . ' WHERE metric_version=2 AND bucket >= %s GROUP BY metric,route_group ORDER BY metric, samples DESC', gmdate( 'Y-m-d H:00:00', time() - DAY_IN_SECONDS ) ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $changes = 'monitoring' === $active_view ? $wpdb->get_results( 'SELECT * FROM ' . PFC_Utils::table( 'changes' ) . ' ORDER BY id DESC LIMIT 20', ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $profile = get_transient( 'pfc_last_profile_' . get_current_user_id() );
        $impact = get_transient( 'pfc_last_impact_' . get_current_user_id() );
        $save_capture_status = 'profiling' === $active_view ? PFC_Bootstrap::save_capture_status() : array();
        $save_capture_notice = get_transient( 'pfc_save_capture_notice_' . get_current_user_id() );
        if ( false !== $save_capture_notice ) { delete_transient( 'pfc_save_capture_notice_' . get_current_user_id() ); }
        $repair_notice_key = 'pfc_last_db_repair_' . get_current_user_id();
        $repair_result = get_transient( $repair_notice_key );
        // Repair results are flash notices. Keeping an old database error for ten minutes made
        // unrelated tools such as Deep Scan appear to be failing with the previous repair error.
        if ( false !== $repair_result ) { delete_transient( $repair_notice_key ); }
        $repairs = 'database' === $active_view ? ( ! empty( $scan['database_repairs'] ) ? (array) $scan['database_repairs'] : ( ! empty( $scan['database_health'] ) ? PFC_Database_Repair::plans( $scan['database_health'] ) : array() ) ) : array();
        $innodb_manager = 'database' === $active_view ? PFC_Database_Repair::transaction_manager_snapshot() : array();
        $backups = 'database' === $active_view ? PFC_Database_Backup::list_backups( 20 ) : array();
        $verified_backups = array_values( array_filter( $backups, static function ( $backup ) { return PFC_Database_Backup::is_verified_recent( (int) ( $backup['id'] ?? 0 ), PFC_Database_Backup::VERIFIED_MAX_AGE, false ); } ) );
        $backup_storage = 'database' === $active_view ? PFC_Database_Backup::storage_summary() : array();
        $backup_notice = get_transient( 'pfc_backup_notice_' . get_current_user_id() );
        $runtime = get_option( 'pfc_runtime', array( 'sample_rate' => 0.0002, 'rum_rate' => 0.005, 'retention_days' => 30 ) );
        $active_plugins = 'profiling' === $active_view ? array_filter( PFC_Scanner::plugins(), static function ( $p ) { return ! empty( $p['active'] ); } ) : array();
        $bootstrap_status = in_array( $active_view, array( 'overview', 'cache' ), true ) ? PFC_Bootstrap::status() : array();
        $cache_diagnostic = 'cache' === $active_view ? get_transient( 'pfc_cache_diagnostic_' . get_current_user_id() ) : false;
        $cache_profile = 'cache' === $active_view ? get_transient( 'pfc_cache_profile_' . get_current_user_id() ) : false;
        $cache_previous = 'cache' === $active_view ? get_transient( 'pfc_cache_profile_previous_' . get_current_user_id() ) : false;
        $cache_mode_comparison = 'cache' === $active_view ? get_transient( 'pfc_cache_mode_comparison_' . get_current_user_id() ) : false;
        $http_self_test = 'cache' === $active_view ? get_transient( 'pfc_http_self_test_' . get_current_user_id() ) : false;
        $incident_notice = get_transient( 'pfc_incident_notice_' . get_current_user_id() );
        if ( false !== $incident_notice ) { delete_transient( 'pfc_incident_notice_' . get_current_user_id() ); }
        ?>
        <div class="wrap pfc-wrap cgm-pfc-root" data-active-view="<?php echo esc_attr( $active_view ); ?>">
        <header class="pfc-suite-header">
            <div class="pfc-suite-mark" aria-hidden="true"><span class="dashicons dashicons-performance"></span></div>
            <div>
                <h1 class="pfc-suite-title">Performance Console <span class="pfc-suite-version">v<?php echo esc_html( PFC_VERSION ); ?></span></h1>
                <p class="pfc-suite-subtitle">Production performance console</p>
            </div>
        </header>
        <nav class="pfc-suite-nav" aria-label="<?php esc_attr_e( 'Performance Console sections', 'performance-console' ); ?>">
            <?php foreach ( array( 'overview' => array( 'dashicons-dashboard', __( 'Overview', 'performance-console' ) ), 'findings' => array( 'dashicons-warning', __( 'Incidents', 'performance-console' ) ), 'database' => array( 'dashicons-database', __( 'Database', 'performance-console' ) ), 'profiling' => array( 'dashicons-chart-area', __( 'Profiling', 'performance-console' ) ), 'monitoring' => array( 'dashicons-chart-line', __( 'Monitoring', 'performance-console' ) ), 'system' => array( 'dashicons-admin-tools', __( 'System', 'performance-console' ) ), 'cache' => array( 'dashicons-performance', __( 'Cache & Redis', 'performance-console' ) ) ) as $view_slug => $view_meta ): ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pfc&view=' . $view_slug ) . '#' . $view_slug ); ?>" data-pfc-tab="<?php echo esc_attr( $view_slug ); ?>"<?php echo $active_view === $view_slug ? ' aria-current="page"' : ''; ?>><span class="dashicons <?php echo esc_attr( $view_meta[0] ); ?>" aria-hidden="true"></span><?php echo esc_html( $view_meta[1] ); ?><?php if ( 'findings' === $view_slug ): ?> <span class="pfc-nav-count"><?php echo esc_html( $open_issue_total > 999 ? '999+' : (string) $open_issue_total ); ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ( 'overview' === $active_view ): ?>
        <section class="pfc-view" data-pfc-view="overview" id="pfc-view-overview">
        <div class="pfc-view-heading"><h2>Performance health</h2><p>Scan the site, work through confirmed root-cause incidents, and verify each change against the same evidence.</p></div>
        <div class="pfc-actions">
        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="pfc_scan"><?php wp_nonce_field('pfc_scan'); ?><button class="button button-primary"><span class="dashicons dashicons-search" aria-hidden="true"></span>Run Production-Safe Scan</button></form>
        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="pfc_scan"><input type="hidden" name="deep" value="1"><?php wp_nonce_field('pfc_scan'); ?><button class="button"><span class="dashicons dashicons-database" aria-hidden="true"></span>Run Deep Database Scan</button></form>
        <?php if ( empty( $bootstrap_status['installed'] ) || empty( $bootstrap_status['current'] ) ): ?><form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="pfc_bootstrap"><?php wp_nonce_field('pfc_bootstrap'); ?><button class="button"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>Repair diagnostic bootstrap</button></form><?php endif; ?>
        </div>
        <div class="pfc-cards">
            <div class="pfc-card pfc-card--critical"><span>Critical incidents</span><strong class="pfc-critical"><?php echo intval($counts['critical']); ?></strong></div>
            <div class="pfc-card pfc-card--high"><span>High incidents</span><strong class="pfc-high"><?php echo intval($counts['high']); ?></strong></div>
            <div class="pfc-card pfc-card--warning"><span>Warnings</span><strong class="pfc-warning"><?php echo intval($counts['warning']); ?></strong></div>
            <div class="pfc-card pfc-card--good"><span>Request samples, 24 hours</span><strong><?php echo intval( $recent_run_count ); ?></strong></div>
            <div class="pfc-card"><span>Captured query patterns</span><strong><?php echo intval( $query_pattern_count ); ?></strong></div>
        </div>
        <div class="pfc-overview-layout">
            <div class="pfc-box">
                <h3>Priority queue</h3>
                <p>Confirmed incidents ranked by severity, recurrence and freshness.</p>
                <?php if ( ! $issues ): ?>
                    <div class="pfc-empty">No open findings yet. Run a scan to establish a baseline.</div>
                <?php else: ?>
                    <div class="pfc-priority-list">
                    <?php foreach ( array_slice( $issues, 0, 6 ) as $priority ): ?>
                        <div class="pfc-priority-item">
                            <span class="pfc-status pfc-status--<?php echo esc_attr( $priority['severity'] ); ?>"><?php echo esc_html( strtoupper( $priority['severity'] ) ); ?></span>
                            <div class="pfc-priority-item__body"><strong><?php echo esc_html( $priority['title'] ); ?></strong><small><?php echo esc_html( wp_strip_all_tags( (string) $priority['message'] ) ); ?></small></div>
                            <span class="pfc-priority-item__impact"><?php echo esc_html( $priority['impact'] ?: $priority['area'] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pfc&view=findings#findings' ) ); ?>">Review all <?php echo esc_html( number_format_i18n( $open_issue_total ) ); ?> confirmed incidents</a></p>
                <?php endif; ?>
            </div>
            <div class="pfc-box">
                <h3>Latest scan snapshot</h3>
                <?php if ( ! $scan ): ?>
                    <div class="pfc-empty">No completed scan is cached yet.</div>
                <?php else: ?>
                    <p><small><?php echo esc_html( strtoupper( (string) ( $scan['mode'] ?? 'scan' ) ) ); ?> · <?php echo esc_html( (string) ( $scan['generated_at'] ?? '' ) ); ?> UTC</small></p>
                    <div class="pfc-snapshot">
                        <div><small>Database</small><strong><?php echo esc_html( size_format( (int) ( $scan['database']['total_size'] ?? 0 ) ) ); ?></strong></div>
                        <div><small>Autoload</small><strong><?php echo esc_html( size_format( (int) ( $scan['autoload']['total_bytes'] ?? 0 ) ) ); ?></strong></div>
                        <div><small>Active plugins</small><strong><?php echo intval( $scan['system']['plugins']['active_count'] ?? 0 ); ?></strong></div>
                        <div><small>Overdue cron</small><strong><?php echo intval( $scan['cron']['overdue'] ?? 0 ); ?></strong></div>
                        <div><small>Persistent cache</small><strong><?php echo ! empty( $scan['cache']['persistent'] ) ? 'Active' : 'No'; ?></strong></div>
                        <div><small>DB integrity</small><strong><?php echo intval( count( (array) ( $scan['database_health']['integrity']['problems'] ?? array() ) ) ); ?> issue(s)</strong></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </section>
        <?php endif; ?>

        <?php if ( 'database' === $active_view ): ?>
        <section class="pfc-view" data-pfc-view="database" id="pfc-view-database">
        <div class="pfc-view-heading"><h2>Database integrity and repair</h2><p>Back up the database, resolve blocking InnoDB transactions, and apply guarded repairs from one place.</p></div>
        <?php if ( $repair_result ): ?><div class="notice <?php echo ! empty( $repair_result['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline"><p><strong>Database repair:</strong> <?php echo esc_html( $repair_result['message'] ?? ( ! empty( $repair_result['ok'] ) ? 'Completed.' : 'Failed.' ) ); ?><?php if('pfc_innodb_busy'===($repair_result['error_code']??'')): ?> <a href="#innodb-transaction-manager"><strong>Open InnoDB Transaction Manager</strong></a><?php endif; ?></p></div><?php endif; ?>
        <?php if ( $backup_notice ): ?><div class="notice <?php echo ! empty( $backup_notice['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline"><p><strong>Database backup:</strong> <?php echo esc_html( $backup_notice['message'] ?? '' ); ?></p></div><?php delete_transient( 'pfc_backup_notice_' . get_current_user_id() ); endif; ?>
        <?php if ( $repair_result && 'innodb_recovery_preflight' === ( $repair_result['action'] ?? '' ) && ! empty( $repair_result['ok'] ) ): ?>
        <div class="pfc-box pfc-preflight"><h3>Latest InnoDB Recovery Preflight</h3>
        <p><strong>Table:</strong> <code><?php echo esc_html( $repair_result['table'] ?? '' ); ?></code><?php if(!empty($repair_result['meta']['size'])): ?>, <?php echo esc_html(size_format((int)$repair_result['meta']['size'])); ?><?php endif; ?></p>
        <p><strong>CHECK TABLE:</strong> <?php echo !empty($repair_result['check']['ok'])?'<span class="pfc-good">clean</span>':'<span class="pfc-critical">problem / skipped</span>'; ?> &nbsp; <strong>Online DDL:</strong> <?php echo !empty($repair_result['online_ddl']['inplace'])?'INPLACE + LOCK=NONE available':'not verified'; ?> &nbsp; <strong>innodb_force_recovery:</strong> <?php echo intval($repair_result['force_recovery']??0); ?></p>
        <?php foreach((array)($repair_result['warnings']??array()) as $warning): ?><p class="pfc-warning">⚠ <?php echo esc_html($warning); ?></p><?php endforeach; ?>
        <?php $ds=$repair_result['disk_space']??array(); ?><p><strong>Disk preflight:</strong> index rebuild <?php echo !empty($ds['index_rebuild']['available'])?( !empty($ds['index_rebuild']['ok'])?'PASS':'FAIL' ):'unknown'; ?>; table rebuild <?php echo !empty($ds['table_rebuild']['available'])?( !empty($ds['table_rebuild']['ok'])?'PASS':'FAIL' ):'unknown'; ?>.</p>
        <?php if(!empty($repair_result['runtime']['lock_graph']['edges'])): ?><p><strong>Active blocker/waiter edges:</strong> <?php echo intval(count($repair_result['runtime']['lock_graph']['edges'])); ?></p><?php endif; ?>
        <?php if(!empty($repair_result['suspect_secondary_index'])): ?><p><strong>Suspected secondary index:</strong> <code><?php echo esc_html($repair_result['suspect_secondary_index']); ?></code></p><?php endif; ?>
        <?php if(!empty($repair_result['recovery_steps'])): ?><h4>Guided recovery sequence</h4><ol><?php foreach($repair_result['recovery_steps'] as $step): ?><li><?php echo esc_html($step); ?></li><?php endforeach; ?></ol><?php endif; ?>
        <?php if(!empty($repair_result['commands'])): ?><details><summary>Reviewed commands / CLI</summary><div><?php foreach($repair_result['commands'] as $label=>$command): ?><p><strong><?php echo esc_html(str_replace('_',' ',$label)); ?>:</strong><br><code><?php echo esc_html($command); ?></code></p><?php endforeach; ?></div></details><?php endif; ?>
        <?php if(!empty($repair_result['indexes'])): ?><details><summary>Index inventory</summary><div><table class="widefat striped"><thead><tr><th>Index</th><th>Type</th><th>Unique</th><th>Columns</th></tr></thead><tbody><?php foreach($repair_result['indexes'] as $idx): ?><tr><td><code><?php echo esc_html($idx['name']); ?></code></td><td><?php echo esc_html($idx['type']); ?></td><td><?php echo !empty($idx['unique'])?'yes':'no'; ?></td><td><?php echo esc_html(implode(', ',(array)$idx['columns'])); ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
        </div><?php endif; ?>

        <div class="pfc-actions">
        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="pfc_scan"><input type="hidden" name="deep" value="1"><?php wp_nonce_field('pfc_scan'); ?><button class="button button-primary"><span class="dashicons dashicons-database" aria-hidden="true"></span>Run Deep Database Scan</button></form>
        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="pfc_expired_transients"><?php wp_nonce_field('pfc_transients'); ?><label><input type="checkbox" required name="confirm_expired_cleanup" value="1"> Confirm deletion of up to 1,000 already-expired transients (not reversible).</label> <button class="button"><span class="dashicons dashicons-trash" aria-hidden="true"></span>Delete 1,000 Expired Transients</button></form>
        </div>
        <h2 id="database-backups">Database Backups</h2>
        <div class="pfc-box">
        <p>Create a private logical database backup before applying schema, index, repair or rebuild operations. Browser backups use adaptive high-throughput batches, automatically adjust to row width, and can be resumed if a request is interrupted.</p>
        <form id="pfc-backup-create-form" class="pfc-form-row">
            <label>Backup scope<select name="backup_scope"><option value="wordpress" selected>All WordPress tables</option><option value="full">All base tables in current database</option></select></label>
            <button type="submit" class="button button-primary">Create Database Backup</button>
        </form>
        <p class="description">The logical export contains base-table schema and row data. It does not include MySQL users/grants, views, routines, triggers/events, database-server configuration or a host/filesystem snapshot. Completeness verification checks the export file and checksum; for a transactionally consistent point-in-time backup on a heavily written site, use a host/database snapshot or run the CLI export in a controlled quiet window.</p>
        <div id="pfc-backup-progress" class="notice notice-info inline" hidden><p>Backup progress will appear here.</p></div>
        <?php if ( ! empty( $backup_storage['ok'] ) ): ?><p><small><strong>Private storage:</strong> <code><?php echo esc_html( $backup_storage['path'] ); ?></code><?php echo ! empty( $backup_storage['inside_webroot'] ) ? ' (inside the web root with deny rules; verify your web server honors them)' : ' (outside the detected web root)'; ?>.</small></p><?php else: ?><p class="pfc-critical"><?php echo esc_html( $backup_storage['message'] ?? 'No backup storage directory is available.' ); ?></p><?php endif; ?>
        <?php if ( ! $backups ): ?><p>No Performance Console database backups have been created yet.</p><?php else: ?>
        <table class="widefat striped"><thead><tr><th>Backup</th><th>Status</th><th>Progress</th><th>Size / checksum</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ( $backups as $backup ): $bid=(int)$backup['id']; $bstatus=(string)$backup['status']; ?>
        <tr><td><strong>#<?php echo $bid; ?></strong> <code><?php echo esc_html($backup['filename']); ?></code><br><small><?php echo esc_html($backup['created_at']); ?> UTC · <?php echo esc_html($backup['scope']); ?></small></td>
        <td><?php if('verified'===$bstatus): ?><span class="pfc-good"><strong>Integrity checked</strong><br><small>Restore not tested</small></span><?php elseif('failed'===$bstatus): ?><span class="pfc-critical"><strong>Failed</strong></span><?php else: ?><strong><?php echo esc_html(ucwords(str_replace('_',' ',$bstatus))); ?></strong><?php endif; ?><?php if(!empty($backup['verified_at'])): ?><br><small><?php echo esc_html($backup['verified_at']); ?> UTC</small><?php endif; ?></td>
        <td><?php echo intval($backup['tables_done']); ?>/<?php echo intval($backup['table_count']); ?> tables<br><small><?php echo esc_html(number_format_i18n((int)$backup['row_count'])); ?> rows<?php if($backup['current_table']): ?> · <code><?php echo esc_html($backup['current_table']); ?></code><?php endif; ?></small></td>
        <td><?php echo esc_html(size_format((int)$backup['size_bytes'])); ?><?php if($backup['sha256']): ?><br><small>SHA-256 <code><?php echo esc_html(substr($backup['sha256'],0,16)); ?>…</code></small><?php endif; ?><?php if($backup['error_text']): ?><br><span class="pfc-critical"><?php echo esc_html($backup['error_text']); ?></span><?php endif; ?></td>
        <td class="pfc-form-row"><?php if('running'===$bstatus): ?><button type="button" class="button" data-pfc-resume-backup="<?php echo $bid; ?>">Resume</button><?php elseif('ready_verify'===$bstatus): ?><button type="button" class="button button-primary" data-pfc-verify-backup="<?php echo $bid; ?>">Verify Backup</button><?php endif; ?>
        <?php if(in_array($bstatus,array('verified','ready_verify'),true)): $download=wp_nonce_url(admin_url('admin-post.php?action=pfc_backup_download&backup_id='.$bid),'pfc_backup_download_'.$bid); ?><a class="button" href="<?php echo esc_url($download); ?>">Download</a><?php endif; ?>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" onsubmit="return confirm('Delete this database backup file?');"><input type="hidden" name="action" value="pfc_backup_delete"><input type="hidden" name="backup_id" value="<?php echo $bid; ?>"><?php wp_nonce_field('pfc_backup_delete_'.$bid); ?><button class="button">Delete</button></form></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php endif; ?>
        <?php if($verified_backups): ?><p class="pfc-good"><strong><?php echo intval(count($verified_backups)); ?> recent integrity-checked Performance Console export(s)</strong> are candidates for repairs for 24 hours. The selected file is rehashed before use. This does not prove restoreability or a transactionally consistent snapshot.</p><?php else: ?><p class="pfc-warning"><strong>No recent integrity-checked Performance Console export is available.</strong> Create and verify one above before running a database-changing repair, or explicitly confirm an external backup/snapshot in the repair form.</p><?php endif; ?>
        </div>

        <h2 id="innodb-transaction-manager">InnoDB Transaction Manager</h2>
        <div class="pfc-box">
        <p>Live transaction controls for DDL-blocking, stuck or idle InnoDB sessions. Performance Console never terminates a connection automatically. A termination interrupts the owning request/session and rolls back its uncommitted work.</p>
        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=pfc#innodb-transaction-manager')); ?>">Refresh Live Status</a> <small>The table below is read directly from InnoDB on each Performance page load.</small></p>
        <?php if ( empty( $innodb_manager['available'] ) ): ?>
            <p class="pfc-warning"><strong>Transaction details are unavailable.</strong> <?php echo esc_html($innodb_manager['message']??'The database account may not have enough visibility into INFORMATION_SCHEMA/Performance Schema.'); ?></p>
        <?php elseif ( empty( $innodb_manager['transactions'] ) ): ?>
            <p class="pfc-good"><strong>No active InnoDB transactions are currently visible.</strong> You can retry the blocked database repair.</p>
        <?php else: ?>
        <table class="widefat striped"><thead><tr><th>Thread / transaction</th><th>Age / state</th><th>Connection</th><th>Rows / locks</th><th>Query</th><th>Risk / action</th></tr></thead><tbody>
        <?php foreach((array)$innodb_manager['transactions'] as $trx): $tid=(int)($trx['thread_id']??0); $risk=(string)($trx['risk']??'medium'); ?>
        <tr>
            <td><strong>Thread <?php echo $tid; ?></strong><br><small>trx <code><?php echo esc_html($trx['id']??''); ?></code></small><?php if(!empty($trx['is_blocker'])): ?><br><span class="pfc-critical"><strong>BLOCKER</strong></span><?php endif; ?><?php if(!empty($trx['is_waiting'])): ?><br><span class="pfc-warning"><strong>WAITING</strong></span><?php endif; ?></td>
            <td><?php echo intval($trx['age_seconds']??0); ?> sec<br><small><?php echo esc_html($trx['state']??''); ?><?php if(!empty($trx['process_state'])): ?> · <?php echo esc_html($trx['process_state']); ?><?php endif; ?></small></td>
            <td><?php echo esc_html($trx['process_user']??'unknown'); ?><?php if(!empty($trx['process_host'])): ?><br><small><?php echo esc_html($trx['process_host']); ?></small><?php endif; ?><?php if(!empty($trx['process_db'])): ?><br><code><?php echo esc_html($trx['process_db']); ?></code><?php endif; ?><?php if(!empty($trx['process_command'])): ?><br><small><?php echo esc_html($trx['process_command']); ?></small><?php endif; ?></td>
            <td>modified <?php echo esc_html(number_format_i18n((int)($trx['rows_modified']??0))); ?><br>locked <?php echo esc_html(number_format_i18n((int)($trx['rows_locked']??0))); ?><br><small><?php echo intval($trx['tables_locked']??0); ?> table(s), <?php echo intval($trx['lock_structs']??0); ?> lock struct(s)</small></td>
            <td><code><?php echo esc_html(PFC_Utils::truncate((string)(($trx['query']??'') ?: ($trx['process_info']??'')),0,900)); ?></code><?php if(empty($trx['query'])&&empty($trx['process_info'])): ?><small>Idle/no current SQL visible.</small><?php endif; ?></td>
            <td><strong class="pfc-<?php echo 'high'===$risk?'critical':('medium'===$risk?'warning':'good'); ?>"><?php echo esc_html(strtoupper($risk)); ?></strong><br><small><?php echo esc_html($trx['risk_message']??''); ?></small>
            <?php if(!empty($trx['can_terminate'])): ?>
                <form style="margin-top:8px" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" onsubmit="return confirm('Terminate MySQL thread <?php echo $tid; ?>? Its uncommitted transaction will be rolled back.');">
                    <input type="hidden" name="action" value="pfc_db_fix"><input type="hidden" name="repair_action" value="terminate_innodb_transaction"><input type="hidden" name="thread_id" value="<?php echo $tid; ?>"><input type="hidden" name="transaction_id" value="<?php echo esc_attr($trx['id']??''); ?>">
                    <label style="display:block;margin:6px 0"><input type="checkbox" name="rollback_confirmed" value="1" required> I understand this interrupts the request/session and rolls back its uncommitted work</label>
                    <input type="hidden" name="danger_confirmed" value="1">
                    <?php if(!empty($trx['requires_high_rollback_ack'])): ?><label style="display:block;margin:6px 0" class="pfc-critical"><input type="checkbox" name="high_rollback_confirmed" value="1" required> I understand this may trigger a large/slow rollback and can increase database load until rollback completes</label><?php endif; ?>
                    <?php wp_nonce_field('pfc_db_fix'); ?><button class="button button-secondary">Terminate + Roll Back Transaction</button>
                </form>
            <?php else: ?><p><small><?php echo esc_html($trx['termination_reason']??'Performance Console is not offering termination for this transaction.'); ?></small></p><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php if(!empty($innodb_manager['lock_graph']['edges'])): ?><details><summary>Blocking relationships</summary><div><table class="widefat striped"><thead><tr><th>Waiting</th><th>Blocking</th><th>Table / index</th><th>Waiting query</th><th>Blocking query</th></tr></thead><tbody><?php foreach(array_slice((array)$innodb_manager['lock_graph']['edges'],0,30) as $edge): ?><tr><td><code><?php echo esc_html($edge['requesting_trx_id']??''); ?></code></td><td><code><?php echo esc_html($edge['blocking_trx_id']??''); ?></code></td><td><?php echo esc_html($edge['table']??''); ?><?php if(!empty($edge['index'])): ?><br><code><?php echo esc_html($edge['index']); ?></code><?php endif; ?></td><td><code><?php echo esc_html(PFC_Utils::truncate((string)($edge['requesting_query']??''),0,500)); ?></code></td><td><code><?php echo esc_html(PFC_Utils::truncate((string)($edge['blocking_query']??''),0,500)); ?></code></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
        <?php endif; ?>
        <p class="description">Performance Console refuses to terminate its own database connection, database/server system sessions, replication/daemon connections, or transactions that already appear to be rolling back. If the database user lacks permission to issue KILL CONNECTION, the server error is shown instead of bypassing host policy.</p>
        </div>

        <h2 id="database-repair-centre">Database Repair Centre</h2>
        <div class="pfc-box">
        <p>Repairs are explicit and evidence-driven. Normal fixes run directly in wp-admin. Large maintenance-window operations can also be run here after a verified backup, explicit maintenance acknowledgement and the existing DDL/lock/disk preflights; WP-CLI remains the preferred path for the largest tables.</p>
        <?php if ( ! $scan ): ?><p>Run a production-safe or deep scan first to build a repair plan.</p><?php elseif ( ! $repairs ): ?><p class="pfc-good"><strong>No database repair actions are currently recommended.</strong></p><?php else: ?>
        <table class="widefat striped"><thead><tr><th>Repair</th><th>Evidence</th><th>Safety</th><th>Action</th></tr></thead><tbody>
        <?php foreach ( $repairs as $plan ): $pargs=(array)($plan['args']??array()); $managed_index=(array)($plan['managed_index']??array()); ?><tr class="<?php echo !empty($managed_index)?'pfc-managed-index-row':''; ?>">
        <td>
            <strong><?php echo esc_html($plan['title']); ?></strong>
            <?php if(!empty($managed_index)): ?>
                <div class="pfc-managed-index-owner"><span class="pfc-managed-index-owner__label">Owner</span><strong><?php echo esc_html($managed_index['owner']??'Active plugin'); ?></strong></div>
                <p class="pfc-managed-index-note">Two database indexes have the same verified definition. Performance Console will preserve one working index and remove only the redundant copy.</p>
            <?php else: ?><br><small><?php echo esc_html($plan['reason']); ?></small><?php endif; ?>
        </td>
        <td>
            <?php if(!empty($managed_index)): ?>
                <div class="pfc-index-decision">
                    <div class="pfc-index-decision__pair"><span class="pfc-index-decision__label">KEEP</span><code><?php echo esc_html($managed_index['keep']??''); ?></code></div>
                    <div class="pfc-index-decision__pair is-remove"><span class="pfc-index-decision__label">REMOVE</span><code><?php echo esc_html($managed_index['drop']??''); ?></code></div>
                </div>
                <dl class="pfc-index-meta">
                    <div><dt>Canonical name</dt><dd><code><?php echo esc_html($managed_index['canonical']?:'Not registered'); ?></code></dd></div>
                    <div><dt>Canonical currently present</dt><dd><?php echo !empty($managed_index['canonical_present'])?'Yes':'No'; ?></dd></div>
                    <div><dt>Create canonical replacement</dt><dd><strong>Not recommended</strong><?php if(empty($managed_index['canonical_present']) && !empty($managed_index['canonical'])): ?> <span class="pfc-index-meta__why">The retained legacy index has the same verified definition, so building another index would add work without a query-performance benefit.</span><?php endif; ?></dd></div>
                </dl>
            <?php else: ?><?php echo esc_html($plan['detail']); ?><?php endif; ?>
        </td>
        <td>
            <?php if(!empty($managed_index)): ?>
                <div class="pfc-index-safety"><span><small>Risk</small><strong class="pfc-good"><?php echo esc_html($managed_index['risk']??'Low'); ?></strong></span><span><small>Confidence</small><strong><?php echo esc_html($managed_index['confidence']??'High'); ?></strong></span><span><small>Functional impact</small><strong><?php echo esc_html($managed_index['functional_impact']??'None expected'); ?></strong></span><span><small>Storage/write impact</small><strong class="pfc-good"><?php echo esc_html($managed_index['storage_write_impact']??'Positive'); ?></strong></span></div>
                <?php if(!empty($managed_index['large_table'])): ?><p class="pfc-warning-text">Large table: run during a maintenance window with a verified backup.</p><?php endif; ?>
            <?php else: ?><code><?php echo esc_html($plan['safety']); ?></code><?php endif; ?>
        </td>
        <td>
        <?php if ( ! empty($plan['available']) && ! empty($plan['action']) ): ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="pfc_db_fix"><input type="hidden" name="repair_action" value="<?php echo esc_attr($plan['action']); ?>"><?php foreach($pargs as $k=>$v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
            <?php if(!empty($plan['requires_backup'])): ?><label style="display:block;margin-bottom:6px">Verified Performance Console backup<select name="backup_id"><option value="">Select recent backup</option><?php foreach($verified_backups as $vb): ?><option value="<?php echo intval($vb['id']); ?>">#<?php echo intval($vb['id']); ?> · <?php echo esc_html(size_format((int)$vb['size_bytes'])); ?> · <?php echo esc_html($vb['verified_at']); ?> UTC</option><?php endforeach; ?></select></label><label style="display:block;margin-bottom:6px"><input type="checkbox" name="backup_confirmed" value="1"> Or I independently verified a current external DB backup/snapshot</label><?php endif; ?>
            <?php if(!empty($plan['requires_danger_ack'])): ?><label style="display:block;margin-bottom:6px"><input type="checkbox" name="danger_confirmed" value="1" required> I reviewed this schema/index change and its locking/data risks</label><?php endif; ?>
            <?php if(!empty($plan['requires_data_loss_ack'])): ?><label style="display:block;margin-bottom:6px"><input type="checkbox" name="data_loss_confirmed" value="1" required> I understand this may not restore lost data and may discard damaged rows</label><?php endif; ?>
            <?php wp_nonce_field('pfc_db_fix'); ?><button class="button<?php echo 'safe'===$plan['safety']?' button-primary':''; ?>"><?php echo esc_html($plan['button_label']??'Apply Fix'); ?></button></form>
            <?php if(!empty($plan['sql_preview'])): ?><details style="margin-top:8px"><summary>Preview SQL</summary><code><?php echo esc_html($plan['sql_preview']); ?></code></details><?php endif; ?>
        <?php elseif ( 'cli-review' === ($plan['safety']??'') && !empty($plan['action']) ): ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="pfc_db_fix"><input type="hidden" name="repair_action" value="<?php echo esc_attr($plan['action']); ?>"><input type="hidden" name="force_large" value="1"><?php foreach($pargs as $k=>$v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
            <?php if(!empty($plan['requires_backup'])): ?><label style="display:block;margin-bottom:6px">Verified Performance Console backup<select name="backup_id"><option value="">Select recent backup</option><?php foreach($verified_backups as $vb): ?><option value="<?php echo intval($vb['id']); ?>">#<?php echo intval($vb['id']); ?> · <?php echo esc_html(size_format((int)$vb['size_bytes'])); ?> · <?php echo esc_html($vb['verified_at']); ?> UTC</option><?php endforeach; ?></select></label><label style="display:block;margin-bottom:6px"><input type="checkbox" name="backup_confirmed" value="1"> Or I independently verified a current external DB backup/snapshot</label><?php endif; ?>
            <label style="display:block;margin-bottom:6px"><input type="checkbox" name="maintenance_confirmed" value="1" required> I am running this in a maintenance window and understand this request may take a long time</label>
            <?php if(!empty($plan['requires_danger_ack'])): ?><label style="display:block;margin-bottom:6px"><input type="checkbox" name="danger_confirmed" value="1" required> I reviewed this schema/index change and its locking/data risks</label><?php endif; ?>
            <?php if(!empty($plan['requires_data_loss_ack'])): ?><label style="display:block;margin-bottom:6px"><input type="checkbox" name="data_loss_confirmed" value="1" required> I understand this may not restore lost data and may discard damaged rows</label><?php endif; ?>
            <?php wp_nonce_field('pfc_db_fix'); ?><button class="button button-primary"><?php echo esc_html($plan['button_label']??'Run Maintenance Fix'); ?></button></form>
            <details style="margin-top:8px"><summary>CLI alternative (recommended for the largest tables)</summary><code>wp performance database-fix <?php echo esc_html($plan['action']); ?><?php foreach($pargs as $k=>$v): ?> --<?php echo esc_html(str_replace('_','-',$k)); ?>=<?php echo esc_html($v); ?><?php endforeach; ?><?php echo !empty($plan['requires_backup'])?' --backup-confirmed':''; ?><?php echo !empty($plan['requires_danger_ack'])?' --danger-confirmed':''; ?><?php echo !empty($plan['requires_data_loss_ack'])?' --data-loss-confirmed':''; ?> --force-large</code></details>
            <?php if(!empty($plan['sql_preview'])): ?><details style="margin-top:8px"><summary>Preview SQL</summary><code><?php echo esc_html($plan['sql_preview']); ?></code></details><?php endif; ?>
        <?php else: ?>
            <strong>Guided fix required.</strong><?php if(!empty($plan['manual_steps'])): ?><ol><?php foreach((array)$plan['manual_steps'] as $step): ?><li><?php echo esc_html($step); ?></li><?php endforeach; ?></ol><?php endif; ?><?php if(!empty($plan['sql_preview'])): ?><details><summary>Reviewed SQL</summary><code><?php echo esc_html($plan['sql_preview']); ?></code></details><?php endif; ?>
        <?php endif; ?>
        </td>
        </tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
        </div>
        </section>
        <?php endif; ?>

        <?php if ( 'profiling' === $active_view ): ?>
        <section class="pfc-view" data-pfc-view="profiling" id="pfc-view-profiling">
        <div class="pfc-view-heading"><h2>Request profiling</h2><p>Capture a real WordPress save or measure public routes, then isolate plugin, database, hook and outbound HTTP cost.</p></div>
        <h2 id="save-profiling">Slow Save Profiler</h2>
        <?php if ( $save_capture_notice ): ?><div class="notice <?php echo ! empty( $save_capture_notice['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline" role="status"><p><?php echo esc_html( $save_capture_notice['message'] ?? '' ); ?></p></div><?php endif; ?>
        <div class="pfc-box pfc-save-capture<?php echo $save_capture_status ? ' is-armed' : ''; ?>">
            <header class="pfc-save-capture__header">
                <div class="pfc-save-capture__title">
                    <h3>Profile your next save</h3>
                    <span class="pfc-status <?php echo $save_capture_status ? 'pfc-status--armed' : 'pfc-status--good'; ?>"><?php echo $save_capture_status ? 'Capture armed' : 'Ready'; ?></span>
                </div>
                <p>Measure one real WordPress save in this browser and see where PHP, database, hooks, plugins, and outbound requests spend their time.</p>
            </header>

            <div class="pfc-save-capture__layout">
                <div class="pfc-save-capture__control">
                    <?php if ( $save_capture_status ): ?>
                        <div class="pfc-capture-listening" role="status">
                            <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
                            <div><h4>Listening for the next <?php echo esc_html( $save_capture_status['kind'] ); ?> save</h4><p>Keep this browser open, edit an existing post, page, product, or custom post type, and save it once.<?php if ( 'manual' === $save_capture_status['kind'] ): ?> Autosaves and unrelated WordPress updates will not consume this capture.<?php endif; ?></p></div>
                        </div>
                        <div class="pfc-save-capture__armed-actions">
                            <p><strong>Capture expires:</strong> <?php echo esc_html( wp_date( 'M j, Y g:i:s a T', (int) $save_capture_status['expires'] ) ); ?></p>
                            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="pfc_save_capture"><input type="hidden" name="capture_action" value="cancel"><?php wp_nonce_field( 'pfc_save_capture' ); ?><button class="button">Cancel capture</button></form>
                        </div>
                    <?php else: ?>
                        <form class="pfc-save-capture__form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                            <input type="hidden" name="action" value="pfc_save_capture"><input type="hidden" name="capture_action" value="arm"><?php wp_nonce_field( 'pfc_save_capture' ); ?>
                            <fieldset>
                                <legend>What should Performance Console capture?</legend>
                                <div class="pfc-capture-choices">
                                    <label class="pfc-capture-choice"><input type="radio" name="capture_kind" value="manual" checked required><span><strong>Manual save</strong><small>Your next Update, Publish, or Save draft action.</small></span></label>
                                    <label class="pfc-capture-choice"><input type="radio" name="capture_kind" value="autosave"><span><strong>Autosave</strong><small>The editor’s next automatic background save.</small></span></label>
                                    <label class="pfc-capture-choice"><input type="radio" name="capture_kind" value="metabox"><span><strong>Legacy metabox save</strong><small>The block editor’s follow-up compatibility save to post.php.</small></span></label>
                                </div>
                            </fieldset>
                            <div class="pfc-save-capture__submit"><button class="button button-primary"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span>Arm save capture</button><small>Armed for 10 minutes and disarmed after one matching request.</small></div>
                        </form>
                    <?php endif; ?>
                </div>

                <aside class="pfc-save-capture__journey" aria-label="Save capture steps">
                    <h4><?php echo $save_capture_status ? 'Finish this capture' : 'What happens next'; ?></h4>
                    <ol>
                        <li><span class="pfc-save-capture__step-number" aria-hidden="true">1</span><div><strong><?php echo $save_capture_status ? 'Capture is armed' : 'Arm the profiler'; ?></strong><span><?php echo $save_capture_status ? 'Performance Console is waiting in this browser.' : 'Choose the save type and start a one-request capture.'; ?></span></div></li>
                        <li><span class="pfc-save-capture__step-number" aria-hidden="true">2</span><div><strong>Save content once</strong><span>Use an existing post, page, product, or custom post type.</span></div></li>
                        <li><span class="pfc-save-capture__step-number" aria-hidden="true">3</span><div><strong>Review the result</strong><span>Return here to see timing and likely plugin or hook causes.</span></div></li>
                    </ol>
                </aside>
            </div>

            <footer class="pfc-save-capture__assurance">
                <span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
                <div><strong>Content-safe diagnostics</strong><span>Performance Console stores timings and safe identifiers—not titles, field values, content, or request bodies.</span></div>
                <p>Tracing adds some overhead, so use results to rank causes. Performance Console never replays a write or disables a plugin while saving.</p>
            </footer>
        </div>

        <?php if ( $latest_save ): $latest_payload = json_decode( (string) $latest_save->payload, true ); $save_context = (array) ( $latest_payload['save_context'] ?? array() ); $save_components = (array) ( $latest_payload['save_components'] ?? array() ); $save_hooks = (array) ( $latest_payload['save_hook_components'] ?? array() ); $save_callback_timings = (array) ( $latest_payload['save_callback_timings'] ?? array() ); $save_success = ! empty( $latest_payload['save_outcome']['successful'] ); $save_severity = (float) $latest_save->php_ms >= 1500 ? 'critical' : ( (float) $latest_save->php_ms >= 750 ? 'high' : ( (float) $latest_save->php_ms >= 500 ? 'warning' : 'good' ) ); if ( ! $save_success ) { $save_severity = 'warning'; } ?>
        <div class="pfc-save-report" aria-labelledby="pfc-latest-save-heading">
            <div class="pfc-save-report__header"><div><h3 id="pfc-latest-save-heading">Latest captured save</h3><p><?php echo esc_html( trim( (string) ( $save_context['post_type'] ?? 'content' ) . ' · ' . (string) ( $save_context['kind'] ?? 'save' ) ) ); ?><?php if ( ! empty( $save_context['post_id'] ) ): ?> · ID <?php echo intval( $save_context['post_id'] ); ?><?php endif; ?> · <?php echo esc_html( $latest_save->created_at ); ?> UTC</p></div><span class="pfc-status pfc-status--<?php echo esc_attr( $save_severity ); ?>"><?php echo esc_html( ! $save_success ? 'Success unconfirmed' : ( 'good' === $save_severity ? 'Quick capture' : $save_severity ) ); ?></span></div>
            <dl class="pfc-save-metrics"><div><dt>PHP time</dt><dd><?php echo esc_html( round( (float) $latest_save->php_ms, 1 ) ); ?> ms</dd></div><div><dt>Database time</dt><dd><?php echo esc_html( round( (float) $latest_save->db_ms, 1 ) ); ?> ms</dd></div><div><dt>Queries</dt><dd><?php echo intval( $latest_save->query_count ); ?></dd></div><div><dt>Outbound HTTP</dt><dd><?php echo esc_html( round( (float) $latest_save->http_ms, 1 ) ); ?> ms / <?php echo intval( $latest_save->http_count ); ?></dd></div><div><dt>Peak memory</dt><dd><?php echo esc_html( size_format( (int) $latest_save->memory_peak ) ); ?></dd></div></dl>
            <p class="pfc-save-verdict pfc-save-verdict--<?php echo esc_attr( $save_severity ); ?>"><strong><?php echo empty( $latest_payload['save_outcome']['successful'] ) ? esc_html__( 'Save success is unconfirmed. A fast response does not prove that content was saved.', 'performance-console' ) : ( (float) $latest_save->php_ms < 500 ? esc_html__( 'WordPress completed a matching write with a successful response, and the captured PHP work was quick.', 'performance-console' ) : esc_html__( 'WordPress completed a matching write with a successful response. Compare another capture before changing the site.', 'performance-console' ) ); ?></strong> <?php esc_html_e( 'Database and HTTP rows measure attributed work, not total plugin execution. Registered hook components are suspects, not measured callback costs. Nested hook times overlap and must not be added together.', 'performance-console' ); ?></p>
            <p class="description"><?php esc_html_e( 'Timing starts at the diagnostic bootstrap, not at the browser click. Editor JavaScript, upload time and some early server work are outside this measurement. Confirm the editor also reports success.', 'performance-console' ); ?></p>
            <?php if ( ! empty( $latest_payload['query_details_truncated'] ) || ! empty( $latest_payload['query_traces_at_limit'] ) ): ?><p class="pfc-warning"><?php esc_html_e( 'This capture reached a query-detail limit. Attribution is incomplete; use database slow-query tooling to investigate the remaining work.', 'performance-console' ); ?></p><?php endif; ?>

            <h4>Measured component work</h4>
            <?php if ( ! $save_components ): ?><div class="pfc-empty">No database or outbound HTTP work could be attributed to a component in this capture. Use the hook evidence below.</div><?php else: ?>
            <table class="widefat striped pfc-responsive-table"><thead><tr><th>Component</th><th>Query work</th><th>HTTP work</th><th>Measured total</th></tr></thead><tbody><?php foreach ( $save_components as $component ): ?><tr><td data-label="Component"><code><?php echo esc_html( $component['component'] ?? 'core:wordpress' ); ?></code></td><td data-label="Query work"><?php echo esc_html( round( (float) ( $component['query_ms'] ?? 0 ), 1 ) . ' ms / ' . (int) ( $component['query_count'] ?? 0 ) ); ?></td><td data-label="HTTP work"><?php echo esc_html( round( (float) ( $component['http_ms'] ?? 0 ), 1 ) . ' ms / ' . (int) ( $component['http_count'] ?? 0 ) ); ?></td><td data-label="Measured total"><strong><?php echo esc_html( round( (float) ( $component['total_ms'] ?? 0 ), 1 ) ); ?> ms</strong></td></tr><?php endforeach; ?></tbody></table>
            <?php endif; ?>

            <h4>Save hooks and callback suspects</h4>
            <?php if ( ! $save_hooks ): ?><div class="pfc-empty">No timed save hook completed during this capture.</div><?php else: ?>
            <table class="widefat striped pfc-responsive-table"><thead><tr><th>Hook</th><th>Total hook time</th><th>Registered component suspects</th></tr></thead><tbody><?php foreach ( array_slice( $save_hooks, 0, 20 ) as $hook ): ?><tr><td data-label="Hook"><code><?php echo esc_html( $hook['hook'] ?? '' ); ?></code></td><td data-label="Total hook time"><strong><?php echo esc_html( round( (float) ( $hook['ms'] ?? 0 ), 1 ) ); ?> ms</strong></td><td data-label="Component suspects"><?php $suspects = array(); foreach ( (array) ( $hook['components'] ?? array() ) as $name => $count ) { $suspects[] = $name . ' (' . (int) $count . ')'; } echo esc_html( $suspects ? implode( ', ', $suspects ) : 'WordPress core only' ); ?></td></tr><?php endforeach; ?></tbody></table>
            <?php endif; ?>
            <?php if ( 'metabox' === ( $save_context['requested_kind'] ?? '' ) ): ?>
            <h4>Measured callback time for this one-shot metabox capture</h4>
            <p class="description">Callback timing is enabled only for the signed metabox capture. It temporarily wraps selected callbacks while they run; callbacks with by-reference parameters are left untouched and are not timed. A callback’s time includes nested work, so totals across rows can overlap and must not be added together. Arguments, field values and return data are not recorded.</p>
            <?php if ( ! $save_callback_timings ): ?><div class="pfc-empty">No callback timings were collected for the selected hooks.</div><?php else: ?>
            <table class="widefat striped pfc-responsive-table"><thead><tr><th>Hook</th><th>Callback</th><th>Component</th><th>Priority</th><th>Calls</th><th>Total inclusive time</th><th>Max</th></tr></thead><tbody><?php foreach ( array_slice( $save_callback_timings, 0, 200 ) as $timing ): ?><tr><td data-label="Hook"><code><?php echo esc_html( $timing['hook'] ?? '' ); ?></code></td><td data-label="Callback"><code><?php echo esc_html( $timing['callback'] ?? '' ); ?></code></td><td data-label="Component"><code><?php echo esc_html( $timing['component'] ?? '' ); ?></code></td><td data-label="Priority"><?php echo (int) ( $timing['priority'] ?? 0 ); ?></td><td data-label="Calls"><?php echo (int) ( $timing['calls'] ?? 0 ); ?></td><td data-label="Total inclusive time"><strong><?php echo esc_html( round( (float) ( $timing['total_ms'] ?? 0 ), 3 ) ); ?> ms</strong></td><td data-label="Max"><?php echo esc_html( round( (float) ( $timing['max_ms'] ?? 0 ), 3 ) ); ?> ms</td></tr><?php endforeach; ?></tbody></table>
            <?php endif; ?>
            <?php if ( ! empty( $latest_payload['save_callback_timings_truncated'] ) ): ?><p class="pfc-warning">The callback timing row limit was reached; <?php echo (int) ( $latest_payload['save_callback_timing_rows_dropped'] ?? 0 ); ?> additional callback rows were omitted.</p><?php endif; ?>
            <?php if ( ! empty( $latest_payload['save_callback_reference_callbacks_skipped'] ) ): ?><p class="description"><?php echo (int) $latest_payload['save_callback_reference_callbacks_skipped']; ?> callbacks with by-reference parameters were not wrapped, to preserve their native argument behavior.</p><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <h2>Deep Route Profiler</h2>
        <div class="pfc-box"><form class="pfc-form-row" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="pfc_profile"><?php wp_nonce_field('pfc_profile'); ?>
        <?php $profile_url = isset( $_GET['profile_url'] ) && PFC_Utils::same_origin_url( wp_unslash( $_GET['profile_url'] ) ) ? esc_url_raw( wp_unslash( $_GET['profile_url'] ) ) : home_url('/'); ?>
        <label>Public WordPress URL<input type="url" name="profile_url" required value="<?php echo esc_attr( $profile_url ); ?>"></label><label>Measured runs<select name="runs"><option>3</option><option selected>5</option></select></label><button class="button button-primary">Profile this route</button></form>
        <p class="description">Runs a private no-cache request without following redirects. Performance Console verifies that WordPress handled the response before accepting the measurement.</p>
        <?php if ( $profile ): $pm=$profile['probe_meta']??array(); ?><p><strong>Last profile:</strong> <?php echo esc_html($profile['url']); ?> &mdash; <?php if($profile['times']): ?>server PHP <?php echo esc_html(implode(', ', $profile['times'])); ?> ms; median <?php echo esc_html(PFC_Utils::median($profile['times'])); ?> ms<?php else: ?><span class="pfc-critical">No verified diagnostic request reached WordPress or matched its saved server measurement. Check the MU profiler and page-cache bypass.</span><?php endif; ?><?php if($pm): ?> <small>(verified <?php echo intval($pm['verified']??0); ?>/<?php echo intval($pm['requested']??0); ?>)</small><?php endif; ?></p><?php endif; ?>
        </div>

        <h2>Private Plugin Impact Test</h2>
        <div class="pfc-box"><form class="pfc-form-row" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="pfc_plugin_impact"><?php wp_nonce_field('pfc_plugin_impact'); ?>
        <label>Route<input type="url" name="profile_url" required value="<?php echo esc_attr(home_url('/')); ?>"></label><label>Plugin<select name="plugin" required><option value="">Select plugin</option><?php foreach($active_plugins as $p): if($p['file']===PFC_BASENAME)continue; ?><option value="<?php echo esc_attr($p['file']); ?>"><?php echo esc_html($p['name'].' '.$p['version']); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Expected page text (optional)', 'performance-console' ); ?><input type="text" name="required_text" maxlength="200"></label><button class="button">Benchmark Plugin</button></form>
        <p class="description">Runs warm-ups followed by five alternating A/B pairs. Each result is matched to its saved server-side PHP measurement and verified exclusion. The plugin remains active for visitors. Enter a distinctive visible phrase to require it in every response. This checks content presence, not forms, checkout or JavaScript behavior; test those separately on staging. Results apply only to this anonymous route.</p>
        <?php if($impact): $bm=$impact['baseline_meta']??array(); $wm=$impact['without_meta']??array(); ?><p><strong><?php echo esc_html($impact['plugin']); ?>:</strong> <?php if($impact['baseline']&&$impact['without']&&!empty($impact['comparable'])&&count((array)($impact['pair_deltas']??array()))>=5): ?>baseline PHP <?php echo esc_html(round((float)$impact['baseline_median'],1)); ?> ms; without plugin <?php echo esc_html(round((float)$impact['without_median'],1)); ?> ms; <?php if(!empty($impact['repeatable'])): ?>paired impact <strong><?php echo esc_html(($impact['delta']>=0?'+':'').$impact['delta']); ?> ms</strong> · <?php echo esc_html($impact['confidence']); ?> confidence.<?php else: ?><strong>No repeatable plugin cost detected.</strong> The paired median <?php echo esc_html(($impact['delta']>=0?'+':'').$impact['delta']); ?> ms is within the ±<?php echo esc_html($impact['noise_floor']); ?> ms noise floor.<?php endif; ?><?php elseif(empty($impact['comparable'])): ?><span class="pfc-critical">The responses changed too much to compare safely. Check for missing templates, redirects or plugin dependencies.</span><?php else: ?><span class="pfc-critical">The benchmark did not produce five verified server-side pairs. Check the MU profiler and page-cache bypass, then run it again.</span><?php endif; ?> <small>(server verified: baseline <?php echo intval($bm['verified']??0); ?>/<?php echo intval($bm['requested']??0); ?>; exclusion <?php echo intval($wm['verified']??0); ?>/<?php echo intval($wm['requested']??0); ?>)</small></p><?php if(!empty($impact['pair_deltas'])): ?><p class="description">Pair deltas: <?php echo esc_html(implode(', ',array_map(static function($value){return ($value>=0?'+':'').round($value,1);},(array)$impact['pair_deltas']))); ?> ms. Variability (MAD): <?php echo esc_html($impact['mad']); ?> ms. Positive values mean the plugin added PHP time.</p><?php endif; ?><?php endif; ?>
        </div>
        </section>
        <?php endif; ?>

        <?php if ( 'findings' === $active_view ): ?>
        <section class="pfc-view" data-pfc-view="findings" id="pfc-view-findings">
        <div class="pfc-view-heading"><h2>Performance incidents</h2><p>Repeated evidence is grouped into root-cause incidents. Diagnose the representative route, apply a guarded change, then recheck the same incident.</p></div>
        <?php if ( $incident_notice ): ?><div class="notice notice-success inline" role="status"><p><?php echo esc_html( $incident_notice ); ?></p></div><?php endif; ?>
        <form class="pfc-incident-toolbar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="pfc"><input type="hidden" name="view" value="findings">
            <label for="pfc-incident-status">Lifecycle status<select id="pfc-incident-status" name="incident_status" data-pfc-submit-change><?php foreach ( array( 'active' => 'Confirmed', 'watching' => 'Watching', 'snoozed' => 'Snoozed', 'resolved' => 'Resolved', 'accepted' => 'Accepted risk', 'all' => 'All incidents' ) as $value => $label ): ?><option value="<?php echo esc_attr( $value ); ?>"<?php selected( $incident_filter, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
            <label for="pfc-incident-severity">Severity<select id="pfc-incident-severity" data-pfc-filter="severity"><option value="">All severities</option><option value="critical">Critical</option><option value="high">High</option><option value="warning">Warning</option><option value="info">Info</option></select></label>
            <label for="pfc-incident-search" class="pfc-incident-search">Search incidents<input id="pfc-incident-search" type="search" data-pfc-filter="search" placeholder="Component, route, or evidence"></label>
        </form>
        <p class="pfc-findings-note" aria-live="polite"><span data-pfc-result-count><?php echo esc_html( number_format_i18n( count( $issues ) ) ); ?></span> grouped incident(s) in this view.</p>
        <?php if ( ! $issues ): ?><div class="pfc-empty"><strong>No incidents match this lifecycle view.</strong><br>Run a production-safe scan or switch to another status.</div><?php endif; ?>
        <div class="pfc-incidents">
        <?php foreach ( $issues as $incident ): $routes=(array)($incident['routes']??array()); $representative=''; foreach($routes as $candidate){if(filter_var($candidate,FILTER_VALIDATE_URL)&&PFC_Utils::same_origin_url($candidate)){$representative=$candidate;break;}} ?>
            <article class="pfc-incident" data-severity="<?php echo esc_attr( $incident['severity'] ); ?>" data-search="<?php echo esc_attr( strtolower( wp_strip_all_tags( implode( ' ', array( $incident['title'], $incident['message'], $incident['area'], $incident['component'], implode( ' ', $routes ) ) ) ) ) ); ?>">
                <header class="pfc-incident__header">
                    <div><span class="pfc-status pfc-status--<?php echo esc_attr( $incident['severity'] ); ?>"><?php echo esc_html( strtoupper( $incident['severity'] ) ); ?></span> <span class="pfc-incident__area"><?php echo esc_html( $incident['area'] ); ?><?php echo !empty($incident['component'])?' · '.esc_html($incident['component']):''; ?></span></div>
                    <span class="pfc-incident__confidence"><?php echo esc_html( round( (float) $incident['confidence'] ) ); ?>/100 rule-based evidence score</span>
                </header>
                <div class="pfc-incident__body">
                    <div class="pfc-incident__summary"><h3><?php echo esc_html( $incident['title'] ); ?></h3><p><?php echo wp_kses_post( $incident['message'] ); ?></p><p class="pfc-incident__recommendation"><strong>Recommended next step:</strong> <?php echo wp_kses_post( $incident['recommendation'] ); ?></p></div>
                    <dl class="pfc-incident__facts"><div><dt>Impact</dt><dd><?php echo esc_html( $incident['impact'] ?: 'Needs measurement' ); ?></dd></div><div><dt>Evidence</dt><dd><?php echo esc_html( number_format_i18n( (int) $incident['occurrences'] ) ); ?> occurrence(s) across <?php echo esc_html( number_format_i18n( count( $routes ) ) ); ?> route(s)</dd></div><div><dt>Last confirmed</dt><dd><?php echo esc_html( $incident['last_seen'] ); ?> UTC</dd></div></dl>
                </div>
                <?php if ( 'frontend' === $incident['area'] && false !== strpos( (string) $incident['title'], 'local HTTP probe' ) ): ?>
                    <p class="pfc-incident__context"><strong>Measurement context:</strong> This is a local HTTP round-trip, not just proxy or network latency. WordPress PHP may account for most of it. Use <em>Profile representative route</em> on the same URL before blaming CDN, TLS or Redis. Recheck tests one representative route; a new scan must refresh the other affected routes.</p>
                <?php endif; ?>
                <?php if ( $routes ): ?><details class="pfc-incident__routes"><summary>View affected routes and evidence <span class="pfc-incident__route-count"><?php echo esc_html( number_format_i18n( count( $routes ) ) ); ?> route(s)</span></summary><div class="pfc-incident__route-content"><ul><?php foreach ( array_slice( $routes, 0, 25 ) as $route ): ?><li><code><?php echo esc_html( $route ); ?></code></li><?php endforeach; ?></ul><?php if(count($routes)>25): ?><p><?php echo esc_html( number_format_i18n( count($routes)-25 ) ); ?> additional route(s) are grouped into this incident.</p><?php endif; ?></div></details><?php endif; ?>
                <footer class="pfc-incident__actions">
                    <?php if ( 'save' === $incident['area'] ): ?><a class="button button-primary" href="<?php echo esc_url( admin_url('admin.php?page=pfc&view=profiling#save-profiling') ); ?>">Capture another save</a><?php elseif ( $representative ): ?><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page'=>'pfc','view'=>'profiling','profile_url'=>$representative ), admin_url('admin.php') ) . '#profiling' ); ?>">Profile representative route</a><?php elseif ( 'database' === $incident['area'] ): ?><a class="button button-primary" href="<?php echo esc_url( admin_url('admin.php?page=pfc&view=database#database') ); ?>">Open database diagnostics</a><?php else: ?><a class="button button-primary" href="<?php echo esc_url( admin_url('admin.php?page=pfc&view=profiling#profiling') ); ?>">Open profiling tools</a><?php endif; ?>
                    <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="pfc_incident_action"><input type="hidden" name="incident_key" value="<?php echo esc_attr( $incident['incident_key'] ); ?>"><?php wp_nonce_field( 'pfc_incident_' . $incident['incident_key'] ); ?><button class="button" name="incident_action" value="verify"><?php echo count( $routes ) > 1 ? 'Recheck one route' : 'Recheck now'; ?></button><button class="button" name="incident_action" value="snooze">Snooze 7 days</button><button class="button" name="incident_action" value="resolve">Mark resolved</button><button class="button-link" name="incident_action" value="accept">Accept risk</button></form>
                </footer>
            </article>
        <?php endforeach; ?>
        </div>
        </section>
        <?php endif; ?>

        <?php if ( 'cache' === $active_view ): ?>
        <section class="pfc-view" data-pfc-view="cache" id="pfc-view-cache">
            <div class="pfc-view-heading"><h2>Cache & Redis diagnostics</h2><p>Measure WordPress cache and Redis timing, and inspect Object Cache Pro using supported, opt-in interfaces. No cache flush, ACL modification or drop-in replacement is performed.</p></div>
            <div class="pfc-box" id="pfc-cache-bootstrap-status"><h3>MU Diagnostic Bootstrap Status</h3>
                <p>Installed: <strong><?php echo ! empty( $bootstrap_status['installed'] ) ? 'Yes' : 'No'; ?></strong> · Installed version: <strong><?php echo esc_html( (string) ( $bootstrap_status['version'] ?: 'Unknown' ) ); ?></strong> · Required version: <strong><?php echo esc_html( PFC_Bootstrap::VERSION ); ?></strong> · Status: <strong><?php echo ! empty( $bootstrap_status['current'] ) ? 'Current' : 'Missing or outdated'; ?></strong></p>
                <?php if ( empty( $bootstrap_status['current'] ) ): ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="pfc_bootstrap"><?php wp_nonce_field( 'pfc_bootstrap' ); ?><button class="button">Repair diagnostic bootstrap</button></form><?php endif; ?>
            </div>
            <?php if ( $cache_diagnostic || $cache_profile ): ?>
            <div class="pfc-box" id="cache-export">
                <h3>Export results for AI analysis</h3>
                <p>Download the latest Redis test and real-request profile together as one structured JSON file. The export also includes a compact summary of your latest system scan, if available.</p>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="pfc_export_ai">
                    <input type="hidden" name="scope" value="cache">
                    <?php wp_nonce_field( 'pfc_export_ai' ); ?>
                    <button type="submit" class="button button-primary"><span class="dashicons dashicons-download" aria-hidden="true"></span> Export for AI Analysis (.json)</button>
                </form>
                <p class="description">Includes the measured latencies, Redis counters, cache phases, SQL timing attribution and autoload sizes that are currently available. Excludes credentials, URLs, IP addresses, option values, raw SQL and visitor data. Results are available for 15 minutes after testing. Review the JSON before uploading it to an external service.</p>
            </div>
            <?php endif; ?>

            <div class="pfc-box" id="cache-request-profile">
                <h3>Real-request cache workload</h3>
                <p>Profile the actual WordPress work for a public page, not just a synthetic Redis key. Each test uses a signed, cache-bypassing request and verifies its saved server-side measurement before including it.</p>
                <form class="pfc-form-row" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="pfc_cache_profile">
                    <?php wp_nonce_field( 'pfc_cache_profile' ); ?>
                    <label>Public URL <input type="url" name="profile_url" required value="<?php echo esc_attr( home_url( '/' ) ); ?>"></label>
                    <label>Requests <select name="runs"><option value="3" selected>3</option><option value="5">5</option></select></label>
                    <label><input type="checkbox" name="php_callback_profile" value="1"> Deep PHP + Bricks query/render profiling (extra overhead)</label>
                    <button class="button button-primary" type="submit">Profile real requests</button>
                </form>
                <p class="description">Enable deep PHP callback profiling only for a controlled diagnosis. It temporarily wraps selected callbacks on signed probes; by-reference callables are skipped. Use a separate uninstrumented profile for comparison. The profiler reads hit/miss counters where available, SQL queries, PHP time and request phases. It does not override the Redis drop-in, record cache values or inject traffic tracing into normal page views. Signed deep traces add some overhead. Use 3 to 5 samples to compare routes, not to establish production percentiles.</p>
                <details id="pfc-mode-comparison" style="margin-top:12px"><summary><strong>Compare standard and deep PHP profiling (3 paired runs)</strong></summary>
                    <p>Run three alternating-order pairs of signed, verified homepage requests with the same route. This is an optional, potentially resource-intensive diagnostic. It makes no configuration changes and is not a production benchmark.</p>
                    <form class="pfc-form-row" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                        <input type="hidden" name="action" value="pfc_cache_compare"><?php wp_nonce_field( 'pfc_cache_compare' ); ?>
                        <label>Public URL <input type="url" name="profile_url" required value="<?php echo esc_attr( home_url( '/' ) ); ?>"></label>
                        <button class="button" type="submit">Run 3 paired comparisons (6 requests)</button>
                    </form>
                    <?php if ( is_array( $cache_mode_comparison ) ): ?>
                        <p><strong>Verified comparable pairs:</strong> <?php echo intval( $cache_mode_comparison['valid_pairs'] ?? 0 ); ?>/<?php echo intval( $cache_mode_comparison['requested_pairs'] ?? 0 ); ?> · <strong>Standard median PHP:</strong> <?php echo isset( $cache_mode_comparison['standard_median_php_ms'] ) ? esc_html( (string) $cache_mode_comparison['standard_median_php_ms'] ) . ' ms' : 'Not measured'; ?> · <strong>Deep median PHP:</strong> <?php echo isset( $cache_mode_comparison['deep_median_php_ms'] ) ? esc_html( (string) $cache_mode_comparison['deep_median_php_ms'] ) . ' ms' : 'Not measured'; ?></p>
                        <p><strong>Median paired deep minus standard:</strong> <?php echo isset( $cache_mode_comparison['paired_median_deep_minus_standard_ms'] ) ? esc_html( (string) $cache_mode_comparison['paired_median_deep_minus_standard_ms'] ) . ' ms' : 'Not measured'; ?>. <?php echo ! empty( $cache_mode_comparison['comparable'] ) ? 'All three pairs verified and matched the response checks.' : 'Comparison incomplete; do not interpret the delta as a measured overhead.'; ?></p>
                        <p class="description"><?php echo esc_html( (string) ( $cache_mode_comparison['notes'] ?? '' ) ); ?></p>
                    <?php endif; ?>
                </details>
                <?php if ( $cache_profile ):
                    $cp = (array) $cache_profile;
                    $cm = (array) ( $cp['probe_meta'] ?? array() );
                    $cruns = (array) ( $cp['runs'] ?? array() ); ?>
                    <h4>Verified route profile</h4>
                    <p><strong>URL:</strong> <code><?php echo esc_html( (string) ( $cp['url'] ?? '' ) ); ?></code> · <strong>Verified:</strong> <?php echo intval( $cm['verified'] ?? 0 ); ?>/<?php echo intval( $cm['requested'] ?? 0 ); ?> · <strong>Captured:</strong> <?php echo esc_html( gmdate( 'Y-m-d H:i', (int) ( $cp['at'] ?? 0 ) ) ); ?> UTC</p>
                    <?php if ( ! $cruns ): ?>
                        <p class="pfc-warning">No verified profile data. Check that loopback HTTP requests work, the MU bootstrap is current, and the CDN/page cache does not serve this signed diagnostic route. <?php echo intval( $cm['probe_mismatch'] ?? 0 ); ?> response(s) did not pass header verification; <?php echo intval( $cm['server_missing'] ?? 0 ); ?> had no matching server-side run.</p>
                    <?php else: ?>
                        <table class="widefat striped pfc-responsive-table">
                            <thead><tr><th>Run</th><th>PHP</th><th>SQL time</th><th>SQL queries</th><th>HTTP API time</th><th>Cache GET lookups</th><th>Hits</th><th>Misses</th><th>Hit ratio</th></tr></thead>
                            <tbody><?php foreach ( $cruns as $i => $run ): $c = (array) ( $run['cache'] ?? array() ); ?>
                                <tr><td><?php echo intval( $i + 1 ); ?></td>
                                <td><?php echo esc_html( (string) $run['php_ms'] ); ?> ms</td>
                                <td><?php echo ! empty( $run['sql_timing_available'] ) ? esc_html( (string) $run['db_ms'] ) . ' ms' : 'Not measured'; ?></td>
                                <td><?php echo intval( $run['queries'] ?? 0 ); ?></td>
                                <td><?php echo isset( $run['http_ms'] ) ? esc_html( (string) $run['http_ms'] ) . ' ms' : 'n/a'; ?></td>
                                <td><?php echo isset( $c['lookups'] ) ? intval( $c['lookups'] ) : 'Not exposed'; ?></td>
                                <td><?php echo isset( $c['hits'] ) ? intval( $c['hits'] ) : 'n/a'; ?></td>
                                <td><?php echo isset( $c['misses'] ) ? intval( $c['misses'] ) : 'n/a'; ?></td>
                                <td><?php echo isset( $c['hit_rate_percent'] ) ? esc_html( (string) $c['hit_rate_percent'] ) . '%' : 'n/a'; ?></td></tr>
                            <?php endforeach; ?></tbody>
                        </table>
                        <p class="description">Median verified PHP: <?php echo esc_html( round( PFC_Utils::median( (array) ( $cm['php_ms'] ?? array() ) ), 2 ) ); ?> ms. Median SQL: <?php echo ! empty( $cruns[0]['sql_timing_available'] ) ? esc_html( round( PFC_Utils::median( (array) ( $cm['db_ms'] ?? array() ) ), 2 ) ) . ' ms' : 'not available'; ?>. Hit/miss lookups are not Redis commands, and this table does not measure Redis processing time inside the cache drop-in.</p>
                        <?php $latest = (array) end( $cruns ); $cache_result = (array) ( $latest['cache'] ?? array() ); $phases = (array) ( $cache_result['phases'] ?? array() ); ?>
                        <?php if ( $phases ): ?><details open><summary>Cache lookups by request phase, last verified run</summary>
                            <table class="widefat striped"><thead><tr><th>From</th><th>To</th><th>Hits</th><th>Misses</th><th>GET lookups</th></tr></thead><tbody>
                            <?php foreach ( $phases as $phase ): ?><tr><td><?php echo esc_html( $phase['from'] ?? '' ); ?></td><td><?php echo esc_html( $phase['to'] ?? '' ); ?></td><td><?php echo isset( $phase['hits'] ) ? intval( $phase['hits'] ) : 'n/a'; ?></td><td><?php echo isset( $phase['misses'] ) ? intval( $phase['misses'] ) : 'n/a'; ?></td><td><?php echo isset( $phase['lookups'] ) ? intval( $phase['lookups'] ) : 'n/a'; ?></td></tr><?php endforeach; ?>
                            </tbody></table><p class="description">Phases are time intervals, not plugin identities. Calls may happen inside any plugin or theme executing within an interval.</p></details><?php endif; ?>
                        <?php if ( ! empty( $latest['sql_components'] ) ): ?><details><summary>Top SQL-attributed components, last verified run</summary>
                            <table class="widefat striped"><thead><tr><th>Component</th><th>SQL time</th><th>Queries</th><th>Patterns</th></tr></thead><tbody>
                            <?php foreach ( array_slice( (array) $latest['sql_components'], 0, 8 ) as $row ): ?><tr><td><code><?php echo esc_html( (string) ( $row['component'] ?? '' ) ); ?></code></td><td><?php echo esc_html( (string) ( $row['sql_ms'] ?? 0 ) ); ?> ms</td><td><?php echo intval( $row['queries'] ?? 0 ); ?></td><td><?php echo intval( $row['patterns'] ?? 0 ); ?></td></tr><?php endforeach; ?></tbody></table>
                            <p class="description">This identifies SQL query origins only. It does not identify which plugin called Redis or measure the time spent in its cache calls.</p></details><?php endif; ?>
                    <?php endif; ?>
                    <?php if ( ! empty( $latest['phase_timings'] ) ): ?>
                        <details id="pfc-phase-time"><summary>Slowest WordPress execution intervals (wall time)</summary>
                        <table class="widefat striped"><thead><tr><th>Phase interval</th><th>Duration</th></tr></thead><tbody>
                        <?php foreach ( array_slice( (array) $latest['phase_timings'], 0, 12 ) as $interval ): ?>
                        <tr><td><code><?php echo esc_html( str_replace( '_to_', ' → ', (string) ( $interval['interval'] ?? '' ) ) ); ?></code></td><td><?php echo esc_html( (string) ( $interval['duration_ms'] ?? 0 ) ); ?> ms</td></tr>
                        <?php endforeach; ?></tbody></table>
                        <p class="description">Intervals include theme, plugin, cache and database work executing during that period; they are not per-plugin execution time, and should not be added to overlapping hook timing measurements.</p>
                        </details>
                    <?php endif; ?>
                    <?php if ( ! empty( $latest['http_requests'] ) ): ?>
                        <details open id="pfc-http-attribution"><summary>Outbound HTTP timing and likely callers, last verified request</summary>
                        <p class="description">Captured through the WordPress HTTP API. Target host, path, parameters, response and error text are withheld here. Component attribution is based on a compact PHP call stack and can be unknown. Durations are not guaranteed exclusive of other measured time.</p>
                        <table class="widefat striped pfc-responsive-table"><thead><tr><th>Likely component</th><th>Destination</th><th>Method</th><th>Blocking</th><th>Status</th><th>Duration</th></tr></thead><tbody>
                        <?php foreach ( array_slice( (array) $latest['http_requests'], 0, 20 ) as $call ): ?>
                            <tr><td><code><?php echo esc_html( (string) ( $call['component'] ?? 'core:unknown' ) ); ?></code></td>
                            <td><?php echo esc_html( (string) ( $call['target_type'] ?? 'unknown' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $call['method'] ?? 'OTHER' ) ); ?></td>
                            <td><?php echo ! empty( $call['blocking'] ) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo isset( $call['status'] ) ? intval( $call['status'] ) : 'Error / unknown'; ?></td>
                            <td><?php echo esc_html( (string) ( $call['duration_ms'] ?? 0 ) ); ?> ms</td></tr>
                        <?php endforeach; ?></tbody></table>
                        <p class="description">Repair strategy: identify the owning integration and change its own settings or code to cache responses, batch lookups or move nonessential remote work to a background queue. Performance Console never globally blocks HTTP requests or caches potentially authenticated responses.</p>
                        </details>
                    <?php endif; ?>
                    <?php if ( ! empty( $latest['http_callsites'] ) ): ?>
                        <details id="pfc-http-callsite-inspector" open>
                            <summary>HTTP call-site inspector and rate-limit evidence (last verified request)</summary>
                            <p class="description">Signed deep request only. Shows the PHP call site that initiated each WordPress HTTP API target group. Target IDs are site-secret HMACs of HTTP method, destination host and path; they reveal neither the domain nor URL or query parameters. Multiple calls to a group are NOT proof of retries because query strings may differ.</p>
                            <?php if ( ! empty( $latest['http_truncated'] ) ): ?><p class="pfc-warning">Over 100 HTTP events were observed. Results are incomplete because collection was bounded.</p><?php endif; ?>
                            <table class="widefat striped pfc-responsive-table"><thead><tr><th>Component / target group</th><th>Calls / status</th><th>HTTP time (summed)</th><th>PHP call site</th></tr></thead><tbody>
                            <?php foreach ( array_slice( (array) $latest['http_callsites'], 0, 20 ) as $http_site ): ?>
                                <tr>
                                    <td><strong><code><?php echo esc_html( (string) ( $http_site['component'] ?? '' ) ); ?></code></strong><br><small><?php echo esc_html( (string) ( $http_site['target_type'] ?? '' ) ); ?> · <?php echo esc_html( (string) ( $http_site['method'] ?? '' ) ); ?><br>Group: <code><?php echo esc_html( (string) ( $http_site['target_group_id'] ?? 'unknown' ) ); ?></code></small></td>
                                    <td><?php echo intval( $http_site['calls'] ?? 0 ); ?> calls; <?php echo intval( $http_site['failed_calls'] ?? 0 ); ?> failed<br><strong><?php echo intval( $http_site['rate_limited_429'] ?? 0 ); ?> HTTP 429</strong>; <?php echo intval( $http_site['no_status_failures'] ?? 0 ); ?> no-status failures</td>
                                    <td><?php echo intval( $http_site['blocking_calls'] ?? 0 ); ?> blocking call(s)<br><?php echo esc_html( (string) ( $http_site['total_ms'] ?? 0 ) ); ?> ms summed; slowest <?php echo esc_html( (string) ( $http_site['slowest_ms'] ?? 0 ) ); ?> ms</td>
                                    <td><strong><code><?php echo esc_html( (string) ( $http_site['function'] ?? 'unknown' ) ); ?></code></strong><br><small><code><?php echo esc_html( (string) ( $http_site['source'] ?? 'unattributed' ) ); ?></code>:<?php echo intval( $http_site['line'] ?? 0 ); ?> · Hook: <code><?php echo esc_html( (string) ( $http_site['hook'] ?? 'unavailable' ) ); ?></code></small></td>
                                </tr>
                            <?php endforeach; ?></tbody></table>
                            <p class="description">A recurring 429 means the remote provider rejected requests. Confirm the integration's quota, refresh scheduling, product-data cache and backoff settings. Do not automatically block, resend or override third-party calls. HTTP totals may overlap PHP timing.</p>
                        </details>
                    <?php endif; ?>
                    <?php $sql_first_following = PFC_Workload_Advisor::first_vs_following_sql( $cruns ); ?>
                    <?php if ( ! empty( $sql_first_following['available'] ) ): ?>
                        <details id="pfc-first-following-sql"><summary>First request versus following SQL analysis</summary>
                            <p>First SQL: <strong><?php echo esc_html( (string) $sql_first_following['first_sql_ms'] ); ?> ms</strong>; following requests median: <strong><?php echo esc_html( (string) $sql_first_following['later_median_sql_ms'] ); ?> ms</strong>. Difference: <strong><?php echo esc_html( (string) $sql_first_following['first_minus_later_ms'] ); ?> ms</strong>.</p>
                            <?php if ( ! empty( $sql_first_following['first_only_patterns'] ) ): ?>
                            <table class="widefat striped pfc-responsive-table"><thead><tr><th>First-only SQL fingerprint</th><th>Component</th><th>First request SQL</th></tr></thead><tbody>
                            <?php foreach ( $sql_first_following['first_only_patterns'] as $first_pattern ): ?><tr><td><code><?php echo esc_html( (string) $first_pattern['pattern_id'] ); ?></code></td><td><code><?php echo esc_html( (string) $first_pattern['component'] ); ?></code></td><td><?php echo esc_html( (string) $first_pattern['first_sql_ms'] ); ?> ms</td></tr><?php endforeach; ?>
                            </tbody></table>
                            <?php endif; ?>
                            <p class="description">Only the observed signed sample is compared. First-only SQL patterns come from a bounded top-pattern list and may omit additional queries. A first-run spike does not prove cold-cache causation: page cache is bypassed, and database state, content and server load can change.</p>
                        </details>
                    <?php endif; ?>
                    <?php if ( ! empty( $latest['sql_patterns'] ) ): ?>
                        <details id="pfc-sql-patterns"><summary>Repeated SQL patterns (anonymized), last verified request</summary>
                            <table class="widefat striped pfc-responsive-table"><thead><tr><th>Pattern fingerprint</th><th>Attributed component</th><th>Executions</th><th>Total SQL</th><th>Slowest execution</th></tr></thead><tbody>
                            <?php foreach ( array_slice( (array) $latest['sql_patterns'], 0, 20 ) as $pattern ): ?>
                                <tr><td><code><?php echo esc_html( (string) ( $pattern['pattern_id'] ?? '' ) ); ?></code></td>
                                    <td><code><?php echo esc_html( (string) ( $pattern['component'] ?? '' ) ); ?></code></td>
                                    <td><?php echo intval( $pattern['count'] ?? 0 ); ?></td>
                                    <td><?php echo esc_html( (string) ( $pattern['total_ms'] ?? 0 ) ); ?> ms</td>
                                    <td><?php echo esc_html( (string) ( $pattern['max_ms'] ?? 0 ) ); ?> ms</td></tr>
                            <?php endforeach; ?></tbody></table>
                            <p class="description">A high repeat count is evidence for further review, not proof of redundant SQL. If query output depends on state, blindly caching or deduplicating it could break functionality. No raw statements or literal values are exported.</p>
                        </details>
                    <?php endif; ?>
                    <?php if ( ! empty( $latest['sql_callsites'] ) ): ?>
                        <details id="pfc-sql-callsite-inspector" open>
                            <summary>SQL call-site inspector (administrator only, last verified request)</summary>
                            <p class="description">Generated only on signed deep-profile requests. Maps SQL fingerprints to the invoking PHP method, component-relative source file and active WordPress hook. No SQL text, SQL parameters or absolute server paths are displayed here or included in the AI export. Call-site timings measure SQL execution, not the full PHP function. A missing trace is shown as unattributed rather than guessed.</p>
                            <?php if ( ! empty( $latest['sql_callsites_truncated'] ) ): ?><p class="pfc-warning">The query trace or query-detail collection limit was reached. This attribution is incomplete.</p><?php endif; ?>
                            <table class="widefat striped pfc-responsive-table">
                                <thead><tr><th>Fingerprint / component</th><th>SQL calls / time</th><th>PHP call sites</th></tr></thead>
                                <tbody>
                                <?php foreach ( array_slice( (array) $latest['sql_callsites'], 0, 35 ) as $pattern ): ?>
                                    <tr>
                                        <td><code><?php echo esc_html( (string) ( $pattern['pattern_id'] ?? '' ) ); ?></code><br><small><?php echo esc_html( (string) ( $pattern['component'] ?? '' ) ); ?></small></td>
                                        <td><?php echo intval( $pattern['count'] ?? 0 ); ?> calls<br><?php echo esc_html( (string) ( $pattern['total_ms'] ?? 0 ) ); ?> ms</td>
                                        <td>
                                            <?php if ( empty( $pattern['sites'] ) ): ?>No source call site identified<?php endif; ?>
                                            <?php foreach ( array_slice( (array) ( $pattern['sites'] ?? array() ), 0, 6 ) as $site ): ?>
                                                <div style="margin-bottom:9px"><strong><code><?php echo esc_html( (string) ( $site['callable'] ?? 'unknown' ) ); ?></code></strong><br>
                                                <small><code><?php echo esc_html( (string) ( $site['file'] ?? '' ) ); ?></code>:<?php echo intval( $site['line'] ?? 0 ); ?> · Hook: <code><?php echo esc_html( (string) ( $site['hook'] ?? 'unavailable' ) ); ?></code> · <?php echo intval( $site['count'] ?? 0 ); ?> calls · <?php echo esc_html( (string) ( $site['total_ms'] ?? 0 ) ); ?> ms</small></div>
                                            <?php endforeach; ?>
                                            <?php if ( ! empty( $pattern['unattributed_count'] ) ): ?><small><?php echo intval( $pattern['unattributed_count'] ); ?> call(s) with no attributable stack</small><?php endif; ?>
                                            <?php if ( ! empty( $pattern['other_sites_count'] ) ): ?><br><small>Calls from additional sites omitted by safety limit: <?php echo intval( $pattern['other_sites_count'] ); ?></small><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="description">Follow the named function in the owning plugin before changing any query. Repeated fingerprint counts alone do not prove that a query can be removed or cached safely.</p>
                        </details>
                    <?php endif; ?>
                    <?php $callback_run = (array) end( $cruns ); $callback_info = (array) ( $callback_run['callback_profile'] ?? array() ); $bricks_info = (array) ( $callback_run['bricks_profile'] ?? array() ); ?>
                    <p class="description"><strong>Cache attribution capability:</strong> <?php $capability = (array) ( $callback_run['cache_attribution_capability'] ?? array() ); echo esc_html( (string) ( $capability['mode'] ?? 'not evaluated' ) ); ?>. WordPress cache GET counters can be measured during selected callbacks, but generic per-operation source attribution and Redis network timings are unavailable without supported drop-in instrumentation.</p>
                    <?php if ( ! empty( $callback_info['enabled'] ) ): ?>
                    <div class="pfc-box" id="php-callback-inspector">
                        <h4>PHP Hook and Callback Inspector</h4>
                        <p>Measured only on explicitly selected, signed request probes. Self times subtract other instrumented nested callbacks; inclusive times may overlap. Uninstrumented nested work remains attributed to its containing callback.</p>
                        <?php $bricks_rows = array_values( array_filter( (array) ( $callback_info['rows'] ?? array() ), static function ( $r ) { return 'theme:bricks' === (string) ( $r['component'] ?? '' ); } ) );
                            $bricks_self = array_sum( array_map( static function ( $r ) { return (float) ( $r['self_ms'] ?? 0 ); }, $bricks_rows ) );
                            $bricks_lookups = array_sum( array_map( static function ( $r ) { return (int) ( $r['cache_get_lookups'] ?? 0 ); }, $bricks_rows ) ); ?>
                        <p><strong>Bricks callback evidence:</strong> <?php echo intval( count( $bricks_rows ) ); ?> measured callback(s) · <?php echo esc_html( (string) round( $bricks_self, 2 ) ); ?> ms self time · <?php echo intval( $bricks_lookups ); ?> cache GET counter changes within measured scopes. These are partial measurements, not total Bricks rendering time.</p>
                        <p><strong>Instrumented:</strong> <?php echo intval( $callback_info['callbacks_wrapped'] ?? 0 ); ?> callbacks · <strong>Hooks observed:</strong> <?php echo intval( $callback_info['hooks_fired'] ?? 0 ); ?> · <strong>Reference callbacks skipped:</strong> <?php echo intval( $callback_info['reference_callbacks_skipped'] ?? 0 ); ?> · <strong>Profiler bookkeeping lower bound:</strong> <?php echo esc_html( (string) ( $callback_info['bookkeeping_lower_bound_ms'] ?? 0 ) ); ?> ms.</p>
                        <?php $cb_rows = array_slice( (array) ( $callback_info['rows'] ?? array() ), 0, 20 ); ?>
                        <?php if ( $cb_rows ): ?>
                        <table class="widefat striped pfc-responsive-table"><thead><tr><th>Hook and callback</th><th>Component</th><th>Calls</th><th>Self PHP</th><th>Inclusive PHP</th><th>Cache GETs in scope</th></tr></thead><tbody>
                        <?php foreach ( $cb_rows as $cb ): ?>
                            <tr><td><code><?php echo esc_html( (string) ( $cb['hook'] ?? '' ) ); ?></code><br><strong><?php echo esc_html( (string) ( $cb['callback'] ?? '' ) ); ?></strong><br><small><?php echo esc_html( (string) ( $cb['file'] ?? '' ) ); ?>:<?php echo intval( $cb['line'] ?? 0 ); ?></small></td><td><?php echo esc_html( (string) ( $cb['component'] ?? '' ) ); ?></td><td><?php echo intval( $cb['calls'] ?? 0 ); ?></td><td><?php echo esc_html( (string) ( $cb['self_ms'] ?? 0 ) ); ?> ms</td><td><?php echo esc_html( (string) ( $cb['inclusive_ms'] ?? 0 ) ); ?> ms</td><td><?php echo isset( $cb['cache_get_lookups'] ) ? intval( $cb['cache_get_lookups'] ) : 'Not exposed'; ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                        <?php else: ?><p>No allowlisted callback executed within the sampled request. This is incomplete coverage, not evidence of zero PHP rendering work.</p><?php endif; ?>
                        <p class="description">Cache GETs are deltas of public WordPress hit/miss counters observed during a timed callback. They are not Redis commands or measured Redis time. Instrumentation adds overhead, and reference callbacks are intentionally not wrapped. Coverage is limited to selected hooks, including Bricks rendering hooks where present.</p>
                    </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $bricks_info['enabled'] ) ): ?>
                    <div class="pfc-box" id="pfc-bricks-query-render">
                        <h4>Bricks Query and Render Inspector</h4>
                        <p><strong>Bricks-origin WP_Query calls observed:</strong> <?php echo intval( $bricks_info['observed_bricks_queries'] ?? 0 ); ?> · <strong>Exact element matches:</strong> <?php echo intval( $bricks_info['exact_element_attributions'] ?? 0 ); ?> · <strong>Query samples dropped:</strong> <?php echo intval( $bricks_info['query_samples_dropped'] ?? 0 ); ?> · <strong>Missing completion hook:</strong> <?php echo intval( $bricks_info['unfinished_queries'] ?? 0 ); ?> · <strong>Instrumentation bookkeeping lower bound:</strong> <?php echo esc_html( (string) ( $bricks_info['bookkeeping_lower_bound_ms'] ?? 0 ) ); ?> ms.</p>
                        <p class="description">The Bricks result filter permits exact WP_Query-to-element matching when supported by this Bricks version. Short element IDs appear only on this administrator page and are not included in AI exports. A missing completion hook is an instrumentation gap, not evidence of a failed query. SQL, query and callback elapsed times can overlap.</p>
                        <?php $completion = (array) ( $bricks_info['query_completion'] ?? array() ); ?>
                        <p><strong>Query lifecycle:</strong> <?php echo intval( $completion['the_posts'] ?? 0 ); ?> completed at <code>the_posts</code> · <?php echo intval( $completion['bricks_result_fallback'] ?? 0 ); ?> at Bricks result fallback · <?php echo intval( $completion['bricks_result_object_matched'] ?? 0 ); ?> result-object matches · <?php echo intval( $completion['no_completion_hook'] ?? 0 ); ?> with no observed end hook.</p>
                        <?php $unclosed = (array) ( $bricks_info['unclosed_query_evidence'] ?? array() );
                            $unclosed_counts = (array) ( $unclosed['categories'] ?? array() ); ?>
                        <?php if ( $unclosed_counts ): ?>
                        <details><summary><strong>Unclosed query investigation</strong> (<?php echo intval( $completion['no_completion_hook'] ?? 0 ); ?> scopes)</summary>
                            <p>These are missing instrumentation endpoints, not query failures. Classification is based only on safe state available at probe shutdown; a suppressed-filter flag is a possible explanation, not confirmation.</p>
                            <p><strong>Suppressed-filter candidates:</strong> <?php echo intval( $unclosed_counts['filters_suppressed_candidate'] ?? 0 ); ?> · <strong>Query objects released:</strong> <?php echo intval( $unclosed_counts['object_released'] ?? 0 ); ?> · <strong>Result objects still available:</strong> <?php echo intval( $unclosed_counts['results_present_without_hook'] ?? 0 ); ?> · <strong>State unknown:</strong> <?php echo intval( $unclosed_counts['no_result_state_observed'] ?? 0 ); ?> · <strong>Reused object IDs:</strong> <?php echo intval( $unclosed_counts['reused_object_id'] ?? 0 ); ?></p>
                            <?php if ( ! empty( $unclosed['groups'] ) ): ?>
                            <table class="widefat striped"><thead><tr><th>Bricks source / query shape</th><th>Observed state</th><th>Calls</th></tr></thead><tbody>
                            <?php foreach ( array_slice( (array) $unclosed['groups'], 0, 15 ) as $gap ): ?>
                            <tr><td><code><?php echo esc_html( (string) ( $gap['parent_scope'] ?? '' ) ); ?></code> · <code><?php echo esc_html( (string) ( $gap['shape_group'] ?? '' ) ); ?></code><br><small><?php echo esc_html( implode( ', ', (array) ( (array) ( $gap['shape'] ?? array() ) )['post_types'] ?? array() ) ); ?>, <?php echo intval( ( (array) ( $gap['shape'] ?? array() ) )['posts_per_page'] ?? 0 ); ?> requested</small></td><td><?php echo esc_html( str_replace( '_', ' ', (string) ( $gap['category'] ?? '' ) ) ); ?></td><td><?php echo intval( $gap['calls'] ?? 0 ); ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table><?php endif; ?>
                        </details>
                        <?php endif; ?>
                        <?php $initialization = (array) ( $bricks_info['initialization_breakdown'] ?? array() ); ?>
                        <?php if ( $initialization ): ?>
                        <h5>Bricks initialization: callback time compared with child query work</h5>
                        <table class="widefat striped pfc-responsive-table"><thead><tr><th>Callback scope</th><th>Callback elapsed</th><th>Observed query scopes</th><th>Difference</th><th>Cache GETs in callback / query scopes</th></tr></thead><tbody>
                        <?php foreach ( $initialization as $block ): ?>
                        <tr><td><code><?php echo esc_html( (string) ( $block['scope'] ?? '' ) ); ?></code><br><small><code><?php echo esc_html( (string) ( $block['callback_file'] ?? '' ) ); ?>:<?php echo intval( $block['callback_line'] ?? 0 ); ?></code></small></td>
                        <td><?php echo esc_html( (string) ( $block['callback_inclusive_ms'] ?? 0 ) ); ?> ms</td>
                        <td><?php echo esc_html( (string) ( $block['observed_child_query_ms'] ?? 0 ) ); ?> ms (<?php echo intval( $block['child_queries'] ?? 0 ); ?> query scopes; <?php echo esc_html( (string) ( $block['observed_child_sql_ms'] ?? 0 ) ); ?> ms SQL)</td>
                        <td><?php echo isset( $block['callback_minus_observed_queries_ms'] ) ? esc_html( (string) $block['callback_minus_observed_queries_ms'] ) . ' ms' : 'Not comparable'; ?></td>
                        <td><?php echo isset( $block['cache_get_lookups_during_callback'] ) ? intval( $block['cache_get_lookups_during_callback'] ) : 'n/a'; ?> / <?php echo isset( $block['cache_get_lookups_during_child_queries'] ) ? intval( $block['cache_get_lookups_during_child_queries'] ) : 'n/a'; ?></td></tr>
                        <?php endforeach; ?></tbody></table>
                        <p class="description">The difference is an arithmetic comparison, not exclusive PHP time and not a guaranteed optimization opportunity. Query scopes may overlap and uninstrumented nested work remains in the callback total.</p>
                        <?php endif; ?>
                        <?php $accessors = (array) ( $bricks_info['initialization_accessor_profile'] ?? array() ); ?>
                        <?php if ( ! empty( $accessors['enabled'] ) ): ?>
                        <h5>Bricks initialization: selected metadata and option accessors</h5>
                        <p>Bounded, read-only WordPress metadata and option filters observed while the two callback scopes execute. Repeated anonymous groups are <strong>not</strong> Redis commands or complete cache attribution. Counts can overlap queries.</p>
                        <p><strong>Accessor events observed:</strong> <?php echo intval( $accessors['observed_events'] ?? 0 ); ?> of a maximum <?php echo intval( $accessors['event_limit'] ?? 0 ); ?>; <strong>Skipped after limit:</strong> <?php echo intval( $accessors['events_skipped_at_limit'] ?? 0 ); ?>.</p>
                        <table class="widefat striped pfc-responsive-table"><thead><tr><th>Callback</th><th>Post metadata</th><th>Term metadata</th><th>User metadata</th><th>Option accessors</th><th>Anonymous key groups / overflow</th></tr></thead><tbody>
                        <?php foreach ( (array) ( $accessors['scopes'] ?? array() ) as $scope ): ?>
                        <tr><td><code><?php echo esc_html( (string) ( $scope['scope'] ?? '' ) ); ?></code></td>
                        <td><?php echo intval( $scope['post_metadata_calls'] ?? 0 ); ?></td>
                        <td><?php echo intval( $scope['term_metadata_calls'] ?? 0 ); ?></td>
                        <td><?php echo intval( $scope['user_metadata_calls'] ?? 0 ); ?></td>
                        <td><?php echo intval( $scope['option_calls'] ?? 0 ); ?></td>
                        <td><?php echo intval( $scope['hashed_accessor_groups_observed'] ?? 0 ); ?> / <?php echo intval( $scope['other_or_untracked_keys'] ?? 0 ); ?></td></tr>
                        <?php endforeach; ?></tbody></table>
                        <?php foreach ( (array) ( $accessors['scopes'] ?? array() ) as $scope ): ?>
                        <?php if ( ! empty( $scope['top_accessor_groups'] ) ): ?>
                        <details><summary>Repeated accessor keys and source hints in <?php echo esc_html( (string) ( $scope['scope'] ?? '' ) ); ?> (administrator only)</summary>
                        <p class="description">Names resembling secrets are masked. An accessor name may be used for many objects; counts are not unique cache keys or Redis commands. Object diversity counts stop at 128 samples per group. *Repeat counts mean the same key/object appeared again within the bounded sample, not that Redis performed an unnecessary operation.</p>
                        <div class="pfc-scroll-x"><table class="widefat striped"><thead><tr><th>Kind</th><th>Key name (local)</th><th>Hashed group</th><th>Calls</th><th>Distinct objects sampled</th><th>Repeat key/object observations*</th><th>Source hint (sampled once)</th></tr></thead><tbody>
                        <?php foreach ( array_slice( (array) $scope['top_accessor_groups'], 0, 12 ) as $group ): ?>
                        <tr><td><?php echo esc_html( (string) ( $group['kind'] ?? '' ) ); ?></td><td><code><?php echo esc_html( (string) ( $group['key_local'] ?? '[unavailable]' ) ); ?></code></td><td><code><?php echo esc_html( (string) ( $group['key_group'] ?? '' ) ); ?></code></td><td><?php echo intval( $group['calls'] ?? 0 ); ?></td><td><?php echo intval( $group['distinct_objects_sampled'] ?? 0 ); ?><?php echo !empty($group['distinct_object_limit_reached'])?' +':''; ?></td><td><?php echo intval( $group['repeat_object_hits_sampled'] ?? 0 ); ?><?php echo !empty($group['distinct_object_limit_reached'])?' (partial)':''; ?></td><td><code><?php echo esc_html( (string) ( $group['callsite_local'] ?? 'unavailable' ) ); ?></code></td></tr>
                        <?php endforeach; ?></tbody></table></div></details>
                        <?php endif; endforeach; ?>
                        <?php endif; ?>
                        <?php $priority_elements = PFC_Bricks_Inspector::priority_review_rows( $cruns ); ?>
                        <?php if ( $priority_elements ): ?>
                        <h5>Priority Bricks query-loop elements: first request versus later requests</h5>
                        <p>Ranked by <strong>first verified request SQL</strong>, not the last warmed request. Matching requires the same anonymous query shape and exact Bricks element group on subsequent requests. Raw short IDs remain administrator-only. Suggested checks are hypotheses, not confirmed optimizations.</p>
                        <table class="widefat striped pfc-responsive-table"><thead><tr><th>Bricks element ID</th><th>Query shape</th><th>First-run SQL / elapsed</th><th>Later median SQL / elapsed</th><th>Configuration checks</th></tr></thead><tbody>
                        <?php foreach ( $priority_elements as $qr ): $shape = (array) ( $qr['shape'] ?? array() ); ?>
                        <tr><td><strong><code>brxe-<?php echo esc_html( (string) $qr['element_id_local'] ); ?></code></strong></td>
                        <td><?php echo esc_html( implode( ', ', (array) ( $shape['post_types'] ?? array() ) ) ); ?> · <?php echo intval( $shape['posts_per_page'] ?? 0 ); ?> requested · <?php echo intval( $shape['tax_clauses'] ?? 0 ); ?> taxonomy clause(s)</td>
                        <td><?php echo isset( $qr['first_sql_ms'] ) ? esc_html( (string) $qr['first_sql_ms'] ) . ' ms' : 'n/a'; ?> SQL / <?php echo isset( $qr['first_elapsed_ms'] ) ? esc_html( (string) $qr['first_elapsed_ms'] ) . ' ms' : 'n/a'; ?> elapsed</td>
                        <td><?php echo isset( $qr['later_median_sql_ms'] ) ? esc_html( (string) $qr['later_median_sql_ms'] ) . ' ms' : 'not matched'; ?> SQL / <?php echo isset( $qr['later_median_elapsed_ms'] ) ? esc_html( (string) $qr['later_median_elapsed_ms'] ) . ' ms' : 'not matched'; ?> elapsed<br><small><?php echo intval( $qr['following_runs_matched'] ); ?> following run(s) matched</small></td>
                        <td><?php echo esc_html( implode( '; ', (array) ( $qr['signals'] ?? array() ) ) ); ?></td></tr>
                        <?php endforeach; ?></tbody></table>
                        <p class="description">Start with the first two mixed post/review sections and the three-taxonomy-clause query. Check AND/OR relationships, pagination and cache behaviour on staging; validate that published content, sorting, and homepage layout remain unchanged.</p>
                        <?php endif; ?>
                        <?php $hot_phases = (array) ( $bricks_info['parent_callback_query_scopes'] ?? array() ); ?>
                        <?php if ( $hot_phases ): ?><p><strong>Bricks callback-path query evidence:</strong> <?php foreach ( $hot_phases as $phase ): ?> <code><?php echo esc_html( (string) ( $phase['scope'] ?? '' ) ); ?></code>: <?php echo intval( $phase['query_calls'] ?? 0 ); ?> query scopes, <?php echo esc_html( (string) ( $phase['observed_query_elapsed_ms'] ?? 0 ) ); ?> ms observed query elapsed, <?php echo esc_html( (string) ( $phase['observed_sql_ms'] ?? 0 ) ); ?> ms SQL; <?php endforeach; ?></p><p class="description">These totals represent observed child query intervals, not the complete assets/elements callback execution time. Nested scopes can overlap.</p><?php endif; ?>
                        <?php $element_hints = array_slice( (array) ( $bricks_info['observed_bricks_element_ids_local'] ?? array() ), 0, 16 ); ?>
                        <?php if ( $element_hints ): ?><p><strong>Query-loop elements observed through Bricks (admin only):</strong> <?php foreach ( $element_hints as $hint ): ?><code>brxe-<?php echo esc_html( (string) $hint ); ?></code> <?php endforeach; ?></p><p class="description">This is a list of observed element IDs, not a claimed match to every WordPress query. Look up these IDs in the Bricks builder. IDs are removed from AI exports.</p><?php endif; ?>
                        <?php $bq = array_slice( (array) ( $bricks_info['query_groups'] ?? array() ), 0, 15 ); ?>
                        <?php if ( $bq ): ?>
                        <table class="widefat striped pfc-responsive-table"><thead><tr><th>Query shape and origin</th><th>Calls / results</th><th>Elapsed</th><th>SQL</th><th>Cache lookups during scope</th></tr></thead><tbody>
                        <?php foreach ( $bq as $qr ): ?><tr>
                            <td><code><?php echo esc_html( (string) ( $qr['shape_group'] ?? '' ) ); ?></code><br><small><?php echo esc_html( (string) ( ( (array) ( $qr['origin'] ?? array() ) )['source'] ?? '' ) ); ?> · <?php echo esc_html( (string) ( ( (array) ( $qr['origin'] ?? array() ) )['function'] ?? '' ) ); ?></small><br><small><?php echo esc_html( implode( ', ', (array) ( ( (array) ( $qr['shape'] ?? array() ) )['post_types'] ?? array() ) ) ); ?> · element group <?php echo esc_html( (string) ( $qr['element_group'] ?: 'not exposed' ) ); ?><?php if ( ! empty( $qr['element_id_local'] ) ): ?> · <strong>Bricks ID: brxe-<?php echo esc_html( (string) $qr['element_id_local'] ); ?></strong><?php endif; ?> · <?php echo esc_html( (string) ( $qr['parent_scope'] ?? 'other-bricks' ) ); ?> · <?php echo esc_html( (string) ( $qr['completion_method'] ?? 'unknown' ) ); ?></small></td>
                            <td><?php echo intval( $qr['calls'] ?? 0 ); ?> / <?php echo intval( $qr['result_count'] ?? 0 ); ?></td>
                            <td><?php echo esc_html( (string) ( $qr['elapsed_ms'] ?? 0 ) ); ?> ms</td>
                            <td><?php echo isset( $qr['sql_ms'] ) ? esc_html( (string) $qr['sql_ms'] ) . ' ms' : 'Not available'; ?> (<?php echo isset( $qr['sql_queries'] ) ? intval( $qr['sql_queries'] ) : 'n/a'; ?> queries)</td>
                            <td><?php echo isset( $qr['cache_lookups'] ) ? intval( $qr['cache_lookups'] ) : 'Not exposed'; ?></td>
                        </tr><?php endforeach; ?></tbody></table>
                        <?php else: ?><p>No Bricks-origin WP_Query calls were observed in this sample. This does not establish that Bricks ran no queries or rendered no elements.</p><?php endif; ?>
                        <?php $br = array_slice( (array) ( $bricks_info['render_groups'] ?? array() ), 0, 15 ); ?>
                        <h5>Observed Bricks render-hook scopes</h5>
                        <?php if ( $br ): ?><table class="widefat striped"><thead><tr><th>Hook / element type</th><th>Calls</th><th>Hook callback time</th><th>Cache lookups</th></tr></thead><tbody>
                        <?php foreach ( $br as $rr ): ?><tr><td><?php echo esc_html( (string) ( $rr['hook'] ?? '' ) ); ?><br><small><?php echo esc_html( (string) ( $rr['element_type'] ?? 'unknown' ) ); ?> · <?php echo esc_html( (string) ( $rr['element_group'] ?: 'no element marker' ) ); ?></small></td><td><?php echo intval( $rr['calls'] ?? 0 ); ?></td><td><?php echo esc_html( (string) ( $rr['elapsed_ms'] ?? 0 ) ); ?> ms</td><td><?php echo isset( $rr['cache_lookups'] ) ? intval( $rr['cache_lookups'] ) : 'Not exposed'; ?></td></tr><?php endforeach; ?></tbody></table>
                        <?php else: ?><p>None of the selected Bricks render hooks executed between the inspector start/end listeners, or no completed groups were observed. This is an instrumentation coverage limit.</p><?php endif; ?>
                        <p class="description">Render-hook scopes observed: <?php echo intval( array_sum( (array) ( $bricks_info['render_hook_fires'] ?? array() ) ) ); ?>. Collapsed into type-level totals: <?php echo intval( $bricks_info['render_samples_aggregated'] ?? 0 ); ?>. Dropped: <?php echo intval( $bricks_info['render_samples_dropped'] ?? 0 ); ?>. Hook callbacks are not full element-render timings.</p>
                        <?php if ( ! empty( $bricks_info['query_rows_omitted'] ) || ! empty( $bricks_info['render_rows_omitted'] ) || ! empty( $bricks_info['render_samples_dropped'] ) ): ?><p class="description">Some rows were omitted to bound profiling overhead and stored diagnostic data.</p><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ( is_array( $cache_previous ) && ! empty( $cache_previous['runs'] ) && ! empty( $cp['runs'] ) && (string) ( $cache_previous['url'] ?? '' ) === (string) ( $cp['url'] ?? '' ) && count( (array) $cache_previous['runs'] ) === count( (array) $cp['runs'] ) && count( (array) $cp['runs'] ) >= 3 && (bool) ( $cache_previous['probe_meta']['callback_profiling_requested'] ?? false ) === (bool) ( $cp['probe_meta']['callback_profiling_requested'] ?? false ) && (int) ( $cache_previous['probe_meta']['verified'] ?? 0 ) === (int) ( $cache_previous['probe_meta']['requested'] ?? -1 ) && (int) ( $cp['probe_meta']['verified'] ?? 0 ) === (int) ( $cp['probe_meta']['requested'] ?? -1 ) ): ?>
                        <?php
                            $before_php = array_map( 'floatval', array_column( (array) $cache_previous['runs'], 'php_ms' ) );
                            $after_php = array_map( 'floatval', array_column( (array) $cp['runs'], 'php_ms' ) );
                            $before_sql = array_map( 'floatval', array_column( (array) $cache_previous['runs'], 'db_ms' ) );
                            $after_sql = array_map( 'floatval', array_column( (array) $cp['runs'], 'db_ms' ) );
                            $delta_php = round( PFC_Utils::median( $after_php ) - PFC_Utils::median( $before_php ), 1 );
                            $delta_sql = round( PFC_Utils::median( $after_sql ) - PFC_Utils::median( $before_sql ), 1 );
                        ?>
                        <div class="pfc-box" id="pfc-compare-repairs"><h4>Before/after profile comparison</h4>
                            <p>Same URL and same number of verified requests. PHP median change: <strong><?php echo esc_html( ( $delta_php >= 0 ? '+' : '' ) . $delta_php ); ?> ms</strong>; SQL median change: <strong><?php echo esc_html( ( $delta_sql >= 0 ? '+' : '' ) . $delta_sql ); ?> ms</strong>.</p>
                            <p class="description">Negative means faster in the second profile. Diagnostic overhead, warm/cold cache state and content changes can affect results. This is an observation, not proof of a causal repair.</p>
                        </div>
                    <?php endif; ?>
                    <div class="pfc-box" id="pfc-repair-centre"><h4>Workload repair centre</h4>
                        <p>Only actions with a safe, bounded implementation run automatically. Changes to other plugins' HTTP requests, SQL or cache policies require owner-specific review. Each finding includes a guided next step.</p>
                        <?php $workload_findings = PFC_Workload_Advisor::findings( $cruns, PFC_Scanner::cached_scan() ); ?>
                        <?php if ( ! $workload_findings ): ?><p>No repeatable high-signal findings from these verified requests. Repeat on the affected route and check ordinary (uninstrumented) performance as well.</p><?php endif; ?>
                        <?php foreach ( $workload_findings as $finding ): ?>
                            <div class="pfc-priority-item">
                                <span class="pfc-status pfc-status--<?php echo esc_attr( $finding['severity'] ); ?>"><?php echo esc_html( strtoupper( (string) $finding['severity'] ) ); ?></span>
                                <div class="pfc-priority-item__body">
                                    <strong><?php echo esc_html( $finding['title'] ); ?></strong>
                                    <p><?php echo esc_html( $finding['evidence'] ); ?></p>
                                    <p><strong>Repair guidance:</strong> <?php echo esc_html( $finding['recommendation'] ); ?></p>
                                    <?php if ( 'transients' === $finding['action'] && 'bounded_cleanup' === $finding['repair'] ): ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="pfc_expired_transients">
                                            <?php wp_nonce_field( 'pfc_transients' ); ?>
                                            <label><input type="checkbox" required name="confirm_expired_cleanup" value="1"> I understand this deletes up to 1,000 already-expired transients and cannot undo those deletions.</label>
                                            <button class="button">Clean expired transients (bounded)</button>
                                        </form>
                                    <?php elseif ( 'sql' === $finding['action'] || 'cache' === $finding['action'] || 'http' === $finding['action'] ): ?>
                                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pfc&view=profiling#profiling' ) ); ?>">Open plugin impact A/B testing</a>
                                    <?php elseif ( 'autoload' === $finding['action'] ): ?>
                                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pfc&view=database#database-repair-centre' ) ); ?>">Review reversible autoload changes</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <h4>Interpretation</h4><ul><?php foreach ( (array) ( $cp['guidance'] ?? array() ) as $advice ): ?><li><?php echo esc_html( (string) $advice ); ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>
            <div class="pfc-box" id="pfc-http-self-test">
                <h3>HTTP Collector Verification</h3>
                <p>Perform one bounded, administrator-triggered request to this site's WordPress REST index to verify that the HTTP instrumentation sees a real request. No external API endpoint or credentials are used.</p>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="pfc_http_self_test">
                    <?php wp_nonce_field( 'pfc_http_self_test' ); ?>
                    <button class="button" type="submit">Verify HTTP collector (local loopback)</button>
                </form>
                <?php if ( is_array( $http_self_test ) ): ?>
                    <p><strong>Collector:</strong> <?php echo ! empty( $http_self_test['collector_observed'] ) ? 'Captured HTTP API call' : 'Did not capture a call'; ?> · <strong>Response:</strong> <?php echo isset( $http_self_test['response_status'] ) ? intval( $http_self_test['response_status'] ) : 'No HTTP response'; ?> · <strong>Elapsed:</strong> <?php echo esc_html( (string) ( $http_self_test['elapsed_ms'] ?? '' ) ); ?> ms.</p>
                    <p class="description">A successful self-test validates hook collection for this local request only; it does not prove AAWP or other plugins issued remote calls in the homepage profile.</p>
                <?php endif; ?>
            </div>
            <div class="pfc-box" id="object-cache-pro-troubleshooter">
                <h3>Object Cache Pro Troubleshooter</h3>
                <p>Dedicated, administrator-only checks for native health status, Key Tracking, ACL command capabilities, cached group inventory, passive flush attempts and serialization options. Where the active drop-in does not provide a supported instrumentation interface, the report explicitly marks that information unavailable.</p>
                <?php $ocp = (array) ( $cache_diagnostic['object_cache_pro'] ?? array() ); ?>
                <?php $last_ocp_profile = (array) ( $cache_profile['runs'] ?? array() ); $last_ocp_profile = $last_ocp_profile ? (array) end( $last_ocp_profile ) : array(); $ocp_callback_rows = (array) ( $last_ocp_profile['callback_profile']['rows'] ?? array() ); usort( $ocp_callback_rows, static function ( $a, $b ) { return (int)($b['cache_get_lookups']??$b['cache_get_lookups_during_scope']??0) <=> (int)($a['cache_get_lookups']??$a['cache_get_lookups_during_scope']??0); } ); ?>
                <details><summary><strong>Cache call attribution (verified callback scopes)</strong></summary>
                    <?php if ( ! $ocp_callback_rows ): ?><p>No deep PHP callback profile available. Run a signed profile with deep PHP enabled to collect bounded callback cache-counter changes.</p><?php else: ?>
                        <table class="widefat striped"><thead><tr><th>Component</th><th>Hook</th><th>WordPress cache GET lookups during scope</th><th>Inclusive PHP time</th></tr></thead><tbody><?php foreach(array_slice($ocp_callback_rows,0,12) as $r): ?><tr><td><?php echo esc_html((string)($r['component']??'unknown')); ?></td><td><code><?php echo esc_html((string)($r['hook']??'unknown')); ?></code></td><td><?php echo isset($r['cache_get_lookups']) || isset($r['cache_get_lookups_during_scope']) ? esc_html(number_format_i18n((int)($r['cache_get_lookups']??$r['cache_get_lookups_during_scope']))) : 'Not measured'; ?></td><td><?php echo isset($r['inclusive_ms']) || isset($r['inclusive_php_ms']) ? esc_html((string)($r['inclusive_ms']??$r['inclusive_php_ms'])) . ' ms' : 'Not measured'; ?></td></tr><?php endforeach; ?></tbody></table>
                    <?php endif; ?>
                    <p class="description">These are WordPress cache hit/miss counter changes within specific callback scopes. They are not individual Redis GET calls or exclusive cache-processing time. Nested callback scopes may overlap.</p>
                </details>
                <?php if ( ! $ocp ): ?>
                    <p class="description">Run Redis, Autoload &amp; Object Cache Pro Diagnostics below to populate this panel. Flush attempts will be collected only after the new plugin version is active and Object Cache Pro is detected.</p>
                <?php else: ?>
                    <?php $od=(array)($ocp['detection']??array()); $health=(array)($ocp['health_checks']??array()); $tracking=(array)($ocp['key_tracking_diagnostics']??array()); $acl=(array)($ocp['acl_diagnostics']??array()); $groups=(array)($ocp['cache_group_metrics']??array()); $serial=(array)($ocp['serialization_benchmarks']??array()); ?>
                    <section class="pfc-native-tracking" id="pfc-ocp-native-tracking" aria-label="Native Object Cache Pro Key Tracking diagnosis">
                        <h4>Native Key Tracking diagnosis <span class="pfc-tracking-priority">P0 investigation</span></h4>
                        <p class="description">Checks Object Cache Pro's WordPress Site Health Info data and registered status tests, when exposed. Never assumes that Redis CLIENT TRACKINGINFO describes Object Cache Pro's key tracking.</p>
                        <div class="pfc-tracking-status-grid">
                            <div><span class="pfc-tracking-label">Tracking evidence</span><strong><?php echo esc_html( str_replace('_',' ', (string)($tracking['status']??'not tested') ) ); ?></strong></div>
                            <div><span class="pfc-tracking-label">Evidence source</span><strong><?php echo esc_html( str_replace('_',' ', (string)($tracking['source']??'none') ) ); ?></strong></div>
                            <div><span class="pfc-tracking-label">Failure category</span><strong><?php echo esc_html( str_replace('_',' ', (string)($tracking['failure_category']??'unknown') ) ); ?></strong><small>Based only on native result text, when available</small></div>
                        </div>
                        <p class="description">Source: <?php echo esc_html( str_replace('_',' ', (string)($tracking['source']??'none') ) ); ?> · confidence: <?php echo esc_html( str_replace('_',' ', (string)($tracking['confidence']??'unavailable') ) ); ?>.</p>
                        <p class="description"><strong>Native diagnostic detail:</strong> <?php echo esc_html(str_replace('_',' ',(string)($tracking['detail_level']??'not exposed'))); ?>. A status-only <code>Error</code> cannot identify which Redis operation failed.</p>
                        <?php $triage=(array)($ocp['key_tracking_triage']??array()); ?>
                        <div class="pfc-ocp-triage" aria-label="Native Key Tracking root-cause evidence">
                            <h5>What the evidence actually establishes</h5>
                            <p><strong>Root-cause status:</strong> <?php echo esc_html(str_replace('_',' ',(string)($triage['status']??'not tested'))); ?>. <strong>Exact failed command:</strong> not identified by these public interfaces.</p>
                            <p class="description">Native status is <?php echo !empty($triage['native_evidence'])?'confirmed by Site Health':'not yet confirmed by native Site Health'; ?>. When Site Health supplies only “Error,” there is no Redis error text to categorize. No Redis permission changes are justified by this field alone.</p>
                            <div class="pfc-ocp-triage-grid">
                            <?php foreach((array)($triage['read_only_capabilities']??array()) as $command=>$result): ?>
                                <div><code><?php echo esc_html((string)$command); ?></code><strong><?php echo esc_html(str_replace('_',' ',(string)$result)); ?></strong></div>
                            <?php endforeach; ?>
                            </div>
                            <p class="description">The separate ACL WHOAMI and CLIENT TRACKINGINFO probes are optional informational checks, <strong>not</strong> a reproduction of Object Cache Pro's key-tracking health test. If the category remains unknown, the next step is the provider's native diagnostic details, not a broader ACL.</p>
                            <?php $operator_evidence = (array) ( $triage['administrator_report'] ?? array() ); ?>
                            <?php if ( $operator_evidence ): ?>
                            <p><strong>Administrator-reported native CLI details:</strong> <?php echo esc_html(str_replace('_',' ',(string)($operator_evidence['category']??'unknown'))); ?> · command <?php echo esc_html((string)($operator_evidence['command']??'NOT_IDENTIFIED')); ?>. <small>Not independently verified by Performance Console.</small></p>
                            <?php endif; ?>
                        </div>
                        <?php if ( in_array( (string)($tracking['status']??''), array('native_error','reported_error'), true ) ): ?>
                            <p><strong>Key Tracking error evidence is present.</strong> This does not identify the failing Redis command. Check the corresponding native diagnostic and grant only demonstrably required permissions, if any.</p>
                        <?php elseif ( 'needs_native_health_result' === ($tracking['status']??'') ): ?>
                            <p><strong>Native tracking status not available through the public WordPress hooks.</strong> The existing Redis ACL tests cannot establish the cause of the reported Key Tracking error.</p>
                        <?php endif; ?>
                        <details><summary>How to verify the exact native error</summary>
                            <ol><li>Open <strong>Tools → Site Health → Info</strong>, expand the Object Cache Pro/Redis section, and find <strong>Key Tracking</strong>.</li>
                            <li>For Object Cache Pro 1.x you can also run <code>wp redis diagnostics</code>. Object Cache Pro 2.x documents <code>wp objectcache diagnostics</code>. Do not paste entire CLI diagnostics into an AI export because they may contain configuration values.</li>
                            <li>Record only the Key Tracking status below. If the status is Error, review the native diagnostic's error description locally before changing ACL rules.</li></ol>
                        </details>
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="pfc-tracking-report-form">
                            <input type="hidden" name="action" value="pfc_ocp_tracking_report">
                            <?php wp_nonce_field('pfc_ocp_tracking_report'); ?>
                            <label for="pfc-tracking-reported"><strong>Record native diagnostic findings (no raw text)</strong></label>
                            <select id="pfc-tracking-reported" name="tracking_status" required>
                                <option value="" selected disabled>Select the exact result…</option><option value="reported_error">Error</option><option value="reported_warning">Warning</option>
                                <option value="reported_ok">OK</option><option value="reported_disabled">Disabled / unavailable</option>
                            </select>
                            <label for="pfc-tracking-category"><strong>Cause described in native diagnostic (optional)</strong></label>
                            <select id="pfc-tracking-category" name="tracking_category">
                                <option value="unknown">Unknown / only says Error</option><option value="acl_denied">NOPERM / denied</option>
                                <option value="authentication">Authentication failure</option><option value="unsupported_command">Unknown or unsupported command</option>
                                <option value="connection_or_timeout">Connection / timeout</option><option value="memory_pressure">Memory pressure</option>
                                <option value="read_only">Read-only replica</option><option value="other">Different category (not recorded as free text)</option>
                            </select>
                            <label for="pfc-tracking-command"><strong>Exact command named by native diagnostic (optional)</strong></label>
                            <select id="pfc-tracking-command" name="tracking_command">
                                <option value="NOT_IDENTIFIED">Not identified</option>
                                <?php foreach ( array_slice( PFC_Object_Cache_Pro::reported_tracking_commands(), 1 ) as $cmd ): ?>
                                    <option value="<?php echo esc_attr($cmd); ?>"><?php echo esc_html($cmd); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="pfc-tracking-error-line"><strong>Optional: classify one native error line</strong></label>
                            <textarea id="pfc-tracking-error-line" name="tracking_error_line" rows="2" maxlength="512" placeholder="Only a single sanitized error line, never a full diagnostic dump" autocomplete="off" spellcheck="false"></textarea>
                            <p class="description">Processed once to classify fixed error categories and explicitly named commands. The line itself is discarded immediately, never saved, displayed, logged intentionally or included in AI exports. Leave blank if the native result is only “Error.”</p>
                            <button type="submit" class="button">Save classified status only</button>
                        </form>
                        <p class="description">All administrator-reported details are explicitly labelled, expire after 24 hours, and cannot override confirmed native status or prove an ACL cause. Only selected categories and command names are stored. Never enter passwords, key names or diagnostic text.</p>
                    </section>
                    <details><summary><strong>Redis ACL and tracking capability checks</strong></summary>
                        <p>Status: <?php echo esc_html((string)($acl['status']??'not tested')); ?>. These are optional, read-only Redis commands, not a full audit of current ACL permissions.</p>
                        <table class="widefat striped"><thead><tr><th>Command</th><th>Result</th></tr></thead><tbody><?php foreach ((array)($acl['tests']??array()) as $command=>$a): ?><tr><td><code><?php echo esc_html((string)$command); ?></code></td><td><?php echo esc_html((string)($a['status']??'unknown')); ?></td></tr><?php endforeach; ?></tbody></table>
                        <p class="description">A denied optional INFO, SCAN, ACL WHOAMI or CLIENT TRACKINGINFO command does not alone establish why Key Tracking reports an error. No ACL rules are altered.</p>
                    </details>
                    <details><summary><strong>Cache groups and memory</strong></summary>
                        <p>Native Object Cache Pro REST inventory: <strong><?php echo esc_html((string)($groups['status']??'not run')); ?></strong>; memory estimates: <?php echo !empty($groups['memory_included'])?'included':'not measured'; ?>.</p>
                        <?php if (!empty($groups['rows'])): ?><table class="widefat striped"><thead><tr><th>Group (allowlisted or anonymous)</th><th>Keys</th><th>Group bytes</th></tr></thead><tbody><?php foreach((array)$groups['rows'] as $g): ?><tr><td><?php echo esc_html((string)($g['group']??'other')); ?> <code><?php echo esc_html((string)($g['group_id']??'')); ?></code></td><td><?php echo isset($g['keys'])?intval($g['keys']):'n/a'; ?></td><td><?php echo isset($g['bytes'])?esc_html(size_format((int)$g['bytes'])):'Not measured'; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
                        <?php $growth=(array)($ocp['cache_group_growth']??array()); ?>
                        <h5>Cache-group change since previous opt-in inventory</h5>
                        <p>Comparison: <strong><?php echo esc_html(str_replace('_',' ',(string)($growth['status']??'not tested'))); ?></strong><?php if(isset($growth['elapsed_seconds'])): ?> · elapsed <?php echo intval($growth['elapsed_seconds']); ?> seconds<?php endif; ?>.</p>
                        <?php if(!empty($growth['rows'])): ?><table class="widefat striped"><thead><tr><th>Group</th><th>Change in keys</th><th>Keys/hour (estimate)</th><th>Change in bytes</th></tr></thead><tbody><?php foreach((array)$growth['rows'] as $g): ?><tr><td><?php echo esc_html((string)($g['group']??'other')); ?> <code><?php echo esc_html((string)($g['group_id']??'')); ?></code></td><td><?php echo isset($g['delta_keys'])?esc_html(number_format_i18n($g['delta_keys'])):'Not measured'; ?></td><td><?php echo isset($g['keys_per_hour'])?esc_html(number_format_i18n($g['keys_per_hour'],1)):'Needs 5 min'; ?></td><td><?php echo isset($g['delta_bytes'])?esc_html(number_format_i18n($g['delta_bytes'])):'Not measured'; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
                        <p class="description">Rates require two separately run inventory scans at least five minutes apart. They are observed changes, not an eviction rate or proof of a leak.</p>
                        <p class="description">The first opted-in inventory records a baseline. Future scans compare anonymized groups and never delete entries. Snapshot changes do not prove an active memory leak or an eviction rate.</p>
                        <p class="description">OCP group inventory can be expensive, especially with memory estimates. Individual values and large-object contents are never read or exported.</p>
                    </details>
                    <details><summary><strong>Passive flush investigator</strong></summary>
                        <?php $history=(array)PFC_Object_Cache_Pro::flush_history(); ?>
                        <?php $flush_fresh = PFC_Object_Cache_Pro::flush_freshness($history); ?>
                        <p><strong>Observed attempts in the last 10 minutes:</strong> <?php echo intval($flush_fresh['attempts_last_10_minutes']??0); ?>. <?php if(isset($flush_fresh['latest_event_age_seconds'])): ?>Latest event <?php echo intval($flush_fresh['latest_event_age_seconds']); ?> seconds ago.<?php endif; ?></p>
                        <p class="description">Older stored events are historical and do not prove flushing is still happening. Caller names with no verified provenance are not proof of the source path.</p>
                        <?php if (!$history): ?><p>No observed flush attempts since the passive hooks were enabled. This does not include older Object Cache Pro flush history.</p><?php else: ?><table class="widefat striped"><thead><tr><th>UTC</th><th>Type</th><th>Component</th><th>Caller</th><th>Source</th><th>Confidence</th></tr></thead><tbody><?php foreach(array_slice($history,-20) as $h): ?><tr><td><?php echo esc_html((string)($h['at_utc']??'')); ?></td><td><?php echo esc_html((string)($h['type']??'')); ?></td><td><?php echo esc_html((string)($h['component']??'')); ?></td><td><code><?php echo esc_html((string)($h['caller']??'')); ?></code></td><td><code><?php echo esc_html((string)($h['caller_source']??'unavailable')); ?></code></td><td><?php echo esc_html(str_replace('_',' ',(string)($h['caller_confidence']??'none'))); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
                        <?php $bursts = (array) ( $ocp['flush_bursts'] ?? array() ); ?>
                        <?php if ( $bursts ): ?>
                        <h5>Repeated attempts within 60 seconds</h5>
                        <div class="pfc-scroll-x"><table class="widefat striped"><thead><tr><th>Component</th><th>Anonymous cache group</th><th>Observed attempts</th><th>Window</th></tr></thead><tbody>
                        <?php foreach ( $bursts as $burst ): ?><tr><td><?php echo esc_html((string)($burst['component']??'unknown')); ?></td><td><code><?php echo esc_html((string)($burst['group_id']??'')); ?></code></td><td><?php echo intval($burst['attempts']??0); ?></td><td><?php echo intval($burst['window_seconds']??0); ?> seconds</td></tr><?php endforeach; ?>
                        </tbody></table></div><?php endif; ?>
                        <p class="description">Records pre-flush hook attempts, not confirmed completed Redis flushes. No cache keys, key values or stack traces are stored.</p>
                    </details>
                    <details><summary><strong>PHP serialization and compression benchmark</strong></summary>
                        <p>Isolated sample payload, <?php echo intval($serial['iterations']??0); ?> round-trip samples per available codec. No live cache configuration changes.</p>
                        <table class="widefat striped"><thead><tr><th>Codec</th><th>Status</th><th>Median round-trip</th><th>Output bytes</th></tr></thead><tbody>
                        <?php foreach(array('Serialization'=>(array)($serial['methods']??array()), 'Compression'=>(array)($serial['compression']??array())) as $kind=>$codecs): foreach($codecs as $name=>$sample): ?><tr><td><?php echo esc_html($kind.' · '.$name); ?></td><td><?php echo esc_html((string)($sample['status']??'unavailable')); ?></td><td><?php echo isset($sample['median_roundtrip_ms'])?esc_html((string)$sample['median_roundtrip_ms']).' ms':'n/a'; ?></td><td><?php echo isset($sample['bytes'])?intval($sample['bytes']):'n/a'; ?></td></tr><?php endforeach; endforeach; ?></tbody></table>
                    </details>
                    <details open><summary><strong>Guided recommendations</strong></summary>
                        <table class="widefat striped"><thead><tr><th>Priority</th><th>Recommended investigation</th><th>Mode</th></tr></thead><tbody><?php foreach((array)($ocp['recommendations']??array()) as $a): ?><tr><td><?php echo esc_html(strtoupper((string)($a['priority']??'info'))); ?></td><td><?php echo esc_html((string)($a['message']??'')); ?></td><td>Guided · no automatic changes</td></tr><?php endforeach; ?></tbody></table>
                        <p class="description">For per-operation Redis command stacks, consult Object Cache Pro's Query Monitor integration with tightly bounded diagnostic windows. Permanent command logging may consume significant PHP memory.</p>
                    </details>
                <?php endif; ?>
            </div>
            <div class="pfc-box" id="cache-diagnostics">
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="pfc_cache_diagnose">
                    <?php wp_nonce_field( 'pfc_cache_diagnose' ); ?>
                    <button type="submit" class="button button-primary">Run Redis, Autoload &amp; Object Cache Pro Diagnostics</button>
                    <label style="display:block;margin-top:10px"><input type="checkbox" name="ocp_groups" value="1"> Read Object Cache Pro cache-group inventory (can scan many keys)</label>
                    <label style="display:block;margin-top:6px"><input type="checkbox" name="ocp_group_memory" value="1"> Include group memory estimates (extra workload, off-peak/staging only; requires group inventory)</label>
                </form>
                <p class="description">Includes the existing short-lived Redis probes and autoload scan, plus read-only Object Cache Pro Site Health/ACL capability checks and an isolated synthetic serialization benchmark. Optional group inventory uses Object Cache Pro's GET endpoint, never a custom Redis key scan. Group memory enumeration may be costly and is never automatic. No credentials, cache keys or values are displayed.</p>
            </div>
            <?php if ( ! $cache_diagnostic ): ?>
                <div class="pfc-empty">No recent diagnostic report. Run the test above to identify whether Redis itself or WordPress object-cache processing is slower.</div>
            <?php else:
                $d = (array) $cache_diagnostic;
                $api = (array) ( $d['object_cache'] ?? array() );
                $redis = (array) ( $d['redis'] ?? array() );
                $auto = (array) ( $d['autoload'] ?? array() );
                $cfg = (array) ( $d['configuration'] ?? array() );
            ?>
                <div class="pfc-box">
                    <h3>Measurements</h3>
                    <p>Recorded <?php echo esc_html( (string) ( $d['at'] ?? '' ) ); ?>. Persistent cache: <strong><?php echo ! empty( $d['persistent'] ) ? 'active' : 'not detected'; ?></strong>. Provider: <?php echo esc_html( (string) ( $cfg['provider'] ?? 'unknown' ) ); ?>. Persistent connections: <?php echo ! isset( $cfg['persistent_connection'] ) ? 'unknown' : ( $cfg['persistent_connection'] ? 'enabled' : 'disabled' ); ?>.</p>
                    <p><strong>Direct Redis:</strong> <?php echo 'ok' === ( $redis['status'] ?? '' ) ? 'connected' : esc_html( (string) ( $redis['reason'] ?? 'unavailable' ) ); ?></p>
                    <p><strong>Test stage:</strong> <?php echo esc_html( strtoupper( (string) ( $redis['stage'] ?? 'unavailable' ) ) ); ?>.
                       <strong>Result category:</strong> <?php echo esc_html( (string) ( $redis['failure_kind'] ?? $redis['result_category'] ?? ( 'ok' === ( $redis['status'] ?? '' ) ? 'success' : 'not tested' ) ) ); ?>.
                       <strong>Probe key prefix:</strong> <?php echo esc_html( (string) ( $redis['key_prefix_source'] ?? 'unknown' ) ); ?>.
                       <strong>Write command:</strong> <?php echo esc_html( (string) ( $redis['set_command'] ?? 'not run' ) ); ?>.</p>
                    <?php if ( 'SET' === ( $redis['stage'] ?? '' ) ): ?><p class="description">WordPress cache SET may work even if this isolated Redis SET is refused. The test key's namespace is only a candidate and might not match Object Cache Pro's internal key layout. Do not broaden ACLs based on this test alone.</p><?php endif; ?>
                    <p><strong>Connection:</strong> <?php echo isset( $redis['connection_ms'] ) ? esc_html( (string) $redis['connection_ms'] ) . ' ms' : 'not measured'; ?>.
                       <strong>PING:</strong> <?php echo isset( $redis['ping_ms'] ) ? esc_html( (string) $redis['ping_ms'] ) . ' ms' : 'not measured'; ?>.</p>
                    <table class="widefat striped"><thead><tr><th>Layer</th><th>Operation</th><th>Samples</th><th>Median</th><th>p95</th><th>Maximum</th></tr></thead><tbody>
                    <?php foreach ( array( 'WordPress cache API' => $api, 'Direct Redis' => $redis ) as $layer => $series ):
                        foreach ( array( 'set', 'get', 'delete' ) as $op ):
                            $stat = (array) ( $series[ $op ] ?? array() ); ?>
                        <tr><td><?php echo esc_html( $layer ); ?></td><td><?php echo esc_html( strtoupper( $op ) ); ?></td><td><?php echo intval( $stat['n'] ?? 0 ); ?></td><td><?php echo isset( $stat['median_ms'] ) ? esc_html( (string) $stat['median_ms'] ) . ' ms' : 'n/a'; ?></td><td><?php echo isset( $stat['p95_ms'] ) ? esc_html( (string) $stat['p95_ms'] ) . ' ms' : 'n/a'; ?></td><td><?php echo isset( $stat['max_ms'] ) ? esc_html( (string) $stat['max_ms'] ) . ' ms' : 'n/a'; ?></td></tr>
                        <?php endforeach; endforeach; ?></tbody></table>
                    <p class="description">WordPress API timings include PHP and the drop-in and may hit an in-process cache. Direct Redis timings measure separate Redis commands. These are not total page-request timings; p95 from 12 samples is only a quick indicator, not a production latency percentile.</p>
                    <?php if ( ! empty( $api['failed'] ) || ! empty( $redis['failed'] ) ): ?><p class="pfc-critical">Verification failures: WordPress <?php echo intval( $api['failed'] ?? 0 ); ?>, direct Redis <?php echo intval( $redis['failed'] ?? 0 ); ?>.</p><?php endif; ?>
                    <?php if ( ! empty( $redis['server_counters'] ) ): ?><details><summary>Redis server counters (snapshot, not per-request costs)</summary><ul><?php foreach ( (array) $redis['server_counters'] as $name => $value ): ?><li><?php echo esc_html( $name ); ?>: <?php echo esc_html( (string) $value ); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
                </div>
                <div class="pfc-box"><h3>Autoloaded WordPress options</h3>
                    <p><?php echo esc_html( size_format( (int) ( $auto['bytes'] ?? 0 ) ) ); ?> in <?php echo intval( $auto['count'] ?? 0 ); ?> options. Read-only evidence: do not disable autoload without checking the plugin that owns the option.</p>
                    <table class="widefat striped"><thead><tr><th>Option</th><th>Size</th><th>Autoload flag</th></tr></thead><tbody>
                    <?php foreach ( (array) ( $auto['largest'] ?? array() ) as $option ): ?><tr><td><code><?php echo esc_html( (string) ( $option['option_name'] ?? '' ) ); ?></code></td><td><?php echo esc_html( size_format( (int) ( $option['bytes'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( (string) ( $option['autoload'] ?? '' ) ); ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                </div>
                <div class="pfc-box"><h3>Recommended next checks</h3><ul><?php foreach ( (array) ( $d['recommendations'] ?? array() ) as $advice ): ?><li><?php echo esc_html( (string) $advice ); ?></li><?php endforeach; ?></ul><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pfc&view=profiling#profiling' ) ); ?>">Profile a representative uncached page request</a> to examine the remaining 80–100 ms request budget.</p></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ( 'system' === $active_view ): ?>
        <section class="pfc-view" data-pfc-view="system" id="pfc-view-system">
        <div class="pfc-view-heading"><h2>System scan</h2><p>Inspect the complete database, plugin, cache, job, frontend and storage-engine snapshot from the latest scan.</p></div>
        <?php if ( $scan ): ?>
        <div class="pfc-actions"><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="pfc_export_ai"><input type="hidden" name="scope" value="scan"><?php wp_nonce_field( 'pfc_export_ai' ); ?><button class="button"><span class="dashicons dashicons-download" aria-hidden="true"></span> Export scan summary for AI (.json)</button></form></div>
        <p class="description">Exports a redacted summary of the cached scan, not raw queries, plugin settings or database contents.</p>
        <?php endif; ?>

        <?php if($scan): self::render_scan_details($scan); else: ?><div class="pfc-empty">Run a Production-Safe Scan to populate the full system view.</div><?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ( 'profiling' === $active_view ): ?>
        <section class="pfc-view" data-pfc-view="profiling">
        <h2>Recent Request Samples</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Time</th><th>Mode</th><th>Variant</th><th>Route</th><th>PHP</th><th>DB</th><th>Queries</th><th>HTTP</th><th>Memory</th><th>Phases / hooks</th></tr></thead><tbody>
        <?php foreach($runs as $r): $payload=json_decode($r->payload,true); ?><tr><td><?php echo (int)$r->id; ?></td><td><?php echo esc_html($r->created_at); ?></td><td><?php echo esc_html($r->mode); ?></td><td><?php echo esc_html('save'===$r->mode?'captured save':(!empty($r->excluded_plugin)?'without '.$r->excluded_plugin:'all plugins')); ?></td><td><code><?php echo esc_html($r->route); ?></code></td><td><?php echo esc_html($r->php_ms.' ms'); ?></td><td><?php echo esc_html($r->db_ms.' ms'); ?></td><td><?php echo (int)$r->query_count; ?></td><td><?php echo esc_html($r->http_ms.' ms / '.(int)$r->http_count); ?></td><td><?php echo esc_html(size_format((int)$r->memory_peak)); ?></td><td><small><?php echo esc_html(self::top_timings($payload)); ?></small></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2>Captured Slow / Repeated Queries</h2><table class="widefat striped"><thead><tr><th>Run</th><th>Component</th><th>Calls</th><th>Total / max</th><th>Query</th><th>EXPLAIN analysis</th></tr></thead><tbody>
        <?php if(!$queries): ?><tr><td colspan="6">Profile a route or capture a save to collect attributed query patterns.</td></tr><?php endif; ?>
        <?php foreach($queries as $q): $ex=json_decode($q->explain_json,true); $cand=$ex['analysis']['index_candidate']??array(); ?><tr><td>#<?php echo intval($q->run_id); ?><br><small><?php echo esc_html($q->created_at); ?></small></td><td><?php echo esc_html($q->component_type.':'.$q->component_slug); ?><br><small><?php echo esc_html($q->source_file); ?><?php echo $q->source_line?':'.intval($q->source_line):''; ?></small></td><td><?php echo intval($q->count); ?></td><td><?php echo esc_html($q->total_ms.' / '.$q->max_ms.' ms'); ?></td><td><code><?php echo esc_html(PFC_Utils::truncate($q->normalized_sql, 1000)); ?></code></td><td><small><?php echo esc_html(implode('; ',(array)($ex['analysis']['reasons']??array()))); ?></small><?php if(!empty($cand['sql'])): ?><br><strong>Review-only index candidate:</strong><br><code><?php echo esc_html($cand['sql']); ?></code><br><small><?php echo esc_html($cand['warning']??''); ?></small><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        </section>
        <?php endif; ?>

        <?php if ( 'monitoring' === $active_view ): ?>
        <section class="pfc-view" data-pfc-view="monitoring" id="pfc-view-monitoring">
        <div class="pfc-view-heading"><h2>Real-user monitoring</h2><p>Compare estimated p75 by route group. Only version 2 measurements are shown; legacy measurements are excluded. Each sampled visit reports once, at its first backgrounding, so later interactions are not included.</p></div>
        <h2>Real User Monitoring, Last 24 Hours</h2><div class="pfc-grid">
        <div class="pfc-box"><table class="widefat striped pfc-responsive-table"><thead><tr><th>Metric</th><th>Route group</th><th>Samples</th><th>p75 bucket upper bound</th><th>Average</th><th>Worst observed</th></tr></thead><tbody><?php if(!$rum): ?><tr><td colspan="6">No browser metrics arrived in the last 24 hours. Confirm the sample rate, page-cache deployment and REST accessibility.</td></tr><?php endif; ?><?php foreach($rum as $m): $unit='cls'===$m['metric']?'':' ms'; ?><tr><td data-label="Metric"><?php echo esc_html(strtoupper($m['metric'])); ?></td><td data-label="Route group"><code><?php echo esc_html($m['route_group']?:'legacy/unknown'); ?></code></td><td data-label="Samples"><?php echo intval($m['samples']); ?><?php if((int)$m['samples']<30): ?><br><small>Low confidence</small><?php endif; ?></td><td data-label="p75"><strong><?php echo esc_html( PFC_Utils::metric_percentile( $m, .75 ) . $unit ); ?></strong></td><td data-label="Average"><?php echo esc_html(round((float)$m['average_value'],2).$unit); ?></td><td data-label="Worst observed"><?php echo esc_html(round((float)$m['value_max'],2).$unit); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="pfc-box"><h3>Recent WordPress Changes</h3><?php if(!$changes): ?><p>No tracked updates/activation changes yet.</p><?php else: ?><ul><?php foreach(array_slice($changes,0,12) as $c): ?><li><strong><?php echo esc_html($c['change_type']); ?></strong> <?php echo esc_html($c['object_name']); ?> <small><?php echo esc_html($c['created_at']); ?></small><?php if(empty($c['reverted_at']) && in_array($c['change_type'],array('autoload','db_cleanup_orphans'),true)): ?> <form style="display:inline" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="pfc_db_fix"><input type="hidden" name="repair_action" value="rollback_change"><input type="hidden" name="change_id" value="<?php echo intval($c['id']); ?>"><?php wp_nonce_field('pfc_db_fix'); ?><button class="button-link">Undo</button></form><?php elseif(!empty($c['reverted_at'])): ?> <small>(reverted <?php echo esc_html($c['reverted_at']); ?>)</small><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?><?php if(!empty($scan['regression']['regressions'])): ?><p><strong>Detected regressions:</strong> <?php echo intval(count($scan['regression']['regressions'])); ?></p><?php endif; ?></div>
        </div>

        <h2>Monitoring Settings</h2><div class="pfc-box"><form class="pfc-form-stack" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="pfc_settings"><?php wp_nonce_field('pfc_settings'); ?>
        <div class="pfc-form-row"><label>Server sample rate (%)<input type="number" min="0" max="100" step="0.1" name="sample_percent" value="<?php echo esc_attr((float)$runtime['sample_rate']*100); ?>"></label><label>RUM sample rate (%)<input type="number" min="0" max="100" step="0.1" name="rum_percent" value="<?php echo esc_attr((float)$runtime['rum_rate']*100); ?>"></label><label>Retention (days)<input type="number" min="7" max="365" name="retention_days" value="<?php echo esc_attr((int)$runtime['retention_days']); ?>"></label></div>
        <label>Additional deep-scan routes, one URL per line<textarea name="route_urls" rows="5" placeholder="<?php echo esc_attr( home_url('/example-route/') ); ?>"><?php echo esc_textarea( implode( "\n", (array) ( $runtime['route_urls'] ?? array() ) ) ); ?></textarea></label><p class="description">Only URLs on the configured WordPress origin are saved. Deep scans test these routes in addition to representative public post types.</p><button class="button button-primary">Save monitoring settings</button></form>
        <p class="description">Keep passive server sampling low on high-traffic sites. Use signed profiles for full traces and paired experiments.</p></div>
        </section>
        <?php endif; ?>
        </div><?php
    }

    private static function top_timings( $payload ) {
        if ( ! is_array( $payload ) ) { return ''; }
        $items = array();
        foreach ( (array) ( $payload['hook_ms'] ?? array() ) as $name => $ms ) { if ( $ms >= 10 ) { $items[ 'hook:' . $name ] = (float) $ms; } }
        foreach ( (array) ( $payload['phases'] ?? array() ) as $name => $ms ) { if ( $ms >= 10 ) { $items[ 'phase:' . $name ] = (float) $ms; } }
        arsort( $items ); $text=array(); foreach(array_slice($items,0,4,true) as $name=>$ms){$text[]=$name.' '.round($ms).'ms';} return implode(', ',$text);
    }

    private static function render_scan_details( array $scan ) {
        $db = $scan['database_health']; $sys = $scan['system']; $jobs=$scan['jobs']; $cache=$scan['cache']; $front=$scan['frontend'];
        ?>
        <h2>Latest Full Scan</h2><div class="pfc-grid">
        <div class="pfc-box"><h3>Database</h3><p><strong><?php echo esc_html(size_format((int)$scan['database']['total_size'])); ?></strong> total; <?php echo intval(count($db['schema']['tables'])); ?> tables inspected.</p><ul><li>Server family: <?php echo esc_html(strtoupper((string)($db['server']['storage_engines']['family']??'unknown'))); ?></li><li>Table engines in use: <?php echo esc_html(implode(', ', array_map(static function($name,$count){return $name.' ('.$count.')';}, array_keys((array)$db['schema']['engines']), array_values((array)$db['schema']['engines'])))); ?></li><li>Missing core tables: <?php echo intval(count($db['schema']['missing_core_tables'])); ?></li><li>Missing core indexes: <?php echo intval(count($db['schema']['missing_core_indexes'])); ?></li><li>Missing core columns: <?php echo intval(count($db['schema']['missing_core_columns']??array())); ?></li><li>Core column definition drift: <?php echo intval(count($db['schema']['mismatched_core_columns']??array())); ?></li><li>Core index definition drift: <?php echo intval(count($db['schema']['mismatched_core_indexes']??array())); ?></li><li>Integrity problems: <?php echo intval(count($db['integrity']['problems']??array())); ?></li><li>Large tables without primary key: <?php echo intval(count($db['schema']['tables_without_primary_key'])); ?></li><li>Autoload: <?php echo esc_html(size_format((int)$db['options']['autoload_bytes'])); ?> / <?php echo intval($db['options']['autoload_count']); ?> options</li><li>Running DB processes visible: <?php echo intval($db['server']['processes']['running']??0); ?></li></ul></div>
        <div class="pfc-box"><h3>Plugins / PHP</h3><p><strong><?php echo intval($sys['plugins']['active_count']); ?></strong> active plugins.</p><ul><li>Paused by Recovery Mode: <?php echo intval(count($sys['plugins']['paused'])); ?></li><li>Recent error fingerprints: <?php echo intval(count($sys['errors']['findings'])); ?></li><li>Registered callbacks this admin request: <?php echo intval($sys['hooks']['total_callbacks']); ?></li><li>OPcache: <?php echo $sys['php']['opcache_configured']?'enabled':'disabled'; ?></li></ul></div>
        <div class="pfc-box"><h3>Jobs / Cache</h3><ul><li>Cron events: <?php echo intval($jobs['cron']['events']); ?></li><li>Overdue: <?php echo intval($jobs['cron']['overdue']); ?></li><li>Action Scheduler: <?php echo !empty($jobs['action_scheduler']['available'])?'detected':'not detected'; ?></li><li>Persistent object cache: <?php echo $cache['persistent']?'yes':'no'; ?></li><li>Same-request cache set/get (not a persistence test): <?php echo !empty($cache['roundtrip']['ok'])?'pass':'fail'; ?> (<?php echo esc_html($cache['roundtrip']['get_ms']); ?> ms GET)</li></ul></div>
        <div class="pfc-box"><h3>Frontend probes</h3><?php foreach($front['pages'] as $p): ?><p><code><?php echo esc_html($p['url']); ?></code><br><?php if(!empty($p['ok'])): ?><?php echo esc_html($p['total_ms']); ?> ms; <?php echo esc_html(size_format((int)$p['html_bytes'])); ?> HTML; <?php echo intval($p['assets']['total']); ?> resources; <?php echo intval($p['assets']['third_party']); ?> third-party<?php else: ?>FAILED: <?php echo esc_html($p['error']??$p['status']); ?><?php endif; ?></p><?php endforeach; ?></div>
        </div>

        <details><summary>Database table health</summary><div><table class="widefat striped"><thead><tr><th>Table</th><th>Rows est.</th><th>Engine</th><th>Collation</th><th>Data</th><th>Index</th><th>Free</th></tr></thead><tbody><?php foreach(array_slice($db['schema']['tables'],0,40) as $t): ?><tr><td><code><?php echo esc_html($t['name']); ?></code></td><td><?php echo esc_html(number_format_i18n($t['rows_estimate'])); ?></td><td><?php echo esc_html($t['engine']); ?></td><td><?php echo esc_html($t['collation']); ?></td><td><?php echo esc_html(size_format($t['data'])); ?></td><td><?php echo esc_html(size_format($t['index'])); ?></td><td><?php echo esc_html(size_format($t['data_free'])); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details><summary>Database runtime / integrity signals</summary><div><ul><li>Visible running processes: <?php echo intval($db['server']['processes']['running']??0); ?></li><li>Lock-related processes: <?php echo intval($db['server']['processes']['locked']??0); ?></li><li>Server slow-query counter: <?php echo esc_html(number_format_i18n((int)($db['server']['status']['Slow_queries']??0))); ?></li><li>Disk temporary tables: <?php echo esc_html(number_format_i18n((int)($db['server']['status']['Created_tmp_disk_tables']??0))); ?></li><li>Current InnoDB row lock waits: <?php echo intval($db['server']['status']['Innodb_row_lock_current_waits']??0); ?></li><?php if(!empty($db['server']['innodb']['available'])): ?><li>Recent InnoDB deadlock section: <?php echo !empty($db['server']['innodb']['deadlock_detected'])?'yes':'no'; ?></li><li>InnoDB history list length: <?php echo esc_html(number_format_i18n((int)($db['server']['innodb']['history_list_length']??0))); ?></li><?php endif; ?></ul><?php if(!empty($db['integrity']['checked'])): ?><p><strong>Deep CHECK TABLE results:</strong> <?php echo intval(count($db['integrity']['checked'])); ?> table(s) checked; <?php echo intval(count($db['integrity']['problems']??array())); ?> problem(s).</p><table class="widefat striped"><thead><tr><th>Table</th><th>Engine</th><th>Status</th><th>Check</th></tr></thead><tbody><?php foreach(array_slice($db['integrity']['checked'],0,75) as $check): ?><tr><td><code><?php echo esc_html($check['table']??''); ?></code></td><td><?php echo esc_html($check['engine']??''); ?></td><td><?php echo esc_html($check['status']??''); ?></td><td><?php echo esc_html($check['check']??''); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></details>
        <details><summary>InnoDB recovery / lock / configuration analysis</summary><div>
        <?php $inn=(array)($db['server']['innodb']??array()); $cfg=(array)($inn['configuration_advice']??array()); $ddl=(array)($inn['online_ddl']??array()); ?>
        <p><strong>Online DDL:</strong> <?php echo !empty($ddl['inplace'])?'INPLACE supported':'not verified'; ?>; <?php echo !empty($ddl['lock_none'])?'LOCK=NONE supported':'LOCK=NONE not verified'; ?>; <?php echo !empty($ddl['instant'])?'INSTANT supported':'INSTANT not assumed'; ?>. Performance Console fails closed rather than falling back to COPY.</p>
        <p><strong>InnoDB dataset:</strong> <?php echo esc_html(size_format((int)($cfg['dataset_bytes']??0))); ?>; <strong>buffer pool:</strong> <?php echo esc_html(size_format((int)($cfg['buffer_pool_bytes']??0))); ?><?php if(isset($cfg['buffer_pool_hit_ratio']) && null!==$cfg['buffer_pool_hit_ratio']): ?>; <strong>calculated hit rate:</strong> <?php echo esc_html(round(100*(float)$cfg['buffer_pool_hit_ratio'],3)); ?>%<?php endif; ?>.</p>
        <?php foreach((array)($cfg['recommendations']??array()) as $rec): ?><p class="pfc-<?php echo esc_attr($rec['severity']??'warning'); ?>"><strong><?php echo esc_html($rec['title']??''); ?>:</strong> <?php echo esc_html($rec['detail']??''); ?><br><small><?php echo esc_html($rec['recommendation']??''); ?></small></p><?php endforeach; ?>
        <?php if(!empty($inn['deadlock']['available'])): ?><h4>Latest parsed deadlock</h4><p>Tables: <code><?php echo esc_html(implode(', ',(array)($inn['deadlock']['tables']??array()))); ?></code></p><?php foreach((array)($inn['deadlock']['queries']??array()) as $dq): ?><p><code><?php echo esc_html($dq); ?></code></p><?php endforeach; ?><?php endif; ?>
        <?php if(!empty($inn['lock_graph']['edges'])): ?><h4>Active blocker/waiter graph</h4><table class="widefat striped"><thead><tr><th>Waiting trx</th><th>Blocking trx</th><th>Table / index</th><th>Waiting query</th><th>Blocking query</th></tr></thead><tbody><?php foreach(array_slice($inn['lock_graph']['edges'],0,25) as $edge): ?><tr><td><code><?php echo esc_html($edge['requesting_trx_id']??''); ?></code></td><td><code><?php echo esc_html($edge['blocking_trx_id']??''); ?></code></td><td><?php echo esc_html($edge['table']??''); ?><?php if(!empty($edge['index'])): ?><br><code><?php echo esc_html($edge['index']); ?></code><?php endif; ?></td><td><code><?php echo esc_html(PFC_Utils::truncate((string)($edge['requesting_query']??''),0,500)); ?></code></td><td><code><?php echo esc_html(PFC_Utils::truncate((string)($edge['blocking_query']??''),0,500)); ?></code></td></tr><?php endforeach; ?></tbody></table><?php elseif(!empty($inn['available'])): ?><p class="pfc-good">No active blocker/waiter edges were captured.</p><?php endif; ?>
        </div></details>
        <details><summary>Storage-engine capabilities</summary><div><p>Detected engine support is read from the live database server. Performance Console only exposes maintenance operations it recognizes as appropriate for that engine.</p><table class="widefat striped"><thead><tr><th>Engine</th><th>Server support</th><th>Transactions</th><th>Check</th><th>Repair</th><th>Optimize</th><th>Analyze</th><th>Notes</th></tr></thead><tbody><?php foreach((array)($db['server']['storage_engines']['engines']??array()) as $engine): if(empty($engine['available'])){continue;} $cap=(array)($engine['capabilities']??array()); ?><tr><td><strong><?php echo esc_html($engine['engine']); ?></strong><?php echo !empty($engine['default'])?' <small>(default)</small>':''; ?></td><td><?php echo esc_html($engine['support']); ?></td><td><?php echo esc_html($engine['transactions']); ?></td><td><?php echo !empty($cap['check'])?'yes':'no'; ?></td><td><?php echo !empty($cap['repair'])?'yes':'no'; ?></td><td><?php echo !empty($cap['optimize'])?'yes':'no'; ?></td><td><?php echo !empty($cap['analyze'])?'yes':'no'; ?></td><td><?php echo esc_html($cap['notes']??''); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details><summary>Plugin loading signals</summary><div><table class="widefat striped"><thead><tr><th>Plugin</th><th>Version</th><th>Included PHP files</th><th>Callbacks</th><th>Hooks</th><th>Autoload estimate</th><th>DB table estimate</th></tr></thead><tbody><?php foreach(array_slice(array_filter($sys['plugins']['plugins'],static function($p){return $p['active'];}),0,80) as $p): ?><tr><td><?php echo esc_html($p['name']); ?><br><small><code><?php echo esc_html($p['file']); ?></code></small></td><td><?php echo esc_html($p['version']); ?></td><td><?php echo intval($p['included_php_files_current_request']); ?></td><td><?php echo intval($p['registered_callbacks_current_request']); ?></td><td><?php echo intval($p['registered_hooks_current_request']); ?></td><td><?php echo esc_html(size_format((int)($p['autoload_bytes_estimate']??0))); ?></td><td><?php echo esc_html(size_format((int)($p['database_bytes_estimate']??0))); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details><summary>Recent PHP / WordPress / database error fingerprints</summary><div><table class="widefat striped"><thead><tr><th>Type</th><th>Component</th><th>Count</th><th>Fingerprint</th></tr></thead><tbody><?php foreach($sys['errors']['findings'] as $e): ?><tr><td><?php echo esc_html($e['type']); ?></td><td><?php echo esc_html($e['component']); ?></td><td><?php echo intval($e['count']); ?></td><td><code><?php echo esc_html($e['fingerprint']); ?></code></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details><summary>Largest autoloaded options</summary><div><table class="widefat striped"><thead><tr><th>Option</th><th>Size</th><th>Autoload</th></tr></thead><tbody><?php foreach(array_slice($db['options']['largest_autoload'],0,30) as $o): ?><tr><td><code><?php echo esc_html($o['option_name']); ?></code></td><td><?php echo esc_html(size_format((int)$o['bytes'])); ?></td><td><?php echo esc_html($o['autoload']); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <?php
    }
}
