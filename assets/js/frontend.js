/* OC Cardcom Cards — checkout behaviour
 *
 * 1. Hide Cardcom's native "save payment method" checkbox (our toggle replaces it).
 * 2. Make sure the "remember my card" toggle is present: on themes that render the
 *    gateway payment box it comes from PHP (Cardcom's description); on themes that
 *    render only the method title (no payment box) we inject it into the Cardcom <li>.
 * 3. In promoted mode, force Cardcom's own (now hidden) saved-cards radio list back
 *    to "new" — otherwise "New credit card" silently submits a hidden token.
 * 4. Hide the toggle when the shopper picks an existing saved card.
 */
( function ( $ ) {
	'use strict';

	var gateway = ( window.OCCC && window.OCCC.gateway ) ? window.OCCC.gateway : 'cardcom';
	var tokenName = 'wc-' + gateway + '-payment-token';
	var nativeSaveId = 'wc-' + gateway + '-new-payment-method';

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

	function sync() {
		ensureToggle();

		var promoting = $( 'body' ).hasClass( 'occc-promote-cards' );

		// In promoted mode Cardcom's own saved-cards radio list is hidden (saved cards
		// are surfaced as top-level payment methods instead). But a hidden token radio
		// stays "checked", so "New credit card" would silently submit that token — and
		// if the token was minted on another terminal Cardcom answers "token not found".
		// Force the hidden native selection back to "new" so New credit card is new.
		// (Selecting a saved card goes through our own gateway, which sets the token
		// server-side, so this never blocks the saved-card path.)
		if ( promoting ) {
			var $tokenRadios = $( 'input[name="' + tokenName + '"]' );
			var $newRadio = $tokenRadios.filter( '[value="new"]' );
			if ( $newRadio.length && ! $newRadio.prop( 'checked' ) ) {
				$tokenRadios.prop( 'checked', false );
				$newRadio.prop( 'checked', true ).trigger( 'change' );
			}
		}

		var $native = $( '#' + nativeSaveId ).closest( 'p, .form-row, .woocommerce-SavedPaymentMethods-saveNew' );
		if ( $native.length ) {
			$native.addClass( 'occc-hide-native-save' );
			$( '#' + nativeSaveId ).prop( 'checked', false );
		}

		var $toggle = $( '.occc-save-card' );
		if ( ! $toggle.length ) {
			return;
		}

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
