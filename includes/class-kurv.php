<?php

declare(strict_types=1);

/**
 * WC_Kurv Payment Gateway
 *
 * @package Kurv
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/class-kurv-api.php';

/**
 * Kurv payment gateway class.
 *
 * Redirects customers to a hosted Kurv payment page. On return, the
 * payment status is read from the response query var and the order is
 * updated accordingly.
 *
 * @extends WC_Payment_Gateway
 * @since 1.0.0
 */
class WC_Kurv extends WC_Payment_Gateway {

	/**
	 * WooCommerce logger instance (lazy-initialised).
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private ?\WC_Logger_Interface $logger = null;

	/**
	 * Whether logging is enabled.
	 */
	private bool $enable_logging;

	/**
	 * Whether sandbox/test mode is active.
	 */
	private bool $is_test_mode;

	/**
	 * Active API key (live or test, depending on mode).
	 */
	private string $access_key;

	/**
	 * Payment type sent to the API.
	 */
	private string $payment_type;

	/**
	 * Constructor — wires up settings, hooks, and API.
	 */
	public function __construct() {
		$this->id                 = 'kurv';
		$this->method_title       = __( 'Kurv Payments', 'kurv-payments-for-woocommerce' );
		$this->method_description = __( 'Kurv redirects customers to a secure hosted payment page to complete their purchase.', 'kurv-payments-for-woocommerce' );
		$this->icon               = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/kurv-logo.svg';
		$this->has_fields         = false;
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->payment_type   = $this->get_option( 'payment_type', 'DB' );
		$this->enable_logging = 'yes' === $this->get_option( 'enable_logging' );
		$this->is_test_mode   = 'yes' === $this->get_option( 'test_mode' );
		$this->access_key     = $this->is_test_mode
			? $this->get_option( 'test_access_key', '' )
			: $this->get_option( 'live_access_key', '' );

		$this->init_api();

		add_filter( 'woocommerce_gateway_icon', [ $this, 'filter_icon_html' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'validate_admin_options' ] );
		// Gateway-specific thank-you hook — only fires for Kurv orders, not every order.
		add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'response_page' ] );
		add_action( 'woocommerce_order_status_changed', [ $this, 'process_full_refund_on_status_change' ], 10, 3 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'add_full_refund_notes' ], 10, 3 );
		add_filter( 'woocommerce_order_actions', [ $this, 'add_capture_order_action' ] );
		add_action( 'woocommerce_order_action_kurv_capture_payment', [ $this, 'process_capture_order_action' ] );
	}

	/**
	 * Define the admin settings fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = [
			'webhook_url'      => [
				'title'       => __( 'Payment Response URL', 'kurv-payments-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					'<code style="display:block;padding:8px;background:#f6f7f7;border:1px solid #ddd;border-radius:3px;word-break:break-all;user-select:all;">%s</code><p class="description">%s</p>',
					esc_url( wc_get_checkout_url() ),
					esc_html__( 'This URL is automatically sent to Kurv with each payment request as the response_url. No manual configuration required.', 'kurv-payments-for-woocommerce' )
				),
			],
			'enabled'          => [
				'title'   => __( 'Enable/Disable', 'kurv-payments-for-woocommerce' ),
				'label'   => __( 'Enable Kurv Payments', 'kurv-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'test_mode'        => [
				'title'       => __( 'Test Mode', 'kurv-payments-for-woocommerce' ),
				'label'       => __( 'Enable test / sandbox mode', 'kurv-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'When enabled, the Test API Key is used and no real payments are processed.', 'kurv-payments-for-woocommerce' ),
			],
			'title'            => [
				'title'       => __( 'Title', 'kurv-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to the customer at checkout.', 'kurv-payments-for-woocommerce' ),
				'default'     => __( 'Kurv', 'kurv-payments-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'description'      => [
				'title'       => __( 'Description', 'kurv-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Description shown below the payment method title at checkout.', 'kurv-payments-for-woocommerce' ),
				'default'     => __( 'Pay securely via Kurv.', 'kurv-payments-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'live_access_key'  => [
				'title'       => __( 'Live API Key', 'kurv-payments-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Starts with <code>kp_live_</code>. Found in your Kurv developer portal.', 'kurv-payments-for-woocommerce' ),
				'default'     => '',
			],
			'test_access_key'  => [
				'title'       => __( 'Test API Key', 'kurv-payments-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Starts with <code>kp_test_</code>. Found in your Kurv developer portal.', 'kurv-payments-for-woocommerce' ),
				'default'     => '',
			],
			'enable_wallet_methods' => [
				'title'       => __( 'Apple Pay / Google Pay', 'kurv-payments-for-woocommerce' ),
				'label'       => __( 'Enable Apple Pay and Google Pay on the Kurv payment page', 'kurv-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'When enabled, customers will see Apple Pay and Google Pay options on the hosted Kurv payment page.', 'kurv-payments-for-woocommerce' ),
			],
			'kurv_send_receipt'    => [
				'title'       => __( 'Kurv Receipt Email', 'kurv-payments-for-woocommerce' ),
				'label'       => __( 'Let Kurv send its own payment receipt to the customer', 'kurv-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'By default, only WooCommerce sends a receipt. Enable this to also send a Kurv receipt. Note: the customer will receive two emails.', 'kurv-payments-for-woocommerce' ),
			],
			'payment_expiry_hours' => [
				'title'       => __( 'Payment Link Expiry', 'kurv-payments-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Hours before a Kurv payment link expires. Leave blank for no expiry.', 'kurv-payments-for-woocommerce' ),
				'default'     => '24',
				'desc_tip'    => true,
				'custom_attributes' => [
					'min'  => '1',
					'max'  => '720',
					'step' => '1',
				],
			],
			'payment_type'         => [
				'title'       => __( 'Payment Type', 'kurv-payments-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'DB charges the customer immediately. PA pre-authorises only — you capture manually from the order screen.', 'kurv-payments-for-woocommerce' ),
				'default'     => 'DB',
				'options'     => [
					'DB' => __( 'DB — Direct Billing (charge immediately)', 'kurv-payments-for-woocommerce' ),
					'PA' => __( 'PA — Pre-authorisation (capture manually)', 'kurv-payments-for-woocommerce' ),
				],
				'desc_tip'    => true,
			],
			'enable_logging'   => [
				'title'   => __( 'Enable Logging', 'kurv-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log API requests and responses for debugging.', 'kurv-payments-for-woocommerce' ),
				'default' => 'no',
			],
		];
	}

	/**
	 * Validate that the relevant API key is present when settings are saved.
	 */
	public function validate_admin_options(): void {
		$post_data   = $this->get_post_data();
		$is_test     = 'yes' === $this->get_field_value( 'test_mode', $this->form_fields, $post_data );
		$key_field   = $is_test ? 'test_access_key' : 'live_access_key';
		$active_key  = $this->get_field_value( $key_field, $this->form_fields, $post_data );

		if ( empty( $active_key ) ) {
			WC_Admin_Settings::add_error(
				$is_test
					? __( 'Please enter a Test API Key (starts with kp_test_).', 'kurv-payments-for-woocommerce' )
					: __( 'Please enter a Live API Key (starts with kp_live_).', 'kurv-payments-for-woocommerce' )
			);
			return;
		}

		// Warn if the key prefix does not match the selected mode.
		$is_test_key = str_starts_with( $active_key, 'kp_test_' );
		$is_live_key = str_starts_with( $active_key, 'kp_live_' );

		if ( $is_test && $is_live_key ) {
			WC_Admin_Settings::add_error(
				__( 'Warning: Test Mode is enabled but a Live API Key was entered. Please enter your Test API Key (starts with kp_test_).', 'kurv-payments-for-woocommerce' )
			);
		} elseif ( ! $is_test && $is_test_key ) {
			WC_Admin_Settings::add_error(
				__( 'Warning: Test Mode is disabled but a Test API Key was entered. Please enter your Live API Key (starts with kp_live_), or enable Test Mode.', 'kurv-payments-for-woocommerce' )
			);
		}
	}

	/**
	 * Disable the gateway if the active API key is missing.
	 */
	public function is_available(): bool {
		if ( empty( $this->access_key ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Enqueue admin JS on the Kurv settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ( $_GET['section'] ?? '' ) !== $this->id ) {
			return;
		}

		wp_enqueue_script(
			'kurv-admin',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/kurv-admin.js',
			[ 'jquery' ],
			KURV_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Constrain the gateway icon to standard checkout dimensions.
	 *
	 * @param string $icon_html Existing icon HTML.
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	public function filter_icon_html( string $icon_html, string $gateway_id ): string {
		if ( $gateway_id !== $this->id ) {
			return $icon_html;
		}
		return '<img src="' . esc_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="height:36px;width:auto;vertical-align:middle;" />';
	}

	/**
	 * Enqueue checkout overlay scripts and styles on the checkout page only.
	 */
	public function enqueue_checkout_assets(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$base = plugin_dir_url( dirname( __FILE__ ) );

		wp_enqueue_style(
			'kurv-checkout',
			$base . 'assets/css/kurv-checkout.css',
			[],
			KURV_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'kurv-checkout',
			$base . 'assets/js/kurv-checkout.js',
			[ 'jquery' ],
			KURV_PLUGIN_VERSION,
			true
		);

		wp_localize_script( 'kurv-checkout', 'kurv_checkout_params', [
			'logoUrl'       => $base . 'assets/img/kurv-logo.svg',
			'preparingText' => __( 'Preparing your secure payment…', 'kurv-payments-for-woocommerce' ),
			'errorText'     => __( 'Something went wrong. Please try again.', 'kurv-payments-for-woocommerce' ),
		] );
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * @param string $message Log message.
	 * @param string $level   PSR-3 log level (debug, info, notice, warning, error).
	 */
	public function log( string $message, string $level = 'info' ): void {
		if ( $this->enable_logging ) {
			$this->logger ??= wc_get_logger();
			$this->logger->log( $level, $message, [ 'source' => 'kurv' ] );
		}
	}

	/**
	 * Push the active access key and mode to the API class.
	 */
	protected function init_api(): void {
		Kurv_API::$access_key   = $this->access_key;
		Kurv_API::$is_test_mode = $this->is_test_mode;
	}

	/**
	 * Generate a one-time token that ties a payment response to this order.
	 *
	 * The token is an HMAC-SHA256 hash (via wp_hash) of the order ID, currency,
	 * transaction ID, and a per-order secret. It is appended to the redirect and
	 * response URLs so we can verify the callback is genuine.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $currency ISO currency code.
	 * @return string
	 */
	protected function generate_token( int $order_id, string $currency ): string {
		$order          = wc_get_order( $order_id );
		$transaction_id = $order->get_meta( '_kurv_transaction_id', true );
		$secret_key     = $order->get_meta( '_kurv_secret_key', true );

		return wp_hash( (string) $order_id . $currency . $transaction_id . $secret_key );
	}

	/**
	 * Build the Kurv-hosted payment URL for a given order.
	 *
	 * @param int    $order_id       Order ID.
	 * @param string $transaction_id Reference number sent to the Kurv API.
	 * @return string Payment URL to redirect the customer to.
	 * @throws \Exception If the API call fails or returns an error.
	 */
	protected function get_payment_url( int $order_id, string $transaction_id ): string {
		$order      = wc_get_order( $order_id );
		$currency   = $order->get_currency();
		$amount     = (float) $order->get_total();
		$token      = $this->generate_token( $order_id, $currency );
		$return_url = $this->get_return_url( $order );

		$country_code_phone    = $this->get_country_code( $order->get_billing_country() );
		$customer_phone_number = $order->get_billing_phone();

		if ( $country_code_phone && str_starts_with( $customer_phone_number, $country_code_phone ) === false && strlen( $customer_phone_number ) <= 10 ) {
			$customer_phone_number = $country_code_phone . $customer_phone_number;
		}

		$body = [
			'reference_number'    => $transaction_id,
			'payment_type'        => $this->payment_type,
			'request_methods'     => [ 'WEB' ],
			'email'               => $order->get_billing_email(),
			'mobile_number'       => $customer_phone_number,
			'customer_first_name' => $order->get_billing_first_name(),
			'customer_last_name'  => $order->get_billing_last_name(),
			'currency'            => $currency,
			'amount'              => $amount,
			'shipping_charges'    => (float) $order->get_shipping_total(),
			'shipping_tax'        => (float) $order->get_shipping_tax(),
			'cart_items'          => $this->get_cart_items( $order_id ),
			'fixed_amount'        => true,
			'send_confirmation'   => 'yes' === $this->get_option( 'kurv_send_receipt', 'no' ) ? 'true' : 'false',
			'cancel_url'          => wc_get_checkout_url(),
			'redirect_url'        => $return_url . '&kurv_token=' . $token,
			'response_url'        => $return_url . '&kurv_token=' . $token,
		];

		// Add Apple Pay / Google Pay if enabled.
		if ( 'yes' === $this->get_option( 'enable_wallet_methods', 'no' ) ) {
			$body['payment_methods'] = [ 'APPLE_PAY', 'GOOGLE_PAY' ];
		}

		// TODO: re-add expiry_date once Kurv confirms exact accepted format.
		// Every format tried (YYYY-MM-DD HH:MM, ISO8601 with Z, ISO8601 with +00:00) is rejected
		// by the API with "expiry_date is not in ISO 8601 format." — but docs say YYYY-MM-DD HH:MM.
		// All successful requests in logs have no expiry_date field at all.
		// The expiry hours setting is preserved in plugin settings for future use.
		// $expiry_hours = (int) $this->get_option( 'payment_expiry_hours', 0 );
		// if ( $expiry_hours > 0 ) {
		// 	$expiry = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		// 	$expiry->modify( '+' . $expiry_hours . ' hours' );
		// 	$body['expiry_date'] = $expiry->format( 'Y-m-d H:i' );
		// }

		// Sync the customer to Kurv's CRM.
		// Note: customer_id is intentionally not sent in the payment request body —
		// the Kurv sandbox rejects it as invalid. Re-evaluate for production once confirmed with Kurv.
		self::update_customer_on_kurv( $order );

		$log_body                 = $body;
		$log_body['response_url'] = $return_url . '&kurv_token=*****';
		$this->log( 'get_payment_url - body: ' . wp_json_encode( $log_body ) );

		$results = Kurv_API::generate_pos_link( $body );
		$this->log( 'get_payment_url - results: ' . wp_json_encode( $results ) );

		if ( is_wp_error( $results ) ) {
			throw new \Exception( $results->get_error_message(), 1 );
		}

		if ( 200 === $results['response']['code'] && 'success' === $results['body']['result'] ) {
			// Store supplementary URLs on the order for admin reference.
			$order = wc_get_order( $order_id );
			$order->update_meta_data( '_kurv_short_url', $results['body']['short_url'] ?? '' );
			$order->update_meta_data( '_kurv_qrcode_url', $results['body']['qrcode_link'] ?? '' );
			$order->save();

			return $results['body']['long_url'];
		}

		if ( 422 === $results['response']['code'] && 'currency' === ( $results['body']['error_field'] ?? '' ) ) {
			throw new \Exception( __( 'We are sorry, this currency is not supported. Please contact us.', 'kurv-payments-for-woocommerce' ), 1 );
		}

		if ( ! empty( $results['body']['error_message'] ) ) {
			throw new \Exception( esc_html( $results['body']['error_message'] ), 1 );
		}

		if ( ! empty( $results['body']['message'] ) ) {
			throw new \Exception( esc_html( $results['body']['message'] ), 1 );
		}

		throw new \Exception( __( 'Payment could not be initiated. Please try again.', 'kurv-payments-for-woocommerce' ), 1 );
	}

	/**
	 * Build the cart items array for the Kurv API payload.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_cart_items( int $order_id ): array {
		$cart_items = [];
		$order      = wc_get_order( $order_id );

		foreach ( $order->get_items() as $item ) {
			$product    = $item->get_product();
			$price      = $product->get_price();
			$tax_rates  = WC_Tax::get_rates( $product->get_tax_class() );
			$taxes      = WC_Tax::calc_tax( (float) $price, $tax_rates, false );
			$total_tax  = array_sum( $taxes );
			$is_taxable = 'taxable' === $item->get_tax_status();
			$sku        = $product->get_sku() ?: '-';
			$item_total = isset( $item['recurring_line_total'] ) ? $item['recurring_line_total'] : $order->get_item_total( $item );

			// Grab the first tax rate label to pass as tax_name.
			$tax_name = '';
			if ( $is_taxable && ! empty( $tax_rates ) ) {
				$first_rate = reset( $tax_rates );
				$tax_name   = $first_rate['label'] ?? '';
			}

			$kurv_product_id = get_post_meta( $item['product_id'], 'kurv_product_id', true );
			if ( ! $kurv_product_id ) {
				self::update_product_on_kurv( (int) $item['product_id'] );
				$kurv_product_id = get_post_meta( $item['product_id'], 'kurv_product_id', true );
			}

			$cart_items[] = [
				'sku'                => $sku,
				'name'               => $item->get_name(),
				'qty'                => $item->get_quantity(),
				'sales_price'        => $item_total,
				'unit'               => 'pc',
				'product_service_id' => $kurv_product_id,
				'taxable'            => $is_taxable ? 1 : 0,
				'tax_value'          => $is_taxable && ! empty( $total_tax ) ? $total_tax : 0,
				'tax_type'           => 'fixed_amount',
				'tax_name'           => $tax_name,
			];
		}

		return $cart_items;
	}

	/**
	 * Initiate payment: create the Kurv payment request and redirect the customer.
	 *
	 * @param int $order_id Order ID.
	 * @return array{result:string,redirect?:string}
	 */
	public function process_payment( $order_id ): array {
		$order          = wc_get_order( $order_id );
		$transaction_id = 'wc-' . $order->get_order_number();
		$secret_key     = wc_rand_hash();

		$order->update_meta_data( '_kurv_transaction_id', $transaction_id );
		$order->update_meta_data( '_kurv_secret_key', $secret_key );
		$order->save();

		try {
			$payment_url = $this->get_payment_url( $order_id, $transaction_id );
		} catch ( \Exception $e ) {
			$this->log( 'process_payment error: ' . $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			return [ 'result' => 'failure' ];
		}

		return [
			'result'   => 'success',
			'redirect' => $payment_url,
		];
	}

	/**
	 * Process a partial refund via WooCommerce admin.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Amount to refund.
	 * @param string $reason   Refund reason (not currently used by the API).
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'kurv' !== $order->get_payment_method() ) {
			return false;
		}

		$payment_id = $order->get_meta( '_kurv_payment_id', true );
		$body       = [
			'email'  => $order->get_billing_email(),
			'amount' => (float) $amount,
		];

		$this->log( 'process_refund - request body: ' . wp_json_encode( $body ) );
		$results = Kurv_API::do_refund( $payment_id, $body );
		$this->log( 'process_refund - results: ' . wp_json_encode( $results ) );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		if ( 200 === $results['response']['code'] && 'refund' === $results['body']['status'] ) {
			$ref_number      = $results['body']['ref_number'] ?? '';
			$balance         = $results['body']['balance'] ?? null;
			$refunded_amount = $results['body']['refunded_amount'] ?? $amount;

			$order->update_meta_data( '_kurv_last_refund_ref', $ref_number );
			$order->save();

			$note = sprintf(
				/* translators: 1: refunded amount, 2: Kurv refund ref number */
				__( 'Kurv partial refund of %1$s successful. Kurv ref: %2$s.', 'kurv-payments-for-woocommerce' ),
				wc_price( (float) $refunded_amount, [ 'currency' => $order->get_currency() ] ),
				$ref_number
			);
			if ( null !== $balance ) {
				$note .= ' ' . sprintf(
					/* translators: remaining refundable amount */
					__( 'Remaining refundable: %s.', 'kurv-payments-for-woocommerce' ),
					wc_price( (float) $balance, [ 'currency' => $order->get_currency() ] )
				);
			}
			$order->add_order_note( $note );
			$this->log( 'process_refund: success' );
			return true;
		}

		$this->log( 'process_refund: failed' );
		return new \WP_Error(
			(string) $results['response']['code'],
			__( 'Refund failed', 'kurv-payments-for-woocommerce' ) . ': ' . ( $results['body']['message'] ?? '' )
		);
	}

	/**
	 * Process a full refund when an order is moved to Refunded status.
	 *
	 * Hooked to woocommerce_order_status_changed (3 params).
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $status_from Previous status (without wc- prefix).
	 * @param string $status_to   New status (without wc- prefix).
	 */
	public function process_full_refund_on_status_change( int $order_id, string $status_from, string $status_to ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'kurv' !== $order->get_payment_method() ) {
			return;
		}

		if ( ! in_array( $status_from, [ 'processing', 'completed' ], true ) || 'refunded' !== $status_to ) {
			return;
		}

		$amount     = (float) $order->get_total();
		$payment_id = $order->get_meta( '_kurv_payment_id', true );
		$body       = [
			'email'  => $order->get_billing_email(),
			'amount' => $amount,
		];

		$this->log( 'process_full_refund - request body: ' . wp_json_encode( $body ) );
		$results = Kurv_API::do_refund( $payment_id, $body );
		$this->log( 'process_full_refund - results: ' . wp_json_encode( $results ) );

		if ( is_wp_error( $results ) ) {
			$order->add_order_note(
				sprintf(
					__( 'Kurv full refund failed: %s', 'kurv-payments-for-woocommerce' ),
					esc_html( $results->get_error_message() )
				)
			);
			$this->log( 'process_full_refund: failed (WP_Error)' );
			return;
		}

		if ( 200 === $results['response']['code'] && 'refund' === $results['body']['status'] ) {
			$ref_number = $results['body']['ref_number'] ?? '';
			$order->update_meta_data( '_kurv_last_refund_ref', $ref_number );
			$order->save();

			$this->restock_refunded_items( $order );
			$order->add_order_note( sprintf(
				/* translators: Kurv refund reference number */
				__( 'Kurv full refund successful. Kurv ref: %s.', 'kurv-payments-for-woocommerce' ),
				$ref_number
			) );
			$this->log( 'process_full_refund: success' );
		} else {
			$order->add_order_note(
				sprintf(
					__( 'Kurv full refund failed: %s', 'kurv-payments-for-woocommerce' ),
					esc_html( $results['body']['message'] ?? __( 'unknown error', 'kurv-payments-for-woocommerce' ) )
				)
			);
			$this->log( 'process_full_refund: failed' );
		}
	}

	/**
	 * Add an order note if the payment amount exceeds the order total (e.g. tip/tax added by merchant).
	 *
	 * Hooked to woocommerce_order_status_changed (3 params).
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $status_from Previous status.
	 * @param string $status_to   New status.
	 */
	public function add_full_refund_notes( int $order_id, string $status_from, string $status_to ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'kurv' !== $order->get_payment_method() ) {
			return;
		}

		if ( ! in_array( $status_from, [ 'processing', 'completed' ], true ) || 'refunded' !== $status_to ) {
			return;
		}

		$order_amount = (float) $order->get_total();
		$payment_id   = $order->get_meta( '_kurv_payment_id', true );
		$results      = Kurv_API::get_payment( $payment_id );

		$this->log( 'add_full_refund_notes - get_payment results: ' . wp_json_encode( $results ) );

		if ( is_wp_error( $results ) || 200 !== $results['response']['code'] ) {
			return;
		}

		$payment_amount = (float) ( $results['body']['payment']['amount'] ?? 0 );
		if ( $payment_amount > $order_amount ) {
			$order->add_order_note(
				__( 'Kurv: The payment amount exceeds the order total (the customer may have added a tip or tax). Please contact Kurv support to refund the remaining amount.', 'kurv-payments-for-woocommerce' )
			);
		}
	}

	/**
	 * Add a "Capture Payment" action to the WooCommerce order action dropdown.
	 *
	 * Only shown for Kurv orders in on-hold status when PA mode is configured.
	 *
	 * @param array<string,string> $actions Existing order actions.
	 * @return array<string,string>
	 */
	public function add_capture_order_action( array $actions ): array {
		global $theorder;
		if ( ! $theorder instanceof \WC_Order ) {
			return $actions;
		}
		if ( 'kurv' !== $theorder->get_payment_method() ) {
			return $actions;
		}
		if ( 'on-hold' !== $theorder->get_status() ) {
			return $actions;
		}
		if ( 'PA' !== $this->get_option( 'payment_type', 'DB' ) ) {
			return $actions;
		}
		$actions['kurv_capture_payment'] = __( 'Capture Kurv pre-authorised payment', 'kurv-payments-for-woocommerce' );
		return $actions;
	}

	/**
	 * Handle the "Capture Payment" order action triggered from the order screen.
	 *
	 * Calls POST /captures/{payment_id} and moves the order to processing or completed.
	 *
	 * @param \WC_Order $order Order to capture.
	 */
	public function process_capture_order_action( \WC_Order $order ): void {
		if ( 'kurv' !== $order->get_payment_method() ) {
			return;
		}

		$payment_id = $order->get_meta( '_kurv_payment_id', true );
		if ( empty( $payment_id ) ) {
			$order->add_order_note( __( 'Kurv capture failed: no payment ID on order.', 'kurv-payments-for-woocommerce' ) );
			return;
		}

		$body = [ 'amount' => (float) $order->get_total() ];

		$this->log( 'process_capture_order_action - payment_id=' . $payment_id . ' amount=' . $body['amount'] );
		$result = Kurv_API::capture_payment( $payment_id, $body );
		$this->log( 'process_capture_order_action - result: ' . wp_json_encode( $result ) );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf(
				/* translators: error message from Kurv API */
				__( 'Kurv capture failed: %s', 'kurv-payments-for-woocommerce' ),
				esc_html( $result->get_error_message() )
			) );
			return;
		}

		if ( 200 === $result['response']['code'] && 'success' === ( $result['body']['result'] ?? '' ) ) {
			$order_status = 'completed';
			foreach ( $order->get_items() as $order_item ) {
				$item = wc_get_product( $order_item->get_product_id() );
				if ( $item && ! $item->is_virtual() ) {
					$order_status = 'processing';
					break;
				}
			}
			$order->update_status( $order_status, __( 'Kurv pre-authorised payment captured successfully.', 'kurv-payments-for-woocommerce' ) );
			$order->save();
			return;
		}

		$error = $result['body']['message'] ?? $result['body']['error_message'] ?? __( 'unknown error', 'kurv-payments-for-woocommerce' );
		$order->add_order_note( sprintf(
			/* translators: error message from Kurv API */
			__( 'Kurv capture failed: %s', 'kurv-payments-for-woocommerce' ),
			esc_html( $error )
		) );
	}

	/**
	 * Restock all items from a refunded order.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function restock_refunded_items( \WC_Order $order ): void {
		$refunded_line_items = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			$refunded_line_items[ $item_id ]['qty'] = $item->get_quantity();
		}

		wc_restock_refunded_items( $order, $refunded_line_items );
	}

	/**
	 * Handle the payment response when the customer returns from Kurv.
	 *
	 * Hooked to woocommerce_thankyou_kurv — only fires for Kurv orders.
	 *
	 * @param int $order_id Order ID.
	 */
	public function response_page( int $order_id ): void {
		$token = get_query_var( 'kurv_token' );

		if ( empty( $token ) ) {
			$this->log( 'response_page: no token, skipping' );
			return;
		}

		$this->log( 'response_page: processing payment response' );

		$raw_response = get_query_var( 'response' );
		$this->log( 'response_page - raw response: ' . $raw_response );

		$response = json_decode( wp_unslash( $raw_response ), true );
		$this->log( 'response_page - decoded response: ' . wp_json_encode( $response ) );

		$payment_status = $response['status'] ?? $response['result'] ?? '';
		$result_code    = (int) ( $response['result_code'] ?? 0 );
		$payment_id     = $response['payment_id'] ?? $response['response']['id'] ?? '';
		$currency       = $response['currency'] ?? $response['response']['currency'] ?? '';

		$generated_token = $this->generate_token( $order_id, $currency );
		$order           = wc_get_order( $order_id );

		if ( ! $order || 'kurv' !== $order->get_payment_method() ) {
			return;
		}

		if ( $token !== $generated_token ) {
			$this->log( 'response_page: token mismatch — possible fraud attempt', 'warning' );
			return;
		}

		// result_code 100 = success per Kurv API docs. Check both for robustness.
		if ( 'ACK' === $payment_status || 100 === $result_code ) {
			$order->update_meta_data( '_kurv_payment_id', $payment_id );
			$order->update_meta_data( '_kurv_payment_result', 'success' );

			if ( 'PA' === $this->payment_type ) {
				$this->log( 'response_page: PA — setting order to on-hold (capture required)' );
				$order->update_status( 'on-hold', __( 'Kurv payment pre-authorised. Capture required.', 'kurv-payments-for-woocommerce' ) );
			} else {
				$order_status = 'completed';
				foreach ( $order->get_items() as $order_item ) {
					$item = wc_get_product( $order_item->get_product_id() );
					if ( $item && ! $item->is_virtual() ) {
						$order_status = 'processing';
						break;
					}
				}
				$this->log( 'response_page: updating order status to ' . $order_status );
				$order->update_status( $order_status, __( 'Kurv payment successful.', 'kurv-payments-for-woocommerce' ) );
			}

			$order->save();
		} else {
			$this->log( 'response_page: payment not acknowledged, marking failed' );

			$order->update_meta_data( '_kurv_payment_result', 'failed' );
			$order->update_status( 'failed', __( 'Kurv payment failed.', 'kurv-payments-for-woocommerce' ) );
			$order->save();
		}
	}

	/**
	 * Create or update a product in Kurv when it is saved in WooCommerce.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public static function update_product_on_kurv( int $product_id ): void {
		$product      = wc_get_product( $product_id );
		$product_image = wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'single-post-thumbnail' );
		$category_id  = self::check_and_create_product_category( $product_id );

		$data = [
			'name'             => $product->get_name(),
			'description'      => $product->get_description(),
			'sku'              => $product->get_sku(),
			'category_id'      => $category_id,
			'type'             => 'product',
			'manage_inventory' => $product->get_manage_stock(),
			'unit_in_stock'    => $product->get_stock_quantity(),
			'unit_low_stock'   => $product->get_low_stock_amount(),
			'unit_type'        => 'flat-rate',
			'cost'             => $product->get_regular_price() ?: $product->get_price(),
			'sales_price'      => $product->get_price(),
			'image'            => $product_image ? $product_image[0] : null,
		];

		$kurv_product_id = get_post_meta( $product_id, 'kurv_product_id', true );

		if ( $kurv_product_id ) {
			$data['id'] = $kurv_product_id;
			Kurv_API::update_product( $data );
		} else {
			$result = Kurv_API::create_product( $data );
			if ( ! is_wp_error( $result ) && 200 === $result['response']['code'] && 'success' === $result['body']['result'] ) {
				update_post_meta( $product_id, 'kurv_product_id', $result['body']['id'] );
			}
		}
	}

	/**
	 * Ensure a product's WooCommerce category exists in Kurv, creating it if needed.
	 *
	 * Returns the Kurv category ID, or null if unavailable.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return string|null
	 */
	public static function check_and_create_product_category( int $product_id ): ?string {
		$product_categories = wp_get_post_terms( $product_id, 'product_cat' );
		$category_name      = count( $product_categories ) ? $product_categories[0]->name : __( 'Uncategorised', 'kurv-payments-for-woocommerce' );

		$result = Kurv_API::category_list( $category_name );
		if ( is_wp_error( $result ) || 200 !== $result['response']['code'] || 'success' !== $result['body']['result'] ) {
			return null;
		}

		if ( ! empty( $result['body']['categories'] ) ) {
			return (string) $result['body']['categories'][0]['id'];
		}

		$create = Kurv_API::create_category( [ 'name' => $category_name ] );
		if ( ! is_wp_error( $create ) && 200 === $create['response']['code'] ) {
			return (string) $create['body']['id'];
		}

		return null;
	}

	/**
	 * Create or update a customer record in Kurv based on order billing data.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return string|null Kurv customer ID, or null on failure.
	 */
	public static function update_customer_on_kurv( \WC_Order $order ): ?string {
		$check = Kurv_API::customers( $order->get_billing_email() );

		if ( is_wp_error( $check ) || 200 !== $check['response']['code'] || 'success' !== $check['body']['result'] ) {
			return null;
		}

		$country_code_phone    = ( new self() )->get_country_code( $order->get_billing_country() );
		$customer_phone_number = $order->get_billing_phone();

		if ( $country_code_phone && ! str_starts_with( $customer_phone_number, $country_code_phone ) && strlen( $customer_phone_number ) <= 10 ) {
			$customer_phone_number = $country_code_phone . $customer_phone_number;
		}

		$customer_data = [
			'email'         => $order->get_billing_email(),
			'mobile_no'     => $customer_phone_number,
			'first_name'    => $order->get_billing_first_name(),
			'last_name'     => $order->get_billing_last_name(),
			'company_name'  => $order->get_billing_company(),
			'listing_type'  => 'individual',
			'address_line1' => $order->get_billing_address_1(),
			'address_line2' => $order->get_billing_address_2(),
			'city'          => $order->get_billing_city(),
			'state'         => $order->get_billing_state(),
			'postal_code'   => $order->get_billing_postcode(),
			'country_iso'   => $order->get_billing_country(),
		];

		if ( ! empty( $check['body']['customers'] ) ) {
			$customer    = $check['body']['customers'][0];
			$customer_id = (string) $customer['customer_id'];
			// customer_id goes in the URL path, not the body — per Kurv API: PUT /customers/{customer_id}
			Kurv_API::update_customer( $customer_id, $customer_data );
			return $customer_id;
		}

		$create = Kurv_API::create_customer( $customer_data );
		if ( ! is_wp_error( $create ) && 200 === $create['response']['code'] && 'success' === $create['body']['result'] ) {
			return (string) ( $create['body']['customer_id'] ?? '' );
		}

		return null;
	}

	/**
	 * Return the international dialling prefix for a given ISO 3166-1 alpha-2 country code.
	 *
	 * @param string $country_code Two-letter country code (e.g. 'US').
	 * @return string|null Prefix including leading '+', or null if not found.
	 */
	public function get_country_code( string $country_code ): ?string {
		$country_phone_codes = [
			'AF' => '+93',
			'AL' => '+355',
			'DZ' => '+213',
			'AS' => '+1-684',
			'AD' => '+376',
			'AO' => '+244',
			'AI' => '+1-264',
			'AQ' => '+672',
			'AG' => '+1-268',
			'AR' => '+54',
			'AM' => '+374',
			'AW' => '+297',
			'AU' => '+61',
			'AT' => '+43',
			'AZ' => '+994',
			'BS' => '+1-242',
			'BH' => '+973',
			'BD' => '+880',
			'BB' => '+1-246',
			'BY' => '+375',
			'BE' => '+32',
			'BZ' => '+501',
			'BJ' => '+229',
			'BM' => '+1-441',
			'BT' => '+975',
			'BO' => '+591',
			'BA' => '+387',
			'BW' => '+267',
			'BR' => '+55',
			'IO' => '+246',
			'VG' => '+1-284',
			'BN' => '+673',
			'BG' => '+359',
			'BF' => '+226',
			'BI' => '+257',
			'KH' => '+855',
			'CM' => '+237',
			'CA' => '+1',
			'CV' => '+238',
			'KY' => '+1-345',
			'CF' => '+236',
			'TD' => '+235',
			'CL' => '+56',
			'CN' => '+86',
			'CX' => '+61',
			'CC' => '+61',
			'CO' => '+57',
			'KM' => '+269',
			'CK' => '+682',
			'CR' => '+506',
			'HR' => '+385',
			'CU' => '+53',
			'CW' => '+599',
			'CY' => '+357',
			'CZ' => '+420',
			'CD' => '+243',
			'DK' => '+45',
			'DJ' => '+253',
			'DM' => '+1-767',
			'DO' => '+1-809',
			'TL' => '+670',
			'EC' => '+593',
			'EG' => '+20',
			'SV' => '+503',
			'GQ' => '+240',
			'ER' => '+291',
			'EE' => '+372',
			'ET' => '+251',
			'FK' => '+500',
			'FO' => '+298',
			'FJ' => '+679',
			'FI' => '+358',
			'FR' => '+33',
			'PF' => '+689',
			'GA' => '+241',
			'GM' => '+220',
			'GE' => '+995',
			'DE' => '+49',
			'GH' => '+233',
			'GI' => '+350',
			'GR' => '+30',
			'GL' => '+299',
			'GD' => '+1-473',
			'GU' => '+1-671',
			'GT' => '+502',
			'GG' => '+44-1481',
			'GN' => '+224',
			'GW' => '+245',
			'GY' => '+592',
			'HT' => '+509',
			'HN' => '+504',
			'HK' => '+852',
			'HU' => '+36',
			'IS' => '+354',
			'IN' => '+91',
			'ID' => '+62',
			'IR' => '+98',
			'IQ' => '+964',
			'IE' => '+353',
			'IM' => '+44-1624',
			'IL' => '+972',
			'IT' => '+39',
			'CI' => '+225',
			'JM' => '+1-876',
			'JP' => '+81',
			'JE' => '+44-1534',
			'JO' => '+962',
			'KZ' => '+7',
			'KE' => '+254',
			'KI' => '+686',
			'XK' => '+383',
			'KW' => '+965',
			'KG' => '+996',
			'LA' => '+856',
			'LV' => '+371',
			'LB' => '+961',
			'LS' => '+266',
			'LR' => '+231',
			'LY' => '+218',
			'LI' => '+423',
			'LT' => '+370',
			'LU' => '+352',
			'MO' => '+853',
			'MK' => '+389',
			'MG' => '+261',
			'MW' => '+265',
			'MY' => '+60',
			'MV' => '+960',
			'ML' => '+223',
			'MT' => '+356',
			'MH' => '+692',
			'MR' => '+222',
			'MU' => '+230',
			'YT' => '+262',
			'MX' => '+52',
			'FM' => '+691',
			'MD' => '+373',
			'MC' => '+377',
			'MN' => '+976',
			'ME' => '+382',
			'MS' => '+1-664',
			'MA' => '+212',
			'MZ' => '+258',
			'MM' => '+95',
			'NA' => '+264',
			'NR' => '+674',
			'NP' => '+977',
			'NL' => '+31',
			'AN' => '+599',
			'NC' => '+687',
			'NZ' => '+64',
			'NI' => '+505',
			'NE' => '+227',
			'NG' => '+234',
			'NU' => '+683',
			'KP' => '+850',
			'MP' => '+1-670',
			'NO' => '+47',
			'OM' => '+968',
			'PK' => '+92',
			'PW' => '+680',
			'PS' => '+970',
			'PA' => '+507',
			'PG' => '+675',
			'PY' => '+595',
			'PE' => '+51',
			'PH' => '+63',
			'PN' => '+64',
			'PL' => '+48',
			'PT' => '+351',
			'PR' => '+1-787',
			'QA' => '+974',
			'CG' => '+242',
			'RE' => '+262',
			'RO' => '+40',
			'RU' => '+7',
			'RW' => '+250',
			'BL' => '+590',
			'SH' => '+290',
			'KN' => '+1-869',
			'LC' => '+1-758',
			'MF' => '+590',
			'PM' => '+508',
			'VC' => '+1-784',
			'WS' => '+685',
			'SM' => '+378',
			'ST' => '+239',
			'SA' => '+966',
			'SN' => '+221',
			'RS' => '+381',
			'SC' => '+248',
			'SL' => '+232',
			'SG' => '+65',
			'SX' => '+1-721',
			'SK' => '+421',
			'SI' => '+386',
			'SB' => '+677',
			'SO' => '+252',
			'ZA' => '+27',
			'KR' => '+82',
			'SS' => '+211',
			'ES' => '+34',
			'LK' => '+94',
			'SD' => '+249',
			'SR' => '+597',
			'SJ' => '+47',
			'SZ' => '+268',
			'SE' => '+46',
			'CH' => '+41',
			'SY' => '+963',
			'TW' => '+886',
			'TJ' => '+992',
			'TZ' => '+255',
			'TH' => '+66',
			'TG' => '+228',
			'TK' => '+690',
			'TO' => '+676',
			'TT' => '+1-868',
			'TN' => '+216',
			'TR' => '+90',
			'TM' => '+993',
			'TC' => '+1-649',
			'TV' => '+688',
			'VI' => '+1-340',
			'UG' => '+256',
			'UA' => '+380',
			'AE' => '+971',
			'GB' => '+44',
			'US' => '+1',
			'UY' => '+598',
			'UZ' => '+998',
			'VU' => '+678',
			'VA' => '+379',
			'VE' => '+58',
			'VN' => '+84',
			'WF' => '+681',
			'EH' => '+212',
			'YE' => '+967',
			'ZM' => '+260',
			'ZW' => '+263',
		];

		return $country_phone_codes[ $country_code ] ?? null;
	}
}
