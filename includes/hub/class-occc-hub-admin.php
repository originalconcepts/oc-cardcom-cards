<?php
/**
 * The "OC Pluginz account" screen: a hidden page of the suite's shared OC
 * Pluginz menu (registered by OCCC_Settings::register_with_suite(), reached
 * through the tab next to the settings): connect a licence key, see its status,
 * sites and updates, check now, check for updates, disconnect. Opening the
 * screen re-asks the hub when the last contact is older than ten minutes.
 * ("Check for updates" is OCCC_Hub_Updater::check_now, shared with the link in
 * the plugin's row on the Plugins screen.)
 *
 * Also here: the licence-state notices in the admin (no key, restricted mode,
 * the invitation for sites that ran a version below 0.6.0) and their dismissal.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Hub_Admin {

	const PAGE    = 'occc-account';
	const DISMISS = 'occc_dismiss_state';

	/** User meta: the day (Y-m-d, site time) the red restricted notice was dismissed; it returns the next day. */
	const META_RESTRICTED = 'occc_restricted_dismissed';

	/** User meta: the invitation to enter a free key was dismissed (legacy installs). */
	const META_LEGACY = 'occc_legacy_dismissed';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'plugin_action_links_' . OCCC_PLUGIN_BASENAME, array( __CLASS__, 'action_link' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'state_notice' ) );
		add_action( 'admin_post_' . self::DISMISS, array( __CLASS__, 'dismiss' ) );
		foreach ( array( 'occc_hub_connect', 'occc_hub_refresh', 'occc_hub_disconnect' ) as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'handle' ) );
		}
	}

	/**
	 * "Licence" next to Deactivate on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_link( $links ) {
		if ( current_user_can( OCCC_Hub::CAP ) ) {
			$links[] = '<a href="' . esc_url( OCCC_Hub::settings_url() ) . '">' . esc_html__( 'Licence', 'oc-cardcom-cards' ) . '</a>';
		}
		return $links;
	}

	/* --------------------------------------------------------------------- *
	 * The licence-state notices
	 * --------------------------------------------------------------------- */

	/**
	 * Where the licence stands, said where the store owner is looking.
	 *
	 *   legacy install without a key: an invitation to enter a free key (it is
	 *     what brings updates now), on the Dashboard, the Plugins and Updates
	 *     screens and the plugin's own; dismissible, per user, for good.
	 *   restricted on the hub's verdict (refused / suspended / expired): a red
	 *     notice on every admin screen; dismissible, per user, for the day.
	 *   restricted because no key was ever connected (a fresh install): what is
	 *     left to do, on the Dashboard and the Plugins screen, and in red on the
	 *     plugin's own screens.
	 *
	 * Never in agency mode, and not on the account screen (it says it in full).
	 *
	 * @return void
	 */
	public static function state_notice() {
		if ( OCCC_Hub::agency() || ! current_user_can( OCCC_Hub::CAP ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( self::PAGE === $page ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$own    = OCCC_Settings::PAGE === $page;
		$entry  = $screen && in_array( $screen->id, array( 'dashboard', 'plugins', 'update-core' ), true );
		$mode   = OCCC_Hub::mode();
		$reason = OCCC_Hub::status();
		$link   = ' <a href="' . esc_url( OCCC_Hub::settings_url() ) . '">' . esc_html__( 'Open the licence screen', 'oc-cardcom-cards' ) . '</a>';

		if ( 'legacy' === $mode ) {
			if ( 'no_key' === $reason ) {
				if ( ( $own || $entry ) && ! get_user_meta( get_current_user_id(), self::META_LEGACY, true ) ) {
					self::dismissible(
						'legacy',
						'notice-info',
						'<strong>' . esc_html__( 'OC Cardcom Cards now updates through OC Pluginz.', 'oc-cardcom-cards' ) . '</strong> '
						. esc_html__( 'Everything on this site keeps working as before. To keep receiving updates, connect a licence key: it is free and takes a minute.', 'oc-cardcom-cards' )
						. ' <a href="' . esc_url( OCCC_Hub::settings_url() ) . '">' . esc_html__( 'Connect the key', 'oc-cardcom-cards' ) . '</a>'
					);
				}
			} elseif ( $own && in_array( $reason, array( 'refused', 'suspended', 'expired', 'grace' ), true ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'The OC Cardcom Cards licence is not in order, so updates are not offered. Saving cards keeps working on this site.', 'oc-cardcom-cards' ) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}
			return;
		}

		if ( 'restricted' === $mode ) {
			if ( in_array( $reason, OCCC_Hub::HUB_VERDICTS, true ) ) {
				if ( get_user_meta( get_current_user_id(), self::META_RESTRICTED, true ) !== wp_date( 'Y-m-d' ) ) {
					self::dismissible( 'restricted', 'notice-error', esc_html( OCCC_Hub::off_text() ) . $link );
				}
			} elseif ( $own ) {
				echo '<div class="notice notice-error"><p>' . esc_html( OCCC_Hub::off_text() ) . ' <a href="' . esc_url( OCCC_Hub::settings_url() ) . '">' . esc_html__( 'Connect the key', 'oc-cardcom-cards' ) . '</a></p></div>';
			} elseif ( $entry && $screen && 'update-core' !== $screen->id ) {
				echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'OC Cardcom Cards is installed.', 'oc-cardcom-cards' ) . '</strong> '
					. esc_html__( 'One step is left: connect the free licence key you received by email. Until then the "remember my card" toggle is not shown at checkout; the checkout itself works as usual.', 'oc-cardcom-cards' )
					. ' <a href="' . esc_url( OCCC_Hub::settings_url() ) . '">' . esc_html__( 'Connect the key', 'oc-cardcom-cards' ) . '</a></p></div>';
			}
			return;
		}

		if ( $own && 'grace' === $reason ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The OC Cardcom Cards licence has passed its end date and is on its grace days. Renew it now, or saving new cards will be switched off when they end.', 'oc-cardcom-cards' ) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		} elseif ( $own && 'unverified' === $reason ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The OC Cardcom Cards licence key has not been confirmed by OC Pluginz yet. Saving cards works in the meantime.', 'oc-cardcom-cards' ) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	/**
	 * A notice whose X is remembered (see dismiss()).
	 *
	 * @param string $what  restricted | legacy.
	 * @param string $class notice-error | notice-info.
	 * @param string $html  The notice's content, already escaped.
	 * @return void
	 */
	private static function dismissible( $what, $class, $html ) {
		static $script = false;
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS . '&what=' . $what ), self::DISMISS . '_' . $what );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible occc-state-notice" data-occc-dismiss="' . esc_url( $url ) . '"><p>' . $html . '</p></div>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		if ( ! $script ) {
			$script = true;
			echo '<script>document.addEventListener("click",function(e){var t=e.target&&e.target.closest?e.target.closest(".occc-state-notice .notice-dismiss"):null;if(!t)return;var n=t.closest(".occc-state-notice"),u=n?n.getAttribute("data-occc-dismiss"):"";if(u){fetch(u,{credentials:"same-origin"}).catch(function(){});}});</script>' . "\n";
		}
	}

	/**
	 * Remember a dismissal for this user: the red restricted notice for today,
	 * the legacy invitation for good.
	 *
	 * @return void
	 */
	public static function dismiss() {
		if ( ! current_user_can( OCCC_Hub::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'oc-cardcom-cards' ) );
		}
		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		check_admin_referer( self::DISMISS . '_' . $what );
		if ( 'restricted' === $what ) {
			update_user_meta( get_current_user_id(), self::META_RESTRICTED, wp_date( 'Y-m-d' ) );
		} elseif ( 'legacy' === $what ) {
			update_user_meta( get_current_user_id(), self::META_LEGACY, 1 );
		}
		if ( ! headers_sent() ) {
			status_header( 204 );
		}
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * The account screen
	 * --------------------------------------------------------------------- */

	/**
	 * The admin-post handler: connect / check now / disconnect.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( OCCC_Hub::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'oc-cardcom-cards' ) );
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		check_admin_referer( $action );
		$back = OCCC_Hub::settings_url();

		switch ( $action ) {
			case 'occc_hub_connect':
				$res = OCCC_Hub::connect( isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '' );
				if ( is_wp_error( $res ) ) {
					wp_safe_redirect( add_query_arg( 'occc_err', rawurlencode( $res->get_error_message() ), $back ) );
					exit;
				}
				OCCC_Hub_Updater::forget();
				delete_site_transient( 'update_plugins' );
				wp_safe_redirect( add_query_arg( 'occc_hub', 'connected', $back ) );
				exit;

			case 'occc_hub_refresh':
				$res = OCCC_Hub::refresh();
				if ( is_wp_error( $res ) ) {
					wp_safe_redirect( add_query_arg( 'occc_err', rawurlencode( $res->get_error_message() ), $back ) );
					exit;
				}
				wp_safe_redirect( add_query_arg( 'occc_hub', 'refreshed', $back ) );
				exit;

			case 'occc_hub_disconnect':
				OCCC_Hub::disconnect();
				OCCC_Hub_Updater::forget();
				delete_site_transient( 'update_plugins' );
				wp_safe_redirect( add_query_arg( 'occc_hub', 'disconnected', $back ) );
				exit;
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The line that says what the licence means on this site right now.
	 *
	 * @return array [ colour, text ]
	 */
	private static function mode_line() {
		switch ( OCCC_Hub::mode() ) {
			case 'legacy':
				return array( '#15803d', __( 'Saving cards is on. This site ran OC Cardcom Cards before version 0.6.0, so it keeps working in full with or without a key; the key is what brings updates.', 'oc-cardcom-cards' ) );
			case 'full':
				return array( '#15803d', __( 'Saving cards is on: the "remember my card" toggle is shown at checkout.', 'oc-cardcom-cards' ) );
		}
		return array( '#b32d2e', OCCC_Hub::off_text() );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( OCCC_Hub::CAP ) ) {
			return;
		}
		OCCC_Hub::refresh_if_stale();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['occc_hub'] ) ? sanitize_key( wp_unslash( $_GET['occc_hub'] ) ) : '';
		$error  = isset( $_GET['occc_err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['occc_err'] ) ) ) : '';
		// phpcs:enable

		$state    = OCCC_Hub::state();
		$ent      = OCCC_Hub::entitlement();
		$constant = OCCC_Hub::key_from_constant();
		$reason   = OCCC_Hub::status();
		$life_map = array(
			'valid'    => __( 'Valid', 'oc-cardcom-cards' ),
			'expiring' => __( 'Renews soon', 'oc-cardcom-cards' ),
			'grace'    => __( 'Grace days', 'oc-cardcom-cards' ),
			'expired'  => __( 'Expired', 'oc-cardcom-cards' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OC Pluginz account', 'oc-cardcom-cards' ); ?></h1>
			<div style="max-width:760px;">
				<?php if ( 'connected' === $notice ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'Connected. The licence is active on this site.', 'oc-cardcom-cards' ); ?></p></div>
				<?php elseif ( 'disconnected' === $notice ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'Disconnected. This site\'s seat on the licence is free again.', 'oc-cardcom-cards' ); ?></p></div>
				<?php elseif ( 'refreshed' === $notice ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'The licence status was updated.', 'oc-cardcom-cards' ); ?></p></div>
				<?php elseif ( 'updates' === $notice ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'Checked for updates. If there is a new version, it now shows on the Plugins screen.', 'oc-cardcom-cards' ); ?></p></div>
				<?php endif; ?>
				<?php if ( '' !== $error ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
				<?php endif; ?>

				<p class="description" style="max-width:680px">
					<?php esc_html_e( 'The plugin is free. The licence connects this site to OC Pluginz: it brings the plugin\'s updates and switches on saving new cards at checkout. Whatever the licence says, cards already saved keep working, and the checkout is never blocked.', 'oc-cardcom-cards' ); ?>
				</p>

				<?php if ( ! OCCC_Hub::agency() ) : ?>
					<?php $line = self::mode_line(); ?>
					<p style="font-size:14px;"><strong style="color:<?php echo esc_attr( $line[0] ); ?>"><?php echo esc_html( $line[1] ); ?></strong></p>
				<?php endif; ?>

				<?php if ( OCCC_Hub::agency() ) : ?>
					<div class="card" style="max-width:none;">
						<h2><?php esc_html_e( 'Agency mode', 'oc-cardcom-cards' ); ?></h2>
						<p><?php esc_html_e( 'OC_PLUGINZ_AGENCY is set in wp-config.php: this site does not use an OC Pluginz licence. Everything works in full, and the plugin does not contact OC Pluginz, so updates are installed by hand.', 'oc-cardcom-cards' ); ?></p>
					</div>
				<?php elseif ( '' === OCCC_Hub::key() ) : ?>
					<div class="card" style="max-width:none;">
						<h2><?php esc_html_e( 'Connect to OC Pluginz', 'oc-cardcom-cards' ); ?></h2>
						<p><strong style="color:#646970"><?php esc_html_e( 'Not connected', 'oc-cardcom-cards' ); ?></strong></p>
						<p><?php esc_html_e( 'Paste the licence key you received by email when you downloaded the plugin (the download is free). It also appears in your personal area at OC Pluginz.', 'oc-cardcom-cards' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'occc_hub_connect' ); ?>
							<input type="hidden" name="action" value="occc_hub_connect">
							<p>
								<label class="screen-reader-text" for="occc-license-key"><?php esc_html_e( 'Licence key', 'oc-cardcom-cards' ); ?></label>
								<input type="text" id="occc-license-key" name="license_key" class="regular-text code" dir="ltr" placeholder="ocpz_…" autocomplete="off" required style="direction:ltr;text-align:left;">
								<button class="button button-primary"><?php esc_html_e( 'Connect', 'oc-cardcom-cards' ); ?></button>
							</p>
						</form>
						<?php self::account_links(); ?>
					</div>
				<?php else : ?>
					<div class="card" style="max-width:none;">
						<h2><?php esc_html_e( 'OC Pluginz licence', 'oc-cardcom-cards' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Licence key', 'oc-cardcom-cards' ); ?></th>
								<td>
									<code dir="ltr"><?php echo esc_html( OCCC_Hub::masked( OCCC_Hub::key() ) ); ?></code>
									<?php if ( $constant ) : ?>
										<p class="description"><?php esc_html_e( 'Set in OCCC_LICENSE_KEY in wp-config.php.', 'oc-cardcom-cards' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Status', 'oc-cardcom-cards' ); ?></th>
								<td>
									<?php
									if ( $ent ) {
										$life = OCCC_Hub::lifecycle( $ent, time() );
										$ok   = OCCC_Hub::updates_allowed();
										$txt  = isset( $life_map[ $life ] ) ? $life_map[ $life ] : $life;
										if ( 'suspended' === $reason ) {
											$txt = __( 'Suspended', 'oc-cardcom-cards' );
										}
										echo '<strong style="color:' . ( $ok ? '#15803d' : '#b32d2e' ) . '">' . esc_html( $txt ) . '</strong>';
										if ( ! empty( $ent['expires_at'] ) && false !== strtotime( (string) $ent['expires_at'] ) ) {
											/* translators: %s: date */
											echo ' <span class="description">· ' . esc_html( sprintf( __( 'until %s', 'oc-cardcom-cards' ), date_i18n( get_option( 'date_format' ), strtotime( (string) $ent['expires_at'] ) ) ) ) . '</span>';
										} else {
											echo ' <span class="description">· ' . esc_html__( 'no end date', 'oc-cardcom-cards' ) . '</span>';
										}
									} elseif ( 'refused' === $reason ) {
										echo '<strong style="color:#b32d2e">' . esc_html__( 'Not accepted by OC Pluginz', 'oc-cardcom-cards' ) . '</strong>';
									} else {
										echo '<strong style="color:#b45309">' . esc_html__( 'Not confirmed yet', 'oc-cardcom-cards' ) . '</strong>';
									}
									if ( ! empty( $state['error'] ) ) {
										echo '<p class="description" style="color:#b32d2e">' . esc_html( (string) $state['error'] ) . '</p>';
									}
									?>
								</td>
							</tr>
							<?php if ( $ent ) : ?>
								<?php if ( ! empty( $ent['plan'] ) ) : ?>
									<tr>
										<th scope="row"><?php esc_html_e( 'Plan', 'oc-cardcom-cards' ); ?></th>
										<td><code><?php echo esc_html( (string) $ent['plan'] ); ?></code></td>
									</tr>
								<?php endif; ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Sites', 'oc-cardcom-cards' ); ?></th>
									<td>
										<?php
										$sites = ( isset( $ent['sites'] ) && is_array( $ent['sites'] ) ) ? $ent['sites'] : array();
										$seats = isset( $ent['seats'] ) ? (int) $ent['seats'] : 1;
										if ( ! $sites ) {
											echo '<span class="description">—</span>';
										}
										foreach ( $sites as $site ) {
											$d  = isset( $site['domain'] ) ? (string) $site['domain'] : '';
											$me = ( OCCC_Hub::domain() === $d );
											echo '<div><code dir="ltr">' . esc_html( $d ) . '</code>' . ( $me ? ' <span class="description">' . esc_html__( '(this site)', 'oc-cardcom-cards' ) . '</span>' : '' ) . '</div>';
										}
										/* translators: 1: sites in use, 2: sites the licence allows */
										echo '<p class="description">' . esc_html( sprintf( __( '%1$d of %2$d sites in use.', 'oc-cardcom-cards' ), count( $sites ), max( 1, $seats ) ) ) . '</p>';
										?>
									</td>
								</tr>
							<?php endif; ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Updates', 'oc-cardcom-cards' ); ?></th>
								<td>
									<?php
									$latest = isset( $state['latest'] ) ? (string) $state['latest'] : '';
									/* translators: %s: version number */
									echo esc_html( sprintf( __( 'Installed: %s', 'oc-cardcom-cards' ), OCCC_VERSION ) );
									if ( '' !== $latest ) {
										/* translators: %s: version number */
										echo ' · ' . esc_html( sprintf( __( 'Latest at OC Pluginz: %s', 'oc-cardcom-cards' ), $latest ) );
										if ( version_compare( $latest, OCCC_VERSION, '>' ) ) {
											echo ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Update from the Plugins screen', 'oc-cardcom-cards' ) . '</a>';
										}
									}
									?>
									<p class="description"><?php esc_html_e( 'New versions are offered on the Plugins screen while the licence is valid.', 'oc-cardcom-cards' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Last check', 'oc-cardcom-cards' ); ?></th>
								<td>
									<?php
									$at = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;
									echo esc_html( $at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at ) : '—' );
									?>
									<p class="description"><?php esc_html_e( 'The licence is checked again every hour, and every time this screen opens.', 'oc-cardcom-cards' ); ?></p>
								</td>
							</tr>
						</table>
						<div style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'occc_hub_refresh' ); ?>
								<input type="hidden" name="action" value="occc_hub_refresh">
								<button class="button"><?php esc_html_e( 'Check now', 'oc-cardcom-cards' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( OCCC_Hub_Updater::ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( OCCC_Hub_Updater::ACTION ); ?>">
								<button class="button"><?php esc_html_e( 'Check for updates', 'oc-cardcom-cards' ); ?></button>
							</form>
							<?php if ( ! $constant ) : ?>
								<?php
								$bye = OCCC_Hub::legacy()
									? __( 'Disconnect this site from the licence? Updates will stop until a key is connected again. Saving cards keeps working.', 'oc-cardcom-cards' )
									: __( 'Disconnect this site from the licence? The "remember my card" toggle will no longer be shown at checkout and no new cards will be saved, until a key is connected again. Cards already saved keep working.', 'oc-cardcom-cards' );
								?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( $bye ); ?>');">
									<?php wp_nonce_field( 'occc_hub_disconnect' ); ?>
									<input type="hidden" name="action" value="occc_hub_disconnect">
									<button class="button button-link-delete"><?php esc_html_e( 'Disconnect this site', 'oc-cardcom-cards' ); ?></button>
								</form>
							<?php endif; ?>
						</div>
						<?php self::account_links(); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The two links under the licence card: the account at the hub, and where a free key comes from.
	 *
	 * @return void
	 */
	private static function account_links() {
		?>
		<p class="description">
			<a href="<?php echo esc_url( OCCC_Hub::ACCOUNT_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Your OC Pluginz account', 'oc-cardcom-cards' ); ?></a>
			&nbsp;·&nbsp;
			<?php esc_html_e( 'No key yet? It is free:', 'oc-cardcom-cards' ); ?>
			<a href="<?php echo esc_url( OCCC_Hub::PRICING_URL ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( OCCC_Hub::PRICING_URL ); ?></a>
		</p>
		<?php
	}
}
