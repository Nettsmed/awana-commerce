/**
 * Checkout organization selector JavaScript.
 *
 * @package Awana_Digital_Sync
 */

(function($) {
	'use strict';

	var AwanaOrgSelect = {
		// Store original billing field values
		originalBilling: {},

		// Billing fields to manage
		billingFields: [
			'billing_company',
			'billing_address_1',
			'billing_postcode',
			'billing_city',
			'billing_phone',
			'billing_email'
		],

		/**
		 * Initialize the module.
		 */
		init: function() {
			this.storeOriginalValues();
			this.bindEvents();
			this.handleInitialState();
		},

		/**
		 * Store original billing field values.
		 */
		storeOriginalValues: function() {
			var self = this;
			this.billingFields.forEach(function(field) {
				var $field = $('#' + field);
				if ($field.length) {
					self.originalBilling[field] = $field.val() || '';
				}
			});
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			var self = this;

			// Payment type radio change
			$(document).on('change', 'input[name="awana_payment_type"]', function() {
				self.handlePaymentTypeChange($(this).val());
			});

			// Organization dropdown change
			$(document).on('change', '#awana_selected_organization', function() {
				self.handleOrgSelection($(this).val());
			});

			// Update original values after WooCommerce checkout updates (for guest-to-login scenarios)
			$(document.body).on('updated_checkout', function() {
				// Only update originals if we're in private mode
				var paymentType = $('input[name="awana_payment_type"]:checked').val();
				if (paymentType === 'private') {
					self.storeOriginalValues();
				}
			});
		},

		/**
		 * Handle initial state on page load.
		 */
		handleInitialState: function() {
			var $checked = $('input[name="awana_payment_type"]:checked');
			if ($checked.length) {
				this.handlePaymentTypeChange($checked.val());
			}
		},

		/**
		 * Handle payment type change.
		 *
		 * @param {string} paymentType - 'private' or 'organization'
		 */
		handlePaymentTypeChange: function(paymentType) {
			var $dropdown = $('.awana-org-dropdown-wrapper');
			var $options = $('.awana-payment-type-option');

			// Update selected state on radio options
			$options.removeClass('selected');
			$options.find('input[value="' + paymentType + '"]').closest('.awana-payment-type-option').addClass('selected');

			if (paymentType === 'organization') {
				$dropdown.addClass('visible');
				// If an org is already selected, fill the fields
				var selectedOrg = $('#awana_selected_organization').val();
				if (selectedOrg) {
					this.handleOrgSelection(selectedOrg);
				}
			} else {
				$dropdown.removeClass('visible');
				this.restoreOriginalBilling();
			}
		},

		/**
		 * Handle organization selection.
		 *
		 * @param {string} orgId - Selected organization ID
		 */
		handleOrgSelection: function(orgId) {
			if (!orgId || typeof awanaOrgData === 'undefined' || !awanaOrgData.organizations) {
				return;
			}

			var org = this.findOrgById(orgId);
			if (!org) {
				return;
			}

			this.fillBillingFromOrg(org);
			$(document.body).trigger('update_checkout');
		},

		/**
		 * Find organization by ID.
		 *
		 * @param {string} orgId - Organization ID to find
		 * @return {object|null} Organization data or null
		 */
		findOrgById: function(orgId) {
			if (typeof awanaOrgData === 'undefined' || !awanaOrgData.organizations) {
				return null;
			}

			for (var i = 0; i < awanaOrgData.organizations.length; i++) {
				if (String(awanaOrgData.organizations[i].organizationId) === String(orgId)) {
					return awanaOrgData.organizations[i];
				}
			}
			return null;
		},

		/**
		 * Fill billing fields from organization data.
		 *
		 * @param {object} org - Organization data
		 */
		fillBillingFromOrg: function(org) {
			// Company name
			this.setFieldValue('billing_company', org.title || '');

			// Address fields
			if (org.billingAddress) {
				this.setFieldValue('billing_address_1', org.billingAddress.street || '');
				this.setFieldValue('billing_postcode', org.billingAddress.postalCode || '');
				this.setFieldValue('billing_city', org.billingAddress.city || '');
			}

			// Contact fields
			this.setFieldValue('billing_phone', org.billingPhone || '');
			this.setFieldValue('billing_email', org.billingEmail || '');
		},

		/**
		 * Restore original billing field values.
		 */
		restoreOriginalBilling: function() {
			var self = this;
			this.billingFields.forEach(function(field) {
				if (self.originalBilling.hasOwnProperty(field)) {
					self.setFieldValue(field, self.originalBilling[field]);
				}
			});
			$(document.body).trigger('update_checkout');
		},

		/**
		 * Set a field value and trigger change.
		 *
		 * @param {string} fieldId - Field ID without #
		 * @param {string} value - Value to set
		 */
		setFieldValue: function(fieldId, value) {
			var $field = $('#' + fieldId);
			if ($field.length) {
				$field.val(value).trigger('change');
			}
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		// Only init on checkout page
		if ($('.awana-payment-type-wrapper').length) {
			AwanaOrgSelect.init();
		}
	});

})(jQuery);
