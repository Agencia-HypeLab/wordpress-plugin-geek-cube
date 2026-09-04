<?php

use PHPUnit\Framework\TestCase;

final class UpdatePatchesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_filters']     = array();
		$GLOBALS['test_options']     = array();
		$GLOBALS['test_cron_events'] = array();
	}

	public function test_successful_patch_is_persisted_once(): void {
		$calls = 0;

		add_filter(
			'geek_cube_studio_update_patch_registry',
			static function ( $registry ) use ( &$calls ) {
				$registry['001-create-schema'] = array(
					'introduced_in' => '0.1.0',
					'description'   => 'Test schema patch.',
					'callback'      => static function () use ( &$calls ) {
						++$calls;
						return true;
					},
				);
				return $registry;
			}
		);

		Geek_Cube_Studio_Update_Patches::run();
		Geek_Cube_Studio_Update_Patches::run();

		$state = get_option( Geek_Cube_Studio_Update_Patches::STATE_OPTION );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'completed', $state['001-create-schema']['status'] );
		$this->assertSame( '0.1.0', get_option( Geek_Cube_Studio_Update_Patches::VERSION_OPTION ) );
	}

	public function test_failed_patch_is_recorded_for_retry(): void {
		add_filter(
			'geek_cube_studio_update_patch_registry',
			static function ( $registry ) {
				$registry['002-retry-example'] = array(
					'introduced_in' => '0.1.0',
					'description'   => 'Retry test.',
					'callback'      => static function () {
						return new WP_Error( 'temporary', 'Temporary failure.' );
					},
				);
				return $registry;
			}
		);

		Geek_Cube_Studio_Update_Patches::run();

		$state = get_option( Geek_Cube_Studio_Update_Patches::STATE_OPTION );
		$this->assertSame( 'pending', $state['002-retry-example']['status'] );
		$this->assertSame( 1, $state['002-retry-example']['attempts'] );
		$this->assertSame( 'Temporary failure.', $state['002-retry-example']['error'] );
		$this->assertArrayHasKey( Geek_Cube_Studio_Update_Patches::CRON_HOOK, $GLOBALS['test_cron_events'] );
	}

	public function test_future_patch_is_not_registered_early(): void {
		add_filter(
			'geek_cube_studio_update_patch_registry',
			static function ( $registry ) {
				$registry['future-patch'] = array(
					'introduced_in' => '9.0.0',
					'description'   => 'Future patch.',
					'callback'      => '__return_true',
				);
				return $registry;
			}
		);

		$this->assertSame( array(), Geek_Cube_Studio_Update_Patches::registry() );
	}
}
