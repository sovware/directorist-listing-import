<?php
/** Native validation and a local-only listing adapter shared by preview and worker. */
use Directorist\MCP\Tool_Providers\Listings\Listing_Schema;
use Directorist\MCP\Tool_Providers\Listings\Listing_Policy;
use Directorist\MCP\Tool_Providers\Listings\Write_Callbacks;

defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Listing_Writer {
    const SOURCES = [ 'title', 'description', 'address', 'phone', 'email', 'website' ];

    public static function candidate( string $source, string $identity, array $fields ): array {
        $safe = [];
        foreach ( self::SOURCES as $key ) {
            $value = is_scalar( $fields[ $key ] ?? null ) ? (string) $fields[ $key ] : '';
            $value = 'description' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
            $safe[ $key ] = substr( $value, 0, 'description' === $key ? 5000 : 1000 );
        }
        // Never stage signed URLs, provider photos or credential-bearing website URLs.
        if ( $safe['website'] ) {
            $parts = wp_parse_url( $safe['website'] );
            $safe['website'] = ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) ? esc_url_raw( strtok( $safe['website'], '?#' ) ) : '';
        }
        // An overlong identity is invalid; truncation could merge distinct sources.
        return [ 'candidate_id' => substr( hash( 'sha256', $source . ':' . $identity ), 0, 40 ), 'source' => $source, 'identity' => strlen( $identity ) <= 2000 ? $identity : '', 'fields' => $safe ];
    }

    public static function directory( int $id, int $category = 0 ): void {
        \Directorist\MCP\Support\Validation::directory( $id );
        if ( $category ) {
            $term = \Directorist\MCP\Tool_Providers\Taxonomies\Term_Validator::term( ATBDP_CATEGORY, $category );
            DLI_MCP_Service::check( $term && in_array( $id, \Directorist\MCP\Tool_Providers\Taxonomies\Term_Validator::scope( $category ), true ), 'The category is incompatible with the directory.', 'invalid_category' );
            \Directorist\MCP\Runtime\Access_Control::term_cap( ATBDP_CATEGORY, 'assign' );
        }
    }

    public static function mappings( int $directory ): array {
        $result = [];
        foreach ( Listing_Schema::definitions( $directory ) as $key => $definition ) {
            if ( ! $definition['writable'] || ! in_array( $definition['schema']['type'], [ 'string', 'number', 'integer', 'boolean' ], true ) || in_array( $definition['widget'], [ 'file', 'pricing', 'image_upload', 'business_hours', 'review' ], true ) ) { continue; }
            $result[ $key ] = $definition;
        }
        return $result;
    }

    public static function fingerprint( int $directory ): string {
        return DLI_MCP_Service::hash( [ Listing_Schema::schema( [ 'directory_id' => $directory ] ), get_option( 'active_plugins', [] ), get_site_option( 'active_sitewide_plugins', [] ), get_option( 'directorist_mcp_epoch', '' ), get_option( 'directorist_mcp_extensions_epoch', '' ), get_site_option( 'directorist_mcp_extensions_epoch', '' ), ATBDP_VERSION, DLI_VERSION, DLI_MCP_Integration::CONTRACT ] );
    }

    public static function duplicate( array $candidate, int $directory ): int {
        $keys = 'places' === $candidate['source'] ? [ '_google_place_id' ] : [ DLI_META_SOURCE_URL, DLI_LEGACY_META_SOURCE_URL ];
        $meta = [ 'relation' => 'OR' ];
        foreach ( $keys as $key ) { $meta[] = [ 'key' => $key, 'value' => $candidate['identity'] ]; }
        $ids = get_posts( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => [ 'draft', 'pending', 'publish', 'private', 'future', 'trash' ], 'fields' => 'ids', 'posts_per_page' => 2, 'meta_query' => $meta, 'tax_query' => [ [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'field' => 'term_id', 'terms' => [ $directory ] ] ], 'orderby' => 'ID', 'order' => 'ASC' ] );
        DLI_MCP_Service::check( count( $ids ) < 2, 'Multiple native duplicates require manual resolution.', 'ambiguous_duplicate' );
        return (int) ( $ids[0] ?? 0 );
    }

    /** Each plan is independent: no earlier item's data is available here. */
    public static function plan( array $candidate, array $args, array $item ): array {
        $directory = $args['directory_id']; self::directory( $directory, $args['category_id'] ?? 0 );
        DLI_MCP_Service::check( $candidate['identity'] && $candidate['fields']['title'], 'A stable source identity and title are required.', 'validation_failed' );
        $existing = self::duplicate( $candidate, $directory );
        if ( $existing ) { DLI_MCP_Service::check( current_user_can( 'edit_post', $existing ), 'The matching listing is not editable.', 'permission_blocked' ); }
        $decision = $existing ? ( 'update_existing' === ( $args['duplicate_mode'] ?? 'find_new_only' ) ? 'update' : 'skip' ) : 'create';
        $mappings = self::mappings( $directory ); $fields = []; $mapping = $args['mapping'] ?? [];
        if ( ! $mapping ) {
            foreach ( self::SOURCES as $source ) { if ( isset( $mappings[ $source ] ) ) { $mapping[] = [ 'source' => $source, 'target' => $source ]; } }
        }
        $seen = [];
        foreach ( $mapping as $map ) {
            DLI_MCP_Service::check( in_array( $map['source'], self::SOURCES, true ) && isset( $mappings[ $map['target'] ] ) && ! isset( $seen[ $map['target'] ] ), 'A mapping is unsupported, inactive or repeated.', 'invalid_mapping' );
            $seen[ $map['target'] ] = true;
            $value = $candidate['fields'][ $map['source'] ];
            if ( '' === $value && $existing ) { continue; }
            $type = $mappings[ $map['target'] ]['schema']['type'];
            if ( in_array( $type, [ 'number', 'integer' ], true ) ) {
                DLI_MCP_Service::check( is_numeric( $value ), 'The mapped numeric value is invalid.', 'validation_failed' );
                $value = 'integer' === $type ? (int) $value : (float) $value;
            } elseif ( 'boolean' === $type ) {
                DLI_MCP_Service::check( in_array( $value, [ 'true', 'false', '1', '0' ], true ), 'The mapped boolean is invalid.', 'validation_failed' );
                $value = in_array( $value, [ 'true', '1' ], true );
            }
            $fields[ $map['target'] ] = $value;
        }
        if ( ! empty( $args['category_id'] ) ) { $fields['categories'] = $existing ? [ 'add' => [ $args['category_id'] ] ] : [ $args['category_id'] ]; }
        if ( ! empty( $item['attachment_ids'] ) ) {
            DLI_MCP_Service::check( isset( Listing_Schema::definitions( $directory )['images'] ), 'This directory does not admit images.', 'invalid_mapping' );
            foreach ( $item['attachment_ids'] as $attachment ) { \Directorist\MCP\Runtime\Access_Control::attachment( $attachment, true ); }
            $fields['images'] = $item['attachment_ids'];
        }
        $native = [ 'dry_run' => true, 'directory_id' => $directory, 'status' => $args['status'] ?? 'draft', 'consents' => $args['consents'] ?? [] ];
        if ( $existing ) { $native += [ 'listing_id' => $existing, 'changes' => $fields ]; }
        else { $native['fields'] = $fields; }
        // Native entitlement and required-field policy also runs for skipped items.
        $plan = Write_Callbacks::prepare( $existing ? 'update-listing' : 'create-listing', $native );
        $status = $plan['preview']['effective_status'];
        if ( $existing && 'publish' === $status ) { Listing_Policy::policy( get_post( $existing ), $directory, 'publish' ); }
        return [ 'decision' => $decision, 'existing_id' => $existing, 'native' => $native, 'native_state' => $plan['state'], 'fields' => $fields, 'effective_status' => $status, 'mapping' => $mapping, 'existing_version' => $existing ? Listing_Schema::version( get_post( $existing ) ) : '' ];
    }

    /** Called under the site lock and the item/checkpoint transaction. No HTTP or file work. */
    public static function commit( array $candidate, array $plan, string $reference ): int {
        if ( 'skip' === $plan['decision'] ) { return $plan['existing_id']; }
        $existing = $plan['existing_id'];
        $native = Write_Callbacks::prepare( $existing ? 'update-listing' : 'create-listing', $plan['native'] );
        $deny_http = static function () { return new WP_Error( 'dli_commit_network_forbidden', 'Network access is forbidden during an import commit.' ); };
        $allowed_meta = [ '_t_c_check' => true, '_privacy_policy' => true ];
        foreach ( Listing_Schema::definitions( $plan['native']['directory_id'] ) as $key => $definition ) {
            if ( isset( $plan['fields'][ $key ] ) ) { $allowed_meta[ '_' . $definition['native_key'] ] = true; }
        }
        $preserve = static function ( $meta ) use ( $existing, $allowed_meta ) { return $existing ? array_intersect_key( $meta, $allowed_meta ) : $meta; };
        $old_post = $existing ? get_post( $existing ) : null;
        $preserve_post = static function ( $data, $postarr ) use ( $old_post, $plan ) {
            if ( ! $old_post || (int) ( $postarr['ID'] ?? 0 ) !== (int) $old_post->ID ) { return $data; }
            foreach ( [ 'title' => 'post_title', 'description' => 'post_content', 'excerpt' => 'post_excerpt' ] as $field => $native_key ) {
                if ( ! array_key_exists( $field, $plan['fields'] ) ) { $data[ $native_key ] = wp_slash( $old_post->$native_key ); }
            }
            $data['post_author'] = $old_post->post_author;
            return $data;
        };
        add_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
        add_filter( 'atbdp_ultimate_listing_meta_user_submission', $preserve, PHP_INT_MAX );
        add_filter( 'wp_insert_post_data', $preserve_post, PHP_INT_MAX, 2 );
        try { $result = call_user_func( $native['execute'] ); }
        finally {
            remove_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
            remove_filter( 'atbdp_ultimate_listing_meta_user_submission', $preserve, PHP_INT_MAX );
            remove_filter( 'wp_insert_post_data', $preserve_post, PHP_INT_MAX );
        }
        $id = (int) ( $result['listing_id'] ?? 0 );
        DLI_MCP_Service::check( $id > 0, 'The native listing result was not verified.', 'outcome_unknown' );
        DLI_MCP_Service::check( get_post_status( $id ) === $plan['effective_status'], 'Native status changed after validation. Preview the effective policy again.', 'candidate_changed' );
        if ( 'places' === $candidate['source'] ) { update_post_meta( $id, '_google_place_id', $candidate['identity'] ); }
        else {
            foreach ( [ DLI_META_SOURCE_URL, DLI_LEGACY_META_SOURCE_URL ] as $key ) { update_post_meta( $id, $key, $candidate['identity'] ); }
            update_post_meta( $id, DLI_META_SOURCE_NAME, wp_parse_url( $candidate['identity'], PHP_URL_HOST ) ?: '' );
        }
        update_post_meta( $id, DLI_META_IMPORTED_AT, time() );
        update_post_meta( $id, '_dli_mcp_item_reference', $reference );
        clean_post_cache( $id ); wp_cache_delete( $id, 'post_meta' );
        return $id;
    }
}
