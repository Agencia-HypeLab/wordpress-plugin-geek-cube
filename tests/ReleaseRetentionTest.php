<?php
/**
 * Release retention tests.
 *
 * @package Geek_Cube_Studio
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/tools/release-retention.php';

/** Validate safe local and remote release filename retention. */
final class ReleaseRetentionTest extends TestCase {
	/**
	 * Temporary build directory.
	 *
	 * @var string
	 */
	private $build_dir;

	/** Create an isolated build directory. */
	protected function setUp(): void {
		$this->build_dir = sys_get_temp_dir() . '/geek-cube-retention-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->build_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated CLI test fixture.
	}

	/** Remove the isolated test fixture. */
	protected function tearDown(): void {
		$paths = glob( $this->build_dir . '/*' );
		$paths = is_array( $paths ) ? $paths : array();

		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated CLI test fixture.
			}
		}

		if ( is_dir( $this->build_dir ) ) {
			rmdir( $this->build_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated CLI test fixture.
		}
	}

	/** Verify that only the three most recently created valid packages survive. */
	public function test_local_retention_keeps_only_the_three_latest_plugin_builds(): void {
		$versions = array( '0.1.1', '0.1.2', '0.1.3', '0.1.4' );

		foreach ( $versions as $index => $version ) {
			$path = $this->build_dir . '/geek-cube-studio-' . $version . '.zip';
			file_put_contents( $path, $version ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated CLI test fixture.
			touch( $path, 1000 + $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test timestamp controls retention order.
			clearstatcache( true, $path );
		}

		$unrelated = $this->build_dir . '/geek-cube-studio-update.zip';
		file_put_contents( $unrelated, 'preserve' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated CLI test fixture.

		$deleted = geek_cube_studio_release_prune_local_builds( $this->build_dir, 'geek-cube-studio' );
		$builds  = geek_cube_studio_release_list_local_builds( $this->build_dir, 'geek-cube-studio' );

		$this->assertSame( array( 'geek-cube-studio-0.1.1.zip' ), $deleted );
		$this->assertSame(
			array(
				'geek-cube-studio-0.1.4.zip',
				'geek-cube-studio-0.1.3.zip',
				'geek-cube-studio-0.1.2.zip',
			),
			array_column( $builds, 'filename' )
		);
		$this->assertFileDoesNotExist( $this->build_dir . '/geek-cube-studio-0.1.1.zip' );
		$this->assertFileExists( $unrelated );
	}

	/** Verify that remote cleanup can never target unrelated filenames. */
	public function test_remote_listing_filter_accepts_only_strict_versioned_plugin_zips(): void {
		$filenames = geek_cube_studio_release_build_filenames(
			array(
				'/releases/geek-cube-studio-1.2.3.zip',
				'releases\\geek-cube-studio-1.2.4-preview.1.zip',
				'geek-cube-studio-update.zip',
				'other-plugin-9.9.9.zip',
				'geek-cube-studio-1.2.3.zip',
			),
			'geek-cube-studio'
		);

		$this->assertSame(
			array(
				'geek-cube-studio-1.2.3.zip',
				'geek-cube-studio-1.2.4-preview.1.zip',
			),
			$filenames
		);
	}
}
