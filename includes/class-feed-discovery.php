<?php
/**
 * Feed discovery — turns a user-pasted source URL into a usable RSS/Atom URL.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Directorist_Listing_Import_Feed_Discovery {

    /**
     * Resolve a normal source URL or direct feed URL into a valid feed URL.
     *
     * @param string $source_url User-pasted URL.
     * @return array|WP_Error
     */
    public static function resolve( string $source_url ) {
        $source_url = esc_url_raw( trim( $source_url ) );

        if ( empty( $source_url ) ) {
            return new WP_Error(
                'dli_empty_source_url',
                __( 'Paste a source page or RSS feed URL before adding a feed.', 'directorist-listing-import' )
            );
        }

        if ( self::is_valid_feed( $source_url ) ) {
            return self::result( $source_url, $source_url, __( 'RSS feed detected.', 'directorist-listing-import' ) );
        }

        $known_feed = self::resolve_known_source( $source_url );
        if ( is_wp_error( $known_feed ) ) {
            return $known_feed;
        }

        if ( $known_feed && self::is_valid_feed( $known_feed ) ) {
            return self::result(
                $source_url,
                $known_feed,
                __( 'A compatible RSS feed was found for this source URL.', 'directorist-listing-import' )
            );
        }

        $discovered_feed = self::discover_from_html( $source_url );
        if ( is_wp_error( $discovered_feed ) ) {
            return $discovered_feed;
        }

        if ( $discovered_feed && self::is_valid_feed( $discovered_feed ) ) {
            return self::result(
                $source_url,
                $discovered_feed,
                __( 'A compatible RSS feed was found on this page.', 'directorist-listing-import' )
            );
        }

        return self::unsupported_source_error( $source_url );
    }

    /**
     * Return up to a few items from a valid feed for admin preview.
     *
     * @param string $feed_url
     * @param int    $limit
     * @return array|WP_Error
     */
    public static function preview_items( string $feed_url, int $limit = 5 ) {
        if ( ! function_exists( 'fetch_feed' ) ) {
            require_once ABSPATH . WPINC . '/feed.php';
        }

        $feed = fetch_feed( $feed_url );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $items   = [];
        $entries = $feed->get_items( 0, $limit );

        foreach ( $entries as $entry ) {
            $items[] = [
                'title'     => wp_strip_all_tags( $entry->get_title() ?? '' ),
                'permalink' => esc_url_raw( $entry->get_permalink() ?? '' ),
                'date'      => $entry->get_date( 'M j, Y' ) ?: '',
            ];
        }

        return $items;
    }

    public static function preview_listings( string $feed_url, int $limit = 5 ) {
        if ( ! function_exists( 'fetch_feed' ) ) {
            require_once ABSPATH . WPINC . '/feed.php';
        }

        $feed = fetch_feed( $feed_url );
        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        $items = [];
        foreach ( $feed->get_items( 0, $limit ) as $entry ) {
            $title       = wp_strip_all_tags( $entry->get_title() ?? '' );
            $description = wp_strip_all_tags( $entry->get_description() ?? '' );
            $permalink   = esc_url_raw( $entry->get_permalink() ?? '' );
            $text        = trim( $title . ' ' . $description );

            $items[] = [
                'title'       => $title,
                'address'     => self::extract_address( $entry, $text ),
                'phone'       => self::extract_phone( $text ),
                'email'       => self::extract_email( $text ),
                'website'     => self::extract_website( $description, $permalink ),
                'description' => $description,
                'permalink'   => $permalink,
            ];
        }

        return $items;
    }

    public static function quality_score( array $items ): array {
        $fields = [ 'title', 'address', 'phone', 'email', 'website', 'description' ];
        $counts = array_fill_keys( $fields, 0 );
        $total  = max( 1, count( $items ) );

        foreach ( $items as $item ) {
            foreach ( $fields as $field ) {
                if ( ! empty( $item[ $field ] ) ) {
                    $counts[ $field ]++;
                }
            }
        }

        $required_weight = [
            'title'       => 25,
            'description' => 20,
            'website'     => 15,
            'address'     => 15,
            'phone'       => 15,
            'email'       => 10,
        ];

        $score = 0;
        foreach ( $required_weight as $field => $weight ) {
            $score += ( $counts[ $field ] / $total ) * $weight;
        }

        return [
            'score'  => (int) round( $score ),
            'counts' => $counts,
            'total'  => $total,
        ];
    }

    private static function is_valid_feed( string $url ): bool {
        if ( ! function_exists( 'fetch_feed' ) ) {
            require_once ABSPATH . WPINC . '/feed.php';
        }

        $feed = fetch_feed( $url );

        return ! is_wp_error( $feed );
    }

    private static function extract_email( string $text ): string {
        if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches ) ) {
            return sanitize_email( $matches[0] );
        }

        return '';
    }

    private static function extract_phone( string $text ): string {
        if ( preg_match( '/(?:\+?\d{1,3}[\s.\-]?)?(?:\(?\d{3}\)?[\s.\-]?)\d{3}[\s.\-]?\d{4}/', $text, $matches ) ) {
            return sanitize_text_field( $matches[0] );
        }

        return '';
    }

    private static function extract_website( string $description, string $fallback ): string {
        if ( preg_match( '#https?://[^\s<>"\']+#i', $description, $matches ) ) {
            return esc_url_raw( $matches[0] );
        }

        return $fallback;
    }

    private static function extract_address( $entry, string $text ): string {
        $cl_tags = $entry->get_item_tags( 'https://www.craigslist.org/about/legal/tos', 'neighborhood' );
        if ( ! empty( $cl_tags[0]['data'] ) ) {
            return sanitize_text_field( $cl_tags[0]['data'] );
        }

        if ( preg_match( '/\b\d{1,6}\s+[A-Za-z0-9 .\-]+(?:street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct)\b[^\n,.]*/i', $text, $matches ) ) {
            return sanitize_text_field( $matches[0] );
        }

        return '';
    }

    private static function resolve_known_source( string $url ): string {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

        if ( false !== strpos( $host, 'craigslist.' ) || false !== strpos( $host, 'craigslist.org' ) ) {
            return self::add_or_replace_query_arg( $url, 'format', 'rss' );
        }

        return '';
    }

    private static function discover_from_html( string $url ) {
        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 12,
                'redirection' => 5,
                'headers'     => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            if ( 415 === $status ) {
                return new WP_Error(
                    'dli_source_unsupported_media_type',
                    __( 'The source URL redirects to a feed endpoint, but that endpoint returned HTTP 415 Unsupported Media Type. This usually means the publisher is not serving a usable RSS/Atom feed to your server. Try another official feed URL or contact the source site for an approved feed.', 'directorist-listing-import' )
                );
            }

            return new WP_Error(
                'dli_source_unreachable',
                sprintf(
                    /* translators: %d is an HTTP status code. */
                    __( 'The source page could not be reached. It returned HTTP status %d.', 'directorist-listing-import' ),
                    $status
                )
            );
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            return new WP_Error(
                'dli_empty_source_page',
                __( 'The source page loaded, but it did not return any readable content.', 'directorist-listing-import' )
            );
        }

        $feed_url = self::find_alternate_feed_link( $html, $url );

        return $feed_url ?: '';
    }

    private static function find_alternate_feed_link( string $html, string $base_url ): string {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return '';
        }

        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument();
        $loaded   = $dom->loadHTML( $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return '';
        }

        foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
            $rel  = strtolower( (string) $link->getAttribute( 'rel' ) );
            $type = strtolower( (string) $link->getAttribute( 'type' ) );
            $href = trim( (string) $link->getAttribute( 'href' ) );

            if ( empty( $href ) || false === strpos( $rel, 'alternate' ) ) {
                continue;
            }

            if ( false === strpos( $type, 'rss' ) && false === strpos( $type, 'atom' ) && false === strpos( $type, 'xml' ) ) {
                continue;
            }

            return self::absolute_url( $href, $base_url );
        }

        return '';
    }

    private static function unsupported_source_error( string $source_url ): WP_Error {
        $host = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );

        if ( false !== strpos( $host, 'kijiji.' ) ) {
            return new WP_Error(
                'dli_kijiji_no_feed',
                __( 'This Kijiji page is a normal web page, not an RSS feed. For legal reasons, this plugin cannot scrape Kijiji pages automatically. Use an official Kijiji RSS feed, a source you have permission to import from, or Directorist CSV import for your own listings.', 'directorist-listing-import' )
            );
        }

        return new WP_Error(
            'dli_no_feed_found',
            __( 'No RSS or Atom feed was found for this page. This plugin imports only official feeds or sources you have permission to use; it will not scrape normal web pages automatically.', 'directorist-listing-import' )
        );
    }

    private static function result( string $source_url, string $feed_url, string $message ): array {
        return [
            'source_url' => esc_url_raw( $source_url ),
            'feed_url'   => esc_url_raw( $feed_url ),
            'message'    => $message,
        ];
    }

    private static function add_or_replace_query_arg( string $url, string $key, string $value ): string {
        return esc_url_raw( add_query_arg( [ $key => $value ], $url ) );
    }

    private static function absolute_url( string $href, string $base_url ): string {
        if ( wp_http_validate_url( $href ) ) {
            return esc_url_raw( $href );
        }

        $scheme = wp_parse_url( $base_url, PHP_URL_SCHEME ) ?: 'https';
        $host   = wp_parse_url( $base_url, PHP_URL_HOST );

        if ( ! $host ) {
            return '';
        }

        if ( 0 === strpos( $href, '//' ) ) {
            return esc_url_raw( $scheme . ':' . $href );
        }

        if ( 0 === strpos( $href, '/' ) ) {
            return esc_url_raw( $scheme . '://' . $host . $href );
        }

        $path = wp_parse_url( $base_url, PHP_URL_PATH ) ?: '/';
        $path = trailingslashit( dirname( $path ) );

        return esc_url_raw( $scheme . '://' . $host . $path . $href );
    }
}
