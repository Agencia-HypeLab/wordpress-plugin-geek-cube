<?php
/**
 * Plugin Name:       Geek Cube Studio
 * Plugin URI:        https://www.hypelab.com.br/
 * Update URI:        https://www.hypelab.com.br/wordpress-plugin-geek-cube/geek-cube-studio-update.php
 * Description:       Connects Geek Cube Studio game pages to its browser-based player experience.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Agência HypeLab
 * Author URI:        https://www.agenciahypelab.com.br/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       geek-cube-studio
 * Domain Path:       /languages
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GEEK_CUBE_STUDIO_VERSION', '0.1.1' );
define( 'GEEK_CUBE_STUDIO_PLUGIN_FILE', __FILE__ );
define( 'GEEK_CUBE_STUDIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GEEK_CUBE_STUDIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL' ) ) {
	define( 'GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL', 'https://www.hypelab.com.br/wordpress-plugin-geek-cube/geek-cube-studio-update.php' );
}

require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-updater.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-update-patches.php';

/**
 * Bootstrap infrastructure shared by every Geek Cube Studio feature.
 *
 * @return void
 */
function geek_cube_studio_boot() {
	Geek_Cube_Studio_Updater::boot();
	Geek_Cube_Studio_Update_Patches::boot();
}

add_action( 'plugins_loaded', 'geek_cube_studio_boot', 5 );

/**
 * Initialize versioned runtime state.
 *
 * @return void
 */
function geek_cube_studio_activate() {
	add_option( 'geek_cube_studio_version', '0.0.0', '', false );
	delete_site_transient( Geek_Cube_Studio_Updater::MANIFEST_CACHE_KEY );
	Geek_Cube_Studio_Update_Patches::maybe_schedule();
}

register_activation_hook( __FILE__, 'geek_cube_studio_activate' );
