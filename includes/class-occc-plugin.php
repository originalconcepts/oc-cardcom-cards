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
	 * @return OCCC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot components.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		OCCC_Settings::init();
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
