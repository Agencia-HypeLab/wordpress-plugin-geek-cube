<?php
/**
 * Shared, secret-free project metadata for local release tools.
 *
 * @package Geek_Cube_Studio
 */

return array(
	'name'                => 'Geek Cube Studio',
	'slug'                => 'geek-cube-studio',
	'main_file'           => 'geek-cube-studio.php',
	'version_constant'    => 'GEEK_CUBE_STUDIO_VERSION',
	'text_domain'         => 'geek-cube-studio',
	'update_manifest_url' => 'https://www.hypelab.com.br/wordpress-plugin-geek-cube/geek-cube-studio-update.php',
	'package_items'       => array(
		'geek-cube-studio.php',
		'readme.txt',
		'uninstall.php',
		'assets',
		'config/release-public-key.php',
		'includes',
		'languages',
		'views',
		'vendor',
	),
);
