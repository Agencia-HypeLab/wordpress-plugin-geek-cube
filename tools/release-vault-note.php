<?php
/**
 * Generate and verify the complete Bitwarden release recovery note.
 *
 * @package Geek_Cube_Studio
 */

require_once __DIR__ . '/release-signing.php';

const GEEK_CUBE_STUDIO_VAULT_NOTE_SCHEMA_VERSION = 1;
const GEEK_CUBE_STUDIO_VAULT_NOTE_PROJECT        = 'wordpress-plugin-geek-cube';
const GEEK_CUBE_STUDIO_VAULT_NOTE_ENVIRONMENT    = 'Produção';
const GEEK_CUBE_STUDIO_VAULT_NOTE_PURPOSE        = 'geek-cube-studio-release-signing-key-recovery';
const GEEK_CUBE_STUDIO_VAULT_NOTE_SEAL_ALGORITHM = 'libsodium-sealed-box-x25519';
const GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_ONE    = '=== 1. CHAVE PRIVADA DE ASSINATURA — Ed25519 — SECRETO ===';
const GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_TWO    = '=== 2. CHAVE PRIVADA DE RECUPERAÇÃO — X25519 — SECRETO ===';
const GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_THREE  = '=== 3. BACKUP SELADO DA CHAVE DE RELEASE ===';

/**
 * Normalize a path, including a destination that does not exist yet.
 *
 * @param string $path Filesystem path.
 * @return string
 */
function geek_cube_studio_vault_note_normalize_path( $path ) {
	$path     = trim( (string) $path );
	$resolved = '' !== $path ? realpath( $path ) : false;

	if ( false !== $resolved ) {
		$path = $resolved;
	} elseif ( '' !== $path ) {
		$parent = realpath( dirname( $path ) );

		if ( false !== $parent ) {
			$path = rtrim( $parent, '/\\' ) . DIRECTORY_SEPARATOR . basename( $path );
		}
	}

	$path = str_replace( '\\', '/', $path );

	if ( '\\' === DIRECTORY_SEPARATOR ) {
		$path = strtolower( $path );
	}

	return rtrim( $path, '/' );
}

/**
 * Refuse secret note files inside the Git project.
 *
 * @param string $path Secret input or output path.
 * @return void
 * @throws RuntimeException When the path is unsafe.
 */
function geek_cube_studio_vault_note_assert_safe_path( $path ) {
	$path                = trim( (string) $path );
	$is_windows_absolute = 3 <= strlen( $path )
		&& ctype_alpha( $path[0] )
		&& ':' === $path[1]
		&& ( '\\' === $path[2] || '/' === $path[2] );
	$is_unix_absolute    = '' !== $path && '/' === $path[0];

	if ( ! $is_windows_absolute && ! $is_unix_absolute ) {
		throw new RuntimeException( 'Release vault note paths must be absolute.' );
	}

	$project = geek_cube_studio_vault_note_normalize_path( dirname( __DIR__ ) );
	$path    = geek_cube_studio_vault_note_normalize_path( $path );

	if ( '' === $path || $path === $project || 0 === strpos( $path, $project . '/' ) ) {
		throw new RuntimeException( 'Release vault notes must be stored outside the Git project.' );
	}
}

/**
 * Encode one JSON section.
 *
 * @param array<string,mixed> $data Section data.
 * @return string
 * @throws RuntimeException When encoding fails.
 */
function geek_cube_studio_vault_note_json( array $data ) {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	if ( ! is_string( $json ) ) {
		throw new RuntimeException( 'Unable to encode the release vault note.' );
	}

	return $json;
}

/**
 * Decode and validate an Ed25519 signing document.
 *
 * @param array<string,mixed>      $data Signing document.
 * @param array<string,string>|null $expected Optional expected public identity.
 * @return array<string,string>
 * @throws RuntimeException When the document is invalid.
 */
function geek_cube_studio_vault_note_signing_document( array $data, $expected = null ) {
	$private_value = '';

	if ( isset( $data['private_key'] ) ) {
		$private_value = (string) $data['private_key'];
		$private_value = 0 === strpos( $private_value, 'base64:' ) ? substr( $private_value, 7 ) : $private_value;
	} elseif ( isset( $data['private_key_base64'] ) ) {
		$private_value = (string) $data['private_key_base64'];
	}

	$secret_key = base64_decode( $private_value, true );
	$public_key = base64_decode( isset( $data['public_key_base64'] ) ? (string) $data['public_key_base64'] : '', true );

	if ( false !== $secret_key && SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $secret_key ) ) {
		$keypair    = sodium_crypto_sign_seed_keypair( $secret_key );
		$secret_key = sodium_crypto_sign_secretkey( $keypair );
	}

	if (
		GEEK_CUBE_STUDIO_VAULT_NOTE_SCHEMA_VERSION !== ( isset( $data['schema_version'] ) ? (int) $data['schema_version'] : 0 )
		|| GEEK_CUBE_STUDIO_RELEASE_SIGNATURE_ALGORITHM !== ( isset( $data['algorithm'] ) ? (string) $data['algorithm'] : '' )
		|| false === $secret_key
		|| false === $public_key
		|| SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret_key )
		|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key )
		|| ! hash_equals( $public_key, sodium_crypto_sign_publickey_from_secretkey( $secret_key ) )
	) {
		throw new RuntimeException( 'The Ed25519 signing key document is invalid.' );
	}

	$key_id = isset( $data['key_id'] ) ? trim( (string) $data['key_id'] ) : '';

	if ( '' === $key_id ) {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $secret_key );
		}

		throw new RuntimeException( 'The signing key ID is missing.' );
	}

	if (
		is_array( $expected )
		&& (
			! isset( $expected['key_id'], $expected['public_key_base64'] )
			|| ! hash_equals( (string) $expected['key_id'], $key_id )
			|| ! hash_equals( (string) $expected['public_key_base64'], base64_encode( $public_key ) )
		)
	) {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $secret_key );
		}

		throw new RuntimeException( 'The signing key does not match the public identity committed in the plugin.' );
	}

	return array(
		'key_id'     => $key_id,
		'secret_key' => $secret_key,
		'public_key' => $public_key,
	);
}

/**
 * Read the existing signing key created by release-key.php.
 *
 * @param string $path Private signing key path.
 * @return array<string,string>
 * @throws RuntimeException When reading fails.
 */
function geek_cube_studio_vault_note_load_signing_key( $path ) {
	geek_cube_studio_vault_note_assert_safe_path( $path );

	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		throw new RuntimeException( 'The private signing key file is missing or unreadable.' );
	}

	$contents = file_get_contents( $path );
	$data     = is_string( $contents ) ? json_decode( $contents, true ) : null;

	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'The private signing key file must contain valid JSON.' );
	}

	return geek_cube_studio_vault_note_signing_document( $data, geek_cube_studio_release_public_config() );
}

/**
 * Generate all three note sections and prove recovery before returning.
 *
 * @param array<string,string> $signing Validated signing identity.
 * @param string               $created_at Creation timestamp.
 * @return string
 * @throws RuntimeException When recovery fails.
 */
function geek_cube_studio_vault_note_build( array $signing, $created_at = '' ) {
	if ( ! function_exists( 'sodium_crypto_box_seal_open' ) ) {
		throw new RuntimeException( 'Libsodium sealed-box support is required.' );
	}

	$created_at     = '' !== $created_at ? $created_at : gmdate( 'c' );
	$recovery_pair = sodium_crypto_box_keypair();
	$recovery_key  = sodium_crypto_box_secretkey( $recovery_pair );
	$recovery_pub  = sodium_crypto_box_publickey( $recovery_pair );
	$recovery_id   = 'sha256:' . hash( 'sha256', $recovery_pub );
	$release_id    = 'sha256:' . hash( 'sha256', $signing['public_key'] );
	$ciphertext    = sodium_crypto_box_seal( $signing['secret_key'], $recovery_pub );
	$restored      = sodium_crypto_box_seal_open( $ciphertext, $recovery_pair );

	if ( false === $restored || ! hash_equals( $signing['secret_key'], $restored ) ) {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $recovery_key );
		}

		throw new RuntimeException( 'The generated sealed backup failed its recovery test.' );
	}

	$signing_document = array(
		'schema_version'   => GEEK_CUBE_STUDIO_VAULT_NOTE_SCHEMA_VERSION,
		'algorithm'        => GEEK_CUBE_STUDIO_RELEASE_SIGNATURE_ALGORITHM,
		'key_id'           => $signing['key_id'],
		'public_key_base64' => base64_encode( $signing['public_key'] ),
		'private_key'      => 'base64:' . base64_encode( $signing['secret_key'] ),
	);
	$recovery_document = array(
		'schema_version'     => GEEK_CUBE_STUDIO_VAULT_NOTE_SCHEMA_VERSION,
		'purpose'            => GEEK_CUBE_STUDIO_VAULT_NOTE_PURPOSE,
		'algorithm'          => 'X25519',
		'key_id'             => $recovery_id,
		'created_at'         => $created_at,
		'public_key_base64'  => base64_encode( $recovery_pub ),
		'private_key_base64' => base64_encode( $recovery_key ),
	);
	$sealed_document   = array(
		'schema_version'             => GEEK_CUBE_STUDIO_VAULT_NOTE_SCHEMA_VERSION,
		'algorithm'                  => GEEK_CUBE_STUDIO_VAULT_NOTE_SEAL_ALGORITHM,
		'release_signature_key_id'   => $signing['key_id'],
		'release_public_key_base64'  => base64_encode( $signing['public_key'] ),
		'release_public_fingerprint' => $release_id,
		'recovery_key_id'            => $recovery_id,
		'created_at'                 => $created_at,
		'ciphertext_base64'          => base64_encode( $ciphertext ),
		'ciphertext_sha256'          => hash( 'sha256', $ciphertext ),
	);

	$text  = 'Projeto: ' . GEEK_CUBE_STUDIO_VAULT_NOTE_PROJECT . PHP_EOL;
	$text .= 'Ambiente: ' . GEEK_CUBE_STUDIO_VAULT_NOTE_ENVIRONMENT . PHP_EOL;
	$text .= 'Finalidade: assinatura e recuperação das releases do plugin WordPress' . PHP_EOL;
	$text .= 'Key ID de assinatura: ' . $signing['key_id'] . PHP_EOL;
	$text .= 'Último teste de recuperação: ' . gmdate( 'Y-m-d' ) . PHP_EOL . PHP_EOL;
	$text .= GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_ONE . PHP_EOL;
	$text .= geek_cube_studio_vault_note_json( $signing_document ) . PHP_EOL . PHP_EOL;
	$text .= GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_TWO . PHP_EOL;
	$text .= geek_cube_studio_vault_note_json( $recovery_document ) . PHP_EOL . PHP_EOL;
	$text .= GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_THREE . PHP_EOL;
	$text .= geek_cube_studio_vault_note_json( $sealed_document ) . PHP_EOL;

	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $recovery_key );
		sodium_memzero( $restored );
	}

	return $text;
}

/**
 * Extract a JSON object between note headings.
 *
 * @param string      $text Note text.
 * @param string      $start Start heading.
 * @param string|null $end Optional following heading.
 * @return array<string,mixed>
 * @throws RuntimeException When the section is invalid.
 */
function geek_cube_studio_vault_note_section( $text, $start, $end = null ) {
	$start_position = strpos( $text, $start );

	if ( false === $start_position ) {
		throw new RuntimeException( 'The release vault note is missing a required section.' );
	}

	$start_position += strlen( $start );
	$end_position    = null === $end ? strlen( $text ) : strpos( $text, $end, $start_position );

	if ( false === $end_position ) {
		throw new RuntimeException( 'The release vault note section order is invalid.' );
	}

	$json = trim( substr( $text, $start_position, $end_position - $start_position ) );
	$data = json_decode( $json, true );

	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'A release vault note section contains invalid JSON.' );
	}

	return $data;
}

/**
 * Parse the three note sections.
 *
 * @param string $text Note text.
 * @return array<string,array<string,mixed>>
 */
function geek_cube_studio_vault_note_parse( $text ) {
	return array(
		'signing'  => geek_cube_studio_vault_note_section( $text, GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_ONE, GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_TWO ),
		'recovery' => geek_cube_studio_vault_note_section( $text, GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_TWO, GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_THREE ),
		'sealed'   => geek_cube_studio_vault_note_section( $text, GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_THREE ),
	);
}

/**
 * Verify every key, fingerprint, hash and the actual sealed-box recovery.
 *
 * @param string                   $text Note text.
 * @param array<string,string>|null $expected Optional expected public identity.
 * @return array<string,string>
 * @throws RuntimeException When any validation fails.
 */
function geek_cube_studio_vault_note_verify_text( $text, $expected = null ) {
	if (
		false === strpos( $text, 'Projeto: ' . GEEK_CUBE_STUDIO_VAULT_NOTE_PROJECT )
		|| false === strpos( $text, 'Ambiente: ' . GEEK_CUBE_STUDIO_VAULT_NOTE_ENVIRONMENT )
		|| false === strpos( $text, 'Finalidade: assinatura e recuperação das releases do plugin WordPress' )
	) {
		throw new RuntimeException( 'The release vault note metadata is invalid.' );
	}

	$sections = geek_cube_studio_vault_note_parse( $text );
	$signing  = geek_cube_studio_vault_note_signing_document( $sections['signing'], $expected );
	$recovery = $sections['recovery'];
	$sealed   = $sections['sealed'];
	$private  = base64_decode( isset( $recovery['private_key_base64'] ) ? (string) $recovery['private_key_base64'] : '', true );
	$public   = base64_decode( isset( $recovery['public_key_base64'] ) ? (string) $recovery['public_key_base64'] : '', true );

	try {
		if (
			false === strpos( $text, 'Key ID de assinatura: ' . $signing['key_id'] )
			|| 1 !== preg_match( '/^Último teste de recuperação: \d{4}-\d{2}-\d{2}\r?$/mu', $text )
		) {
			throw new RuntimeException( 'The release vault note identity or recovery-test date is invalid.' );
		}

		if (
			GEEK_CUBE_STUDIO_VAULT_NOTE_SCHEMA_VERSION !== ( isset( $recovery['schema_version'] ) ? (int) $recovery['schema_version'] : 0 )
			|| GEEK_CUBE_STUDIO_VAULT_NOTE_PURPOSE !== ( isset( $recovery['purpose'] ) ? (string) $recovery['purpose'] : '' )
			|| 'X25519' !== ( isset( $recovery['algorithm'] ) ? (string) $recovery['algorithm'] : '' )
			|| false === $private
			|| false === $public
			|| SODIUM_CRYPTO_BOX_SECRETKEYBYTES !== strlen( $private )
			|| SODIUM_CRYPTO_BOX_PUBLICKEYBYTES !== strlen( $public )
			|| ! hash_equals( $public, sodium_crypto_box_publickey_from_secretkey( $private ) )
		) {
			throw new RuntimeException( 'The X25519 recovery key document is invalid.' );
		}

		$recovery_id = 'sha256:' . hash( 'sha256', $public );

		if ( ! hash_equals( $recovery_id, isset( $recovery['key_id'] ) ? (string) $recovery['key_id'] : '' ) ) {
			throw new RuntimeException( 'The X25519 recovery key fingerprint is invalid.' );
		}

		$ciphertext = base64_decode( isset( $sealed['ciphertext_base64'] ) ? (string) $sealed['ciphertext_base64'] : '', true );

		if (
			GEEK_CUBE_STUDIO_VAULT_NOTE_SCHEMA_VERSION !== ( isset( $sealed['schema_version'] ) ? (int) $sealed['schema_version'] : 0 )
			|| GEEK_CUBE_STUDIO_VAULT_NOTE_SEAL_ALGORITHM !== ( isset( $sealed['algorithm'] ) ? (string) $sealed['algorithm'] : '' )
			|| ! hash_equals( $signing['key_id'], isset( $sealed['release_signature_key_id'] ) ? (string) $sealed['release_signature_key_id'] : '' )
			|| ! hash_equals( base64_encode( $signing['public_key'] ), isset( $sealed['release_public_key_base64'] ) ? (string) $sealed['release_public_key_base64'] : '' )
			|| ! hash_equals( 'sha256:' . hash( 'sha256', $signing['public_key'] ), isset( $sealed['release_public_fingerprint'] ) ? (string) $sealed['release_public_fingerprint'] : '' )
			|| ! hash_equals( $recovery_id, isset( $sealed['recovery_key_id'] ) ? (string) $sealed['recovery_key_id'] : '' )
			|| false === $ciphertext
			|| SODIUM_CRYPTO_SIGN_SECRETKEYBYTES + SODIUM_CRYPTO_BOX_SEALBYTES !== strlen( $ciphertext )
			|| ! hash_equals( hash( 'sha256', $ciphertext ), isset( $sealed['ciphertext_sha256'] ) ? (string) $sealed['ciphertext_sha256'] : '' )
		) {
			throw new RuntimeException( 'The sealed release key backup failed integrity validation.' );
		}

		$keypair  = sodium_crypto_box_keypair_from_secretkey_and_publickey( $private, $public );
		$restored = sodium_crypto_box_seal_open( $ciphertext, $keypair );

		if ( false === $restored || ! hash_equals( $signing['secret_key'], $restored ) ) {
			throw new RuntimeException( 'The sealed release key backup could not be recovered.' );
		}

		$restored_public = sodium_crypto_sign_publickey_from_secretkey( $restored );

		if ( ! hash_equals( $signing['public_key'], $restored_public ) ) {
			throw new RuntimeException( 'The recovered release key has the wrong public identity.' );
		}
	} finally {
		if ( function_exists( 'sodium_memzero' ) ) {
			if ( is_string( $private ) ) {
				sodium_memzero( $private );
			}
			if ( isset( $restored ) && is_string( $restored ) ) {
				sodium_memzero( $restored );
			}
			sodium_memzero( $signing['secret_key'] );
		}
	}

	return array(
		'signing_key_id' => $signing['key_id'],
		'recovery_key_id' => $recovery_id,
	);
}

/**
 * Read and verify a note file outside the project.
 *
 * @param string $path Note file.
 * @return array<string,string>
 */
function geek_cube_studio_vault_note_verify_file( $path ) {
	geek_cube_studio_vault_note_assert_safe_path( $path );

	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		throw new RuntimeException( 'The exported release vault note is missing or unreadable.' );
	}

	$text = file_get_contents( $path );

	if ( ! is_string( $text ) ) {
		throw new RuntimeException( 'Unable to read the exported release vault note.' );
	}

	return geek_cube_studio_vault_note_verify_text( $text, geek_cube_studio_release_public_config() );
}

/**
 * Write a new secret note without overwriting another recovery identity.
 *
 * @param string $path Note path.
 * @param string $text Note contents.
 * @return void
 */
function geek_cube_studio_vault_note_write( $path, $text ) {
	geek_cube_studio_vault_note_assert_safe_path( $path );

	if ( file_exists( $path ) ) {
		throw new RuntimeException( 'Refusing to overwrite an existing release vault note.' );
	}

	$directory = dirname( $path );

	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( 'Unable to create the private note directory.' );
	}

	$temporary = $path . '.tmp-' . bin2hex( random_bytes( 6 ) );
	$handle    = fopen( $temporary, 'xb' );

	if ( false === $handle ) {
		throw new RuntimeException( 'Unable to create the private release vault note.' );
	}

	try {
		$written = 0;
		$length  = strlen( $text );

		while ( $written < $length ) {
			$count = fwrite( $handle, substr( $text, $written ) );

			if ( false === $count || 0 === $count ) {
				throw new RuntimeException( 'Unable to write the private release vault note.' );
			}

			$written += $count;
		}

		if ( ! fflush( $handle ) ) {
			throw new RuntimeException( 'Unable to flush the private release vault note.' );
		}
	} finally {
		fclose( $handle );
	}

	chmod( $temporary, 0600 );

	if ( ! rename( $temporary, $path ) ) {
		@unlink( $temporary );
		throw new RuntimeException( 'Unable to finalize the private release vault note.' );
	}
}

/**
 * Read one --name=value option.
 *
 * @param string[] $arguments CLI arguments.
 * @param string   $name Option name.
 * @return string
 */
function geek_cube_studio_vault_note_option( array $arguments, $name ) {
	$prefix = '--' . $name . '=';

	foreach ( $arguments as $argument ) {
		if ( 0 === strpos( $argument, $prefix ) ) {
			return trim( substr( $argument, strlen( $prefix ) ) );
		}
	}

	return '';
}

/**
 * Print CLI usage without secret values.
 *
 * @return void
 */
function geek_cube_studio_vault_note_help() {
	echo "Usage:\n";
	echo "  php tools/release-vault-note.php generate --signing-key-file=<absolute-path> --output=<absolute-path>\n";
	echo "  php tools/release-vault-note.php verify --input=<absolute-path>\n";
}

/**
 * Run the standalone command.
 *
 * @param string[] $arguments CLI arguments.
 * @return int
 */
function geek_cube_studio_vault_note_cli( array $arguments ) {
	$command = isset( $arguments[0] ) ? strtolower( trim( $arguments[0] ) ) : '';

	try {
		if ( 'generate' === $command ) {
			$signing_path = geek_cube_studio_vault_note_option( $arguments, 'signing-key-file' );
			$output_path  = geek_cube_studio_vault_note_option( $arguments, 'output' );

			if ( '' === $signing_path || '' === $output_path ) {
				geek_cube_studio_vault_note_help();
				return 1;
			}

			$signing = geek_cube_studio_vault_note_load_signing_key( $signing_path );

			try {
				$text = geek_cube_studio_vault_note_build( $signing );
				geek_cube_studio_vault_note_write( $output_path, $text );
				geek_cube_studio_vault_note_verify_file( $output_path );
			} finally {
				if ( function_exists( 'sodium_memzero' ) ) {
					sodium_memzero( $signing['secret_key'] );
				}
			}

			echo "Bitwarden release note created and recovery-tested.\n";
			echo "Private note: {$output_path}\n";
			return 0;
		}

		if ( 'verify' === $command ) {
			$input_path = geek_cube_studio_vault_note_option( $arguments, 'input' );

			if ( '' === $input_path ) {
				geek_cube_studio_vault_note_help();
				return 1;
			}

			geek_cube_studio_vault_note_verify_file( $input_path );
			echo "Bitwarden release note and sealed recovery backup verified.\n";
			return 0;
		}

		geek_cube_studio_vault_note_help();
		return 1;
	} catch ( Throwable $throwable ) {
		fwrite( STDERR, 'Release vault note error: ' . $throwable->getMessage() . PHP_EOL );
		return 1;
	}
}

$geek_cube_studio_vault_note_script = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) : false;

if ( false !== $geek_cube_studio_vault_note_script && realpath( __FILE__ ) === $geek_cube_studio_vault_note_script ) {
	exit( geek_cube_studio_vault_note_cli( array_slice( $argv, 1 ) ) );
}
