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

		// Custom sanitization for numeric / percentage / gateway fields.
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_COD_TITLE, array( $this, 'sanitize_cod_title' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FIXED, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_PERCENTAGE, array( $this, 'sanitize_percentage' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_MIN, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_MAX, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_THRESHOLD, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_GATEWAYS, array( $this, 'sanitize_gateways' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FEE_AMOUNT, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FEE_PERCENTAGE, array( $this, 'sanitize_percentage' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Core::OPT_FEE_TAX_CLASS, array( $this, 'sanitize_tax_class' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Eligibility::OPT_MAXIMUM, array( $this, 'sanitize_positive_number' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Eligibility::OPT_EXCLUDED_PINCODES, array( $this, 'sanitize_pincode_rules' ), 10, 3 );

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
			''               => __( 'Setup', 'cod-shield' ),
			'advance'        => __( 'Advanced Rules', 'cod-shield' ),
			// Underscore slugs: WC builds the settings method as
			// get_settings_for_{section}_section, and a PHP method name cannot
			// contain a hyphen. Underscore slugs resolve natively even if WC ever
			// drops the deprecated get_settings() path the hyphen-mapping
			// override rides on (see Core::settings_method_for_section()).
			'cod_fee'        => __( 'COD Fee', 'cod-shield' ),
			'messaging'      => __( 'Messages', 'cod-shield' ),
			'order_handling' => __( 'Order Handling', 'cod-shield' ),
		);
	}

	/**
	 * Setup section: the minimum three-step configuration.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
		$gateway_options = array(
			'' => __( 'Select a tested gateway…', 'cod-shield' ),
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
				'desc'  => '<img src="' . esc_url( COD_SHIELD_URL . 'admin/img/icon-128x128.png' ) . '" alt="" class="cod-shield-settings-logo" /> ' . __( 'Collect a small online advance to confirm COD orders. Complete the three steps below; optional controls are in the other tabs.', 'cod-shield' ),
				'id'    => 'cod_shield_general',
			),
			array(
				'type' => 'cod_shield_configuration_status',
				'id'   => 'cod_shield_configuration_status',
			),
			array(
				'title'   => __( '1. Enable Cash on Delivery', 'cod-shield' ),
				'desc'    => __( 'Show COD at checkout when the order is eligible.', 'cod-shield' ),
				'id'      => Core::OPT_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( '2. Choose the advance', 'cod-shield' ),
				'id'       => Core::OPT_MODE,
				'type'     => 'select',
				'default'  => 'disabled',
				'options'  => array(
					'disabled'   => __( 'No advance — plain COD', 'cod-shield' ),
					'fixed'      => __( 'Fixed amount (₹)', 'cod-shield' ),
					'percentage' => __( 'Percentage of order total', 'cod-shield' ),
				),
				'desc_tip' => __( 'Choose how the upfront payment is calculated.', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Fixed amount', 'cod-shield' ),
				'id'                => Core::OPT_FIXED,
				'type'              => 'number',
				'default'           => 99,
				'row_class'         => 'cod-shield-setup-subfield',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => __( 'Used when Fixed amount is selected.', 'cod-shield' ),
			),
			array(
				'title'             => __( 'Percentage', 'cod-shield' ),
				'id'                => Core::OPT_PERCENTAGE,
				'type'              => 'number',
				'default'           => 10,
				'row_class'         => 'cod-shield-setup-subfield',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '1',
				),
				'desc_tip'          => __( 'Used when Percentage is selected.', 'cod-shield' ),
			),
			array(
				'title'    => __( '3. Select an advance gateway', 'cod-shield' ),
				'id'       => Core::OPT_GATEWAYS,
				'type'     => 'cod_shield_gateway_select',
				'class'    => 'wc-enhanced-select cod-shield-gateway-select',
				'css'      => 'min-width:350px;',
				'default'  => array(),
				'options'  => $gateway_options,
				'desc_tip' => __( 'Only enabled, fieldless redirect candidates are shown. Test the current gateway version before going live.', 'cod-shield' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_general',
			),
		);
	}

	/**
	 * Advanced advance-payment and COD eligibility rules.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_advance_section() {
		return array(
			array(
				'title' => __( 'Advanced advance rules', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => __( 'Optional limits for the advance amount and COD order value. Leave a value at 0 to disable that limit.', 'cod-shield' ),
				'id'    => 'cod_shield_advance',
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
				'title'             => __( 'Minimum order value for COD', 'cod-shield' ),
				'id'                => Core::OPT_THRESHOLD,
				'type'              => 'number',
				// Off by default: a non-zero default would hide small COD orders
				// the moment the plugin is activated (see Core::get_defaults()).
				'default'           => 0,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => __( 'Orders below this amount cannot use COD. Leave 0 for no minimum.', 'cod-shield' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_advance',
			),
			array(
				'title' => __( 'Eligibility rules', 'cod-shield' ),
				'type'  => 'title',
				'desc'  => __( 'Optionally limit COD by order value or delivery pincode.', 'cod-shield' ),
				'id'    => 'cod_shield_eligibility',
			),
			array(
				'title'             => __( 'Maximum order value for COD', 'cod-shield' ),
				'id'                => Eligibility::OPT_MAXIMUM,
				'type'              => 'number',
				'default'           => 0,
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => __( 'Orders above this amount cannot use COD. Leave 0 for no maximum.', 'cod-shield' ),
			),
			array(
				'title'       => __( 'Excluded delivery pincodes', 'cod-shield' ),
				'id'          => Eligibility::OPT_EXCLUDED_PINCODES,
				'type'        => 'textarea',
				'default'     => '',
				'css'         => 'min-width:350px; min-height:90px;',
				'placeholder' => __( '110001, 400*', 'cod-shield' ),
				'desc'        => __( 'Separate pincodes with commas, spaces, or new lines. Add * at the end to exclude a range, for example 400*.', 'cod-shield' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'cod_shield_eligibility',
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
				'title'       => __( 'Checkout title', 'cod-shield' ),
				'desc'        => __( 'Optional customer-facing name for COD. Leave blank to keep the WooCommerce title.', 'cod-shield' ),
				'id'          => Core::OPT_COD_TITLE,
				'type'        => 'text',
				'default'     => '',
				'placeholder' => __( 'Cash on delivery (COD)', 'cod-shield' ),
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
			$summary       = __( 'COD is turned off', 'cod-shield' );
			$summary_class = 'muted';
		} else {
			$summary       = $status['ready'] ? __( 'Ready for checkout', 'cod-shield' ) : __( 'Needs attention', 'cod-shield' );
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
			echo esc_html__( 'Advance payment is enabled, but no eligible gateway is selected. Choose and test one in Step 3, or use plain COD.', 'cod-shield' );
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
		return max( 0.0, (float) $raw_value );
	}

	/**
	 * Sanitize the customer-facing COD title.
	 *
	 * @param mixed $value     Sanitized-by-type value (unused).
	 * @param array $option    Field definition (unused).
	 * @param mixed $raw_value Raw posted value.
	 * @return string
	 */
	public function sanitize_cod_title( $value, $option, $raw_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WooCommerce filter signature.
		return sanitize_text_field( (string) $raw_value );
	}

	/**
	 * Sanitize exact and trailing-wildcard pincode rules.
	 *
	 * @param mixed $value     Sanitized-by-type value (unused).
	 * @param array $option    Field definition (unused).
	 * @param mixed $raw_value Raw posted value.
	 * @return string
	 */
	public function sanitize_pincode_rules( $value, $option, $raw_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WooCommerce filter signature.
		$rules = strtoupper( (string) $raw_value );
		$rules = preg_replace( '/[^A-Z0-9*,\r\n ]+/', ' ', $rules );
		return trim( (string) $rules );
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
		$options = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$status = Core::assess_gateway_compatibility(
					$id,
					array(
						'id'            => $gateway->id,
						'enabled'       => $gateway->enabled,
						'has_fields'    => $gateway->has_fields,
						'supports'      => $gateway->supports,
						'can_process'   => is_callable( array( $gateway, 'process_payment' ) ),
						'gateway_class' => get_class( $gateway ),
						'is_direct'     => is_a( $gateway, '\\WC_Payment_Gateway_CC' )
							|| is_a( $gateway, '\\WC_Payment_Gateway_ECheck' ),
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
		$advance_enabled    = 'disabled' !== get_option( Core::OPT_MODE, 'disabled' );
		$gateway_ready      = '' !== $selected_gateway && isset( $gateway_options[ $selected_gateway ] );
		$shield_enabled     = 'yes' === get_option( Core::OPT_ENABLED, 'yes' );
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
