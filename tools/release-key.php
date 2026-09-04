<?php
/**
 * Generate and verify the Geek Cube Studio Ed25519 release identity.
 *
 * @package Geek_Cube_Studio
 */

$root_dir = realpath( __DIR__ . '/..' );

if ( false === $root_dir ) {
	fwrite( STDERR, "Unable to resolve the project root.\n" );
	exit( 1 );
}

require_once __DIR__ . '/release-signing.php';

/**
 * Read one --name=value option.
 *
 * @param string[] $args Arguments.
 * @param string   $name Option name.
 * @return string
 */
function geek_cube_studio_release_key_option( array $args, $name ) {
	$prefix = '--' . $name . '=';

	foreach ( $args as $argument ) {
		if ( 0 === strpos( $argument, $prefix ) ) {
			return trim( substr( $argument, strlen( $prefix ) ) );
		}
	}

	return '';
}

/**
 * Normalize a path for containment checks.
 *
 * @param string $path Filesystem path.
 * @return string
 */
function geek_cube_studio_release_key_normalize_path( $path ) {
	$path = str_replace( '\\', '/', trim( (string) $path ) );

	if ( preg_match( '#^[A-Za-z]:/#', $path ) ) {
		return strtolower( rtrim( $path, '/' ) );
	}

	$resolved = realpath( $path );

	return false !== $resolved ? strtolower( str_replace( '\\', '/', $resolved ) ) : strtolower( $path );
}

/**
 * Determine whether a path lives inside another path.
 *
 * @param string $path Candidate path.
 * @param string $root Root path.
 * @return bool
 */
function geek_cube_studio_release_key_is_within( $path, $root ) {
	$path = geek_cube_studio_release_key_normalize_path( $path );
	$root = geek_cube_studio_release_key_normalize_path( $root );

	return $path === $root || 0 === strpos( $path, $root . '/' );
}

/**
 * Atomically write a new file without overwriting an existing secret.
 *
 * @param string $path Contents destination.
 * @param string $contents File contents.
 * @return void
 * @throws RuntimeException When writing fails.
 */
function geek_cube_studio_release_key_write_new_file( $path, $contents ) {
	if ( file_exists( $path ) ) {
		throw new RuntimeException( "Refusing to overwrite existing file: {$path}" );
	}

	$directory = dirname( $path );

	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( "Unable to create private key directory: {$directory}" );
	}

	$temporary = $path . '.tmp-' . bin2hex( random_bytes( 6 ) );

	if ( false === file_put_contents( $temporary, $contents, LOCK_EX ) ) {
		throw new RuntimeException( 'Unable to write the private release key.' );
	}

	chmod( $temporary, 0600 );

	if ( ! rename( $temporary, $path ) ) {
		@unlink( $temporary );
		throw new RuntimeException( 'Unable to finalize the private release key.' );
	}
}

/**
 * Write the public PHP trust anchor tracked by Git.
 *
 * @param string $root_dir Project root.
 * @param string $key_id Key identifier.
 * @param string $public_key_base64 Public key.
 * @return void
 * @throws RuntimeException When writing fails.
 */
function geek_cube_studio_release_key_write_public_config( $root_dir, $key_id, $public_key_base64 ) {
	$path = rtrim( $root_dir, '/\\' ) . '/config/release-public-key.php';
	$body = "<?php\n/**\n * Public trust anchor used to verify Geek Cube Studio update manifests.\n *\n * @package Geek_Cube_Studio\n */\n\nreturn array(\n\t'key_id'            => '" . addslashes( $key_id ) . "',\n\t'public_key_base64' => '" . addslashes( $public_key_base64 ) . "',\n);\n";

	if ( false === file_put_contents( $path, $body, LOCK_EX ) ) {
		throw new RuntimeException( 'Unable to write the public release configuration.' );
	}
}

/**
 * Print command usage.
 *
 * @return void
 */
function geek_cube_studio_release_key_help() {
	echo "Usage:\n";
	echo "  php tools/release-key.php generate --private-key-file=<absolute-path>\n";
	echo "  php tools/release-key.php verify --private-key-file=<absolute-path>\n";
}

$arguments = array_slice( $argv, 1 );
$command   = isset( $arguments[0] ) ? strtolower( trim( $arguments[0] ) ) : '';
$key_file  = geek_cube_studio_release_key_option( $arguments, 'private-key-file' );

try {
	if ( ! in_array( $command, array( 'generate', 'verify' ), true ) || '' === $key_file ) {
		geek_cube_studio_release_key_help();
		exit( 1 );
	}

	if ( ! preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', $key_file ) ) {
		throw new RuntimeException( 'The private key destination must be an absolute path.' );
	}

	if ( geek_cube_studio_release_key_is_within( $key_file, $root_dir ) ) {
		throw new RuntimeException( 'The private release key must be stored outside the Git project.' );
	}

	if ( 'generate' === $command ) {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			throw new RuntimeException( 'Libsodium Ed25519 support is required.' );
		}

		$config_path   = $root_dir . '/config/release-public-key.php';
		$current       = is_file( $config_path ) ? require $config_path : array();
		$current_key   = is_array( $current ) && isset( $current['public_key_base64'] ) ? trim( (string) $current['public_key_base64'] ) : '';
		$key_id        = is_array( $current ) && isset( $current['key_id'] ) ? trim( (string) $current['key_id'] ) : '';

		if ( '' !== $current_key ) {
			throw new RuntimeException( 'A public release identity is already configured. Key rotation requires an explicit migration.' );
		}

		if ( '' === $key_id ) {
			throw new RuntimeException( 'The public release key ID is missing.' );
		}

		$keypair    = sodium_crypto_sign_keypair();
		$secret_key = sodium_crypto_sign_secretkey( $keypair );
		$public_key = sodium_crypto_sign_publickey( $keypair );
		$document   = array(
			'schema_version'      => 1,
			'algorithm'           => GEEK_CUBE_STUDIO_RELEASE_SIGNATURE_ALGORITHM,
			'key_id'              => $key_id,
			'created_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'public_key_base64'   => base64_encode( $public_key ),
			'private_key_base64'  => base64_encode( $secret_key ),
		);
		$json       = json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Unable to encode the release key document.' );
		}

		geek_cube_studio_release_key_write_new_file( $key_file, $json . PHP_EOL );
		geek_cube_studio_release_key_write_public_config( $root_dir, $key_id, base64_encode( $public_key ) );

		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $secret_key );
		}

		echo "Release identity created.\n";
		echo "Private key: {$key_file}\n";
		echo 'Public fingerprint: ' . hash( 'sha256', $public_key ) . PHP_EOL;
		echo "Import the private JSON file into the vault, then verify the vault export before the first release.\n";
		exit( 0 );
	}

	putenv( 'GEEK_CUBE_UPDATE_ED25519_PRIVATE_KEY_FILE=' . $key_file );
	geek_cube_studio_release_assert_signing_identity();
	echo "Release private key matches the committed public identity.\n";
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, 'Release key error: ' . $exception->getMessage() . PHP_EOL );
	exit( 1 );
}
