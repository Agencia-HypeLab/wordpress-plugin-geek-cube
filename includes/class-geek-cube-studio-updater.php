<?php
/**
 * Signed plugin update client.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates the private Geek Cube Studio release feed with WordPress updates.
 */
final class Geek_Cube_Studio_Updater {
	/** Plugin slug. */
	const SLUG = 'geek-cube-studio';

	/** Cached manifest transient. */
	const MANIFEST_CACHE_KEY = 'geek_cube_studio_update_manifest';

	/** Successful manifest cache duration. */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Failed request cache duration. */
	const ERROR_CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/** Signature algorithm accepted by this client. */
	const SIGNATURE_ALGORITHM = 'Ed25519';

	/** Fields protected by the release signature. */
	const SIGNED_FIELDS = array(
		'auto_update',
		'auto_update_major',
		'author',
		'channel',
		'download_url',
		'homepage',
		'last_updated',
		'name',
		'release_type',
		'requires',
		'requires_php',
		'rollout_percent',
		'sha256',
		'slug',
		'tested',
		'version',
	);

	/**
	 * Whether hooks were registered.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register update hooks once.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		$host         = wp_parse_url( GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL, PHP_URL_HOST );

		if ( is_string( $host ) && '' !== $host ) {
			add_filter( 'update_plugins_' . $host, array( __CLASS__, 'filter_update_uri_response' ), 10, 4 );
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update_transient' ) );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_update_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugin_information' ), 20, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'filter_auto_update' ), 20, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_package_download' ), 20, 4 );
		add_filter( 'http_request_host_is_external', array( __CLASS__, 'allow_manifest_host' ), 10, 3 );
	}

	/**
	 * Allow the configured manifest host when outbound requests are restricted.
	 *
	 * @param bool   $allowed Whether the request is allowed.
	 * @param string $host Request host.
	 * @param string $url Request URL.
	 * @return bool
	 */
	public static function allow_manifest_host( $allowed, $host, $url ) {
		unset( $url );
		$manifest_host = wp_parse_url( GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL, PHP_URL_HOST );

		if ( is_string( $manifest_host ) && hash_equals( strtolower( $manifest_host ), strtolower( (string) $host ) ) ) {
			return true;
		}

		return $allowed;
	}

	/**
	 * Supply update data through the Update URI host-specific hook.
	 *
	 * @param array|false $update Existing update response.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @param string[]    $locales Requested locales.
	 * @return array|false
	 */
	public static function filter_update_uri_response( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $plugin_data, $locales );

		if ( self::plugin_basename() !== $plugin_file ) {
			return $update;
		}

		$manifest = self::get_manifest();

		if ( ! self::is_update_available( $manifest ) ) {
			return false;
		}

		return self::update_array( $manifest );
	}

	/**
	 * Add this plugin to the standard WordPress update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public static function inject_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$plugin_file = self::plugin_basename();
		$manifest    = self::get_manifest();

		if ( ! is_array( $manifest ) ) {
			return $transient;
		}

		$offer = (object) self::update_array( $manifest );

		if ( self::is_update_available( $manifest ) ) {
			$transient->response[ $plugin_file ] = $offer;
			unset( $transient->no_update[ $plugin_file ] );
		} else {
			$transient->no_update[ $plugin_file ] = $offer;
			unset( $transient->response[ $plugin_file ] );
		}

		return $transient;
	}

	/**
	 * Supply the modal shown by "View details" in wp-admin.
	 *
	 * @param false|object|array $result Existing API result.
	 * @param string             $action API action.
	 * @param object             $args API arguments.
	 * @return false|object|array
	 */
	public static function filter_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( isset( $args->slug ) ? $args->slug : '' ) ) {
			return $result;
		}

		$manifest = self::get_manifest();

		if ( ! is_array( $manifest ) ) {
			return $result;
		}

		if ( ! self::channel_is_accepted( $manifest ) ) {
			return $result;
		}

		return (object) array(
			'name'          => isset( $manifest['name'] ) ? sanitize_text_field( $manifest['name'] ) : 'Geek Cube Studio',
			'slug'          => self::SLUG,
			'version'       => $manifest['version'],
			'author'        => isset( $manifest['author'] ) ? wp_kses_post( $manifest['author'] ) : 'Agência HypeLab',
			'homepage'      => isset( $manifest['homepage'] ) ? esc_url_raw( $manifest['homepage'] ) : '',
			'requires'      => $manifest['requires'],
			'tested'        => isset( $manifest['tested'] ) ? sanitize_text_field( $manifest['tested'] ) : '',
			'requires_php'  => $manifest['requires_php'],
			'last_updated'  => $manifest['last_updated'],
			'download_link' => $manifest['download_url'],
			'sections'      => array(
				'description' => esc_html__( 'Atualização oficial e assinada do Geek Cube Studio.', 'geek-cube-studio' ),
				'changelog'   => esc_html__( 'Consulte o changelog incluído no pacote do plugin.', 'geek-cube-studio' ),
			),
		);
	}

	/**
	 * Apply only the auto-update policy carried by the signed manifest.
	 *
	 * @param bool|null $update Whether WordPress should update automatically.
	 * @param object    $item Update item.
	 * @return bool|null
	 */
	public static function filter_auto_update( $update, $item ) {
		$plugin_file = is_object( $item ) && isset( $item->plugin ) ? (string) $item->plugin : '';
		$slug        = is_object( $item ) && isset( $item->slug ) ? (string) $item->slug : '';

		if ( self::plugin_basename() !== $plugin_file && self::SLUG !== $slug ) {
			return $update;
		}

		$manifest = self::get_manifest();

		if (
			! self::is_update_available( $manifest )
			|| 'stable' !== $manifest['channel']
			|| true !== $manifest['auto_update']
			|| ! self::environment_meets_requirements( $manifest )
			|| ! self::site_is_in_rollout( $manifest )
		) {
			return false;
		}

		$current_major = self::version_major( GEEK_CUBE_STUDIO_VERSION );
		$next_major    = self::version_major( $manifest['version'] );

		if ( $next_major > $current_major && true !== $manifest['auto_update_major'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Download this plugin's package and validate its signed SHA-256 digest.
	 *
	 * @param bool|WP_Error $reply Existing short-circuit result.
	 * @param string        $package Package URL.
	 * @param WP_Upgrader   $upgrader Upgrader instance.
	 * @param array         $hook_extra Upgrade context.
	 * @return bool|string|WP_Error
	 */
	public static function verify_package_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader );

		$target_plugin = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
		$manifest      = self::get_manifest();
		$is_ours       = self::plugin_basename() === $target_plugin;

		if ( ! $is_ours && is_array( $manifest ) ) {
			$is_ours = hash_equals( (string) $manifest['download_url'], (string) $package );
		}

		if ( ! $is_ours ) {
			return $reply;
		}

		if ( ! is_array( $manifest ) || ! hash_equals( (string) $manifest['download_url'], (string) $package ) ) {
			return new WP_Error( 'geek_cube_studio_update_manifest', __( 'O pacote não corresponde ao manifesto assinado.', 'geek-cube-studio' ) );
		}

		$temporary_file = download_url( $package, 300 );

		if ( is_wp_error( $temporary_file ) ) {
			return $temporary_file;
		}

		$actual_hash = hash_file( 'sha256', $temporary_file );

		if ( ! is_string( $actual_hash ) || ! hash_equals( strtolower( $manifest['sha256'] ), strtolower( $actual_hash ) ) ) {
			wp_delete_file( $temporary_file );

			return new WP_Error( 'geek_cube_studio_update_checksum', __( 'A integridade do pacote de atualização não pôde ser confirmada.', 'geek-cube-studio' ) );
		}

		return $temporary_file;
	}

	/**
	 * Fetch and authenticate the release manifest.
	 *
	 * @param bool $force Whether to bypass the transient cache.
	 * @return array<string,mixed>|false
	 */
	public static function get_manifest( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::MANIFEST_CACHE_KEY );

			if ( is_array( $cached ) && isset( $cached['__fetch_error'] ) ) {
				return false;
			}

			if ( self::validate_manifest( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::MANIFEST_CACHE_KEY, array( '__fetch_error' => true ), self::ERROR_CACHE_TTL );

			return false;
		}

		$manifest = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! self::validate_manifest( $manifest ) ) {
			set_site_transient( self::MANIFEST_CACHE_KEY, array( '__fetch_error' => true ), self::ERROR_CACHE_TTL );

			return false;
		}

		set_site_transient( self::MANIFEST_CACHE_KEY, $manifest, self::CACHE_TTL );

		return $manifest;
	}

	/**
	 * Validate manifest structure, origin and Ed25519 signature.
	 *
	 * @param mixed $manifest Candidate manifest.
	 * @return bool
	 */
	public static function validate_manifest( $manifest ) {
		if ( ! is_array( $manifest ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}

		foreach ( self::SIGNED_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $manifest ) ) {
				return false;
			}
		}

		if (
			self::SLUG !== $manifest['slug']
			|| ! is_string( $manifest['name'] )
			|| ! is_string( $manifest['author'] )
			|| ! is_string( $manifest['homepage'] )
			|| ( '' !== $manifest['homepage'] && ! self::is_https_url( $manifest['homepage'] ) )
			|| ! is_string( $manifest['version'] )
			|| ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $manifest['version'] )
			|| ! is_string( $manifest['requires'] )
			|| ! is_string( $manifest['requires_php'] )
			|| ! is_string( $manifest['tested'] )
			|| ! is_string( $manifest['download_url'] )
			|| ! self::is_https_url( $manifest['download_url'] )
			|| ! is_string( $manifest['last_updated'] )
			|| ! in_array( $manifest['channel'], array( 'stable', 'preview' ), true )
			|| ! in_array( $manifest['release_type'], array( 'patch', 'minor', 'major' ), true )
			|| ! is_bool( $manifest['auto_update'] )
			|| ! is_bool( $manifest['auto_update_major'] )
			|| ! is_int( $manifest['rollout_percent'] )
			|| 0 > $manifest['rollout_percent']
			|| 100 < $manifest['rollout_percent']
			|| ! is_string( $manifest['sha256'] )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $manifest['sha256'] )
		) {
			return false;
		}

		$public_config = self::public_key_config();

		if (
			false === $public_config
			|| self::SIGNATURE_ALGORITHM !== ( isset( $manifest['signature_alg'] ) ? $manifest['signature_alg'] : '' )
			|| ( isset( $manifest['signature_key_id'] ) ? $manifest['signature_key_id'] : '' ) !== $public_config['key_id']
			|| ! isset( $manifest['signature'] )
		) {
			return false;
		}

		// Base64 is the transport encoding for these cryptographic byte strings.
		$signature = base64_decode( (string) $manifest['signature'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$public    = base64_decode( $public_config['public_key_base64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$canonical = self::canonical_json( $manifest );

		return is_string( $canonical )
			&& false !== $signature
			&& false !== $public
			&& SODIUM_CRYPTO_SIGN_BYTES === strlen( $signature )
			&& SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $public )
			&& sodium_crypto_sign_verify_detached( $signature, $canonical, $public );
	}

	/**
	 * Produce the exact JSON envelope signed by the release tool.
	 *
	 * @param array<string,mixed> $manifest Release manifest.
	 * @return string|false
	 */
	public static function canonical_json( array $manifest ) {
		$signed = array();

		foreach ( self::SIGNED_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $manifest ) ) {
				return false;
			}

			$signed[ $field ] = $manifest[ $field ];
		}

		ksort( $signed, SORT_STRING );
		$json = wp_json_encode( $signed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : false;
	}

	/**
	 * Determine whether a newer compatible release exists.
	 *
	 * @param mixed $manifest Release manifest.
	 * @return bool
	 */
	public static function is_update_available( $manifest ) {
		return is_array( $manifest )
			&& self::channel_is_accepted( $manifest )
			&& self::environment_meets_requirements( $manifest )
			&& version_compare( $manifest['version'], GEEK_CUBE_STUDIO_VERSION, '>' );
	}

	/**
	 * Check whether this site opted into the manifest's release channel.
	 *
	 * Preview sites also receive stable releases. Stable sites fail closed for
	 * preview manifests.
	 *
	 * @param array<string,mixed> $manifest Release manifest.
	 * @return bool
	 */
	public static function channel_is_accepted( array $manifest ) {
		/**
		 * Filters the update channel accepted by this site.
		 *
		 * @param string $channel Either stable or preview.
		 */
		$accepted = apply_filters( 'geek_cube_studio_update_channel', 'stable' );
		$accepted = in_array( $accepted, array( 'stable', 'preview' ), true ) ? $accepted : 'stable';

		return 'stable' === $manifest['channel'] || 'preview' === $accepted;
	}

	/**
	 * Test the signed PHP and WordPress minimums.
	 *
	 * @param array<string,mixed> $manifest Release manifest.
	 * @return bool
	 */
	public static function environment_meets_requirements( array $manifest ) {
		global $wp_version;

		return version_compare( PHP_VERSION, $manifest['requires_php'], '>=' )
			&& version_compare( (string) $wp_version, $manifest['requires'], '>=' );
	}

	/**
	 * Use a deterministic per-site bucket for gradual auto-update rollout.
	 *
	 * @param array<string,mixed> $manifest Release manifest.
	 * @return bool
	 */
	public static function site_is_in_rollout( array $manifest ) {
		$percent = (int) $manifest['rollout_percent'];

		if ( 100 <= $percent ) {
			return true;
		}

		if ( 0 >= $percent ) {
			return false;
		}

		$hex    = substr( hash( 'sha256', home_url( '/' ) . '|' . $manifest['version'] ), 0, 8 );
		$bucket = hexdec( $hex ) % 100;

		return $bucket < $percent;
	}

	/**
	 * Normalize a manifest for WordPress core.
	 *
	 * @param array<string,mixed> $manifest Release manifest.
	 * @return array<string,mixed>
	 */
	private static function update_array( array $manifest ) {
		return array(
			'id'           => GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL,
			'slug'         => self::SLUG,
			'plugin'       => self::plugin_basename(),
			'new_version'  => $manifest['version'],
			'url'          => isset( $manifest['homepage'] ) ? esc_url_raw( $manifest['homepage'] ) : '',
			'package'      => $manifest['download_url'],
			'requires'     => $manifest['requires'],
			'tested'       => isset( $manifest['tested'] ) ? sanitize_text_field( $manifest['tested'] ) : '',
			'requires_php' => $manifest['requires_php'],
			'autoupdate'   => $manifest['auto_update'],
		);
	}

	/**
	 * Read the committed public trust anchor.
	 *
	 * @return array{key_id:string,public_key_base64:string}|false
	 */
	private static function public_key_config() {
		$path   = GEEK_CUBE_STUDIO_PLUGIN_DIR . 'config/release-public-key.php';
		$config = is_file( $path ) ? require $path : null;

		if ( ! is_array( $config ) ) {
			return false;
		}

		$key_id     = isset( $config['key_id'] ) ? trim( (string) $config['key_id'] ) : '';
		$public_b64 = isset( $config['public_key_base64'] ) ? trim( (string) $config['public_key_base64'] ) : '';
		// Base64 is the configured transport encoding for the public key bytes.
		$public = base64_decode( $public_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( '' === $key_id || false === $public || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public ) ) {
			return false;
		}

		return array(
			'key_id'            => $key_id,
			'public_key_base64' => $public_b64,
		);
	}

	/**
	 * Check for an HTTPS URL without accepting malformed input.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_https_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		return 'https' === strtolower( (string) $scheme ) && is_string( $host ) && '' !== $host;
	}

	/**
	 * Get this plugin's installed basename.
	 *
	 * @return string
	 */
	private static function plugin_basename() {
		return plugin_basename( GEEK_CUBE_STUDIO_PLUGIN_FILE );
	}

	/**
	 * Extract a semantic version major number.
	 *
	 * @param string $version Version.
	 * @return int
	 */
	private static function version_major( $version ) {
		$parts = explode( '.', ltrim( (string) $version, 'v' ) );

		return isset( $parts[0] ) ? (int) $parts[0] : 0;
	}
}
