<?php
/**
 * Catalog and compatibility repository.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the only write surface for immutable product records.
 */
final class Geek_Cube_Studio_Repository {

	/** Supported game platforms in the first catalog version. */
	const PLATFORMS = array( 'nes', 'gb', 'gbc', 'gba', 'snes', 'megadrive', 'psx', 'arcade', 'n64', 'nds', 'psp' );

	/** Artifact types allowed in execution profiles. */
	const ARTIFACT_TYPES = array( 'player', 'core', 'rom', 'bios', 'patch', 'config', 'controls' );

	/** Checklist fields stored in every test run. */
	const TEST_CHECKLIST = array(
		'boot',
		'video',
		'audio',
		'keyboard',
		'touch',
		'gamepad',
		'fullscreen',
		'orientation',
		'native_save',
		'save_state',
		'reload_save',
		'resume',
		'endurance',
	);

	/**
	 * Create an immutable game identity.
	 *
	 * @param array<string,mixed> $data Sanitized form-shaped data.
	 *
	 * @return int|WP_Error
	 */
	public static function create_game( array $data ) {
		global $wpdb;

		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		$slug  = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $title );

		if ( '' === $title || '' === $slug || 'laboratorio' === $slug ) {
			return new WP_Error( 'geek_cube_game_invalid', __( 'Enter a valid title and a non-reserved game slug.', 'geek-cube-studio' ) );
		}

		$platform = self::platform( isset( $data['platform'] ) ? $data['platform'] : '' );
		if ( '' === $platform ) {
			return new WP_Error( 'geek_cube_platform_invalid', __( 'Select a supported game platform.', 'geek-cube-studio' ) );
		}

		$language     = isset( $data['language'] ) && is_scalar( $data['language'] ) ? sanitize_key( (string) $data['language'] ) : 'default';
		$language     = '' !== $language ? $language : 'default';
		$descriptions = array( $language => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '' );
		$titles       = array( $language => $title );
		$now          = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			Geek_Cube_Studio_Schema::table( 'games' ),
			array(
				'uuid'                  => wp_generate_uuid4(),
				'slug'                  => $slug,
				'platform'              => $platform,
				'status'                => 'draft',
				'titles'                => wp_json_encode( $titles ),
				'descriptions'          => wp_json_encode( $descriptions ),
				'cover_attachment_id'   => isset( $data['cover_attachment_id'] ) ? absint( $data['cover_attachment_id'] ) : 0,
				'source_url'            => isset( $data['source_url'] ) ? esc_url_raw( $data['source_url'] ) : '',
				'rights_notes'          => isset( $data['rights_notes'] ) ? sanitize_textarea_field( $data['rights_notes'] ) : '',
				'production_profile_id' => 0,
				'created_by'            => get_current_user_id(),
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return false === $inserted
			? new WP_Error( 'geek_cube_game_insert_failed', self::database_error( __( 'The game could not be created.', 'geek-cube-studio' ) ) )
			: (int) $wpdb->insert_id;
	}

	/**
	 * Create one immutable artifact version.
	 *
	 * @param array<string,mixed> $data Validated artifact data.
	 *
	 * @return int|WP_Error
	 */
	public static function create_artifact( array $data ) {
		global $wpdb;

		$type    = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
		$name    = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$version = isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '';

		if ( ! in_array( $type, self::ARTIFACT_TYPES, true ) || '' === $name || '' === $version ) {
			return new WP_Error( 'geek_cube_artifact_invalid', __( 'Artifact type, name and version are required.', 'geek-cube-studio' ) );
		}

		$sha256 = isset( $data['sha256'] ) ? strtolower( sanitize_text_field( $data['sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return new WP_Error( 'geek_cube_artifact_hash_invalid', __( 'The artifact requires a valid SHA-256 fingerprint.', 'geek-cube-studio' ) );
		}

		$platform = isset( $data['platform'] ) && '' !== $data['platform'] ? self::platform( $data['platform'] ) : '';
		if ( in_array( $type, array( 'core', 'rom', 'bios' ), true ) && '' === $platform ) {
			return new WP_Error( 'geek_cube_artifact_platform_invalid', __( 'Core, ROM and BIOS artifacts require a supported platform.', 'geek-cube-studio' ) );
		}

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			Geek_Cube_Studio_Schema::table( 'artifacts' ),
			array(
				'uuid'            => isset( $data['uuid'] ) ? sanitize_text_field( $data['uuid'] ) : wp_generate_uuid4(),
				'type'            => $type,
				'name'            => $name,
				'version'         => $version,
				'platform'        => $platform,
				'runtime_key'     => isset( $data['runtime_key'] ) ? sanitize_key( $data['runtime_key'] ) : '',
				'source_url'      => isset( $data['source_url'] ) ? esc_url_raw( $data['source_url'] ) : '',
				'license_name'    => isset( $data['license_name'] ) ? sanitize_text_field( $data['license_name'] ) : '',
				'commercial_use'  => self::enum( isset( $data['commercial_use'] ) ? $data['commercial_use'] : '', array( 'yes', 'no', 'review' ), 'review' ),
				'rights_notes'    => isset( $data['rights_notes'] ) ? sanitize_textarea_field( $data['rights_notes'] ) : '',
				'sha256'          => $sha256,
				'file_size'       => isset( $data['file_size'] ) ? absint( $data['file_size'] ) : 0,
				'relative_path'   => isset( $data['relative_path'] ) ? sanitize_text_field( $data['relative_path'] ) : '',
				'entrypoint_path' => isset( $data['entrypoint_path'] ) ? sanitize_text_field( $data['entrypoint_path'] ) : '',
				'status'          => 'pending',
				'created_by'      => get_current_user_id(),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false === $inserted
			? new WP_Error( 'geek_cube_artifact_insert_failed', self::database_error( __( 'The artifact could not be registered.', 'geek-cube-studio' ) ) )
			: (int) $wpdb->insert_id;
	}

	/**
	 * Create an immutable execution profile after cross-artifact validation.
	 *
	 * @param array<string,mixed> $data Profile data.
	 *
	 * @return int|WP_Error
	 */
	public static function create_profile( array $data ) {
		global $wpdb;

		$game = self::get_game( isset( $data['game_id'] ) ? absint( $data['game_id'] ) : 0 );
		if ( ! $game ) {
			return new WP_Error( 'geek_cube_profile_game_missing', __( 'Select an existing game.', 'geek-cube-studio' ) );
		}

		$artifacts = array(
			'player'   => self::get_artifact( isset( $data['player_artifact_id'] ) ? absint( $data['player_artifact_id'] ) : 0 ),
			'core'     => self::get_artifact( isset( $data['core_artifact_id'] ) ? absint( $data['core_artifact_id'] ) : 0 ),
			'rom'      => self::get_artifact( isset( $data['rom_artifact_id'] ) ? absint( $data['rom_artifact_id'] ) : 0 ),
			'bios'     => self::get_artifact( isset( $data['bios_artifact_id'] ) ? absint( $data['bios_artifact_id'] ) : 0 ),
			'config'   => self::get_artifact( isset( $data['config_artifact_id'] ) ? absint( $data['config_artifact_id'] ) : 0 ),
			'controls' => self::get_artifact( isset( $data['controls_artifact_id'] ) ? absint( $data['controls_artifact_id'] ) : 0 ),
		);

		$valid = self::validate_profile_artifacts( $game, $artifacts );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$slug = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $name );
		if ( '' === $name || '' === $slug ) {
			return new WP_Error( 'geek_cube_profile_identity_invalid', __( 'Profile name and slug are required.', 'geek-cube-studio' ) );
		}

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			Geek_Cube_Studio_Schema::table( 'profiles' ),
			array(
				'uuid'                 => wp_generate_uuid4(),
				'name'                 => $name,
				'slug'                 => $slug,
				'game_id'              => (int) $game['id'],
				'player_artifact_id'   => (int) $artifacts['player']['id'],
				'core_artifact_id'     => (int) $artifacts['core']['id'],
				'rom_artifact_id'      => (int) $artifacts['rom']['id'],
				'bios_artifact_id'     => $artifacts['bios'] ? (int) $artifacts['bios']['id'] : 0,
				'config_artifact_id'   => $artifacts['config'] ? (int) $artifacts['config']['id'] : 0,
				'controls_artifact_id' => $artifacts['controls'] ? (int) $artifacts['controls']['id'] : 0,
				'status'               => 'ready',
				'created_by'           => get_current_user_id(),
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		return false === $inserted
			? new WP_Error( 'geek_cube_profile_insert_failed', self::database_error( __( 'The execution profile could not be created.', 'geek-cube-studio' ) ) )
			: (int) $wpdb->insert_id;
	}

	/**
	 * Store one immutable completed test run.
	 *
	 * @param array<string,mixed> $data Test data.
	 *
	 * @return int|WP_Error
	 */
	public static function create_test_run( array $data ) {
		global $wpdb;

		$profile = self::get_profile( isset( $data['profile_id'] ) ? absint( $data['profile_id'] ) : 0 );
		$result  = self::enum( isset( $data['result'] ) ? $data['result'] : '', array( 'passed', 'failed', 'inconclusive' ), '' );
		if ( ! $profile || '' === $result ) {
			return new WP_Error( 'geek_cube_test_invalid', __( 'The test requires a valid profile and final result.', 'geek-cube-studio' ) );
		}

		$checklist = array();
		$raw_items = isset( $data['checklist'] ) && is_array( $data['checklist'] ) ? $data['checklist'] : array();
		foreach ( self::TEST_CHECKLIST as $item ) {
			$checklist[ $item ] = self::enum( isset( $raw_items[ $item ] ) ? $raw_items[ $item ] : '', array( 'passed', 'failed', 'na', 'not_tested' ), 'not_tested' );
		}
		if ( 'passed' === $result ) {
			foreach ( array( 'boot', 'video', 'audio' ) as $required_check ) {
				if ( 'passed' !== $checklist[ $required_check ] ) {
					return new WP_Error( 'geek_cube_test_required_check_failed', __( 'A passing test requires boot, video and audio to pass.', 'geek-cube-studio' ) );
				}
			}

			if ( in_array( 'failed', $checklist, true ) ) {
				return new WP_Error( 'geek_cube_test_checklist_failed', __( 'A test with a failed checklist item cannot receive a passing result.', 'geek-cube-studio' ) );
			}
		}

		$environment = isset( $data['environment'] ) && is_array( $data['environment'] ) ? $data['environment'] : array();
		$metrics     = isset( $data['metrics'] ) && is_array( $data['metrics'] ) ? $data['metrics'] : array();
		$inserted    = $wpdb->insert(
			Geek_Cube_Studio_Schema::table( 'test_runs' ),
			array(
				'uuid'        => wp_generate_uuid4(),
				'profile_id'  => (int) $profile['id'],
				'result'      => $result,
				'checklist'   => wp_json_encode( $checklist ),
				'metrics'     => wp_json_encode( self::sanitize_map( $metrics ) ),
				'environment' => wp_json_encode( self::sanitize_map( $environment ) ),
				'notes'       => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '',
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'geek_cube_test_insert_failed', self::database_error( __( 'The test result could not be recorded.', 'geek-cube-studio' ) ) );
		}

		if ( in_array( $profile['status'], array( 'ready', 'testing' ), true ) ) {
			self::update_profile_status( (int) $profile['id'], 'testing' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Validate all artifact roles before freezing a profile.
	 *
	 * @param array<string,mixed>                    $game      Game row.
	 * @param array<string,array<string,mixed>|null> $artifacts Artifacts by role.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_profile_artifacts( array $game, array $artifacts ) {
		foreach ( array( 'player', 'core', 'rom' ) as $required ) {
			if ( empty( $artifacts[ $required ] ) || $required !== $artifacts[ $required ]['type'] ) {
				return new WP_Error( 'geek_cube_profile_artifact_missing', sprintf( /* translators: %s: artifact role. */ __( 'A verified %s artifact is required.', 'geek-cube-studio' ), $required ) );
			}
		}

		foreach ( $artifacts as $role => $artifact ) {
			if ( ! $artifact ) {
				continue;
			}

			if ( $role !== $artifact['type'] ) {
				return new WP_Error( 'geek_cube_profile_artifact_type', __( 'An artifact was assigned to the wrong profile role.', 'geek-cube-studio' ) );
			}

			if ( 'verified' !== $artifact['status'] ) {
				return new WP_Error( 'geek_cube_profile_artifact_unverified', __( 'Every profile artifact must be verified first.', 'geek-cube-studio' ) );
			}

			if ( in_array( $role, array( 'core', 'rom', 'bios' ), true ) && '' !== $artifact['platform'] && $artifact['platform'] !== $game['platform'] ) {
				return new WP_Error( 'geek_cube_profile_platform_mismatch', __( 'The game and its emulation artifacts must use the same platform.', 'geek-cube-studio' ) );
			}
		}

		if ( '' === $artifacts['player']['entrypoint_path'] ) {
			return new WP_Error( 'geek_cube_profile_player_invalid', __( 'The player package has no validated loader entrypoint.', 'geek-cube-studio' ) );
		}

		if ( '' === $artifacts['core']['runtime_key'] || '' === $artifacts['rom']['relative_path'] ) {
			return new WP_Error( 'geek_cube_profile_runtime_invalid', __( 'The core runtime key and ROM file are required.', 'geek-cube-studio' ) );
		}

		return true;
	}

	/**
	 * Update artifact review status without changing immutable metadata.
	 *
	 * @param int    $artifact_id Artifact ID.
	 * @param string $status      Target status.
	 *
	 * @return true|WP_Error
	 */
	public static function update_artifact_status( $artifact_id, $status ) {
		global $wpdb;

		$artifact = self::get_artifact( $artifact_id );
		$status   = self::enum( $status, array( 'pending', 'verified', 'blocked', 'deprecated' ), '' );
		if ( ! $artifact || '' === $status ) {
			return new WP_Error( 'geek_cube_artifact_status_invalid', __( 'The artifact status change is invalid.', 'geek-cube-studio' ) );
		}

		if ( 'verified' === $status && ( 'yes' !== $artifact['commercial_use'] || '' === $artifact['license_name'] || '' === $artifact['source_url'] ) ) {
			return new WP_Error( 'geek_cube_artifact_rights_incomplete', __( 'Verification requires source, license and confirmed commercial use.', 'geek-cube-studio' ) );
		}
		if ( 'verified' === $status && '' !== $artifact['relative_path'] ) {
			$path = Geek_Cube_Studio_Artifact_Storage::path( $artifact['relative_path'] );
			$hash = '' !== $path && is_file( $path ) ? hash_file( 'sha256', $path ) : false;
			if ( false === $hash || ! hash_equals( $artifact['sha256'], $hash ) ) {
				return new WP_Error( 'geek_cube_artifact_file_invalid', __( 'The stored file is missing or no longer matches its SHA-256 fingerprint.', 'geek-cube-studio' ) );
			}
		}
		if ( 'verified' === $status && '' === $artifact['relative_path'] && ( 'core' !== $artifact['type'] || '' === $artifact['runtime_key'] ) ) {
			return new WP_Error( 'geek_cube_artifact_file_missing', __( 'This artifact type requires an immutable stored file.', 'geek-cube-studio' ) );
		}
		if ( 'verified' === $status && 'player' === $artifact['type'] ) {
			$entrypoint = Geek_Cube_Studio_Artifact_Storage::path( $artifact['entrypoint_path'] );
			if ( '' === $entrypoint || ! is_file( $entrypoint ) ) {
				return new WP_Error( 'geek_cube_player_entrypoint_missing', __( 'The player loader entrypoint is missing from immutable storage.', 'geek-cube-studio' ) );
			}
		}

		$updated = $wpdb->update(
			Geek_Cube_Studio_Schema::table( 'artifacts' ),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $artifact['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? new WP_Error( 'geek_cube_artifact_status_failed', self::database_error( __( 'Artifact status could not be updated.', 'geek-cube-studio' ) ) ) : true;
	}

	/**
	 * Approve a profile only after at least one passing immutable test.
	 *
	 * @param int $profile_id Profile ID.
	 *
	 * @return true|WP_Error
	 */
	public static function approve_profile( $profile_id ) {
		$profile = self::get_profile( $profile_id );
		if ( ! $profile || ! in_array( $profile['status'], array( 'testing', 'approved' ), true ) || ! self::profile_has_passed_test( $profile_id ) ) {
			return new WP_Error( 'geek_cube_profile_not_tested', __( 'A profile needs at least one passing test before approval.', 'geek-cube-studio' ) );
		}
		if ( 'approved' === $profile['status'] ) {
			return true;
		}

		$game  = self::get_game( $profile['game_id'] );
		$valid = $game ? self::validate_profile_artifacts( $game, self::get_profile_artifacts( $profile ) ) : new WP_Error( 'geek_cube_profile_game_missing', __( 'The profile game no longer exists.', 'geek-cube-studio' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return self::update_profile_status( $profile_id, 'approved' );
	}

	/**
	 * Promote an approved profile and publish its game.
	 *
	 * @param int $profile_id Profile ID.
	 *
	 * @return true|WP_Error
	 */
	public static function promote_profile( $profile_id ) {
		global $wpdb;

		$profile = self::get_profile( $profile_id );
		if ( ! $profile || 'approved' !== $profile['status'] ) {
			return new WP_Error( 'geek_cube_profile_not_approved', __( 'Only an approved profile can be promoted to production.', 'geek-cube-studio' ) );
		}

		$game  = self::get_game( $profile['game_id'] );
		$valid = $game ? self::validate_profile_artifacts( $game, self::get_profile_artifacts( $profile ) ) : new WP_Error( 'geek_cube_profile_game_missing', __( 'The profile game no longer exists.', 'geek-cube-studio' ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$profiles_table = Geek_Cube_Studio_Schema::table( 'profiles' );
		$games_table    = Geek_Cube_Studio_Schema::table( 'games' );
		$now            = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic custom-table promotion.
		$previous     = $wpdb->update(
			$profiles_table,
			array(
				'status'     => 'approved',
				'updated_at' => $now,
			),
			array(
				'game_id' => (int) $profile['game_id'],
				'status'  => 'production',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		$promoted     = $wpdb->update(
			$profiles_table,
			array(
				'status'     => 'production',
				'updated_at' => $now,
			),
			array( 'id' => (int) $profile['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$game_updated = $wpdb->update(
			$games_table,
			array(
				'production_profile_id' => (int) $profile['id'],
				'status'                => 'published',
				'updated_at'            => $now,
			),
			array( 'id' => (int) $profile['game_id'] ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $previous || false === $promoted || false === $game_updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic custom-table promotion.
			return new WP_Error( 'geek_cube_profile_promotion_failed', self::database_error( __( 'The profile promotion could not be completed.', 'geek-cube-studio' ) ) );
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic custom-table promotion.
		return true;
	}

	/**
	 * Return recent games.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_games( $limit = 100 ) {
		return self::get_rows( 'games', $limit );
	}

	/**
	 * Return recent artifacts.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_artifacts( $limit = 200 ) {
		return self::get_rows( 'artifacts', $limit );
	}

	/**
	 * Return recent profiles.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_profiles( $limit = 100 ) {
		return self::get_rows( 'profiles', $limit );
	}

	/**
	 * Return recent test runs.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_test_runs( $limit = 100 ) {
		return self::get_rows( 'test_runs', $limit );
	}

	/**
	 * Return one game by ID.
	 *
	 * @param int $id Game ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_game( $id ) {
		return self::get_row( 'games', 'id', absint( $id ) );
	}

	/**
	 * Return one game by canonical slug.
	 *
	 * @param string $slug Canonical slug.
	 * @return array<string,mixed>|null
	 */
	public static function get_game_by_slug( $slug ) {
		return self::get_row( 'games', 'slug', sanitize_title( $slug ) );
	}

	/**
	 * Return one artifact by ID.
	 *
	 * @param int $id Artifact ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_artifact( $id ) {
		return self::get_row( 'artifacts', 'id', absint( $id ) );
	}

	/**
	 * Return one artifact by its immutable catalog identity.
	 *
	 * @param string $type    Artifact type.
	 * @param string $name    Artifact name.
	 * @param string $version Artifact version.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_artifact_by_identity( $type, $name, $version ) {
		global $wpdb;

		$table = Geek_Cube_Studio_Schema::table( 'artifacts' );
		$sql   = $wpdb->prepare(
			'SELECT * FROM %i WHERE type = %s AND name = %s AND version = %s LIMIT 1',
			$table,
			sanitize_key( (string) $type ),
			sanitize_text_field( (string) $name ),
			sanitize_text_field( (string) $version )
		);
		$row   = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared custom-table lookup.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Return one profile by ID.
	 *
	 * @param int $id Profile ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_profile( $id ) {
		return self::get_row( 'profiles', 'id', absint( $id ) );
	}

	/**
	 * Return one profile by UUID.
	 *
	 * @param string $uuid Profile UUID.
	 * @return array<string,mixed>|null
	 */
	public static function get_profile_by_uuid( $uuid ) {
		return self::get_row( 'profiles', 'uuid', sanitize_text_field( $uuid ) );
	}

	/**
	 * Decode one language map and choose the requested or first value.
	 *
	 * @param string $json     Stored JSON map.
	 * @param string $language Desired language.
	 *
	 * @return string
	 */
	public static function translated_value( $json, $language = '' ) {
		$values = json_decode( (string) $json, true );
		if ( ! is_array( $values ) || empty( $values ) ) {
			return '';
		}

		$language = '' !== $language ? sanitize_key( $language ) : Geek_Cube_Studio_URLs::get_current_language();
		if ( isset( $values[ $language ] ) ) {
			return (string) $values[ $language ];
		}

		if ( isset( $values['default'] ) ) {
			return (string) $values['default'];
		}

		return (string) reset( $values );
	}

	/**
	 * Return profile artifacts indexed by their role.
	 *
	 * @param array<string,mixed> $profile Profile row.
	 *
	 * @return array<string,array<string,mixed>|null>
	 */
	public static function get_profile_artifacts( array $profile ) {
		return array(
			'player'   => self::get_artifact( $profile['player_artifact_id'] ),
			'core'     => self::get_artifact( $profile['core_artifact_id'] ),
			'rom'      => self::get_artifact( $profile['rom_artifact_id'] ),
			'bios'     => self::get_artifact( $profile['bios_artifact_id'] ),
			'config'   => self::get_artifact( $profile['config_artifact_id'] ),
			'controls' => self::get_artifact( $profile['controls_artifact_id'] ),
		);
	}

	/**
	 * Check whether one profile has a passing test.
	 *
	 * @param int $profile_id Profile ID.
	 * @return bool
	 */
	public static function profile_has_passed_test( $profile_id ) {
		global $wpdb;

		$table = Geek_Cube_Studio_Schema::table( 'test_runs' );
		$sql   = $wpdb->prepare( 'SELECT id FROM %i WHERE profile_id = %d AND result = %s LIMIT 1', $table, absint( $profile_id ), 'passed' );
		$id    = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared custom-table lookup.

		return 0 < (int) $id;
	}

	/**
	 * Update only the mutable profile lifecycle status.
	 *
	 * @param int    $profile_id Profile ID.
	 * @param string $status     New status.
	 * @return true|WP_Error
	 */
	private static function update_profile_status( $profile_id, $status ) {
		global $wpdb;

		$status = self::enum( $status, array( 'ready', 'testing', 'approved', 'production', 'deprecated' ), '' );
		if ( '' === $status ) {
			return new WP_Error( 'geek_cube_profile_status_invalid', __( 'The profile status is invalid.', 'geek-cube-studio' ) );
		}

		$updated = $wpdb->update(
			Geek_Cube_Studio_Schema::table( 'profiles' ),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $profile_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? new WP_Error( 'geek_cube_profile_status_failed', self::database_error( __( 'Profile status could not be updated.', 'geek-cube-studio' ) ) ) : true;
	}

	/**
	 * Return one custom-table row by allow-listed identity field.
	 *
	 * @param string     $entity Entity name.
	 * @param string     $field  Identity field.
	 * @param int|string $value  Identity value.
	 * @return array<string,mixed>|null
	 */
	private static function get_row( $entity, $field, $value ) {
		global $wpdb;

		if ( ! in_array( $entity, array( 'games', 'artifacts', 'profiles', 'test_runs' ), true ) || ! in_array( $field, array( 'id', 'uuid', 'slug' ), true ) || '' === (string) $value || '0' === (string) $value ) {
			return null;
		}

		$table = Geek_Cube_Studio_Schema::table( $entity );
		$sql   = $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $table, $field, (string) $value );
		$row   = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared custom-table lookup.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Return recent rows from one allow-listed custom table.
	 *
	 * @param string $entity Entity name.
	 * @param int    $limit  Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_rows( $entity, $limit ) {
		global $wpdb;

		if ( ! in_array( $entity, array( 'games', 'artifacts', 'profiles', 'test_runs' ), true ) ) {
			return array();
		}

		$table = Geek_Cube_Studio_Schema::table( $entity );
		$sql   = $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, max( 1, min( 500, absint( $limit ) ) ) );
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared bounded custom-table list.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Normalize one enum-like scalar.
	 *
	 * @param mixed    $value    Raw value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Fallback value.
	 * @return string
	 */
	private static function enum( $value, array $allowed, $fallback ) {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Sanitize a shallow diagnostics map.
	 *
	 * @param array<string,mixed> $map Raw map.
	 * @return array<string,string>
	 */
	private static function sanitize_map( array $map ) {
		$output = array();
		foreach ( $map as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$output[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		return $output;
	}

	/**
	 * Append the database error without exposing SQL.
	 *
	 * @param string $fallback Fallback message.
	 * @return string
	 */
	private static function database_error( $fallback ) {
		global $wpdb;

		return '' !== (string) $wpdb->last_error ? sanitize_text_field( $wpdb->last_error ) : $fallback;
	}

	/**
	 * Return a validated platform identifier.
	 *
	 * @param mixed $platform Raw platform.
	 * @return string
	 */
	private static function platform( $platform ) {
		$platform = is_scalar( $platform ) ? sanitize_text_field( (string) $platform ) : '';

		return in_array( $platform, self::PLATFORMS, true ) ? $platform : '';
	}
}
