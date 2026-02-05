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
	const FIELD_PAYMENT_TYPE = 'awana_payment_type';
	const META_ORG_ID        = '_awana_selected_org_id';
	const META_ORG_MEMBER_ID = '_awana_selected_org_member_id';
	const META_ORG_TITLE     = '_awana_selected_org_title';
	const META_PAYMENT_TYPE  = '_awana_payment_type';
	const META_ORG_NUMBER    = 'org_number';
	const META_POG_CUSTOMER  = '_pog_customer_id';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		$instance = new self();
		add_action( 'woocommerce_before_checkout_billing_form', array( $instance, 'render_payment_type_selector' ) );
		add_action( 'woocommerce_checkout_process', array( $instance, 'validate_checkout_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( $instance, 'save_checkout_field' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $instance, 'enqueue_checkout_assets' ) );
		add_action( 'add_meta_boxes', array( $instance, 'add_order_meta_box' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $instance, 'display_org_info_in_order' ) );
	}

	/**
	 * Enqueue checkout assets.
	 */
	public function enqueue_checkout_assets() {
		if ( ! is_checkout() || ! is_user_logged_in() ) {
			return;
		}

		$organizations = $this->get_user_organizations();
		if ( empty( $organizations ) ) {
			return;
		}

		$plugin_url = plugin_dir_url( dirname( __FILE__ ) );

		wp_enqueue_style(
			'awana-checkout-org-select',
			$plugin_url . 'assets/css/checkout-org-select.css',
			array(),
			filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/css/checkout-org-select.css' )
		);

		wp_enqueue_script(
			'awana-checkout-org-select',
			$plugin_url . 'assets/js/checkout-org-select.js',
			array( 'jquery' ),
			filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/checkout-org-select.js' ),
			true
		);

		wp_localize_script(
			'awana-checkout-org-select',
			'awanaOrgData',
			array(
				'organizations' => $organizations,
			)
		);
	}

	/**
	 * Render the payment type selector on checkout.
	 */
	public function render_payment_type_selector() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$organizations = $this->get_user_organizations();

		if ( empty( $organizations ) ) {
			return;
		}

		$options = $this->build_options( $organizations );
		if ( empty( $options ) ) {
			return;
		}

		// Add "Velg organisasjon" placeholder if more than one option.
		if ( count( $options ) > 1 ) {
			$options = array( '' => __( 'Velg organisasjon', 'awana-digital-sync' ) ) + $options;
		}

		?>
		<div class="awana-payment-type-wrapper">
			<label class="awana-payment-type-label"><?php esc_html_e( 'Hvem handler?', 'awana-digital-sync' ); ?></label>
			<div class="awana-payment-type-options">
				<label class="awana-payment-type-option selected">
					<input type="radio" name="<?php echo esc_attr( self::FIELD_PAYMENT_TYPE ); ?>" value="private" checked="checked" />
					<span><?php esc_html_e( 'Privat', 'awana-digital-sync' ); ?></span>
				</label>
				<label class="awana-payment-type-option">
					<input type="radio" name="<?php echo esc_attr( self::FIELD_PAYMENT_TYPE ); ?>" value="organization" />
					<span><?php esc_html_e( 'Organisasjon', 'awana-digital-sync' ); ?></span>
				</label>
			</div>

			<div class="awana-org-dropdown-wrapper">
				<label for="<?php echo esc_attr( self::FIELD_KEY ); ?>"><?php esc_html_e( 'Velg organisasjon', 'awana-digital-sync' ); ?></label>
				<select name="<?php echo esc_attr( self::FIELD_KEY ); ?>" id="<?php echo esc_attr( self::FIELD_KEY ); ?>">
					<?php foreach ( $options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<?php
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$payment_type = isset( $_POST[ self::FIELD_PAYMENT_TYPE ] ) ? wc_clean( wp_unslash( $_POST[ self::FIELD_PAYMENT_TYPE ] ) ) : 'private';

		// Only validate org selection if payment type is organization.
		if ( 'organization' !== $payment_type ) {
			return;
		}

		$options  = $this->build_options( $organizations );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$selected = isset( $_POST[ self::FIELD_KEY ] ) ? wc_clean( wp_unslash( $_POST[ self::FIELD_KEY ] ) ) : '';

		// Auto-select if only one option.
		if ( empty( $selected ) && count( $options ) === 1 ) {
			$selected = array_key_first( $options );
		}

		if ( empty( $selected ) ) {
			wc_add_notice( __( 'Velg organisasjon for å fortsette.', 'awana-digital-sync' ), 'error' );
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

		// Get payment type.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$payment_type = isset( $_POST[ self::FIELD_PAYMENT_TYPE ] ) ? wc_clean( wp_unslash( $_POST[ self::FIELD_PAYMENT_TYPE ] ) ) : 'private';

		// Always save payment type.
		$order->update_meta_data( self::META_PAYMENT_TYPE, $payment_type );

		// If private or no organizations, we're done.
		if ( 'private' === $payment_type || empty( $organizations ) ) {
			return;
		}

		$options  = $this->build_options( $organizations );
		$selected = '';
		if ( isset( $data[ self::FIELD_KEY ] ) ) {
			$selected = wc_clean( $data[ self::FIELD_KEY ] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( isset( $_POST[ self::FIELD_KEY ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$selected = wc_clean( wp_unslash( $_POST[ self::FIELD_KEY ] ) );
		}

		// Auto-select if only one option.
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

		// Save organization details.
		$order->update_meta_data( self::META_ORG_ID, $selected );

		if ( ! empty( $selected_org['memberId'] ) ) {
			$order->update_meta_data( self::META_ORG_MEMBER_ID, $selected_org['memberId'] );
		}
		if ( ! empty( $selected_org['title'] ) ) {
			$order->update_meta_data( self::META_ORG_TITLE, $selected_org['title'] );
		}
		if ( ! empty( $selected_org['orgNumber'] ) ) {
			$order->update_meta_data( self::META_ORG_NUMBER, $selected_org['orgNumber'] );
		}
		if ( ! empty( $selected_org['pogCustomerNumber'] ) ) {
			$order->update_meta_data( self::META_POG_CUSTOMER, $selected_org['pogCustomerNumber'] );
		}
	}

	/**
	 * Add order meta box.
	 */
	public function add_order_meta_box() {
		$screen = $this->get_order_screen_id();

		add_meta_box(
			'awana-org-info',
			__( 'Organisasjonsinformasjon', 'awana-digital-sync' ),
			array( $this, 'render_order_meta_box' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Get the correct screen ID for orders (HPOS compatible).
	 *
	 * @return string
	 */
	private function get_order_screen_id() {
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
			if ( $controller && method_exists( $controller, 'custom_orders_table_usage_is_enabled' ) && $controller->custom_orders_table_usage_is_enabled() ) {
				return wc_get_page_screen_id( 'shop-order' );
			}
		}
		return 'shop_order';
	}

	/**
	 * Render order meta box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post object or WC_Order.
	 */
	public function render_order_meta_box( $post_or_order ) {
		$order = $this->get_order_from_param( $post_or_order );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Ordre ikke funnet.', 'awana-digital-sync' ) . '</p>';
			return;
		}

		$this->output_org_info( $order );
	}

	/**
	 * Display organization info in order (HPOS fallback).
	 *
	 * @param WC_Order $order Order object.
	 */
	public function display_org_info_in_order( $order ) {
		// Only show if meta box isn't rendering (fallback for some themes).
		if ( did_action( 'add_meta_boxes' ) && doing_action( 'woocommerce_admin_order_data_after_billing_address' ) ) {
			// Meta box should handle display, but output inline if needed.
			// Check if we have org data.
			$payment_type = $order->get_meta( self::META_PAYMENT_TYPE );
			$org_id       = $order->get_meta( self::META_ORG_ID );

			// Skip if no org data.
			if ( empty( $payment_type ) && empty( $org_id ) ) {
				return;
			}

			// Output org info inline.
			$this->output_org_info( $order );
		}
	}

	/**
	 * Output organization info HTML.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function output_org_info( $order ) {
		$payment_type = $order->get_meta( self::META_PAYMENT_TYPE );
		$org_id       = $order->get_meta( self::META_ORG_ID );

		// Backward compatibility: detect org orders without payment type.
		if ( empty( $payment_type ) && ! empty( $org_id ) ) {
			$payment_type = 'organization';
		}

		// Default to private if no data.
		if ( empty( $payment_type ) ) {
			$payment_type = 'private';
		}

		echo '<div class="awana-org-meta-box">';

		// Payment type badge.
		$badge_class = 'organization' === $payment_type ? 'organization' : 'private';
		$badge_text  = 'organization' === $payment_type ? __( 'Organisasjon', 'awana-digital-sync' ) : __( 'Privat', 'awana-digital-sync' );

		echo '<p>';
		echo '<strong>' . esc_html__( 'Betalingstype:', 'awana-digital-sync' ) . '</strong> ';
		echo '<span class="awana-payment-type-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_text ) . '</span>';
		echo '</p>';

		// Show organization details if organization order.
		if ( 'organization' === $payment_type && ! empty( $org_id ) ) {
			echo '<hr />';

			$org_title      = $order->get_meta( self::META_ORG_TITLE );
			$org_number     = $order->get_meta( self::META_ORG_NUMBER );
			$pog_customer   = $order->get_meta( self::META_POG_CUSTOMER );
			$org_member_id  = $order->get_meta( self::META_ORG_MEMBER_ID );

			if ( ! empty( $org_title ) ) {
				echo '<p><strong>' . esc_html__( 'Organisasjon:', 'awana-digital-sync' ) . '</strong> ' . esc_html( $org_title ) . '</p>';
			}

			if ( ! empty( $org_number ) ) {
				echo '<p><strong>' . esc_html__( 'Org.nummer:', 'awana-digital-sync' ) . '</strong> ' . esc_html( $org_number ) . '</p>';
			}

			if ( ! empty( $pog_customer ) ) {
				echo '<p><strong>' . esc_html__( 'POG-kunde:', 'awana-digital-sync' ) . '</strong> ' . esc_html( $pog_customer ) . '</p>';
			}

			if ( ! empty( $org_id ) ) {
				echo '<p><strong>' . esc_html__( 'Org.ID:', 'awana-digital-sync' ) . '</strong> <code>' . esc_html( $org_id ) . '</code></p>';
			}

			if ( ! empty( $org_member_id ) ) {
				echo '<p><strong>' . esc_html__( 'Medlem-ID:', 'awana-digital-sync' ) . '</strong> <code>' . esc_html( $org_member_id ) . '</code></p>';
			}
		}

		echo '</div>';
	}

	/**
	 * Get WC_Order from post or order parameter.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post object or WC_Order.
	 * @return WC_Order|null
	 */
	private function get_order_from_param( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}

		// HPOS might pass order ID directly.
		if ( is_numeric( $post_or_order ) ) {
			return wc_get_order( $post_or_order );
		}

		// Try to get from global.
		global $theorder;
		if ( $theorder instanceof WC_Order ) {
			return $theorder;
		}

		return null;
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
