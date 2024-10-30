# BTCN Payment Gateway Service Integration for WooCommerce

- Contributors: bitcoinote, skyverge, beka.rice
- Tags: btcn, bitcoinote, woocommerce, payment gateway, gateway, manual payment
- Requires at least: 4.3
- Tested up to: 5.0.3
- Requires WooCommerce at least: 3.0
- Tested WooCommerce up to: 3.5.4
- Stable Tag: 1.0.0
- License: GPLv3
- License URI: http://www.gnu.org/licenses/gpl-3.0.html

This plugin enabled BitcoiNote payments in WooCommerce, using the BTCN Gateway Service.

## Description

> **Requires: WooCommerce 3.0+**
>
> **Also requires: [BTCN Payment Gateway Service](https://github.com/Bitcoinote/BTCN-Gateway-Service)**

This plugin adds [BitcoiNote](https://www.bitcoinote.org) as payment method for WooCommerce. It integrates to the [BTCN Payment Gateway Service](https://github.com/Bitcoinote/BTCN-Gateway-Service).

When an order is submitted, the order will be placed "on-hold". Once payment is confirmed, it will be moved to "completed". From the "order received" page (which is additionally linked in the "order received" email), the user will be able to complete payment in case it was aborted somehow (cancelled, expired, tab closed, etc.).

## Installation

1. Be sure you're running WooCommerce 3.0+ in your shop.
2. Be sure you've set up the [BTCN Payment Gateway Service](https://github.com/Bitcoinote/BTCN-Gateway-Service).
3. You can: (1) upload the entire `BTCN-WooCommerce-Plugin` folder to the `/wp-content/plugins/` directory, (2) upload the .zip file with the plugin under **Plugins &gt; Add New &gt; Upload**
4. Activate the plugin through the **Plugins** menu in WordPress
5. Go to **WooCommerce &gt; Settings &gt; Payments** and select "BitcoiNote" to configure
6. Set the gateway URL, username, password and IPN secret.

## Frequently Asked Questions

**What does the gateway URL configuration mean?**
You need to also have the [BTCN Gateway Service](https://github.com/Bitcoinote/BTCN-Gateway-Service) installed. See instructions there.

**What is the text domain for translations?**
The text domain is `wc-gateway-btcn`.
