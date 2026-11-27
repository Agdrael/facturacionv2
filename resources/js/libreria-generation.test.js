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
    state.payments = [{ method: '01', amount: '11.30', reference: '' }];
    return state;
}

test('excess sale payments require explicit confirmation of the current amounts and cancellation keeps the draft', async () => {
    const state = sale(); const shown = []; let calls = 0;
    state.$flux = { modal: name => ({ show() { shown.push(name); }, close() {} }) };
    state.postGeneration = async () => { calls++; return { id: 1 }; };
    state.payments = [{ method: '01', amount: '10' }, { method: '02', amount: '5' }];

    await state.generateSale();
    assert.equal(calls, 0);
    assert.deepEqual(shown, ['sale-payment-excess']);
    assert.deepEqual([state.paymentExcess.total, state.paymentExcess.paid, state.paymentExcess.change], [1130, 1500, 370]);
    assert.equal(state.requestKey, null);
    state.cancelExcessPayment();
    assert.equal(state.paymentExcess, null);
    assert.equal(state.items.length, 1);
    assert.equal(state.payments[0].amount, '10');
    await state.generateSale();
    state.payments[0].amount = '11';
    await state.confirmExcessPayment();
    assert.equal(calls, 0);
    assert.equal(state.paymentExcess.change, 470);
    await state.confirmExcessPayment();
    await state.confirmExcessPayment();
    assert.equal(calls, 1);
    assert.equal(state.generated.id, 1);
    state.newSale();
    assert.equal(state.paymentApproval, null);
});

test('an approved sale excess retries the same request while noncash excess cannot be approved', async () => {
    const state = sale(); const requests = [];
    state.payments = [{ method: '02', amount: '20' }];
    state.postGeneration = async (url, body) => { requests.push(body); throw new Error('Sin respuesta'); };
    await state.generateSale();
    assert.equal(requests.length, 0);
    assert.match(state.notice, /efectivo/);
    assert.equal(state.paymentExcess, null);
    state.payments = [{ method: '01', amount: '20' }];
    await state.generateSale();
    await state.confirmExcessPayment();
    await state.generateSale();
    assert.equal(requests.length, 2);
    assert.deepEqual(requests[0], requests[1]);
});

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

test('issuing a sale does not open a ticket and declining the copy starts another sale without a second request', async t => {
    const state = sale();
    const originalOpen = window.open;
    t.after(() => { window.open = originalOpen; });
    let opened = 0; let requests = 0;
    window.open = () => { opened++; return {}; };
    state.postGeneration = async () => { requests++; return { id: 7, transmissionState: 'ACEPTADO_PRUEBA', seal: 'SEAL', ticketUrl: '/ticket/7' }; };

    await state.generateSale();

    assert.equal(opened, 0);
    assert.equal(state.generated.seal, 'SEAL');
    assert.equal(state.items.length, 1);
    state.finishSale();
    assert.equal(opened, 0);
    assert.equal(requests, 1);
    assert.equal(state.generated, null);
    assert.equal(state.items.length, 0);
});

test('requesting a copy opens the registered ticket and never generates or transmits the sale again', async t => {
    const state = sale();
    const originalOpen = window.open;
    t.after(() => { window.open = originalOpen; });
    const opened = []; const ticketWindow = { opener: {} };
    window.open = (...args) => { opened.push(args); return ticketWindow; };
    state.generated = { id: 7, ticketUrl: '/ticket/7', seal: 'SEAL' };
    state.postGeneration = async () => { assert.fail('Printing must not issue another sale'); };

    state.finishSale(true);

    assert.deepEqual(opened, [['/ticket/7', '_blank']]);
    assert.equal(ticketWindow.opener, null);
    assert.equal(state.generated, null);
    assert.equal(state.items.length, 0);
});

test('a blocked ticket popup preserves the registered sale so the copy can be retried', async t => {
    const state = sale();
    const originalOpen = window.open;
    t.after(() => { window.open = originalOpen; });
    window.open = () => null;
    state.generated = { id: 7, ticketUrl: '/ticket/7' };

    state.finishSale(true);

    assert.equal(state.generated.id, 7);
    assert.equal(state.items.length, 1);
    assert.match(state.notice, /ventanas emergentes/);
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
