jQuery(document).ready(function ($) {
	if (typeof wc === 'undefined') {
		return false;
	}

	var shippingToggle, timeoutCanMakePayment,
		paymentMethodPermissionRunning = false,
		currentRequestData = false;

	registerPaymentMethod();

	function registerPaymentMethod() {
		wc.wcBlocksRegistry.registerPaymentMethod({
			name: wc.rc.id,
			label: wc.rc.label,
			ariaLabel: wc.rc.label,
			content: wp.element.createElement(wc.rc.description, null),
			edit: wp.element.createElement(wc.rc.description, null),
			canMakePayment: canMakePaymentCheck,
		});
	}

	function canMakePaymentCheck(data) {
		// console.log('running canMakePayment');
		var reqData = getRequiredData(data);
		if (hasEmptyFields(reqData)) {
			return false
		}
		if (paymentMethodPermissionRunning && isObjectEquals(reqData, currentRequestData)) {
			return paymentMethodPermissionRunning;
		}
		window.clearTimeout(timeoutCanMakePayment);
		return new Promise(function (resolveTimeout, rejectTimeout) {
			timeoutCanMakePayment = window.setTimeout(function () {
				paymentMethodPermissionRunning = ajaxVerifyCustomerData(reqData)
					.then(function (r) {
						resolveTimeout(r.allowInvoice)
						paymentMethodPermissionRunning = false;
					})
					.catch(function (r) {
						resolveTimeout(false);
						paymentMethodPermissionRunning = false;
					});
			}, 1000);
		}).then(function (r) {
			return r;
		});
	}

	// TODO: This will fail if the keys are not in matching order
	function isObjectEquals(objA, objB) {
		return JSON.stringify(objA) === JSON.stringify(objB);
	}

	// Is the shipping address the same as the billing address?
	function isToggleSelected() {
		if (!shippingToggle) {
			shippingToggle = jQuery('.wc-block-checkout__use-address-for-billing #checkbox-control-1, .wc-block-checkout__use-address-for-billing #checkbox-control-0');
		}

		return jQuery(shippingToggle).is(':checked');
	}

	function getRequiredData(data) {
		var reqData = {
			shipping_same_as_billing: isToggleSelected(),
			shipping: data.shippingAddress,
		};
		// TODO: cleanup - Currently the WC Blocks module is in development, so these values are still subject to change
		if (data.billingAddress !== undefined) {
			reqData.billing = data.billingAddress;
		} else if (data.billingData !== undefined) {
			reqData.billing = data.billingData;
		} else {
			console.error('Unable to find Billing Data');
		}
		if (data.cart.cartTotals !== undefined) {
			reqData.cart = data.cart.cartTotals;
		} else if (data.cartTotals !== undefined) {
			reqData.cart = data.cartTotals;
		} else {
			console.error('Unable to find Cart Data');
		}

		return reqData;
	}

	function hasEmptyFields(data) {
		var emptyFieldsCount = 0,
			required = ['address_1', 'city', 'email', 'first_name', 'last_name', 'phone', 'postcode'];

		required.forEach(function (item) {
			if (data.shipping[item] === "") {
				emptyFieldsCount++;
			}
			if (!data.shipping_same_as_billing && data.billing[item] === "") {
				emptyFieldsCount++;
			}
		});

		return emptyFieldsCount > 1;
	}

	function ajaxVerifyCustomerData(data) {
		return $.ajax({
			url: '/?rest_route=/riskcube/v1/verify-customer-data',
			method: 'POST',
			data: data,
		});
	}
})
