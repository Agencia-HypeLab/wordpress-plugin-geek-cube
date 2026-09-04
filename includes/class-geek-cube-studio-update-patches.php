<?php
/**
 * Persistent, retryable update patch runner.
 *
 * @package Geek_Cube_Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one-time data migrations independently from file replacement.
 */
final class Geek_Cube_Studio_Update_Patches {
	/** Patch state option. */
	const STATE_OPTION = 'geek_cube_studio_update_patch_state';

	/** Last plugin version whose patches all completed. */
	const VERSION_OPTION = 'geek_cube_studio_version';

	/** WP-Cron event name. */
	const CRON_HOOK = 'geek_cube_studio_run_update_patches';

	/** Short-lived worker lock. */
	const LOCK_OPTION = 'geek_cube_studio_update_patch_lock';

	/** Maximum patches processed per worker run. */
	const BATCH_SIZE = 3;

	/** Maximum attempts before requiring an administrator intervention. */
	const MAX_ATTEMPTS = 8;

	/** Worker lock lifetime. */
	const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Whether hooks were registered.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register patch hooks once.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render_admin_notice' ) );
	}

	/**
	 * Schedule a worker when the permanent registry has pending entries.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$pending = self::pending_patch_ids();

		if ( empty( $pending ) ) {
			if ( GEEK_CUBE_STUDIO_VERSION !== get_option( self::VERSION_OPTION ) ) {
				update_option( self::VERSION_OPTION, GEEK_CUBE_STUDIO_VERSION, false );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Process a bounded batch of patches, recording every outcome.
	 *
	 * @return void
	 */
	public static function run() {
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$registry  = self::registry();
			$state     = self::state();
			$processed = 0;

			foreach ( $registry as $patch_id => $patch ) {
				$current = isset( $state[ $patch_id ] ) && is_array( $state[ $patch_id ] ) ? $state[ $patch_id ] : self::new_state();

				if ( 'completed' === $current['status'] || self::MAX_ATTEMPTS <= (int) $current['attempts'] ) {
					continue;
				}

				if ( self::BATCH_SIZE <= $processed ) {
					break;
				}

				++$processed;
				$current['status']     = 'running';
				$current['attempts']   = (int) $current['attempts'] + 1;
				$current['updated_at'] = time();
				$current['error']      = '';
				$state[ $patch_id ]    = $current;
				self::save_state( $state );

				$result = self::execute_patch( $patch_id, $patch, $current );

				if ( true === $result['complete'] ) {
					$current['status']       = 'completed';
					$current['completed_at'] = time();
					$current['error']        = '';
				} else {
					$current['status'] = self::MAX_ATTEMPTS <= (int) $current['attempts'] ? 'failed' : 'pending';
					$current['error']  = $result['message'];
				}

				$current['updated_at'] = time();
				$state[ $patch_id ]    = $current;
				self::save_state( $state );
			}

			$pending = self::pending_patch_ids( $registry, $state );

			if ( empty( $pending ) ) {
				update_option( self::VERSION_OPTION, GEEK_CUBE_STUDIO_VERSION, false );
			} elseif ( self::has_retryable_patch( $pending, $state ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + self::retry_delay( $state ), self::CRON_HOOK );
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Return a normalized inventory for diagnostics and future admin screens.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function inventory() {
		$inventory = array();
		$state     = self::state();

		foreach ( self::registry() as $patch_id => $patch ) {
			$current = isset( $state[ $patch_id ] ) && is_array( $state[ $patch_id ] ) ? $state[ $patch_id ] : self::new_state();

			$inventory[ $patch_id ] = array(
				'id'            => $patch_id,
				'introduced_in' => $patch['introduced_in'],
				'description'   => $patch['description'],
				'status'        => $current['status'],
				'attempts'      => (int) $current['attempts'],
				'updated_at'    => (int) $current['updated_at'],
				'completed_at'  => (int) $current['completed_at'],
				'error'         => $current['error'],
			);
		}

		return $inventory;
	}

	/**
	 * Warn administrators only when a patch exhausted all retries.
	 *
	 * @return void
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'manage_options' ) ) {
			return;
		}

		$failed = array();

		foreach ( self::inventory() as $patch ) {
			if ( 'failed' === $patch['status'] ) {
				$failed[] = $patch['id'];
			}
		}

		if ( empty( $failed ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: comma-separated patch identifiers. */
			__( 'Geek Cube Studio não conseguiu concluir estas migrações após várias tentativas: %s. Verifique o log do WordPress antes de tentar novamente.', 'geek-cube-studio' ),
			esc_html( implode( ', ', $failed ) )
		);

		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}

	/**
	 * Permanent patch registry.
	 *
	 * Never delete old entries after release. Callbacks must be idempotent.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function registry() {
		$registry = array();

		/**
		 * Filters the permanent Geek Cube Studio update patch registry.
		 *
		 * Each item requires introduced_in, description and callback keys.
		 *
		 * @param array<string,array<string,mixed>> $registry Patch registry.
		 */
		$registry   = apply_filters( 'geek_cube_studio_update_patch_registry', $registry );
		$normalized = array();

		if ( ! is_array( $registry ) ) {
			return $normalized;
		}

		foreach ( $registry as $patch_id => $patch ) {
			if (
				! is_string( $patch_id )
				|| ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $patch_id )
				|| ! is_array( $patch )
				|| empty( $patch['introduced_in'] )
				|| ! isset( $patch['callback'] )
				|| version_compare( (string) $patch['introduced_in'], GEEK_CUBE_STUDIO_VERSION, '>' )
			) {
				continue;
			}

			$normalized[ $patch_id ] = array(
				'introduced_in' => (string) $patch['introduced_in'],
				'description'   => isset( $patch['description'] ) ? (string) $patch['description'] : '',
				'callback'      => $patch['callback'],
			);
		}

		uasort(
			$normalized,
			static function ( $left, $right ) {
				return version_compare( $left['introduced_in'], $right['introduced_in'] );
			}
		);

		return $normalized;
	}

	/**
	 * Return IDs that have not completed.
	 *
	 * @param array<string,array<string,mixed>>|null $registry Optional registry.
	 * @param array<string,array<string,mixed>>|null $state Optional state.
	 * @return string[]
	 */
	public static function pending_patch_ids( $registry = null, $state = null ) {
		$registry = is_array( $registry ) ? $registry : self::registry();
		$state    = is_array( $state ) ? $state : self::state();
		$pending  = array();

		foreach ( $registry as $patch_id => $patch ) {
			unset( $patch );
			$status = isset( $state[ $patch_id ]['status'] ) ? $state[ $patch_id ]['status'] : 'pending';

			if ( 'completed' !== $status ) {
				$pending[] = $patch_id;
			}
		}

		return $pending;
	}

	/**
	 * Execute one callback and normalize its contract.
	 *
	 * A callback may return true/null, false, WP_Error, or an array containing
	 * a boolean `complete` and optional `message`.
	 *
	 * @param string              $patch_id Patch identifier.
	 * @param array<string,mixed> $patch Patch definition.
	 * @param array<string,mixed> $state Current patch state.
	 * @return array{complete:bool,message:string}
	 */
	private static function execute_patch( $patch_id, array $patch, array $state ) {
		if ( ! is_callable( $patch['callback'] ) ) {
			return array(
				'complete' => false,
				'message'  => 'Patch callback is not callable.',
			);
		}

		try {
			$result = call_user_func(
				$patch['callback'],
				array(
					'id'             => $patch_id,
					'introduced_in'  => $patch['introduced_in'],
					'plugin_version' => GEEK_CUBE_STUDIO_VERSION,
					'attempt'        => (int) $state['attempts'],
				)
			);
		} catch ( Throwable $throwable ) {
			return array(
				'complete' => false,
				'message'  => sanitize_text_field( $throwable->getMessage() ),
			);
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'complete' => false,
				'message'  => sanitize_text_field( $result->get_error_message() ),
			);
		}

		if ( is_array( $result ) && array_key_exists( 'complete', $result ) ) {
			return array(
				'complete' => true === $result['complete'],
				'message'  => isset( $result['message'] ) ? sanitize_text_field( $result['message'] ) : '',
			);
		}

		return array(
			'complete' => null === $result || true === $result,
			'message'  => false === $result ? 'Patch callback returned false.' : '',
		);
	}

	/**
	 * Read and normalize persisted patch state.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function state() {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist patch state without autoloading it on every request.
	 *
	 * @param array<string,array<string,mixed>> $state Patch state.
	 * @return void
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Create the default state for a never-run patch.
	 *
	 * @return array<string,mixed>
	 */
	private static function new_state() {
		return array(
			'status'       => 'pending',
			'attempts'     => 0,
			'updated_at'   => 0,
			'completed_at' => 0,
			'error'        => '',
		);
	}

	/**
	 * Acquire a recoverable option-based worker lock.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		$now = time();

		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$existing = (int) get_option( self::LOCK_OPTION, 0 );

		if ( 0 < $existing && ( $now - $existing ) <= self::LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );

		return add_option( self::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Release the worker lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Determine whether at least one pending patch can still retry.
	 *
	 * @param string[]                          $pending Pending IDs.
	 * @param array<string,array<string,mixed>> $state Patch state.
	 * @return bool
	 */
	private static function has_retryable_patch( array $pending, array $state ) {
		foreach ( $pending as $patch_id ) {
			$attempts = isset( $state[ $patch_id ]['attempts'] ) ? (int) $state[ $patch_id ]['attempts'] : 0;

			if ( self::MAX_ATTEMPTS > $attempts ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculate a conservative exponential retry delay.
	 *
	 * @param array<string,array<string,mixed>> $state Patch state.
	 * @return int
	 */
	private static function retry_delay( array $state ) {
		$highest_attempt = 1;

		foreach ( $state as $patch ) {
			$highest_attempt = max( $highest_attempt, isset( $patch['attempts'] ) ? (int) $patch['attempts'] : 0 );
		}

		return min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** min( 4, $highest_attempt - 1 ) ) );
	}
}
