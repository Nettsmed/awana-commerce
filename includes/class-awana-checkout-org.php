<?php
/**
 * Checkout organization selector for logged-in users.
 *
 * @package Awana_Digital_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout organization selector.
 */
class Awana_Checkout_Org {

	const FIELD_KEY          = 'awana_selected_organization';
	const META_ORG_ID        = '_awana_selected_org_id';
	const META_ORG_MEMBER_ID = '_awana_selected_org_member_id';
	const META_ORG_TITLE     = '_awana_selected_org_title';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		$instance = new self();
		add_filter( 'woocommerce_checkout_fields', array( $instance, 'add_checkout_field' ) );
		add_action( 'woocommerce_checkout_process', array( $instance, 'validate_checkout_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( $instance, 'save_checkout_field' ), 10, 2 );
	}

	/**
	 * Add organization dropdown to checkout.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function add_checkout_field( $fields ) {
		// Debug logging.
		error_log( 'Awana Checkout Org: add_checkout_field called' );
		error_log( 'Awana Checkout Org: AWANA_USE_SAMPLE_ORGS = ' . ( defined( 'AWANA_USE_SAMPLE_ORGS' ) ? ( AWANA_USE_SAMPLE_ORGS ? 'true' : 'false' ) : 'not defined' ) );
		error_log( 'Awana Checkout Org: is_user_logged_in = ' . ( is_user_logged_in() ? 'yes' : 'no' ) );

		if ( ! is_user_logged_in() ) {
			return $fields;
		}

		$organizations = $this->get_user_organizations();
		error_log( 'Awana Checkout Org: organizations count = ' . count( $organizations ) );

		if ( empty( $organizations ) ) {
			return $fields;
		}

		$options = $this->build_options( $organizations );
		if ( empty( $options ) ) {
			return $fields;
		}

		$default = '';
		if ( count( $options ) === 1 ) {
			$default = array_key_first( $options );
		} else {
			$options = array( '' => __( 'Velg organisasjon', 'awana-digital-sync' ) ) + $options;
		}

		$fields['order'][ self::FIELD_KEY ] = array(
			'type'     => 'select',
			'label'    => __( 'Organisasjon', 'awana-digital-sync' ),
			'required' => true,
			'class'    => array( 'form-row-wide' ),
			'options'  => $options,
			'default'  => $default,
			'priority' => 120,
		);

		return $fields;
	}

	/**
	 * Validate organization selection.
	 */
	public function validate_checkout_field() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$organizations = $this->get_user_organizations();
		if ( empty( $organizations ) ) {
			return;
		}

		$options = $this->build_options( $organizations );
		$selected = isset( $_POST[ self::FIELD_KEY ] ) ? wc_clean( wp_unslash( $_POST[ self::FIELD_KEY ] ) ) : '';
		if ( empty( $selected ) && count( $options ) === 1 ) {
			$selected = array_key_first( $options );
		}
		if ( empty( $selected ) ) {
			wc_add_notice( __( 'Velg organisasjon for a fortsette.', 'awana-digital-sync' ), 'error' );
			return;
		}

		// Validate that the selected organization belongs to the user.
		if ( ! $this->find_org_by_id( $organizations, $selected ) ) {
			wc_add_notice( __( 'Ugyldig organisasjonsvalg.', 'awana-digital-sync' ), 'error' );
		}
	}

	/**
	 * Save organization selection to order meta.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted checkout data.
	 */
	public function save_checkout_field( $order, $data ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$organizations = $this->get_user_organizations();
		if ( empty( $organizations ) ) {
			return;
		}

		$options = $this->build_options( $organizations );
		$selected = '';
		if ( isset( $data[ self::FIELD_KEY ] ) ) {
			$selected = wc_clean( $data[ self::FIELD_KEY ] );
		} elseif ( isset( $_POST[ self::FIELD_KEY ] ) ) {
			$selected = wc_clean( wp_unslash( $_POST[ self::FIELD_KEY ] ) );
		}

		if ( empty( $selected ) && count( $options ) === 1 ) {
			$selected = array_key_first( $options );
		}

		if ( empty( $selected ) ) {
			return;
		}

		// Validate that the selected organization belongs to the user.
		$selected_org = $this->find_org_by_id( $organizations, $selected );
		if ( ! $selected_org ) {
			return;
		}

		$order->update_meta_data( self::META_ORG_ID, $selected );

		if ( ! empty( $selected_org['memberId'] ) ) {
			$order->update_meta_data( self::META_ORG_MEMBER_ID, $selected_org['memberId'] );
		}
		if ( ! empty( $selected_org['title'] ) ) {
			$order->update_meta_data( self::META_ORG_TITLE, $selected_org['title'] );
		}
	}

	/**
	 * Get organizations for the current user.
	 *
	 * @return array
	 */
	private function get_user_organizations() {
		// Development mode: return sample data for testing.
		if ( defined( 'AWANA_USE_SAMPLE_ORGS' ) && AWANA_USE_SAMPLE_ORGS ) {
			return $this->get_sample_organizations();
		}

		$user_id = get_current_user_id();
		if ( empty( $user_id ) ) {
			return array();
		}

		$stored = get_user_meta( $user_id, '_awana_organizations', true );
		if ( empty( $stored ) ) {
			return array();
		}

		$decoded = $stored;
		if ( is_string( $stored ) ) {
			$decoded = json_decode( $stored, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return array();
			}
		}

		if ( is_array( $decoded ) && isset( $decoded['organizations'] ) && is_array( $decoded['organizations'] ) ) {
			return $decoded['organizations'];
		}

		if ( is_array( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
			return $decoded;
		}

		return array();
	}

	/**
	 * Build select options from organizations.
	 *
	 * @param array $organizations Organizations list.
	 * @return array
	 */
	private function build_options( $organizations ) {
		$options = array();
		foreach ( $organizations as $org ) {
			if ( empty( $org['organizationId'] ) ) {
				continue;
			}
			$label = ! empty( $org['title'] ) ? $org['title'] : $org['organizationId'];
			if ( ! empty( $org['orgNumber'] ) ) {
				$label .= ' (' . $org['orgNumber'] . ')';
			}
			$options[ (string) $org['organizationId'] ] = $label;
		}
		return $options;
	}

	/**
	 * Find organization by ID.
	 *
	 * @param array  $organizations Organizations list.
	 * @param string $org_id Organization ID.
	 * @return array|null
	 */
	private function find_org_by_id( $organizations, $org_id ) {
		foreach ( $organizations as $org ) {
			if ( ! empty( $org['organizationId'] ) && (string) $org['organizationId'] === (string) $org_id ) {
				return $org;
			}
		}
		return null;
	}

	/**
	 * Get sample organizations for development/testing.
	 *
	 * Enable by adding to wp-config.php:
	 * define( 'AWANA_USE_SAMPLE_ORGS', true );
	 *
	 * @return array
	 */
	private function get_sample_organizations() {
		return array(
			array(
				'organizationId'     => 'test-org-001',
				'memberId'           => 'member-001',
				'title'              => 'Test Organisasjon AS',
				'orgNumber'          => '999888777',
				'pogCustomerNumber'  => '10001',
				'billingAddress'     => array(
					'street'     => 'Testveien 1',
					'postalCode' => '0150',
					'city'       => 'Oslo',
				),
				'billingEmail'       => 'faktura@test.no',
				'billingContactName' => 'Test Person',
				'billingPhone'       => '+47 123 45 678',
				'userRole'           => 'admin',
			),
			array(
				'organizationId'     => 'test-org-002',
				'memberId'           => 'member-002',
				'title'              => 'Andre Bedrift AS',
				'orgNumber'          => '888777666',
				'pogCustomerNumber'  => '10002',
				'billingAddress'     => array(
					'street'     => 'Annengate 5',
					'postalCode' => '5003',
					'city'       => 'Bergen',
				),
				'billingEmail'       => 'faktura@andre.no',
				'billingContactName' => 'Ola Nordmann',
				'billingPhone'       => '+47 987 65 432',
				'userRole'           => 'member',
			),
		);
	}
}
