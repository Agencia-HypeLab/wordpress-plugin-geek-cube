<?php
/**
 * Language-aware URL construction for Geek Cube Studio.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps canonical object identity separate from public route translations.
 */
class Geek_Cube_Studio_URLs {

	/** Supported public route names. */
	const ROUTES = array( 'catalog', 'game', 'play', 'lab', 'profile' );

	/**
	 * Register URL lifecycle hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'update_option_' . Geek_Cube_Studio_Settings::OPTION_KEY, array( __CLASS__, 'maybe_flush_rewrite_rules' ), 10, 2 );
	}

	/**
	 * Return languages available to the settings screen.
	 *
	 * Polylang functions are always guarded because they temporarily disappear
	 * while WordPress replaces the plugin during an update.
	 *
	 * @return array<string,array{name:string,locale:string}>
	 */
	public static function get_languages() {
		$languages = array();

		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs   = (array) pll_languages_list(
				array(
					'hide_empty' => 0,
					'fields'     => 'slug',
				)
			);
			$names   = (array) pll_languages_list(
				array(
					'hide_empty' => 0,
					'fields'     => 'name',
				)
			);
			$locales = (array) pll_languages_list(
				array(
					'hide_empty' => 0,
					'fields'     => 'locale',
				)
			);

			foreach ( $slugs as $index => $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug ) {
					continue;
				}

				$languages[ $slug ] = array(
					'name'   => isset( $names[ $index ] ) ? (string) $names[ $index ] : strtoupper( $slug ),
					'locale' => isset( $locales[ $index ] ) ? (string) $locales[ $index ] : $slug,
				);
			}
		}

		return $languages;
	}

	/**
	 * Return the current or default language slug when Polylang is available.
	 *
	 * @return string
	 */
	public static function get_current_language() {
		if ( function_exists( 'pll_current_language' ) ) {
			$current = sanitize_key( (string) pll_current_language( 'slug' ) );
			if ( '' !== $current ) {
				return $current;
			}
		}

		if ( function_exists( 'pll_default_language' ) ) {
			return sanitize_key( (string) pll_default_language( 'slug' ) );
		}

		return 'default';
	}

	/**
	 * Resolve one configured route slug.
	 *
	 * @param string $route    Route identifier.
	 * @param string $language Optional language slug.
	 *
	 * @return string
	 */
	public static function get_route_slug( $route, $language = '' ) {
		$route = sanitize_key( (string) $route );
		if ( ! in_array( $route, self::ROUTES, true ) ) {
			return '';
		}

		$language = '' !== $language ? sanitize_key( (string) $language ) : self::get_current_language();
		$all      = Geek_Cube_Studio_Settings::get( 'url_slugs', array() );
		$fallback = Geek_Cube_Studio_Settings::defaults()['url_slugs']['default'][ $route ];

		if ( isset( $all[ $language ][ $route ] ) && '' !== $all[ $language ][ $route ] ) {
			return (string) $all[ $language ][ $route ];
		}

		return isset( $all['default'][ $route ] ) ? (string) $all['default'][ $route ] : $fallback;
	}

	/**
	 * Build a language-aware route URL.
	 *
	 * @param string          $route      Route identifier.
	 * @param string|string[] $object_slug Optional path segments appended to route.
	 * @param string          $language   Optional Polylang language slug.
	 *
	 * @return string
	 */
	public static function build( $route, $object_slug = '', $language = '' ) {
		$language   = '' !== $language ? sanitize_key( (string) $language ) : self::get_current_language();
		$route_slug = self::get_route_slug( $route, $language );

		if ( '' === $route_slug ) {
			return '';
		}

		$home = home_url( '/' );
		if ( 'default' !== $language && function_exists( 'pll_home_url' ) ) {
			$localized_home = pll_home_url( $language );
			if ( is_string( $localized_home ) && '' !== $localized_home ) {
				$home = $localized_home;
			}
		}

		$path     = $route_slug;
		$segments = is_array( $object_slug ) ? $object_slug : array( $object_slug );
		foreach ( $segments as $segment ) {
			$segment = is_scalar( $segment ) ? sanitize_title( (string) $segment ) : '';
			if ( '' !== $segment ) {
				$path .= '/' . $segment;
			}
		}

		$url = trailingslashit( $home ) . user_trailingslashit( ltrim( $path, '/' ) );

		return apply_filters( 'geek_cube_studio_route_url', $url, $route, $object_slug, $language );
	}

	/**
	 * Flush rewrites only when route configuration changes.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $new_value New settings.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules( $old_value, $new_value ) {
		$old_routes = is_array( $old_value ) && isset( $old_value['url_slugs'] ) ? $old_value['url_slugs'] : array();
		$new_routes = is_array( $new_value ) && isset( $new_value['url_slugs'] ) ? $new_value['url_slugs'] : array();

		if ( $old_routes !== $new_routes ) {
			flush_rewrite_rules( false );
		}
	}
}
