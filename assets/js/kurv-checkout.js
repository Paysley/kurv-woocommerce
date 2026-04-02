/* global kurv_checkout_params, jQuery */

/**
 * Kurv Checkout Overlay
 */
( function () {
	'use strict';

	var config = window.kurv_checkout_params || {};

	/* ------------------------------------------------------------------
	   DOM helpers
	------------------------------------------------------------------ */

	function getOverlay() {
		return document.getElementById( 'kurv-payment-overlay' );
	}

	function buildOverlay() {
		var overlay = document.createElement( 'div' );
		overlay.id  = 'kurv-payment-overlay';

		overlay.innerHTML =
			'<div class="kurv-overlay-card">' +
				'<img src="' + escAttr( config.logoUrl ) + '" alt="Kurv" class="kurv-overlay-logo" />' +
				'<div class="kurv-spinner">' +
					'<svg viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg">' +
						'<circle cx="25" cy="25" r="20" fill="none" stroke-width="4" />' +
					'</svg>' +
				'</div>' +
				'<p class="kurv-overlay-text">' + escHtml( config.preparingText ) + '</p>' +
			'</div>';

		document.body.appendChild( overlay );
		return overlay;
	}

	function escAttr( str ) {
		return String( str || '' )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function escHtml( str ) {
		return String( str || '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	/* ------------------------------------------------------------------
	   Overlay state
	------------------------------------------------------------------ */

	function showOverlay() {
		var overlay = getOverlay() || buildOverlay();
		var text    = overlay.querySelector( '.kurv-overlay-text' );

		overlay.classList.remove( 'kurv-overlay-error' );
		if ( text ) {
			text.textContent = config.preparingText || 'Preparing your secure payment\u2026'; // \u2026 = …
		}
		overlay.classList.add( 'kurv-overlay-visible' );
	}

	function hideOverlay() {
		var overlay = getOverlay();
		if ( overlay ) {
			overlay.classList.remove( 'kurv-overlay-visible' );
		}
	}

	function showError( message ) {
		var overlay = getOverlay();
		if ( ! overlay ) {
			return;
		}
		var text = overlay.querySelector( '.kurv-overlay-text' );
		overlay.classList.add( 'kurv-overlay-error' );
		if ( text ) {
			text.textContent = message || config.errorText || 'Something went wrong. Please try again.';
		}
		setTimeout( hideOverlay, 3500 );
	}

	/* ------------------------------------------------------------------
	   Init
	------------------------------------------------------------------ */

	if ( typeof jQuery !== 'undefined' ) {
		jQuery( function ( $ ) {

			// Only run on the checkout page.
			if ( ! $( 'form.woocommerce-checkout' ).length ) {
				return;
			}

			buildOverlay();

			// Direct form submit — stripped back to basics.
			$( 'form.woocommerce-checkout' ).on( 'submit', function () {
				if ( $( 'input[name="payment_method"]:checked' ).val() === 'kurv' ) {
					showOverlay();
				}
			} );

			// Hide overlay and surface error if WooCommerce reports a checkout failure.
			$( document.body ).on( 'checkout_error', function () {
				var $notice = $( '.woocommerce-error li' ).first();
				var message = $notice.length ? $notice.text().trim() : ( config.errorText || '' );
				showError( message );
			} );

		} );
	}

} )();
