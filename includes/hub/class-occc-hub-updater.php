<?php
/**
 * Updates from the OC Pluginz package store: WordPress learns about a new
 * version from the hub, with the licence key as the ticket. This is the
 * update source of every site with a licence key. A site that ran a version below
 * 0.6.0 (or an agency site) and has no key keeps OCCC_Updater, the GitHub one.
 *
 * GET /products/oc-cardcom-cards/info answers {version, slug, package, url,
 * tested, requires}; the package URL streams the zip (top folder
 * oc-cardcom-cards/) for an active licence or one in its grace days. The answer
 * is kept 12 hours (a failure, one hour); a site whose licence brings no
 * updates never asks.
 *
 * Without a key (and in agency mode) this class touches nothing: no hub calls,
 * no entry in the update_plugins transient. With a key it owns the plugin's
 * entry there and the "View details" window; whether a newer version is
 * actually offered depends on the licence (OCCC_Hub::updates_allowed()).
 *
 * Updates are offered, not forced: the store updates from the Plugins screen
 * as with any plugin (WordPress's own auto-update toggle works too).
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Hub_Updater {

	const TRANSIENT = 'occc_hub_pkg';
	const TTL       = 12 * HOUR_IN_SECONDS;
	const TTL_FAIL  = HOUR_IN_SECONDS;
	const ACTION    = 'occc_hub_check_updates';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ), 10, 0 );
		if ( is_admin() ) {
			add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
			add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'check_now' ) );
		}
	}

	/**
	 * Is the hub's version newer than the one running here?
	 *
	 * @param array|null $info      The hub's answer.
	 * @param string     $installed The running version.
	 * @return bool
	 */
	public static function newer( $info, $installed ) {
		return is_array( $info ) && '' !== ( isset( $info['version'] ) ? (string) $info['version'] : '' ) && version_compare( (string) $info['version'], (string) $installed, '>' );
	}

	/**
	 * Does the hub look after this site's updates? A key is set, outside agency mode.
	 *
	 * @return bool
	 */
	public static function active() {
		return ! OCCC_Hub::agency() && '' !== OCCC_Hub::key();
	}

	/**
	 * The plugin's basename (folder/file.php).
	 *
	 * @return string
	 */
	public static function basename() {
		return OCCC_PLUGIN_BASENAME;
	}

	/**
	 * The plugin's folder name.
	 *
	 * @return string
	 */
	public static function slug() {
		return dirname( OCCC_PLUGIN_BASENAME );
	}

	/**
	 * Forget the kept answer: the next check asks the hub again.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The hub's package, kept 12 hours (a failure, one hour). Null without a
	 * licence that brings updates, when the hub is unreachable, or before the
	 * hub has a package.
	 *
	 * @param bool $force Ask the hub even if an answer is kept.
	 * @return array|null
	 */
	public static function info( $force = false ) {
		if ( ! OCCC_Hub::updates_allowed() ) {
			return null;
		}
		if ( ! $force ) {
			$kept = get_transient( self::TRANSIENT );
			if ( is_array( $kept ) ) {
				return '' === ( isset( $kept['version'] ) ? (string) $kept['version'] : '' ) ? null : $kept;
			}
		}
		$response = wp_remote_get(
			OCCC_Hub::url() . '/wp-json/ocpz/v1/products/' . OCCC_Hub::PRODUCT . '/info',
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => OCCC_Hub::headers(),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data     = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package'] ) ) {
			set_transient( self::TRANSIENT, array( 'version' => '' ), self::TTL_FAIL );
			return null;
		}
		$info = array(
			'version'  => (string) $data['version'],
			'package'  => (string) $data['package'],
			'url'      => isset( $data['url'] ) ? (string) $data['url'] : OCCC_Hub::url(),
			'tested'   => isset( $data['tested'] ) ? (string) $data['tested'] : '',
			'requires' => isset( $data['requires'] ) ? (string) $data['requires'] : '5.8',
		);
		set_transient( self::TRANSIENT, $info, self::TTL );
		return $info;
	}

	/**
	 * Tell WordPress about a newer package (or list the plugin under no_update
	 * so its auto-update toggle stays visible). Nothing happens without a key.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || ! self::active() ) {
			return $transient;
		}
		$info = self::info();
		$item = (object) array(
			'id'            => 'ocpluginz/' . OCCC_Hub::PRODUCT,
			'slug'          => self::slug(),
			'plugin'        => self::basename(),
			'new_version'   => OCCC_VERSION,
			'url'           => is_array( $info ) ? $info['url'] : OCCC_Hub::url(),
			'package'       => '',
			'tested'        => is_array( $info ) ? $info['tested'] : '',
			'requires'      => is_array( $info ) ? $info['requires'] : '5.8',
			'requires_php'  => '7.4',
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'compatibility' => new stdClass(),
		);
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		if ( self::newer( $info, OCCC_VERSION ) ) {
			$item->new_version = $info['version'];
			$item->package     = $info['package'];

			$transient->response[ self::basename() ] = $item;
			unset( $transient->no_update[ self::basename() ] );
		} else {
			$transient->no_update[ self::basename() ] = $item;
			unset( $transient->response[ self::basename() ] );
		}
		return $transient;
	}

	/**
	 * The "View details" window on the Plugins screen.
	 *
	 * @param mixed  $result Result object or false.
	 * @param string $action The API action requested.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::slug() !== $args->slug || ! self::active() ) {
			return $result;
		}
		$info = self::info();
		if ( null === $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'OC Cardcom Cards',
			'slug'          => self::slug(),
			'version'       => $info['version'],
			'author'        => '<a href="https://ocpluginz.com">OC Pluginz</a>',
			'homepage'      => $info['url'],
			'requires'      => $info['requires'],
			'tested'        => $info['tested'],
			'requires_php'  => '7.4',
			'download_link' => $info['package'],
			'sections'      => array(
				'description' => esc_html__( 'Companion to the Cardcom payment plugin. It adds a "remember my card" toggle at checkout and saves the token Cardcom mints under Capture Charge (J5) as a reusable saved card. Cardcom keeps handling the charge, J5 capture and invoicing.', 'oc-cardcom-cards' ),
			),
		);
	}

	/**
	 * A "Check for updates" link in the plugin's row on the Plugins screen.
	 *
	 * @param array  $links Row meta links.
	 * @param string $file  Plugin basename.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( self::basename() !== $file || ! self::active() || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ) ) . '">' . esc_html__( 'Check for updates', 'oc-cardcom-cards' ) . '</a>';
		return $links;
	}

	/**
	 * The link: ask the hub now, then back to where the click came from (or the Plugins screen).
	 *
	 * @return void
	 */
	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'oc-cardcom-cards' ) );
		}
		check_admin_referer( self::ACTION );
		self::forget();
		OCCC_Hub::refresh();
		self::info( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$back = wp_get_referer();
		if ( ! $back || false === strpos( (string) $back, 'page=' . OCCC_Hub_Admin::PAGE ) ) {
			$back = admin_url( 'plugins.php' );
		} else {
			$back = add_query_arg( 'occc_hub', 'updates', remove_query_arg( array( 'occc_hub', 'occc_err' ), $back ) );
		}
		wp_safe_redirect( $back );
		exit;
	}
}
