<?php
/**
 * Token rescue + saved-card storage.
 *
 * Under Cardcom's Operation 6 (Capture Charge / J5) their plugin mints a token but
 * only turns it into a customer WC_Payment_Token when the response Operation is 2/3
 * — so under J5 the saved card is never created. This class fills exactly that gap:
 * it listens on Cardcom's own action `cardcom_IsLowProfileCodeDealOneOK`, reads the
 * token straight from their response array, and saves it as a WC_Payment_Token_CC
 * under THEIR gateway id. From then on Cardcom's engine displays and J5-charges it.
 *
 * We store ONLY: token, last4, expiry and brand. Never a PAN, never a CVV.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Tokens {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Primary capture point: Cardcom's own post-transaction action.
		add_action( 'cardcom_IsLowProfileCodeDealOneOK', array( __CLASS__, 'rescue_from_response' ), 20, 3 );

		// Fallbacks, in case the action above didn't fire (older flows / direct API):
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'rescue_from_order_id' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_change' ), 20, 4 );
	}

	/**
	 * The Cardcom gateway id our saved cards belong to (so Cardcom's own machinery
	 * renders and charges them). Filterable in case the id ever differs.
	 *
	 * @return string
	 */
	public static function gateway_id() {
		return apply_filters( 'occc_cardcom_gateway_id', OCCC_Settings::get( 'gateway_id' ) );
	}

	/**
	 * The Cardcom terminal currently configured in Cardcom's own gateway settings.
	 * Tokens are terminal-bound, so we tag each with this and only reuse matching ones.
	 *
	 * @return string
	 */
	public static function current_terminal() {
		$settings = get_option( 'woocommerce_' . self::gateway_id() . '_settings' );
		return ( is_array( $settings ) && isset( $settings['terminalnumber'] ) ) ? (string) $settings['terminalnumber'] : '';
	}

	/**
	 * The user's saved Cardcom cards that belong to the CURRENT terminal (so a token
	 * minted on a different/old terminal is never offered — it would be declined).
	 *
	 * @param int $user_id User id.
	 * @return WC_Payment_Token[]
	 */
	public static function tokens_for_terminal( $user_id ) {
		$terminal = self::current_terminal();
		$out      = array();
		$seen     = array();
		foreach ( WC_Payment_Tokens::get_customer_tokens( (int) $user_id, self::gateway_id() ) as $token ) {
			$token_terminal = (string) $token->get_meta( 'occc_terminal' );
			// Terminal guard: only hide a token whose terminal is explicitly a DIFFERENT one.
			if ( ! ( '' === $terminal || '' === $token_terminal || $token_terminal === $terminal ) ) {
				continue;
			}

			$last4 = method_exists( $token, 'get_last4' ) ? (string) $token->get_last4() : '';
			$month = method_exists( $token, 'get_expiry_month' ) ? (string) $token->get_expiry_month() : '';
			$year  = method_exists( $token, 'get_expiry_year' ) ? (string) $token->get_expiry_year() : '';

			// Skip junk/placeholder tokens that carry no real card number.
			if ( '' === $last4 || '0000' === $last4 ) {
				continue;
			}

			// De-duplicate by card identity (same card re-tokenised across purchases
			// would otherwise appear many times) — keep the first (most recent).
			$key = $last4 . '|' . $month . '|' . $year;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$out[] = $token;
		}
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * Capture entry points
	 * --------------------------------------------------------------------- */

	/**
	 * Rescue straight from Cardcom's response array (best source: it carries the
	 * token + masked card + expiry + brand exactly as Cardcom returned them).
	 *
	 * @param string $returnvalue   Cardcom deal status ('0' == OK).
	 * @param array  $response      Cardcom response array.
	 * @param int    $order_id      Order id.
	 * @return void
	 */
	public static function rescue_from_response( $returnvalue, $response, $order_id ) {
		if ( (string) $returnvalue !== '0' || empty( $response ) || ! is_array( $response ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::should_save( $order ) ) {
			return;
		}

		$data = array(
			'token'  => isset( $response['Token'] ) ? (string) $response['Token'] : '',
			'last4'  => isset( $response['ExtShvaParams_CardNumber5'] ) ? (string) $response['ExtShvaParams_CardNumber5'] : '',
			'tokef'  => isset( $response['ExtShvaParams_Tokef30'] ) ? (string) $response['ExtShvaParams_Tokef30'] : '',
			'brand'  => isset( $response['ExtShvaParams_Mutag24'] ) ? (string) $response['ExtShvaParams_Mutag24'] : '',
		);
		self::save( $order, $data );
	}

	/**
	 * Fallback: rebuild from the order meta Cardcom stores under J5.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function rescue_from_order_id( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::should_save( $order ) ) {
			return;
		}
		$tokef = (string) $order->get_meta( 'cardcom_Tokef' );   // MMYY, computed by Cardcom.
		if ( '' === $tokef ) {
			$tokef = (string) $order->get_meta( 'cc_Tokef' );    // Raw ExtShvaParams_Tokef30.
		}
		self::save(
			$order,
			array(
				'token' => (string) $order->get_meta( 'cardcom_token_val' ),
				'last4' => (string) $order->get_meta( 'cc_number' ),
				'tokef' => $tokef,
				'brand' => '',
			)
		);
	}

	/**
	 * Fallback on status transitions (J5 authorisations often land on-hold/processing).
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order.
	 * @return void
	 */
	public static function on_status_change( $order_id, $from, $to, $order = null ) {
		if ( in_array( $to, array( 'processing', 'on-hold', 'completed' ), true ) ) {
			self::rescue_from_order_id( $order_id );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Core
	 * --------------------------------------------------------------------- */

	/**
	 * Whether this order opted in to saving and can be saved.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function should_save( $order ) {
		if ( ! OCCC_Settings::get_bool( 'enabled' ) ) {
			return false;
		}
		if ( 'yes' !== $order->get_meta( '_occc_remember' ) ) {
			return false;
		}
		return (int) $order->get_user_id() > 0;
	}

	/**
	 * Save a rescued token as a WC_Payment_Token_CC (idempotent).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  { token, last4, tokef(MMYY), brand }.
	 * @return void
	 */
	private static function save( $order, $data ) {
		$user_id = (int) $order->get_user_id();
		$raw     = trim( (string) $data['token'] );
		if ( '' === $raw || $user_id <= 0 ) {
			return;
		}

		$gateway = self::gateway_id();

		$last4 = self::last4( $data['last4'] );
		list( $month, $year ) = self::parse_expiry( $data['tokef'] );

		// Don't persist junk/placeholder tokens (no real card number).
		if ( '' === $last4 || '0000' === $last4 ) {
			return;
		}
		// Don't persist a token without a valid, future expiry — charging it with a
		// wrong expiry is exactly what the acquirer rejects (60000004). A later rescue
		// (status change, once Cardcom has written the expiry meta) will save it right.
		if ( '' === $month || '' === $year ) {
			return;
		}

		// Dedupe: skip if this user already has the same token value, OR the same
		// card (last4 + expiry) — a repeat purchase re-tokenises the same card and
		// must not pile up as a new saved card each time.
		$identity = $last4 . '|' . $month . '|' . $year;
		foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway ) as $existing ) {
			if ( hash_equals( $existing->get_token(), $raw ) ) {
				return;
			}
			$ex_key = ( method_exists( $existing, 'get_last4' ) ? (string) $existing->get_last4() : '' )
				. '|' . ( method_exists( $existing, 'get_expiry_month' ) ? (string) $existing->get_expiry_month() : '' )
				. '|' . ( method_exists( $existing, 'get_expiry_year' ) ? (string) $existing->get_expiry_year() : '' );
			if ( $ex_key === $identity ) {
				return;
			}
		}

		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway );
		$token->set_user_id( $user_id );
		$token->set_token( $raw );
		$token->set_last4( self::last4( $data['last4'] ) );
		$token->set_expiry_month( $month );
		$token->set_expiry_year( $year );
		$token->set_card_type( self::brand( $data['brand'] ) );
		$token->add_meta_data( 'occc_terminal', self::current_terminal(), true );

		if ( $token->save() ) {
			$order->add_payment_token( $token );
			$order->add_order_note( __( 'Card saved for future purchases (Cardcom token rescued under J5).', 'oc-cardcom-cards' ) );
			OCCC_Logger::log( 'token saved', array( 'order' => $order->get_id(), 'user' => $user_id, 'last4' => self::last4( $data['last4'] ) ) );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Parse a Cardcom MMYY expiry into [MM, YYYY].
	 *
	 * @param string $tokef MMYY.
	 * @return array [ month, year ]
	 */
	// Parse a Cardcom MMYY expiry into [MM, YYYY], or [ '', '' ] if missing, malformed
	// or already expired. NEVER guesses a default — a wrong expiry makes the acquirer
	// decline the later token charge (ResponseCode 60000004).
	private static function parse_expiry( $tokef ) {
		$tokef = preg_replace( '/\D/', '', (string) $tokef );
		if ( strlen( $tokef ) < 4 ) {
			return array( '', '' );
		}
		$month = substr( $tokef, 0, 2 );
		$year  = '20' . substr( $tokef, 2, 2 );
		$m     = (int) $month;
		if ( $m < 1 || $m > 12 || (int) ( $year . $month ) < (int) gmdate( 'Ym' ) ) {
			return array( '', '' );
		}
		return array( $month, $year );
	}

	/**
	 * Normalise the masked card number to 4 digits.
	 *
	 * @param string $value Masked/last digits from Cardcom.
	 * @return string
	 */
	private static function last4( $value ) {
		$value = preg_replace( '/\D/', '', (string) $value );
		return '' !== $value ? substr( $value, -4 ) : '0000';
	}

	/**
	 * Map Cardcom brand code (Mutag24) to a WC card type slug.
	 *
	 * @param string $code 0=other, 1=mastercard, 2=visa.
	 * @return string
	 */
	private static function brand( $code ) {
		switch ( (string) $code ) {
			case '1':
				return 'mastercard';
			case '2':
				return 'visa';
			default:
				return 'cardcom';
		}
	}
}
