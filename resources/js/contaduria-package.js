function priceUnits(value) {
    const match = /^(\d+)(?:\.(\d{0,6}))?$/.exec(String(value ?? ''));
    return match ? BigInt(match[1]) * 1000000n + BigInt((match[2] || '').padEnd(6, '0')) : null;
}

function quantityUnits(value) {
    const match = /^(\d*)(?:\.(\d{0,2}))?$/.exec(String(value ?? ''));
    return match && (match[1] || match[2]) ? BigInt(match[1] || '0') * 100n + BigInt((match[2] || '').padEnd(2, '0')) : null;
}

function decimalPrice(units) {
    return `${units / 1000000n}.${String(units % 1000000n).padStart(6, '0')}`;
}

export function createPackageForm(options = {}) {
    return {
        productSearchUrl: options.productSearchUrl,
        items: (options.items || []).map((item, index) => ({ ...item, rowKey: index + 1 })),
        rowSequence: options.items?.length || 0,
        search: '', results: [], loading: false, error: '', page: 1, pages: 1, totalResults: 0,
        requestSequence: 0, notice: '',

        formatPrice(value) {
            const units = priceUnits(value);
            if (units === null) return '—';
            return `$${(units / 1000000n).toLocaleString('en-US')}.${String(units % 1000000n).padStart(6, '0')}`;
        },

        linePrice(item) {
            const price = priceUnits(item.netPrice);
            const quantity = quantityUnits(item.quantity);
            return price === null || quantity === null ? null : price * quantity;
        },

        get total() {
            const units = this.items.reduce((sum, item) => sum + (this.linePrice(item) || 0n), 0n);
            return decimalPrice((units + 50n) / 100n);
        },

        lineTotal(item) {
            const units = this.linePrice(item);
            return units === null ? '—' : this.formatPrice(decimalPrice((units + 50n) / 100n));
        },

        async openProducts() {
            this.$flux.modal('package-products').show();
            this.search = '';
            await this.searchProducts();
        },

        async searchProducts(page = 1) {
            const sequence = ++this.requestSequence;
            this.loading = true;
            this.error = '';
            this.results = [];
            try {
                const url = new URL(this.productSearchUrl, window.location.origin);
                url.searchParams.set('q', this.search.trim());
                url.searchParams.set('page', page);
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error('Could not load products.');
                const data = await response.json();
                if (sequence !== this.requestSequence) return;
                this.results = data.data;
                this.page = data.meta.current_page;
                this.pages = data.meta.last_page;
                this.totalResults = data.meta.total;
            } catch {
                if (sequence === this.requestSequence) this.error = 'No se pudieron consultar los productos. Intenta nuevamente.';
            } finally {
                if (sequence === this.requestSequence) this.loading = false;
            }
        },

        addProduct(product) {
            if (!product.canSelect) {
                this.showNotice(product.configurationError || 'Revisa la configuración del producto.');
                return;
            }
            const current = this.items.find(item => item.productId === product.productId);
            if (current) {
                const units = quantityUnits(current.quantity);
                current.quantity = units === null ? '1' : String(Number(units + 100n) / 100);
            } else if (this.items.length < 200) {
                this.items.push({ ...product, quantity: '1', rowKey: ++this.rowSequence });
            } else {
                this.showNotice('El paquete admite como máximo 200 productos.');
            }
        },

        removeProduct(rowKey) {
            this.items = this.items.filter(item => item.rowKey !== rowKey);
        },

        showNotice(message) {
            this.notice = message;
            this.$flux.modal('package-message').show();
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('contaduriaPackage', createPackageForm);
});
