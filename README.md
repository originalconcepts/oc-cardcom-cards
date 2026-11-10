# OC Cardcom Cards

Plug-and-play **companion** for the Cardcom payment gateway on WooCommerce. It adds a
"remember my card" toggle at checkout and rescues the token Cardcom mints under
**J5 (Capture Charge / Operation 6)** so it becomes a real saved card for the
customer — the step Cardcom's own plugin skips under Operation 6.

It **rides on Cardcom's engine and never replaces it**: charging, J5 capture and
invoicing all stay with Cardcom's plugin. This plugin only adds the missing
customer-facing saved-card experience.

## Features
- "Remember my card" toggle at checkout (title / label / description configurable).
- Rescues the J5 token → saves it as a native `WC_Payment_Token` for the customer.
- Optional: show saved cards as **separate top-level payment methods** ("New credit
  card" below), instead of nested under Cardcom.
- Saved cards appear natively under **My Account → Payment methods**.
- Terminal-bound tokens (a card saved on one terminal is never offered on another).
- Bilingual: Hebrew (he_IL) + English, per WordPress admin/site language.
- Free. Updates arrive through OC Pluginz with a free licence key (the hub at my.ocpluginz.com).

## Requirements
- WooCommerce 6.0+
- Cardcom's `woo-cardcom-payment-gateway` plugin, Operation **6 (Capture Charge)**.
- The Cardcom terminal must allow "card-not-present" / token charges.

## Settings
**OC Pluginz → Cardcom Cards** in the WordPress admin (the shared menu of the OC
plugins). The licence is on the **OC Pluginz account** tab next to the settings.

## Licence (since 0.6.0)

Since 0.6.1 a site that ran a version below 0.6.0 needs no key at all: it works in full and keeps updating from this repository's tags. Only new installs need the free key, and they update through the hub.
The plugin is free. A free OC Pluginz licence key connects the site to the hub
(`my.ocpluginz.com`, product `oc-cardcom-cards`, plan `free`): it brings the
updates and switches on saving new cards.

- **No key / refused / suspended / expired past its grace days** → restricted
  mode: the "remember my card" toggle is not rendered and no new token is
  saved. Nothing else changes: saved cards keep being offered and charged, My
  Account keeps listing and deleting them, the admin keeps working, nothing is
  deleted, and the checkout is never blocked.
- **Sites that ran a version below 0.6.0** keep working in full without a key
  (option `occc_legacy_install`); they only need the key for updates.
- `wp-config.php`: `OCCC_LICENSE_KEY` (a fixed key), `OCCC_HUB_URL` (another
  hub, e.g. staging), `OC_PLUGINZ_AGENCY` (our own sites: always full, no hub
  calls, updates by hand).

## Updates
Since 0.6.0 updates come only from the OC Pluginz hub, with the licence key as
the ticket, and show up on the Plugins screen like any other update. The bundled
Plugin Update Checker and the GitHub update source were removed.

## Release zip
`bash bin/build-zip.sh` → `dist/oc-cardcom-cards.zip` (top folder
`oc-cardcom-cards/`), the package the hub serves. CI builds it on every push.

## Author
Original Concepts — https://originalconcepts.co.il/ · https://ocpluginz.com/oc-cardcom-cards
