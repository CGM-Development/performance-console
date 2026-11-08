<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Asset and priority-hint ledger.
 *
 * Reads the HTML a visitor actually receives and prices what it costs to render:
 * render-blocking stylesheets and head scripts, the likely LCP image's loading
 * and priority hints, third-party origins without a preconnect, images with no
 * reserved dimensions, and inline payload weight.
 *
 * The millisecond figures are heuristics, not measurements. They exist to order
 * the list, and every finding states the bytes it can prove. Nothing here
 * rewrites HTML or enqueues anything: it produces recommendations.
 */
final class PFC_Asset_Ledger {
    const MAX_RESOURCES = 300;
    const MAX_HEAD_REQUESTS = 8;
    const SIZE_TTL = 6 * HOUR_IN_SECONDS;
    const INLINE_BUDGET = 61440; // 60 KB of inline CSS + JS is already a lot on a text page.
    const THIRD_PARTY_BUDGET = 8;

    /** Tag names that appear in the attribute regex and must not be mistaken for attributes. */
    const TAGS = array( 'link', 'script', 'img', 'style' );

    /**
     * Extract resources from rendered HTML.
     *
     * Pure: no WordPress calls, so the regression suite can exercise it against
     * a saved response. Relative URLs resolve against $base_url.
     */
    public static function parse( $html, $base_url = '' ) {
        $html = (string) $html;
        $base_url = (string) $base_url;
        $base_origin = self::origin( $base_url );
        $body_start = stripos( $html, '<body' );
        $head_end = false === $body_start ? strlen( $html ) : $body_start;

        $parsed = array(
            'url'          => $base_url,
            'html_bytes'   => strlen( $html ),
            'head_bytes'   => $head_end,
            'stylesheets'  => array(),
            'scripts'      => array(),
            'images'       => array(),
            'links'        => array(),
            'origins'      => array(),
            'inline_bytes' => array( 'style' => 0, 'script' => 0 ),
        );

        if ( preg_match_all( '/<link\b[^>]*>/i', $html, $matches ) ) {
            foreach ( array_slice( $matches[0], 0, self::MAX_RESOURCES ) as $tag ) {
                $attrs = self::attributes( $tag );
                $rel = strtolower( trim( (string) ( $attrs['rel'] ?? '' ) ) );
                if ( '' === $rel ) { continue; }
                $href = (string) ( $attrs['href'] ?? '' );
                if ( 'stylesheet' === $rel || in_array( 'stylesheet', preg_split( '/\s+/', $rel ), true ) ) {
                    $media = strtolower( trim( (string) ( $attrs['media'] ?? '' ) ) );
                    $blocking = ( '' === $media || 'all' === $media || 'screen' === $media )
                        && ! isset( $attrs['onload'] )
                        && ! preg_match( '/\bdisabled\b/i', $tag );
                    $parsed['stylesheets'][] = array(
                        'href'     => self::resolve( $href, $base_url ),
                        'media'    => $media,
                        'blocking' => $blocking,
                        'origin'   => self::classify_origin( self::resolve( $href, $base_url ), $base_origin ),
                        'preload'  => false,
                    );
                    continue;
                }
                if ( in_array( $rel, array( 'preload', 'preconnect', 'dns-prefetch', 'prefetch', 'modulepreload' ), true ) ) {
                    $parsed['links'][] = array(
                        'rel'    => $rel,
                        'href'   => self::resolve( $href, $base_url ),
                        'as'     => strtolower( trim( (string) ( $attrs['as'] ?? '' ) ) ),
                        'origin' => self::classify_origin( self::resolve( $href, $base_url ), $base_origin ),
                    );
                }
            }
        }

        if ( preg_match_all( '/<script\b[^>]*>/i', $html, $matches ) ) {
            foreach ( array_slice( $matches[0], 0, self::MAX_RESOURCES ) as $tag ) {
                $attrs = self::attributes( $tag );
                $src = (string) ( $attrs['src'] ?? '' );
                if ( '' === $src ) { continue; }
                $type = strtolower( trim( (string) ( $attrs['type'] ?? '' ) ) );
                $module = 'module' === $type;
                $defer = $module || array_key_exists( 'defer', $attrs );
                $async = array_key_exists( 'async', $attrs );
                $position = strpos( $html, $tag );
                $in_head = false !== $position && $position < $head_end;
                $parsed['scripts'][] = array(
                    'src'      => self::resolve( $src, $base_url ),
                    'defer'    => $defer,
                    'async'    => $async,
                    'module'   => $module,
                    'in_head'  => $in_head,
                    'blocking' => $in_head && ! $defer && ! $async,
                    'origin'   => self::classify_origin( self::resolve( $src, $base_url ), $base_origin ),
                );
            }
        }

        if ( preg_match_all( '/<img\b[^>]*>/i', $html, $matches ) ) {
            foreach ( array_slice( $matches[0], 0, self::MAX_RESOURCES ) as $index => $tag ) {
                $attrs = self::attributes( $tag );
                $src = (string) ( $attrs['src'] ?? '' );
                if ( '' === $src && isset( $attrs['data-src'] ) ) { $src = (string) $attrs['data-src']; }
                $parsed['images'][] = array(
                    'src'           => self::resolve( $src, $base_url ),
                    'width'         => (int) ( $attrs['width'] ?? 0 ),
                    'height'        => (int) ( $attrs['height'] ?? 0 ),
                    'loading'       => strtolower( trim( (string) ( $attrs['loading'] ?? '' ) ) ),
                    'fetchpriority' => strtolower( trim( (string) ( $attrs['fetchpriority'] ?? '' ) ) ),
                    'srcset'        => isset( $attrs['srcset'] ) && '' !== trim( (string) $attrs['srcset'] ),
                    'origin'        => self::classify_origin( self::resolve( $src, $base_url ), $base_origin ),
                    'position'      => $index,
                );
            }
        }

        if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles ) ) {
            $parsed['inline_bytes']['style'] = strlen( implode( '', $styles[1] ) );
        }
        if ( preg_match_all( '/<script\b(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/is', $html, $inline ) ) {
            $parsed['inline_bytes']['script'] = strlen( implode( '', $inline[1] ) );
        }

        $origins = array();
        foreach ( array_merge( $parsed['stylesheets'], $parsed['scripts'], $parsed['images'] ) as $resource ) {
            // Stylesheets carry href; scripts and images carry src.
            $resource_url = (string) ( $resource['src'] ?? $resource['href'] ?? '' );
            if ( 'third-party' === $resource['origin'] && '' !== $resource_url ) {
                $host = (string) parse_url( $resource_url, PHP_URL_HOST );
                if ( '' !== $host ) { $origins[ strtolower( $host ) ] = ( $origins[ strtolower( $host ) ] ?? 0 ) + 1; }
            }
        }
        $preconnected = array();
        foreach ( $parsed['links'] as $link ) {
            if ( in_array( $link['rel'], array( 'preconnect', 'dns-prefetch' ), true ) ) {
                $host = strtolower( (string) parse_url( $link['href'], PHP_URL_HOST ) );
                if ( '' !== $host ) { $preconnected[ $host ] = true; }
            }
        }
        arsort( $origins );
        $parsed['origins'] = array(
            'third_party' => $origins,
            'preconnected' => array_keys( $preconnected ),
            'unprepared'  => array_values( array_diff( array_keys( $origins ), array_keys( $preconnected ) ) ),
        );

        return $parsed;
    }

    /** Lowercase attribute map for one tag. */
    private static function attributes( $tag ) {
        $out = array();
        if ( ! preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', (string) $tag, $matches, PREG_SET_ORDER ) ) {
            return $out;
        }
        foreach ( $matches as $match ) {
            $name = strtolower( $match[1] );
            if ( in_array( $name, self::TAGS, true ) ) { continue; }
            $value = '';
            if ( isset( $match[2] ) && '' !== $match[2] ) { $value = $match[2]; }
            elseif ( isset( $match[3] ) && '' !== $match[3] ) { $value = $match[3]; }
            elseif ( isset( $match[4] ) ) { $value = $match[4]; }
            $out[ $name ] = $value;
        }
        return $out;
    }

    /** Resolve a possibly relative URL against the request URL. */
    private static function resolve( $url, $base ) {
        $url = trim( (string) $url );
        if ( '' === $url || 0 === stripos( $url, 'data:' ) ) { return ''; }
        if ( preg_match( '#^(https?:)?//#i', $url ) ) { return 0 === strpos( $url, '//' ) ? 'https:' . $url : $url; }
        if ( '/' === substr( $url, 0, 1 ) ) {
            $scheme = (string) parse_url( $base, PHP_URL_SCHEME );
            $host = (string) parse_url( $base, PHP_URL_HOST );
            $port = parse_url( $base, PHP_URL_PORT );
            $authority = ( $scheme ?: 'https' ) . '://' . ( $host ?: '' ) . ( $port ? ':' . $port : '' );
            return $authority . $url;
        }
        $root = preg_replace( '#^([a-z]+://[^/]+).*$#i', '$1', (string) $base );
        $path = (string) parse_url( $base, PHP_URL_PATH );
        $directory = preg_replace( '#/[^/]*$#', '/', $path );
        return $root . $directory . $url;
    }

    private static function origin( $url ) {
        $scheme = strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) );
        $host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
        if ( '' === $host ) { return ''; }
        $port = (int) parse_url( (string) $url, PHP_URL_PORT );
        if ( ! $port ) { $port = 'https' === $scheme ? 443 : 80; }
        return $host . ':' . $port;
    }

    private static function classify_origin( $url, $base_origin ) {
        if ( '' === $url ) { return 'inline'; }
        $origin = self::origin( $url );
        if ( '' === $origin || '' === $base_origin ) { return 'unknown'; }
        return $origin === $base_origin ? 'same-origin' : 'third-party';
    }

    /**
     * Turn parsed resources into ranked findings.
     *
     * Every finding carries a heuristic estimate and the exact count or bytes it
     * is based on, so the estimate can be ignored without losing the evidence.
     */
    public static function audit( array $parsed ) {
        $findings = array();
        $add = static function ( $id, $severity, $title, $detail, $estimate_ms, $evidence ) use ( &$findings ) {
            $findings[] = array(
                'id'          => $id,
                'severity'    => $severity,
                'title'       => $title,
                'detail'      => $detail,
                'estimate_ms' => (int) $estimate_ms,
                'evidence'    => $evidence,
            );
        };
        $estimate = static function ( $per_item, $count, $cap ) { return (int) min( $cap, $per_item * max( 0, $count ) ); };

        $images = (array) ( $parsed['images'] ?? array() );
        $lcp = $images[0] ?? null;
        if ( $lcp && '' !== (string) $lcp['src'] ) {
            if ( 'lazy' === $lcp['loading'] ) {
                $add( 'lcp_lazy', 'high', 'LCP candidate image is lazy-loaded', 'The first image on the page is a strong LCP candidate, and loading="lazy" delays it until layout has been calculated. Make it eager, or let the browser discover it in the initial HTML.', 250, 'loading="lazy" on the first <img>' );
            }
            if ( 'high' !== $lcp['fetchpriority'] ) {
                $add( 'lcp_no_fetchpriority', 'high', 'LCP candidate has no fetchpriority hint', 'Adding fetchpriority="high" to the likely LCP image tells the preload scanner to fetch it ahead of lower-value resources. This changes ordering, not bytes, which makes it one of the cheapest LCP wins.', 120, 'no fetchpriority="high" on the first <img>' );
            }
            if ( ! $lcp['width'] || ! $lcp['height'] ) {
                $add( 'lcp_no_dimensions', 'warning', 'LCP candidate has no intrinsic dimensions', 'Without width and height the browser cannot reserve space, so the image contributes to layout shift as well as LCP.', 0, 'missing width/height on the first <img>' );
            }
        }

        $blocking_css = array_values( array_filter( (array) ( $parsed['stylesheets'] ?? array() ), static function ( $sheet ) { return ! empty( $sheet['blocking'] ); } ) );
        if ( count( $blocking_css ) > 2 ) {
            $add( 'render_blocking_css', 'high', 'Multiple render-blocking stylesheets', count( $blocking_css ) . ' stylesheets block first paint. Combine them, or load non-critical ones non-blocking. Each blocking stylesheet is a full round trip before the browser can render.', $estimate( 60, count( $blocking_css ) - 2, 300 ), count( $blocking_css ) . ' blocking <link rel="stylesheet">' );
        }

        $head_scripts = array_values( array_filter( (array) ( $parsed['scripts'] ?? array() ), static function ( $script ) { return ! empty( $script['blocking'] ); } ) );
        if ( $head_scripts ) {
            $add( 'head_scripts', 'high', 'Blocking scripts in the document head', count( $head_scripts ) . ' script(s) in <head> have neither defer nor async, so HTML parsing stops until each one downloads and executes. Defer them, or move them to the footer.', $estimate( 80, count( $head_scripts ), 320 ), count( $head_scripts ) . ' blocking <script src> in head' );
        }

        $third_party = (array) ( $parsed['origins']['third_party'] ?? array() );
        $unprepared = (array) ( $parsed['origins']['unprepared'] ?? array() );
        if ( $unprepared ) {
            $add( 'no_preconnect', 'warning', 'Third-party origins without preconnect', count( $unprepared ) . ' origin(s) are contacted without a preconnect hint, so the browser pays DNS, TCP and TLS setup on the critical path: ' . implode( ', ', array_slice( $unprepared, 0, 6 ) ) . '. Add <link rel="preconnect"> (and dns-prefetch as a fallback) for the ones that matter.', $estimate( 40, count( $unprepared ), 160 ), count( $unprepared ) . ' unprepared origins' );
        }
        if ( count( $third_party ) > self::THIRD_PARTY_BUDGET ) {
            $add( 'third_party_volume', 'warning', 'High third-party origin count', count( $third_party ) . ' third-party hosts are referenced. Every extra origin is another connection the browser must open and another failure mode for the page.', 0, count( $third_party ) . ' distinct third-party hosts' );
        }

        $no_dimensions = 0;
        $no_srcset = 0;
        foreach ( $images as $index => $image ) {
            if ( ! $image['width'] || ! $image['height'] ) { $no_dimensions++; }
            if ( ! $image['srcset'] && $index > 0 ) { $no_srcset++; }
        }
        if ( $no_dimensions > 0 ) {
            $add( 'images_no_dimensions', 'warning', 'Images without reserved space', $no_dimensions . ' image(s) have no width/height attributes or CSS aspect ratio, which causes layout shift as they load. Reserve the box to protect CLS.', 0, $no_dimensions . ' images missing dimensions' );
        }
        if ( $no_srcset > 2 ) {
            $add( 'images_no_srcset', 'info', 'Images without responsive srcset', $no_srcset . ' non-hero image(s) have no srcset, so mobile visitors download desktop-sized files. Generating a couple of smaller variants usually saves more bytes than any compression tweak.', 0, $no_srcset . ' images without srcset' );
        }

        $font_hosts = array();
        foreach ( (array) ( $parsed['stylesheets'] ?? array() ) as $sheet ) {
            $host = strtolower( (string) parse_url( (string) $sheet['href'], PHP_URL_HOST ) );
            if ( false !== strpos( $host, 'fonts.' ) ) { $font_hosts[ $host ] = true; }
        }
        if ( $font_hosts && empty( $parsed['links'] ) ) {
            $add( 'font_no_preconnect', 'warning', 'Web fonts loaded without preconnect', 'Font stylesheets are requested from ' . implode( ', ', array_keys( $font_hosts ) ) . ' with no preconnect hint, so the font request waits for a fresh connection after CSS has downloaded.', 100, 'font origins: ' . implode( ', ', array_keys( $font_hosts ) ) );
        }

        $inline = (array) ( $parsed['inline_bytes'] ?? array() );
        $inline_total = (int) ( $inline['style'] ?? 0 ) + (int) ( $inline['script'] ?? 0 );
        if ( $inline_total > self::INLINE_BUDGET ) {
            $inline_human = function_exists( 'size_format' ) ? size_format( $inline_total ) : round( $inline_total / 1024, 1 ) . ' KB';
            $add( 'inline_weight', 'info', 'Heavy inline CSS and JavaScript', 'The document carries ' . $inline_human . ' of inline code, which cannot be cached separately and inflates every HTML response. Move stable blocks into cacheable files.', 0, $inline_human . ' inline' );
        }

        usort( $findings, static function ( $a, $b ) {
            $rank = array( 'critical' => 4, 'high' => 3, 'warning' => 2, 'info' => 1 );
            $by_severity = ( $rank[ $b['severity'] ] ?? 0 ) <=> ( $rank[ $a['severity'] ] ?? 0 );
            return 0 !== $by_severity ? $by_severity : (int) $b['estimate_ms'] <=> (int) $a['estimate_ms'];
        } );

        $total_estimate = 0;
        foreach ( $findings as $finding ) { $total_estimate += (int) $finding['estimate_ms']; }

        return array(
            'findings'            => $findings,
            'estimate_ms_total'   => $total_estimate,
            'render_blocking_css' => count( $blocking_css ),
            'blocking_scripts'    => count( $head_scripts ),
            'third_party_origins' => count( $third_party ),
            'images'              => count( $images ),
            'estimate_labelled'   => true,
        );
    }

    /**
     * Fetch the HTML for a route with a signed diagnostic token and audit it.
     *
     * The signed request bypasses page cache by design, so the result describes
     * the origin's real HTML rather than a cached copy.
     */
    public static function inspect( $url = '' ) {
        $url = '' === (string) $url ? home_url( '/' ) : esc_url_raw( (string) $url, array( 'http', 'https' ) );
        if ( ! $url || ! PFC_Utils::same_origin_url( $url ) ) {
            return new WP_Error( 'pfc_asset_url', 'Use a public URL on this site\'s own scheme, host and port.' );
        }
        $admin_path = trailingslashit( (string) wp_parse_url( admin_url(), PHP_URL_PATH ) );
        if ( 0 === strpos( trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ), $admin_path ) ) {
            return new WP_Error( 'pfc_asset_admin', 'Audit a public route; admin pages are not representative.' );
        }
        $token = PFC_Bootstrap::token( $url );
        $response = wp_remote_get( add_query_arg( $token, $url ), array(
            'timeout'     => 30,
            'redirection' => 0,
            'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
            'headers'     => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ),
        ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $verified = '1' === trim( (string) wp_remote_retrieve_header( $response, 'x-pfc-diagnostic' ) );
        $html = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $html ) < 128 ) {
            return new WP_Error( 'pfc_asset_empty', 'The diagnostic response was empty or too small to analyse.' );
        }
        $parsed = self::parse( $html, $url );
        $report = self::audit( $parsed );
        $report['url'] = $url;
        $report['verified'] = $verified;
        $report['html_bytes'] = strlen( $html );
        if ( ! $verified ) {
            $report['warning'] = 'The response did not carry the diagnostic header, so a page cache or proxy may have answered. Findings describe that copy.';
        }
        $report['resources'] = array(
            'stylesheets' => count( $parsed['stylesheets'] ),
            'scripts'     => count( $parsed['scripts'] ),
            'images'      => count( $parsed['images'] ),
            'preloads'    => count( array_filter( (array) $parsed['links'], static function ( $link ) { return 'preload' === $link['rel']; } ) ),
        );
        $report['top_third_party'] = array_slice( (array) $parsed['origins']['third_party'], 0, 10, true );
        set_transient( 'pfc_asset_ledger_' . get_current_user_id(), $report, 30 * MINUTE_IN_SECONDS );
        return $report;
    }

    /** Cached resource byte sizes, bounded and same-origin only. */
    public static function sizes( array $urls ) {
        $sizes = array();
        foreach ( array_slice( array_unique( array_filter( $urls ) ), 0, self::MAX_HEAD_REQUESTS ) as $url ) {
            $url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
            if ( ! $url || ! PFC_Utils::same_origin_url( $url ) ) { continue; }
            $key = 'pfc_asset_size_' . md5( $url );
            $cached = get_transient( $key );
            if ( is_numeric( $cached ) ) { $sizes[ $url ] = (int) $cached; continue; }
            $response = wp_remote_head( $url, array( 'timeout' => 3, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
            if ( is_wp_error( $response ) ) { continue; }
            $length = (int) wp_remote_retrieve_header( $response, 'content-length' );
            if ( $length > 0 ) {
                $sizes[ $url ] = $length;
                set_transient( $key, $length, self::SIZE_TTL );
            }
        }
        return $sizes;
    }
}
