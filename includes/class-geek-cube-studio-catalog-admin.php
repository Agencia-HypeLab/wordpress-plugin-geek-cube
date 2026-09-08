<?php
/**
 * Operational catalog and laboratory screens.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns administrator actions for immutable catalog data.
 */
final class Geek_Cube_Studio_Catalog_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Geek_Cube_Studio_Catalog_Admin|null
	 */
	private static $instance = null;

	/**
	 * Register hooks once.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
	}

	/**
	 * Connect screens and form handlers.
	 *
	 * @return void
	 */
	private function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_post_geek_cube_create_game', array( $this, 'create_game' ) );
		add_action( 'admin_post_geek_cube_update_game', array( $this, 'update_game' ) );
		add_action( 'admin_post_geek_cube_analyze_artifact', array( $this, 'analyze_artifact' ) );
		add_action( 'geek_cube_studio_cleanup_artifact_draft', array( $this, 'cleanup_expired_artifact_draft' ) );
		add_action( 'admin_post_geek_cube_create_artifact', array( $this, 'create_artifact' ) );
		add_action( 'admin_post_geek_cube_update_artifact_name', array( $this, 'update_artifact_name' ) );
		add_action( 'admin_post_geek_cube_update_core_runtime_key', array( $this, 'update_core_runtime_key' ) );
		add_action( 'admin_post_geek_cube_artifact_status', array( $this, 'update_artifact_status' ) );
		add_action( 'admin_post_geek_cube_create_profile', array( $this, 'create_profile' ) );
		add_action( 'admin_post_geek_cube_record_test', array( $this, 'record_test' ) );
		add_action( 'admin_post_geek_cube_approve_profile', array( $this, 'approve_profile' ) );
		add_action( 'admin_post_geek_cube_promote_profile', array( $this, 'promote_profile' ) );
	}

	/**
	 * Add operational screens under the existing product menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page( 'geek-cube-studio', __( 'Games', 'geek-cube-studio' ), __( 'Games', 'geek-cube-studio' ), 'manage_options', 'geek-cube-studio-games', array( $this, 'render_games' ) );
		add_submenu_page( 'geek-cube-studio', __( 'Artifacts', 'geek-cube-studio' ), __( 'Artifacts', 'geek-cube-studio' ), 'manage_options', 'geek-cube-studio-artifacts', array( $this, 'render_artifacts' ) );
		add_submenu_page( 'geek-cube-studio', __( 'Execution profiles', 'geek-cube-studio' ), __( 'Profiles', 'geek-cube-studio' ), 'manage_options', 'geek-cube-studio-profiles', array( $this, 'render_profiles' ) );
		add_submenu_page( 'geek-cube-studio', __( 'Compatibility laboratory', 'geek-cube-studio' ), __( 'Laboratory', 'geek-cube-studio' ), 'manage_options', 'geek-cube-studio-laboratory', array( $this, 'render_laboratory' ) );
	}

	/** Render games screen. */
	public function render_games() {
		$this->authorize();
		$schema_ready          = $this->schema_ready();
		$games                 = $schema_ready ? Geek_Cube_Studio_Repository::get_games() : array();
		$production_candidates = array();

		foreach ( $games as $game ) {
			$production_candidates[ (int) $game['id'] ] = Geek_Cube_Studio_Repository::get_approved_profiles_for_game( $game['id'] );
		}
		require GEEK_CUBE_STUDIO_PLUGIN_DIR . 'views/admin/games.php';
	}

	/** Render artifacts screen. */
	public function render_artifacts() {
		$this->authorize();
		$schema_ready    = $this->schema_ready();
		$artifact_type   = self::resolve_artifact_type( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen filter.
		$artifact_draft  = $this->get_artifact_draft( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only opaque draft selector.
		$artifact_draft  = $artifact_draft && $artifact_type === $artifact_draft['type'] ? $artifact_draft : null;
		$artifact_tabs   = self::artifact_tabs();
		$all_artifacts   = $schema_ready ? Geek_Cube_Studio_Repository::get_artifacts() : array();
		$artifact_counts = array_fill_keys( array_keys( $artifact_tabs ), 0 );

		foreach ( $all_artifacts as $artifact ) {
			$type = isset( $artifact['type'] ) ? sanitize_key( (string) $artifact['type'] ) : '';

			if ( isset( $artifact_counts[ $type ] ) ) {
				++$artifact_counts[ $type ];
			}
		}

		$artifacts = array_values(
			array_filter(
				$all_artifacts,
				static function ( $artifact ) use ( $artifact_type ) {
					return is_array( $artifact ) && isset( $artifact['type'] ) && $artifact_type === $artifact['type'];
				}
			)
		);
		require GEEK_CUBE_STUDIO_PLUGIN_DIR . 'views/admin/artifacts.php';
	}

	/**
	 * Return the artifact categories shown as tabs on the operational screen.
	 *
	 * @return array<string,string>
	 */
	public static function artifact_tabs() {
		$tabs = array();

		foreach ( Geek_Cube_Studio_Repository::ARTIFACT_TYPES as $type ) {
			$tabs[ $type ] = self::artifact_type_label( $type );
		}

		return $tabs;
	}

	/**
	 * Resolve an allow-listed artifact category from a read-only request.
	 *
	 * @param mixed $request Request data.
	 * @return string
	 */
	public static function resolve_artifact_type( $request ) {
		$raw   = is_array( $request ) && isset( $request['artifact_type'] ) ? $request['artifact_type'] : ( is_array( $request ) && isset( $request['type'] ) ? $request['type'] : '' );
		$value = is_scalar( $raw )
			? sanitize_key( (string) $raw )
			: 'player';

		return isset( self::artifact_tabs()[ $value ] ) ? $value : 'player';
	}

	/**
	 * Return the localized label for a stable artifact type identifier.
	 *
	 * @param string $type Artifact type identifier.
	 * @return string
	 */
	public static function artifact_type_label( $type ) {
		$labels = array(
			'player'   => __( 'Player', 'geek-cube-studio' ),
			'core'     => __( 'Core', 'geek-cube-studio' ),
			'rom'      => __( 'ROM', 'geek-cube-studio' ),
			'bios'     => __( 'BIOS', 'geek-cube-studio' ),
			'patch'    => __( 'Patch', 'geek-cube-studio' ),
			'config'   => __( 'Configuration', 'geek-cube-studio' ),
			'controls' => __( 'Controls', 'geek-cube-studio' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : strtoupper( $type );
	}

	/**
	 * Return the localized label for a stable workflow status identifier.
	 *
	 * @param string $status Workflow status identifier.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending'      => __( 'Pending', 'geek-cube-studio' ),
			'verified'     => __( 'Verified', 'geek-cube-studio' ),
			'blocked'      => __( 'Blocked', 'geek-cube-studio' ),
			'deprecated'   => __( 'Deprecated', 'geek-cube-studio' ),
			'draft'        => __( 'Draft', 'geek-cube-studio' ),
			'testing'      => __( 'Testing', 'geek-cube-studio' ),
			'approved'     => __( 'Approved', 'geek-cube-studio' ),
			'production'   => __( 'Production', 'geek-cube-studio' ),
			'published'    => __( 'Published', 'geek-cube-studio' ),
			'passed'       => __( 'Passed', 'geek-cube-studio' ),
			'failed'       => __( 'Failed', 'geek-cube-studio' ),
			'inconclusive' => __( 'Inconclusive', 'geek-cube-studio' ),
			'completed'    => __( 'Completed', 'geek-cube-studio' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/** Render profiles screen. */
	public function render_profiles() {
		$this->authorize();
		$schema_ready  = $this->schema_ready();
		$games         = $schema_ready ? Geek_Cube_Studio_Repository::get_games() : array();
		$artifacts     = $schema_ready ? Geek_Cube_Studio_Repository::get_artifacts() : array();
		$profiles      = $schema_ready ? Geek_Cube_Studio_Repository::get_profiles() : array();
		$profile_draft = $schema_ready ? get_transient( self::profile_draft_key( get_current_user_id() ) ) : array();
		$profile_draft = is_array( $profile_draft ) ? $profile_draft : array();
		require GEEK_CUBE_STUDIO_PLUGIN_DIR . 'views/admin/profiles.php';
	}

	/** Render laboratory screen. */
	public function render_laboratory() {
		$this->authorize();
		$schema_ready = $this->schema_ready();
		$profiles     = $schema_ready ? Geek_Cube_Studio_Repository::get_profiles() : array();
		$test_runs    = $schema_ready ? Geek_Cube_Studio_Repository::get_test_runs() : array();
		$profile_id   = isset( $_GET['profile_id'] ) ? absint( wp_unslash( $_GET['profile_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		$profile      = $schema_ready && $profile_id ? Geek_Cube_Studio_Repository::get_profile( $profile_id ) : null;
		$lab_url      = $profile ? Geek_Cube_Studio_URLs::build( 'lab', $profile['uuid'] ) : '';
		require GEEK_CUBE_STUDIO_PLUGIN_DIR . 'views/admin/laboratory.php';
	}

	/** Handle game creation. */
	public function create_game() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_create_game' );
		$result = Geek_Cube_Studio_Repository::create_game( wp_unslash( $_POST ) );
		$game   = ! is_wp_error( $result ) ? Geek_Cube_Studio_Repository::get_game( $result ) : null;
		$this->finish( 'geek-cube-studio-games', $result, $game ? self::game_saved_notice( $game['slug'] ) : __( 'Game registered.', 'geek-cube-studio' ) );
	}

	/** Handle mutable game metadata changes. */
	public function update_game() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_update_game' );
		$game_id = isset( $_POST['game_id'] ) ? absint( wp_unslash( $_POST['game_id'] ) ) : 0;
		$result  = Geek_Cube_Studio_Repository::update_game( $game_id, wp_unslash( $_POST ) );
		$game    = ! is_wp_error( $result ) ? Geek_Cube_Studio_Repository::get_game( $game_id ) : null;
		$this->finish( 'geek-cube-studio-games', $result, $game ? self::game_saved_notice( $game['slug'] ) : __( 'Game updated.', 'geek-cube-studio' ) );
	}

	/** Handle immutable artifact import. */
	public function analyze_artifact() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_analyze_artifact' );

		$type  = self::resolve_artifact_type( $_POST );
		$file  = isset( $_FILES['artifact_file'] ) && is_array( $_FILES['artifact_file'] ) ? $_FILES['artifact_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Storage validates upload fields and bytes.
		$draft = Geek_Cube_Studio_Artifact_Storage::stage_upload( $file, $type );
		$extra = array( 'artifact_type' => $type );

		if ( is_wp_error( $draft ) ) {
			$this->finish( 'geek-cube-studio-artifacts', $draft, '', $extra );
		}

		$draft['owner_id'] = get_current_user_id();
		set_transient( self::artifact_draft_key( $draft['token'] ), $draft, Geek_Cube_Studio_Artifact_Storage::STAGED_UPLOAD_TTL );
		wp_schedule_single_event( time() + Geek_Cube_Studio_Artifact_Storage::STAGED_UPLOAD_TTL, 'geek_cube_studio_cleanup_artifact_draft', array( $draft['token'] ) );
		$extra['artifact_draft'] = $draft['token'];
		$this->finish( 'geek-cube-studio-artifacts', true, __( 'File analyzed. Review the suggested metadata before saving the immutable artifact.', 'geek-cube-studio' ), $extra );
	}

	/** Handle immutable artifact import. */
	public function create_artifact() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_create_artifact' );

		$type             = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$artifact_name    = isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '';
		$artifact_version = isset( $_POST['version'] ) ? wp_unslash( $_POST['version'] ) : '';
		$platform         = isset( $_POST['platform'] ) ? wp_unslash( $_POST['platform'] ) : '';
		$artifact_name    = is_scalar( $artifact_name ) ? sanitize_text_field( (string) $artifact_name ) : '';
		$artifact_version = is_scalar( $artifact_version ) ? sanitize_text_field( (string) $artifact_version ) : '';
		$platform         = is_scalar( $platform ) ? sanitize_key( (string) $platform ) : '';
		$draft            = $this->get_artifact_draft( $_POST );
		$auto_create_game = 'rom' === $type && isset( $_POST['create_draft_game'] ) && is_scalar( $_POST['create_draft_game'] ) && '1' === (string) wp_unslash( $_POST['create_draft_game'] );
		$game_slug        = sanitize_title( $artifact_name );
		$existing_game    = $auto_create_game && '' !== $game_slug ? Geek_Cube_Studio_Repository::get_game_by_slug( $game_slug ) : null;
		if ( $draft && ( $type !== $draft['type'] || ( ! empty( $draft['platform_locked'] ) && $platform !== $draft['platform'] ) ) ) {
			$this->finish( 'geek-cube-studio-artifacts', new WP_Error( 'geek_cube_stage_metadata_invalid', __( 'The analyzed file metadata does not match this submission. Analyze the file again.', 'geek-cube-studio' ) ), '', array( 'artifact_type' => $type ) );
		}
		if ( $auto_create_game && ( '' === $game_slug || 'laboratorio' === $game_slug ) ) {
			$extra = array( 'artifact_type' => $type );
			if ( is_array( $draft ) && isset( $draft['token'] ) ) {
				$extra['artifact_draft'] = $draft['token'];
			}
			$this->finish( 'geek-cube-studio-artifacts', new WP_Error( 'geek_cube_auto_game_invalid', __( 'The ROM name cannot create a valid game slug. Adjust the name and try again.', 'geek-cube-studio' ) ), '', $extra );
		}
		$uuid   = wp_generate_uuid4();
		$stored = $draft ? Geek_Cube_Studio_Artifact_Storage::store_staged( $draft, $artifact_name, $artifact_version, $platform ) : new WP_Error( 'geek_cube_stage_missing', __( 'Analyze a file before saving an artifact.', 'geek-cube-studio' ) );

		if ( is_wp_error( $stored ) ) {
			$this->finish( 'geek-cube-studio-artifacts', $stored );
		}

		$data = array_merge( wp_unslash( $_POST ), $stored, array( 'uuid' => $uuid ) );
		unset( $data['absolute_path'], $data['absolute_root'] );
		$result = Geek_Cube_Studio_Repository::create_artifact( $data );

		if ( is_wp_error( $result ) ) {
			Geek_Cube_Studio_Artifact_Storage::cleanup( $stored );
			$this->finish( 'geek-cube-studio-artifacts', $result, '', array( 'artifact_type' => self::resolve_artifact_type( array( 'artifact_type' => $type ) ) ) );
		}

		Geek_Cube_Studio_Artifact_Storage::cleanup_staged( $draft );
		delete_transient( self::artifact_draft_key( $draft['token'] ) );
		$success = __( 'Immutable artifact imported. Review its rights and verify it before use.', 'geek-cube-studio' );

		if ( $auto_create_game && ! $existing_game ) {
			$game_result = Geek_Cube_Studio_Repository::create_game(
				array(
					'title'       => $artifact_name,
					'slug'        => $game_slug,
					'platform'    => $platform,
					'language'    => 'en',
					'description' => '',
				)
			);
			if ( is_wp_error( $game_result ) ) {
				$success = sprintf(
					/* translators: %s: game creation error. */
					__( 'ROM imported, but the draft game could not be created: %s', 'geek-cube-studio' ),
					$game_result->get_error_message()
				);
			} else {
				$game     = Geek_Cube_Studio_Repository::get_game( $game_result );
				$success  = __( 'ROM imported and a draft game was created. Verify the ROM before creating a test profile.', 'geek-cube-studio' );
				$success .= $game ? ' ' . self::game_saved_notice( $game['slug'] ) : '';
			}
		} elseif ( $auto_create_game ) {
			$success = __( 'ROM imported. An existing game with the same canonical slug was kept.', 'geek-cube-studio' );
		}

		$this->finish( 'geek-cube-studio-artifacts', true, $success, array( 'artifact_type' => self::resolve_artifact_type( array( 'artifact_type' => $type ) ) ) );
	}

	/**
	 * Remove an expired temporary artifact upload.
	 *
	 * @param string $token Staged upload UUID.
	 * @return void
	 */
	public function cleanup_expired_artifact_draft( $token ) {
		$token = sanitize_text_field( (string) $token );
		$draft = get_transient( self::artifact_draft_key( $token ) );
		if ( is_array( $draft ) ) {
			Geek_Cube_Studio_Artifact_Storage::cleanup_staged( $draft );
		}
		delete_transient( self::artifact_draft_key( $token ) );
	}

	/** Handle an artifact lifecycle transition. */
	public function update_artifact_status() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_artifact_status' );
		$artifact_id   = isset( $_POST['artifact_id'] ) ? absint( wp_unslash( $_POST['artifact_id'] ) ) : 0;
		$status        = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$artifact_type = self::resolve_artifact_type( $_POST );
		$result        = Geek_Cube_Studio_Repository::update_artifact_status( $artifact_id, $status );
		$this->finish( 'geek-cube-studio-artifacts', $result, __( 'Artifact status updated.', 'geek-cube-studio' ), array( 'artifact_type' => $artifact_type ) );
	}

	/** Update an artifact name and synchronized stored filename. */
	public function update_artifact_name() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_update_artifact_name' );
		$artifact_id   = isset( $_POST['artifact_id'] ) ? absint( wp_unslash( $_POST['artifact_id'] ) ) : 0;
		$name          = isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '';
		$name          = is_scalar( $name ) ? sanitize_text_field( (string) $name ) : '';
		$artifact_type = self::resolve_artifact_type( $_POST );
		$result        = Geek_Cube_Studio_Repository::update_artifact_name( $artifact_id, $name );
		$this->finish( 'geek-cube-studio-artifacts', $result, __( 'Artifact name updated.', 'geek-cube-studio' ), array( 'artifact_type' => $artifact_type ) );
	}

	/** Update a core's logical emulator runtime binding. */
	public function update_core_runtime_key() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_update_core_runtime_key' );
		$artifact_id   = isset( $_POST['artifact_id'] ) ? absint( wp_unslash( $_POST['artifact_id'] ) ) : 0;
		$runtime_key   = isset( $_POST['runtime_key'] ) ? wp_unslash( $_POST['runtime_key'] ) : '';
		$runtime_key   = is_scalar( $runtime_key ) ? sanitize_key( (string) $runtime_key ) : '';
		$artifact_type = self::resolve_artifact_type( $_POST );
		$result        = Geek_Cube_Studio_Repository::update_core_runtime_key( $artifact_id, $runtime_key );
		$this->finish( 'geek-cube-studio-artifacts', $result, __( 'Core runtime key updated.', 'geek-cube-studio' ), array( 'artifact_type' => $artifact_type ) );
	}

	/** Handle execution profile creation. */
	public function create_profile() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_create_profile' );
		$data   = wp_unslash( $_POST );
		$result = Geek_Cube_Studio_Repository::create_profile( $data );
		if ( is_wp_error( $result ) ) {
			set_transient( self::profile_draft_key( get_current_user_id() ), self::profile_draft_values( $data ), 15 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( self::profile_draft_key( get_current_user_id() ) );
		}
		$this->finish( 'geek-cube-studio-profiles', $result, __( 'Immutable execution profile created.', 'geek-cube-studio' ) );
	}

	/** Store a completed compatibility test. */
	public function record_test() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_record_test' );

		$data                = wp_unslash( $_POST );
		$data['environment'] = array(
			'user_agent' => isset( $data['environment_user_agent'] ) ? $data['environment_user_agent'] : '',
			'platform'   => isset( $data['environment_platform'] ) ? $data['environment_platform'] : '',
			'language'   => isset( $data['environment_language'] ) ? $data['environment_language'] : '',
			'viewport'   => isset( $data['environment_viewport'] ) ? $data['environment_viewport'] : '',
		);
		$data['metrics']     = array(
			'load_ms' => isset( $data['metric_load_ms'] ) ? $data['metric_load_ms'] : '',
			'fps'     => isset( $data['metric_fps'] ) ? $data['metric_fps'] : '',
		);
		$result              = Geek_Cube_Studio_Repository::create_test_run( $data );
		$extra               = isset( $data['profile_id'] ) ? array( 'profile_id' => absint( $data['profile_id'] ) ) : array();
		$this->finish( 'geek-cube-studio-laboratory', $result, __( 'Immutable test run recorded.', 'geek-cube-studio' ), $extra );
	}

	/** Approve a tested profile. */
	public function approve_profile() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_approve_profile' );
		$profile_id = isset( $_POST['profile_id'] ) ? absint( wp_unslash( $_POST['profile_id'] ) ) : 0;
		$result     = Geek_Cube_Studio_Repository::approve_profile( $profile_id );
		$this->finish( 'geek-cube-studio-profiles', $result, __( 'Profile approved.', 'geek-cube-studio' ) );
	}

	/** Promote an approved profile to its game's production slot. */
	public function promote_profile() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_promote_profile' );
		$profile_id  = isset( $_POST['profile_id'] ) ? absint( wp_unslash( $_POST['profile_id'] ) ) : 0;
		$return_page = isset( $_POST['return_page'] ) && is_scalar( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : '';
		$result      = Geek_Cube_Studio_Repository::promote_profile( $profile_id );
		$this->finish( 'geek-cube-studio-games' === $return_page ? $return_page : 'geek-cube-studio-profiles', $result, __( 'Profile promoted to production.', 'geek-cube-studio' ) );
	}

	/**
	 * Display one redirect-provided result notice.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( isset( $_GET['gc_error'] ) && is_scalar( $_GET['gc_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Escaped one-request feedback only.
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( wp_unslash( $_GET['gc_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Escaped one-request feedback only.
		} elseif ( isset( $_GET['gc_notice'] ) && is_scalar( $_GET['gc_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Escaped one-request feedback only.
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( wp_unslash( $_GET['gc_notice'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Escaped one-request feedback only.
		}
	}

	/**
	 * Return a human-readable title for one game row.
	 *
	 * @param array<string,mixed>|null $game Game row.
	 * @return string
	 */
	public static function game_title( $game ) {
		return is_array( $game ) ? Geek_Cube_Studio_Repository::translated_value( $game['titles'] ) : '';
	}

	/**
	 * Return an artifact display identity.
	 *
	 * @param array<string,mixed>|null $artifact Artifact row.
	 * @return string
	 */
	public static function artifact_title( $artifact ) {
		return is_array( $artifact ) ? $artifact['name'] . ' ' . $artifact['version'] . ' · ' . self::artifact_type_label( $artifact['type'] ) : '';
	}

	/**
	 * Return a stable status badge class.
	 *
	 * @param string $status Lifecycle status.
	 * @return string
	 */
	public static function badge_class( $status ) {
		if ( in_array( $status, array( 'verified', 'approved', 'production', 'published', 'passed', 'completed' ), true ) ) {
			return 'is-ready';
		}

		return in_array( $status, array( 'blocked', 'failed', 'deprecated' ), true ) ? 'is-error' : 'is-warning';
	}

	/** Verify base administrator access. */
	private function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'geek-cube-studio' ), '', array( 'response' => 403 ) );
		}
	}

	/** Verify action access and schema state. */
	private function authorize_action() {
		$this->authorize();

		if ( ! $this->schema_ready() ) {
			wp_die( esc_html__( 'The catalog schema patch is still pending. Reload the screen after WP-Cron runs.', 'geek-cube-studio' ), '', array( 'response' => 503 ) );
		}
	}

	/** Determine whether the catalog patch completed. */
	private function schema_ready() {
		return Geek_Cube_Studio_Schema::VERSION === (string) get_option( Geek_Cube_Studio_Schema::VERSION_OPTION, '' );
	}

	/**
	 * Return one owner-bound staged upload from an opaque request token.
	 *
	 * @param mixed $request Request data.
	 * @return array<string,mixed>|null
	 */
	private function get_artifact_draft( $request ) {
		$token = is_array( $request ) && isset( $request['artifact_draft'] ) && is_scalar( $request['artifact_draft'] )
			? sanitize_text_field( (string) $request['artifact_draft'] )
			: '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $token ) ) {
			return null;
		}

		$draft = get_transient( self::artifact_draft_key( $token ) );
		if ( ! is_array( $draft ) || (int) get_current_user_id() !== (int) ( $draft['owner_id'] ?? 0 ) || (int) ( $draft['expires_at'] ?? 0 ) < time() ) {
			return null;
		}

		return $draft;
	}

	/**
	 * Return the WordPress transient key for one staged upload.
	 *
	 * @param string $token Staged upload UUID.
	 * @return string
	 */
	private static function artifact_draft_key( $token ) {
		return 'geek_cube_studio_artifact_draft_' . sanitize_text_field( (string) $token );
	}

	/**
	 * Return the per-administrator transient key for an invalid profile form.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	private static function profile_draft_key( $user_id ) {
		return 'geek_cube_studio_profile_draft_' . absint( $user_id );
	}

	/**
	 * Keep only scalar profile form values for a short retry window.
	 *
	 * @param array<string,mixed> $data Submitted profile data.
	 * @return array<string,string>
	 */
	private static function profile_draft_values( array $data ) {
		$values = array();
		$keys   = array( 'name', 'slug', 'game_id', 'player_artifact_id', 'core_artifact_id', 'rom_artifact_id', 'bios_artifact_id', 'config_artifact_id', 'controls_artifact_id' );

		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$values[ $key ] = sanitize_text_field( (string) $data[ $key ] );
			}
		}

		return $values;
	}

	/**
	 * Return the copyable shortcode confirmation shown after saving a game.
	 *
	 * @param string $slug Canonical game slug.
	 * @return string
	 */
	private static function game_saved_notice( $slug ) {
		$shortcode = '[geek_cube_game slug="' . sanitize_title( $slug ) . '"]';

		return sprintf(
			/* translators: %s: copyable game shortcode. */
			__( 'Game saved. Add this shortcode to a page: %s', 'geek-cube-studio' ),
			$shortcode
		);
	}

	/**
	 * Redirect with a success or safe error message.
	 *
	 * @param string              $page    Destination page slug.
	 * @param mixed               $result  Operation result.
	 * @param string              $success Success message.
	 * @param array<string,mixed> $extra Additional query arguments.
	 * @return void
	 */
	private function finish( $page, $result, $success = '', array $extra = array() ) {
		$args = array_merge( array( 'page' => $page ), $extra );
		if ( is_wp_error( $result ) ) {
			$args['gc_error'] = $result->get_error_message();
		} else {
			$args['gc_notice'] = $success;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
