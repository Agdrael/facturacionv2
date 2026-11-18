import assert from 'node:assert/strict';
import { test } from 'node:test';
globalThis.document = { addEventListener() {}, querySelector: () => ({ content: 'csrf-example' }) };
globalThis.window = { location: { origin: 'https://example.test' } };
const { createSaleWorkspace } = await import('./libreria-sale.js');

function sale() {
    const state = createSaleWorkspace({ documents: [{ codigo: '01' }], operations: [{ codigo: '1' }], quoteUrl: '/quote', generateUrl: '/generate' });
    state.$flux = { modal: () => ({ show() {}, close() {} }) };
    state.showGenerationNotice = message => { state.notice = message; };
    state.client = { id: 1, name: 'Cliente', documents: { '13': '00000000-0' } };
    state.document.date = '2026-11-11';
    state.items = [{ productId: 1, name: 'Producto', quantity: 1, price: 1130, netPrice: '10', discount: 0 }];
    state.payments = [{ method: '01', amount: '20', reference: '' }];
    return state;
}

test('preparation uses server totals and invalidates the quote when the basket changes', async () => {
    const state = sale(); let payload;
    state.postGeneration = async (url, body) => { payload = body; return { total: '11.31' }; };
    assert.equal(await state.quoteSale(), true);
    assert.equal(state.total, 1131);
    assert.equal(payload.document.creditAccount, undefined);
    assert.equal(payload.client.documents, undefined);
    assert.equal(payload.payments, undefined);
    state.items[0].quantity = 2;
    assert.equal(state.total, 2260);
});

test('preparation errors show a popup and do not enable payment with a false total', async () => {
    const state = sale(); state.postGeneration = async () => { throw new Error('Stock insuficiente'); };
    assert.equal(await state.quoteSale(), false);
    assert.equal(state.notice, 'Stock insuficiente');
    assert.equal(state.quotedTotal, null);
    assert.equal(state.quoteLoading, false);
});

test('an interrupted generation keeps its request key for retry and prevents duplicate submissions', async () => {
    const state = sale(); let resolve; let calls = 0;
    state.postGeneration = async () => { calls++; return new Promise(done => { resolve = done; }); };
    const pending = state.generateSale();
    const key = state.requestKey;
    await state.generateSale(); state.newSale();
    assert.equal(calls, 1);
    assert.equal(state.items.length, 1);
    resolve({ id: 1, saved: true, number: 'DTE', message: 'Generado' }); await pending;
    assert.equal(state.generated.id, 1);
    assert.equal(state.requestKey, key);
    await state.generateSale(); assert.equal(calls, 1);
});

test('a failed network request can be repeated with the same key and payload', async () => {
    const state = sale(); const sent = [];
    state.postGeneration = async (url, body) => { sent.push(body); throw new Error('Sin respuesta'); };
    await state.generateSale(); await state.generateSale();
    assert.deepEqual(sent[0], sent[1]);
    assert.equal(state.generated, null);
    assert.equal(state.generating, false);
});

test('credit fiscal cannot be generated with a renamed default client', async () => {
    const state = sale();
    state.saleCatalogs.defaultClient = { id: 1 };
    state.document.type = '03';
    let sent = false;
    const shown = [];
    state.$flux = { modal: name => ({ show() { shown.push(name); }, close() {} }) };
    state.postGeneration = async () => { sent = true; };

    await state.generateSale();

    assert.equal(sent, false);
    assert.equal(state.generated, null);
    assert.deepEqual(shown, ['sale-credit-client']);
});

test('external storage retries call only the retry URL without resending products or payments', async () => {
    const state = sale(); state.generated = { id: 7, saved: false, retryUrl: '/retry/7' }; let request;
    state.postGeneration = async (url, body) => { request = { url, body }; return { id: 7, saved: true, message: 'Guardado' }; };
    await state.retryDte();
    assert.equal(request.url, '/retry/7'); assert.equal(request.body, undefined);
    assert.equal(state.generated.saved, true);
    assert.equal(state.items.length, 1);
});

test('a new sale clears generation and quote data while keeping the history', () => {
    const state = sale(); state.generated = { id: 1 }; state.requestKey = 'saved'; state.quotedTotal = 1131; state.history = [{ id: 'DTE' }];
    state.newSale();
    assert.equal(state.generated, null); assert.equal(state.requestKey, null); assert.equal(state.quotedTotal, null);
    assert.equal(state.items.length, 0); assert.equal(state.history.length, 1);
});

test('academic lines send only their source identifiers and never trust client amounts or accounts', () => {
    const state = sale(); state.items[0].tuition = { studentId: '123', balanceId: '9', fundingType: 'beca', responsibleType: 'beca', amount: 999999, incomeAccount: 'fake' };
    const tuition = state.generationBase().items[0].tuition;
    assert.deepEqual(tuition, { studentId: '123', balanceId: '9', fundingType: 'beca', responsibleType: 'beca' });
});

test('the generation request includes CSRF and same origin credentials', async t => {
    const state = sale(); const previous = globalThis.fetch; t.after(() => { globalThis.fetch = previous; }); let options;
    globalThis.fetch = async (url, input) => { options = input; return { ok: true, json: async () => ({ saved: true }) }; };
    await state.postGeneration('/generate', { example: true });
    assert.equal(options.credentials, 'same-origin'); assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-example');
});

test('transmission uses only the generated DTE endpoint and preserves its download and retry references', async () => {
    const state = sale(); state.generated = { id: 7, saved: true, downloadUrl: '/json/7', retryUrl: '/store/7', transmitUrl: '/transmit/7' }; let sent;
    state.postGeneration = async (url, body) => { sent = { url, body }; return { id: 7, transmissionState: 'ACEPTADO_PRUEBA', seal: 'SEAL', paymentsApplied: false, message: 'Aceptado en pruebas' }; };
    await state.transmitDte();
    assert.equal(sent.url, '/transmit/7'); assert.equal(sent.body, undefined);
    assert.equal(state.generated.transmissionState, 'ACEPTADO_PRUEBA'); assert.equal(state.generated.downloadUrl, '/json/7');
    assert.equal(state.generated.saved, true); assert.equal(state.items.length, 1); assert.equal(state.generating, false);
});

test('transmission controls prevent duplicate clicks and show API errors in a popup', async () => {
    const state = sale(); state.generated = { id: 7, transmitUrl: '/transmit/7' }; let reject; let count = 0;
    state.postGeneration = async () => { count++; return new Promise((resolve, fail) => { reject = fail; }); };
    const pending = state.transmitDte(); await state.transmitDte();
    reject(new Error('El ambiente no coincide')); await pending;
    assert.equal(count, 1); assert.equal(state.notice, 'El ambiente no coincide'); assert.equal(state.generating, false);
});

test('invalidation forms load only for authorized rows using same origin requests', async t => {
    const state = sale(); const previous = globalThis.fetch; t.after(() => { globalThis.fetch = previous; }); const calls = [];
    globalThis.fetch = async (url, options) => { calls.push({ url, options }); return { ok: true, json: async () => ({ number: 'DTE-01', reasonTypes: [], identityDocuments: [], event: null, storeUrl: '/invalidate/1', defaults: { reasonType: '2', confirmed: false } }) }; };
    await state.openInvalidation({ canInvalidate: false, invalidationUrl: '/private/1' });
    await state.openInvalidation({ canInvalidate: true, invalidationUrl: '/invalidate/1' });
    assert.equal(calls.length, 1); assert.equal(calls[0].url, '/invalidate/1'); assert.equal(calls[0].options.credentials, 'same-origin');
    assert.equal(state.invalidation.number, 'DTE-01'); assert.equal(state.invalidationForm.confirmed, false); assert.equal(state.invalidationLoading, false);
});

test('preparing an invalidation requires confirmation and clears a replacement for a rescinded sale', async () => {
    const state = sale(); const calls = [];
    state.invalidation.storeUrl = '/invalidate/1';
    state.postGeneration = async (url, body) => { calls.push({ url, body }); return { id: 1, invalidationState: 'PENDIENTE', message: 'Preparado', transmitUrl: '/invalidate/1/send' }; };
    await state.prepareInvalidation();
    assert.equal(calls.length, 0); assert.match(state.notice, /Confirma/);
    state.invalidationForm.confirmed = true; state.invalidationForm.replacement = 'stale-replacement';
    await state.prepareInvalidation(); await state.prepareInvalidation();
    assert.equal(calls.length, 1); assert.equal(calls[0].body.replacement, null);
    assert.equal(state.invalidation.event.invalidationState, 'PENDIENTE'); assert.equal(state.invalidating, false); assert.equal(state.items.length, 1);
});

test('invalidation transmission prevents duplicate clicks and never posts a new sale', async () => {
    const state = sale(); let resolve; const calls = [];
    state.invalidation.event = { id: 7, invalidationState: 'PENDIENTE', transmitUrl: '/invalidate/7/send' };
    state.postGeneration = async (url, body) => { calls.push({ url, body }); return new Promise(done => { resolve = done; }); };
    const pending = state.transmitInvalidation(); await state.transmitInvalidation();
    assert.equal(calls.length, 1); assert.equal(calls[0].url, '/invalidate/7/send'); assert.equal(calls[0].body, undefined);
    resolve({ id: 7, invalidationState: 'ACEPTADO_PRUEBA', applied: true, message: 'Anulada', transmitUrl: '/invalidate/7/send' }); await pending;
    await state.transmitInvalidation();
    assert.equal(calls.length, 1); assert.equal(state.invalidation.event.applied, true); assert.equal(state.invalidating, false); assert.equal(state.items.length, 1);
});

test('invalidation errors remain in the popup and preserve the prepared event for consultation', async () => {
    const state = sale();
    state.invalidation.event = { id: 1, invalidationState: 'PENDIENTE', transmitUrl: '/invalidate/1/send' };
    state.postGeneration = async () => { throw new Error('La transmisión está deshabilitada'); };
    await state.transmitInvalidation();
    assert.equal(state.notice, 'La transmisión está deshabilitada'); assert.equal(state.invalidation.event.id, 1); assert.equal(state.invalidating, false);
});
