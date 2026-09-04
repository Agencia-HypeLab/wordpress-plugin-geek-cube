<?php
/**
 * Immutable artifacts screen.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap geek-cube-admin">
	<header class="geek-cube-admin__header"><div><p class="geek-cube-admin__eyebrow"><?php esc_html_e( 'Versioned files', 'geek-cube-studio' ); ?></p><h1><?php esc_html_e( 'Artifacts', 'geek-cube-studio' ); ?></h1><p><?php esc_html_e( 'Every uploaded byte is stored under its SHA-256 and never overwritten.', 'geek-cube-studio' ); ?></p></div><span class="geek-cube-admin__version"><?php echo esc_html( GEEK_CUBE_STUDIO_VERSION ); ?></span></header>
	<?php Geek_Cube_Studio_Catalog_Admin::render_notice(); ?>
	<?php if ( ! $schema_ready ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The catalog patch is pending. WP-Cron will retry it automatically.', 'geek-cube-studio' ); ?></p></div>
	<?php else : ?>
		<div class="geek-cube-workspace">
			<section class="geek-cube-panel">
				<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Import a version', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Upload only content you may legally use and redistribute.', 'geek-cube-studio' ); ?></p></div></div>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="geek-cube-form">
					<input type="hidden" name="action" value="geek_cube_create_artifact"><?php wp_nonce_field( 'geek_cube_create_artifact' ); ?>
					<label><span><?php esc_html_e( 'Type', 'geek-cube-studio' ); ?></span><select name="type" required>
					<?php
					foreach ( Geek_Cube_Studio_Repository::ARTIFACT_TYPES as $artifact_type ) :
						?>
						<option value="<?php echo esc_attr( $artifact_type ); ?>"><?php echo esc_html( strtoupper( $artifact_type ) ); ?></option><?php endforeach; ?></select></label>
					<label><span><?php esc_html_e( 'Name', 'geek-cube-studio' ); ?></span><input type="text" name="name" required></label>
					<label><span><?php esc_html_e( 'Version', 'geek-cube-studio' ); ?></span><input type="text" name="version" placeholder="4.2.3 or 1.0.0" required></label>
					<label><span><?php esc_html_e( 'Platform', 'geek-cube-studio' ); ?></span><select name="platform"><option value=""><?php esc_html_e( 'Any / not applicable', 'geek-cube-studio' ); ?></option>
					<?php
					foreach ( Geek_Cube_Studio_Repository::PLATFORMS as $platform ) :
						?>
						<option value="<?php echo esc_attr( $platform ); ?>"><?php echo esc_html( strtoupper( $platform ) ); ?></option><?php endforeach; ?></select></label>
					<label><span><?php esc_html_e( 'Core runtime key', 'geek-cube-studio' ); ?></span><input type="text" name="runtime_key" placeholder="fceumm"></label>
					<label><span><?php esc_html_e( 'License', 'geek-cube-studio' ); ?></span><input type="text" name="license_name" placeholder="MIT" required></label>
					<label><span><?php esc_html_e( 'Commercial use reviewed', 'geek-cube-studio' ); ?></span><select name="commercial_use" required><option value="review"><?php esc_html_e( 'Needs review', 'geek-cube-studio' ); ?></option><option value="yes"><?php esc_html_e( 'Allowed', 'geek-cube-studio' ); ?></option><option value="no"><?php esc_html_e( 'Not allowed', 'geek-cube-studio' ); ?></option></select></label>
					<label><span><?php esc_html_e( 'File', 'geek-cube-studio' ); ?></span><input type="file" name="artifact_file" required></label>
					<label class="is-wide"><span><?php esc_html_e( 'Authoritative source URL', 'geek-cube-studio' ); ?></span><input type="url" name="source_url" required></label>
					<label class="is-wide"><span><?php esc_html_e( 'Rights and attribution notes', 'geek-cube-studio' ); ?></span><textarea name="rights_notes" rows="3" required></textarea></label>
					<div class="is-wide geek-cube-callout"><strong><?php esc_html_e( 'Player packages', 'geek-cube-studio' ); ?></strong><p><?php esc_html_e( 'Send the official self-hosted ZIP. The importer safely extracts it and requires a data/loader.js entrypoint.', 'geek-cube-studio' ); ?></p></div>
					<div class="is-wide"><?php submit_button( __( 'Import immutable artifact', 'geek-cube-studio' ), 'primary', 'submit', false ); ?></div>
				</form>
			</section>

			<section class="geek-cube-panel">
				<div class="geek-cube-panel__heading"><div><h2><?php esc_html_e( 'Artifact versions', 'geek-cube-studio' ); ?></h2><p><?php esc_html_e( 'Pending artifacts cannot be attached to profiles.', 'geek-cube-studio' ); ?></p></div></div>
				<div class="geek-cube-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Identity', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Fingerprint', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Rights', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Status', 'geek-cube-studio' ); ?></th><th><?php esc_html_e( 'Review', 'geek-cube-studio' ); ?></th></tr></thead><tbody>
				<?php
				if ( empty( $artifacts ) ) :
					?>
					<tr><td colspan="5"><?php esc_html_e( 'No artifacts imported.', 'geek-cube-studio' ); ?></td></tr><?php endif; ?>
				<?php
				foreach ( $artifacts as $artifact ) :
					?>
					<tr><td><strong><?php echo esc_html( Geek_Cube_Studio_Catalog_Admin::artifact_title( $artifact ) ); ?></strong><br><?php echo esc_html( $artifact['platform'] ? strtoupper( $artifact['platform'] ) : 'GLOBAL' ); ?></td><td><code title="<?php echo esc_attr( $artifact['sha256'] ); ?>"><?php echo esc_html( substr( $artifact['sha256'], 0, 12 ) . '…' ); ?></code><br><?php echo esc_html( size_format( (int) $artifact['file_size'] ) ); ?></td><td><?php echo esc_html( $artifact['license_name'] ); ?><br><small><?php echo esc_html( 'yes' === $artifact['commercial_use'] ? __( 'Commercial use allowed', 'geek-cube-studio' ) : __( 'Not cleared', 'geek-cube-studio' ) ); ?></small></td><td><span class="geek-cube-badge <?php echo esc_attr( Geek_Cube_Studio_Catalog_Admin::badge_class( $artifact['status'] ) ); ?>"><?php echo esc_html( $artifact['status'] ); ?></span></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="geek-cube-inline-form"><input type="hidden" name="action" value="geek_cube_artifact_status"><input type="hidden" name="artifact_id" value="<?php echo esc_attr( $artifact['id'] ); ?>"><?php wp_nonce_field( 'geek_cube_artifact_status' ); ?><select name="status"><option value="verified"><?php esc_html_e( 'Verify', 'geek-cube-studio' ); ?></option><option value="blocked"><?php esc_html_e( 'Block', 'geek-cube-studio' ); ?></option><option value="deprecated"><?php esc_html_e( 'Deprecate', 'geek-cube-studio' ); ?></option><option value="pending"><?php esc_html_e( 'Pending', 'geek-cube-studio' ); ?></option></select><button class="button"><?php esc_html_e( 'Apply', 'geek-cube-studio' ); ?></button></form></td></tr><?php endforeach; ?>
				</tbody></table></div>
			</section>
		</div>
	<?php endif; ?>
</div>
