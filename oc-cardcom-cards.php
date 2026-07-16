<?php
/**
 * Plugin Name:       OC Cardcom Cards
 * Plugin URI:        https://originalconcepts.co.il/
 * Description:        Companion for the Cardcom payment gateway: adds a "remember my card" toggle at checkout and rescues the token Cardcom mints under J5 (Capture Charge) so it becomes a real saved card for the customer — the step Cardcom's own plugin skips under Operation 6. Rides on Cardcom's engine; never replaces it. Charging, J5 capture and invoicing stay with Cardcom's plugin.
 * Version:           0.5.0
 * Author:            Original Concepts
 * Author URI:        https://originalconcepts.co.il/
 * Text Domain:       oc-cardcom-cards
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * License:           GPL-2.0-or-later
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OCCC_VERSION', '0.5.0' );
define( 'OCCC_PLUGIN_FILE', __FILE__ );
define( 'OCCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OCCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OCCC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Self-update source. Point this at the plugin's GitHub repo; every site running
 * the plugin then sees updates in Dashboard → Plugins. For a private repo also
 * define OCCC_UPDATE_TOKEN with a GitHub access token.
 */
if ( ! defined( 'OCCC_UPDATE_REPO' ) ) {
	define( 'OCCC_UPDATE_REPO', 'https://github.com/originalconcepts/oc-cardcom-cards/' );
}

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
 * Boot the plugin once WooCommerce is known to be present.
 *
 * @return void
 */
function occc_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'OC Cardcom Cards requires WooCommerce to be installed and active.', 'oc-cardcom-cards' );
				echo '</p></div>';
			}
		);
		return;
	}

	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-logger.php';
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-settings.php';
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-tokens.php';
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-checkout.php';
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-methods.php';
	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-plugin.php';

	OCCC_Plugin::instance();

	require_once OCCC_PLUGIN_DIR . 'includes/class-occc-updater.php';
	OCCC_Updater::init();
}

register_activation_hook(
	__FILE__,
	function () {
		require_once OCCC_PLUGIN_DIR . 'includes/class-occc-logger.php';
		OCCC_Logger::install();
	}
);
