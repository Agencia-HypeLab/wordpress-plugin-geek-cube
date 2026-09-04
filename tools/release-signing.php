<?php
/**
 * Ed25519 helpers shared by Geek Cube Studio release tooling.
 *
 * @package Geek_Cube_Studio
 */

const GEEK_CUBE_STUDIO_RELEASE_SIGNATURE_ALGORITHM = 'Ed25519';
const GEEK_CUBE_STUDIO_RELEASE_SIGNED_FIELDS       = array(
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
 * Read the public release identity committed with the plugin.
 *
 * @return array{key_id:string,public_key_base64:string}
 * @throws RuntimeException When the configuration is unavailable.
 */
function geek_cube_studio_release_public_config() {
	$path   = dirname( __DIR__ ) . '/config/release-public-key.php';
	$config = is_file( $path ) ? require $path : null;

	if ( ! is_array( $config ) ) {
		throw new RuntimeException( 'Release public key configuration is missing.' );
	}

	$key_id     = isset( $config['key_id'] ) ? trim( (string) $config['key_id'] ) : '';
	$public_b64 = isset( $config['public_key_base64'] ) ? trim( (string) $config['public_key_base64'] ) : '';
	$public_key = base64_decode( $public_b64, true );

	if ( '' === $key_id || false === $public_key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
		throw new RuntimeException( 'Run tools/release-key.php generate before building a release.' );
	}

	return array(
		'key_id'            => $key_id,
		'public_key_base64' => $public_b64,
	);
}

/**
 * Produce the canonical JSON envelope covered by the release signature.
 *
 * @param array<string,mixed> $manifest Release manifest.
 * @return string
 * @throws RuntimeException When a required field is missing or cannot encode.
 */
function geek_cube_studio_release_canonical_json( array $manifest ) {
	$signed = array();

	foreach ( GEEK_CUBE_STUDIO_RELEASE_SIGNED_FIELDS as $field ) {
		if ( ! array_key_exists( $field, $manifest ) ) {
			throw new RuntimeException( "Missing signed release field: {$field}" );
		}

		$signed[ $field ] = $manifest[ $field ];
	}

	ksort( $signed, SORT_STRING );
	$json = json_encode( $signed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	if ( ! is_string( $json ) ) {
		throw new RuntimeException( 'Unable to encode canonical release JSON.' );
	}

	return $json;
}

/**
 * Resolve the private release note from the environment or ignored local config.
 *
 * @return string
 * @throws RuntimeException When no private note is configured.
 */
function geek_cube_studio_release_private_key_path() {
	$path = trim( (string) getenv( 'GEEK_CUBE_UPDATE_ED25519_PRIVATE_KEY_FILE' ) );

	if ( '' === $path ) {
		$config_path = __DIR__ . '/.release-credentials.json';
		$contents    = is_file( $config_path ) ? file_get_contents( $config_path ) : false;
		$config      = is_string( $contents ) ? json_decode( $contents, true ) : null;
		$path        = is_array( $config ) && isset( $config['release_note_file'] ) ? trim( (string) $config['release_note_file'] ) : '';
	}

	if ( '' === $path ) {
		throw new RuntimeException( 'Configure tools/.release-credentials.json or GEEK_CUBE_UPDATE_ED25519_PRIVATE_KEY_FILE.' );
	}

	return $path;
}

/**
 * Load and validate the configured release private key.
 *
 * @return string Libsodium 64-byte Ed25519 secret key.
 * @throws RuntimeException When the private key is absent or invalid.
 */
function geek_cube_studio_release_load_private_key() {
	if ( ! function_exists( 'sodium_crypto_sign_publickey_from_secretkey' ) ) {
		throw new RuntimeException( 'Libsodium Ed25519 support is required.' );
	}

	$path = geek_cube_studio_release_private_key_path();

	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		throw new RuntimeException( 'The configured private release note is missing or unreadable.' );
	}

	$resolved_path = realpath( $path );
	$project_root  = realpath( dirname( __DIR__ ) );

	if ( false === $resolved_path || false === $project_root ) {
		throw new RuntimeException( 'Unable to resolve the private release key path.' );
	}

	$normalized_path = strtolower( str_replace( '\\', '/', $resolved_path ) );
	$normalized_root = rtrim( strtolower( str_replace( '\\', '/', $project_root ) ), '/' );

	if ( $normalized_path === $normalized_root || 0 === strpos( $normalized_path, $normalized_root . '/' ) ) {
		throw new RuntimeException( 'The private release key must be stored outside the Git project.' );
	}

	$contents = file_get_contents( $resolved_path );
	$data     = is_string( $contents ) ? json_decode( $contents, true ) : null;

	if ( ! is_array( $data ) && is_string( $contents ) ) {
		$first_heading  = '=== 1. CHAVE PRIVADA DE ASSINATURA — Ed25519 — SECRETO ===';
		$second_heading = '=== 2. CHAVE PRIVADA DE RECUPERAÇÃO — X25519 — SECRETO ===';
		$start          = strpos( $contents, $first_heading );
		$end            = false === $start ? false : strpos( $contents, $second_heading, $start + strlen( $first_heading ) );

		if ( false !== $start && false !== $end ) {
			$json = trim( substr( $contents, $start + strlen( $first_heading ), $end - $start - strlen( $first_heading ) ) );
			$data = json_decode( $json, true );
		}
	}

	if ( ! is_array( $data ) || ( empty( $data['private_key_base64'] ) && empty( $data['private_key'] ) ) ) {
		throw new RuntimeException( 'The release private key file is not a valid Geek Cube Studio key document.' );
	}

	$private_value = ! empty( $data['private_key_base64'] ) ? (string) $data['private_key_base64'] : (string) $data['private_key'];
	$private_value = 0 === strpos( $private_value, 'base64:' ) ? substr( $private_value, 7 ) : $private_value;
	$secret_key    = base64_decode( $private_value, true );

	if ( false === $secret_key ) {
		throw new RuntimeException( 'The release private key is not valid Base64.' );
	}

	if ( SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $secret_key ) ) {
		$keypair   = sodium_crypto_sign_seed_keypair( $secret_key );
		$secret_key = sodium_crypto_sign_secretkey( $keypair );
	}

	if ( SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret_key ) ) {
		throw new RuntimeException( 'The Ed25519 private key must contain a 32-byte seed or 64-byte secret key.' );
	}

	$public_config = geek_cube_studio_release_public_config();
	$derived       = sodium_crypto_sign_publickey_from_secretkey( $secret_key );
	$expected      = base64_decode( $public_config['public_key_base64'], true );
	$key_id        = isset( $data['key_id'] ) ? trim( (string) $data['key_id'] ) : '';

	if ( $key_id !== $public_config['key_id'] || false === $expected || ! hash_equals( $expected, $derived ) ) {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $secret_key );
		}

		throw new RuntimeException( 'The private release key does not match the public identity committed in the plugin.' );
	}

	return $secret_key;
}

/**
 * Assert that the build environment owns the configured signing identity.
 *
 * @return void
 */
function geek_cube_studio_release_assert_signing_identity() {
	$secret_key = geek_cube_studio_release_load_private_key();

	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $secret_key );
	}
}

/**
 * Sign a release manifest.
 *
 * @param array<string,mixed> $manifest Unsigned manifest.
 * @return array<string,mixed>
 */
function geek_cube_studio_release_sign_manifest( array $manifest ) {
	$public_config = geek_cube_studio_release_public_config();
	$secret_key    = geek_cube_studio_release_load_private_key();
	$canonical     = geek_cube_studio_release_canonical_json( $manifest );
	$signature     = sodium_crypto_sign_detached( $canonical, $secret_key );

	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $secret_key );
	}

	$manifest['signature_alg']    = GEEK_CUBE_STUDIO_RELEASE_SIGNATURE_ALGORITHM;
	$manifest['signature_key_id'] = $public_config['key_id'];
	$manifest['signature']        = base64_encode( $signature );

	return $manifest;
}

/**
 * Verify a release manifest with the committed public trust anchor.
 *
 * @param array<string,mixed> $manifest Signed manifest.
 * @return bool
 */
function geek_cube_studio_release_verify_manifest( array $manifest ) {
	try {
		$public_config = geek_cube_studio_release_public_config();
		$canonical     = geek_cube_studio_release_canonical_json( $manifest );
	} catch ( RuntimeException $exception ) {
		return false;
	}

	if (
		GEEK_CUBE_STUDIO_RELEASE_SIGNATURE_ALGORITHM !== ( isset( $manifest['signature_alg'] ) ? $manifest['signature_alg'] : '' )
		|| $public_config['key_id'] !== ( isset( $manifest['signature_key_id'] ) ? $manifest['signature_key_id'] : '' )
		|| empty( $manifest['signature'] )
	) {
		return false;
	}

	$signature = base64_decode( (string) $manifest['signature'], true );
	$public    = base64_decode( $public_config['public_key_base64'], true );

	return false !== $signature
		&& false !== $public
		&& SODIUM_CRYPTO_SIGN_BYTES === strlen( $signature )
		&& SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $public )
		&& sodium_crypto_sign_verify_detached( $signature, $canonical, $public );
}
