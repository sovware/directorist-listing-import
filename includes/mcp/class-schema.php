<?php
/** Closed and bounded API 1 input, result and preview schemas. */
defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Schema {
    public static function object( array $properties = [], array $required = [] ): array { return [ 'type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required ]; }
    public static function text( int $max = 200, int $min = 0 ): array { return [ 'type' => 'string', 'maxLength' => $max, 'minLength' => $min ]; }
    public static function integer( int $min = 0, ?int $max = null ): array { $s = [ 'type' => 'integer', 'minimum' => $min ]; if ( null !== $max ) { $s['maximum'] = $max; } return $s; }
    public static function enum( array $values ): array { return [ 'type' => 'string', 'enum' => $values ]; }
    public static function array( array $items, int $max = 100, int $min = 0 ): array { return [ 'type' => 'array', 'items' => $items, 'maxItems' => $max, 'minItems' => $min ]; }
    public static function bool(): array { return [ 'type' => 'boolean' ]; }
    /** WordPress schema validation accepts numeric strings; MCP identifiers do not. */
    public static function typed( $value, array $schema ): bool {
        switch ( $schema['type'] ) {
            case 'integer': return is_int( $value );
            case 'number': return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value );
            case 'boolean': return is_bool( $value );
            case 'string': return is_string( $value );
            case 'array':
                if ( ! is_array( $value ) || array_values( $value ) !== $value ) { return false; }
                foreach ( $value as $item ) { if ( ! self::typed( $item, $schema['items'] ) ) { return false; } }
                return true;
            case 'object':
                if ( ! is_array( $value ) && ! is_object( $value ) ) { return false; }
                $value = (array) $value;
                foreach ( $schema['properties'] as $key => $child ) { if ( array_key_exists( $key, $value ) && ! self::typed( $value[ $key ], $child ) ) { return false; } }
                return true;
        }
        return false;
    }
    public static function pagination(): array { return [ 'page' => self::integer( 1, 1000000 ), 'per_page' => self::integer( 1, 100 ) ]; }
    public static function mapping(): array { return self::object( [ 'source' => self::enum( DLI_MCP_Listing_Writer::SOURCES ), 'target' => self::text( 80, 1 ) ], [ 'source', 'target' ] ); }
    public static function fields(): array { $fields = []; foreach ( DLI_MCP_Listing_Writer::SOURCES as $field ) { $fields[ $field ] = self::text( 'description' === $field ? 5000 : 1000 ); } return self::object( $fields ); }
    public static function feed_config(): array { return [ 'name' => self::text( 200, 1 ), 'source_url' => self::text( 2000, 1 ), 'directory_id' => self::integer( 1 ), 'category_id' => self::integer(), 'mode' => self::enum( [ 'one_time', 'sync' ] ), 'interval' => self::enum( [ 'manual', 'hourly', 'twicedaily', 'daily' ] ), 'status' => self::enum( [ 'active', 'paused' ] ) ]; }
    public static function input( string $action ): array {
        $id = self::text( 64, 1 ); $directory = [ 'directory_id' => self::integer( 1 ) ];
        switch ( $action ) {
            case 'get-schema': return self::object( $directory + [ 'source_type' => self::enum( [ 'feed', 'places' ] ) ] );
            case 'list-feeds': return self::object( $directory + self::pagination() + [ 'status' => self::enum( [ 'active', 'paused' ] ) ] );
            case 'get-feed': case 'delete-feed': return self::object( [ 'feed_id' => $id ], [ 'feed_id' ] );
            case 'create-feed': return self::object( self::feed_config(), [ 'name', 'source_url', 'directory_id' ] );
            case 'update-feed': return self::object( [ 'feed_id' => $id, 'changes' => self::object( self::feed_config() ) ], [ 'feed_id', 'changes' ] );
            case 'discover-feed': return self::object( $directory + [ 'source_url' => self::text( 2000, 1 ), 'source_approved' => self::bool(), 'sample_size' => self::integer( 1, 20 ), 'feed_id' => $id ], [ 'directory_id', 'source_url', 'source_approved' ] );
            case 'search-places': return self::object( $directory + [ 'keyword' => self::text( 200, 1 ), 'location' => self::text( 200 ), 'latitude' => [ 'type' => 'number', 'minimum' => -90, 'maximum' => 90 ], 'longitude' => [ 'type' => 'number', 'minimum' => -180, 'maximum' => 180 ], 'radius' => self::integer( 0, 50000 ), 'result_limit' => self::integer( 1, 20 ), 'continuation' => $id ], [ 'directory_id', 'keyword' ] );
            case 'get-staged-candidates': return self::object( self::pagination() + [ 'snapshot_id' => $id ], [ 'snapshot_id' ] );
            case 'import-candidates': return self::object( $directory + [ 'snapshot_id' => $id, 'items' => self::array( self::object( [ 'candidate_id' => $id, 'client_ref' => self::text( 64, 1 ), 'attachment_ids' => self::array( self::integer( 1 ), 20 ) ], [ 'candidate_id', 'client_ref' ] ), 50, 1 ), 'category_id' => self::integer(), 'mapping' => self::array( self::mapping(), 20 ), 'status' => self::enum( [ 'draft', 'pending', 'publish' ] ), 'duplicate_mode' => self::enum( [ 'find_new_only', 'update_existing' ] ), 'consents' => self::object( [ 'privacy_policy' => self::bool(), 'terms_conditions' => self::bool() ] ) ], [ 'directory_id', 'snapshot_id', 'items' ] );
            case 'list-jobs': return self::object( self::pagination() + [ 'scope' => self::enum( [ 'own', 'admin' ] ), 'status' => self::enum( [ 'queued', 'running', 'stop-requested', 'completed', 'cancelled', 'outcome-unknown' ] ) ] );
            case 'get-job': return self::object( self::pagination() + [ 'job_id' => $id ], [ 'job_id' ] );
            case 'cancel-job': return self::object( [ 'job_id' => $id ], [ 'job_id' ] );
            default: return self::object( self::pagination() + [ 'scope' => self::enum( [ 'own', 'admin' ] ), 'feed_id' => $id, 'job_id' => $id, 'after' => self::integer(), 'before' => self::integer() ] );
        }
    }
    public static function feed(): array { return self::object( [ 'feed_id' => self::text( 64 ), 'name' => self::text(), 'source' => self::text( 1000 ), 'directory_id' => self::integer(), 'category_id' => self::integer(), 'mode' => self::text( 20 ), 'interval' => self::text( 20 ), 'status' => self::text( 20 ), 'last_run' => self::integer(), 'version' => self::text( 64 ), 'deleted' => self::bool() ] ); }
    public static function item(): array { return self::object( [ 'candidate_id' => self::text( 64 ), 'client_ref' => self::text( 64 ), 'status' => self::text( 32 ), 'listing_id' => self::integer(), 'reason' => self::text( 80 ), 'idempotency_reference' => self::text( 64 ) ] ); }
    public static function event(): array { return self::object( [ 'job_id' => self::text( 64 ), 'feed_id' => self::text( 64 ), 'client_ref' => self::text( 64 ), 'status' => self::text( 32 ), 'reason' => self::text( 80 ), 'time' => self::integer(), 'created' => self::integer(), 'skipped' => self::integer(), 'failed' => self::integer() ] ); }
    public static function job(): array { return self::object( [ 'job_id' => self::text( 64 ), 'status' => self::text( 32 ), 'created' => self::integer(), 'updated' => self::integer(), 'total_items' => self::integer(), 'outcomes' => self::array( self::object( [ 'status' => self::text( 32 ), 'count' => self::integer() ] ), 10 ), 'cancel_requested' => self::bool(), 'in_flight' => self::bool(), 'next_action' => self::text( 256 ), 'version' => self::text( 64 ) ] ); }
    public static function page( array $item, array $extra = [] ): array { return self::object( [ 'items' => self::array( $item ), 'total' => self::integer(), 'page' => self::integer(), 'per_page' => self::integer(), 'total_pages' => self::integer() ] + $extra ); }
    public static function output( string $action ): array {
        switch ( $action ) {
            case 'get-schema': return self::object( [ 'directory_id' => self::integer(), 'source_types' => self::array( self::text( 20 ), 2 ), 'source_fields' => self::array( self::text( 80 ), 20 ), 'mappings' => self::array( self::object( [ 'target' => self::text( 80 ), 'type' => self::text( 20 ), 'required' => self::bool() ] ) ), 'statuses' => self::array( self::text( 20 ), 3 ), 'duplicate_modes' => self::array( self::text( 20 ), 2 ), 'modes' => self::array( self::text( 20 ), 2 ), 'intervals' => self::array( self::text( 20 ), 4 ), 'feed_states' => self::array( self::text( 20 ), 2 ), 'provider_configured' => self::bool(), 'storage_ready' => self::bool(), 'scheduled_runs_available' => self::bool(), 'prerequisite_reason' => self::text( 500 ), 'snapshot_ttl_seconds' => self::integer(), 'max_candidates' => self::integer(), 'places_result_limit' => self::integer(), 'places_requests_per_hour' => self::integer(), 'continuation_pages' => self::integer(), 'media_policy' => self::text(), 'schema_version' => self::text( 64 ) ] );
            case 'get-feed': case 'create-feed': case 'update-feed': case 'delete-feed': return self::feed();
            case 'list-feeds': return self::page( self::feed() );
            case 'discover-feed': case 'search-places': return self::object( [ 'snapshot_id' => self::text( 64 ), 'candidate_count' => self::integer(), 'expires' => self::integer(), 'quality' => self::integer(), 'continuation' => self::text( 64 ), 'warnings' => self::array( self::text( 256 ), 10 ) ] );
            case 'get-staged-candidates': return self::page( self::object( [ 'candidate_id' => self::text( 64 ), 'fields' => self::fields(), 'existing_listing_id' => self::integer(), 'duplicate' => self::bool(), 'ready' => self::bool(), 'reason' => self::text( 80 ) ] ), [ 'snapshot_id' => self::text( 64 ), 'expires' => self::integer(), 'source' => self::text( 1000 ), 'quality' => self::integer(), 'continuation' => self::text( 64 ) ] );
            case 'import-candidates': return self::object( [ 'job_id' => self::text( 64 ), 'status' => self::text( 32 ), 'accepted' => self::array( self::item(), 50 ) ] );
            case 'get-job': return self::page( self::item(), self::job()['properties'] );
            case 'list-jobs': return self::page( self::job() );
            case 'cancel-job': return self::job();
            default: return self::page( self::event() );
        }
    }
    public static function preview( string $action ): array {
        if ( in_array( $action, [ 'create-feed', 'update-feed', 'delete-feed' ], true ) ) { return self::object( [ 'feed_id' => self::text( 64 ), 'before' => self::feed(), 'after' => self::feed(), 'scheduling_effect' => self::text( 256 ) ] ); }
        if ( in_array( $action, [ 'discover-feed', 'search-places' ], true ) ) { return self::object( [ 'source' => self::text( 1000 ), 'directory_id' => self::integer(), 'limit' => self::integer(), 'network_effect' => self::text( 500 ), 'media_policy' => self::text( 256 ) ] ); }
        if ( 'cancel-job' === $action ) { return self::object( [ 'job_id' => self::text( 64 ), 'effect' => self::text( 256 ), 'in_flight' => self::bool() ] ); }
        return self::object( [ 'snapshot_id' => self::text( 64 ), 'directory_id' => self::integer(), 'effect' => self::text( 256 ), 'items' => self::array( self::object( [ 'candidate_id' => self::text( 64 ), 'client_ref' => self::text( 64 ), 'decision' => self::text( 32 ), 'existing_listing_id' => self::integer(), 'effective_status' => self::text( 20 ), 'reason' => self::text( 80 ), 'mapping' => self::array( self::mapping(), 20 ), 'fields' => self::array( self::object( [ 'field' => self::text( 80 ), 'value' => self::text( 6000 ) ] ), 25 ), 'attachment_ids' => self::array( self::integer( 1 ), 20 ) ] ), 50 ) ] );
    }
}
