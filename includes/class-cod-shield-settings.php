<?php
/**
 * Settings tab under WooCommerce > Settings > COD Shield.
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
		$this->label = __( 'COD Shield', 'cod-shield' );

		parent::__construct();

		// Custom sanitization for numeric / percentage / gateway fields.
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FIXED, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_PERCENTAGE, array( $this, 'sanitize_percentage' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_MIN, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_MAX, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_THRESHOLD, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_GATEWAYS, array( $this, 'sanitize_gateways' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FEE_AMOUNT, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FEE_PERCENTAGE, array( $this, 'sanitize_percentage' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FEE_TAX_CLASS, array( $this, 'sanitize_tax_class' ), 10, 3 );

		// Load the admin stylesheet on the COD Shield settings tab AND on the
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
			}
		);
	}

	/**
	 * Get the settings for a section.
	 *
	 * Overrides WC_Settings_Page::get_settings() (which otherwise builds the
	 * method name as `get_settings_for_{section}_section`) so hyphenated
	 * section slugs resolve to their underscore-form method. Without this,
	 * the "Extra COD Fee" (cod-fee) and "Order Handling" (order-handling)
	 * tabs render empty because a PHP method name cannot contain a hyphen and
	 * WC falls through to the empty section registry.
	 *
	 * Both WC_Admin_Settings::output() and the save flow call this method, so
	 * the override fixes display and saving. The woocommerce_get_settings_
	 * filter is preserved for third-party extensions.
	 *
	 * @param string $section Section slug ('' for the default section).
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings( $section = '' ) {
		$method = Core::settings_method_for_section( $section );
		if ( method_exists( $this, $method ) ) {
			$settings = $this->$method();
		} else {
			$settings = array(); // Unknown section — nothing to render.
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's documented settings filter (woocommerce_get_settings_{id}); the prefix is dictated by WC core.
		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings, $section );
	}

	/**
	 * Sections.
	 *
	 * @return array<string,string>
	 */
	protected function get_own_sections() {
		return array(
			''               => __( 'General', 'cod-shield' ),
			'advance'        => __( 'Advance Payment', 'cod-shield' ),
			// Underscore slugs: WC builds the settings method as
			// get_settings_for_{section}_section, and a PHP method name cannot
			// contain a hyphen. Underscore slugs resolve natively even if WC ever
			// drops the deprecated get_settings() path the hyphen-mapping
			// override rides on (see Core::settings_method_for_section()).
			'cod_fee'        => __( 'Extra COD Fee', 'cod-shield' ),
			'messaging'      => __( 'Messaging', 'cod-shield' ),
			'order_handling' => __( 'Order Handling', 'cod-shield' ),
		);
	}

	/**
	 * General section: the global COD kill switch.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
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
				'title' => __( 'COD Shield', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => '<img src="' . esc_url( COD_SHIELD_URL . 'admin/img/icon-128x128.png' ) . '" alt="" class="cod-shield-settings-logo" /> ' . __( 'Global control for Cash on Delivery. This switch is independent of the plugin activation state and of WooCommerce\'s own COD gateway settings.', 'cod-shield' ),
				'id'    => 'cod_shield_general',
			),
			array(
				'title'   => __( 'Enable Cash on Delivery', 'cod-shield' ),
				'desc'    => __( 'Allow COD orders on the storefront. Turn this off to instantly hide COD from checkout — no cache flush or gateway settings needed.', 'cod-shield' ),
				'id'      => Core::OPT_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_general',
			),
		);
	}

	/**
	 * Advance Payment section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_advance_section() {
		$gateway_options = $this->get_delegation_gateway_options();

		return array(
			array(
				'title' => __( 'Advance Payment', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => __( 'Collect a partial payment upfront on COD orders to filter out low-intent buyers. The advance is charged through one of your existing enabled gateways; the remaining amount is payable on delivery. All amounts are calculated server-side at checkout.', 'cod-shield' ),
				'id'    => 'cod_shield_advance',
			),
			array(
				'title'    => __( 'Advance mode', 'cod-shield' ),
				'id'       => Core::OPT_MODE,
				'type'     => 'select',
				'default'  => 'disabled',
				'options'  => array(
					'disabled'   => __( 'Disabled — plain COD', 'cod-shield' ),
					'fixed'      => __( 'Fixed amount (₹)', 'cod-shield' ),
					'percentage' => __( 'Percentage of order total', 'cod-shield' ),
				),
				'desc_tip' => __( 'How much to collect upfront on every applicable COD order.', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Fixed advance amount', 'cod-shield' ),
				'id'                => Core::OPT_FIXED,
				'type'              => 'number',
				'default'           => 99,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => __( 'Flat amount charged upfront on every COD order (when mode is Fixed).', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Advance percentage', 'cod-shield' ),
				'id'                => Core::OPT_PERCENTAGE,
				'type'              => 'number',
				'default'           => 10,
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '1',
				),
				'desc_tip'          => __( 'Percentage of the order total charged upfront (when mode is Percentage).', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Minimum advance', 'cod-shield' ),
				'id'                => Core::OPT_MIN,
				'type'              => 'number',
				'default'           => 49,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => __( 'Never charge less than this, even when the percentage would be lower. Leave 0 for no minimum.', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Maximum advance', 'cod-shield' ),
				'id'                => Core::OPT_MAX,
				'type'              => 'number',
				'default'           => 999,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => __( 'Never charge more than this, even when the percentage would be higher. Leave 0 for no maximum.', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Order value threshold', 'cod-shield' ),
				'id'                => Core::OPT_THRESHOLD,
				'type'              => 'number',
				// Off by default: a non-zero default would hide small COD orders
				// the moment the plugin is activated (see Core::get_defaults()).
				'default'           => 0,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => __( 'Minimum order value for Cash on Delivery. Orders below this amount cannot choose COD at all; orders at or above it follow the advance rules. Leave 0 for no minimum.', 'cod-shield' ),
			),
			array(
				'title'    => __( 'Gateways allowed for the advance', 'cod-shield' ),
				'id'       => Core::OPT_GATEWAYS,
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width:350px;',
				'default'  => array(),
				'options'  => $gateway_options,
				'desc_tip' => __( 'Which of your enabled gateways may collect the advance (e.g. Razorpay). At least one must be selected for the advance flow to work.', 'cod-shield' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_advance',
			),
		);
	}

	/**
	 * Extra COD Fee section (features.md).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_cod_fee_section() {
		$tax_options = array( '' => __( 'Standard', 'cod-shield' ) );
		if ( function_exists( 'wc_get_product_tax_class_options' ) ) {
			$tax_options = wc_get_product_tax_class_options();
		}

		return array(
			array(
				'title' => __( 'Extra COD Fee', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => __( 'Charge an additional fee on COD orders. The fee applies to both plain COD orders and COD orders with an advance. On the block checkout the fee is applied to the order at placement, so it appears in the final total rather than in the cart preview.', 'cod-shield' ),
				'id'    => 'cod_shield_fee',
			),
			array(
				'title'   => __( 'Enable extra COD fee', 'cod-shield' ),
				'desc'    => __( 'Add a fee to orders paid by Cash on Delivery.', 'cod-shield' ),
				'id'      => Core::OPT_FEE_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => __( 'Fee type', 'cod-shield' ),
				'id'       => Core::OPT_FEE_TYPE,
				'type'     => 'select',
				'default'  => 'fixed',
				'options'  => array(
					'fixed'      => __( 'Fixed amount (₹)', 'cod-shield' ),
					'percentage' => __( 'Percentage of order total', 'cod-shield' ),
				),
				'desc_tip' => __( 'How the COD fee is calculated.', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Fee amount', 'cod-shield' ),
				'id'                => Core::OPT_FEE_AMOUNT,
				'type'              => 'number',
				'default'           => 30,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => __( 'Flat fee in ₹ (when type is Fixed).', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Fee percentage', 'cod-shield' ),
				'id'                => Core::OPT_FEE_PERCENTAGE,
				'type'              => 'number',
				'default'           => 2,
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '0.1',
				),
				'desc_tip'          => __( 'Percentage of the order total (when type is Percentage).', 'cod-shield' ),
			),
			array(
				'title'   => __( 'Enable tax rates on the fee', 'cod-shield' ),
				'desc'    => __( 'Apply WooCommerce tax rates to the extra COD fee.', 'cod-shield' ),
				'id'      => Core::OPT_FEE_TAXABLE,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => __( 'Fee tax class', 'cod-shield' ),
				'id'       => Core::OPT_FEE_TAX_CLASS,
				'type'     => 'select',
				'default'  => '',
				'options'  => $tax_options,
				'desc_tip' => __( 'Tax class used when tax rates are enabled on the fee.', 'cod-shield' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_fee',
			),
		);
	}

	/**
	 * Messaging section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_messaging_section() {
		return array(
			array(
				'title' => __( 'Checkout Messaging', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => __( 'Messages shown near the COD payment method at checkout. Each can be disabled independently of the underlying logic. Available placeholders: {advance_amount}, {remaining_amount}, {percentage}.', 'cod-shield' ),
				'id'    => 'cod_shield_messaging',
			),
			array(
				'title'   => __( 'Advance message', 'cod-shield' ),
				'desc'    => __( 'Show the advance notice when an advance is required.', 'cod-shield' ),
				'id'      => Core::OPT_MSG_ADV_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Advance message text', 'cod-shield' ),
				'id'      => Core::OPT_MSG_ADV_TEXT,
				'type'    => 'textarea',
				'default' => __( 'Pay {advance_amount} now, {remaining_amount} on delivery (COD).', 'cod-shield' ),
				'css'     => 'min-width:500px; min-height:80px;',
			),
			array(
				'title'   => __( 'Risk-free message', 'cod-shield' ),
				'desc'    => __( 'Show the trust-building message when no advance applies.', 'cod-shield' ),
				'id'      => Core::OPT_MSG_FREE_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Risk-free message text', 'cod-shield' ),
				'id'      => Core::OPT_MSG_FREE_TEXT,
				'type'    => 'textarea',
				'default' => __( 'Cash on Delivery available — no advance payment required.', 'cod-shield' ),
				'css'     => 'min-width:500px; min-height:80px;',
			),
			array(
				'title' => __( 'Order Emails', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => __( 'Append an advance-payment summary to WooCommerce order emails (customer and admin). It renders only after the advance has been collected. Placeholders: {advance_amount}, {remaining_amount}, {gateway}.', 'cod-shield' ),
				'id'    => 'cod_shield_messaging_email',
			),
			array(
				'title'   => __( 'Show advance summary in emails', 'cod-shield' ),
				'desc'    => __( 'Append the paid advance and due-on-delivery amounts to order emails.', 'cod-shield' ),
				'id'      => Core::OPT_MSG_EMAIL_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( 'Email summary heading', 'cod-shield' ),
				'id'       => Core::OPT_MSG_EMAIL_TEXT,
				'type'     => 'textarea',
				'default'  => __( 'Advance payment received', 'cod-shield' ),
				'css'      => 'min-width:500px; min-height:60px;',
				'desc_tip' => __( 'Optional heading shown above the amount rows. Leave blank for rows only. Supports {advance_amount}, {remaining_amount} and {gateway}.', 'cod-shield' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_messaging_email',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_messaging',
			),
		);
	}

	/**
	 * Order Handling section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_order_handling_section() {
		return array(
			array(
				'title' => __( 'Order Handling', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => __( 'Behaviour of orders when the advance payment fails or is abandoned at the gateway.', 'cod-shield' ),
				'id'    => 'cod_shield_order_handling',
			),
			array(
				'title'    => __( 'On advance payment failure', 'cod-shield' ),
				'id'       => Core::OPT_FAILURE,
				'type'     => 'select',
				'default'  => 'allow',
				'options'  => array(
					'allow' => __( 'Keep order as pending — customer can retry', 'cod-shield' ),
					'block' => __( 'Cancel the order — order is not placed', 'cod-shield' ),
				),
				'desc_tip' => __( '"Allow" leaves the order in Pending so the customer can retry payment. "Block" cancels the order so a new checkout is required.', 'cod-shield' ),
			),
			array(
				'title'    => __( 'Order status after advance is paid', 'cod-shield' ),
				'id'       => Core::OPT_ORDER_STATUS,
				'type'     => 'select',
				// Processing by default: the advance is already collected online
				// when this status is applied. With 'on-hold' the customer
				// receives the processing/payment-received email while the order
				// actually lands on hold (no on-hold email follows — the
				// transition is processing -> on-hold, which no WC email listens
				// to). See Core::get_defaults().
				'default'  => 'processing',
				'options'  => array(
					'on-hold'    => __( 'On hold', 'cod-shield' ),
					'processing' => __( 'Processing', 'cod-shield' ),
				),
				'desc_tip' => __( 'Applied once the advance is collected. Processing is recommended (the advance is already collected online); On hold mirrors native COD behaviour.', 'cod-shield' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_order_handling',
			),
		);
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
		return max( 0.0, (float) $raw_value );
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
		return min( 100.0, max( 0.0, (float) $raw_value ) );
	}

	/**
	 * Sanitize a tax class: keep only classes WooCommerce knows about.
	 *
	 * @param mixed $value     Sanitized-by-type value (unused).
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Raw posted value.
	 * @return string
	 */
	public function sanitize_tax_class( $value, $option, $raw_value ) {
		$raw_value = sanitize_key( (string) $raw_value );
		if ( function_exists( 'wc_get_product_tax_class_options' ) ) {
			$options = wc_get_product_tax_class_options();
			if ( isset( $options[ $raw_value ] ) ) {
				return $raw_value;
			}
		}
		return '';
	}

	/**
	 * Sanitize the gateway multiselect: keep only currently-registered,
	 * enabled gateway ids (excluding COD itself and the internal proxy).
	 *
	 * @param mixed $value     Sanitized-by-type value (unused).
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Raw posted value.
	 * @return array<string>
	 */
	public function sanitize_gateways( $value, $option, $raw_value ) {
		if ( ! is_array( $raw_value ) ) {
			return array();
		}
		$valid = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				if ( 'yes' === $gateway->enabled && 'cod' !== $id && Core::GATEWAY_ID !== $id ) {
					$valid[ $id ] = true;
				}
			}
		}
		return array_values(
			array_filter(
				array_map( 'sanitize_title', (array) $raw_value ),
				static function ( $id ) use ( $valid ) {
					return isset( $valid[ $id ] );
				}
			)
		);
	}

	/**
	 * Build the multiselect options list of currently enabled gateways.
	 *
	 * @return array<string,string>
	 */
	private function get_delegation_gateway_options() {
		$options = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				if ( 'yes' !== $gateway->enabled || 'cod' === $id || Core::GATEWAY_ID === $id ) {
					continue;
				}
				$options[ $id ] = $gateway->get_title() . ' (' . $id . ')';
			}
		}
		return $options;
	}
}
