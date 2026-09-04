<?php

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['test_options']             = array();
		$GLOBALS['test_registered_settings'] = array();
		$GLOBALS['test_settings_errors']     = array();
		$GLOBALS['test_rewrite_flushes']     = 0;
	}

	public function test_settings_registration_uses_single_central_option(): void {
		Geek_Cube_Studio_Settings::register();

		$this->assertArrayHasKey( Geek_Cube_Studio_Settings::OPTION_KEY, $GLOBALS['test_registered_settings'] );
		$this->assertSame(
			Geek_Cube_Studio_Settings::OPTION_GROUP,
			$GLOBALS['test_registered_settings'][ Geek_Cube_Studio_Settings::OPTION_KEY ]['group']
		);
	}

	public function test_section_submission_preserves_other_tabs(): void {
		$current                          = Geek_Cube_Studio_Settings::defaults();
		$current['player_public_enabled'] = '1';
		update_option( Geek_Cube_Studio_Settings::OPTION_KEY, $current );

		$sanitized = Geek_Cube_Studio_Settings::sanitize(
			array(
				'_section' => 'urls',
				'url_slugs' => array(
					'default' => array(
						'catalog' => 'Meus Jogos',
						'game'    => 'Jogo',
						'play'    => 'Jogar Agora',
						'lab'     => 'Laboratorio',
						'profile' => 'Perfil',
					),
				),
			)
		);

		$this->assertSame( '1', $sanitized['player_public_enabled'] );
		$this->assertSame( '0', $sanitized['url_redirect_legacy'] );
		$this->assertSame( 'meus-jogos', $sanitized['url_slugs']['default']['catalog'] );
		$this->assertSame( 'jogar-agora', $sanitized['url_slugs']['default']['play'] );
	}

	public function test_artifact_directory_is_always_relative_and_normalized(): void {
		$sanitized = Geek_Cube_Studio_Settings::sanitize(
			array(
				'_section'                     => 'artifacts',
				'artifact_storage_subdirectory' => '../../Geek Cube\\Artifacts',
			)
		);

		$this->assertSame( 'geek-cube/artifacts', $sanitized['artifact_storage_subdirectory'] );
	}

	public function test_duplicate_route_slugs_are_rejected(): void {
		$current = Geek_Cube_Studio_Settings::defaults();
		update_option( Geek_Cube_Studio_Settings::OPTION_KEY, $current );

		$sanitized = Geek_Cube_Studio_Settings::sanitize(
			array(
				'_section' => 'urls',
				'url_slugs' => array(
					'default' => array(
						'catalog' => 'jogos',
						'game'    => 'jogos',
						'play'    => 'jogar',
						'lab'     => 'laboratorio',
						'profile' => 'perfil',
					),
				),
			)
		);

		$this->assertSame( $current['url_slugs']['default'], $sanitized['url_slugs']['default'] );
		$this->assertCount( 1, $GLOBALS['test_settings_errors'] );
	}

	public function test_url_builder_uses_language_override_and_canonical_object_slug(): void {
		$settings                    = Geek_Cube_Studio_Settings::defaults();
		$settings['url_slugs']['en'] = array(
			'catalog' => 'games',
			'game'    => 'game',
			'play'    => 'play',
			'lab'     => 'laboratory',
			'profile' => 'profile',
		);
		update_option( Geek_Cube_Studio_Settings::OPTION_KEY, $settings );

		$this->assertSame( 'play', Geek_Cube_Studio_URLs::get_route_slug( 'play', 'en' ) );
		$this->assertSame(
			'https://site.example.test/play/demo-game/',
			Geek_Cube_Studio_URLs::build( 'play', 'Demo Game', 'en' )
		);
		$this->assertSame(
			'https://site.example.test/laboratory/profile-id/',
			Geek_Cube_Studio_URLs::build( 'lab', array( 'Profile ID' ), 'en' )
		);
	}

	public function test_rewrites_flush_only_when_route_map_changes(): void {
		$old = Geek_Cube_Studio_Settings::defaults();
		$new = $old;

		Geek_Cube_Studio_URLs::maybe_flush_rewrite_rules( $old, $new );
		$this->assertSame( 0, $GLOBALS['test_rewrite_flushes'] );

		$new['url_slugs']['default']['play'] = 'play';
		Geek_Cube_Studio_URLs::maybe_flush_rewrite_rules( $old, $new );
		$this->assertSame( 1, $GLOBALS['test_rewrite_flushes'] );
	}

	public function test_admin_tab_resolution_rejects_unknown_values(): void {
		$this->assertSame( 'saves', Geek_Cube_Studio_Admin::resolve_tab( array( 'tab' => 'saves' ) ) );
		$this->assertSame( 'overview', Geek_Cube_Studio_Admin::resolve_tab( array( 'tab' => 'unknown' ) ) );
		$this->assertSame( 'overview', Geek_Cube_Studio_Admin::resolve_tab( array( 'tab' => array( 'saves' ) ) ) );
	}

	public function test_release_package_includes_admin_views(): void {
		$project = require dirname( __DIR__ ) . '/config/project.php';

		$this->assertContains( 'views', $project['package_items'] );
	}
}
