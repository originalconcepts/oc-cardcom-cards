<?php
/**
 * First run of 0.6.0 and later: is this a site that already ran an older
 * version (a "legacy install"), or a fresh one?
 *
 * Since 0.6.0 saving new cards needs a free OC Pluginz licence key. Sites that
 * ran the plugin before that keep working in full without one, for good; a
 * fresh install starts restricted until a key is connected. The answer is
 * written once to occc_legacy_install ('yes' | 'no') and never decided again.
 *
 * How the two are told apart. Versions below 0.6.0 stored no version number,
 * so the decision rests on HOW 0.6.0 first comes to run:
 *
 *   A. Through the activation hook (activate()): somebody pressed Activate on
 *      0.6.0+ code. That is a fresh install, unless the site carries traces an
 *      older version left behind (traces()): then the plugin was here before
 *      and was merely inactive while its files were replaced.
 *
 *   B. Without the activation hook (maybe_upgrade(), on plugins_loaded): the
 *      plugin is running although 0.6.0+ code never activated it. WordPress
 *      does not fire the activation hook when an active plugin is updated
 *      (from the Plugins screen, by auto-update, by upload-and-replace, FTP or
 *      git), so this is an update of an older version: legacy. No traces are
 *      asked for here, because a site that never saved the settings has none
 *      in the database and must still not lose a feature it had.
 *
 * In the activation request plugins_loaded has already fired, so B never runs
 * before A on a fresh install.
 *
 * Multisite: network activation fires the hook on one site only, so the other
 * sites of the network would land in B. A fresh network activation therefore
 * leaves a network-wide marker, and B treats a site without traces on such a
 * network as fresh.
 *
 * occc_legacy_install is deliberately NOT removed by uninstall.php: the logs
 * folder and saved cards survive an uninstall, and without the stored answer a
 * fresh install that was removed and installed again would look like a legacy
 * one.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Install {

	/** The version whose code last ran here (new in 0.6.0; absent on anything older). */
	const VERSION_OPTION = 'occc_version';

	/** 'yes' | 'no', written once (the same name as OCCC_Hub::LEGACY_OPTION). */
	const LEGACY_OPTION = 'occc_legacy_install';

	/** Network option: the plugin was first activated network-wide by 0.6.0+ code, with no older traces. */
	const NETWORK_FRESH = 'occc_network_fresh';

	/**
	 * The activation hook (route A). Must run BEFORE OCCC_Logger::install(),
	 * which creates one of the traces this looks for.
	 *
	 * @param bool $network_wide Network activation on multisite.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( ! self::decided() ) {
			$legacy = self::traces();
			add_option( self::LEGACY_OPTION, $legacy ? 'yes' : 'no' );
			if ( ! $legacy && $network_wide && is_multisite() ) {
				update_site_option( self::NETWORK_FRESH, OCCC_VERSION );
			}
		}
		update_option( self::VERSION_OPTION, OCCC_VERSION );
	}

	/**
	 * On every load, cheap (one autoloaded option): when the stored version is
	 * not this one, record it, and on the very first run decide legacy (route B).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$stored = (string) get_option( self::VERSION_OPTION, '' );
		if ( OCCC_VERSION === $stored ) {
			return;
		}
		if ( ! self::decided() ) {
			$legacy = true;
			if ( is_multisite() && get_site_option( self::NETWORK_FRESH ) && ! self::traces() ) {
				$legacy = false;
			}
			add_option( self::LEGACY_OPTION, $legacy ? 'yes' : 'no' );
		}
		if ( '' === $stored ) {
			// The GitHub updater (Plugin Update Checker) is gone: drop its scheduled check.
			wp_clear_scheduled_hook( 'puc_cron_check_updates-oc-cardcom-cards' );
		}
		update_option( self::VERSION_OPTION, OCCC_VERSION );
	}

	/**
	 * Has the legacy question been answered on this site already?
	 *
	 * @return bool
	 */
	private static function decided() {
		return in_array( get_option( self::LEGACY_OPTION ), array( 'yes', 'no' ), true );
	}

	/**
	 * Did a version below 0.6.0 leave anything behind on this site?
	 *
	 *   - the settings option (written when the settings were saved);
	 *   - Plugin Update Checker's state (written by 0.4.0 to 0.5.2 on their
	 *     first update check, in the admin or by cron);
	 *   - the protected logs folder (created by the old activation hook);
	 *   - a saved card tagged with its terminal (written with every card saved
	 *     since 0.3.3).
	 *
	 * @return bool
	 */
	public static function traces() {
		if ( null !== get_option( 'occc_settings', null ) ) {
			return true;
		}
		if ( false !== get_site_option( 'external_updates-oc-cardcom-cards', false ) ) {
			return true;
		}
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && is_dir( trailingslashit( $uploads['basedir'] ) . 'occc-logs' ) ) {
			return true;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_payment_tokenmeta';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off existence check on a core WooCommerce table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			if ( $wpdb->get_var( "SELECT meta_id FROM {$table} WHERE meta_key = 'occc_terminal' LIMIT 1" ) ) {
				return true;
			}
		}
		// phpcs:enable
		return false;
	}
}
