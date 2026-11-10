=== OC Cardcom Cards ===
Contributors: originalconcepts
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPL-2.0-or-later

Companion for Cardcom: a "remember my card" toggle at checkout that saves the J5 token as a reusable customer card.

== Description ==

Adds the customer-facing saved-card experience on top of Cardcom's payment gateway,
including the token Cardcom mints under J5 (Capture Charge / Operation 6) that its own
plugin never turns into a saved card. Charging, J5 capture and invoicing stay with
Cardcom's plugin.

== Changelog ==

= 0.6.0 =
* The plugin is now part of OC Pluginz and stays free. Updates arrive through the OC Pluginz hub with a free licence key; the bundled GitHub updater (Plugin Update Checker) was removed.
* New "OC Pluginz account" screen: connect the licence key, see its status, check now, check for updates.
* The settings moved from the WooCommerce menu to the shared "OC Pluginz" menu (the address admin.php?page=oc-cardcom-cards is unchanged).
* New installs need the free key to save new cards. Without a valid licence the "remember my card" toggle is not shown and no new card is saved; saved cards keep being offered and charged, My Account keeps working, nothing is deleted and the checkout is never blocked.
* Sites that already ran an earlier version keep working in full without a key, and see a dismissible invitation to connect one (it is what brings updates from now on).

= 0.5.2 =
* FIX: "New credit card" could fail with "token not found" (or silently charge the wrong saved card) in promoted mode. Cardcom's own saved-cards radio list is hidden there, but a hidden token radio stayed selected — so "New credit card" submitted that token instead of a new card, and a token minted on another terminal is rejected as "token not found". The checkout script now forces the hidden native selection back to "new".

= 0.5.1 =
* FIX: the save-card toggle switch could collapse to a lone circle on some themes (that force `label{display:block}`), which un-blockified the switch track. Hardened the toggle CSS (explicit inline-block track + !important on the switch dimensions) so it always renders as a proper on/off switch.

= 0.5.0 =
* FIX: never save a token with a wrong/expired expiry (was defaulting to 01/current-year), which made the acquirer decline the saved-card charge with 60000004. Read the expiry from Cardcom cc_Tokef/cardcom_Tokef and validate it is a real future date.

= 0.4.5 =
* Reverted default-to-saved-card: checkout defaults to a new card again (saved-card J5 token charge is currently rejected by the acquirer with 60000004).

= 0.4.4 =
* Pre-select the saved card by default for returning customers (instead of "New credit card").

= 0.4.3 =
* Show the "remember my card" toggle on themes that don't render the gateway payment box (JS injects it into the Cardcom method).

= 0.4.2 =
* De-duplicate saved cards by card identity and hide junk/placeholder (0000) tokens.

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
