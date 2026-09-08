<?php
/**
 * Games catalog screen.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap geek-cube-admin">
	<header class="geek-cube-admin__header">
		<div><p class="geek-cube-admin__eyebrow"><?php esc_html_e( 'Immutable catalog', 'geek-cube-studio' ); ?></p><h1><?php esc_html_e( 'Games', 'geek-cube-studio' ); ?></h1><p><?php esc_html_e( 'A game is the stable identity that receives versioned execution profiles.', 'geek-cube-studio' ); ?></p></div>
		<span class="geek-cube-admin__version"><?php echo esc_html( GEEK_CUBE_STUDIO_VERSION ); ?></span>
	</header>
	<?php Geek_Cube_Studio_Catalog_Admin::render_notice(); ?>
	<?php if ( ! $schema_ready ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The catalog patch is pending. WP-Cron will retry it automatically.', 'geek-cube-studio' ); ?></p></div>
	<?php else : ?>
		<div class="geek-cube-workspace">
			<section class="geek-cube-panel">
				<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Register a game', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Do not attach ROM files here; they are separate immutable artifacts.', 'geek-cube-studio' ); ?></p></div></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="geek-cube-form">
					<input type="hidden" name="action" value="geek_cube_create_game">
					<?php wp_nonce_field( 'geek_cube_create_game' ); ?>
					<label><span><?php esc_html_e( 'Title', 'geek-cube-studio' ); ?></span><input type="text" name="title" required></label>
					<label><span><?php esc_html_e( 'Canonical slug', 'geek-cube-studio' ); ?></span><input type="text" name="slug" pattern="[a-z0-9-]+" placeholder="falling-nes" data-geek-cube-slug></label>
					<label><span><?php esc_html_e( 'Platform', 'geek-cube-studio' ); ?></span><select name="platform" required><option value=""><?php esc_html_e( 'Select', 'geek-cube-studio' ); ?></option>
					<?php
					foreach ( Geek_Cube_Studio_Repository::PLATFORMS as $platform ) :
						?>
						<option value="<?php echo esc_attr( $platform ); ?>"><?php echo esc_html( strtoupper( $platform ) ); ?></option><?php endforeach; ?></select></label>
					<label><span><?php esc_html_e( 'Initial language', 'geek-cube-studio' ); ?></span><input type="text" name="language" value="default" required></label>
					<label class="is-wide"><span><?php esc_html_e( 'Description', 'geek-cube-studio' ); ?></span><textarea name="description" rows="3"></textarea></label>
					<label class="is-wide"><span><?php esc_html_e( 'Authoritative source URL', 'geek-cube-studio' ); ?></span><input type="url" name="source_url"></label>
					<label class="is-wide"><span><?php esc_html_e( 'Rights notes', 'geek-cube-studio' ); ?></span><textarea name="rights_notes" rows="3" required></textarea></label>
					<div class="is-wide"><?php submit_button( __( 'Register game', 'geek-cube-studio' ), 'primary', 'submit', false ); ?></div>
				</form>
			</section>

			<section class="geek-cube-panel">
				<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Registered games', 'geek-cube-studio' ); ?></h2><p><?php echo esc_html( sprintf( /* translators: %d: number of games. */ __( '%d catalog identities', 'geek-cube-studio' ), count( $games ) ) ); ?></p></div></div>
				<div class="geek-cube-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Game', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Platform', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Status', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Production profile', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Public URL', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Publication', 'geek-cube-studio' ); ?></th></tr></thead><tbody>
				<?php
				if ( empty( $games ) ) :
					?>
					<tr><td colspan="6"><?php esc_html_e( 'No games registered.', 'geek-cube-studio' ); ?></td></tr><?php endif; ?>
				<?php
				foreach ( $games as $game ) :
					$public_url   = Geek_Cube_Studio_URLs::build( 'play', $game['slug'] );
					$is_published = 'published' === $game['status'] && ! empty( $game['production_profile_id'] );
					$candidates   = isset( $production_candidates[ (int) $game['id'] ] ) ? $production_candidates[ (int) $game['id'] ] : array();
					$game_title   = Geek_Cube_Studio_Catalog_Admin::game_title( $game );
					$description  = Geek_Cube_Studio_Repository::translated_value( $game['descriptions'] );
					?>
					<tr class="geek-cube-game-edit-row"><td colspan="6">
						<details class="geek-cube-game-edit">
							<summary><?php esc_html_e( 'Edit game', 'geek-cube-studio' ); ?></summary>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="geek-cube-form">
								<input type="hidden" name="action" value="geek_cube_update_game">
								<input type="hidden" name="game_id" value="<?php echo esc_attr( $game['id'] ); ?>">
								<input type="hidden" name="language" value="default">
								<?php wp_nonce_field( 'geek_cube_update_game' ); ?>
								<label><span><?php esc_html_e( 'Title', 'geek-cube-studio' ); ?></span><input type="text" name="title" value="<?php echo esc_attr( $game_title ); ?>" required></label>
								<label><span><?php esc_html_e( 'Canonical slug', 'geek-cube-studio' ); ?></span><input type="text" name="slug" value="<?php echo esc_attr( $game['slug'] ); ?>" pattern="[a-z0-9-]+" data-geek-cube-slug required></label>
								<label><span><?php esc_html_e( 'Platform', 'geek-cube-studio' ); ?></span><select name="platform" required><?php foreach ( Geek_Cube_Studio_Repository::PLATFORMS as $platform ) : ?>
									<option value="<?php echo esc_attr( $platform ); ?>"<?php selected( $game['platform'], $platform ); ?>><?php echo esc_html( strtoupper( $platform ) ); ?></option>
								<?php endforeach; ?></select><small><?php esc_html_e( 'The platform cannot change after a profile is created.', 'geek-cube-studio' ); ?></small></label>
								<label class="is-wide"><span><?php esc_html_e( 'Description', 'geek-cube-studio' ); ?></span><textarea name="description" rows="3"><?php echo esc_textarea( $description ); ?></textarea></label>
								<label class="is-wide"><span><?php esc_html_e( 'Authoritative source URL', 'geek-cube-studio' ); ?></span><input type="url" name="source_url" value="<?php echo esc_attr( $game['source_url'] ); ?>"></label>
								<label class="is-wide"><span><?php esc_html_e( 'Rights notes', 'geek-cube-studio' ); ?></span><textarea name="rights_notes" rows="3"><?php echo esc_textarea( $game['rights_notes'] ); ?></textarea></label>
								<div class="is-wide"><button class="button button-primary"><?php esc_html_e( 'Save game', 'geek-cube-studio' ); ?></button></div>
							</form>
						</details>
					</td></tr>
					<tr>
						<td><strong><?php echo esc_html( $game_title ); ?></strong><br><code><?php echo esc_html( $game['slug'] ); ?></code></td>
						<td><?php echo esc_html( strtoupper( $game['platform'] ) ); ?></td>
						<td><span class="geek-cube-badge <?php echo esc_attr( Geek_Cube_Studio_Catalog_Admin::badge_class( $game['status'] ) ); ?>"><?php echo esc_html( Geek_Cube_Studio_Catalog_Admin::status_label( $game['status'] ) ); ?></span></td>
						<td><?php echo $game['production_profile_id'] ? esc_html( '#' . $game['production_profile_id'] ) : '—'; ?></td>
						<td>
							<?php if ( $is_published ) : ?>
								<a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $public_url ); ?></code></a>
							<?php else : ?>
								<code><?php echo esc_html( $public_url ); ?></code><br>
								<small><?php esc_html_e( 'Available after publishing.', 'geek-cube-studio' ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( empty( $candidates ) ) : ?>
								<small><?php esc_html_e( 'No approved test profile is available.', 'geek-cube-studio' ); ?></small>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="geek-cube-inline-form geek-cube-publication-form">
									<input type="hidden" name="action" value="geek_cube_promote_profile">
									<input type="hidden" name="return_page" value="geek-cube-studio-games">
									<?php wp_nonce_field( 'geek_cube_promote_profile' ); ?>
									<select name="profile_id" aria-label="<?php esc_attr_e( 'Select an approved profile', 'geek-cube-studio' ); ?>">
										<?php
										foreach ( $candidates as $profile ) :
											/* translators: 1: profile ID, 2: most recent profile update date. */
											$profile_label = sprintf( __( 'Profile #%1$d - %2$s', 'geek-cube-studio' ), $profile['id'], mysql2date( get_option( 'date_format' ), $profile['updated_at'], true ) );
											?>
											<option value="<?php echo esc_attr( $profile['id'] ); ?>"><?php echo esc_html( $profile_label ); ?></option>
										<?php endforeach; ?>
									</select>
									<button class="button button-primary"><?php echo esc_html( $is_published ? __( 'Replace production profile', 'geek-cube-studio' ) : __( 'Publish game', 'geek-cube-studio' ) ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table></div>
			</section>
		</div>
	<?php endif; ?>
</div>
