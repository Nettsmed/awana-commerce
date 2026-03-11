# Awana Commerce

WooCommerce integration hub for Awana — invoice sync, CRM webhooks, B2B checkout, Firebase org sync, and admin dashboard.

## Installation

1. Copy the `awana-commerce` folder to `wp-content/plugins/`
2. Activate the plugin in WordPress admin
3. Add the API key to `wp-config.php`:

```php
define( 'AWANA_DIGITAL_API_KEY', 'your-secret-api-key-here' );
```

### Firebase Org Sync (Login)

Configure the Firebase org sync in `wp-config.php`:

```php
define( 'AWANA_FIREBASE_GET_ORGS_URL', 'https://europe-west3-awana-server.cloudfunctions.net/getUserOrganizations' );
define( 'AWANA_FIREBASE_API_KEY', 'your-firebase-api-key-here' );
```

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+

## API Endpoints

### 1. Create/Update Invoice

**POST** `/wp-json/awana/v1/invoice`

**Headers:**
```
X-CRM-API-Key: your-api-key
Content-Type: application/json
```

**Request Body:**
```json
{
  "invoiceId": "firebaseRecordName",
  "invoiceNumber": "321665",
  "status": "unpaid",
  "type": "membership_fee",
  "memberId": "b8dab589-dbde-4516-b56e-6b5fcb853ec6",
  "memberName": "Kristkirken søndagsskole Bergen",
  "organizationId": "kristkirken-i-bergen",
  "organizationName": null,
  "pogCustomerNumber": null,
  "email": "vigdis@kristkirken.no",
  "countryId": "no",
  "currency": "NOK",
  "amount": 1750,
  "total": 1750,
  "totalTax": 19,
  "invoiceDate": "2025-12-02T00:00:00.000Z",
  "dueDate": "2025-12-12T00:00:00.000Z",
  "method": "invoice",
  "source": "awana-crm",
  "syncStatus": {
    "woo": "pending"
  },
  "invoiceLines": [
    {
      "productId": 3102,
      "quantity": 1,
      "description": "Medlemskontingent 2025 - lisens undervisningsbøker (oppgradering)",
      "vatRate": 0,
      "vatCode": "fritatt"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "wooOrderId": 1234,
  "wooOrderNumber": "1234",
  "wooStatus": "pending",
  "digitalInvoiceId": "firebaseRecordName",
  "message": "Order created/updated from digital invoice"
}
```

## Outbound Webhooks (Woo → CRM)

This plugin sends **two different webhooks** back to CRM when Integrera/POG updates order meta.

### 1) invoiceCustomerNumberWebhook

- **Purpose**: sync `pog_customer_number` to CRM.
- **Trigger**: Woo order meta `pog_customer_number` changes.
- **Config (wp-config.php)**:
  - `AWANA_POG_CUSTOMER_WEBHOOK_URL` (required)
  - `AWANA_POG_CUSTOMER_WEBHOOK_API_KEY` (required; sent as `x-api-key`)
- **Payload**:
  - `invoiceId`
  - `pog_customer_number`

Example:

```bash
curl -X POST "https://invoicecustomernumberwebhook-<...>/" \
  -H "Content-Type: application/json" \
  -H "x-api-key: <key>" \
  -d '{
    "invoiceId": "YrFJsshG5K0RFmywbp1P",
    "pog_customer_number": "10199"
  }'
```

### 2) invoiceStatusWebhook

- **Purpose**: sync invoice status + reference fields (KID / invoice number) to CRM.
- **Trigger**: Woo order meta `pog_status`, `pog_kid_number`, or `pog_invoice_number` changes.
- **Config (wp-config.php)**:
  - `AWANA_INVOICE_STATUS_WEBHOOK_URL` (required)
  - `AWANA_INVOICE_STATUS_WEBHOOK_API_KEY` (optional; sent as `x-api-key` if set)
- **Payload**:
  - `invoiceId` (required)
  - `kid` (optional; from `pog_kid_number`)
  - `pogInvoiceNumber` + `invoiceNumber` (optional; from `pog_invoice_number`)
  - `status` (optional; mapped from `pog_status`)

Status mapping:
- `pog_status=order` → `status=pending`
- `pog_status=invoice` → `status=unpaid`

Example:

```bash
curl -X POST "https://invoicestatuswebhook-<...>/" \
  -H "Content-Type: application/json" \
  -H "x-api-key: <key>" \
  -d '{
    "invoiceId": "YrFJsshG5K0RFmywbp1P",
    "kid": "12345678903",
    "pogInvoiceNumber": "999999",
    "invoiceNumber": "999999",
    "status": "unpaid"
  }'
```

## Status Mapping

Digital status → WooCommerce status:
- `draft` → `pending`
- `unpaid` → `on-hold`
- `paid` → `completed`
- `cancelled` → `cancelled`
- `refunded` → `refunded`

## Order Meta Fields

The plugin stores the following meta fields on orders:

### CRM/Digital identifiers
- `crm_invoice_id` - Invoice ID (used to find/update the same Woo order)
- `crm_member_id` - Member ID
- `crm_organization_id` - Organization ID
- `crm_source` - Source system (e.g. `awana-crm`)
- `crm_sync_woo` - Sync marker

### POG fields (written by Integrera/POG)
- `pog_customer_number` - POG customer number
- `pog_invoice_number` - POG invoice number
- `pog_kid_number` - KID
- `pog_status` - POG status (mapped and sent to `invoiceStatusWebhook`)

### Sync dedupe fields (internal)
- `_pog_customer_synced_to_crm`
- `_pog_invoice_number_synced_to_crm`
- `_pog_kid_number_synced_to_crm`
- `_pog_status_synced_to_crm`

## Action Hooks

The plugin triggers the following action hooks:

- `awana_digital_invoice_created` - Fired when an invoice is created/updated
  - Parameters: `$order` (WC_Order), `$data` (array)
  
- `awana_digital_invoice_synced` - Fired when an invoice is synced from POG
  - Parameters: `$order` (WC_Order), `$data` (array)

## Logging

All plugin activity is logged using WooCommerce's logger. Logs can be viewed in:
**WooCommerce → Status → Logs** (select "awana_digital" from the dropdown)

## Product Mapping

The plugin attempts to find products in the following order:
1. By WooCommerce product ID (if `productId` matches)
2. By SKU (if `productId` matches a product SKU)

If a product is not found, the line item is skipped and a warning is logged.

**Pricing:** Product prices are always taken from WooCommerce. The `unitPrice` field is not used - prices are automatically calculated from the WooCommerce product's current price.

## Notes

- All orders are created as **guest orders** (no WordPress user account)
- The plugin uses `invoiceId` as the unique identifier for finding existing orders
- If an order with the same `invoiceId` exists, it will be updated (line items are replaced)
- Currency defaults to NOK if not specified
- Country codes are automatically converted to uppercase (e.g., "no" → "NO")

## Security

- API key authentication is required for all endpoints
- API key must be defined in `wp-config.php` (not in the plugin file)
- Use different API keys for staging and production environments
