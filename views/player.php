<?php
/**
 * Isolated EmulatorJS player document.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$runtime = array(
	'player'        => '#geek-cube-player',
	'core'          => $core,
	'gameUrl'       => $rom_url,
	'pathToData'    => $data_url,
	'biosUrl'       => $bios_url,
	'gameName'      => $title,
	'volume'        => $volume,
	'profileId'     => $profile['uuid'],
	'laboratory'    => (bool) $laboratory,
	'startOnLoaded' => true,
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<title><?php echo esc_html( $title ); ?> · Geek Cube Studio</title>
	<style>
		html,body,#geek-cube-player{width:100%;height:100%;margin:0;background:#090711}body{overflow:hidden;color:#fff;font-family:system-ui,sans-serif}.geek-cube-player-status{position:fixed;z-index:3;top:12px;left:50%;transform:translateX(-50%);padding:7px 12px;background:rgba(23,20,41,.88);border:1px solid rgba(255,255,255,.16);border-radius:999px;font-size:12px;pointer-events:none}.is-loaded .geek-cube-player-status{display:none}
	</style>
</head>
<body>
	<div class="geek-cube-player-status" role="status"><?php esc_html_e( 'Loading frozen execution profile…', 'geek-cube-studio' ); ?></div>
	<div id="geek-cube-player"></div>
	<script>
		(function () {
			'use strict';
			const runtime = <?php echo wp_json_encode( $runtime, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded runtime data. ?>;
			window.EJS_player = runtime.player;
			window.EJS_core = runtime.core;
			window.EJS_gameUrl = runtime.gameUrl;
			window.EJS_pathtodata = runtime.pathToData;
			window.EJS_gameName = runtime.gameName;
			window.EJS_volume = runtime.volume;
			window.EJS_startOnLoaded = runtime.startOnLoaded;
			if ( runtime.biosUrl ) {
				window.EJS_biosUrl = runtime.biosUrl;
			}
			window.addEventListener( 'load', function () {
				document.body.classList.add( 'is-loaded' );
				if ( window.parent !== window ) {
					window.parent.postMessage( { type: 'geek-cube-player-loaded', profileId: runtime.profileId }, window.location.origin );
				}
			} );
		}());
	</script>
	<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Immutable runtime URL is selected dynamically before WordPress' enqueue phase. ?>
	<script src="<?php echo esc_url( $loader_url ); ?>"></script>
	<?php // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
</body>
</html>
