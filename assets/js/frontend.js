/* OC Cardcom Cards — checkout behaviour
 *
 * 1. Hide Cardcom's native "save payment method" checkbox (our toggle replaces it).
 * 2. Make sure the "remember my card" toggle is present: on themes that render the
 *    gateway payment box it comes from PHP (Cardcom's description); on themes that
 *    render only the method title (no payment box) we inject it into the Cardcom <li>.
 * 3. In promoted mode, pre-select the first SAVED card (not "New credit card") once
 *    on load — a returning customer should default to their saved card.
 * 4. Hide the toggle when the shopper picks an existing saved card.
 */
( function ( $ ) {
	'use strict';

	var gateway = ( window.OCCC && window.OCCC.gateway ) ? window.OCCC.gateway : 'cardcom';
	var tokenName = 'wc-' + gateway + '-payment-token';
	var nativeSaveId = 'wc-' + gateway + '-new-payment-method';
	var autoSelected = false;

	function ensureToggle() {
		if ( $( '.occc-save-card' ).length ) {
			return;
		}
		if ( ! window.OCCC || ! window.OCCC.toggleHtml ) {
			return;
		}
		var $li = $( 'li.payment_method_' + gateway ).first();
		if ( $li.length ) {
			$li.append( window.OCCC.toggleHtml );
		}
	}

	// Default a returning customer to their saved card (promoted top-level methods),
	// once — if the current selection is "New credit card" (or nothing).
	function autoSelectSaved() {
		if ( autoSelected ) {
			return;
		}
		var $saved = $( 'input[name="payment_method"][value^="occc_saved_"]' );
		if ( ! $saved.length ) {
			return;
		}
		var $checked = $( 'input[name="payment_method"]:checked' );
		if ( ! $checked.length || $checked.val() === gateway ) {
			autoSelected = true;
			$saved.first().prop( 'checked', true ).trigger( 'click' ).trigger( 'change' );
		} else {
			autoSelected = true; // The shopper already has a (different) choice — leave it.
		}
	}

	function sync() {
		ensureToggle();
		autoSelectSaved();

		var $native = $( '#' + nativeSaveId ).closest( 'p, .form-row, .woocommerce-SavedPaymentMethods-saveNew' );
		if ( $native.length ) {
			$native.addClass( 'occc-hide-native-save' );
			$( '#' + nativeSaveId ).prop( 'checked', false );
		}

		var $toggle = $( '.occc-save-card' );
		if ( ! $toggle.length ) {
			return;
		}

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
