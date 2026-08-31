import { Controller } from '@hotwired/stimulus';
import { TOOLS } from '../registry';

export default class extends Controller {
    static targets = ['modal', 'body', 'loader', 'footer'];

    connect() {
        this.modalInstance = null;
    }

    async open() {
        this.showModal();
        this.renderToolList();
    }

    close() {
        if (this.modalInstance) {
            this.modalInstance.hide();
        }
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

    renderToolList() {
        if (this.loaderTarget) {
            this.loaderTarget.remove();
        }

        const tools = TOOLS;
        if (tools.length === 0) {
            this.bodyTarget.innerHTML = '<div class="text-muted text-center py-3">No tools available.</div>';
            return;
        }

        const html = tools.map((tool) => this.renderToolCard(tool)).join('');
        this.bodyTarget.innerHTML = `<div class="row g-3">${html}</div>`;

        this.footerTarget.style.display = '';
    }

    renderToolCard(tool) {
        const properties = tool.inputSchema?.properties || {};
        const required = tool.inputSchema?.required || [];
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
                        <h6 class="card-title mb-1">${this.escapeHtml(tool.name)}${readOnlyBadge}</h6>
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
        const desc = prop.description ? `<div class="form-text">${this.escapeHtml(prop.description)}</div>` : '';

        let input;
        if (prop.type === 'boolean') {
            input = `
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="${key}" id="${toolName}-${key}" value="1">
                    <label class="form-check-label" for="${toolName}-${key}">${this.escapeHtml(key)}</label>
                </div>`;
            return `<div class="mb-2">${input}</div>`;
        }

        if (prop.type === 'integer' || prop.type === 'number') {
            const min = prop.minimum !== undefined ? `min="${prop.minimum}"` : '';
            const max = prop.maximum !== undefined ? `max="${prop.maximum}"` : '';
            const step = prop.type === 'number' ? 'step="0.01"' : '';
            const placeholder = prop.default !== undefined ? `value="${prop.default}"` : '';
            input = `<input type="number" class="form-control form-control-sm" name="${key}" id="${toolName}-${key}" ${min} ${max} ${step} ${placeholder} ${requiredAttr}>`;
        } else if (Array.isArray(prop.items)) {
            input = `<input type="text" class="form-control form-control-sm" name="${key}" id="${toolName}-${key}" placeholder='["item1","item2"]' ${requiredAttr}>`;
        } else {
            const placeholder = prop.default !== undefined ? `value="${prop.default}"` : '';
            input = `<input type="text" class="form-control form-control-sm" name="${key}" id="${toolName}-${key}" ${placeholder} ${requiredAttr}>`;
        }

        return `<div class="mb-2"><label for="${toolName}-${key}" class="form-label small mb-0">${this.escapeHtml(key)}${requiredMark}</label>${input}${desc}</div>`;
    }

    async runTool(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const toolName = formData.get('_toolName');

        const payload = {};
        for (const [key, value] of formData.entries()) {
            if (key === '_toolName') continue;
            const prop = this.findToolProperty(toolName, key);
            if (prop && prop.type === 'integer') {
                payload[key] = parseInt(value, 10);
            } else if (prop && prop.type === 'boolean') {
                payload[key] = value === '1' || value === 'true';
            } else if (prop && prop.type === 'number') {
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
            if (!document.modelContext) {
                throw new Error('WebMCP not available — enable chrome://flags/#enable-webmcp-testing');
            }
            const result = await document.modelContext.executeTool(toolName, payload);
            const parsed = typeof result === 'string' ? JSON.parse(result) : result;

            if (parsed.error) {
                window.dispatchEvent(new CustomEvent('webmcp:toast', { detail: { type: 'error', message: parsed.error } }));
            } else {
                const summary = this.summarizeResult(toolName, parsed);
                window.dispatchEvent(new CustomEvent('webmcp:toast', { detail: { type: 'success', message: summary } }));
            }
        } catch (e) {
            window.dispatchEvent(new CustomEvent('webmcp:toast', { detail: { type: 'error', message: e.message || String(e) } }));
        } finally {
            if (spinner) spinner.style.display = 'none';
            if (runButton) runButton.disabled = false;
        }
    }

    summarizeResult(toolName, result) {
        if (toolName === 'wishlist.list' && Array.isArray(result)) {
            return `Found ${result.length} wishlist(s)`;
        }
        if (toolName === 'wishlist.get' && result.wishlist) {
            return `Wishlist "${result.wishlist.name}" — ${(result.wishlist.items || []).length} item(s)`;
        }
        if (toolName === 'wishlist.create_themed' && result.id) {
            return `Created wishlist "${result.name}" (id: ${result.id})`;
        }
        if (toolName === 'product.search_themed' && Array.isArray(result)) {
            return `Found ${result.length} product(s)`;
        }
        if (result.cartUrl) {
            return `Cart created — ${result.cartUrl}`;
        }
        return `Tool "${toolName}" executed`;
    }

    findToolProperty(toolName, key) {
        const tool = TOOLS.find((t) => t.name === toolName);
        return tool?.inputSchema?.properties?.[key] || null;
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
