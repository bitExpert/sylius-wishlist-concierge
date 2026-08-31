import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['container'];

    connect() {
        window.addEventListener('webmcp:toast', this.handleToast);
        window.addEventListener('webmcp:promotions-applied', this.handlePromotionsApplied);
    }

    disconnect() {
        window.removeEventListener('webmcp:toast', this.handleToast);
        window.removeEventListener('webmcp:promotions-applied', this.handlePromotionsApplied);
    }

    handlePromotionsApplied = (event) => {
        const { promotionsApplied = [], savedFormatted = '' } = event.detail || {};
        if (!promotionsApplied.length) return;
        const names = promotionsApplied.map((p) => p && p.name ? p.name : p).join(', ');
        this.show('success', `Promotions applied: ${names}${savedFormatted ? ` — saved ${savedFormatted}` : ''}`);
    };

    handleToast = (event) => {
        const { type, message } = event.detail || {};
        this.show(type || 'info', message || '');
    };

    show(type, message) {
        const bgClass = type === 'error' ? 'bg-danger' : type === 'success' ? 'bg-success' : 'bg-info';
        const textClass = 'text-white';

        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center ${bgClass} ${textClass} border-0`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${this.escapeHtml(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>`;

        this.containerTarget.appendChild(toastEl);

        if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
            const toast = new bootstrap.Toast(toastEl, { delay: 5000 });
            toast.show();
            toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
        } else {
            toastEl.classList.add('show');
            setTimeout(() => toastEl.remove(), 5000);
        }
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
