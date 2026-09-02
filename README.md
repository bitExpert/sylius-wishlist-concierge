# Wishlist Concierge — A WebMCP implementation for Sylius

![Wishlist Concierge](docs/assets/wishlist_concierge.png)

<p align="center">
  <a href="https://sylius.com"><img alt="Sylius 2" src="https://img.shields.io/badge/Sylius-2.0-1ab150"></a>
  <a href="https://webmachinelearning.github.io/webmcp/"><img alt="WebMCP" src="https://img.shields.io/badge/WebMCP-Chrome%20149%2B%20%7C%20ChatGPT%20in--app-orange"></a>
  <a href="LICENSE"><img alt="MIT" src="https://img.shields.io/badge/license-MIT-green"></a>
</p>

**Agent + human co-curate themed, budget-aware wishlist management for Sylius.** Instead of clicking 30 filters, you tell your agent *“birthday for my nephew”* — it searches taxons, builds a wishlist, optimizes for budget with Sylius `ChannelPricing`, and moves the best fit to cart after your confirm.

 - Demo: `https://wishlist-concierge.demo.bitexpert.de/en_US/`
 - Video: `https://youtu.be/TEZSOK3mSmQ`

## What People + Agents Can Do Together

**Before:** 30 filter clicks, manual budget math `$75.89 + $17.03+...`, missed `CatalogPromotion`, abandoned cart. **After:** one conversation.

**Story 1 — Themed gift**
> User: “birthday for my nephew”
> Agent: `product.search {theme:"gift"}` → returns `Ethereal_Drift_T_Shirt` etc. Human: “more books, less plastic” → agent swaps via `wishlist.add_item`.

**Story 2 — Budget**
> Agent: `wishlist.optimize_for_budget {wishlistId:2, budgetCents:15000}` → `BudgetOptimizer.php:40` cheapest-first knapsack with `quantity * ChannelPricing` → `chosen:["Lunar_Echo_T_Shirt-variant-0"], total $17.03, $7 remaining` + explanation string. Human decides to increase budget.

**Story 3 — Share & Checkout**
> `wishlist.move_to_cart {wishlistId:2}` → `CartTransferController.php:50` (the `confirmMessage` in the `#[ModelContextTool]` annotation) triggers `window.confirm("Move items from this wishlist to cart?")` at `registry.js:71` (spec Mitigation 6.3.2 — agent cannot finalize without human; the exact items/amount shown come from the preceding budget optimization). `OrderFactory` + `OrderProcessor` → `cartToken` + `/en_US/cart`. Anon allowed (`FASHION_WEB` gift registries are shareable via `WishlistAccessChecker.php:34` — owned lists still `403`).

## Documentation

 - [Architecture overview](./docs/architecture.md)
 - [WebMCP Tool reference](./docs/webmcp_tool_reference.md) (see `assets/shop/webmcp/registry.js:180`, registering tools from the server-served manifest)

## Installation

### Sylius Application

1. Install the plugin via Composer
```bash
composer require bitexpert/sylius-wishlist-concierge-plugin:dev-master
```

2. Enable the plugin
```php
<?php
# config/bundles.php
return [
    // ...

    Sylius\WishlistPlugin\SyliusWishlistPlugin::class => ['all' => true],
    BitExpert\SyliusWishlistConciergePlugin\BitExpertSyliusWishlistConciergePlugin::class => ['all' => true],
];
```

3. Import config
```yaml
# config/packages/bitexpert_wishlist_concierge.yaml
imports:
   - { resource: "@BitExpertSyliusWishlistConciergePlugin/config/config.yaml" }
```

4. Import routing
```yaml
# config/routes/bitexpert_wishlist_concierge.yaml
bitexpert_sylius_wishlist_concierge_plugin_shop:
   resource: "@BitExpertSyliusWishlistConciergePlugin/config/routes/shop.yaml"
   prefix: /{_locale}
   requirements:
      _locale: ^[A-Za-z]{2,4}(_([A-Za-z]{4}|[0-9]{3}))?(_([A-Za-z]{2}|[0-9]{3}))?$
```

5. Update your database schema
```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

6. Import the plugin's entrypoint in the host app's `assets/shop/entrypoint.js`:
```js
import '@vendor/bitexpert/sylius-wishlist-concierge-plugin/assets/shop/entrypoint';
```
7. Make the npm package resolvable from the host app (required for the stimulus-bridge to read the plugin's `package.json`):
```bash
yarn add @bitexpert/wishlist-concierge-plugin@file:vendor/bitexpert/sylius-wishlist-concierge-plugin/assets/shop
```

8. Register the Stimulus controllers in the host app's `assets/shop/controllers.json`:
```json
{
  "controllers": {
    "@bitexpert/wishlist-concierge-plugin": {
      "webmcp--toolbox": { "enabled": true, "fetch": "lazy" },
      "webmcp--toast":   { "enabled": true, "fetch": "lazy" }
    }
  }
}
```
The bridge looks up `main` and the short controller identifier `webmcp--toolbox` (via the `name` field) from the plugin's `package.json` — no host-side `main`/`name` needed.

9. Rebuild assets:
```bash
yarn run build:prod
bin/console assets:install
bin/console cache:clear
```

### Development

For local development, you need to install [DDEV](https://www.ddev.com) first.

Once you have DDEV installed, run the following commands to bootstrap the local development environment:
```bash
ddev start
ddev bootstrap
```

To stop the DDEV instance run `ddev stop`.

## License

The Wishlist Concierge Plugin is released under the MIT License.
