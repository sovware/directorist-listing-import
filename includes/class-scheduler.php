<?php
/**
 * Scheduler — hooks WP-Cron to the import engine.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Directorist_Listing_Import_Scheduler {

    public static function init(): void {
        // Run all due feeds on the cron hook
        add_action( DLI_CRON_HOOK, [ __CLASS__, 'run_due_feeds' ] );

        // Allow manual "Run Now" trigger from admin
        add_action( 'admin_post_dli_run_feed_now', [ __CLASS__, 'handle_run_now' ] );
    }

    /**
     * Loop through feeds that are due and run each one.
     */
    public static function run_due_feeds(): void {
        $due_feeds = Directorist_Listing_Import_Feed_Manager::get_due_feeds();

        if ( empty( $due_feeds ) ) return;

        $importer = new Directorist_Listing_Import_Importer();

        foreach ( $due_feeds as $feed ) {
            $importer->run_feed( $feed );
        }
    }

    /**
     * Handle admin "Run Now" POST action.
     */
    public static function handle_run_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'directorist-listing-import' ) );
        }

        check_admin_referer( 'dli_run_now' );

        $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
        $feed    = Directorist_Listing_Import_Feed_Manager::get_feed( $feed_id );

        if ( ! $feed ) {
            wp_die( __( 'Feed not found.', 'directorist-listing-import' ) );
        }

        $importer = new Directorist_Listing_Import_Importer();
        $result   = $importer->run_feed( $feed );

        // Redirect back to admin page with a notice
        $redirect = add_query_arg( [
            'page'          => DLI_PAGE_SLUG,
            'tab'           => 'rss',
            'rss_tab'       => 'logs',
            'dli_notice'  => 'run_complete',
            'dli_imported'=> $result['imported'],
            'dli_skipped' => $result['skipped'],
            'dli_errors'  => $result['errors'],
        ], admin_url( 'edit.php?post_type=at_biz_dir' ) );

        wp_safe_redirect( $redirect );
        exit;
    }
}
