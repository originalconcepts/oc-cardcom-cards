<?php
/**
 * Uninstall: remove settings only.
 *
 * We intentionally DO NOT delete saved payment tokens here — they live in the core
 * WooCommerce token tables and may still be referenced by past orders. Removing a
 * customer's cards should be a deliberate action, not a side effect of uninstall.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'occc_settings' );
