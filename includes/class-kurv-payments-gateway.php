<?php

declare(strict_types=1);

/**
 * Kurv_Payments_Gateway — Kurv payment gateway for WooCommerce.
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
 * Redirects customers to a hosted Kurv payment page. The authoritative
 * payment result arrives on the server-to-server callback endpoint
 * (/wc-api/kurv/); the customer's browser return is only a secondary
 * confirmation, and a scheduled reconciliation sweep catches orders where
 * neither signal arrived.
 *
 * @extends WC_Payment_Gateway
 * @since 1.0.0
 */
class Kurv_Payments_Gateway extends WC_Payment_Gateway {

	/**
	 * Action Scheduler hook used to reconcile orders with no confirmed result.
	 */
	public const RECONCILE_HOOK = 'kurv_reconcile_order';

	/**
	 * Delays (in seconds) at which an unconfirmed order is re-checked against
	 * the Kurv API: 10 minutes, 1 hour, then 6 hours.
	 *
	 * @var array<int,int>
	 */
	private const RECONCILE_DELAYS = [ 600, 3600, 21600 ];

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
		// Server-to-server payment callback: POST to /wc-api/kurv/. This is the
		// authoritative result — it arrives whether or not the customer returns.
		add_action( 'woocommerce_api_' . $this->id, [ $this, 'handle_payment_callback' ] );
		// Gateway-specific thank-you hook — only fires for Kurv orders, not every order.
		add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'response_page' ] );
		// NOTE: the reconciliation hook is deliberately NOT registered here. Action
		// Scheduler runs in contexts that never instantiate payment gateways, so it
		// is bound once at file scope in kurv-woocommerce.php instead.
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
					esc_url( self::get_callback_url() ),
					esc_html__( 'Kurv posts each payment result back to this endpoint. It is sent automatically with every payment request as the response_url — no manual configuration required.', 'kurv-payments-for-woocommerce' )
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
			// NOTE: the payment link expiry field is intentionally not registered.
			// The Kurv API rejects every documented expiry_date format we have tried
			// (see get_payment_url()), so exposing the setting would promise behaviour
			// the plugin cannot deliver. Restore both together once Kurv confirms the
			// accepted format.
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

		// Read-only page-routing check on an admin screen; no state is changed here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';

		if ( $section !== $this->id ) {
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
			'logoUrl'        => $base . 'assets/img/kurv-logo.svg',
			'brandText'      => __( 'Kurv', 'kurv-payments-for-woocommerce' ),
			'processingText' => __( 'Processing payment', 'kurv-payments-for-woocommerce' ),
			'secureText'     => __( 'Secured by Kurv', 'kurv-payments-for-woocommerce' ),
			'errorText'      => __( 'Something went wrong. Please try again.', 'kurv-payments-for-woocommerce' ),
			'messages'       => [
				__( 'Hang tight — building your secure payment page…', 'kurv-payments-for-woocommerce' ),
				__( 'We know you’re in a hurry. Almost there…', 'kurv-payments-for-woocommerce' ),
				__( 'Connecting you to Kurv…', 'kurv-payments-for-woocommerce' ),
				__( 'Your payment page is being handcrafted…', 'kurv-payments-for-woocommerce' ),
			],
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
	 * Load API credentials straight from the stored settings.
	 *
	 * Background jobs (Action Scheduler, WP-Cron) run in requests that never
	 * instantiate payment gateways, so init_api() does not fire and Kurv_API
	 * would otherwise authenticate with an empty key.
	 */
	public static function ensure_api_credentials(): void {
		if ( '' !== Kurv_API::$access_key ) {
			return;
		}

		$settings = get_option( 'woocommerce_kurv_settings', [] );
		$is_test  = 'yes' === ( $settings['test_mode'] ?? 'no' );

		Kurv_API::$is_test_mode = $is_test;
		Kurv_API::$access_key   = (string) ( $is_test
			? ( $settings['test_access_key'] ?? '' )
			: ( $settings['live_access_key'] ?? '' ) );
	}

	/**
	 * Return the server-to-server callback endpoint Kurv posts payment results to.
	 *
	 * Uses the WooCommerce API endpoint (/wc-api/kurv/) rather than the
	 * order-received page, so the callback is handled by dedicated code that can
	 * read a POST body — the thank-you page cannot.
	 */
	public static function get_callback_url(): string {
		return WC()->api_request_url( 'kurv' );
	}

	/**
	 * Generate a one-time token that ties a payment response to this order.
	 *
	 * The token is an HMAC-SHA256 hash (via wp_hash) of the order ID and a
	 * per-order secret, and is appended to both the redirect and response URLs so
	 * we can verify a callback is genuine.
	 *
	 * The token deliberately depends only on values we already hold locally. An
	 * earlier version mixed in the currency and transaction ID taken from the
	 * callback payload, which meant a payload that omitted either field could
	 * never be verified.
	 *
	 * @param int $order_id Order ID.
	 * @return string Empty string if the order or its secret is unavailable.
	 */
	protected function generate_token( int $order_id ): string {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return '';
		}

		$secret_key = (string) $order->get_meta( '_kurv_secret_key', true );

		if ( '' === $secret_key ) {
			return '';
		}

		return wp_hash( $order_id . '|' . $secret_key );
	}

	/**
	 * Constant-time check that a supplied token matches the one for this order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $token    Token supplied by the caller.
	 */
	protected function verify_token( int $order_id, string $token ): bool {
		$expected = $this->generate_token( $order_id );

		if ( '' === $expected || '' === $token ) {
			return false;
		}

		return hash_equals( $expected, $token );
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
		$token      = $this->generate_token( $order_id );
		$return_url = $this->get_return_url( $order );

		// Browser return — the customer lands here after paying.
		$redirect_url = add_query_arg( 'kurv_token', $token, $return_url );

		// Server-to-server result callback. This carries the order ID explicitly
		// because, unlike the redirect, it is not tied to a WooCommerce order page.
		$response_url = add_query_arg(
			[
				'kurv_order_id' => $order_id,
				'kurv_token'    => $token,
			],
			self::get_callback_url()
		);

		$country_code_phone    = self::get_country_code( $order->get_billing_country() );
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
			'redirect_url'        => $redirect_url,
			'response_url'        => $response_url,
		];

		// Add Apple Pay / Google Pay if enabled.
		if ( 'yes' === $this->get_option( 'enable_wallet_methods', 'no' ) ) {
			$body['payment_methods'] = [ 'APPLE_PAY', 'GOOGLE_PAY' ];
		}

		/*
		 * expiry_date is deliberately omitted.
		 *
		 * Every documented format we have tried (YYYY-MM-DD HH:MM, ISO 8601 with Z,
		 * ISO 8601 with +00:00) is rejected by the API with "expiry_date is not in
		 * ISO 8601 format.", and every request that succeeds in the logs omits the
		 * field entirely. The matching admin setting is unregistered in
		 * init_form_fields() so the two stay in step — restore both once Kurv
		 * confirms the accepted format.
		 */

		// Sync the customer to Kurv's CRM. Queued, not inline: it costs up to two
		// API calls and nothing in this request depends on the result — customer_id
		// is intentionally not sent in the payment request body, because the Kurv
		// sandbox rejects it as invalid. Re-evaluate once confirmed with Kurv.
		self::queue_customer_sync( $order_id );

		$log_body                 = $body;
		$log_body['response_url'] = add_query_arg( 'kurv_token', '*****', self::get_callback_url() );
		$log_body['redirect_url'] = add_query_arg( 'kurv_token', '*****', $return_url );
		$this->log( 'get_payment_url - body: ' . wp_json_encode( $log_body ) );

		$results = Kurv_API::generate_pos_link( $body );
		$this->log( 'get_payment_url - results: ' . wp_json_encode( $results ) );

		if ( is_wp_error( $results ) ) {
			throw new \Exception( esc_html( $results->get_error_message() ), 1 );
		}

		$code = (int) ( $results['response']['code'] ?? 0 );
		$data = is_array( $results['body'] ?? null ) ? $results['body'] : [];

		if ( 200 === $code && 'success' === ( $data['result'] ?? '' ) && ! empty( $data['long_url'] ) ) {
			// Store the Kurv-side identifiers so the order can be reconciled later
			// even if no callback or browser return ever arrives.
			$order = wc_get_order( $order_id );
			$order->update_meta_data( '_kurv_short_url', $data['short_url'] ?? '' );
			$order->update_meta_data( '_kurv_qrcode_url', $data['qrcode_link'] ?? '' );
			$order->update_meta_data( '_kurv_request_id', $data['transaction_id'] ?? '' );
			$order->save();

			$this->schedule_reconciliation( $order_id );

			return (string) $data['long_url'];
		}

		if ( 422 === $code && 'currency' === ( $data['error_field'] ?? '' ) ) {
			throw new \Exception( esc_html__( 'We are sorry, this currency is not supported. Please contact us.', 'kurv-payments-for-woocommerce' ), 1 );
		}

		if ( ! empty( $data['error_message'] ) ) {
			throw new \Exception( esc_html( (string) $data['error_message'] ), 1 );
		}

		if ( ! empty( $data['message'] ) ) {
			throw new \Exception( esc_html( (string) $data['message'] ), 1 );
		}

		throw new \Exception( esc_html__( 'Payment could not be initiated. Please try again.', 'kurv-payments-for-woocommerce' ), 1 );
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
			$product = $item->get_product();

			// The product may have been deleted since the order was placed. Fall
			// back to the line item's own data rather than fatalling on null.
			$price      = $product ? (float) $product->get_price() : 0.0;
			$tax_rates  = $product ? WC_Tax::get_rates( $product->get_tax_class() ) : [];
			$taxes      = $tax_rates ? WC_Tax::calc_tax( $price, $tax_rates, false ) : [];
			$total_tax  = array_sum( $taxes );
			$is_taxable = 'taxable' === $item->get_tax_status();
			$sku        = ( $product && $product->get_sku() ) ? $product->get_sku() : '-';
			$item_total = isset( $item['recurring_line_total'] ) ? $item['recurring_line_total'] : $order->get_item_total( $item );

			// Grab the first tax rate label to pass as tax_name.
			$tax_name = '';
			if ( $is_taxable && ! empty( $tax_rates ) ) {
				$first_rate = reset( $tax_rates );
				$tax_name   = $first_rate['label'] ?? '';
			}

			// Never sync a product inline here — that would put a blocking HTTP
			// round trip (up to 70s) between the customer and their payment page.
			// Queue it instead; the ID will be present on subsequent orders.
			$kurv_product_id = get_post_meta( $item['product_id'], 'kurv_product_id', true );
			if ( ! $kurv_product_id ) {
				self::queue_product_sync( (int) $item['product_id'] );
				$kurv_product_id = '';
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

		$payment_id = (string) $order->get_meta( '_kurv_payment_id', true );

		if ( '' === $payment_id ) {
			return new \WP_Error(
				'kurv_no_payment_id',
				__( 'Refund failed: this order has no Kurv payment ID, so the payment was never confirmed.', 'kurv-payments-for-woocommerce' )
			);
		}

		$body = [
			'email'  => $order->get_billing_email(),
			'amount' => (float) $amount,
		];

		$this->log( 'process_refund - request body: ' . wp_json_encode( $body ) );
		$results = Kurv_API::do_refund( $payment_id, $body );
		$this->log( 'process_refund - results: ' . wp_json_encode( $results ) );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$code = (int) ( $results['response']['code'] ?? 0 );
		$data = is_array( $results['body'] ?? null ) ? $results['body'] : [];

		if ( 200 === $code && 'refund' === ( $data['status'] ?? '' ) ) {
			$ref_number      = $data['ref_number'] ?? '';
			$balance         = $data['balance'] ?? null;
			$refunded_amount = $data['refunded_amount'] ?? $amount;

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
			'kurv_refund_failed_' . $code,
			__( 'Refund failed', 'kurv-payments-for-woocommerce' ) . ': ' . ( $data['message'] ?? __( 'unknown error', 'kurv-payments-for-woocommerce' ) )
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
					/* translators: %s: error message returned by the Kurv API. */
					__( 'Kurv full refund failed: %s', 'kurv-payments-for-woocommerce' ),
					esc_html( $results->get_error_message() )
				)
			);
			$this->log( 'process_full_refund: failed (WP_Error)' );
			return;
		}

		$code = (int) ( $results['response']['code'] ?? 0 );
		$data = is_array( $results['body'] ?? null ) ? $results['body'] : [];

		if ( 200 === $code && 'refund' === ( $data['status'] ?? '' ) ) {
			$ref_number = $data['ref_number'] ?? '';
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
					/* translators: %s: error message returned by the Kurv API. */
					__( 'Kurv full refund failed: %s', 'kurv-payments-for-woocommerce' ),
					esc_html( $data['message'] ?? __( 'unknown error', 'kurv-payments-for-woocommerce' ) )
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
	 * Handle Kurv's server-to-server payment callback.
	 *
	 * Hooked to woocommerce_api_kurv, i.e. POST /wc-api/kurv/. Kurv sends the
	 * result as a form field named "response" containing a JSON string. This is
	 * the authoritative signal: it arrives whether or not the customer's browser
	 * ever makes it back to the site.
	 */
	public function handle_payment_callback(): void {
		// Authenticity is established by the per-order HMAC token in the URL,
		// which is why there is no nonce here — Kurv is not a logged-in user and
		// cannot hold one.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_REQUEST['kurv_order_id'] ) ? absint( wp_unslash( $_REQUEST['kurv_order_id'] ) ) : 0;
		$token    = isset( $_REQUEST['kurv_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['kurv_token'] ) ) : '';
		$raw      = isset( $_REQUEST['response'] ) ? wp_unslash( $_REQUEST['response'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->log( 'handle_payment_callback: order_id=' . $order_id );

		if ( ! $order_id || ! $this->verify_token( $order_id, $token ) ) {
			$this->log( 'handle_payment_callback: token mismatch or missing order — rejected', 'warning' );
			status_header( 403 );
			exit;
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			$this->log( 'handle_payment_callback: empty response payload', 'warning' );
			status_header( 400 );
			exit;
		}

		$response = json_decode( $raw, true );

		if ( ! is_array( $response ) ) {
			$this->log( 'handle_payment_callback: response payload was not valid JSON', 'warning' );
			status_header( 400 );
			exit;
		}

		$this->apply_payment_result( $order_id, $response, 'callback' );

		status_header( 200 );
		exit;
	}

	/**
	 * Handle the customer's browser return from the hosted payment page.
	 *
	 * Hooked to woocommerce_thankyou_kurv — only fires for Kurv orders. This is a
	 * best-effort secondary path: if the redirect carries a result we apply it,
	 * but its absence proves nothing (the callback may simply have arrived first,
	 * or be in flight), so an order is never failed from here.
	 *
	 * @param int $order_id Order ID.
	 */
	public function response_page( int $order_id ): void {
		$token = (string) get_query_var( 'kurv_token' );

		if ( '' === $token ) {
			return;
		}

		if ( ! $this->verify_token( $order_id, $token ) ) {
			$this->log( 'response_page: token mismatch — ignoring', 'warning' );
			return;
		}

		$raw = (string) get_query_var( 'response' );

		if ( '' === trim( $raw ) ) {
			// No result in the redirect. The server-to-server callback is
			// authoritative and reconciliation will catch anything it misses, so
			// leave the order exactly as it is.
			$this->log( 'response_page: no result in redirect — deferring to callback' );
			return;
		}

		$response = json_decode( wp_unslash( $raw ), true );

		if ( ! is_array( $response ) ) {
			$this->log( 'response_page: response payload was not valid JSON — deferring to callback', 'warning' );
			return;
		}

		$this->apply_payment_result( $order_id, $response, 'redirect' );
	}

	/**
	 * Apply a decoded Kurv payment result to an order.
	 *
	 * Idempotent: an order that has already been paid is left untouched, so the
	 * callback and the browser return racing each other is harmless.
	 *
	 * @param int                 $order_id Order ID.
	 * @param array<string,mixed> $response Decoded Kurv response payload.
	 * @param string              $source   Where the result came from, for logs.
	 */
	protected function apply_payment_result( int $order_id, array $response, string $source ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'kurv' !== $order->get_payment_method() ) {
			return;
		}

		$this->log( $source . ' - decoded response: ' . wp_json_encode( $response ) );

		if ( $order->is_paid() || 'on-hold' === $order->get_status() ) {
			$this->log( $source . ': order ' . $order_id . ' already settled — ignoring duplicate result' );
			return;
		}

		$payment_status = $response['status'] ?? $response['result'] ?? '';
		$result_code    = (int) ( $response['result_code'] ?? 0 );
		$payment_id     = (string) ( $response['payment_id'] ?? $response['response']['id'] ?? '' );

		// result_code 100 = success per Kurv API docs. Check both for robustness.
		if ( 'ACK' === $payment_status || 100 === $result_code ) {
			$this->complete_order_payment( $order, $payment_id, $source );
			return;
		}

		$this->log( $source . ': payment not acknowledged, marking order ' . $order_id . ' failed' );

		$order->update_meta_data( '_kurv_payment_result', 'failed' );
		$order->save();
		$order->update_status(
			'failed',
			__( 'Kurv payment failed.', 'kurv-payments-for-woocommerce' ) . ' ' . esc_html( (string) ( $response['result_description'] ?? '' ) )
		);

		$this->cancel_reconciliation( $order_id );
	}

	/**
	 * Mark an order as paid (or pre-authorised) following a successful result.
	 *
	 * @param \WC_Order $order      Order to settle.
	 * @param string    $payment_id Kurv payment ID.
	 * @param string    $source     Where the result came from, for logs.
	 */
	protected function complete_order_payment( \WC_Order $order, string $payment_id, string $source ): void {
		$order->update_meta_data( '_kurv_payment_id', $payment_id );
		$order->update_meta_data( '_kurv_payment_result', 'success' );

		if ( 'PA' === $this->payment_type ) {
			$this->log( $source . ': PA — setting order to on-hold (capture required)' );
			$order->set_transaction_id( $payment_id );
			$order->save();
			$order->update_status( 'on-hold', __( 'Kurv payment pre-authorised. Capture required.', 'kurv-payments-for-woocommerce' ) );
		} else {
			$this->log( $source . ': completing payment for order ' . $order->get_id() );
			$order->add_order_note( __( 'Kurv payment successful.', 'kurv-payments-for-woocommerce' ) );
			$order->save();

			// payment_complete() records the paid date and transaction ID, reduces
			// stock, picks the right status for the cart contents, and fires
			// woocommerce_payment_complete for other extensions. Setting the status
			// by hand — as this plugin used to — skips all of that.
			$order->payment_complete( $payment_id );
		}

		$this->cancel_reconciliation( $order->get_id() );
	}

	/**
	 * Queue the first reconciliation check for an order awaiting payment.
	 *
	 * @param int $order_id Order ID.
	 * @param int $attempt  Zero-based index into self::RECONCILE_DELAYS.
	 */
	protected function schedule_reconciliation( int $order_id, int $attempt = 0 ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( ! isset( self::RECONCILE_DELAYS[ $attempt ] ) ) {
			return;
		}

		as_schedule_single_action(
			time() + self::RECONCILE_DELAYS[ $attempt ],
			self::RECONCILE_HOOK,
			[ 'order_id' => $order_id ],
			'kurv'
		);
	}

	/**
	 * Drop any pending reconciliation checks for an order that has now settled.
	 *
	 * @param int $order_id Order ID.
	 */
	protected function cancel_reconciliation( int $order_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RECONCILE_HOOK, [ 'order_id' => $order_id ], 'kurv' );
		}
	}

	/**
	 * Ask Kurv directly whether an unresolved order was in fact paid.
	 *
	 * This is the safety net for the case where neither the server-to-server
	 * callback nor the customer's browser return reached us — otherwise the
	 * customer is charged and the order sits in pending forever.
	 *
	 * Deliberately one-directional: it can only move an order forward to paid. A
	 * lookup that comes back negative or unrecognised is treated as "not yet
	 * known", never as a failure, because the alternative risks failing an order
	 * that was actually paid.
	 *
	 * @param int $order_id Order ID.
	 */
	public function reconcile_order( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'kurv' !== $order->get_payment_method() ) {
			return;
		}

		// Anything other than a still-awaiting-payment order is already resolved.
		if ( ! in_array( $order->get_status(), [ 'pending', 'failed' ], true ) ) {
			return;
		}

		$attempt    = (int) $order->get_meta( '_kurv_reconcile_attempt', true );
		$payment_id = (string) $order->get_meta( '_kurv_payment_id', true );
		$request_id = (string) $order->get_meta( '_kurv_request_id', true );

		$this->log( sprintf( 'reconcile_order: order=%d attempt=%d', $order_id, $attempt ) );

		if ( '' !== $payment_id ) {
			$results = Kurv_API::get_payment( $payment_id );
		} elseif ( '' !== $request_id ) {
			$results = Kurv_API::get_payment_request( $request_id );
		} else {
			$this->log( 'reconcile_order: no Kurv identifier on order ' . $order_id . ' — cannot reconcile', 'warning' );
			return;
		}

		$order->update_meta_data( '_kurv_reconcile_attempt', $attempt + 1 );
		$order->save();

		if ( is_wp_error( $results ) ) {
			$this->log( 'reconcile_order: lookup failed — ' . $results->get_error_message(), 'warning' );
			$this->schedule_reconciliation( $order_id, $attempt + 1 );
			return;
		}

		$this->log( 'reconcile_order - results: ' . wp_json_encode( $results ) );

		$body       = is_array( $results['body'] ?? null ) ? $results['body'] : [];
		$confirmed  = $this->extract_confirmed_payment_id( $body );

		if ( null !== $confirmed ) {
			$this->log( 'reconcile_order: confirmed paid, settling order ' . $order_id );
			$order->add_order_note( __( 'Kurv payment confirmed by scheduled status check (no callback was received).', 'kurv-payments-for-woocommerce' ) );
			$this->complete_order_payment( $order, '' !== $confirmed ? $confirmed : $payment_id, 'reconcile' );
			return;
		}

		// Not confirmed. Try again later; give up quietly once attempts run out.
		$this->schedule_reconciliation( $order_id, $attempt + 1 );
	}

	/**
	 * Pull a confirmed-paid payment ID out of a Kurv status lookup response.
	 *
	 * The payment-request and payment endpoints nest their data differently, so
	 * this checks the shapes we have observed. Returns null unless the response
	 * explicitly indicates success — an unrecognised shape must never be read as
	 * a payment.
	 *
	 * @param array<string,mixed> $body Decoded response body.
	 * @return string|null Payment ID (possibly an empty string) when paid, else null.
	 */
	protected function extract_confirmed_payment_id( array $body ): ?string {
		$candidates = [ $body, $body['payment'] ?? null, $body['payment_request'] ?? null ];

		foreach ( $candidates as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$status = strtoupper( (string) ( $node['status'] ?? $node['payment_status'] ?? '' ) );
			$code   = (int) ( $node['result_code'] ?? 0 );

			if ( in_array( $status, [ 'ACK', 'PAID', 'COMPLETED', 'SUCCESS', 'SETTLED' ], true ) || 100 === $code ) {
				return (string) ( $node['payment_id'] ?? $node['id'] ?? '' );
			}
		}

		return null;
	}

	/**
	 * Queue a product sync instead of running it inline.
	 *
	 * Product saves and checkout both used to make blocking API calls on the
	 * request thread; Action Scheduler moves them to the background.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public static function queue_product_sync( int $product_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler unavailable (WooCommerce not fully loaded) — fall
			// back to a direct call so the sync still happens.
			self::update_product_on_kurv( $product_id );
			return;
		}

		if ( as_has_scheduled_action( 'kurv_do_product_sync', [ 'product_id' => $product_id ], 'kurv' ) ) {
			return;
		}

		as_enqueue_async_action( 'kurv_do_product_sync', [ 'product_id' => $product_id ], 'kurv' );
	}

	/**
	 * Queue a customer sync for an order instead of running it inline.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function queue_customer_sync( int $order_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				self::update_customer_on_kurv( $order );
			}
			return;
		}

		if ( as_has_scheduled_action( 'kurv_do_customer_sync', [ 'order_id' => $order_id ], 'kurv' ) ) {
			return;
		}

		as_enqueue_async_action( 'kurv_do_customer_sync', [ 'order_id' => $order_id ], 'kurv' );
	}

	/**
	 * Create or update a product in Kurv when it is saved in WooCommerce.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public static function update_product_on_kurv( int $product_id ): void {
		self::ensure_api_credentials();

		$product = wc_get_product( $product_id );

		// This now runs asynchronously, so the product may have been deleted
		// between the save that queued the sync and this job running.
		if ( ! $product ) {
			return;
		}

		$product_image = wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'single-post-thumbnail' );
		$category_id   = self::check_and_create_product_category( $product_id );

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
		self::ensure_api_credentials();

		$check = Kurv_API::customers( $order->get_billing_email() );

		if ( is_wp_error( $check ) || 200 !== $check['response']['code'] || 'success' !== $check['body']['result'] ) {
			return null;
		}

		$country_code_phone    = self::get_country_code( $order->get_billing_country() );
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
	 * Static because it is a pure lookup. It previously was not, which forced
	 * update_customer_on_kurv() to build a throwaway gateway instance on every
	 * checkout — and each instance re-ran the constructor, registering a second
	 * copy of every hook (including the refund handlers).
	 *
	 * @param string $country_code Two-letter country code (e.g. 'US').
	 * @return string|null Prefix including leading '+', or null if not found.
	 */
	public static function get_country_code( string $country_code ): ?string {
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
