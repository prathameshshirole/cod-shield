/**
 * SureOrder — Checkout block payment method.
 *
 * Registers the proxy gateway with the block checkout so the advance
 * messaging renders natively under the payment method radio. Uses wp.element
 * (no build step required).
 */
(() => {
	var blocksRegistry = window.wc && window.wc.wcBlocksRegistry;
	var settings =
		window.wc && window.wc.wcSettings
			? window.wc.wcSettings.getSetting("cod_shield_advance_data")
			: null;

	if (!blocksRegistry || !settings) {
		return;
	}

	var createElement = window.wp.element.createElement;

	var Content = () => {
		var message = settings.message || settings.description || "";
		if (message) {
			return createElement("div", {
				className: "cod-shield-block-message",
				dangerouslySetInnerHTML: { __html: message },
			});
		}
		// Plain-text fallback: advance_plain is a stripped amount (no markup).
		// The HTML variants must not be used here — React renders them as a
		// text child and the customer would see raw <span> markup. Same
		// highlighted callout class as the server-rendered message branch.
		return createElement(
			"div",
			{ className: "cod-shield-block-message cod-shield-advance-notice" },
			"Pay " +
				(settings.advance_plain || settings.advance_html || "") +
				" now, " +
				(settings.remaining_plain || settings.remaining_html || "") +
				" on delivery (COD).",
		);
	};

	var Label = () =>
		createElement("span", null, settings.title || "Cash on delivery (COD)");

	blocksRegistry.registerPaymentMethod({
		name: settings.gateway_id || "cod_shield_advance",
		label: createElement(Label),
		content: createElement(Content),
		edit: createElement(Content),
		canMakePayment: () => true,
		ariaLabel: settings.title || "Cash on delivery (COD)",
		supports: {
			features: settings.supports || ["products"],
		},
		paymentMethodId: settings.gateway_id || "cod_shield_advance",
	});
})();
