<?php

use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_options']       = array();
		$GLOBALS['test_transients']    = array();
		$GLOBALS['test_remote_result'] = null;
	}

	private function manifest(): array {
		$manifest = array(
			'name'                => 'Geek Cube Studio',
			'slug'                => 'geek-cube-studio',
			'version'             => '0.1.1',
			'author'              => 'Agência HypeLab',
			'homepage'            => 'https://www.hypelab.com.br/',
			'requires'            => '6.5',
			'tested'              => '6.8',
			'requires_php'        => '8.1',
			'download_url'        => 'https://updates.example.test/geek-cube-studio-0.1.1.zip',
			'last_updated'        => '2026-09-03',
			'channel'             => 'stable',
			'release_type'        => 'patch',
			'auto_update'         => true,
			'auto_update_major'   => false,
			'rollout_percent'     => 100,
			'sha256'              => str_repeat( 'a', 64 ),
			'signature_alg'       => 'Ed25519',
			'signature_key_id'    => 'geek-cube-studio-test-key',
		);

		$keypair               = sodium_crypto_sign_seed_keypair( str_repeat( chr( 7 ), 32 ) );
		$manifest['signature'] = base64_encode(
			sodium_crypto_sign_detached(
				Geek_Cube_Studio_Updater::canonical_json( $manifest ),
				sodium_crypto_sign_secretkey( $keypair )
			)
		);

		return $manifest;
	}

	public function test_valid_signed_manifest_is_accepted(): void {
		$this->assertTrue( Geek_Cube_Studio_Updater::validate_manifest( $this->manifest() ) );
	}

	public function test_tampering_with_auto_update_policy_is_rejected(): void {
		$manifest                = $this->manifest();
		$manifest['auto_update'] = false;

		$this->assertFalse( Geek_Cube_Studio_Updater::validate_manifest( $manifest ) );
	}

	public function test_rollout_boundaries_are_explicit(): void {
		$manifest = $this->manifest();

		$manifest['rollout_percent'] = 0;
		$this->assertFalse( Geek_Cube_Studio_Updater::site_is_in_rollout( $manifest ) );

		$manifest['rollout_percent'] = 100;
		$this->assertTrue( Geek_Cube_Studio_Updater::site_is_in_rollout( $manifest ) );
	}

	public function test_update_status_exposes_a_verified_available_release(): void {
		$GLOBALS['test_remote_result'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $this->manifest() ),
		);

		$status = Geek_Cube_Studio_Updater::get_update_status( true );

		$this->assertSame( 'available', $status['state'] );
		$this->assertSame( '0.1.1', $status['remote_version'] );
		$this->assertTrue( $status['signature_verified'] );
		$this->assertTrue( $status['environment_ok'] );
		$this->assertTrue( $status['auto_update_eligible'] );
		$this->assertSame( 'ok', $status['last_check_status'] );
	}

	public function test_update_status_reports_a_redirecting_manifest_as_unavailable(): void {
		$GLOBALS['test_remote_result'] = array(
			'response' => array( 'code' => 301 ),
			'body'     => '',
		);

		$status = Geek_Cube_Studio_Updater::get_update_status( true );

		$this->assertSame( 'unavailable', $status['state'] );
		$this->assertSame( 'http_error', $status['last_check_status'] );
		$this->assertStringContainsString( '301', $status['last_check_message'] );
	}
}
