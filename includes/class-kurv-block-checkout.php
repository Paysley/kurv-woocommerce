<?php

declare(strict_types=1);

/**
 * WC_Kurv_Blocks — WooCommerce Blocks payment method integration.
 *
 * @package Kurv
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Kurv as a payment method for the WooCommerce Cart & Checkout blocks.
 *
 * The $name property must exactly match the gateway's $this->id ('kurv') so that
 * WooCommerce Blocks can match this integration to the classic gateway.
 *
 * @since 1.0.0
 */
final class WC_Kurv_Blocks extends AbstractPaymentMethodType {

	/**
	 * Cached gateway instance used to check availability and read settings.
	 *
	 * @var WC_Kurv
	 */
	private WC_Kurv $gateway;

	/**
	 * Must exactly match the gateway ID declared in WC_Kurv::$id.
	 *
	 * @var string
	 */
	protected $name = 'kurv';

	/**
	 * Load settings and instantiate the gateway for availability checks.
	 */
	public function initialize(): void {
		$this->settings         = get_option( 'woocommerce_kurv_settings', [] );
		$this->gateway          = new WC_Kurv();
		$this->settings['icon'] = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/kurv-logo.svg';
	}

	/**
	 * Whether the Kurv gateway is available for the current cart/customer.
	 */
	public function is_active(): bool {
		return $this->gateway->is_available();
	}

	/**
	 * Register and enqueue the blocks checkout script, passing settings to JS.
	 *
	 * @return array<int,string> Script handles.
	 */
	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'wc-kurv-blocks-integration',
			plugin_dir_url( __FILE__ ) . 'block/checkout.js',
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			KURV_PLUGIN_VERSION,
			true
		);

		wp_enqueue_script( 'wc-kurv-blocks-integration' );
		wp_localize_script( 'wc-kurv-blocks-integration', 'kurv_settings', $this->settings );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'wc-kurv-blocks-integration',
				'kurv-payments-for-woocommerce',
				plugin_dir_path( dirname( __FILE__ ) ) . 'languages/'
			);
		}

		return [ 'wc-kurv-blocks-integration' ];
	}

	/**
	 * Return data passed to the JS payment method registration.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data(): array {
		return [
			'title'       => $this->gateway->title,
			'description' => $this->gateway->description,
			'icon'        => $this->settings['icon'] ?? '',
			'supports'    => $this->get_supported_features(),
		];
	}
}
