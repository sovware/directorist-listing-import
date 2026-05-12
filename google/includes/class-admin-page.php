<?php
/**
 * Admin page UI with tabbed interface.
 *
 * Tabs: Import | History | Settings
 *
 * Implements all audit UX fixes:
 *  - API key removed from the import form (lives in Settings tab) (audit §3.1)
 *  - Category dropdown instead of raw ID input (audit §2.2 / §8.1)
 *  - Listing status selector (audit §8.1)
 *  - Directory type selector for multi-directory support (audit §5.4)
 *  - Max results field on the form (audit §2.2)
 *  - Errors stored in transient, not URL params (audit §3.5)
 *  - Capability check on clear-history independent of nonce (audit §3.4)
 *  - Correct text domain everywhere (audit §4.2)
 *  - Inline styles replaced by CSS class (audit §4.6)
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Page
 */
class Admin_Page {

	/** @var Settings */
	private $settings;

	/** @var Importer */
	private $importer;

	/** @var Field_Mapping */
	private $field_mapping;

	/** Slug used for this admin page. */
	const PAGE_SLUG = 'directorist-google-import';

	/**
	 * Constructor — registers all hooks.
	 *
	 * @param Settings      $settings
	 * @param Importer      $importer
	 * @param Field_Mapping $field_mapping
	 */
	public function __construct( Settings $settings, Importer $importer, Field_Mapping $field_mapping ) {
		$this->settings      = $settings;
		$this->importer      = $importer;
		$this->field_mapping = $field_mapping;

		add_action( 'admin_menu',    [ $this, 'register_menu' ] );
		add_action( 'admin_init',    [ $this, 'handle_form_submissions' ] );
		add_action( 'admin_notices', [ $this, 'show_notices' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// ── Menu registration ─────────────────────────────────────────────────────

	public function register_menu(): void {
		// The bundled Google importer is rendered inside Listing Importer.
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * Enqueue CSS/JS only on this plugin's admin page. (audit §6.3)
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on the combined Listing Importer page.
		if ( strpos( $hook, DLI_PAGE_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style(
			'dgbi-admin',
			DLIG_URL . 'assets/css/admin.css',
			[],
			DLIG_VERSION
		);

		wp_enqueue_script(
			'dgbi-admin',
			DLIG_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			DLIG_VERSION,
			true
		);

		wp_localize_script( 'dgbi-admin', 'dgbiAjax', [
			// Protocol-relative URL: strips http:/https: so the browser uses whatever
			// protocol the admin page is on. Prevents mixed-content blocks on HTTPS sites
			// where WP_SITEURL is still stored as http:// in the database.
			'ajaxUrl' => preg_replace( '#^https?:#', '', admin_url( 'admin-ajax.php' ) ),
			'nonce'   => wp_create_nonce( 'dlig_ajax' ),
			'i18n'    => [
				'keyword_required'  => __( 'Please enter a keyword before importing.', 'directorist-listing-import' ),
				'searching'         => __( 'Searching Google Places…', 'directorist-listing-import' ),
				/* translators: %d: number of places found */
				'preview_heading'   => __( 'Found %d place(s). Select which to import:', 'directorist-listing-import' ),
				'select_all'        => __( 'Select All', 'directorist-listing-import' ),
				'deselect_all'      => __( 'Deselect All', 'directorist-listing-import' ),
				/* translators: %d: number of selected places */
				'import_selected'   => __( 'Import %d Selected', 'directorist-listing-import' ),
				'none_selected'     => __( 'Please select at least one listing to import.', 'directorist-listing-import' ),
				'already_imported'  => __( 'Already imported', 'directorist-listing-import' ),
				/* translators: 1: current index, 2: total, 3: place name */
				'importing'         => __( 'Importing %1$d of %2$d: %3$s', 'directorist-listing-import' ),
				'finishing'         => __( 'Finishing up…', 'directorist-listing-import' ),
				'search_failed'     => __( 'Search failed. Please check your API key and try again.', 'directorist-listing-import' ),
				'ajax_error'        => __( 'A network error occurred. Please try again.', 'directorist-listing-import' ),
					/* translators: 1: imported count, 2: skipped count */
					'done'              => __( '%1$d listing(s) imported, %2$d skipped.', 'directorist-listing-import' ),
					/* translators: %d: updated count */
					'updated'           => __( '%d listing(s) refreshed with new data from Google.', 'directorist-listing-import' ),
					'no_results'        => __( 'No places found for that keyword and location.', 'directorist-listing-import' ),
					/* translators: %d: review count */
					'reviews'           => __( '%d Google review(s) imported as Directorist reviews.', 'directorist-listing-import' ),
					/* translators: %d: description count */
					'descriptions'      => __( '%d listing(s) received Google editorial summary data.', 'directorist-listing-import' ),
					'errors_heading'    => __( 'Some listings could not be imported:', 'directorist-listing-import' ),
					'confirm_navigate'  => __( 'An import is in progress. Leaving this page will stop it. Are you sure?', 'directorist-listing-import' ),
				],
			] );
		}

	// ── Page render ───────────────────────────────────────────────────────────

	public function render_page(): void {
		$this->render_embedded_page();
	}

	public function render_embedded_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'directorist-listing-import' ) );
		}

			$active_tab = isset( $_GET['google_tab'] ) ? sanitize_key( $_GET['google_tab'] ) : 'import'; // phpcs:ignore WordPress.Security.NonceVerification
			$tabs = [
				'import'   => __( 'Import', 'directorist-listing-import' ),
				'mapping'  => __( 'Field Mapping', 'directorist-listing-import' ),
				'history'  => __( 'Import History', 'directorist-listing-import' ),
				'settings' => __( 'Settings', 'directorist-listing-import' ),
			];
		?>
		<div class="dgbi-wrap">
			<nav class="nav-tab-wrapper dgbi-tabs dli-secondary-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( [ 'page' => DLI_PAGE_SLUG, 'tab' => 'google', 'google_tab' => $slug ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) ); ?>"
						class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="dgbi-tab-content">
					<?php
					if ( 'import' === $active_tab ) {
						$this->render_import_tab();
					} elseif ( 'mapping' === $active_tab ) {
						$this->render_mapping_tab();
					} elseif ( 'history' === $active_tab ) {
						$this->render_history_tab();
					} elseif ( 'settings' === $active_tab ) {
						$this->settings->render_tab();
					}
					?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the field mapping tab.
	 */
	private function render_mapping_tab(): void {
		$directory_id = $this->field_mapping->get_mapping_screen_directory_id();
		$types        = $this->field_mapping->get_directory_types();
		$sources      = $this->field_mapping->get_source_fields();
		$mapping      = $this->field_mapping->get_effective_mapping( $directory_id );
		?>
		<h2><?php esc_html_e( 'Field Mapping', 'directorist-listing-import' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Map Google Places data into the selected Directorist directory type. Custom fields are loaded directly from that directory type’s submission form.', 'directorist-listing-import' ); ?>
		</p>

		<?php if ( ! empty( $types ) ) : ?>
			<form method="get" class="dgbi-inline-form">
				<input type="hidden" name="post_type" value="at_biz_dir">
				<input type="hidden" name="page" value="<?php echo esc_attr( DLI_PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="google">
				<input type="hidden" name="google_tab" value="mapping">
				<label for="dgbi_mapping_directory"><strong><?php esc_html_e( 'Directory Type', 'directorist-listing-import' ); ?></strong></label>
				<select id="dgbi_mapping_directory" name="dgbi_mapping_directory" onchange="this.form.submit()">
					<?php foreach ( $types as $type_id => $label ) : ?>
						<option value="<?php echo esc_attr( $type_id ); ?>" <?php selected( $directory_id, $type_id ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dlig_save_google_field_mapping">
			<input type="hidden" name="dgbi_mapping_directory" value="<?php echo esc_attr( $directory_id ); ?>">
			<?php wp_nonce_field( 'dlig_save_google_field_mapping_action', 'dgbi_field_mapping_nonce' ); ?>

			<table class="widefat striped dgbi-field-map-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Google Field', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Description', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Directorist Destination', 'directorist-listing-import' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sources as $source_key => $source ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $source['label'] ); ?></strong>
								<div><code><?php echo esc_html( $source_key ); ?></code></div>
							</td>
							<td><?php echo esc_html( $source['description'] ); ?></td>
							<td>
								<?php $destinations = $this->field_mapping->get_available_destinations( $directory_id, $source_key ); ?>
								<select name="dgbi_field_map[<?php echo esc_attr( $source_key ); ?>]" class="dgbi-field-map-select">
									<?php foreach ( $destinations as $group_label => $group_options ) : ?>
										<optgroup label="<?php echo esc_attr( $group_label ); ?>">
											<?php foreach ( $group_options as $destination_id => $label ) : ?>
												<option value="<?php echo esc_attr( $destination_id ); ?>" <?php selected( $mapping[ $source_key ] ?? 'skip', $destination_id ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description dgbi-note">
				<?php esc_html_e( 'Each Directorist destination can only be assigned once. Structured Google fields like business type, status, service-area flags, and multi-type values now only show compatible Directorist field types. Required Google system data like place ID, selected directory type, and listing expiry defaults still continue automatically.', 'directorist-listing-import' ); ?>
			</p>

			<?php submit_button( __( 'Save Mapping', 'directorist-listing-import' ) ); ?>
			<?php
			submit_button(
				__( 'Reset to Default Mapping', 'directorist-listing-import' ),
				'secondary',
				'dgbi_reset_field_map',
				false,
				[
					'onclick' => 'return confirm("' . esc_js( __( 'Reset this directory type to the default Google field mapping?', 'directorist-listing-import' ) ) . '");',
				]
			);
			?>
		</form>
		<?php
	}

	// ── Import tab ────────────────────────────────────────────────────────────

	private function render_import_tab(): void {
		$api_key_configured = '' !== $this->settings->get_api_key();
		if ( ! $api_key_configured ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: Settings tab URL */
						esc_html__( 'No Google API key configured. Please add your key in the %s tab before importing.', 'directorist-listing-import' ),
						'<a href="' . esc_url( add_query_arg( [ 'page' => DLI_PAGE_SLUG, 'tab' => 'google', 'google_tab' => 'settings' ], admin_url( 'edit.php?post_type=at_biz_dir' ) ) ) . '">'
						. esc_html__( 'Settings', 'directorist-listing-import' )
						. '</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" id="dgbi-import-form">
			<input type="hidden" name="dgbi_action" value="import">
			<?php wp_nonce_field( 'dgbi_import_action', 'dgbi_nonce' ); ?>

			<table class="form-table" role="presentation">

				<tr>
					<th scope="row">
						<label for="dgbi_keyword"><?php esc_html_e( 'Keyword', 'directorist-listing-import' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input
							type="text"
							id="dgbi_keyword"
							name="keyword"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'e.g. Restaurant, Dentist, Hotel', 'directorist-listing-import' ); ?>"
							required
						>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dgbi_location"><?php esc_html_e( 'Location', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="dgbi_location"
							name="location"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'e.g. Dhaka, New York, London', 'directorist-listing-import' ); ?>"
						>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dgbi_radius"><?php esc_html_e( 'Radius (metres)', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<input type="number" id="dgbi_radius" name="radius" class="small-text" min="0" max="50000" value="5000">
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dgbi_category_id"><?php esc_html_e( 'Directorist Category', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<?php
						// FIXED: Category dropdown instead of raw numeric input (audit §2.2)
						$categories = get_terms( [
							'taxonomy'   => 'at_biz_dir-category',
							'hide_empty' => false,
						] );
						if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) :
							?>
							<select id="dgbi_category_id" name="category_id">
								<option value="0"><?php esc_html_e( '— None —', 'directorist-listing-import' ); ?></option>
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->term_id ); ?>">
										<?php echo esc_html( $cat->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<p class="description">
								<?php esc_html_e( 'No categories found. Create categories in Directorist first.', 'directorist-listing-import' ); ?>
								<input type="hidden" name="category_id" value="0">
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<?php
				// Location term selector — assigns the at_biz_dir-location taxonomy term.
				$locations = get_terms( [
					'taxonomy'   => 'at_biz_dir-location',
					'hide_empty' => false,
				] );
				if ( ! empty( $locations ) && ! is_wp_error( $locations ) ) : ?>
				<tr>
					<th scope="row">
						<label for="dgbi_location_id"><?php esc_html_e( 'Directorist Location', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<select id="dgbi_location_id" name="location_id">
							<option value="0"><?php esc_html_e( '— None —', 'directorist-listing-import' ); ?></option>
							<?php foreach ( $locations as $loc ) : ?>
								<option value="<?php echo esc_attr( $loc->term_id ); ?>">
									<?php echo esc_html( $loc->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php endif; ?>

				<?php
				// Directory type selector for multi-directory support (audit §5.4)
				$directory_types = $this->get_directory_types();
				if ( ! empty( $directory_types ) ) :
					?>
				<tr>
					<th scope="row">
						<label for="dgbi_directory_type"><?php esc_html_e( 'Directory Type', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<select id="dgbi_directory_type" name="directory_type">
							<option value=""><?php esc_html_e( '— Default —', 'directorist-listing-import' ); ?></option>
							<?php foreach ( $directory_types as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>">
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php endif; ?>

				<tr>
					<th scope="row">
						<label for="dgbi_post_status"><?php esc_html_e( 'Listing Status', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<select id="dgbi_post_status" name="post_status">
							<?php
							$default_status = $this->settings->get_default_status();
							foreach ( [ 'draft' => __( 'Draft', 'directorist-listing-import' ), 'pending' => __( 'Pending Review', 'directorist-listing-import' ), 'publish' => __( 'Published', 'directorist-listing-import' ) ] as $val => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $val ),
									selected( $default_status, $val, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dgbi_max_results"><?php esc_html_e( 'Max Listings', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="dgbi_max_results"
							name="max_results"
							class="small-text"
							min="1"
							max="60"
							value="<?php echo esc_attr( $this->settings->get_max_results() ); ?>"
						>
					</td>
				</tr>

				<tr class="dli-form-section-row">
					<th colspan="2">
						<span><?php esc_html_e( 'Options', 'directorist-listing-import' ); ?></span>
					</th>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Import Reviews', 'directorist-listing-import' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="import_reviews"
								value="1"
								<?php checked( $this->settings->get_import_reviews() ); ?>
							>
							<?php esc_html_e( 'Import Google reviews as Directorist review comments', 'directorist-listing-import' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Import Photos', 'directorist-listing-import' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="import_photos" value="1" checked>
							<?php esc_html_e( 'Sideload first Google photo as listing featured image', 'directorist-listing-import' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Update Existing', 'directorist-listing-import' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="update_existing" value="1">
							<?php esc_html_e( 'Refresh listings that were already imported (address, phone, hours, rating)', 'directorist-listing-import' ); ?>
						</label>
					</td>
				</tr>

			</table>

			<?php
			submit_button(
				__( 'Import Businesses', 'directorist-listing-import' ),
				'primary',
				'dgbi_submit',
				true,
				$api_key_configured ? [] : [ 'disabled' => 'disabled' ]
			);
			?>

		</form>

		<div id="dgbi-checklist" hidden></div>

		<div id="dgbi-progress" hidden>
			<div
				class="dgbi-progress-track"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax="100"
				aria-valuenow="0"
			>
				<div class="dgbi-progress-bar-fill"></div>
			</div>
			<p class="dgbi-progress-status"></p>
		</div>

		<div id="dgbi-import-results"></div>
		<?php
	}

	// ── History tab ───────────────────────────────────────────────────────────

	private function render_history_tab(): void {
		$history = get_option( 'dgbi_import_history', [] );
		?>
		<h2><?php esc_html_e( 'Import History', 'directorist-listing-import' ); ?></h2>

		<?php if ( empty( $history ) ) : ?>
			<p><?php esc_html_e( 'No imports have been run yet.', 'directorist-listing-import' ); ?></p>
		<?php else : ?>
			<table class="widefat striped dgbi-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'User', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Keyword', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Location', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Status', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Imported', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Skipped', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Reviews', 'directorist-listing-import' ); ?></th>
						<th><?php esc_html_e( 'Errors', 'directorist-listing-import' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( array_slice( $history, 0, 50 ) as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
						<td>
							<?php
							$user = get_userdata( intval( $entry['user_id'] ?? 0 ) );
							echo esc_html( $user ? $user->user_login : __( 'Unknown', 'directorist-listing-import' ) );
							?>
						</td>
						<td><?php echo esc_html( $entry['keyword'] ?? '' ); ?></td>
						<td><?php echo esc_html( $entry['location'] ?? '' ); ?></td>
						<td><?php echo esc_html( $entry['post_status'] ?? '—' ); ?></td>
						<td><?php echo intval( $entry['imported'] ?? 0 ); ?></td>
						<td><?php echo intval( $entry['skipped'] ?? 0 ); ?></td>
						<td><?php echo intval( $entry['reviews_created'] ?? 0 ); ?>/<?php echo intval( $entry['reviews'] ?? 0 ); ?></td>
						<td>
							<?php
							$errors = $entry['errors'] ?? [];
							if ( ! empty( $errors ) ) {
								echo '<span class="dgbi-errors" title="' . esc_attr( implode( ' | ', $errors ) ) . '">';
								/* translators: %d: number of errors */
								echo esc_html( sprintf( _n( '%d error', '%d errors', count( $errors ), 'directorist-listing-import' ), count( $errors ) ) );
								echo '</span>';
							} else {
								echo '—';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" style="margin-top: 16px;">
				<?php wp_nonce_field( 'dgbi_clear_history_action', 'dgbi_clear_nonce' ); ?>
				<input type="hidden" name="dgbi_clear_history" value="1">
				<?php
				submit_button(
					__( 'Clear All History', 'directorist-listing-import' ),
					'delete',
					'dgbi_clear_btn',
					false,
					[ 'onclick' => 'return confirm("' . esc_js( __( 'Are you sure you want to clear all import history?', 'directorist-listing-import' ) ) . '");' ]
				);
				?>
			</form>
		<?php endif; ?>
		<?php
	}

	// ── Form submission handlers ──────────────────────────────────────────────

	/**
	 * Handles all form POST actions for this page.
	 */
	public function handle_form_submissions(): void {
		// ── Clear history ─────────────────────────────────────────────────────
		if ( ! empty( $_POST['dgbi_clear_history'] ) ) {
			// FIXED: explicit capability check BEFORE nonce (audit §3.4)
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'directorist-listing-import' ) );
			}
			if ( empty( $_POST['dgbi_clear_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['dgbi_clear_nonce'] ), 'dgbi_clear_history_action' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'directorist-listing-import' ) );
			}
			update_option( 'dgbi_import_history', [], 'no' );
			wp_safe_redirect( add_query_arg(
				[ 'page' => DLI_PAGE_SLUG, 'tab' => 'google', 'google_tab' => 'history', 'dgbi_cleared' => '1' ],
				admin_url( 'edit.php?post_type=at_biz_dir' )
			) );
			exit;
		}

	}

	// ── Admin notices ─────────────────────────────────────────────────────────

	/**
	 * Display admin notices for import results / errors. (audit §3.5 - errors from transient)
	 */
	public function show_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['page'] ) || DLI_PAGE_SLUG !== sanitize_key( $_GET['page'] ) ) {
			return;
		}

		if ( isset( $_GET['tab'] ) && 'google' !== sanitize_key( $_GET['tab'] ) ) {
			return;
		}

			// Settings saved
			if ( ! empty( $_GET['dgbi_settings_saved'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'directorist-listing-import' ) . '</p></div>';
			}

			if ( ! empty( $_GET['dgbi_mapping_saved'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Field mapping saved.', 'directorist-listing-import' ) . '</p></div>';
			}

			if ( ! empty( $_GET['dgbi_mapping_reset'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Field mapping reset to defaults.', 'directorist-listing-import' ) . '</p></div>';
			}

			// History cleared
			if ( ! empty( $_GET['dgbi_cleared'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Import history cleared.', 'directorist-listing-import' ) . '</p></div>';
			}

		// Import errors
		if ( ! empty( $_GET['dgbi_error'] ) ) {
			$code    = sanitize_key( $_GET['dgbi_error'] );
			$messages = [
				'missing_keyword'    => __( 'Import failed: keyword is required.', 'directorist-listing-import' ),
				'no_api_key'         => __( 'Import failed: no Google API key configured. Please add your key in the Settings tab.', 'directorist-listing-import' ),
				'rate_limit_exceeded' => __( 'Import failed: you have reached the limit of 100 imports per hour. Please wait before running another import.', 'directorist-listing-import' ),
			];
			$msg = $messages[ $code ] ?? sprintf(
				/* translators: %s: error code */
				__( 'Import failed: %s', 'directorist-listing-import' ),
				esc_html( $code )
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
			return;
		}

		// Import results
		if ( isset( $_GET['dgbi_imported'] ) ) {
			$imported = intval( $_GET['dgbi_imported'] );
			$skipped  = intval( $_GET['dgbi_skipped'] ?? 0 );
			$reviews  = intval( $_GET['dgbi_reviews']  ?? 0 );
			$descs    = intval( $_GET['dgbi_descs']    ?? 0 );

			$msg = sprintf(
				/* translators: 1: imported count 2: skipped count */
				__( '%1$d listing(s) imported, %2$d skipped (already exist).', 'directorist-listing-import' ),
				$imported,
				$skipped
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';

			if ( $reviews > 0 ) {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(
					sprintf(
						/* translators: %d: review count */
						__( '%d Google review(s) imported as Directorist reviews.', 'directorist-listing-import' ),
						$reviews
					)
				) . '</p></div>';
			}

				if ( $descs > 0 ) {
					echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(
						sprintf(
							/* translators: %d: description count */
							__( '%d listing(s) received Google editorial summary data.', 'directorist-listing-import' ),
							$descs
						)
					) . '</p></div>';
			}
		}

		// Errors from transient (audit §3.5)
		if ( ! empty( $_GET['dgbi_had_err'] ) && '1' === $_GET['dgbi_had_err'] ) {
			$errors = get_transient( 'dgbi_last_errors_' . get_current_user_id() );
			if ( ! empty( $errors ) && is_array( $errors ) ) {
				echo '<div class="notice notice-warning is-dismissible"><p>'
					. esc_html__( 'Some listings could not be imported:', 'directorist-listing-import' )
					. '</p><ul>';
				foreach ( $errors as $e ) {
					echo '<li>' . esc_html( $e ) . '</li>';
				}
				echo '</ul></div>';
				delete_transient( 'dgbi_last_errors_' . get_current_user_id() );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Retrieve available Directorist directory types for the dropdown.
	 * Returns an empty array when only one (the default) type exists.
	 *
	 * Directorist tracks directory types as terms of the 'atbdp_listing_types'
	 * taxonomy (constant ATBDP_DIRECTORY_TYPE). The term_id is what must be
	 * stored in _directory_type post meta and passed to directorist_set_listing_directory().
	 *
	 * @return array [ term_id => label ]
	 */
	private function get_directory_types(): array {
		$taxonomy = defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types';
		$terms    = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		// Only show the picker when there are multiple types.
		if ( count( $terms ) <= 1 ) {
			return [];
		}

		$types = [];
		foreach ( $terms as $term ) {
			$types[ $term->term_id ] = $term->name;
		}
		return $types;
	}

}
