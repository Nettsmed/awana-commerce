# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.7] - 2026-05-04

### Added
- **Klær-frakt-logikk flyttet fra Code Snippet til plugin-kode** (`Awana_Shipping_Klaer`). Implementerer Eiriks bekreftede modell (e-post 30.04.2026): klær-only-kurv = 199 kr inkl. mva fast frakt (Melings sender direkte fra sitt lager), bok-only-kurv = Posten Bring + Local Pickup som vanlig, mixed kurv = Bring/Pickup-rate + 199 kr fee for klær. Detekterer både `klaer` og `melings` shipping-class-slugs (legacy — beholdt til DB-konsolidering). Klar tax-håndtering: 199 splittes som 159.20 net + 39.80 mva (25%). Bedre kunde-label "Frakt klær (Melings)" istedenfor "Tillegg for spesialfrakt". Forbedringer over Code Snippet 12: ny `WC_Shipping_Rate`-objekt (kaprer ikke eksisterende rate som snippet gjorde — defensiv mot Bring API-feil), eksplisitt tax-class håndtering med fallback (`get_rates('')` fordi DB-raten har class=''), edge case for tom rates-input. Alle scenarier verifisert mot prod-DB-kopi (awana-post Local site): 1 klær = 388 kr, 3 klær = 766 kr, bok+Bring = 158 kr, bok+Local Pickup = 99 kr, mixed+Bring = 546 kr, mixed+LP = 487 kr, 2 klær + 5 bok + Bring = 1141 kr.

### Removed
- Code Snippet #12 "Sikre fast frakt-sats for klær" bør deaktiveres etter deploy. Lå i `wp_snippets` siden 2025-11-03 og hadde samme intensjon, men: (1) levde i DB istedenfor git, (2) "Tillegg for spesialfrakt"-label var uforståelig, (3) hijacket billigste eksisterende rate på klær-only og brakk hvis Bring API ikke returnerte rater, (4) tax-håndtering Sindre selv var usikker på i Nov 2025.

### Notes
- **Split-package vs fee-approach**: Den arkitekturisk-rene løsningen (split kurv i klær-package + bok-package) ble vurdert og forkastet for nå. WooCommerce Blocks `ShippingController::filter_shipping_packages()` fjerner Local Pickup fra ALLE pakker når ikke alle støtter pickup — split-implementasjonen ville miste Local Pickup på mixed carts (UX-regresjon). Dessuten: alle 23 klær-produkter har unset/zero vekt på awana.no per 30.04.2026 (verifisert), så Brings rate-beregning på mixed cart er ikke påvirket av klær uansett. Hvis/når Eirik setter klær-vekter, vurder å bytte til split-package.

## [1.4.6] - 2026-05-04

### Fixed
- **Daglig sammendrag-mail teller informasjons-regler som rød**: 1.4.5-fixen ekskluderte Rule 5 fra 30-min-alarmer, men daily summary brukte fortsatt rå `$counts['red']` og sendte mail fordi Rule 5 fortsatt er rød internt. Sentralisert `ALERT_EXCLUDED_RULES`-konstant brukes nå begge steder, og daily summary teller kun *actionable* røde regler. Verifisert mot prod: med Rule 5 = rød, Rule 4 = gul, og resten grønne sender hverken cron eller daily summary mail.

## [1.4.5] - 2026-05-04

### Changed
- **Helsesjekk støyreduksjon**: Daglig sammendrag-mail sendes nå kun når minst én regel er rød (tidligere: hver dag uavhengig av status). Status er fortsatt synlig i admin Helse-fanen. Rule 5 (Sist Firebase-sync) ekskludert fra 30-min-trigger-mail og flyttet til informasjons-only — den slo rødt etter 24 t uten trafikk og ga falske alarmer på lave-trafikk-dager. Reelle Firebase-feil fanges fortsatt av Rule 3 (B2B Nets uten Firebase) innen 30 min.

## [1.4.4] - 2026-04-30

### Fixed
- **Local Pickup ble pre-valgt som default shipping i checkout**: Etter Local Pickup Straume ble lagt til 29.04 satte WC `local_pickup:5` som default for alle kunder, fordi WCs `wc_get_default_shipping_method_for_package` har en "preserve chosen pickup"-logikk som beholder pickup på tvers av recalculation-passes selv når Posten Bring-rater dukker opp i en senere pass. Lagt til `Awana_Shipping`-klasse med filter på `woocommerce_shipping_chosen_method` som bytter default fra `local_pickup` til billigste Posten-rate når begge er tilgjengelige. Bekreftet i prod-DB-simulering: før fix ble `local_pickup:5` valgt for postnr 5353; etter fix blir `posten-bring-checkout-mailbox` valgt. Brukere kan fortsatt manuelt klikke Local Pickup — fixen styrer kun forhåndsvalg.

## [1.4.3] - 2026-04-30

### Changed
- **Helse-regler ignorerer pre-go-live ordrer**: Reglene 1-4 filtrerer nå på `date_created_gmt >= AWANA_HEALTH_GOLIVE_DATE` (default `2026-04-10 00:00:00` — dagen Nets gikk live på awana.no). Pre-launch test-ordrer og streifkunder fra perioden mens butikk.awana.no var hovedkanalen er ikke lenger støy i dashbordet eller daglig sammendrag. Override via `AWANA_HEALTH_GOLIVE_DATE`-konstant i wp-config.php hvis nødvendig. Cutoff vises i Helse → Konfigurasjon-blokken.

## [1.4.2] - 2026-04-29

### Fixed
- **Recursion-OOM i woocommerce_after_order_object_save-hook**: Hooken kalte POG-webhooks som internt kjørte `update_sync_status()` → `$order->save()` → re-fyrte samme hook. Pre-1.4.1 var dette latent fordi B2B Faktura aldri fikk `crm_invoice_id` (guarden returnerte tidlig). 1.4.1 åpnet pathen for Faktura, og første Retry på #94247 tomte 512MB PHP-memory og feilet med "Request failed". Fix: per-request static-flag forhindrer re-entry; `_pog_*_synced_to_crm`-meta settes nå FØR webhooks fyrer som defense-in-depth.

## [1.4.1] - 2026-04-29

### Fixed
- **B2B Faktura-ordrer fikk aldri `crm_invoice_id`**: `notify_checkout_invoice_to_crm()` var hooket kun på `woocommerce_payment_complete`, som ikke fyrer for `bacs` (Faktura går rett til `on-hold`). Faktura-ordrer havnet permanent som "Mangler CRM-ID" i admin-dashbordet med mindre noen klikket Retry manuelt. Fix: ny hook på `woocommerce_checkout_order_created` for B2B Faktura. `should_sync_checkout_invoice()` + `_awana_checkout_invoice_synced`-dedup hindrer dobbel-sync mot eksisterende Nets-flyt.
- **POG-kolonnen viste bare "order"-statusen uten nummer**: `render_pog_cell()` leste kun `pog_invoice_number`, men Integrera skriver typisk `pog_order_number` (f.eks. 1325 for Misjonskirken Askim). Ordrer som er i "order"-stadiet (ikke konvertert til faktura ennå) viste bare statusstrengen. Fallback til `pog_order_number` når `pog_invoice_number` er tom.

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



