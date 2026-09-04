<?php

use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase {
	private array $temporaryDirectories = array();

	protected function tearDown(): void {
		foreach ( $this->temporaryDirectories as $directory ) {
			$this->removeDirectory( $directory );
		}
	}

	public function test_initial_patches_are_permanent_and_versioned(): void {
		$patches = Geek_Cube_Studio_Schema::register_patch( array() );
		$patches = Geek_Cube_Studio_Seed::register_patch( $patches );

		$this->assertArrayHasKey( '001-create-content-schema', $patches );
		$this->assertArrayHasKey( '002-seed-initial-nes-catalog', $patches );
		$this->assertSame( '0.1.3', $patches['002-seed-initial-nes-catalog']['introduced_in'] );
	}

	public function test_unsupported_archive_cannot_be_imported_as_a_rom(): void {
		$this->assertNotContains( '7z', Geek_Cube_Studio_Artifact_Storage::allowed_extensions( 'rom' ) );
		$this->assertContains( 'nes', Geek_Cube_Studio_Artifact_Storage::allowed_extensions( 'rom' ) );
	}

	public function test_artifact_tab_resolution_accepts_only_known_categories(): void {
		$this->assertSame( 'all', Geek_Cube_Studio_Catalog_Admin::resolve_artifact_type( array() ) );
		$this->assertSame( 'rom', Geek_Cube_Studio_Catalog_Admin::resolve_artifact_type( array( 'artifact_type' => 'rom' ) ) );
		$this->assertSame( 'all', Geek_Cube_Studio_Catalog_Admin::resolve_artifact_type( array( 'artifact_type' => 'unknown' ) ) );
		$this->assertSame( 'all', Geek_Cube_Studio_Catalog_Admin::resolve_artifact_type( array( 'artifact_type' => array( 'rom' ) ) ) );
	}

	public function test_verified_nes_profile_without_bios_is_valid(): void {
		$game      = array( 'platform' => 'nes' );
		$artifacts = array(
			'player'   => array(
				'type'            => 'player',
				'status'          => 'verified',
				'platform'        => '',
				'entrypoint_path' => 'data/loader.js',
			),
			'core'     => array(
				'type'        => 'core',
				'status'      => 'verified',
				'platform'    => 'nes',
				'runtime_key' => 'fceumm',
			),
			'rom'      => array(
				'type'          => 'rom',
				'status'        => 'verified',
				'platform'      => 'nes',
				'relative_path' => 'rom/falling.nes',
			),
			'bios'     => null,
			'config'   => null,
			'controls' => null,
		);

		$this->assertTrue( Geek_Cube_Studio_Repository::validate_profile_artifacts( $game, $artifacts ) );
	}

	public function test_unverified_rom_cannot_be_frozen_into_a_profile(): void {
		$game      = array( 'platform' => 'nes' );
		$artifacts = array(
			'player'   => array(
				'type'            => 'player',
				'status'          => 'verified',
				'platform'        => '',
				'entrypoint_path' => 'data/loader.js',
			),
			'core'     => array(
				'type'        => 'core',
				'status'      => 'verified',
				'platform'    => 'nes',
				'runtime_key' => 'fceumm',
			),
			'rom'      => array(
				'type'          => 'rom',
				'status'        => 'blocked',
				'platform'      => 'nes',
				'relative_path' => 'rom/test.nes',
			),
			'bios'     => null,
			'config'   => null,
			'controls' => null,
		);

		$result = Geek_Cube_Studio_Repository::validate_profile_artifacts( $game, $artifacts );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_player_archive_is_extracted_to_a_frozen_entrypoint(): void {
		$directory    = $this->temporaryDirectory();
		$archive      = $directory . '/player.zip';
		$artifactRoot = $directory . '/artifact';
		mkdir( $artifactRoot );

		$zip = new ZipArchive();
		$zip->open( $archive, ZipArchive::CREATE );
		$zip->addFromString( 'release/data/loader.js', 'window.EmulatorJS = true;' );
		$zip->addFromString( 'release/data/cores/fceumm.data', 'core' );
		$zip->close();

		$result = $this->extractPlayerPackage( $archive, $artifactRoot, 'geek-cube/player/hash' );

		$this->assertSame( 'geek-cube/player/hash/package/release/data/loader.js', $result );
		$this->assertFileExists( $artifactRoot . '/package/release/data/loader.js' );
	}

	public function test_player_archive_rejects_directory_traversal(): void {
		$directory    = $this->temporaryDirectory();
		$archive      = $directory . '/unsafe.zip';
		$artifactRoot = $directory . '/artifact';
		mkdir( $artifactRoot );

		$zip = new ZipArchive();
		$zip->open( $archive, ZipArchive::CREATE );
		$zip->addFromString( '../escaped.txt', 'unsafe' );
		$zip->addFromString( 'data/loader.js', 'loader' );
		$zip->close();

		$result = $this->extractPlayerPackage( $archive, $artifactRoot, 'geek-cube/player/hash' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFileDoesNotExist( $directory . '/escaped.txt' );
	}

	private function extractPlayerPackage( string $archive, string $root, string $relative ): mixed {
		$method = new ReflectionMethod( Geek_Cube_Studio_Artifact_Storage::class, 'extract_player_package' );
		$method->setAccessible( true );

		return $method->invoke( null, $archive, $root, $relative );
	}

	private function temporaryDirectory(): string {
		$directory = sys_get_temp_dir() . '/geek-cube-tests-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0777, true );
		$this->temporaryDirectories[] = $directory;

		return $directory;
	}

	private function removeDirectory( string $directory ): void {
		if ( ! is_dir( $directory ) || ! str_starts_with( realpath( $directory ), realpath( sys_get_temp_dir() ) ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
