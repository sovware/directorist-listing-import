<?php
/** Extension-owned durable staging, job checkpoints and event storage. */
defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Store {
    const VERSION = '1';

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'dli_mcp_records'; }

    /** Only activation/admin upgrade paths call this; never discovery or preview. */
    public static function upgrade(): void {
        if ( self::VERSION === get_option( 'dli_mcp_store_version' ) ) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            id varchar(64) NOT NULL,
            kind varchar(20) NOT NULL,
            actor bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL,
            created bigint(20) unsigned NOT NULL,
            expires bigint(20) unsigned NOT NULL DEFAULT 0,
            data longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY scope (kind,actor,status,created)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
        if ( self::ready() ) { update_option( 'dli_mcp_store_version', self::VERSION, false ); }
    }

    public static function ready(): bool {
        global $wpdb;
        return 'INNODB' === strtoupper( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', self::table() ) ) );
    }

    public static function put( string $id, string $kind, array $data, string $status = 'ready', int $expires = 0 ): void {
        global $wpdb;
        $row = [ 'id' => $id, 'kind' => $kind, 'actor' => $data['actor'] ?? get_current_user_id(), 'status' => $status, 'created' => $data['created'] ?? time(), 'expires' => $expires, 'data' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ];
        DLI_MCP_Service::check( false !== $wpdb->replace( self::table(), $row ), 'Could not persist the import checkpoint.', 'outcome_unknown' );
    }

    public static function get( string $id, string $kind, bool $authorized = true ): array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%s AND kind=%s', $id, $kind ), ARRAY_A );
        DLI_MCP_Service::check( $row, 'Import record unavailable.', 'not_found' );
        if ( $authorized ) {
            DLI_MCP_Service::check( DLI_MCP_Integration::admin() && ( (int) $row['actor'] === get_current_user_id() || current_user_can( 'manage_options' ) ), 'Import record unavailable.', 'not_found' );
        }
        $data = json_decode( $row['data'], true );
        DLI_MCP_Service::check( is_array( $data ), 'Import checkpoint is unreadable.', 'outcome_unknown' );
        return $data;
    }

    public static function rows( string $kind, string $scope = 'own', string $status = '' ): array {
        global $wpdb;
        $where = $wpdb->prepare( 'kind=%s', $kind );
        if ( 'admin' !== $scope ) { $where .= $wpdb->prepare( ' AND actor=%d', get_current_user_id() ); }
        if ( $status ) { $where .= $wpdb->prepare( ' AND status=%s', $status ); }
        return array_map( static function ( $json ) { return json_decode( $json, true ); }, $wpdb->get_col( 'SELECT data FROM ' . self::table() . ' WHERE ' . $where . ' ORDER BY created DESC,id DESC' ) );
    }

    public static function event( string $job, string $ref, string $status, string $reason = '', string $feed = '' ): void {
        self::put( wp_generate_uuid4(), 'event', [ 'job_id' => $job, 'feed_id' => $feed, 'client_ref' => $ref, 'status' => $status, 'reason' => $reason, 'time' => time() ] );
    }

    public static function invalidate(): void {
        wp_cache_delete( DLI_OPTION_FEEDS, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) { wp_cache_set_posts_last_changed(); }
    }
}
