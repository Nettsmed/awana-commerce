# Awana Commerce

WooCommerce integration hub for Awana — connects CRM, WooCommerce, Integrera, and PowerOffice Go (POG) to manage membership invoices end-to-end.

## What It Does

| Feature | Description |
|---------|-------------|
| **Invoice Sync** | Receives invoices from CRM via REST API, creates/updates WooCommerce guest orders |
| **CRM Webhooks** | Sends POG customer numbers, invoice status, and KID back to CRM when Integrera updates orders |
| **B2B Checkout** | 3-step wizard with org selector, billing auto-fill from Firebase organization data |
| **Org Sync** | TTL-based (4h) Firebase organization refresh on cart and checkout pages |
| **Admin Dashboard** | Sync statistics, failed sync retry, health checks (WooCommerce → Awana Sync) |
| **Debug Tools** | Org sync validation, Firebase UID management (WooCommerce → Awana Org Debug) |

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+

## Installation

1. Copy the `awana-commerce` folder to `wp-content/plugins/`
2. Activate in WordPress admin
3. Configure constants in `wp-config.php` (see [Setup Guide](SETUP.md))

## Configuration (wp-config.php)

```php
// Inbound API authentication
define( 'AWANA_DIGITAL_API_KEY', 'your-secret-api-key' );

// Firebase org sync
define( 'AWANA_FIREBASE_GET_ORGS_URL', 'https://europe-west3-awana-server.cloudfunctions.net/getUserOrganizations' );
define( 'AWANA_FIREBASE_API_KEY', 'your-firebase-api-key' );

// Outbound webhooks (Woo → CRM)
define( 'AWANA_POG_CUSTOMER_WEBHOOK_URL', 'https://...' );
define( 'AWANA_POG_CUSTOMER_WEBHOOK_API_KEY', 'x-api-key' );
define( 'AWANA_INVOICE_STATUS_WEBHOOK_URL', 'https://...' );
define( 'AWANA_INVOICE_STATUS_WEBHOOK_API_KEY', 'x-api-key' );  // optional
```

## Data Flow

```
CRM (Firebase)
  │
  ├─── POST /awana/v1/invoice ───► WooCommerce (guest order)
  │                                     │
  │                                     ▼
  │                                Integrera ───► POG (accounting)
  │                                     │
  │    ◄── invoiceCustomerNumberWebhook ┤  (pog_customer_number)
  │    ◄── invoiceStatusWebhook ────────┘  (status, KID, invoice number)
  │
  └─── Order completed ──► invoiceStatusWebhook (status=paid)
```

## Inbound API

### POST `/wp-json/awana/v1/invoice`

Creates or updates a WooCommerce guest order from a CRM invoice. Idempotent by `invoiceId`.

**Auth:** `X-CRM-API-Key` header

**Request:**
```json
{
  "invoiceId": "firebaseRecordName",
  "invoiceNumber": "321665",
  "status": "unpaid",
  "memberId": "uuid",
  "organizationId": "org-slug",
  "email": "billing@example.com",
  "currency": "NOK",
  "total": 1750,
  "source": "awana-crm",
  "invoiceLines": [
    { "productId": 3102, "quantity": 1, "description": "Membership fee 2025" }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "wooOrderId": 1234,
  "wooStatus": "on-hold",
  "digitalInvoiceId": "firebaseRecordName",
  "message": "Order created from digital invoice"
}
```

**Status mapping** (CRM → WooCommerce): `draft`→`pending`, `unpaid`→`on-hold`, `paid`→`completed`, `cancelled`→`cancelled`, `refunded`→`refunded`

## Outbound Webhooks (Woo → CRM)

### 1) invoiceCustomerNumberWebhook

Triggered when `pog_customer_number` changes on an order.

**Payload:** `{ "invoiceId": "...", "pog_customer_number": "10199" }`

### 2) invoiceStatusWebhook

Triggered when `pog_status`, `pog_kid_number`, or `pog_invoice_number` changes, or when order status changes to `completed`.

**Payload:** `{ "invoiceId": "...", "kid": "...", "pogInvoiceNumber": "...", "status": "unpaid" }`

**Status mapping** (POG → webhook): `order`→`transferred`, `invoice`→`unpaid`, WC `completed`→`paid`

Deduplication: per-field `_pog_*_synced_to_crm` meta keys prevent duplicate sends.

## Order Meta Fields

| Prefix | Keys | Written by |
|--------|------|------------|
| `crm_` | `invoice_id`, `member_id`, `organization_id`, `source`, `sync_woo` | REST API (inbound) |
| `pog_` | `customer_number`, `invoice_number`, `kid_number`, `status` | Integrera/POG |
| `_awana_sync_` | `last_attempt`, `last_success`, `last_error`, `error_count` | Webhook handler |
| `_awana_selected_` | `org_id`, `org_member_id`, `org_title` | Checkout wizard |

## Product Mapping

Products are matched by WooCommerce product ID first, then by SKU. Prices come from WooCommerce unless `unitPrice` is provided in the API payload.

## Logging

WooCommerce → Status → Logs → select **awana_digital** from the dropdown.

## Security

- API key auth required for all inbound endpoints
- Keys must be defined in `wp-config.php`, never in plugin code
- Uses `hash_equals()` for timing-safe key comparison
- All webhook payloads sent over HTTPS
