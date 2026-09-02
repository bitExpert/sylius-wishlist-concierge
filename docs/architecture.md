# Architecture

```mermaid
graph TD
   subgraph Frontend
      Shop["Shop Twig Hook sylius_shop.base.footer.content<br/>templates/shop/webmcp/status.html.twig"]
      Shop -->|encore_entry| Entry["assets/shop/entrypoint.js<br/>plugin-shop-entry.js"]
      Entry --> Registry["assets/shop/webmcp/registry.js<br/>registerAll() → 12× document.modelContext.registerTool"]
      Entry --> Toolbox["toolbox_controller.js<br/>Stimulus: discoverability modal, run forms, spinner, toasts"]
      Entry --> Toast["toast_controller.js<br/>listens webmcp:toast → Bootstrap toast"]
   end

   subgraph Kernel["kernel.request validation"]
      TCV["ToolContractValidator.php<br/>kernel.request listener, priority 16<br/>deserializes JSON → DTO → validates constraints"]
   end

   subgraph Backend
      Registry -->|fetch /en_US/_webmcp/wishlist_concierge/*| TCV
      TCV --> Ctrl{"Controller Shop"}
      Ctrl --> PS["ProductSearchController.php<br/>GET /products/search"]
      Ctrl --> WL["WishlistController.php<br/>POST /wishlist<br/>GET /wishlist/{id}<br/>DELETE /wishlist/{id}<br/>POST /wishlist/{id}/items<br/>POST /wishlist/{id}/items/bulk<br/>POST /wishlist/{id}/items/clear<br/>POST /wishlist/{id}/items/remove<br/>POST /wishlist/{id}/optimize"]
      Ctrl --> CT["CartTransferController.php<br/>POST /wishlist/{id}/move-to-cart"]
      Ctrl --> TC["ToolContractsController.php<br/>GET /_webmcp/wishlist_concierge/tools.json"]
      PS --> TF["ThemedProductFinder.php<br/>QB innerJoin p.channels ch<br/>innerJoin t.code IN (:taxonCodes)"]
      WL --> WM["WishlistManager.php<br/>sylius_wishlist_plugin.factory.wishlist"]
      WL --> BO["BudgetOptimizer.php<br/>quantity * ChannelPricing knapsack"]
      BO --> EP["EligiblePromotionsProvider.php<br/>active CatalogPromotions per channel"]
      CT --> OF["Factory sylius.factory.order<br/>+ order_processing.order_processor"]
      TC --> MC["ModelContextToolCollector.php<br/>#[ModelContextTool] attributes + router lookup via routeName"]
   end

   subgraph Supporting["Supporting services"]
      EF["ErrorResponseFactory.php<br/>consistent {error,message,violations?}"]
      VF["ValidationErrorFormatter.php<br/>ConstraintViolation → [{field,message}]"]
      TCV --> EF
      TCV --> VF
   end

   TF --> Prod[("Sylius Product<br/>ChannelPricing / Taxon")]
   WM --> WLDB[("WishlistPlugin<br/>Wishlist / WishlistProduct")]
   OF --> Cart[("Order<br/>Token")]
```

## Folder map

```
config/packages/bitexpert_wishlist_concierge.yaml  → themes param
config/routes/shop.yaml                             → explicit YAML routes for all WebMCP endpoints (no #[Route] attributes, no wildcard import)
config/services/webmcp.xml                          → services private:true, controllers public:true
config/twig_hooks/shop.yaml                         → sylius_shop.base.footer.content badge
src/Dto/* (9)                                       → #[Assert] validation (WishlistCreateRequest, WishlistAddItemRequest, WishlistRemoveItemRequest, WishlistBulkAddRequest, WishlistClearRequest, WishlistDeleteRequest, BudgetOptimizeRequest, MoveToCartRequest, ProductSearchRequest)
src/Security/ToolContractValidator.php              → kernel.request listener: deserializes JSON → DTO → validates constraints → 422 on failure
src/Security/WishlistAccessChecker.php              → shareable anon vs owned 403
src/Controller/Shop/ToolContractsController.php     → GET /_webmcp/wishlist_concierge/tools.json — machine-readable tool manifest
src/Service/ModelContextToolCollector.php           → reads #[ModelContextTool] attributes, resolves route URL via routeName lookup; builds manifest
src/Service/ThemedProductFinder.php                 → channel-scoped QB, mapProduct()
src/Service/BudgetOptimizer.php                     → int cents + quantity + CatalogPromotion integration
src/Service/ErrorResponseFactory.php                → centralized {error,message,violations?} JSON envelope
src/Service/ValidationErrorFormatter.php            → ConstraintViolation → [{field,message}]
src/Command/ConciergeTagsSetupCommand.php           → bitexpert:wishlist-concierge:setup-tags — creates concierge_tags attribute + tags products
templates/shop/webmcp/status.html.twig              → {{ 'bitexpert_wishlist_concierge.shop.status.label'|trans }}
translations/messages.en.yaml                       → EN keys
tests/Unit/Service/BudgetOptimizerTest.php          → 3 cases, phpunit
assets/shop/webmcp/registry.js                      → Promise.allSettled race fix, apiFetch parses {error,message,violations}, withErrorHandling wrapper per tool
assets/shop/webmcp/controllers/toolbox_controller.js → Stimulus: discoverability modal, form generation from inputSchema, spinner, run button
assets/shop/webmcp/controllers/toast_controller.js  → listens webmcp:toast, renders Bootstrap toast (error/success/info)
```

## Security

`src/Security/WishlistAccessChecker.php:34` — owned wishlists (`getShopUser() !== null`) require `ROLE_USER` + owner `id` match → `403`. Anonymous gift registries are *shareable by design* (`shopUser === null` → allow) — required for contest anon `move_to_cart`. To lock anon to cookie, uncomment token check `wishlist.getToken() !== cookieTokenResolver->resolve()`.
