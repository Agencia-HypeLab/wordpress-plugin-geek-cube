<?php
/**
 * Build and verify the native WordPress translation catalogues.
 *
 * Usage:
 *   php tools/i18n.php make-pot
 *   php tools/i18n.php sync-pt-br
 *   php tools/i18n.php compile
 *   php tools/i18n.php verify
 *
 * This project intentionally keeps the compiler dependency-free so a release
 * can be built on the same PHP-only workstation used for deployment.
 *
 * @package Geek_Cube_Studio
 */

$root_dir = realpath( __DIR__ . '/..' );

if ( false === $root_dir ) {
	fwrite( STDERR, "Unable to resolve the project root.\n" );
	exit( 1 );
}

$domain   = 'geek-cube-studio';
$languages = $root_dir . '/languages';
$pot_file  = $languages . '/' . $domain . '.pot';
$po_file   = $languages . '/' . $domain . '-pt_BR.po';
$mo_file   = $languages . '/' . $domain . '-pt_BR.mo';
$command   = $argv[1] ?? 'verify';

/**
 * Escape a value for a GNU gettext catalogue line.
 *
 * @param string $value Value to escape.
 * @return string
 */
function geek_cube_studio_i18n_quote( $value ) {
	return '"' . str_replace( array( '\\', '"', "\n", "\r" ), array( '\\\\', '\\"', '\\n', '' ), $value ) . '"';
}

/**
 * Decode a quoted GNU gettext catalogue value.
 *
 * @param string $value Quoted value.
 * @return string
 */
function geek_cube_studio_i18n_unquote( $value ) {
	$decoded = json_decode( trim( $value ), true );

	if ( ! is_string( $decoded ) ) {
		throw new RuntimeException( 'Invalid gettext quoted value.' );
	}

	return $decoded;
}

/**
 * Return every text-domain string directly used by production PHP sources.
 *
 * @param string $root_dir Project root.
 * @return string[]
 */
function geek_cube_studio_i18n_source_messages( $root_dir ) {
	$files = array( $root_dir . '/geek-cube-studio.php' );

	foreach ( array( 'includes', 'views' ) as $directory ) {
		$path = $root_dir . '/' . $directory;
		if ( ! is_dir( $path ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
	}

	$messages = array();
	$pattern  = "~(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\\(\\s*'((?:\\\\.|[^'])*)'\\s*,\\s*'geek-cube-studio'~s";

	foreach ( $files as $file ) {
		$contents = file_get_contents( $file );
		if ( false === $contents || ! preg_match_all( $pattern, $contents, $matches ) ) {
			continue;
		}

		foreach ( $matches[1] as $message ) {
			$messages[ str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $message ) ] = true;
		}
	}

	$messages = array_keys( $messages );
	sort( $messages, SORT_STRING );

	return $messages;
}

/**
 * Read singular gettext entries from a catalogue.
 *
 * @param string $path Catalogue path.
 * @return array<string,string>
 */
function geek_cube_studio_i18n_read_po( $path ) {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		throw new RuntimeException( 'Unable to read ' . $path . '.' );
	}

	$entries = array();
	$id      = null;
	$value   = null;
	$state   = '';
	$flush   = static function () use ( &$entries, &$id, &$value ) {
		if ( null !== $id && null !== $value ) {
			$entries[ $id ] = $value;
		}
		$id    = null;
		$value = null;
	};

	foreach ( preg_split( '/\R/', preg_replace( '/^\xEF\xBB\xBF/', '', $contents ) ) as $line ) {
		if ( '' === trim( $line ) ) {
			$flush();
			$state = '';
			continue;
		}

		if ( 0 === strpos( $line, '#' ) ) {
			continue;
		}

		if ( 0 === strpos( $line, 'msgid ' ) ) {
			$flush();
			$id    = geek_cube_studio_i18n_unquote( substr( $line, 6 ) );
			$value = null;
			$state = 'id';
			continue;
		}

		if ( 0 === strpos( $line, 'msgstr ' ) ) {
			$value = geek_cube_studio_i18n_unquote( substr( $line, 7 ) );
			$state = 'value';
			continue;
		}

		if ( '"' === substr( ltrim( $line ), 0, 1 ) ) {
			$part = geek_cube_studio_i18n_unquote( ltrim( $line ) );
			if ( 'id' === $state ) {
				$id .= $part;
			} elseif ( 'value' === $state ) {
				$value .= $part;
			}
		}
	}

	$flush();

	return $entries;
}

/**
 * Create a GNU MO binary from singular gettext entries.
 *
 * @param array<string,string> $entries Entries including the empty header key.
 * @return string
 */
function geek_cube_studio_i18n_compile_mo( array $entries ) {
	ksort( $entries, SORT_STRING );
	$count              = count( $entries );
	$original_offset    = 28 + ( $count * 16 );
	$original_block     = '';
	$translation_block  = '';
	$original_table     = '';
	$translation_table  = '';
	$translation_offset = $original_offset;

	foreach ( $entries as $id => $translation ) {
		$translation_offset += strlen( $id ) + 1;
	}

	$current_original    = $original_offset;
	$current_translation = $translation_offset;
	foreach ( $entries as $id => $translation ) {
		$original_table    .= pack( 'VV', strlen( $id ), $current_original );
		$translation_table .= pack( 'VV', strlen( $translation ), $current_translation );
		$original_block    .= $id . "\0";
		$translation_block .= $translation . "\0";
		$current_original    += strlen( $id ) + 1;
		$current_translation += strlen( $translation ) + 1;
	}

	return pack( 'V7', 0x950412de, 0, $count, 28, 28 + ( $count * 8 ), 0, $original_offset ) . $original_table . $translation_table . $original_block . $translation_block;
}

/**
 * Write the POT source template.
 *
 * @param string[] $messages Source messages.
 * @param string   $path Destination path.
 * @return void
 */
function geek_cube_studio_i18n_write_pot( array $messages, $path ) {
	$header = "Project-Id-Version: Geek Cube Studio\n"
		. "Report-Msgid-Bugs-To: \n"
		. "POT-Creation-Date: 2026-09-04 00:00+0000\n"
		. "MIME-Version: 1.0\n"
		. "Content-Type: text/plain; charset=UTF-8\n"
		. "Content-Transfer-Encoding: 8bit\n"
		. "X-Domain: geek-cube-studio\n";
	$output = "msgid \"\"\nmsgstr \"\"\n";
	foreach ( explode( "\n", rtrim( $header, "\n" ) ) as $line ) {
		$output .= geek_cube_studio_i18n_quote( $line . "\n" ) . "\n";
	}

	foreach ( $messages as $message ) {
		$output .= "\nmsgid " . geek_cube_studio_i18n_quote( $message ) . "\nmsgstr \"\"\n";
	}

	if ( false === file_put_contents( $path, $output, LOCK_EX ) ) {
		throw new RuntimeException( 'Unable to write ' . $path . '.' );
	}
}

/**
 * Write a singular pt_BR catalogue while retaining existing translations.
 *
 * @param string[]             $messages Source messages.
 * @param array<string,string> $entries Existing entries.
 * @param string               $path Destination path.
 * @return void
 */
function geek_cube_studio_i18n_write_pt_br( array $messages, array $entries, $path ) {
	$header = isset( $entries[''] ) ? $entries[''] : '';
	$output = "msgid \"\"\nmsgstr \"\"\n";

	foreach ( explode( "\n", rtrim( $header, "\n" ) ) as $line ) {
		$output .= geek_cube_studio_i18n_quote( $line . "\n" ) . "\n";
	}

	foreach ( $messages as $message ) {
		$translation = isset( $entries[ $message ] ) ? $entries[ $message ] : '';
		$output     .= "\nmsgid " . geek_cube_studio_i18n_quote( $message ) . "\nmsgstr " . geek_cube_studio_i18n_quote( $translation ) . "\n";
	}

	if ( false === file_put_contents( $path, $output, LOCK_EX ) ) {
		throw new RuntimeException( 'Unable to write ' . $path . '.' );
	}
}

try {
	if ( ! in_array( $command, array( 'make-pot', 'sync-pt-br', 'compile', 'verify' ), true ) ) {
		throw new InvalidArgumentException( 'Usage: php tools/i18n.php [make-pot|sync-pt-br|compile|verify]' );
	}

	if ( 'make-pot' === $command ) {
		geek_cube_studio_i18n_write_pot( geek_cube_studio_i18n_source_messages( $root_dir ), $pot_file );
		echo "POT catalogue written: {$pot_file}\n";
		exit( 0 );
	}

	if ( 'sync-pt-br' === $command ) {
		$existing_entries = is_file( $po_file ) ? geek_cube_studio_i18n_read_po( $po_file ) : array();
		geek_cube_studio_i18n_write_pt_br( geek_cube_studio_i18n_source_messages( $root_dir ), $existing_entries, $po_file );
		echo "pt_BR catalogue synchronized: {$po_file}\n";
		exit( 0 );
	}

	$entries = geek_cube_studio_i18n_read_po( $po_file );
	if ( ! isset( $entries[''] ) || false === strpos( $entries[''], 'Language: pt_BR' ) ) {
		throw new RuntimeException( 'The pt_BR catalogue is missing its required header.' );
	}

	$missing_translations = array_filter(
		$entries,
		static function ( $translation, $message ) {
			return '' !== $message && '' === $translation;
		},
		ARRAY_FILTER_USE_BOTH
	);
	if ( ! empty( $missing_translations ) ) {
		throw new RuntimeException( 'The pt_BR catalogue contains untranslated entries.' );
	}

	$compiled = geek_cube_studio_i18n_compile_mo( $entries );
	if ( 'compile' === $command ) {
		if ( false === file_put_contents( $mo_file, $compiled, LOCK_EX ) ) {
			throw new RuntimeException( 'Unable to write ' . $mo_file . '.' );
		}
		echo "MO catalogue compiled: {$mo_file}\n";
		exit( 0 );
	}

	$expected = array_fill_keys( geek_cube_studio_i18n_source_messages( $root_dir ), true );
	$pot      = geek_cube_studio_i18n_read_po( $pot_file );
	unset( $pot[''] );
	$po_messages = $entries;
	unset( $po_messages[''] );

	if ( array_keys( $expected ) !== array_keys( $pot ) || array_keys( $expected ) !== array_keys( $po_messages ) ) {
		throw new RuntimeException( 'Translation catalogues are not synchronized with the production source. Run make-pot and update the pt_BR PO file.' );
	}

	$existing = is_file( $mo_file ) ? file_get_contents( $mo_file ) : false;
	if ( ! is_string( $existing ) || ! hash_equals( $compiled, $existing ) ) {
		throw new RuntimeException( 'The compiled pt_BR MO file is out of date. Run php tools/i18n.php compile.' );
	}

	echo 'Translation catalogues verified (' . count( $expected ) . " strings).\n";
} catch ( Throwable $exception ) {
	fwrite( STDERR, 'Translation catalogue error: ' . $exception->getMessage() . "\n" );
	exit( 1 );
}
