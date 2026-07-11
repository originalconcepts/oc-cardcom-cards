<?php
/**
 * Self-update from a GitHub repository.
 *
 * Uses the bundled Plugin Update Checker (Yahnis Elsts) so any site running this
 * plugin sees updates in Dashboard → Plugins and can one-click update, pulled from
 * the plugin's GitHub repo (stable releases/tags).
 *
 * Configure the repo (and, for a private repo, a token) via constants in the main
 * file or the filters below:
 *   define( 'OCCC_UPDATE_REPO', 'https://github.com/<owner>/oc-cardcom-cards/' );
 *   define( 'OCCC_UPDATE_TOKEN', '<github token>' ); // private repos only
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Updater {

	/**
	 * Wire up the update checker (admin/cron only — no front-end cost).
	 *
	 * @return void
	 */
	public static function init() {
		$repo = defined( 'OCCC_UPDATE_REPO' ) ? OCCC_UPDATE_REPO : '';
		$repo = apply_filters( 'occc_update_repo', $repo );

		if ( empty( $repo ) ) {
			return; // No repo configured yet — nothing to check against.
		}
		if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		$loader = OCCC_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $loader ) ) {
			return;
		}
		require_once $loader;

		$factory = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		// PUC's GitHub default picks the latest published Release, then the latest
		// tag — so updates are controlled: bump the version and tag/release to ship.
		// (No setBranch = we do NOT auto-update from every push to the branch.)
		$checker = call_user_func( array( $factory, 'buildUpdateChecker' ), $repo, OCCC_PLUGIN_FILE, 'oc-cardcom-cards' );

		// Private repos: supply a GitHub token (constant or filter).
		$token = defined( 'OCCC_UPDATE_TOKEN' ) ? OCCC_UPDATE_TOKEN : '';
		$token = apply_filters( 'occc_update_token', $token );
		if ( ! empty( $token ) && method_exists( $checker, 'setAuthentication' ) ) {
			$checker->setAuthentication( $token );
		}
	}
}
