<?php
/**
 * Main plugin loader (singleton).
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var OCCC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @param bool $woocommerce Whether WooCommerce is active (the checkout parts need it).
	 * @return OCCC_Plugin
	 */
	public static function instance( $woocommerce = true ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $woocommerce );
		}
		return self::$instance;
	}

	/**
	 * Boot components.
	 *
	 * @param bool $woocommerce Whether WooCommerce is active.
	 */
	private function __construct( $woocommerce ) {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// The OC Pluginz licence, updates from the hub, and the admin screens.
		OCCC_Hub::boot();
		OCCC_Notices::boot();
		if ( OCCC_Hub::github_updates() ) {
			OCCC_Updater::init(); // An older install or an agency site, with no key: GitHub tags, as before 0.6.0.
		} else {
			OCCC_Hub_Updater::boot();
		}
		OCCC_Hub_Admin::boot();
		OCCC_Settings::init();

		if ( ! $woocommerce ) {
			return;
		}
		OCCC_Tokens::init();
		OCCC_Checkout::init();
		OCCC_Methods::init();
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'oc-cardcom-cards', false, dirname( OCCC_PLUGIN_BASENAME ) . '/languages' );
	}
}
