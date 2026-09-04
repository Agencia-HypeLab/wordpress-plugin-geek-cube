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
		add_action( 'admin_post_geek_cube_create_artifact', array( $this, 'create_artifact' ) );
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
		$schema_ready = $this->schema_ready();
		$games        = $schema_ready ? Geek_Cube_Studio_Repository::get_games() : array();
		require GEEK_CUBE_STUDIO_PLUGIN_DIR . 'views/admin/games.php';
	}

	/** Render artifacts screen. */
	public function render_artifacts() {
		$this->authorize();
		$schema_ready    = $this->schema_ready();
		$artifact_type   = self::resolve_artifact_type( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen filter.
		$artifact_tabs   = self::artifact_tabs();
		$all_artifacts   = $schema_ready ? Geek_Cube_Studio_Repository::get_artifacts() : array();
		$artifact_counts = array_fill_keys( array_keys( $artifact_tabs ), 0 );

		foreach ( $all_artifacts as $artifact ) {
			$type = isset( $artifact['type'] ) ? sanitize_key( (string) $artifact['type'] ) : '';
			++$artifact_counts['all'];

			if ( isset( $artifact_counts[ $type ] ) ) {
				++$artifact_counts[ $type ];
			}
		}

		$artifacts = 'all' === $artifact_type
			? $all_artifacts
			: array_values(
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
		$tabs = array(
			'all' => __( 'All artifacts', 'geek-cube-studio' ),
		);

		foreach ( Geek_Cube_Studio_Repository::ARTIFACT_TYPES as $type ) {
			$tabs[ $type ] = strtoupper( $type );
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
		$value = is_array( $request ) && isset( $request['artifact_type'] ) && is_scalar( $request['artifact_type'] )
			? sanitize_key( (string) $request['artifact_type'] )
			: 'all';

		return isset( self::artifact_tabs()[ $value ] ) ? $value : 'all';
	}

	/** Render profiles screen. */
	public function render_profiles() {
		$this->authorize();
		$schema_ready = $this->schema_ready();
		$games        = $schema_ready ? Geek_Cube_Studio_Repository::get_games() : array();
		$artifacts    = $schema_ready ? Geek_Cube_Studio_Repository::get_artifacts() : array();
		$profiles     = $schema_ready ? Geek_Cube_Studio_Repository::get_profiles() : array();
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
		$this->finish( 'geek-cube-studio-games', $result, __( 'Game registered.', 'geek-cube-studio' ) );
	}

	/** Handle immutable artifact import. */
	public function create_artifact() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_create_artifact' );

		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$uuid   = wp_generate_uuid4();
		$file   = isset( $_FILES['artifact_file'] ) && is_array( $_FILES['artifact_file'] ) ? $_FILES['artifact_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload storage validates all fields and bytes.
		$stored = Geek_Cube_Studio_Artifact_Storage::store_upload( $file, $type, $uuid );

		if ( is_wp_error( $stored ) ) {
			$this->finish( 'geek-cube-studio-artifacts', $stored );
		}

		$data = array_merge( wp_unslash( $_POST ), $stored, array( 'uuid' => $uuid ) );
		unset( $data['absolute_path'], $data['absolute_root'] );
		$result = Geek_Cube_Studio_Repository::create_artifact( $data );

		if ( is_wp_error( $result ) ) {
			Geek_Cube_Studio_Artifact_Storage::cleanup( $stored );
		}

		$this->finish( 'geek-cube-studio-artifacts', $result, __( 'Immutable artifact imported. Review its rights and verify it before use.', 'geek-cube-studio' ), array( 'artifact_type' => self::resolve_artifact_type( array( 'artifact_type' => $type ) ) ) );
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

	/** Handle execution profile creation. */
	public function create_profile() {
		$this->authorize_action();
		check_admin_referer( 'geek_cube_create_profile' );
		$result = Geek_Cube_Studio_Repository::create_profile( wp_unslash( $_POST ) );
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
		$profile_id = isset( $_POST['profile_id'] ) ? absint( wp_unslash( $_POST['profile_id'] ) ) : 0;
		$result     = Geek_Cube_Studio_Repository::promote_profile( $profile_id );
		$this->finish( 'geek-cube-studio-profiles', $result, __( 'Profile promoted to production.', 'geek-cube-studio' ) );
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
		return is_array( $artifact ) ? $artifact['name'] . ' ' . $artifact['version'] . ' · ' . strtoupper( $artifact['type'] ) : '';
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
