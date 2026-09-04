<?php
/**
 * Compatibility laboratory screen.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$checklist_labels = array(
	'boot'        => __( 'Boot completes', 'geek-cube-studio' ),
	'video'       => __( 'Video is stable', 'geek-cube-studio' ),
	'audio'       => __( 'Audio is clean', 'geek-cube-studio' ),
	'keyboard'    => __( 'Keyboard controls', 'geek-cube-studio' ),
	'touch'       => __( 'Touch controls', 'geek-cube-studio' ),
	'gamepad'     => __( 'Gamepad controls', 'geek-cube-studio' ),
	'fullscreen'  => __( 'Fullscreen', 'geek-cube-studio' ),
	'orientation' => __( 'Screen orientation', 'geek-cube-studio' ),
	'native_save' => __( 'Native game save', 'geek-cube-studio' ),
	'save_state'  => __( 'Save state', 'geek-cube-studio' ),
	'reload_save' => __( 'Reload saved state', 'geek-cube-studio' ),
	'resume'      => __( 'Resume after background', 'geek-cube-studio' ),
	'endurance'   => __( '30-minute endurance', 'geek-cube-studio' ),
);
?>
<div class="wrap geek-cube-admin">
	<header class="geek-cube-admin__header"><div><p class="geek-cube-admin__eyebrow"><?php esc_html_e( 'Repeatable evidence', 'geek-cube-studio' ); ?></p><h1><?php esc_html_e( 'Compatibility laboratory', 'geek-cube-studio' ); ?></h1><p><?php esc_html_e( 'Run the exact frozen profile and record an immutable browser result.', 'geek-cube-studio' ); ?></p></div><span class="geek-cube-admin__version"><?php echo esc_html( GEEK_CUBE_STUDIO_VERSION ); ?></span></header>
	<?php Geek_Cube_Studio_Catalog_Admin::render_notice(); ?>
	<?php if ( ! $schema_ready ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The catalog patch is pending. WP-Cron will retry it automatically.', 'geek-cube-studio' ); ?></p></div>
	<?php else : ?>
		<section class="geek-cube-panel">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="geek-cube-picker"><input type="hidden" name="page" value="geek-cube-studio-laboratory"><label for="geek-cube-profile-picker"><strong><?php esc_html_e( 'Profile under test', 'geek-cube-studio' ); ?></strong></label><select id="geek-cube-profile-picker" name="profile_id" required><option value=""><?php esc_html_e( 'Select a profile', 'geek-cube-studio' ); ?></option>
			<?php
			foreach ( $profiles as $row ) :
				?>
				<option value="<?php echo esc_attr( $row['id'] ); ?>" <?php selected( $profile_id, $row['id'] ); ?>><?php echo esc_html( $row['name'] . ' · ' . Geek_Cube_Studio_Catalog_Admin::status_label( $row['status'] ) ); ?></option><?php endforeach; ?></select><button class="button"><?php esc_html_e( 'Open test', 'geek-cube-studio' ); ?></button></form>
		</section>

		<?php if ( $profile ) : ?>
			<div class="geek-cube-lab-layout">
				<section class="geek-cube-panel geek-cube-lab-player"><div class="geek-cube-panel__heading"><div><h2><?php echo esc_html( $profile['name'] ); ?></h2><p><code><?php echo esc_html( $profile['uuid'] ); ?></code></p></div><a class="button" href="<?php echo esc_url( $lab_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in new tab', 'geek-cube-studio' ); ?></a></div><iframe src="<?php echo esc_url( $lab_url ); ?>" title="<?php esc_attr_e( 'Game compatibility test player', 'geek-cube-studio' ); ?>" allow="autoplay; fullscreen; gamepad" data-geek-cube-lab-frame></iframe></section>
				<section class="geek-cube-panel"><div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Test report', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Complete the checklist after playing.', 'geek-cube-studio' ); ?></p></div></div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="geek-cube-test-form" data-geek-cube-test-form><input type="hidden" name="action" value="geek_cube_record_test"><input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile['id'] ); ?>"><?php wp_nonce_field( 'geek_cube_record_test' ); ?><input type="hidden" name="environment_user_agent" data-env="userAgent"><input type="hidden" name="environment_platform" data-env="platform"><input type="hidden" name="environment_language" data-env="language"><input type="hidden" name="environment_viewport" data-env="viewport">
						<?php
						foreach ( $checklist_labels as $key => $label ) :
							?>
							<label><span><?php echo esc_html( $label ); ?></span><select name="checklist[<?php echo esc_attr( $key ); ?>]"><option value="not_tested"><?php esc_html_e( 'Not tested', 'geek-cube-studio' ); ?></option><option value="passed"><?php esc_html_e( 'Passed', 'geek-cube-studio' ); ?></option><option value="failed"><?php esc_html_e( 'Failed', 'geek-cube-studio' ); ?></option><option value="na"><?php esc_html_e( 'Not applicable', 'geek-cube-studio' ); ?></option></select></label><?php endforeach; ?>
						<label><span><?php esc_html_e( 'Observed load time (ms)', 'geek-cube-studio' ); ?></span><input type="number" min="0" name="metric_load_ms"></label><label><span><?php esc_html_e( 'Observed FPS', 'geek-cube-studio' ); ?></span><input type="number" min="0" max="240" step="0.1" name="metric_fps"></label>
						<label class="is-wide"><span><?php esc_html_e( 'Notes', 'geek-cube-studio' ); ?></span><textarea name="notes" rows="4"></textarea></label><label class="is-wide"><span><?php esc_html_e( 'Final result', 'geek-cube-studio' ); ?></span><select name="result" required><option value="inconclusive"><?php esc_html_e( 'Inconclusive', 'geek-cube-studio' ); ?></option><option value="passed"><?php esc_html_e( 'Passed', 'geek-cube-studio' ); ?></option><option value="failed"><?php esc_html_e( 'Failed', 'geek-cube-studio' ); ?></option></select></label><div class="is-wide"><?php submit_button( __( 'Record immutable test', 'geek-cube-studio' ), 'primary', 'submit', false ); ?></div>
					</form>
				</section>
			</div>
		<?php endif; ?>

		<section class="geek-cube-panel geek-cube-history"><div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Test history', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Past results are evidence and are never edited.', 'geek-cube-studio' ); ?></p></div></div><div class="geek-cube-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Run', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Profile', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Result', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Environment', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Date UTC', 'geek-cube-studio' ); ?></th></tr></thead><tbody>
		<?php
		if ( empty( $test_runs ) ) :
			?>
			<tr><td colspan="5"><?php esc_html_e( 'No tests recorded.', 'geek-cube-studio' ); ?></td></tr><?php endif; ?>
			<?php
			foreach ( $test_runs as $run ) :
						$run_profile = Geek_Cube_Studio_Repository::get_profile( $run['profile_id'] );
						$environment = json_decode( $run['environment'], true );
				?>
	<tr><td><code><?php echo esc_html( substr( $run['uuid'], 0, 8 ) ); ?></code></td><td><?php echo esc_html( $run_profile ? $run_profile['name'] : '#' . $run['profile_id'] ); ?></td><td><span class="geek-cube-badge <?php echo esc_attr( Geek_Cube_Studio_Catalog_Admin::badge_class( $run['result'] ) ); ?>"><?php echo esc_html( Geek_Cube_Studio_Catalog_Admin::status_label( $run['result'] ) ); ?></span></td><td><small><?php echo esc_html( is_array( $environment ) && isset( $environment['platform'] ) ? $environment['platform'] : '—' ); ?></small></td><td><?php echo esc_html( $run['created_at'] ); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
	<?php endif; ?>
</div>
