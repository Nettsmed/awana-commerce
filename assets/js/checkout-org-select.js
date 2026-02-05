/**
 * 3-Step Checkout Wizard JavaScript
 * Full-width layout with WooCommerce content integration
 *
 * @package Awana_Digital_Sync
 */

(function($) {
	'use strict';

	var AwanaCheckoutWizard = {
		// Current step (1, 2, or 3)
		currentStep: 1,

		// Store original billing field values
		originalBilling: {},

		// Selected payment type
		paymentType: 'private',

		// Selected organization
		selectedOrg: null,

		// Billing fields to manage
		billingFields: [
			'billing_company',
			'billing_address_1',
			'billing_postcode',
			'billing_city',
			'billing_phone',
			'billing_email'
		],

		// Cache DOM elements
		$wizard: null,
		$steps: null,
		$stepContents: null,
		$typeCards: null,
		$orgDropdown: null,
		$orgSelect: null,

		// WooCommerce elements
		$billingFields: null,
		$shippingFields: null,
		$additionalFields: null,
		$orderReview: null,
		$payment: null,

		/**
		 * Initialize the wizard.
		 */
		init: function() {
			this.$wizard = $('.awana-checkout-wizard');
			if (!this.$wizard.length) {
				return;
			}

			this.$steps = this.$wizard.find('.awana-step');
			this.$stepContents = this.$wizard.find('.awana-step-content');
			this.$typeCards = this.$wizard.find('.awana-type-card');
			this.$orgDropdown = this.$wizard.find('.awana-org-dropdown-wrapper');
			this.$orgSelect = $('#awana_selected_organization');

			// Cache WooCommerce elements
			this.$billingFields = $('.woocommerce-billing-fields__field-wrapper');
			this.$shippingFields = $('.woocommerce-shipping-fields');
			this.$additionalFields = $('.woocommerce-additional-fields');
			this.$orderReview = $('.woocommerce-checkout-review-order');
			this.$payment = $('#payment');

			// Add active class to body
			$('body').addClass('awana-wizard-active');

			// Position wizard full-width
			this.positionWizard();

			this.storeOriginalValues();
			this.moveWooCommerceContent();
			this.bindEvents();
			this.updateBodyClass();
			this.updateStepIndicator();
		},

		/**
		 * Position the wizard to be full-width viewport.
		 * Calculates correct margin-left based on actual position.
		 */
		positionWizard: function() {
			var self = this;

			var recalculate = function() {
				var wizardEl = self.$wizard[0];
				var rect = wizardEl.getBoundingClientRect();

				// Calculate how much to offset to reach left edge of viewport
				var offsetLeft = -rect.left;
				self.$wizard.css('margin-left', offsetLeft + 'px');
			};

			// Calculate on init
			recalculate();

			// Recalculate on window resize
			$(window).on('resize', function() {
				recalculate();
			});
		},

		/**
		 * Move WooCommerce content into step containers.
		 */
		moveWooCommerceContent: function() {
			var self = this;
			var $step2Box = this.$wizard.find('[data-step="2"] .awana-step-box');
			var $step3Box = this.$wizard.find('[data-step="3"] .awana-step-box');

			// Create containers for WooCommerce content
			var $billingContainer = $('<div class="awana-wc-fields-container"></div>');
			var $paymentContainer = $('<div class="awana-wc-payment-container"></div>');

			// Move billing fields to step 2
			if (this.$billingFields.length && $step2Box.length) {
				$billingContainer.append(this.$billingFields.clone(true, true));

				// Also include additional fields (order notes)
				if (this.$additionalFields.length) {
					$billingContainer.append(this.$additionalFields.clone(true, true));
				}

				// Insert before the navigation
				$step2Box.find('.awana-step-nav').before($billingContainer);

				// Re-initialize selectWoo on cloned country/state dropdowns
				this.reinitializeSelectWoo($billingContainer);
			}

			// Move payment and order review to step 3
			// Note: Only clone orderReview as it contains the payment section
			if ($step3Box.length) {
				if (this.$orderReview.length) {
					$paymentContainer.append(this.$orderReview.clone(true, true));
				}

				// Insert before the navigation
				$step3Box.find('.awana-step-nav').before($paymentContainer);
			}

			// Sync field values between original and cloned fields
			this.setupFieldSync();
		},

		/**
		 * Refresh the cloned order review section with updated content from WooCommerce.
		 */
		refreshOrderReview: function() {
			var $step3Box = this.$wizard.find('[data-step="3"] .awana-step-box');
			var $paymentContainer = $step3Box.find('.awana-wc-payment-container');

			if ($paymentContainer.length && this.$orderReview.length) {
				// Store which payment method was selected in the clone before refresh
				var $selectedPayment = $paymentContainer.find('input[type="radio"]:checked');
				var selectedPaymentName = $selectedPayment.attr('name');
				var selectedPaymentValue = $selectedPayment.val();

				// Remove old clone
				$paymentContainer.empty();

				// Clone fresh order review
				$paymentContainer.append(this.$orderReview.clone(true, true));

				// Restore payment method selection if one was previously selected
				if (selectedPaymentName && selectedPaymentValue) {
					var $newPayment = $paymentContainer.find('input[name="' + selectedPaymentName + '"][value="' + selectedPaymentValue + '"]');
					if ($newPayment.length) {
						$newPayment.prop('checked', true);
						// Sync to original
						var $original = $('form.checkout').find('input[name="' + selectedPaymentName + '"][value="' + selectedPaymentValue + '"]').not(this.$wizard.find('input'));
						if ($original.length) {
							$original.prop('checked', true).trigger('change');
						}
					}
				}

				// Re-setup field sync for the new clone
				this.setupFieldSync();
			}
		},

		/**
		 * Re-initialize selectWoo on cloned country/state dropdowns.
		 *
		 * @param {jQuery} $container - Container with cloned fields
		 */
		reinitializeSelectWoo: function($container) {
			// Find cloned country and state selects
			var $countrySelect = $container.find('select#billing_country, select[name="billing_country"]');
			var $stateSelect = $container.find('select#billing_state, select[name="billing_state"]');

			// Re-initialize selectWoo if available
			if (typeof $.fn.selectWoo !== 'undefined') {
				if ($countrySelect.length) {
					// Destroy any existing selectWoo instance
					if ($countrySelect.data('selectWoo')) {
						$countrySelect.selectWoo('destroy');
					}
					// Re-initialize
					$countrySelect.selectWoo();
				}

				if ($stateSelect.length) {
					// Destroy any existing selectWoo instance
					if ($stateSelect.data('selectWoo')) {
						$stateSelect.selectWoo('destroy');
					}
					// Re-initialize
					$stateSelect.selectWoo();
				}
			}
		},

		/**
		 * Setup two-way sync between original and cloned form fields.
		 */
		setupFieldSync: function() {
			var self = this;

			// Billing fields sync
			this.$wizard.find('.awana-wc-fields-container').on('change keyup', 'input, select, textarea', function() {
				var $this = $(this);
				var name = $this.attr('name');
				var id = $this.attr('id');

				if (name) {
					// Update original field
					var $original = $('form.checkout').find('[name="' + name + '"]').not(self.$wizard.find('[name="' + name + '"]'));
					if ($original.length) {
						$original.val($this.val());
					}
				}
			});

			// Payment methods sync
			this.$wizard.find('.awana-wc-payment-container').on('change', 'input[type="radio"]', function() {
				var $this = $(this);
				var name = $this.attr('name');
				var value = $this.val();

				if (name) {
					var $original = $('form.checkout').find('input[name="' + name + '"][value="' + value + '"]').not(self.$wizard.find('input'));
					if ($original.length) {
						// Temporarily change cloned radio name to prevent browser from unchecking it
						// when we check the original (they would be in the same radio group)
						var originalName = $this.attr('name');
						$this.attr('name', originalName + '_clone_temp');
						$original.prop('checked', true).trigger('change');
						// Restore the name after sync completes using requestAnimationFrame
						// This ensures the browser has processed the radio group change
						requestAnimationFrame(function() {
							$this.attr('name', originalName);
						});
					}
				}
			});
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

			// Card selection
			this.$typeCards.on('click keypress', function(e) {
				if (e.type === 'keypress' && e.which !== 13 && e.which !== 32) {
					return;
				}
				e.preventDefault();
				self.selectCard($(this));
			});

			// Organization dropdown change
			this.$orgSelect.on('change', function() {
				self.handleOrgSelection($(this).val());
			});

			// Continue button
			this.$wizard.on('click', '.awana-btn-continue', function(e) {
				e.preventDefault();
				var nextStep = $(this).data('next-step');
				self.goToStep(nextStep);
			});

			// Back button
			this.$wizard.on('click', '.awana-btn-back', function(e) {
				e.preventDefault();
				var prevStep = $(this).data('prev-step');
				self.goToStep(prevStep);
			});

			// Update original values after WooCommerce checkout updates
			$(document.body).on('updated_checkout', function() {
				if (self.paymentType === 'private') {
					self.storeOriginalValues();
				}
				// Re-sync cloned fields with originals after checkout update
				self.syncFieldsFromOriginals();
				// Refresh cloned order review to show updated totals
				self.refreshOrderReview();
			});

			// Handle WooCommerce validation errors
			$(document.body).on('checkout_error', function() {
				self.handleValidationError();
			});
		},

		/**
		 * Sync cloned fields from original fields (after WooCommerce updates).
		 */
		syncFieldsFromOriginals: function() {
			var self = this;

			// Sync billing fields
			this.$wizard.find('.awana-wc-fields-container input, .awana-wc-fields-container select, .awana-wc-fields-container textarea').each(function() {
				var $clone = $(this);
				var name = $clone.attr('name');

				if (name) {
					var $original = $('form.checkout').find('[name="' + name + '"]').not(self.$wizard.find('[name="' + name + '"]'));
					if ($original.length && $original.val()) {
						$clone.val($original.val());
					}
				}
			});
		},

		/**
		 * Handle card selection.
		 *
		 * @param {jQuery} $card - The clicked card
		 */
		selectCard: function($card) {
			var type = $card.data('type');

			// Update visual state
			this.$typeCards.removeClass('selected').attr('aria-pressed', 'false');
			$card.addClass('selected').attr('aria-pressed', 'true');

			// Update hidden radio
			$card.find('input[type="radio"]').prop('checked', true);

			// Store selection
			this.paymentType = type;

			// Show/hide org dropdown
			if (type === 'organization') {
				this.$orgDropdown.addClass('visible');
				// If an org is already selected, fill the fields
				var selectedOrg = this.$orgSelect.val();
				if (selectedOrg) {
					this.handleOrgSelection(selectedOrg);
				}
			} else {
				this.$orgDropdown.removeClass('visible');
				this.selectedOrg = null;
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
				this.selectedOrg = null;
				return;
			}

			var org = this.findOrgById(orgId);
			if (!org) {
				this.selectedOrg = null;
				return;
			}

			this.selectedOrg = org;
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
			} else {
				// Clear address fields if organization lacks billing address
				this.setFieldValue('billing_address_1', '');
				this.setFieldValue('billing_postcode', '');
				this.setFieldValue('billing_city', '');
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
		 * Set a field value and trigger change (both original and cloned).
		 *
		 * @param {string} fieldId - Field ID without #
		 * @param {string} value - Value to set
		 */
		setFieldValue: function(fieldId, value) {
			// Set on original field
			var $field = $('#' + fieldId);
			if ($field.length) {
				$field.val(value).trigger('change');
			}

			// Set on cloned field in wizard
			var $cloned = this.$wizard.find('#' + fieldId + ', [name="' + fieldId + '"]');
			if ($cloned.length) {
				$cloned.val(value).trigger('change');
			}
		},

		/**
		 * Validate the current step.
		 *
		 * @return {boolean} Whether validation passed
		 */
		validateStep: function() {
			var self = this;

			if (this.currentStep === 1) {
				// Must have a payment type selected
				if (!this.paymentType) {
					this.showError('Velg kundetype for å fortsette.');
					return false;
				}

				// If organization selected, must have an org chosen
				if (this.paymentType === 'organization') {
					var orgVal = this.$orgSelect.val();
					if (!orgVal) {
						this.showError('Velg organisasjon for å fortsette.');
						return false;
					}
				}

				return true;
			}

			if (this.currentStep === 2) {
				// Check required billing fields in our cloned container
				var requiredFields = [
					'billing_first_name',
					'billing_last_name',
					'billing_address_1',
					'billing_postcode',
					'billing_city',
					'billing_email'
				];

				var isValid = true;
				var $container = this.$wizard.find('.awana-wc-fields-container');

				requiredFields.forEach(function(field) {
					var $field = $container.find('#' + field + ', [name="' + field + '"]');
					var $row = $field.closest('.form-row');

					if ($field.length) {
						if (!$field.val()) {
							$row.addClass('woocommerce-invalid woocommerce-invalid-required-field');
							isValid = false;
						} else {
							$row.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
						}
					}
				});

				if (!isValid) {
					this.showError('Fyll ut alle obligatoriske felt.');
					return false;
				}

				// Sync values to original fields before proceeding
				this.syncToOriginalFields();

				return true;
			}

			return true;
		},

		/**
		 * Sync values from wizard fields to original WooCommerce fields.
		 */
		syncToOriginalFields: function() {
			var self = this;

			this.$wizard.find('.awana-wc-fields-container input, .awana-wc-fields-container select, .awana-wc-fields-container textarea').each(function() {
				var $clone = $(this);
				var name = $clone.attr('name');

				if (name) {
					var $original = $('form.checkout').find('[name="' + name + '"]').not(self.$wizard.find('[name="' + name + '"]'));
					if ($original.length) {
						$original.val($clone.val());
					}
				}
			});
		},

		/**
		 * Show error message.
		 *
		 * @param {string} message - Error message
		 */
		showError: function(message) {
			var $content = this.$stepContents.filter('.active');
			var $box = $content.find('.awana-step-box');
			var $existing = $box.find('.awana-error');

			if ($existing.length) {
				$existing.text(message);
			} else {
				$box.find('.awana-step-title').after(
					'<div class="awana-error">' + message + '</div>'
				);
			}

			// Scroll to error
			$('html, body').animate({
				scrollTop: this.$wizard.offset().top - 20
			}, 300);
		},

		/**
		 * Clear error messages.
		 */
		clearErrors: function() {
			this.$wizard.find('.awana-error').remove();
		},

		/**
		 * Go to a specific step.
		 *
		 * @param {number} step - Step number (1, 2, or 3)
		 */
		goToStep: function(step) {
			// Validate current step before proceeding forward
			if (step > this.currentStep && !this.validateStep()) {
				return;
			}

			this.clearErrors();

			// Store current step
			this.currentStep = step;

			// Update step indicator
			this.updateStepIndicator();

			// Update step content
			this.$stepContents.removeClass('active');
			this.$stepContents.filter('[data-step="' + step + '"]').addClass('active');

			// Update body class for CSS targeting
			this.updateBodyClass();

			// Handle step-specific setup
			this.setupStepContent(step);

			// Scroll to top of wizard
			$('html, body').animate({
				scrollTop: this.$wizard.offset().top - 20
			}, 300);
		},

		/**
		 * Update step indicator UI.
		 */
		updateStepIndicator: function() {
			var self = this;

			this.$steps.each(function() {
				var $step = $(this);
				var stepNum = $step.data('step');

				$step.removeClass('active completed');

				if (stepNum < self.currentStep) {
					$step.addClass('completed').removeAttr('aria-current');
				} else if (stepNum === self.currentStep) {
					$step.addClass('active').attr('aria-current', 'step');
				} else {
					$step.removeAttr('aria-current');
				}
			});
		},

		/**
		 * Update body class based on current step.
		 */
		updateBodyClass: function() {
			$('body')
				.removeClass('awana-wizard-step-1 awana-wizard-step-2 awana-wizard-step-3')
				.addClass('awana-wizard-step-' + this.currentStep);
		},

		/**
		 * Setup content for a specific step.
		 *
		 * @param {number} step - Step number
		 */
		setupStepContent: function(step) {
			if (step === 2) {
				// Update org info text if organization is selected
				var $infoText = this.$wizard.find('.awana-org-info-text');
				if (this.paymentType === 'organization' && this.selectedOrg) {
					$infoText.text('Handler for: ' + this.selectedOrg.title).show();
				} else {
					$infoText.hide();
				}

				// Sync field values from originals
				this.syncFieldsFromOriginals();

				// Trigger WooCommerce checkout update
				$(document.body).trigger('update_checkout');
			}

			if (step === 3) {
				// Sync payment section
				$(document.body).trigger('update_checkout');
			}
		},

		/**
		 * Handle WooCommerce validation errors.
		 */
		handleValidationError: function() {
			// Check if errors are related to billing fields
			var $billingErrors = $('.woocommerce-error li').filter(function() {
				var text = $(this).text().toLowerCase();
				return text.indexOf('billing') !== -1 ||
					text.indexOf('faktura') !== -1 ||
					text.indexOf('adresse') !== -1 ||
					text.indexOf('e-post') !== -1 ||
					text.indexOf('telefon') !== -1 ||
					text.indexOf('first name') !== -1 ||
					text.indexOf('last name') !== -1;
			});

			if ($billingErrors.length && this.currentStep !== 2) {
				this.goToStep(2);
			}

			// Check if errors are related to payment
			var $paymentErrors = $('.woocommerce-error li').filter(function() {
				var text = $(this).text().toLowerCase();
				return text.indexOf('betaling') !== -1 ||
					text.indexOf('payment') !== -1;
			});

			if ($paymentErrors.length && this.currentStep !== 3) {
				this.goToStep(3);
			}
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		AwanaCheckoutWizard.init();
	});

})(jQuery);
