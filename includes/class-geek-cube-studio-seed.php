<?php
/**
 * Initial legally redistributable test catalog.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds safe starting records through the permanent patch runner.
 */
final class Geek_Cube_Studio_Seed {

	/** Initial game slug. */
	const GAME_SLUG = 'falling-nes';

	/** Seeded core version identifier. */
	const CORE_VERSION = 'emulatorjs-runtime-v1';

	/**
	 * Whether patch registration was initialized.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register the permanent seed patch.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		add_filter( 'geek_cube_studio_update_patch_registry', array( __CLASS__, 'register_patch' ) );
	}

	/**
	 * Add the idempotent seed operation.
	 *
	 * @param array<string,array<string,mixed>> $patches Existing patches.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_patch( array $patches ) {
		$patches['002-seed-initial-nes-catalog'] = array(
			'introduced_in' => '0.1.3',
			'description'   => 'Register the Falling NES test game and FCEUmm runtime preset.',
			'callback'      => array( __CLASS__, 'install' ),
		);

		return $patches;
	}

	/**
	 * Create safe test records without downloading executable content.
	 *
	 * The ROM and player package remain explicit administrator imports. This
	 * keeps patch execution deterministic and makes the exact uploaded bytes
	 * part of the immutable artifact identity.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		if ( Geek_Cube_Studio_Schema::VERSION !== (string) get_option( Geek_Cube_Studio_Schema::VERSION_OPTION, '' ) ) {
			return new WP_Error( 'geek_cube_seed_schema_pending', __( 'The catalog schema must be installed before its initial records.', 'geek-cube-studio' ) );
		}

		$game = Geek_Cube_Studio_Repository::get_game_by_slug( self::GAME_SLUG );
		if ( $game && ( 'nes' !== $game['platform'] || 'https://github.com/xram64/falling-nes' !== $game['source_url'] ) ) {
			return new WP_Error( 'geek_cube_seed_game_conflict', __( 'The falling-nes slug is already used by a different catalog record.', 'geek-cube-studio' ) );
		}

		if ( ! $game ) {
			$game_id = Geek_Cube_Studio_Repository::create_game(
				array(
					'title'        => 'Falling',
					'slug'         => self::GAME_SLUG,
					'platform'     => 'nes',
					'language'     => 'default',
					'description'  => __( 'Open-source NES homebrew selected for the first browser compatibility test.', 'geek-cube-studio' ),
					'source_url'   => 'https://github.com/xram64/falling-nes',
					'rights_notes' => 'MIT-licensed source and ROM. Keep the upstream copyright and license notice with every redistributed copy.',
				)
			);

			if ( is_wp_error( $game_id ) ) {
				return $game_id;
			}
		}

		$descriptor = 'geek-cube-studio|core|fceumm|emulatorjs-runtime-key|v1';
		$core_hash  = hash( 'sha256', $descriptor );
		$core       = Geek_Cube_Studio_Repository::get_artifact_by_identity( 'core', 'FCEUmm', self::CORE_VERSION );
		if ( $core && $core_hash !== $core['sha256'] ) {
			return new WP_Error( 'geek_cube_seed_core_conflict', __( 'The initial FCEUmm identity already uses a different fingerprint.', 'geek-cube-studio' ) );
		}

		if ( ! $core ) {
			$core_id = Geek_Cube_Studio_Repository::create_artifact(
				array(
					'type'           => 'core',
					'name'           => 'FCEUmm',
					'version'        => self::CORE_VERSION,
					'platform'       => 'nes',
					'runtime_key'    => 'fceumm',
					'source_url'     => 'https://github.com/libretro/libretro-fceumm',
					'license_name'   => 'GPL-2.0',
					'commercial_use' => 'yes',
					'rights_notes'   => 'Logical EmulatorJS runtime binding. The executable core is frozen inside, and covered by the SHA-256 of, the selected player package.',
					'sha256'         => $core_hash,
				)
			);

			if ( is_wp_error( $core_id ) ) {
				return $core_id;
			}

			$core = Geek_Cube_Studio_Repository::get_artifact( $core_id );
		}

		if ( $core && 'pending' === $core['status'] ) {
			$verified = Geek_Cube_Studio_Repository::update_artifact_status( $core['id'], 'verified' );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
		}

		return true;
	}
}
