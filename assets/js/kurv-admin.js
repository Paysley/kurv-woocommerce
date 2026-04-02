/* global jQuery */
/**
 * Kurv Admin Settings JS
 *
 * - Auto-detects test/live mode from the API key prefix (kp_test_ / kp_live_)
 *   and toggles the Test Mode checkbox accordingly.
 * - Warns if the entered key prefix does not match the current mode selection.
 */
( function ( $ ) {
	'use strict';

	var $testMode    = $( '#woocommerce_kurv_test_mode' );
	var $liveKey     = $( '#woocommerce_kurv_live_access_key' );
	var $testKey     = $( '#woocommerce_kurv_test_access_key' );

	if ( ! $testMode.length ) {
		return; // Not on the Kurv settings page.
	}

	/**
	 * Read a key field and auto-set the Test Mode checkbox if the prefix is clear.
	 */
	function detectModeFromKeys() {
		var liveVal = $liveKey.val() || '';
		var testVal = $testKey.val() || '';

		// Only auto-detect when the actively-edited field has a recognisable prefix.
		if ( liveVal.indexOf( 'kp_live_' ) === 0 ) {
			$testMode.prop( 'checked', false );
			showHint( 'live' );
		} else if ( testVal.indexOf( 'kp_test_' ) === 0 ) {
			$testMode.prop( 'checked', true );
			showHint( 'test' );
		}
	}

	/**
	 * Show an inline hint next to the Test Mode checkbox.
	 */
	function showHint( mode ) {
		$( '.kurv-mode-hint' ).remove();
		var text  = mode === 'test'
			? '✓ Test key detected — Test Mode enabled automatically.'
			: '✓ Live key detected — Test Mode disabled automatically.';
		var color = mode === 'test' ? '#b45309' : '#15803d';
		$testMode.closest( 'fieldset' ).append(
			$( '<span class="kurv-mode-hint"></span>' )
				.text( text )
				.css( { display: 'block', marginTop: '6px', fontSize: '13px', color: color } )
		);
	}

	// Watch both key fields for input.
	$liveKey.on( 'input', detectModeFromKeys );
	$testKey.on( 'input', detectModeFromKeys );

	// Run once on load in case fields are already populated (e.g. after a save).
	detectModeFromKeys();

} )( jQuery );
