# Project Knowledge Base

## 1. Overview

This repository is a Sylius-based wishlist-concierge plugin that enables **AI agents + humans to co-curate themed, budget-aware gift registries**. It integrates:

- **Sylius 2.0** with the **WishlistPlugin** for wishlist management
- **WebMCP** (Web Machine Learning Control Plane) to expose structured tools to AI agents
- **Sylius TestApplication** for the demo storefront

**Demo URL:** `https://wishlist-concierge.ddev.site/en_US/`

## 2. Tag & Theme System

### Core concept
- Product attribute: `concierge_tags` (type: `selection`, multiple: `true`)
- Tags are populated via console command `bitexpert:wishlist-concierge:setup-tags`
- Themes are used to filter products for curation (agent can search by theme)

### Valid tags
```
summer, casual, gift, birthday, formal, winter
```

**Rationale:**
- `summer`, `casual`, `formal`, `winter` → style/season categories
- `gift`, `birthday` → occasion-based tags

### How it works
1. **Hard match**: Product's `concierge_tags` attribute must contain the theme value (case-insensitive)
2. **Soft match**: If no hard match, theme is matched against the product name (case-insensitive)
3. **Fallback**: If no products match, returns all enabled products for the channel

## 3. CLI Commands

### Setup tags
```bash
ddev exec "php vendor/bin/console bitexpert:wishlist-concierge:setup-tags"
```

**Options:**
- `--channel=CHANNEL_CODE` (default: `FASHION_WEB`)
- `--dry-run` → show what would be done without persisting

**What it does:**
1. Creates `concierge_tags` product attribute if missing
2. Creates attribute values for all tags in `ConciergeTagsSetupCommand::PRODUCT_TAGS`
3. Assigns tags to products in the specified channel

### Other useful commands
```bash
ddev exec "php vendor/bin/console --version"              # Check console availability
ddev exec "php vendor/bin/console debug:config concierge_tags"  # Debug config
```

**Important:** All Symfony console commands require **PHP ≥ 8.4.1**. Always use `ddev exec` to access the container's correct PHP version.

## 4. DDEV Environment

### Project details
- **Project name:** `wishlist-concierge`
- **DDEV version:** v1.25.3
- **Web container:** `PHP 8.4` (required for Sylius 2.0)
- **Database:** MariaDB 11.8
- **Web root:** `vendor/sylius/test-application/public`

### Service endpoints
| Service     | URL                                         | Description      |
|-------------|---------------------------------------------|------------------|
| Web (https) | `https://wishlist-concierge.ddev.site`      | Main app (HTTPS) |
| Web (http)  | `http://wishlist-concierge.ddev.site`       | Main app (HTTP)  |
| Mailpit     | `https://wishlist-concierge.ddev.site:8026` | Email testing    |
| DDEV host   | `127.0.0.1:32772`                           | HTTP proxy       |
| Database    | `db:3306` (db/db)                           | MariaDB          |

### Database credentials
- `db/db` (normal user)
- `root/root` (admin user)

### Environment file
`.env.local` contains database, mailer, and other configuration for the DDEV environment.

## 5. Sylius TestApplication

### Role
The `vendor/sylius/test-application` provides a fully-featured Sylius demo storefront with:
- Pre-seeded products, variants, taxons, channels
- Pre-configured catalog promotions
- Shop API (`/api/v2/shop/...`)
- Test-specific configuration

### Key facts
- Web root is `vendor/sylius/test-application/public`
- The application shares services with the plugin
- Console commands run against this application
- API endpoints like `/api/v2/shop/products/{code}` **do NOT accept locale prefixes**

### Gotchas
- **API URLs with locale:** The `apiFetch()` helper in `registry.js` prepends locale prefix (`/en_US`). For Sylius Shop API endpoints, this causes 404 errors. Use `getBaseUrl(false)` or construct URLs without locale for Shop API.

## 6. Plugins & Dependencies

| Package                       | Version      | Purpose                        |
|-------------------------------|--------------|--------------------------------|
| `sylius/sylius`               | ^2.0         | Core e-commerce platform       |
| `sylius/wishlist-plugin`      | ^1.3         | Wishlist entities and services |
| `sylius/test-application`     | ^2.0.0@alpha | Demo storefront for testing    |
| `sylius-labs/coding-standard` | ^4.4         | Code style enforcement         |

## 7. WebMCP Tools

The plugin exposes **8 imperative tools** for AI agents:

| Tool                           | Description                         | `readOnlyHint`  |
|--------------------------------|-------------------------------------|-----------------|
| `wishlist.list`                | List recent wishlists               | ✅              |
| `wishlist.get`                 | Get wishlist details                | ✅              |
| `wishlist.create`              | Create a new themed wishlist        | ❌              |
| `wishlist.add_item`            | Add product variant to wishlist     | ❌              |
| `wishlist.remove_item`         | Remove item from wishlist           | ❌              |
| `product.search`               | Search products by theme            | ✅              |
| `product.get_details`          | Get product details                 | ✅              |
| `wishlist.optimize_for_budget` | Optimize for budget with promotions | ❌              |
| `wishlist.move_to_cart`        | Move items to cart                  | ❌              |

### Tool registration
- Single source of truth: `assets/shop/webmcp/registry.js`
- Tools registered via `document.modelContext.registerTool()`
- Frontend UI: WebMCP status badge (clicks open toolbox modal)

## 8. Development Workflow

### Adding a new tag
1. Update `ConciergeTagsSetupCommand::PRODUCT_TAGS` with new tag
2. Run `ddev exec "php vendor/bin/console bitexpert:wishlist-concierge:setup-tags --dry-run"` to verify
3. Run without `--dry-run` to persist
4. Update README examples if needed
5. Update `assets/shop/webmcp/registry.js` tool descriptions

### Testing a change
```bash
ddev exec "php vendor/bin/console doctrine:cache:clear-metadata"
ddev exec "php vendor/bin/console cache:clear"
ddev exec "php vendor/bin/console cache:warmup"
```

### Build assets
```bash
ddev exec "cd /var/www/html && yarn encore dev"
# or for production
ddev exec "cd /var/www/html && yarn encore production"
```

## 9. Gotchas & Best Practices

### Common pitfalls
| Issue                                                | Solution                                                                    |
|------------------------------------------------------|-----------------------------------------------------------------------------|
| `requires PHP ≥ 8.4.1`                               | Always use `ddev exec` for console commands                                 |
| `Cannot load resource "config/services/webmcp.yaml"` | Check YAML syntax, ensure services are registered in `config/services.yaml` |
| 404 on `product.get_details`                         | Sylius Shop API does not accept locale prefix                               |
| "Registered 0/8 — failed"                            | Webpack duplicates registry.js; ensure lazy loading is configured correctly |
| `class not found` errors                             | Run `ddev exec "php vendor/bin/console cache:clear"`                        |

### Naming conventions
- **Tool names:** snake_case (`wishlist.create`, `product.search`)
- **Theme tags:** lowercase, hyphenated if compound (`birthday`, `birthday-gift`)
- **Product codes:** PascalCase with underscores (`Ethereal_Drift_T_Shirt`)
- **Controller actions:** lowercase_with_underscore (`wishlist.create`, `product.search`)

### Git & deployment
- DDEV handles environment-specific config via `.env.local`
- TestApplication is git-ignored in `vendor/`

## 10. Reference Links

- [Sylius Documentation](https://docs.sylius.com)
- [WishlistPlugin Documentation](https://github.com/Sylius/WishlistPlugin)
- [WebMCP Specification](https://webmachinelearning.github.io/webmcp/)
- [DDEV Documentation](https://ddev.readthedocs.io)

## 11. Key Files

| Path                                                   | Purpose                       |
|--------------------------------------------------------|-------------------------------|
| `src/Command/ConciergeTagsSetupCommand.php`            | Tag setup logic               |
| `src/Service/ThemedProductFinder.php`                  | Theme-based product filtering |
| `src/Service/ToolContractMetadata.php`                 | WebMCP tool schema generation |
| `src/Dto/WishlistCreateRequest.php`                    | Wishlist creation DTO         |
| `src/Security/WishlistAccessChecker.php`               | Wishlist access rules         |
| `assets/shop/webmcp/registry.js`                       | WebMCP tool definitions       |
| `assets/shop/webmcp/controllers/toolbox_controller.js` | Toolbox UI controller         |
| `config/services/webmcp.yaml`                          | Service wiring                |
| `config/packages/bitexpert_wishlist_concierge.yaml`    | Plugin parameters             |
