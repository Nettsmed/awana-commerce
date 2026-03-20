# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Awana Commerce is a WordPress/WooCommerce plugin that serves as the integration hub between four systems:

- **CRM (Firebase)** — master for organizations and invoices
- **WooCommerce** — stateless order engine, creates guest orders from CRM invoices
- **Integrera** — integration hub, sends orders to POG and pushes updates back to Woo
- **PowerOffice Go (POG)** — accounting system, issues invoices, tracks payments

The core flow: CRM creates invoices → Woo receives them via REST API → Integrera forwards to POG → POG updates flow back through Woo to CRM via webhooks.

## Architecture

### Entry Point & Initialization

`awana-commerce.php` — main plugin file. Defines constants (`AWANA_COMMERCE_VERSION`, `_PATH`, `_URL`), includes all classes, and registers WordPress hooks for POG meta sync, order status changes, and HPOS compatibility.

### Key Classes

| Class | File | Role |
|-------|------|------|
| `Awana_REST_Controller` | `includes/class-awana-rest-controller.php` | Inbound REST API (`POST /awana/v1/invoice`), API key auth via `X-CRM-API-Key` header |
| `Awana_Order_Handler` | `includes/class-awana-order-handler.php` | Creates/updates WooCommerce guest orders from CRM invoice data. Idempotent by `invoiceId` |
| `Awana_CRM_Webhook` | `includes/class-awana-crm-webhook.php` | Outbound webhooks to CRM. Two endpoints: `invoiceCustomerNumberWebhook` and `invoiceStatusWebhook` |
| `Awana_Org_Sync` | `includes/class-awana-org-sync.php` | Firebase org sync with TTL-based refresh (4h) on cart/checkout pages |
| `Awana_Checkout_Org` | `includes/class-awana-checkout-org.php` | B2B 3-step checkout wizard with org selector and billing auto-fill |
| `Awana_Admin` | `includes/class-awana-admin.php` | Admin dashboard: sync stats, failed syncs, manual sync, health checks |
| `Awana_B2B_Sync_Status` | `includes/class-awana-b2b-sync-status.php` | CRM Sync admin page: B2B order sync status, retry, POG tracking |
| `Awana_Debug` | `includes/class-awana-debug.php` | Debug page for org sync validation, Firebase UID management |
| `Awana_Logger` | `includes/class-awana-logger.php` | Wrapper around WooCommerce logger (source: `awana_digital`) |

### Helper Files

- `includes/helpers.php` — `awana_find_order_by_invoice_id()`, status mapping, name splitting
- `includes/product-mapping.php` — Product lookup by ID or SKU, line item creation with custom pricing

### Webhook Deduplication

POG field changes (`pog_customer_number`, `pog_status`, `pog_kid_number`, `pog_invoice_number`) are detected via both `updated_postmeta` and `woocommerce_after_order_object_save` hooks. Per-field `_pog_*_synced_to_crm` meta keys prevent duplicate webhook sends.

## Development

### No Build Step

This is a plain PHP WordPress plugin — no build, transpile, or bundler. Edit PHP/JS/CSS files directly.

### Deploy to Production

```bash
rsync -avz --exclude='.git' --exclude='node_modules' --exclude='.github' \
  /Users/sinfjell/repositories/awana-commerce/ \
  awana:/www/awana_753/public/wp-content/plugins/awana-commerce/
```

Host: awana.no (Kinsta). SSH alias: `awana`.

### Verify on Server

```bash
ssh awana "cd /www/awana_753/public && wp plugin status awana-commerce --allow-root"
ssh awana "cd /www/awana_753/public && wp eval 'echo AWANA_COMMERCE_VERSION;' --allow-root"
```

### View Logs

WooCommerce logs: **WooCommerce → Status → Logs** → select `awana_digital` from dropdown.

## Conventions

- **Text domain:** `awana-commerce`
- **Constants prefix:** `AWANA_COMMERCE_*` (backward-compat for `AWANA_DIGITAL_SYNC_*`)
- **Class prefix:** `Awana_` (company name, not plugin-specific)
- **Function prefix:** `awana_`
- **All classes use static `init()` pattern** — instantiate `new self()` and register hooks
- **WooCommerce HPOS compatible** — uses `$order->get_meta()` / `update_meta_data()`, not `get_post_meta()`

## wp-config.php Constants (Required on Server)

```
AWANA_DIGITAL_API_KEY              — REST API auth key
AWANA_FIREBASE_GET_ORGS_URL        — Firebase getUserOrganizations endpoint
AWANA_FIREBASE_API_KEY             — Firebase API key
AWANA_POG_CUSTOMER_WEBHOOK_URL     — invoiceCustomerNumberWebhook URL
AWANA_POG_CUSTOMER_WEBHOOK_API_KEY — x-api-key for customer number webhook
AWANA_INVOICE_STATUS_WEBHOOK_URL   — invoiceStatusWebhook URL
AWANA_INVOICE_STATUS_WEBHOOK_API_KEY — x-api-key for status webhook + checkout invoice creation
AWANA_FIREBASE_CHECKOUT_INVOICE_URL — createCheckoutInvoice endpoint (B2B checkout → CRM)
```

## Order Meta Keys (Do Not Rename)

These are stored on existing orders in production — renaming would break data:

- CRM identifiers: `crm_invoice_id`, `crm_member_id`, `crm_organization_id`, `crm_source`, `crm_sync_woo`
- POG fields: `pog_customer_number`, `pog_invoice_number`, `pog_kid_number`, `pog_status`
- Sync tracking: `_awana_sync_last_attempt`, `_awana_sync_last_success`, `_awana_sync_last_error`, `_awana_sync_error_count`
- Dedup markers: `_pog_customer_synced_to_crm`, `_pog_invoice_number_synced_to_crm`, `_pog_kid_number_synced_to_crm`, `_pog_status_synced_to_crm`, `_awana_checkout_invoice_synced`
- Checkout: `_awana_selected_org_id`, `_awana_selected_org_member_id`, `_awana_selected_org_title`, `_awana_payment_type`
- User meta: `_awana_organizations`, `_awana_orgs_last_sync`
