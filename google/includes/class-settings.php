<?php
/**
 * Plugin settings management.
 *
 * Provides a Settings tab under Directorist → Google Importer for:
 *  - API Key (stored encrypted, never passed through URLs)
 *  - Default listing status
 *  - Max results per run
 *
 * @package Directorist_Google_Importer
 */

namespace DLIG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {

	/** Option key for the encrypted API key. */
	const OPT_API_KEY        = 'dgbi_api_key';
	const OPT_DEFAULT_STATUS = 'dgbi_default_status';
	const OPT_MAX_RESULTS    = 'dgbi_max_results';

	/**
	 * Constructor: register settings hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_dlig_save_google_settings',   [ $this, 'handle_save' ] );
		add_action( 'admin_post_dlig_remove_google_api_key',  [ $this, 'handle_remove' ] );
	}

	// ── Getters ──────────────────────────────────────────────────────────────

	/**
	 * Return the stored API key (plain text after decryption).
	 */
	public function get_api_key(): string {
		$raw = get_option( self::OPT_API_KEY, '' );
		return $raw ? $this->decrypt( $raw ) : '';
	}

	public function get_default_status(): string {
		$status = get_option( self::OPT_DEFAULT_STATUS, 'pending' );
		$allowed = [ 'draft', 'pending', 'publish' ];
		return in_array( $status, $allowed, true ) ? $status : 'pending';
	}

	public function get_max_results(): int {
		return max( 1, min( 20, intval( get_option( self::OPT_MAX_RESULTS, 20 ) ) ) );
	}

	// ── WP Settings API registration (used for REST / options sanitization) ──

	public function register_settings(): void {
		register_setting( 'dgbi_settings_group', self::OPT_DEFAULT_STATUS, [
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, [ 'draft', 'pending', 'publish' ], true ) ? $v : 'pending';
			},
		] );
		register_setting( 'dgbi_settings_group', self::OPT_MAX_RESULTS, [
			'sanitize_callback' => function ( $v ) {
				return max( 1, min( 20, absint( $v ) ) );
			},
		] );
	}

	// ── Settings form save handler ────────────────────────────────────────────

	/**
	 * Handles POST from the Settings tab.
	 * Called via admin-post.php action dlig_save_google_settings.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'directorist-listing-import' ) );
		}

		check_admin_referer( 'dlig_save_google_settings_action', 'dgbi_settings_nonce' );

		// API key: only update if the submitted value is not the placeholder mask.
		if ( isset( $_POST['dgbi_api_key'] ) ) {
			$submitted_key = sanitize_text_field( wp_unslash( $_POST['dgbi_api_key'] ) );
			// If the user left the masked placeholder unchanged, skip overwriting.
			if ( $submitted_key !== '••••••••••••••••••••' && '' !== $submitted_key ) {
				update_option( self::OPT_API_KEY, $this->encrypt( $submitted_key ), 'no' );
			} elseif ( '' === $submitted_key ) {
				update_option( self::OPT_API_KEY, '', 'no' );
			}
		}

		$status = isset( $_POST['dgbi_default_status'] ) ? sanitize_text_field( wp_unslash( $_POST['dgbi_default_status'] ) ) : 'pending';
		if ( ! in_array( $status, [ 'draft', 'pending', 'publish' ], true ) ) {
			$status = 'pending';
		}
		update_option( self::OPT_DEFAULT_STATUS, $status, 'no' );

		$max = max( 1, min( 20, absint( $_POST['dgbi_max_results'] ?? 20 ) ) );
		update_option( self::OPT_MAX_RESULTS, $max, 'no' );

		wp_safe_redirect( add_query_arg(
			[ 'page' => DLI_PAGE_SLUG, 'tab' => 'google', 'google_tab' => 'settings', 'dgbi_settings_saved' => '1' ],
			admin_url( 'edit.php?post_type=at_biz_dir' )
		) );
		exit;
	}

	/**
	 * Handle the Remove API Key form submission.
	 */
	public function handle_remove(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'directorist-listing-import' ) );
		}

		check_admin_referer( 'dlig_remove_google_api_key_action', 'dgbi_remove_nonce' );

		update_option( self::OPT_API_KEY, '', 'no' );

		wp_safe_redirect( add_query_arg(
			[ 'page' => DLI_PAGE_SLUG, 'tab' => 'google', 'google_tab' => 'settings', 'dgbi_key_removed' => '1' ],
			admin_url( 'edit.php?post_type=at_biz_dir' )
		) );
		exit;
	}

	// ── Settings page UI ─────────────────────────────────────────────────────

	/**
	 * Render the settings tab content.
	 */
	public function render_tab(): void {
		$api_key_stored = '' !== get_option( self::OPT_API_KEY, '' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dlig_save_google_settings">
			<?php wp_nonce_field( 'dlig_save_google_settings_action', 'dgbi_settings_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<td colspan="2">
						<p class="description">
							<?php esc_html_e( 'These defaults are preselected on the Import tab and can be changed before each import.', 'directorist-listing-import' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dgbi_api_key"><?php esc_html_e( 'Google Places API Key', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="dgbi_api_key"
							name="dgbi_api_key"
							class="regular-text"
							autocomplete="new-password"
							value="<?php echo $api_key_stored ? esc_attr( '••••••••••••••••••••' ) : ''; ?>"
							placeholder="<?php esc_attr_e( 'Enter your Google Places API key', 'directorist-listing-import' ); ?>"
						>
						<?php if ( $api_key_stored ) : ?>
							<p class="description dli-key-connected">
								<span aria-hidden="true">✓</span>
								<?php esc_html_e( 'Key connected. Enter a new key to replace it.', 'directorist-listing-import' ); ?>
							</p>
							<button
								type="submit"
								form="dgbi-remove-api-key-form"
								class="button button-link-delete dgbi-remove-key-button"
								onclick="return confirm('<?php echo esc_js( __( 'Remove the stored API key? Imports will stop working until a new key is added.', 'directorist-listing-import' ) ); ?>')"
							><?php esc_html_e( 'Remove API Key', 'directorist-listing-import' ); ?></button>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: Google Cloud Console URL */
									esc_html__( 'Get a key from %s and enable Places API.', 'directorist-listing-import' ),
									'<a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">Google Cloud Console</a>'
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dgbi_default_status"><?php esc_html_e( 'Default Listing Status', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<select id="dgbi_default_status" name="dgbi_default_status">
							<?php
							$current = $this->get_default_status();
							foreach ( [ 'draft' => __( 'Draft', 'directorist-listing-import' ), 'pending' => __( 'Pending Review', 'directorist-listing-import' ), 'publish' => __( 'Published', 'directorist-listing-import' ) ] as $val => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $val ),
									selected( $current, $val, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dgbi_max_results"><?php esc_html_e( 'Max Results per Import', 'directorist-listing-import' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="dgbi_max_results"
							name="dgbi_max_results"
							class="small-text"
							min="1"
							max="20"
							value="<?php echo esc_attr( $this->get_max_results() ); ?>"
						>
					</td>
				</tr>

			</table>

			<?php submit_button( __( 'Save Settings', 'directorist-listing-import' ) ); ?>
		</form>
		<?php if ( $api_key_stored ) : ?>
			<form id="dgbi-remove-api-key-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dlig_remove_google_api_key">
				<?php wp_nonce_field( 'dlig_remove_google_api_key_action', 'dgbi_remove_nonce' ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	// ── Cryptographic encryption for API keys ──────────────────────────────
	// Uses AES-256-GCM with authenticated encryption (AEAD cipher).
	// Key derived using PBKDF2 with WordPress salts.

	/**
	 * Encrypt API key using AES-256-GCM with authentication.
	 * Uses dedicated encryption salt for key derivation.
	 *
	 * @param string $plain The plain-text API key.
	 * @return string Base64-encoded ciphertext with IV and tag.
	 */
	private function encrypt( string $plain ): string {
		// Require OpenSSL for encryption
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			// Log error instead of silently failing
			error_log( 'DGBI: OpenSSL required for encryption - API key not stored' );
			return '';
		}

		// Generate random IV
		$iv = openssl_random_pseudo_bytes( 16 );
		
		// Get encryption key using proper PBKDF2 derivation
		$key = $this->get_cipher_key();
		
		// Use GCM mode for authenticated encryption
		$tag = '';
		$ciphertext = openssl_encrypt( 
			$plain, 
			'aes-256-gcm', 
			$key, 
			OPENSSL_RAW_DATA,  // Don't base64 encode automatically
			$iv, 
			$tag 
		);
		
		if ( false === $ciphertext || '' === $tag ) {
			error_log( 'DGBI: Encryption failed' );
			return '';
		}

		// Return: IV + tag + ciphertext, all base64-encoded
		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt API key.
	 *
	 * @param string $stored The encrypted/stored value.
	 * @return string Plain-text API key, or empty string on failure.
	 */
	private function decrypt( string $stored ): string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			error_log( 'DGBI: OpenSSL required for decryption' );
			return '';
		}

		// Decode from base64
		$decoded = base64_decode( $stored, true );
		if ( false === $decoded || strlen( $decoded ) < 32 ) {
			// Too short for IV + tag
			error_log( 'DGBI: Invalid encrypted data format' );
			return '';
		}

		// Extract components: 16-byte IV + 16-byte tag + remaining ciphertext
		$iv = substr( $decoded, 0, 16 );
		$tag = substr( $decoded, 16, 16 );
		$ciphertext = substr( $decoded, 32 );

		// Get decryption key
		$key = $this->get_cipher_key();

		// Decrypt with authentication tag verification
		$plain = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plain ) {
			error_log( 'DGBI: Decryption failed - possible tampering detected' );
			return '';
		}

		return $plain;
	}

	/**
	 * Derive encryption key using PBKDF2.
	 * Uses WordPress salts for key material.
	 *
	 * @return string 32-byte binary encryption key.
	 */
	private function get_cipher_key(): string {
		// Use dedicated encryption salt if defined, otherwise use WordPress salts
		$input = defined( 'DGBI_ENCRYPTION_SALT' ) 
			? DGBI_ENCRYPTION_SALT 
			: wp_salt( 'secure_auth' );

		// PBKDF2 with 100,000 iterations for proper key derivation
		$key = hash_pbkdf2(
			'sha256',
			$input,
			wp_salt( 'auth' ),  // Use auth salt as additional entropy
			100000,             // iterations
			32,                 // 256 bits
			true                // binary output
		);

		return $key;
	}
}
