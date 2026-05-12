<?php
/**
 * Google field mapping storage + application layer.
 *
 * Aligns Google import destinations with Directorist's directory-type form
 * schema so preset fields, taxonomy fields, pricing, map data, and custom
 * fields can all be driven from one saved per-directory mapping.
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Mapping
 */
class Field_Mapping {

	/** Option key for per-directory Google field mappings. */
	const OPT_FIELD_MAP = 'dgbi_field_map';

	/** @var array<int,array> */
	private $form_fields_cache = [];

	public function __construct() {
		add_action( 'admin_post_dlig_save_google_field_mapping', [ $this, 'handle_save' ] );
	}

	/**
	 * Return all Google source fields supported by the mapper.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public function get_source_fields(): array {
		return [
			'name' => [
				'label'       => __( 'Business Name', 'directorist-listing-import' ),
				'description' => __( 'Google business display name.', 'directorist-listing-import' ),
			],
			'place_id' => [
				'label'       => __( 'Place ID', 'directorist-listing-import' ),
				'description' => __( 'Unique Google Place identifier. Stored automatically in system meta as well.', 'directorist-listing-import' ),
			],
			'formatted_address' => [
				'label'       => __( 'Formatted Address', 'directorist-listing-import' ),
				'description' => __( 'Full address string returned by Google.', 'directorist-listing-import' ),
			],
			'short_formatted_address' => [
				'label'       => __( 'Short Address', 'directorist-listing-import' ),
				'description' => __( 'Short formatted address from Google Places.', 'directorist-listing-import' ),
			],
			'lat' => [
				'label'       => __( 'Latitude', 'directorist-listing-import' ),
				'description' => __( 'Google latitude coordinate.', 'directorist-listing-import' ),
			],
			'lng' => [
				'label'       => __( 'Longitude', 'directorist-listing-import' ),
				'description' => __( 'Google longitude coordinate.', 'directorist-listing-import' ),
			],
			'phone' => [
				'label'       => __( 'Phone', 'directorist-listing-import' ),
				'description' => __( 'National-format phone number.', 'directorist-listing-import' ),
			],
			'international_phone' => [
				'label'       => __( 'International Phone', 'directorist-listing-import' ),
				'description' => __( 'International-format phone number.', 'directorist-listing-import' ),
			],
			'website' => [
				'label'       => __( 'Website', 'directorist-listing-import' ),
				'description' => __( 'Business website URL.', 'directorist-listing-import' ),
			],
			'google_maps_uri' => [
				'label'       => __( 'Google Maps URL', 'directorist-listing-import' ),
				'description' => __( 'Direct link to the Google Maps place page.', 'directorist-listing-import' ),
			],
			'editorial_summary' => [
				'label'       => __( 'Editorial Summary', 'directorist-listing-import' ),
				'description' => __( 'Google editorial summary/about text.', 'directorist-listing-import' ),
			],
			'rating' => [
				'label'       => __( 'Google Rating', 'directorist-listing-import' ),
				'description' => __( 'Average rating returned by Google.', 'directorist-listing-import' ),
			],
			'user_ratings_total' => [
				'label'       => __( 'Google Review Count', 'directorist-listing-import' ),
				'description' => __( 'Total number of Google reviews.', 'directorist-listing-import' ),
			],
			'primary_type' => [
				'label'       => __( 'Primary Type', 'directorist-listing-import' ),
				'description' => __( 'Google primary place type slug.', 'directorist-listing-import' ),
				'mapping_kind' => 'choice_single',
			],
			'primary_type_display_name' => [
				'label'       => __( 'Primary Type Label', 'directorist-listing-import' ),
				'description' => __( 'Human-readable Google primary type name.', 'directorist-listing-import' ),
				'mapping_kind' => 'choice_single',
			],
			'types' => [
				'label'       => __( 'All Place Types', 'directorist-listing-import' ),
				'description' => __( 'Array of all Google place types for the business.', 'directorist-listing-import' ),
				'mapping_kind' => 'choice_multi',
			],
			'types_text' => [
				'label'       => __( 'All Place Types (Text)', 'directorist-listing-import' ),
				'description' => __( 'Comma-separated version of the Google place types.', 'directorist-listing-import' ),
			],
			'business_status' => [
				'label'       => __( 'Business Status', 'directorist-listing-import' ),
				'description' => __( 'Google business status enum value.', 'directorist-listing-import' ),
				'mapping_kind' => 'choice_single',
			],
			'business_status_label' => [
				'label'       => __( 'Business Status Label', 'directorist-listing-import' ),
				'description' => __( 'Human-readable Google business status label.', 'directorist-listing-import' ),
				'mapping_kind' => 'choice_single',
			],
			'price_level' => [
				'label'       => __( 'Price Level', 'directorist-listing-import' ),
				'description' => __( 'Google price level enum value.', 'directorist-listing-import' ),
				'mapping_kind' => 'choice_single',
				'allowed_special_destinations' => [ 'special:pricing_range' ],
			],
			'price_level_label' => [
				'label'       => __( 'Price Level Label', 'directorist-listing-import' ),
				'description' => __( 'Human-readable Google price level label.', 'directorist-listing-import' ),
				'mapping_kind' => 'choice_single',
				'allowed_special_destinations' => [ 'special:pricing_range' ],
			],
			'price_range_start' => [
				'label'       => __( 'Price Range Start', 'directorist-listing-import' ),
				'description' => __( 'Low end of Google price range when available.', 'directorist-listing-import' ),
			],
			'price_range_end' => [
				'label'       => __( 'Price Range End', 'directorist-listing-import' ),
				'description' => __( 'High end of Google price range when available.', 'directorist-listing-import' ),
			],
			'price_range_currency' => [
				'label'       => __( 'Price Range Currency', 'directorist-listing-import' ),
				'description' => __( 'Currency code used by Google price range values.', 'directorist-listing-import' ),
			],
			'price_range_text' => [
				'label'       => __( 'Price Range Text', 'directorist-listing-import' ),
				'description' => __( 'Formatted Google price range string.', 'directorist-listing-import' ),
			],
			'plus_code' => [
				'label'       => __( 'Plus Code', 'directorist-listing-import' ),
				'description' => __( 'Google Plus Code for the place.', 'directorist-listing-import' ),
			],
			'pure_service_area_business' => [
				'label'       => __( 'Pure Service Area Business', 'directorist-listing-import' ),
				'description' => __( 'Boolean flag for businesses without a public storefront.', 'directorist-listing-import' ),
				'mapping_kind' => 'boolean',
				'allowed_special_destinations' => [ 'special:hide_map' ],
			],
		];
	}

	/**
	 * Resolve the directory type used for mapping or import defaults.
	 */
	public function resolve_directory_id( int $directory_id = 0 ): int {
		if ( $directory_id > 0 ) {
			return $directory_id;
		}

		if ( function_exists( 'directorist_get_default_directory' ) ) {
			return (int) directorist_get_default_directory();
		}

		if ( function_exists( 'default_directory_type' ) ) {
			return (int) default_directory_type();
		}

		return 0;
	}

	/**
	 * Return all available directory types.
	 *
	 * @return array<int,string>
	 */
	public function get_directory_types(): array {
		$taxonomy = defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types';
		$terms    = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		$types = [];
		foreach ( $terms as $term ) {
			$types[ (int) $term->term_id ] = $term->name;
		}

		return $types;
	}

	/**
	 * Resolve the directory ID selected on the mapping tab.
	 */
	public function get_mapping_screen_directory_id(): int {
		$requested = absint( $_GET['dgbi_mapping_directory'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		return $this->resolve_directory_id( $requested );
	}

	/**
	 * Return grouped destination choices for the selected directory type.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function get_available_destinations( int $directory_id, string $source_key = '' ): array {
		$directory_id = $this->resolve_directory_id( $directory_id );
		$groups       = [
			__( 'System', 'directorist-listing-import' ) => [
				'skip'                             => __( 'Do Not Import', 'directorist-listing-import' ),
				'special:manual_lat'               => __( 'Map Latitude (system)', 'directorist-listing-import' ),
				'special:manual_lng'               => __( 'Map Longitude (system)', 'directorist-listing-import' ),
				'special:hide_map'                 => __( 'Hide Map (system)', 'directorist-listing-import' ),
				'special:directorist_rating'       => __( 'Directorist Rating (system)', 'directorist-listing-import' ),
				'special:directorist_review_count' => __( 'Directorist Review Count (system)', 'directorist-listing-import' ),
				'special:pricing_amount'           => __( 'Directorist Price (system)', 'directorist-listing-import' ),
				'special:pricing_range'            => __( 'Directorist Price Range (system)', 'directorist-listing-import' ),
			],
			__( 'Taxonomies', 'directorist-listing-import' ) => [
				'taxonomy:' . $this->get_category_taxonomy() => __( 'Directory Categories', 'directorist-listing-import' ),
				'taxonomy:' . $this->get_location_taxonomy() => __( 'Directory Locations', 'directorist-listing-import' ),
				'taxonomy:' . $this->get_tag_taxonomy()      => __( 'Directory Tags', 'directorist-listing-import' ),
			],
			__( 'Standard Fields', 'directorist-listing-import' ) => [],
			__( 'Custom Fields', 'directorist-listing-import' ) => [],
		];

		foreach ( $this->get_form_fields( $directory_id ) as $field ) {
			if ( ! $this->is_supported_form_field( $field ) ) {
				continue;
			}

			$destination = 'field:' . $field['field_key'];
			$label       = $this->get_destination_label( $field );
			$group_key   = ( ( $field['widget_group'] ?? '' ) === 'custom' )
				? __( 'Custom Fields', 'directorist-listing-import' )
				: __( 'Standard Fields', 'directorist-listing-import' );

			$groups[ $group_key ][ $destination ] = $label;
		}

		if ( '' !== $source_key ) {
			$groups = $this->filter_destinations_for_source( $groups, $directory_id, $source_key );
		}

		return array_filter(
			$groups,
			static function ( array $options ): bool {
				return ! empty( $options );
			}
		);
	}

	/**
	 * Return the merged default + saved mapping for a directory type.
	 *
	 * @return array<string,string>
	 */
	public function get_effective_mapping( int $directory_id ): array {
		$directory_id  = $this->resolve_directory_id( $directory_id );
		$default_map   = $this->get_default_mapping( $directory_id );
		$saved_map     = $this->get_saved_mapping( $directory_id );
		$effective_map = $default_map;

		foreach ( $saved_map as $source_key => $destination ) {
			$allowed_ids = $this->get_allowed_destination_ids( $directory_id, $source_key );
			if ( isset( $default_map[ $source_key ] ) && in_array( $destination, $allowed_ids, true ) ) {
				$effective_map[ $source_key ] = $destination;
			}
		}

		return $this->enforce_unique_destinations( $effective_map );
	}

	/**
	 * Save or reset field mappings for one directory type.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'directorist-listing-import' ) );
		}

		check_admin_referer( 'dlig_save_google_field_mapping_action', 'dgbi_field_mapping_nonce' );

		$directory_id = $this->resolve_directory_id( absint( $_POST['dgbi_mapping_directory'] ?? 0 ) );
		$all_maps     = get_option( self::OPT_FIELD_MAP, [] );
		$all_maps     = is_array( $all_maps ) ? $all_maps : [];
		$query_args   = [
			'page'                   => DLI_PAGE_SLUG,
			'tab'                    => 'google',
			'google_tab'             => 'mapping',
			'dgbi_mapping_directory' => $directory_id,
		];

		if ( ! empty( $_POST['dgbi_reset_field_map'] ) ) {
			unset( $all_maps[ (string) $directory_id ] );
			update_option( self::OPT_FIELD_MAP, $all_maps, 'no' );
			$query_args['dgbi_mapping_reset'] = '1';
		} else {
			$submitted = $_POST['dgbi_field_map'] ?? [];
			$submitted = is_array( $submitted ) ? wp_unslash( $submitted ) : [];
			$all_maps[ (string) $directory_id ] = $this->sanitize_mapping_payload( $directory_id, $submitted );
			update_option( self::OPT_FIELD_MAP, $all_maps, 'no' );
			$query_args['dgbi_mapping_saved'] = '1';
		}

		wp_safe_redirect(
			add_query_arg(
				$query_args,
				admin_url( 'edit.php?post_type=at_biz_dir' )
			)
		);
		exit;
	}

	/**
	 * Apply the effective mapping to one imported listing.
	 *
	 * @return string[] Source keys that were successfully applied.
	 */
	public function apply_mapped_data( int $post_id, array $source_data, int $directory_id ): array {
		$directory_id = $this->resolve_directory_id( $directory_id );
		$mapping      = $this->get_effective_mapping( $directory_id );
		$applied      = [];

		foreach ( $mapping as $source_key => $destination ) {
			if ( 'skip' === $destination || ! array_key_exists( $source_key, $source_data ) ) {
				continue;
			}

			$value = $source_data[ $source_key ];
			if ( ! $this->source_has_value( $value ) ) {
				continue;
			}

			if ( $this->apply_destination( $post_id, $directory_id, $destination, $value ) ) {
				$applied[] = $source_key;
			}
		}

		if ( $this->source_has_value( $source_data['editorial_summary'] ?? '' ) ) {
			update_post_meta( $post_id, '_google_description', sanitize_textarea_field( (string) $source_data['editorial_summary'] ) );
		}

		return array_values( array_unique( $applied ) );
	}

	/**
	 * Return the default mapping for a directory type.
	 *
	 * @return array<string,string>
	 */
	private function get_default_mapping( int $directory_id ): array {
		$directory_id = $this->resolve_directory_id( $directory_id );

		return [
			'name'                       => $this->find_field_destination( $directory_id, [ 'title' ] ) ?: 'skip',
			'place_id'                   => 'skip',
			'formatted_address'          => $this->find_field_destination( $directory_id, [ 'address' ] ) ?: 'skip',
			'short_formatted_address'    => 'skip',
			'lat'                        => 'special:manual_lat',
			'lng'                        => 'special:manual_lng',
			'phone'                      => $this->find_field_destination( $directory_id, [ 'phone' ] ) ?: 'skip',
			'international_phone'        => $this->find_field_destination( $directory_id, [ 'phone2' ] ) ?: 'skip',
			'website'                    => $this->find_field_destination( $directory_id, [ 'website' ] ) ?: 'skip',
			'google_maps_uri'            => 'skip',
			'editorial_summary'          => $this->find_field_destination( $directory_id, [ 'description' ] ) ?: 'skip',
			'rating'                     => 'special:directorist_rating',
			'user_ratings_total'         => 'special:directorist_review_count',
			'primary_type'               => 'skip',
			'primary_type_display_name'  => 'skip',
			'types'                      => 'skip',
			'types_text'                 => 'skip',
			'business_status'            => 'skip',
			'business_status_label'      => 'skip',
			'price_level'                => 'skip',
			'price_level_label'          => 'skip',
			'price_range_start'          => 'skip',
			'price_range_end'            => 'skip',
			'price_range_currency'       => 'skip',
			'price_range_text'           => 'skip',
			'plus_code'                  => 'skip',
			'pure_service_area_business' => 'skip',
		];
	}

	/**
	 * Return saved mapping for a directory type without defaults.
	 *
	 * @return array<string,string>
	 */
	private function get_saved_mapping( int $directory_id ): array {
		$all_maps = get_option( self::OPT_FIELD_MAP, [] );
		$all_maps = is_array( $all_maps ) ? $all_maps : [];

		return isset( $all_maps[ (string) $directory_id ] ) && is_array( $all_maps[ (string) $directory_id ] )
			? $all_maps[ (string) $directory_id ]
			: [];
	}

	/**
	 * Return all destination IDs allowed for one directory type.
	 *
	 * @return string[]
	 */
	private function get_allowed_destination_ids( int $directory_id, string $source_key = '' ): array {
		$allowed = [];
		foreach ( $this->get_available_destinations( $directory_id, $source_key ) as $group ) {
			foreach ( array_keys( $group ) as $id ) {
				$allowed[] = $id;
			}
		}

		return $allowed;
	}

	/**
	 * Sanitize submitted mapping rows.
	 *
	 * @param array<string,mixed> $submitted
	 * @return array<string,string>
	 */
	private function sanitize_mapping_payload( int $directory_id, array $submitted ): array {
		$clean       = [];
		$source_keys = array_keys( $this->get_source_fields() );

		foreach ( $source_keys as $source_key ) {
			$allowed_ids          = $this->get_allowed_destination_ids( $directory_id, $source_key );
			$destination          = isset( $submitted[ $source_key ] ) ? sanitize_text_field( (string) $submitted[ $source_key ] ) : 'skip';
			$clean[ $source_key ] = in_array( $destination, $allowed_ids, true ) ? $destination : 'skip';
		}

		return $this->enforce_unique_destinations( $clean );
	}

	/**
	 * Ensure each non-skip destination is only used once.
	 *
	 * Later duplicate assignments fall back to skip, keeping the first mapping
	 * row as the winner.
	 *
	 * @param array<string,string> $mapping
	 * @return array<string,string>
	 */
	private function enforce_unique_destinations( array $mapping ): array {
		$used = [];

		foreach ( $mapping as $source_key => $destination ) {
			if ( 'skip' === $destination || '' === $destination ) {
				$mapping[ $source_key ] = 'skip';
				continue;
			}

			if ( isset( $used[ $destination ] ) ) {
				$mapping[ $source_key ] = 'skip';
				continue;
			}

			$used[ $destination ] = true;
		}

		return $mapping;
	}

	/**
	 * Restrict available destinations for structured Google source fields.
	 *
	 * @param array<string,array<string,string>> $groups
	 * @return array<string,array<string,string>>
	 */
	private function filter_destinations_for_source( array $groups, int $directory_id, string $source_key ): array {
		$source = $this->get_source_fields()[ $source_key ] ?? [];
		$kind   = (string) ( $source['mapping_kind'] ?? '' );

		if ( '' === $kind ) {
			return $groups;
		}

		$allowed_specials = isset( $source['allowed_special_destinations'] ) && is_array( $source['allowed_special_destinations'] )
			? array_map( 'strval', $source['allowed_special_destinations'] )
			: [];
		$filtered         = [];

		foreach ( $groups as $group_label => $options ) {
			foreach ( $options as $destination_id => $label ) {
				if ( $this->destination_matches_source_kind( $directory_id, $destination_id, $kind, $allowed_specials ) ) {
					$filtered[ $group_label ][ $destination_id ] = $label;
				}
			}
		}

		return $filtered;
	}

	/**
	 * Check if a destination is compatible with one structured source kind.
	 *
	 * @param string[] $allowed_specials
	 */
	private function destination_matches_source_kind( int $directory_id, string $destination_id, string $source_kind, array $allowed_specials ): bool {
		if ( 'skip' === $destination_id ) {
			return true;
		}

		if ( 0 === strpos( $destination_id, 'special:' ) ) {
			return in_array( $destination_id, $allowed_specials, true );
		}

		if ( 0 === strpos( $destination_id, 'taxonomy:' ) ) {
			return in_array( $source_kind, [ 'choice_single', 'choice_multi' ], true );
		}

		if ( 0 !== strpos( $destination_id, 'field:' ) ) {
			return false;
		}

		$field      = $this->find_form_field_by_key( $directory_id, substr( $destination_id, 6 ) );
		$field_type = (string) ( $field['type'] ?? '' );

		if ( 'choice_single' === $source_kind ) {
			return in_array( $field_type, [ 'select', 'radio' ], true );
		}

		if ( 'choice_multi' === $source_kind ) {
			return 'checkbox' === $field_type;
		}

		if ( 'boolean' === $source_kind ) {
			return in_array( $field_type, [ 'checkbox', 'select', 'radio' ], true );
		}

		return true;
	}

	/**
	 * Check whether a form field is safe for Google mapping.
	 */
	private function is_supported_form_field( array $field ): bool {
		$widget_key = (string) ( $field['widget_key'] ?? '' );
		$type       = (string) ( $field['type'] ?? '' );

		if ( in_array( $widget_key, [ 'category', 'location', 'tag', 'pricing', 'map', 'image_upload', 'video', 'social_info', 'privacy_policy' ], true ) ) {
			return false;
		}

		if ( in_array( $type, [ 'file', 'media', 'image_upload', 'video', 'pricing', 'map', 'social_info' ], true ) ) {
			return false;
		}

		return in_array( $type, [ 'text', 'textarea', 'wp_editor', 'tel', 'email', 'number', 'url', 'select', 'checkbox', 'radio', 'date', 'time', 'color_picker' ], true )
			|| in_array( $widget_key, [ 'title', 'description', 'excerpt', 'address', 'phone', 'phone2', 'email', 'website', 'zip', 'fax', 'tagline' ], true );
	}

	/**
	 * Build a readable label for one destination field.
	 */
	private function get_destination_label( array $field ): string {
		$label     = trim( (string) ( $field['label'] ?? '' ) );
		$field_key = (string) ( $field['field_key'] ?? '' );

		if ( '' === $label ) {
			$label = ucwords( str_replace( [ '-', '_' ], ' ', $field_key ) );
		}

		return sprintf( '%s (%s)', $label, $field_key );
	}

	/**
	 * Find the first supported form field matching one of the given widget keys.
	 */
	private function find_field_destination( int $directory_id, array $widget_keys ): string {
		foreach ( $this->get_form_fields( $directory_id ) as $field ) {
			if ( ! $this->is_supported_form_field( $field ) ) {
				continue;
			}

			if ( in_array( (string) ( $field['widget_key'] ?? '' ), $widget_keys, true ) ) {
				return 'field:' . $field['field_key'];
			}
		}

		return '';
	}

	/**
	 * Return all listing form fields for the directory type.
	 *
	 * @return array<int,array>
	 */
	private function get_form_fields( int $directory_id ): array {
		$directory_id = $this->resolve_directory_id( $directory_id );

		if ( isset( $this->form_fields_cache[ $directory_id ] ) ) {
			return $this->form_fields_cache[ $directory_id ];
		}

		if ( ! function_exists( 'directorist_get_listing_form_fields' ) || $directory_id <= 0 ) {
			$this->form_fields_cache[ $directory_id ] = [];
			return [];
		}

		$this->form_fields_cache[ $directory_id ] = array_values( directorist_get_listing_form_fields( $directory_id ) );
		return $this->form_fields_cache[ $directory_id ];
	}

	/**
	 * Locate one Directorist form field by field_key.
	 */
	private function find_form_field_by_key( int $directory_id, string $field_key ): array {
		foreach ( $this->get_form_fields( $directory_id ) as $field ) {
			if ( ( $field['field_key'] ?? '' ) === $field_key ) {
				return $field;
			}
		}

		return [];
	}

	/**
	 * Determine if a source value should be considered present.
	 *
	 * @param mixed $value Source value.
	 */
	private function source_has_value( $value ): bool {
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}

		return null !== $value && '' !== $value;
	}

	/**
	 * Apply one mapped destination.
	 *
	 * @param mixed $value Source value.
	 */
	private function apply_destination( int $post_id, int $directory_id, string $destination, $value ): bool {
		if ( 0 === strpos( $destination, 'special:' ) ) {
			return $this->apply_special_destination( $post_id, substr( $destination, 8 ), $value );
		}

		if ( 0 === strpos( $destination, 'taxonomy:' ) ) {
			return $this->apply_taxonomy_destination( $post_id, substr( $destination, 9 ), $value );
		}

		if ( 0 === strpos( $destination, 'field:' ) ) {
			return $this->apply_form_field_destination( $post_id, $directory_id, substr( $destination, 6 ), $value );
		}

		return false;
	}

	/**
	 * Apply one system-level destination.
	 *
	 * @param mixed $value Source value.
	 */
	private function apply_special_destination( int $post_id, string $destination, $value ): bool {
		switch ( $destination ) {
			case 'manual_lat':
				$lat = round( (float) $value, 6 );
				update_post_meta( $post_id, '_manual_lat', $lat );
				update_post_meta( $post_id, '_latitude', $lat );
				return true;

			case 'manual_lng':
				$lng = round( (float) $value, 6 );
				update_post_meta( $post_id, '_manual_lng', $lng );
				update_post_meta( $post_id, '_longitude', $lng );
				return true;

			case 'hide_map':
				$hide_map = $this->normalise_bool( $value );
				update_post_meta( $post_id, '_hide_map', $hide_map ? 1 : '' );
				if ( $hide_map ) {
					update_post_meta( $post_id, '_manual_lat', '' );
					update_post_meta( $post_id, '_manual_lng', '' );
				}
				return true;

			case 'directorist_rating':
				update_post_meta( $post_id, '_directorist_listing_rating', (float) $value );
				return true;

			case 'directorist_review_count':
				$count = (int) $value;
				update_post_meta( $post_id, '_user_ratings_total', $count );
				update_post_meta( $post_id, '_directorist_listing_review_count', $count );
				return true;

			case 'pricing_amount':
				$amount = $this->normalise_numeric( $value );
				if ( null === $amount ) {
					return false;
				}

				update_post_meta( $post_id, '_atbd_listing_pricing', 'price' );
				update_post_meta( $post_id, '_price', round( $amount, 2 ) );
				update_post_meta( $post_id, '_price_range', '' );
				return true;

			case 'pricing_range':
				$range = $this->normalise_price_range_value( $value );
				if ( '' === $range ) {
					return false;
				}

				update_post_meta( $post_id, '_atbd_listing_pricing', 'range' );
				update_post_meta( $post_id, '_price', '' );
				update_post_meta( $post_id, '_price_range', $range );
				return true;
		}

		return false;
	}

	/**
	 * Apply one taxonomy destination.
	 *
	 * @param mixed $value Source value.
	 */
	private function apply_taxonomy_destination( int $post_id, string $taxonomy, $value ): bool {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$terms = $this->normalise_taxonomy_terms( $value );
		if ( empty( $terms ) ) {
			return false;
		}

		$term_ids = [];
		foreach ( $terms as $term_value ) {
			$term_id = $this->resolve_term_id( $taxonomy, $term_value );
			if ( $term_id > 0 ) {
				$term_ids[] = $term_id;
			}
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
		if ( empty( $term_ids ) ) {
			return false;
		}

		$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, true );
		return ! is_wp_error( $result );
	}

	/**
	 * Apply one Directorist form-field destination.
	 *
	 * @param mixed $value Source value.
	 */
	private function apply_form_field_destination( int $post_id, int $directory_id, string $field_key, $value ): bool {
		$field = $this->find_form_field_by_key( $directory_id, $field_key );
		if ( empty( $field ) ) {
			return false;
		}

		$prepared = $this->prepare_value_for_field( $field, $value );
		if ( null === $prepared ) {
			return false;
		}

		$posted_data = [
			$field_key => $prepared,
		];

		$field_obj = null;
		if ( class_exists( '\Directorist\Fields\Fields' ) ) {
			$field_obj = \Directorist\Fields\Fields::create( $field );
		}

		if ( $field_obj && method_exists( $field_obj, 'validate' ) && ! $field_obj->validate( $posted_data ) ) {
			return false;
		}

		$clean_value = $field_obj && method_exists( $field_obj, 'sanitize' )
			? $field_obj->sanitize( $posted_data )
			: sanitize_text_field( (string) $prepared );

		$widget_key = (string) ( $field['widget_key'] ?? '' );

		switch ( $widget_key ) {
			case 'title':
				wp_update_post(
					[
						'ID'         => $post_id,
						'post_title' => (string) $clean_value,
					]
				);
				return true;

			case 'description':
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => (string) $clean_value,
					]
				);
				return true;

			case 'excerpt':
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_excerpt' => (string) $clean_value,
					]
				);
				update_post_meta( $post_id, '_excerpt', $clean_value );
				return true;

			default:
				update_post_meta( $post_id, '_' . $field_key, $clean_value );
				return true;
		}
	}

	/**
	 * Prepare a Google value for one Directorist field type.
	 *
	 * @param mixed $value Source value.
	 * @return mixed|null
	 */
	private function prepare_value_for_field( array $field, $value ) {
		$type = (string) ( $field['type'] ?? '' );

		switch ( $type ) {
			case 'checkbox':
				return $this->prepare_checkbox_value( $field, $value );

			case 'select':
			case 'radio':
				return $this->prepare_selectable_value( $field, $value );

			case 'number':
				$number = $this->normalise_numeric( $value );
				return null === $number ? null : (string) $number;

			case 'textarea':
			case 'wp_editor':
				return $this->stringify_value( $value, "\n" );

			case 'date':
			case 'time':
			case 'color_picker':
			case 'tel':
			case 'email':
			case 'url':
			case 'text':
			default:
				return $this->stringify_value( $value );
		}
	}

	/**
	 * Prepare a scalar value for select/radio fields.
	 *
	 * @param mixed $value Source value.
	 */
	private function prepare_selectable_value( array $field, $value ): ?string {
		$candidates = $this->value_to_candidates( $value );
		$options    = $this->get_field_options( $field );

		foreach ( $candidates as $candidate ) {
			$matched = $this->match_field_option( $candidate, $options );
			if ( '' !== $matched ) {
				return $matched;
			}
		}

		return null;
	}

	/**
	 * Prepare a value array for checkbox fields.
	 *
	 * @param mixed $value Source value.
	 * @return array<int,string>|null
	 */
	private function prepare_checkbox_value( array $field, $value ): ?array {
		$candidates = $this->value_to_candidates( $value, true );
		$options    = $this->get_field_options( $field );
		$matched    = [];

		foreach ( $candidates as $candidate ) {
			$option = $this->match_field_option( $candidate, $options );
			if ( '' !== $option ) {
				$matched[] = $option;
			}
		}

		$matched = array_values( array_unique( $matched ) );
		return empty( $matched ) ? null : $matched;
	}

	/**
	 * Return the option values for one field.
	 *
	 * @return string[]
	 */
	private function get_field_options( array $field ): array {
		$options = $field['options'] ?? [];
		if ( ! is_array( $options ) ) {
			return [];
		}

		$values = [];
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['option_value'] ) ) {
				continue;
			}

			$values[] = str_replace( '&lt;', '<', (string) $option['option_value'] );
		}

		return $values;
	}

	/**
	 * Match one source candidate against field options.
	 *
	 * @param string[] $options
	 */
	private function match_field_option( string $candidate, array $options ): string {
		$candidate = trim( $candidate );
		if ( '' === $candidate ) {
			return '';
		}

		foreach ( $options as $option ) {
			if ( $candidate === $option ) {
				return $option;
			}
		}

		$needle = $this->normalise_comparison_token( $candidate );
		foreach ( $options as $option ) {
			if ( $needle === $this->normalise_comparison_token( $option ) ) {
				return $option;
			}
		}

		return '';
	}

	/**
	 * Convert one source value into candidate strings for flexible matching.
	 *
	 * @param mixed $value Source value.
	 * @return string[]
	 */
	private function value_to_candidates( $value, bool $split_scalar = false ): array {
		$candidates = [];

		if ( is_array( $value ) ) {
			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$candidates ): void {
					if ( is_scalar( $item ) || is_bool( $item ) ) {
						$candidates[] = is_bool( $item ) ? ( $item ? 'Yes' : 'No' ) : (string) $item;
					}
				}
			);
		} elseif ( is_bool( $value ) ) {
			$candidates[] = $value ? 'Yes' : 'No';
			$candidates[] = $value ? '1' : '0';
			$candidates[] = $value ? 'true' : 'false';
		} elseif ( null !== $value ) {
			$candidates[] = (string) $value;
		}

		if ( $split_scalar ) {
			$expanded = [];
			foreach ( $candidates as $candidate ) {
				$expanded[] = $candidate;
				if ( strpos( $candidate, ',' ) !== false ) {
					$parts = array_map( 'trim', explode( ',', $candidate ) );
					$expanded = array_merge( $expanded, array_filter( $parts ) );
				}
			}
			$candidates = $expanded;
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'trim', $candidates ),
					static function ( string $candidate ): bool {
						return '' !== $candidate;
					}
				)
			)
		);
	}

	/**
	 * Convert a source value into plain text.
	 *
	 * @param mixed  $value Source value.
	 * @param string $glue  Glue used for arrays.
	 */
	private function stringify_value( $value, string $glue = ', ' ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_array( $value ) ) {
			if ( $this->is_flat_scalar_array( $value ) ) {
				$items = array_map(
					static function ( $item ): string {
						return is_bool( $item ) ? ( $item ? '1' : '0' ) : (string) $item;
					},
					$value
				);
				return implode( $glue, array_filter( array_map( 'trim', $items ) ) );
			}

			$json = wp_json_encode( $value );
			return is_string( $json ) ? $json : '';
		}

		return trim( (string) $value );
	}

	/**
	 * Check whether an array only contains scalar leaf values.
	 *
	 * @param array<int|string,mixed> $value
	 */
	private function is_flat_scalar_array( array $value ): bool {
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) && ! is_bool( $item ) && null !== $item ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Convert a source value into a float when possible.
	 *
	 * @param mixed $value Source value.
	 */
	private function normalise_numeric( $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}

		if ( is_bool( $value ) || null === $value ) {
			return null;
		}

		if ( is_array( $value ) ) {
			return null;
		}

		$raw_string = trim( (string) $value );
		$string     = $raw_string;
		if ( '' === $string ) {
			return null;
		}

		$string = preg_replace( '/[^0-9,\.\-]/', '', $string );
		if ( null === $string || '' === $string ) {
			return null;
		}

		if ( strpos( $string, ',' ) !== false && strpos( $string, '.' ) === false ) {
			$string = str_replace( ',', '.', $string );
		} else {
			$string = str_replace( ',', '', $string );
		}

		// Number fields often receive phone-like input from Google such as
		// "01712-345678" or "(650) 253-0000". Remove any non-leading hyphens so
		// Directorist's numeric validation can still accept the value.
		$string = preg_replace( '/(?!^)-/', '', $string );

		if ( is_numeric( $string ) ) {
			return (float) $string;
		}

		$digit_count      = preg_match_all( '/\d/', $raw_string, $matches );
		$looks_phone_like = $digit_count >= 7
			&& (
				false !== strpos( $raw_string, '+' )
				|| false !== strpos( $raw_string, '(' )
				|| false !== strpos( $raw_string, ')' )
				|| preg_match( '/\d-\d/', $raw_string )
				|| preg_match( '/\d\s+\d/', $raw_string )
			);

		if ( $looks_phone_like ) {
			$digits_only = preg_replace( '/\D+/', '', $raw_string );
			if ( null !== $digits_only && '' !== $digits_only && is_numeric( $digits_only ) ) {
				return (float) $digits_only;
			}
		}

		return null;
	}

	/**
	 * Convert common truthy/falsy strings into a boolean.
	 *
	 * @param mixed $value Source value.
	 */
	private function normalise_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value === 1;
		}

		$string = strtolower( trim( (string) $value ) );
		return in_array( $string, [ '1', 'true', 'yes', 'y', 'on' ], true );
	}

	/**
	 * Map a Google price level value into Directorist's supported price ranges.
	 *
	 * @param mixed $value Source value.
	 */
	private function normalise_price_range_value( $value ): string {
		$string = strtolower( trim( $this->stringify_value( $value ) ) );
		if ( '' === $string ) {
			return '';
		}

		$direct_values = [ 'skimming', 'moderate', 'economy', 'bellow_economy' ];
		if ( in_array( $string, $direct_values, true ) ) {
			return $string;
		}

		$token = $this->normalise_comparison_token( $string );

		if ( false !== strpos( $token, 'very expensive' ) || false !== strpos( $token, 'expensive' ) || false !== strpos( $token, 'premium' ) ) {
			return 'skimming';
		}

		if ( false !== strpos( $token, 'moderate' ) ) {
			return 'moderate';
		}

		if ( false !== strpos( $token, 'inexpensive' ) || false !== strpos( $token, 'economy' ) ) {
			return 'economy';
		}

		if ( false !== strpos( $token, 'free' ) || false !== strpos( $token, 'cheap' ) || false !== strpos( $token, 'budget' ) ) {
			return 'bellow_economy';
		}

		return '';
	}

	/**
	 * Normalise one value into taxonomy term candidates.
	 *
	 * @param mixed $value Source value.
	 * @return string[]
	 */
	private function normalise_taxonomy_terms( $value ): array {
		if ( is_array( $value ) ) {
			$terms = [];
			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$terms ): void {
					if ( is_scalar( $item ) || is_bool( $item ) ) {
						$terms[] = is_bool( $item ) ? ( $item ? 'Yes' : 'No' ) : (string) $item;
					}
				}
			);

			return array_values(
				array_unique(
					array_filter(
						array_map( 'trim', $terms ),
						static function ( string $term ): bool {
							return '' !== $term;
						}
					)
				)
			);
		}

		$term = trim( $this->stringify_value( $value ) );
		return '' === $term ? [] : [ $term ];
	}

	/**
	 * Resolve a term ID by ID, name, or slug, creating the term when needed.
	 */
	private function resolve_term_id( string $taxonomy, string $value ): int {
		if ( '' === trim( $value ) ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			$term = get_term( (int) $value, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		$existing = get_term_by( 'name', $value, $taxonomy );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		$existing = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		$created = wp_insert_term( $value, $taxonomy );
		if ( is_wp_error( $created ) ) {
			if ( 'term_exists' === $created->get_error_code() ) {
				return (int) $created->get_error_data();
			}

			return 0;
		}

		return (int) ( $created['term_id'] ?? 0 );
	}

	/**
	 * Normalise a string for looser comparisons.
	 */
	private function normalise_comparison_token( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = str_replace( [ '_', '-' ], ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Return the Directorist category taxonomy slug.
	 */
	private function get_category_taxonomy(): string {
		return defined( 'ATBDP_CATEGORY' ) ? ATBDP_CATEGORY : 'at_biz_dir-category';
	}

	/**
	 * Return the Directorist location taxonomy slug.
	 */
	private function get_location_taxonomy(): string {
		return defined( 'ATBDP_LOCATION' ) ? ATBDP_LOCATION : 'at_biz_dir-location';
	}

	/**
	 * Return the Directorist tag taxonomy slug.
	 */
	private function get_tag_taxonomy(): string {
		return defined( 'ATBDP_TAGS' ) ? ATBDP_TAGS : 'at_biz_dir-tags';
	}
}
