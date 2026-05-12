<?php
/**
 * Importer — fetches an RSS feed and creates Directorist listings.
 *
 * Responsibilities:
 *  - Fetch & parse RSS via WordPress's built-in SimplePie wrapper
 *  - Deduplicate by hashing the source URL
 *  - Map RSS fields → Directorist post + meta
 *  - Sideload thumbnail images
 *  - Write to the import log
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'DLI_META_SOURCE_URL' ) ) {
    define( 'DLI_META_SOURCE_URL', '_directorist_listing_import_source_url' );
    define( 'DLI_META_SOURCE_NAME', '_directorist_listing_import_source_name' );
    define( 'DLI_META_IMPORTED_AT', '_directorist_listing_import_imported_at' );
    define( 'DLI_LEGACY_META_SOURCE_URL', '_dsync_source_url' );
    define( 'DLI_LEGACY_META_SOURCE_NAME', '_dsync_source_name' );
    define( 'DLI_LEGACY_META_IMPORTED_AT', '_dsync_imported_at' );
}

class Directorist_Listing_Import_Importer {

    /** Max items to process per feed per run */
    private int $batch_size;

    /** Listing post status: 'pending' | 'publish' */
    private string $default_status;

    public function __construct() {
        $settings             = (array) get_option( DLI_OPTION_SETTINGS, [] );
        $this->batch_size     = absint( $settings['batch_size']   ?? 25 );
        $this->default_status = in_array( $settings['default_status'] ?? '', [ 'pending', 'publish' ], true )
                                    ? $settings['default_status']
                                    : 'pending';
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Import listings from a single feed config.
     *
     * @param array $feed  Feed config array from Feed_Manager
     * @return array{ imported: int, skipped: int, errors: int }
     */
    public function run_feed( array $feed ): array {
        $result = [ 'imported' => 0, 'skipped' => 0, 'errors' => 0 ];

        if ( empty( $feed['url'] ) ) {
            $this->log( $feed, $result, 'Feed URL is empty.' );
            return $result;
        }

        $items = $this->fetch_items( $feed['url'] );

        if ( is_wp_error( $items ) ) {
            $result['errors']++;
            $this->log( $feed, $result, $items->get_error_message() );
            return $result;
        }

        $count = 0;
        foreach ( $items as $item ) {
            if ( $count >= $this->batch_size ) break;

            $source_url = $item->get_permalink();

            if ( $this->is_duplicate( $source_url ) ) {
                $result['skipped']++;
                $count++;
                continue;
            }

            $post_id = $this->create_listing( $item, $feed );

            if ( is_wp_error( $post_id ) || ! $post_id ) {
                $result['errors']++;
            } else {
                $result['imported']++;
            }

            $count++;
        }

        // Update last_run timestamp
        Directorist_Listing_Import_Feed_Manager::update_feed( $feed['id'], [ 'last_run' => time() ] );

        $this->log( $feed, $result );

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Fetch and parse an RSS feed, returning SimplePie items.
     *
     * @param string $url
     * @return \SimplePie_Item[]|WP_Error
     */
    private function fetch_items( string $url ) {
        // Include SimplePie via WordPress
        if ( ! class_exists( 'SimplePie' ) ) {
            require_once ABSPATH . WPINC . '/class-simplepie.php';
        }

        $feed = fetch_feed( $url );

        if ( is_wp_error( $feed ) ) {
            return $feed;
        }

        return $feed->get_items( 0, $this->batch_size );
    }

    /**
     * Check if a listing with this source URL already exists.
     *
     * @param string|null $source_url
     * @return bool
     */
    private function is_duplicate( ?string $source_url ): bool {
        if ( empty( $source_url ) ) return false;

        $existing = get_posts( [
            'post_type'      => 'at_biz_dir',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'any',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'   => DLI_META_SOURCE_URL,
                    'value' => $source_url,
                ],
                [
                    'key'   => DLI_LEGACY_META_SOURCE_URL,
                    'value' => $source_url,
                ],
            ],
        ] );

        return ! empty( $existing );
    }

    /**
     * Create a Directorist listing from a SimplePie item.
     *
     * @param \SimplePie_Item $item
     * @param array           $feed
     * @return int|WP_Error  Post ID on success
     */
    private function create_listing( $item, array $feed ) {
        $title       = wp_strip_all_tags( $item->get_title() ?? '' );
        $description = wp_kses_post( $item->get_description() ?? '' );
        $source_url  = $item->get_permalink() ?? '';
        $pub_date    = $item->get_date( 'Y-m-d H:i:s' ) ?: current_time( 'mysql' );

        if ( empty( $title ) ) return false;

        $post_data = [
            'post_type'    => 'at_biz_dir',
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => $this->default_status,
            'post_date'    => $pub_date,
        ];

        $post_data = apply_filters( 'directorist_listing_import_pre_insert_listing', $post_data, $item, $feed );

        // ── Create the WP post ───────────────────────────────────────────────
        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) return $post_id;

        $directory_type = absint( $feed['directory_type'] ?? 0 );
        if ( $directory_type > 0 && function_exists( 'directorist_set_listing_directory' ) ) {
            directorist_set_listing_directory( $post_id, $directory_type );
        } elseif ( function_exists( 'directorist_get_default_directory' ) && function_exists( 'directorist_set_listing_directory' ) ) {
            $default_directory = (int) directorist_get_default_directory();
            if ( $default_directory > 0 ) {
                directorist_set_listing_directory( $post_id, $default_directory );
            }
        }

        // ── Core meta ────────────────────────────────────────────────────────
        $source_name = $this->get_source_name( $source_url );
        $imported_at = time();

        update_post_meta( $post_id, DLI_META_SOURCE_URL, $source_url );
        update_post_meta( $post_id, DLI_META_SOURCE_NAME, $source_name );
        update_post_meta( $post_id, DLI_META_IMPORTED_AT, $imported_at );
        update_post_meta( $post_id, DLI_LEGACY_META_SOURCE_URL, $source_url );
        update_post_meta( $post_id, DLI_LEGACY_META_SOURCE_NAME, $source_name );
        update_post_meta( $post_id, DLI_LEGACY_META_IMPORTED_AT, $imported_at );

        // ── Price ─────────────────────────────────────────────────────────────
        // Craigslist uses the cl: namespace; also try a regex fallback on title
        $price = $this->extract_price( $item );
        if ( $price ) {
            update_post_meta( $post_id, '_price', $price );
            // Directorist standard price field (may vary by version)
            update_post_meta( $post_id, 'price', $price );
        }

        // ── Location / neighbourhood ─────────────────────────────────────────
        $location = $this->extract_location( $item );
        if ( $location ) {
            update_post_meta( $post_id, '_address', $location );
            update_post_meta( $post_id, 'address',  $location );
        }

        $text    = wp_strip_all_tags( $title . ' ' . $description );
        $phone   = $this->extract_phone( $text );
        $email   = $this->extract_email( $text );
        $website = $this->extract_website( $description, $source_url );

        if ( $phone ) {
            update_post_meta( $post_id, '_phone', $phone );
            update_post_meta( $post_id, 'phone', $phone );
        }

        if ( $email ) {
            update_post_meta( $post_id, '_email', $email );
            update_post_meta( $post_id, 'email', $email );
        }

        if ( $website ) {
            update_post_meta( $post_id, '_website', $website );
            update_post_meta( $post_id, 'website', $website );
        }

        // ── Assign Directorist category ──────────────────────────────────────
        if ( ! empty( $feed['category'] ) ) {
            wp_set_object_terms( $post_id, (int) $feed['category'], 'at_biz_dir-category' );
        }

        // ── Sideload thumbnail ───────────────────────────────────────────────
        $enclosure = $item->get_enclosure();
        if ( $enclosure ) {
            $image_url = $enclosure->get_link();
            if ( $image_url ) {
                $this->sideload_image( $image_url, $post_id, $title );
            }
        }

        do_action( 'directorist_listing_import_listing_created', $post_id, $item, $feed );

        return $post_id;
    }

    /**
     * Sideload an image from a remote URL and set it as the post thumbnail.
     *
     * @param string $url
     * @param int    $post_id
     * @param string $alt
     */
    private function sideload_image( string $url, int $post_id, string $alt = '' ): void {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_id = media_sideload_image( $url, $post_id, $alt, 'id' );

        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    /**
     * Extract price from a SimplePie item (Craigslist namespace + title fallback).
     *
     * @param \SimplePie_Item $item
     * @return string
     */
    private function extract_price( $item ): string {
        // Try Craigslist namespace
        $cl_tags = $item->get_item_tags( 'https://www.craigslist.org/about/legal/tos', 'price' );
        if ( ! empty( $cl_tags[0]['data'] ) ) {
            return sanitize_text_field( $cl_tags[0]['data'] );
        }

        // Fallback: regex on title  e.g. "$1,800" or "1800 USD"
        $title = $item->get_title() ?? '';
        if ( preg_match( '/\$[\d,]+/', $title, $matches ) ) {
            return $matches[0];
        }

        return '';
    }

    /**
     * Extract neighbourhood / location from a SimplePie item.
     *
     * @param \SimplePie_Item $item
     * @return string
     */
    private function extract_location( $item ): string {
        // Craigslist namespace
        $cl_tags = $item->get_item_tags( 'https://www.craigslist.org/about/legal/tos', 'neighborhood' );
        if ( ! empty( $cl_tags[0]['data'] ) ) {
            return sanitize_text_field( $cl_tags[0]['data'] );
        }

        $text = wp_strip_all_tags( ( $item->get_title() ?? '' ) . ' ' . ( $item->get_description() ?? '' ) );
        if ( preg_match( '/\b\d{1,6}\s+[A-Za-z0-9 .\-]+(?:street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct)\b[^\n,.]*/i', $text, $matches ) ) {
            return sanitize_text_field( $matches[0] );
        }

        return '';
    }

    private function extract_phone( string $text ): string {
        if ( preg_match( '/(?:\+?\d{1,3}[\s.\-]?)?(?:\(?\d{3}\)?[\s.\-]?)\d{3}[\s.\-]?\d{4}/', $text, $matches ) ) {
            return sanitize_text_field( $matches[0] );
        }

        return '';
    }

    private function extract_email( string $text ): string {
        if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches ) ) {
            return sanitize_email( $matches[0] );
        }

        return '';
    }

    private function extract_website( string $description, string $fallback ): string {
        if ( preg_match( '#https?://[^\s<>"\']+#i', wp_strip_all_tags( $description ), $matches ) ) {
            return esc_url_raw( $matches[0] );
        }

        return esc_url_raw( $fallback );
    }

    /**
     * Guess a human-readable source name from the feed URL.
     *
     * @param string $url
     * @return string
     */
    private function get_source_name( string $url ): string {
        $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
        if ( false !== strpos( $host, 'craigslist' ) )  return 'Craigslist';
        if ( false !== strpos( $host, 'kijiji' ) )      return 'Kijiji';
        if ( false !== strpos( $host, 'olx' ) )         return 'OLX';
        if ( false !== strpos( $host, 'gumtree' ) )     return 'Gumtree';
        return $host ?: 'Unknown';
    }

    /**
     * Append an entry to the plugin import log (capped at 200 entries).
     *
     * @param array  $feed
     * @param array  $result
     * @param string $error_msg
     */
    private function log( array $feed, array $result, string $error_msg = '' ): void {
        $logs   = (array) get_option( DLI_OPTION_LOGS, [] );
        $logs[] = [
            'time'      => time(),
            'feed_id'   => $feed['id']   ?? '',
            'feed_name' => $feed['name'] ?? '',
            'imported'  => $result['imported'],
            'skipped'   => $result['skipped'],
            'errors'    => $result['errors'],
            'error_msg' => $error_msg,
        ];

        // Keep only the latest 200 log entries
        if ( count( $logs ) > 200 ) {
            $logs = array_slice( $logs, -200 );
        }

        update_option( DLI_OPTION_LOGS, $logs );
    }
}
