<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The Optimize view.
 *
 * One screen that answers "what should I change, what did changing it do, and
 * how do I undo it". Read-only panels are cached briefly; every mutation goes
 * through admin-post with a nonce, a capability check and an explicit confirm
 * checkbox, and is recorded in the changes table so the impact ledger can judge
 * it afterwards.
 */
final class PFC_Optimize {
    const NONCE = 'pfc_optimize';
    const ACTION = 'pfc_optimize';
    const PANEL_CACHE = 300;

    public static function init() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }

    /** Dispatch one Optimize action and bounce back to the view. */
    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'performance-console' ) ); }
        check_admin_referer( self::NONCE );
        $action = sanitize_key( wp_unslash( $_POST['pfc_optimize_action'] ?? '' ) );
        $confirmed = isset( $_POST['pfc_confirm'] ) && '1' === (string) wp_unslash( $_POST['pfc_confirm'] );
        $notice = array( 'type' => 'error', 'text' => 'Unknown action.' );

        switch ( $action ) {
            case 'save_budgets':
                $input = array();
                foreach ( array( 'ttfb', 'lcp', 'inp', 'cls', 'server_php_ms', 'queries' ) as $key ) {
                    if ( isset( $_POST[ 'pfc_' . $key ] ) ) { $input[ $key ] = wp_unslash( $_POST[ 'pfc_' . $key ] ); }
                }
                $thresholds = PFC_Budget::save( $input );
                $notice = array( 'type' => 'success', 'text' => 'Budgets saved: LCP ' . PFC_Budget::format_value( 'lcp', $thresholds['lcp'] ) . ', TTFB ' . PFC_Budget::format_value( 'ttfb', $thresholds['ttfb'] ) . '.' );
                break;

            case 'audit_assets':
                $result = PFC_Asset_Ledger::inspect( (string) wp_unslash( $_POST['pfc_url'] ?? '' ) );
                $notice = is_wp_error( $result )
                    ? array( 'type' => 'error', 'text' => $result->get_error_message() )
                    : array( 'type' => 'success', 'text' => count( $result['findings'] ) . ' finding(s) from the audited page.' );
                break;

            case 'create_index':
                $columns = array_filter( array_map( 'trim', explode( ',', (string) wp_unslash( $_POST['pfc_columns'] ?? '' ) ) ) );
                $args = array(
                    'backup_confirmed' => $confirmed,
                    'force_large'      => isset( $_POST['pfc_force_large'] ) && '1' === (string) wp_unslash( $_POST['pfc_force_large'] ),
                );
                $notice = self::outcome( PFC_Index_Repair::create( (string) wp_unslash( $_POST['pfc_table'] ?? '' ), $columns, $args ) );
                break;

            case 'undo_index':
                $notice = self::outcome( PFC_Index_Repair::undo( absint( $_POST['pfc_change_id'] ?? 0 ) ) );
                break;

            case 'save_levers':
                $state = array(
                    'heartbeat' => array(
                        'enabled'  => isset( $_POST['pfc_heartbeat'] ) ? wp_unslash( $_POST['pfc_heartbeat'] ) : '0',
                        'interval' => wp_unslash( $_POST['pfc_heartbeat_interval'] ?? 60 ),
                    ),
                    'revisions' => array(
                        'enabled' => isset( $_POST['pfc_revisions'] ) ? wp_unslash( $_POST['pfc_revisions'] ) : '0',
                        'keep'    => wp_unslash( $_POST['pfc_revisions_keep'] ?? 5 ),
                    ),
                );
                $notice = self::outcome( PFC_Runtime_Levers::save( $state, $confirmed ) );
                break;

            case 'dedupe_cron':
                $notice = self::outcome( PFC_Cron_Repair::dedupe( (string) wp_unslash( $_POST['pfc_hook'] ?? '' ), $confirmed ) );
                break;

            case 'autoload_off':
                $names = array_filter( array_map( 'trim', explode( ',', (string) wp_unslash( $_POST['pfc_options'] ?? '' ) ) ) );
                $notice = self::outcome( PFC_Autoload_Budget::disable( $names, $confirmed ) );
                break;

            case 'undo_levers':
                $notice = self::outcome( PFC_Runtime_Levers::undo( absint( $_POST['pfc_change_id'] ?? 0 ) ) );
                break;

            case 'undo_autoload':
                $notice = self::outcome( PFC_Autoload_Budget::undo( absint( $_POST['pfc_change_id'] ?? 0 ) ) );
                break;
        }

        self::clear_cache();
        set_transient( 'pfc_optimize_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=pfc&view=optimize' ) );
        exit;
    }

    private static function outcome( $result ) {
        if ( is_wp_error( $result ) ) { return array( 'type' => 'error', 'text' => $result->get_error_message() ); }
        return array(
            'type' => ! empty( $result['ok'] ) ? 'success' : 'error',
            'text' => (string) ( $result['message'] ?? 'Completed.' ),
        );
    }

    private static function cached( $key, callable $callback, $ttl = self::PANEL_CACHE ) {
        $cache_key = 'pfc_opt_panel_' . get_current_user_id() . '_' . sanitize_key( $key );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }
        $value = call_user_func( $callback );
        if ( is_array( $value ) ) { set_transient( $cache_key, $value, $ttl ); }
        return $value;
    }

    private static function clear_cache() {
        foreach ( array( 'budget', 'impact', 'attribution', 'assets', 'cacheability', 'boot', 'candidates', 'usage', 'autoload' ) as $key ) {
            delete_transient( 'pfc_opt_panel_' . get_current_user_id() . '_' . $key );
        }
    }

    private static function form_open( $action ) {
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="pfc-opt-form">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="pfc_optimize_action" value="' . esc_attr( $action ) . '">';
        wp_nonce_field( self::NONCE );
    }

    private static function confirm_box( $label ) {
        echo '<label class="pfc-opt-confirm"><input type="checkbox" name="pfc_confirm" value="1" required> ' . esc_html( $label ) . '</label> ';
    }

    private static function status_badge( $ok, $text ) {
        $class = $ok ? 'pfc-status--good' : 'pfc-status--warning';
        return '<span class="pfc-status ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
    }

    public static function render() {
        $notice = get_transient( 'pfc_optimize_notice_' . get_current_user_id() );
        if ( false !== $notice ) { delete_transient( 'pfc_optimize_notice_' . get_current_user_id() ); }

        echo '<section class="pfc-view" data-pfc-view="optimize" id="pfc-view-optimize">';
        echo '<div class="pfc-view-heading"><h2>Optimize</h2><p>Measured recommendations, guarded fixes and the evidence that a change helped. Every action here is recorded and reversible.</p></div>';

        if ( is_array( $notice ) ) {
            echo '<div class="notice notice-' . esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $notice['text'] ) . '</p></div>';
        }

        self::render_budgets();
        self::render_impact();
        self::render_attribution();
        self::render_assets();
        self::render_cacheability();
        self::render_boot();
        self::render_remediation();

        echo '</section>';
    }

    private static function restart_form( $action, array $hidden = array(), $label ) {
        self::form_open( $action );
        foreach ( $hidden as $name => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
        }
        echo '<button class="button">' . esc_html( $label ) . '</button></form>';
    }

    private static function render_budgets() {
        $report = self::cached( 'budget', static function () { return PFC_Budget::report( 1, '', array(), false ); } );
        $thresholds = (array) ( $report['thresholds'] ?? PFC_Budget::DEFAULTS );
        echo '<div class="pfc-card"><h3>Performance budgets</h3>';
        echo '<p>Budgets turn the diagnostics into a pass/fail contract so a regression can be stopped in CI with <code>wp performance budget</code>.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Budget</th><th>Observed</th><th>Limit</th><th>Samples</th><th>Status</th></tr></thead><tbody>';
        foreach ( (array) ( $report['checks'] ?? array() ) as $check ) {
            echo '<tr><td>' . esc_html( $check['label'] ) . '</td><td>' . esc_html( PFC_Budget::format_value( $check['key'], $check['value'] ) ) . '</td><td>' . esc_html( PFC_Budget::format_value( $check['key'], $check['limit'] ) ) . '</td><td>' . esc_html( (string) $check['samples'] ) . '</td><td>' . self::status_badge( 'fail' !== $check['status'] && 'no_data' !== $check['status'], strtoupper( (string) $check['status'] ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::form_open( 'save_budgets' );
        echo '<p class="pfc-opt-grid">';
        foreach ( array( 'ttfb' => 'TTFB p75 (ms)', 'lcp' => 'LCP p75 (ms)', 'inp' => 'INP p75 (ms)', 'cls' => 'CLS p75', 'server_php_ms' => 'Server PHP median (ms)', 'queries' => 'Queries p75' ) as $key => $label ) {
            echo '<label>' . esc_html( $label ) . '<input type="number" step="0.001" min="0" name="pfc_' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $thresholds[ $key ] ?? 0 ) ) . '"></label>';
        }
        echo '</p><button class="button button-primary">Save budgets</button></form></div>';
    }

    private static function render_impact() {
        $records = self::cached( 'impact', static function () { return PFC_Change_Impact::recent( 8 ); }, 120 );
        echo '<div class="pfc-card"><h3>Change impact</h3>';
        echo '<p>Traffic before and after each recorded change. A verdict needs a full settled window and a shift beyond the window&rsquo;s own spread, so an empty list is normal on a quiet site.</p>';
        if ( empty( $records ) ) {
            echo '<p class="pfc-muted">No changes have been recorded yet.</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Change</th><th>When (UTC)</th><th>Verdict</th><th>PHP median</th><th>TTFB p75</th><th>LCP p75</th></tr></thead><tbody>';
        foreach ( $records as $record ) {
            $change = (array) $record['change'];
            echo '<tr><td>' . esc_html( (string) $change['change_type'] . ' ' . (string) $change['object_name'] ) . '</td><td>' . esc_html( (string) $change['created_at'] ) . '</td><td>' . esc_html( (string) $record['verdict'] ) . '</td>';
            foreach ( array( 'server_php_ms', 'ttfb', 'lcp' ) as $metric ) {
                $comparison = (array) ( $record['metrics'][ $metric ] ?? array() );
                $verdict = (string) ( $comparison['verdict'] ?? '' );
                if ( '' === $verdict || 'insufficient' === $verdict ) { echo '<td>&mdash;</td>'; continue; }
                echo '<td>' . esc_html( sprintf( '%+.1f%%', (float) $comparison['percent'] ) ) . ' <span class="pfc-muted">' . esc_html( $verdict ) . '</span></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_attribution() {
        $report = self::cached( 'attribution', static function () { return PFC_Attribution::report( 7 ); } );
        echo '<div class="pfc-card"><h3>Field attribution</h3>';
        if ( empty( $report['available'] ) ) {
            echo '<p class="pfc-muted">No attributed RUM samples yet. Attribution is collected from sampled real visits, so it appears after a little traffic.</p></div>';
            return;
        }
        echo '<p>Of ' . esc_html( number_format_i18n( (int) $report['samples'] ) ) . ' attributed visits in the last ' . esc_html( (string) $report['window_days'] ) . ' days:</p>';
        echo '<table class="widefat striped"><thead><tr><th>LCP element</th><th>Share</th><th>Average bytes</th><th>Largest</th></tr></thead><tbody>';
        foreach ( (array) $report['lcp_elements'] as $element ) {
            echo '<tr><td>' . esc_html( (string) $element['tag'] ) . '</td><td>' . esc_html( (string) $element['share'] ) . '%</td><td>' . esc_html( null === $element['avg_kb'] ? '—' : number_format_i18n( (float) $element['avg_kb'], 1 ) . ' KB' ) . '</td><td>' . esc_html( null === $element['max_kb'] ? '—' : number_format_i18n( (float) $element['max_kb'], 0 ) . ' KB' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( ! empty( $report['long_tasks']['events'] ) ) {
            echo '<p class="pfc-muted">Long tasks: ' . esc_html( number_format_i18n( (int) $report['long_tasks']['events'] ) ) . ' observed, worst ' . esc_html( number_format_i18n( (int) $report['long_tasks']['max_ms'] ) ) . ' ms, average ' . esc_html( number_format_i18n( (float) $report['long_tasks']['average_ms'], 0 ) ) . ' ms. A high worst value points at main-thread work, not at an event handler.</p>';
        }
        echo '</div>';
    }

    private static function render_assets() {
        $report = get_transient( 'pfc_asset_ledger_' . get_current_user_id() );
        echo '<div class="pfc-card"><h3>Asset and priority hints</h3>';
        echo '<p>Audits the HTML a visitor receives for render-blocking resources, the LCP image&rsquo;s priority hints, unprepared origins and unreserved image space.</p>';
        if ( ! is_array( $report ) ) {
            self::form_open( 'audit_assets' );
            echo '<p class="pfc-opt-grid"><label>Route to audit<input type="url" name="pfc_url" value="' . esc_attr( home_url( '/' ) ) . '"></label></p>';
            echo '<button class="button button-primary">Audit this page</button></form>';
            echo '</div>';
            return;
        }
        echo '<p><strong>' . esc_html( (string) $report['url'] ) . '</strong> &middot; ' . esc_html( size_format( (int) $report['html_bytes'] ) ) . ' of HTML &middot; ' . esc_html( (string) $report['resources']['stylesheets'] ) . ' stylesheets, ' . esc_html( (string) $report['resources']['scripts'] ) . ' scripts, ' . esc_html( (string) $report['resources']['images'] ) . ' images</p>';
        if ( ! empty( $report['warning'] ) ) { echo '<p class="pfc-muted">' . esc_html( (string) $report['warning'] ) . '</p>'; }
        if ( empty( $report['findings'] ) ) {
            echo '<p>' . self::status_badge( true, 'No asset findings' ) . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Severity</th><th>Finding</th><th>Why it costs time</th><th>Evidence</th></tr></thead><tbody>';
            foreach ( $report['findings'] as $finding ) {
                echo '<tr><td>' . esc_html( strtoupper( (string) $finding['severity'] ) ) . '</td><td><strong>' . esc_html( (string) $finding['title'] ) . '</strong></td><td>' . esc_html( (string) $finding['detail'] ) . '</td><td>' . esc_html( (string) $finding['evidence'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="pfc-muted">Ordered by a heuristic estimate, not a measurement. The evidence column is what the page actually contains.</p>';
        }
        self::restart_form( 'audit_assets', array( 'pfc_url' => (string) $report['url'] ), 'Audit again' );
        echo '</div>';
    }

    private static function render_cacheability() {
        $report = self::cached( 'cacheability', static function () { return PFC_Cacheability::analyze( 7, 20 ); } );
        echo '<div class="pfc-card"><h3>Cacheability advisor</h3>';
        echo '<p>Repeated reads ranked by what caching would plausibly save. Reads that depend on time, locks or the current user are excluded.</p>';
        if ( empty( $report['rows'] ) ) {
            echo '<p class="pfc-muted">No repeated query patterns in the last week. Run a deep profile first.</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Cacheable</th><th>Calls</th><th>Total ms</th><th>Est. saved</th><th>Component</th><th>Reason</th></tr></thead><tbody>';
        foreach ( $report['rows'] as $row ) {
            echo '<tr><td>' . ( ! empty( $row['cacheable'] ) ? self::status_badge( true, 'yes' ) : self::status_badge( false, 'no' ) ) . '</td><td>' . esc_html( (string) $row['calls'] ) . '</td><td>' . esc_html( (string) $row['total_ms'] ) . '</td><td>' . esc_html( (string) $row['saved_ms'] ) . '</td><td>' . esc_html( (string) $row['component'] ) . '</td><td>' . esc_html( (string) $row['reason'] ) . '</td></tr>';
        }
        echo '</tbody></table><p class="pfc-muted">Upper bound: it assumes one execution per request is unavoidable and every repeat is removable.</p></div>';
    }

    private static function render_boot() {
        $report = self::cached( 'boot', static function () { return PFC_Bootstrap_Timeline::report(); }, 600 );
        echo '<div class="pfc-card"><h3>Bootstrap waterfall</h3>';
        if ( empty( $report['waterfall']['steps'] ) ) {
            echo '<p class="pfc-muted">No deep profile with phase timings yet. Use Cache &amp; Redis to run a signed deep profile.</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Phase</th><th>Measured ms</th><th>Share</th></tr></thead><tbody>';
        foreach ( $report['waterfall']['steps'] as $step ) {
            echo '<tr><td>' . esc_html( (string) $step['label'] ) . '</td><td>' . esc_html( number_format_i18n( (float) $step['ms'], 2 ) ) . '</td><td>' . esc_html( (string) $step['share'] ) . '%</td></tr>';
        }
        echo '</tbody></table><p class="pfc-muted">Measured total ' . esc_html( number_format_i18n( (float) $report['waterfall']['total_ms'], 2 ) ) . ' ms from ' . esc_html( (string) $report['measurement'] ) . '.</p>';
        if ( ! empty( $report['footprint']['components'] ) ) {
            echo '<h4>Static include footprint</h4><table class="widefat striped"><thead><tr><th>Component</th><th>Type</th><th>Files</th><th>Bytes</th></tr></thead><tbody>';
            foreach ( array_slice( $report['footprint']['components'], 0, 12 ) as $component ) {
                echo '<tr><td>' . esc_html( (string) $component['slug'] ) . '</td><td>' . esc_html( (string) $component['type'] ) . '</td><td>' . esc_html( (string) $component['files'] ) . '</td><td>' . esc_html( size_format( (int) $component['bytes'] ) ) . '</td></tr>';
            }
            echo '</tbody></table><p class="pfc-muted">Files and bytes, not duration: PHP cannot observe another plugin&rsquo;s include time.</p>';
        }
        echo '</div>';
    }

    private static function render_remediation() {
        echo '<div class="pfc-card"><h3>Guarded fixes</h3><p>Each action requires confirmation, is recorded, and can be reverted from the change ledger.</p>';

        $candidates = self::cached( 'candidates', static function () { return PFC_Index_Repair::candidates( 8 ); }, 600 );
        echo '<h4>Index candidates</h4>';
        if ( empty( $candidates ) ) {
            echo '<p class="pfc-muted">No single-table index candidate was generated by the last deep profile.</p>';
        } else {
            foreach ( $candidates as $candidate ) {
                echo '<div class="pfc-opt-row"><code>' . esc_html( (string) $candidate['sql'] ) . '</code> ';
                self::form_open( 'create_index' );
                echo '<input type="hidden" name="pfc_table" value="' . esc_attr( (string) $candidate['table'] ) . '"><input type="hidden" name="pfc_columns" value="' . esc_attr( implode( ',', (array) $candidate['columns'] ) ) . '">';
                self::confirm_box( 'I have a verified backup' );
                echo '<button class="button">Create index</button></form></div>';
            }
        }

        $usage = self::cached( 'usage', static function () { return PFC_Index_Repair::usage( 15 ); }, 600 );
        echo '<h4>Never-used indexes</h4>';
        if ( empty( $usage['available'] ) ) {
            echo '<p class="pfc-muted">' . esc_html( (string) ( $usage['message'] ?? 'Index usage is unavailable on this server.' ) ) . '</p>';
        } elseif ( empty( $usage['rows'] ) ) {
            echo '<p class="pfc-muted">Every index has been used since the server started.</p>';
        } else {
            if ( ! empty( $usage['warning'] ) ) { echo '<p class="pfc-muted">' . esc_html( (string) $usage['warning'] ) . '</p>'; }
            echo '<table class="widefat striped"><thead><tr><th>Table</th><th>Index</th></tr></thead><tbody>';
            foreach ( array_slice( $usage['rows'], 0, 15 ) as $row ) {
                echo '<tr><td>' . esc_html( (string) $row['table_name'] ) . '</td><td>' . esc_html( (string) $row['index_name'] ) . '</td></tr>';
            }
            echo '</tbody></table><p class="pfc-muted">Evidence only. Dropping an index can change a query plan or break a foreign key, so this view never drops one.</p>';
        }

        $cron = PFC_Cron_Repair::snapshot();
        echo '<h4>WP-Cron duplicates</h4>';
        if ( empty( $cron['available'] ) ) {
            echo '<p class="pfc-muted">The cron array is not available in this context.</p>';
        } elseif ( (int) $cron['duplicate_hooks'] < 1 ) {
            echo '<p class="pfc-muted">' . esc_html( (string) $cron['total_events'] ) . ' scheduled events, no hook scheduled more than once.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Hook</th><th>Events</th><th>Timestamps</th><th>Shortest gap</th><th></th></tr></thead><tbody>';
            foreach ( $cron['hooks'] as $row ) {
                if ( empty( $row['multiple_timestamps'] ) || ! empty( $row['protected'] ) ) { continue; }
                echo '<tr><td>' . esc_html( (string) $row['hook'] ) . '</td><td>' . esc_html( (string) $row['events'] ) . '</td><td>' . esc_html( (string) $row['timestamps'] ) . '</td><td>' . esc_html( (int) $row['min_gap'] > 0 ? number_format_i18n( (int) $row['min_gap'] ) . ' s' : '—' ) . '</td><td>';
                self::form_open( 'dedupe_cron' );
                echo '<input type="hidden" name="pfc_hook" value="' . esc_attr( (string) $row['hook'] ) . '">';
                self::confirm_box( 'Confirm' );
                echo '<button class="button">Collapse duplicates</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }

        $autoload = self::cached( 'autoload', static function () { return array( 'summary' => PFC_Autoload_Budget::summary(), 'offenders' => PFC_Autoload_Budget::offenders( 10 ) ); }, 300 );
        $summary = (array) $autoload['summary'];
        echo '<h4>Autoload budget</h4>';
        echo '<p>' . esc_html( number_format_i18n( (int) $summary['count'] ) ) . ' autoloaded options using ' . esc_html( size_format( (int) $summary['bytes'] ) ) . ' of a ' . esc_html( size_format( (int) $summary['budget_bytes'] ) ) . ' budget. ' . self::status_badge( empty( $summary['over_bytes'] ) && empty( $summary['over_count'] ), empty( $summary['over_bytes'] ) && empty( $summary['over_count'] ) ? 'within budget' : 'over budget' ) . '</p>';
        if ( ! empty( $autoload['offenders'] ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Option</th><th>Bytes</th><th>Reads observed</th><th>Protected</th><th></th></tr></thead><tbody>';
            foreach ( $autoload['offenders'] as $option ) {
                echo '<tr><td><code>' . esc_html( (string) $option['option_name'] ) . '</code></td><td>' . esc_html( size_format( (int) $option['bytes'] ) ) . '</td><td>' . esc_html( (int) $option['hits'] > 0 ? number_format_i18n( (int) $option['hits'] ) : 'no evidence' ) . '</td><td>' . ( ! empty( $option['protected'] ) ? 'yes' : 'no' ) . '</td><td>';
                if ( empty( $option['protected'] ) ) {
                    self::form_open( 'autoload_off' );
                    echo '<input type="hidden" name="pfc_options" value="' . esc_attr( (string) $option['option_name'] ) . '">';
                    self::confirm_box( 'Confirm' );
                    echo '<button class="button">Stop autoloading</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        $levers = PFC_Runtime_Levers::state();
        echo '<h4>Runtime levers</h4><p class="pfc-muted">' . esc_html( implode( ' ', PFC_Runtime_Levers::describe( $levers ) ) ) . '</p>';
        self::form_open( 'save_levers' );
        echo '<p class="pfc-opt-grid">';
        echo '<label><input type="checkbox" name="pfc_heartbeat" value="1"' . checked( ! empty( $levers['heartbeat']['enabled'] ), true, false ) . '> Throttle Heartbeat</label>';
        echo '<label>Interval (s)<input type="number" min="' . esc_attr( (string) PFC_Runtime_Levers::HEARTBEAT_MIN ) . '" max="' . esc_attr( (string) PFC_Runtime_Levers::HEARTBEAT_MAX ) . '" name="pfc_heartbeat_interval" value="' . esc_attr( (string) $levers['heartbeat']['interval'] ) . '"></label>';
        echo '<label><input type="checkbox" name="pfc_revisions" value="1"' . checked( ! empty( $levers['revisions']['enabled'] ), true, false ) . '> Cap revisions</label>';
        echo '<label>Revisions to keep<input type="number" min="' . esc_attr( (string) PFC_Runtime_Levers::REVISIONS_MIN ) . '" max="' . esc_attr( (string) PFC_Runtime_Levers::REVISIONS_MAX ) . '" name="pfc_revisions_keep" value="' . esc_attr( (string) $levers['revisions']['keep'] ) . '"></label>';
        echo '</p>';
        self::confirm_box( 'I understand these change editor behaviour' );
        echo '<button class="button button-primary">Save levers</button></form>';

        echo '<h4>Recent changes and undo</h4>';
        global $wpdb;
        $changes = $wpdb->get_results( 'SELECT id,created_at,change_type,object_name,reverted_at FROM ' . PFC_Utils::table( 'changes' ) . ' ORDER BY id DESC LIMIT 12', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( empty( $changes ) ) {
            echo '<p class="pfc-muted">Nothing recorded yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>When</th><th>Change</th><th>Object</th><th></th></tr></thead><tbody>';
            foreach ( $changes as $change ) {
                $undo_action = '';
                if ( 'index_created' === (string) $change['change_type'] ) { $undo_action = 'undo_index'; }
                elseif ( 'autoload_bulk_off' === (string) $change['change_type'] ) { $undo_action = 'undo_autoload'; }
                elseif ( 'runtime_levers' === (string) $change['change_type'] ) { $undo_action = 'undo_levers'; }
                echo '<tr><td>' . esc_html( (string) $change['id'] ) . '</td><td>' . esc_html( (string) $change['created_at'] ) . '</td><td>' . esc_html( (string) $change['change_type'] ) . '</td><td>' . esc_html( (string) $change['object_name'] ) . '</td><td>';
                if ( $undo_action && empty( $change['reverted_at'] ) ) {
                    self::form_open( $undo_action );
                    echo '<input type="hidden" name="pfc_change_id" value="' . esc_attr( (string) $change['id'] ) . '">';
                    echo '<button class="button">Undo</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
