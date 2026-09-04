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
	'player'          => '#geek-cube-player',
	'core'            => $core,
	'gameUrl'         => $rom_url,
	'pathToData'      => $data_url,
	'biosUrl'         => $bios_url,
	'gameName'        => $title,
	'volume'          => $volume,
	'profileId'       => $profile['uuid'],
	'laboratory'      => (bool) $laboratory,
	'startOnLoaded'   => 'interaction' === $start_mode,
	'startButtonName' => $laboratory ? __( 'Start test', 'geek-cube-studio' ) : __( 'Start game', 'geek-cube-studio' ),
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<title><?php echo esc_html( $title ); ?> · Geek Cube Studio</title>
	<style>
		html,body,#geek-cube-player{width:100%;height:100%;margin:0;background:#090711}body{overflow:hidden;color:#fff;font-family:system-ui,sans-serif}.geek-cube-player-status,.geek-cube-player-fps{position:fixed;z-index:3;top:12px;padding:7px 12px;background:rgba(23,20,41,.88);border:1px solid rgba(255,255,255,.16);border-radius:999px;font-size:12px;pointer-events:none}.geek-cube-player-status{left:50%;transform:translateX(-50%)}.geek-cube-player-fps{right:12px;font-variant-numeric:tabular-nums}.is-loaded .geek-cube-player-status{display:none}
	</style>
</head>
<body>
	<div class="geek-cube-player-status" role="status"><?php esc_html_e( 'Loading frozen execution profile…', 'geek-cube-studio' ); ?></div>
	<?php if ( $laboratory ) : ?>
		<output class="geek-cube-player-fps" data-geek-cube-live-fps>FPS: --</output>
	<?php endif; ?>
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
			window.EJS_startButtonName = runtime.startButtonName;
			if ( runtime.laboratory ) {
				let previousFrame = null;
				let previousTime = 0;
				const fpsOutput = document.querySelector( '[data-geek-cube-live-fps]' );

				const sampleFps = function () {
					const emulator = window.EJS_emulator;
					const manager = emulator && emulator.gameManager;
					if ( ! manager || typeof manager.getFrameNum !== 'function' ) {
						window.requestAnimationFrame( sampleFps );
						return;
					}

					const now = window.performance.now();
					let frame;
					try {
						frame = Number( manager.getFrameNum() );
					} catch ( error ) {
						window.requestAnimationFrame( sampleFps );
						return;
					}

					if ( Number.isFinite( frame ) && previousFrame !== null && now - previousTime >= 750 ) {
						const fps = Math.max( 0, ( frame - previousFrame ) * 1000 / ( now - previousTime ) );
						if ( fpsOutput ) {
							fpsOutput.value = `FPS: ${ fps.toFixed( 1 ) }`;
							fpsOutput.textContent = fpsOutput.value;
						}
						if ( window.parent !== window ) {
							window.parent.postMessage( { type: 'geek-cube-player-fps', profileId: runtime.profileId, fps: fps }, window.location.origin );
						}
						previousFrame = frame;
						previousTime = now;
					} elseif ( previousFrame === null ) {
						previousFrame = frame;
						previousTime = now;
					}

					window.requestAnimationFrame( sampleFps );
				};

				window.EJS_onGameStart = function () {
					previousFrame = null;
					previousTime = 0;
					window.requestAnimationFrame( sampleFps );
				};
			}
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
