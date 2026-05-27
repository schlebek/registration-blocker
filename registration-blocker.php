<?php
/**
 * Plugin Name:       Registration Blocker
 * Plugin URI:        https://chlebek.me
 * Description:       Disables user registration site-wide — WordPress core, WooCommerce, BuddyPress, Ultimate Member, REST API and more. Logs blocked attempts.
 * Version:           1.3.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Tested up to:      6.7
 * Author:            Chlebek
 * Author URI:        https://chlebek.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       registration-blocker
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RB_VERSION', '1.3.0' );
define( 'RB_FILE', __FILE__ );

final class Registration_Blocker {

	private static ?self $instance = null;

	private const LOG_OPTION = 'rb_blocked_log';
	private const LOG_MAX    = 100;

	private string $message;
	private string $redirect_url;

	/**
	 * Centralna lista integracji — klucz, etykieta, główny plik wtyczki.
	 * plugin = null → blokada bezwarunkowa (niezależna od tego, czy wtyczka jest zainstalowana).
	 */
	private const INTEGRATIONS = [
		'core'   => [ 'label' => 'WordPress Core',         'plugin' => null ],
		'rest'   => [ 'label' => 'REST API',                'plugin' => null ],
		'xmlrpc' => [ 'label' => 'XML-RPC',                 'plugin' => null ],
		'woo'    => [ 'label' => 'WooCommerce',             'plugin' => 'woocommerce/woocommerce.php' ],
		'bp'     => [ 'label' => 'BuddyPress / BuddyBoss', 'plugin' => null ],
		'um'     => [ 'label' => 'Ultimate Member',         'plugin' => 'ultimate-member/ultimate-member.php' ],
		'nsl'    => [ 'label' => 'Nextend Social Login',   'plugin' => 'nextend-social-login/nextend-social-login.php' ],
		'pp'     => [ 'label' => 'ProfilePress',            'plugin' => 'wp-user-avatar/wp-user-avatar.php' ],
		'mp'     => [ 'label' => 'MemberPress',             'plugin' => null ],
		'pmpro'  => [ 'label' => 'Paid Memberships Pro',    'plugin' => null ],
		'rcp'    => [ 'label' => 'Restrict Content Pro',    'plugin' => null ],
		'bbp'    => [ 'label' => 'bbPress',                 'plugin' => null ],
		'ur'     => [ 'label' => 'User Registration',       'plugin' => null ],
		'lp'     => [ 'label' => 'ListingPro',              'plugin' => 'listingpro-plugin/listingpro-plugin.php' ],
		'cwpf'   => [ 'label' => 'CubeWP Forms',            'plugin' => 'cubewp-forms/cubewp-forms.php' ],
	];

	// =========================================================================
	// Singleton
	// =========================================================================

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->message      = (string) get_option( 'rb_message', __( 'Rejestracja jest wyłączona.', 'registration-blocker' ) );
		$this->redirect_url = (string) get_option( 'rb_redirect_url', home_url() );

		$this->hook_core();
		$this->hook_woocommerce();
		$this->hook_buddypress();
		$this->hook_ultimate_member();
		$this->hook_nextend_social_login();
		$this->hook_profilepress();
		$this->hook_memberpress();
		$this->hook_pmpro();
		$this->hook_restrict_content_pro();
		$this->hook_bbpress();
		$this->hook_user_registration_plugin();
		$this->hook_listingpro();
		$this->hook_cubewp_forms();
		$this->hook_application_passwords();
		$this->hook_rest_api();
		$this->hook_xmlrpc();
		$this->hook_admin();
	}

	/** Blokada klonowania singletona */
	private function __clone(): void {}

	/** Blokada deserializacji singletona */
	public function __wakeup(): void {
		throw new \RuntimeException( 'Nie można deserializować singletona Registration_Blocker.' );
	}

	// =========================================================================
	// Aktywacja / Dezaktywacja
	// =========================================================================

	public static function activate(): void {
		update_option( 'users_can_register', 0 );

		if ( false === get_option( self::LOG_OPTION ) ) {
			add_option( self::LOG_OPTION, [], '', false );
		}
	}

	public static function deactivate(): void {
		// Celowo nie przywracamy users_can_register — admin decyduje samodzielnie.
	}

	// =========================================================================
	// Logowanie prób
	// =========================================================================

	private function log_attempt( string $method, string $identifier = '' ): void {
		$masked_ip = $this->get_masked_ip();
		$now       = time();

		$log = (array) get_option( self::LOG_OPTION, [] );

		// Deduplicate: ten sam zamaskowany IP w ciągu 60 s → pomiń wpis.
		foreach ( array_slice( $log, 0, 20 ) as $existing ) {
			if ( isset( $existing['ip'], $existing['t'] )
				&& $existing['ip'] === $masked_ip
				&& ( $now - (int) $existing['t'] ) < 60
			) {
				return;
			}
		}

		$entry = [
			't'  => $now,
			'ip' => $masked_ip,
			'm'  => $method,
			'id' => $identifier ? sanitize_text_field( mb_substr( $identifier, 0, 60 ) ) : '',
		];

		array_unshift( $log, $entry );

		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, 0, self::LOG_MAX );
		}

		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Zwraca IP klienta z zamaskowanym ostatnim oktetem (IPv4) lub ostatnimi dwoma
	 * grupami (IPv6). Priorytet: REMOTE_ADDR (wiarygodne) → nagłówki proxy.
	 */
	private function get_masked_ip(): string {
		$candidates = [
			$_SERVER['REMOTE_ADDR']          ?? '',
			$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
			$_SERVER['HTTP_CLIENT_IP']       ?? '',
		];

		$ip = '';
		foreach ( $candidates as $candidate ) {
			$candidate = trim( explode( ',', $candidate )[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
				break;
			}
		}

		if ( ! $ip ) {
			return 'unknown';
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '***';
			return implode( '.', $parts );
		}

		// IPv6 — maskuj ostatnie 2 grupy
		$parts = explode( ':', $ip );
		$n     = count( $parts );
		if ( $n >= 2 ) {
			$parts[ $n - 1 ] = '****';
			$parts[ $n - 2 ] = '****';
		}
		return implode( ':', $parts );
	}

	// =========================================================================
	// WordPress Core
	// =========================================================================

	private function hook_core(): void {
		// Wymuszenie wyłączenia rejestracji bez zapisu do bazy
		add_filter( 'option_users_can_register', '__return_zero' );
		add_filter( 'pre_option_users_can_register', '__return_zero' );

		add_filter( 'registration_errors', [ $this, 'core_registration_error' ], 99, 3 );
		add_action( 'register_post', [ $this, 'core_prevent_register_post' ], 1, 3 );
		add_action( 'login_form_register', [ $this, 'redirect_away' ] );
		add_action( 'login_init', [ $this, 'block_login_register_action' ] );
		add_action( 'template_redirect', [ $this, 'redirect_registration_pages' ] );

		// Blokada niskopoziomowa — przechwytuje bezpośrednie wywołania wp_insert_user()
		// (np. z WooCommerce, wtyczek formularzy, REST API custom endpoints).
		// Filtr wp_pre_insert_user_data może zwrócić WP_Error i przerwać insert przed
		// jakimkolwiek zapisem do bazy. Dostępny od WP 5.8 (plugin wymaga 5.9).
		add_filter( 'wp_pre_insert_user_data', [ $this, 'block_direct_user_insert' ], 1, 4 );
	}

	public function core_registration_error( \WP_Error $errors, string $sanitized_user_login, string $user_email ): \WP_Error {
		$this->log_attempt( 'WP Core', $user_email );
		$errors->add(
			'registration_blocked',
			'<strong>' . esc_html__( 'Błąd:', 'registration-blocker' ) . '</strong> ' . esc_html( $this->message )
		);
		return $errors;
	}

	public function core_prevent_register_post( string $sanitized_user_login, string $user_email, \WP_Error $errors ): void {
		if ( ! $errors->get_error_code( 'registration_blocked' ) ) {
			$errors->add( 'registration_blocked', esc_html( $this->message ) );
		}
	}

	public function redirect_away(): void {
		wp_safe_redirect( $this->redirect_url, 302 );
		exit;
	}

	public function block_login_register_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_REQUEST['action'] ) && 'register' === sanitize_key( $_REQUEST['action'] ) ) {
			$this->log_attempt( 'WP login page' );
			wp_safe_redirect( $this->redirect_url, 302 );
			exit;
		}
	}

	/**
	 * Przekierowuje żądania do stron rejestracji na podstawie segmentów URL.
	 * Używa dokładnego dopasowania segmentu ścieżki (nie str_contains), żeby
	 * uniknąć fałszywych blokad np. /produkt/car-registration.
	 */
	public function redirect_registration_pages(): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$raw_path     = parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ?? '';
		$path_segments = array_filter( explode( '/', strtolower( trim( $raw_path, '/' ) ) ) );

		$registration_slugs = [ 'register', 'registration', 'sign-up', 'signup', 'rejestracja' ];

		foreach ( $path_segments as $segment ) {
			if ( in_array( $segment, $registration_slugs, true ) ) {
				$this->log_attempt( 'URL /' . $segment );
				wp_safe_redirect( $this->redirect_url, 302 );
				exit;
			}
		}

		if ( is_page() ) {
			$page = get_queried_object();
			if ( $page instanceof \WP_Post && in_array( $page->post_name, $registration_slugs, true ) ) {
				$this->log_attempt( 'WP page slug: ' . $page->post_name );
				wp_safe_redirect( $this->redirect_url, 302 );
				exit;
			}
		}
	}

	/**
	 * Blokada niskopoziomowa: przechwytuje każde bezpośrednie wp_insert_user().
	 * Uruchamiana PRZED faktycznym insertem — WP_Error przerywa zapis.
	 *
	 * @param array|WP_Error $data     Dane użytkownika lub istniejący WP_Error.
	 * @param bool           $update   true = aktualizacja istniejącego użytkownika.
	 * @param int|null       $user_id  ID dla aktualizacji, null dla nowych.
	 * @param array          $userdata Oryginalne dane przekazane do wp_insert_user().
	 */
	public function block_direct_user_insert( $data, bool $update, ?int $user_id, array $userdata ) {
		// Nie blokuj aktualizacji istniejących użytkowników.
		if ( $update ) {
			return $data;
		}

		// Pozwól adminowi tworzyć konta ręcznie z panelu.
		if ( current_user_can( 'create_users' ) ) {
			return $data;
		}

		// Pozwól WP-CLI (np. migracje, import).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $data;
		}

		$identifier = isset( $userdata['user_email'] ) ? (string) $userdata['user_email'] : '';
		$this->log_attempt( $this->detect_registration_source(), $identifier );

		return new \WP_Error( 'registration_blocked', $this->message );
	}

	// =========================================================================
	// WooCommerce
	// =========================================================================

	private function hook_woocommerce(): void {
		add_filter( 'woocommerce_registration_enabled', '__return_false' );
		add_filter( 'woocommerce_checkout_registration_enabled', '__return_false' );
		add_filter( 'woocommerce_checkout_registration_required', '__return_false' );

		// Wymuszenie opcji WooCommerce w bazie (bez zapisu)
		add_filter( 'pre_option_woocommerce_enable_myaccount_registration', '__return_empty_string' );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', '__return_empty_string' );

		add_action( 'woocommerce_register_post', [ $this, 'woo_block_register_post' ], 1, 3 );
		add_filter( 'woocommerce_process_registration_errors', [ $this, 'woo_process_registration_error' ] );
	}

	public function woo_block_register_post( string $username, string $email, \WP_Error $errors ): void {
		$this->log_attempt( 'WooCommerce', $email );
		$errors->add( 'registration_blocked', esc_html( $this->message ) );
	}

	public function woo_process_registration_error( \WP_Error $errors ): \WP_Error {
		if ( ! $errors->get_error_code( 'registration_blocked' ) ) {
			$errors->add( 'registration_blocked', esc_html( $this->message ) );
		}
		return $errors;
	}

	// =========================================================================
	// BuddyPress / BuddyBoss
	// =========================================================================

	private function hook_buddypress(): void {
		add_filter( 'bp_core_signup_is_enabled', '__return_false' );
		add_filter( 'bp_registration_enabled', '__return_false' );
		add_filter( 'bp_get_signup_allowed', '__return_false' );
		add_action( 'bp_core_signup_user', [ $this, 'bp_block_signup' ], 1 );
		add_action( 'bp_screens', [ $this, 'bp_redirect_registration' ] );
	}

	public function bp_block_signup(): void {
		$this->log_attempt( 'BuddyPress' );
		wp_safe_redirect( $this->redirect_url, 302 );
		exit;
	}

	public function bp_redirect_registration(): void {
		if ( function_exists( 'bp_is_register_page' ) && bp_is_register_page() ) {
			$this->log_attempt( 'BuddyPress page' );
			wp_safe_redirect( $this->redirect_url, 302 );
			exit;
		}
	}

	// =========================================================================
	// Ultimate Member
	// =========================================================================

	private function hook_ultimate_member(): void {
		add_filter( 'um_is_register_page', '__return_false' );
		add_filter( 'um_registration_is_restricted', '__return_true' );
		add_action( 'um_submit_form_register', [ $this, 'um_block_registration' ], 1 );
		add_action( 'um_before_register_form_show', [ $this, 'um_redirect_registration' ] );
	}

	public function um_block_registration( array $args ): void {
		$this->log_attempt( 'Ultimate Member' );
		wp_die(
			esc_html( $this->message ),
			esc_html__( 'Zablokowano', 'registration-blocker' ),
			[ 'response' => 403, 'back_link' => true ]
		);
	}

	public function um_redirect_registration(): void {
		$this->log_attempt( 'Ultimate Member page' );
		wp_safe_redirect( $this->redirect_url, 302 );
		exit;
	}

	// =========================================================================
	// Nextend Social Login
	// =========================================================================

	private function hook_nextend_social_login(): void {
		// Wyłącza rejestrację przez social login na poziomie ustawień NSL.
		add_filter( 'option_nextend-social-login_registration', '__return_empty_string' );
		add_filter( 'pre_option_nextend-social-login_registration', '__return_empty_string' );

		// Przechwytuje próbę tworzenia nowego użytkownika przez NSL (przed zapisem do bazy).
		add_action( 'nextend_social_login_before_register_user', [ $this, 'nsl_block_registration' ], 1 );
	}

	public function nsl_block_registration(): void {
		$this->log_attempt( 'Nextend Social Login' );
		wp_die(
			esc_html( $this->message ),
			esc_html__( 'Zablokowano', 'registration-blocker' ),
			[ 'response' => 403, 'back_link' => true ]
		);
	}

	// =========================================================================
	// ProfilePress
	// =========================================================================

	private function hook_profilepress(): void {
		add_filter( 'ppress_registration_enabled', '__return_false' );
		add_filter( 'ppress_is_registration_enabled', '__return_false' );
		add_action( 'ppress_before_registration_submit', [ $this, 'pp_block_registration' ], 1 );
	}

	public function pp_block_registration(): void {
		$this->log_attempt( 'ProfilePress' );
		wp_die(
			esc_html( $this->message ),
			esc_html__( 'Zablokowano', 'registration-blocker' ),
			[ 'response' => 403, 'back_link' => true ]
		);
	}

	// =========================================================================
	// MemberPress
	// =========================================================================

	private function hook_memberpress(): void {
		add_filter( 'mepr_allow_new_user_registration', '__return_false' );
		add_filter( 'mepr-check-registers', '__return_false' );
	}

	// =========================================================================
	// Paid Memberships Pro
	// =========================================================================

	private function hook_pmpro(): void {
		// Blokujemy tylko rejestrację gości — zalogowani użytkownicy mogą zmieniać plany
		add_filter( 'pmpro_registration_checks', [ $this, 'pmpro_block_guest_registration' ] );
	}

	public function pmpro_block_guest_registration( bool $continue ): bool {
		if ( ! is_user_logged_in() ) {
			$this->log_attempt( 'Paid Memberships Pro' );
			return false;
		}
		return $continue;
	}

	// =========================================================================
	// Restrict Content Pro
	// =========================================================================

	private function hook_restrict_content_pro(): void {
		add_filter( 'rcp_registration_is_open', '__return_false' );
	}

	// =========================================================================
	// bbPress
	// =========================================================================

	private function hook_bbpress(): void {
		add_filter( 'bbp_current_user_can_register', '__return_false' );
		add_filter( 'bbp_allow_anonymous', '__return_false' );
	}

	// =========================================================================
	// User Registration (WPEverest)
	// =========================================================================

	private function hook_user_registration_plugin(): void {
		add_filter( 'user_registration_form_is_enabled', '__return_false' );
		add_action( 'user_registration_before_register_user_action', [ $this, 'ur_block_registration' ] );
	}

	public function ur_block_registration(): void {
		$this->log_attempt( 'User Registration (WPEverest)' );
		wp_die(
			esc_html( $this->message ),
			esc_html__( 'Zablokowano', 'registration-blocker' ),
			[ 'response' => 403, 'back_link' => true ]
		);
	}

	// =========================================================================
	// ListingPro (CridioStudio)
	// =========================================================================

	private function hook_listingpro(): void {
		// Przechwytuje AJAX-owe żądania rejestracji przed wywołaniem wp_insert_user().
		add_action( 'wp_ajax_nopriv_lp_register',      [ $this, 'lp_block_registration' ], 1 );
		add_action( 'wp_ajax_nopriv_lp_register_user', [ $this, 'lp_block_registration' ], 1 );
		add_action( 'wp_ajax_nopriv_lp_vendor_register', [ $this, 'lp_block_registration' ], 1 );
	}

	public function lp_block_registration(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		$email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
		$this->log_attempt( 'ListingPro', $email );
		wp_send_json_error( [ 'message' => $this->message ], 403 );
	}

	// =========================================================================
	// CubeWP Forms
	// =========================================================================

	private function hook_cubewp_forms(): void {
		// Przechwytuje AJAX-owe żądania formularzy rejestracyjnych CubeWP.
		add_action( 'wp_ajax_nopriv_cwpf_submit_form',  [ $this, 'cwpf_block_ajax' ], 1 );
		add_action( 'wp_ajax_nopriv_cubewp_form_submit', [ $this, 'cwpf_block_ajax' ], 1 );

		// Filtr wywoływany przez CubeWP przed zapisem danych formularza.
		add_filter( 'cwp_before_form_process', [ $this, 'cwpf_block_filter' ] );
	}

	public function cwpf_block_ajax(): void {
		$this->log_attempt( 'CubeWP Forms' );
		wp_send_json_error( [ 'message' => $this->message ], 403 );
	}

	public function cwpf_block_filter( $data ) {
		$this->log_attempt( 'CubeWP Forms' );
		wp_die(
			esc_html( $this->message ),
			esc_html__( 'Zablokowano', 'registration-blocker' ),
			[ 'response' => 403, 'back_link' => true ]
		);
	}

	// =========================================================================
	// Application Passwords (WP 5.6+)
	// =========================================================================

	private function hook_application_passwords(): void {
		// Wyłącza Application Passwords dla wszystkich poza administratorami.
		// Dotyczy zarówno ekranu profilu (wp-admin) jak i REST API.
		add_filter( 'wp_is_application_passwords_available_for_user', [ $this, 'block_app_passwords_for_user' ], 1, 2 );
	}

	public function block_app_passwords_for_user( bool $available, \WP_User $user ): bool {
		return user_can( $user, 'manage_options' ) ? $available : false;
	}

	// =========================================================================
	// Helper — wykrywanie źródła rejestracji z kontekstu AJAX
	// =========================================================================

	private function detect_registration_source(): string {
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return 'wp_insert_user (direct)';
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		$known = [
			'lp_register'         => 'ListingPro',
			'lp_register_user'    => 'ListingPro',
			'lp_vendor_register'  => 'ListingPro',
			'cwpf_submit_form'    => 'CubeWP Forms',
			'cubewp_form_submit'  => 'CubeWP Forms',
		];

		if ( isset( $known[ $action ] ) ) {
			return $known[ $action ];
		}

		if ( 0 === strpos( $action, 'cwp' ) || 0 === strpos( $action, 'cubewp' ) ) {
			return 'CubeWP Forms';
		}

		if ( 0 === strpos( $action, 'lp_' ) ) {
			return 'ListingPro';
		}

		return 'wp_insert_user (direct)';
	}

	// =========================================================================
	// REST API
	// =========================================================================

	private function hook_rest_api(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'rest_guard' ], 10, 3 );
	}

	public function rest_guard( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		$route  = $request->get_route();
		$method = $request->get_method();

		// Blokada tworzenia użytkownika dla nie-adminów
		if ( 'POST' === $method
			&& preg_match( '#^/wp/v2/users$#', $route )
			&& ! current_user_can( 'create_users' )
		) {
			$this->log_attempt( 'REST POST /users' );
			return new \WP_Error( 'registration_blocked', $this->message, [ 'status' => 403 ] );
		}

		// Blokada Application Passwords przez REST API dla nie-adminów (WP 5.6+)
		if ( 'POST' === $method
			&& preg_match( '#^/wp/v2/users/\d+/application-passwords$#', $route )
			&& ! current_user_can( 'manage_options' )
		) {
			$this->log_attempt( 'REST POST /application-passwords' );
			return new \WP_Error( 'registration_blocked', $this->message, [ 'status' => 403 ] );
		}

		// Blokada enumeracji użytkowników dla nie-adminów (ochrona przed user enumeration)
		if ( 'GET' === $method
			&& preg_match( '#^/wp/v2/users#', $route )
			&& ! current_user_can( 'list_users' )
		) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Brak dostępu.', 'registration-blocker' ),
				[ 'status' => 403 ]
			);
		}

		return $result;
	}

	// =========================================================================
	// XML-RPC
	// =========================================================================

	private function hook_xmlrpc(): void {
		add_filter( 'xmlrpc_methods', [ $this, 'xmlrpc_remove_user_methods' ] );
	}

	public function xmlrpc_remove_user_methods( array $methods ): array {
		foreach ( [ 'wp.newUser', 'wp.registerNewBlog' ] as $m ) {
			unset( $methods[ $m ] );
		}
		return $methods;
	}

	// =========================================================================
	// Admin — rejestracja hooków
	// =========================================================================

	private function hook_admin(): void {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_init', [ $this, 'admin_settings' ] );
		add_action( 'admin_head', [ $this, 'admin_head_css' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar_node' ], 100 );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
		add_action( 'wp_ajax_rb_clear_log', [ $this, 'ajax_clear_log' ] );
		add_action( 'admin_post_rb_export_log', [ $this, 'handle_export_log' ] );
	}

	// =========================================================================
	// Admin — menu i ustawienia
	// =========================================================================

	public function admin_menu(): void {
		add_options_page(
			__( 'Registration Blocker', 'registration-blocker' ),
			__( 'Blokada rejestracji', 'registration-blocker' ),
			'manage_options',
			'registration-blocker',
			[ $this, 'render_admin_page' ]
		);
	}

	public function admin_settings(): void {
		register_setting( 'rb_options', 'rb_message', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => __( 'Rejestracja jest wyłączona.', 'registration-blocker' ),
		] );
		register_setting( 'rb_options', 'rb_redirect_url', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_redirect_url' ],
			'default'           => home_url(),
		] );
	}

	/**
	 * Sanitizuje URL przekierowania.
	 * Zabezpieczenie przed open redirect: akceptuje tylko URLe z tej samej domeny.
	 */
	public function sanitize_redirect_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );

		if ( empty( $url ) || ! $this->is_same_host( $url ) ) {
			add_settings_error(
				'rb_redirect_url',
				'rb_invalid_redirect',
				__( 'URL przekierowania musi wskazywać na tę samą domenę co strona.', 'registration-blocker' ),
				'error'
			);
			return (string) get_option( 'rb_redirect_url', home_url() );
		}

		return $url;
	}

	private function is_same_host( string $url ): bool {
		$site = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
		$dest = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		return $site && $dest && $site === $dest;
	}

	// =========================================================================
	// Admin — CSS (wstrzykiwany tylko na stronie wtyczki)
	// =========================================================================

	public function admin_head_css(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_GET['page'] ) || 'registration-blocker' !== $_GET['page'] ) {
			return;
		}
		echo '<style id="rb-admin-css">' . $this->get_admin_css() . '</style>';
	}

	private function get_admin_css(): string {
		return '
.rb-wrap { max-width: 1200px; }

/* ---- Header ---- */
.rb-header {
	display: flex; align-items: center; justify-content: space-between;
	background: #fff; padding: 16px 20px; margin-bottom: 20px;
	border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; border-radius: 3px;
}
.rb-header-left { display: flex; align-items: center; gap: 14px; }
.rb-header-left h1 { margin: 0; font-size: 22px; padding: 0; }
.rb-icon-lock { font-size: 36px; color: #2271b1; width: 36px; height: 36px; }
.rb-version { font-size: 11px; color: #888; display: block; margin-top: 2px; }
.rb-status-badge {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 7px 16px; border-radius: 20px; font-weight: 600; font-size: 13px;
	background: #d1fae5; color: #065f46;
}
.rb-status-badge .dashicons { color: #059669; }

/* ---- Stats row ---- */
.rb-stats-row {
	display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px;
}
.rb-stat-card {
	background: #fff; border: 1px solid #c3c4c7; border-top-width: 3px;
	border-radius: 4px; padding: 16px 20px; text-align: center;
}
.rb-stat-danger  { border-top-color: #d63638; }
.rb-stat-warning { border-top-color: #d97706; }
.rb-stat-info    { border-top-color: #2271b1; }
.rb-stat-neutral { border-top-color: #3c434a; }
.rb-stat-value   { font-size: 30px; font-weight: 700; line-height: 1; margin-bottom: 4px; }
.rb-stat-danger  .rb-stat-value { color: #d63638; }
.rb-stat-warning .rb-stat-value { color: #d97706; }
.rb-stat-info    .rb-stat-value { color: #2271b1; }
.rb-stat-neutral .rb-stat-value { color: #3c434a; }
.rb-stat-label { font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: .05em; }

/* ---- Layout ---- */
.rb-cols { display: grid; grid-template-columns: 1fr 400px; gap: 20px; align-items: start; }
.rb-col-main { display: flex; flex-direction: column; gap: 20px; }

/* ---- Cards ---- */
.rb-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; overflow: hidden; }
.rb-card-header {
	display: flex; align-items: center; gap: 8px; padding: 12px 16px;
	background: #f6f7f7; border-bottom: 1px solid #c3c4c7;
	font-weight: 600; font-size: 13px; color: #1d2327;
}
.rb-card-header .dashicons { color: #646970; }
.rb-card-body { padding: 16px; }
.rb-card-header-split { justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.rb-log-actions { display: flex; gap: 6px; align-items: center; }
.rb-log-actions .button { display: inline-flex; align-items: center; gap: 4px; height: 28px; line-height: 28px; }

/* ---- Settings form ---- */
.rb-form-table { margin-top: 0 !important; }
.rb-form-table th { padding-left: 0; }

/* ---- Integrations grid ---- */
.rb-integrations-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.rb-int-item {
	display: flex; align-items: center; gap: 8px; padding: 9px 12px;
	border-radius: 4px; border: 1px solid; font-size: 12px;
}
.rb-int-on  { background: #f0f9ff; border-color: #bae6fd; }
.rb-int-off { background: #f9f9f9; border-color: #e2e8f0; }
.rb-int-on  .dashicons { color: #2271b1; }
.rb-int-off .dashicons { color: #c3c4c7; }
.rb-int-label { flex: 1; }
.rb-int-off .rb-int-label { color: #888; }
.rb-badge {
	font-size: 10px; padding: 2px 7px; border-radius: 10px; font-weight: 600;
	text-transform: uppercase; letter-spacing: .04em; white-space: nowrap;
}
.rb-badge-on  { background: #d1fae5; color: #065f46; }
.rb-badge-off { background: #f3f4f6; color: #9ca3af; }

/* ---- Log table ---- */
.rb-log-body { padding: 0 !important; }
.rb-log-table { font-size: 12px; margin: 0 !important; border: 0 !important; }
.rb-log-table th { font-size: 11px; color: #646970; text-transform: uppercase; padding: 8px 12px !important; }
.rb-log-table td { padding: 7px 12px !important; vertical-align: middle; }
.rb-time { color: #646970; }
.rb-ip { font-family: monospace; font-size: 11px; }
.rb-id-cell { color: #646970; font-size: 11px; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rb-method-badge {
	display: inline-block; background: #eff6ff; color: #1d4ed8;
	border: 1px solid #bfdbfe; font-size: 10px; padding: 2px 7px;
	border-radius: 10px; white-space: nowrap;
}
.rb-empty-log { color: #888; font-style: italic; text-align: center; padding: 24px 16px; margin: 0; }

/* ---- Responsive ---- */
@media (max-width: 1050px) {
	.rb-cols { grid-template-columns: 1fr; }
	.rb-stats-row { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
	.rb-integrations-grid { grid-template-columns: 1fr; }
}
		';
	}

	// =========================================================================
	// Admin — pasek admina
	// =========================================================================

	public function admin_bar_node( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node( [
			'id'    => 'rb-status',
			'title' => '<span class="ab-icon dashicons dashicons-lock" style="font:400 18px/1 dashicons;vertical-align:middle;margin-right:4px;margin-top:6px"></span>'
			           . esc_html__( 'Rejestracja: OFF', 'registration-blocker' ),
			'href'  => admin_url( 'options-general.php?page=registration-blocker' ),
			'meta'  => [
				'title' => esc_attr__( 'Registration Blocker — rejestracja jest wyłączona', 'registration-blocker' ),
				'class' => 'rb-ab-node',
			],
		] );
	}

	// =========================================================================
	// Admin — widget dashboardu
	// =========================================================================

	public function register_dashboard_widget(): void {
		wp_add_dashboard_widget(
			'rb_widget',
			__( '🔒 Blokada rejestracji', 'registration-blocker' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget(): void {
		$log    = (array) get_option( self::LOG_OPTION, [] );
		$today  = strtotime( 'today midnight' );
		$week   = strtotime( '-7 days midnight' );

		$today_n = count( array_filter( $log, static fn( $e ) => $e['t'] >= $today ) );
		$week_n  = count( array_filter( $log, static fn( $e ) => $e['t'] >= $week ) );
		$total_n = count( $log );

		echo '<div style="display:flex;gap:10px;margin-bottom:14px">';
		$this->pill( __( 'Dziś', 'registration-blocker' ), $today_n, '#d63638' );
		$this->pill( __( '7 dni', 'registration-blocker' ), $week_n, '#d97706' );
		$this->pill( __( 'Łącznie', 'registration-blocker' ), $total_n, '#2271b1' );
		echo '</div>';

		$recent = array_slice( $log, 0, 5 );

		if ( $recent ) {
			echo '<table style="width:100%;font-size:11px;border-collapse:collapse">';
			foreach ( $recent as $e ) {
				echo '<tr>'
				     . '<td style="padding:3px 0;color:#888;white-space:nowrap">' . esc_html( gmdate( 'd.m H:i', $e['t'] ) ) . '</td>'
				     . '<td style="padding:3px 8px;font-family:monospace">' . esc_html( $e['ip'] ) . '</td>'
				     . '<td style="color:#555">' . esc_html( $e['m'] ) . '</td>'
				     . '</tr>';
			}
			echo '</table>';
		} else {
			echo '<p style="color:#888;font-size:12px;margin:0">' . esc_html__( 'Brak zablokowanych prób.', 'registration-blocker' ) . '</p>';
		}

		echo '<p style="margin:12px 0 0"><a href="' . esc_url( admin_url( 'options-general.php?page=registration-blocker' ) ) . '">'
		     . esc_html__( 'Pełny log →', 'registration-blocker' ) . '</a></p>';
	}

	private function pill( string $label, int $value, string $color ): void {
		echo '<div style="flex:1;text-align:center;padding:8px 4px;background:#f6f7f7;border-radius:4px;border-top:3px solid ' . esc_attr( $color ) . '">'
		     . '<div style="font-size:22px;font-weight:700;color:' . esc_attr( $color ) . '">' . esc_html( (string) $value ) . '</div>'
		     . '<div style="font-size:11px;color:#666">' . esc_html( $label ) . '</div>'
		     . '</div>';
	}

	// =========================================================================
	// Admin — AJAX: czyszczenie logu
	// =========================================================================

	public function ajax_clear_log(): void {
		check_ajax_referer( 'rb_clear_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		update_option( self::LOG_OPTION, [], false );
		wp_send_json_success( [ 'message' => __( 'Log wyczyszczony.', 'registration-blocker' ) ] );
	}

	// =========================================================================
	// Admin — eksport logu CSV
	// =========================================================================

	public function handle_export_log(): void {
		check_admin_referer( 'rb_export_log' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak dostępu.', 'registration-blocker' ), 403 );
		}

		$log      = (array) get_option( self::LOG_OPTION, [] );
		$filename = 'registration-blocker-log-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, [ 'Data i czas (UTC)', 'IP (zamaskowane)', 'Integracja', 'Identyfikator' ] );

		foreach ( $log as $e ) {
			fputcsv( $fh, [
				gmdate( 'Y-m-d H:i:s', (int) $e['t'] ),
				$e['ip']  ?? '',
				$e['m']   ?? '',
				$e['id']  ?? '',
			] );
		}

		fclose( $fh );
		exit;
	}

	// =========================================================================
	// Admin — strona ustawień
	// =========================================================================

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$log     = (array) get_option( self::LOG_OPTION, [] );
		$today   = strtotime( 'today midnight' );
		$week    = strtotime( '-7 days midnight' );
		$today_n = count( array_filter( $log, static fn( $e ) => $e['t'] >= $today ) );
		$week_n  = count( array_filter( $log, static fn( $e ) => $e['t'] >= $week ) );
		$total_n = count( $log );

		$clear_nonce  = wp_create_nonce( 'rb_clear_log' );
		$export_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=rb_export_log' ),
			'rb_export_log'
		);
		?>
		<div class="wrap rb-wrap">

			<!-- Header -->
			<div class="rb-header">
				<div class="rb-header-left">
					<span class="dashicons dashicons-lock rb-icon-lock" aria-hidden="true"></span>
					<div>
						<h1><?php esc_html_e( 'Registration Blocker', 'registration-blocker' ); ?></h1>
						<span class="rb-version">v<?php echo esc_html( RB_VERSION ); ?></span>
					</div>
				</div>
				<div class="rb-status-badge">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'Blokada aktywna', 'registration-blocker' ); ?>
				</div>
			</div>

			<!-- Statystyki -->
			<div class="rb-stats-row">
				<div class="rb-stat-card rb-stat-danger">
					<div class="rb-stat-value"><?php echo esc_html( (string) $today_n ); ?></div>
					<div class="rb-stat-label"><?php esc_html_e( 'Dziś', 'registration-blocker' ); ?></div>
				</div>
				<div class="rb-stat-card rb-stat-warning">
					<div class="rb-stat-value"><?php echo esc_html( (string) $week_n ); ?></div>
					<div class="rb-stat-label"><?php esc_html_e( 'Ostatnie 7 dni', 'registration-blocker' ); ?></div>
				</div>
				<div class="rb-stat-card rb-stat-info">
					<div class="rb-stat-value"><?php echo esc_html( (string) $total_n ); ?></div>
					<div class="rb-stat-label"><?php esc_html_e( 'Łącznie w logu', 'registration-blocker' ); ?></div>
				</div>
				<div class="rb-stat-card rb-stat-neutral">
					<div class="rb-stat-value"><?php echo esc_html( (string) count( self::INTEGRATIONS ) ); ?></div>
					<div class="rb-stat-label"><?php esc_html_e( 'Warstwy blokady', 'registration-blocker' ); ?></div>
				</div>
			</div>

			<div class="rb-cols">

				<!-- Kolumna główna -->
				<div class="rb-col-main">

					<!-- Ustawienia -->
					<div class="rb-card">
						<div class="rb-card-header">
							<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
							<?php esc_html_e( 'Ustawienia', 'registration-blocker' ); ?>
						</div>
						<div class="rb-card-body">
							<?php settings_errors( 'rb_options' ); ?>
							<form method="post" action="options.php">
								<?php settings_fields( 'rb_options' ); ?>
								<table class="form-table rb-form-table" role="presentation">
									<tr>
										<th scope="row">
											<label for="rb_message"><?php esc_html_e( 'Wiadomość blokady', 'registration-blocker' ); ?></label>
										</th>
										<td>
											<input type="text" name="rb_message" id="rb_message"
												class="large-text"
												value="<?php echo esc_attr( get_option( 'rb_message', __( 'Rejestracja jest wyłączona.', 'registration-blocker' ) ) ); ?>">
											<p class="description"><?php esc_html_e( 'Wyświetlana użytkownikowi przy próbie rejestracji przez formularze.', 'registration-blocker' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row">
											<label for="rb_redirect_url"><?php esc_html_e( 'URL przekierowania', 'registration-blocker' ); ?></label>
										</th>
										<td>
											<input type="url" name="rb_redirect_url" id="rb_redirect_url"
												class="large-text"
												value="<?php echo esc_attr( get_option( 'rb_redirect_url', home_url() ) ); ?>">
											<p class="description">
												<?php esc_html_e( 'Dokąd przenieść użytkownika wchodzącego na stronę rejestracji.', 'registration-blocker' ); ?>
												<strong><?php esc_html_e( 'Dozwolona wyłącznie ta sama domena.', 'registration-blocker' ); ?></strong>
											</p>
										</td>
									</tr>
								</table>
								<?php submit_button( __( 'Zapisz ustawienia', 'registration-blocker' ) ); ?>
							</form>
						</div>
					</div>

					<!-- Integracje -->
					<div class="rb-card">
						<div class="rb-card-header">
							<span class="dashicons dashicons-plugins-checked" aria-hidden="true"></span>
							<?php esc_html_e( 'Aktywne integracje', 'registration-blocker' ); ?>
						</div>
						<div class="rb-card-body">
							<div class="rb-integrations-grid">
								<?php foreach ( self::INTEGRATIONS as $int ) :
									$active = null === $int['plugin'] || $this->is_plugin_active( $int['plugin'] );
									?>
									<div class="rb-int-item <?php echo $active ? 'rb-int-on' : 'rb-int-off'; ?>">
										<span class="dashicons <?php echo $active ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>" aria-hidden="true"></span>
										<span class="rb-int-label"><?php echo esc_html( $int['label'] ); ?></span>
										<span class="rb-badge <?php echo $active ? 'rb-badge-on' : 'rb-badge-off'; ?>">
											<?php echo $active ? esc_html__( 'Aktywna', 'registration-blocker' ) : esc_html__( 'Nie wykryto', 'registration-blocker' ); ?>
										</span>
									</div>
								<?php endforeach; ?>
							</div>
							<p class="description" style="margin-top:12px">
								<?php esc_html_e( '"Nie wykryto" oznacza, że wtyczka nie jest zainstalowana — filtry są zarejestrowane prewencyjnie.', 'registration-blocker' ); ?>
							</p>
						</div>
					</div>

				</div><!-- .rb-col-main -->

				<!-- Kolumna boczna: Log -->
				<div class="rb-col-sidebar">
					<div class="rb-card">
						<div class="rb-card-header rb-card-header-split">
							<div style="display:flex;align-items:center;gap:8px">
								<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
								<?php esc_html_e( 'Log zablokowanych prób', 'registration-blocker' ); ?>
							</div>
							<div class="rb-log-actions">
								<a href="<?php echo esc_url( $export_url ); ?>" class="button button-small">
									<span class="dashicons dashicons-download" aria-hidden="true"></span>
									CSV
								</a>
								<button type="button" class="button button-small rb-btn-clear"
									data-nonce="<?php echo esc_attr( $clear_nonce ); ?>"
									data-confirm="<?php echo esc_attr__( 'Na pewno wyczyścić cały log?', 'registration-blocker' ); ?>">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
									<?php esc_html_e( 'Wyczyść', 'registration-blocker' ); ?>
								</button>
							</div>
						</div>
						<div class="rb-log-body" id="rb-log">
							<?php $this->render_log_table( $log ); ?>
						</div>
					</div>
				</div>

			</div><!-- .rb-cols -->
		</div>

		<script>
		(function () {
			var btn = document.querySelector('.rb-btn-clear');
			if (!btn) return;

			btn.addEventListener('click', function () {
				if (!confirm(btn.dataset.confirm)) return;
				btn.disabled = true;

				var body = new URLSearchParams({ action: 'rb_clear_log', nonce: btn.dataset.nonce });

				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.success) {
						document.getElementById('rb-log').innerHTML =
							'<p class="rb-empty-log"><?php echo esc_js( __( 'Log jest pusty.', 'registration-blocker' ) ); ?></p>';
						document.querySelectorAll('.rb-stat-value').forEach(function (el, i) {
							if (i < 3) el.textContent = '0';
						});
					}
					btn.disabled = false;
				})
				.catch(function () { btn.disabled = false; });
			});
		})();
		</script>
		<?php
	}

	// =========================================================================
	// Renderowanie tabeli logu
	// =========================================================================

	private function render_log_table( array $log ): void {
		if ( empty( $log ) ) {
			echo '<p class="rb-empty-log">' . esc_html__( 'Brak zablokowanych prób — log jest pusty.', 'registration-blocker' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped rb-log-table" role="table">';
		echo '<thead><tr>'
		     . '<th scope="col">' . esc_html__( 'Kiedy', 'registration-blocker' ) . '</th>'
		     . '<th scope="col">' . esc_html__( 'IP', 'registration-blocker' ) . '</th>'
		     . '<th scope="col">' . esc_html__( 'Integracja', 'registration-blocker' ) . '</th>'
		     . '<th scope="col">' . esc_html__( 'ID', 'registration-blocker' ) . '</th>'
		     . '</tr></thead><tbody>';

		foreach ( $log as $entry ) {
			$ts   = (int) $entry['t'];
			$diff = $this->time_diff( $ts );
			echo '<tr>'
			     . '<td title="' . esc_attr( gmdate( 'Y-m-d H:i:s', $ts ) . ' UTC' ) . '">'
			     . '<span class="rb-time">' . esc_html( $diff ) . '</span></td>'
			     . '<td class="rb-ip">' . esc_html( $entry['ip'] ?? '' ) . '</td>'
			     . '<td><span class="rb-method-badge">' . esc_html( $entry['m'] ?? '' ) . '</span></td>'
			     . '<td class="rb-id-cell" title="' . esc_attr( $entry['id'] ?? '' ) . '">'
			     . esc_html( $entry['id'] ?? '' ) . '</td>'
			     . '</tr>';
		}

		echo '</tbody></table>';
	}

	private function time_diff( int $ts ): string {
		$diff = time() - $ts;
		if ( $diff < 60 )   return sprintf( _n( '%d sek. temu', '%d sek. temu', $diff, 'registration-blocker' ), $diff );
		if ( $diff < 3600 ) return sprintf( _n( '%d min. temu', '%d min. temu', (int) ( $diff / 60 ), 'registration-blocker' ), (int) ( $diff / 60 ) );
		if ( $diff < 86400 ) return sprintf( _n( '%d godz. temu', '%d godz. temu', (int) ( $diff / 3600 ), 'registration-blocker' ), (int) ( $diff / 3600 ) );
		return gmdate( 'd.m.Y H:i', $ts );
	}

	// =========================================================================
	// Helper
	// =========================================================================

	private function is_plugin_active( string $plugin ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $plugin );
	}
}

register_activation_hook( RB_FILE, [ 'Registration_Blocker', 'activate' ] );
register_deactivation_hook( RB_FILE, [ 'Registration_Blocker', 'deactivate' ] );

add_action( 'plugins_loaded', [ Registration_Blocker::class, 'get_instance' ] );
