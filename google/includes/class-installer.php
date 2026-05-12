<?php
/**
 * Activation / deactivation routines.
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Installer
 */
class Installer {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		// Ensure Directorist is active; abort activation gracefully if not.
		if ( ! defined( 'ATBDP_VERSION' ) ) {
			deactivate_plugins( plugin_basename( DLIG_FILE ) );
			wp_die(
				esc_html__(
					'Listing Importer requires Directorist to be installed and active.',
					'directorist-listing-import'
				)
			);
		}

		// Set default options (only if not already set).
		add_option( 'dgbi_api_key',        '',         '', 'no' );
		add_option( 'dgbi_default_status', 'pending',  '', 'no' );
		add_option( 'dgbi_import_reviews', '1',        '', 'no' );
		add_option( 'dgbi_max_results',    '20',       '', 'no' );
		add_option( 'dgbi_field_map',      [],         '', 'no' );
		// History stored with autoload off to avoid front-end overhead.
		add_option( 'dgbi_import_history', [],         '', 'no' );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		// Nothing needed on deactivation; data cleanup is in uninstall.php.
	}
}
