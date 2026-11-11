<?php
/** Directorist MCP extension API 1 registration. */
defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Integration {
    const OWNER = 'listing-import';
    const CONTRACT = '1.2.0';
    public static function admin(): bool { return get_current_user_id() > 0 && current_user_can( 'manage_options' ); }
    public static function listing_cap(): bool {
        $type = get_post_type_object( defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir' );
        return is_callable( [ '\ATBDP_Add_Listing', 'current_user_can_create' ] ) && $type && current_user_can( $type->cap->create_posts ) && \ATBDP_Add_Listing::current_user_can_create();
    }
    public static function available(): bool {
        $plugin = plugin_basename( DLI_FILE );
        $active = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        return '' === DLI_MCP_Compatibility::reason() && in_array( $plugin, $active, true ) && in_array( 'directorist/directorist-base.php', $active, true );
    }
    /** Refresh authority after a provider request or between worker items. */
    public static function refresh_actor( int $actor ): void {
        clean_user_cache( $actor ); wp_set_current_user( 0 );
        foreach ( [ 'active_plugins', 'directorist_mcp_epoch', 'directorist_mcp_extensions_epoch' ] as $option ) { wp_cache_delete( $option, 'options' ); }
        wp_cache_delete( 'alloptions', 'options' );
        wp_set_current_user( $actor );
    }
    public static function admission( int $actor ): bool { return $actor === get_current_user_id() && self::admin(); }
    public static function permitted( string $action, array $args, int $actor ): bool {
        if ( ! self::admission( $actor ) || ! self::available() || '' !== DLI_MCP_Compatibility::reason( $action ) || ! DLI_MCP_Schema::typed( $args, DLI_MCP_Schema::input( $action ) ) ) { return false; }
        return ! in_array( $action, [ 'create-feed', 'update-feed', 'delete-feed', 'import-candidates', 'run-feed', 'import-photo' ], true ) || self::listing_cap();
    }
    public static function actions(): array { return [ 'get-settings', 'update-settings', 'get-field-mapping', 'update-field-mapping', 'list-import-history', 'run-feed', 'get-place-details', 'import-photo', 'get-schema', 'list-feeds', 'get-feed', 'create-feed', 'update-feed', 'delete-feed', 'discover-feed', 'search-places', 'get-staged-candidates', 'import-candidates', 'list-jobs', 'get-job', 'cancel-job', 'list-import-logs' ]; }
    public static function register( $registry ): void {
        if ( '' !== DLI_MCP_Compatibility::reason() || ! is_object( $registry ) || ! is_callable( [ $registry, 'register_extension' ] ) || ! is_callable( [ $registry, 'register_tool' ] ) ) { return; }
        $result = $registry->register_extension( [ 'owner' => self::OWNER, 'plugin' => plugin_basename( DLI_FILE ), 'label' => 'Directorist Listing Import', 'version' => DLI_VERSION, 'contract_version' => self::CONTRACT, 'api_version' => 1, 'requires_core' => DLI_MCP_Compatibility::MINIMUM_CORE, 'capability' => 'manage_options', 'requires' => [ 'directorist/directorist-base.php' ], 'availability' => [ self::class, 'available' ], 'admission' => [ self::class, 'admission' ] ] );
        if ( is_wp_error( $result ) ) { return; }
        foreach ( self::actions() as $action ) {
            if ( '' !== DLI_MCP_Compatibility::reason( $action ) ) { continue; }
            $external = in_array( $action, [ 'discover-feed', 'search-places', 'run-feed', 'get-place-details', 'import-photo' ], true );
            $write = $external || in_array( $action, [ 'create-feed', 'update-feed', 'delete-feed', 'import-candidates', 'cancel-job', 'update-settings', 'update-field-mapping' ], true );
            $definition = [ 'owner' => self::OWNER, 'action' => $action, 'domain' => 'extension', 'group' => 'Listing Import', 'description' => self::description( $action ), 'contract_version' => self::CONTRACT, 'write' => $write, 'schema' => DLI_MCP_Schema::input( $action ), 'output_schema' => DLI_MCP_Schema::output( $action ), 'discover' => [ self::class, 'admission' ], 'permission' => static function ( array $args, int $actor ) use ( $action ): bool { return self::permitted( $action, $args, $actor ); }, 'project' => static function ( array $result, array $args, int $actor ) use ( $action ): array {
                DLI_MCP_Service::check( self::permitted( $action, $args, $actor ), 'Import access is no longer permitted.', 'forbidden' );
                if ( isset( $result['attachment_id'] ) ) { \Directorist\MCP\Runtime\Access_Control::attachment( $result['attachment_id'], true ); }
                // Replays recheck current snapshot access/freshness and listing references.
                if ( isset( $result['snapshot_id'] ) ) { DLI_MCP_Service::snapshot( $result['snapshot_id'], false ); }
                if ( isset( $result['accepted'] ) ) {
                    foreach ( $result['accepted'] as &$item ) { if ( $item['listing_id'] && ! current_user_can( 'edit_post', $item['listing_id'] ) ) { $item['listing_id'] = 0; } } unset( $item );
                }
                return array_intersect_key( $result, DLI_MCP_Schema::output( $action )['properties'] );
            } ];
            // Discovery has the (args, actor) signature; inventory does not depend on an actor.
            $definition['discover'] = static function ( array $args, int $actor ): bool { return self::admission( $actor ); };
            if ( $write ) { $definition += [ 'preview_schema' => DLI_MCP_Schema::preview( $action ), 'tables' => DLI_MCP_Service::tables(), 'invalidate' => [ DLI_MCP_Store::class, 'invalidate' ], 'prepare' => static function ( array $args ) use ( $action ): array { return DLI_MCP_Service::prepare( $action, $args ); } ]; }
            else { $definition['read'] = static function ( array $args ) use ( $action ): array { return DLI_MCP_Service::read( $action, $args ); }; }
            if ( $external ) {
                $definition += [ 'external_effects' => true, 'idempotent' => true, 'dispatch' => static function ( string $id, array $args ) use ( $action ): array { return DLI_MCP_Service::dispatch( $action, $id, $args ); }, 'reconcile' => [ DLI_MCP_Service::class, 'reconcile' ], 'verify_record' => [ DLI_MCP_Service::class, 'verify' ] ];
            }
            $registry->register_tool( $definition );
        }
    }
    private static function description( string $action ): string {
        $descriptions = [ 'get-settings' => 'Read saved native Google or RSS defaults and credential readiness; never returns a credential.', 'update-settings' => 'Preview and save native Google or RSS settings. Credentials are encrypted and never included in results.', 'get-field-mapping' => 'Read effective saved Google mappings and compatible destinations for a directory.', 'update-field-mapping' => 'Preview and save selected Google field mappings, or reset to native defaults.', 'list-import-history' => 'Read actual Google or RSS UI import history. Clearing history is not supported.', 'run-feed' => 'Preview and run exactly one saved RSS source through bounded staging and a durable import job.', 'get-place-details' => 'Preview one potentially billable Google detail request for a staged candidate. Stage full mapping fields, hours and reviews.', 'import-photo' => 'Preview one staged candidate photo import into the WordPress media library; provider charges may apply.', 'get-schema' => 'Read supported sources, active directory mappings, native policy, bounds and prerequisite flags.', 'list-feeds' => 'List safe feed configuration summaries without starting work.', 'get-feed' => 'Read one feed configuration with a redacted source and version.', 'create-feed' => 'Preview and confirm a source; defaults paused/manual. Active sync uses bounded scheduled jobs.', 'update-feed' => 'Preview and confirm source changes, including pause/resume and scheduled sync intervals.', 'delete-feed' => 'Preview and confirm removal of feed configuration, retaining imported listings.', 'discover-feed' => 'Preview and confirm bounded public feed discovery into a fifteen-minute staged snapshot.', 'search-places' => 'Preview and confirm one bounded, potentially billable Places search into a staged snapshot.', 'get-staged-candidates' => 'Read authorized fresh staged candidates and native duplicate decisions without remote calls.', 'import-candidates' => 'Preview and confirm one durable job for 1–50 explicitly selected candidates.', 'list-jobs' => 'Read authorized job progress and filtered totals without starting workers.', 'get-job' => 'Read independent per-candidate outcomes, safe listing references and recovery guidance.', 'cancel-job' => 'Preview and confirm stopping future work, retaining all completed outcomes.', 'list-import-logs' => 'Read authorized safe native/job events without provider error bodies.' ];
        return $descriptions[ $action ];
    }
}
