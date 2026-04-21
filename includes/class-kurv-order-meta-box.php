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
		// Classic post-based order screen.
		add_action( 'add_meta_boxes', [ __CLASS__, 'register' ] );
		// HPOS order screen.
		add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'maybe_render_hpos' ] );
	}

	/**
	 * Register the meta box for the classic order edit screen.
	 */
	public static function register(): void {
		$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

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

		$payment_id  = $order->get_meta( '_kurv_payment_id', true );
		$short_url   = $order->get_meta( '_kurv_short_url', true );
		$qrcode_url  = $order->get_meta( '_kurv_qrcode_url', true );
		$refund_ref  = $order->get_meta( '_kurv_last_refund_ref', true );
		$result      = $order->get_meta( '_kurv_payment_result', true );
		$is_test     = 'yes' === get_option( 'woocommerce_kurv_settings' )['test_mode'] ?? false;

		$settings   = get_option( 'woocommerce_kurv_settings', [] );
		$is_test    = 'yes' === ( $settings['test_mode'] ?? 'no' );
		?>
		<style>
			.kurv-meta-box { font-size: 13px; }
			.kurv-meta-box table { width: 100%; border-collapse: collapse; }
			.kurv-meta-box td { padding: 5px 0; vertical-align: top; }
			.kurv-meta-box td:first-child { color: #666; width: 42%; }
			.kurv-meta-box .kurv-badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
			.kurv-meta-box .kurv-badge-success { background: #d1fae5; color: #065f46; }
			.kurv-meta-box .kurv-badge-failed  { background: #fee2e2; color: #991b1b; }
			.kurv-meta-box .kurv-badge-test    { background: #fef3c7; color: #92400e; }
			.kurv-meta-box .kurv-mono { font-family: monospace; font-size: 11px; word-break: break-all; }
		</style>
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

	/**
	 * Fallback render for HPOS if the meta box registration fails.
	 * Not used on standard installs — the meta box registration handles it.
	 */
	public static function maybe_render_hpos( \WC_Order $order ): void {
		// Intentionally empty — HPOS uses the same add_meta_box registration above.
	}
}
