<?php
/**
 * Firebase organization sync with TTL-based refresh
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Org sync class
 */
class Awana_Org_Sync {

	/**
	 * TTL for organization sync in seconds (4 hours)
	 */
	const TTL_SECONDS = 14400;

	/**
	 * Initialize hooks
	 */
	public static function init() {
		// Login sync.
		add_action( 'mo_firebase_auth_after_login', array( __CLASS__, 'on_firebase_login' ), 10, 3 );

		// TTL-based sync on cart and checkout pages.
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'maybe_sync_on_page' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'maybe_sync_on_page' ) );
	}

	/**
	 * Check if a sync should occur based on TTL.
	 *
	 * @param int      $user_id     WordPress user ID.
	 * @param int|null $ttl_seconds TTL in seconds, defaults to self::TTL_SECONDS.
	 * @return bool True if sync should occur.
	 */
	public static function should_sync( $user_id, $ttl_seconds = null ) {
		if ( null === $ttl_seconds ) {
			$ttl_seconds = self::TTL_SECONDS;
		}

		$last_sync = get_user_meta( $user_id, '_awana_orgs_last_sync', true );

		if ( empty( $last_sync ) ) {
			return true;
		}

		return ( time() - intval( $last_sync ) ) > $ttl_seconds;
	}

	/**
	 * Maybe sync organizations on cart/checkout pages if TTL expired.
	 */
	public static function maybe_sync_on_page() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! self::should_sync( $user_id ) ) {
			return;
		}

		$firebase_uid = get_user_meta( $user_id, 'mo_firebase_user_uid', true );

		if ( empty( $firebase_uid ) ) {
			return;
		}

		self::sync_organizations( $user_id, $firebase_uid );
	}

	/**
	 * Handle Firebase login hook.
	 *
	 * @param mixed $user_or_id   WP_User, user ID, or array with ID.
	 * @param mixed $firebase_user Firebase user object/array.
	 * @param mixed $extra        Extra data from the hook.
	 */
	public static function on_firebase_login( $user_or_id = null, $firebase_user = null, $extra = null ) {
		$user_id = self::resolve_user_id( $user_or_id );

		// Try to get Firebase UID from the hook args first, fallback to user meta.
		$firebase_uid = self::resolve_firebase_uid( $firebase_user, $extra );

		if ( empty( $firebase_uid ) && $user_id ) {
			$firebase_uid = get_user_meta( $user_id, 'mo_firebase_user_uid', true );
		}

		if ( empty( $user_id ) || empty( $firebase_uid ) ) {
			Awana_Logger::warning(
				'Org sync skipped - missing user_id or Firebase uid',
				array(
					'user_id' => $user_id,
					'uid'     => $firebase_uid,
				)
			);
			return;
		}

		self::sync_organizations( $user_id, $firebase_uid );
	}

	/**
	 * Sync organizations from Firebase API.
	 *
	 * @param int    $user_id      WordPress user ID.
	 * @param string $firebase_uid Firebase UID.
	 * @return bool True on success, false on failure.
	 */
	public static function sync_organizations( $user_id, $firebase_uid ) {
		if ( empty( $user_id ) || empty( $firebase_uid ) ) {
			return false;
		}

		if ( ! defined( 'AWANA_FIREBASE_GET_ORGS_URL' ) || empty( AWANA_FIREBASE_GET_ORGS_URL ) ) {
			Awana_Logger::error(
				'AWANA_FIREBASE_GET_ORGS_URL not configured in wp-config.php',
				array( 'user_id' => $user_id )
			);
			return false;
		}

		if ( ! defined( 'AWANA_FIREBASE_API_KEY' ) || empty( AWANA_FIREBASE_API_KEY ) ) {
			Awana_Logger::error(
				'AWANA_FIREBASE_API_KEY not configured in wp-config.php',
				array( 'user_id' => $user_id )
			);
			return false;
		}

		$args = array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-API-Key'    => AWANA_FIREBASE_API_KEY,
			),
			'body'    => wp_json_encode(
				array(
					'uid' => (string) $firebase_uid,
				)
			),
		);

		$response = wp_remote_request( AWANA_FIREBASE_GET_ORGS_URL, $args );

		if ( is_wp_error( $response ) ) {
			Awana_Logger::error(
				'Org sync failed - request error',
				array(
					'user_id' => $user_id,
					'uid'     => $firebase_uid,
					'error'   => $response->get_error_message(),
				)
			);
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			Awana_Logger::warning(
				'Org sync failed - non-2xx response',
				array(
					'user_id'     => $user_id,
					'uid'         => $firebase_uid,
					'status_code' => $status_code,
					'response'    => $body,
				)
			);
			return false;
		}

		$decoded = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			Awana_Logger::error(
				'Org sync failed - invalid JSON response',
				array(
					'user_id' => $user_id,
					'uid'     => $firebase_uid,
					'error'   => json_last_error_msg(),
				)
			);
			return false;
		}

		update_user_meta( $user_id, '_awana_organizations', wp_json_encode( $decoded ) );
		update_user_meta( $user_id, '_awana_orgs_last_sync', time() );

		Awana_Logger::info(
			'Org sync completed',
			array(
				'user_id'     => $user_id,
				'uid'         => $firebase_uid,
				'status_code' => $status_code,
			)
		);

		return true;
	}

	/**
	 * Get user organizations from meta.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|null Decoded organizations array or null if not available.
	 */
	public static function get_user_organizations( $user_id ) {
		$raw = get_user_meta( $user_id, '_awana_organizations', true );

		if ( empty( $raw ) ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Resolve user ID from possible hook args.
	 *
	 * @param mixed $user_or_id User or ID.
	 * @return int
	 */
	private static function resolve_user_id( $user_or_id ) {
		if ( $user_or_id instanceof WP_User ) {
			return (int) $user_or_id->ID;
		}

		if ( is_numeric( $user_or_id ) ) {
			return (int) $user_or_id;
		}

		if ( is_array( $user_or_id ) ) {
			if ( ! empty( $user_or_id['ID'] ) ) {
				return (int) $user_or_id['ID'];
			}
			if ( ! empty( $user_or_id['user_id'] ) ) {
				return (int) $user_or_id['user_id'];
			}
		}

		return 0;
	}

	/**
	 * Resolve Firebase UID from hook args.
	 *
	 * @param mixed $firebase_user Firebase user object/array.
	 * @param mixed $extra Extra data.
	 * @return string
	 */
	private static function resolve_firebase_uid( $firebase_user, $extra ) {
		$uid = '';

		if ( is_object( $firebase_user ) && isset( $firebase_user->uid ) ) {
			$uid = (string) $firebase_user->uid;
		} elseif ( is_array( $firebase_user ) ) {
			if ( isset( $firebase_user['uid'] ) ) {
				$uid = (string) $firebase_user['uid'];
			} elseif ( isset( $firebase_user['localId'] ) ) {
				$uid = (string) $firebase_user['localId'];
			}
		}

		if ( empty( $uid ) && is_array( $extra ) && isset( $extra['uid'] ) ) {
			$uid = (string) $extra['uid'];
		}

		return $uid;
	}
}
