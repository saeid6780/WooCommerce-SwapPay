# SwapWallet WooCommerce Gateway

Crypto payment gateway plugin for WooCommerce that adds the **SwapWallet** payment method to both Classic and Blocks checkout.

## Features

- SwapWallet payment method with localized titles/descriptions (FA/EN).
- Supports WooCommerce Cart & Checkout Blocks.
- Optional icon display (toggle in gateway settings).
- Webhook endpoint at `/wp-json/swap-pay/v1/webhook`.

## Requirements

- WordPress with WooCommerce installed and active.
- PHP extensions required by WooCommerce HTTP functions (curl/json).

## Installation

1) Upload the plugin folder `swapwallet-swappay` to `wp-content/plugins/`.
2) Activate **SwapWallet Payment Gateway** in **Plugins**.
3) Go to **WooCommerce → Settings → Payments → SwapWallet** and enable the gateway.

## Configuration

- **Username** and **API Key**: Provided by SwapWallet.
- **Network**: Active network for USD payments (TON, BSC, TRON).
- **Invoice TTL**: Minutes before an invoice expires (clamped 5–360).
- **Language**: Persian or English labels/descriptions.
- **Show icon on checkout**: Toggle to show/hide the SwapWallet icon in Classic and Blocks checkout.
- Optional success/failed messages and debug logging (writes to WooCommerce logs).

## Blocks Support

- Compatibility declared via `declare_swap_pay_cart_checkout_blocks_compatibility`.
- Payment method registered through `Swap_Pay_Gateway_Blocks` (`class-block.php`) and rendered via `assets/js/swap-pay-checkout.js`.

## Development Notes

- Front-end assets: `assets/js/swap-pay-checkout.js`.
- Gateway logic: `class-wc-gateway-swap-pay.php`.
- Block registration: `class-block.php`.
- Main entry: `index.php`.

After updating JS, clear caches (e.g., WP Rocket/Cloudflare) and hard-refresh checkout to load the latest script.

## License

Licensed under the GNU General Public License v2 or later.
You should have received a copy of the GPL-2.0 license; if not, see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
