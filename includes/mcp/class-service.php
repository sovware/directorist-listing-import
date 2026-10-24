<?php
/** Import orchestration: pure plans, separate dispatch/writer, independent worker commits. */
use Directorist\MCP\Safety\Write_Lock;
use Directorist\MCP\Safety\Transaction;

defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Service {
    private static $evidence = [];
    const TTL = 900;
    const TERMINAL = [ 'created', 'updated', 'skipped-duplicate', 'validation-failed', 'permission-blocked', 'cancelled', 'outcome-unknown' ];

    public static function check( $condition, string $message, string $code = 'validation_failed' ): void {
        if ( ! $condition ) { throw new \Directorist\MCP\Support\Failure( $code, $message ); }
    }
    public static function hash( $value ): string { return \Directorist\MCP\Support\Canonical_Data::hash( $value ); }
    private static function payload( array $args ): array {
        foreach ( [ 'dry_run', 'confirm_token', 'idempotency_key', 'expected_version', 'schema_version' ] as $key ) { unset( $args[ $key ] ); }
        return $args;
    }
    public static function tables(): array {
        global $wpdb;
        $tables = [ DLI_MCP_Store::table(), $wpdb->options, $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships ];
        if ( class_exists( '\DirectoristPricingPlan\App\MCP\Integration' ) ) { $tables = array_merge( $tables, \DirectoristPricingPlan\App\MCP\Integration::tables() ); }
        return array_values( array_unique( $tables ) );
    }
    public static function page( array $rows, array $args ): array {
        $page = $args['page'] ?? 1; $per = $args['per_page'] ?? 20;
        return [ 'items' => array_slice( array_values( $rows ), ( $page - 1 ) * $per, $per ), 'total' => count( $rows ), 'page' => $page, 'per_page' => $per, 'total_pages' => (int) ceil( count( $rows ) / $per ) ];
    }
    public static function feed( string $id ): array {
        $feed = Directorist_Listing_Import_Feed_Manager::get_feed( $id );
        self::check( $feed, 'Feed unavailable.', 'not_found' );
        return $feed;
    }
    public static function safe_feed( array $feed ): array {
        return [ 'feed_id' => $feed['id'], 'name' => substr( $feed['name'], 0, 200 ), 'source' => DLI_MCP_Source::descriptor( $feed['url'] ), 'directory_id' => (int) $feed['directory_type'], 'category_id' => (int) $feed['category'], 'mode' => $feed['sync_mode'] ?? 'sync', 'interval' => $feed['interval'], 'status' => $feed['status'], 'last_run' => (int) $feed['last_run'], 'version' => self::hash( $feed ) ];
    }
    public static function snapshot( string $id, bool $fresh = true ): array {
        $snapshot = DLI_MCP_Store::get( $id, 'snapshot' );
        self::check( (int) $snapshot['site'] === get_current_blog_id(), 'Snapshot unavailable.', 'not_found' );
        if ( $fresh ) {
            self::check( $snapshot['expires'] > time(), 'The snapshot expired. Preview a new discovery request.', 'snapshot_expired' );
            self::check( hash_equals( $snapshot['fingerprint'], DLI_MCP_Listing_Writer::fingerprint( $snapshot['directory_id'] ) ), 'The directory or extension lifecycle changed. Discover again.', 'snapshot_stale' );
        }
        return $snapshot;
    }

    public static function read( string $action, array $args ): array {
        if ( 'get-schema' === $action ) {
            $directory = $args['directory_id'] ?? (int) directorist_get_default_directory();
            DLI_MCP_Listing_Writer::directory( $directory );
            $fields = [];
            foreach ( DLI_MCP_Listing_Writer::mappings( $directory ) as $key => $definition ) { $fields[] = [ 'target' => $key, 'type' => $definition['schema']['type'], 'required' => $definition['required'] ]; }
            return [ 'directory_id' => $directory, 'source_types' => [ 'feed', 'places' ], 'source_fields' => DLI_MCP_Listing_Writer::SOURCES, 'mappings' => $fields, 'statuses' => \Directorist\MCP\Tool_Providers\Listings\Listing_Policy::initial_statuses( $directory ), 'duplicate_modes' => [ 'find_new_only', 'update_existing' ], 'modes' => [ 'one_time', 'sync' ], 'intervals' => [ 'manual', 'hourly', 'twicedaily', 'daily' ], 'feed_states' => [ 'paused', 'active' ], 'provider_configured' => '' !== ( new \DLIG\Settings() )->get_api_key(), 'storage_ready' => DLI_MCP_Store::ready(), 'scheduled_runs_available' => false, 'prerequisite_reason' => 'Native scheduled workers do not yet provide bounded staging and item checkpoints. MCP feeds must remain paused.', 'snapshot_ttl_seconds' => self::TTL, 'max_candidates' => 50, 'places_result_limit' => ( new \DLIG\Settings() )->get_max_results(), 'places_requests_per_hour' => 100, 'continuation_pages' => DLI_MCP_Source::MAX_PAGES, 'media_policy' => 'existing_authorized_attachment_references_only', 'schema_version' => DLI_MCP_Listing_Writer::fingerprint( $directory ) ];
        }
        if ( 'get-feed' === $action ) { return self::safe_feed( self::feed( $args['feed_id'] ) ); }
        if ( 'list-feeds' === $action ) {
            $rows = array_filter( Directorist_Listing_Import_Feed_Manager::get_feeds(), static function ( $feed ) use ( $args ) { return ( ! isset( $args['status'] ) || $feed['status'] === $args['status'] ) && ( ! isset( $args['directory_id'] ) || (int) $feed['directory_type'] === $args['directory_id'] ); } );
            return self::page( array_map( [ self::class, 'safe_feed' ], $rows ), $args );
        }
        if ( 'get-staged-candidates' === $action ) {
            $snapshot = self::snapshot( $args['snapshot_id'] ); $rows = [];
            foreach ( $snapshot['candidates'] as $candidate ) {
                $id = 0; $reason = '';
                try { $id = DLI_MCP_Listing_Writer::duplicate( $candidate, $snapshot['directory_id'] ); self::check( $candidate['identity'] && $candidate['fields']['title'], 'Missing identity/title.', 'validation_failed' ); }
                catch ( \Throwable $error ) { $reason = self::reason( $error ); }
                $rows[] = [ 'candidate_id' => $candidate['candidate_id'], 'fields' => $candidate['fields'], 'existing_listing_id' => $id && current_user_can( 'edit_post', $id ) ? $id : 0, 'duplicate' => $id > 0, 'ready' => '' === $reason, 'reason' => $reason ];
            }
            return self::page( $rows, $args ) + [ 'snapshot_id' => $args['snapshot_id'], 'expires' => $snapshot['expires'], 'source' => $snapshot['source'], 'quality' => $snapshot['quality'], 'continuation' => $snapshot['continuation'] ];
        }
        if ( 'list-jobs' === $action ) {
            return self::page( array_map( [ self::class, 'job_summary' ], DLI_MCP_Store::rows( 'job', $args['scope'] ?? 'own', $args['status'] ?? '' ) ), $args );
        }
        if ( 'get-job' === $action ) {
            $job = DLI_MCP_Store::get( $args['job_id'], 'job' );
            $rows = array_map( [ self::class, 'item_summary' ], $job['items'] );
            return self::page( $rows, $args ) + self::job_summary( $job );
        }
        $events = DLI_MCP_Store::rows( 'event', $args['scope'] ?? 'own' );
        foreach ( self::external_records() as $record ) {
            if ( 'admin' !== ( $args['scope'] ?? 'own' ) && (int) $record['actor'] !== get_current_user_id() ) { continue; }
            $reason = $record['provider_reference'] ?? '';
            $allowed = [ 'unsafe_source', 'invalid_source', 'source_unavailable', 'source_redirect', 'provider_redirect', 'source_too_large', 'unsupported_source', 'provider_quota', 'provider_missing', 'network_outcome_unknown', 'transport_not_verified', 'source_http_error', 'provider_response_invalid' ];
            $events[] = [ 'job_id' => '', 'feed_id' => $record['args']['feed_id'] ?? '', 'client_ref' => '', 'status' => $record['state'], 'reason' => in_array( $reason, $allowed, true ) ? $reason : '', 'time' => strtotime( $record['created_at'] ) ];
        }
        foreach ( (array) get_option( DLI_OPTION_LOGS, [] ) as $log ) {
            // Legacy native logs are administrator-only and have no actor attribution.
            if ( 'admin' !== ( $args['scope'] ?? 'own' ) || isset( $args['job_id'] ) ) { continue; }
            $events[] = [ 'time' => (int) ( $log['time'] ?? 0 ), 'job_id' => '', 'feed_id' => (string) ( $log['feed_id'] ?? '' ), 'client_ref' => '', 'status' => 'native-run', 'reason' => empty( $log['error_msg'] ) ? '' : 'native_error', 'created' => (int) ( $log['imported'] ?? 0 ), 'skipped' => (int) ( $log['skipped'] ?? 0 ), 'failed' => (int) ( $log['errors'] ?? 0 ) ];
        }
        $events = array_filter( $events, static function ( $event ) use ( $args ) {
            foreach ( [ 'job_id', 'feed_id' ] as $key ) { if ( isset( $args[ $key ] ) && $args[ $key ] !== $event[ $key ] ) { return false; } }
            return ( ! isset( $args['after'] ) || $event['time'] >= $args['after'] ) && ( ! isset( $args['before'] ) || $event['time'] <= $args['before'] );
        } );
        usort( $events, static function ( $a, $b ) { return $b['time'] <=> $a['time']; } );
        return self::page( array_map( static function ( $event ) { return array_intersect_key( $event, DLI_MCP_Schema::event()['properties'] ); }, $events ), $args );
    }

    public static function prepare( string $action, array $args ): array {
        $args = self::payload( $args );
        self::check( DLI_MCP_Store::ready(), 'Open the native admin page to complete the import storage upgrade.', 'prerequisite_missing' );
        if ( in_array( $action, [ 'discover-feed', 'search-places' ], true ) ) { return self::prepare_discovery( $action, $args ); }
        if ( 'import-candidates' === $action ) { return self::prepare_import( $args ); }
        if ( 'cancel-job' === $action ) {
            $job = DLI_MCP_Store::get( $args['job_id'], 'job' );
            return [ 'state' => $job, 'preview' => [ 'job_id' => $job['job_id'], 'effect' => 'Stop future items; completed outcomes are retained.', 'in_flight' => (bool) array_filter( $job['items'], static function ( $item ) { return 'running' === $item['status']; } ) ], 'execute' => static function () use ( $job ) {
                $current = DLI_MCP_Store::get( $job['job_id'], 'job' );
                $current['cancel_requested'] = true;
                foreach ( $current['items'] as &$item ) { if ( 'queued' === $item['status'] ) { $item['status'] = 'cancelled'; $item['reason'] = 'stop_requested'; } } unset( $item );
                self::save_job( $current ); DLI_MCP_Store::event( $job['job_id'], '', 'cancelled' );
                return self::job_summary( $current );
            } ];
        }
        $create = 'create-feed' === $action;
        $before = $create ? [] : self::feed( $args['feed_id'] );
        if ( ! $create ) {
            foreach ( DLI_MCP_Store::rows( 'job', 'admin' ) as $job ) { self::check( ( $job['feed_id'] ?? '' ) !== $args['feed_id'] || ! in_array( $job['status'], [ 'queued', 'running', 'stop-requested' ], true ), 'An active import job depends on this feed.', 'dependency_blocked' ); }
        }
        $after = $before;
        if ( 'delete-feed' !== $action ) {
            $change = $create ? $args : (array) $args['changes'];
            $map = [ 'directory_id' => 'directory_type', 'category_id' => 'category', 'mode' => 'sync_mode', 'source_url' => 'url' ];
            foreach ( [ 'name', 'source_url', 'directory_id', 'category_id', 'mode', 'interval', 'status' ] as $key ) { if ( array_key_exists( $key, $change ) ) { $after[ $map[ $key ] ?? $key ] = $change[ $key ]; } }
            $after += [ 'interval' => 'manual', 'status' => 'paused', 'category' => 0, 'sync_mode' => 'one_time' ];
            self::check( 'paused' === $after['status'], 'Automatic/native scheduling needs bounded staging and checkpoints before MCP can enable a feed. Keep it paused.', 'scheduler_prerequisite' );
            self::check( ! empty( trim( $after['name'] ?? '' ) ), 'Feed name is required.' );
            $after['url'] = DLI_MCP_Source::url( $after['url'] ); $after['source_url'] = $after['url'];
            DLI_MCP_Listing_Writer::directory( $after['directory_type'], $after['category'] );
        }
        return [ 'state' => [ $before, $after, get_option( DLI_OPTION_FEEDS, [] ) ], 'preview' => [ 'feed_id' => $before['id'] ?? '', 'before' => $before ? self::safe_feed( $before ) : (object) [], 'after' => 'delete-feed' === $action ? (object) [] : [ 'name' => $after['name'], 'source' => DLI_MCP_Source::descriptor( $after['url'] ), 'directory_id' => $after['directory_type'], 'category_id' => $after['category'], 'mode' => $after['sync_mode'], 'interval' => $after['interval'], 'status' => $after['status'] ], 'scheduling_effect' => 'delete-feed' === $action ? 'Remove configuration and future eligibility; imported listings are retained.' : 'Paused: no automatic or immediate import.' ], 'execute' => static function () use ( $action, $args, $after, $create ) {
            if ( 'delete-feed' === $action ) { self::check( Directorist_Listing_Import_Feed_Manager::delete_feed( $args['feed_id'] ), 'Feed deletion failed.' ); return [ 'feed_id' => $args['feed_id'], 'deleted' => true ]; }
            if ( $create ) { $id = Directorist_Listing_Import_Feed_Manager::add_feed( $after ); }
            else { $id = $args['feed_id']; self::check( Directorist_Listing_Import_Feed_Manager::update_feed( $id, $after ), 'Feed update failed.' ); }
            return self::safe_feed( self::feed( $id ) );
        } ];
    }

    private static function discovery_args( string $action, array $args, string $operation = '' ): array {
        DLI_MCP_Listing_Writer::directory( $args['directory_id'] );
        if ( 'discover-feed' === $action ) {
            self::check( true === $args['source_approved'], 'Explicit approval of a public source is required.' ); DLI_MCP_Source::url( $args['source_url'] );
            if ( ! empty( $args['feed_id'] ) ) { self::check( self::feed( $args['feed_id'] )['url'] === $args['source_url'], 'The source differs from the selected feed.' ); }
        } else {
            self::check( '' !== ( new \DLIG\Settings() )->get_api_key(), 'Configure Google Places in native settings.', 'provider_missing' );
            self::check( ! empty( trim( $args['keyword'] ) ) && ( ! empty( trim( $args['location'] ?? '' ) ) || ( isset( $args['latitude'], $args['longitude'] ) ) ), 'Supply a keyword and location or coordinates.' );
            self::check( isset( $args['latitude'] ) === isset( $args['longitude'] ), 'Both coordinates are required.' );
            if ( ! empty( $args['continuation'] ) ) {
                $continuation = DLI_MCP_Store::get( $args['continuation'], 'continuation' );
                self::check( $continuation['actor'] === get_current_user_id() && $continuation['expires'] > time() && $continuation['page'] < DLI_MCP_Source::MAX_PAGES && $continuation['query'] === self::query_hash( $args ), 'The continuation is stale or belongs to a different query.', 'continuation_invalid' );
                foreach ( self::external_records() as $record ) {
                    self::check( ( $record['args']['continuation'] ?? '' ) !== $args['continuation'] || ( $record['operation_id'] ?? '' ) === $operation, 'This continuation already belongs to a durable operation. Read that operation or refresh the query.', 'continuation_used' );
                }
                $args['_page'] = $continuation['page']; $args['_page_token'] = $continuation['provider'];
            }
        }
        return $args;
    }
    private static function query_hash( array $args ): string {
        return self::hash( [ $args['keyword'], $args['location'] ?? '', $args['latitude'] ?? null, $args['longitude'] ?? null, $args['radius'] ?? 0, $args['result_limit'] ?? 20, $args['directory_id'], get_current_user_id(), get_current_blog_id() ] );
    }
    public static function prepare_discovery( string $action, array $args ): array {
        $resolved = self::discovery_args( $action, $args );
        if ( 'search-places' === $action ) { self::check( self::quota_count() < 100, 'The hourly Places request limit was reached.', 'provider_quota' ); }
        $fingerprint = DLI_MCP_Listing_Writer::fingerprint( $args['directory_id'] );
        return [ 'state' => [ $args, $fingerprint, 'search-places' === $action ? self::quota_count() < 100 : true ], 'preview' => [ 'source' => 'discover-feed' === $action ? DLI_MCP_Source::descriptor( $args['source_url'] ) : 'Google Places', 'directory_id' => $args['directory_id'], 'limit' => $args['sample_size'] ?? min( $args['result_limit'] ?? 20, ( new \DLIG\Settings() )->get_max_results() ), 'network_effect' => 'search-places' === $action ? 'One bounded Google Places text search POST; provider charges may apply. No automatic retries.' : 'Bounded public feed discovery; redirects and payloads are validated.', 'media_policy' => 'Existing attachment references only; no remote images are fetched.' ], 'execute' => static function () use ( $args, $resolved, $fingerprint ) {
            $evidence = self::$evidence[ self::hash( $args ) ] ?? null;
            self::check( $evidence && 'settled' === $evidence['data']['state'], 'The original provider result is unavailable.', 'outcome_unknown' );
            $id = $evidence['operation']; $data = $evidence['data']; $continuation = '';
            if ( ! empty( $data['next'] ) && ( $resolved['_page'] ?? 0 ) + 1 < DLI_MCP_Source::MAX_PAGES ) {
                $continuation = wp_generate_uuid4();
                DLI_MCP_Store::put( $continuation, 'continuation', [ 'actor' => get_current_user_id(), 'query' => self::query_hash( $args ), 'provider' => $data['next'], 'page' => ( $resolved['_page'] ?? 0 ) + 1, 'expires' => time() + self::TTL ], 'ready', time() + self::TTL );
            }
            $unique = [];
            foreach ( $data['candidates'] as $candidate ) { $unique[ $candidate['candidate_id'] ] = $candidate; }
            $snapshot = [ 'actor' => get_current_user_id(), 'site' => get_current_blog_id(), 'created' => time(), 'expires' => time() + self::TTL, 'directory_id' => $args['directory_id'], 'feed_id' => $args['feed_id'] ?? '', 'fingerprint' => $fingerprint, 'args_hash' => self::hash( $args ), 'source' => $data['source'], 'quality' => $data['quality'], 'candidates' => array_values( $unique ), 'continuation' => $continuation ];
            DLI_MCP_Store::put( $id, 'snapshot', $snapshot, 'ready', $snapshot['expires'] );
            return [ 'snapshot_id' => $id, 'candidate_count' => count( $unique ), 'expires' => $snapshot['expires'], 'quality' => $data['quality'], 'continuation' => $continuation, 'warnings' => [ 'Review mapped data and native validation before importing.' ] ];
        } ];
    }

    /** Count durable intents, including interrupted/failed requests and continuations. */
    private static function external_records(): array {
        global $wpdb;
        $values = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_directorist_mcp_extension_operation_' ) . '%' ) );
        $records = [];
        foreach ( $values as $value ) {
            $record = maybe_unserialize( $value );
            if ( is_array( $record ) && 'listing-import' === ( $record['owner'] ?? '' ) ) { $records[] = $record; }
        }
        return $records;
    }
    public static function quota_count(): int {
        $count = (int) get_transient( 'dgbi_import_count_' . get_current_user_id() );
        foreach ( self::external_records() as $record ) {
            if ( 'listing-import-search-places' === $record['tool'] && get_current_user_id() === (int) $record['actor'] && strtotime( $record['created_at'] ) > time() - HOUR_IN_SECONDS ) { ++$count; }
        }
        return $count;
    }
    public static function dispatch( string $action, string $operation, array $args ): array {
        $args = self::payload( $args );
        try {
            $resolved = self::discovery_args( $action, $args, $operation );
            if ( 'search-places' === $action ) { self::check( self::quota_count() <= 100, 'The hourly Places request limit was reached.', 'provider_quota' ); }
            $data = 'discover-feed' === $action ? DLI_MCP_Source::feed( $resolved ) : DLI_MCP_Source::places( $resolved, $resolved['_page_token'] ?? '' );
            self::$evidence[ self::hash( $args ) ] = [ 'operation' => $operation, 'data' => $data ];
            return [ 'state' => $data['state'], 'reference' => $data['reason'] ?? $operation ];
        } catch ( \Directorist\MCP\Support\Failure $error ) { return [ 'state' => 'failed', 'reference' => $error->reason ]; }
    }
    public static function reconcile( string $operation, string $reference, array $args ): array {
        $args = self::payload( $args );
        try { $snapshot = DLI_MCP_Store::get( $operation, 'snapshot' ); return [ 'state' => $snapshot['args_hash'] === self::hash( $args ) ? 'settled' : 'unknown', 'reference' => $operation ]; }
        catch ( \Throwable $error ) { return [ 'state' => 'unknown', 'reference' => $reference ]; }
    }
    public static function verify( string $operation, array $args, array $result ): bool {
        $args = self::payload( $args );
        try { $snapshot = DLI_MCP_Store::get( $operation, 'snapshot' ); return $operation === ( $result['snapshot_id'] ?? '' ) && $snapshot['args_hash'] === self::hash( $args ) && count( $snapshot['candidates'] ) === ( $result['candidate_count'] ?? -1 ); }
        catch ( \Throwable $error ) { return false; }
    }

    public static function prepare_import( array $args ): array {
        $snapshot = self::snapshot( $args['snapshot_id'] );
        self::check( $args['directory_id'] === $snapshot['directory_id'], 'Select the snapshot directory.' );
        self::check( in_array( $args['status'] ?? 'draft', \Directorist\MCP\Tool_Providers\Listings\Listing_Policy::initial_statuses( $args['directory_id'] ), true ), 'The requested publication status is not authorized.', 'forbidden' );
        self::check( count( $args['items'] ) >= 1 && count( $args['items'] ) <= 50, 'Select 1–50 explicit candidates.' );
        $source = array_column( $snapshot['candidates'], null, 'candidate_id' ); $items = []; $seen = []; $refs = []; $preview = [];
        foreach ( $args['items'] as $item ) {
            self::check( isset( $source[ $item['candidate_id'] ] ) && ! isset( $seen[ $item['candidate_id'] ] ) && ! isset( $refs[ $item['client_ref'] ] ), 'Candidates and client references must be explicit and unique.' );
            $seen[ $item['candidate_id'] ] = true; $refs[ $item['client_ref'] ] = true;
            $record = [ 'candidate' => $source[ $item['candidate_id'] ], 'input' => $item, 'client_ref' => $item['client_ref'], 'status' => 'queued', 'listing_id' => 0, 'reason' => '', 'plan' => null ];
            try { $record['plan'] = DLI_MCP_Listing_Writer::plan( $record['candidate'], $args, $item ); }
            catch ( \Directorist\MCP\Support\Failure $error ) { $record['status'] = in_array( $error->reason, [ 'forbidden', 'permission_blocked' ], true ) ? 'permission-blocked' : 'validation-failed'; $record['reason'] = self::reason( $error ); }
            $items[] = $record;
            $preview[] = [ 'candidate_id' => $item['candidate_id'], 'client_ref' => $item['client_ref'], 'decision' => $record['plan']['decision'] ?? $record['status'], 'existing_listing_id' => $record['plan']['existing_id'] ?? 0, 'effective_status' => $record['plan']['effective_status'] ?? '', 'reason' => $record['reason'], 'mapping' => $record['plan']['mapping'] ?? [], 'fields' => self::field_preview( $record['plan']['fields'] ?? [] ), 'attachment_ids' => $item['attachment_ids'] ?? [] ];
        }
        $fingerprint = DLI_MCP_Listing_Writer::fingerprint( $args['directory_id'] );
        return [ 'state' => [ $args, $items, $fingerprint, $snapshot['expires'] ], 'preview' => [ 'snapshot_id' => $args['snapshot_id'], 'directory_id' => $args['directory_id'], 'items' => $preview, 'effect' => 'Create one durable job for only these candidates. Each native write is validated independently.' ], 'execute' => static function () use ( $snapshot, $args, $items, $fingerprint ) {
            $id = wp_generate_uuid4();
            $job = [ 'job_id' => $id, 'actor' => get_current_user_id(), 'site' => get_current_blog_id(), 'created' => time(), 'updated' => time(), 'status' => 'queued', 'cancel_requested' => false, 'feed_id' => $snapshot['feed_id'], 'snapshot_id' => $args['snapshot_id'], 'expires' => $snapshot['expires'], 'fingerprint' => $fingerprint, 'args' => $args, 'items' => $items ];
            self::save_job( $job ); DLI_MCP_Store::event( $id, '', 'accepted', '', $snapshot['feed_id'] );
            // Cron is only a wake-up; checkpoints live in the job, never in a transient.
            wp_schedule_single_event( time() + 1, 'dli_mcp_worker', [ $id ] );
            return [ 'job_id' => $id, 'status' => $job['status'], 'accepted' => array_map( [ self::class, 'item_summary' ], $items ) ];
        } ];
    }
    public static function field_preview( array $fields ): array {
        $rows = []; foreach ( $fields as $key => $value ) { $rows[] = [ 'field' => $key, 'value' => is_string( $value ) ? $value : wp_json_encode( $value ) ]; } return $rows;
    }
    public static function reason( \Throwable $error ): string { return $error instanceof \Directorist\MCP\Support\Failure ? substr( $error->reason, 0, 80 ) : 'native_write_failed'; }
    public static function item_summary( array $item ): array {
        $listing = $item['listing_id'];
        return [ 'candidate_id' => $item['candidate']['candidate_id'], 'client_ref' => $item['client_ref'], 'status' => $item['status'], 'listing_id' => $listing && current_user_can( 'edit_post', $listing ) ? $listing : 0, 'reason' => $item['reason'], 'idempotency_reference' => $item['reference'] ?? '' ];
    }
    public static function job_summary( array $job ): array {
        $counts = array_fill_keys( array_merge( self::TERMINAL, [ 'queued', 'running' ] ), 0 );
        foreach ( $job['items'] as $item ) { ++$counts[ $item['status'] ]; }
        $outcomes = []; foreach ( $counts as $status => $count ) { $outcomes[] = [ 'status' => $status, 'count' => $count ]; }
        return [ 'job_id' => $job['job_id'], 'status' => $job['status'], 'created' => $job['created'], 'updated' => $job['updated'], 'total_items' => count( $job['items'] ), 'outcomes' => $outcomes, 'cancel_requested' => $job['cancel_requested'], 'in_flight' => $counts['running'] > 0, 'next_action' => $counts['outcome-unknown'] ? 'Reconcile this job/item locally; do not submit a new import.' : ( $counts['queued'] || $counts['running'] ? 'Wait for the native worker or cancel future work.' : 'Review retained per-item outcomes.' ), 'version' => self::hash( $job ) ];
    }
    private static function save_job( array &$job ): void {
        $statuses = array_column( $job['items'], 'status' );
        $job['status'] = in_array( 'running', $statuses, true ) ? ( $job['cancel_requested'] ? 'stop-requested' : 'running' ) : ( in_array( 'queued', $statuses, true ) ? 'queued' : ( in_array( 'outcome-unknown', $statuses, true ) ? 'outcome-unknown' : ( $job['cancel_requested'] ? 'cancelled' : 'completed' ) ) );
        $job['updated'] = time(); DLI_MCP_Store::put( $job['job_id'], 'job', $job, $job['status'] );
    }

    /** Trusted local recovery API. It proves an existing commit, never retries a write. */
    public static function reconcile_item( string $job_id, string $client_ref ): array {
        self::check( DLI_MCP_Integration::admin() && DLI_MCP_Integration::available(), 'Current administrator authority is required.', 'forbidden' );
        $owned = ! Write_Lock::is_locked(); Write_Lock::lock();
        try {
            $job = DLI_MCP_Store::get( $job_id, 'job' );
            foreach ( $job['items'] as $index => $item ) {
                if ( $client_ref !== $item['client_ref'] ) { continue; }
                self::check( 'outcome-unknown' === $item['status'], 'Only an unknown outcome needs this proof.', 'invalid_recovery' );
                $reference = hash( 'sha256', $job_id . ':' . $item['candidate']['candidate_id'] );
                $ids = get_posts( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => [ 'draft', 'pending', 'publish', 'private', 'future', 'trash' ], 'fields' => 'ids', 'posts_per_page' => 2, 'meta_key' => '_dli_mcp_item_reference', 'meta_value' => $reference ] );
                // Absence of a marker does not prove that an interrupted commit failed.
                if ( 1 !== count( $ids ) ) { return self::item_summary( $item ); }
                $id = (int) $ids[0];
                self::check( current_user_can( 'edit_post', $id ) && \Directorist\MCP\Tool_Providers\Listings\Listing_Schema::directory( $id ) === $job['args']['directory_id'] && DLI_MCP_Listing_Writer::duplicate( $item['candidate'], $job['args']['directory_id'] ) === $id, 'The native listing proof is inconsistent.', 'outcome_unknown' );
                $item['listing_id'] = $id; $item['reference'] = $reference; $item['reason'] = '';
                $item['status'] = 'create' === $item['plan']['decision'] ? 'created' : 'updated';
                $job['items'][ $index ] = $item;
                Transaction::atomic( static function () use ( &$job, $item ) {
                    self::save_job( $job );
                    DLI_MCP_Store::event( $job['job_id'], $item['client_ref'], $item['status'], 'native_commit_verified', $job['feed_id'] );
                }, self::tables(), [ DLI_MCP_Store::class, 'invalidate' ] );
                return self::item_summary( $item );
            }
            self::check( false, 'The job item is unavailable.', 'not_found' );
        } finally { if ( $owned ) { Write_Lock::unlock(); } }
    }

    /** Worker reentry resumes a checkpoint; each listing and outcome commit together. */
    public static function work( string $job_id ): void {
        if ( ! class_exists( Write_Lock::class ) || ! DLI_MCP_Store::ready() ) { return; }
        $actor_before = get_current_user_id();
        for ( $turn = 0; $turn < 50; ++$turn ) {
            $owned = ! Write_Lock::is_locked(); Write_Lock::lock();
            try {
                $job = DLI_MCP_Store::get( $job_id, 'job', false );
                // Capability and activation caches must not carry authority from
                // an earlier item in this same long-running PHP process.
                clean_user_cache( $job['actor'] );
                wp_set_current_user( 0 );
                foreach ( [ 'active_plugins', 'directorist_mcp_epoch', 'directorist_mcp_extensions_epoch' ] as $option ) { wp_cache_delete( $option, 'options' ); }
                wp_cache_delete( 'alloptions', 'options' );
                wp_set_current_user( $job['actor'] );
                $index = null;
                foreach ( $job['items'] as $i => $item ) { if ( in_array( $item['status'], [ 'queued', 'running' ], true ) ) { $index = $i; break; } }
                if ( null === $index ) { return; }
                // Reserve recovery before entering any native writer. Terminal
                // jobs never reschedule; interrupted work keeps the same job ID.
                if ( ! wp_next_scheduled( 'dli_mcp_worker', [ $job_id ] ) ) { wp_schedule_single_event( time() + 60, 'dli_mcp_worker', [ $job_id ] ); }
                $item = $job['items'][ $index ];
                $item['reference'] = hash( 'sha256', $job_id . ':' . $item['candidate']['candidate_id'] );
                if ( $job['cancel_requested'] ) { $item['status'] = 'cancelled'; $item['reason'] = 'stop_requested'; }
                elseif ( ! DLI_MCP_Integration::admin() || ! DLI_MCP_Integration::available() || ! DLI_MCP_Integration::listing_cap() || $job['site'] !== get_current_blog_id() ) { $item['status'] = 'permission-blocked'; $item['reason'] = 'authority_revoked'; }
                else {
                    try {
                        self::check( $job['expires'] > time() && $job['fingerprint'] === DLI_MCP_Listing_Writer::fingerprint( $job['args']['directory_id'] ), 'The staged source or directory changed.', 'snapshot_stale' );
                        $current = DLI_MCP_Listing_Writer::plan( $item['candidate'], $job['args'], $item['input'] );
                        self::check( self::hash( $current ) === self::hash( $item['plan'] ), 'Candidate state changed; obtain a fresh preview.', 'candidate_changed' );
                        $item['status'] = 'running'; $job['items'][ $index ] = $item; self::save_job( $job );
                        // A process death before this transaction leaves a resumable running checkpoint.
                        Transaction::atomic( static function () use ( &$job, &$item, $index, $current ) {
                            $item['listing_id'] = DLI_MCP_Listing_Writer::commit( $item['candidate'], $current, $item['reference'] );
                            $item['status'] = [ 'create' => 'created', 'update' => 'updated', 'skip' => 'skipped-duplicate' ][ $current['decision'] ];
                            $item['reason'] = ''; $job['items'][ $index ] = $item;
                            self::save_job( $job ); DLI_MCP_Store::event( $job['job_id'], $item['client_ref'], $item['status'], '', $job['feed_id'] );
                        }, self::tables(), [ DLI_MCP_Store::class, 'invalidate' ] );
                        continue;
                    } catch ( \Throwable $error ) {
                        $item['reason'] = self::reason( $error );
                        $item['status'] = 'outcome_unknown' === $item['reason'] ? 'outcome-unknown' : ( in_array( $item['reason'], [ 'forbidden', 'permission_blocked' ], true ) ? 'permission-blocked' : 'validation-failed' );
                        $item['listing_id'] = 0;
                    }
                }
                $job['items'][ $index ] = $item;
                self::save_job( $job ); DLI_MCP_Store::event( $job_id, $item['client_ref'], $item['status'], $item['reason'], $job['feed_id'] );
            } finally { wp_set_current_user( $actor_before ); if ( $owned ) { Write_Lock::unlock(); } }
        }
    }
}
