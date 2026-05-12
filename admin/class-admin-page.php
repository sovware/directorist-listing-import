<?php
/**
 * Admin Page — registers the Directorist submenu and handles form actions.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Directorist_Listing_Import_Admin_Page {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_post_dli_preview_feed', [ __CLASS__, 'handle_preview_feed' ] );
        add_action( 'admin_post_dli_add_feed',    [ __CLASS__, 'handle_add_feed' ] );
        add_action( 'admin_post_dli_save_feed',   [ __CLASS__, 'handle_save_feed' ] );
        add_action( 'admin_post_dli_toggle_feed', [ __CLASS__, 'handle_toggle_feed' ] );
        add_action( 'admin_post_dli_delete_feed', [ __CLASS__, 'handle_delete_feed' ] );
        add_action( 'admin_post_dli_save_settings', [ __CLASS__, 'handle_save_settings' ] );
        add_action( 'admin_post_dli_clear_logs',  [ __CLASS__, 'handle_clear_logs' ] );
        add_action( 'admin_menu', [ __CLASS__, 'hide_standalone_google_menu' ], 99 );
    }

    // ── Menu ─────────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=at_biz_dir',
            __( 'Listing Importer', 'directorist-listing-import' ),
            __( 'Listing Importer', 'directorist-listing-import' ),
            'manage_options',
            DLI_PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function hide_standalone_google_menu(): void {
        remove_submenu_page( 'edit.php?post_type=at_biz_dir', 'directorist-google-import' );
    }

    // ── Assets ───────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, DLI_PAGE_SLUG ) === false ) return;

        wp_enqueue_style(
            'directorist-listing-import-admin',
            DLI_PLUGIN_URL . 'assets/admin.css',
            [],
            DLI_VERSION
        );
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'directorist-listing-import' ) );
        }

        $active_tab = sanitize_key( $_GET['tab'] ?? 'google' );
        if ( ! in_array( $active_tab, [ 'google', 'rss' ], true ) ) {
            $active_tab = 'google';
        }

        $tabs = [
            'google' => __( 'Google Business', 'directorist-listing-import' ),
            'rss'    => __( 'From RSS/FEED', 'directorist-listing-import' ),
        ];
        $csv_import_url = admin_url( 'edit.php?post_type=at_biz_dir&page=tools' );
        ?>
        <div class="wrap dli-wrap">
            <div class="dli-header">
                <div>
                    <h1><?php esc_html_e( 'Listing Importer', 'directorist-listing-import' ); ?></h1>
                    <p class="dli-page-intro">
                        <?php esc_html_e( 'For CSV uploads, use Directorist core importer.', 'directorist-listing-import' ); ?>
                        <a href="<?php echo esc_url( $csv_import_url ); ?>"><?php esc_html_e( 'Open CSV importer', 'directorist-listing-import' ); ?></a>
                    </p>
                </div>
            </div>

            <nav class="nav-tab-wrapper dli-tabs dli-primary-tabs">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a
                        href="<?php echo esc_url( add_query_arg( [ 'page' => DLI_PAGE_SLUG, 'tab' => $slug ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) ); ?>"
                        class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"
                    ><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            if ( 'google' === $active_tab && class_exists( '\DLIG\Plugin' ) ) {
                \DLIG\Plugin::instance()->admin_page->render_embedded_page();
            } else {
                $active_tab = sanitize_key( $_GET['rss_tab'] ?? 'feeds' );
                if ( ! in_array( $active_tab, [ 'feeds', 'logs', 'settings' ], true ) ) {
                    $active_tab = 'feeds';
                }
                require DLI_PLUGIN_DIR . 'admin/views/admin-page.php';
            }
            ?>
        </div>
        <?php
    }

    // ── Form handlers ────────────────────────────────────────────────────────

    public static function handle_preview_feed(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'dli_preview_feed' );

        $name       = sanitize_text_field( $_POST['feed_name'] ?? '' );
        $source_url = esc_url_raw( trim( $_POST['feed_url'] ?? '' ) );
        $directory_type = absint( $_POST['directory_type'] ?? 0 );
        $category   = absint( $_POST['feed_category'] ?? 0 );
        $interval   = sanitize_key( $_POST['feed_interval'] ?? 'daily' );
        $sync_mode  = sanitize_key( $_POST['sync_mode'] ?? 'one_time' );
        $resolved   = Directorist_Listing_Import_Feed_Discovery::resolve( $source_url );

        if ( is_wp_error( $resolved ) ) {
            self::redirect_with_notice( 'feed_discovery_failed', $resolved->get_error_message(), [ 'rss_tab' => 'feeds' ] );
        }

        $items = Directorist_Listing_Import_Feed_Discovery::preview_listings( $resolved['feed_url'], 5 );
        if ( is_wp_error( $items ) ) {
            self::redirect_with_notice( 'feed_discovery_failed', $items->get_error_message(), [ 'rss_tab' => 'feeds' ] );
        }

        update_user_meta( get_current_user_id(), self::preview_results_key(), [
            'name'       => $name ?: self::default_source_name( $source_url ),
            'source_url' => $resolved['source_url'],
            'feed_url'   => $resolved['feed_url'],
            'directory_type' => $directory_type,
            'category'   => $category,
            'interval'   => in_array( $interval, [ 'hourly', 'twicedaily', 'daily' ], true ) ? $interval : 'daily',
            'sync_mode'  => in_array( $sync_mode, [ 'one_time', 'sync' ], true ) ? $sync_mode : 'one_time',
            'items'      => $items,
            'quality'    => Directorist_Listing_Import_Feed_Discovery::quality_score( $items ),
        ] );

        self::redirect_with_notice( 'feed_preview_ready', '', [ 'rss_tab' => 'feeds' ] );
    }

    public static function handle_add_feed(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'dli_add_feed' );

        $name       = sanitize_text_field( $_POST['feed_name'] ?? '' );
        $source_url = esc_url_raw( trim( $_POST['feed_url'] ?? '' ) );
        $directory_type = absint( $_POST['directory_type'] ?? 0 );
        $category   = absint( $_POST['feed_category'] ?? 0 );
        $interval   = sanitize_key( $_POST['feed_interval'] ?? 'daily' );
        $sync_mode  = sanitize_key( $_POST['sync_mode'] ?? 'sync' );
        $resolved   = Directorist_Listing_Import_Feed_Discovery::resolve( $source_url );

        if ( is_wp_error( $resolved ) ) {
            self::redirect_with_notice( 'feed_discovery_failed', $resolved->get_error_message() );
        }

        $url = $resolved['feed_url'];
        if ( '' === $name ) {
            $name = self::default_source_name( $source_url );
        }

        if ( $url && self::feed_url_exists( $url ) ) {
            self::redirect_with_notice( 'feed_exists' );
        }

        if ( $url ) {
            $status = 'one_time' === $sync_mode ? 'paused' : 'active';
            $interval = 'one_time' === $sync_mode ? 'manual' : $interval;
            $feed_id = Directorist_Listing_Import_Feed_Manager::add_feed( compact( 'name', 'url', 'source_url', 'directory_type', 'category', 'interval', 'sync_mode', 'status' ) );

            if ( 'one_time' === $sync_mode ) {
                $feed = Directorist_Listing_Import_Feed_Manager::get_feed( $feed_id );
                if ( $feed ) {
                    $result = ( new Directorist_Listing_Import_Importer() )->run_feed( $feed );
                    self::redirect_with_notice( 'run_complete', '', [
                        'rss_tab'      => 'logs',
                        'dli_imported' => $result['imported'],
                        'dli_skipped'  => $result['skipped'],
                        'dli_errors'   => $result['errors'],
                    ] );
                }
            }
        }

        self::redirect_with_notice( 'feed_added', self::feed_success_message( $resolved ) );
    }

    public static function handle_save_feed(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'dli_save_feed' );

        $feed_id    = sanitize_text_field( $_POST['feed_id'] ?? '' );
        $name       = sanitize_text_field( $_POST['feed_name'] ?? '' );
        $source_url = esc_url_raw( trim( $_POST['feed_url'] ?? '' ) );
        $directory_type = absint( $_POST['directory_type'] ?? 0 );
        $category   = absint( $_POST['feed_category'] ?? 0 );
        $interval   = sanitize_key( $_POST['feed_interval'] ?? 'daily' );
        $resolved   = Directorist_Listing_Import_Feed_Discovery::resolve( $source_url );

        $updated = false;
        if ( is_wp_error( $resolved ) ) {
            self::redirect_with_notice( 'feed_discovery_failed', $resolved->get_error_message(), [ 'dli_edit_feed' => $feed_id ] );
        }

        $url = $resolved['feed_url'];

        if ( $url && self::feed_url_exists( $url, $feed_id ) ) {
            self::redirect_with_notice( 'feed_exists', '', [ 'dli_edit_feed' => $feed_id ] );
        }

        if ( $feed_id && $url ) {
            $updated = Directorist_Listing_Import_Feed_Manager::update_feed(
                $feed_id,
                compact( 'name', 'url', 'source_url', 'directory_type', 'category', 'interval' )
            );
        }

        self::redirect_with_notice( $updated ? 'feed_updated' : 'feed_not_updated', $updated ? self::feed_success_message( $resolved ) : '' );
    }

    public static function handle_toggle_feed(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'dli_toggle_feed' );

        $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
        $feed    = Directorist_Listing_Import_Feed_Manager::get_feed( $feed_id );

        if ( ! $feed ) {
            wp_die( __( 'Feed not found.', 'directorist-listing-import' ) );
        }

        $new_status = ( $feed['status'] ?? 'active' ) === 'active' ? 'paused' : 'active';
        Directorist_Listing_Import_Feed_Manager::update_feed( $feed_id, [ 'status' => $new_status ] );

        wp_safe_redirect( add_query_arg( [
            'page'       => DLI_PAGE_SLUG,
            'tab'        => 'rss',
            'dli_notice' => 'feed_' . $new_status,
        ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) );
        exit;
    }

    public static function handle_delete_feed(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'dli_delete_feed' );

        $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
        Directorist_Listing_Import_Feed_Manager::delete_feed( $feed_id );

        wp_safe_redirect( add_query_arg( [
            'page'       => DLI_PAGE_SLUG,
            'tab'        => 'rss',
            'dli_notice' => 'feed_deleted',
        ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) );
        exit;
    }

    public static function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'dli_save_settings' );

        $settings = [
            'default_status' => in_array( $_POST['default_status'] ?? '', [ 'pending', 'publish' ], true )
                                    ? $_POST['default_status'] : 'pending',
            'batch_size'     => max( 1, min( 100, absint( $_POST['batch_size'] ?? 25 ) ) ),
        ];
        update_option( DLI_OPTION_SETTINGS, $settings );

        wp_safe_redirect( add_query_arg( [
            'page'       => DLI_PAGE_SLUG,
            'tab'        => 'rss',
            'rss_tab'    => 'settings',
            'dli_notice' => 'settings_saved',
        ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) );
        exit;
    }

    public static function handle_clear_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'dli_clear_logs' );

        update_option( DLI_OPTION_LOGS, [] );

        wp_safe_redirect( add_query_arg( [
            'page'       => DLI_PAGE_SLUG,
            'tab'        => 'rss',
            'rss_tab'    => 'logs',
            'dli_notice' => 'logs_cleared',
        ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) );
        exit;
    }

    private static function feed_url_exists( string $url, string $exclude_feed_id = '' ): bool {
        foreach ( Directorist_Listing_Import_Feed_Manager::get_feeds() as $feed ) {
            if ( $exclude_feed_id && ( $feed['id'] ?? '' ) === $exclude_feed_id ) {
                continue;
            }

            if ( untrailingslashit( $feed['url'] ?? '' ) === untrailingslashit( $url ) ) {
                return true;
            }
        }

        return false;
    }

    private static function redirect_with_notice( string $notice, string $message = '', array $extra_args = [] ): void {
        if ( $message ) {
            set_transient(
                self::notice_transient_key(),
                wp_strip_all_tags( $message ),
                MINUTE_IN_SECONDS
            );
        }

        wp_safe_redirect(
            add_query_arg(
                array_merge(
                    [
                        'page'       => DLI_PAGE_SLUG,
                        'tab'        => 'rss',
                        'dli_notice' => $notice,
                    ],
                    $extra_args
                ),
                admin_url( 'edit.php?post_type=at_biz_dir' )
            )
        );
        exit;
    }

    public static function notice_transient_key(): string {
        return 'dli_admin_notice_' . get_current_user_id();
    }

    public static function preview_results_key(): string {
        return 'dli_preview_results_' . get_current_user_id();
    }

    private static function feed_success_message( array $resolved ): string {
        $message = $resolved['message'] ?? '';
        $preview = Directorist_Listing_Import_Feed_Discovery::preview_items( $resolved['feed_url'] ?? '', 3 );

        if ( is_wp_error( $preview ) || empty( $preview ) ) {
            return $message;
        }

        $titles = array_filter( wp_list_pluck( $preview, 'title' ) );
        if ( empty( $titles ) ) {
            return $message;
        }

        return trim( $message . ' ' . sprintf(
            /* translators: %s is a comma-separated list of listing titles. */
            __( 'Preview items: %s', 'directorist-listing-import' ),
            implode( ', ', array_slice( $titles, 0, 3 ) )
        ) );
    }

    private static function default_source_name( string $source_url ): string {
        $host = wp_parse_url( $source_url, PHP_URL_HOST );
        $host = $host ? preg_replace( '/^www\./', '', strtolower( $host ) ) : '';

        return $host ? sprintf( __( '%s Source', 'directorist-listing-import' ), $host ) : __( 'Imported Source', 'directorist-listing-import' );
    }
}
