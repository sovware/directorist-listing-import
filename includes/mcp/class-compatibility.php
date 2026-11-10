<?php
/** Keep optional MCP integration separate from the native importer UI. */
defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Compatibility {
    const MINIMUM_CORE = '8.11';
    const SETTINGS_CORE = '8.11.3';

    /** Check the public entry points before registering tools or waking workers. */
    public static function reason( string $action = '' ): string {
        if ( ! defined( 'ATBDP_VERSION' ) || version_compare( ATBDP_VERSION, self::MINIMUM_CORE, '<' ) ) {
            return __( 'Listing Importer MCP operations require Directorist 8.11 or later. Update Directorist to use MCP; the Google Business and RSS/Feed screens remain available.', 'directorist-listing-import' );
        }
        $methods = [
            'Directorist\MCP\Support\Canonical_Data' => [ 'hash' ],
            'Directorist\MCP\Support\Validation' => [ 'directory' ],
            'Directorist\MCP\Safety\Write_Lock' => [ 'lock', 'unlock', 'is_locked' ],
            'Directorist\MCP\Safety\Transaction' => [ 'atomic' ],
            'Directorist\MCP\Registration\Schema_Validator' => [ 'schema' ],
            'Directorist\MCP\Runtime\Access_Control' => [ 'attachment', 'term_cap' ],
            'Directorist\MCP\Tool_Providers\Listings\Listing_Schema' => [ 'definitions', 'schema', 'version', 'directory' ],
            'Directorist\MCP\Tool_Providers\Listings\Listing_Policy' => [ 'policy', 'initial_statuses' ],
            'Directorist\MCP\Tool_Providers\Listings\Write_Callbacks' => [ 'prepare' ],
            'Directorist\MCP\Tool_Providers\Taxonomies\Term_Validator' => [ 'term', 'scope' ],
            'Directorist\MCP\Tool_Providers\Taxonomies\Write_Callbacks' => [ 'prepare' ],
            'Directorist\Fields\Fields' => [ 'create' ],
            'ATBDP_Add_Listing' => [ 'current_user_can_create' ],
        ];
        if ( ! is_subclass_of( 'Directorist\MCP\Support\Failure', \RuntimeException::class ) ) {
            return self::runtime_message();
        }
        foreach ( $methods as $class => $names ) {
            foreach ( $names as $name ) {
                if ( ! is_callable( [ $class, $name ] ) ) { return self::runtime_message(); }
            }
        }
        if ( 'update-settings' === $action && ( version_compare( ATBDP_VERSION, self::SETTINGS_CORE, '<' ) || ( new \ReflectionMethod( 'Directorist\MCP\Registration\Schema_Validator', 'schema' ) )->getNumberOfParameters() < 3 ) ) {
            return __( 'Saving settings through MCP requires Directorist 8.11.3 or later. Update Directorist or use the Google Business and RSS/Feed Settings screens.', 'directorist-listing-import' );
        }
        return '';
    }

    private static function runtime_message(): string {
        return __( 'Listing Importer MCP operations are unavailable because required Directorist MCP components are missing. Update or reinstall Directorist 8.11.3 or later. The Google Business and RSS/Feed screens remain available.', 'directorist-listing-import' );
    }

    /** Image writes also depend on the active directory field implementation. */
    public static function image_reason( int $directory ): string {
        $reason = self::reason();
        if ( $reason ) { return $reason; }
        $definition = \Directorist\MCP\Tool_Providers\Listings\Listing_Schema::definitions( $directory )['images'] ?? null;
        if ( ! $definition ) { return ''; }
        $field = \Directorist\Fields\Fields::create( $definition['props'] );
        if ( $field instanceof \Directorist\Fields\Image_Upload_Field && get_class( $field ) !== \Directorist\Fields\Image_Upload_Field::class && ( version_compare( ATBDP_VERSION, self::SETTINGS_CORE, '<' ) || ! $definition['writable'] ) ) {
            return __( 'MCP image imports for this directory require Directorist 8.11.3 or later. Update Directorist, import without images, or use the native import screen.', 'directorist-listing-import' );
        }
        return '';
    }

    public static function assert_supported( string $action = '' ): void {
        $reason = self::reason( $action );
        if ( $reason ) { self::fail( $reason ); }
    }

    public static function assert_images( int $directory ): void {
        $reason = self::image_reason( $directory );
        if ( $reason ) { self::fail( $reason ); }
    }

    private static function fail( string $message ): void {
        if ( class_exists( '\Directorist\MCP\Support\Failure' ) ) {
            throw new \Directorist\MCP\Support\Failure( 'core_update_required', $message );
        }
        throw new \RuntimeException( $message );
    }

    /** Native screens can use a lock only when that optional method exists. */
    public static function native_lock(): void {
        if ( is_callable( [ '\Directorist\MCP\Safety\Write_Lock', 'native_lock' ] ) ) {
            \Directorist\MCP\Safety\Write_Lock::native_lock();
        }
    }

    public static function status( int $directory ): array {
        $settings = self::reason( 'update-settings' );
        $images = self::image_reason( $directory );
        return [ 'core_version' => defined( 'ATBDP_VERSION' ) ? ATBDP_VERSION : '', 'minimum_core' => self::MINIMUM_CORE, 'full_support_core' => self::SETTINGS_CORE, 'settings_updates_available' => '' === $settings, 'image_imports_available' => '' === $images, 'message' => trim( $settings . ' ' . $images ) ];
    }

    public static function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) || ( $_GET['page'] ?? '' ) !== DLI_PAGE_SLUG ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $reason = self::reason( 'update-settings' );
        if ( ! $reason ) { return; }
        echo '<div class="notice notice-warning"><p>' . esc_html( $reason ) . '</p></div>';
    }
}
