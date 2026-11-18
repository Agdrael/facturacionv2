import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {} };
globalThis.window = { location: { origin: 'https://example.test' } };
const { createPackageForm } = await import('./contaduria-package.js');

function product(productId, netPrice = '10.25') {
    return { productId, code: `P${productId}`, name: `Producto ${productId}`, netPrice, canSelect: true, stock: null };
}

function form(items = []) {
    const state = createPackageForm({ productSearchUrl: '/contaduria/paquetes/productos', items });
    state.$flux = { modal: () => ({ show() {} }) };
    return state;
}

test('package definitions calculate exact net totals with product quantities', () => {
    const state = form();
    state.addProduct(product(1));
    state.addProduct(product(2, '2.5'));
    state.items[0].quantity = '2';
    state.items[1].quantity = '.5';

    assert.equal(state.total, '21.750000');
    assert.equal(state.lineTotal(state.items[0]), '$20.500000');
    assert.equal(state.formatPrice(state.total), '$21.750000');
});

test('rounding happens after adding fractional line amounts', () => {
    const state = form([{ ...product(1, '0.000001'), quantity: '0.5' }, { ...product(2, '0.000001'), quantity: '0.5' }]);

    assert.equal(state.total, '0.000001');
    state.removeProduct(state.items[1].rowKey);
    assert.equal(state.total, '0.000001');
});

test('reselecting a product increases its quantity without duplicating component rows', () => {
    const state = form();
    state.addProduct(product(1));
    state.items[0].quantity = '1.5';
    state.addProduct(product(1));

    assert.equal(state.items.length, 1);
    assert.equal(state.items[0].quantity, '2.5');
    assert.equal(state.total, '25.625000');
});

test('restored form rows have stable keys and allow adding or removing components', () => {
    const state = form([{ ...product(1), quantity: '2' }]);
    state.addProduct(product(2));

    assert.equal(new Set(state.items.map(item => item.rowKey)).size, 2);
    state.removeProduct(state.items[0].rowKey);
    assert.equal(state.items[0].productId, 2);
    assert.equal(state.total, '10.250000');
});

test('invalid products show a popup and cannot be included', () => {
    const state = form();
    let shown = '';
    state.$flux.modal = name => ({ show() { shown = name; } });

    state.addProduct({ ...product(1), canSelect: false, configurationError: 'Configura una cuenta de ingreso válida.' });

    assert.deepEqual(state.items, []);
    assert.equal(shown, 'package-message');
    assert.match(state.notice, /cuenta de ingreso/);
});

test('product lookup uses authenticated pagination and discards outdated responses', async t => {
    const state = form();
    const previous = globalThis.fetch;
    t.after(() => { globalThis.fetch = previous; });
    let firstResolve;
    globalThis.fetch = () => new Promise(resolve => { firstResolve = resolve; });
    const first = state.searchProducts();
    let request;
    globalThis.fetch = async (url, options) => {
        request = { url, options };
        return { ok: true, json: async () => ({ data: [product(2)], meta: { current_page: 2, last_page: 3, total: 45 } }) };
    };
    state.search = 'Ábaco';
    await state.searchProducts(2);
    firstResolve({ ok: true, json: async () => ({ data: [product(1)], meta: { current_page: 1, last_page: 1, total: 1 } }) });
    await first;

    assert.equal(state.results[0].productId, 2);
    assert.equal(request.url.searchParams.get('q'), 'Ábaco');
    assert.equal(request.url.searchParams.get('page'), '2');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(state.page, 2);
    assert.equal(state.totalResults, 45);
    assert.equal(state.loading, false);
});

test('lookup failures clear results and provide a retry message', async t => {
    const state = form();
    state.results = [product(1)];
    const previous = globalThis.fetch;
    t.after(() => { globalThis.fetch = previous; });
    globalThis.fetch = async () => { throw new Error('Unavailable'); };

    await state.searchProducts();

    assert.deepEqual(state.results, []);
    assert.equal(state.loading, false);
    assert.match(state.error, /Intenta nuevamente/);
});
