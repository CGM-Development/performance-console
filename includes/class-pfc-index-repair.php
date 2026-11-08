<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Guarded index creation.
 *
 * The profiler already generates review-only index candidates and stops there.
 * This class carries the candidate through the same gate pattern the database
 * Repair Centre uses: validate identifiers, probe the schema, size-gate the
 * table, require a verified backup, execute online DDL, verify the result, and
 * record an undo that drops exactly the index it created.
 *
 * It never creates a unique index, never changes a column type, and never drops
 * an index it did not create.
 */
final class PFC_Index_Repair {
    const MAX_COLUMNS = 3;
    const MAX_TABLE_BYTES = 2147483648; // 2 GB: above this, require --force-large.
    const CHANGE_TYPE = 'index_created';

    /** Identifiers are interpolated into DDL, so the allow-list is the boundary. */
    public static function valid_name( $name ) {
        return 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/', (string) $name );
    }

    public static function index_name( $table, array $columns ) {
        $key = strtolower( (string) $table ) . ':' . strtolower( implode( ',', $columns ) );
        return 'pfc_opt_' . substr( md5( $key ), 0, 10 );
    }

    /** Validate, de-duplicate and cap the column list. */
    public static function normalize_columns( array $columns ) {
        $out = array();
        foreach ( $columns as $column ) {
            $column = trim( (string) $column );
            if ( '' === $column || ! self::valid_name( $column ) ) { return array(); }
            if ( ! in_array( strtolower( $column ), array_map( 'strtolower', $out ), true ) ) { $out[] = $column; }
            if ( count( $out ) > self::MAX_COLUMNS ) { return array(); }
        }
        return $out;
    }

    /** The current server's DDL support, reported rather than assumed. */
    public static function ddl_support() {
        global $wpdb;
        $version = (string) $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $mariadb = false !== stripos( $version, 'mariadb' );
        $major = 0;
        $minor = 0;
        if ( preg_match( '/(\d+)\.(\d+)/', $version, $matches ) ) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];
        }
        // MariaDB reports 5.5.5-prefixed versions through $wpdb->db_version(),
        // which is why this asks the server directly.
        $supports = $mariadb ? ( $major > 10 || ( 10 === $major && $minor >= 0 ) ) : ( $major > 5 || ( 5 === $major && $minor >= 6 ) );
        return array( 'version' => $version, 'flavor' => $mariadb ? 'mariadb' : 'mysql', 'online_ddl' => (bool) $supports );
    }

    /**
     * Read-only preflight. Returns a report rather than throwing so the admin
     * panel can show exactly which gate would block the operation.
     */
    public static function preflight( $table, array $columns, $name = '' ) {
        global $wpdb;
        $table = trim( (string) $table );
        $columns = self::normalize_columns( $columns );
        $report = array(
            'ok'        => false,
            'errors'    => array(),
            'warnings'  => array(),
            'table'     => $table,
            'columns'   => $columns,
            'name'      => '',
            'engine'    => '',
            'rows'      => 0,
            'bytes'     => 0,
            'algorithm' => '',
            'duplicate' => false,
        );
        if ( ! self::valid_name( $table ) ) { $report['errors'][] = 'Invalid table name.'; return $report; }
        if ( ! $columns ) { $report['errors'][] = 'Provide between one and ' . self::MAX_COLUMNS . ' valid column names.'; return $report; }
        $name = '' === (string) $name ? self::index_name( $table, $columns ) : trim( (string) $name );
        if ( ! self::valid_name( $name ) ) { $report['errors'][] = 'Invalid index name.'; return $report; }
        $report['name'] = $name;

        $old = $wpdb->suppress_errors( true );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        $table_columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $indexes = $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $status = $wpdb->get_row( 'SHOW TABLE STATUS LIKE \'' . $table . '\'', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->suppress_errors( $old );

        if ( $exists !== $table ) { $report['errors'][] = 'That table does not exist in this database.'; return $report; }
        $available = array();
        foreach ( (array) $table_columns as $column ) { $available[ strtolower( (string) ( $column['Field'] ?? '' ) ) ] = (string) ( $column['Type'] ?? '' ); }
        foreach ( $columns as $column ) {
            if ( ! isset( $available[ strtolower( $column ) ] ) ) { $report['errors'][] = 'Column not found: ' . $column; }
            elseif ( preg_match( '/\b(text|blob|json|geometry)\b/i', $available[ strtolower( $column ) ] ) ) {
                $report['errors'][] = 'Column ' . $column . ' is ' . $available[ strtolower( $column ) ] . ' and needs a prefix length; this tool does not guess one.';
            }
        }
        if ( $report['errors'] ) { return $report; }

        $grouped = array();
        foreach ( (array) $indexes as $row ) {
            $key_name = (string) ( $row['Key_name'] ?? '' );
            $sequence = max( 1, (int) ( $row['Seq_in_index'] ?? 1 ) );
            $column = (string) ( $row['Column_name'] ?? '' );
            if ( '' === $key_name || '' === $column ) { continue; }
            if ( $key_name === $name ) { $report['errors'][] = 'An index called ' . $name . ' already exists on this table.'; }
            $grouped[ $key_name ][ $sequence ] = $column;
        }
        if ( $report['errors'] ) { return $report; }

        foreach ( $grouped as $existing ) {
            ksort( $existing );
            $existing = array_values( $existing );
            if ( count( $existing ) < count( $columns ) ) { continue; }
            $prefix = true;
            foreach ( $columns as $index => $column ) {
                if ( 0 !== strcasecmp( (string) ( $existing[ $index ] ?? '' ), $column ) ) { $prefix = false; break; }
            }
            if ( $prefix ) {
                $report['duplicate'] = true;
                $report['warnings'][] = 'An existing index already covers this column prefix. Adding another one would duplicate write cost without improving reads.';
                break;
            }
        }

        $report['engine'] = (string) ( $status['Engine'] ?? '' );
        $report['rows'] = (int) ( $status['Rows'] ?? 0 );
        $report['bytes'] = (int) ( $status['Data_length'] ?? 0 ) + (int) ( $status['Index_length'] ?? 0 );
        $support = self::ddl_support();
        $report['ddl'] = $support;
        if ( 'InnoDB' === $report['engine'] ) {
            if ( ! empty( $support['online_ddl'] ) ) {
                $report['algorithm'] = 'INPLACE';
                $report['lock'] = 'NONE';
            } else {
                $report['warnings'][] = 'This server predates online DDL for InnoDB, so the ALTER would lock writes. Schedule a maintenance window instead.';
            }
        } elseif ( '' !== $report['engine'] ) {
            $report['warnings'][] = 'Table engine is ' . $report['engine'] . ', which does not support online DDL. The operation will lock writes unless forced.';
        }
        if ( $report['bytes'] > self::MAX_TABLE_BYTES ) {
            $report['warnings'][] = 'Table is larger than ' . size_format( self::MAX_TABLE_BYTES ) . '; the large-table gate applies.';
        }
        $report['ok'] = true;
        return $report;
    }

    /**
     * Create the index behind every gate.
     *
     * @param array $args backup_confirmed, force_large, force_engine.
     */
    public static function create( $table, array $columns, array $args = array() ) {
        global $wpdb;
        if ( empty( $args['backup_confirmed'] ) ) {
            return new WP_Error( 'pfc_index_backup', 'Confirm a verified current database backup or snapshot before adding an index.' );
        }
        $report = self::preflight( $table, $columns, (string) ( $args['name'] ?? '' ) );
        if ( ! $report['ok'] ) {
            return new WP_Error( 'pfc_index_preflight', implode( ' ', $report['errors'] ) );
        }
        if ( ! empty( $report['duplicate'] ) ) {
            return new WP_Error( 'pfc_index_duplicate', 'An existing index already covers those columns.' );
        }
        if ( $report['bytes'] > self::MAX_TABLE_BYTES && empty( $args['force_large'] ) ) {
            return new WP_Error( 'pfc_index_size', 'Table exceeds the large-table gate. Re-run with force_large only inside a maintenance window.' );
        }
        if ( 'InnoDB' !== $report['engine'] && empty( $args['force_engine'] ) ) {
            return new WP_Error( 'pfc_index_engine', 'Only InnoDB supports the online DDL path. Confirm the lock window before forcing another engine.' );
        }

        $parts = array();
        foreach ( $report['columns'] as $column ) { $parts[] = '`' . $column . '`'; }
        $sql = 'ALTER TABLE `' . $report['table'] . '` ADD INDEX `' . $report['name'] . '` (' . implode( ',', $parts ) . ')';
        if ( 'INPLACE' === ( $report['algorithm'] ?? '' ) ) { $sql .= ' ALGORITHM=INPLACE, LOCK=NONE'; }

        $old = $wpdb->suppress_errors( true );
        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $error = (string) $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( false === $result || '' !== $error ) {
            return new WP_Error( 'pfc_index_failed', 'The ALTER did not complete: ' . ( '' !== $error ? $error : 'unknown database error' ) );
        }

        $created = $wpdb->get_results( 'SHOW INDEX FROM `' . $report['table'] . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $verified = false;
        foreach ( (array) $created as $row ) { if ( $report['name'] === (string) ( $row['Key_name'] ?? '' ) ) { $verified = true; break; } }
        if ( ! $verified ) {
            return new WP_Error( 'pfc_index_unverified', 'The ALTER reported success but the index is not visible in SHOW INDEX.' );
        }

        $change_id = PFC_Change_Impact::record( self::CHANGE_TYPE, $report['table'], '', $report['name'], array(
            'columns' => $report['columns'],
            'sql'     => $sql,
            'engine'  => $report['engine'],
        ) );
        return array(
            'ok'        => true,
            'index'     => $report['name'],
            'table'     => $report['table'],
            'columns'   => $report['columns'],
            'sql'       => $sql,
            'change_id' => $change_id,
            'message'   => 'Index ' . $report['name'] . ' created and verified. Measure the route before and after; write cost rises even when reads improve.',
        );
    }

    /** Drop only an index this plugin created and recorded. */
    public static function undo( $change_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PFC_Utils::table( 'changes' ) . ' WHERE id=%d AND change_type=%s', (int) $change_id, self::CHANGE_TYPE ), ARRAY_A );
        if ( ! $row ) { return new WP_Error( 'pfc_index_undo_missing', 'No Performance Console index creation matches that change ID.' ); }
        $table = (string) $row['object_name'];
        $index = (string) $row['after_value'];
        if ( ! self::valid_name( $table ) || ! self::valid_name( $index ) || 0 !== strpos( $index, 'pfc_opt_' ) ) {
            return new WP_Error( 'pfc_index_undo_invalid', 'The recorded identifiers are not in the managed form.' );
        }
        $old = $wpdb->suppress_errors( true );
        $result = $wpdb->query( 'ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $error = (string) $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( false === $result || '' !== $error ) {
            return new WP_Error( 'pfc_index_undo_failed', 'The DROP did not complete: ' . ( '' !== $error ? $error : 'unknown database error' ) );
        }
        PFC_Change_Impact::record( 'index_dropped', $table, $index, '', array( 'restored_from_change' => (int) $change_id ) );
        return array( 'ok' => true, 'message' => 'Index ' . $index . ' dropped from ' . $table . '.' );
    }

    /**
     * Indexes the server has never used since it started.
     *
     * Evidence only. Dropping an index can break a query plan or a foreign key,
     * so this never offers an automatic drop.
     */
    public static function usage( $limit = 25 ) {
        global $wpdb;
        $limit = max( 1, min( 100, (int) $limit ) );
        $uptime = 0;
        $status = $wpdb->get_row( "SHOW GLOBAL STATUS LIKE 'Uptime'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( is_array( $status ) ) { $uptime = (int) ( $status['Value'] ?? 0 ); }
        $old = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results( 'SELECT OBJECT_NAME table_name, INDEX_NAME index_name, SUM(COUNT_STAR) io_count, SUM(COUNT_FETCH) fetches FROM performance_schema.table_io_waits_summary_by_index_usage WHERE OBJECT_SCHEMA = DATABASE() AND INDEX_NAME IS NOT NULL GROUP BY OBJECT_NAME, INDEX_NAME HAVING SUM(COUNT_STAR) = 0 ORDER BY table_name ASC LIMIT ' . $limit, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $error = (string) $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( '' !== $error || ! is_array( $rows ) ) {
            return array( 'available' => false, 'message' => 'performance_schema index usage is not readable on this server.', 'rows' => array(), 'uptime_days' => round( $uptime / DAY_IN_SECONDS, 1 ) );
        }
        return array(
            'available'   => true,
            'rows'        => $rows,
            'uptime_days' => round( $uptime / DAY_IN_SECONDS, 1 ),
            'warning'     => $uptime < 7 * DAY_IN_SECONDS ? 'The server has been up for less than a week, so "never used" is weak evidence.' : '',
        );
    }

    /** Stored candidates from the newest deep profile, de-duplicated. */
    public static function candidates( $limit = 10 ) {
        global $wpdb;
        $limit = max( 1, min( 30, (int) $limit ) );
        $rows = $wpdb->get_results( 'SELECT explain_json FROM ' . PFC_Utils::table( 'queries' ) . ' WHERE explain_json IS NOT NULL ORDER BY id DESC LIMIT 400', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $candidates = array();
        foreach ( (array) $rows as $row ) {
            $decoded = json_decode( (string) $row['explain_json'], true );
            $candidate = (array) ( $decoded['analysis']['index_candidate'] ?? array() );
            if ( empty( $candidate['sql'] ) || empty( $candidate['table'] ) || empty( $candidate['columns'] ) ) { continue; }
            $key = strtolower( (string) $candidate['table'] . ':' . implode( ',', (array) $candidate['columns'] ) );
            if ( isset( $candidates[ $key ] ) ) { continue; }
            $candidates[ $key ] = array(
                'table'   => (string) $candidate['table'],
                'columns' => array_values( (array) $candidate['columns'] ),
                'sql'     => (string) $candidate['sql'],
                'rows'    => (int) ( $candidate['rows_examined_estimate'] ?? 0 ),
            );
            if ( count( $candidates ) >= $limit ) { break; }
        }
        return array_values( $candidates );
    }
}
