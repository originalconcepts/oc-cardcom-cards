<?php
/**
 * Settings: storage + admin page.
 *
 * Single option (occc_settings), plug-and-play. The __submitted guard stops a
 * stale/partial form from wiping fields it never rendered.
 *
 * @package OC_Cardcom_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCCC_Settings {

	const OPTION = 'occc_settings';
	const GROUP  = 'occc_settings_group';
	const PAGE   = 'oc-cardcom-cards';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . OCCC_PLUGIN_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Field schema.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			// --- Feature ---
			'enabled'           => array(
				'section' => 'general',
				'label'   => __( 'Enable saved credit cards', 'oc-cardcom-cards' ),
				'type'    => 'checkbox',
				'default' => 1,
				'desc'    => __( 'Master switch for the "remember my card" feature. Turn off to hide the toggle everywhere without deactivating the plugin.', 'oc-cardcom-cards' ),
			),
			'gateway_id'        => array(
				'section' => 'general',
				'label'   => __( 'Cardcom gateway id', 'oc-cardcom-cards' ),
				'type'    => 'text',
				'default' => 'cardcom',
				'desc'    => __( "The WooCommerce id of Cardcom's payment method. Leave as 'cardcom' unless it was renamed.", 'oc-cardcom-cards' ),
			),
			'debug_logging'     => array(
				'section' => 'general',
				'label'   => __( 'Enable debug logging (redacted, protected folder)', 'oc-cardcom-cards' ),
				'type'    => 'checkbox',
				'default' => 0,
			),

			// --- Checkout toggle ---
			'toggle_title'      => array(
				'section' => 'toggle',
				'label'   => __( 'Title', 'oc-cardcom-cards' ),
				'type'    => 'text',
				'default' => __( 'Save credit card', 'oc-cardcom-cards' ),
			),
			'toggle_label'      => array(
				'section' => 'toggle',
				'label'   => __( 'Toggle label', 'oc-cardcom-cards' ),
				'type'    => 'text',
				'default' => __( 'Remember my card for next time', 'oc-cardcom-cards' ),
			),
			'toggle_description' => array(
				'section' => 'toggle',
				'label'   => __( 'Description (shown under the toggle)', 'oc-cardcom-cards' ),
				'type'    => 'textarea',
				'default' => __( 'Your card is stored securely by Cardcom (a token, not the card number). You can remove it at any time from your account.', 'oc-cardcom-cards' ),
			),
			'default_on'        => array(
				'section' => 'toggle',
				'label'   => __( 'Toggle on by default', 'oc-cardcom-cards' ),
				'type'    => 'checkbox',
				'default' => 1,
				'desc'    => __( 'The "remember my card" toggle starts on at checkout.', 'oc-cardcom-cards' ),
			),
			'promote_saved_cards' => array(
				'section' => 'toggle',
				'label'   => __( 'Show saved cards as separate payment methods', 'oc-cardcom-cards' ),
				'type'    => 'checkbox',
				'default' => 1,
				'desc'    => __( 'When on, each saved card appears as its own option in the payment list (with "New credit card" below), instead of nested under Cardcom. The charge is still processed by Cardcom (J5 + invoice).', 'oc-cardcom-cards' ),
			),
		);
	}

	/**
	 * Section metadata.
	 *
	 * @return array
	 */
	private static function sections() {
		return array(
			'general' => __( 'General', 'oc-cardcom-cards' ),
			'toggle'  => __( 'Checkout Toggle', 'oc-cardcom-cards' ),
		);
	}

	/* --------------------------------------------------------------------- *
	 * Storage helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Get one setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		if ( null === self::$cache ) {
			self::$cache = get_option( self::OPTION, array() );
			if ( ! is_array( self::$cache ) ) {
				self::$cache = array();
			}
		}
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}
		$fields = self::fields();
		return isset( $fields[ $key ]['default'] ) ? $fields[ $key ]['default'] : null;
	}

	/**
	 * Get a boolean setting.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function get_bool( $key ) {
		return (bool) self::get( $key );
	}

	/* --------------------------------------------------------------------- *
	 * Admin page
	 * --------------------------------------------------------------------- */

	/**
	 * Add the settings page under WooCommerce.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'OC Cardcom Cards', 'oc-cardcom-cards' ),
			__( 'OC Cardcom Cards', 'oc-cardcom-cards' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the option and its sanitisation.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	/**
	 * Sanitise submitted settings, preserving any field the form didn't submit.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$existing = get_option( self::OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		if ( empty( $input['__submitted'] ) ) {
			return $existing;
		}

		$clean  = $existing;
		$fields = self::fields();

		foreach ( $fields as $key => $field ) {
			if ( 'checkbox' === $field['type'] ) {
				$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
				continue;
			}
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$clean[ $key ] = ( 'textarea' === $field['type'] )
				? sanitize_textarea_field( $input[ $key ] )
				: sanitize_text_field( $input[ $key ] );
		}

		return $clean;
	}

	/**
	 * Add a "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'oc-cardcom-cards' ) . '</a>' );
		return $links;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		$fields   = self::fields();
		$sections = self::sections();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OC Cardcom Cards', 'oc-cardcom-cards' ); ?></h1>
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'Companion to the Cardcom payment plugin. It adds a "remember my card" toggle at checkout and saves the token Cardcom mints under Capture Charge (J5) as a reusable saved card. Cardcom keeps handling the charge, J5 capture and invoicing.', 'oc-cardcom-cards' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[__submitted]" value="1" />
				<?php foreach ( $sections as $section_id => $section_label ) : ?>
					<h2><?php echo esc_html( $section_label ); ?></h2>
					<table class="form-table" role="presentation"><tbody>
						<?php
						foreach ( $fields as $key => $field ) {
							if ( $field['section'] === $section_id ) {
								self::render_field( $key, $field );
							}
						}
						?>
					</tbody></table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single field row.
	 *
	 * @param string $key   Field key.
	 * @param array  $field Field definition.
	 * @return void
	 */
	private static function render_field( $key, $field ) {
		$name  = self::OPTION . '[' . $key . ']';
		$id    = 'occc_' . $key;
		$value = self::get( $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

		switch ( $field['type'] ) {
			case 'checkbox':
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( 1, (int) $value, false ) . ' />';
				break;
			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
		}

		if ( ! empty( $field['desc'] ) ) {
			echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
		}
		echo '</td></tr>';
	}
}
