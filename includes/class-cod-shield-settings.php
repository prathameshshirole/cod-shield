<?php
/**
 * Settings tab under WooCommerce > Settings > SureOrder.
 *
 * Implemented as a WC_Settings_Page subclass (the official way to add a new
 * top-level tab). Saving is handled by WC_Admin_Settings::save_fields();
 * custom sanitization is applied via woocommerce_admin_settings_sanitize_option_*
 * filters.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page.
 */
class Settings extends \WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'cod_shield';
		$this->label = __( 'SureOrder', 'cod-shield' );

		parent::__construct();

		// Preserve the existing amount options and single gateway selection.
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FIXED, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_PERCENTAGE, array( $this, 'sanitize_percentage' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_GATEWAYS, array( $this, 'sanitize_gateways' ), 10, 3 );

		add_action( 'woocommerce_admin_field_cod_shield_gateway_select', array( $this, 'handle_gateway_select_field' ) );
		add_action( 'woocommerce_admin_field_cod_shield_configuration_status', array( $this, 'handle_configuration_status_field' ) );
		add_action( 'admin_notices', array( $this, 'handle_admin_configuration_notices' ) );

		// Load the admin stylesheet on the SureOrder settings tab AND on the
		// order screens: the stylesheet styles the COD Advance column badges
		// and the advance metabox, which live on the orders list / order edit
		// screen, not just on this settings tab.
		add_action(
			'admin_enqueue_scripts',
			static function ( $hook_suffix ) {
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection; no state change.
				$tab       = isset( $_GET['tab'] ) ? sanitize_title( wp_unslash( $_GET['tab'] ) ) : '';
				$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
				$post_id   = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
				// phpcs:enable

				$is_settings_tab = ( 'woocommerce_page_wc-settings' === $hook_suffix && 'cod_shield' === $tab );
				$is_legacy_list  = ( 'edit.php' === $hook_suffix && 'shop_order' === $post_type );
				$is_legacy_edit  = ( 'post.php' === $hook_suffix && 'shop_order' === get_post_type( $post_id ) );
				$is_hpos         = in_array( $hook_suffix, array( 'woocommerce_page_wc-orders' ), true );

				if ( ! ( $is_settings_tab || $is_legacy_list || $is_legacy_edit || $is_hpos ) ) {
					return;
				}

				wp_enqueue_style( 'cod-shield-admin', COD_SHIELD_URL . 'admin/css/admin.css', array(), COD_SHIELD_VERSION );
				if ( $is_settings_tab ) {
					wp_enqueue_script(
						'cod-shield-admin-settings',
						COD_SHIELD_URL . 'admin/js/settings.js',
						array( 'jquery' ),
						COD_SHIELD_VERSION,
						true
					);
				}
			}
		);
	}

	/**
	 * Return only the four-control setup. Retired section URLs cannot save rules.
	 *
	 * @param string $section Section slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings( $section = '' ) {
		$settings = '' === $section ? $this->get_settings_for_default_section() : array();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce settings extension filter.
		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings, $section );
	}

	/**
	 * One setup screen, without optional rule sections.
	 *
	 * @return array<string,string>
	 */
	protected function get_own_sections() {
		return array( '' => __( 'Setup', 'cod-shield' ) );
	}

	/**
	 * Setup section: enable, calculation type, value, and existing gateway.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
		$gateway_options = array(
			'' => __( 'Select a supported gateway…', 'cod-shield' ),
		) + $this->get_delegation_gateway_options();

		return array(
			array(
				// The section `title` of a WC settings page is rendered with
				// esc_html() by WC_Admin_Settings::output_fields(), so HTML in
				// it prints as literal text (the v2.0.0 banner regression). The
				// logo therefore lives in `desc`, which WC runs through
				// wp_kses_post() and renders below the heading. The asset is
				// served from admin/img/, which ships with the plugin — never
				// from .wordpress-org/, the WP.org listing-artwork folder that
				// is not part of an installed plugin.
				'title' => __( 'Set up SureOrder', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => '<img src="' . esc_url( COD_SHIELD_URL . 'admin/img/icon-128x128.png' ) . '" alt="" class="cod-shield-settings-logo" /> ' . __( 'Collect a fixed or percentage advance through an existing payment gateway, with the remaining balance due on delivery.', 'cod-shield' ),
				'id'    => 'cod_shield_general',
			),
			array(
				'type' => 'cod_shield_configuration_status',
				'id'   => 'cod_shield_configuration_status',
			),
			array(
				'title'   => __( 'Enable partial COD', 'cod-shield' ),
				'desc'    => __( 'Collect an advance on COD orders. Turning this off leaves WooCommerce COD unchanged.', 'cod-shield' ),
				'id'      => Core::OPT_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes' === get_option( Core::OPT_ENABLED, 'yes' ) && 'disabled' !== get_option( Core::OPT_MODE, 'disabled' ) ? 'yes' : 'no',
			),
			array(
				'title'    => __( 'Advance type', 'cod-shield' ),
				'id'       => Core::OPT_MODE,
				'type'     => 'select',
				'default'  => 'fixed',
				'value'    => 'percentage' === get_option( Core::OPT_MODE, 'disabled' ) ? 'percentage' : 'fixed',
				'options'  => array(
					'fixed'      => __( 'Fixed amount', 'cod-shield' ),
					'percentage' => __( 'Percentage of order total', 'cod-shield' ),
				),
				'desc_tip' => __( 'Choose how the upfront payment is calculated.', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Advance value', 'cod-shield' ),
				'id'                => Core::OPT_FIXED,
				'type'              => 'number',
				'default'           => 99,
				'row_class'         => 'cod-shield-setup-subfield cod-shield-fixed-field',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => 'any',
				),
				'desc_tip'          => __( 'Amount in the store currency. The advance cannot exceed the order total.', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Advance value (%)', 'cod-shield' ),
				'id'                => Core::OPT_PERCENTAGE,
				'type'              => 'number',
				'default'           => 10,
				'row_class'         => 'cod-shield-setup-subfield cod-shield-percentage-field',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => 'any',
				),
				'desc_tip'          => __( 'Percentage of the final order total after discounts, including shipping and taxes.', 'cod-shield' ),
			),
			array(
				'title'    => __( 'Advance gateway', 'cod-shield' ),
				'id'       => Core::OPT_GATEWAYS,
				'type'     => 'cod_shield_gateway_select',
				'class'    => 'wc-enhanced-select cod-shield-gateway-select',
				'css'      => 'width:100%;max-width:350px;',
				'default'  => array(),
				'options'  => $gateway_options,
				'desc_tip' => __( 'Only explicitly supported gateway plugins are shown. Test the installed gateway version before going live.', 'cod-shield' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_general',
			),
		);
	}

	/**
	 * Render the one-gateway selector while preserving array option storage.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @return void
	 */
	public function handle_gateway_select_field( $field ) {
		$options     = (array) ( $field['options'] ?? array() );
		$selected_id = Core::resolve_delegation_gateway_id( get_option( Core::OPT_GATEWAYS, array() ) );
		$field_name  = (string) ( $field['field_name'] ?? Core::OPT_GATEWAYS );
		$description = (string) ( $field['desc'] ?? $field['desc_tip'] ?? '' );
		?>
		<tr class="<?php echo esc_attr( (string) ( $field['row_class'] ?? '' ) ); ?>">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( Core::OPT_GATEWAYS ); ?>"><?php echo esc_html( (string) ( $field['title'] ?? '' ) ); ?></label>
			</th>
			<td class="forminp forminp-select">
				<select
					name="<?php echo esc_attr( $field_name ); ?>"
					id="<?php echo esc_attr( Core::OPT_GATEWAYS ); ?>"
					class="<?php echo esc_attr( (string) ( $field['class'] ?? '' ) ); ?>"
					style="<?php echo esc_attr( (string) ( $field['css'] ?? '' ) ); ?>"
				>
					<?php foreach ( $options as $gateway_id => $gateway_label ) : ?>
						<option value="<?php echo esc_attr( (string) $gateway_id ); ?>" <?php echo selected( $selected_id, (string) $gateway_id, false ); ?>><?php echo esc_html( (string) $gateway_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the setup health panel.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $field Field definition (unused).
	 * @return void
	 */
	public function handle_configuration_status_field( $field ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WooCommerce custom-field callback signature.
		$status = $this->get_configuration_status();
		if ( ! $status['shield_enabled'] ) {
			$summary       = __( 'Partial COD is off — native COD is unchanged', 'cod-shield' );
			$summary_class = 'muted';
		} else {
			$summary       = $status['ready'] ? __( 'Configuration saved', 'cod-shield' ) : __( 'Needs attention', 'cod-shield' );
			$summary_class = $status['ready'] ? 'ready' : 'warning';
		}
		?>
		<tr class="cod-shield-configuration-row">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Configuration status', 'cod-shield' ); ?></th>
			<td class="forminp">
				<div class="cod-shield-configuration cod-shield-configuration--<?php echo esc_attr( $summary_class ); ?>" role="status">
					<strong class="cod-shield-configuration__summary"><?php echo esc_html( $summary ); ?></strong>
					<ul>
						<li class="cod-shield-check cod-shield-check--<?php echo $status['shield_enabled'] ? 'ready' : 'warning'; ?>">
							<span><?php esc_html_e( 'SureOrder', 'cod-shield' ); ?></span>
							<strong><?php echo esc_html( $status['shield_enabled'] ? __( 'Enabled', 'cod-shield' ) : __( 'Disabled', 'cod-shield' ) ); ?></strong>
						</li>
						<li class="cod-shield-check cod-shield-check--<?php echo $status['native_cod_enabled'] ? 'ready' : 'warning'; ?>">
							<span><?php esc_html_e( 'WooCommerce COD gateway', 'cod-shield' ); ?></span>
							<strong><?php echo esc_html( $status['native_cod_enabled'] ? __( 'Enabled', 'cod-shield' ) : __( 'Disabled', 'cod-shield' ) ); ?></strong>
						</li>
						<li class="cod-shield-check cod-shield-check--<?php echo esc_attr( $status['gateway_state'] ); ?>">
							<span><?php esc_html_e( 'Advance gateway', 'cod-shield' ); ?></span>
							<strong><?php echo esc_html( $status['gateway_label'] ); ?></strong>
						</li>
					</ul>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Show configuration warnings on the SureOrder settings screen.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function handle_admin_configuration_notices() {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers the manage_woocommerce capability.
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->is_cod_shield_settings_screen() ) {
			return;
		}

		$status = $this->get_configuration_status();
		if ( $status['shield_enabled'] && ! $status['native_cod_enabled'] ) {
			$cod_settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=cod' );
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>' . esc_html__( 'SureOrder needs attention:', 'cod-shield' ) . '</strong> ';
			echo esc_html__( 'WooCommerce Cash on Delivery is disabled, so COD cannot appear at checkout.', 'cod-shield' ) . ' ';
			echo '<a href="' . esc_url( $cod_settings_url ) . '">' . esc_html__( 'Enable the COD gateway', 'cod-shield' ) . '</a>';
			echo '</p></div>';
		}

		if ( $status['advance_enabled'] && ! $status['gateway_ready'] ) {
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>' . esc_html__( 'Advance setup is incomplete:', 'cod-shield' ) . '</strong> ';
			echo esc_html__( 'Advance payment is enabled, but no eligible gateway is selected. Choose and test an advance gateway, or turn partial COD off.', 'cod-shield' );
			echo '</p></div>';
		}
	}

	/**
	 * Sanitize a non-negative number.
	 *
	 * @param mixed $value     Sanitized-by-type value (unused).
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Raw posted value.
	 * @return float
	 */
	public function sanitize_positive_number( $value, $option, $raw_value ) {
		return is_scalar( $raw_value ) && is_numeric( $raw_value ) && is_finite( (float) $raw_value ) ? max( 0.0, (float) $raw_value ) : 0.0;
	}

	/**
	 * Sanitize a percentage (0-100).
	 *
	 * @param mixed $value      Sanitized-by-type value (unused).
	 * @param array $option     Field definition.
	 * @param mixed $raw_value  Raw posted value.
	 * @return float
	 */
	public function sanitize_percentage( $value, $option, $raw_value ) {
		return min( 100.0, $this->sanitize_positive_number( $value, $option, $raw_value ) );
	}

	/**
	 * Sanitize the gateway selector to one compatible enabled gateway.
	 *
	 * Older versions posted an array from a multiselect. Both shapes remain
	 * accepted, but the option continues to be stored as a one-item array so
	 * existing runtime readers remain compatible.
	 *
	 * @param mixed $value     Sanitized-by-type value (unused).
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Raw posted value.
	 * @return array<string>
	 */
	public function sanitize_gateways( $value, $option, $raw_value ) {
		$valid      = array_fill_keys( array_keys( $this->get_delegation_gateway_options() ), true );
		$candidates = is_array( $raw_value ) ? $raw_value : array( $raw_value );

		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$gateway_id = sanitize_title( (string) $candidate );
			if ( '' !== $gateway_id && isset( $valid[ $gateway_id ] ) ) {
				return array( $gateway_id );
			}
		}

		return array();
	}

	/**
	 * Build the selector options from compatible enabled gateways.
	 *
	 * @return array<string,string>
	 */
	private function get_delegation_gateway_options() {
		$options           = array();
		$supported_classes = apply_filters( 'cod_shield_supported_gateway_classes', Core::supported_gateway_classes() );
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$status = Core::assess_gateway_compatibility(
					$id,
					array(
						'id'                        => $gateway->id,
						'enabled'                   => $gateway->enabled,
						'has_fields'                => $gateway->has_fields,
						'supports'                  => $gateway->supports,
						'can_process'               => is_callable( array( $gateway, 'process_payment' ) ),
						'gateway_class'             => get_class( $gateway ),
						'is_direct'                 => is_a( $gateway, '\\WC_Payment_Gateway_CC' )
							|| is_a( $gateway, '\\WC_Payment_Gateway_ECheck' ),
						'payment_action'            => is_callable( array( $gateway, 'get_option' ) ) ? $gateway->get_option( 'payment_action', '' ) : '',
						'route_enabled'             => is_callable( array( $gateway, 'get_option' ) ) ? $gateway->get_option( 'route_enable', 'no' ) : 'no',
						'supported_gateway_classes' => is_array( $supported_classes ) ? $supported_classes : Core::supported_gateway_classes(),
					)
				);
				if ( ! $status['compatible'] ) {
					continue;
				}
				$options[ $id ] = $gateway->get_title() . ' (' . $id . ')';
			}
		}
		return $options;
	}

	/**
	 * Get the current setup health state.
	 *
	 * @return array{ready:bool,shield_enabled:bool,native_cod_enabled:bool,advance_enabled:bool,gateway_ready:bool,gateway_state:string,gateway_label:string}
	 */
	private function get_configuration_status() {
		$gateway_options    = $this->get_delegation_gateway_options();
		$selected_gateway   = Core::resolve_delegation_gateway_id( get_option( Core::OPT_GATEWAYS, array() ) );
		$advance_enabled    = 'yes' === get_option( Core::OPT_ENABLED, 'yes' ) && in_array( get_option( Core::OPT_MODE, 'disabled' ), array( 'fixed', 'percentage' ), true );
		$gateway_ready      = '' !== $selected_gateway && isset( $gateway_options[ $selected_gateway ] );
		$shield_enabled     = $advance_enabled;
		$native_cod_enabled = Core::instance()->native_cod_enabled();

		if ( ! $advance_enabled ) {
			$gateway_state = 'muted';
			$gateway_label = __( 'Not required — plain COD', 'cod-shield' );
		} elseif ( $gateway_ready ) {
			$gateway_state = 'ready';
			$gateway_label = $gateway_options[ $selected_gateway ];
		} else {
			$gateway_state = 'warning';
			$gateway_label = __( 'Select and test a gateway', 'cod-shield' );
		}

		return array(
			'ready'              => $shield_enabled && $native_cod_enabled && ( ! $advance_enabled || $gateway_ready ),
			'shield_enabled'     => $shield_enabled,
			'native_cod_enabled' => $native_cod_enabled,
			'advance_enabled'    => $advance_enabled,
			'gateway_ready'      => $gateway_ready,
			'gateway_state'      => $gateway_state,
			'gateway_label'      => $gateway_label,
		);
	}

	/**
	 * Whether the current admin screen is the SureOrder settings tab.
	 *
	 * @return bool
	 */
	private function is_cod_shield_settings_screen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection; no state change.
		$page = isset( $_GET['page'] ) ? sanitize_title( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_title( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable

		return 'wc-settings' === $page && $this->id === $tab;
	}
}
