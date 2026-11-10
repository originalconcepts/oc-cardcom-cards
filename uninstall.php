<?php
/**
 * Uninstall: remove settings, the licence state and the scheduled licence check.
 *
 * We intentionally DO NOT delete saved payment tokens here — they live in the core
 * WooCommerce token tables and may still be referenced by past orders. Removing a
 * customer's cards should be a deliberate action, not a side effect of uninstall.
 *
 * occc_legacy_install also stays: it records, once, whether this site ran a
 * version below 0.6.0 (see OCCC_Install). The logs folder and saved cards
 * survive an uninstall, so without it a later reinstall could not tell a fresh
 * site from an old one.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'occc_settings', 'occc_hub', 'occc_version', 'occc_restricted_mailed' ) as $occc_option ) {
	delete_option( $occc_option );
}
delete_transient( 'occc_hub_pkg' );
wp_clear_scheduled_hook( 'occc_hub_refresh' );
