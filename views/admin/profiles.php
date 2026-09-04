<?php
/**
 * Immutable execution profiles screen.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$by_type = array_fill_keys( Geek_Cube_Studio_Repository::ARTIFACT_TYPES, array() );
foreach ( $artifacts as $artifact ) {
	if ( 'verified' === $artifact['status'] && isset( $by_type[ $artifact['type'] ] ) ) {
		$by_type[ $artifact['type'] ][] = $artifact;
	}
}
?>
<div class="wrap geek-cube-admin">
	<header class="geek-cube-admin__header"><div><p class="geek-cube-admin__eyebrow"><?php esc_html_e( 'Frozen combinations', 'geek-cube-studio' ); ?></p><h1><?php esc_html_e( 'Execution profiles', 'geek-cube-studio' ); ?></h1><p><?php esc_html_e( 'A profile permanently binds one game to exact player, core, ROM and optional BIOS versions.', 'geek-cube-studio' ); ?></p></div><span class="geek-cube-admin__version"><?php echo esc_html( GEEK_CUBE_STUDIO_VERSION ); ?></span></header>
	<?php Geek_Cube_Studio_Catalog_Admin::render_notice(); ?>
	<?php if ( ! $schema_ready ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The catalog patch is pending. WP-Cron will retry it automatically.', 'geek-cube-studio' ); ?></p></div>
	<?php else : ?>
		<div class="geek-cube-workspace">
			<section class="geek-cube-panel">
				<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Freeze a test combination', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Only verified artifacts are offered. NES does not require a BIOS.', 'geek-cube-studio' ); ?></p></div></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="geek-cube-form">
					<input type="hidden" name="action" value="geek_cube_create_profile"><?php wp_nonce_field( 'geek_cube_create_profile' ); ?>
					<label><span><?php esc_html_e( 'Profile name', 'geek-cube-studio' ); ?></span><input type="text" name="name" placeholder="Falling · EJS 4.2.3 · FCEUmm" required></label>
					<label><span><?php esc_html_e( 'Profile slug', 'geek-cube-studio' ); ?></span><input type="text" name="slug" pattern="[a-z0-9-]+" required></label>
					<label class="is-wide"><span><?php esc_html_e( 'Game', 'geek-cube-studio' ); ?></span><select name="game_id" required><option value=""><?php esc_html_e( 'Select', 'geek-cube-studio' ); ?></option>
					<?php
					foreach ( $games as $game ) :
						?>
						<option value="<?php echo esc_attr( $game['id'] ); ?>"><?php echo esc_html( Geek_Cube_Studio_Catalog_Admin::game_title( $game ) . ' · ' . strtoupper( $game['platform'] ) ); ?></option><?php endforeach; ?></select></label>
				<?php
				$roles = array(
					'player'   => __( 'Player package', 'geek-cube-studio' ),
					'core'     => __( 'Core', 'geek-cube-studio' ),
					'rom'      => __( 'ROM', 'geek-cube-studio' ),
					'bios'     => __( 'BIOS (optional)', 'geek-cube-studio' ),
					'config'   => __( 'Configuration (optional)', 'geek-cube-studio' ),
					'controls' => __( 'Controls (optional)', 'geek-cube-studio' ),
				);
				foreach ( $roles as $artifact_role => $label ) :
					$required = in_array( $artifact_role, array( 'player', 'core', 'rom' ), true );
					?>
					<label><span><?php echo esc_html( $label ); ?></span><select name="<?php echo esc_attr( $artifact_role ); ?>_artifact_id" <?php echo $required ? 'required' : ''; ?>><option value=""><?php echo esc_html( $required ? __( 'Select', 'geek-cube-studio' ) : __( 'None', 'geek-cube-studio' ) ); ?></option>
					<?php
					foreach ( $by_type[ $artifact_role ] as $artifact ) :
						?>
						<option value="<?php echo esc_attr( $artifact['id'] ); ?>"><?php echo esc_html( Geek_Cube_Studio_Catalog_Admin::artifact_title( $artifact ) ); ?></option><?php endforeach; ?></select></label>
				<?php endforeach; ?>
					<div class="is-wide"><?php submit_button( __( 'Create immutable profile', 'geek-cube-studio' ), 'primary', 'submit', false ); ?></div>
				</form>
			</section>

			<section class="geek-cube-panel">
				<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Profiles', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Test, approve, and only then promote a combination.', 'geek-cube-studio' ); ?></p></div></div>
				<div class="geek-cube-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Profile', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Game', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Status', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Actions', 'geek-cube-studio' ); ?></th></tr></thead><tbody>
				<?php
				if ( empty( $profiles ) ) :
					?>
					<tr><td colspan="4"><?php esc_html_e( 'No profiles created.', 'geek-cube-studio' ); ?></td></tr><?php endif; ?>
				<?php
				foreach ( $profiles as $row ) :
					$row_game = Geek_Cube_Studio_Repository::get_game( $row['game_id'] );
					?>
					<tr><td><strong><?php echo esc_html( $row['name'] ); ?></strong><br><code><?php echo esc_html( $row['uuid'] ); ?></code></td><td><?php echo esc_html( Geek_Cube_Studio_Catalog_Admin::game_title( $row_game ) ); ?></td><td><span class="geek-cube-badge <?php echo esc_attr( Geek_Cube_Studio_Catalog_Admin::badge_class( $row['status'] ) ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td><td><div class="geek-cube-actions"><a class="button" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'       => 'geek-cube-studio-laboratory',
								'profile_id' => $row['id'],
							),
							admin_url( 'admin.php' )
						)
					);
					?>
"><?php esc_html_e( 'Test', 'geek-cube-studio' ); ?></a>
					<?php
					if ( 'testing' === $row['status'] ) :
						?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="geek_cube_approve_profile"><input type="hidden" name="profile_id" value="<?php echo esc_attr( $row['id'] ); ?>"><?php wp_nonce_field( 'geek_cube_approve_profile' ); ?><button class="button"><?php esc_html_e( 'Approve', 'geek-cube-studio' ); ?></button></form><?php endif; ?>
					<?php
					if ( 'approved' === $row['status'] ) :
						?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="geek_cube_promote_profile"><input type="hidden" name="profile_id" value="<?php echo esc_attr( $row['id'] ); ?>"><?php wp_nonce_field( 'geek_cube_promote_profile' ); ?><button class="button button-primary"><?php esc_html_e( 'Promote', 'geek-cube-studio' ); ?></button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
				</tbody></table></div>
			</section>
		</div>
	<?php endif; ?>
</div>
