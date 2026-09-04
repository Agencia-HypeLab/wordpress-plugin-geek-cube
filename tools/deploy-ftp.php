<?php
/**
 * Publish Geek Cube Studio release artifacts over FTP, FTPS or SFTP.
 *
 * @package Geek_Cube_Studio
 */

$root_dir = realpath( __DIR__ . '/..' );

if ( false === $root_dir ) {
	fwrite( STDERR, "Unable to resolve the project root.\n" );
	exit( 1 );
}

require_once __DIR__ . '/release-signing.php';

$autoload = $root_dir . '/vendor/autoload.php';

if ( is_file( $autoload ) ) {
	require_once $autoload;
}

$project = require $root_dir . '/config/project.php';

/**
 * Read and validate the private transfer configuration.
 *
 * @param string $path JSON configuration path.
 * @return array<string,mixed>
 * @throws RuntimeException When the configuration is invalid.
 */
function geek_cube_studio_deploy_read_config( $path ) {
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		throw new RuntimeException( "FTP configuration not found at {$path}. Copy tools/.ftp-credentials.example.json to tools/.ftp-credentials.json." );
	}

	$contents = file_get_contents( $path );
	$config   = is_string( $contents ) ? json_decode( $contents, true ) : null;

	if ( ! is_array( $config ) ) {
		throw new RuntimeException( 'FTP configuration must contain valid JSON.' );
	}

	foreach ( array( 'host', 'username', 'password', 'protocol', 'remote_dir', 'public_base_url' ) as $field ) {
		if ( ! isset( $config[ $field ] ) || '' === trim( (string) $config[ $field ] ) ) {
			throw new RuntimeException( "FTP configuration field '{$field}' is required." );
		}
	}

	$config['protocol'] = strtolower( trim( (string) $config['protocol'] ) );

	if ( ! in_array( $config['protocol'], array( 'ftp', 'ftps', 'sftp' ), true ) ) {
		throw new RuntimeException( 'FTP protocol must be ftp, ftps or sftp.' );
	}

	if ( false !== strpos( (string) $config['host'], '://' ) ) {
		throw new RuntimeException( 'FTP host must not include a URL scheme.' );
	}

	if ( 0 !== strpos( strtolower( (string) $config['public_base_url'] ), 'https://' ) ) {
		throw new RuntimeException( 'public_base_url must use HTTPS.' );
	}

	$config['port']       = isset( $config['port'] ) ? max( 0, (int) $config['port'] ) : 0;
	$config['verify_ssl'] = ! isset( $config['verify_ssl'] ) || (bool) $config['verify_ssl'];
	$config['passive']    = ! isset( $config['passive'] ) || (bool) $config['passive'];

	if ( 'ftps' === $config['protocol'] && ! $config['verify_ssl'] ) {
		throw new RuntimeException( 'FTPS certificate verification cannot be disabled.' );
	}

	if ( ! empty( $config['ca_bundle'] ) && ! is_readable( (string) $config['ca_bundle'] ) ) {
		throw new RuntimeException( 'The configured ca_bundle is not readable.' );
	}

	if ( 'sftp' === $config['protocol'] && ( empty( $config['known_hosts'] ) || ! is_readable( (string) $config['known_hosts'] ) ) ) {
		throw new RuntimeException( 'SFTP requires a readable known_hosts file for server identity verification.' );
	}

	return $config;
}

/**
 * Resolve a trusted CA bundle for PHP cURL.
 *
 * @param array<string,mixed> $config Transfer configuration.
 * @return string
 * @throws RuntimeException When no bundle can be found.
 */
function geek_cube_studio_deploy_ca_bundle( array $config ) {
	if ( ! empty( $config['ca_bundle'] ) ) {
		return (string) $config['ca_bundle'];
	}

	if ( class_exists( 'Composer\\CaBundle\\CaBundle' ) ) {
		$path = Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();

		if ( is_string( $path ) && is_readable( $path ) ) {
			return $path;
		}
	}

	foreach ( array( ini_get( 'curl.cainfo' ), ini_get( 'openssl.cafile' ) ) as $path ) {
		if ( is_string( $path ) && '' !== $path && is_readable( $path ) ) {
			return $path;
		}
	}

	throw new RuntimeException( 'No trusted CA bundle is available. Run composer install or configure ca_bundle.' );
}

/**
 * Build the authenticated transfer directory URL without credentials.
 *
 * @param array<string,mixed> $config Transfer configuration.
 * @return string
 */
function geek_cube_studio_deploy_transfer_url( array $config ) {
	$host = trim( (string) $config['host'], "/\\ \t\n\r\0\x0B" );
	$port = ! empty( $config['port'] ) ? ':' . (int) $config['port'] : '';
	$path = trim( str_replace( '\\', '/', (string) $config['remote_dir'] ), '/' );
	$path = implode( '/', array_map( 'rawurlencode', array_filter( explode( '/', $path ), 'strlen' ) ) );
	$scheme = 'ftps' === $config['protocol'] ? 'ftp' : $config['protocol'];

	return $scheme . '://' . $host . $port . '/' . ( '' !== $path ? $path . '/' : '' );
}

/**
 * Apply shared secure cURL transfer options.
 *
 * @param CurlHandle          $handle cURL handle.
 * @param array<string,mixed> $config Transfer configuration.
 * @return void
 */
function geek_cube_studio_deploy_configure_curl( $handle, array $config ) {
	curl_setopt( $handle, CURLOPT_USERPWD, (string) $config['username'] . ':' . (string) $config['password'] );
	curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 20 );
	curl_setopt( $handle, CURLOPT_TIMEOUT, 180 );
	curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, (bool) $config['verify_ssl'] );
	curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, $config['verify_ssl'] ? 2 : 0 );

	if ( 'ftp' === $config['protocol'] || 'ftps' === $config['protocol'] ) {
		curl_setopt( $handle, CURLOPT_FTP_USE_EPSV, (bool) $config['passive'] );
	}

	if ( 'ftps' === $config['protocol'] ) {
		curl_setopt( $handle, CURLOPT_USE_SSL, CURLUSESSL_ALL );
		curl_setopt( $handle, CURLOPT_CAINFO, geek_cube_studio_deploy_ca_bundle( $config ) );
	}

	if ( 'sftp' === $config['protocol'] ) {
		curl_setopt( $handle, CURLOPT_SSH_KNOWNHOSTS, (string) $config['known_hosts'] );
	}
}

/**
 * Confirm that the configured remote directory is accessible.
 *
 * @param array<string,mixed> $config Transfer configuration.
 * @return void
 * @throws RuntimeException When the connection fails.
 */
function geek_cube_studio_deploy_test_connection( array $config ) {
	$handle = curl_init( geek_cube_studio_deploy_transfer_url( $config ) );

	if ( false === $handle ) {
		throw new RuntimeException( 'Unable to initialize cURL.' );
	}

	geek_cube_studio_deploy_configure_curl( $handle, $config );
	curl_setopt( $handle, CURLOPT_DIRLISTONLY, true );
	$result = curl_exec( $handle );
	$error  = curl_error( $handle );
	curl_close( $handle );

	if ( false === $result ) {
		throw new RuntimeException( 'FTP preflight failed: ' . $error );
	}
}

/**
 * Upload one file.
 *
 * @param array<string,mixed> $config Transfer configuration.
 * @param string              $local_path Local file.
 * @param string              $remote_name Remote filename.
 * @return void
 * @throws RuntimeException When the upload fails.
 */
function geek_cube_studio_deploy_upload( array $config, $local_path, $remote_name ) {
	if ( ! is_file( $local_path ) || ! is_readable( $local_path ) ) {
		throw new RuntimeException( "Release artifact is missing: {$local_path}" );
	}

	$stream = fopen( $local_path, 'rb' );

	if ( false === $stream ) {
		throw new RuntimeException( "Unable to open release artifact: {$local_path}" );
	}

	$handle = curl_init( geek_cube_studio_deploy_transfer_url( $config ) . rawurlencode( $remote_name ) );

	if ( false === $handle ) {
		fclose( $stream );
		throw new RuntimeException( 'Unable to initialize cURL.' );
	}

	geek_cube_studio_deploy_configure_curl( $handle, $config );
	curl_setopt( $handle, CURLOPT_UPLOAD, true );
	curl_setopt( $handle, CURLOPT_INFILE, $stream );
	curl_setopt( $handle, CURLOPT_INFILESIZE, filesize( $local_path ) );

	if ( defined( 'CURLFTP_CREATE_DIR_RETRY' ) && in_array( $config['protocol'], array( 'ftp', 'ftps' ), true ) ) {
		curl_setopt( $handle, CURLOPT_FTP_CREATE_MISSING_DIRS, CURLFTP_CREATE_DIR_RETRY );
	}

	$result = curl_exec( $handle );
	$error  = curl_error( $handle );
	curl_close( $handle );
	fclose( $stream );

	if ( false === $result ) {
		throw new RuntimeException( "Upload failed for {$remote_name}: {$error}" );
	}
}

/**
 * Verify the newly published public manifest through HTTPS.
 *
 * @param array<string,mixed> $config Transfer configuration.
 * @param string              $manifest_name Manifest filename.
 * @param string              $version Expected version.
 * @param string              $sha256 Expected package checksum.
 * @return void
 * @throws RuntimeException When verification fails.
 */
function geek_cube_studio_deploy_verify_public_manifest( array $config, $manifest_name, $version, $sha256 ) {
	$url        = rtrim( (string) $config['public_base_url'], '/' ) . '/' . rawurlencode( $manifest_name );
	$last_error = 'No valid response received.';

	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$separator = false === strpos( $url, '?' ) ? '?' : '&';
		$handle    = curl_init( $url . $separator . 'release_verify=' . rawurlencode( (string) microtime( true ) ) );

		if ( false === $handle ) {
			throw new RuntimeException( 'Unable to initialize public manifest verification.' );
		}

		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 );
		curl_setopt( $handle, CURLOPT_CAINFO, geek_cube_studio_deploy_ca_bundle( $config ) );
		curl_setopt( $handle, CURLOPT_HTTPHEADER, array( 'Accept: application/json', 'Cache-Control: no-cache' ) );
		curl_setopt( $handle, CURLOPT_TIMEOUT, 20 );
		$body   = curl_exec( $handle );
		$error  = curl_error( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );

		$manifest = is_string( $body ) ? json_decode( $body, true ) : null;

		if (
			200 === $status
			&& is_array( $manifest )
			&& isset( $manifest['version'], $manifest['sha256'] )
			&& hash_equals( $version, (string) $manifest['version'] )
			&& hash_equals( strtolower( $sha256 ), strtolower( (string) $manifest['sha256'] ) )
			&& geek_cube_studio_release_verify_manifest( $manifest )
		) {
			return;
		}

		$last_error = '' !== $error ? $error : "HTTP {$status} or stale/invalid manifest";

		if ( $attempt < 3 ) {
			sleep( $attempt );
		}
	}

	throw new RuntimeException( 'Public manifest verification failed: ' . $last_error );
}

try {
	if ( ! function_exists( 'curl_init' ) ) {
		throw new RuntimeException( 'The PHP cURL extension is required for FTP deployment.' );
	}

	$config_path = trim( (string) getenv( 'GEEK_CUBE_FTP_CONFIG' ) );
	$config_path = '' !== $config_path ? $config_path : __DIR__ . '/.ftp-credentials.json';
	$config      = geek_cube_studio_deploy_read_config( $config_path );
	$test_only   = in_array( '--test', array_slice( $argv, 1 ), true );

	geek_cube_studio_deploy_test_connection( $config );

	if ( $test_only ) {
		echo "FTP preflight completed successfully.\n";
		exit( 0 );
	}

	$build_dir    = $root_dir . '/build';
	$slug         = (string) $project['slug'];
	$manifest_name = $slug . '-update.json';
	$endpoint_name = $slug . '-update.php';
	$manifest_path = $build_dir . '/' . $manifest_name;
	$endpoint_path = $build_dir . '/' . $endpoint_name;
	$contents      = is_file( $manifest_path ) ? file_get_contents( $manifest_path ) : false;
	$manifest      = is_string( $contents ) ? json_decode( $contents, true ) : null;

	if ( ! is_array( $manifest ) || ! geek_cube_studio_release_verify_manifest( $manifest ) ) {
		throw new RuntimeException( 'The local release manifest is missing or has an invalid signature.' );
	}

	$version  = isset( $manifest['version'] ) ? (string) $manifest['version'] : '';
	$sha256   = isset( $manifest['sha256'] ) ? strtolower( (string) $manifest['sha256'] ) : '';
	$zip_name = $slug . '-' . $version . '.zip';
	$zip_path = $build_dir . '/' . $zip_name;
	$zip_hash = is_file( $zip_path ) ? hash_file( 'sha256', $zip_path ) : false;

	if ( ! is_string( $zip_hash ) || ! hash_equals( $sha256, strtolower( $zip_hash ) ) ) {
		throw new RuntimeException( 'The local release package does not match the signed manifest checksum.' );
	}

	foreach (
		array(
			$zip_name      => $zip_path,
			$manifest_name => $manifest_path,
			$endpoint_name => $endpoint_path,
		) as $remote_name => $local_path
	) {
		geek_cube_studio_deploy_upload( $config, $local_path, $remote_name );
		echo "Uploaded {$remote_name}.\n";
	}

	geek_cube_studio_deploy_verify_public_manifest( $config, $endpoint_name, $version, $sha256 );
	echo "Published signed Geek Cube Studio release {$version}.\n";
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, 'Deploy error: ' . $exception->getMessage() . PHP_EOL );
	exit( 1 );
}
