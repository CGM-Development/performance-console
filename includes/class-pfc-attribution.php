<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Field-metric attribution storage.
 *
 * The RUM client sends a handful of derived scalars describing the element or
 * resource behind LCP and INP, plus long-task counts. This class is the only
 * thing that decides what reaches the database: unknown tags collapse to OTHER,
 * bytes are clamped, and any key the client invents is dropped.
 *
 * Cardinality is bounded by the tag whitelist and the existing route_group
 * ceiling, so a public endpoint cannot create unbounded series.
 */
final class PFC_Attribution {
    const MAX_BYTES = 10485760;
    const MAX_LONG_TASKS = 500;
    const MAX_LONG_TASK_MS = 600000;

    /** Mirrors the whitelist in assets/js/rum.js. Anything else becomes OTHER. */
    const TAGS = array(
        'IMG', 'PICTURE', 'VIDEO', 'H1', 'H2', 'H3', 'P', 'A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA',
        'DIV', 'SPAN', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'LI', 'UL', 'SVG', 'CANVAS', 'IFRAME',
        'FORM', 'TABLE', 'TD', 'BODY', 'MAIN', 'OTHER',
    );

    const METRICS = array( 'lcp_element', 'lcp_element_bytes', 'inp_target', 'long_task' );

    /**
     * Validate and clamp a client attribution payload.
     *
     * Pure apart from sanitize_text_field(). Returns only recognised keys; an
     * empty array means nothing worth storing.
     */
    public static function sanitize( array $input ) {
        $out = array();
        $tag = strtoupper( trim( sanitize_text_field( (string) ( $input['lcp_tag'] ?? '' ) ) ) );
        if ( in_array( $tag, self::TAGS, true ) ) { $out['lcp_tag'] = $tag; }

        if ( isset( $input['lcp_bytes'] ) && is_numeric( $input['lcp_bytes'] ) ) {
            $bytes = (int) $input['lcp_bytes'];
            if ( $bytes > 0 ) { $out['lcp_bytes'] = min( self::MAX_BYTES, $bytes ); }
        }
        if ( isset( $input['lcp_same_origin'] ) && is_numeric( $input['lcp_same_origin'] ) ) {
            $out['lcp_same_origin'] = (int) $input['lcp_same_origin'] ? 1 : 0;
        }

        $inp_tag = strtoupper( trim( sanitize_text_field( (string) ( $input['inp_tag'] ?? '' ) ) ) );
        if ( in_array( $inp_tag, self::TAGS, true ) ) { $out['inp_tag'] = $inp_tag; }

        if ( isset( $input['long_task_count'] ) && is_numeric( $input['long_task_count'] ) ) {
            $count = (int) $input['long_task_count'];
            if ( $count > 0 ) { $out['long_task_count'] = min( self::MAX_LONG_TASKS, $count ); }
        }
        if ( isset( $input['long_task_max'] ) && is_numeric( $input['long_task_max'] ) ) {
            $max = (int) $input['long_task_max'];
            if ( $max > 0 ) { $out['long_task_max'] = min( self::MAX_LONG_TASK_MS, $max ); }
        }
        if ( isset( $input['long_task_total'] ) && is_numeric( $input['long_task_total'] ) ) {
            $total = (int) $input['long_task_total'];
            if ( $total > 0 ) { $out['long_task_total'] = min( self::MAX_LONG_TASK_MS * self::MAX_LONG_TASKS, $total ); }
        }
        return $out;
    }

    /** One aggregated upsert. Returns 1 when the statement was accepted. */
    private static function bump( $metric, $label, $samples, $value ) {
        global $wpdb;
        $table = PFC_Utils::table( 'attribution' );
        $sql = "INSERT INTO {$table} (bucket,metric,route_group,label,samples,value_sum,value_max) VALUES (%s,%s,%s,%s,%d,%d,%d)
            ON DUPLICATE KEY UPDATE samples=samples+VALUES(samples),value_sum=value_sum+VALUES(value_sum),value_max=GREATEST(value_max,VALUES(value_max))";
        $ok = $wpdb->query( $wpdb->prepare( $sql, self::bucket(), $metric, self::$route_group, $label, $samples, $value, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return false === $ok ? 0 : 1;
    }

    private static $route_group = '';

    private static function bucket() { return gmdate( 'Y-m-d H:00:00' ); }

    /**
     * Persist one visit's attribution.
     *
     * @return int Number of series rows touched.
     */
    public static function store( $route_group, array $input ) {
        $route_group = sanitize_text_field( (string) $route_group );
        if ( ! preg_match( '/^[a-z0-9:_-]{1,80}$/', $route_group ) ) { $route_group = 'frontend:other'; }
        $data = self::sanitize( $input );
        if ( ! $data ) { return 0; }
        self::$route_group = $route_group;
        $rows = 0;
        if ( ! empty( $data['lcp_tag'] ) ) {
            $rows += self::bump( 'lcp_element', $data['lcp_tag'], 1, 0 );
            if ( ! empty( $data['lcp_bytes'] ) ) {
                $rows += self::bump( 'lcp_element_bytes', $data['lcp_tag'], 1, (int) $data['lcp_bytes'] );
            }
        }
        if ( ! empty( $data['inp_tag'] ) ) {
            $rows += self::bump( 'inp_target', $data['inp_tag'], 1, 0 );
        }
        if ( ! empty( $data['long_task_count'] ) ) {
            $rows += self::bump( 'long_task', '', (int) $data['long_task_count'], (int) ( $data['long_task_max'] ?? $data['long_task_total'] ?? 0 ) );
        }
        return $rows;
    }

    /**
     * Summarise attribution for the dashboard.
     *
     * Percentages are of the same window's attributed samples, so they answer
     * "of the visits we could attribute, what share had an IMG as the LCP
     * element" rather than pretending to describe all traffic.
     */
    public static function report( $days = 7 ) {
        global $wpdb;
        $days = max( 1, min( 30, (int) $days ) );
        $table = PFC_Utils::table( 'attribution' );
        $from = gmdate( 'Y-m-d H:00:00', time() - $days * DAY_IN_SECONDS );
        $report = array(
            'window_days'  => $days,
            'available'    => false,
            'lcp_elements' => array(),
            'inp_targets'  => array(),
            'long_tasks'   => array( 'events' => 0, 'max_ms' => 0, 'average_ms' => 0.0 ),
            'samples'      => 0,
        );

        $lcp = $wpdb->get_results( $wpdb->prepare( "SELECT label,SUM(samples) samples FROM {$table} WHERE metric=%s AND bucket>=%s GROUP BY label ORDER BY samples DESC LIMIT 10", 'lcp_element', $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $bytes = $wpdb->get_results( $wpdb->prepare( "SELECT label,SUM(samples) samples,SUM(value_sum) bytes_sum,MAX(value_max) bytes_max FROM {$table} WHERE metric=%s AND bucket>=%s GROUP BY label", 'lcp_element_bytes', $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $byte_map = array();
        foreach ( (array) $bytes as $row ) { $byte_map[ (string) $row['label'] ] = $row; }

        $total = 0;
        foreach ( (array) $lcp as $row ) { $total += (int) $row['samples']; }
        $report['samples'] = $total;
        foreach ( (array) $lcp as $row ) {
            $label = (string) $row['label'];
            $samples = (int) $row['samples'];
            $entry = array(
                'tag'     => $label,
                'samples' => $samples,
                'share'   => $total > 0 ? round( 100 * $samples / $total, 1 ) : 0.0,
                'avg_kb'  => null,
                'max_kb'  => null,
            );
            if ( isset( $byte_map[ $label ] ) && (int) $byte_map[ $label ]['samples'] > 0 ) {
                $entry['avg_kb'] = round( ( (int) $byte_map[ $label ]['bytes_sum'] / 1024 ) / max( 1, (int) $byte_map[ $label ]['samples'] ), 1 );
                $entry['max_kb'] = round( (int) $byte_map[ $label ]['bytes_max'] / 1024, 1 );
            }
            $report['lcp_elements'][] = $entry;
        }

        $inp = $wpdb->get_results( $wpdb->prepare( "SELECT label,SUM(samples) samples FROM {$table} WHERE metric=%s AND bucket>=%s GROUP BY label ORDER BY samples DESC LIMIT 10", 'inp_target', $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $inp_total = 0;
        foreach ( (array) $inp as $row ) { $inp_total += (int) $row['samples']; }
        foreach ( (array) $inp as $row ) {
            $report['inp_targets'][] = array(
                'tag'     => (string) $row['label'],
                'samples' => (int) $row['samples'],
                'share'   => $inp_total > 0 ? round( 100 * (int) $row['samples'] / $inp_total, 1 ) : 0.0,
            );
        }

        $long = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(samples) events,MAX(value_max) max_ms,SUM(value_sum) total_ms FROM {$table} WHERE metric=%s AND bucket>=%s", 'long_task', $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $long && (int) ( $long['events'] ?? 0 ) > 0 ) {
            $events = (int) $long['events'];
            $report['long_tasks'] = array(
                'events'     => $events,
                'max_ms'     => (int) ( $long['max_ms'] ?? 0 ),
                'average_ms' => $events > 0 ? round( (float) $long['total_ms'] / $events, 1 ) : 0.0,
            );
        }

        $report['available'] = $total > 0 || ! empty( $report['inp_targets'] );
        return $report;
    }
}
