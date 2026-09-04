<?php
/**
 * Uninstall infrastructure-only options.
 *
 * Product data is intentionally preserved until its lifecycle is formally
 * defined.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'geek_cube_studio_version' );
delete_option( 'geek_cube_studio_update_patch_state' );
delete_option( 'geek_cube_studio_update_patch_lock' );
delete_option( 'geek_cube_studio_update_last_check' );
delete_option( 'geek_cube_studio_settings' );
delete_site_transient( 'geek_cube_studio_update_manifest' );
