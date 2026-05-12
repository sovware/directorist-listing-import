<?php
/**
 * AJAX import handler.
 *
 * Breaks the import into three wp_ajax_ actions so each PHP request handles
 * only one unit of work and never approaches the host's max_execution_time:
 *
 *   dlig_start_import  — validate, geocode, search → returns place list + queue ID.
 *   dlig_import_place  — fetch details + create one listing → returns per-place result.
 *   dlig_finish_import — log the completed run + clean up the transient queue.
 *
 * The queue (search results + import settings) is stored in a transient so
 * each dlig_import_place call only needs the queue ID and a place index.
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ajax_Import
 */
class Ajax_Import {

	/** Transient lifetime — long enough for a full 60-place import at ~3 s/place. */
	const QUEUE_TTL = 15 * MINUTE_IN_SECONDS;

	/** @var Settings */
	private $settings;

	/** @var Importer */
	private $importer;

	/**
	 * @param Settings $settings
	 * @param Importer $importer
	 */
	public function __construct( Settings $settings, Importer $importer ) {
		$this->settings = $settings;
		$this->importer = $importer;

		add_action( 'wp_ajax_dlig_start_import',  [ $this, 'handle_start' ] );
		add_action( 'wp_ajax_dlig_import_place',  [ $this, 'handle_place' ] );
		add_action( 'wp_ajax_dlig_finish_import', [ $this, 'handle_finish' ] );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * Validate the request, run the search, store the queue, return the place list.
	 */
	public function handle_start(): void {
		$this->verify( 'dlig_ajax' );

		// Rate limiting: max 100 import runs per hour per user.
		$user_id       = get_current_user_id();
		$rate_key      = 'dgbi_import_count_' . $user_id;
		$import_count  = (int) get_transient( $rate_key );

		if ( $import_count >= 100 ) {
			wp_send_json_error( [
				'message' => __( 'You have reached the limit of 100 imports per hour. Please wait before running another import.', 'directorist-listing-import' ),
			] );
		}

		set_transient( $rate_key, $import_count + 1, HOUR_IN_SECONDS );

		$api_key = $this->settings->get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( [
				'message' => __( 'No Google API key configured. Add your key in the Settings tab.', 'directorist-listing-import' ),
			] );
		}

		$keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		if ( empty( $keyword ) ) {
			wp_send_json_error( [ 'message' => __( 'Keyword is required.', 'directorist-listing-import' ) ] );
		}

		$post_status = sanitize_text_field( wp_unslash( $_POST['post_status'] ?? 'pending' ) );
		if ( ! in_array( $post_status, [ 'draft', 'pending', 'publish' ], true ) ) {
			$post_status = 'pending';
		}

		// Run the search (geocode + Places API call).
		$search = $this->importer->search_only( [
			'keyword'     => $keyword,
			'location'    => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
			'radius'      => max( 0, min( 50000, intval( $_POST['radius'] ?? 5000 ) ) ),
			'api_key'     => $api_key,
			'max_results' => max( 1, min( 60, intval( $_POST['max_results'] ?? 20 ) ) ),
		] );

		if ( ! empty( $search['error'] ) ) {
			wp_send_json_error( [ 'message' => $search['error'] ] );
		}

		$places = $search['places'] ?? [];

		// Store queue: places + import settings (api_key stays server-side).
		$queue_id = 'dgbi_q_' . $user_id . '_' . wp_rand();
		set_transient(
			$queue_id,
			[
				'places'     => $places,
				'args'       => [
					'api_key'         => $api_key,
					'category_id'     => intval( $_POST['category_id']    ?? 0 ),
					'location_id'     => intval( $_POST['location_id']    ?? 0 ),
					'directory_type'  => intval( $_POST['directory_type'] ?? 0 ),
					'post_status'     => $post_status,
					'import_reviews'  => ! empty( $_POST['import_reviews'] ),
					'import_photos'   => ! empty( $_POST['import_photos'] ),
					'update_existing' => ! empty( $_POST['update_existing'] ),
				],
				'meta'       => [
					'keyword'     => $keyword,
					'location'    => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
					'post_status' => $post_status,
					'started_at'  => current_time( 'mysql' ),
					'user_id'     => $user_id,
				],
			],
			self::QUEUE_TTL
		);

		// Return place_id + name + is_duplicate to the browser (no internal API details).
		// is_duplicate lets the JS pre-deselect already-imported places in the preview list.
		$importer   = $this->importer;
		$place_list = array_map( function ( $p ) use ( $importer ) {
			return [
				'place_id'     => $p['place_id'],
				'name'         => $p['name'],
				'is_duplicate' => $importer->listing_exists( $p['place_id'] ),
			];
		}, $places );

		wp_send_json_success( [
			'queue_id' => $queue_id,
			'total'    => count( $places ),
			'places'   => $place_list,
		] );
	}

	/**
	 * Process a single place: fetch details, create listing, assign terms.
	 */
	public function handle_place(): void {
		$this->verify( 'dlig_ajax' );

		$queue_id = sanitize_key( $_POST['queue_id'] ?? '' );
		$index    = intval( $_POST['place_index'] ?? -1 );

		$queue = get_transient( $queue_id );

		if ( ! $queue ) {
			wp_send_json_error( [
				'message' => __( 'Import queue expired or not found. Please start a new import.', 'directorist-listing-import' ),
			] );
		}

		if ( ! isset( $queue['places'][ $index ] ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %d: place index */
					__( 'Invalid place index %d.', 'directorist-listing-import' ),
					$index
				),
			] );
		}

		$result = $this->importer->import_single_place(
			$queue['places'][ $index ],
			$queue['args']
		);

		wp_send_json_success( $result );
	}

	/**
	 * Log the completed run and clean up the transient.
	 */
	public function handle_finish(): void {
		$this->verify( 'dlig_ajax' );

		$queue_id = sanitize_key( $_POST['queue_id'] ?? '' );
		$queue    = get_transient( $queue_id );

		if ( $queue ) {
			$errors_raw = json_decode( stripslashes( $_POST['errors'] ?? '[]' ), true );
			$errors     = is_array( $errors_raw )
				? array_map( 'sanitize_text_field', $errors_raw )
				: [];

			$history = get_option( 'dgbi_import_history', [] );
			array_unshift( $history, [
				'time'            => $queue['meta']['started_at'] ?? current_time( 'mysql' ),
				'user_id'         => $queue['meta']['user_id']    ?? get_current_user_id(),
				'keyword'         => $queue['meta']['keyword']    ?? '',
				'location'        => $queue['meta']['location']   ?? '',
				'post_status'     => $queue['meta']['post_status'] ?? '',
				'imported'        => intval( $_POST['imported']        ?? 0 ),
				'updated'         => intval( $_POST['updated']          ?? 0 ),
				'skipped'         => intval( $_POST['skipped']         ?? 0 ),
				'reviews'         => intval( $_POST['reviews']         ?? 0 ),
				'reviews_created' => intval( $_POST['reviews_created'] ?? 0 ),
				'errors'          => $errors,
			] );
			update_option( 'dgbi_import_history', array_slice( $history, 0, 50 ), 'no' );

			delete_transient( $queue_id );
		}

		wp_send_json_success( [ 'done' => true ] );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Verify nonce + capability, die on failure.
	 *
	 * @param string $action Nonce action.
	 */
	private function verify( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'directorist-listing-import' ) ], 403 );
		}
		check_ajax_referer( $action, 'nonce' );
	}
}
