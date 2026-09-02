# WebMCP Tool Reference — Full JSON Schema Inline

All tools are imperative: definitions live in `#[ModelContextTool]` attributes on the shop controllers, are resolved to their Symfony routes by name (via `routeName`), and served as a JSON manifest at `/en_US/_webmcp/wishlist_concierge/tools.json`. `assets/shop/webmcp/registry.js` fetches that manifest and registers each tool at runtime via `document.modelContext.registerTool(tool, {signal})` — adding a tool requires no JS. `name` regex `^[A-Za-z0-9_.-]{1,128}$`.

## Error handling

Every tool's `execute` function is wrapped by `withErrorHandling()` — if the underlying API call throws, the tool returns a structured `{error, message}` JSON payload instead of crashing the agent. The `apiFetch()` helper also parses server-side `{error, message, violations}` envelopes so the front-end (and the agent) always receives a consistent error shape.

## Custom events (DOM)

Tools dispatch events that the UI listens to for live feedback:

| Event                       | Fired by                            | Payload                       |
|-----------------------------|-------------------------------------|-------------------------------|
| `webmcp:toast`              | `toolbox_controller.js`, `apiFetch` | `{type: "success"|"error"|"info", message}` |
| `webmcp:wishlist-created`   | `wishlist.create`                   | `{wishlist: {id, name, ...}}` |
| `webmcp:wishlist-updated`   | `wishlist.add_item`, `wishlist.bulk_add`, `wishlist.clear`, `wishlist.remove_item` | `{wishlist: {...}}` |
| `webmcp:wishlist-deleted`   | `wishlist.delete`                   | `{wishlist: {...}}`           |
| `webmcp:promotions-applied` | `wishlist.optimize_for_budget`      | `{promotionsApplied: [...]}`  |
| `webmcp:cart-created`       | `wishlist.move_to_cart`             | `{cartToken, cartUrl, ...}`   |
| `webmcp:ready`              | `registerAll()`                     | `{count: 12}`                  |

## Exposed WebMCP tools

### `wishlist.list` — `readOnlyHint:true`
List recent wishlists for the current channel.
```json
{
  "name": "wishlist.list",
  "description": "List recent wishlists for the current channel. The active channel is automatically inferred from the current Sylius context. Use to discover existing wishlists before creating a new themed one.",
  "inputSchema": {
    "type": "object",
    "properties": {}
  },
  "execute": "GET /en_US/_webmcp/wishlist_concierge/wishlist → {wishlists:[{id,token,name,channelCode,items}], channelCode}"
}
```

### `wishlist.get` — `readOnlyHint:true`
```json
{
  "name": "wishlist.get",
  "description": "Get details of a single wishlist by id, including items with variantCode, productName, price and quantities.",
  "inputSchema": {
    "type": "object",
    "properties": { "wishlistId": { "type": "integer", "description": "Wishlist id" } },
    "required": ["wishlistId"]
  },
  "execute": "GET /en_US/_webmcp/wishlist_concierge/wishlist/{id} → {wishlist:{id,token,name,items:[{wishlistProductId,variantCode,productCode,productName,quantity,price,originalPrice}]}}"
}
```

### `wishlist.create`
```json
{
  "name": "wishlist.create",
  "description": "Create a new themed wishlist. The active channel is automatically inferred from the current Sylius context. Theme examples: birthday, gift, summer, casual, formal. Name should be human readable like \"Birthday Wishlist — $150\".",
  "inputSchema": {
    "type": "object",
    "properties": {
      "name": { "type": "string", "description": "Wishlist name, e.g. Birthday Wishlist — $150" },
      "theme": { "type": "string", "description": "Theme keyword: birthday, gift, summer, etc." }
    },
    "required": ["name","theme"]
  },
  "constraints": "WishlistCreateRequest.php #[Assert\\NotBlank, Length(max:100), Regex ^[\\pL\\pN\\s\\-_]+$]",
  "execute": "POST /en_US/_webmcp/wishlist_concierge/wishlist {name,theme} → 201 {wishlist} | 422 {violations}"
}
```

### `product.search` — `readOnlyHint:true`
```json
{
  "name": "product.search",
  "description": "Search products by theme and optional taxon/price filters. The active channel is automatically inferred from the current Sylius context. Returns products with code, name, variantCode, price (cents), taxonCodes for curation. Matches products tagged with the concierge_tags attribute (e.g. \"gift\", \"summer\") or whose name contains the theme string.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "theme": { "type": "string", "description": "Theme keyword" },
      "taxonCodes": { "type": "array", "items": { "type": "string" }, "description": "Optional taxon filter e.g. [\"t_shirts\",\"caps\"]" },
      "priceMinCents": { "type": "integer", "description": "Min price cents" },
      "priceMaxCents": { "type": "integer", "description": "Max price cents" },
      "limit": { "type": "integer", "default": 12, "minimum": 1, "maximum": 50 }
    },
    "required": ["theme"]
  },
  "execute": "GET /en_US/_webmcp/wishlist_concierge/products/search?theme=&taxonCodes[]=&priceMin=&priceMax=&limit= → {count, products:[{code,name,slug,price,originalPrice,taxonCodes,image,variantCode}]} | 404 {error:\"Channel not found\"} | 422 priceMin>priceMax"
}
```

### `product.get_details` — `readOnlyHint:true`
```json
{
  "name": "product.get_details",
  "description": "Get product details by productCode, including variants and pricing.",
  "inputSchema": {
    "type": "object",
    "properties": { "productCode": { "type": "string" } },
    "required": ["productCode"]
  },
  "execute": "GET /api/v2/shop/products/{code} (Accept: application/ld+json)"
}
```

### `wishlist.add_item`
```json
{
  "name": "wishlist.add_item",
  "description": "Add a product variant to a wishlist by variantCode and quantity.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "wishlistId": { "type": "integer" },
      "variantCode": { "type": "string", "description": "Variant code like T_SHIRT_VARIANT", "pattern": "^[A-Za-z0-9._-]+$" },
      "quantity": { "type": "integer", "default": 1, "minimum": 1, "maximum": 99 }
    },
    "required": ["wishlistId","variantCode"]
  },
  "execute": "POST /en_US/_webmcp/wishlist_concierge/wishlist/{id}/items {variantCode,quantity} → {wishlist} | 422 Invalid variant code format | 400 Variant not found | 403 token mismatch"
}
```

### `wishlist.remove_item`
```json
{
  "name": "wishlist.remove_item",
  "description": "Remove an item from a wishlist by itemId.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "wishlistId": { "type": "integer" },
      "itemId": { "type": "integer", "description": "Wishlist line-item id", "minimum": 1 }
    },
    "required": ["wishlistId","itemId"]
  },
  "destructiveHint": true,
  "emitsEvents": ["webmcp:wishlist-updated"],
  "pathParams": {"wishlistId":"id"},
  "execute": "POST /en_US/_webmcp/wishlist_concierge/wishlist/{id}/items/remove {itemId} → {wishlist} | 400 Item not found | 403 token mismatch"
}
```

### `wishlist.optimize_for_budget` — `readOnlyHint:true`
```json
{
  "name": "wishlist.optimize_for_budget",
  "description": "Optimize a wishlist for a budget (cents, USD). Applies eligible Sylius catalog promotions when includePromotions is true: returns chosen variantCodes, totalCents/savedCents, the list of active promotionsApplied and a human explanation. Use before move_to_cart to stay under budget.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "wishlistId": { "type": "integer" },
      "budgetCents": { "type": "integer", "description": "Budget in cents, e.g. 15000 for $150", "minimum": 1, "maximum": 10000000 },
      "includePromotions": { "type": "boolean", "default": true, "description": "Apply eligible Sylius catalog promotions when computing the optimal set" }
    },
    "required": ["wishlistId","budgetCents"]
  },
  "constraints": "BudgetOptimizeRequest.php #[Assert\\NotNull, Positive, LessThanOrEqual(10000000)]",
  "execute": "POST /en_US/_webmcp/wishlist_concierge/wishlist/{id}/optimize {budgetCents,includePromotions} → {wishlistId,budgetCents,budgetFormatted,chosen:[variantCode],totalCents,totalOriginal,savedCents,totalFormatted,savedFormatted,explanation,promotionsApplied:[{code,name}],promotionsIgnored:bool}"
}
```

### `wishlist.move_to_cart`
```json
{
  "name": "wishlist.move_to_cart",
  "description": "Move wishlist items to cart (anon allowed). Requires human confirmation — the tool will show a confirm dialog in the page before proceeding. Optionally pass variantCodes to move subset, else all.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "wishlistId": { "type": "integer" },
      "variantCodes": { "type": "array", "items": { "type": "string", "pattern": "^[A-Za-z0-9._-]+$" }, "description": "Subset to move, omit for all" }
    },
    "required": ["wishlistId"]
  },
  "execute": "GET /wishlist/{id} preview → window.confirm(\"Move N items ($X) to cart?\") → POST /en_US/_webmcp/wishlist_concierge/wishlist/{id}/move-to-cart {variantCodes} → 201 {cartToken,items:[{variantCode,quantity,unitPrice,total}],total,totalFormatted,cartUrl:\"/en_US/cart\"} | {canceled:true} if declined | AbortSignal respected"
}
```

### `wishlist.delete`
```json
{
  "name": "wishlist.delete",
  "description": "Delete a wishlist permanently.",
  "inputSchema": {"type": "object","properties": {}},
  "confirmMessage": "Are you sure you want to permanently delete this wishlist? This cannot be undone.",
  "destructiveHint": true,
  "emitsEvents": ["webmcp:wishlist-deleted"],
  "pathParams": {"wishlistId":"id"}
}
```

### `wishlist.bulk_add`
```json
{
  "name": "wishlist.bulk_add",
  "description": "Add multiple product variants to a wishlist in one call. Input is an array of {variantCode, quantity} objects.",
  "inputSchema": {"type": "object","properties": {"wishlistId": {"type": "integer"},"items": {"type": "array","items": {"type": "object","properties": {"variantCode": {"type": "string"},"quantity": {"type": "integer","default": 1}},"required": ["variantCode"]}}},"required": ["wishlistId","items"]},
  "emitsEvents": ["webmcp:wishlist-updated"],
  "pathParams": {"wishlistId":"id"}
}
```

### `wishlist.clear`
```json
{
  "name": "wishlist.clear",
  "description": "Remove all items from a wishlist in one call. Useful for resetting a themed list before re-curating.",
  "inputSchema": {"type": "object","properties": {"wishlistId": {"type": "integer"}},"required": ["wishlistId"]},
  "destructiveHint": true,
  "emitsEvents": ["webmcp:wishlist-updated"],
  "pathParams": {"wishlistId":"id"}
}
```

## Machine-Readable Tool Manifest

The plugin exposes a single JSON manifest that describes every WebMCP tool — name, description, JSON-Schema `inputSchema` (introspected from the Symfony Validator constraints on each tool's input DTO), route URL, path params, and annotations. `assets/shop/webmcp/registry.js` fetches this manifest at runtime and registers each tool with `document.modelContext.registerTool()` — so the schema shown in this reference, the tool advertised to the agent, and the payload validated by `ToolContractValidator` are all one and the same.

| Endpoint                                          | Description                                                                         |
|---------------------------------------------------|-------------------------------------------------------------------------------------|
| `GET /en_US/_webmcp/wishlist_concierge/tools.json` | Full tool manifest: `{tools:[{name,description,inputSchema,route,pathParams,...}]}` |

## WebMCP tool contract validation

`src/Security/ToolContractValidator.php` is a `kernel.request` event listener (priority 16) that centralizes server-side validation of the WebMCP tool contracts. For every imperative `/_webmcp/wishlist_concierge/*` endpoint that accepts a JSON payload (`create`, `add_item`, `bulk_add`, `clear`, `remove_item`, `optimize`, `move_to_cart`), it:

1. Resolves the tool's input DTO from the collector's `routeDtoMap()` (route name → DTO class),
2. Deserializes the request body via the DTO's `fromRequest()`,
3. Validates it against the DTO's `#[Assert]` constraints,
4. On failure returns a single, deterministic `422` response shaped by `ErrorResponseFactory` + `ValidationErrorFormatter`.

This means the "structured contract" promised by WebMCP is honored regardless of which client (browser, script, test) invokes it — the schema advertised to the agent is exactly the payload the server enforces. On success, the validated DTO is exposed to the controller via the `_webmcp_validated_dto` request attribute.

```bash
# e.g. a malformed payload now returns a structured 422 (not a crash)
curl -s -X POST https://wishlist-concierge.ddev.site/en_US/_webmcp/wishlist_concierge/wishlist \
  -H "Content-Type: application/json" -d '{"name":"","theme":""}' | python3 -m json.tool
# → {"error":"Validation failed","message":"The tool input does not satisfy its declared contract.",
#    "violations":[{"field":"name","message":"Wishlist name must not be blank."}, ...]}
```
