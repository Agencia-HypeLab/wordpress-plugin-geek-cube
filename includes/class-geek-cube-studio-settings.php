<?php
/**
 * Central settings registry for Geek Cube Studio.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and sanitizes product-wide configuration.
 */
class Geek_Cube_Studio_Settings {

	/** Settings API group. */
	const OPTION_GROUP = 'geek_cube_studio_settings_group';

	/** Option containing the settings array. */
	const OPTION_KEY = 'geek_cube_studio_settings';

	/**
	 * Register the option with WordPress.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Return canonical defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'url_slugs'                     => array(
				'default' => array(
					'catalog' => 'jogos',
					'game'    => 'jogo',
					'play'    => 'jogar',
					'profile' => 'perfil',
				),
			),
			'url_redirect_legacy'           => '1',
			'player_public_enabled'         => '0',
			'player_guest_enabled'          => '1',
			'player_default_engine'         => 'emulatorjs',
			'player_threads_mode'           => 'auto',
			'player_start_mode'             => 'interaction',
			'player_default_volume'         => 70,
			'artifact_storage_subdirectory' => 'geek-cube-studio/artifacts',
			'artifact_update_checks'        => '1',
			'artifact_auto_download'        => '0',
			'lab_enabled'                   => '1',
			'lab_record_metrics'            => '1',
			'lab_revalidation_days'         => 90,
			'lab_required_capability'       => 'manage_options',
			'guest_saves_enabled'           => '1',
			'cloud_saves_enabled'           => '1',
			'save_revision_limit'           => 5,
			'save_state_max_mb'             => 16,
			'save_conflict_policy'          => 'keep_both',
			'login_mode'                    => 'optional',
			'email_login_enabled'           => '1',
			'google_login_enabled'          => '0',
			'apple_login_enabled'           => '0',
		);
	}

	/**
	 * Read all settings merged with current defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array_replace_recursive( self::defaults(), $stored );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Optional fallback.
	 *
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$settings = self::get_all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Sanitize a full or section-level settings submission.
	 *
	 * Section-level submissions preserve settings from the other tabs.
	 *
	 * @param mixed $input Raw settings input.
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$section  = isset( $input['_section'] ) && is_scalar( $input['_section'] ) ? sanitize_key( (string) $input['_section'] ) : '';
		$current  = self::get_all();
		$defaults = self::defaults();
		$output   = '' === $section ? $defaults : $current;

		if ( '' === $section || 'urls' === $section ) {
			$output['url_slugs']           = self::sanitize_url_slugs( isset( $input['url_slugs'] ) ? $input['url_slugs'] : $current['url_slugs'] );
			$output['url_redirect_legacy'] = self::checkbox( $input, 'url_redirect_legacy' );
		}

		if ( '' === $section || 'player' === $section ) {
			$output['player_public_enabled'] = self::checkbox( $input, 'player_public_enabled' );
			$output['player_guest_enabled']  = self::checkbox( $input, 'player_guest_enabled' );
			$output['player_default_engine'] = self::enum( $input, 'player_default_engine', array( 'emulatorjs', 'nostalgist' ), $defaults['player_default_engine'] );
			$output['player_threads_mode']   = self::enum( $input, 'player_threads_mode', array( 'off', 'auto', 'on' ), $defaults['player_threads_mode'] );
			$output['player_start_mode']     = self::enum( $input, 'player_start_mode', array( 'interaction', 'manual' ), $defaults['player_start_mode'] );
			$output['player_default_volume'] = self::bounded_int( $input, 'player_default_volume', 0, 100, $defaults['player_default_volume'] );
		}

		if ( '' === $section || 'artifacts' === $section ) {
			$output['artifact_storage_subdirectory'] = self::sanitize_relative_path(
				isset( $input['artifact_storage_subdirectory'] ) ? $input['artifact_storage_subdirectory'] : $defaults['artifact_storage_subdirectory'],
				$defaults['artifact_storage_subdirectory']
			);
			$output['artifact_update_checks']        = self::checkbox( $input, 'artifact_update_checks' );
			$output['artifact_auto_download']        = self::checkbox( $input, 'artifact_auto_download' );
		}

		if ( '' === $section || 'lab' === $section ) {
			$output['lab_enabled']             = self::checkbox( $input, 'lab_enabled' );
			$output['lab_record_metrics']      = self::checkbox( $input, 'lab_record_metrics' );
			$output['lab_revalidation_days']   = self::bounded_int( $input, 'lab_revalidation_days', 1, 3650, $defaults['lab_revalidation_days'] );
			$output['lab_required_capability'] = self::enum( $input, 'lab_required_capability', array( 'manage_options', 'edit_others_posts' ), $defaults['lab_required_capability'] );
		}

		if ( '' === $section || 'saves' === $section ) {
			$output['guest_saves_enabled']  = self::checkbox( $input, 'guest_saves_enabled' );
			$output['cloud_saves_enabled']  = self::checkbox( $input, 'cloud_saves_enabled' );
			$output['save_revision_limit']  = self::bounded_int( $input, 'save_revision_limit', 1, 50, $defaults['save_revision_limit'] );
			$output['save_state_max_mb']    = self::bounded_int( $input, 'save_state_max_mb', 1, 128, $defaults['save_state_max_mb'] );
			$output['save_conflict_policy'] = self::enum( $input, 'save_conflict_policy', array( 'keep_both', 'newest' ), $defaults['save_conflict_policy'] );
		}

		if ( '' === $section || 'accounts' === $section ) {
			$output['login_mode']           = self::enum( $input, 'login_mode', array( 'optional', 'required', 'disabled' ), $defaults['login_mode'] );
			$output['email_login_enabled']  = self::checkbox( $input, 'email_login_enabled' );
			$output['google_login_enabled'] = self::checkbox( $input, 'google_login_enabled' );
			$output['apple_login_enabled']  = self::checkbox( $input, 'apple_login_enabled' );
		}

		return $output;
	}

	/**
	 * Sanitize route slugs by language while preventing collisions.
	 *
	 * @param mixed $raw Raw language map.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function sanitize_url_slugs( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$defaults = self::defaults()['url_slugs']['default'];
		$output   = array();

		foreach ( $raw as $language => $routes ) {
			$language = 'default' === $language ? 'default' : sanitize_key( (string) $language );
			if ( '' === $language || ! is_array( $routes ) ) {
				continue;
			}

			$sanitized = array();
			foreach ( array_keys( $defaults ) as $route ) {
				$fallback            = isset( $defaults[ $route ] ) ? $defaults[ $route ] : $route;
				$value               = isset( $routes[ $route ] ) ? sanitize_title( (string) $routes[ $route ] ) : $fallback;
				$sanitized[ $route ] = '' !== $value ? $value : $fallback;
			}

			if ( count( array_unique( $sanitized ) ) !== count( $sanitized ) ) {
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error(
						self::OPTION_KEY,
						'geek_cube_studio_duplicate_url_slug_' . $language,
						__( 'Each Geek Cube route must use a different slug within the same language.', 'geek-cube-studio' )
					);
				}

				$sanitized = isset( self::get_all()['url_slugs'][ $language ] )
					? self::get_all()['url_slugs'][ $language ]
					: $defaults;
			}

			$output[ $language ] = $sanitized;
		}

		if ( ! isset( $output['default'] ) ) {
			$output['default'] = $defaults;
		}

		return $output;
	}

	/**
	 * Sanitize a relative directory path.
	 *
	 * @param mixed  $value    Raw path.
	 * @param string $fallback Fallback path.
	 *
	 * @return string
	 */
	private static function sanitize_relative_path( $value, $fallback ) {
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}

		$value = str_replace( '\\', '/', trim( (string) $value ) );
		$parts = array_filter( explode( '/', $value ), 'strlen' );
		$clean = array();

		foreach ( $parts as $part ) {
			$part = sanitize_title( $part );
			if ( '' !== $part && '.' !== $part && '..' !== $part ) {
				$clean[] = $part;
			}
		}

		return empty( $clean ) ? $fallback : implode( '/', $clean );
	}

	/**
	 * Return a normalized checkbox value.
	 *
	 * @param array<string,mixed> $input Raw settings.
	 * @param string              $key   Setting key.
	 *
	 * @return string
	 */
	private static function checkbox( array $input, $key ) {
		return isset( $input[ $key ] ) && '1' === (string) $input[ $key ] ? '1' : '0';
	}

	/**
	 * Return an allow-listed string value.
	 *
	 * @param array<string,mixed> $input    Raw settings.
	 * @param string              $key      Setting key.
	 * @param string[]            $allowed  Allowed values.
	 * @param string              $fallback Fallback value.
	 *
	 * @return string
	 */
	private static function enum( array $input, $key, array $allowed, $fallback ) {
		$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_key( (string) $input[ $key ] ) : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Return an integer constrained to an inclusive range.
	 *
	 * @param array<string,mixed> $input    Raw settings.
	 * @param string              $key      Setting key.
	 * @param int                 $minimum  Minimum value.
	 * @param int                 $maximum  Maximum value.
	 * @param int                 $fallback Fallback value.
	 *
	 * @return int
	 */
	private static function bounded_int( array $input, $key, $minimum, $maximum, $fallback ) {
		$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? absint( $input[ $key ] ) : (int) $fallback;

		return max( $minimum, min( $maximum, $value ) );
	}
}
