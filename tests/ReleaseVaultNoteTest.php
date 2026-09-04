<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/tools/release-vault-note.php';

final class ReleaseVaultNoteTest extends TestCase {
	private function signingIdentity(): array {
		$keypair = sodium_crypto_sign_seed_keypair( str_repeat( chr( 11 ), 32 ) );

		return array(
			'key_id'     => 'geek-cube-studio-test-vault-key',
			'secret_key' => sodium_crypto_sign_secretkey( $keypair ),
			'public_key' => sodium_crypto_sign_publickey( $keypair ),
		);
	}

	public function test_note_round_trips_the_sealed_signing_key(): void {
		$signing  = $this->signingIdentity();
		$expected = array(
			'key_id'            => $signing['key_id'],
			'public_key_base64' => base64_encode( $signing['public_key'] ),
		);
		$note     = geek_cube_studio_vault_note_build( $signing, '2026-09-03T12:00:00+00:00' );
		$result   = geek_cube_studio_vault_note_verify_text( $note, $expected );

		$this->assertStringContainsString( 'Projeto: wordpress-plugin-geek-cube', $note );
		$this->assertStringContainsString( GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_ONE, $note );
		$this->assertStringContainsString( GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_TWO, $note );
		$this->assertStringContainsString( GEEK_CUBE_STUDIO_VAULT_NOTE_SECTION_THREE, $note );
		$this->assertSame( $signing['key_id'], $result['signing_key_id'] );
		$this->assertStringStartsWith( 'sha256:', $result['recovery_key_id'] );

		sodium_memzero( $signing['secret_key'] );
	}

	public function test_note_detects_ciphertext_tampering(): void {
		$signing  = $this->signingIdentity();
		$expected = array(
			'key_id'            => $signing['key_id'],
			'public_key_base64' => base64_encode( $signing['public_key'] ),
		);
		$note     = geek_cube_studio_vault_note_build( $signing );
		$sections = geek_cube_studio_vault_note_parse( $note );
		$original = $sections['sealed']['ciphertext_sha256'];
		$tampered = str_replace( $original, str_repeat( '0', 64 ), $note );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'failed integrity validation' );

		try {
			geek_cube_studio_vault_note_verify_text( $tampered, $expected );
		} finally {
			sodium_memzero( $signing['secret_key'] );
		}
	}

	public function test_note_refuses_a_relative_secret_path(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'must be absolute' );

		geek_cube_studio_vault_note_assert_safe_path( 'release-vault-note.txt' );
	}

	public function test_note_accepts_an_absolute_windows_secret_path(): void {
		geek_cube_studio_vault_note_assert_safe_path( 'D:\\Projetos\\hypelab\\secrets\\wordpress-plugin-geek-cube\\release-vault-note.txt' );

		$this->addToAssertionCount( 1 );
	}
}
