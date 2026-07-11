/* OC Cardcom Cards — checkout behaviour
 *
 * 1. Hide Cardcom's native "save payment method" checkbox (our toggle replaces it).
 * 2. Hide our toggle when the shopper picks an existing saved card in Cardcom's own
 *    nested list — nothing to save then. In promoted mode the saved cards are separate
 *    top-level methods, so whenever Cardcom's box is open it means "new card": keep
 *    the toggle shown (its hidden nested radios must NOT hide it).
 */
( function ( $ ) {
	'use strict';

	var gateway = ( window.OCCC && window.OCCC.gateway ) ? window.OCCC.gateway : 'cardcom';
	var tokenName = 'wc-' + gateway + '-payment-token';
	var nativeSaveId = 'wc-' + gateway + '-new-payment-method';

	function sync() {
		var $native = $( '#' + nativeSaveId ).closest( 'p, .form-row, .woocommerce-SavedPaymentMethods-saveNew' );
		if ( $native.length ) {
			$native.addClass( 'occc-hide-native-save' );
			$( '#' + nativeSaveId ).prop( 'checked', false );
		}

		var $toggle = $( '.occc-save-card' );
		if ( ! $toggle.length ) {
			return;
		}

		// In promoted mode the saved cards live outside Cardcom's box, so an open
		// Cardcom box always means a new card — always show the toggle.
		var promoting = $( 'body' ).hasClass( 'occc-promote-cards' );
		var $selected = $( 'input[name="' + tokenName + '"]:checked' );
		var usingNew = promoting || ! $selected.length || 'new' === $selected.val();

		$toggle.toggle( usingNew );
		if ( ! usingNew ) {
			$toggle.find( '.occc-toggle__input' ).prop( 'checked', false );
		}
	}

	$( document.body ).on( 'change', 'input[name="' + tokenName + '"]', sync );
	$( document.body ).on( 'updated_checkout payment_method_selected', sync );
	$( sync );
} )( jQuery );
