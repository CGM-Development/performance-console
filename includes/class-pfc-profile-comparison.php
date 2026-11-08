<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only paired signed-probe comparison with explicit coverage checks. */
final class PFC_Profile_Comparison {
    public static function summarize( array $pairs ) {
        $valid = array();
        $reasons = array( 'missing_or_unverified_probe' => 0, 'different_response' => 0, 'instrumentation_mode_unverified' => 0 );
        foreach ( array_slice( $pairs, 0, 5 ) as $pair ) {
            $base = (array) ( $pair['standard'] ?? array() );
            $deep = (array) ( $pair['deep'] ?? array() );
            if ( ! is_numeric( $base['php_ms'] ?? null ) || ! is_numeric( $deep['php_ms'] ?? null ) ||
                 ! is_numeric( $base['sql_ms'] ?? null ) || ! is_numeric( $deep['sql_ms'] ?? null ) ) {
                ++$reasons['missing_or_unverified_probe']; continue;
            }
            if ( ! empty( $base['callback_profile_enabled'] ) || empty( $deep['callback_profile_enabled'] ) ) {
                ++$reasons['instrumentation_mode_unverified']; continue;
            }
            if ( (string) ( $base['content_type'] ?? '' ) !== (string) ( $deep['content_type'] ?? '' ) ||
                 (int) ( $base['status'] ?? 0 ) !== (int) ( $deep['status'] ?? 0 ) ||
                 (int) ( $base['body_bytes'] ?? 0 ) < 128 || (int) ( $deep['body_bytes'] ?? 0 ) < 128 ) {
                ++$reasons['different_response']; continue;
            }
            $big = max( (int) $base['body_bytes'], (int) $deep['body_bytes'] );
            $small = min( (int) $base['body_bytes'], (int) $deep['body_bytes'] );
            if ( $big > 0 && ( $big - $small ) / $big > 0.10 ) {
                ++$reasons['different_response']; continue;
            }
            $valid[] = array(
                'standard_php_ms' => round( (float) $base['php_ms'], 2 ),
                'deep_php_ms' => round( (float) $deep['php_ms'], 2 ),
                'standard_sql_ms' => round( (float) $base['sql_ms'], 2 ),
                'deep_sql_ms' => round( (float) $deep['sql_ms'], 2 ),
                'deep_minus_standard_php_ms' => round( (float) $deep['php_ms'] - (float) $base['php_ms'], 2 ),
            );
        }
        $requested = min( 5, count( $pairs ) );
        $valid_n = count( $valid );
        $base_times = array_column( $valid, 'standard_php_ms' );
        $deep_times = array_column( $valid, 'deep_php_ms' );
        $deltas = array_column( $valid, 'deep_minus_standard_php_ms' );
        return array(
            'requested_pairs' => $requested,
            'valid_pairs' => $valid_n,
            'incomplete_pairs' => $requested - $valid_n,
            'unmatched_reasons' => $reasons,
            'comparable' => $valid_n === $requested && $valid_n >= 3,
            'standard_median_php_ms' => $base_times ? round( (float) PFC_Utils::median( $base_times ), 2 ) : null,
            'deep_median_php_ms' => $deep_times ? round( (float) PFC_Utils::median( $deep_times ), 2 ) : null,
            'paired_median_deep_minus_standard_ms' => $deltas ? round( (float) PFC_Utils::median( $deltas ), 2 ) : null,
            'paired_deltas_ms' => $deltas,
            'notes' => 'Small alternating-order signed probes, not a randomized production benchmark. Cold-cache state, traffic, instrumented callback wrappers and probe order can change results; negative deltas are possible. Do not subtract this figure as a guaranteed profiler overhead correction.',
        );
    }
}
