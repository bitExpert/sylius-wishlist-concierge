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

async function apiFetch(path, opts = {}) {
    const url = `${getBaseUrl()}${path}`;
    const res = await fetch(url, {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...opts.headers },
        ...opts,
    });
    if (!res.ok) {
        const text = await res.text();
        throw new Error(`API ${path} ${res.status}: ${text}`);
    }
    return res.json();
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
            execute: async (input) => {
                const data = await apiFetch('/concierge/wishlist');
                return JSON.stringify(data, null, 2);
            },
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
            execute: async ({ wishlistId }) => {
                const data = await apiFetch(`/concierge/wishlist/${wishlistId}`);
                return JSON.stringify(data, null, 2);
            },
        },
        {
            name: 'wishlist.create_themed',
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
            execute: async (input) => {
                const data = await apiFetch('/concierge/wishlist', {
                    method: 'POST',
                    body: JSON.stringify({ name: input.name, theme: input.theme, channelCode: input.channelCode || BASE_CHANNEL }),
                });
                // Dispatch live UI update
                window.dispatchEvent(new CustomEvent('webmcp:wishlist-created', { detail: data }));
                return JSON.stringify(data, null, 2);
            },
        },
        {
            name: 'product.search_themed',
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
            execute: async (input) => {
                const params = new URLSearchParams();
                params.set('theme', input.theme);
                params.set('channelCode', input.channelCode || BASE_CHANNEL);
                params.set('limit', String(input.limit || 12));
                if (input.priceMinCents) params.set('priceMin', String(input.priceMinCents));
                if (input.priceMaxCents) params.set('priceMax', String(input.priceMaxCents));
                if (input.taxonCodes) input.taxonCodes.forEach((c) => params.append('taxonCodes[]', c));
                const data = await apiFetch(`/concierge/products/search?${params.toString()}`);
                return JSON.stringify(data, null, 2);
            },
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
            execute: async ({ productCode }) => {
                // Use Shop API directly for details — channel aware
                const res = await fetch(`/api/v2/shop/products/${productCode}?channelCode=${BASE_CHANNEL}`, {
                    headers: { Accept: 'application/ld+json' },
                });
                if (!res.ok) throw new Error(`Product ${productCode} not found`);
                const data = await res.json();
                return JSON.stringify(data, null, 2);
            },
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
            execute: async (input, { signal }) => {
                if (signal?.aborted) throw new Error('Aborted');
                const data = await apiFetch(`/concierge/wishlist/${input.wishlistId}/items`, {
                    method: 'POST',
                    body: JSON.stringify({ variantCode: input.variantCode, quantity: input.quantity || 1 }),
                });
                window.dispatchEvent(new CustomEvent('webmcp:wishlist-updated', { detail: data }));
                return JSON.stringify(data, null, 2);
            },
        },
        {
            name: 'wishlist.optimize_for_budget',
            description: 'Optimize a wishlist for a budget (cents, USD). Returns chosen variantCodes, totalCents/savedCents and human explanation. Use before move_to_cart to stay under budget.',
            inputSchema: {
                type: 'object',
                properties: {
                    wishlistId: { type: 'integer' },
                    budgetCents: { type: 'integer', description: 'Budget in cents, e.g. 15000 for $150' },
                    includePromotions: { type: 'boolean', default: true },
                },
                required: ['wishlistId', 'budgetCents'],
            },
            annotations: { readOnlyHint: true },
            execute: async (input) => {
                const data = await apiFetch(`/concierge/wishlist/${input.wishlistId}/optimize`, {
                    method: 'POST',
                    body: JSON.stringify({ budgetCents: input.budgetCents, includePromotions: input.includePromotions ?? true }),
                });
                return JSON.stringify(data, null, 2);
            },
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
            execute: async (input, { signal }) => {
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
            },
        },
    ];

async function registerAll() {
    if (!('modelContext' in document)) {
        console.warn('[WebMCP] document.modelContext not available — WebMCP disabled. Enable chrome://flags/#enable-webmcp-testing');
        updateStatus('unavailable', 'WebMCP unavailable — enable flag');
        return;
    }

    // Use Promise.all to avoid race — ensures status only after all registrations settle
    const results = await Promise.allSettled(
        TOOLS.map(async (tool) => {
            try {
                await document.modelContext.registerTool(tool);
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
        const failedNames = results.filter((r) => !r.value?.ok).map((r) => r.value?.name).join(', ');
        updateStatus('error', `Registered ${succeeded}/${results.length} — failed: ${failedNames}`);
        console.warn('[WebMCP] failed tools', failedNames);
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

// Auto-register when DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerAll);
} else {
    // slight delay to ensure TestApplication scripts loaded
    setTimeout(registerAll, 100);
}

export { registerAll, TOOLS };
