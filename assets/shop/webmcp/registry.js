/**
 * WebMCP Registry — Wishlist Concierge
 * Registers imperative tools via document.modelContext.registerTool.
 *
 * Tool DEFINITIONS (name, description, schema, route, annotations, ...) are
 * declared once on the Symfony controller methods via the #[WebMcpTool]
 * attribute and served as a JSON manifest from /_webmcp/wishlist_concierge/tools.json.
 * This module fetches that manifest and wires each tool to a single generic
 * executor, so adding a tool only requires the PHP attribute — no JS here.
 *
 * See https://webmachinelearning.github.io/webmcp/
 */
const DEFAULT_LOCALE = 'en_US';
const MANIFEST_URL = '/_webmcp/wishlist_concierge/tools.json';

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
 * Build a generic executor for a tool definition from the manifest.
 * Handles placeholder substitution, query params, JSON body, confirmation
 * prompts and post-success DOM events — no per-tool code required.
 */
function createGenericExecutor(toolDef) {
    return async (input, { signal } = {}) => {
        if (signal?.aborted) throw new Error('Aborted');

        // 1. Optional static confirmation prompt
        if (toolDef.confirmMessage) {
            if (!window.confirm(toolDef.confirmMessage)) {
                return JSON.stringify({ canceled: true, reason: 'User declined confirmation' }, null, 2);
            }
            if (signal?.aborted) throw new Error('Aborted');
        }

        // 2. Substitute route path placeholders. Input keys may map to a
        //    differently-named route placeholder via toolDef.pathParams.
        const pathParams = toolDef.pathParams || {};
        let url = toolDef.route.path;
        const consumed = new Set();
        for (const [key, val] of Object.entries(input)) {
            const placeholder = pathParams[key] || key;
            const ph = `{${placeholder}}`;
            if (url.includes(ph) && val != null) {
                url = url.replace(ph, encodeURIComponent(String(val)));
                consumed.add(key);
            }
        }

        // 3. Build request based on HTTP method
        const method = (toolDef.route.methods && toolDef.route.methods[0]) || 'GET';
        const opts = { method, headers: { ...toolDef.headers } };

        if (['POST', 'PUT', 'PATCH'].includes(method)) {
            const body = Object.fromEntries(
                Object.entries(input).filter(([k]) => !consumed.has(k)),
            );
            opts.body = JSON.stringify(body);
        } else if (['DELETE'].includes(method)) {
            // DELETE carries remaining input as query params
            const qs = new URLSearchParams();
            for (const [k, v] of Object.entries(input)) {
                if (consumed.has(k) || v == null) continue;
                if (Array.isArray(v)) v.forEach((x) => qs.append(`${k}[]`, x));
                else qs.set(k, String(v));
            }
            for (const [k, v] of Object.entries(toolDef.queryParams || {})) qs.set(k, v);
            const s = qs.toString();
            if (s) url += `?${s}`;
        } else {
            // GET — remaining input as query params
            const qs = new URLSearchParams();
            for (const [k, v] of Object.entries(input)) {
                if (consumed.has(k) || v == null) continue;
                if (Array.isArray(v)) v.forEach((x) => qs.append(`${k}[]`, x));
                else qs.set(k, String(v));
            }
            for (const [k, v] of Object.entries(toolDef.queryParams || {})) qs.set(k, v);
            const s = qs.toString();
            if (s) url += `?${s}`;
        }

        // 4. Fetch (optionally skipping the locale prefix)
        const data = await apiFetch(url, opts);

        // 5. Dispatch declared post-success DOM events
        for (const ev of toolDef.emitsEvents || []) {
            window.dispatchEvent(new CustomEvent(ev, { detail: data }));
        }

        return JSON.stringify(data, null, 2);
    };
}

async function loadManifest() {
    const base = getBaseUrl();
    const res = await fetch(`${base}${MANIFEST_URL}`, {
        headers: { Accept: 'application/json' },
    });
    if (!res.ok) {
        throw new Error(`Failed to load WebMCP manifest: ${res.status}`);
    }
    const payload = await res.json();
    return payload.tools || [];
}

async function registerAll() {
    if (!('modelContext' in document)) {
        console.warn('[WebMCP] document.modelContext not available — WebMCP disabled. Enable chrome://flags/#enable-webmcp-testing');
        updateStatus('unavailable', 'WebMCP unavailable — enable flag');
        window.dispatchEvent(new CustomEvent('webmcp:toast', {
            detail: { type: 'error', message: 'WebMCP unavailable — enable chrome://flags/#enable-webmcp-testing' },
        }));
        return;
    }

    let tools;
    try {
        tools = await loadManifest();
    } catch (e) {
        console.error('[WebMCP] failed to load manifest', e);
        updateStatus('error', 'WebMCP manifest failed');
        window.dispatchEvent(new CustomEvent('webmcp:toast', {
            detail: { type: 'error', message: `WebMCP manifest — ${e.message}` },
        }));
        return;
    }

    const results = await Promise.allSettled(
        tools.map(async (def) => {
            const tool = {
                name: def.name,
                description: def.description,
                inputSchema: def.inputSchema,
                annotations: def.annotations,
                execute: withErrorHandling(createGenericExecutor(def), def.name),
            };
            try {
                const registered = await document.modelContext.registerTool(tool);
                console.log('[WebMCP] registered', def.name);
                return { name: def.name, ok: true };
            } catch (e) {
                console.error('[WebMCP] failed to register', def.name, e);
                return { name: def.name, ok: false, error: e };
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
// so that the toolbox controller (a separate lazy chunk) doesn't trigger registration.

export { registerAll };
