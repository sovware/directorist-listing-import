<?php
/** One-source execution and actor-bound scheduled sync through durable listing jobs. */
defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Sync {
    private static $evidence = [];

    public static function configure( string $id, int $actor ): void {
        Directorist_Listing_Import_Feed_Manager::update_feed( $id, [ 'mcp_actor' => $actor ] );
        self::reschedule( $id );
    }

    public static function reschedule( string $id ): void {
        wp_clear_scheduled_hook( 'dli_mcp_sync_feed', [ $id ] );
        if ( '' !== DLI_MCP_Compatibility::reason() ) { return; }
        $feed = Directorist_Listing_Import_Feed_Manager::get_feed( $id );
        if ( ! $feed || empty( $feed['mcp_actor'] ) || 'active' !== $feed['status'] || 'sync' !== $feed['sync_mode'] || 'manual' === $feed['interval'] ) { return; }
        if ( '' !== DLI_MCP_Compatibility::image_reason( (int) ( $feed['directory_type'] ?: directorist_get_default_directory() ) ) ) { return; }
        $delay = [ 'hourly' => HOUR_IN_SECONDS, 'twicedaily' => 12 * HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS ][ $feed['interval'] ];
        $result = wp_schedule_event( max( time() + 1, (int) $feed['last_run'] + $delay ), $feed['interval'], 'dli_mcp_sync_feed', [ $id ], true );
        DLI_MCP_Service::check( ! is_wp_error( $result ) && false !== $result, 'Could not schedule this feed.', 'scheduler_unavailable' );
    }

    private static function context( string $id ): array {
        $feed = DLI_MCP_Service::feed( $id );
        $directory = (int) ( $feed['directory_type'] ?: directorist_get_default_directory() );
        DLI_MCP_Listing_Writer::directory( $directory, (int) $feed['category'] );
        DLI_MCP_Compatibility::assert_images( $directory );
        foreach ( DLI_MCP_Store::rows( 'job', 'admin' ) as $job ) {
            DLI_MCP_Service::check( ( $job['feed_id'] ?? '' ) !== $id || ! in_array( $job['status'], [ 'queued', 'running', 'stop-requested', 'outcome-unknown' ], true ), 'This source has unfinished work. Inspect its existing job.', 'dependency_blocked' );
        }
        DLI_MCP_Source::url( $feed['url'] );
        $settings = DLI_MCP_Configuration::settings( 'feed' );
        return [ $feed, $directory, $settings ];
    }

    public static function prepare( array $args ): array {
        [ $feed, $directory, $settings ] = self::context( $args['feed_id'] );
        $fingerprint = DLI_MCP_Listing_Writer::fingerprint( $directory );
        return [ 'state' => [ $feed, $settings, $fingerprint ], 'preview' => [ 'source' => DLI_MCP_Source::descriptor( $feed['url'] ), 'directory_id' => $directory, 'limit' => $settings['batch_size'], 'network_effect' => 'Fetch this source once, stage up to the saved batch size and create one durable import job using the saved RSS listing status. Existing source matches are skipped.', 'media_policy' => 'Import each new listing’s first public feed enclosure image; report image failures without discarding the listing.' ], 'execute' => static function () use ( $args, $feed, $directory, $settings ) {
            $evidence = self::$evidence[ DLI_MCP_Service::hash( $args ) ] ?? null;
            DLI_MCP_Service::check( $evidence && 'settled' === $evidence['data']['state'], 'The original feed result is unavailable.', 'outcome_unknown' );
            $result = self::accept( $evidence['id'], $evidence['data'], $feed, $directory, $settings, $args );
            self::configure( $feed['id'], get_current_user_id() );
            return $result;
        } ];
    }

    public static function dispatch( string $id, array $args ): array {
        try {
            [ $feed, $directory, $settings ] = self::context( $args['feed_id'] );
            $data = DLI_MCP_Source::feed( [ 'source_url' => $feed['url'], 'sample_size' => $settings['batch_size'] ] );
            self::$evidence[ DLI_MCP_Service::hash( $args ) ] = [ 'id' => $id, 'data' => $data ];
            return [ 'state' => $data['state'], 'reference' => $data['reason'] ?? $id ];
        } catch ( \Directorist\MCP\Support\Failure $error ) { return [ 'state' => 'failed', 'reference' => $error->reason ]; }
    }

    private static function accept( string $id, array $data, array $feed, int $directory, array $settings, array $args ): array {
        $candidates = array_values( array_column( $data['candidates'], null, 'candidate_id' ) );
        $snapshot = [ 'actor' => get_current_user_id(), 'site' => get_current_blog_id(), 'created' => time(), 'expires' => time() + DLI_MCP_Service::TTL, 'directory_id' => $directory, 'feed_id' => $feed['id'], 'fingerprint' => DLI_MCP_Listing_Writer::fingerprint( $directory ), 'args_hash' => DLI_MCP_Service::hash( $args ), 'source' => $data['source'], 'source_type' => 'feed', 'quality' => $data['quality'], 'candidates' => $candidates, 'continuation' => '' ];
        DLI_MCP_Store::put( $id, 'snapshot', $snapshot, 'ready', $snapshot['expires'] );
        $job = [ 'job_id' => '', 'status' => 'completed', 'accepted' => [] ];
        if ( $candidates ) {
            $input = [ 'directory_id' => $directory, 'snapshot_id' => $id, 'category_id' => (int) $feed['category'], 'status' => $settings['default_status'], 'duplicate_mode' => 'find_new_only', 'import_photos' => true, 'items' => [] ];
            foreach ( $candidates as $candidate ) { $input['items'][] = [ 'candidate_id' => $candidate['candidate_id'], 'client_ref' => $candidate['candidate_id'] ]; }
            $plan = DLI_MCP_Service::prepare_import( $input, true );
            $job = $plan['execute']();
        } else {
            self::append_feed_history( $feed, [ 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'job_id' => '' ] );
        }
        Directorist_Listing_Import_Feed_Manager::update_feed( $feed['id'], [ 'last_run' => time(), 'mcp_last_job' => $job['job_id'] ] );
        $result = [ 'snapshot_id' => $id, 'candidate_count' => count( $candidates ) ] + $job;
        DLI_MCP_Store::put( 'run:' . $id, 'feed-run', [ 'actor' => get_current_user_id(), 'args_hash' => DLI_MCP_Service::hash( $args ), 'result' => $result ], 'completed' );
        return $result;
    }

    /** A recurring event handles exactly its selected feed and never the global runner. */
    public static function tick( string $id ): void {
        if ( '' !== DLI_MCP_Compatibility::reason() || ! DLI_MCP_Store::ready() ) { return; }
        $prior = get_current_user_id(); $owned = ! \Directorist\MCP\Safety\Write_Lock::is_locked();
        \Directorist\MCP\Safety\Write_Lock::lock();
        try {
            $feed = Directorist_Listing_Import_Feed_Manager::get_feed( $id );
            if ( ! $feed || empty( $feed['mcp_actor'] ) || 'active' !== $feed['status'] || 'sync' !== $feed['sync_mode'] || 'manual' === $feed['interval'] ) { return; }
            DLI_MCP_Integration::refresh_actor( $feed['mcp_actor'] );
            DLI_MCP_Service::check( DLI_MCP_Integration::admin() && DLI_MCP_Integration::available() && DLI_MCP_Integration::listing_cap(), 'Scheduled import authority was revoked.', 'authority_revoked' );
            $delay = [ 'hourly' => HOUR_IN_SECONDS, 'twicedaily' => 12 * HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS ][ $feed['interval'] ];
            if ( time() < (int) $feed['last_run'] + $delay ) { return; }
            [ $feed, $directory, $settings ] = self::context( $id );
            $run = wp_generate_uuid4();
            // Persist the attempted interval before dispatch: process death cannot replay this request.
            DLI_MCP_Store::put( 'run:' . $run, 'feed-run', [ 'actor' => get_current_user_id(), 'feed_id' => $id, 'result' => [], 'args_hash' => '' ], 'running' );
            Directorist_Listing_Import_Feed_Manager::update_feed( $id, [ 'last_run' => time() ] );
            $data = DLI_MCP_Source::feed( [ 'source_url' => $feed['url'], 'sample_size' => $settings['batch_size'] ] );
            DLI_MCP_Service::check( 'settled' === $data['state'], 'Scheduled source request did not settle.', $data['reason'] ?? 'source_unavailable' );
            DLI_MCP_Integration::refresh_actor( $feed['mcp_actor'] );
            DLI_MCP_Service::check( DLI_MCP_Integration::admin() && DLI_MCP_Integration::available() && DLI_MCP_Integration::listing_cap(), 'Scheduled import authority was revoked during fetching.', 'authority_revoked' );
            \Directorist\MCP\Safety\Transaction::atomic( static function () use ( $run, $data, $feed, $directory, $settings ) { self::accept( $run, $data, $feed, $directory, $settings, [ 'feed_id' => $feed['id'] ] ); }, DLI_MCP_Service::tables(), [ DLI_MCP_Store::class, 'invalidate' ] );
        } catch ( \Throwable $error ) {
            $reason = DLI_MCP_Service::reason( $error );
            if ( isset( $feed ) && $feed && 'dependency_blocked' !== $reason ) { self::append_feed_history( $feed, [ 'imported' => 0, 'skipped' => 0, 'errors' => 1, 'error_msg' => $reason ] ); }
            DLI_MCP_Store::event( '', '', 'sync-blocked', $reason, $id );
        } finally { wp_set_current_user( $prior ); if ( $owned ) { \Directorist\MCP\Safety\Write_Lock::unlock(); } }
    }

    private static function append_feed_history( array $feed, array $result ): void {
        $history = (array) get_option( DLI_OPTION_LOGS, [] );
        array_unshift( $history, [ 'feed_id' => $feed['id'], 'feed_name' => $feed['name'], 'time' => time(), 'error_msg' => '' ] + $result );
        update_option( DLI_OPTION_LOGS, array_slice( $history, 0, 500 ), false );
    }

    public static function record_history( array $job ): void {
        $counts = array_count_values( array_column( $job['items'], 'status' ) );
        $result = [ 'job_id' => $job['job_id'], 'imported' => $counts['created'] ?? 0, 'updated' => $counts['updated'] ?? 0, 'skipped' => $counts['skipped-duplicate'] ?? 0, 'errors' => ( $counts['validation-failed'] ?? 0 ) + ( $counts['permission-blocked'] ?? 0 ) + ( $counts['outcome-unknown'] ?? 0 ) ];
        if ( 'places' === ( $job['source_type'] ?? '' ) ) {
            $history = (array) get_option( 'dgbi_import_history', [] );
            $result['errors'] = $result['errors'] ? array_fill( 0, $result['errors'], 'MCP item failure; inspect the job.' ) : [];
            $reviews = array_sum( array_column( $job['items'], 'reviews_created' ) );
            array_unshift( $history, [ 'time' => current_time( 'mysql' ), 'user_id' => $job['actor'], 'keyword' => $job['query']['keyword'] ?? '', 'location' => $job['query']['location'] ?? '', 'post_status' => $job['args']['status'] ?? 'draft', 'reviews' => array_sum( array_column( $job['items'], 'reviews_total' ) ), 'reviews_created' => $reviews ] + $result );
            update_option( 'dgbi_import_history', array_slice( $history, 0, 50 ), false );
        } else {
            $feed = Directorist_Listing_Import_Feed_Manager::get_feed( $job['feed_id'] );
            self::append_feed_history( $feed ?: [ 'id' => $job['feed_id'], 'name' => 'MCP feed import' ], $result );
        }
    }

    public static function reconcile( string $id, array $args ): array {
        try { $record = DLI_MCP_Store::get( 'run:' . $id, 'feed-run' ); return [ 'state' => ! empty( $record['result'] ) && $record['args_hash'] === DLI_MCP_Service::hash( $args ) ? 'settled' : 'unknown', 'reference' => $id ]; }
        catch ( \Throwable $error ) { return [ 'state' => 'unknown', 'reference' => $id ]; }
    }
    public static function verify( string $id, array $args, array $result ): bool {
        try { $record = DLI_MCP_Store::get( 'run:' . $id, 'feed-run' ); return $record['args_hash'] === DLI_MCP_Service::hash( $args ) && $record['result'] === $result; }
        catch ( \Throwable $error ) { return false; }
    }
}
