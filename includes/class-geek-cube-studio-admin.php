<?php
/**
 * WordPress admin screens for Geek Cube Studio.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the product control center.
 */
class Geek_Cube_Studio_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Geek_Cube_Studio_Admin|null
	 */
	private static $instance = null;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Boot admin integration once.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( 'Geek_Cube_Studio_Settings', 'register' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'geek-cube-studio', false, dirname( plugin_basename( GEEK_CUBE_STUDIO_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Register the top-level control center.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->page_hook = (string) add_menu_page(
			__( 'Geek Cube Studio', 'geek-cube-studio' ),
			__( 'Geek Cube', 'geek-cube-studio' ),
			'manage_options',
			'geek-cube-studio',
			array( $this, 'render_settings_page' ),
			'dashicons-games',
			3
		);
	}

	/**
	 * Enqueue assets only on this plugin screen.
	 *
	 * @param string $hook_suffix Current admin hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $this->page_hook !== (string) $hook_suffix && false === strpos( (string) $hook_suffix, 'geek-cube-studio' ) ) {
			return;
		}

		$style_path  = GEEK_CUBE_STUDIO_PLUGIN_DIR . 'assets/css/admin.css';
		$script_path = GEEK_CUBE_STUDIO_PLUGIN_DIR . 'assets/js/admin-settings.js';

		wp_enqueue_style(
			'geek-cube-studio-admin',
			GEEK_CUBE_STUDIO_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : GEEK_CUBE_STUDIO_VERSION
		);
		wp_enqueue_script(
			'geek-cube-studio-admin',
			GEEK_CUBE_STUDIO_PLUGIN_URL . 'assets/js/admin-settings.js',
			array(),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : GEEK_CUBE_STUDIO_VERSION,
			true
		);
		wp_localize_script(
			'geek-cube-studio-admin',
			'geekCubeStudioAdmin',
			array(
				'searchIndex' => $this->get_search_index(),
				'noResults'   => __( 'No settings found.', 'geek-cube-studio' ),
			)
		);
	}

	/**
	 * Return settings navigation.
	 *
	 * @return array<string,string>
	 */
	public static function get_tabs() {
		return array(
			'overview'  => __( 'Overview', 'geek-cube-studio' ),
			'urls'      => __( 'URLs and languages', 'geek-cube-studio' ),
			'player'    => __( 'Player', 'geek-cube-studio' ),
			'artifacts' => __( 'Artifacts', 'geek-cube-studio' ),
			'lab'       => __( 'Laboratory', 'geek-cube-studio' ),
			'saves'     => __( 'Saves', 'geek-cube-studio' ),
			'accounts'  => __( 'Accounts', 'geek-cube-studio' ),
		);
	}

	/**
	 * Resolve and validate the requested tab.
	 *
	 * @param array<string,mixed>|null $request Optional request source.
	 *
	 * @return string
	 */
	public static function resolve_tab( $request = null ) {
		$request = is_array( $request ) ? $request : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state.
		$raw_tab = isset( $request['tab'] ) && is_scalar( $request['tab'] ) ? wp_unslash( (string) $request['tab'] ) : 'overview';
		$tab     = sanitize_key( $raw_tab );

		return array_key_exists( $tab, self::get_tabs() ) ? $tab : 'overview';
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'geek-cube-studio' ), '', array( 'response' => 403 ) );
		}

		$active_tab       = self::resolve_tab();
		$tabs             = self::get_tabs();
		$settings         = Geek_Cube_Studio_Settings::get_all();
		$polylang_active  = function_exists( 'pll_languages_list' );
		$languages        = Geek_Cube_Studio_URLs::get_languages();
		$configured_slugs = isset( $settings['url_slugs'] ) && is_array( $settings['url_slugs'] ) ? $settings['url_slugs'] : array();
		$default_slugs    = Geek_Cube_Studio_Settings::defaults()['url_slugs']['default'];

		$configured_slugs['default'] = isset( $configured_slugs['default'] ) && is_array( $configured_slugs['default'] )
			? array_replace( $default_slugs, $configured_slugs['default'] )
			: $default_slugs;

		foreach ( $configured_slugs as $language => $routes ) {
			if ( 'default' !== $language && ! isset( $languages[ $language ] ) ) {
				$languages[ $language ] = array(
					'name'   => strtoupper( (string) $language ),
					'locale' => (string) $language,
				);
			}
		}

		$google_configured = defined( 'GEEK_CUBE_STUDIO_GOOGLE_CLIENT_ID' ) && '' !== (string) GEEK_CUBE_STUDIO_GOOGLE_CLIENT_ID;
		$apple_configured  = defined( 'GEEK_CUBE_STUDIO_APPLE_CLIENT_ID' ) && '' !== (string) GEEK_CUBE_STUDIO_APPLE_CLIENT_ID;

		require GEEK_CUBE_STUDIO_PLUGIN_DIR . 'views/admin/settings.php';
	}

	/**
	 * Return searchable settings metadata.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_search_index() {
		$items = array(
			array(
				'field'    => 'url-slugs',
				'tab'      => 'urls',
				'label'    => __( 'Translated route slugs', 'geek-cube-studio' ),
				'keywords' => 'url polylang idioma slug permalink rota',
			),
			array(
				'field'    => 'url-redirect-legacy',
				'tab'      => 'urls',
				'label'    => __( 'Redirect legacy routes', 'geek-cube-studio' ),
				'keywords' => 'redirect url antiga 301',
			),
			array(
				'field'    => 'player-public-enabled',
				'tab'      => 'player',
				'label'    => __( 'Public player', 'geek-cube-studio' ),
				'keywords' => 'player publicar jogo',
			),
			array(
				'field'    => 'player-default-engine',
				'tab'      => 'player',
				'label'    => __( 'Default engine', 'geek-cube-studio' ),
				'keywords' => 'emulatorjs nostalgist emulador',
			),
			array(
				'field'    => 'player-threads-mode',
				'tab'      => 'player',
				'label'    => __( 'WebAssembly threads', 'geek-cube-studio' ),
				'keywords' => 'wasm threads performance coop coep',
			),
			array(
				'field'    => 'artifact-storage-subdirectory',
				'tab'      => 'artifacts',
				'label'    => __( 'Artifact storage', 'geek-cube-studio' ),
				'keywords' => 'rom bios core wasm arquivo diretorio',
			),
			array(
				'field'    => 'artifact-update-checks',
				'tab'      => 'artifacts',
				'label'    => __( 'Upstream update checks', 'geek-cube-studio' ),
				'keywords' => 'versao atualizacao upstream',
			),
			array(
				'field'    => 'lab-enabled',
				'tab'      => 'lab',
				'label'    => __( 'Laboratory access', 'geek-cube-studio' ),
				'keywords' => 'teste laboratorio compatibilidade',
			),
			array(
				'field'    => 'lab-revalidation-days',
				'tab'      => 'lab',
				'label'    => __( 'Revalidation interval', 'geek-cube-studio' ),
				'keywords' => 'teste navegador periodicidade',
			),
			array(
				'field'    => 'guest-saves-enabled',
				'tab'      => 'saves',
				'label'    => __( 'Guest saves', 'geek-cube-studio' ),
				'keywords' => 'save local visitante indexeddb',
			),
			array(
				'field'    => 'cloud-saves-enabled',
				'tab'      => 'saves',
				'label'    => __( 'Account save synchronization', 'geek-cube-studio' ),
				'keywords' => 'save nuvem perfil sincronizacao',
			),
			array(
				'field'    => 'login-mode',
				'tab'      => 'accounts',
				'label'    => __( 'Login requirement', 'geek-cube-studio' ),
				'keywords' => 'conta visitante login social',
			),
			array(
				'field'    => 'google-login-enabled',
				'tab'      => 'accounts',
				'label'    => __( 'Google login', 'geek-cube-studio' ),
				'keywords' => 'google oidc social',
			),
			array(
				'field'    => 'apple-login-enabled',
				'tab'      => 'accounts',
				'label'    => __( 'Apple login', 'geek-cube-studio' ),
				'keywords' => 'apple oidc social',
			),
		);

		foreach ( $items as &$item ) {
			$item['url'] = add_query_arg(
				array(
					'page' => 'geek-cube-studio',
					'tab'  => $item['tab'],
				),
				admin_url( 'admin.php' )
			) . '#' . $item['field'];
		}
		unset( $item );

		return $items;
	}
}
