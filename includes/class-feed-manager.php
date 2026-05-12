<?php
/**
 * Feed Manager — CRUD operations for stored RSS feed configurations.
 *
 * Each feed is stored as an associative array inside wp_options:
 * [
 *   'id'        => 'unique-uuid',
 *   'name'      => 'NYC Real Estate',
 *   'url'       => 'https://newyork.craigslist.org/search/rea?format=rss',
 *   'source_url'=> 'https://newyork.craigslist.org/search/rea',
 *   'directory_type' => 12,     // Directory type term ID (0 = default)
 *   'category'  => 42,          // Directorist category term ID (0 = uncategorised)
 *   'interval'  => 'daily',     // manual | hourly | twicedaily | daily
 *   'sync_mode' => 'sync',      // one_time | sync
 *   'status'    => 'active',    // active | paused
 *   'last_run'  => 0,           // Unix timestamp
 *   'added'     => 0,           // Unix timestamp
 * ]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Directorist_Listing_Import_Feed_Manager {

    /**
     * Return all saved feeds.
     *
     * @return array
     */
    public static function get_feeds(): array {
        return (array) get_option( DLI_OPTION_FEEDS, [] );
    }

    /**
     * Return a single feed by ID.
     *
     * @param string $id
     * @return array|null
     */
    public static function get_feed( string $id ): ?array {
        foreach ( self::get_feeds() as $feed ) {
            if ( isset( $feed['id'] ) && $feed['id'] === $id ) {
                return $feed;
            }
        }
        return null;
    }

    /**
     * Add a new feed.
     *
     * @param array $args  Keys: name, url, category, interval
     * @return string  New feed ID
     */
    public static function add_feed( array $args ): string {
        $feeds = self::get_feeds();

        $feed = [
            'id'       => self::generate_id(),
            'name'     => sanitize_text_field( $args['name'] ?? '' ),
            'url'      => esc_url_raw( $args['url'] ?? '' ),
            'source_url' => esc_url_raw( $args['source_url'] ?? ( $args['url'] ?? '' ) ),
            'directory_type' => absint( $args['directory_type'] ?? 0 ),
            'category' => absint( $args['category'] ?? 0 ),
            'interval' => in_array( $args['interval'] ?? '', [ 'manual', 'hourly', 'twicedaily', 'daily' ], true )
                              ? $args['interval']
                              : 'daily',
            'sync_mode' => in_array( $args['sync_mode'] ?? '', [ 'one_time', 'sync' ], true ) ? $args['sync_mode'] : 'sync',
            'status'   => in_array( $args['status'] ?? '', [ 'active', 'paused' ], true ) ? $args['status'] : 'active',
            'last_run' => 0,
            'added'    => time(),
        ];

        $feeds[] = $feed;
        update_option( DLI_OPTION_FEEDS, $feeds );

        return $feed['id'];
    }

    /**
     * Update specific fields on an existing feed.
     *
     * @param string $id
     * @param array  $args
     * @return bool
     */
    public static function update_feed( string $id, array $args ): bool {
        $feeds = self::get_feeds();
        $found = false;

        foreach ( $feeds as &$feed ) {
            if ( $feed['id'] !== $id ) continue;

            if ( isset( $args['name'] ) )     $feed['name']     = sanitize_text_field( $args['name'] );
            if ( isset( $args['url'] ) )      $feed['url']      = esc_url_raw( $args['url'] );
            if ( isset( $args['source_url'] ) ) $feed['source_url'] = esc_url_raw( $args['source_url'] );
            if ( isset( $args['directory_type'] ) ) $feed['directory_type'] = absint( $args['directory_type'] );
            if ( isset( $args['category'] ) ) $feed['category'] = absint( $args['category'] );
            if ( isset( $args['interval'] ) && in_array( $args['interval'], [ 'manual', 'hourly', 'twicedaily', 'daily' ], true ) ) {
                $feed['interval'] = $args['interval'];
            }
            if ( isset( $args['sync_mode'] ) && in_array( $args['sync_mode'], [ 'one_time', 'sync' ], true ) ) {
                $feed['sync_mode'] = $args['sync_mode'];
            }
            if ( isset( $args['status'] ) && in_array( $args['status'], [ 'active', 'paused' ], true ) ) {
                $feed['status'] = $args['status'];
            }
            if ( isset( $args['last_run'] ) ) $feed['last_run'] = absint( $args['last_run'] );

            $found = true;
            break;
        }
        unset( $feed );

        if ( $found ) {
            update_option( DLI_OPTION_FEEDS, $feeds );
        }

        return $found;
    }

    /**
     * Delete a feed by ID.
     *
     * @param string $id
     * @return bool
     */
    public static function delete_feed( string $id ): bool {
        $feeds   = self::get_feeds();
        $initial = count( $feeds );
        $feeds   = array_values( array_filter( $feeds, fn( $f ) => $f['id'] !== $id ) );

        if ( count( $feeds ) === $initial ) return false;

        update_option( DLI_OPTION_FEEDS, $feeds );
        return true;
    }

    /**
     * Return feeds that are due to run (status=active and past their interval).
     *
     * @return array
     */
    public static function get_due_feeds(): array {
        $interval_seconds = [
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
        ];

        $due = [];
        foreach ( self::get_feeds() as $feed ) {
            if ( ( $feed['status'] ?? '' ) !== 'active' ) continue;
            if ( ( $feed['sync_mode'] ?? 'sync' ) === 'one_time' || ( $feed['interval'] ?? '' ) === 'manual' ) continue;

            $interval = $interval_seconds[ $feed['interval'] ?? 'daily' ] ?? DAY_IN_SECONDS;
            if ( ( time() - (int) $feed['last_run'] ) >= $interval ) {
                $due[] = $feed;
            }
        }

        return $due;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function generate_id(): string {
        return wp_generate_uuid4();
    }
}
