import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {} };
globalThis.window = { location: { origin: 'https://example.test' } };
const { createSaleWorkspace } = await import('./libreria-sale.js');

function sale() {
    const workspace = createSaleWorkspace({ productSearchUrl: '/libreria/productos', packageSearchUrl: '/libreria/paquetes' });
    workspace.$nextTick = () => {};
    workspace.shown = [];
    workspace.$flux = { modal: name => ({ show: () => workspace.shown.push(name), close() {} }) };
    return workspace;
}

function component(id, quantity = 1, overrides = {}) {
    return {
        id: `product-${id}`, productId: id, name: `Producto ${id}`, code: `P${id}`, category: 'productos',
        price: 1000, netPrice: '10', productType: 'B', taxable: false, taxType: 'E', priceType: 'F',
        discountAllowed: false, incomeAccount: '5101', inventoryAccount: '1101', expenseAccount: '6101',
        stock: { warehouseId: 10, consigned: '3.0000', own: '7.0000', total: '10.0000' },
        quantity, canSelect: true, configurationError: null, ...overrides,
    };
}

const selected = { id: 'package-1', packageId: 1, name: 'Paquete de prueba', category: 'paquetes', canSelect: true, detailUrl: '/libreria/paquetes/1' };

function respond(t, handler) {
    const original = globalThis.fetch;
    t.after(() => { globalThis.fetch = original; });
    globalThis.fetch = handler;
}

test('package searches use the package endpoint with server pagination', async t => {
    const workspace = sale();
    let request;
    respond(t, async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ data: [selected], meta: { current_page: 1, last_page: 2, total: 21 } }) };
    });

    await workspace.openCatalog('paquetes', 'útiles');

    assert.equal(request.url.pathname, '/libreria/paquetes');
    assert.equal(request.url.searchParams.get('q'), 'útiles');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(workspace.catalogPages, 2);
    assert.equal(workspace.catalogTotal, 21);
    assert.equal(workspace.filteredCatalog[0].packageId, 1);
});

test('a package adds separate products with the configured quantities and their actual sale rules', async t => {
    const workspace = sale();
    respond(t, async () => ({ ok: true, json: async () => ({ products: [
        component(1, 2),
        component(2, 1.5, { price: 1130, priceType: 'V', taxable: true, taxType: 'A', discountAllowed: true }),
    ] }) }));

    await workspace.addPackage(selected);

    assert.equal(workspace.items.length, 2);
    assert.deepEqual(workspace.items.map(item => item.quantity), [2, 1.5]);
    assert.equal(workspace.items[0].productId, 1);
    assert.equal(workspace.items[0].incomeAccount, '5101');
    assert.equal(workspace.canEditPrice(workspace.items[0]), false);
    assert.equal(workspace.canEditPrice(workspace.items[1]), true);
    assert.equal(workspace.canDiscount(workspace.items[0]), false);
    assert.equal(workspace.canDiscount(workspace.items[1]), true);
    assert.equal(workspace.total, 3695);
    workspace.removeProduct('product-1');
    assert.equal(workspace.items.length, 1);
    assert.equal(workspace.items[0].id, 'product-2');
});

test('a package merges existing products without replacing an edited variable price', async t => {
    const workspace = sale();
    workspace.addProduct(component(1, 1, { priceType: 'V' }));
    workspace.changeProductPrice(workspace.items[0], '12.5');
    respond(t, async () => ({ ok: true, json: async () => ({ products: [component(1, 3, { priceType: 'V' })] }) }));

    await workspace.addPackage(selected);

    assert.equal(workspace.items.length, 1);
    assert.equal(workspace.items[0].quantity, 4);
    assert.equal(workspace.items[0].netPrice, '12.5');
    assert.equal(workspace.total, 5000);
});

test('an insufficient cumulative stock balance prevents all package lines from being added', async t => {
    const workspace = sale();
    workspace.addProduct(component(2));
    workspace.items[0].quantity = 9;
    const original = structuredClone(workspace.items);
    respond(t, async () => ({ ok: true, json: async () => ({ products: [component(1, 1), component(2, 2)] }) }));

    await workspace.addPackage(selected);

    assert.deepEqual(workspace.items, original);
    assert.deepEqual(workspace.shown, ['sale-product-configuration']);
    assert.match(workspace.catalogConfigurationError, /Producto 2/);
});

test('an incomplete component prevents adding an otherwise valid package', async t => {
    const workspace = sale();
    respond(t, async () => ({ ok: true, json: async () => ({ products: [
        component(1), component(2, 1, { canSelect: false, configurationError: 'Configura una cuenta de ingreso válida.' }),
    ] }) }));

    await workspace.addPackage(selected);

    assert.deepEqual(workspace.items, []);
    assert.match(workspace.catalogConfigurationError, /cuenta de ingreso/);
});

test('empty packages and server failures display a popup without adding products', async t => {
    const workspace = sale();
    respond(t, async () => ({ ok: true, json: async () => ({ products: [] }) }));
    await workspace.addPackage(selected);
    assert.deepEqual(workspace.items, []);
    assert.match(workspace.catalogConfigurationError, /no tiene productos/);
    globalThis.fetch = async () => ({ ok: false, json: async () => ({ errors: { package: ['El paquete contiene un producto eliminado.'] } }) });
    await workspace.addPackage(selected);
    assert.match(workspace.catalogConfigurationError, /producto eliminado/);
    assert.deepEqual(workspace.items, []);
    assert.equal(workspace.packageAdding, false);
});

test('network failures restore package controls and leave existing lines unchanged', async t => {
    const workspace = sale();
    workspace.addProduct(component(1));
    const original = structuredClone(workspace.items);
    respond(t, async () => { throw new Error('Network unavailable'); });

    await workspace.addPackage(selected);

    assert.deepEqual(workspace.items, original);
    assert.equal(workspace.packageAdding, false);
    assert.deepEqual(workspace.shown, ['sale-product-configuration']);
});

test('pending package requests prevent duplicate additions and payment and cannot modify a reset sale', async t => {
    const workspace = sale();
    workspace.client = { ...workspace.client, id: 44 };
    workspace.addProduct(component(1));
    let complete;
    let requests = 0;
    respond(t, () => { requests++; return new Promise(resolve => { complete = resolve; }); });
    const pending = workspace.addPackage(selected);
    await workspace.addPackage(selected);
    await workspace.openPayment();
    assert.equal(requests, 1);
    assert.deepEqual(workspace.shown, []);

    workspace.newSale();
    complete({ ok: true, json: async () => ({ products: [component(2)] }) });
    await pending;

    assert.deepEqual(workspace.items, []);
    assert.equal(workspace.packageAdding, false);
});
