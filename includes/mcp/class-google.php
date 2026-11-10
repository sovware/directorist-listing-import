<?php
/** Google UI parity: staged details/photos and native saved mapping translation. */
defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Google {
    private static $evidence = [];

    public static function sanitize( array $data ): array {
        $safe = [];
        $allowed = array_merge( array_keys( ( new \DLIG\Field_Mapping() )->get_source_fields() ), [ 'opening_hours', 'reviews', 'photo_name' ] );
        foreach ( $allowed as $key ) {
            if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) { continue; }
            $value = $data[ $key ];
            if ( is_string( $value ) ) { $value = substr( sanitize_textarea_field( $value ), 0, 'editorial_summary' === $key ? 5000 : 1000 ); }
            if ( in_array( $key, [ 'website', 'google_maps_uri' ], true ) ) { $parts = wp_parse_url( (string) $value ); $value = ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) ? esc_url_raw( strtok( (string) $value, '?#' ) ) : ''; }
            if ( 'reviews' === $key ) {
                $value = array_map( static function ( $r ) { return [ 'author_name' => substr( sanitize_text_field( $r['author_name'] ?? '' ), 0, 200 ), 'text' => substr( sanitize_textarea_field( $r['text'] ?? '' ), 0, 5000 ), 'rating' => max( 0, min( 5, (int) ( $r['rating'] ?? 0 ) ) ), 'time' => (int) ( $r['time'] ?? 0 ), 'language_code' => substr( sanitize_text_field( $r['language_code'] ?? '' ), 0, 20 ) ]; }, array_slice( is_array( $value ) ? $value : [], 0, 5 ) );
            }
            if ( 'opening_hours' === $key ) { $value = is_array( $value ) && strlen( wp_json_encode( $value ) ) <= 10000 ? $value : []; }
            if ( 'types' === $key ) { $value = array_map( 'sanitize_text_field', array_slice( is_array( $value ) ? $value : [], 0, 100 ) ); }
            $safe[ $key ] = $value;
        }
        return $safe;
    }

    public static function public_fields( array $candidate ): array {
        return array_intersect_key( $candidate['google'] ?? [], ( new \DLIG\Field_Mapping() )->get_source_fields() );
    }

    private static function candidate( array $args ): array {
        $snapshot = DLI_MCP_Service::snapshot( $args['snapshot_id'] );
        foreach ( $snapshot['candidates'] as $candidate ) { if ( $candidate['candidate_id'] === $args['candidate_id'] ) { return [ $snapshot, $candidate ]; } }
        DLI_MCP_Service::check( false, 'This candidate is unavailable.', 'not_found' );
    }

    public static function prepare( string $action, array $args ): array {
        [ $snapshot, $candidate ] = self::candidate( $args );
        if ( 'places' === $candidate['source'] ) { DLI_MCP_Service::check( '' !== ( new \DLIG\Settings() )->get_api_key(), 'Configure a Google Places key.', 'provider_missing' ); }
        if ( 'get-place-details' === $action ) {
            DLI_MCP_Service::check( 'places' === $candidate['source'], 'Select a Google Places candidate.' );
            DLI_MCP_Service::check( DLI_MCP_Service::quota_count() < 100, 'The hourly Places request limit was reached.', 'provider_quota' );
        } else {
            if ( 'places' === $candidate['source'] ) { DLI_MCP_Service::check( DLI_MCP_Service::quota_count() < 100, 'The hourly Places request limit was reached.', 'provider_quota' ); }
            DLI_MCP_Service::check( current_user_can( 'upload_files' ), 'Media upload permission is required.', 'forbidden' );
            DLI_MCP_Service::check( ! empty( $candidate['google']['photo_name'] ) || ! empty( $candidate['photo_url'] ), 'Fetch Google details first, or select a feed candidate with an image enclosure.', 'photo_missing' );
        }
        return [ 'state' => [ $args, $snapshot['fingerprint'], $candidate, get_option( \DLIG\Settings::OPT_API_KEY, '' ) ], 'preview' => [ 'source' => $snapshot['source'], 'directory_id' => $snapshot['directory_id'], 'limit' => 1, 'network_effect' => 'get-place-details' === $action ? 'One potentially billable Google Place Details request; stages mapping fields, opening hours, reviews and the first photo reference.' : 'Download one candidate image into the media library. Google photos require one potentially billable media lookup and one image download. Returns an attachment ID to use in import-candidates.', 'media_policy' => 'Public image only; JPEG, PNG, GIF or WebP, at most 10 MiB. No credentials or provider photo URLs are returned.' ], 'execute' => static function () use ( $action, $args, $snapshot, $candidate ) {
            $evidence = self::$evidence[ DLI_MCP_Service::hash( $args ) ] ?? null;
            DLI_MCP_Service::check( $evidence, 'The original provider result is unavailable.', 'outcome_unknown' );
            $id = $evidence['id'];
            if ( 'import-photo' === $action ) { return self::save_photo( $id, $args, $candidate, $evidence['data']['bytes'], false ); }
            $detail = $evidence['data'];
            $updated = $candidate;
            $updated['google'] = array_replace( $candidate['google'] ?? [], self::sanitize( $detail ) );
            $updated['fields'] = [ 'title' => $updated['google']['name'] ?? $candidate['fields']['title'], 'description' => $updated['google']['editorial_summary'] ?? '', 'address' => $updated['google']['formatted_address'] ?? '', 'phone' => $updated['google']['phone'] ?? '', 'email' => '', 'website' => $updated['google']['website'] ?? '' ];
            $new = $snapshot; $new['candidates'] = [ $updated ]; $new['created'] = time(); $new['expires'] = time() + DLI_MCP_Service::TTL; $new['continuation'] = ''; $new['args_hash'] = DLI_MCP_Service::hash( $args );
            DLI_MCP_Store::put( $id, 'snapshot', $new, 'ready', $new['expires'] );
            return [ 'snapshot_id' => $id, 'candidate_count' => 1, 'expires' => $new['expires'], 'quality' => $new['quality'], 'continuation' => '', 'warnings' => [ 'Review the staged detail fields before importing.' ] ];
        } ];
    }

    public static function dispatch( string $action, string $id, array $args, array $worker_candidate = [] ): array {
        try {
            if ( $worker_candidate ) { $candidate = $worker_candidate; }
            else { [ $snapshot, $candidate ] = self::candidate( $args ); }
            if ( 'get-place-details' === $action ) {
                DLI_MCP_Service::check( DLI_MCP_Service::quota_count() <= 100, 'The hourly Places request limit was reached.', 'provider_quota' );
                DLI_MCP_Service::check( preg_match( '/^[A-Za-z0-9_-]{1,255}$/', $candidate['identity'] ), 'The place identifier is invalid.' );
                $key = ( new \DLIG\Settings() )->get_api_key();
                DLI_MCP_Service::check( '' !== $key, 'Configure a Google Places key.', 'provider_missing' );
                $response = DLI_MCP_Source::request( \DLIG\Google_Places_Client::DETAIL_ENDPOINT . rawurlencode( $candidate['identity'] ), [ 'method' => 'GET', '_no_redirect' => true, 'headers' => [ 'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'id,displayName,formattedAddress,shortFormattedAddress,location,nationalPhoneNumber,internationalPhoneNumber,websiteUri,googleMapsUri,editorialSummary,regularOpeningHours,rating,userRatingCount,reviews,photos,primaryType,primaryTypeDisplayName,types,businessStatus,priceLevel,priceRange,plusCode,pureServiceAreaBusiness' ] ] );
                if ( 'settled' !== $response['state'] ) { return [ 'state' => $response['state'], 'reference' => $response['reason'] ]; }
                $raw = json_decode( $response['body'], true );
                DLI_MCP_Service::check( is_array( $raw ) && ( $raw['id'] ?? '' ) === $candidate['identity'], 'The provider returned a different place.', 'provider_response_invalid' );
                self::$evidence[ DLI_MCP_Service::hash( $args ) ] = [ 'id' => $id, 'data' => ( new \DLIG\Google_Places_Client() )->normalise_detail( $raw ) ];
            } else {
                $url = $candidate['photo_url'] ?? ''; $google = 'places' === $candidate['source'];
                if ( $google ) {
                    DLI_MCP_Service::check( DLI_MCP_Service::quota_count() <= 100, 'The hourly Places request limit was reached.', 'provider_quota' );
                    $name = $candidate['google']['photo_name'] ?? '';
                    DLI_MCP_Service::check( 0 === strpos( $name, 'places/' . $candidate['identity'] . '/photos/' ) && preg_match( '#^places/[A-Za-z0-9_-]+/photos/[A-Za-z0-9_-]+$#', $name ), 'The photo reference is invalid.' );
                    $key = ( new \DLIG\Settings() )->get_api_key();
                    DLI_MCP_Service::check( '' !== $key, 'Configure a Google Places key.', 'provider_missing' );
                    $response = DLI_MCP_Source::request( \DLIG\Google_Places_Client::PHOTO_ENDPOINT . $name . '/media?maxWidthPx=1200&maxHeightPx=1200&skipHttpRedirect=true', [ 'method' => 'GET', '_no_redirect' => true, 'headers' => [ 'X-Goog-Api-Key' => $key ] ] );
                    if ( 'settled' !== $response['state'] ) { return [ 'state' => $response['state'], 'reference' => $response['reason'] ]; }
                    $url = json_decode( $response['body'], true )['photoUri'] ?? '';
                }
                $response = DLI_MCP_Source::request( $url, [ '_trusted_photo' => $google, '_max_bytes' => 10 * 1024 * 1024 ] );
                if ( 'settled' !== $response['state'] ) { return [ 'state' => $response['state'], 'reference' => $response['reason'] ]; }
                self::$evidence[ DLI_MCP_Service::hash( $args ) ] = [ 'id' => $id, 'data' => [ 'bytes' => $response['body'] ] ];
            }
            return [ 'state' => 'settled', 'reference' => $id ];
        } catch ( \Directorist\MCP\Support\Failure $error ) { return [ 'state' => 'failed', 'reference' => $error->reason ]; }
    }

    private static function save_photo( string $id, array $args, array $candidate, string $bytes, bool $atomic = true ): array {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $info = @getimagesizefromstring( $bytes );
        $extensions = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
        DLI_MCP_Service::check( $info && isset( $extensions[ $info['mime'] ] ) && $info[0] * $info[1] <= 40000000, 'The photo is not a supported bounded image.', 'invalid_image' );
        $upload = wp_upload_bits( 'dli-mcp-' . $id . '.' . $extensions[ $info['mime'] ], null, $bytes );
        DLI_MCP_Service::check( empty( $upload['error'] ), 'The photo could not be stored.', 'media_write_failed' );
        try {
            $write = static function () use ( $id, $args, $candidate, $info, $upload ) {
                $attachment = wp_insert_attachment( [ 'post_title' => $candidate['fields']['title'], 'post_mime_type' => $info['mime'], 'post_status' => 'inherit', 'post_author' => get_current_user_id() ], $upload['file'], 0, true );
                DLI_MCP_Service::check( ! is_wp_error( $attachment ) && $attachment > 0, 'Could not create the photo attachment.', 'media_write_failed' );
                // Generate metadata after the durable original file exists; no provider work occurs here.
                wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $upload['file'] ) );
                update_post_meta( $attachment, '_dli_mcp_photo_operation', $id );
                $result = [ 'attachment_id' => (int) $attachment, 'candidate_id' => $candidate['candidate_id'] ];
                DLI_MCP_Store::put( $id, 'photo-result', [ 'actor' => get_current_user_id(), 'args_hash' => DLI_MCP_Service::hash( $args ), 'result' => $result ], 'completed' );
                return $result;
            };
            return $atomic ? \Directorist\MCP\Safety\Transaction::atomic( $write, DLI_MCP_Service::tables(), [ DLI_MCP_Store::class, 'invalidate' ] ) : $write();
        } catch ( \Throwable $error ) { wp_delete_file( $upload['file'] ); throw $error; }
    }

    /** A job's optional photo has its own durable, non-repeating provider intent. */
    public static function photo_for_item( array $job, array $item ): array {
        $empty = [ 'attachment_id' => 0, 'warning' => '' ];
        $candidate = $item['candidate'];
        $definitions = \Directorist\MCP\Tool_Providers\Listings\Listing_Schema::definitions( $job['args']['directory_id'] );
        if ( empty( $definitions['images']['writable'] ) ) { return [ 'attachment_id' => 0, 'warning' => 'images_not_supported' ]; }
        if ( empty( $candidate['google']['photo_name'] ) && empty( $candidate['photo_url'] ) ) { return $empty; }
        if ( ! empty( $item['plan']['existing_id'] ) && ( get_post_thumbnail_id( $item['plan']['existing_id'] ) || get_post_meta( $item['plan']['existing_id'], '_listing_prv_img', true ) ) ) { return $empty; }
        $id = substr( hash( 'sha256', $job['job_id'] . ':' . $candidate['candidate_id'] . ':photo' ), 0, 36 );
        try {
            $record = DLI_MCP_Store::get( $id, 'photo-result' );
            \Directorist\MCP\Runtime\Access_Control::attachment( $record['result']['attachment_id'], true );
            return [ 'attachment_id' => $record['result']['attachment_id'], 'warning' => '' ];
        } catch ( \Directorist\MCP\Support\Failure $error ) {
            if ( 'not_found' !== $error->reason ) { return [ 'attachment_id' => 0, 'warning' => $error->reason ]; }
        }
        try {
            DLI_MCP_Store::get( 'photo:' . $id, 'photo-intent' );
            return [ 'attachment_id' => 0, 'warning' => 'photo_outcome_unknown' ];
        } catch ( \Directorist\MCP\Support\Failure $error ) {
            if ( 'not_found' !== $error->reason ) { return [ 'attachment_id' => 0, 'warning' => $error->reason ]; }
        }
        try {
            DLI_MCP_Service::check( current_user_can( 'upload_files' ), 'Media upload permission is required.', 'forbidden' );
            if ( 'places' === $candidate['source'] ) { DLI_MCP_Service::check( DLI_MCP_Service::quota_count() < 100, 'The hourly Places limit was reached.', 'provider_quota' ); }
            DLI_MCP_Store::put( 'photo:' . $id, 'photo-intent', [ 'actor' => get_current_user_id(), 'created' => time(), 'google' => 'places' === $candidate['source'], 'job_id' => $job['job_id'] ], 'running' );
            $photo_args = [ 'snapshot_id' => $job['snapshot_id'], 'candidate_id' => $candidate['candidate_id'] ];
            $response = self::dispatch( 'import-photo', $id, $photo_args, $candidate );
            if ( 'settled' !== $response['state'] ) { return [ 'attachment_id' => 0, 'warning' => $response['reference'] ]; }
            $evidence = self::$evidence[ DLI_MCP_Service::hash( $photo_args ) ];
            $result = self::save_photo( $id, $photo_args, $candidate, $evidence['data']['bytes'] );
            return [ 'attachment_id' => $result['attachment_id'], 'warning' => '' ];
        } catch ( \Throwable $error ) { return [ 'attachment_id' => 0, 'warning' => DLI_MCP_Service::reason( $error ) ]; }
    }

    public static function reconcile( string $id, array $args ): array {
        try {
            $record = DLI_MCP_Store::get( $id, 'photo-result' );
            $settled = $record['args_hash'] === DLI_MCP_Service::hash( $args ) && get_post( $record['result']['attachment_id'] );
        } catch ( \Throwable $error ) {
            try { $snapshot = DLI_MCP_Store::get( $id, 'snapshot' ); $settled = $snapshot['args_hash'] === DLI_MCP_Service::hash( $args ); }
            catch ( \Throwable $error ) { $settled = false; }
        }
        return [ 'state' => $settled ? 'settled' : 'unknown', 'reference' => $id ];
    }
    public static function verify( string $id, array $args, array $result ): bool {
        try {
            if ( isset( $result['attachment_id'] ) ) {
                $record = DLI_MCP_Store::get( $id, 'photo-result' );
                \Directorist\MCP\Runtime\Access_Control::attachment( $result['attachment_id'], true );
                return $record['args_hash'] === DLI_MCP_Service::hash( $args ) && $record['result'] === $result;
            }
            $snapshot = DLI_MCP_Store::get( $id, 'snapshot' );
            return $snapshot['args_hash'] === DLI_MCP_Service::hash( $args ) && $id === $result['snapshot_id'] && 1 === $result['candidate_count'];
        } catch ( \Throwable $error ) { return false; }
    }

    /** Translate saved UI field destinations into the native listing validator. */
    public static function mapped_fields( array $candidate, int $directory ): array {
        $mapper = new \DLIG\Field_Mapping(); $fields = []; $extras = []; $mapping = [];
        $definitions = \Directorist\MCP\Tool_Providers\Listings\Listing_Schema::definitions( $directory );
        $seen_terms = [];
        foreach ( $mapper->get_effective_mapping( $directory ) as $source => $destination ) {
            if ( 'skip' === $destination || ! array_key_exists( $source, $candidate['google'] ?? [] ) ) { continue; }
            $value = $candidate['google'][ $source ];
            if ( null === $value || '' === $value || [] === $value ) { continue; }
            $mapping[] = [ 'source' => $source, 'target' => $destination ];
            if ( 0 === strpos( $destination, 'field:' ) ) {
                $key = substr( $destination, 6 ); $match = null;
                foreach ( $definitions as $definition ) { if ( $definition['native_key'] === $key && $definition['writable'] ) { $match = $definition; break; } }
                DLI_MCP_Service::check( $match, 'A saved Google destination is not admitted by the current native directory.', 'invalid_mapping' );
                $prepared = $mapper->prepare_import_value( $directory, $key, $value );
                DLI_MCP_Service::check( null !== $prepared, 'The Google value is incompatible with its destination.', 'invalid_mapping' );
                $fields[ $match['key'] ] = $prepared;
            } elseif ( 0 === strpos( $destination, 'taxonomy:' ) ) {
                $taxonomy = substr( $destination, 9 );
                \Directorist\MCP\Runtime\Access_Control::term_cap( $taxonomy, 'assign' );
                $names = is_array( $value ) ? $value : preg_split( '/[,;|]/', (string) $value );
                foreach ( array_slice( $names, 0, 100 ) as $name ) {
                    $name = sanitize_text_field( trim( (string) $name ) ); if ( '' === $name ) { continue; }
                    $term_key = $taxonomy . ':' . strtolower( $name );
                    if ( isset( $seen_terms[ $term_key ] ) ) { continue; }
                    $seen_terms[ $term_key ] = true;
                    $term = ctype_digit( $name ) ? get_term( (int) $name, $taxonomy ) : get_term_by( 'name', $name, $taxonomy );
                    if ( is_wp_error( $term ) ) { $term = false; }
                    if ( ! $term ) { $term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy ); }
                    if ( ! $term ) {
                        $plan = \Directorist\MCP\Tool_Providers\Taxonomies\Write_Callbacks::prepare( 'create-term', [ 'taxonomy' => $taxonomy, 'name' => $name, 'directories' => [ $directory ], 'dry_run' => true ] );
                        $extras[] = [ 'taxonomy' => $taxonomy, 'name' => $name, 'new' => true ];
                    } else {
                        DLI_MCP_Service::check( in_array( $directory, \Directorist\MCP\Tool_Providers\Taxonomies\Term_Validator::scope( $term->term_id ), true ), 'A mapped term belongs to another directory.', 'invalid_mapping' );
                        $key = [ ATBDP_CATEGORY => 'categories', ATBDP_LOCATION => 'locations', ATBDP_TAGS => 'tags' ][ $taxonomy ];
                        $fields[ $key ][] = (int) $term->term_id;
                    }
                }
            } else {
                $bounds = [ 'special:manual_lat' => [ -90, 90 ], 'special:manual_lng' => [ -180, 180 ], 'special:directorist_rating' => [ 0, 5 ], 'special:directorist_review_count' => [ 0, PHP_INT_MAX ] ];
                if ( isset( $bounds[ $destination ] ) ) { DLI_MCP_Service::check( is_numeric( $value ) && $value >= $bounds[ $destination ][0] && $value <= $bounds[ $destination ][1], 'A Google system value is outside its bounds.', 'invalid_mapping' ); }
                $extras[] = [ 'destination' => $destination, 'value' => $value ];
            }
        }
        return [ 'fields' => $fields, 'extras' => $extras, 'mapping' => $mapping ];
    }

    public static function commit_extras( int $listing, int $directory, array $extras ): void {
        $mapper = new \DLIG\Field_Mapping();
        foreach ( $extras as $extra ) {
            if ( isset( $extra['taxonomy'] ) ) {
                $plan = \Directorist\MCP\Tool_Providers\Taxonomies\Write_Callbacks::prepare( 'create-term', [ 'taxonomy' => $extra['taxonomy'], 'name' => $extra['name'], 'directories' => [ $directory ], 'dry_run' => true ] );
                $result = $plan['execute']();
                $assigned = wp_set_object_terms( $listing, [ (int) $result['term_id'] ], $extra['taxonomy'], true );
                DLI_MCP_Service::check( ! is_wp_error( $assigned ), 'Could not assign a mapped term.', 'native_write_failed' );
            } else {
                DLI_MCP_Service::check( $mapper->apply_import_destination( $listing, $directory, $extra['destination'], $extra['value'] ), 'A mapped Google system field could not be written.', 'invalid_mapping' );
            }
        }
    }
}
