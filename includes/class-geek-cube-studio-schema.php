<?php
/**
 * Database schema for the Geek Cube Studio catalog and laboratory.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates versioned product data tables through the permanent patch runner.
 */
final class Geek_Cube_Studio_Schema {

	/** Schema version stored for diagnostics. */
	const VERSION = '1';

	/** Schema version option. */
	const VERSION_OPTION = 'geek_cube_studio_schema_version';

	/**
	 * Whether patch registration was initialized.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register the permanent schema patch.
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
	 * Add the schema migration to the patch inventory.
	 *
	 * @param array<string,array<string,mixed>> $patches Existing patches.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_patch( array $patches ) {
		$patches['001-create-content-schema'] = array(
			'introduced_in' => '0.1.3',
			'description'   => 'Create immutable catalog, artifact, profile and laboratory tables.',
			'callback'      => array( __CLASS__, 'install' ),
		);

		return $patches;
	}

	/**
	 * Resolve one owned table name.
	 *
	 * @param string $name Logical table name.
	 *
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		$allowed = array( 'games', 'artifacts', 'profiles', 'test_runs' );
		$name    = sanitize_key( (string) $name );

		return in_array( $name, $allowed, true ) ? $wpdb->prefix . 'geek_cube_' . $name : '';
	}

	/**
	 * Create or update all owned tables idempotently.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return new WP_Error( 'geek_cube_database_unavailable', __( 'WordPress database is unavailable.', 'geek-cube-studio' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$games           = self::table( 'games' );
		$artifacts       = self::table( 'artifacts' );
		$profiles        = self::table( 'profiles' );
		$test_runs       = self::table( 'test_runs' );

		$sql = array(
			"CREATE TABLE {$games} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				slug varchar(191) NOT NULL,
				platform varchar(32) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				titles longtext NOT NULL,
				descriptions longtext NOT NULL,
				cover_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_url text NOT NULL,
				rights_notes longtext NOT NULL,
				production_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY slug (slug),
				KEY platform (platform),
				KEY status (status),
				KEY production_profile_id (production_profile_id)
			) {$charset_collate};",
			"CREATE TABLE {$artifacts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				type varchar(20) NOT NULL,
				name varchar(191) NOT NULL,
				version varchar(100) NOT NULL,
				platform varchar(32) NOT NULL DEFAULT '',
				runtime_key varchar(100) NOT NULL DEFAULT '',
				source_url text NOT NULL,
				license_name varchar(191) NOT NULL,
				commercial_use varchar(20) NOT NULL DEFAULT 'review',
				rights_notes longtext NOT NULL,
				sha256 char(64) NOT NULL,
				file_size bigint(20) unsigned NOT NULL DEFAULT 0,
				relative_path text NOT NULL,
				entrypoint_path text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY artifact_identity (type,name(100),version(50)),
				KEY sha256 (sha256),
				KEY status (status),
				KEY platform (platform)
			) {$charset_collate};",
			"CREATE TABLE {$profiles} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				game_id bigint(20) unsigned NOT NULL,
				player_artifact_id bigint(20) unsigned NOT NULL,
				core_artifact_id bigint(20) unsigned NOT NULL,
				rom_artifact_id bigint(20) unsigned NOT NULL,
				bios_artifact_id bigint(20) unsigned NOT NULL DEFAULT 0,
				config_artifact_id bigint(20) unsigned NOT NULL DEFAULT 0,
				controls_artifact_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'ready',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY slug (slug),
				KEY game_id (game_id),
				KEY status (status)
			) {$charset_collate};",
			"CREATE TABLE {$test_runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				profile_id bigint(20) unsigned NOT NULL,
				result varchar(20) NOT NULL,
				checklist longtext NOT NULL,
				metrics longtext NOT NULL,
				environment longtext NOT NULL,
				notes longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY profile_id (profile_id),
				KEY result (result),
				KEY created_at (created_at)
			) {$charset_collate};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'geek_cube_schema_failed', sanitize_text_field( $wpdb->last_error ) );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );

		return true;
	}
}
