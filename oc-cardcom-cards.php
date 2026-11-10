<?php
/**
 * Plugin Name:       OC Cardcom Cards
 * Plugin URI:        https://ocpluginz.com/oc-cardcom-cards
 * Description:        Companion for the Cardcom payment gateway: adds a "remember my card" toggle at checkout and rescues the token Cardcom mints under J5 (Capture Charge) so it becomes a real saved card for the customer — the step Cardcom's own plugin skips under Operation 6. Rides on Cardcom's engine; never replaces it. Charging, J5 capture and invoicing stay with Cardcom's plugin.
 * Version:           0.6.0
 * Author:            Original Concepts
 * Author URI:        https://originalconcepts.co.il/
 * Text Domain:       oc-cardcom-cards
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * License:           GPL-2.0-or-later
 * Update URI:        https://my.ocpluginz.com/oc-cardcom-cards
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The suite's shared admin chrome (one "OC Pluginz" menu for every OC plugin on the site); the newest bundled copy wins.
require_once __DIR__ . '/oc-pluginz/loader.php';

define( 'OCCC_VERSION', '0.6.0' );
define( 'OCCC_PLUGIN_FILE', __FILE__ );
define( 'OCCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OCCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OCCC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce HPOS. (We store the token via the native
 * WC_Payment_Token API, so no custom order tables are touched directly.)
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OCCC_PLUGIN_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', 'occc_bootstrap', 20 );

/**
 * Boot the plugin. The licence, the updates and the admin screens load with or
 * without WooCommerce; the checkout parts only once WooCommerce is present.
 *
 * @return void
 */
function occc_bootstrap() {
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-install.php';
	require_once OCCC_PLUGIN_DIR . 'includes/hub/class-occc-hub.php';
	require_once OCCC_PLUGIN_DIR . 'includes/hub/class-occc-notices.php';
	require_once OCCC_PLUGIN_DIR . 'includes/hub/class-occc-hub-updater.php';
	require_once OCCC_PLUGIN_DIR . 'includes/hub/class-occc-hub-admin.php';
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-logger.php';
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-settings.php';
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-plugin.php';

	// First run of this version: record it, and settle once whether this site ran a version below 0.6.0.
	OCCC_Install::maybe_upgrade();

	$woocommerce = class_exists( 'WooCommerce' );
	if ( $woocommerce ) {
		require_once OCCC_PLUGIN_DIR . 'includes/class-occc-tokens.php';
		require_once OCCC_PLUGIN_DIR . 'includes/class-occc-checkout.php';
		require_once OCCC_PLUGIN_DIR . 'includes/class-occc-methods.php';
	} else {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'OC Cardcom Cards requires WooCommerce to be installed and active.', 'oc-cardcom-cards' );
				echo '</p></div>';
			}
		);
	}

	OCCC_Plugin::instance( $woocommerce );
}

register_activation_hook(
	__FILE__,
	function ( $network_wide = false ) {
		// Before the logger creates its folder: that folder is one of the traces of an older version.
		require_once OCCC_PLUGIN_DIR . 'includes/class-occc-install.php';
		OCCC_Install::activate( (bool) $network_wide );

		require_once OCCC_PLUGIN_DIR . 'includes/class-occc-logger.php';
		OCCC_Logger::install();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'occc_hub_refresh' );
	}
);
