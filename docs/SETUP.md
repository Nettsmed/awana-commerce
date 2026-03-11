# Setup Guide

## 1. Install Plugin

Copy the `awana-commerce` folder to your WordPress installation:
```
wp-content/plugins/awana-commerce/
```

## 2. Activate Plugin

1. Go to **WordPress Admin → Plugins**
2. Find "Awana Commerce"
3. Click "Activate"

## 3. Configure API Key

Add the following line to your `wp-config.php` file (before the "That's all, stop editing!" line):

```php
// Awana Commerce API Key
define( 'AWANA_DIGITAL_API_KEY', 'your-super-secret-api-key-here' );
```

### Generate a Secure API Key

You can generate a secure random key using one of these methods:

**Using PHP:**
```php
<?php
echo bin2hex(random_bytes(32));
?>
```

**Using command line:**
```bash
openssl rand -hex 32
```

**Using online tool:**
- Visit: https://randomkeygen.com/
- Use a "CodeIgniter Encryption Keys" or similar

### Environment-Specific Keys

For different environments, use different keys:

```php
// wp-config.php
if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
    switch ( WP_ENVIRONMENT_TYPE ) {
        case 'production':
            define( 'AWANA_DIGITAL_API_KEY', 'production-key-here' );
            break;
        case 'staging':
            define( 'AWANA_DIGITAL_API_KEY', 'staging-key-here' );
            break;
        default:
            define( 'AWANA_DIGITAL_API_KEY', 'development-key-here' );
    }
} else {
    // Fallback
    define( 'AWANA_DIGITAL_API_KEY', 'default-key-here' );
}
```

## 4. Configure Firebase Org Sync (Login)

Add the Firebase org sync configuration to `wp-config.php` (before the "That's all, stop editing!" line):

```php
// Firebase org sync (getUserOrganizations)
define( 'AWANA_FIREBASE_GET_ORGS_URL', 'https://europe-west3-awana-server.cloudfunctions.net/getUserOrganizations' );
define( 'AWANA_FIREBASE_API_KEY', 'your-firebase-api-key-here' );
```

## 5. Configure Outbound Webhooks (Woo → CRM)

Integrera/POG writes POG fields back to the Woo order as meta. This plugin detects changes and sends outbound webhooks back to CRM.

Add the following to your `wp-config.php` file (before the "That's all, stop editing!" line):

```php
// 1) invoiceCustomerNumberWebhook (required)
define( 'AWANA_POG_CUSTOMER_WEBHOOK_URL', 'https://invoicecustomernumberwebhook-<...>/' );
define( 'AWANA_POG_CUSTOMER_WEBHOOK_API_KEY', '<x-api-key>' );

// 2) invoiceStatusWebhook (required URL; API key optional)
define( 'AWANA_INVOICE_STATUS_WEBHOOK_URL', 'https://invoicestatuswebhook-<...>/' );

// Reuse the same key as customer number webhook, or set a different one
define( 'AWANA_INVOICE_STATUS_WEBHOOK_API_KEY', '<x-api-key>' );
```

Notes:
- `AWANA_POG_CUSTOMER_WEBHOOK_API_KEY` is required (customer number webhook always uses `x-api-key`).
- `AWANA_INVOICE_STATUS_WEBHOOK_API_KEY` is optional; if you don’t define it, the plugin will send without `x-api-key`.

## 6. Test the Endpoint

You can test the endpoint using curl:

```bash
curl -X POST https://your-site.com/wp-json/awana/v1/invoice \
  -H "Content-Type: application/json" \
  -H "X-CRM-API-Key: your-api-key" \
  -d '{
    "invoiceId": "test-123",
    "invoiceNumber": "TEST-001",
    "status": "unpaid",
    "email": "test@example.com",
    "invoiceLines": [
      {
        "productId": 123,
        "quantity": 1,
        "unitPrice": 100,
        "description": "Test product"
      }
    ]
  }'
```

## 7. Configure Firebase/Integrera

Update your Firebase functions and Integrera integration to use:

- **Endpoint URL:** `https://your-site.com/wp-json/awana/v1/invoice`
- **API Key Header:** `X-CRM-API-Key: your-api-key`

## 8. Verify Logging

Check that logs are being created:

1. Go to **WooCommerce → Status → Logs**
2. Select "awana_digital" from the dropdown
3. You should see log entries for API requests

## Troubleshooting

### Plugin not activating
- Ensure WooCommerce is installed and activated
- Check PHP version (requires 7.4+)
- Check WordPress version (requires 5.8+)

### API key errors
- Verify the key is defined in `wp-config.php`
- Check for typos in the constant name: `AWANA_DIGITAL_API_KEY`
- Ensure the key matches exactly (case-sensitive)

### Orders not creating
- Check WooCommerce logs for errors
- Verify products exist with the provided `productId`
- Check that the payment method "invoice" exists in WooCommerce

### 404 errors on endpoints
- Ensure permalinks are not set to "Plain" (use any other option)
- Try flushing permalinks: **Settings → Permalinks → Save Changes**
