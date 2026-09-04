<?php

use PHPUnit\Framework\TestCase;

final class PlayerDocumentTest extends TestCase {

	public function test_laboratory_fps_sampler_uses_javascript_conditional_syntax(): void {
		$template = file_get_contents( dirname( __DIR__ ) . '/views/player.php' );

		$this->assertIsString( $template );
		$this->assertStringContainsString( '} else if ( previousFrame === null ) {', $template );
		$this->assertStringNotContainsString( '} elseif ( previousFrame === null ) {', $template );
	}
}
