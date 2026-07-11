=== OC Cardcom Cards ===
Contributors: originalconcepts
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.1
License: GPL-2.0-or-later

Companion for Cardcom: a "remember my card" toggle at checkout that saves the J5 token as a reusable customer card.

== Description ==

Adds the customer-facing saved-card experience on top of Cardcom's payment gateway,
including the token Cardcom mints under J5 (Capture Charge / Operation 6) that its own
plugin never turns into a saved card. Charging, J5 capture and invoicing stay with
Cardcom's plugin.

== Changelog ==

= 0.4.1 =
* Both checkout toggles ("remember my card" and "saved cards as separate methods") now default to ON.

= 0.4.0 =
* Added self-update from the GitHub repository (Plugin Update Checker).

= 0.3.3 =
* Promoted saved cards as top-level payment methods; "New credit card" keeps the save toggle.
* Terminal-bound tokens; removed the broken "Add payment method" account button.
* Hebrew (he_IL) translations; checkout styling as a standalone bubble.

= 0.2.0 =
* Companion architecture: rescue the J5 token via Cardcom's own action and save it
  as a native WooCommerce payment token.

= 0.1.0 =
* Initial internal build.
