<?php

use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {
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
}
