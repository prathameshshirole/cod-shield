/* Regression coverage for asynchronous payment selection and live cart data. */
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
(async () => {
	let method = "cod_shield_advance";
	let data = { extensions: { "cod-shield": { available: true, message: "initial", payment_method: method } } };
	let subscription;
	let cartSubscription;
	let applyingCoupon = false;
	let selectingShipping = false;
	let registration;
	let requests = [];
	let completions = [];
	let validationErrors = {};
	let notices = {};
	const window = {
		wc: {
			wcSettings: { getSetting: () => ({ gateway_id: method, title: "COD", message: "initial" }) },
			wcBlocksRegistry: { registerPaymentMethod: (value) => { registration = value; } },
			wcBlocksData: { cartStore: "cart", paymentStore: "payment" },
			blocksCheckout: { extensionCartUpdate: (args) => { requests.push(args); return new Promise((resolve, reject) => completions.push({ resolve, reject })); } },
		},
		wp: {
			element: { createElement: (type, props, ...children) => ({ type, props: { ...props, children: children.length === 1 ? children[0] : children } }), useState: () => [0, () => {}], useEffect: () => {} },
			i18n: { __: (text) => text },
			data: { select: (store) => store === "cart" ? { getCartData: () => data, hasFinishedResolution: () => true, isApplyingCoupon: () => applyingCoupon, isShippingRateBeingSelected: () => selectingShipping, isCustomerDataUpdating: () => false, isAddressFieldsForShippingRatesUpdating: () => false, hasPendingItemsOperations: () => false } : { getActivePaymentMethod: () => method }, dispatch: () => ({ setValidationErrors: (errors) => { Object.assign(validationErrors, errors); }, clearValidationError: (id) => { delete validationErrors[id]; }, createNotice: (type, message, options) => { notices[options.id] = { ...options, type, message }; }, removeNotice: (id) => { delete notices[id]; } }), subscribe: (callback, store) => { if (store === "payment") subscription = callback; if (store === "cart") cartSubscription = callback; }, useSelect: (callback) => callback() },
		},
	};
	vm.runInNewContext(fs.readFileSync("public/js/blocks/payment-method.js", "utf8"), { window, Set });
	assert.equal(requests.length, 1);
	assert.ok(validationErrors["cod-shield-payment-totals"], "Every payment method must wait for updated totals");
	assert.equal(requests[0].overwriteDirtyCustomerData, false, "Cart refresh must preserve unsaved customer input");
	method = "bacs";
	subscription();
	assert.equal(requests.length, 1, "Selection updates must be serialized");
	completions[0].resolve();
	await new Promise(setImmediate);
	assert.equal(requests.length, 2);
	assert.ok(validationErrors["cod-shield-payment-totals"], "Queued payment selection must keep checkout blocked");
	assert.equal(requests[1].data.payment_method, "bacs", "Switching away from COD removes its fee");
	data.extensions["cod-shield"].payment_method = method;
	completions[1].resolve();
	await new Promise(setImmediate);
	data.extensions["cod-shield"].available = false;
	assert.equal(registration.canMakePayment({}), false, "Availability must follow current cart eligibility");
	data.extensions["cod-shield"].available = true;
	assert.equal(registration.canMakePayment({}), true, "Eligibility can become available after cart changes");
	data.extensions["cod-shield"].message = "coupon adjusted";
	data.extensions["cod-shield"].payment_method = method;
	const content = registration.content.type({});
	assert.equal(content.props.dangerouslySetInnerHTML.__html, "coupon adjusted", "Amounts must come from live Store API data");
	method = "cod";
	subscription();
	completions[2].reject(new Error("Network unavailable"));
	await new Promise(setImmediate);
	assert.ok(validationErrors["cod-shield-payment-totals"], "A failed update blocks native COD too");
	assert.ok(notices["cod-shield-update"], "Native COD must offer recovery after failure");
	notices["cod-shield-update"].actions[0].onClick();
	assert.equal(requests.length, 4, "Retry starts a new update for the selected method");
	data.extensions["cod-shield"].payment_method = method;
	completions[3].resolve();
	await new Promise(setImmediate);
	assert.equal(validationErrors["cod-shield-payment-totals"], undefined, "Successful retry clears the checkout blocker");
	applyingCoupon = true;
	cartSubscription();
	assert.ok(validationErrors["cod-shield-payment-totals"], "Coupon updates block stale checkout amounts");
	applyingCoupon = false;
	selectingShipping = true;
	cartSubscription();
	assert.ok(validationErrors["cod-shield-payment-totals"], "Shipping selection blocks stale checkout amounts");
	selectingShipping = false;
	cartSubscription();
	assert.equal(validationErrors["cod-shield-payment-totals"], undefined, "Settled cart updates clear the blocker");

	// COD ineligibility reasons propagate to notices and the method content.
	assert.equal(notices["cod-shield-ineligibility"], undefined, "No reason notice exists while COD is eligible");
	data.extensions["cod-shield"].cod_eligible = false;
	data.extensions["cod-shield"].cod_ineligibility_code = "excluded_product";
	data.extensions["cod-shield"].cod_ineligibility_message = "Cash on Delivery is not available for some items in your cart.";
	data.extensions["cod-shield"].available = false;
	cartSubscription();
	assert.ok(notices["cod-shield-ineligibility"], "An active COD selection must surface the ineligibility reason");
	assert.equal(notices["cod-shield-ineligibility"].message, "Cash on Delivery is not available for some items in your cart.", "The notice carries the server-provided reason verbatim");
	const unavailable = registration.content.type({});
	assert.ok(unavailable.props.children.includes("Cash on Delivery is not available for some items in your cart."), "Unavailable content renders the reason text");
	assert.equal(unavailable.props.dangerouslySetInnerHTML, undefined, "The reason must never be injected as raw HTML");

	// Online payment selection is never interfered with by COD reasons.
	method = "bacs";
	cartSubscription();
	subscription();
	assert.equal(notices["cod-shield-ineligibility"], undefined, "Switching to an online method removes the COD reason notice");
	method = "cod";
	cartSubscription();
	assert.ok(notices["cod-shield-ineligibility"], "Native COD selection also shows the shared reason");
	method = "bacs";
	cartSubscription();
	subscription();
	console.log("Blocks payment selection and reactive breakdown regressions passed.");
})().catch((error) => { console.error(error); process.exitCode = 1; });
