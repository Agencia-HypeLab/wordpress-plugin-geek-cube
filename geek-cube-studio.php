<?php
/**
 * Plugin Name:       Geek Cube Studio
 * Plugin URI:        https://www.hypelab.com.br/
 * Update URI:        https://www.hypelab.com.br/wordpress-plugin-geek-cube/geek-cube-studio-update.php
 * Description:       Connects Geek Cube Studio game pages to its browser-based player experience.
 * Version:           0.1.16
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

define( 'GEEK_CUBE_STUDIO_VERSION', '0.1.16' );
define( 'GEEK_CUBE_STUDIO_PLUGIN_FILE', __FILE__ );
define( 'GEEK_CUBE_STUDIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GEEK_CUBE_STUDIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL' ) ) {
	define( 'GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL', 'https://www.hypelab.com.br/wordpress-plugin-geek-cube/geek-cube-studio-update.php' );
}

require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-updater.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-update-patches.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-settings.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-urls.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-schema.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-artifact-storage.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-repository.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-seed.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-player.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-admin.php';
require_once GEEK_CUBE_STUDIO_PLUGIN_DIR . 'includes/class-geek-cube-studio-catalog-admin.php';

/**
 * Load the plugin catalogue for both the administration and public player.
 *
 * @return void
 */
function geek_cube_studio_load_textdomain() {
	load_plugin_textdomain( 'geek-cube-studio', false, dirname( plugin_basename( GEEK_CUBE_STUDIO_PLUGIN_FILE ) ) . '/languages' );
}

add_action( 'init', 'geek_cube_studio_load_textdomain', 0 );

/**
 * Bootstrap infrastructure shared by every Geek Cube Studio feature.
 *
 * @return void
 */
function geek_cube_studio_boot() {
	geek_cube_studio_refresh_opcode_cache();
	Geek_Cube_Studio_Schema::boot();
	Geek_Cube_Studio_Seed::boot();
	Geek_Cube_Studio_Updater::boot();
	Geek_Cube_Studio_Update_Patches::boot();
	Geek_Cube_Studio_URLs::boot();
	Geek_Cube_Studio_Player::boot();

	if ( is_admin() ) {
		Geek_Cube_Studio_Admin::boot();
		Geek_Cube_Studio_Catalog_Admin::boot();
	}
}

/**
 * Clear the PHP OPcache once after this plugin version is installed.
 *
 * Shared hosting may keep old PHP bytecode after a native WordPress update.
 * This check is intentionally version-scoped, and does nothing when OPcache
 * is unavailable or disabled for the current PHP runtime.
 *
 * @return void
 */
function geek_cube_studio_refresh_opcode_cache() {
	$option = 'geek_cube_studio_opcode_cache_version';
	if ( GEEK_CUBE_STUDIO_VERSION === get_option( $option, '' ) ) {
		return;
	}

	geek_cube_studio_clear_opcode_cache();

	update_option( $option, GEEK_CUBE_STUDIO_VERSION, false );
}

/**
 * Clear OPcache when it is enabled for the current PHP runtime.
 *
 * @return void
 */
function geek_cube_studio_clear_opcode_cache() {
	if ( ! function_exists( 'opcache_get_status' ) || ! function_exists( 'opcache_reset' ) ) {
		return;
	}

	$status = opcache_get_status( false ); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.opcache_get_statusFound -- Plugin requires PHP 8.1.
	if ( is_array( $status ) && ! empty( $status['opcache_enabled'] ) ) {
		opcache_reset(); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.opcache_resetFound -- Plugin requires PHP 8.1.
	}
}

add_action( 'plugins_loaded', 'geek_cube_studio_boot', 5 );

/**
 * Invalidate PHP bytecode immediately after a native update of this plugin.
 *
 * @param WP_Upgrader         $upgrader WordPress upgrader instance.
 * @param array<string,mixed> $options Upgrader operation details.
 * @return void
 */
function geek_cube_studio_after_plugin_update( $upgrader, $options ) {
	if ( ! is_array( $options ) || 'update' !== ( $options['action'] ?? '' ) || 'plugin' !== ( $options['type'] ?? '' ) ) {
		return;
	}

	$plugins = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : array( $options['plugin'] ?? '' );
	if ( ! in_array( plugin_basename( GEEK_CUBE_STUDIO_PLUGIN_FILE ), $plugins, true ) ) {
		return;
	}

	geek_cube_studio_clear_opcode_cache();
	delete_option( 'geek_cube_studio_opcode_cache_version' );
}

add_action( 'upgrader_process_complete', 'geek_cube_studio_after_plugin_update', 10, 2 );

/**
 * Initialize versioned runtime state.
 *
 * @return void
 */
function geek_cube_studio_activate() {
	Geek_Cube_Studio_Schema::boot();
	Geek_Cube_Studio_Seed::boot();
	add_option( 'geek_cube_studio_version', '0.0.0', '', false );
	add_option( Geek_Cube_Studio_Settings::OPTION_KEY, Geek_Cube_Studio_Settings::defaults(), '', false );
	delete_site_transient( Geek_Cube_Studio_Updater::MANIFEST_CACHE_KEY );
	Geek_Cube_Studio_Schema::install();
	Geek_Cube_Studio_Player::register_rewrite_rules();
	flush_rewrite_rules( false );
	Geek_Cube_Studio_Update_Patches::maybe_schedule();
}

register_activation_hook( __FILE__, 'geek_cube_studio_activate' );
