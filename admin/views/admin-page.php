<?php
/**
 * RSS/feed import view — Feeds, Logs, and Settings tabs.
 *
 * Variables in scope: $active_tab (string)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$feeds    = Directorist_Listing_Import_Feed_Manager::get_feeds();
$logs     = array_reverse( (array) get_option( DLI_OPTION_LOGS, [] ) );
$settings = (array) get_option( DLI_OPTION_SETTINGS, [] );
$editing_feed_id = sanitize_text_field( $_GET['dli_edit_feed'] ?? '' );
$editing_feed    = $editing_feed_id ? Directorist_Listing_Import_Feed_Manager::get_feed( $editing_feed_id ) : null;
$notice_detail   = get_transient( Directorist_Listing_Import_Admin_Page::notice_transient_key() );
$preview_results = get_user_meta( get_current_user_id(), Directorist_Listing_Import_Admin_Page::preview_results_key(), true );
if ( $notice_detail ) {
    delete_transient( Directorist_Listing_Import_Admin_Page::notice_transient_key() );
}
if ( $preview_results ) {
    delete_user_meta( get_current_user_id(), Directorist_Listing_Import_Admin_Page::preview_results_key() );
}

// Directorist categories for the dropdown
$dir_categories = get_terms( [
    'taxonomy'   => 'at_biz_dir-category',
    'hide_empty' => false,
] );
$directory_types = get_terms( [
    'taxonomy'   => defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types',
    'hide_empty' => false,
] );

// ── Admin notices ──────────────────────────────────────────────────────────
$notice = sanitize_key( $_GET['dli_notice'] ?? '' );
$notice_map = [
    'feed_added'     => [ 'success', 'Feed added successfully.' ],
    'feed_exists'    => [ 'warning', 'A feed with this URL already exists.' ],
    'feed_updated'   => [ 'success', 'Feed updated.' ],
    'feed_not_updated' => [ 'error', 'Feed could not be updated.' ],
    'feed_discovery_failed' => [ 'error', 'Feed could not be added.' ],
    'feed_active'    => [ 'success', 'Feed resumed.' ],
    'feed_paused'    => [ 'success', 'Feed paused.' ],
    'feed_deleted'   => [ 'success', 'Feed deleted.' ],
    'settings_saved' => [ 'success', 'Settings saved.' ],
    'logs_cleared'   => [ 'success', 'Logs cleared.' ],
    'feed_preview_ready' => [ 'success', 'Source preview ready.' ],
    'run_complete'   => [
        'success',
        sprintf(
            'Import complete — %d imported, %d skipped, %d errors.',
            absint( $_GET['dli_imported'] ?? 0 ),
            absint( $_GET['dli_skipped']  ?? 0 ),
            absint( $_GET['dli_errors']   ?? 0 )
        ),
    ],
];
?>
<div class="dli-rss-panel">
    <?php if ( $notice && isset( $notice_map[ $notice ] ) ) :
        [$type, $msg] = $notice_map[ $notice ]; ?>
        <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
            <p><?php echo esc_html( $msg ); ?></p>
            <?php if ( $notice_detail ) : ?>
                <p><?php echo esc_html( $notice_detail ); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Tab nav -->
    <nav class="nav-tab-wrapper dli-tabs dli-secondary-tabs">
        <?php
        $tabs = [
            'feeds'    => 'Sources',
            'logs'     => 'History',
            'settings' => 'Settings',
        ];
        foreach ( $tabs as $slug => $label ) :
            $class = ( $active_tab === $slug ) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url   = add_query_arg( [ 'page' => DLI_PAGE_SLUG, 'tab' => 'rss', 'rss_tab' => $slug ], admin_url( 'edit.php?post_type=at_biz_dir' ) );
        ?>
            <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
                <?php echo esc_html( $label ); ?>
                <?php if ( $slug === 'feeds' ) : ?>
                    <span class="dli-badge"><?php echo count( $feeds ); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="dli-tab-content">

        <?php /* ───────────── FEEDS TAB ───────────── */ ?>
        <?php if ( $active_tab === 'feeds' ) : ?>

            <div class="dli-card dli-connected-sources">
                <h2><?php esc_html_e( 'Connected Sources', 'directorist-listing-import' ); ?></h2>

                <?php if ( empty( $feeds ) ) : ?>
                    <div class="dli-empty-state">
                        <h3><?php esc_html_e( 'No sources added', 'directorist-listing-import' ); ?></h3>
                        <p><?php esc_html_e( 'Add a listing source to preview and import listings.', 'directorist-listing-import' ); ?></p>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped dli-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Source', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Directory', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Category', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Mode', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Last Run', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'directorist-listing-import' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $feeds as $feed ) :
                                $last_run  = $feed['last_run'] ? human_time_diff( $feed['last_run'] ) . ' ago' : 'Never';
                                $cat_name  = '—';
                                if ( $feed['category'] ) {
                                    $term = get_term( $feed['category'], 'at_biz_dir-category' );
                                    if ( $term && ! is_wp_error( $term ) ) $cat_name = $term->name;
                                }
                                $directory_name = '—';
                                if ( ! empty( $feed['directory_type'] ) ) {
                                    $directory_term = get_term( $feed['directory_type'], defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types' );
                                    if ( $directory_term && ! is_wp_error( $directory_term ) ) $directory_name = $directory_term->name;
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $feed['name'] ); ?></strong></td>
                                <td>
                                    <a href="<?php echo esc_url( $feed['source_url'] ?? $feed['url'] ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html( wp_trim_words( $feed['source_url'] ?? $feed['url'], 8, '…' ) ); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html( $directory_name ); ?></td>
                                <td><?php echo esc_html( $cat_name ); ?></td>
                                <td>
                                    <?php if ( ( $feed['sync_mode'] ?? 'sync' ) === 'one_time' ) : ?>
                                        <?php esc_html_e( 'One-time', 'directorist-listing-import' ); ?>
                                    <?php else : ?>
                                        <?php echo esc_html( ucfirst( $feed['interval'] ) ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $last_run ); ?></td>
                                <td>
                                    <span class="dli-badge dli-badge--<?php echo esc_attr( $feed['status'] ); ?>">
                                        <?php echo esc_html( ucfirst( $feed['status'] ) ); ?>
                                    </span>
                                </td>
                                <td class="dli-actions">
                                    <a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'page' => DLI_PAGE_SLUG, 'tab' => 'rss', 'dli_edit_feed' => $feed['id'] ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) ); ?>">
                                        <?php esc_html_e( 'Edit', 'directorist-listing-import' ); ?>
                                    </a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                        <input type="hidden" name="action"  value="dli_run_feed_now">
                                        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed['id'] ); ?>">
                                        <?php wp_nonce_field( 'dli_run_now' ); ?>
                                        <button type="submit" class="button button-small"><?php esc_html_e( 'Run', 'directorist-listing-import' ); ?></button>
                                    </form>
                                    <?php if ( ( $feed['sync_mode'] ?? 'sync' ) !== 'one_time' ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                            <input type="hidden" name="action" value="dli_toggle_feed">
                                            <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed['id'] ); ?>">
                                            <?php wp_nonce_field( 'dli_toggle_feed' ); ?>
                                            <button type="submit" class="button button-small">
                                                <?php echo ( $feed['status'] ?? 'active' ) === 'active' ? esc_html__( 'Pause', 'directorist-listing-import' ) : esc_html__( 'Resume', 'directorist-listing-import' ); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                          onsubmit="return confirm('Delete this source?')">
                                        <input type="hidden" name="action"  value="dli_delete_feed">
                                        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed['id'] ); ?>">
                                        <?php wp_nonce_field( 'dli_delete_feed' ); ?>
                                        <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'directorist-listing-import' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <details class="dli-card dli-manual-source" open>
                <summary><?php echo $editing_feed ? esc_html__( 'Edit Source', 'directorist-listing-import' ) : esc_html__( 'Add Listing Source', 'directorist-listing-import' ); ?></summary>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( $editing_feed ? 'dli_save_feed' : 'dli_preview_feed' ); ?>">
                    <?php if ( $editing_feed ) : ?>
                        <input type="hidden" name="feed_id" value="<?php echo esc_attr( $editing_feed['id'] ); ?>">
                        <?php wp_nonce_field( 'dli_save_feed' ); ?>
                    <?php else : ?>
                        <?php wp_nonce_field( 'dli_preview_feed' ); ?>
                    <?php endif; ?>

                    <table class="form-table">
                        <?php if ( ! $editing_feed ) : ?>
                        <tr>
                            <th><label for="sync_mode"><?php esc_html_e( 'Import Mode', 'directorist-listing-import' ); ?></label></th>
                            <td>
                                <select name="sync_mode" id="sync_mode">
                                    <option value="one_time" selected><?php esc_html_e( 'Import once', 'directorist-listing-import' ); ?></option>
                                    <option value="sync"><?php esc_html_e( 'Keep this source in sync', 'directorist-listing-import' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th><label for="feed_url"><?php esc_html_e( 'Source URL', 'directorist-listing-import' ); ?></label></th>
                            <td>
                                <input type="url" name="feed_url" id="feed_url" class="large-text"
                                       value="<?php echo esc_attr( $editing_feed['source_url'] ?? ( $editing_feed['url'] ?? '' ) ); ?>"
                                       placeholder="https://newyork.craigslist.org/search/rea" required>
                                <p class="description">
                                    <?php esc_html_e( 'Paste a source page or direct RSS/Atom feed.', 'directorist-listing-import' ); ?>
                                </p>
                            </td>
                        </tr>
                        <?php if ( ! is_wp_error( $directory_types ) && ! empty( $directory_types ) ) : ?>
                        <tr>
                            <th><label for="directory_type"><?php esc_html_e( 'Directory Type', 'directorist-listing-import' ); ?></label></th>
                            <td>
                                <select name="directory_type" id="directory_type">
                                    <option value="0"><?php esc_html_e( '— Default —', 'directorist-listing-import' ); ?></option>
                                    <?php foreach ( $directory_types as $directory_type ) : ?>
                                        <option value="<?php echo esc_attr( $directory_type->term_id ); ?>" <?php selected( absint( $editing_feed['directory_type'] ?? 0 ), $directory_type->term_id ); ?>>
                                            <?php echo esc_html( $directory_type->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th><label for="feed_category"><?php esc_html_e( 'Directorist Category', 'directorist-listing-import' ); ?></label></th>
                            <td>
                                <select name="feed_category" id="feed_category">
                                    <option value="0"><?php esc_html_e( '— Uncategorised —', 'directorist-listing-import' ); ?></option>
                                    <?php if ( ! is_wp_error( $dir_categories ) ) :
                                        foreach ( $dir_categories as $cat ) : ?>
                                            <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( absint( $editing_feed['category'] ?? 0 ), $cat->term_id ); ?>>
                                                <?php echo esc_html( $cat->name ); ?>
                                            </option>
                                        <?php endforeach;
                                    endif; ?>
                                </select>
                            </td>
                        </tr>
                        <tr class="dli-sync-field">
                            <th><label for="feed_name"><?php esc_html_e( 'Source Name', 'directorist-listing-import' ); ?></label></th>
                            <td>
                                <input type="text" name="feed_name" id="feed_name" class="regular-text"
                                       value="<?php echo esc_attr( $editing_feed['name'] ?? '' ); ?>"
                                       placeholder="e.g. NYC Real Estate">
                            </td>
                        </tr>
                        <tr class="dli-sync-field">
                            <th><label for="feed_interval"><?php esc_html_e( 'Sync Interval', 'directorist-listing-import' ); ?></label></th>
                            <td>
                                <select name="feed_interval" id="feed_interval">
                                    <?php $selected_interval = $editing_feed['interval'] ?? 'daily'; ?>
                                    <option value="hourly" <?php selected( $selected_interval, 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'directorist-listing-import' ); ?></option>
                                    <option value="twicedaily" <?php selected( $selected_interval, 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'directorist-listing-import' ); ?></option>
                                    <option value="daily" <?php selected( $selected_interval, 'daily' ); ?>><?php esc_html_e( 'Daily', 'directorist-listing-import' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php echo $editing_feed ? esc_html__( 'Save Source', 'directorist-listing-import' ) : esc_html__( 'Preview Source', 'directorist-listing-import' ); ?>
                        </button>
                        <?php if ( $editing_feed ) : ?>
                            <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => DLI_PAGE_SLUG, 'tab' => 'rss' ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) ); ?>">
                                <?php esc_html_e( 'Cancel', 'directorist-listing-import' ); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </form>
            </details>

            <?php if ( is_array( $preview_results ) ) :
                $quality = $preview_results['quality'] ?? [ 'score' => 0, 'counts' => [], 'total' => 0 ];
                $items = $preview_results['items'] ?? [];
            ?>
                <div class="dli-card dli-preview-card">
                    <h2><?php esc_html_e( 'Source Preview', 'directorist-listing-import' ); ?></h2>
                    <div class="dli-quality-summary">
                        <strong><?php echo esc_html( sprintf( __( 'Quality score: %d/100', 'directorist-listing-import' ), absint( $quality['score'] ?? 0 ) ) ); ?></strong>
                        <span><?php echo esc_html( sprintf( __( '%d sample listings checked', 'directorist-listing-import' ), absint( $quality['total'] ?? 0 ) ) ); ?></span>
                    </div>
                    <table class="widefat fixed striped dli-table dli-preview-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Title', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Address', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Phone', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Email', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Website', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Description', 'directorist-listing-import' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $items as $item ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $item['title'] ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $item['address'] ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $item['phone'] ?: '—' ); ?></td>
                                    <td><?php echo esc_html( $item['email'] ?: '—' ); ?></td>
                                    <td><?php echo $item['website'] ? '<a href="' . esc_url( $item['website'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open', 'directorist-listing-import' ) . '</a>' : '—'; ?></td>
                                    <td><?php echo esc_html( $item['description'] ? wp_trim_words( $item['description'], 10 ) : '—' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="dli_add_feed">
                        <input type="hidden" name="feed_name" value="<?php echo esc_attr( $preview_results['name'] ?? '' ); ?>">
                        <input type="hidden" name="feed_url" value="<?php echo esc_url( $preview_results['source_url'] ?? '' ); ?>">
                        <input type="hidden" name="directory_type" value="<?php echo esc_attr( absint( $preview_results['directory_type'] ?? 0 ) ); ?>">
                        <input type="hidden" name="feed_category" value="<?php echo esc_attr( absint( $preview_results['category'] ?? 0 ) ); ?>">
                        <input type="hidden" name="feed_interval" value="<?php echo esc_attr( $preview_results['interval'] ?? 'daily' ); ?>">
                        <input type="hidden" name="sync_mode" value="<?php echo esc_attr( $preview_results['sync_mode'] ?? 'one_time' ); ?>">
                        <?php wp_nonce_field( 'dli_add_feed' ); ?>
                        <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm Import', 'directorist-listing-import' ); ?></button></p>
                    </form>
                </div>
            <?php endif; ?>

        <?php endif; // feeds tab ?>

        <?php /* ───────────── LOGS TAB ───────────── */ ?>
        <?php if ( $active_tab === 'logs' ) : ?>

            <div class="dli-card">
                <h2>
                    <?php esc_html_e( 'Import History', 'directorist-listing-import' ); ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;float:right">
                        <input type="hidden" name="action" value="dli_clear_logs">
                        <?php wp_nonce_field( 'dli_clear_logs' ); ?>
                        <button type="submit" class="button button-small"
                                onclick="return confirm('Clear all logs?')">
                            <?php esc_html_e( 'Clear Logs', 'directorist-listing-import' ); ?>
                        </button>
                    </form>
                </h2>

                <?php if ( empty( $logs ) ) : ?>
                    <p class="dli-empty"><?php esc_html_e( 'No import logs yet.', 'directorist-listing-import' ); ?></p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped dli-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Time', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Feed', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Imported', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Skipped', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Errors', 'directorist-listing-import' ); ?></th>
                                <th><?php esc_html_e( 'Note', 'directorist-listing-import' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( 'M j, Y H:i', $log['time'] ) ); ?></td>
                                <td><?php echo esc_html( $log['feed_name'] ); ?></td>
                                <td class="dli-num dli-num--green"><?php echo esc_html( $log['imported'] ); ?></td>
                                <td class="dli-num"><?php echo esc_html( $log['skipped'] ); ?></td>
                                <td class="dli-num <?php echo $log['errors'] ? 'dli-num--red' : ''; ?>">
                                    <?php echo esc_html( $log['errors'] ); ?>
                                </td>
                                <td><?php echo esc_html( $log['error_msg'] ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php endif; // logs tab ?>

        <?php /* ───────────── SETTINGS TAB ───────────── */ ?>
        <?php if ( $active_tab === 'settings' ) : ?>

            <div class="dli-card">
                <h2><?php esc_html_e( 'Global Settings', 'directorist-listing-import' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="dli_save_settings">
                    <?php wp_nonce_field( 'dli_save_settings' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="default_status"><?php esc_html_e( 'Default Listing Status', 'directorist-listing-import' ); ?></label></th>
                            <td>
                                <select name="default_status" id="default_status">
                                    <option value="pending" <?php selected( $settings['default_status'] ?? 'pending', 'pending' ); ?>>
                                        <?php esc_html_e( 'Pending Review (Recommended)', 'directorist-listing-import' ); ?>
                                    </option>
                                    <option value="publish" <?php selected( $settings['default_status'] ?? '', 'publish' ); ?>>
                                        <?php esc_html_e( 'Published Immediately', 'directorist-listing-import' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="batch_size"><?php esc_html_e( 'Batch Size per Run', 'directorist-listing-import' ); ?></label></th>
                            <td>
                                <input type="number" name="batch_size" id="batch_size"
                                       value="<?php echo esc_attr( $settings['batch_size'] ?? 25 ); ?>"
                                       min="1" max="100" style="width:80px">
                                <p class="description"><?php esc_html_e( 'Max listings per feed per run.', 'directorist-listing-import' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Save Settings', 'directorist-listing-import' ); ?>
                        </button>
                    </p>
                </form>
            </div>

        <?php endif; // settings tab ?>

    </div><!-- .dli-tab-content -->
</div><!-- .dli-rss-panel -->
