<?php
/** PHPUnit bootstrap with the small WordPress surface used by infrastructure. */

define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'GEEK_CUBE_STUDIO_VERSION', '0.1.0' );
define( 'GEEK_CUBE_STUDIO_PLUGIN_FILE', dirname( __DIR__ ) . '/geek-cube-studio.php' );
define( 'GEEK_CUBE_STUDIO_PLUGIN_DIR', __DIR__ . '/fixtures/plugin/' );
define( 'GEEK_CUBE_STUDIO_UPDATE_MANIFEST_URL', 'https://updates.example.test/geek-cube-studio-update.php' );

$GLOBALS['wp_version']         = '6.7.0';
$GLOBALS['test_filters']       = array();
$GLOBALS['test_options']       = array();
$GLOBALS['test_transients']    = array();
$GLOBALS['test_cron_events']   = array();
$GLOBALS['test_remote_result'] = null;

class WP_Error {
	private $message;

	public function __construct( $code = '', $message = '' ) {
		unset( $code );
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $priority, $accepted_args );
	$GLOBALS['test_filters'][ $hook ][] = $callback;
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function plugin_basename( $file ) {
	return 'geek-cube-studio/' . basename( $file );
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_kses_post( $value ) {
	return (string) $value;
}

function esc_url_raw( $value ) {
	return (string) $value;
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $value, $domain = '' ) {
	unset( $domain );
	return $value;
}

function __( $value, $domain = '' ) {
	unset( $domain );
	return $value;
}

function home_url( $path = '' ) {
	return 'https://site.example.test' . $path;
}

function get_site_transient( $key ) {
	return $GLOBALS['test_transients'][ $key ] ?? false;
}

function set_site_transient( $key, $value, $expiration = 0 ) {
	unset( $expiration );
	$GLOBALS['test_transients'][ $key ] = $value;
	return true;
}

function delete_site_transient( $key ) {
	unset( $GLOBALS['test_transients'][ $key ] );
	return true;
}

function wp_remote_get( $url, $args = array() ) {
	unset( $url, $args );
	return $GLOBALS['test_remote_result'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

function get_option( $key, $default = false ) {
	return $GLOBALS['test_options'][ $key ] ?? $default;
}

function add_option( $key, $value, $deprecated = '', $autoload = null ) {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $key, $GLOBALS['test_options'] ) ) {
		return false;
	}
	$GLOBALS['test_options'][ $key ] = $value;
	return true;
}

function update_option( $key, $value, $autoload = null ) {
	unset( $autoload );
	$GLOBALS['test_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['test_options'][ $key ] );
	return true;
}

function wp_next_scheduled( $hook ) {
	return $GLOBALS['test_cron_events'][ $hook ] ?? false;
}

function wp_schedule_single_event( $timestamp, $hook ) {
	$GLOBALS['test_cron_events'][ $hook ] = $timestamp;
	return true;
}

function current_user_can( $capability ) {
	unset( $capability );
	return true;
}

function is_multisite() {
	return false;
}

require_once dirname( __DIR__ ) . '/includes/class-geek-cube-studio-updater.php';
require_once dirname( __DIR__ ) . '/includes/class-geek-cube-studio-update-patches.php';
