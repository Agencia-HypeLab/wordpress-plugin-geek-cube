<?php
/**
 * Validate, version, package, sign, push and deploy Geek Cube Studio.
 *
 * The default command is intentionally the complete release workflow:
 * php tools/build.php
 *
 * @package Geek_Cube_Studio
 */

$root_dir = realpath( __DIR__ . '/..' );

if ( false === $root_dir ) {
	fwrite( STDERR, "Unable to resolve the project root.\n" );
	exit( 1 );
}

$project = require $root_dir . '/config/project.php';

require_once __DIR__ . '/release-signing.php';
require_once __DIR__ . '/release-vault-note.php';
require_once __DIR__ . '/release-retention.php';

/**
 * Parse supported build options.
 *
 * @param string[] $arguments CLI arguments.
 * @return array<string,mixed>
 */
function geek_cube_studio_build_options( array $arguments ) {
	$options = array(
		'bump'              => true,
		'bump_type'         => 'patch',
		'auto_update'       => false,
		'auto_update_major' => false,
		'channel'           => 'stable',
		'rollout_percent'   => 100,
		'validate_only'     => false,
	);

	foreach ( $arguments as $argument ) {
		if ( '--no-bump' === $argument ) {
			$options['bump'] = false;
			continue;
		}

		if ( '--auto-update' === $argument ) {
			$options['auto_update'] = true;
			continue;
		}

		if ( '--auto-update-major' === $argument ) {
			$options['auto_update']       = true;
			$options['auto_update_major'] = true;
			continue;
		}

		if ( '--no-auto-update' === $argument ) {
			$options['auto_update']       = false;
			$options['auto_update_major'] = false;
			continue;
		}

		if ( '--validate-only' === $argument ) {
			$options['validate_only'] = true;
			continue;
		}

		if ( 0 === strpos( $argument, '--bump=' ) ) {
			$type = strtolower( trim( substr( $argument, 7 ) ) );

			if ( ! in_array( $type, array( 'patch', 'minor', 'major' ), true ) ) {
				fwrite( STDERR, "Invalid bump type. Use patch, minor or major.\n" );
				exit( 1 );
			}

			$options['bump']      = true;
			$options['bump_type'] = $type;
			continue;
		}

		if ( 0 === strpos( $argument, '--channel=' ) ) {
			$channel = strtolower( trim( substr( $argument, 10 ) ) );

			if ( ! in_array( $channel, array( 'stable', 'preview' ), true ) ) {
				fwrite( STDERR, "Invalid channel. Use stable or preview.\n" );
				exit( 1 );
			}

			$options['channel'] = $channel;
			continue;
		}

		if ( 0 === strpos( $argument, '--rollout-percent=' ) ) {
			$options['rollout_percent'] = max( 0, min( 100, (int) substr( $argument, 18 ) ) );
			continue;
		}

		fwrite( STDERR, "Unknown build option: {$argument}\n" );
		exit( 1 );
	}

	if ( 'stable' !== $options['channel'] ) {
		$options['auto_update']       = false;
		$options['auto_update_major'] = false;
	}

	return $options;
}

/**
 * Execute a command in the project and fail on a non-zero exit status.
 *
 * @param string $command Command.
 * @param string $failure Failure message.
 * @return void
 */
function geek_cube_studio_build_run( $command, $failure ) {
	$status = 1;
	passthru( $command, $status );

	if ( 0 !== $status ) {
		fwrite( STDERR, $failure . PHP_EOL );
		exit( 1 );
	}
}

/**
 * Execute and capture a Git command.
 *
 * @param string   $root_dir Project root.
 * @param string   $arguments Git arguments.
 * @param string[] $output Captured output.
 * @return int
 */
function geek_cube_studio_build_git( $root_dir, $arguments, &$output ) {
	$output  = array();
	$status  = 1;
	$command = 'git -C ' . escapeshellarg( $root_dir ) . ' ' . $arguments . ' 2>&1';
	exec( $command, $output, $status );

	return $status;
}

/**
 * Validate Git state and interactive SSH push access.
 *
 * @param string $root_dir Project root.
 * @return string Current branch.
 */
function geek_cube_studio_build_git_preflight( $root_dir ) {
	$output = array();

	if ( 0 !== geek_cube_studio_build_git( $root_dir, 'rev-parse --is-inside-work-tree', $output ) || 'true' !== trim( implode( '', $output ) ) ) {
		fwrite( STDERR, "Git preflight failed: project is not a Git working tree.\n" );
		exit( 1 );
	}

	if ( 0 !== geek_cube_studio_build_git( $root_dir, 'branch --show-current', $output ) ) {
		fwrite( STDERR, "Git preflight failed: unable to detect current branch.\n" );
		exit( 1 );
	}

	$branch = trim( implode( PHP_EOL, $output ) );

	if ( '' === $branch || ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $branch ) ) {
		fwrite( STDERR, "Git preflight failed: releases require a named branch.\n" );
		exit( 1 );
	}

	if ( 0 !== geek_cube_studio_build_git( $root_dir, 'remote get-url --push origin', $output ) ) {
		fwrite( STDERR, "Git preflight failed: origin is not configured.\n" );
		exit( 1 );
	}

	$origin = trim( implode( PHP_EOL, $output ) );

	if ( ! preg_match( '#^(?:ssh://|[^/@\s]+@[^/:\s]+:).+#', $origin ) ) {
		fwrite( STDERR, "Git preflight failed: origin must use SSH.\n" );
		exit( 1 );
	}

	echo "Checking Git push access. OpenSSH may request the project key passphrase.\n";
	geek_cube_studio_build_run(
		'git -C ' . escapeshellarg( $root_dir ) . ' push --dry-run origin ' . escapeshellarg( 'HEAD:refs/heads/' . $branch ),
		'Git SSH preflight failed.'
	);

	return $branch;
}

/**
 * Run local PHP and optional JavaScript quality gates.
 *
 * @param string $root_dir Project root.
 * @return void
 */
function geek_cube_studio_build_quality_gates( $root_dir ) {
	$phpunit = $root_dir . '/vendor/bin/phpunit';
	$phpcs   = $root_dir . '/vendor/bin/phpcs';

	if ( ! is_file( $phpunit ) || ! is_file( $phpcs ) ) {
		fwrite( STDERR, "Development dependencies are missing. Run composer install first.\n" );
		exit( 1 );
	}

	geek_cube_studio_build_run(
		escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root_dir . '/tools/i18n.php' ) . ' verify',
		'Translation catalogue verification failed. Release stopped.'
	);
	geek_cube_studio_build_run(
		escapeshellarg( PHP_BINARY ) . ' -d memory_limit=1G ' . escapeshellarg( $phpunit ),
		'PHPUnit failed. Release stopped.'
	);
	geek_cube_studio_build_run(
		escapeshellarg( PHP_BINARY ) . ' -d memory_limit=1G ' . escapeshellarg( $phpcs ),
		'PHPCS failed. Release stopped.'
	);

	if ( is_file( $root_dir . '/package-lock.json' ) ) {
		geek_cube_studio_build_run( 'npm --prefix ' . escapeshellarg( $root_dir ) . ' ci', 'npm ci failed. Release stopped.' );
		geek_cube_studio_build_run( 'npm --prefix ' . escapeshellarg( $root_dir ) . ' run build', 'Frontend build failed. Release stopped.' );
	}
}

/**
 * Detect the version from the plugin header.
 *
 * @param string $plugin_file Main plugin file.
 * @return string
 */
function geek_cube_studio_build_detect_version( $plugin_file ) {
	$contents = is_file( $plugin_file ) ? file_get_contents( $plugin_file ) : false;

	if ( is_string( $contents ) && preg_match( '/^\s*\*\s*Version:\s*([0-9A-Za-z.+-]+)/m', $contents, $matches ) ) {
		return trim( $matches[1] );
	}

	return '';
}

/**
 * Increment one semantic version.
 *
 * @param string $version Current version.
 * @param string $type Bump type.
 * @return string
 */
function geek_cube_studio_build_next_version( $version, $type ) {
	if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $version, $matches ) ) {
		return '';
	}

	$major = (int) $matches[1];
	$minor = (int) $matches[2];
	$patch = (int) $matches[3];

	if ( 'major' === $type ) {
		++$major;
		$minor = 0;
		$patch = 0;
	} elseif ( 'minor' === $type ) {
		++$minor;
		$patch = 0;
	} else {
		++$patch;
	}

	return sprintf( '%d.%d.%d', $major, $minor, $patch );
}

/**
 * Update the plugin header, version constant and stable tag together.
 *
 * @param string $plugin_file Main plugin file.
 * @param string $readme_file WordPress readme.
 * @param string $constant Version constant.
 * @param string $version New version.
 * @return void
 */
function geek_cube_studio_build_write_version( $plugin_file, $readme_file, $constant, $version ) {
	$contents = file_get_contents( $plugin_file );

	if ( ! is_string( $contents ) ) {
		fwrite( STDERR, "Unable to read the main plugin file.\n" );
		exit( 1 );
	}

	$header_count   = 0;
	$constant_count = 0;
	$contents       = preg_replace( '/^(\s*\*\s*Version:\s*)[^\r\n]+/m', '${1}' . $version, $contents, 1, $header_count );
	$contents       = preg_replace(
		"/(define\\(\\s*'" . preg_quote( $constant, '/' ) . "'\\s*,\\s*')[^']+('\\s*\\);)/",
		'${1}' . $version . '${2}',
		$contents,
		1,
		$constant_count
	);

	if ( 1 !== $header_count || 1 !== $constant_count || false === file_put_contents( $plugin_file, $contents, LOCK_EX ) ) {
		fwrite( STDERR, "Unable to update the plugin version safely.\n" );
		exit( 1 );
	}

	$readme = file_get_contents( $readme_file );
	$count  = 0;

	if ( ! is_string( $readme ) ) {
		fwrite( STDERR, "Unable to read readme.txt.\n" );
		exit( 1 );
	}

	$readme = preg_replace( '/^(Stable tag:\s*)[^\r\n]+/mi', '${1}' . $version, $readme, 1, $count );

	if ( 1 !== $count || false === file_put_contents( $readme_file, $readme, LOCK_EX ) ) {
		fwrite( STDERR, "Unable to update the readme stable tag.\n" );
		exit( 1 );
	}
}

/**
 * Recursively remove a build-owned directory.
 *
 * @param string $directory Directory.
 * @param string $build_root Validated build root.
 * @return void
 */
function geek_cube_studio_build_remove_directory( $directory, $build_root ) {
	$directory  = str_replace( '\\', '/', $directory );
	$build_root = rtrim( str_replace( '\\', '/', $build_root ), '/' );

	if ( ! is_dir( $directory ) || 0 !== strpos( $directory, $build_root . '/' ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $directory );
}

/**
 * Copy one package item into staging.
 *
 * @param string $source Source.
 * @param string $destination Destination.
 * @return void
 */
function geek_cube_studio_build_copy( $source, $destination ) {
	if ( is_dir( $source ) ) {
		if ( ! is_dir( $destination ) ) {
			mkdir( $destination, 0775, true );
		}

		foreach ( new DirectoryIterator( $source ) as $item ) {
			if ( $item->isDot() ) {
				continue;
			}

			geek_cube_studio_build_copy( $item->getPathname(), $destination . '/' . $item->getFilename() );
		}

		return;
	}

	if ( ! is_dir( dirname( $destination ) ) ) {
		mkdir( dirname( $destination ), 0775, true );
	}

	copy( $source, $destination );
}

/**
 * Determine whether production Composer packages must ship.
 *
 * @param string $root_dir Project root.
 * @return bool
 */
function geek_cube_studio_build_has_runtime_composer_dependencies( $root_dir ) {
	$contents = file_get_contents( $root_dir . '/composer.json' );
	$data     = is_string( $contents ) ? json_decode( $contents, true ) : null;
	$requires = is_array( $data ) && isset( $data['require'] ) && is_array( $data['require'] ) ? $data['require'] : array();

	foreach ( array_keys( $requires ) as $package ) {
		if ( 'php' !== $package && 0 !== strpos( $package, 'ext-' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Build the plugin staging tree and ZIP archive.
 *
 * @param string              $root_dir Project root.
 * @param array<string,mixed> $project Project metadata.
 * @param string              $version Release version.
 * @return string ZIP path.
 */
function geek_cube_studio_build_package( $root_dir, array $project, $version ) {
	$build_dir = $root_dir . '/build';
	$staging   = $build_dir . '/' . $project['slug'];

	if ( ! is_dir( $build_dir ) ) {
		mkdir( $build_dir, 0775, true );
	}

	geek_cube_studio_build_remove_directory( $staging, $build_dir );
	mkdir( $staging, 0775, true );

	foreach ( $project['package_items'] as $item ) {
		$source = $root_dir . '/' . $item;

		if ( 'vendor' === $item || ! file_exists( $source ) ) {
			continue;
		}

		geek_cube_studio_build_copy( $source, $staging . '/' . $item );
	}

	if ( geek_cube_studio_build_has_runtime_composer_dependencies( $root_dir ) ) {
		copy( $root_dir . '/composer.json', $staging . '/composer.json' );

		if ( is_file( $root_dir . '/composer.lock' ) ) {
			copy( $root_dir . '/composer.lock', $staging . '/composer.lock' );
		}

		geek_cube_studio_build_run(
			'composer install --working-dir=' . escapeshellarg( $staging ) . ' --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-ansi',
			'Unable to install production Composer dependencies.'
		);
	}

	$zip_path = $build_dir . '/' . $project['slug'] . '-' . $version . '.zip';

	if ( is_file( $zip_path ) ) {
		unlink( $zip_path );
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fwrite( STDERR, "Unable to create {$zip_path}.\n" );
		exit( 1 );
	}

	$zip->addEmptyDir( $project['slug'] );
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $staging, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $files as $file ) {
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $staging ) + 1 ) );
		$target   = $project['slug'] . '/' . $relative;

		$file->isDir() ? $zip->addEmptyDir( $target ) : $zip->addFile( $file->getPathname(), $target );
	}

	$zip->close();
	geek_cube_studio_build_remove_directory( $staging, $build_dir );

	return $zip_path;
}

/**
 * Read metadata from plugin and readme files.
 *
 * @param string $plugin_file Main plugin file.
 * @param string $readme_file WordPress readme.
 * @return array<string,string>
 */
function geek_cube_studio_build_metadata( $plugin_file, $readme_file ) {
	$plugin = file_get_contents( $plugin_file );
	$readme = file_get_contents( $readme_file );
	$data   = array(
		'name'         => '',
		'author'       => '',
		'homepage'     => '',
		'requires'     => '',
		'tested'       => '',
		'requires_php' => '',
	);

	foreach ( array(
		'Plugin Name' => 'name',
		'Author'      => 'author',
		'Plugin URI'  => 'homepage',
	) as $header => $key ) {
		if ( is_string( $plugin ) && preg_match( '/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $plugin, $matches ) ) {
			$data[ $key ] = trim( $matches[1] );
		}
	}

	foreach ( array(
		'Requires at least' => 'requires',
		'Tested up to'      => 'tested',
		'Requires PHP'      => 'requires_php',
	) as $header => $key ) {
		if ( is_string( $readme ) && preg_match( '/^' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $readme, $matches ) ) {
			$data[ $key ] = trim( $matches[1] );
		}
	}

	return $data;
}

/**
 * Read the public package base URL from the private FTP configuration.
 *
 * @return string
 */
function geek_cube_studio_build_public_base_url() {
	$config_path = trim( (string) getenv( 'GEEK_CUBE_FTP_CONFIG' ) );
	$config_path = '' !== $config_path ? $config_path : __DIR__ . '/.ftp-credentials.json';
	$contents    = is_file( $config_path ) ? file_get_contents( $config_path ) : false;
	$config      = is_string( $contents ) ? json_decode( $contents, true ) : null;
	$url         = is_array( $config ) && isset( $config['public_base_url'] ) ? trim( (string) $config['public_base_url'] ) : '';

	if ( 0 !== strpos( strtolower( $url ), 'https://' ) ) {
		fwrite( STDERR, "FTP public_base_url must use HTTPS.\n" );
		exit( 1 );
	}

	return rtrim( $url, '/' );
}

/**
 * Generate the signed manifest and no-cache PHP endpoint.
 *
 * @param string              $root_dir Project root.
 * @param array<string,mixed> $project Project metadata.
 * @param array<string,mixed> $options Build options.
 * @param string              $version Version.
 * @param string              $zip_path Package path.
 * @return array<string,mixed>
 */
function geek_cube_studio_build_manifest( $root_dir, array $project, array $options, $version, $zip_path ) {
	$metadata  = geek_cube_studio_build_metadata( $root_dir . '/' . $project['main_file'], $root_dir . '/readme.txt' );
	$zip_name  = basename( $zip_path );
	$base_url  = geek_cube_studio_build_public_base_url();
	$sha256    = hash_file( 'sha256', $zip_path );
	$manifest  = array(
		'name'              => $metadata['name'],
		'slug'              => $project['slug'],
		'version'           => $version,
		'author'            => $metadata['author'],
		'homepage'          => $metadata['homepage'],
		'requires'          => $metadata['requires'],
		'tested'            => $metadata['tested'],
		'requires_php'      => $metadata['requires_php'],
		'download_url'      => $base_url . '/' . rawurlencode( $zip_name ),
		'last_updated'      => gmdate( 'Y-m-d' ),
		'channel'           => $options['channel'],
		'release_type'      => $options['bump_type'],
		'auto_update'       => (bool) $options['auto_update'],
		'auto_update_major' => (bool) $options['auto_update_major'],
		'rollout_percent'   => (int) $options['rollout_percent'],
		'sha256'            => is_string( $sha256 ) ? strtolower( $sha256 ) : '',
	);
	$manifest  = geek_cube_studio_release_sign_manifest( $manifest );
	$build_dir = $root_dir . '/build';
	$json_name = $project['slug'] . '-update.json';
	$php_name  = $project['slug'] . '-update.php';
	$json      = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	if ( ! is_string( $json ) || false === file_put_contents( $build_dir . '/' . $json_name, $json . PHP_EOL, LOCK_EX ) ) {
		fwrite( STDERR, "Unable to write the signed update manifest.\n" );
		exit( 1 );
	}

	$endpoint = "<?php\n/** Geek Cube Studio update manifest endpoint. */\nheader( 'Content-Type: application/json; charset=utf-8' );\nheader( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );\nheader( 'Pragma: no-cache' );\nheader( 'X-Robots-Tag: noindex, nofollow', true );\n\$manifest = __DIR__ . '/{$json_name}';\nif ( ! is_file( \$manifest ) || ! is_readable( \$manifest ) ) {\n\thttp_response_code( 503 );\n\techo json_encode( array( 'error' => 'manifest_unavailable' ) );\n\texit;\n}\nreadfile( \$manifest );\n";

	if ( false === file_put_contents( $build_dir . '/' . $php_name, $endpoint, LOCK_EX ) ) {
		fwrite( STDERR, "Unable to write the update manifest endpoint.\n" );
		exit( 1 );
	}

	return $manifest;
}

/**
 * Commit release sources and push the current branch.
 *
 * @param string $root_dir Project root.
 * @param string $branch Current branch.
 * @param string $version Release version.
 * @return void
 */
function geek_cube_studio_build_commit_and_push( $root_dir, $branch, $version ) {
	$output = array();
	$status = geek_cube_studio_build_git( $root_dir, 'add -A -- .', $output );

	if ( 0 !== $status ) {
		fwrite( STDERR, "Unable to stage release source files.\n" );
		exit( 1 );
	}

	$status = geek_cube_studio_build_git( $root_dir, 'diff --cached --name-only', $output );
	$files  = array_values( array_filter( array_map( 'trim', $output ) ) );

	if ( 0 !== $status ) {
		fwrite( STDERR, "Unable to inspect staged release files.\n" );
		exit( 1 );
	}

	foreach ( $files as $file ) {
		$normalized          = strtolower( str_replace( '\\', '/', $file ) );
		$is_example          = in_array(
			$normalized,
			array(
				'tools/.ftp-credentials.example.json',
				'tools/.release-credentials.example.json',
			),
			true
		);
		$is_secret_data_file = 1 === preg_match( '/\.(?:json|txt|pem|key|env)$/', $normalized );

		if (
			0 === strpos( $normalized, 'build/' )
			|| preg_match( '/\.(zip|nes|rom|smc|sfc|gba|gbc|n64|z64)$/', $normalized )
			|| ( ! $is_example && $is_secret_data_file && preg_match( '/(?:credentials|private[-_.]?key|release[-_.]?key|signing[-_.]?private|vault[-_.]?note)/', $normalized ) )
		) {
			geek_cube_studio_build_git( $root_dir, 'reset -- ' . escapeshellarg( $file ), $output );
			fwrite( STDERR, "Release refused potentially private or generated staged file: {$file}\n" );
			exit( 1 );
		}
	}

	if ( empty( $files ) ) {
		echo "No source changes to commit.\n";
	} else {
		echo "Release source files:\n - " . implode( "\n - ", $files ) . PHP_EOL;
		geek_cube_studio_build_run(
			'git -C ' . escapeshellarg( $root_dir ) . ' commit -m ' . escapeshellarg( 'Release ' . $version ),
			'Release commit failed.'
		);
	}

	geek_cube_studio_build_run(
		'git -C ' . escapeshellarg( $root_dir ) . ' push origin ' . escapeshellarg( $branch ),
		'Release push failed. FTP deploy was not started.'
	);
}

/**
 * Create and push the annotated release tag after successful deployment.
 *
 * @param string $root_dir Project root.
 * @param string $version Release version.
 * @return void
 */
function geek_cube_studio_build_tag_release( $root_dir, $version ) {
	$tag    = 'v' . $version;
	$output = array();

	if ( 0 === geek_cube_studio_build_git( $root_dir, 'rev-parse -q --verify ' . escapeshellarg( 'refs/tags/' . $tag ), $output ) ) {
		$tag_commit = array();
		$head       = array();

		geek_cube_studio_build_git( $root_dir, 'rev-list -n 1 ' . escapeshellarg( $tag ), $tag_commit );
		geek_cube_studio_build_git( $root_dir, 'rev-parse HEAD', $head );

		if ( trim( implode( '', $tag_commit ) ) !== trim( implode( '', $head ) ) ) {
			fwrite( STDERR, "Release tag {$tag} exists on a different commit.\n" );
			exit( 1 );
		}

		echo "Release tag {$tag} already exists locally and matches HEAD.\n";
	} else {
		geek_cube_studio_build_run(
			'git -C ' . escapeshellarg( $root_dir ) . ' tag -a ' . escapeshellarg( $tag ) . ' -m ' . escapeshellarg( 'Release ' . $version ),
			'Release tag creation failed.'
		);
	}

	geek_cube_studio_build_run(
		'git -C ' . escapeshellarg( $root_dir ) . ' push origin ' . escapeshellarg( $tag ),
		'Release tag push failed.'
	);
}

$options     = geek_cube_studio_build_options( array_slice( $argv, 1 ) );
$plugin_file = $root_dir . '/' . $project['main_file'];
$readme_file = $root_dir . '/readme.txt';

foreach ( array( 'zip', 'sodium', 'curl' ) as $extension ) {
	if ( ! extension_loaded( $extension ) ) {
		fwrite( STDERR, "Required PHP extension is unavailable: {$extension}\n" );
		exit( 1 );
	}
}

geek_cube_studio_build_quality_gates( $root_dir );

if ( $options['validate_only'] ) {
	echo "Validation completed successfully.\n";
	exit( 0 );
}

try {
	geek_cube_studio_release_assert_signing_identity();
	$release_note_path = geek_cube_studio_release_private_key_path();
	geek_cube_studio_vault_note_verify_file( $release_note_path );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, 'Release identity preflight failed: ' . $exception->getMessage() . PHP_EOL );
	exit( 1 );
}

$branch = geek_cube_studio_build_git_preflight( $root_dir );

geek_cube_studio_build_run(
	escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/deploy-ftp.php' ) . ' --test',
	'FTP preflight failed. No version was changed.'
);

$version = geek_cube_studio_build_detect_version( $plugin_file );

if ( '' === $version ) {
	fwrite( STDERR, "Unable to detect the plugin version.\n" );
	exit( 1 );
}

if ( $options['bump'] ) {
	$next_version = geek_cube_studio_build_next_version( $version, $options['bump_type'] );

	if ( '' === $next_version ) {
		fwrite( STDERR, "Plugin version must use semantic X.Y.Z format.\n" );
		exit( 1 );
	}

	geek_cube_studio_build_write_version( $plugin_file, $readme_file, $project['version_constant'], $next_version );
	echo "Version bumped: {$version} -> {$next_version}\n";
	$version = $next_version;
}

$zip_path = geek_cube_studio_build_package( $root_dir, $project, $version );
$manifest = geek_cube_studio_build_manifest( $root_dir, $project, $options, $version, $zip_path );
$pruned   = geek_cube_studio_release_prune_local_builds( $root_dir . '/build', $project['slug'] );

echo 'Build created: ' . $zip_path . PHP_EOL;
echo 'SHA-256: ' . $manifest['sha256'] . PHP_EOL;

if ( ! empty( $pruned ) ) {
	echo "Removed old local builds:\n - " . implode( "\n - ", $pruned ) . PHP_EOL;
}

echo 'Local retention verified: the latest ' . GEEK_CUBE_STUDIO_RELEASE_RETENTION . " builds are available.\n";

geek_cube_studio_build_commit_and_push( $root_dir, $branch, $version );
geek_cube_studio_build_run(
	escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/deploy-ftp.php' ),
	'FTP deployment failed. Fix the cause and rerun with --no-bump.'
);
geek_cube_studio_build_tag_release( $root_dir, $version );

echo "Release {$version} completed successfully.\n";
