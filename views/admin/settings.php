<?php
/**
 * Geek Cube Studio settings screen.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$option_name   = Geek_Cube_Studio_Settings::OPTION_KEY;
$field_name    = static function ( $key ) use ( $option_name ) {
	return $option_name . '[' . $key . ']';
};
$checked_value = static function ( $key ) use ( $settings ) {
	return isset( $settings[ $key ] ) && '1' === (string) $settings[ $key ];
};
$tab_url       = static function ( $tab ) {
	return add_query_arg(
		array(
			'page' => 'geek-cube-studio',
			'tab'  => $tab,
		),
		admin_url( 'admin.php' )
	);
};
?>
<div class="wrap geek-cube-admin">
	<header class="geek-cube-admin__header">
		<div>
			<p class="geek-cube-admin__eyebrow"><?php esc_html_e( 'Product control center', 'geek-cube-studio' ); ?></p>
			<h1><?php esc_html_e( 'Geek Cube Studio', 'geek-cube-studio' ); ?></h1>
			<p><?php esc_html_e( 'Configure stable, versioned browser game experiences without coupling public URLs to their internal artifacts.', 'geek-cube-studio' ); ?></p>
		</div>
		<span class="geek-cube-admin__version"><?php echo esc_html( 'v' . GEEK_CUBE_STUDIO_VERSION ); ?></span>
	</header>

	<?php settings_errors(); ?>

	<div class="geek-cube-search" data-geek-cube-search>
		<label class="screen-reader-text" for="geek-cube-settings-search"><?php esc_html_e( 'Search settings', 'geek-cube-studio' ); ?></label>
		<span class="dashicons dashicons-search" aria-hidden="true"></span>
		<input id="geek-cube-settings-search" type="search" placeholder="<?php esc_attr_e( 'Search controls, URLs, saves, emulators...', 'geek-cube-studio' ); ?>" autocomplete="off" data-geek-cube-search-input>
		<div class="geek-cube-search__results" data-geek-cube-search-results hidden></div>
	</div>

	<nav class="nav-tab-wrapper geek-cube-tabs" aria-label="<?php esc_attr_e( 'Geek Cube settings', 'geek-cube-studio' ); ?>">
		<?php foreach ( $tabs as $tab_slug => $label ) : ?>
			<a class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $tab_url( $tab_slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'overview' === $active_tab ) : ?>
		<section class="geek-cube-panel geek-cube-panel--overview">
			<div class="geek-cube-panel__heading">
				<div>
					<h2><?php esc_html_e( 'Architecture status', 'geek-cube-studio' ); ?></h2>
					<p><?php esc_html_e( 'A central view of the controls already prepared for the playable prototype.', 'geek-cube-studio' ); ?></p>
				</div>
			</div>
			<div class="geek-cube-status-grid">
				<article class="geek-cube-status-card">
					<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'Multilingual URLs', 'geek-cube-studio' ); ?></h3>
					<strong class="geek-cube-badge <?php echo $polylang_active ? 'is-ready' : 'is-neutral'; ?>"><?php echo esc_html( $polylang_active ? __( 'Polylang detected', 'geek-cube-studio' ) : __( 'Fallback active', 'geek-cube-studio' ) ); ?></strong>
					<p><?php esc_html_e( 'Route segments remain independent from game and artifact IDs.', 'geek-cube-studio' ); ?></p>
				</article>
				<article class="geek-cube-status-card">
					<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'Public player', 'geek-cube-studio' ); ?></h3>
					<strong class="geek-cube-badge <?php echo $checked_value( 'player_public_enabled' ) ? 'is-ready' : 'is-warning'; ?>"><?php echo esc_html( $checked_value( 'player_public_enabled' ) ? __( 'Enabled', 'geek-cube-studio' ) : __( 'Protected', 'geek-cube-studio' ) ); ?></strong>
					<p><?php esc_html_e( 'Keep it protected until the first execution profile is approved.', 'geek-cube-studio' ); ?></p>
				</article>
				<article class="geek-cube-status-card">
					<span class="dashicons dashicons-performance" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'Compatibility laboratory', 'geek-cube-studio' ); ?></h3>
					<strong class="geek-cube-badge <?php echo $checked_value( 'lab_enabled' ) ? 'is-ready' : 'is-neutral'; ?>"><?php echo esc_html( $checked_value( 'lab_enabled' ) ? __( 'Enabled', 'geek-cube-studio' ) : __( 'Disabled', 'geek-cube-studio' ) ); ?></strong>
					<p><?php esc_html_e( 'Every run will be attached to an immutable execution profile.', 'geek-cube-studio' ); ?></p>
				</article>
				<article class="geek-cube-status-card">
					<span class="dashicons dashicons-database" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'Cache boundary', 'geek-cube-studio' ); ?></h3>
					<strong class="geek-cube-badge is-neutral"><?php esc_html_e( 'Managed by HypeLab', 'geek-cube-studio' ); ?></strong>
					<p><?php esc_html_e( 'Geek Cube emits immutable artifact URLs and does not manage cache policy.', 'geek-cube-studio' ); ?></p>
				</article>
			</div>
		</section>
	<?php elseif ( 'urls' === $active_tab ) : ?>
		<form method="post" action="options.php" class="geek-cube-panel">
			<?php settings_fields( Geek_Cube_Studio_Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( $field_name( '_section' ) ); ?>" value="urls">
			<div class="geek-cube-panel__heading">
				<div>
					<h2><?php esc_html_e( 'URLs and languages', 'geek-cube-studio' ); ?></h2>
					<p><?php esc_html_e( 'Only route segments are stored. Polylang remains responsible for the language domain or directory.', 'geek-cube-studio' ); ?></p>
				</div>
				<strong class="geek-cube-badge <?php echo $polylang_active ? 'is-ready' : 'is-neutral'; ?>"><?php echo esc_html( $polylang_active ? __( 'Polylang Free connected', 'geek-cube-studio' ) : __( 'Polylang not detected', 'geek-cube-studio' ) ); ?></strong>
			</div>

			<div id="url-slugs" class="geek-cube-route-languages">
				<?php
				$route_labels    = array(
					'catalog' => __( 'Catalog', 'geek-cube-studio' ),
					'game'    => __( 'Game detail', 'geek-cube-studio' ),
					'play'    => __( 'Playable URL', 'geek-cube-studio' ),
					'lab'     => __( 'Test laboratory', 'geek-cube-studio' ),
					'profile' => __( 'Player profile', 'geek-cube-studio' ),
				);
				$route_languages = array_merge(
					array(
						'default' => array(
							'name'   => __( 'Default fallback', 'geek-cube-studio' ),
							'locale' => get_locale(),
						),
					),
					$languages
				);
				foreach ( $route_languages as $language_slug => $language ) :
					$routes = isset( $configured_slugs[ $language_slug ] ) && is_array( $configured_slugs[ $language_slug ] )
						? array_replace( $configured_slugs['default'], $configured_slugs[ $language_slug ] )
						: $configured_slugs['default'];
					?>
					<article class="geek-cube-route-card">
						<header>
							<h3><?php echo esc_html( $language['name'] ); ?></h3>
							<code><?php echo esc_html( 'default' === $language_slug ? 'fallback' : $language_slug . ' · ' . $language['locale'] ); ?></code>
						</header>
						<div class="geek-cube-route-card__grid">
							<?php foreach ( $route_labels as $route => $label ) : ?>
								<label>
									<span><?php echo esc_html( $label ); ?></span>
									<input type="text" name="<?php echo esc_attr( $option_name . '[url_slugs][' . $language_slug . '][' . $route . ']' ); ?>" value="<?php echo esc_attr( $routes[ $route ] ); ?>" pattern="[a-z0-9-]+" required>
								</label>
							<?php endforeach; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<div id="url-redirect-legacy" class="geek-cube-setting-row">
				<div><label for="geek-cube-url-redirect"><strong><?php esc_html_e( 'Redirect legacy routes', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Preserve old playable links with permanent redirects after a route change.', 'geek-cube-studio' ); ?></p></div>
				<label class="geek-cube-switch"><input id="geek-cube-url-redirect" type="checkbox" name="<?php echo esc_attr( $field_name( 'url_redirect_legacy' ) ); ?>" value="1" <?php checked( $checked_value( 'url_redirect_legacy' ) ); ?>><span aria-hidden="true"></span></label>
			</div>

			<div class="geek-cube-callout"><strong><?php esc_html_e( 'Polylang Free strategy', 'geek-cube-studio' ); ?></strong><p><?php esc_html_e( 'Geek Cube will resolve its own translated endpoint segments. It will not depend on the translated custom-post-type slug feature from Polylang Pro.', 'geek-cube-studio' ); ?></p></div>
			<?php submit_button( __( 'Save URL settings', 'geek-cube-studio' ) ); ?>
		</form>
	<?php elseif ( 'player' === $active_tab ) : ?>
		<form method="post" action="options.php" class="geek-cube-panel">
			<?php settings_fields( Geek_Cube_Studio_Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( $field_name( '_section' ) ); ?>" value="player">
			<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Browser player', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Global defaults. An approved execution profile may override compatible controls.', 'geek-cube-studio' ); ?></p></div></div>
			<div id="player-public-enabled" class="geek-cube-setting-row"><div><label for="geek-cube-player-public"><strong><?php esc_html_e( 'Enable public player', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Keep disabled while only laboratory profiles exist.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-player-public" type="checkbox" name="<?php echo esc_attr( $field_name( 'player_public_enabled' ) ); ?>" value="1" <?php checked( $checked_value( 'player_public_enabled' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-player-guest"><strong><?php esc_html_e( 'Allow guest play', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Visitors can start immediately and connect an account later.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-player-guest" type="checkbox" name="<?php echo esc_attr( $field_name( 'player_guest_enabled' ) ); ?>" value="1" <?php checked( $checked_value( 'player_guest_enabled' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div id="player-default-engine" class="geek-cube-setting-row"><div><label for="geek-cube-player-engine"><strong><?php esc_html_e( 'Default engine', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'The exact engine version is locked by each execution profile.', 'geek-cube-studio' ); ?></p></div><select id="geek-cube-player-engine" name="<?php echo esc_attr( $field_name( 'player_default_engine' ) ); ?>"><option value="emulatorjs" <?php selected( $settings['player_default_engine'], 'emulatorjs' ); ?>>EmulatorJS</option><option value="nostalgist" <?php selected( $settings['player_default_engine'], 'nostalgist' ); ?>>Nostalgist.js</option></select></div>
			<div id="player-threads-mode" class="geek-cube-setting-row"><div><label for="geek-cube-player-threads"><strong><?php esc_html_e( 'WebAssembly threads', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Automatic mode only enables threads when the required browser isolation is available.', 'geek-cube-studio' ); ?></p></div><select id="geek-cube-player-threads" name="<?php echo esc_attr( $field_name( 'player_threads_mode' ) ); ?>"><option value="off" <?php selected( $settings['player_threads_mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'geek-cube-studio' ); ?></option><option value="auto" <?php selected( $settings['player_threads_mode'], 'auto' ); ?>><?php esc_html_e( 'Automatic', 'geek-cube-studio' ); ?></option><option value="on" <?php selected( $settings['player_threads_mode'], 'on' ); ?>><?php esc_html_e( 'Required', 'geek-cube-studio' ); ?></option></select></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-player-start"><strong><?php esc_html_e( 'Start behavior', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Browsers require user interaction before reliable audio playback.', 'geek-cube-studio' ); ?></p></div><select id="geek-cube-player-start" name="<?php echo esc_attr( $field_name( 'player_start_mode' ) ); ?>"><option value="interaction" <?php selected( $settings['player_start_mode'], 'interaction' ); ?>><?php esc_html_e( 'Start after first interaction', 'geek-cube-studio' ); ?></option><option value="manual" <?php selected( $settings['player_start_mode'], 'manual' ); ?>><?php esc_html_e( 'Explicit Play button', 'geek-cube-studio' ); ?></option></select></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-player-volume"><strong><?php esc_html_e( 'Default volume', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Initial volume before the player remembers a user preference.', 'geek-cube-studio' ); ?></p></div><div class="geek-cube-range"><input id="geek-cube-player-volume" type="range" min="0" max="100" name="<?php echo esc_attr( $field_name( 'player_default_volume' ) ); ?>" value="<?php echo esc_attr( $settings['player_default_volume'] ); ?>" data-geek-cube-range><output><?php echo esc_html( $settings['player_default_volume'] . '%' ); ?></output></div></div>
			<?php submit_button( __( 'Save player settings', 'geek-cube-studio' ) ); ?>
		</form>
	<?php elseif ( 'artifacts' === $active_tab ) : ?>
		<form method="post" action="options.php" class="geek-cube-panel">
			<?php settings_fields( Geek_Cube_Studio_Settings::OPTION_GROUP ); ?><input type="hidden" name="<?php echo esc_attr( $field_name( '_section' ) ); ?>" value="artifacts">
			<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Versioned artifacts', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Local, immutable storage for players, cores, ROMs, BIOS files, patches and configurations.', 'geek-cube-studio' ); ?></p></div><strong class="geek-cube-badge is-neutral"><?php esc_html_e( 'No CDN', 'geek-cube-studio' ); ?></strong></div>
			<div id="artifact-storage-subdirectory" class="geek-cube-setting-row"><div><label for="geek-cube-artifact-directory"><strong><?php esc_html_e( 'Uploads subdirectory', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Relative path only. Artifact versions will add their own SHA-256 directory.', 'geek-cube-studio' ); ?></p></div><input id="geek-cube-artifact-directory" type="text" class="regular-text code" name="<?php echo esc_attr( $field_name( 'artifact_storage_subdirectory' ) ); ?>" value="<?php echo esc_attr( $settings['artifact_storage_subdirectory'] ); ?>"></div>
			<div id="artifact-update-checks" class="geek-cube-setting-row"><div><label for="geek-cube-artifact-checks"><strong><?php esc_html_e( 'Check upstream releases', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Detect candidate versions without changing approved game profiles.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-artifact-checks" type="checkbox" name="<?php echo esc_attr( $field_name( 'artifact_update_checks' ) ); ?>" value="1" <?php checked( $checked_value( 'artifact_update_checks' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-artifact-download"><strong><?php esc_html_e( 'Download candidates automatically', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Downloads never approve or promote an artifact. Keep disabled until source validation is implemented.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-artifact-download" type="checkbox" name="<?php echo esc_attr( $field_name( 'artifact_auto_download' ) ); ?>" value="1" <?php checked( $checked_value( 'artifact_auto_download' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div class="geek-cube-callout"><strong><?php esc_html_e( 'Cache responsibility', 'geek-cube-studio' ); ?></strong><p><?php esc_html_e( 'This plugin only emits versioned local URLs. Cache creation and invalidation remain under the HypeLab plugin.', 'geek-cube-studio' ); ?></p></div>
			<?php submit_button( __( 'Save artifact settings', 'geek-cube-studio' ) ); ?>
		</form>
	<?php elseif ( 'lab' === $active_tab ) : ?>
		<form method="post" action="options.php" class="geek-cube-panel">
			<?php settings_fields( Geek_Cube_Studio_Settings::OPTION_GROUP ); ?><input type="hidden" name="<?php echo esc_attr( $field_name( '_section' ) ); ?>" value="lab">
			<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Compatibility laboratory', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Controls for repeatable test runs and immutable compatibility reports.', 'geek-cube-studio' ); ?></p></div></div>
			<div id="lab-enabled" class="geek-cube-setting-row"><div><label for="geek-cube-lab-enabled"><strong><?php esc_html_e( 'Enable laboratory', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Makes the internal test route available to authorized users.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-lab-enabled" type="checkbox" name="<?php echo esc_attr( $field_name( 'lab_enabled' ) ); ?>" value="1" <?php checked( $checked_value( 'lab_enabled' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-lab-metrics"><strong><?php esc_html_e( 'Record technical metrics', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Attach loading, frame, audio and control observations to each test run.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-lab-metrics" type="checkbox" name="<?php echo esc_attr( $field_name( 'lab_record_metrics' ) ); ?>" value="1" <?php checked( $checked_value( 'lab_record_metrics' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div id="lab-revalidation-days" class="geek-cube-setting-row"><div><label for="geek-cube-lab-days"><strong><?php esc_html_e( 'Revalidation reminder', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'A reminder does not invalidate the original test record.', 'geek-cube-studio' ); ?></p></div><div class="geek-cube-number-suffix"><input id="geek-cube-lab-days" type="number" min="1" max="3650" name="<?php echo esc_attr( $field_name( 'lab_revalidation_days' ) ); ?>" value="<?php echo esc_attr( $settings['lab_revalidation_days'] ); ?>"><span><?php esc_html_e( 'days', 'geek-cube-studio' ); ?></span></div></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-lab-capability"><strong><?php esc_html_e( 'Required capability', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Administrators are recommended until a dedicated tester role exists.', 'geek-cube-studio' ); ?></p></div><select id="geek-cube-lab-capability" name="<?php echo esc_attr( $field_name( 'lab_required_capability' ) ); ?>"><option value="manage_options" <?php selected( $settings['lab_required_capability'], 'manage_options' ); ?>>manage_options</option><option value="edit_others_posts" <?php selected( $settings['lab_required_capability'], 'edit_others_posts' ); ?>>edit_others_posts</option></select></div>
			<?php submit_button( __( 'Save laboratory settings', 'geek-cube-studio' ) ); ?>
		</form>
	<?php elseif ( 'saves' === $active_tab ) : ?>
		<form method="post" action="options.php" class="geek-cube-panel">
			<?php settings_fields( Geek_Cube_Studio_Settings::OPTION_GROUP ); ?><input type="hidden" name="<?php echo esc_attr( $field_name( '_section' ) ); ?>" value="saves">
			<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Game saves', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Local guest progress and account synchronization remain separated by execution profile.', 'geek-cube-studio' ); ?></p></div></div>
			<div id="guest-saves-enabled" class="geek-cube-setting-row"><div><label for="geek-cube-guest-saves"><strong><?php esc_html_e( 'Guest saves in the browser', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Allow immediate play and offer an import when the visitor signs in.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-guest-saves" type="checkbox" name="<?php echo esc_attr( $field_name( 'guest_saves_enabled' ) ); ?>" value="1" <?php checked( $checked_value( 'guest_saves_enabled' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div id="cloud-saves-enabled" class="geek-cube-setting-row"><div><label for="geek-cube-cloud-saves"><strong><?php esc_html_e( 'Synchronize saves to user profiles', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'The WordPress user ID remains the canonical save owner.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-cloud-saves" type="checkbox" name="<?php echo esc_attr( $field_name( 'cloud_saves_enabled' ) ); ?>" value="1" <?php checked( $checked_value( 'cloud_saves_enabled' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-save-revisions"><strong><?php esc_html_e( 'Revisions per save slot', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Older revisions provide recovery from corruption or device conflicts.', 'geek-cube-studio' ); ?></p></div><input id="geek-cube-save-revisions" type="number" min="1" max="50" name="<?php echo esc_attr( $field_name( 'save_revision_limit' ) ); ?>" value="<?php echo esc_attr( $settings['save_revision_limit'] ); ?>"></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-save-size"><strong><?php esc_html_e( 'Maximum save-state size', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Native cartridge saves may use a smaller limit in their artifact profile.', 'geek-cube-studio' ); ?></p></div><div class="geek-cube-number-suffix"><input id="geek-cube-save-size" type="number" min="1" max="128" name="<?php echo esc_attr( $field_name( 'save_state_max_mb' ) ); ?>" value="<?php echo esc_attr( $settings['save_state_max_mb'] ); ?>"><span>MB</span></div></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-save-conflict"><strong><?php esc_html_e( 'Device conflict policy', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Keeping both versions is safest while synchronization is new.', 'geek-cube-studio' ); ?></p></div><select id="geek-cube-save-conflict" name="<?php echo esc_attr( $field_name( 'save_conflict_policy' ) ); ?>"><option value="keep_both" <?php selected( $settings['save_conflict_policy'], 'keep_both' ); ?>><?php esc_html_e( 'Keep both revisions', 'geek-cube-studio' ); ?></option><option value="newest" <?php selected( $settings['save_conflict_policy'], 'newest' ); ?>><?php esc_html_e( 'Keep newest', 'geek-cube-studio' ); ?></option></select></div>
			<?php submit_button( __( 'Save progress settings', 'geek-cube-studio' ) ); ?>
		</form>
	<?php elseif ( 'accounts' === $active_tab ) : ?>
		<form method="post" action="options.php" class="geek-cube-panel">
			<?php settings_fields( Geek_Cube_Studio_Settings::OPTION_GROUP ); ?><input type="hidden" name="<?php echo esc_attr( $field_name( '_section' ) ); ?>" value="accounts">
			<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Player accounts', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Social providers authenticate users, but WordPress remains the canonical account and save owner.', 'geek-cube-studio' ); ?></p></div></div>
			<div id="login-mode" class="geek-cube-setting-row"><div><label for="geek-cube-login-mode"><strong><?php esc_html_e( 'Login requirement', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Optional login is recommended: play first, sign in to synchronize.', 'geek-cube-studio' ); ?></p></div><select id="geek-cube-login-mode" name="<?php echo esc_attr( $field_name( 'login_mode' ) ); ?>"><option value="optional" <?php selected( $settings['login_mode'], 'optional' ); ?>><?php esc_html_e( 'Optional', 'geek-cube-studio' ); ?></option><option value="required" <?php selected( $settings['login_mode'], 'required' ); ?>><?php esc_html_e( 'Required', 'geek-cube-studio' ); ?></option><option value="disabled" <?php selected( $settings['login_mode'], 'disabled' ); ?>><?php esc_html_e( 'Accounts disabled', 'geek-cube-studio' ); ?></option></select></div>
			<div class="geek-cube-setting-row"><div><label for="geek-cube-email-login"><strong><?php esc_html_e( 'Email login', 'geek-cube-studio' ); ?></strong></label><p><?php esc_html_e( 'Keep an account recovery path independent from social providers.', 'geek-cube-studio' ); ?></p></div><label class="geek-cube-switch"><input id="geek-cube-email-login" type="checkbox" name="<?php echo esc_attr( $field_name( 'email_login_enabled' ) ); ?>" value="1" <?php checked( $checked_value( 'email_login_enabled' ) ); ?>><span aria-hidden="true"></span></label></div>
			<div id="google-login-enabled" class="geek-cube-setting-row"><div><label for="geek-cube-google-login"><strong><?php esc_html_e( 'Sign in with Google', 'geek-cube-studio' ); ?></strong></label><p><?php echo esc_html( $google_configured ? __( 'Public client identifier detected in server configuration.', 'geek-cube-studio' ) : __( 'Requires GEEK_CUBE_STUDIO_GOOGLE_CLIENT_ID in secure server configuration.', 'geek-cube-studio' ) ); ?></p></div><div class="geek-cube-control-with-status"><strong class="geek-cube-badge <?php echo $google_configured ? 'is-ready' : 'is-warning'; ?>"><?php echo esc_html( $google_configured ? __( 'Configured', 'geek-cube-studio' ) : __( 'Pending credentials', 'geek-cube-studio' ) ); ?></strong><label class="geek-cube-switch"><input id="geek-cube-google-login" type="checkbox" name="<?php echo esc_attr( $field_name( 'google_login_enabled' ) ); ?>" value="1" <?php checked( $checked_value( 'google_login_enabled' ) ); ?> <?php disabled( $google_configured, false ); ?>><span aria-hidden="true"></span></label></div></div>
			<div id="apple-login-enabled" class="geek-cube-setting-row"><div><label for="geek-cube-apple-login"><strong><?php esc_html_e( 'Sign in with Apple', 'geek-cube-studio' ); ?></strong></label><p><?php echo esc_html( $apple_configured ? __( 'Public client identifier detected in server configuration.', 'geek-cube-studio' ) : __( 'Requires GEEK_CUBE_STUDIO_APPLE_CLIENT_ID and the private key outside WordPress.', 'geek-cube-studio' ) ); ?></p></div><div class="geek-cube-control-with-status"><strong class="geek-cube-badge <?php echo $apple_configured ? 'is-ready' : 'is-warning'; ?>"><?php echo esc_html( $apple_configured ? __( 'Configured', 'geek-cube-studio' ) : __( 'Pending credentials', 'geek-cube-studio' ) ); ?></strong><label class="geek-cube-switch"><input id="geek-cube-apple-login" type="checkbox" name="<?php echo esc_attr( $field_name( 'apple_login_enabled' ) ); ?>" value="1" <?php checked( $checked_value( 'apple_login_enabled' ) ); ?> <?php disabled( $apple_configured, false ); ?>><span aria-hidden="true"></span></label></div></div>
			<div class="geek-cube-callout"><strong><?php esc_html_e( 'Secrets stay out of this screen', 'geek-cube-studio' ); ?></strong><p><?php esc_html_e( 'OAuth client secrets and Apple private keys must be supplied through server configuration or a secret manager, never saved in the WordPress options table.', 'geek-cube-studio' ); ?></p></div>
			<?php submit_button( __( 'Save account settings', 'geek-cube-studio' ) ); ?>
		</form>
	<?php endif; ?>
</div>
