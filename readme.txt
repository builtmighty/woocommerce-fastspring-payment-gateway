=== FastSpring for WooCommerce ===
Contributors: Enradia, Built Mighty
Tags: WooCommerce, Payment Gateway
Version: 2.5.0
Requires PHP: 7.4
Requires at least: 4.4
Tested up to: 6.8.1
Contributor: cyberwombat, Built Mighty
Stable tag: trunk
License: MIT
License URI: https://opensource.org/licenses/MIT
Contributors: cyberwombat, Built Mighty
Donate link: https://www.paypal.com/donate/?token=SiMqCFR8nI8ciqqKR8EpxBhGrBTAt6ye5kevdwvLF5MGjGTAO_oN7o-vDlWvRiBrZopSw0&country.x=US&locale.x=US

FastSpring For Woocommerce integrates your FastSpring account with your wordpress site

== Description ==

FastSpring For Woocommerce integrates your [FastSpring[(http://fastspring.com) account with your WordPress site. It provides support for both the hosted and popup version of FastSpring and provides webhook and API support for order validation as well as subscription support.

== Installation ==

View installation instructions [here](https://github.com/cyberwombat/woocommerce-fastspring-payment-gateway/wiki).

== Screenshots ==
 
1. FastSpring admin dashboard.
2. FastSpring payment popup option.

== Upgrade Notice == 

N/A

== Changelog ==
 
= 1.0.2 =
* Pass order ID as FS tag to avoid race conditions experienced with using transaction ID.

= 1.0.3 =
* Adjustement to discount calculation.

= 1.0.4 =
* Removed interim order confirm page - FS lauches right from checkout
* Option to use hosted page or popup

= 1.0.5 =
* Option for hosted storefront.
* Removed interim page - FS launches from checkout directly.

= 1.1.0 =
* Preliminary subscription support.

= 1.1.1 =
* Improved subscription support.

= 1.1.2 =
* Bug fix.

= 1.1.3 =
* Pricing bug fixed and improvements with coupons.

= 1.1.4 =
* Subscription pricing bug fixed.

= 1.1.5 =
* Upgrade FastSpring JavasScript to 0.7.6.

= 1.1.6 =
* Added FS debug functions

= 1.1.7 =
* Update quantity handling for locked behavior

= 1.1.8 =
* Test mode cleanup

= 1.1.9 =
* Better storefront path test handling

= 1.2.0 =
* Checkbox setting issue fix

= 1.2.1 =
* Refactor settings code

= 1.2.2 =
* Fix typo

= 1.2.3 =
* Support for payemnt icons, transaction URL

= 1.2.4 =
* Upgrade FastSpring JavasScript to 0.8.3

= 1.2.5 =
* Fix typo causing subscription activate issues

= 1.3.0 =
* Address functionality which passes discounts from WooCommerce to FastSpring

= 2.0.0 =
* Built Mighty has taken over plugin maintenance. Updated author/contributor details.
* Full compatibility with WooCommerce 9.3.1 and WordPress 6.6.2.
* Feature: Discounts and coupons now sync from WooCommerce to FastSpring, including proportional distribution and display in FastSpring checkout.
* Feature: New settings for temporary order deletion time—customize FastSpring popup session timeout and temp order lifetime.
* Improvement: Popup checkout now supports session timeout with automatic page refresh after expiry.
* Improvement: Better error handling and user feedback during checkout, including multi-message display.
* Improvement: Admin can now use human-readable time formats (e.g., "1h 5m") for temp order deletion in settings.
* Improvement: Improved FastSpring webhook handling—customer address and invoice URL are now attached to orders.
* Patch: Improved nonce handling for guest checkout compatibility.
* Patch: Improved logging and minor performance tweaks.
* Misc: Updated plugin meta, version numbers, and documentation.

= 2.1.0 =
* Fixed JavaScript event binding for the `#place_order` button and improved code consistency in `fastspring-checkout.js`.
* Ensured orders are properly associated with the current logged-in user before updating customer data.
* Cleaned up order creation logic for better readability and maintainability.
* Updated plugin version and compatibility metadata for WordPress and WooCommerce.

= 2.2.0 =
* Added reload_checkout_on_order_received_script to reload checkout after payment completion.
* Improved order ID tracking in WooCommerce session for consistent order management.
* Updated redirect logic for smoother post-payment experience.

= 2.3.0 =
* Improved checkout process reliability by adding an `isCheckoutProcessing` flag to prevent double submissions and duplicate orders.
* Enhanced event handling for the `#place_order` button to support both click and keyboard events, restricting form submission to valid user actions (only on Enter key for keyboard events).

= 2.4.0 =

* Enhanced temporary order handling by persisting applied coupons and discount data in order meta for improved reliability.
* Added static `is_temp_order` method for consistent and reusable temporary order identification.
* Refactored codebase to use `is_temp_order` for all temporary order checks.
* During temporary order creation, applied coupons are now backed up, removed from the cart, and restored after order creation to maintain cart consistency.
* Applied coupons and discount totals are now stored in the `_fs_temp_order_data` meta key, with new `wc_fs_temp_order_data` filter and `wc_fs_update_temp_order_meta` action for extensibility.
* Discounts are now applied to finalized orders using data from temporary order meta, not the current cart, ensuring accuracy.
* Temporary order meta (_fs_temp_order_data) is now deleted when converting to an actual order, preventing stale or orphaned data.