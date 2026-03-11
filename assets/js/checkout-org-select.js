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
			'billing_email',
			'org_number'
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
			// Reset margin-left to 0 before reading getBoundingClientRect
			// to avoid contamination from previously applied margin
			self.$wizard.css('margin-left', '0');
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
		 *
		 * Billing fields are moved (detach) to avoid duplicate IDs.
		 * Order review/payment is cloned because WooCommerce AJAX replaces the original.
		 */
		moveWooCommerceContent: function() {
			var $step2Box = this.$wizard.find('[data-step="2"] .awana-step-box');
			var $step3Box = this.$wizard.find('[data-step="3"] .awana-step-box');

			// Move billing fields to step 2 (originals, no cloning)
			if (this.$billingFields.length && $step2Box.length) {
				var $billingContainer = $('<div class="awana-wc-fields-container"></div>');
				$billingContainer.append(this.$billingFields.detach());

				if (this.$additionalFields.length) {
					$billingContainer.append(this.$additionalFields.detach());
				}

				$step2Box.find('.awana-step-nav').before($billingContainer);
			}

			// Clone order review/payment to step 3 (WooCommerce AJAX replaces the original)
			if ($step3Box.length && this.$orderReview.length) {
				var $paymentContainer = $('<div class="awana-wc-payment-container"></div>');
				$paymentContainer.append(this.$orderReview.clone(true, true));
				$step3Box.find('.awana-step-nav').before($paymentContainer);
			}

			// Setup sync for cloned payment/shipping radio buttons
			this.setupPaymentMethodSync();
			this.setupShippingMethodSync();
		},

		/**
		 * Setup payment method selection sync.
		 * Fixes duplicate ID issues with cloned radio buttons.
		 */
		setupPaymentMethodSync: function() {
			var self = this;
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			if (!$paymentContainer.length) {
				return;
			}

			// Rename IDs and setup handlers with slight delay to ensure DOM is ready
			setTimeout(function() {
				self.renamePaymentRadioIds();
				self.attachPaymentClickHandlers();
				self.syncPaymentFromOriginal();
			}, 100);
		},

		/**
		 * Rename payment radio IDs and names to avoid duplicates with original form.
		 */
		renamePaymentRadioIds: function() {
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			$paymentContainer.find('input[type="radio"][name="payment_method"]').each(function() {
				var $radio = $(this);
				var originalId = $radio.attr('id');

				// Rename ID
				if (originalId && originalId.indexOf('awana_') !== 0) {
					var newId = 'awana_' + originalId;
					$radio.attr('id', newId);

					// Update associated label
					var $label = $paymentContainer.find('label[for="' + originalId + '"]');
					if ($label.length) {
						$label.attr('for', newId);
					}
				}

				// Rename name attribute to prevent browser radio group conflicts
				$radio.attr('name', 'awana_payment_method');
			});
		},

		/**
		 * Attach click handlers to payment methods in wizard.
		 */
		attachPaymentClickHandlers: function() {
			var self = this;
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			// Direct click on list items - use native event listener for reliability
			$paymentContainer.find('.wc_payment_method').each(function() {
				var $method = $(this);

				// Remove any existing handlers to prevent duplicates
				$method.off('click.awanaPayment');

				// Attach new handler
				$method.on('click.awanaPayment', function(e) {
					// Don't handle if clicking directly on a link
					if ($(e.target).is('a')) {
						return;
					}

					e.preventDefault();
					e.stopPropagation();

					var $radio = $method.find('input[type="radio"]');
					if ($radio.length && !$radio.prop('disabled')) {
						self.selectPaymentMethod($method, $radio);
					}
				});
			});

			// Also handle direct clicks on radios (use new name after renaming)
			$paymentContainer.find('input[type="radio"][name="awana_payment_method"]').each(function() {
				var $radio = $(this);
				$radio.off('change.awanaPayment click.awanaPayment');

				$radio.on('change.awanaPayment click.awanaPayment', function(e) {
					e.stopPropagation();
					var $method = $radio.closest('.wc_payment_method');
					self.selectPaymentMethod($method, $radio);
				});
			});
		},

		/**
		 * Select a payment method in the wizard.
		 */
		selectPaymentMethod: function($method, $radio) {
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			// Check the radio (use the renamed name attribute)
			$paymentContainer.find('input[type="radio"][name="awana_payment_method"]').prop('checked', false);
			$radio.prop('checked', true);

			// Update visual state
			$paymentContainer.find('.wc_payment_method').removeClass('payment_method_active');
			$method.addClass('payment_method_active');

			// Show/hide payment box
			$paymentContainer.find('.payment_box').hide();
			$method.find('.payment_box').show();

			// Sync to original WooCommerce form
			this.syncPaymentToOriginal($radio.val());
		},

		/**
		 * Sync payment method selection to original WooCommerce form.
		 */
		syncPaymentToOriginal: function(value) {
			// Find original radios (not inside our wizard)
			var $originalRadio = $('form.checkout').find('input[name="payment_method"][value="' + value + '"]').filter(function() {
				return !$(this).closest('.awana-wc-payment-container').length;
			});

			if ($originalRadio.length) {
				$originalRadio.prop('checked', true).trigger('click').trigger('change');
			}
		},

		/**
		 * Sync payment method selection from original WooCommerce form to wizard.
		 */
		syncPaymentFromOriginal: function() {
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			// Find checked original radio
			var $checkedOriginal = $('form.checkout').find('input[name="payment_method"]:checked').filter(function() {
				return !$(this).closest('.awana-wc-payment-container').length;
			});

			if ($checkedOriginal.length) {
				var value = $checkedOriginal.val();
				// Use the renamed name attribute for wizard radios
				var $clonedRadio = $paymentContainer.find('input[name="awana_payment_method"][value="' + value + '"]');

				if ($clonedRadio.length) {
					$paymentContainer.find('input[name="awana_payment_method"]').prop('checked', false);
					$clonedRadio.prop('checked', true);

					$paymentContainer.find('.wc_payment_method').removeClass('payment_method_active');
					$clonedRadio.closest('.wc_payment_method').addClass('payment_method_active');

					$paymentContainer.find('.payment_box').hide();
					$clonedRadio.closest('.wc_payment_method').find('.payment_box').show();
				}
			} else {
				// No original selected, select first one
				var $firstRadio = $paymentContainer.find('input[name="awana_payment_method"]').first();
				if ($firstRadio.length) {
					$firstRadio.prop('checked', true);
					$firstRadio.closest('.wc_payment_method').addClass('payment_method_active');
					$firstRadio.closest('.wc_payment_method').find('.payment_box').show();
					this.syncPaymentToOriginal($firstRadio.val());
				}
			}
		},

		/**
		 * Setup shipping method selection sync.
		 */
		setupShippingMethodSync: function() {
			var self = this;
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			// Setup with slight delay to ensure DOM is ready
			setTimeout(function() {
				self.renameShippingRadioIds();
				self.attachShippingClickHandlers();
				self.syncShippingFromOriginal();
			}, 100);
		},

		/**
		 * Rename shipping radio IDs and names to avoid duplicates.
		 */
		renameShippingRadioIds: function() {
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			$paymentContainer.find('input[type="radio"][name^="shipping_method"]').each(function() {
				var $radio = $(this);
				var originalId = $radio.attr('id');
				var originalName = $radio.attr('name');

				// Rename ID
				if (originalId && originalId.indexOf('awana_') !== 0) {
					var newId = 'awana_' + originalId;
					$radio.attr('id', newId);

					var $label = $paymentContainer.find('label[for="' + originalId + '"]');
					if ($label.length) {
						$label.attr('for', newId);
					}
				}

				// Rename name attribute to prevent browser radio group conflicts
				// Store original name as data attribute for syncing
				if (originalName && originalName.indexOf('awana_') !== 0) {
					$radio.data('original-name', originalName);
					$radio.attr('name', 'awana_' + originalName);
				}
			});
		},

		/**
		 * Attach click handlers to shipping methods in wizard.
		 */
		attachShippingClickHandlers: function() {
			var self = this;
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			// Handle clicks on shipping method rows
			$paymentContainer.find('.woocommerce-shipping-methods li').each(function() {
				var $li = $(this);
				$li.off('click.awanaShipping');

				$li.on('click.awanaShipping', function(e) {
					if ($(e.target).is('a')) return;

					e.preventDefault();
					e.stopPropagation();

					var $radio = $li.find('input[type="radio"]');
					if ($radio.length && !$radio.prop('disabled')) {
						self.selectShippingMethod($radio);
					}
				});
			});

			// Handle direct clicks on radios (use the renamed name pattern)
			$paymentContainer.find('input[type="radio"][name^="awana_shipping_method"]').each(function() {
				var $radio = $(this);
				$radio.off('change.awanaShipping click.awanaShipping');

				$radio.on('change.awanaShipping click.awanaShipping', function(e) {
					e.stopPropagation();
					self.selectShippingMethod($radio);
				});
			});
		},

		/**
		 * Select a shipping method in the wizard.
		 */
		selectShippingMethod: function($radio) {
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');
			var name = $radio.attr('name');
			var originalName = $radio.data('original-name') || name.replace('awana_', '');

			// Check the radio
			$paymentContainer.find('input[name="' + name + '"]').prop('checked', false);
			$radio.prop('checked', true);

			// Sync to original WooCommerce form using the original name
			this.syncShippingToOriginal(originalName, $radio.val());
		},

		/**
		 * Sync shipping method selection to original WooCommerce form.
		 */
		syncShippingToOriginal: function(name, value) {
			var $originalRadio = $('form.checkout').find('input[name="' + name + '"][value="' + value + '"]').filter(function() {
				return !$(this).closest('.awana-wc-payment-container').length;
			});

			if ($originalRadio.length) {
				$originalRadio.prop('checked', true).trigger('change');
				$(document.body).trigger('update_checkout');
			}
		},

		/**
		 * Sync shipping method selection from original WooCommerce form to wizard.
		 */
		syncShippingFromOriginal: function() {
			var self = this;
			var $paymentContainer = this.$wizard.find('.awana-wc-payment-container');

			// Find all shipping method groups from original form
			$('form.checkout').find('input[type="radio"][name^="shipping_method"]:checked').filter(function() {
				return !$(this).closest('.awana-wc-payment-container').length;
			}).each(function() {
				var $original = $(this);
				var originalName = $original.attr('name');
				var value = $original.val();

				// Find cloned radio using the renamed name (awana_ prefix)
				var renamedName = 'awana_' + originalName;
				var $clonedRadio = $paymentContainer.find('input[name="' + renamedName + '"][value="' + value + '"]');
				if ($clonedRadio.length) {
					$paymentContainer.find('input[name="' + renamedName + '"]').prop('checked', false);
					$clonedRadio.prop('checked', true);
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

				// If on step 3, restore payment/shipping handlers and rename IDs
				// (WooCommerce AJAX updates may replace DOM elements)
				if (self.currentStep === 3) {
					self.renamePaymentRadioIds();
					self.renameShippingRadioIds();
					self.attachPaymentClickHandlers();
					self.attachShippingClickHandlers();
					self.syncPaymentFromOriginal();
					self.syncShippingFromOriginal();
				}
			});

			// Handle WooCommerce validation errors
			$(document.body).on('checkout_error', function() {
				self.handleValidationError();
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

			var emailValue = org.billingEmail || '';
			if (!emailValue && typeof awanaOrgData !== 'undefined' && awanaOrgData.userEmail) {
				emailValue = awanaOrgData.userEmail;
			}
			this.setFieldValue('billing_email', emailValue);

			// Organization number
			this.setFieldValue('org_number', org.orgNumber || '');
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
		 * Set a billing field value and trigger change.
		 *
		 * @param {string} fieldId - Field ID without #
		 * @param {string} value - Value to set
		 */
		setFieldValue: function(fieldId, value) {
			var $field = $('#' + fieldId);
			if ($field.length) {
				$field.val(value).trigger('change');
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
				// Check required billing fields
				var requiredFields = [
					'billing_first_name',
					'billing_last_name',
					'billing_address_1',
					'billing_postcode',
					'billing_city',
					'billing_phone',
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

				return true;
			}

			return true;
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
				var $error = $('<div class="awana-error"></div>').text(message);
				$box.find('.awana-step-title').after($error);
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
					$step.addClass('completed');
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

				// Trigger WooCommerce checkout update
				$(document.body).trigger('update_checkout');
			}

			if (step === 3) {
				// Re-rename IDs and re-attach handlers in case WooCommerce re-rendered
				this.renamePaymentRadioIds();
				this.renameShippingRadioIds();
				this.attachPaymentClickHandlers();
				this.attachShippingClickHandlers();

				// Sync payment and shipping methods from original
				this.syncPaymentFromOriginal();
				this.syncShippingFromOriginal();

				// Trigger WooCommerce checkout update
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
				return;
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
