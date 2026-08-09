<?php
/**
 * Admin order visibility: COD Advance column (sortable + filterable) on the
 * orders list screen, an advance details metabox on the order edit screen,
 * and a compact inline advance summary under the order total.
 *
 * Works with both HPOS (woocommerce_shop_order_list_table_*) and the legacy
 * posts-based orders list (manage_edit-shop_order_*), registering both hook
 * sets so a single build supports both datastores.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Order UI class.
 */
class Order_UI {

	const COLUMN = 'cod_shield_advance';

	/**
	 * Hook registration.
	 */
	public function __construct() {
		// Columns — HPOS.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ), 10, 1 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 10, 2 );
		// Columns — legacy posts datastore.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 10, 1 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 10, 2 );

		// Sortable — HPOS. NB: WooCommerce applies
		// woocommerce_order_list_table_prepare_items_query_args with a SINGLE
		// argument (the query-args array); the callbacks below must not
		// declare a required second parameter or every HPOS orders-list
		// render fatals with ArgumentCountError.
		add_filter( 'woocommerce_shop_order_list_table_sortable_columns', array( $this, 'make_sortable' ), 10, 1 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'apply_orderby_query_args' ), 10, 1 );
		// Sortable — legacy.
		add_filter( 'manage_edit-shop_order_sortable_columns', array( $this, 'make_sortable' ), 10, 1 );
		add_action( 'pre_get_posts', array( $this, 'apply_orderby_query_args_legacy' ), 10, 1 );

		// Filter dropdown — HPOS + legacy.
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filter_dropdown' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filter_dropdown_legacy' ), 10, 1 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'apply_filter_query_args' ), 20, 1 );
		add_action( 'pre_get_posts', array( $this, 'apply_filter_query_args_legacy' ), 20, 1 );

		// Metabox on the order edit screen.
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ), 10, 2 );

		// Compact inline advance summary under the order total (admin).
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_admin_totals_inline' ), 10, 1 );
	}

	/*
	---------------------------------------------------------------------
	 * Columns
	 */

	/**
	 * Register the COD Advance column after the order total column.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_total' === $key ) {
				$new[ self::COLUMN ] = __( 'COD Advance', 'cod-shield' );
			}
		}
		if ( ! isset( $new[ self::COLUMN ] ) ) {
			$new[ self::COLUMN ] = __( 'COD Advance', 'cod-shield' );
		}
		return $new;
	}

	/**
	 * Render column content (HPOS: receives a WC_Order).
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function render_column( $column, $order ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$this->output_column_content( $order );
	}

	/**
	 * Render column content (legacy: receives a post id).
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function render_column_legacy( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			$this->output_column_content( $order );
		}
	}

	/**
	 * Column cell: paid advance amount, or "—" when not applicable.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function output_column_content( $order ) {
		$view = Order_Meta::from_order( $order );
		if ( ! $view->has_data() || $view->advance <= 0 ) {
			echo '<span class="cod-shield-na" aria-label="' . esc_attr__( 'No advance', 'cod-shield' ) . '">—</span>';
			return;
		}

		$label = wc_price( $view->advance );
		$class = 'cod-shield-status cod-shield-status--' . ( in_array( $view->status, array( 'paid', 'pending', 'failed' ), true ) ? $view->status : 'pending' );

		printf(
			'<span class="%1$s" title="%2$s">%3$s</span>',
			esc_attr( $class ),
			esc_attr(
				sprintf(
					/* translators: 1: status, 2: remaining amount. */
					__( 'Status: %1$s — %2$s due on delivery', 'cod-shield' ),
					ucfirst( $view->status ),
					wc_price( $view->remaining )
				)
			),
			wp_kses_post( $label ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/*
	---------------------------------------------------------------------
	 * Sorting
	 */

	/**
	 * Mark the column sortable.
	 *
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public function make_sortable( $columns ) {
		$columns[ self::COLUMN ] = self::COLUMN;
		return $columns;
	}

	/**
	 * Wire sorting for HPOS: order by the advance meta as a number.
	 *
	 * WooCommerce applies the hook with a single argument (the query-args
	 * array); there is no second "order type" parameter.
	 *
	 * @param array<string,mixed> $query_args Query args.
	 * @return array<string,mixed>
	 */
	public function apply_orderby_query_args( $query_args ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( self::COLUMN !== $orderby ) {
			return $query_args;
		}
		$query_args['orderby']   = 'meta_value_num'; // phpcs:ignore WordPress.DB.SlowDBQuery -- Intentional meta-based sorting.
		$query_args['meta_key']  = Core::META_ADVANCE; // phpcs:ignore WordPress.DB.SlowDBQuery -- Intentional meta-based sorting.
		$query_args['meta_type'] = 'NUMERIC';
		return $query_args;
	}

	/**
	 * Wire sorting for the legacy datastore.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function apply_orderby_query_args_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( self::COLUMN === $orderby ) {
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'meta_key', Core::META_ADVANCE );
			$query->set( 'meta_type', 'NUMERIC' );
		}
	}

	/*
	---------------------------------------------------------------------
	 * Filter dropdown
	 */

	/**
	 * Filter options: any advance / advance paid / advance pending / no advance.
	 *
	 * @return array<string,string>
	 */
	private function filter_options() {
		return array(
			''                => __( 'All COD states', 'cod-shield' ),
			'advance_any'     => __( 'COD with advance', 'cod-shield' ),
			'advance_paid'    => __( 'Advance paid', 'cod-shield' ),
			'advance_pending' => __( 'Advance pending/failed', 'cod-shield' ),
			'no_advance'      => __( 'COD no advance', 'cod-shield' ),
		);
	}

	/**
	 * Current filter value.
	 *
	 * @return string
	 */
	private function current_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['cod_shield_filter'] ) ? sanitize_key( wp_unslash( $_GET['cod_shield_filter'] ) ) : '';
		return array_key_exists( $filter, $this->filter_options() ) ? $filter : '';
	}

	/**
	 * Render the dropdown (HPOS).
	 *
	 * @param string $order_type Order type.
	 * @param string $which      Which tablenav ('top' or 'bottom').
	 * @return void
	 */
	public function render_filter_dropdown( $order_type, $which ) {
		if ( 'shop_order' !== $order_type || 'top' !== $which ) {
			return;
		}
		$this->render_filter_select();
	}

	/**
	 * Render the dropdown (legacy).
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function render_filter_dropdown_legacy( $post_type ) {
		global $typenow;
		if ( 'shop_order' !== ( $post_type ? $post_type : $typenow ) ) {
			return;
		}
		$this->render_filter_select();
	}

	/**
	 * Output the select element.
	 *
	 * @return void
	 */
	private function render_filter_select() {
		$current = $this->current_filter();
		echo '<select name="cod_shield_filter" id="cod-shield-filter">';
		foreach ( $this->filter_options() as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Apply the filter to the HPOS query args.
	 *
	 * WooCommerce applies the hook with a single argument (the query-args
	 * array); there is no second "order type" parameter.
	 *
	 * @param array<string,mixed> $query_args Query args.
	 * @return array<string,mixed>
	 */
	public function apply_filter_query_args( $query_args ) {
		$filter = $this->current_filter();
		if ( '' === $filter ) {
			return $query_args;
		}

		$meta_query = isset( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery -- Intentional meta-based filtering.
			? $query_args['meta_query']
			: array();

		switch ( $filter ) {
			case 'no_advance':
				$meta_query[] = array(
					'key'     => Core::META_ADVANCE,
					'compare' => 'NOT EXISTS',
				);
				break;
			case 'advance_paid':
				$meta_query[] = array(
					'key'   => Core::META_STATUS,
					'value' => 'paid',
				);
				break;
			case 'advance_pending':
				$meta_query[] = array(
					'key'     => Core::META_STATUS,
					'value'   => array( 'pending', 'failed' ),
					'compare' => 'IN',
				);
				break;
			case 'advance_any':
			default:
				$meta_query[] = array(
					'key'     => Core::META_ADVANCE,
					'compare' => 'EXISTS',
				);
				break;
		}

		$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery -- Intentional meta-based filtering.
		return $query_args;
	}

	/**
	 * Apply the filter to the legacy query.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function apply_filter_query_args_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		$filter = $this->current_filter();
		if ( '' === $filter ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' ); // phpcs:ignore WordPress.DB.SlowDBQuery -- Intentional meta-based filtering.

		switch ( $filter ) {
			case 'no_advance':
				$meta_query[] = array(
					'key'     => Core::META_ADVANCE,
					'compare' => 'NOT EXISTS',
				);
				break;
			case 'advance_paid':
				$meta_query[] = array(
					'key'   => Core::META_STATUS,
					'value' => 'paid',
				);
				break;
			case 'advance_pending':
				$meta_query[] = array(
					'key'     => Core::META_STATUS,
					'value'   => array( 'pending', 'failed' ),
					'compare' => 'IN',
				);
				break;
			default:
				$meta_query[] = array(
					'key'     => Core::META_ADVANCE,
					'compare' => 'EXISTS',
				);
				break;
		}

		$query->set( 'meta_query', $meta_query );
	}

	/*
	---------------------------------------------------------------------
	 * Metabox
	 */

	/**
	 * Register the advance details metabox on both HPOS and legacy screens.
	 *
	 * @param string                  $post_type Post type (hook signature; unused).
	 * @param \WP_Post|\WC_Order|null $post_or_order_object Object (hook signature; unused).
	 * @return void
	 */
	public function add_metabox( $post_type, $post_or_order_object ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		if ( ! in_array( $post_type, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		$core   = Core::instance();
		$screen = $core->is_hpos() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box(
			'cod_shield_advance_box',
			__( 'SureOrder — Advance Payment', 'cod-shield' ),
			array( $this, 'render_metabox' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Metabox content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order_object Object.
	 * @return void
	 */
	public function render_metabox( $post_or_order_object ) {
		$order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return;
		}

		$view = Order_Meta::from_order( $order );

		echo '<div class="cod-shield-metabox">';

		if ( ! $view->has_data() || $view->advance <= 0 ) {
			echo '<p>' . esc_html__( 'No advance payment was collected for this order.', 'cod-shield' ) . '</p>';
			echo '</div>';
			return;
		}

		$rows = array(
			__( 'Advance collected', 'cod-shield' ) => wc_price( $view->advance ),
			__( 'Status', 'cod-shield' )            => ucfirst( $view->status ? $view->status : 'pending' ),
			__( 'Source', 'cod-shield' )            => $view->source_label(),
			__( 'Gateway used', 'cod-shield' )      => $view->gateway ? $view->gateway : '—',
			__( 'Transaction ID', 'cod-shield' )    => $view->txn ? $view->txn : '—',
			__( 'Due on delivery', 'cod-shield' )   => wc_price( $view->remaining ),
		);

		echo '<ul>';
		foreach ( $rows as $label => $value ) {
			printf(
				'<li><span class="label">%1$s</span> <strong>%2$s</strong></li>',
				esc_html( $label ),
				wp_kses_post( $value )
			);
		}
		echo '</ul>';

		// Manual reconciliation: when the advance is still outstanding (never
		// recorded as paid via the gateway — e.g. the customer paid outside the
		// gateway, or the gateway never recorded a transaction id), offer an
		// explicit admin action instead of leaving the order stuck awaiting.
		if ( $view->advance > 0 && 'paid' !== $view->status ) {
			$confirm = esc_js( __( 'Mark the advance as paid without a gateway transaction? Only do this if you have collected the advance elsewhere (e.g. bank transfer or cash).', 'cod-shield' ) );
			echo '<input type="hidden" name="cod_shield_order_id" value="' . esc_attr( $order->get_id() ) . '" />';
			wp_nonce_field( Core::NONCE_MANUAL_PAID . '_' . $order->get_id(), 'cod_shield_manual_paid_nonce', false );
			echo '<p class="cod-shield-manual-paid-form"><button type="submit" name="action" value="' . esc_attr( Core::NONCE_MANUAL_PAID ) . '" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" formmethod="post" class="button button-link-delete" onclick="return confirm(\'' . $confirm . '\');">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js() escapes the confirmation string used by the inline confirm call.
			echo esc_html__( 'Mark advance as paid (manual)', 'cod-shield' );
			echo '</button></p>';
		}

		echo '<p class="cod-shield-metabox-note">' . esc_html__( 'Refunds of the advance must be processed manually through the gateway\'s own refund flow.', 'cod-shield' ) . '</p>';
		echo '</div>';
	}

	/*
	---------------------------------------------------------------------
	 * Inline admin order-totals summary
	 */

	/**
	 * Compact advance summary rendered under the order total on the admin
	 * order edit screen — a quick at-a-glance complement to the side metabox.
	 *
	 * Styles are inline so it renders on the order screen without requiring
	 * the admin stylesheet to be enqueued there.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function render_admin_totals_inline( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$view = Order_Meta::from_order( $order );
		if ( ! $view->has_data() || $view->advance <= 0 ) {
			return;
		}

		$paid    = ( 'paid' === $view->status );
		$gateway = $view->gateway;

		if ( $paid ) {
			if ( $gateway ) {
				// translators: %s: gateway id.
				$advance_label = sprintf( __( 'Advance paid (via %s)', 'cod-shield' ), $gateway );
			} else {
				$advance_label = __( 'Advance paid', 'cod-shield' );
			}
		} elseif ( $gateway ) {
			// translators: %s: gateway id.
			$advance_label = sprintf( __( 'Advance pending (via %s)', 'cod-shield' ), $gateway );
		} else {
			$advance_label = __( 'Advance pending', 'cod-shield' );
		}

		printf(
			'<tr class="cod-shield-admin-total"><th scope="row">%1$s</th><td style="color:%2$s;font-weight:600;">%3$s</td></tr>',
			esc_html( $advance_label ),
			esc_attr( $paid ? '#1a7f37' : '#9a6700' ),
			wp_kses_post( wc_price( $view->advance ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		if ( $paid && $view->remaining > 0 ) {
			printf(
				'<tr class="cod-shield-admin-total"><th scope="row">%1$s</th><td style="color:%2$s;font-weight:600;">%3$s</td></tr>',
				esc_html__( 'Due on delivery', 'cod-shield' ),
				esc_attr( '#9a6700' ),
				wp_kses_post( wc_price( $view->remaining ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}
}
