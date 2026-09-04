<?php
/**
 * Release package retention helpers shared by build and deploy tooling.
 *
 * @package Geek_Cube_Studio
 */

/** Number of versioned plugin packages retained locally and remotely. */
const GEEK_CUBE_STUDIO_RELEASE_RETENTION = 3;

/**
 * Return the strict filename pattern for this plugin's versioned packages.
 *
 * @param string $plugin_slug Plugin slug.
 * @return string
 */
function geek_cube_studio_release_build_pattern( $plugin_slug ) {
	return '/^' . preg_quote( (string) $plugin_slug, '/' ) . '-([0-9]+\.[0-9]+\.[0-9]+(?:[-.][0-9A-Za-z]+)*)\.zip$/';
}

/**
 * Extract safe, unique package names from local paths or remote list entries.
 *
 * @param string[] $entries     Paths or list entries.
 * @param string   $plugin_slug Plugin slug.
 * @return string[]
 */
function geek_cube_studio_release_build_filenames( array $entries, $plugin_slug ) {
	$filenames = array();
	$pattern   = geek_cube_studio_release_build_pattern( $plugin_slug );

	foreach ( $entries as $entry ) {
		$normalized = str_replace( '\\', '/', trim( (string) $entry ) );
		$filename   = basename( $normalized );

		if ( preg_match( $pattern, $filename ) ) {
			$filenames[ $filename ] = true;
		}
	}

	return array_keys( $filenames );
}

/**
 * List local builds from newest filesystem timestamp to oldest.
 *
 * The release workflow creates or replaces the current package immediately
 * before this function runs, matching the retention behavior of HypeLab.
 *
 * @param string $build_dir   Build directory.
 * @param string $plugin_slug Plugin slug.
 * @param int    $keep        Maximum packages returned.
 * @return array<int,array{path:string,filename:string,mtime:int}>
 */
function geek_cube_studio_release_list_local_builds( $build_dir, $plugin_slug, $keep = GEEK_CUBE_STUDIO_RELEASE_RETENTION ) {
	$keep  = max( 0, (int) $keep );
	$paths = glob( rtrim( (string) $build_dir, '/\\' ) . '/' . (string) $plugin_slug . '-*.zip' );
	$paths = is_array( $paths ) ? $paths : array();
	$valid = array();

	foreach ( $paths as $path ) {
		if ( is_file( $path ) && preg_match( geek_cube_studio_release_build_pattern( $plugin_slug ), basename( $path ) ) ) {
			$valid[] = $path;
		}
	}

	usort(
		$valid,
		static function ( $left, $right ) {
			$left_mtime  = (int) filemtime( $left );
			$right_mtime = (int) filemtime( $right );

			if ( $left_mtime === $right_mtime ) {
				return strcmp( basename( $right ), basename( $left ) );
			}

			return $right_mtime <=> $left_mtime;
		}
	);

	$builds = array();

	foreach ( array_slice( $valid, 0, $keep ) as $path ) {
		$builds[] = array(
			'path'     => $path,
			'filename' => basename( $path ),
			'mtime'    => (int) filemtime( $path ),
		);
	}

	return $builds;
}

/**
 * Remove old local packages and verify the configured retention limit.
 *
 * @param string $build_dir   Build directory.
 * @param string $plugin_slug Plugin slug.
 * @param int    $keep        Maximum packages to retain.
 * @return string[] Deleted filenames.
 * @throws RuntimeException When an old package cannot be removed.
 */
function geek_cube_studio_release_prune_local_builds( $build_dir, $plugin_slug, $keep = GEEK_CUBE_STUDIO_RELEASE_RETENTION ) {
	$keep_builds = geek_cube_studio_release_list_local_builds( $build_dir, $plugin_slug, $keep );
	$keep_map    = array();

	foreach ( $keep_builds as $build ) {
		$keep_map[ $build['filename'] ] = true;
	}

	$paths   = glob( rtrim( (string) $build_dir, '/\\' ) . '/' . (string) $plugin_slug . '-*.zip' );
	$paths   = is_array( $paths ) ? $paths : array();
	$deleted = array();

	foreach ( $paths as $path ) {
		$filename = basename( $path );

		if (
			! is_file( $path )
			|| ! preg_match( geek_cube_studio_release_build_pattern( $plugin_slug ), $filename )
			|| isset( $keep_map[ $filename ] )
		) {
			continue;
		}

		if ( ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- CLI cleanup of a validated generated package.
			throw new RuntimeException( "Unable to remove old local build: {$filename}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI diagnostic.
		}

		$deleted[] = $filename;
	}

	if ( count( geek_cube_studio_release_list_local_builds( $build_dir, $plugin_slug, PHP_INT_MAX ) ) > max( 0, (int) $keep ) ) {
		throw new RuntimeException( 'Local build retention verification failed.' );
	}

	return $deleted;
}
