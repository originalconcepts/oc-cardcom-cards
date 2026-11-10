<?php
/**
 * The link to OC Pluginz (my.ocpluginz.com): the free licence that brings
 * updates from the hub, lists this install among the hub's sites, and decides
 * whether NEW cards may be saved at checkout.
 *
 * One option (occc_hub) holds the key, a copy of the entitlement the hub last
 * answered with, and the hub's notices for the admin (OCCC_Notices). The hub is
 * asked again hourly (WP-Cron) and when the account screen opens. Nothing here
 * calls the hub from the storefront: the checkout only reads the stored copy.
 *
 * licensed() is the one question the rest of the plugin asks:
 *   - agency mode (OC_PLUGINZ_AGENCY in wp-config.php): always yes;
 *   - a site that ran a version below 0.6.0 (OCCC_Install, occc_legacy_install):
 *     always yes, with or without a key;
 *   - no key: no;
 *   - a key the hub has never answered about: yes;
 *   - a key the hub refused, or a suspended licence: no, at once;
 *   - a licence past its end: no only once the hub has answered after the end
 *     date AND the grace days have passed. An unreachable hub keeps the last
 *     verdict.
 *
 * "No" (restricted mode) means only this: the "remember my card" toggle is not
 * rendered and no new token is saved. Cards already saved keep being offered
 * and charged, My Account keeps listing and deleting them, the admin keeps
 * working, nothing is deleted.
 *
 * What goes to the hub: the key, the product, this site's domain and address,
 * the plugin and WordPress versions. Nothing about orders, customers or cards.
 *
 * wp-config.php overrides: OCCC_LICENSE_KEY (a fixed key), OCCC_HUB_URL (another
 * hub, e.g. staging), OC_PLUGINZ_AGENCY (our own sites: no licence, no hub
 * calls, no notices).
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Hub {

	const URL         = 'https://my.ocpluginz.com';
	const PRODUCT     = 'oc-cardcom-cards';
	const OPTION      = 'occc_hub';
	const CRON        = 'occc_hub_refresh';
	const ACCOUNT_URL = 'https://my.ocpluginz.com/account/';
	const PRICING_URL = 'https://ocpluginz.com/oc-cardcom-cards';
	const CAP         = 'manage_options';

	/** Set once by OCCC_Install: 'yes' on a site that ran a version below 0.6.0, 'no' on a fresh install. */
	const LEGACY_OPTION = 'occc_legacy_install';

	/** Present while the "saving is off" mail of the current restricted spell has gone out. */
	const MAILED_OPTION = 'occc_restricted_mailed';

	/** The hub's thresholds (OCPZS_Entitlements), mirrored so a cached copy ages the same way. */
	const EXPIRING_DAYS = 14;
	const GRACE_DAYS    = 7;

	/** Codes that mean "this key buys nothing here": the cached entitlement goes. An outage keeps it. */
	const REFUSALS = array( 'ocpzs_bad_key', 'ocpzs_inactive', 'ocpzs_seats_full', 'ocpzs_bad_product', 'ocpzs_bad_request' );

	/** The reasons that come from the hub's own verdict (as opposed to "no key was ever entered"). */
	const HUB_VERDICTS = array( 'refused', 'suspended', 'expired' );

	/**
	 * Per-request memo of licensed().
	 *
	 * @var bool|null
	 */
	private static $licensed = null;

	/**
	 * Hooks. The cron action is registered on every request (WP-Cron does not
	 * run in wp-admin); scheduling and the transition check are admin only.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
			add_action( 'admin_init', array( __CLASS__, 'transition' ), 20 );
		}
	}

	/* --------------------------------------------------------------------- *
	 * The rules, without WordPress
	 * --------------------------------------------------------------------- */

	/**
	 * Valid | expiring | grace | expired, from expires_at as the hub ages it.
	 * No end date never expires.
	 *
	 * @param array|null $ent The entitlement.
	 * @param int        $now Unix time.
	 * @return string
	 */
	public static function lifecycle( $ent, $now ) {
		if ( ! is_array( $ent ) ) {
			return 'expired';
		}
		$end = isset( $ent['expires_at'] ) ? (string) $ent['expires_at'] : '';
		if ( '' === $end ) {
			return 'valid';
		}
		$exp = strtotime( $end );
		if ( false === $exp ) {
			return isset( $ent['state'] ) ? (string) $ent['state'] : 'valid';
		}
		if ( $exp > $now ) {
			return ( $exp - $now < self::EXPIRING_DAYS * DAY_IN_SECONDS ) ? 'expiring' : 'valid';
		}
		return ( $now - $exp < self::GRACE_DAYS * DAY_IN_SECONDS ) ? 'grace' : 'expired';
	}

	/**
	 * Are updates offered? The hub's own rule for a download
	 * (OCPZS_Packages::license_for): an active licence, or one past due within
	 * its grace days.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return bool
	 */
	public static function decide( array $state, $key, $now ) {
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( '' === $key || null === $ent ) {
			return false;
		}
		$status = isset( $ent['status'] ) ? (string) $ent['status'] : '';
		$life   = self::lifecycle( $ent, $now );
		if ( in_array( $status, array( 'suspended', 'deleted' ), true ) || 'expired' === $life ) {
			return false;
		}
		return 'active' === $status || 'grace' === $life;
	}

	/**
	 * May new cards be saved, as far as the key and the hub's last answer go?
	 * (Agency mode and legacy installs are decided before this, in licensed().)
	 *
	 *   no key                                   -> no
	 *   key, the hub never answered (unverified) -> yes
	 *   key, the hub refused it                  -> no, at once
	 *   suspended / deleted                      -> no, at once
	 *   past its end                             -> no only when the hub answered
	 *                                               after the end date (so it was
	 *                                               not renewed) and the grace days
	 *                                               have passed; until then yes
	 *   anything else the hub accepted           -> yes
	 *
	 * An unreachable hub changes nothing here: the stored copy and the time of
	 * the hub's last real answer (checked_at) are all this reads.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return bool
	 */
	public static function verdict( array $state, $key, $now ) {
		if ( '' === (string) $key ) {
			return false;
		}
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( null === $ent ) {
			return empty( $state['refused'] );
		}
		$status = isset( $ent['status'] ) ? (string) $ent['status'] : '';
		if ( in_array( $status, array( 'suspended', 'deleted' ), true ) ) {
			return false;
		}
		$end = isset( $ent['expires_at'] ) ? (string) $ent['expires_at'] : '';
		$exp = '' === $end ? false : strtotime( $end );
		if ( false === $exp ) {
			// No end date to age: only the hub's own word can end it.
			return 'expired' !== ( isset( $ent['state'] ) ? (string) $ent['state'] : '' );
		}
		if ( $now - $exp < self::GRACE_DAYS * DAY_IN_SECONDS ) {
			return true; // Not over yet, or over and within its grace days.
		}
		// Past the grace days: restricted only once the hub has confirmed the end,
		// that is, answered after the end date with that same end date.
		$checked = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;
		return $checked < $exp;
	}

	/**
	 * Where the licence stands, for the screen and the notices: '' (fine),
	 * no_key, refused, unverified, suspended, grace, expired.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return string
	 */
	public static function reason( array $state, $key, $now ) {
		if ( '' === (string) $key ) {
			return 'no_key';
		}
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( null === $ent ) {
			return ! empty( $state['refused'] ) ? 'refused' : 'unverified';
		}
		if ( in_array( isset( $ent['status'] ) ? (string) $ent['status'] : '', array( 'suspended', 'deleted' ), true ) ) {
			return 'suspended';
		}
		if ( ! self::decide( $state, $key, $now ) ) {
			return 'expired';
		}
		return 'grace' === self::lifecycle( $ent, $now ) ? 'grace' : '';
	}

	/**
	 * An OC Pluginz key: ocpz_ and hex.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function looks_like_key( $key ) {
		return 1 === preg_match( '/^ocpz_[a-f0-9]{20,64}$/i', (string) $key );
	}

	/**
	 * The domain the hub binds a seat to: the host, lower case, without www.
	 *
	 * @param string $url Site address.
	 * @return string
	 */
	public static function domain_of( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * The key as the screen shows it: the start and the end.
	 *
	 * @param string $key Licence key.
	 * @return string
	 */
	public static function masked( $key ) {
		$key = (string) $key;
		return strlen( $key ) > 14 ? substr( $key, 0, 9 ) . '…' . substr( $key, -4 ) : $key;
	}

	/* --------------------------------------------------------------------- *
	 * The site's state
	 * --------------------------------------------------------------------- */

	/**
	 * The hub's address (OCCC_HUB_URL points at another hub).
	 *
	 * @return string
	 */
	public static function url() {
		return rtrim( defined( 'OCCC_HUB_URL' ) ? (string) OCCC_HUB_URL : self::URL, '/' );
	}

	/**
	 * Agency mode (our own sites): no licence, no hub calls, no notices.
	 *
	 * @return bool
	 */
	public static function agency() {
		return defined( 'OC_PLUGINZ_AGENCY' ) && OC_PLUGINZ_AGENCY;
	}

	/**
	 * Did this site run a version below 0.6.0? (Decided once, by OCCC_Install.)
	 *
	 * @return bool
	 */
	public static function legacy() {
		return 'yes' === get_option( self::LEGACY_OPTION );
	}

	/**
	 * Is the key fixed in wp-config.php?
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'OCCC_LICENSE_KEY' ) && '' !== trim( (string) OCCC_LICENSE_KEY );
	}

	/**
	 * The option.
	 *
	 * @return array
	 */
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Merge into the option. Autoloaded: the checkout asks licensed() on the
	 * storefront, so the row rides along with the other options.
	 *
	 * @param array $patch Changes.
	 * @return void
	 */
	private static function patch( array $patch ) {
		update_option( self::OPTION, array_merge( self::state(), $patch ), true );
		self::$licensed = null;
	}

	/**
	 * The key in use: wp-config.php first, then the connected one.
	 *
	 * @return string
	 */
	public static function key() {
		if ( self::key_from_constant() ) {
			return trim( (string) OCCC_LICENSE_KEY );
		}
		$s = self::state();
		return isset( $s['key'] ) ? (string) $s['key'] : '';
	}

	/**
	 * This site's domain as the hub sees it.
	 *
	 * @return string
	 */
	public static function domain() {
		return self::domain_of( home_url( '/' ) );
	}

	/**
	 * The entitlement the hub last answered with.
	 *
	 * @return array|null
	 */
	public static function entitlement() {
		$s = self::state();
		return ( isset( $s['entitlement'] ) && is_array( $s['entitlement'] ) ) ? $s['entitlement'] : null;
	}

	/**
	 * Are updates from the hub offered on this site?
	 *
	 * @return bool
	 */
	public static function updates_allowed() {
		return ! self::agency() && self::decide( self::state(), self::key(), time() );
	}

	/**
	 * May new cards be saved at checkout? Reads stored options only: it is
	 * asked on the storefront and must never wait for the hub or fail.
	 *
	 * @return bool
	 */
	public static function licensed() {
		if ( null === self::$licensed ) {
			$ok             = self::agency() || self::legacy() || self::verdict( self::state(), self::key(), time() );
			self::$licensed = (bool) apply_filters( 'occc_licensed', $ok );
		}
		return self::$licensed;
	}

	/**
	 * The mode the plugin runs in: agency | legacy | full | restricted.
	 *
	 * @return string
	 */
	public static function mode() {
		if ( self::agency() ) {
			return 'agency';
		}
		if ( self::legacy() ) {
			return 'legacy';
		}
		return self::licensed() ? 'full' : 'restricted';
	}

	/**
	 * Where the licence stands now (see reason()).
	 *
	 * @return string
	 */
	public static function status() {
		return self::reason( self::state(), self::key(), time() );
	}

	/**
	 * The account screen in this site's admin: a hidden page of the suite's
	 * shared OC Pluginz menu.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=' . OCCC_Hub_Admin::PAGE );
	}

	/* --------------------------------------------------------------------- *
	 * The hub
	 * --------------------------------------------------------------------- */

	/**
	 * Who is asking.
	 *
	 * @return array
	 */
	private static function identity() {
		return array(
			'key'            => self::key(),
			'product'        => self::PRODUCT,
			'domain'         => self::domain(),
			'site_url'       => home_url( '/' ),
			'version'        => OCCC_VERSION,
			'plugin_version' => OCCC_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
		);
	}

	/**
	 * The headers every call to the hub carries.
	 *
	 * @return array
	 */
	public static function headers() {
		return array(
			'X-OCPZ-Key'     => self::key(),
			'X-OCPZ-Site'    => self::domain(),
			'X-OCPZ-Version' => OCCC_VERSION,
			'Accept'         => 'application/json',
			'User-Agent'     => 'OC-Cardcom-Cards/' . OCCC_VERSION . '; ' . home_url( '/' ),
		);
	}

	/**
	 * A call to /wp-json/ocpz/v1{$path}.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   Under /wp-json/ocpz/v1.
	 * @param array  $body   Extra parameters.
	 * @return array|WP_Error The decoded answer.
	 */
	private static function request( $method, $path, array $body = array() ) {
		$body = array_merge( self::identity(), $body );
		$args = array(
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => self::headers(),
		);
		$url  = self::url() . '/wp-json/ocpz/v1' . $path;
		if ( 'GET' === $method ) {
			$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $body ), $url ), $args );
		} else {
			$args['body'] = $body;
			$response     = wp_remote_post( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		/* translators: %d: HTTP status code */
		$status_text = sprintf( __( 'OC Pluginz answered with status %d.', 'oc-cardcom-cards' ), $code );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'occc_hub_http', $status_text );
		}
		if ( $code >= 400 || ( isset( $data['ok'] ) && false === $data['ok'] ) ) {
			$msg = ( isset( $data['message'] ) && '' !== (string) $data['message'] ) ? (string) $data['message'] : $status_text;
			return new WP_Error( isset( $data['code'] ) ? (string) $data['code'] : 'occc_hub_http', $msg, isset( $data['data'] ) ? $data['data'] : null );
		}
		return $data;
	}

	/**
	 * The hub's refusals in words the store owner can act on.
	 *
	 * @param WP_Error $error The hub's answer.
	 * @return string
	 */
	private static function friendly( WP_Error $error ) {
		switch ( (string) $error->get_error_code() ) {
			case 'ocpzs_bad_key':
				return __( 'OC Pluginz does not recognise this key for OC Cardcom Cards. Check it in your OC Pluginz account.', 'oc-cardcom-cards' );
			case 'ocpzs_inactive':
				return __( 'This licence is not active. You can renew it in your OC Pluginz account.', 'oc-cardcom-cards' );
			case 'ocpzs_seats_full':
				return __( 'Every site this licence allows is already connected. Disconnect another site first (on its account screen, or in your OC Pluginz account).', 'oc-cardcom-cards' );
			case 'ocpzs_bad_product':
				return __( 'OC Pluginz does not offer OC Cardcom Cards yet. Contact Original Concepts.', 'oc-cardcom-cards' );
			case 'ocpzs_rate':
				return __( 'Too many requests in the last minute. Try again in a minute.', 'oc-cardcom-cards' );
			case 'rest_forbidden':
				return __( 'OC Pluginz refused the request for now. Try again in a minute.', 'oc-cardcom-cards' );
		}
		return $error->get_error_message();
	}

	/**
	 * Keep what the hub answered.
	 *
	 * @param array $data The decoded answer.
	 * @return true|WP_Error
	 */
	private static function accept( array $data ) {
		$ent = ( isset( $data['entitlement'] ) && is_array( $data['entitlement'] ) ) ? $data['entitlement'] : null;
		if ( null === $ent ) {
			return new WP_Error( 'occc_hub_shape', __( 'OC Pluginz answered without a licence.', 'oc-cardcom-cards' ) );
		}
		$s = self::state();
		self::patch(
			array(
				'entitlement' => $ent,
				'notices'     => OCCC_Notices::from_answer( $data ),
				'latest'      => isset( $data['latest'] ) ? (string) $data['latest'] : ( isset( $s['latest'] ) ? (string) $s['latest'] : '' ),
				'checked_at'  => time(),
				'error'       => '',
				'error_code'  => '',
				'error_at'    => 0,
				'refused'     => false,
			)
		);
		return true;
	}

	/**
	 * A failure: a refusal drops the cached entitlement, an outage does not.
	 *
	 * @param WP_Error $error The failure.
	 * @return void
	 */
	private static function note_error( WP_Error $error ) {
		$patch = array(
			'error'      => self::friendly( $error ),
			'error_code' => (string) $error->get_error_code(),
			'error_at'   => time(),
		);
		if ( in_array( (string) $error->get_error_code(), self::REFUSALS, true ) ) {
			$patch['entitlement'] = null;
			$patch['refused']     = true;
		}
		self::patch( $patch );
	}

	/**
	 * Take a seat for this site. The key is kept only once the hub accepts it,
	 * so a typo never leaves the site half connected.
	 *
	 * @param string $key The licence key.
	 * @return true|WP_Error
	 */
	public static function connect( $key ) {
		$key = trim( (string) $key );
		if ( ! self::looks_like_key( $key ) ) {
			return new WP_Error( 'occc_hub_key', __( 'That does not look like an OC Pluginz licence key (ocpz_…).', 'oc-cardcom-cards' ) );
		}
		$previous = self::state();
		update_option( self::OPTION, array( 'key' => $key ), true );
		self::$licensed = null;
		$res            = self::request( 'POST', '/sdk/activate' );
		$ok             = is_wp_error( $res ) ? $res : self::accept( $res );
		if ( is_wp_error( $ok ) ) {
			if ( $previous ) {
				update_option( self::OPTION, $previous, true );
			} else {
				delete_option( self::OPTION );
			}
			self::$licensed = null;
			return new WP_Error( $ok->get_error_code(), self::friendly( $ok ) );
		}
		self::patch( array( 'activated_at' => time() ) );
		self::maybe_schedule();
		self::transition();
		return true;
	}

	/**
	 * Ask the hub again; a wp-config key that never took its seat takes it first.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh() {
		if ( self::agency() || '' === self::key() ) {
			return new WP_Error( 'occc_hub_no_key', __( 'No licence key.', 'oc-cardcom-cards' ) );
		}
		$s     = self::state();
		$fresh = self::key_from_constant() && null === self::entitlement() && empty( $s['refused'] );
		$res   = self::request( $fresh ? 'POST' : 'GET', $fresh ? '/sdk/activate' : '/sdk/status' );
		if ( is_wp_error( $res ) ) {
			self::note_error( $res );
			self::transition();
			return new WP_Error( $res->get_error_code(), self::friendly( $res ) );
		}
		$ok = self::accept( $res );
		if ( true === $ok && $fresh ) {
			self::patch( array( 'activated_at' => time() ) );
		}
		self::transition();
		return $ok;
	}

	/**
	 * The hourly check (WP-Cron).
	 *
	 * @return void
	 */
	public static function cron() {
		self::refresh();
	}

	/**
	 * Ask again when the last contact is older than $max_age seconds (the account screen).
	 *
	 * @param int $max_age Seconds.
	 * @return void
	 */
	public static function refresh_if_stale( $max_age = 600 ) {
		if ( self::agency() || '' === self::key() ) {
			return;
		}
		$s    = self::state();
		$last = max( isset( $s['checked_at'] ) ? (int) $s['checked_at'] : 0, isset( $s['error_at'] ) ? (int) $s['error_at'] : 0 );
		if ( time() - $last >= (int) $max_age ) {
			self::refresh();
		}
	}

	/**
	 * Free the seat at the hub and forget the key.
	 *
	 * @return true
	 */
	public static function disconnect() {
		if ( '' !== self::key() && ! self::agency() ) {
			self::request( 'POST', '/sdk/deactivate' );
		}
		delete_option( self::OPTION );
		self::$licensed = null;
		wp_clear_scheduled_hook( self::CRON );
		self::transition();
		return true;
	}

	/**
	 * The hourly check runs while a key is set, and only then.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$want = '' !== self::key() && ! self::agency();
		$next = wp_next_scheduled( self::CRON );
		if ( $want && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		} elseif ( ! $want && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/* --------------------------------------------------------------------- *
	 * The way into restricted mode, and back
	 * --------------------------------------------------------------------- */

	/**
	 * What restricted mode means on this site, in words the store owner can act
	 * on; '' while new cards can be saved.
	 *
	 * @return string
	 */
	public static function off_text() {
		if ( self::licensed() ) {
			return '';
		}
		$kept = __( 'Cards already saved keep working at checkout and in My Account.', 'oc-cardcom-cards' );
		switch ( self::status() ) {
			case 'no_key':
				return __( 'OC Cardcom Cards is not connected to OC Pluginz yet, so the "remember my card" toggle is not shown at checkout and no new cards are saved. Connect the licence key (it is free) to switch it on.', 'oc-cardcom-cards' ) . ' ' . $kept;
			case 'refused':
				return __( 'OC Pluginz did not accept the OC Cardcom Cards licence key, so the "remember my card" toggle is not shown at checkout and no new cards are saved.', 'oc-cardcom-cards' ) . ' ' . $kept;
			case 'suspended':
				return __( 'The OC Cardcom Cards licence is suspended, so the "remember my card" toggle is not shown at checkout and no new cards are saved.', 'oc-cardcom-cards' ) . ' ' . $kept;
		}
		return __( 'The OC Cardcom Cards licence has expired, so the "remember my card" toggle is not shown at checkout and no new cards are saved.', 'oc-cardcom-cards' ) . ' ' . $kept;
	}

	/**
	 * Notice the move into restricted mode and the way back. Runs after every
	 * answer from the hub and on admin_init (a licence can run out of grace
	 * days with no answer at all).
	 *
	 * Into restricted, on the hub's verdict (refused, suspended, expired): ONE
	 * mail to the site's admin address. MAILED_OPTION is the flag; add_option()
	 * fails when it is already there, so two requests at once send one mail.
	 * Back to licensed: the flag goes, so the next spell mails again.
	 *
	 * A site that simply has no key (a fresh install, or one the owner
	 * disconnected) gets the notices, not a mail: nothing was taken from it.
	 *
	 * @return void
	 */
	public static function transition() {
		if ( self::licensed() ) {
			if ( false !== get_option( self::MAILED_OPTION, false ) ) {
				delete_option( self::MAILED_OPTION );
			}
			return;
		}
		if ( ! in_array( self::status(), self::HUB_VERDICTS, true ) ) {
			return;
		}
		if ( ! add_option( self::MAILED_OPTION, time() ) ) {
			return;
		}
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] OC Cardcom Cards: saving new cards is switched off', 'oc-cardcom-cards' ), $site );
		$body    = self::off_text() . "\n\n"
			. __( 'Nothing was deleted and the checkout keeps working as usual. Saving new cards comes back by itself as soon as the licence is in order.', 'oc-cardcom-cards' ) . "\n\n"
			. __( 'The licence screen on your site:', 'oc-cardcom-cards' ) . ' ' . self::settings_url() . "\n"
			. __( 'Your OC Pluginz account:', 'oc-cardcom-cards' ) . ' ' . self::ACCOUNT_URL . "\n";
		wp_mail( $to, $subject, $body );
	}
}
