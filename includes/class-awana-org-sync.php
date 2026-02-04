<?php
/**
 * Firebase organization sync on login
 *
 * @package Awana_Digital_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Org sync class
 */
class Awana_Org_Sync {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		$instance = new self();
		add_action( 'mo_firebase_auth_after_login', array( $instance, 'sync_user_organizations' ), 10, 3 );
	}

	/**
	 * Sync user organizations from Firebase to user meta on login.
	 *
	 * @param mixed $user_or_id   WP_User, user ID, or array with ID.
	 * @param mixed $firebase_user Firebase user object/array.
	 * @param mixed $extra        Extra data from the hook.
	 */
	public function sync_user_organizations( $user_or_id = null, $firebase_user = null, $extra = null ) {
		$user_id = $this->resolve_user_id( $user_or_id );
		$uid     = $this->resolve_firebase_uid( $firebase_user, $extra );

		if ( empty( $user_id ) || empty( $uid ) ) {
			Awana_Logger::warning(
				'Org sync skipped - missing user_id or Firebase uid',
				array(
					'user_id' => $user_id,
					'uid'     => $uid,
				)
			);
			return;
		}

		if ( ! defined( 'AWANA_FIREBASE_GET_ORGS_URL' ) || empty( AWANA_FIREBASE_GET_ORGS_URL ) ) {
			Awana_Logger::error(
				'AWANA_FIREBASE_GET_ORGS_URL not configured in wp-config.php',
				array( 'user_id' => $user_id )
			);
			return;
		}

		if ( ! defined( 'AWANA_FIREBASE_API_KEY' ) || empty( AWANA_FIREBASE_API_KEY ) ) {
			Awana_Logger::error(
				'AWANA_FIREBASE_API_KEY not configured in wp-config.php',
				array( 'user_id' => $user_id )
			);
			return;
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
					'uid' => (string) $uid,
				)
			),
		);

		$response = wp_remote_request( AWANA_FIREBASE_GET_ORGS_URL, $args );

		if ( is_wp_error( $response ) ) {
			Awana_Logger::error(
				'Org sync failed - request error',
				array(
					'user_id' => $user_id,
					'uid'     => $uid,
					'error'   => $response->get_error_message(),
				)
			);
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			Awana_Logger::warning(
				'Org sync failed - non-2xx response',
				array(
					'user_id'     => $user_id,
					'uid'         => $uid,
					'status_code' => $status_code,
					'response'    => $body,
				)
			);
			return;
		}

		$decoded = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			Awana_Logger::error(
				'Org sync failed - invalid JSON response',
				array(
					'user_id' => $user_id,
					'uid'     => $uid,
					'error'   => json_last_error_msg(),
				)
			);
			return;
		}

		update_user_meta( $user_id, '_awana_organizations', wp_json_encode( $decoded ) );
		update_user_meta( $user_id, '_awana_orgs_last_sync', current_time( 'mysql' ) );

		Awana_Logger::info(
			'Org sync completed',
			array(
				'user_id'     => $user_id,
				'uid'         => $uid,
				'status_code' => $status_code,
			)
		);
	}

	/**
	 * Resolve user ID from possible hook args.
	 *
	 * @param mixed $user_or_id User or ID.
	 * @return int
	 */
	private function resolve_user_id( $user_or_id ) {
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
	private function resolve_firebase_uid( $firebase_user, $extra ) {
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

