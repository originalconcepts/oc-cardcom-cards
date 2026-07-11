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
- Self-updates from this repository (Plugin Update Checker).

## Requirements
- WooCommerce 6.0+
- Cardcom's `woo-cardcom-payment-gateway` plugin, Operation **6 (Capture Charge)**.
- The Cardcom terminal must allow "card-not-present" / token charges.

## Settings
WooCommerce → **OC Cardcom Cards**.

## Updates
The plugin checks this repo for new tagged releases and shows the update in
Dashboard → Plugins. Configure the source in the main file:

```php
define( 'OCCC_UPDATE_REPO', 'https://github.com/<owner>/oc-cardcom-cards/' );
// define( 'OCCC_UPDATE_TOKEN', '<github token>' ); // private repos only
```

## Author
Original Concepts — https://originalconcepts.co.il/
