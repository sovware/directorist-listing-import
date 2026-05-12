<?php
/**
 * Core import orchestrator.
 *
 * Public surface:
 *   run()                 — full synchronous import (batch, keeps backward compat).
 *   search_only()         — step 1 of the AJAX flow: geocode + search, return place list.
 *   import_single_place() — step 2 of the AJAX flow: detail fetch + listing creation for one place.
 *   listing_exists()      — duplicate check used by Ajax_Import to flag preview results.
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Importer
 */
class Importer {

	/** @var Google_Places_Client */
	private $client;

	/** @var Review_Mapper */
	private $review_mapper;

	/** @var Field_Mapping */
	private $field_mapping;

	/**
	 * @param Google_Places_Client $client
	 * @param Review_Mapper        $review_mapper
	 * @param Field_Mapping        $field_mapping
	 */
	public function __construct( Google_Places_Client $client, Review_Mapper $review_mapper, Field_Mapping $field_mapping ) {
		$this->client        = $client;
		$this->review_mapper = $review_mapper;
		$this->field_mapping = $field_mapping;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Run a full synchronous import (search + create all listings in one request).
	 * Used by the legacy form path and unit tests.
	 * The AJAX path calls search_only() + import_single_place() separately.
	 *
	 * @param array $args {
	 *   @type string $keyword          Search keyword.
	 *   @type string $location         Location string.
	 *   @type int    $radius           Search radius in metres.
	 *   @type string $api_key          Google Places API key.
	 *   @type int    $category_id      Directorist category term ID (0 = none).
	 *   @type int    $location_id      Directorist location term ID (0 = none).
	 *   @type int    $directory_type   Directorist directory type term ID (0 = default).
	 *   @type string $post_status      Post status for new listings.
	 *   @type bool   $import_reviews   Whether to import Google reviews.
	 *   @type bool   $import_photos    Whether to sideload the first Google photo.
	 *   @type bool   $update_existing  Whether to refresh already-imported listings.
	 *   @type int    $max_results      Max listings to import (1–60).
	 * }
	 * @return array{imported:int,updated:int,skipped:int,errors:string[],reviews:int,reviews_created:int,descriptions:int}
	 */
	public function run( array $args ): array {
		$summary = [
			'imported'        => 0,
			'updated'         => 0,
			'skipped'         => 0,
			'errors'          => [],
			'reviews'         => 0,
			'reviews_created' => 0,
			'descriptions'    => 0,
		];

		$search = $this->search_only( $args );

		if ( ! empty( $search['error'] ) ) {
			$summary['errors'][] = $search['error'];
			return $summary;
		}

		if ( empty( $search['places'] ) ) {
			return $summary;
		}

		$import_args = [
			'api_key'         => $args['api_key'] ?? '',
			'category_id'     => intval( $args['category_id'] ?? 0 ),
			'location_id'     => intval( $args['location_id'] ?? 0 ),
			'directory_type'  => intval( $args['directory_type'] ?? 0 ),
			'post_status'     => sanitize_text_field( $args['post_status'] ?? 'pending' ),
			'import_reviews'  => (bool) ( $args['import_reviews'] ?? true ),
			'import_photos'   => (bool) ( $args['import_photos'] ?? false ),
			'update_existing' => (bool) ( $args['update_existing'] ?? false ),
		];

		foreach ( $search['places'] as $place ) {
			$result = $this->import_single_place( $place, $import_args );

			$summary['imported']        += $result['imported'];
			$summary['updated']         += $result['updated'];
			$summary['skipped']         += $result['skipped'];
			$summary['reviews']         += $result['reviews'];
			$summary['reviews_created'] += $result['reviews_created'];
			$summary['descriptions']    += $result['descriptions'];

			if ( ! empty( $result['errors'] ) ) {
				$summary['errors'] = array_merge( $summary['errors'], $result['errors'] );
			}
		}

		return $summary;
	}

	/**
	 * Step 1 of the AJAX import flow: geocode + search, return the place list.
	 * Does NOT create any listings.
	 *
	 * @param array $args Same keys as run(): keyword, location, radius, api_key, max_results.
	 * @return array { places: array, error: string }
	 */
	public function search_only( array $args ): array {
		$keyword     = sanitize_text_field( $args['keyword'] ?? '' );
		$location    = sanitize_text_field( $args['location'] ?? '' );
		$radius      = max( 0, min( 50000, intval( $args['radius'] ?? 5000 ) ) );
		$api_key     = $args['api_key'] ?? '';
		$max_results = max( 1, min( 60, intval( $args['max_results'] ?? 20 ) ) );

		if ( empty( $keyword ) || empty( $api_key ) ) {
			return [
				'places' => [],
				'error'  => __( 'Keyword and API key are required.', 'directorist-listing-import' ),
			];
		}

		$this->client->set_api_key( $api_key );

		$query = $location ? "{$keyword} in {$location}" : $keyword;

		// Geocode the location to get a valid centre for locationBias.
		// Falls back to text-only search if geocoding fails or no location is given.
		$lat = 0.0;
		$lng = 0.0;
		if ( $location && $radius > 0 ) {
			$coords = $this->client->geocode( $location );
			$lat    = $coords['lat'] ?? 0.0;
			$lng    = $coords['lng'] ?? 0.0;
		}

		return $this->client->search( $query, $radius, $max_results, $lat, $lng );
	}

	/**
	 * Step 2 of the AJAX import flow: fetch details and create (or update) one listing.
	 *
	 * @param array $place  Normalised search result from search_only() / Google_Places_Client.
	 * @param array $args {
	 *   @type string $api_key         Google Places API key.
	 *   @type int    $category_id     Directorist category term ID (0 = none).
	 *   @type int    $location_id     Directorist location term ID (0 = none).
	 *   @type int    $directory_type  Directory type term ID (0 = default).
	 *   @type string $post_status     Post status.
	 *   @type bool   $import_reviews  Whether to import reviews.
	 *   @type bool   $import_photos   Whether to sideload the first Google photo.
	 *   @type bool   $update_existing Whether to refresh an already-imported listing.
	 * }
	 * @return array { imported, updated, skipped, errors, reviews, reviews_created, descriptions, place_name }
	 */
	public function import_single_place( array $place, array $args ): array {
		$result = [
			'imported'        => 0,
			'updated'         => 0,
			'skipped'         => 0,
			'errors'          => [],
			'reviews'         => 0,
			'reviews_created' => 0,
			'descriptions'    => 0,
			'place_name'      => sanitize_text_field( $place['name'] ?? '' ),
		];

		if ( empty( $place['place_id'] ) ) {
			$result['errors'][] = __( 'Skipped: missing place ID.', 'directorist-listing-import' );
			return $result;
		}

		// Ensure the client has the key set for the detail call.
		if ( ! empty( $args['api_key'] ) ) {
			$this->client->set_api_key( $args['api_key'] );
		}

		$category_id     = intval( $args['category_id'] ?? 0 );
		$location_id     = intval( $args['location_id'] ?? 0 );
		$directory_type  = intval( $args['directory_type'] ?? 0 );
		$post_status     = sanitize_text_field( $args['post_status'] ?? 'pending' );
		$import_reviews  = (bool) ( $args['import_reviews'] ?? false );
		$import_photos   = (bool) ( $args['import_photos'] ?? false );
		$update_existing = (bool) ( $args['update_existing'] ?? false );

			$existing_id = $this->get_existing_listing_id( $place['place_id'] );

			// ── Update existing listing ───────────────────────────────────────────
			if ( $existing_id ) {
				if ( ! $update_existing ) {
					$result['skipped']++;
					return $result;
				}

				$details      = $this->client->get_details( $place['place_id'] );
				$details      = is_array( $details ) ? $details : [];
				$directory_id = $this->resolve_listing_directory( $existing_id, $directory_type );
				$applied      = $this->apply_google_source_data( $existing_id, $this->merge_source_data( $place, $details ), $directory_id );

				if ( in_array( 'editorial_summary', $applied, true ) ) {
					$result['descriptions']++;
				}

				if ( $import_photos && ! empty( $details['photo_name'] ) ) {
					$this->sync_directorist_preview_image( $existing_id );

					if ( ! $this->has_directorist_preview_image( $existing_id ) ) {
						$this->sideload_featured_image( $existing_id, $details['photo_name'], $result['place_name'] );
					}
				}

				if ( $import_reviews && ! empty( $details['reviews'] ) ) {
					$created = $this->import_reviews( $existing_id, $details['reviews'] );
					$result['reviews']         += count( $details['reviews'] );
					$result['reviews_created'] += $created;
					if ( $created > 0 ) {
						$this->restore_google_rating( $existing_id, $details );
					}
				}

				do_action( 'dgbi_after_listing_updated', $existing_id, $place, $details ?? [] );

				$result['updated']++;
				return $result;
			}

		// ── Create new listing ────────────────────────────────────────────────
		$post_id = $this->create_listing( $place, $post_status, $directory_type );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			/* translators: %s: Google place ID */
			$result['errors'][] = sprintf(
				__( 'Failed to create listing for place %s.', 'directorist-listing-import' ),
				esc_html( $place['place_id'] )
			);
			return $result;
		}

		if ( $category_id > 0 ) {
			wp_set_object_terms( $post_id, $category_id, 'at_biz_dir-category' );
		}
		if ( $location_id > 0 ) {
			wp_set_object_terms( $post_id, $location_id, 'at_biz_dir-location' );
		}

			$details      = $this->client->get_details( $place['place_id'] );
			$details      = is_array( $details ) ? $details : [];
			$directory_id = $this->resolve_listing_directory( $post_id, $directory_type );
			$applied      = $this->apply_google_source_data( $post_id, $this->merge_source_data( $place, $details ), $directory_id );

			if ( in_array( 'editorial_summary', $applied, true ) ) {
				$result['descriptions']++;
			}

			if ( $import_photos && ! empty( $details['photo_name'] ) ) {
				$this->sideload_featured_image( $post_id, $details['photo_name'], $result['place_name'] );
			}

			if ( $import_reviews && ! empty( $details['reviews'] ) ) {
				$created = $this->import_reviews( $post_id, $details['reviews'] );
				$result['reviews']         += count( $details['reviews'] );
				$result['reviews_created'] += $created;

				if ( $created > 0 ) {
					$this->restore_google_rating( $post_id, $details );
				}
			}

		/**
		 * Fires after a listing is successfully imported.
		 *
		 * @param int   $post_id Newly created post ID.
		 * @param array $place   Normalised place search result.
		 * @param array $details Normalised place detail result (empty array if unavailable).
		 */
		do_action( 'dgbi_after_listing_imported', $post_id, $place, $details ?? [] );

		$result['imported']++;
		return $result;
	}

	/**
	 * Check whether a listing with this Google place ID already exists.
	 * Public so Ajax_Import can flag duplicates in the preview response.
	 */
	public function listing_exists( string $place_id ): bool {
		return $this->get_existing_listing_id( $place_id ) > 0;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return the post ID of an existing listing for the given place_id, or 0.
	 */
	private function get_existing_listing_id( string $place_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_google_place_id'
				 AND meta_value = %s
				 LIMIT 1",
				$place_id
			)
		);
	}

	/**
	 * Create a new Directorist listing post.
	 *
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	private function create_listing( array $place, string $post_status, int $dir_type ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => sanitize_text_field( $place['name'] ?? '' ),
				'post_type'   => 'at_biz_dir',
				'post_status' => $post_status,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_google_place_id', sanitize_text_field( $place['place_id'] ) );

		// Required by Directorist so the listing is not treated as expired.
		update_post_meta( $post_id, '_never_expire', 1 );
		// Required by Directorist's featured-listing query logic.
		update_post_meta( $post_id, '_featured', 0 );

		// directorist_set_listing_directory() writes both _directory_type post meta
		// AND the atbdp_listing_types taxonomy term — both required for front-end queries.
		$resolved_dir = $this->field_mapping->resolve_directory_id( $dir_type );
		if ( $resolved_dir > 0 && function_exists( 'directorist_set_listing_directory' ) ) {
			directorist_set_listing_directory( $post_id, $resolved_dir );
		}

		return $post_id;
	}

	/**
	 * Resolve which directory type should drive mapped field writes.
	 */
	private function resolve_listing_directory( int $post_id, int $fallback_directory ): int {
		$current_directory = (int) get_post_meta( $post_id, '_directory_type', true );
		return $this->field_mapping->resolve_directory_id( $current_directory ?: $fallback_directory );
	}

	/**
	 * Apply mapped Google data plus system-only side effects.
	 *
	 * @return string[] Source keys successfully applied by the mapping layer.
	 */
	private function apply_google_source_data( int $post_id, array $source_data, int $directory_id ): array {
		$applied = $this->field_mapping->apply_mapped_data( $post_id, $source_data, $directory_id );

		if ( ! empty( $source_data['opening_hours'] ) ) {
			update_post_meta( $post_id, '_opening_hours', wp_json_encode( $source_data['opening_hours'] ) );
			$this->map_opening_hours( $post_id, $source_data['opening_hours'] );
		}

		return $applied;
	}

	/**
	 * Merge search data with place details without overwriting useful values
	 * with empty detail responses.
	 */
	private function merge_source_data( array $search_data, array $detail_data ): array {
		$merged = $search_data;

		foreach ( $detail_data as $key => $value ) {
			if ( is_array( $value ) && empty( $value ) ) {
				continue;
			}

			if ( null === $value || '' === $value ) {
				continue;
			}

			$merged[ $key ] = $value;
		}

		return $merged;
	}

	/**
	 * Fetch a Google photo URL and sideload it as the listing's featured image.
	 * Skips silently on any failure — photo import is best-effort.
	 */
	private function sideload_featured_image( int $post_id, string $photo_name, string $title ): void {
		$photo_url = $this->client->get_photo_media_url( $photo_name );
		if ( empty( $photo_url ) ) {
			$this->log_photo_import_failure( 'empty_photo_url', $post_id );
			return;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$attachment_id = $this->sideload_image_from_url( $photo_url, $post_id, $title );
		if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
			$this->set_directorist_preview_image( $post_id, $attachment_id );
			return;
		}

		$this->log_photo_import_failure(
			is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'unknown_sideload_failure',
			$post_id
		);
	}

	/**
	 * Sideload an image URL using a controlled filename.
	 *
	 * Google photo CDN URLs often do not expose a conventional image extension.
	 * media_sideload_image() validates extensions from the URL, so use the lower
	 * level download + media_handle_sideload flow and provide a stable .jpg name.
	 *
	 * @return int|\WP_Error Attachment ID on success.
	 */
	private function sideload_image_from_url( string $photo_url, int $post_id, string $title ) {
		$temp_file = download_url( $photo_url, 30 );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$file = [
			'name'     => sanitize_file_name( sanitize_title( $title ?: 'google-place-photo' ) . '.jpg' ),
			'type'     => 'image/jpeg',
			'tmp_name' => $temp_file,
			'error'    => 0,
			'size'     => filesize( $temp_file ),
		];

		$attachment_id = media_handle_sideload( $file, $post_id, sanitize_text_field( $title ) );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
		}

		return $attachment_id;
	}

	/**
	 * Check Directorist's own preview image meta.
	 */
	private function has_directorist_preview_image( int $post_id ): bool {
		return (int) get_post_meta( $post_id, '_listing_prv_img', true ) > 0;
	}

	/**
	 * Keep Directorist preview image meta in sync with the WP featured image.
	 *
	 * Directorist archive/single templates read _listing_prv_img instead of only
	 * _thumbnail_id, so set both when Google photos are imported.
	 */
	private function set_directorist_preview_image( int $post_id, int $attachment_id ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		update_post_meta( $post_id, '_listing_prv_img', $attachment_id );
	}

	/**
	 * Repair imported listings that already have _thumbnail_id but no
	 * Directorist preview image meta.
	 */
	private function sync_directorist_preview_image( int $post_id ): void {
		if ( $this->has_directorist_preview_image( $post_id ) || ! has_post_thumbnail( $post_id ) ) {
			return;
		}

		$this->set_directorist_preview_image( $post_id, (int) get_post_thumbnail_id( $post_id ) );
	}

	/**
	 * Log photo import details only when explicitly enabled.
	 */
	private function log_photo_import_failure( string $message, int $post_id ): void {
		if ( ! apply_filters( 'dgbi_log_photo_import_failures', defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		error_log( sprintf( 'DGBI photo import failed for listing %d: %s', $post_id, $message ) );
	}

	/**
	 * Map Google regularOpeningHours to the Directorist Business Hours extension format.
	 * Only runs when the extension is detected as active.
	 */
	private function map_opening_hours( int $post_id, array $opening_hours ): void {
		$active = defined( 'ATBDP_BUSINESS_HOURS' )
			|| class_exists( 'Directorist_Business_Hour' )
			|| class_exists( 'ATBDP_Business_Hours' );

		$active = apply_filters( 'dgbi_business_hours_active', $active, $post_id );

		if ( ! $active ) {
			return;
		}

		$day_names = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];
		$mapped    = [];

		foreach ( $day_names as $day ) {
			$mapped[ $day ] = [ 'day' => $day, 'open' => '09:00', 'close' => '17:00', 'closed' => true ];
		}

		if ( ! empty( $opening_hours['periods'] ) && is_array( $opening_hours['periods'] ) ) {
			foreach ( $opening_hours['periods'] as $period ) {
				$day_index = intval( $period['open']['day'] ?? 0 );
				if ( ! isset( $day_names[ $day_index ] ) ) {
					continue;
				}
				$day_key = $day_names[ $day_index ];
				$mapped[ $day_key ] = [
					'day'    => $day_key,
					'open'   => sprintf( '%02d:%02d', intval( $period['open']['hour'] ?? 9 ), intval( $period['open']['minute'] ?? 0 ) ),
					'close'  => sprintf( '%02d:%02d', intval( $period['close']['hour'] ?? 17 ), intval( $period['close']['minute'] ?? 0 ) ),
					'closed' => false,
				];
			}
		}

		update_post_meta( $post_id, '_businesshours', $mapped );
	}

	/**
	 * Re-pin the accurate Google rating after importing reviews.
	 *
	 * Google only returns up to 5 reviews via the API. If we averaged those 5
	 * to compute the listing's rating we would corrupt it — the real rating is
	 * aggregated from all of a place's reviews. This method writes Google's
	 * pre-computed values back to Directorist's meta keys, undoing any damage
	 * that might have been caused by averaging a small sample.
	 *
	 * @param int   $post_id Post ID of the Directorist listing.
	 * @param array $details Normalised place detail array.
	 */
	private function restore_google_rating( int $post_id, array $details ): void {
		if ( null !== ( $details['rating'] ?? null ) ) {
			update_post_meta( $post_id, '_directorist_listing_rating', (float) $details['rating'] );
		}
		if ( ! empty( $details['user_ratings_total'] ) ) {
			update_post_meta( $post_id, '_directorist_listing_review_count', intval( $details['user_ratings_total'] ) );
		}
		// Let Directorist clear any cached rating data for this listing.
		do_action( 'atbdp_after_review_added', $post_id );
	}

	/**
	 * Sanitize and insert an array of Google reviews as Directorist comment reviews.
	 *
	 * @return int Number of reviews actually created.
	 */
	private function import_reviews( int $post_id, array $reviews ): int {
		$clean = array_map( function ( $r ) {
			return [
				'author_name'               => sanitize_text_field( $r['author_name'] ?? '' ),
				'rating'                    => intval( $r['rating'] ?? 0 ),
				'time'                      => intval( $r['time'] ?? 0 ),
				'relative_time_description' => sanitize_text_field( $r['relative_time_description'] ?? '' ),
				'text'                      => sanitize_textarea_field( $r['text'] ?? '' ),
			];
		}, $reviews );

		update_post_meta( $post_id, '_google_reviews', wp_json_encode( $clean ) );

		$created = 0;
		foreach ( $clean as $review ) {
			if ( $this->review_mapper->insert( $post_id, $review ) ) {
				$created++;
			}
		}
		return $created;
	}
}
