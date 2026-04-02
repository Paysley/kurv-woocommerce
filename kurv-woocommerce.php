<?php

declare(strict_types=1);

/**
 * Plugin Name:          Kurv Payments for WooCommerce
 * Plugin URI:           https://github.com/kurv/kurv-woocommerce
 * Description:          Accept payments through Kurv.
 * Version:              1.0.0
 * Author:               Kurv
 * Author URI:           https://kurv.com
 * License:              GPL v3 or later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          kurv-woocommerce
 * Domain Path:          /languages
 * Requires at least:    6.0
 * Requires PHP:         8.1
 * WC requires at least: 8.0
 * WC tested up to:      9.4
 *
 * @package Kurv
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KURV_PLUGIN_VERSION', '1.0.0' );
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
 */
function kurv_uninstall_plugin(): void {
	delete_option( 'kurv_plugin_version' );
	delete_option( 'woocommerce_kurv_settings' );
}

/**
 * Initialise the plugin after WooCommerce is loaded.
 */
function kurv_init(): void {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	load_plugin_textdomain( 'kurv-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-kurv.php';

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
		esc_html__( '⚠️ Kurv Payments is in Test Mode. No real payments are being processed.', 'kurv-woocommerce' ),
		esc_url( $settings_url ),
		esc_html__( 'Manage settings', 'kurv-woocommerce' )
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
	$methods[] = 'WC_Kurv';
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
		'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'kurv-woocommerce' ) . '</a>',
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
 * Sync a product to Kurv whenever it is created or updated.
 */
add_action( 'woocommerce_new_product', 'kurv_sync_product', 10, 1 );
add_action( 'woocommerce_update_product', 'kurv_sync_product', 10, 1 );
function kurv_sync_product( int $product_id ): void {
	WC_Kurv::update_product_on_kurv( $product_id );
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

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-kurv-block-checkout.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
			$registry->register( new WC_Kurv_Blocks() );
		}
	);
}
