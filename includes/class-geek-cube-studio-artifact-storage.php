<?php
/**
 * Local immutable artifact storage.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and stores uploaded versioned artifacts outside the plugin ZIP.
 */
final class Geek_Cube_Studio_Artifact_Storage {

	/** Maximum extracted player package size (300 MiB). */
	const MAX_EXTRACTED_BYTES = 314572800;

	/** Maximum files in one player package. */
	const MAX_ARCHIVE_FILES = 4000;

	/** Temporary upload lifetime in seconds. */
	const STAGED_UPLOAD_TTL = HOUR_IN_SECONDS;

	/**
	 * Return allow-listed file extensions by artifact type.
	 *
	 * @param string $type Artifact type.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions( $type ) {
		$map = array(
			'player'   => array( 'zip' ),
			'core'     => array( 'wasm', 'zip', 'tgz' ),
			'rom'      => array( 'nes', 'fds', 'gb', 'gbc', 'gba', 'sfc', 'smc', 'md', 'gen', 'bin', 'chd', 'cue', 'iso' ),
			'bios'     => array( 'bin', 'rom', 'zip' ),
			'patch'    => array( 'ips', 'bps', 'ups', 'xdelta' ),
			'config'   => array( 'json' ),
			'controls' => array( 'json' ),
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : array();
	}

	/**
	 * Return the extension groups displayed by the ROM import screen.
	 *
	 * @return array<string,string[]>
	 */
	public static function rom_extension_groups() {
		return array(
			'nes'       => array( 'nes', 'fds' ),
			'gb'        => array( 'gb' ),
			'gbc'       => array( 'gbc' ),
			'gba'       => array( 'gba' ),
			'snes'      => array( 'sfc', 'smc' ),
			'megadrive' => array( 'md', 'gen', 'bin' ),
			'psx'       => array( 'chd', 'cue', 'iso', 'bin' ),
		);
	}

	/**
	 * Infer a platform when one extension represents exactly one platform.
	 *
	 * @param string $filename Uploaded filename.
	 * @return string
	 */
	public static function detected_platform( $filename ) {
		$extension = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		$matches   = array();

		foreach ( self::rom_extension_groups() as $platform => $extensions ) {
			if ( in_array( $extension, $extensions, true ) ) {
				$matches[] = $platform;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : '';
	}

	/**
	 * Return an execution-core suggestion for a confirmed platform.
	 *
	 * @param string $platform Platform key.
	 * @return string
	 */
	public static function suggested_runtime_key( $platform ) {
		$suggestions = array(
			'nes'       => 'fceumm',
			'snes'      => 'snes9x',
			'gb'        => 'gambatte',
			'gbc'       => 'gambatte',
			'gba'       => 'mgba',
			'megadrive' => 'genesis_plus_gx',
			'psx'       => 'pcsx_rearmed',
		);
		$platform    = sanitize_key( (string) $platform );

		return isset( $suggestions[ $platform ] ) ? $suggestions[ $platform ] : '';
	}

	/**
	 * Place an uploaded file in a short-lived, private staging directory.
	 *
	 * @param array<string,mixed> $file Uploaded file entry.
	 * @param string              $type Artifact type.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function stage_upload( array $file, $type ) {
		$type = sanitize_key( (string) $type );

		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'geek_cube_upload_failed', __( 'The artifact upload did not complete successfully.', 'geek-cube-studio' ) );
		}

		$original_name = sanitize_file_name( (string) $file['name'] );
		$extension     = strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::allowed_extensions( $type ), true ) ) {
			return new WP_Error(
				'geek_cube_extension_blocked',
				sprintf(
					/* translators: 1: uploaded extension, 2: artifact type, 3: allowed extensions. */
					__( 'The .%1$s extension is not allowed for the %2$s artifact. Allowed extensions: %3$s.', 'geek-cube-studio' ),
					$extension,
					self::artifact_type_label( $type ),
					implode( ', ', array_map( static fn( $item ) => '.' . $item, self::allowed_extensions( $type ) ) )
				)
			);
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'geek_cube_upload_directory', sanitize_text_field( $uploads['error'] ) );
		}

		$token        = wp_generate_uuid4();
		$relative_dir = self::base_relative_directory() . '/temporary/' . $token;
		$absolute_dir = trailingslashit( $uploads['basedir'] ) . $relative_dir;
		$destination  = trailingslashit( $absolute_dir ) . $original_name;

		if ( ! wp_mkdir_p( $absolute_dir ) || ! move_uploaded_file( (string) $file['tmp_name'], $destination ) ) {
			self::remove_empty_directory( $absolute_dir, $uploads['basedir'] );
			return new WP_Error( 'geek_cube_stage_failed', __( 'The artifact could not be prepared for analysis.', 'geek-cube-studio' ) );
		}

		$sha256 = hash_file( 'sha256', $destination );
		if ( false === $sha256 ) {
			self::cleanup_staged( array( 'temporary_relative_path' => $relative_dir . '/' . $original_name ) );
			return new WP_Error( 'geek_cube_hash_failed', __( 'The artifact hash could not be calculated.', 'geek-cube-studio' ) );
		}

		$platform = 'rom' === $type ? self::detected_platform( $original_name ) : '';
		$analysis = self::analyze_rom( $destination, $platform );
		$name     = sanitize_text_field( (string) pathinfo( $original_name, PATHINFO_FILENAME ) );
		$name     = '' !== $name ? $name : __( 'Untitled artifact', 'geek-cube-studio' );

		return array(
			'token'                   => $token,
			'type'                    => $type,
			'original_name'           => $original_name,
			'temporary_relative_path' => $relative_dir . '/' . $original_name,
			'sha256'                  => $sha256,
			'file_size'               => (int) filesize( $destination ),
			'name'                    => isset( $analysis['title'] ) && '' !== $analysis['title'] ? $analysis['title'] : $name,
			'version'                 => '0.1.0',
			'platform'                => $platform,
			'platform_locked'         => '' !== $platform,
			'suggested_runtime_key'   => self::suggested_runtime_key( $platform ),
			'analysis'                => $analysis,
			'expires_at'              => time() + self::STAGED_UPLOAD_TTL,
		);
	}

	/**
	 * Delete a staged upload and its private parent directory.
	 *
	 * @param array<string,mixed> $draft Staged upload data.
	 * @return void
	 */
	public static function cleanup_staged( array $draft ) {
		$relative = isset( $draft['temporary_relative_path'] ) ? (string) $draft['temporary_relative_path'] : '';
		$path     = self::path( $relative );
		$uploads  = wp_upload_dir();
		$base     = trailingslashit( $uploads['basedir'] ) . self::base_relative_directory() . '/temporary/';

		if ( '' === $path || 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $base ) ) ) {
			return;
		}

		$directory = dirname( $path );
		if ( is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Validated short-lived staging file.
		}
		self::remove_empty_directory( $directory, $uploads['basedir'] );
	}

	/**
	 * Move a previously analyzed upload into its immutable artifact location.
	 *
	 * @param array<string,mixed> $draft            Staged upload data.
	 * @param string              $artifact_name    Registered artifact name.
	 * @param string              $artifact_version Registered artifact version.
	 * @param string              $platform         Registered artifact platform.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function store_staged( array $draft, $artifact_name, $artifact_version, $platform ) {
		$type     = isset( $draft['type'] ) ? sanitize_key( (string) $draft['type'] ) : '';
		$original = isset( $draft['original_name'] ) ? (string) $draft['original_name'] : '';
		$source   = isset( $draft['temporary_relative_path'] ) ? self::path( $draft['temporary_relative_path'] ) : '';

		if ( '' === $source || ! is_file( $source ) || ! in_array( strtolower( (string) pathinfo( $original, PATHINFO_EXTENSION ) ), self::allowed_extensions( $type ), true ) ) {
			return new WP_Error( 'geek_cube_stage_missing', __( 'The analyzed upload is no longer available. Analyze the file again.', 'geek-cube-studio' ) );
		}

		$sha256 = hash_file( 'sha256', $source );
		if ( false === $sha256 || empty( $draft['sha256'] ) || ! hash_equals( (string) $draft['sha256'], $sha256 ) ) {
			return new WP_Error( 'geek_cube_stage_changed', __( 'The analyzed upload changed before it could be saved.', 'geek-cube-studio' ) );
		}

		$uploads      = wp_upload_dir();
		$relative_dir = self::artifact_relative_directory( $type, $platform );
		$absolute_dir = trailingslashit( $uploads['basedir'] ) . $relative_dir;
		$filename     = self::artifact_filename( $artifact_name, $artifact_version, $platform, $original );
		$destination  = trailingslashit( $absolute_dir ) . $filename;

		if ( ! wp_mkdir_p( $absolute_dir ) ) {
			return new WP_Error( 'geek_cube_directory_failed', __( 'The immutable artifact directory could not be created.', 'geek-cube-studio' ) );
		}
		if ( file_exists( $destination ) ) {
			return new WP_Error( 'geek_cube_artifact_filename_conflict', __( 'An artifact with this name, version and platform already exists. Register a new version instead.', 'geek-cube-studio' ) );
		}
		if ( ! self::move_file( $source, $destination ) ) {
			return new WP_Error( 'geek_cube_move_failed', __( 'The artifact could not be moved into immutable storage.', 'geek-cube-studio' ) );
		}

		$result = array(
			'sha256'          => $sha256,
			'file_size'       => (int) filesize( $destination ),
			'relative_path'   => $relative_dir . '/' . $filename,
			'entrypoint_path' => '',
			'absolute_path'   => $destination,
			'absolute_root'   => '',
		);

		if ( 'player' === $type ) {
			$package_name            = (string) pathinfo( $filename, PATHINFO_FILENAME );
			$package_dir             = trailingslashit( $absolute_dir ) . $package_name;
			$package_rel             = $relative_dir . '/' . $package_name;
			$result['absolute_root'] = $package_dir;
			$extracted               = self::extract_player_package( $destination, $package_dir, $package_rel );
			if ( is_wp_error( $extracted ) ) {
				self::cleanup( $result );
				return $extracted;
			}

			$result['entrypoint_path'] = $extracted;
		}

		return $result;
	}

	/**
	 * Move one legacy ROM file into the managed human-readable directory.
	 *
	 * @param array<string,mixed> $artifact ROM artifact database row.
	 * @return string|WP_Error New relative path.
	 */
	public static function relocate_legacy_rom( array $artifact ) {
		if ( 'rom' !== ( $artifact['type'] ?? '' ) || empty( $artifact['relative_path'] ) ) {
			return new WP_Error( 'geek_cube_relocation_invalid', __( 'Only stored ROM artifacts can be relocated.', 'geek-cube-studio' ) );
		}

		$source       = self::path( $artifact['relative_path'] );
		$original     = (string) basename( $artifact['relative_path'] );
		$relative_dir = self::artifact_relative_directory( 'rom', $artifact['platform'] ?? '' );
		$filename     = self::artifact_filename( $artifact['name'] ?? '', $artifact['version'] ?? '', $artifact['platform'] ?? '', $original );
		$relative     = $relative_dir . '/' . $filename;
		$destination  = self::path( $relative );

		if ( '' === $source || '' === $destination ) {
			return new WP_Error( 'geek_cube_relocation_missing', __( 'The ROM file could not be located for relocation.', 'geek-cube-studio' ) );
		}
		if ( wp_normalize_path( $source ) === wp_normalize_path( $destination ) ) {
			return $relative;
		}
		if ( file_exists( $destination ) ) {
			$hash = hash_file( 'sha256', $destination );
			return is_string( $hash ) && hash_equals( (string) $artifact['sha256'], $hash ) ? $relative : new WP_Error( 'geek_cube_relocation_conflict', __( 'A different ROM already occupies the managed artifact path.', 'geek-cube-studio' ) );
		}
		if ( ! is_file( $source ) || ! wp_mkdir_p( dirname( $destination ) ) || ! self::move_file( $source, $destination ) ) {
			return new WP_Error( 'geek_cube_relocation_failed', __( 'The ROM file could not be moved into the managed artifact directory.', 'geek-cube-studio' ) );
		}

		return $relative;
	}

	/**
	 * Return the configured artifact root relative to wp-content/uploads.
	 *
	 * @return string
	 */
	private static function base_relative_directory() {
		return trim( (string) Geek_Cube_Studio_Settings::get( 'artifact_storage_subdirectory' ), '/\\' );
	}

	/**
	 * Return the human-readable directory for one artifact type.
	 *
	 * @param string $type Artifact type.
	 * @return string
	 */
	private static function artifact_directory_name( $type ) {
		$directories = array(
			'player'   => 'players',
			'core'     => 'cores',
			'rom'      => 'roms',
			'bios'     => 'bios',
			'patch'    => 'patches',
			'config'   => 'configs',
			'controls' => 'controls',
		);
		$type        = sanitize_key( (string) $type );

		return isset( $directories[ $type ] ) ? $directories[ $type ] : 'other';
	}

	/**
	 * Return a translated artifact type label for administrator feedback.
	 *
	 * @param string $type Artifact type.
	 * @return string
	 */
	private static function artifact_type_label( $type ) {
		$labels = array(
			'player'   => __( 'Player', 'geek-cube-studio' ),
			'core'     => __( 'Core', 'geek-cube-studio' ),
			'rom'      => __( 'ROM', 'geek-cube-studio' ),
			'bios'     => __( 'BIOS', 'geek-cube-studio' ),
			'patch'    => __( 'Patch', 'geek-cube-studio' ),
			'config'   => __( 'Configuration', 'geek-cube-studio' ),
			'controls' => __( 'Controls', 'geek-cube-studio' ),
		);
		$type   = sanitize_key( (string) $type );

		return isset( $labels[ $type ] ) ? $labels[ $type ] : strtoupper( $type );
	}

	/**
	 * Return the final directory for an artifact identity.
	 *
	 * @param string $type Artifact type.
	 * @param string $platform Platform key.
	 * @return string
	 */
	private static function artifact_relative_directory( $type, $platform ) {
		$platform = sanitize_key( (string) $platform );
		$platform = '' !== $platform ? $platform : 'global';

		return self::base_relative_directory() . '/' . self::artifact_directory_name( $type ) . '/' . $platform;
	}

	/**
	 * Move a staging file on the local filesystem without overwriting a target.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @return bool
	 */
	private static function move_file( $source, $destination ) {
		if ( rename( $source, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Validated plugin-owned paths.
			return true;
		}

		return copy( $source, $destination ) && unlink( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy, WordPress.WP.AlternativeFunctions.unlink_unlink -- Validated plugin-owned paths.
	}

	/**
	 * Store one HTTP upload under its content hash.
	 *
	 * @param array<string,mixed> $file Uploaded file entry.
	 * @param string              $type Artifact type.
	 * @param string              $uuid Artifact UUID.
	 * @param string              $artifact_name Registered immutable artifact name.
	 * @param string              $artifact_version Registered immutable artifact version.
	 * @param string              $platform Registered artifact platform, when applicable.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function store_upload( array $file, $type, $uuid, $artifact_name = '', $artifact_version = '', $platform = '' ) {
		$type = sanitize_key( (string) $type );
		$uuid = sanitize_text_field( (string) $uuid );

		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'geek_cube_upload_failed', __( 'The artifact upload did not complete successfully.', 'geek-cube-studio' ) );
		}

		$extension = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::allowed_extensions( $type ), true ) ) {
			return new WP_Error( 'geek_cube_extension_blocked', __( 'This file extension is not allowed for the selected artifact type.', 'geek-cube-studio' ) );
		}

		$tmp_name = (string) $file['tmp_name'];
		$sha256   = hash_file( 'sha256', $tmp_name );
		if ( false === $sha256 ) {
			return new WP_Error( 'geek_cube_hash_failed', __( 'The artifact hash could not be calculated.', 'geek-cube-studio' ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'geek_cube_upload_directory', sanitize_text_field( $uploads['error'] ) );
		}

		$base_subdirectory = trim( (string) Geek_Cube_Studio_Settings::get( 'artifact_storage_subdirectory' ), '/\\' );
		$relative_dir      = $base_subdirectory . '/' . $type . '/' . $uuid . '/' . $sha256;
		$absolute_dir      = trailingslashit( $uploads['basedir'] ) . $relative_dir;

		if ( ! wp_mkdir_p( $absolute_dir ) ) {
			return new WP_Error( 'geek_cube_directory_failed', __( 'The immutable artifact directory could not be created.', 'geek-cube-studio' ) );
		}

		$filename     = self::artifact_filename( $artifact_name, $artifact_version, $platform, (string) $file['name'] );
		$destination  = trailingslashit( $absolute_dir ) . $filename;
		$relative     = $relative_dir . '/' . $filename;
		$was_uploaded = is_uploaded_file( $tmp_name );
		$moved        = $was_uploaded ? move_uploaded_file( $tmp_name, $destination ) : false;

		if ( ! $moved ) {
			self::remove_empty_directory( $absolute_dir, $uploads['basedir'] );
			return new WP_Error( 'geek_cube_move_failed', __( 'The uploaded artifact could not be moved to immutable storage.', 'geek-cube-studio' ) );
		}

		$result = array(
			'sha256'          => $sha256,
			'file_size'       => (int) filesize( $destination ),
			'relative_path'   => str_replace( '\\', '/', $relative ),
			'entrypoint_path' => '',
			'absolute_path'   => $destination,
			'absolute_root'   => $absolute_dir,
		);

		if ( 'player' === $type ) {
			$extracted = self::extract_player_package( $destination, $absolute_dir, $relative_dir );
			if ( is_wp_error( $extracted ) ) {
				self::cleanup( $result );
				return $extracted;
			}

			$result['entrypoint_path'] = $extracted;
		}

		return $result;
	}

	/**
	 * Create a stable storage filename from the registered artifact identity.
	 *
	 * The extension remains the uploaded file's allowed, validated extension.
	 * Existing artifacts are never renamed: a corrected name is a new immutable
	 * artifact version with its own directory and SHA-256 fingerprint.
	 *
	 * @param string $artifact_name    Registered artifact name.
	 * @param string $artifact_version Registered artifact version.
	 * @param string $platform         Registered platform, when applicable.
	 * @param string $uploaded_name    Original uploaded filename.
	 * @return string
	 */
	private static function artifact_filename( $artifact_name, $artifact_version, $platform, $uploaded_name ) {
		$extension = strtolower( (string) pathinfo( $uploaded_name, PATHINFO_EXTENSION ) );
		$name      = sanitize_title( (string) $artifact_name );
		$version   = sanitize_title( (string) $artifact_version );
		$platform  = sanitize_key( (string) $platform );
		$platform  = '' !== $platform ? $platform : 'global';

		if ( '' === $name || '' === $version || '' === $extension ) {
			return sanitize_file_name( $uploaded_name );
		}

		return $name . '-' . $version . '-' . $platform . '.' . $extension;
	}

	/**
	 * Read conservative, non-authoritative metadata from a supported ROM.
	 *
	 * @param string $path Detected local ROM path.
	 * @param string $platform Detected platform key.
	 * @return array<string,string>
	 */
	private static function analyze_rom( $path, $platform ) {
		if ( 'snes' !== $platform || ! is_file( $path ) ) {
			return array();
		}

		$best = array();
		foreach ( array( 0, 512 ) as $copier_header ) {
			foreach ( array( 0x7FC0, 0xFFC0 ) as $header_offset ) {
				$header = file_get_contents( $path, false, null, $copier_header + $header_offset, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a validated local staging file.
				if ( false === $header || 32 !== strlen( $header ) ) {
					continue;
				}

				$title = trim( preg_replace( '/[^\x20-\x7e]/', '', substr( $header, 0, 21 ) ) );
				if ( strlen( $title ) < 3 ) {
					continue;
				}

				$revision = (string) ord( $header[27] );
				$score    = preg_match( '/[a-zA-Z]/', $title ) ? 2 : 1;
				$check    = unpack( 'vchecksum/vinverse', substr( $header, 28, 4 ) );
				if ( is_array( $check ) && 65535 === ( (int) $check['checksum'] + (int) $check['inverse'] ) ) {
					++$score;
				}
				if ( ! isset( $best['score'] ) || $score > (int) $best['score'] ) {
					$best = array(
						'score'           => (string) $score,
						'title'           => $title,
						'header_revision' => $revision,
						'mapping'         => 0x7FC0 === $header_offset ? 'lorom' : 'hirom',
					);
				}
			}
		}

		unset( $best['score'] );
		return $best;
	}

	/**
	 * Convert an artifact-relative path to the current uploads URL.
	 *
	 * @param string $relative_path Stored relative path.
	 *
	 * @return string
	 */
	public static function url( $relative_path ) {
		$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );
		$uploads       = wp_upload_dir();

		return '' === $relative_path || preg_match( '#(^|/)\.\.(/|$)#', $relative_path ) || ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] )
			? ''
			: trailingslashit( $uploads['baseurl'] ) . $relative_path;
	}

	/**
	 * Resolve an artifact-relative path and ensure it remains in uploads.
	 *
	 * @param string $relative_path Stored relative path.
	 *
	 * @return string
	 */
	public static function path( $relative_path ) {
		$relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );
		if ( '' === $relative_path || preg_match( '#(^|/)\.\.(/|$)#', $relative_path ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$candidate  = trailingslashit( $uploads['basedir'] ) . $relative_path;
		$base       = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$normalized = wp_normalize_path( $candidate );

		return 0 === strpos( $normalized, $base ) ? $candidate : '';
	}

	/**
	 * Remove a just-created artifact after a failed database insert.
	 *
	 * @param array<string,mixed> $stored Storage result.
	 *
	 * @return void
	 */
	public static function cleanup( array $stored ) {
		$file = isset( $stored['absolute_path'] ) ? (string) $stored['absolute_path'] : '';
		if ( '' !== $file && is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Validated artifact file created during this request.
		}

		$root = isset( $stored['absolute_root'] ) ? (string) $stored['absolute_root'] : '';
		if ( '' === $root || ! is_dir( $root ) ) {
			return;
		}

		$uploads = wp_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$target  = realpath( $root );

		if ( false === $base || false === $target || 0 !== strpos( wp_normalize_path( $target ), wp_normalize_path( trailingslashit( $base ) ) ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Validated plugin-owned artifact path.
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Validated plugin-owned artifact path.
			}
		}

		rmdir( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Validated plugin-owned artifact path.
	}

	/**
	 * Safely extract an EmulatorJS package and find data/loader.js.
	 *
	 * @param string $archive      ZIP path.
	 * @param string $absolute_dir Artifact root.
	 * @param string $relative_dir Artifact relative root.
	 *
	 * @return string|WP_Error
	 */
	private static function extract_player_package( $archive, $absolute_dir, $relative_dir ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'geek_cube_zip_unavailable', __( 'The ZIP extension is required to import a player package.', 'geek-cube-studio' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			return new WP_Error( 'geek_cube_zip_invalid', __( 'The player package is not a readable ZIP archive.', 'geek-cube-studio' ) );
		}

		if ( self::MAX_ARCHIVE_FILES < $zip->numFiles ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
			$zip->close();
			return new WP_Error( 'geek_cube_zip_too_many_files', __( 'The player package contains too many files.', 'geek-cube-studio' ) );
		}

		$total_size = 0;
		$entries    = array();
		for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
			$stat       = $zip->statIndex( $index );
			$name       = isset( $stat['name'] ) ? str_replace( '\\', '/', (string) $stat['name'] ) : '';
			$entry_key  = strtolower( rtrim( $name, '/' ) );
			$operations = 0;
			$attributes = 0;
			$is_symlink = $zip->getExternalAttributesIndex( $index, $operations, $attributes )
				&& ZipArchive::OPSYS_UNIX === $operations
				&& 0120000 === ( ( $attributes >> 16 ) & 0170000 );

			if ( '' === $name || preg_match( '#^(?:/|[a-z]:/)#i', $name ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) || false !== strpos( $name, "\0" ) || $is_symlink || isset( $entries[ $entry_key ] ) ) {
				$zip->close();
				return new WP_Error( 'geek_cube_zip_unsafe', __( 'The player package contains an unsafe path.', 'geek-cube-studio' ) );
			}
			$entries[ $entry_key ] = $stat;

			$total_size += isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			if ( self::MAX_EXTRACTED_BYTES < $total_size ) {
				$zip->close();
				return new WP_Error( 'geek_cube_zip_too_large', __( 'The extracted player package is larger than the configured safety limit.', 'geek-cube-studio' ) );
			}
		}

		$extract_dir = trailingslashit( $absolute_dir ) . 'package';
		if ( ! wp_mkdir_p( $extract_dir ) ) {
			$zip->close();
			return new WP_Error( 'geek_cube_zip_extract_failed', __( 'The player package could not be extracted.', 'geek-cube-studio' ) );
		}

		$loader_entries = array();
		foreach ( $entries as $entry ) {
			$name        = str_replace( '\\', '/', (string) $entry['name'] );
			$destination = trailingslashit( $extract_dir ) . $name;

			if ( '/' === substr( $name, -1 ) ) {
				if ( ! wp_mkdir_p( $destination ) ) {
					$zip->close();
					return new WP_Error( 'geek_cube_zip_extract_failed', __( 'The player package could not be extracted.', 'geek-cube-studio' ) );
				}
				continue;
			}

			if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
				$zip->close();
				return new WP_Error( 'geek_cube_zip_extract_failed', __( 'The player package could not be extracted.', 'geek-cube-studio' ) );
			}

			$input  = $zip->getStream( (string) $entry['name'] );
			$output = fopen( $destination, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Validated immutable artifact path.
			if ( false === $input || false === $output ) {
				is_resource( $input ) && fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired archive stream close.
				is_resource( $output ) && fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired artifact stream close.
				$zip->close();
				return new WP_Error( 'geek_cube_zip_extract_failed', __( 'The player package could not be extracted.', 'geek-cube-studio' ) );
			}

			$written = stream_copy_to_stream( $input, $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_copy_to_stream -- Streams validated ZIP data into a validated artifact path.
			fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired archive stream close.
			fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired artifact stream close.

			if ( false === $written || (int) $entry['size'] !== $written ) {
				$zip->close();
				return new WP_Error( 'geek_cube_zip_extract_failed', __( 'The player package could not be extracted.', 'geek-cube-studio' ) );
			}

			if ( preg_match( '#(^|/)data/loader\.js$#', $name ) ) {
				$loader_entries[] = $name;
			}
		}
		$zip->close();

		if ( 1 !== count( $loader_entries ) ) {
			return new WP_Error( 'geek_cube_player_entrypoint_missing', __( 'The player package must contain exactly one data/loader.js entrypoint.', 'geek-cube-studio' ) );
		}

		return str_replace( '\\', '/', $relative_dir . '/package/' . reset( $loader_entries ) );
	}

	/**
	 * Remove an empty directory after an upload error.
	 *
	 * @param string $directory      Directory to remove.
	 * @param string $base_directory Trusted uploads base directory.
	 * @return void
	 */
	private static function remove_empty_directory( $directory, $base_directory ) {
		$base   = realpath( $base_directory );
		$parent = realpath( dirname( $directory ) );

		if ( false !== $base && false !== $parent && 0 === strpos( wp_normalize_path( $parent ), wp_normalize_path( trailingslashit( $base ) ) ) && is_dir( $directory ) ) {
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Validated empty plugin-owned path.
		}
	}
}
