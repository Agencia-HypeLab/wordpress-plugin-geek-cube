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
			'core'     => array( 'wasm', 'zip' ),
			'rom'      => array( 'nes', 'fds', 'gb', 'gbc', 'gba', 'sfc', 'smc', 'md', 'gen', 'bin', 'zip', 'chd', 'cue', 'iso' ),
			'bios'     => array( 'bin', 'rom', 'zip' ),
			'patch'    => array( 'ips', 'bps', 'ups', 'xdelta' ),
			'config'   => array( 'json' ),
			'controls' => array( 'json' ),
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : array();
	}

	/**
	 * Store one HTTP upload under its content hash.
	 *
	 * @param array<string,mixed> $file Uploaded file entry.
	 * @param string              $type Artifact type.
	 * @param string              $uuid Artifact UUID.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function store_upload( array $file, $type, $uuid ) {
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

		$filename     = sanitize_file_name( (string) $file['name'] );
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
