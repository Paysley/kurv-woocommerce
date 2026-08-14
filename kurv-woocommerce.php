<?php

declare(strict_types=1);

/**
 * Plugin Name:          Kurv Payments for WooCommerce
 * Plugin URI:           https://github.com/Paysley/kurv-woocommerce
 * Description:          Accept payments through Kurv.
 * Version:              1.0.6
 * Author:               Kurv
 * Author URI:           https://kurv.com
 * License:              GPL v3 or later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          kurv-payments-for-woocommerce
 * Domain Path:          /languages
 * Requires at least:    6.0
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.0
 *
 * @package Kurv
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KURV_PLUGIN_VERSION', '1.0.6' );
define( 'KURV_PLUGIN_FILE', __FILE__ );

register_activation_hook( __FILE__, 'kurv_activate_plugin' );
register_uninstall_hook( __FILE__, 'kurv_uninstall_plugin' );

/**
 * Runs on plugin activation.
 */
function kurv_activate_plugin(): void {
	$version = get_option( 'kurv_plugin_version' );
	if ( ! $version ) {
		add_option( 'kurv_plugin_version', KURV_PLUGIN_VERSION );
	} else {
		update_option( 'kurv_plugin_version', KURV_PLUGIN_VERSION );
	}
}

/**
 * Runs on plugin deletion.
 *
 * Order meta is deliberately left in place: it is part of the merchant's
 * financial record and must survive an uninstall.
 */
function kurv_uninstall_plugin(): void {
	delete_option( 'kurv_plugin_version' );
	delete_option( 'woocommerce_kurv_settings' );

	// Drop queued background work so it cannot fire against a removed plugin.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', [], 'kurv' );
	}
}

/**
 * Initialise the plugin after WooCommerce is loaded.
 */
function kurv_init(): void {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	// Translations load automatically for WordPress.org-hosted plugins whose text
	// domain matches their slug, so no load_plugin_textdomain() call is needed.
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-kurv-payments-gateway.php';

	// Admin-only includes.
	if ( is_admin() ) {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-kurv-order-meta-box.php';
		Kurv_Order_Meta_Box::init();
	}
}
add_action( 'plugins_loaded', 'kurv_init', 0 );

/**
 * Show a persistent admin notice when Kurv is in test mode.
 */
function kurv_test_mode_notice(): void {
	$settings = get_option( 'woocommerce_kurv_settings', [] );

	if ( ( $settings['enabled'] ?? 'no' ) !== 'yes' ) {
		return;
	}
	if ( ( $settings['test_mode'] ?? 'no' ) !== 'yes' ) {
		return;
	}

	$settings_url = add_query_arg(
		[ 'page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'kurv' ],
		admin_url( 'admin.php' )
	);

	printf(
		'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( '⚠️ Kurv Payments is in Test Mode. No real payments are being processed.', 'kurv-payments-for-woocommerce' ),
		esc_url( $settings_url ),
		esc_html__( 'Manage settings', 'kurv-payments-for-woocommerce' )
	);
}
add_action( 'admin_notices', 'kurv_test_mode_notice' );

/**
 * Register the Kurv gateway with WooCommerce.
 *
 * @param array<int,string> $methods Registered payment gateway class names.
 * @return array<int,string>
 */
function kurv_add_gateway( array $methods ): array {
	$methods[] = 'Kurv_Payments_Gateway';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'kurv_add_gateway' );

/**
 * Add a Settings link to the plugin list table.
 *
 * @param array<string,string> $links Existing plugin action links.
 * @return array<string,string>
 */
function kurv_plugin_links( array $links ): array {
	$settings_url = add_query_arg(
		[
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'kurv',
		],
		admin_url( 'admin.php' )
	);

	$plugin_links = [
		'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'kurv-payments-for-woocommerce' ) . '</a>',
	];

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'kurv_plugin_links' );

/**
 * Register custom query vars used by the payment response handler.
 *
 * @param array<int,string> $vars Registered query vars.
 * @return array<int,string>
 */
function kurv_add_query_vars( array $vars ): array {
	$vars[] = 'response';
	$vars[] = 'kurv_token';
	return $vars;
}
add_filter( 'query_vars', 'kurv_add_query_vars' );

/**
 * Queue a Kurv product sync whenever a product is created or updated.
 *
 * Queued rather than run inline: the sync makes up to three blocking API calls,
 * which would otherwise stall every product save in wp-admin.
 */
add_action( 'woocommerce_new_product', 'kurv_sync_product', 10, 1 );
add_action( 'woocommerce_update_product', 'kurv_sync_product', 10, 1 );
function kurv_sync_product( int $product_id ): void {
	Kurv_Payments_Gateway::queue_product_sync( $product_id );
}

/**
 * Run a queued product sync (Action Scheduler callback).
 */
add_action( 'kurv_do_product_sync', 'kurv_run_product_sync', 10, 1 );
function kurv_run_product_sync( int $product_id ): void {
	Kurv_Payments_Gateway::update_product_on_kurv( $product_id );
}

/**
 * Run a queued customer sync (Action Scheduler callback).
 */
add_action( 'kurv_do_customer_sync', 'kurv_run_customer_sync', 10, 1 );
function kurv_run_customer_sync( int $order_id ): void {
	$order = wc_get_order( $order_id );

	if ( $order ) {
		Kurv_Payments_Gateway::update_customer_on_kurv( $order );
	}
}

/**
 * Return the live Kurv gateway instance from WooCommerce's registry.
 *
 * Uses the registry rather than `new` so the gateway's constructor — and the
 * hooks it registers — runs exactly once per request.
 */
function kurv_get_gateway(): ?Kurv_Payments_Gateway {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}

	$gateway = WC()->payment_gateways()->payment_gateways()['kurv'] ?? null;

	return $gateway instanceof Kurv_Payments_Gateway ? $gateway : null;
}

/**
 * Reconcile an order against the Kurv API (Action Scheduler callback).
 *
 * Bound at file scope because Action Scheduler runs in background requests that
 * never load the payment gateways by themselves.
 */
add_action( 'kurv_reconcile_order', 'kurv_run_reconcile_order', 10, 1 );
function kurv_run_reconcile_order( int $order_id ): void {
	$gateway = kurv_get_gateway();

	if ( $gateway ) {
		$gateway->reconcile_order( $order_id );
	}
}

/**
 * Declare compatibility with WooCommerce Cart & Checkout blocks.
 */
function kurv_declare_blocks_compatibility(): void {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'kurv_declare_blocks_compatibility' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
function kurv_declare_hpos_compatibility(): void {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'kurv_declare_hpos_compatibility' );

/**
 * Register the Kurv payment method with WooCommerce Blocks.
 */
add_action( 'woocommerce_blocks_loaded', 'kurv_register_block_payment_method' );
function kurv_register_block_payment_method(): void {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-kurv-payments-blocks.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
			$registry->register( new Kurv_Payments_Blocks() );
		}
	);
}
