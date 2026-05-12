<?php
/**
 * Plugin Name:       Listing Importer
 * Plugin URI:        https://directorist.com/
 * Description:       Import Directorist listings from Google Business Profile data and legal RSS/feed sources. Use Directorist core for CSV/spreadsheet imports.
 * Version:           1.0.0
 * Author:            wpwax
 * Author URI:        https://wpwax.com/
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       directorist-listing-import
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ────────────────────────────────────────────────────────────────
define( 'DLI_VERSION',   '1.0.0' );
define( 'DLI_FILE',      __FILE__ );
define( 'DLI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DLI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DLI_TEXT_DOMAIN', 'directorist-listing-import' );
define( 'DLI_PAGE_SLUG', 'directorist-listing-import' );
define( 'DLI_OPTION_FEEDS', 'directorist_listing_import_feeds' );
define( 'DLI_OPTION_LOGS',  'directorist_listing_import_logs' );
define( 'DLI_OPTION_SETTINGS', 'directorist_listing_import_settings' );
define( 'DLI_LEGACY_OPTION_FEEDS', 'directorsync_feeds' );
define( 'DLI_LEGACY_OPTION_LOGS', 'directorsync_logs' );
define( 'DLI_LEGACY_OPTION_SETTINGS', 'directorsync_settings' );
define( 'DLI_CRON_HOOK', 'directorist_listing_import_run_feeds' );
define( 'DLI_LEGACY_CRON_HOOK', 'directorsync_run_feeds' );

define( 'DLIG_VERSION', '2.0.0' );
define( 'DLIG_FILE', DLI_FILE );
define( 'DLIG_DIR', DLI_PLUGIN_DIR . 'google/' );
define( 'DLIG_URL', DLI_PLUGIN_URL . 'google/' );
define( 'DLIG_TEXT_DOMAIN', DLI_TEXT_DOMAIN );

// ── Autoload classes ──────────────────────────────────────────────────────────
require_once DLI_PLUGIN_DIR . 'includes/class-feed-manager.php';
require_once DLI_PLUGIN_DIR . 'includes/class-feed-discovery.php';
require_once DLI_PLUGIN_DIR . 'includes/class-importer.php';
require_once DLI_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once DLI_PLUGIN_DIR . 'admin/class-admin-page.php';

require_once DLIG_DIR . 'includes/class-settings.php';
require_once DLIG_DIR . 'includes/class-field-mapping.php';
require_once DLIG_DIR . 'includes/class-installer.php';
require_once DLIG_DIR . 'includes/class-google-places-client.php';
require_once DLIG_DIR . 'includes/class-review-mapper.php';
require_once DLIG_DIR . 'includes/class-importer.php';
require_once DLIG_DIR . 'includes/class-admin-page.php';
require_once DLIG_DIR . 'includes/class-ajax-import.php';
require_once DLIG_DIR . 'includes/class-plugin.php';

// ── Bootstrap ────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'directorist_listing_import_init' );
function directorist_listing_import_init() {
    load_plugin_textdomain(
        DLI_TEXT_DOMAIN,
        false,
        dirname( plugin_basename( DLI_FILE ) ) . '/languages'
    );

    if ( ! defined( 'ATBDP_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'Listing Importer requires Directorist to be installed and activated.', 'directorist-listing-import' );
            echo '</p></div>';
        } );
        return;
    }

    directorist_listing_import_migrate_legacy_options();
    \DLIG\Plugin::instance();
    Directorist_Listing_Import_Scheduler::init();
    Directorist_Listing_Import_Admin_Page::init();
}

function directorist_listing_import_migrate_legacy_options(): void {
    $option_pairs = [
        DLI_LEGACY_OPTION_SETTINGS => DLI_OPTION_SETTINGS,
        DLI_LEGACY_OPTION_FEEDS    => DLI_OPTION_FEEDS,
        DLI_LEGACY_OPTION_LOGS     => DLI_OPTION_LOGS,
    ];

    foreach ( $option_pairs as $legacy_option => $new_option ) {
        if ( false !== get_option( $new_option, false ) ) {
            continue;
        }

        $legacy_value = get_option( $legacy_option, false );
        if ( false !== $legacy_value ) {
            update_option( $new_option, $legacy_value );
        }
    }
}

// ── Activation ───────────────────────────────────────────────────────────────
register_activation_hook( DLI_FILE, 'directorist_listing_import_activate' );
function directorist_listing_import_activate() {
    directorist_listing_import_migrate_legacy_options();

    if ( class_exists( '\DLIG\Installer' ) ) {
        \DLIG\Installer::activate();
    }

    // Seed default settings
    if ( ! get_option( DLI_OPTION_SETTINGS ) ) {
        update_option( DLI_OPTION_SETTINGS, [
            'default_status'   => 'pending',   // pending | publish
            'batch_size'       => 25,
            'default_interval' => 'daily',
        ] );
    }

    // Seed empty feeds list
    if ( ! get_option( DLI_OPTION_FEEDS ) ) {
        update_option( DLI_OPTION_FEEDS, [] );
    }

    // Seed empty log
    if ( ! get_option( DLI_OPTION_LOGS ) ) {
        update_option( DLI_OPTION_LOGS, [] );
    }

    // Schedule the cron event
    $legacy_timestamp = wp_next_scheduled( DLI_LEGACY_CRON_HOOK );
    if ( $legacy_timestamp ) {
        wp_unschedule_event( $legacy_timestamp, DLI_LEGACY_CRON_HOOK );
    }

    if ( ! wp_next_scheduled( DLI_CRON_HOOK ) ) {
        wp_schedule_event( time(), 'hourly', DLI_CRON_HOOK );
    }
}

// ── Deactivation ─────────────────────────────────────────────────────────────
register_deactivation_hook( DLI_FILE, 'directorist_listing_import_deactivate' );
function directorist_listing_import_deactivate() {
    foreach ( [ DLI_CRON_HOOK, DLI_LEGACY_CRON_HOOK ] as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }
}
