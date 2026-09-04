<?php
/**
 * Public and laboratory player endpoints.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves friendly URLs into frozen execution profiles.
 */
final class Geek_Cube_Studio_Player {

	/** Public game query variable. */
	const GAME_QUERY_VAR = 'geek_cube_game';

	/** Laboratory profile query variable. */
	const LAB_QUERY_VAR = 'geek_cube_lab_profile';

	/** Register route hooks. */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ), 9 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ) );
	}

	/**
	 * Add all configured translated route variants.
	 *
	 * @return void
	 */
	public static function register_rewrite_rules() {
		$settings = Geek_Cube_Studio_Settings::get( 'url_slugs', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$play     = array();
		$lab      = array();

		foreach ( $settings as $routes ) {
			if ( ! is_array( $routes ) ) {
				continue;
			}
			if ( ! empty( $routes['play'] ) ) {
				$play[] = sanitize_title( $routes['play'] );
			}
			if ( ! empty( $routes['lab'] ) ) {
				$lab[] = sanitize_title( $routes['lab'] );
			}
		}

		$play[] = Geek_Cube_Studio_URLs::get_route_slug( 'play', 'default' );
		$lab[]  = Geek_Cube_Studio_URLs::get_route_slug( 'lab', 'default' );

		foreach ( array_unique( array_filter( $lab ) ) as $slug ) {
			add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/([a-f0-9-]{36})/?$', 'index.php?' . self::LAB_QUERY_VAR . '=$matches[1]', 'top' );
		}
		foreach ( array_unique( array_filter( $play ) ) as $slug ) {
			add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/([^/]+)/?$', 'index.php?' . self::GAME_QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	/**
	 * Add owned public query variables.
	 *
	 * @param string[] $query_vars Existing variables.
	 * @return string[]
	 */
	public static function query_vars( $query_vars ) {
		$query_vars[] = self::GAME_QUERY_VAR;
		$query_vars[] = self::LAB_QUERY_VAR;

		return array_unique( $query_vars );
	}

	/**
	 * Render a player when an owned endpoint was resolved.
	 *
	 * @return void
	 */
	public static function dispatch() {
		$lab_uuid  = sanitize_text_field( (string) get_query_var( self::LAB_QUERY_VAR, '' ) );
		$game_slug = sanitize_title( (string) get_query_var( self::GAME_QUERY_VAR, '' ) );

		if ( '' !== $lab_uuid ) {
			self::dispatch_laboratory( $lab_uuid );
		}

		if ( '' !== $game_slug ) {
			self::dispatch_public( $game_slug );
		}
	}

	/**
	 * Resolve the protected laboratory endpoint.
	 *
	 * @param string $uuid Profile UUID.
	 * @return void
	 */
	private static function dispatch_laboratory( $uuid ) {
		$capability = (string) Geek_Cube_Studio_Settings::get( 'lab_required_capability', 'manage_options' );
		if ( '1' !== Geek_Cube_Studio_Settings::get( 'lab_enabled', '1' ) || ! current_user_can( $capability ) ) {
			self::error( 403, __( 'You cannot access this laboratory profile.', 'geek-cube-studio' ) );
		}

		$profile = Geek_Cube_Studio_Repository::get_profile_by_uuid( $uuid );
		if ( ! $profile ) {
			self::error( 404, __( 'The requested execution profile does not exist.', 'geek-cube-studio' ) );
		}

		self::render( $profile, true );
	}

	/**
	 * Resolve a public game to its production profile.
	 *
	 * @param string $slug Canonical game slug.
	 * @return void
	 */
	private static function dispatch_public( $slug ) {
		if ( '1' !== Geek_Cube_Studio_Settings::get( 'player_public_enabled', '0' ) ) {
			self::error( 404, __( 'The public player is not enabled.', 'geek-cube-studio' ) );
		}

		if ( '1' !== Geek_Cube_Studio_Settings::get( 'player_guest_enabled', '1' ) && ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$game = Geek_Cube_Studio_Repository::get_game_by_slug( $slug );
		if ( ! $game || 'published' !== $game['status'] || ! $game['production_profile_id'] ) {
			self::error( 404, __( 'This game has no published execution profile.', 'geek-cube-studio' ) );
		}

		$profile = Geek_Cube_Studio_Repository::get_profile( $game['production_profile_id'] );
		if ( ! $profile || 'production' !== $profile['status'] ) {
			self::error( 404, __( 'This game has no active production profile.', 'geek-cube-studio' ) );
		}

		self::render( $profile, false, $game );
	}

	/**
	 * Validate files and render the isolated self-hosted player document.
	 *
	 * @param array<string,mixed>      $profile    Execution profile.
	 * @param bool                     $laboratory Whether this is an internal test.
	 * @param array<string,mixed>|null $game       Optional preloaded game.
	 * @return void
	 */
	private static function render( array $profile, $laboratory, $game = null ) {
		$game      = is_array( $game ) ? $game : Geek_Cube_Studio_Repository::get_game( $profile['game_id'] );
		$artifacts = Geek_Cube_Studio_Repository::get_profile_artifacts( $profile );
		$valid     = $game ? Geek_Cube_Studio_Repository::validate_profile_artifacts( $game, $artifacts ) : new WP_Error( 'geek_cube_game_missing', __( 'The profile game is missing.', 'geek-cube-studio' ) );

		if ( is_wp_error( $valid ) ) {
			self::error( 409, $valid->get_error_message() );
		}

		$loader_path = Geek_Cube_Studio_Artifact_Storage::path( $artifacts['player']['entrypoint_path'] );
		$rom_path    = Geek_Cube_Studio_Artifact_Storage::path( $artifacts['rom']['relative_path'] );
		if ( '' === $loader_path || ! is_file( $loader_path ) || '' === $rom_path || ! is_file( $rom_path ) ) {
			self::error( 409, __( 'One or more immutable profile files are missing from local storage.', 'geek-cube-studio' ) );
		}

		$loader_url = Geek_Cube_Studio_Artifact_Storage::url( $artifacts['player']['entrypoint_path'] );
		$rom_url    = Geek_Cube_Studio_Artifact_Storage::url( $artifacts['rom']['relative_path'] );
		$bios_url   = $artifacts['bios'] ? Geek_Cube_Studio_Artifact_Storage::url( $artifacts['bios']['relative_path'] ) : '';
		$data_url   = preg_replace( '#loader\.js$#', '', $loader_url );
		$title      = Geek_Cube_Studio_Repository::translated_value( $game['titles'] );
		$core       = $artifacts['core']['runtime_key'];
		$volume     = max( 0, min( 100, (int) Geek_Cube_Studio_Settings::get( 'player_default_volume', 70 ) ) ) / 100;

		status_header( 200 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		require GEEK_CUBE_STUDIO_PLUGIN_DIR . 'views/player.php';
		exit;
	}

	/**
	 * Render a safe endpoint error.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Safe user-facing message.
	 * @return void
	 */
	private static function error( $status, $message ) {
		status_header( (int) $status );
		nocache_headers();
		wp_die( esc_html( $message ), esc_html__( 'Geek Cube Studio player', 'geek-cube-studio' ), array( 'response' => (int) $status ) );
	}
}
