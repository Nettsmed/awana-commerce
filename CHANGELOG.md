# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0] - 2026-04-28

### Added
- **B2C → CRM-sync**: privatkunde-ordrer (alt som ikke er organization-checkout) synkroniseres nå til Eiriks Firebase CRM via samme `createCheckoutInvoice`-pipeline som B2B. Cloud Function har fått `type: "b2c"`-modus som resolver eller oppretter individual-member basert på e-post.
- `Awana_CRM_Webhook::build_b2c_invoice_payload()` — bygger payload uten member-/org-IDer, sender med `type: "b2c"` + contactName.
- Ny WC-meta-key `crm_source` får verdi `woo-b2c-checkout` for B2C-ordrer (fortsatt `woo-checkout` for B2B).

### Changed
- `should_sync_checkout_invoice()` tillater nå B2C-ordrer (krever billing email, ekskluderer Faktura/`bacs` siden den fortsatt er B2B-only per v1.2.2).
- `build_checkout_invoice_payload()` brancher på payment_type til separate B2B/B2C-builders. Felles `build_invoice_lines()` deler line-item-logikken.
- `handle_checkout_invoice_response()` håndterer nå tilfellet hvor `memberId` kommer fra response-body (B2C, server-resolved) i stedet for payload (B2B, klient-spesifisert).

### Antagelser (venter Eirik-validering 2026-04-30)
- Match B2C-kjøpere mot eksisterende members via `email`-feltet i Firebase. Hvis Awana bruker et annet felt (f.eks. `billingEmail` som primær key), må Cloud Function-koden justeres.
- Nye B2C-members får `type: "individual"`. Justeres hvis Awana ønsker en annen markør.
- Backfill av historiske B2C-ordrer er IKKE i denne versjonen — kun nye ordrer fra deploy-dato.
- POG-håndtering antas samme som B2B Nets: `syncStatus.pog: "not_applicable"` (Nets bokføres månedlig via Integrera).

## [1.4.0] - 2026-04-28

### Changed
- **Konsolidert admin-UI**: tidligere to admin-sider (`?page=awana-sync` legacy + `?page=awana-b2b-sync` nyere) er slått sammen til én side på `?page=awana-sync`.
- Tre tabber: **Ordrer** (alle WC-ordrer med sync-status, ikke bare CRM-orienterte), **Mislykkede syncs**, **Helse**.
- Ordrer-tab erstatter dagens "Recent sync activity" / "Completed not synced" / "High error count" — alt smelter sammen til én paginert tabell med søk og filter-chips.
- Type-kolonne klassifiserer hver ordre som B2B / B2C / Faktura-import. B2C vises som "N/A" i sync-status-kolonnen (B2C-sync er ikke implementert ennå — på roadmap).
- Datalag bruker nå direkte SQL mot `wp_wc_orders` (HPOS) for paginering — løser O(N)-per-sidevisning på den gamle "load all order objects then paginate"-tilnærmingen.

### Removed
- `?page=awana-b2b-sync` (legacy URL fra v1.2.0). Gamle bookmarks redirectes automatisk til `?page=awana-sync` med `tab=ordrer&filter=b2b` (eller `tab=helse` hvis det var Helse-tabben).
- `Awana_B2B_Sync_Status`-klassen (logikken konsolidert inn i `Awana_Admin`).

## [1.3.0] - 2026-04-28

### Added
- **Awana Sync Helse**: nytt overvåkingssystem som forhindrer 13-dagers stille sync-incidents (som Integrera-incidenten 15.04–28.04). Ny tab i `Awana Sync` admin-page.
- 5 helsesjekk-regler kjører hver 30. min mot WC-DB og varsler ved problemer:
  - Pending Nets >2t (gul) — fanger død WC-cron
  - On-hold Faktura >48t uten POG-kobling (rød) — catch-all for Integrera-pipen
  - B2B Nets uten Firebase crm_invoice_id >1t (gul) — Firebase createCheckoutInvoice-feil
  - Migrasjons-orphans (gul) — Faktura uten _awana_payment_type
  - Sist vellykket POG-mapping >24t (rød)
- Per-rule dedup-mekanisme: ingen ny mail om samme regel innen 24t (transient-basert).
- Daglig sammendrag kl 08:00 Europe/Oslo, sender uavhengig av status.
- Per-ordre tracking via nye meta-keys: `_awana_health_last_attempt_ts`, `_awana_health_last_attempt_error`, `_awana_health_dismissed`.
- Ny wp_option `awana_health_last_firebase_sync` oppdateres ved hver vellykkede checkout-invoice-sync (legacy navn `awana_health_last_pog_sync` migreres automatisk på første lesing).
- Color-blind safe severity-indikatorer (`!`, `?`, `✓`).
- Sentry breadcrumb på alarm-trigger (krever Sentry SDK aktiv).

### Configuration (wp-config.php)
- `AWANA_HEALTH_ALERT_RECIPIENTS` (CSV) — påkrevd for at mail skal sendes
- `AWANA_HEALTH_DEDUP_HOURS` (int, default 24) — overstyr dedup-vinduet

### Changed
- `Awana_B2B_Sync_Status::render_page()` har nå tab-navigasjon (B2B-ordrer + Helse).
- `Awana_CRM_Webhook::handle_checkout_invoice_response()` populerer `_awana_health_last_attempt_*` meta + `awana_health_last_firebase_sync` option.

## [1.2.2] - 2026-04-20

### Fixed
- Checkout: "Faktura" (bacs) er nå skjult for privatkunder — kun bedriftsvalg kan betale med faktura. Server-side filter på `woocommerce_available_payment_gateways` + JS trigger `update_checkout` ved bytte av kundetype.

## [1.2.1] - 2026-04-16

### Fixed
- Checkout wizard now normalizes prefilled organization phone numbers to include `+47` country code, preventing Nets Easy validation bounce-back at the payment step (TSK-18093)

## [1.2.0] - 2026-03-11

### Added
- B2B checkout: 3-step wizard (customer type, billing details, payment) with org selector
- Auto-fill billing fields from Firebase organization data when org is selected
- Organization number checkout field (moved from code snippet into plugin)
- TTL-based organization sync on cart and checkout pages
- Admin debug page for B2B org sync validation
- Sentry error monitoring integration for centralized error tracking
- Composer dependency management with `sentry/sentry` SDK

### Changed
- **Renamed plugin** from "Awana Digital Sync" to "Awana Commerce" to reflect expanded scope
- Main file renamed from `awana-digital-sync.php` to `awana-commerce.php`
- Text domain changed from `awana-digital-sync` to `awana-commerce`
- Constants renamed from `AWANA_DIGITAL_SYNC_*` to `AWANA_COMMERCE_*` (backward-compat defines added)

### Fixed
- Billing company now set via `set_billing_company()` so native WooCommerce field is populated
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



