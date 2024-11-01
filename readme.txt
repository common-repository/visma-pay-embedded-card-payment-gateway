=== Visma Pay (Embedded Card) for Woocommerce ===
Contributors: hsuvant
Donate link: 
Tags: payment gateway, visma, pay, verkkomaksut, korttimaksut, vismapay
Requires at least: 3.3
Tested up to: 6.6.1
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 3.0.0
WC tested up to: 9.1.4

Visma Pay plugin for Woocommerce.

== Description ==

This plugin uses the Visma Pay Payment API. 

This is a plugin for integrating Visma Pay payment gateway with your Woocommerce store. To accept card payments with this plugin, you need to have an active contract with [Visma Pay](https://www.visma.fi/vismapay/). You can order Visma Pay [here](https://www.visma.fi/vismapay/tilaa-visma-pay/) (See [terms](https://static.vismapay.com/terms/yleiset-ehdot.pdf)).

Compared to the normal Visma Pay payment gateway this plugin embeds a card payment form on your checkout and supports recurring payment using Woocommerce Subscriptions.

= Supported payment methods =
Card payments

== Installation ==

1. Activate the plugin through the ‘Plugins’ menu in WordPress.
2. From the Woocommerce settings menu open ‘Payment gateways’ tab, and select ‘Visma Pay (Embedded Card)’.
3. Fill in private key and API key. These can be found from the Visma Pay merchant portal.
4. Save settings and make a test order and payment to confirm everything works.


== Frequently asked questions ==
-


== Screenshots ==
-


== Changelog ==

= 1.1.5 =
* Fixed an issue with incorrect shipping tax rate being sent to Visma Pay API.

= 1.1.4 =
* Changed Visma Pay API version to w3.2 which supports decimals in tax percent
* Updated 'tested up to' versions.

= 1.1.3 =
* Increased specificity of plugin styles
* Updated 'tested up to' versions.

= 1.1.2 =
* Fixed issue with subscription payments being charged twice

= 1.1.1 =
* Added files that were missing from 1.1.0

= 1.1.0 =
* Support for Woocommerce Blocks
* Updated 'tested up to' versions.

= 1.0.5 =
* Support for HPOS
* Updated 'tested up to' versions.

= 1.0.4 =
* Updated 'tested up to' versions.

= 1.0.3 =
* Updated 'tested up to' versions.
* Fixed bug with card brand logos

= 1.0.2 =
* Updated 'tested up to' versions.
* Fixed payment confirmation email

= 1.0.1 =
* Updated 'tested up to' versions.


== Upgrade notice ==
-