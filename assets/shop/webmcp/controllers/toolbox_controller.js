import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['modal', 'body', 'loader', 'footer'];

    connect() {
        this.modalInstance = null;
        this.registeredTools = new Map();
        this.toolChangeTimeout = null;
    }

    async open() {
        if (!('modelContext' in document) || !document.modelContext.getTools) {
            window.dispatchEvent(new CustomEvent('webmcp:toast', {
                detail: { type: 'error', message: 'WebMCP unavailable — enable chrome://flags/#enable-webmcp-testing' },
            }));
            return;
        }

        this.showModal();
        await this.refreshTools();
        if (typeof document.modelContext.addEventListener === 'function') {
            document.modelContext.addEventListener('toolchange', this.handleToolChange.bind(this));
        }
    }

    close() {
        if (this.modalInstance) {
            this.modalInstance.hide();
        }
        if (document.modelContext && typeof document.modelContext.removeEventListener === 'function') {
            document.modelContext.removeEventListener('toolchange', this.handleToolChange.bind(this));
        }
    }

    handleToolChange() {
        if (this.toolChangeTimeout) {
            clearTimeout(this.toolChangeTimeout);
        }
        this.toolChangeTimeout = setTimeout(async () => {
            if (this.modalInstance && this.modalInstance._isShown) {
                await this.refreshTools();
            }
        }, 250);
    }

    async refreshTools() {
        if (this.hasLoaderTarget) {
            this.loaderTarget.remove();
        }

        const tools = await document.modelContext.getTools();
        this.registeredTools.clear();
        tools.forEach((tool) => {
            this.registeredTools.set(tool.name, tool);
        });

        if (tools.length === 0) {
            this.bodyTarget.innerHTML = '<div class="text-muted text-center py-3">No tools available.</div>';
            this.footerTarget.style.display = 'none';
            return;
        }

        const html = tools.map((tool) => this.renderToolCard(tool)).join('');
        this.bodyTarget.innerHTML = `<div class="row g-3">${html}</div>`;

        this.footerTarget.style.display = '';
    }

    showModal() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            if (!this.modalInstance) {
                this.modalInstance = new bootstrap.Modal(this.modalTarget);
            }
            this.modalInstance.show();
        } else {
            this.modalTarget.classList.add('show');
            this.modalTarget.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    }

    renderToolCard(tool) {
        // Normalize inputSchema: if it's a string, parse it; fallback to empty object on error
        let schema = tool.inputSchema;
        if (typeof schema === 'string') {
            try {
                schema = JSON.parse(schema);
            } catch (e) {
                console.warn('[WebMCP] Invalid inputSchema for tool', tool.name, e);
                schema = {};
            }
        }
        const properties = schema?.properties || {};
        const required = schema?.required || [];
        const fields = Object.entries(properties)
            .map(([key, prop]) => this.renderField(tool.name, key, prop, required.includes(key)))
            .join('');

        const annotations = tool.annotations || {};
        const readOnlyBadge = annotations.readOnlyHint
            ? '<span class="badge bg-info-subtle text-info-emphasis ms-2">read-only</span>'
            : '';

        return `
            <div class="col-12 col-md-6">
                <div class="card h-100" data-tool-name="${tool.name}">
                    <div class="card-body p-3">
                        <h6 class="card-title mb-1">${this.escapeHtml(tool.name || tool.title || 'Tool')}${readOnlyBadge}</h6>
                        <p class="card-text small text-muted mb-2">${this.escapeHtml(tool.description)}</p>
                        <form data-action="submit->webmcp--toolbox#runTool">
                            <input type="hidden" name="_toolName" value="${tool.name}">
                            ${fields}
                            <button type="submit" class="btn btn-primary btn-sm mt-2" data-webmcp--toolbox-target="runButton">
                                Run
                            </button>
                            <span class="ms-2" data-webmcp--toolbox-target="spinner" style="display:none;">
                                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                            </span>
                        </form>
                    </div>
                </div>
            </div>`;
    }

    renderField(toolName, key, prop, required) {
        const requiredAttr = required ? 'required' : '';
        const requiredMark = required ? ' <span class="text-danger">*</span>' : '';

        // Normalize prop: if it's a string, parse it
        const schema = typeof prop === 'string' ? JSON.parse(prop) : prop;
        const desc = schema.description ? `<div class="form-text">${this.escapeHtml(schema.description)}</div>` : '';

        let input;
        if (schema.type === 'boolean') {
            input = `
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="${key}" id="${toolName}-${key}" value="1">
                    <label class="form-check-label" for="${toolName}-${key}">${this.escapeHtml(key)}</label>
                </div>`;
            return `<div class="mb-2">${input}</div>`;
        }

        if (schema.type === 'integer' || schema.type === 'number') {
            const min = schema.minimum !== undefined ? `min="${schema.minimum}"` : '';
            const max = schema.maximum !== undefined ? `max="${schema.maximum}"` : '';
            const step = schema.type === 'number' ? 'step="0.01"' : '';
            const placeholder = schema.default !== undefined ? `value="${schema.default}"` : '';
            input = `<input type="number" class="form-control form-control-sm" name="${key}" id="${toolName}-${key}" ${min} ${max} ${step} ${placeholder} ${requiredAttr}>`;
        } else if (Array.isArray(schema.items)) {
            input = `<input type="text" class="form-control form-control-sm" name="${key}" id="${toolName}-${key}" placeholder='["item1","item2"]' ${requiredAttr}>`;
        } else {
            const placeholder = schema.default !== undefined ? `value="${schema.default}"` : '';
            input = `<input type="text" class="form-control form-control-sm" name="${key}" id="${toolName}-${key}" ${placeholder} ${requiredAttr}>`;
        }

        return `<div class="mb-2"><label for="${toolName}-${key}" class="form-label small mb-0">${this.escapeHtml(key)}${requiredMark}</label>${input}${desc}</div>`;
    }

    async runTool(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const toolName = formData.get('_toolName');

        const tool = this.registeredTools.get(toolName);
        if (!tool) {
            window.dispatchEvent(new CustomEvent('webmcp:toast', {
                detail: { type: 'error', message: `Unknown tool "${toolName}"` },
            }));
            return;
        }

        // Normalize inputSchema: if it's a string, parse it; fallback to empty object on error
        let schema = tool.inputSchema;
        if (typeof schema === 'string') {
            try {
                schema = JSON.parse(schema);
            } catch (e) {
                console.warn('[WebMCP] Invalid inputSchema for tool', tool.name, e);
                schema = {};
            }
        }
        const properties = schema?.properties || {};

        const payload = {};
        for (const [key, value] of formData.entries()) {
            if (key === '_toolName') continue;
            const prop = properties[key];
            if (!prop) continue;

            const schema = typeof prop === 'string' ? JSON.parse(prop) : prop;

            if (schema.type === 'integer') {
                payload[key] = parseInt(value, 10);
            } else if (schema.type === 'boolean') {
                payload[key] = value === '1' || value === 'true';
            } else if (schema.type === 'number') {
                payload[key] = parseFloat(value);
            } else {
                try {
                    payload[key] = JSON.parse(value);
                } catch {
                    payload[key] = value;
                }
            }
        }

        const card = form.closest('.card');
        const spinner = card ? card.querySelector('[data-webmcp--toolbox-target="spinner"]') : null;
        const runButton = card ? card.querySelector('[data-webmcp--toolbox-target="runButton"]') : null;

        if (spinner) spinner.style.display = '';
        if (runButton) runButton.disabled = true;

        try {
            let result;
            // WebMCP executeTool expects a JSON string for input arguments, not an object
            const inputArgs = JSON.stringify(payload);
            if (document.modelContext?.executeTool) {
                result = await document.modelContext.executeTool(tool, inputArgs);
            } else {
                result = await tool.execute(payload, {});
            }
            const parsed = typeof result === 'string' ? JSON.parse(result) : result;

            if (parsed.error) {
                const message = parsed.message || parsed.error;
                const violations = Array.isArray(parsed.violations) && parsed.violations.length > 0
                    ? ` (${parsed.violations.map((v) => v.message).join('; ')})`
                    : '';
                window.dispatchEvent(new CustomEvent('webmcp:toast', { detail: { type: 'error', message: `${message}${violations}` } }));
            } else {
                const summary = this.summarizeResult(toolName, parsed);
                window.dispatchEvent(new CustomEvent('webmcp:toast', { detail: { type: 'success', message: summary } }));
            }
        } catch (e) {
            window.dispatchEvent(new CustomEvent('webmcp:toast', {
                detail: { type: 'error', message: e.message || String(e) },
            }));
        } finally {
            if (spinner) spinner.style.display = 'none';
            if (runButton) runButton.disabled = false;
        }
    }

    summarizeResult(toolName, result) {
        if (toolName === 'wishlist.list' && Array.isArray(result.wishlists)) {
            return `Found ${result.wishlists.length} wishlist(s)`;
        }
        if (toolName === 'wishlist.get' && result.wishlist) {
            return `Wishlist "${result.wishlist.name}" — ${(result.wishlist.items || []).length} item(s)`;
        }
        if (toolName === 'wishlist.create' && result.id) {
            return `Created wishlist "${result.name}" (id: ${result.id})`;
        }
        if (toolName === 'wishlist.remove_item' && result.wishlist) {
            return `Removed item — wishlist now has ${(result.wishlist.items || []).length} item(s)`;
        }
        if (toolName === 'product.search' && Array.isArray(result.products)) {
            return `Found ${result.count ?? result.products.length} product(s)`;
        }
        if (result.cartUrl) {
            return `Cart created — ${result.cartUrl}`;
        }
        return `Tool "${toolName}" executed`;
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
