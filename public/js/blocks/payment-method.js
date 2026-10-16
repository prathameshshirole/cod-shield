/** SureOrder — live Checkout block payment breakdown and COD fees. */
(() => {
	var wc = window.wc;
	var wp = window.wp;
	var settings = wc && wc.wcSettings && wc.wcSettings.getSetting("cod_shield_advance_data");
	if (!settings || !wc.wcBlocksRegistry || !wc.wcBlocksData || !wc.blocksCheckout || !wp.data) {
		return;
	}
	var createElement = wp.element.createElement;
	var __ = wp.i18n.__;
	var cartStore = wc.wcBlocksData.cartStore;
	var paymentStore = wc.wcBlocksData.paymentStore;
	var requested = null;
	var inFlight = false;
	var failed = false;
	var listeners = new Set();
	var notify = () => listeners.forEach((listener) => listener());
	var cartData = () => wp.data.select(cartStore).getCartData();
	var extension = () => (cartData().extensions || {})["cod-shield"];
	var activeMethod = () => wp.data.select(paymentStore).getActivePaymentMethod();

	var validation = wp.data.dispatch(wc.wcBlocksData.validationStore);
	var notices = wp.data.dispatch("core/notices");
	var cartBusy = () => {
		var store = wp.data.select(cartStore);
		var pendingSelectors = ["isCustomerDataUpdating", "isAddressFieldsForShippingRatesUpdating", "hasPendingItemsOperations", "isApplyingCoupon", "isRemovingCoupon", "isShippingRateBeingSelected"];
		return !store.hasFinishedResolution("getCartData", []) || pendingSelectors.some((selector) => typeof store[selector] === "function" && store[selector]());
	};
	var lastMinimum = null;
	var lastReasonShown = false;
	var lastReasonText = "";
	var updateValidation = () => {
		var data = extension();
		var method = activeMethod();
		if (inFlight || failed || cartBusy() || (method && (!data || data.payment_method !== method))) {
			validation.setValidationErrors({ "cod-shield-payment-totals": { message: __("Please wait for your payment totals to update, then try again.", "cod-shield"), hidden: true } });
		} else {
			validation.clearValidationError("cod-shield-payment-totals");
		}
		var minimum = data ? data.minimum_notice || "" : "";
		if (minimum !== lastMinimum) {
			lastMinimum = minimum;
			notices.removeNotice("cod-shield-minimum", "wc/checkout");
			if (minimum) {
				notices.createNotice("info", minimum, { id: "cod-shield-minimum", context: "wc/checkout", isDismissible: false });
			}
		}

		// Surface the server-evaluated COD ineligibility reason only while a COD
		// method is (or was just) selected — online methods are never interfered with.
		var reason = data && data.cod_eligible === false ? data.cod_ineligibility_message || "" : "";
		var showReason = !!(reason && (method === settings.gateway_id || method === "cod"));
		if (showReason !== lastReasonShown || (showReason && reason !== lastReasonText)) {
			lastReasonShown = showReason;
			lastReasonText = reason;
			notices.removeNotice("cod-shield-ineligibility", "wc/checkout");
			if (showReason) {
				notices.createNotice("error", reason, { id: "cod-shield-ineligibility", context: "wc/checkout", isDismissible: false });
			}
		}
	};

	// Serialize updates: a slower previous selection must never overwrite a newer one.
	var syncSelection = () => {
		var method = activeMethod();
		if (!method || inFlight || requested === method) {
			return;
		}
		requested = method;
		inFlight = true;
		failed = false;
		updateValidation();
		notify();
		wc.blocksCheckout.extensionCartUpdate({
			namespace: "cod-shield",
			data: { payment_method: method },
			overwriteDirtyCustomerData: false,
		}).then(() => {
			failed = false;
			notices.removeNotice("cod-shield-update", "wc/checkout");
		}).catch((error) => {
			failed = true;
			notices.createNotice("error", __("Your payment totals could not be updated. Please try again.", "cod-shield"), { id: "cod-shield-update", context: "wc/checkout", actions: [{ label: __("Try again", "cod-shield"), onClick: () => { requested = null; syncSelection(); } }] });
			if (wc.wcBlocksData.processErrorResponse) {
				wc.wcBlocksData.processErrorResponse(error);
			}
		}).finally(() => {
			inFlight = false;
			syncSelection();
			updateValidation();
			notify();
		});
	};
	wp.data.subscribe(syncSelection, paymentStore);
	wp.data.subscribe(updateValidation, cartStore);
	syncSelection();

	var Content = (props) => {
		var state = wp.data.useSelect(() => ({
			data: extension(),
			method: activeMethod(),
			loading: cartBusy() || !!(props.checkoutStatus && props.checkoutStatus.isCalculating),
		}), [props.checkoutStatus]);
		var refresh = wp.element.useState(0)[1];
		wp.element.useEffect(() => {
			var listener = () => refresh((value) => value + 1);
			listeners.add(listener);
			return () => listeners.delete(listener);
		}, []);
		wp.element.useEffect(() => {
			if (!props.eventRegistration || !props.emitResponse) {
				return;
			}
			return props.eventRegistration.onPaymentSetup(() => {
				var data = extension();
				if (inFlight || failed || !data || data.payment_method !== activeMethod() || !data.available) {
					var reason = data && data.payment_method === activeMethod() && !data.available ? data.cod_ineligibility_message || "" : "";
					return {
						type: props.emitResponse.responseTypes.ERROR,
						message: reason || __("Please wait for your payment totals to update, then try again.", "cod-shield"),
					};
				}
				return { type: props.emitResponse.responseTypes.SUCCESS };
			});
		}, [props.eventRegistration, props.emitResponse]);
		if (failed) {
			return createElement("div", { className: "cod-shield-block-message", role: "alert" },
				__("Your payment totals could not be updated.", "cod-shield"), " ",
				createElement("button", { type: "button", onClick: () => { requested = null; syncSelection(); } }, __("Try again", "cod-shield")));
		}
		if (inFlight || state.loading || !state.data || state.data.payment_method !== state.method) {
			return createElement("div", { className: "cod-shield-block-message", role: "status", "aria-live": "polite" }, __("Updating payment totals…", "cod-shield"));
		}
		if (state.data.available === false && state.data.cod_ineligibility_message) {
			// Rendered as a text child (never dangerouslySetInnerHTML) so the
			// server-provided reason is always escaped by React.
			return createElement("div", { className: "cod-shield-block-message", role: "alert" }, state.data.cod_ineligibility_message);
		}
		return createElement("div", { className: "cod-shield-block-message", "aria-live": "polite", dangerouslySetInnerHTML: { __html: state.data.message } });
	};
	wc.wcBlocksRegistry.registerPaymentMethod({
		name: settings.gateway_id,
		label: createElement("span", null, settings.title),
		content: createElement(Content),
		edit: createElement("div", { dangerouslySetInnerHTML: { __html: settings.message } }),
		canMakePayment: (args) => {
			var data = args.cart && args.cart.extensions ? args.cart.extensions["cod-shield"] : extension();
			return !!(data && data.available);
		},
		ariaLabel: settings.title,
		placeOrderButtonLabel: settings.button_label || __("Pay advance & confirm COD", "cod-shield"),
		supports: { features: settings.supports || ["products"] },
		paymentMethodId: settings.gateway_id,
	});
})();
