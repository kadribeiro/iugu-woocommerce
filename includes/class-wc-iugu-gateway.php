<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Iugu Payment Gateway class
 *
 * Extended by individual payment gateways to handle payments.
 *
 * @class   WC_Iugu_Gateway
 * @extends WC_Payment_Gateway
 * @version 1.0.0
 * @author  Iugu
 */
class WC_Iugu_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		global $woocommerce;

		$this->id                 = 'iugu';
		$this->icon               = apply_filters( 'iugu_woocommerce_icon', plugins_url( 'assets/images/iugu.png', plugin_dir_path( __FILE__ ) ) );
		$this->method_title       = __( 'Iugu', 'iugu-woocommerce' );
		$this->method_description = __( 'Accept payments by credit card or banking ticket using the Iugu.', 'iugu-woocommerce' );
		$this->has_fields         = true;
		$this->view_transaction_url = 'https://iugu.com/a/invoices/%s';

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Optins.
		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->account_id      = $this->get_option( 'account_id' );
		$this->api_key         = $this->get_option( 'api_key' );
		$this->methods         = $this->get_option( 'methods', 'all' );
		$this->installments    = $this->get_option( 'installments' );
		$this->send_only_total = $this->get_option( 'send_only_total', 'no' );
		$this->debug           = $this->get_option( 'debug' );

		// Active logs.
		if ( 'yes' == $this->debug ) {
			if ( class_exists( 'WC_Logger' ) ) {
				$this->log = new WC_Logger();
			} else {
				$this->log = $woocommerce->logger();
			}
		}

		$this->api = new WC_Iugu_API( $this );

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'transparent_checkout_billet_thankyou_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ), 9999 );

		// Display admin notices.
		$this->admin_notices();
	}

	/**
	 * Returns a value indicating the the Gateway is available or not. It's called
	 * automatically by WooCommerce before allowing customers to use the gateway
	 * for payment.
	 *
	 * @return bool
	 */
	public function is_available() {
		// Test if is valid for use.
		$api = ( ! empty( $this->account_id ) && ! empty( $this->api_key ) );

		$available = ( 'yes' == $this->settings['enabled'] ) && $api && $this->using_supported_currency();

		return $available;
	}

	/**
	 * Call plugin scripts in front-end.
	 */
	public function frontend_scripts() {
		if ( is_checkout() ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			wp_enqueue_style( 'iugu-woocommerce-checkout-css', plugins_url( 'assets/css/checkout' . $suffix . '.css', plugin_dir_path( __FILE__ ) ) );

			wp_enqueue_script( 'iugu-js', $this->api->get_js_url(), array(), null, true );
			wp_enqueue_script( 'iugu-woocommerce-checkout-js', plugins_url( 'assets/js/checkout' . $suffix . '.js', plugin_dir_path( __FILE__ ) ), array( 'jquery', 'wc-credit-card-form' ), WC_Iugu::VERSION, true );

			wp_localize_script(
				'iugu-woocommerce-checkout-js',
				'iugu_wc_checkout_params',
				array(
					'account_id'                    => $this->account_id,
					'i18n_number_field'             => __( 'Card Number', 'iugu-woocommerce' ),
					'i18n_verification_value_field' => __( 'Security Code', 'iugu-woocommerce' ),
					'i18n_expiration_field'         => __( 'Card Expiry Date', 'iugu-woocommerce' ),
					'i18n_first_name_field'         => __( 'First Name', 'iugu-woocommerce' ),
					'i18n_last_name_field'          => __( 'Last Name', 'iugu-woocommerce' ),
					'i18n_is_invalid'               => __( 'is invalid', 'iugu-woocommerce' )
				)
			);
		}
	}

	/**
	 * Returns a bool that indicates if currency is amongst the supported ones.
	 *
	 * @return bool
	 */
	public function using_supported_currency() {
		return ( 'BRL' == get_woocommerce_currency() );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'iugu-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Iugu', 'iugu-woocommerce' ),
				'default' => 'yes'
			),
			'title' => array(
				'title'       => __( 'Title', 'iugu-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'iugu-woocommerce' ),
				'desc_tip'    => true,
				'default'     => __( 'Iugu', 'iugu-woocommerce' )
			),
			'description' => array(
				'title'       => __( 'Description', 'iugu-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'iugu-woocommerce' ),
				'default'     => __( 'Pay via Iugu', 'iugu-woocommerce' )
			),
			'integration' => array(
				'title'       => __( 'Integration Settings', 'iugu-woocommerce' ),
				'type'        => 'title',
				'description' => ''
			),
			'account_id' => array(
				'title'       => __( 'Account ID', 'iugu-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Please enter your Account ID; this is needed in order to take payment.', 'iugu-woocommerce' ),
				'desc_tip'    => true,
				'default'     => ''
			),
			'api_key' => array(
				'title'       => __( 'API Token', 'iugu-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Please enter your API Token; this is needed in order to take payment.', 'iugu-woocommerce' ),
				'desc_tip'    => true,
				'default'     => ''
			),
			'payment' => array(
				'title'       => __( 'Payment Options', 'iugu-woocommerce' ),
				'type'        => 'title',
				'description' => ''
			),
			'methods' => array(
				'title'       => __( 'Payment Methods', 'woocommerce-moip' ),
				'type'        => 'select',
				'description' => __( 'Select the payment methods', 'iugu-woocommerce' ),
				'default'     => 'all',
				'desc_tip'    => true,
				'class'       => 'wc-enhanced-select',
				'options'     => array(
					'all'         => __( 'Credit Card and Billet', 'iugu-woocommerce' ),
					'credit_card' => __( 'Credit Card only', 'iugu-woocommerce' ),
					'billet'      => __( 'Billet only', 'iugu-woocommerce' ),
				)
			),
			'installments' => array(
				'title'       => __( 'Number of Installments', 'iugu-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The maximum number of installments allowed for credit cards. Put a number bigger than 1 to enable the field', 'iugu-woocommerce' ),
				'desc_tip'    => true,
				'default'     => '0'
			),
			'behavior' => array(
				'title'       => __( 'Integration Behavior', 'iugu-woocommerce' ),
				'type'        => 'title',
				'description' => ''
			),
			'send_only_total' => array(
				'title'   => __( 'Send only the order total', 'iugu-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'If this option is enabled will only send the order total, not the list of items.', 'iugu-woocommerce' ),
				'default' => 'no'
			),
			'testing' => array(
				'title'       => __( 'Gateway Testing', 'iugu-woocommerce' ),
				'type'        => 'title',
				'description' => ''
			),
			'debug' => array(
				'title'       => __( 'Debug Log', 'iugu-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'iugu-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf( __( 'Log Iugu events, such as API requests, inside %s', 'iugu-woocommerce' ), '<code>woocommerce/logs/iugu-' . sanitize_file_name( wp_hash( 'iugu' ) ) . '.txt</code>' )
			)
		);
	}

	/**
	 * Payment fields.
	 */
	public function payment_fields() {
		wp_enqueue_script( 'wc-credit-card-form' );

		if ( $description = $this->get_description() ) {
			echo wpautop( wptexturize( $description ) );
		}

		woocommerce_get_template( 'payment-form.php', array(
			'methods'      => $this->methods,
			'installments' => $this->installments
		), 'woocommerce/iugu/', WC_Iugu::get_templates_path() );
	}

	/**
	 * Displays notifications when the admin has something wrong with the configuration.
	 */
	protected function admin_notices() {
		if ( is_admin() ) {

			// Checks if token is not empty.
			if ( empty( $this->account_id ) ) {
				add_action( 'admin_notices', array( $this, 'account_id_missing_message' ) );
			}

			// Checks if key is not empty.
			if ( empty( $this->api_key ) ) {
				add_action( 'admin_notices', array( $this, 'key_missing_message'  ) );
			}

			// Checks that the currency is supported
			if ( ! $this->using_supported_currency() ) {
				add_action( 'admin_notices', array( $this, 'currency_not_supported_message'  ) );
			}
		}
	}

	/**
	 * Add error message in checkout.
	 *
	 * @param  string $message Error message.
	 *
	 * @return string          Displays the error message.
	 */
	protected function add_error( $message ) {
		global $woocommerce;

		if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '>=' ) ) {
			wc_add_notice( $message, 'error' );
		} else {
			$woocommerce->add_error( $message );
		}
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param  int $order_id Order ID.
	 *
	 * @return array         Redirect.
	 */
	public function process_payment( $order_id ) {
		$order  = new WC_Order( $order_id );
		$charge = $this->api->do_charge( $order, $_POST );

		if ( isset( $charge['errors'] ) && ! empty( $charge['errors'] ) ) {
			$errors = is_array( $charge['errors'] ) ? $charge['errors'] : array( $charge['errors'] );

			foreach ( $charge['errors'] as $error ) {
				if ( is_array( $error ) ) {
					foreach ( $error as $_error ) {
						$this->add_error( '<strong>' . esc_attr( $this->gateway->title ) . '</strong>: ' . $_error );
					}
				} else {
					$this->add_error( '<strong>' . esc_attr( $this->gateway->title ) . '</strong>: ' . $error );
				}
			}

			error_log( print_r( 'deu errado', true ) );

			return array(
				'result'   => 'fail',
				'redirect' => ''
			);
		}

		$payment_method = isset( $_POST['iugu_payment_method'] ) ? sanitize_text_field( $_POST['iugu_payment_method'] ) : '';
		$installments   = isset( $_POST['iugu_card_installments'] ) ? sanitize_text_field( $_POST['iugu_card_installments'] ) : '';

		// Save transaction data.
		$payment_data = array_map(
			'sanitize_text_field',
			array(
				'payment_method' => $payment_method,
				'installments'   => $installments,
				'pdf'            => $charge['pdf']
			)
		);
		update_post_meta( $order->id, '_iugu_wc_transaction_data', $payment_data );
		update_post_meta( $order->id, '_transaction_id', intval( $charge['invoice_id'] ) );

		// Save only in old versions.
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '2.1.12', '<=' ) ) {
			update_post_meta( $order->id, __( 'Iugu Transaction details', 'iugu-woocommerce' ), 'https://iugu.com/a/invoices/' . intval( $charge['invoice_id'] ) );
		}

		// Empty cart.
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '2.1', '>=' ) ) {
			WC()->cart->empty_cart();
		} else {
			$woocommerce->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order )
		);
	}

	/**
	 * Transparent billet checkout custom Thank You message.
	 *
	 * @return void
	 */
	public function transparent_checkout_billet_thankyou_page(){

		session_start();
		$result = $_SESSION['billet_result'];

		if(!empty($result)){
			?>

			<div>
				<a href="<?php echo $result->__get('pdf'); ?>" target="_blank">
					<button type="button"><?php _e("Click here to generate your billet");?></button>
				</a>
			</div>

			<?php
			unset($_SESSION['billet_result']);
		}
	}

	/**
	 * Gets the admin url.
	 *
	 * @return string
	 */
	protected function admin_url() {
		if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '>=' ) ) {
			return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_iugu_gateway' );
		}

		return admin_url( 'admin.php?page=woocommerce_settings&tab=payment_gateways&section=WC_Iugu_Gateway' );
	}

	/**
	 * Adds error message when not configured the token.
	 *
	 * @return string Error Mensage.
	 */
	public function account_id_missing_message() {
		echo '<div class="error"><p><strong>' . __( 'Iugu Disabled', 'iugu-woocommerce' ) . '</strong>: ' . sprintf( __( 'You should inform your Account ID. %s', 'iugu-woocommerce' ), '<a href="' . $this->admin_url() . '">' . __( 'Click here to configure!', 'iugu-woocommerce' ) . '</a>' ) . '</p></div>';
	}

	/**
	 * Adds error message when not configured the key.
	 *
	 * @return string Error Mensage.
	 */
	public function key_missing_message() {
		echo '<div class="error"><p><strong>' . __( 'Iugu Disabled', 'iugu-woocommerce' ) . '</strong>: ' . sprintf( __( 'You should inform your API Token. %s', 'iugu-woocommerce' ), '<a href="' . $this->admin_url() . '">' . __( 'Click here to configure!', 'iugu-woocommerce' ) . '</a>' ) . '</p></div>';
	}
}
