import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {} };
globalThis.window = { location: { origin: 'https://example.test' } };
const { createSaleWorkspace } = await import('./libreria-sale.js');

function sale() {
    const workspace = createSaleWorkspace({
        productSearchUrl: '/libreria/productos',
        documents: [{ codigo: '01', valor: 'Factura' }, { codigo: '03', valor: 'Crédito fiscal' }],
    });
    workspace.$nextTick = () => {};
    workspace.$flux = { modal: () => ({ show() {}, close() {} }) };
    return workspace;
}

function product(overrides = {}) {
    return {
        id: 'product-17', productId: 17, code: 'P-001', name: 'Ábaco', category: 'productos', productType: 'B',
        stock: { warehouseId: 10, consigned: '3.0000', own: '7.0000', total: '10.0000' },
        price: 414.999958, netPrice: '3.672566', taxable: true, taxType: 'A', priceType: 'F', unit: '59',
        origin: 'LIBRERIA', incomeAccount: '5101', expenseAccount: '6101', inventoryAccount: '1101',
        discountAllowed: true, canSelect: true, configurationError: null, ...overrides,
    };
}

function results(products = [product()], meta = {}) {
    return { data: products, meta: { current_page: 1, last_page: 1, total: products.length, ...meta } };
}

test('the sale starts empty and fetching products uses the authenticated endpoint and server pagination', async t => {
    const workspace = sale();
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    let request;
    globalThis.fetch = async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => results([product()], { current_page: 2, last_page: 4, total: 61 }) };
    };
    workspace.search = 'Ábaco';
    assert.deepEqual(workspace.items, []);
    assert.deepEqual(workspace.catalog, []);

    await workspace.searchCatalog(2);

    assert.equal(request.url.pathname, '/libreria/productos');
    assert.equal(request.url.searchParams.get('q'), 'Ábaco');
    assert.equal(request.url.searchParams.get('page'), '2');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(request.options.headers.Accept, 'application/json');
    assert.equal(workspace.catalogPage, 2);
    assert.equal(workspace.catalogPages, 4);
    assert.equal(workspace.catalogTotal, 61);
    workspace.addProduct(workspace.catalog[0]);
    assert.equal(workspace.items[0].productId, 17);
    assert.equal(workspace.items[0].netPrice, '3.672566');
    assert.equal(workspace.items[0].incomeAccount, '5101');
    assert.equal(workspace.items[0].inventoryAccount, '1101');
    assert.equal(workspace.items[0].expenseAccount, '6101');
    assert.equal(workspace.items[0].priceType, 'F');
    assert.equal(workspace.items[0].unit, '59');
    assert.equal(workspace.lineTotal(workspace.items[0]), 415);
});

test('reselecting a catalog product increments quantity and preserves the original line price and metadata', () => {
    const workspace = sale();
    workspace.addProduct(product());
    workspace.addProduct(product({ price: 999, incomeAccount: '9999' }));

    assert.equal(workspace.items.length, 1);
    assert.equal(workspace.items[0].quantity, 2);
    assert.equal(workspace.items[0].incomeAccount, '5101');
    assert.equal(workspace.lineTotal(workspace.items[0]), 830);
});

test('unit precision is retained until the line amount is calculated and discounts follow the product configuration', () => {
    const workspace = sale();
    workspace.addProduct(product({ price: 4.4248, netPrice: '0.044248', taxable: false, discountAllowed: false }));
    workspace.items[0].quantity = 100;
    workspace.items[0].discount = 100;
    workspace.document.discount = 50;

    assert.equal(workspace.lineSubtotal(workspace.items[0]), 442);
    assert.equal(workspace.lineDiscount(workspace.items[0]), 0);
    assert.equal(workspace.total, 442);
    workspace.addProduct(product({ id: 'product-18', productId: 18, price: 1000 }));
    assert.equal(workspace.discount, 500);
    assert.equal(workspace.total, 942);
});

test('products marked as incomplete cannot be added through the catalog action', () => {
    const workspace = sale();
    workspace.addProduct(product({ canSelect: false, configurationError: 'Falta cuenta de ingreso.' }));

    assert.deepEqual(workspace.items, []);
});

test('general discounts apply to existing and newly added eligible products without discounting other products', () => {
    const workspace = sale();
    workspace.addProduct(product({ price: 1000, netPrice: '10', taxable: false }));
    workspace.addProduct(product({ id: 'blocked', productId: 18, price: 500, discountAllowed: false }));

    workspace.document.discount = 20;

    assert.equal(workspace.lineTotal(workspace.items[0]), 800);
    assert.equal(workspace.lineTotal(workspace.items[1]), 500);
    assert.equal(workspace.discount, 200);
    assert.equal(workspace.total, 1300);

    workspace.addProduct(product({ id: 'new', productId: 19, price: 2000 }));

    assert.equal(workspace.lineTotal(workspace.items[2]), 1600);
    assert.equal(workspace.discount, 600);
    assert.equal(workspace.total, 2900);
    assert.equal(workspace.generationBase().document.discount, 20);
    assert.deepEqual(workspace.generationBase().items.map(item => item.discount), [0, 0, 0]);
});

test('discounted amounts recalculate after quantity price and discount changes and invalidate previous quotes', async () => {
    const workspace = sale();
    workspace.saleCatalogs.quoteUrl = '/quote';
    workspace.document.discount = 10;
    workspace.addProduct(product({ priceType: 'V', price: 10000, netPrice: '100', taxable: false }));
    const line = workspace.items[0];
    line.discount = 20;
    workspace.postGeneration = async () => ({ total: '72.00' });
    await workspace.quoteSale();
    assert.equal(workspace.total, 7200);

    workspace.document.discount = 25;

    assert.equal(workspace.lineTotal(line), 6000);
    assert.equal(workspace.total, 6000);
    line.quantity = 2;
    assert.equal(workspace.lineTotal(line), 12000);
    assert.equal(workspace.total, 12000);
    workspace.changeProductPrice(line, '120');
    assert.equal(workspace.lineTotal(line), 14400);
    assert.equal(workspace.total, 14400);
    line.discount = 0;
    assert.equal(workspace.lineTotal(line), 18000);
    assert.equal(workspace.total, 18000);
    workspace.document.discount = 0;
    assert.equal(workspace.lineTotal(line), 24000);
    assert.equal(workspace.discount, 0);
    assert.equal(workspace.total, 24000);
});

test('variable sale prices update net and tax inclusive amounts without changing the catalog price', () => {
    const workspace = sale();
    const catalogProduct = product({ priceType: 'V' });
    workspace.addProduct(catalogProduct);
    const line = workspace.items[0];

    assert.equal(workspace.changeProductPrice(line, '10.25'), true);
    assert.equal(line.netPrice, '10.25');
    assert.equal(line.price, 1158.25);
    assert.equal(workspace.total, 1158);
    assert.equal(catalogProduct.netPrice, '3.672566');
    workspace.addProduct(catalogProduct);
    assert.equal(line.quantity, 2);
    assert.equal(line.netPrice, '10.25');
    assert.equal(workspace.total, 2317);
});

test('variable exempt service prices preserve six decimals without applying VAT', () => {
    const workspace = sale();
    workspace.addProduct(product({ priceType: 'V', productType: 'S', stock: null, taxable: false, taxType: 'E' }));

    workspace.changeProductPrice(workspace.items[0], '2.123456');

    assert.equal(workspace.items[0].netPrice, '2.123456');
    assert.equal(workspace.items[0].price, 212.3456);
    assert.equal(workspace.total, 212);
});

test('fixed prices and tuition fees cannot be edited through the price action', () => {
    const workspace = sale();
    workspace.addProduct(product());
    const fixed = workspace.items[0];
    const original = fixed.price;
    assert.equal(workspace.canEditPrice(fixed), false);
    assert.equal(workspace.changeProductPrice(fixed, '1'), false);
    assert.equal(fixed.price, original);
    const tuition = { ...product(), priceType: 'V', tuition: { amount: 7500 } };
    assert.equal(workspace.canEditPrice(tuition), false);
    assert.equal(workspace.changeProductPrice(tuition, '1'), false);
    assert.equal(workspace.lineSubtotal(tuition), 7500);
});

test('invalid variable prices preserve the last valid value and block payment until corrected', async () => {
    const workspace = sale();
    workspace.client = { ...workspace.client, id: 44 };
    workspace.addProduct(product({ priceType: 'V' }));
    const line = workspace.items[0];
    const original = line.price;
    const opened = [];
    workspace.$flux.modal = name => ({ show: () => opened.push(name), close() {} });

    for (const invalid of ['', '-1', 'texto', '1.1234567', '100000', 'Infinity']) {
        assert.equal(workspace.changeProductPrice(line, invalid, false), false);
        assert.equal(line.price, original);
    }
    await workspace.openPayment();
    assert.deepEqual(opened, ['sale-product-configuration']);
    workspace.changeProductPrice(line, '0');
    assert.equal(line.priceError, '');
    assert.equal(workspace.total, 0);
    workspace.changeProductPrice(line, '.5');
    assert.equal(line.netPrice, '.5');
    assert.equal(workspace.total, 57);
});

test('only an explicit discount permission allows line and general discounts', () => {
    const workspace = sale();
    workspace.addProduct(product({ price: 1000, discountAllowed: true }));
    workspace.addProduct(product({ id: 'product-18', productId: 18, price: 1000, discountAllowed: false }));
    workspace.addProduct(product({ id: 'product-19', productId: 19, price: 1000, discountAllowed: undefined }));
    for (const line of workspace.items) line.discount = 10;
    workspace.document.discount = 20;

    assert.equal(workspace.lineDiscount(workspace.items[0]), 100);
    assert.equal(workspace.lineDiscount(workspace.items[1]), 0);
    assert.equal(workspace.lineDiscount(workspace.items[2]), 0);
    assert.equal(workspace.discount, 280);
    assert.equal(workspace.total, 2720);
});

test('quick addition finds an exact real code without relying on the currently displayed catalog page', async () => {
    const workspace = sale();
    const requests = [];
    workspace.quickSearch = 'p-001';
    workspace.productRequest = async parameters => {
        requests.push(parameters);
        return results();
    };

    await workspace.quickAdd();

    assert.deepEqual(requests, [{ code: 'p-001' }]);
    assert.equal(workspace.items.length, 1);
    assert.equal(workspace.items[0].productId, 17);
    assert.equal(workspace.quickSearch, '');
    assert.equal(workspace.quickProductLoading, false);
});

test('a product description opens search results for manual selection instead of adding an arbitrary match', async () => {
    const workspace = sale();
    const opened = [];
    workspace.$flux.modal = name => ({ show: () => opened.push(name), close() {} });
    workspace.quickSearch = 'abaco';
    workspace.productRequest = async parameters => parameters.code ? results([]) : results();

    await workspace.quickAdd();

    assert.deepEqual(workspace.items, []);
    assert.deepEqual(opened, ['sale-catalog']);
    assert.equal(workspace.search, 'abaco');
    assert.equal(workspace.catalog[0].name, 'Ábaco');
});

test('failed product requests clear previous results and a retry restores the catalog', async () => {
    const workspace = sale();
    workspace.catalog = [product()];
    workspace.productRequest = async () => { throw new Error('Unavailable'); };

    await workspace.searchCatalog();

    assert.deepEqual(workspace.catalog, []);
    assert.ok(workspace.catalogError);
    assert.equal(workspace.catalogLoading, false);
    workspace.productRequest = async () => results();
    await workspace.searchCatalog();
    assert.equal(workspace.catalogError, '');
    assert.equal(workspace.catalog.length, 1);
});

test('an older search response cannot replace the latest results or loading state', async () => {
    const workspace = sale();
    let completeFirst;
    workspace.productRequest = () => new Promise(resolve => { completeFirst = resolve; });
    const first = workspace.searchCatalog();
    workspace.search = 'Cuaderno';
    workspace.productRequest = async () => results([product({ name: 'Cuaderno' })]);
    await workspace.searchCatalog();

    completeFirst(results());
    await first;

    assert.equal(workspace.catalog[0].name, 'Cuaderno');
    assert.equal(workspace.catalogLoading, false);
});

test('switching to packages discards an outstanding product response and never shows sample products', async () => {
    const workspace = sale();
    let complete;
    workspace.productRequest = () => new Promise(resolve => { complete = resolve; });
    const pending = workspace.searchCatalog();

    await workspace.openCatalog('paquetes');
    complete(results());
    await pending;

    assert.equal(workspace.catalogCategory, 'paquetes');
    assert.deepEqual(workspace.catalog, []);
    assert.equal(workspace.catalogLoading, false);
});

test('duplicate quick submissions and responses arriving after a new sale cannot add unwanted products', async () => {
    const workspace = sale();
    let complete;
    let requests = 0;
    workspace.productRequest = () => { requests++; return new Promise(resolve => { complete = resolve; }); };
    workspace.quickSearch = 'P-001';
    const pending = workspace.quickAdd();
    await workspace.quickAdd();
    assert.equal(requests, 1);

    workspace.newSale();
    complete(results());
    await pending;

    assert.deepEqual(workspace.items, []);
    assert.equal(workspace.quickProductLoading, false);
    assert.equal(workspace.quickSearch, '');
});

test('repeated additions and manual quantity changes cannot exceed the displayed principal stock', () => {
    const workspace = sale();
    const opened = [];
    workspace.$flux.modal = name => ({ show: () => opened.push(name), close() {} });
    const selected = product({ stock: { warehouseId: 10, consigned: '1.0000', own: '1.0000', total: '2.0000' } });
    workspace.addProduct(selected);
    workspace.addProduct(selected);
    workspace.addProduct(selected);

    assert.equal(workspace.items[0].quantity, 2);
    assert.deepEqual(opened, ['sale-product-configuration']);
    workspace.items[0].quantity = 9;
    workspace.changeProductQuantity(workspace.items[0]);
    assert.equal(workspace.items[0].quantity, 2);
    assert.match(workspace.catalogConfigurationError, /Disponible|disponible/);
});

test('services are not limited by physical stock and fractional balances can be added', () => {
    const workspace = sale();
    workspace.addProduct(product({ productType: 'S', stock: null }));
    workspace.items[0].quantity = 100;
    workspace.changeProductQuantity(workspace.items[0]);
    assert.equal(workspace.items[0].quantity, 100);
    workspace.addProduct(product({ id: 'product-18', productId: 18, stock: { warehouseId: 10, consigned: '0.5000', own: '0.0000', total: '0.5000' } }));
    assert.equal(workspace.items[1].quantity, 0.5);
    workspace.items[1].stock.total = '2.5001';
    workspace.items[1].quantity = 3;
    workspace.changeProductQuantity(workspace.items[1]);
    assert.equal(workspace.items[1].quantity, 2.5);
});

test('the server rechecks real product identifiers and quantities before allowing the payment modal', async t => {
    const workspace = sale();
    workspace.saleCatalogs.stockCheckUrl = '/libreria/venta/disponibilidad';
    workspace.client = { ...workspace.client, id: 44 };
    workspace.addProduct(product());
    const opened = [];
    workspace.$flux.modal = name => ({ show: () => opened.push(name), close() {} });
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    let request;
    globalThis.fetch = async (url, options) => {
        request = { url, options };
        return { ok: false, json: async () => ({ errors: { items: ['Stock insuficiente para P-001.'] } }) };
    };

    await workspace.openPayment();

    assert.equal(request.options.method, 'POST');
    assert.deepEqual(JSON.parse(request.options.body), { documentType: '01', items: [{ productId: 17, quantity: 1 }] });
    assert.deepEqual(opened, ['sale-product-configuration']);
    assert.equal(workspace.catalogConfigurationError, 'Stock insuficiente para P-001.');
    assert.equal(workspace.stockChecking, false);
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ allocation: [] }) });
    await workspace.openPayment();
    assert.equal(opened.at(-1), 'sale-payment');
});

test('a stock response for a modified or reset sale cannot open payment or overwrite the new sale', async t => {
    const workspace = sale();
    workspace.saleCatalogs.stockCheckUrl = '/libreria/venta/disponibilidad';
    workspace.client = { ...workspace.client, id: 44 };
    workspace.addProduct(product());
    const opened = [];
    workspace.$flux.modal = name => ({ show: () => opened.push(name), close() {} });
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    let complete;
    globalThis.fetch = () => new Promise(resolve => { complete = resolve; });
    const pending = workspace.openPayment();
    workspace.items[0].quantity = 2;
    complete({ ok: true, json: async () => ({ allocation: [] }) });
    await pending;
    assert.deepEqual(opened, []);

    const previousDocument = workspace.openPayment();
    workspace.document.type = '03';
    complete({ ok: true, json: async () => ({ invoiceDocumentType: '01', inventoryDocumentType: 'DTE-01', allocation: [] }) });
    await previousDocument;
    assert.deepEqual(opened, []);
    assert.equal(workspace.document.type, '03');

    const oldSale = workspace.openPayment();
    workspace.newSale();
    complete({ ok: false, json: async () => ({ errors: { items: ['Old sale.'] } }) });
    await oldSale;
    assert.deepEqual(opened, []);
    assert.equal(workspace.stockChecking, false);
});

test('credit invoice stock checks send the fiscal code and keep it unchanged after the server maps inventory', async t => {
    const workspace = sale();
    workspace.saleCatalogs.stockCheckUrl = '/libreria/venta/disponibilidad';
    workspace.document.type = '03';
    workspace.client = { ...workspace.client, id: 44 };
    workspace.addProduct(product());
    const originalFetch = globalThis.fetch;
    t.after(() => { globalThis.fetch = originalFetch; });
    let body;
    globalThis.fetch = async (url, options) => {
        body = JSON.parse(options.body);
        return { ok: true, json: async () => ({ invoiceDocumentType: '03', inventoryDocumentType: 'DTE-03', allocation: [] }) };
    };

    await workspace.openPayment();

    assert.equal(body.documentType, '03');
    assert.equal(workspace.document.type, '03');
});
