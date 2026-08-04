/**
 * COD Shield for WooCommerce — classic checkout live risk-free info box.
 *
 * Display-only: re-fetches the cart state from the server whenever the cart
 * changes (updated_checkout) so the box always reflects the authoritative
 * server-side state. This box sits ABOVE the payment methods section and only
 * ever shows the risk-free message — the advance notice is rendered under the
 * COD payment method radio, inside the payment box, via the gateway
 * description instead. The amounts are re-validated server-side at order
 * creation regardless.
 */
(() => {
	var box = null;

	function fill(data) {
		if (!box) {
			return;
		}

		// Below the minimum order value, COD is hidden entirely — nothing to
		// display (the server never allows COD below the threshold).
		if (false === data.available) {
			box.hide();
			return;
		}

		// When an advance is required the advance notice renders under the
		// payment method radio inside the payment box (gateway description).
		// It is intentionally NOT repeated here, above the payment box.
		if (data.required) {
			box.hide();
			return;
		}

		box
			.find(".cod-shield-message")
			.html(window.codShieldCheckout.freeMessage || "");
		box.show();
	}

	function fetchAdvance() {
		jQuery.ajax({
			url: window.codShieldCheckout.ajaxUrl,
			type: "POST",
			data: {
				action: "cod_shield_get_advance",
				nonce: window.codShieldCheckout.nonce,
			},
			success: (response) => {
				if (response && response.success) {
					fill(response.data);
				}
			},
		});
	}

	jQuery(($) => {
		if (
			!window.codShieldCheckout ||
			"disabled" === window.codShieldCheckout.mode
		) {
			return;
		}

		if (!window.codShieldCheckout.freeMessage) {
			return; // Risk-free message disabled — nothing to show.
		}

		box = $(
			'<div class="cod-shield-info-box">' +
				'<span class="cod-shield-message"></span>' +
				"</div>",
		).hide();

		// Right above the payment methods section.
		$("#payment").before(box);

		$(document.body).on("updated_checkout", () => {
			box.show();
			fetchAdvance();
		});

		fetchAdvance();
	});
})();
