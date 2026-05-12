<?php
/**
 * Google Places API (New) client.
 *
 * Migrated from the deprecated Places API (Old) to the Places API (New):
 *   Endpoint: https://places.googleapis.com/v1/places:searchText
 *   Auth:     X-Goog-Api-Key header (never in URL)
 *   Fields:   X-Goog-FieldMask header
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Google_Places_Client
 */
class Google_Places_Client {

	/** Base URL for the Places API (New). */
	public const SEARCH_ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';
	public const DETAIL_ENDPOINT = 'https://places.googleapis.com/v1/places/';
	public const PHOTO_ENDPOINT  = 'https://places.googleapis.com/v1/';

	/** Default request timeout in seconds. */
	public const TIMEOUT = 20;

	/** @var string */
	private $api_key = '';

	/**
	 * Whether to verify TLS certificates on outbound requests.
	 * Defaults to true. Hosts with an outdated CA bundle can override via filter:
	 *   add_filter( 'dgbi_sslverify', '__return_false' );
	 */
	private function sslverify(): bool {
		return (bool) apply_filters( 'dgbi_sslverify', true );
	}

	/**
	 * Set the API key for this client instance.
	 */
	public function set_api_key( string $key ): void {
		$this->api_key = trim( $key );
	}

	/**
	 * Resolve a location string to lat/lng using the Places API text search.
	 *
	 * Reuses header-based auth so the API key never appears in a URL.
	 * Returns an empty array on any failure; callers should fall back to
	 * a text-only search in that case.
	 *
	 * @param string $location Human-readable location string (e.g. "Dhaka").
	 * @return array { lat: float, lng: float } or empty array on failure.
	 */
	public function geocode( string $location ): array {
		if ( empty( $this->api_key ) || empty( $location ) ) {
			return [];
		}

		$response = wp_remote_post(
			self::SEARCH_ENDPOINT,
			[
				'timeout'   => self::TIMEOUT,
				'sslverify' => $this->sslverify(),
				'headers'   => [
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $this->api_key,
					'X-Goog-FieldMask' => 'places.location',
				],
				'body' => wp_json_encode( [
					'textQuery'      => $location,
					'maxResultCount' => 1,
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$place = $data['places'][0] ?? null;

		if ( empty( $place['location'] ) ) {
			return [];
		}

		return [
			'lat' => (float) ( $place['location']['latitude']  ?? 0 ),
			'lng' => (float) ( $place['location']['longitude'] ?? 0 ),
		];
	}

	/**
	 * Search for places using the New Places API text search.
	 *
	 * @param string $query  Combined keyword + location string.
	 * @param int    $radius Bias radius in metres (ignored when lat/lng are both 0.0).
	 * @param int    $max    Maximum results to return (1–60).
	 * @param float  $lat    Centre latitude for locationBias (0.0 = omit bias).
	 * @param float  $lng    Centre longitude for locationBias (0.0 = omit bias).
	 * @return array{places: array, error: string} Normalised result.
	 */
	public function search( string $query, int $radius, int $max = 20, float $lat = 0.0, float $lng = 0.0 ): array {
		if ( empty( $this->api_key ) ) {
			return [ 'places' => [], 'error' => __( 'No API key configured.', 'directorist-listing-import' ) ];
		}

		$places     = [];
		$page_token = null;
		$per_page   = \min( $max, 20 ); // New API max per page is 20
		$sslverify  = $this->sslverify();

		do {
			$body = [ 'textQuery' => $query, 'maxResultCount' => $per_page ];

			// locationBias.circle requires both a centre and a radius.
			// Only include it when valid coordinates were resolved by the caller.
			if ( $radius > 0 && ( 0.0 !== $lat || 0.0 !== $lng ) ) {
				$body['locationBias'] = [
					'circle' => [
						'center' => [
							'latitude'  => $lat,
							'longitude' => $lng,
						],
						'radius' => (float) $radius,
					],
				];
			}

			if ( $page_token ) {
				$body['pageToken'] = $page_token;
			}

			$response = wp_remote_post(
				self::SEARCH_ENDPOINT,
				[
					'timeout'   => self::TIMEOUT,
					'sslverify' => $sslverify,
					'headers'   => [
						'Content-Type'     => 'application/json',
						'X-Goog-Api-Key'   => $this->api_key,
						'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.shortFormattedAddress,places.location,places.rating,places.userRatingCount,places.googleMapsUri,places.primaryType,places.primaryTypeDisplayName,places.types,places.businessStatus,places.priceLevel,places.priceRange,places.plusCode,places.pureServiceAreaBusiness,nextPageToken',
					],
					'body' => wp_json_encode( $body ),
				]
			);

			if ( is_wp_error( $response ) ) {
				return [ 'places' => $places, 'error' => $response->get_error_message() ];
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			// Detect API-level errors
			if ( isset( $data['error'] ) ) {
				$msg      = $data['error']['message'] ?? __( 'Unknown Google API error.', 'directorist-listing-import' );
				$code_str = $data['error']['code']    ?? '';
				/* translators: 1: Error code, 2: Error message */
				return [ 'places' => $places, 'error' => \sprintf( __( 'Google API error %1$s: %2$s', 'directorist-listing-import' ), $code_str, $msg ) ];
			}

			if ( 200 !== (int) $code ) {
				/* translators: %s: HTTP status code */
				return [ 'places' => $places, 'error' => \sprintf( __( 'Unexpected HTTP status: %s', 'directorist-listing-import' ), $code ) ];
			}

			$batch = $data['places'] ?? [];
			foreach ( $batch as $raw ) {
				$places[] = $this->normalise_search_result( $raw );
			}

			$page_token = $data['nextPageToken'] ?? null;

			if ( \count( $places ) >= $max ) {
				break;
			}

			// Google recommends a brief pause between paginated requests.
			if ( $page_token && \count( $places ) < $max ) {
				sleep( 2 );
			}

		} while ( $page_token && \count( $places ) < $max );

		return [ 'places' => \array_slice( $places, 0, $max ), 'error' => '' ];
	}

	/**
	 * Fetch detailed information for a single place.
	 *
	 * @param string $place_id Google place resource name, e.g. "places/ChIJ...".
	 * @return array Normalised detail array, or empty array on failure.
	 */
	public function get_details( string $place_id ): array {
		if ( empty( $this->api_key ) || empty( $place_id ) ) {
			return [];
		}

		$url = self::DETAIL_ENDPOINT . rawurlencode( $place_id );

		$response = wp_remote_get(
			$url,
			[
				'timeout'   => self::TIMEOUT,
				'sslverify' => $this->sslverify(),
				'headers'   => [
					'X-Goog-Api-Key'   => $this->api_key,
					'X-Goog-FieldMask' => 'id,displayName,formattedAddress,shortFormattedAddress,location,nationalPhoneNumber,internationalPhoneNumber,websiteUri,googleMapsUri,regularOpeningHours,editorialSummary,rating,userRatingCount,reviews,photos,primaryType,primaryTypeDisplayName,types,businessStatus,priceLevel,priceRange,plusCode,pureServiceAreaBusiness',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data ) || isset( $data['error'] ) ) {
			return [];
		}

		return $this->normalise_detail( $data );
	}

	// ── Normalisers ──────────────────────────────────────────────────────────
	// Map the New API response shape to a consistent internal format so the
	// rest of the plugin doesn't have to know which API version it is using.

	/**
	 * Normalise a single search result from the New API.
	 */
	private function normalise_search_result( array $raw ): array {
		$types       = ! empty( $raw['types'] ) && is_array( $raw['types'] ) ? array_values( array_filter( $raw['types'] ) ) : [];
		$price_range = $raw['priceRange'] ?? [];

		return [
			'place_id'                   => $raw['id'] ?? '',
			'name'                       => $raw['displayName']['text'] ?? '',
			'formatted_address'          => $raw['formattedAddress'] ?? '',
			'short_formatted_address'    => $raw['shortFormattedAddress'] ?? '',
			'lat'                        => (float) ( $raw['location']['latitude']  ?? 0 ),
			'lng'                        => (float) ( $raw['location']['longitude'] ?? 0 ),
			'google_maps_uri'            => $raw['googleMapsUri'] ?? '',
			'primary_type'               => $raw['primaryType'] ?? '',
			'primary_type_display_name'  => $raw['primaryTypeDisplayName']['text'] ?? '',
			'types'                      => $types,
			'types_text'                 => implode( ', ', $types ),
			'business_status'            => $raw['businessStatus'] ?? '',
			'business_status_label'      => $this->humanise_business_status( $raw['businessStatus'] ?? '' ),
			'price_level'                => $raw['priceLevel'] ?? '',
			'price_level_label'          => $this->humanise_price_level( $raw['priceLevel'] ?? '' ),
			'price_range_start'          => $this->money_to_float( $price_range['startPrice'] ?? null ),
			'price_range_end'            => $this->money_to_float( $price_range['endPrice'] ?? null ),
			'price_range_currency'       => $this->resolve_price_currency( $price_range ),
			'price_range_text'           => $this->format_price_range_text( $price_range ),
			'plus_code'                  => $this->resolve_plus_code( $raw['plusCode'] ?? [] ),
			'pure_service_area_business' => $this->optional_bool( $raw, 'pureServiceAreaBusiness' ),
			'rating'                     => isset( $raw['rating'] ) ? (float) $raw['rating'] : null,
			'user_ratings_total'         => isset( $raw['userRatingCount'] ) ? \intval( $raw['userRatingCount'] ) : 0,
		];
	}

	/**
	 * Normalise a Place Details response from the New API.
	 */
	private function normalise_detail( array $raw ): array {
		$summary       = $raw['editorialSummary']['text'] ?? '';
		$opening_hours = $raw['regularOpeningHours'] ?? [];
		$types         = ! empty( $raw['types'] ) && \is_array( $raw['types'] ) ? array_values( array_filter( $raw['types'] ) ) : [];
		$price_range   = $raw['priceRange'] ?? [];

		$reviews = [];
		if ( ! empty( $raw['reviews'] ) && \is_array( $raw['reviews'] ) ) {
			foreach ( $raw['reviews'] as $r ) {
				$reviews[] = [
					'author_name'               => $r['authorAttribution']['displayName'] ?? '',
					'rating'                    => isset( $r['rating'] ) ? \intval( $r['rating'] ) : 0,
					'time'                      => isset( $r['publishTime'] ) ? strtotime( $r['publishTime'] ) : 0,
					'relative_time_description' => $r['relativePublishTimeDescription'] ?? '',
					'text'                      => $r['text']['text'] ?? '',
				];
			}
		}

		// First photo resource name (e.g. "places/ChIJ.../photos/AUc7tXW...")
		$photo_name = $raw['photos'][0]['name'] ?? '';

		return [
			'place_id'                   => $raw['id'] ?? '',
			'name'                       => $raw['displayName']['text'] ?? '',
			'formatted_address'          => $raw['formattedAddress'] ?? '',
			'short_formatted_address'    => $raw['shortFormattedAddress'] ?? '',
			'lat'                        => isset( $raw['location']['latitude'] ) ? (float) $raw['location']['latitude'] : null,
			'lng'                        => isset( $raw['location']['longitude'] ) ? (float) $raw['location']['longitude'] : null,
			'phone'                      => $raw['nationalPhoneNumber'] ?? '',
			'international_phone'        => $raw['internationalPhoneNumber'] ?? '',
			'website'                    => $raw['websiteUri'] ?? '',
			'google_maps_uri'            => $raw['googleMapsUri'] ?? '',
			'editorial_summary'          => $summary,
			'opening_hours'              => $opening_hours,
			'primary_type'               => $raw['primaryType'] ?? '',
			'primary_type_display_name'  => $raw['primaryTypeDisplayName']['text'] ?? '',
			'types'                      => $types,
			'types_text'                 => implode( ', ', $types ),
			'business_status'            => $raw['businessStatus'] ?? '',
			'business_status_label'      => $this->humanise_business_status( $raw['businessStatus'] ?? '' ),
			'price_level'                => $raw['priceLevel'] ?? '',
			'price_level_label'          => $this->humanise_price_level( $raw['priceLevel'] ?? '' ),
			'price_range_start'          => $this->money_to_float( $price_range['startPrice'] ?? null ),
			'price_range_end'            => $this->money_to_float( $price_range['endPrice'] ?? null ),
			'price_range_currency'       => $this->resolve_price_currency( $price_range ),
			'price_range_text'           => $this->format_price_range_text( $price_range ),
			'plus_code'                  => $this->resolve_plus_code( $raw['plusCode'] ?? [] ),
			'pure_service_area_business' => $this->optional_bool( $raw, 'pureServiceAreaBusiness' ),
			'rating'                     => isset( $raw['rating'] ) ? (float) $raw['rating'] : null,
			'user_ratings_total'         => isset( $raw['userRatingCount'] ) ? \intval( $raw['userRatingCount'] ) : 0,
			'reviews'                    => $reviews,
			'photo_name'                 => $photo_name,
		];
	}

	/**
	 * Convert an API enum-style business status into a label.
	 */
	private function humanise_business_status( string $status ): string {
		switch ( $status ) {
			case 'OPERATIONAL':
				return 'Operational';
			case 'CLOSED_TEMPORARILY':
				return 'Temporarily Closed';
			case 'CLOSED_PERMANENTLY':
				return 'Permanently Closed';
			default:
				return '';
		}
	}

	/**
	 * Convert an API enum-style price level into a label.
	 */
	private function humanise_price_level( string $price_level ): string {
		switch ( $price_level ) {
			case 'PRICE_LEVEL_FREE':
				return 'Free';
			case 'PRICE_LEVEL_INEXPENSIVE':
				return 'Inexpensive';
			case 'PRICE_LEVEL_MODERATE':
				return 'Moderate';
			case 'PRICE_LEVEL_EXPENSIVE':
				return 'Expensive';
			case 'PRICE_LEVEL_VERY_EXPENSIVE':
				return 'Very Expensive';
			default:
				return '';
		}
	}

	/**
	 * Convert a Google Money object into a float.
	 *
	 * @param mixed $money Money object from the Places API.
	 */
	private function money_to_float( $money ): ?float {
		if ( ! is_array( $money ) ) {
			return null;
		}

		if ( ! isset( $money['units'] ) && ! isset( $money['nanos'] ) ) {
			return null;
		}

		$units = isset( $money['units'] ) ? (float) $money['units'] : 0.0;
		$nanos = isset( $money['nanos'] ) ? (float) $money['nanos'] / 1000000000 : 0.0;

		return $units + $nanos;
	}

	/**
	 * Resolve a currency code from a Google PriceRange object.
	 */
	private function resolve_price_currency( array $price_range ): string {
		if ( ! empty( $price_range['startPrice']['currencyCode'] ) ) {
			return (string) $price_range['startPrice']['currencyCode'];
		}

		if ( ! empty( $price_range['endPrice']['currencyCode'] ) ) {
			return (string) $price_range['endPrice']['currencyCode'];
		}

		return '';
	}

	/**
	 * Format a Google PriceRange object into a readable string.
	 */
	private function format_price_range_text( array $price_range ): string {
		$start = $this->format_money( $price_range['startPrice'] ?? null );
		$end   = $this->format_money( $price_range['endPrice'] ?? null );

		if ( '' !== $start && '' !== $end ) {
			return $start . ' - ' . $end;
		}

		if ( '' !== $start ) {
			return $start;
		}

		if ( '' !== $end ) {
			return $end;
		}

		return '';
	}

	/**
	 * Format a Google Money object into a human-readable string.
	 *
	 * @param mixed $money Money object from the Places API.
	 */
	private function format_money( $money ): string {
		if ( ! is_array( $money ) ) {
			return '';
		}

		$amount   = $this->money_to_float( $money );
		$currency = (string) ( $money['currencyCode'] ?? '' );

		if ( null === $amount ) {
			return '';
		}

		$formatted = number_format_i18n( $amount, 2 );
		return '' !== $currency ? $currency . ' ' . $formatted : $formatted;
	}

	/**
	 * Resolve the most useful Plus Code string from the API payload.
	 *
	 * @param mixed $plus_code Plus Code object from the Places API.
	 */
	private function resolve_plus_code( $plus_code ): string {
		if ( ! is_array( $plus_code ) ) {
			return '';
		}

		if ( ! empty( $plus_code['globalCode'] ) ) {
			return (string) $plus_code['globalCode'];
		}

		if ( ! empty( $plus_code['compoundCode'] ) ) {
			return (string) $plus_code['compoundCode'];
		}

		return '';
	}

	/**
	 * Return null when a boolean-style field is absent from the payload.
	 *
	 * @param array<string,mixed> $raw
	 */
	private function optional_bool( array $raw, string $key ): ?bool {
		return array_key_exists( $key, $raw ) ? (bool) $raw[ $key ] : null;
	}

	/**
	 * Resolve a photo resource name to a publicly downloadable URL.
	 *
	 * Uses skipHttpRedirect=true so the API returns JSON {"photoUri":"..."} instead
	 * of a binary redirect, giving us a clean CDN URL for media_sideload_image().
	 * Falls back to reading the Location header if a redirect is returned anyway.
	 *
	 * @param string $photo_name Resource name from the Places API, e.g. "places/ChIJ.../photos/AUc7...".
	 * @return string Public photo URL, or empty string on failure.
	 */
	public function get_photo_media_url( string $photo_name ): string {
		if ( empty( $this->api_key ) || empty( $photo_name ) ) {
			return '';
		}

		$url = self::PHOTO_ENDPOINT . $photo_name . '/media?maxHeightPx=1200&maxWidthPx=1200&skipHttpRedirect=true';

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'sslverify'   => $this->sslverify(),
				'redirection' => 0, // handle redirect ourselves; don't let WP follow it silently
				'headers'     => [
					'X-Goog-Api-Key' => $this->api_key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log_photo_media_failure( $response->get_error_message() );
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );

		// skipHttpRedirect=true → 200 with JSON {"photoUri":"..."}
		if ( 200 === $code ) {
			$data = json_decode( $body, true );
			if ( ! empty( $data['photoUri'] ) ) {
				return esc_url_raw( $data['photoUri'] );
			}

			$this->log_photo_media_failure( 'missing_photo_uri' );
			return '';
		}

		// Fallback: some API versions return a 302 redirect to the CDN URL directly.
		if ( 301 === $code || 302 === $code ) {
			return esc_url_raw( (string) $location );
		}

		$this->log_photo_media_failure( 'unexpected_http_status_' . $code );
		return '';
	}

	/**
	 * Log photo media resolution failures only when explicitly enabled.
	 */
	private function log_photo_media_failure( string $message ): void {
		if ( ! apply_filters( 'dgbi_log_photo_import_failures', defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		error_log( 'DGBI photo media resolution failed: ' . $message );
	}
}
