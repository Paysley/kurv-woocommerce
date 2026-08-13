<?php

declare(strict_types=1);

/**
 * Kurv Order Meta Box
 *
 * Displays Kurv payment details in a dedicated meta box on the WooCommerce
 * order edit screen (both legacy post-based and HPOS order screens).
 *
 * @package Kurv
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Kurv payment details meta box.
 *
 * @since 1.0.0
 */
class Kurv_Order_Meta_Box {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		// Registers on both the classic and HPOS order screens — see register().
		add_action( 'add_meta_boxes', [ __CLASS__, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
	}

	/**
	 * Enqueue the meta box stylesheet on order screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_styles( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}

		wp_enqueue_style(
			'kurv-admin',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/kurv-admin.css',
			[],
			KURV_PLUGIN_VERSION
		);
	}

	/**
	 * Register the meta box for the classic order edit screen.
	 */
	public static function register(): void {
		// OrderUtil is WooCommerce's public API for this; the CustomOrdersTableController
		// this used to reach for lives under \Internal\ and carries no compatibility promise.
		$hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$screen = $hpos_enabled ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box(
			'kurv-order-details',
			__( 'Kurv Payment', 'kurv-payments-for-woocommerce' ),
			[ __CLASS__, 'render' ],
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WC_Order|\WP_Post $order_or_post
	 */
	public static function render( $order_or_post ): void {
		$order = $order_or_post instanceof \WC_Order
			? $order_or_post
			: wc_get_order( $order_or_post->ID );

		if ( ! $order || 'kurv' !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'No Kurv payment data for this order.', 'kurv-payments-for-woocommerce' ) . '</p>';
			return;
		}

		$payment_id = $order->get_meta( '_kurv_payment_id', true );
		$short_url  = $order->get_meta( '_kurv_short_url', true );
		$qrcode_url = $order->get_meta( '_kurv_qrcode_url', true );
		$refund_ref = $order->get_meta( '_kurv_last_refund_ref', true );
		$result     = $order->get_meta( '_kurv_payment_result', true );

		$settings = get_option( 'woocommerce_kurv_settings', [] );
		$is_test  = 'yes' === ( $settings['test_mode'] ?? 'no' );
		?>
		<div class="kurv-meta-box">
			<table>
				<?php if ( $is_test ) : ?>
				<tr>
					<td colspan="2"><span class="kurv-badge kurv-badge-test"><?php esc_html_e( 'Test Mode', 'kurv-payments-for-woocommerce' ); ?></span></td>
				</tr>
				<?php endif; ?>

				<?php if ( $result ) : ?>
				<tr>
					<td><?php esc_html_e( 'Result', 'kurv-payments-for-woocommerce' ); ?></td>
					<td>
						<span class="kurv-badge <?php echo 'success' === $result ? 'kurv-badge-success' : 'kurv-badge-failed'; ?>">
							<?php echo esc_html( $result ); ?>
						</span>
					</td>
				</tr>
				<?php endif; ?>

				<?php if ( $payment_id ) : ?>
				<tr>
					<td><?php esc_html_e( 'Payment ID', 'kurv-payments-for-woocommerce' ); ?></td>
					<td><span class="kurv-mono"><?php echo esc_html( $payment_id ); ?></span></td>
				</tr>
				<?php endif; ?>

				<?php if ( $short_url ) : ?>
				<tr>
					<td><?php esc_html_e( 'Payment Link', 'kurv-payments-for-woocommerce' ); ?></td>
					<td><a href="<?php echo esc_url( $short_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $short_url ); ?></a></td>
				</tr>
				<?php endif; ?>

				<?php if ( $qrcode_url ) : ?>
				<tr>
					<td><?php esc_html_e( 'QR Code', 'kurv-payments-for-woocommerce' ); ?></td>
					<td><a href="<?php echo esc_url( $qrcode_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View QR Code', 'kurv-payments-for-woocommerce' ); ?></a></td>
				</tr>
				<?php endif; ?>

				<?php if ( $refund_ref ) : ?>
				<tr>
					<td><?php esc_html_e( 'Refund Ref', 'kurv-payments-for-woocommerce' ); ?></td>
					<td><span class="kurv-mono"><?php echo esc_html( $refund_ref ); ?></span></td>
				</tr>
				<?php endif; ?>

				<?php if ( ! $payment_id && ! $short_url ) : ?>
				<tr>
					<td colspan="2"><?php esc_html_e( 'Payment pending or not yet processed.', 'kurv-payments-for-woocommerce' ); ?></td>
				</tr>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}

}
