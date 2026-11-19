import { createTuitionState } from './libreria-tuition.js';
import { createGenerationState } from './libreria-generation.js';

function emptyClient() {
    return { id: null, academicResponsible: '', name: 'Sin seleccionar', documents: {}, documentType: '', document: '', nrc: '', student: '', address: '', department: '', district: '', municipality: '', email: '', phone: '', phone2: '', activity: '' };
}

function clientFromCatalog(client, student = '') {
    const documents = { ...client.documents };
    const documentType = String(documents['13'] ?? '').trim() ? '13' : '36';
    return {
        ...emptyClient(),
        ...Object.fromEntries(Object.entries(client).map(([key, value]) => [key, value ?? ''])),
        documents, student, documentType, document: String(documents[documentType] ?? '').trim(),
    };
}

export function createSaleWorkspace(options = {}) {
    const initialCode = (catalog, preferred) => options[catalog]?.find(option => option.codigo === preferred)?.codigo || options[catalog]?.[0]?.codigo || '';
    const initialClient = () => options.defaultClient?.id ? clientFromCatalog(options.defaultClient) : emptyClient();
    const workspace = {
        saleCatalogs: options,
        items: [],
        catalog: [],
        catalogLoading: false,
        catalogError: '',
        catalogConfigurationError: '',
        catalogPage: 1,
        catalogPages: 1,
        catalogTotal: 0,
        catalogRequestSequence: 0,
        quickProductSequence: 0,
        quickProductLoading: false,
        packageAdding: false,
        packageRequestSequence: 0,
        stockChecking: false,
        stockCheckSequence: 0,
        catalogCategory: 'productos',
        search: '',
        quickSearch: '',
        historySearch: '',
        requestSource: 'Registro',
        document: { type: initialCode('documents', '01'), date: '', thirdPartyType: '', thirdPartyDocument: '', account: '11010101', creditAccount: 'Clientes varios', discount: 0 },
        client: initialClient(),
        clientIsDefault: Boolean(options.defaultClient?.id),
        clientDraft: {},
        clientSearch: '',
        clientResults: [],
        clientLoading: false,
        clientError: '',
        clientRequestSequence: 0,
        observation: '',
        operation: initialCode('operations', '1'),
        installments: 1,
        term: initialCode('creditTerms', '01'),
        payments: [{ id: 1, method: initialCode('paymentMethods', '01'), amount: '', reference: '' }],
        paymentSequence: 1,
        paymentNotice: '',
        history: [],
        historySelection: null,

        init() {
            const today = new Date();
            this.document.date = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
            this.$watch('operation', value => {
                if (value !== '2') {
                    this.installments = 1;
                    this.term = initialCode('creditTerms', '01');
                }
            });
        },

        get documentLabel() {
            return this.saleCatalogs.documents?.find(option => option.codigo === this.document.type)?.valor || 'Sin seleccionar';
        },

        get hasBillingClient() {
            return Boolean(this.client.id || this.client.academicResponsible);
        },

        get needsFiscalClient() {
            return this.document.type === '03' && (!this.client.id
                || String(this.client.id) === String(this.saleCatalogs.defaultClient?.id)
                || this.normalize(this.client.name).trim().replace(/\s+/g, ' ') === 'clientes varios');
        },

        requireFiscalClientChange() {
            if (!this.needsFiscalClient) return false;
            this.$flux.modal('sale-credit-client').show();
            return true;
        },

        chooseFiscalClient() {
            this.clientDraft = { ...this.client, documents: { ...this.client.documents } };
            this.$flux.modal('sale-credit-client').close();
            this.$flux.modal('sale-payment').close();
            this.openClientPicker();
        },

        get hasBillingDraft() {
            return Boolean(this.clientDraft.id || this.clientDraft.academicResponsible);
        },

        get availableDistricts() {
            return (this.saleCatalogs.geography?.districts || []).filter(district => district.department === this.clientDraft.department);
        },

        get draftMunicipalityName() {
            return this.availableDistricts.find(district => district.codigo === this.clientDraft.district)?.municipalityName || '';
        },

        get hasKnownActivity() {
            return (this.saleCatalogs.economicActivities || []).some(activity => activity.codigo === this.clientDraft.activity);
        },

        changeDraftDepartment() {
            this.clientDraft.district = '';
            this.clientDraft.municipality = '';
        },

        syncDraftLocation() {
            const district = this.availableDistricts.find(district => district.codigo === String(this.clientDraft.district));
            this.clientDraft.district = district?.codigo || '';
            this.clientDraft.municipality = district?.municipalityCode || '';
        },

        changeClientDocument() {
            this.clientDraft.document = String(this.clientDraft.documents?.[this.clientDraft.documentType] ?? '').trim();
        },

        money(cents) {
            return new Intl.NumberFormat('es-SV', { style: 'currency', currency: 'USD' }).format((Number(cents) || 0) / 100);
        },

        normalize(value) {
            return String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        },

        get filteredCatalog() {
            return this.catalog;
        },

        get filteredHistory() {
            const search = this.normalize(this.historySearch);
            return this.history.filter(sale => this.normalize(`${sale.id} ${sale.client} ${sale.status}`).includes(search));
        },

        lineSubtotal(item) {
            if (item.tuition) return item.tuition.amount;
            return Math.round(Math.max(0, Number(item.quantity) || 0) * item.price);
        },

        lineDiscount(item) {
            if (!this.canDiscount(item)) return 0;
            return Math.round(this.lineSubtotal(item) * Math.min(100, Math.max(0, Number(item.discount) || 0)) / 100);
        },

        lineGeneralDiscount(item) {
            if (!this.canDiscount(item)) return 0;
            const amount = this.lineSubtotal(item) - this.lineDiscount(item);
            return Math.round(amount * Math.min(100, Math.max(0, Number(this.document.discount) || 0)) / 100);
        },

        lineTotal(item) {
            return this.lineSubtotal(item) - this.lineDiscount(item) - this.lineGeneralDiscount(item);
        },

        get subtotal() {
            return this.items.reduce((total, item) => total + this.lineSubtotal(item), 0);
        },

        get discount() {
            const lineDiscounts = this.items.reduce((total, item) => total + this.lineDiscount(item), 0);
            const discountable = this.items.filter(item => this.canDiscount(item)).reduce((sum, item) => sum + this.lineSubtotal(item) - this.lineDiscount(item), 0);
            return lineDiscounts + Math.round(discountable * Math.min(100, Math.max(0, Number(this.document.discount) || 0)) / 100);
        },

        get total() {
            if (this.quotedTotal !== null && this.quotedSnapshot === JSON.stringify(this.generationBase())) return this.quotedTotal;
            return this.subtotal - this.discount;
        },

        canDiscount(item) {
            return !item.tuition && item.discountAllowed === true;
        },

        canEditPrice(item) {
            return !item.tuition && item.priceType === 'V';
        },

        changeProductPrice(item, value, notify = true) {
            if (!this.canEditPrice(item)) return false;
            const price = String(value).trim();
            if (!/^(?:\d+(?:\.\d{0,6})?|\.\d{1,6})$/.test(price) || Number(price) > 99999.999999) {
                item.priceError = 'Introduce un precio sin IVA entre 0 y 99999.999999, con hasta seis decimales.';
                if (notify) this.showStockNotice(item.priceError);
                return false;
            }
            item.netPrice = price;
            item.price = Number(price) * (item.taxable ? 113 : 100);
            item.priceError = '';
            this.paymentNotice = '';
            return true;
        },

        get units() {
            return this.items.reduce((total, item) => total + (Number(item.quantity) || 0), 0);
        },

        get paid() {
            return this.payments.reduce((total, payment) => total + Math.round(Math.max(0, Number(payment.amount) || 0) * 100), 0);
        },

        get remaining() {
            return Math.max(0, this.total - this.paid);
        },

        get cashPaid() {
            return this.payments.filter(payment => payment.method === '01').reduce((total, payment) => total + Math.round(Math.max(0, Number(payment.amount) || 0) * 100), 0);
        },

        get change() {
            return Math.min(this.cashPaid, Math.max(0, this.paid - this.total));
        },

        openCatalog(category = 'productos', search = '') {
            if (!['productos', 'paquetes'].includes(category)) {
                return;
            }
            this.catalogCategory = category;
            this.search = search;
            this.$flux.modal('sale-catalog').show();
            return this.searchCatalog();
        },

        async productRequest(parameters, endpoint = this.saleCatalogs.productSearchUrl) {
            const url = new URL(endpoint, window.location.origin);
            for (const [key, value] of Object.entries(parameters)) url.searchParams.set(key, value);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('No se pudieron consultar los productos.');
            return response.json();
        },

        async searchCatalog(page = 1) {
            const sequence = ++this.catalogRequestSequence;
            this.catalog = [];
            this.catalogError = '';
            this.catalogLoading = false;
            this.catalogPage = page;
            this.catalogPages = 1;
            this.catalogTotal = 0;
            const endpoint = this.catalogCategory === 'paquetes' ? this.saleCatalogs.packageSearchUrl : this.saleCatalogs.productSearchUrl;
            if (!endpoint) {
                this.catalogError = 'Este catálogo no está disponible.';
                return;
            }
            this.catalogLoading = true;
            try {
                const result = await this.productRequest({ q: this.search.trim(), page }, endpoint);
                if (sequence !== this.catalogRequestSequence) return;
                this.catalog = result.data;
                this.catalogPage = result.meta.current_page;
                this.catalogPages = result.meta.last_page;
                this.catalogTotal = result.meta.total;
            } catch {
                if (sequence === this.catalogRequestSequence) this.catalogError = 'No se pudo cargar el catálogo. Intenta nuevamente.';
            } finally {
                if (sequence === this.catalogRequestSequence) this.catalogLoading = false;
            }
        },

        addProduct(product, quantityToAdd = null) {
            if (product.canSelect === false) return;
            const item = this.items.find(item => item.id === product.id);
            const stock = product.stock || item?.stock;
            const available = this.stockLimit({ ...product, stock });
            const increment = quantityToAdd ?? (item ? 1 : Math.min(1, available));
            const quantity = Math.round(((Number(item?.quantity) || 0) + increment) * 100) / 100;
            if (!item?.tuition && (!Number.isFinite(increment) || increment <= 0 || quantity <= 0 || quantity > available)) {
                this.showStockNotice(`No puedes agregar esa cantidad de ${product.name}. Disponible en bodega principal: ${available}.`);
                return;
            }
            if (item) {
                if (!item.tuition) {
                    item.quantity = quantity;
                    if (stock) item.stock = { ...stock };
                }
            } else {
                this.items.push({ ...product, quantity, discount: 0 });
                this.$nextTick(() => {
                    const container = this.$refs.productScroll;
                    container.scrollTo({
                        top: container.scrollHeight,
                        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
                    });
                });
            }
            this.paymentNotice = '';
        },

        async addPackage(selected) {
            if (this.packageAdding || !selected.canSelect) return;
            const sequence = ++this.packageRequestSequence;
            this.packageAdding = true;
            try {
                const response = await fetch(selected.detailUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = await response.json();
                if (sequence !== this.packageRequestSequence) return;
                if (!response.ok) {
                    this.showStockNotice(Object.values(data.errors || {}).flat().join(' ') || 'No se pudieron cargar los productos del paquete.');
                    return;
                }
                if (!data.products?.length) {
                    this.showStockNotice('El paquete no tiene productos configurados.');
                    return;
                }
                const errors = [];
                for (const product of data.products) {
                    const existing = this.items.find(item => item.id === product.id);
                    const requested = Math.round(((Number(existing?.quantity) || 0) + Number(product.quantity)) * 100) / 100;
                    if (!product.canSelect || !Number.isFinite(product.quantity) || product.quantity <= 0 || requested > this.stockLimit(product)) {
                        errors.push(`${product.name}: ${product.configurationError || 'la cantidad del paquete y de la venta supera las existencias disponibles.'}`);
                    }
                }
                if (errors.length) {
                    this.showStockNotice(errors.join('\n'));
                    return;
                }
                for (const product of data.products) this.addProduct(product, product.quantity);
            } catch {
                if (sequence === this.packageRequestSequence) this.showStockNotice('No se pudo cargar el paquete. Intenta nuevamente.');
            } finally {
                if (sequence === this.packageRequestSequence) this.packageAdding = false;
            }
        },

        showStockNotice(message) {
            this.catalogConfigurationError = message;
            this.$flux.modal('sale-product-configuration').show();
        },

        stockLimit(product) {
            return product.productType === 'B' ? Math.floor(Math.round(Number(product.stock?.total || 0) * 10000) / 100) / 100 : Infinity;
        },

        changeProductQuantity(item) {
            const requested = Math.max(0.01, Number(item.quantity) || 1);
            const available = this.stockLimit(item);
            item.quantity = item.tuition ? 1 : Math.min(requested, available);
            if (!item.tuition && requested > available) {
                this.showStockNotice(`Disponible en bodega principal para ${item.name}: ${available}. Se ajustó la cantidad al saldo disponible.`);
            }
        },

        async quickAdd() {
            if (this.quickProductLoading) return;
            const search = this.quickSearch.trim();
            if (!search) {
                return this.openCatalog();
            }
            if (search.length > 64 || !this.saleCatalogs.productSearchUrl) {
                return this.openCatalog('productos', search);
            }
            const sequence = ++this.quickProductSequence;
            this.quickProductLoading = true;
            try {
                const result = await this.productRequest({ code: search });
                if (sequence !== this.quickProductSequence) return;
                const product = result.data.find(product => this.normalize(product.code) === this.normalize(search));
                if (product?.canSelect) {
                    this.addProduct(product);
                } else {
                    await this.openCatalog('productos', search);
                }
                if (this.quickSearch.trim() === search) this.quickSearch = '';
            } catch {
                if (sequence === this.quickProductSequence) {
                    await this.openCatalog('productos', search);
                }
            } finally {
                if (sequence === this.quickProductSequence) this.quickProductLoading = false;
            }
        },

        removeProduct(id) {
            this.items = this.items.filter(item => item.id !== id);
            this.paymentNotice = '';
        },

        editClient() {
            this.clientDraft = { ...this.client, documents: { ...this.client.documents } };
            this.syncDraftLocation();
            if (!this.hasBillingClient) {
                this.openClientPicker();
                return;
            }
            this.$flux.modal('sale-client').show();
        },

        openClientPicker() {
            this.$flux.modal('sale-client').close();
            this.clientSearch = '';
            this.clientResults = [];
            this.clientError = '';
            this.$nextTick(() => this.$flux.modal('sale-client-picker').show());
            this.searchClients();
        },

        clientPickerClosed() {
            if (this.hasBillingDraft) {
                this.$nextTick(() => this.$flux.modal('sale-client').show());
            }
        },

        async searchClients() {
            if (!this.saleCatalogs.clientSearchUrl) return;
            const sequence = ++this.clientRequestSequence;
            this.clientLoading = true;
            this.clientError = '';
            try {
                const url = new URL(this.saleCatalogs.clientSearchUrl, window.location.origin);
                url.searchParams.set('q', this.clientSearch.trim());
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error('No se pudieron consultar los clientes.');
                const result = await response.json();
                if (sequence === this.clientRequestSequence) this.clientResults = result.data;
            } catch {
                if (sequence === this.clientRequestSequence) {
                    this.clientResults = [];
                    this.clientError = 'No se pudieron cargar los clientes. Intenta buscar nuevamente.';
                }
            } finally {
                if (sequence === this.clientRequestSequence) this.clientLoading = false;
            }
        },

        selectClient(client) {
            this.clientDraft = clientFromCatalog(client, this.client.student);
            this.syncDraftLocation();
            this.$flux.modal('sale-client-picker').close();
        },

        saveClient() {
            if (!this.hasBillingDraft || !this.clientDraft.name.trim()) {
                return;
            }
            this.syncDraftLocation();
            this.applyBillingClient(this.clientDraft);
            this.$flux.modal('sale-client').close();
        },

        applyBillingClient(client) {
            if (this.client.id !== client.id || this.client.academicResponsible !== client.academicResponsible) {
                this.document.thirdPartyType = '';
                this.document.thirdPartyDocument = '';
            }
            this.client = { ...client, documents: { ...client.documents }, name: client.name.trim() };
            this.clientIsDefault = false;
        },

        clientFromTuition() {
            if (!this.tuitionStudent || !this.tuitionBilling || this.tuitionChoosingStudent || this.tuitionStudentLoading) return null;
            const billing = this.tuitionBilling;
            const catalogCode = (options, value) => {
                const text = String(value ?? '').trim();
                if (!text) return '';
                return options.find(option => String(option.codigo) === text)?.codigo
                    || options.find(option => /^\d+$/.test(text) && Number(option.codigo) === Number(text))?.codigo
                    || options.find(option => this.normalize(option.valor.trim()) === this.normalize(text))?.codigo || '';
            };
            const department = catalogCode(this.saleCatalogs.geography?.departments || [], billing.department);
            const districts = (this.saleCatalogs.geography?.districts || []).filter(district => district.department === department);
            const district = catalogCode(districts, billing.district);
            const documentType = String(billing.documentType || '');
            return {
                ...emptyClient(),
                ...billing,
                id: null,
                academicResponsible: `${this.tuitionStudent.id}:${this.tuitionResponsibleType}`,
                name: billing.name?.trim() || 'Clientes varios',
                documents: documentType ? { [documentType]: billing.document || '' } : {},
                documentType,
                document: billing.document || '',
                department,
                district,
                municipality: districts.find(option => option.codigo === district)?.municipalityCode || '',
            };
        },

        editTuitionClient() {
            const client = this.clientFromTuition();
            if (!client) return;
            this.clientDraft = this.client.academicResponsible === client.academicResponsible
                ? { ...this.client, documents: { ...this.client.documents } }
                : client;
            this.$flux.modal('sale-client').show();
        },

        async openPayment() {
            if (this.generating || this.quoteLoading || this.generated) return;
            if (this.requireFiscalClientChange()) return;
            const priceErrors = [...new Set(this.items.map(item => item.priceError).filter(Boolean))];
            if (priceErrors.length) {
                this.showStockNotice(priceErrors.join(' '));
                return;
            }
            if (this.stockChecking || this.packageAdding || !this.hasBillingClient || !this.items.length || this.items.some(item => !(Number(item.quantity) > 0))) {
                return;
            }
            if (this.saleCatalogs.stockCheckUrl && !await this.checkStock()) return;
            if (this.saleCatalogs.quoteUrl && !await this.quoteSale()) return;
            this.paymentNotice = '';
            this.$flux.modal('sale-payment').show();
        },

        async checkStock() {
            const sequence = ++this.stockCheckSequence;
            const documentType = this.document.type;
            const items = this.items.map(item => ({ productId: item.productId, quantity: Number(item.quantity) }));
            this.stockChecking = true;
            try {
                const response = await fetch(this.saleCatalogs.stockCheckUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector?.('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ documentType, items }),
                });
                if (sequence !== this.stockCheckSequence) return false;
                const data = await response.json();
                if (sequence !== this.stockCheckSequence) return false;
                if (!response.ok) {
                    this.showStockNotice(Object.values(data.errors || {}).flat().join(' ') || 'No se pudo comprobar la disponibilidad. Intenta nuevamente.');
                    return false;
                }
                const current = this.items.map(item => ({ productId: item.productId, quantity: Number(item.quantity) }));
                return JSON.stringify(current) === JSON.stringify(items) && this.document.type === documentType && this.hasBillingClient;
            } catch {
                if (sequence === this.stockCheckSequence) this.showStockNotice('No se pudo comprobar la disponibilidad. Intenta nuevamente.');
                return false;
            } finally {
                if (sequence === this.stockCheckSequence) this.stockChecking = false;
            }
        },

        addPayment() {
            this.payments.push({ id: ++this.paymentSequence, method: initialCode('paymentMethods', '02'), amount: this.remaining ? (this.remaining / 100).toFixed(2) : '', reference: '' });
        },

        exactPayment() {
            if (!this.saleCatalogs.paymentMethods?.some(method => method.codigo === '01')) return;
            this.payments = [{ id: ++this.paymentSequence, method: '01', amount: (this.total / 100).toFixed(2), reference: '' }];
        },

        newSale() {
            if (this.generating || this.quoteLoading) return;
            this.generated = null;
            this.requestKey = null;
            this.quotedTotal = null;
            this.quotedSnapshot = '';
            ++this.packageRequestSequence;
            this.packageAdding = false;
            ++this.stockCheckSequence;
            this.stockChecking = false;
            ++this.quickProductSequence;
            ++this.catalogRequestSequence;
            this.quickProductLoading = false;
            this.quickSearch = '';
            this.catalog = [];
            this.catalogLoading = false;
            this.catalogError = '';
            this.items = [];
            this.client = initialClient();
            this.clientIsDefault = Boolean(options.defaultClient?.id);
            this.clientDraft = {};
            this.observation = '';
            this.document.discount = 0;
            this.document.thirdPartyDocument = '';
            this.document.thirdPartyType = '';
            this.operation = initialCode('operations', '1');
            this.installments = 1;
            this.term = initialCode('creditTerms', '01');
            this.payments = [{ id: ++this.paymentSequence, method: initialCode('paymentMethods', '01'), amount: '', reference: '' }];
            this.paymentNotice = '';
            this.resetTuition();
            this.$flux.modal('sale-reset').close();
        },
    };

    Object.defineProperties(workspace, Object.getOwnPropertyDescriptors(createGenerationState()));
    return Object.defineProperties(workspace, Object.getOwnPropertyDescriptors(createTuitionState()));
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('libreriaSale', createSaleWorkspace);
});
