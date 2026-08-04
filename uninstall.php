<?php
/**
 * Uninstall routine for COD Shield for WooCommerce.
 *
 * Removes only the options this plugin owns.
 *
 * @package COD_Shield
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cod_shield_options = array(
	'cod_shield_enabled',
	'cod_shield_advance_mode',
	'cod_shield_advance_fixed',
	'cod_shield_advance_percentage',
	'cod_shield_advance_min',
	'cod_shield_advance_max',
	'cod_shield_advance_threshold',
	'cod_shield_advance_gateways',
	'cod_shield_msg_advance_enabled',
	'cod_shield_msg_advance_text',
	'cod_shield_msg_free_enabled',
	'cod_shield_msg_free_text',
	'cod_shield_msg_email_enabled',
	'cod_shield_msg_email_text',
	'cod_shield_on_advance_failure',
	'cod_shield_order_status',
	'cod_shield_fee_enabled',
	'cod_shield_fee_type',
	'cod_shield_fee_amount',
	'cod_shield_fee_percentage',
	'cod_shield_fee_taxable',
	'cod_shield_fee_tax_class',
);

foreach ( $cod_shield_options as $cod_shield_option ) {
	delete_option( $cod_shield_option );
}
