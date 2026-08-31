/**
 * WebMCP Registry — Wishlist Concierge
 * Registers 8 imperative tools via document.modelContext.registerTool
 * See https://webmachinelearning.github.io/webmcp/
 */
const BASE_CHANNEL = 'FASHION_WEB';
const DEFAULT_LOCALE = 'en_US';

function getBaseUrl() {
    const locale = document.documentElement.lang || DEFAULT_LOCALE;
    // Use current locale prefix if present in URL, else default
    const pathLocale = window.location.pathname.match(/^\/(en_US|de_DE|fr_FR)(\/|$)/);
    const l = pathLocale ? pathLocale[1] : locale;
    return `/${l}`;
}

function getApiUrl(path) {
    return `${path.startsWith('/') ? '' : '/'}${path}`;
}

async function apiFetch(path, opts = {}) {
    const url = `${getBaseUrl()}${path}`;
    const res = await fetch(url, {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...opts.headers },
        ...opts,
    });
    if (!res.ok) {
        const text = await res.text();
        try {
            const body = JSON.parse(text);
            const msg = body.message || body.error || text;
            const violations = body.violations?.length
                ? ` (${body.violations.map((v) => v.message).join('; ')})`
                : '';
            throw new Error(`${msg}${violations}`);
        } catch (e) {
            if (e instanceof SyntaxError) {
                throw new Error(`API ${path} ${res.status}: ${text}`);
            }
            throw e;
        }
    }
    return res.json();
}

function withErrorHandling(fn, toolName) {
    return async (input, options) => {
        try {
            return await fn(input, options);
        } catch (e) {
            const payload = { error: toolName, message: e.message || String(e) };
            return JSON.stringify(payload, null, 2);
        }
    };
}

/**
 * The full catalogue of WebMCP tools registered with the runtime.
 * Exported so the Discoverability UI ("WebMCP toolbox") can list and run
 * the tools from a single source of truth, without duplicating definitions.
 */
const TOOLS = [
        {
            name: 'wishlist.list',
            description: 'List recent wishlists for the current channel (FASHION_WEB, en_US). Use to discover existing wishlists before creating a new themed one.',
            inputSchema: {
                type: 'object',
                properties: {
                    channelCode: { type: 'string', description: 'Channel code, defaults to FASHION_WEB', default: BASE_CHANNEL },
                },
            },
            annotations: { readOnlyHint: true },
            execute: withErrorHandling(async (input) => {
                const data = await apiFetch('/concierge/wishlist');
                return JSON.stringify(data, null, 2);
            }, 'wishlist.list'),
        },
        {
            name: 'wishlist.get',
            description: 'Get details of a single wishlist by id, including items with variantCode, productName, price and quantities.',
            inputSchema: {
                type: 'object',
                properties: { wishlistId: { type: 'integer', description: 'Wishlist id' } },
                required: ['wishlistId'],
            },
            annotations: { readOnlyHint: true },
            execute: withErrorHandling(async ({ wishlistId }) => {
                const data = await apiFetch(`/concierge/wishlist/${wishlistId}`);
                return JSON.stringify(data, null, 2);
            }, 'wishlist.get'),
        },
        {
            name: 'wishlist.create',
            description: 'Create a new themed wishlist for FASHION_WEB. Theme examples: birthday, gift, summer, casual, formal. Name should be human readable like "Dino Birthday — $150".',
            inputSchema: {
                type: 'object',
                properties: {
                    name: { type: 'string', description: 'Wishlist name, e.g. Dino Birthday — $150' },
                    theme: { type: 'string', description: 'Theme keyword: birthday, dinosaur, gift, summer, etc.' },
                    channelCode: { type: 'string', default: BASE_CHANNEL },
                },
                required: ['name', 'theme'],
            },
            execute: withErrorHandling(async (input) => {
                const data = await apiFetch('/concierge/wishlist', {
                    method: 'POST',
                    body: JSON.stringify({ name: input.name, theme: input.theme, channelCode: input.channelCode || BASE_CHANNEL }),
                });
                // Dispatch live UI update
                window.dispatchEvent(new CustomEvent('webmcp:wishlist-created', { detail: data }));
                return JSON.stringify(data, null, 2);
            }, 'wishlist.create'),
        },
        {
            name: 'product.search',
            description: 'Search products for FASHION_WEB by theme and optional taxon/price filters. Returns products with code, name, variantCode, price (cents), taxonCodes for curation. Matches products tagged with the concierge_tags attribute (e.g. "dinosaur", "gift", "summer") or whose name contains the theme string.',
            inputSchema: {
                type: 'object',
                properties: {
                    theme: { type: 'string', description: 'Theme keyword' },
                    taxonCodes: { type: 'array', items: { type: 'string' }, description: 'Optional taxon filter e.g. ["t_shirts","caps"]' },
                    priceMinCents: { type: 'integer', description: 'Min price cents' },
                    priceMaxCents: { type: 'integer', description: 'Max price cents' },
                    limit: { type: 'integer', default: 12 },
                    channelCode: { type: 'string', default: BASE_CHANNEL },
                },
                required: ['theme'],
            },
            annotations: { readOnlyHint: true },
            execute: withErrorHandling(async (input) => {
                const params = new URLSearchParams();
                params.set('theme', input.theme);
                params.set('channelCode', input.channelCode || BASE_CHANNEL);
                params.set('limit', String(input.limit || 12));
                if (input.priceMinCents) params.set('priceMin', String(input.priceMinCents));
                if (input.priceMaxCents) params.set('priceMax', String(input.priceMaxCents));
                if (input.taxonCodes) input.taxonCodes.forEach((c) => params.append('taxonCodes[]', c));
                const data = await apiFetch(`/concierge/products/search?${params.toString()}`);
                return JSON.stringify(data, null, 2);
            }, 'product.search'),
        },
        {
            name: 'product.get_details',
            description: 'Get product details by productCode for FASHION_WEB including variants and pricing.',
            inputSchema: {
                type: 'object',
                properties: { productCode: { type: 'string' } },
                required: ['productCode'],
            },
            annotations: { readOnlyHint: true },
            execute: withErrorHandling(async ({ productCode }) => {
                const url = getApiUrl(`/api/v2/shop/products/${productCode}?channelCode=${BASE_CHANNEL}`);
                const res = await fetch(url, {
                    headers: { Accept: 'application/ld+json' },
                });
                if (!res.ok) {
                    const text = await res.text();
                    throw new Error(`API ${res.status}: ${text}`);
                }
                const data = await res.json();
                return JSON.stringify(data, null, 2);
            }, 'product.get_details'),
        },
        {
            name: 'wishlist.add_item',
            description: 'Add a product variant to a wishlist by variantCode and quantity.',
            inputSchema: {
                type: 'object',
                properties: {
                    wishlistId: { type: 'integer' },
                    variantCode: { type: 'string', description: 'Variant code like T_SHIRT_VARIANT' },
                    quantity: { type: 'integer', default: 1, minimum: 1 },
                },
                required: ['wishlistId', 'variantCode'],
            },
            execute: withErrorHandling(async (input, { signal }) => {
                if (signal?.aborted) throw new Error('Aborted');
                const data = await apiFetch(`/concierge/wishlist/${input.wishlistId}/items`, {
                    method: 'POST',
                    body: JSON.stringify({ variantCode: input.variantCode, quantity: input.quantity || 1 }),
                });
                window.dispatchEvent(new CustomEvent('webmcp:wishlist-updated', { detail: data }));
                return JSON.stringify(data, null, 2);
            }, 'wishlist.add_item'),
        },
        {
            name: 'wishlist.optimize_for_budget',
            description: 'Optimize a wishlist for a budget (cents, USD). Applies eligible Sylius catalog promotions when includePromotions is true: returns chosen variantCodes, totalCents/savedCents, the list of active promotionsApplied and a human explanation. Use before move_to_cart to stay under budget.',
            inputSchema: {
                type: 'object',
                properties: {
                    wishlistId: { type: 'integer' },
                    budgetCents: { type: 'integer', description: 'Budget in cents, e.g. 15000 for $150' },
                    includePromotions: { type: 'boolean', default: true, description: 'Apply eligible Sylius catalog promotions when computing the optimal set' },
                },
                required: ['wishlistId', 'budgetCents'],
            },
            annotations: { readOnlyHint: true },
            execute: withErrorHandling(async (input) => {
                const data = await apiFetch(`/concierge/wishlist/${input.wishlistId}/optimize`, {
                    method: 'POST',
                    body: JSON.stringify({ budgetCents: input.budgetCents, includePromotions: input.includePromotions ?? true }),
                });
                if (data.promotionsApplied?.length) {
                    window.dispatchEvent(new CustomEvent('webmcp:promotions-applied', { detail: data }));
                }
                return JSON.stringify(data, null, 2);
            }, 'wishlist.optimize_for_budget'),
        },
        {
            name: 'wishlist.move_to_cart',
            description: 'Move wishlist items to cart (anon allowed). Requires human confirmation — the tool will show a confirm dialog in the page before proceeding. Optionally pass variantCodes to move subset, else all.',
            inputSchema: {
                type: 'object',
                properties: {
                    wishlistId: { type: 'integer' },
                    variantCodes: { type: 'array', items: { type: 'string' }, description: 'Subset to move, omit for all' },
                },
                required: ['wishlistId'],
            },
            execute: withErrorHandling(async (input, { signal }) => {
                if (signal?.aborted) throw new Error('Aborted');
                const preview = await apiFetch(`/concierge/wishlist/${input.wishlistId}`);
                const items = preview.wishlist.items || [];
                const count = input.variantCodes ? input.variantCodes.length : items.length;
                const totalPreview = items.reduce((s, it) => s + (it.price || 0) * it.quantity, 0);
                const ok = window.confirm(
                    `Move ${count} item(s) ($${(totalPreview / 100).toFixed(2)}) from wishlist "${preview.wishlist.name}" to cart? This will create a new cart (anon allowed).`,
                );
                if (!ok) return JSON.stringify({ canceled: true, reason: 'Human declined confirmation' }, null, 2);
                if (signal?.aborted) throw new Error('Aborted');
                const data = await apiFetch(`/concierge/wishlist/${input.wishlistId}/move-to-cart`, {
                    method: 'POST',
                    body: JSON.stringify({ variantCodes: input.variantCodes || null }),
                });
                window.dispatchEvent(new CustomEvent('webmcp:cart-created', { detail: data }));
                // Navigate hint
                return JSON.stringify({ ...data, message: 'Cart created. Visit ' + data.cartUrl + ' with token ' + data.cartToken }, null, 2);
            }, 'wishlist.move_to_cart'),
        },
    ];

async function registerAll() {
    if (!('modelContext' in document)) {
        console.warn('[WebMCP] document.modelContext not available — WebMCP disabled. Enable chrome://flags/#enable-webmcp-testing');
        updateStatus('unavailable', 'WebMCP unavailable — enable flag');
        window.dispatchEvent(new CustomEvent('webmcp:toast', {
            detail: { type: 'error', message: 'WebMCP unavailable — enable chrome://flags/#enable-webmcp-testing' },
        }));
        return;
    }

    // Use Promise.all to avoid race — ensures status only after all registrations settle
    const results = await Promise.allSettled(
        TOOLS.map(async (tool) => {
            try {
                const registered = await document.modelContext.registerTool(tool);
                // Share RegisteredTool handles via window: registry.js is duplicated
                // into multiple webpack chunks (entrypoint + lazy Stimulus chunks), so
                // module-level state would NOT be shared with the toolbox controller.
                (window.__webmcpTools ??= {})[tool.name] = registered;
                console.log('[WebMCP] registered', tool.name);
                return { name: tool.name, ok: true };
            } catch (e) {
                console.error('[WebMCP] failed to register', tool.name, e);
                return { name: tool.name, ok: false, error: e };
            }
        }),
    );
    const succeeded = results.filter((r) => r.value?.ok).length;
    const failed = results.length - succeeded;
    if (failed > 0) {
        const failedTools = results.filter((r) => !r.value?.ok);
        const failedNames = failedTools.map((r) => r.value?.name).join(', ');
        updateStatus('error', `Registered ${succeeded}/${results.length} — failed: ${failedNames}`);
        const failDetail = failedTools
            .map((r) => `${r.value?.name}: ${r.value?.error?.message || r.value?.error || 'unknown'}`)
            .join('; ');
        window.dispatchEvent(new CustomEvent('webmcp:toast', { detail: { type: 'error', message: `WebMCP registration — ${failDetail}` } }));
        console.warn('[WebMCP] failed tools', failedTools);
    } else {
        updateStatus('ready', `${succeeded} tools ready`);
        window.dispatchEvent(new CustomEvent('webmcp:ready', { detail: { count: succeeded } }));
    }

    // Also listen for toolchange
    if (document.modelContext) {
        document.modelContext.addEventListener('toolchange', () => {
            console.log('[WebMCP] toolchange event');
        });
    }
}

function updateStatus(state, text) {
    const el = document.querySelector('[data-webmcp-indicator] .badge');
    if (!el) return;
    el.textContent = text;
    el.className = 'badge ' + (state === 'ready' ? 'bg-success' : state === 'error' ? 'bg-danger' : state === 'unavailable' ? 'bg-warning text-dark' : 'bg-secondary');
}

// Registration is triggered by the shop entrypoint (entrypoint.js) — not here —
// so that importing TOOLS from this module (e.g. by the Stimulus toolbox controller)
// does not cause registration to run at module-load time.

export { registerAll, TOOLS };
