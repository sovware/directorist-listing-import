<?php
/**
 * Main plugin orchestrator.
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Singleton that boots all subsystems.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

	/** @var Admin_Page */
	public $admin_page;

	/** @var Importer */
	public $importer;

	/** @var Field_Mapping */
	public $field_mapping;

	/**
	 * Return (or create) the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/** Private constructor — use instance(). */
	private function __construct() {}

	/**
	 * Wire up all components.
	 */
	private function init(): void {
		$this->settings      = new Settings();
		$this->field_mapping = new Field_Mapping();
		$this->importer      = new Importer( new Google_Places_Client(), new Review_Mapper(), $this->field_mapping );
		$this->admin_page    = new Admin_Page( $this->settings, $this->importer, $this->field_mapping );
		new Ajax_Import( $this->settings, $this->importer );

		add_action( 'admin_init', [ $this, 'maybe_repair_listings' ] );
	}

	/**
	 * One-time repair: fix listings imported before v1.1 that are missing the
	 * atbdp_listing_types taxonomy term, _never_expire, and _featured meta.
	 *
	 * Runs once (guarded by an option flag) so it never fires again after the
	 * first successful pass.
	 */
	public function maybe_repair_listings(): void {
		if ( get_option( 'dgbi_repair_v1_1_done' ) ) {
			return;
		}

		if ( ! function_exists( 'directorist_set_listing_directory' ) || ! function_exists( 'directorist_get_default_directory' ) ) {
			return;
		}

		$default_dir = (int) directorist_get_default_directory();
		if ( ! $default_dir ) {
			return;
		}

		$taxonomy = defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types';

		// Find all listings we created (have _google_place_id set).
		$ids = get_posts( [
			'post_type'      => 'at_biz_dir',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_google_place_id',
		] );

		foreach ( $ids as $post_id ) {
			// Fix missing taxonomy term.
			$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				$dir = (int) get_post_meta( $post_id, '_directory_type', true );
				directorist_set_listing_directory( $post_id, $dir > 0 ? $dir : $default_dir );
			}

			// Fix missing required meta.
			if ( '' === get_post_meta( $post_id, '_never_expire', true ) ) {
				update_post_meta( $post_id, '_never_expire', 1 );
			}
			if ( '' === get_post_meta( $post_id, '_featured', true ) ) {
				update_post_meta( $post_id, '_featured', 0 );
			}
		}

		update_option( 'dgbi_repair_v1_1_done', 1, false );
	}
}
