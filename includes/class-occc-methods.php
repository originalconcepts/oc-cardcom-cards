<?php
/**
 * Saved cards as top-level payment methods (optional).
 *
 * When enabled, each of the customer's saved Cardcom cards is presented as its own
 * payment method in the checkout list (same level as "Cash" etc.), instead of a
 * sub-option nested under Cardcom. The Cardcom method itself becomes "New credit
 * card". Selecting a saved card routes the charge straight back through Cardcom's
 * own engine (its J5 token charge + invoicing) — we don't charge anything ourselves.
 *
 * This is gated behind the `promote_saved_cards` setting so the default (native
 * nested) behaviour is untouched.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A lightweight gateway representing one saved Cardcom card.
 */
class OCCC_Token_Gateway extends WC_Payment_Gateway {

	/**
	 * The underlying WC payment token.
	 *
	 * @var WC_Payment_Token|null
	 */
	private $wc_token;

	/**
	 * @param WC_Payment_Token|null $token Saved token.
	 */
	public function __construct( $token = null ) {
		$this->wc_token   = $token;
		$this->id         = 'occc_saved_' . ( $token ? $token->get_id() : '0' );
		$this->has_fields = false;
		$this->supports   = array( 'products' );
		$this->enabled    = 'yes';

		if ( $token ) {
			$this->title = sprintf(
				/* translators: 1: last 4 digits, 2: MM/YY expiry */
				__( 'Credit card ending in %1$s (exp %2$s)', 'oc-cardcom-cards' ),
				$token->get_last4(),
				str_pad( $token->get_expiry_month(), 2, '0', STR_PAD_LEFT ) . '/' . substr( $token->get_expiry_year(), -2 )
			);
		}
		$this->method_title = $this->title;
	}

	/**
	 * Available only while its token still exists and the feature is on.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->wc_token && OCCC_Settings::get_bool( 'enabled' ) && OCCC_Settings::get_bool( 'promote_saved_cards' );
	}

	/**
	 * Route the charge through Cardcom's own gateway using this saved token.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$cardcom = OCCC_Methods::cardcom_gateway();

		if ( ! $order || ! $cardcom || ! $this->wc_token || (int) $this->wc_token->get_user_id() !== get_current_user_id() ) {
			wc_add_notice( __( 'That saved card is unavailable. Please choose another method.', 'oc-cardcom-cards' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Make Cardcom's gateway see this as "pay with saved token", then delegate.
		$_POST[ 'wc-' . $cardcom->id . '-payment-token' ] = (string) $this->wc_token->get_id();
		$_POST['payment_method']                          = $cardcom->id;

		$order->set_payment_method( $cardcom );
		$order->save();

		return $cardcom->process_payment( $order_id );
	}
}

/**
 * Registers the per-token gateways and reshapes the Cardcom method accordingly.
 */
class OCCC_Methods {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'register' ), 30 );
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'relabel_new_card' ), 20, 2 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		// Cardcom's "Add payment method" flow (from My Account) is broken — disable it.
		add_filter( 'woocommerce_payment_gateway_supports', array( __CLASS__, 'disable_add_method' ), 20, 3 );
	}

	/**
	 * Turn off the Cardcom gateway's "add_payment_method" support so WooCommerce
	 * doesn't offer the (broken) Add-payment-method button in the account area.
	 * Saved cards are still created from checkout — this only affects account add.
	 *
	 * @param bool               $supports Whether the gateway supports the feature.
	 * @param string             $feature  Feature being checked.
	 * @param WC_Payment_Gateway $gateway  Gateway instance.
	 * @return bool
	 */
	public static function disable_add_method( $supports, $feature, $gateway ) {
		if ( 'add_payment_method' === $feature && isset( $gateway->id ) && $gateway->id === OCCC_Tokens::gateway_id() ) {
			return false;
		}
		return $supports;
	}

	/**
	 * Whether the feature is on and the current user actually has saved Cardcom cards.
	 *
	 * @return WC_Payment_Token[] The user's saved Cardcom tokens (empty array if none/off).
	 */
	private static function user_tokens() {
		if ( ! OCCC_Settings::get_bool( 'enabled' ) || ! OCCC_Settings::get_bool( 'promote_saved_cards' ) || ! is_user_logged_in() ) {
			return array();
		}
		// Only cards minted on the CURRENT terminal — cross-terminal tokens are declined.
		return OCCC_Tokens::tokens_for_terminal( get_current_user_id() );
	}

	/**
	 * True when the promoted top-level saved cards are actually being shown to this
	 * user (feature on + they have at least one usable saved card).
	 *
	 * @return bool
	 */
	public static function is_promoting_for_user() {
		return ! empty( self::user_tokens() );
	}

	/**
	 * The live Cardcom gateway instance.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	public static function cardcom_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$all = WC()->payment_gateways()->payment_gateways();
		$gid = OCCC_Tokens::gateway_id();
		return isset( $all[ $gid ] ) ? $all[ $gid ] : null;
	}

	/**
	 * Inject a top-level gateway per saved card, ordered before the rest.
	 *
	 * @param array $gateways Available gateways.
	 * @return array
	 */
	public static function register( $gateways ) {
		$gid = OCCC_Tokens::gateway_id();
		if ( ! isset( $gateways[ $gid ] ) ) {
			return $gateways; // Cardcom not available on this checkout.
		}
		$tokens = self::user_tokens();
		if ( empty( $tokens ) ) {
			return $gateways;
		}

		$saved = array();
		foreach ( $tokens as $token ) {
			$g = new OCCC_Token_Gateway( $token );
			$saved[ $g->id ] = $g;
		}

		// Saved cards first, then everything else (Cardcom now reads as "New credit card").
		return $saved + $gateways;
	}

	/**
	 * Relabel the Cardcom method to "New credit card" once the saved cards are promoted.
	 *
	 * @param string $title Title.
	 * @param string $id    Gateway id.
	 * @return string
	 */
	public static function relabel_new_card( $title, $id ) {
		if ( $id === OCCC_Tokens::gateway_id() && ! empty( self::user_tokens() ) ) {
			return __( 'New credit card', 'oc-cardcom-cards' );
		}
		return $title;
	}

	/**
	 * Add a body class so CSS can hide Cardcom's nested saved-cards list while promoting.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function body_class( $classes ) {
		if ( ! empty( self::user_tokens() ) ) {
			$classes[] = 'occc-promote-cards';
		}
		return $classes;
	}
}
