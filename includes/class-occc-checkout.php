<?php
/**
 * Checkout UX: the "remember my card" toggle under the Cardcom method.
 *
 * Cardcom's gateway prints its own description straight from $this->description
 * inside payment_fields(), so it never runs through the `woocommerce_gateway_description`
 * filter. To reliably land the toggle inside their payment box we append our markup
 * to the gateway object's ->description via `woocommerce_available_payment_gateways`.
 * JS then hides it when a saved card is chosen and hides Cardcom's native save box.
 *
 * On submit we persist the opt-in to the order; OCCC_Tokens saves the token after
 * Cardcom returns.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Checkout {

	public static function init() {
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'inject_toggle' ), 20 );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'save_optin' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Append our toggle to the Cardcom gateway's description so it renders inside
	 * that method's payment box.
	 *
	 * @param array $gateways Available gateways.
	 * @return array
	 */
	public static function inject_toggle( $gateways ) {
		if ( ! OCCC_Settings::get_bool( 'enabled' ) || ! is_user_logged_in() ) {
			return $gateways;
		}
		// The toggle also shows on "New credit card" in promoted mode, so a customer
		// can save an additional card.
		$gid = OCCC_Tokens::gateway_id();
		if ( isset( $gateways[ $gid ] ) && false === strpos( (string) $gateways[ $gid ]->description, 'occc-save-card' ) ) {
			$gateways[ $gid ]->description .= self::toggle_html( $gid );
		}
		return $gateways;
	}

	/**
	 * Build the toggle markup.
	 *
	 * @param string $gateway_id Gateway id.
	 * @return string
	 */
	private static function toggle_html( $gateway_id ) {
		$title   = OCCC_Settings::get( 'toggle_title' );
		$label   = OCCC_Settings::get( 'toggle_label' );
		$desc    = OCCC_Settings::get( 'toggle_description' );
		$checked = OCCC_Settings::get_bool( 'default_on' ) ? ' checked' : '';

		ob_start();
		?>
		<span class="occc-save-card" data-gateway="<?php echo esc_attr( $gateway_id ); ?>">
			<?php if ( $title ) : ?>
				<span class="occc-save-card__title"><?php echo esc_html( $title ); ?></span>
			<?php endif; ?>
			<label class="occc-toggle">
				<input type="checkbox" class="occc-toggle__input" name="occc_remember" value="1"<?php echo esc_attr( $checked ); ?> />
				<span class="occc-toggle__track"><span class="occc-toggle__thumb"></span></span>
				<?php if ( $label ) : ?>
					<span class="occc-toggle__label"><?php echo esc_html( $label ); ?></span>
				<?php endif; ?>
			</label>
			<?php if ( $desc ) : ?>
				<span class="occc-save-card__desc"><?php echo esc_html( $desc ); ?></span>
			<?php endif; ?>
		</span>
		<?php
		return ob_get_clean();
	}

	/**
	 * Persist the opt-in to the order so OCCC_Tokens can act on it after payment.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function save_optin( $order_id ) {
		if ( ! OCCC_Settings::get_bool( 'enabled' ) ) {
			return;
		}
		$field       = 'wc-' . OCCC_Tokens::gateway_id() . '-payment-token';
		$chose_saved = ! empty( $_POST[ $field ] ) && 'new' !== $_POST[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification
		$remember    = ! empty( $_POST['occc_remember'] ) && ! $chose_saved; // phpcs:ignore WordPress.Security.NonceVerification

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_occc_remember', $remember ? 'yes' : 'no' );
			$order->save();
		}
	}

	/**
	 * Enqueue the toggle styling + behaviour.
	 *
	 * @return void
	 */
	public static function assets() {
		if ( ! OCCC_Settings::get_bool( 'enabled' ) ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! ( is_checkout() || is_account_page() ) ) {
			return;
		}
		wp_enqueue_style( 'occc-frontend', OCCC_PLUGIN_URL . 'assets/css/frontend.css', array(), OCCC_VERSION );
		wp_enqueue_script( 'occc-frontend', OCCC_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), OCCC_VERSION, true );

		$data = array( 'gateway' => OCCC_Tokens::gateway_id() );
		// Provide the toggle markup so the JS can inject it on themes that don't render
		// the gateway payment box (where the PHP description injection never appears).
		if ( is_user_logged_in() ) {
			$data['toggleHtml'] = self::toggle_html( OCCC_Tokens::gateway_id() );
		}
		wp_localize_script( 'occc-frontend', 'OCCC', $data );
	}
}
