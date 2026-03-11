# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-03-11

### Changed
- **Renamed plugin** from "Awana Digital Sync" to "Awana Commerce" to reflect expanded scope (B2B checkout, org sync, admin dashboard)
- Main file renamed from `awana-digital-sync.php` to `awana-commerce.php`
- Text domain changed from `awana-digital-sync` to `awana-commerce`
- Constants renamed from `AWANA_DIGITAL_SYNC_*` to `AWANA_COMMERCE_*` (backward-compat defines added)

## [1.1.3] - 2026-03-11

### Added
- B2B checkout: 3-step wizard (customer type, billing details, payment) with org selector
- Auto-fill billing fields from Firebase organization data when org is selected
- Organization number checkout field (moved from code snippet into plugin)
- TTL-based organization sync on cart and checkout pages
- Admin debug page for B2B org sync validation

### Fixed
- Billing email now falls back to WP user email when org has no `billingEmail`
- Organization number field auto-populated from org data on selection
- Wizard positioning on resize and completed step number visibility
- Payment/shipping method sync in checkout wizard after WooCommerce AJAX updates

## [1.1.2] - 2025-01-XX

### Added
- Search functionality by order ID or invoice ID across all dashboard sections
- Firebase links for invoice IDs (opens invoice document in Firestore)
- Recent sync activity list with sync type detection
- Sync health checks: completed orders not synced as paid, orders with high error counts
- AJAX sync buttons in health check sections with live feedback

## [1.1.1] - 2025-01-XX

### Added
- Sync status tracking via repurposed `crm_sync_woo` meta field
- Automatic CRM sync when order status changes to "completed"
- Admin UI dashboard for managing syncs (`WooCommerce → Awana Sync`)
- Manual sync functionality by order ID
- Failed syncs list with retry functionality
- Sync tracking meta fields: `_awana_sync_last_attempt`, `_awana_sync_last_success`, `_awana_sync_last_error`, `_awana_sync_error_count`

### Changed
- Status mapping: `pog_status="order"` → `status="transferred"` (was `"pending"`)
- Status mapping prioritizes WooCommerce order status over POG status
- `crm_sync_woo` tracks sync status (`success`/`failed`/`pending`/`never_synced`) instead of static `synced` value

## [1.0.1] - 2025-01-XX

### Fixed
- Minor bug fixes and improvements

## [1.1.0] - 2025-01-XX

### Added
- Outbound webhook for invoice status/KID/invoice number sync (`invoiceStatusWebhook`)
- Support for syncing `pog_status`, `pog_kid_number`, and `pog_invoice_number` meta fields
- Per-field deduplication markers (`_pog_*_synced_to_crm`) to prevent duplicate webhook sends
- Status mapping: `pog_status=order` → `status=pending`, `pog_status=invoice` → `status=unpaid`
- New configuration constants:
  - `AWANA_INVOICE_STATUS_WEBHOOK_URL` (required)
  - `AWANA_INVOICE_STATUS_WEBHOOK_API_KEY` (optional)

### Changed
- Split POG sync into two separate webhooks:
  - `invoiceCustomerNumberWebhook`: only sends `pog_customer_number` changes
  - `invoiceStatusWebhook`: sends `pog_status`, `pog_kid_number`, `pog_invoice_number` changes
- Updated `notify_pog_customer_number_to_crm()` payload (removed `memberId` field)
- Refactored webhook sending to use shared `send_x_api_key_webhook()` method

### Fixed
- Prevent duplicate webhook sends when both `updated_postmeta` and HPOS save hooks fire
- Improved deduplication logic to track last synced value per field

## [1.0.0] - 2024-XX-XX

### Added
- Initial release
- Inbound REST API endpoint `/awana/v1/invoice` for creating/updating orders from CRM
- Outbound webhook `invoiceCustomerNumberWebhook` for syncing POG customer numbers to CRM
- Support for guest orders with CRM invoice metadata
- Product mapping by ID or SKU
- WooCommerce HPOS (High-Performance Order Storage) compatibility
- Comprehensive logging via WooCommerce logger



