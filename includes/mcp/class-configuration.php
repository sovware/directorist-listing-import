<?php
/** Native UI configuration and read-only history adapters. */
defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Configuration {
    public static function settings( string $source ): array {
        if ( 'places' === $source ) {
            $settings = new \DLIG\Settings();
            // WordPress may return scalar options as strings after cache invalidation.
            // Fingerprint effective typed settings so a valid continuation stays valid.
            $status = $settings->get_default_status();
            $maximum = $settings->get_max_results();
            return [ 'source_type' => $source, 'default_status' => $status, 'max_results' => $maximum, 'provider_configured' => '' !== $settings->get_api_key(), 'version' => DLI_MCP_Service::hash( [ $status, $maximum, (string) get_option( \DLIG\Settings::OPT_API_KEY, '' ) ] ) ];
        }
        $settings = (array) get_option( DLI_OPTION_SETTINGS, [] );
        return [ 'source_type' => $source, 'default_status' => $settings['default_status'] ?? 'pending', 'batch_size' => (int) ( $settings['batch_size'] ?? 25 ), 'version' => DLI_MCP_Service::hash( $settings ) ];
    }

    public static function mapping( int $directory, int $page = 1, int $per_page = 100 ): array {
        DLI_MCP_Listing_Writer::directory( $directory );
        $mapper = new \DLIG\Field_Mapping(); $rows = [];
        $effective = $mapper->get_effective_mapping( $directory );
        foreach ( $mapper->get_source_fields() as $key => $source ) {
            $destinations = [];
            foreach ( $mapper->get_available_destinations( $directory, $key ) as $group => $options ) {
                foreach ( $options as $id => $label ) { $destinations[] = [ 'id' => $id, 'label' => $label, 'group' => $group ]; }
            }
            $rows[] = [ 'source' => $key, 'label' => $source['label'], 'destination' => $effective[ $key ] ?? 'skip', 'destinations' => array_slice( $destinations, ( $page - 1 ) * $per_page, $per_page ), 'destinations_total' => count( $destinations ), 'destinations_has_more' => $page * $per_page < count( $destinations ) ];
        }
        return [ 'directory_id' => $directory, 'fields' => $rows, 'page' => $page, 'per_page' => $per_page, 'version' => DLI_MCP_Service::hash( [ get_option( \DLIG\Field_Mapping::OPT_FIELD_MAP, [] ), $rows ] ) ];
    }

    public static function prepare( string $action, array $args ): array {
        if ( 'update-settings' === $action ) {
            $source = $args['source_type']; $before = self::settings( $source ); $changes = $args['changes'];
            DLI_MCP_Service::check( count( $changes ) > 0, 'Supply at least one setting.' );
            $allowed = 'places' === $source ? [ 'default_status', 'max_results', 'api_key', 'remove_key' ] : [ 'default_status', 'batch_size' ];
            DLI_MCP_Service::check( ! array_diff( array_keys( $changes ), $allowed ), 'This setting does not belong to the selected source.' );
            DLI_MCP_Service::check( ! isset( $changes['api_key'], $changes['remove_key'] ), 'Replace or remove the key, not both.' );
            if ( isset( $changes['default_status'] ) && 'feed' === $source ) { DLI_MCP_Service::check( in_array( $changes['default_status'], [ 'pending', 'publish' ], true ), 'RSS defaults support pending or publish.' ); }
            $after = $before;
            foreach ( [ 'default_status', 'max_results', 'batch_size' ] as $key ) { if ( isset( $changes[ $key ] ) ) { $after[ $key ] = $changes[ $key ]; } }
            $key_action = isset( $changes['api_key'] ) ? 'replace' : ( ! empty( $changes['remove_key'] ) ? 'remove' : 'unchanged' );
            if ( 'unchanged' !== $key_action ) { $after['provider_configured'] = 'replace' === $key_action; }
            return [ 'state' => [ $before, $changes ], 'preview' => [ 'before' => $before, 'after' => $after, 'key_action' => $key_action ], 'execute' => static function () use ( $source, $changes ) {
                if ( 'places' === $source ) {
                    if ( isset( $changes['api_key'] ) ) { ( new \DLIG\Settings() )->save_api_key( $changes['api_key'] ); }
                    elseif ( ! empty( $changes['remove_key'] ) ) { ( new \DLIG\Settings() )->save_api_key( '' ); }
                    foreach ( [ 'default_status' => \DLIG\Settings::OPT_DEFAULT_STATUS, 'max_results' => \DLIG\Settings::OPT_MAX_RESULTS ] as $key => $option ) { if ( isset( $changes[ $key ] ) ) { update_option( $option, $changes[ $key ], false ); } }
                } else {
                    update_option( DLI_OPTION_SETTINGS, array_replace( (array) get_option( DLI_OPTION_SETTINGS, [] ), $changes ), false );
                }
                return self::settings( $source );
            } ];
        }
        $directory = $args['directory_id']; $before = self::mapping( $directory ); $mapper = new \DLIG\Field_Mapping();
        $reset = ! empty( $args['reset'] ); $rows = $args['mapping'] ?? [];
        DLI_MCP_Service::check( $reset ? ! $rows : count( $rows ) > 0, 'Supply mapping changes or reset:true.' );
        $effective = $mapper->get_effective_mapping( $directory ); $seen = [];
        foreach ( $rows as $row ) {
            DLI_MCP_Service::check( isset( $effective[ $row['source'] ] ) && ! isset( $seen[ $row['source'] ] ), 'Unknown or repeated Google source field.' );
            $allowed = [];
            foreach ( $mapper->get_available_destinations( $directory, $row['source'] ) as $options ) { $allowed += $options; }
            DLI_MCP_Service::check( isset( $allowed[ $row['destination'] ] ), 'The destination is incompatible with this source field.', 'invalid_mapping' );
            $effective[ $row['source'] ] = $row['destination']; $seen[ $row['source'] ] = true;
        }
        $assigned = array_filter( array_values( $effective ), static function ( $id ) { return 'skip' !== $id; } );
        DLI_MCP_Service::check( $reset || count( $assigned ) === count( array_unique( $assigned ) ), 'Each destination can be mapped only once.', 'invalid_mapping' );
        return [ 'state' => [ $before, $args ], 'preview' => [ 'directory_id' => $directory, 'reset' => $reset, 'mapping' => $rows, 'effect' => $reset ? 'Restore native Google field mapping defaults for this directory.' : 'Save mapping changes for the UI and future MCP imports.' ], 'execute' => static function () use ( $mapper, $directory, $effective, $reset ) {
            $mapper->save_mapping( $directory, $effective, $reset );
            return self::mapping( $directory );
        } ];
    }

    /** Read the actual UI history stores. There is deliberately no deletion API. */
    public static function history( array $args ): array {
        $google = 'places' === $args['source_type']; $rows = [];
        $history = (array) get_option( $google ? 'dgbi_import_history' : DLI_OPTION_LOGS, [] );
        foreach ( $history as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            if ( isset( $args['feed_id'] ) && $args['feed_id'] !== ( $entry['feed_id'] ?? '' ) ) { continue; }
            try { $timestamp = is_numeric( $entry['time'] ?? null ) ? (int) $entry['time'] : ( new DateTimeImmutable( (string) ( $entry['time'] ?? '1970-01-01' ), wp_timezone() ) )->getTimestamp(); }
            catch ( Exception $error ) { $timestamp = 0; }
            if ( ( isset( $args['after'] ) && $timestamp < $args['after'] ) || ( isset( $args['before'] ) && $timestamp > $args['before'] ) ) { continue; }
            $rows[] = [ 'source_type' => $args['source_type'], 'time' => substr( (string) ( $entry['time'] ?? '' ), 0, 40 ), 'user_id' => (int) ( $entry['user_id'] ?? 0 ), 'post_status' => substr( sanitize_key( $entry['post_status'] ?? '' ), 0, 20 ), 'feed_id' => (string) ( $entry['feed_id'] ?? '' ), 'name' => substr( sanitize_text_field( $entry['feed_name'] ?? $entry['keyword'] ?? '' ), 0, 200 ), 'location' => substr( sanitize_text_field( $entry['location'] ?? '' ), 0, 200 ), 'imported' => (int) ( $entry['imported'] ?? 0 ), 'updated' => (int) ( $entry['updated'] ?? 0 ), 'skipped' => (int) ( $entry['skipped'] ?? 0 ), 'reviews' => (int) ( $entry['reviews'] ?? 0 ), 'reviews_created' => (int) ( $entry['reviews_created'] ?? 0 ), 'errors' => is_array( $entry['errors'] ?? null ) ? count( $entry['errors'] ) : (int) ( $entry['errors'] ?? 0 ), 'has_error' => ! empty( $entry['errors'] ) || ! empty( $entry['error_msg'] ), 'job_id' => (string) ( $entry['job_id'] ?? '' ) ];
        }
        return DLI_MCP_Service::page( $rows, $args );
    }
}
