import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {}, querySelector: () => ({ content: 'csrf' }) };
globalThis.window = { location: { origin: 'https://example.test' } };
const { createReceiptWorkspace } = await import('./libreria-receipts.js');

function workspace() {
    const state = createReceiptWorkspace({ year: 2026, canCreate: true, recipientsUrl: '/people', storeUrl: '/receipts', prepareUrl: '/prepare', cxcUrl: '/academic-cxc',
        concepts: [{ id: 1, name: 'Constancia', price: '10.00', kind: 'normal', account: '5101' }], methods: [{ codigo: '01', valor: 'Efectivo' }, { codigo: '02', valor: 'Tarjeta' }] });
    state.$flux = { modal: () => ({ show() {}, close() {} }) };
    return state;
}

function normal(state) {
    state.form.recipient.name = 'Persona sin registro';
    state.form.conceptId = 1;
    state.selectConcept();
    state.exact();
}

test('explicit academic diagnostics request the selected server carnet and show results without changing receipt fields', async () => {
    const state = workspace(); normal(state); const shown = [];
    const original = structuredClone(state.form);
    state.lookupKind = 'academic_cxc'; state.lookupQuery = ' 501 '; state.lookupYear = '2025'; state.lookupFunding = 'beca';
    state.$flux = { modal: name => ({ show() { shown.push(name); }, close() {} }) };
    state.api = async url => {
        assert.equal(url.pathname, '/academic-cxc');
        assert.equal(url.searchParams.get('diagnostic'), '1');
        assert.equal(url.searchParams.get('q'), '501');
        assert.equal(url.searchParams.get('year'), '2025');
        assert.equal(url.searchParams.get('funding'), 'beca');
        return { diagnostic: { reference: 'diag-1', complete: true, student_id: '501', cxc_rows: [] } };
    };

    await state.diagnoseCxc();

    assert.deepEqual(shown, ['receipt-cxc-diagnostic']);
    assert.equal(state.cxcDiagnostic.reference, 'diag-1');
    assert.equal(state.diagnosticError, '');
    assert.equal(state.diagnosticLoading, false);
    assert.deepEqual(state.form, original);
    assert.equal(state.diagnosticReason('SIN_CXC: No hay registros.'), 'No hay registros.');
});

test('academic diagnostics show input deployment and query errors instead of claiming that debt does not exist', async () => {
    const state = workspace(); state.lookupKind = 'academic_cxc'; state.lookupQuery = 'Alumno'; let requests = 0;
    state.api = async () => { requests++; return { data: [] }; };
    await state.diagnoseCxc();
    assert.equal(requests, 0);
    assert.match(state.diagnosticError, /carnet completo/);
    state.lookupQuery = '501';
    await state.diagnoseCxc();
    assert.equal(requests, 1);
    assert.match(state.diagnosticError, /servidor no devolvió/);
    state.api = async () => ({ diagnostic: { reference: 'diag-2', complete: false } });
    await state.diagnoseCxc();
    assert.equal(state.cxcDiagnostic.reference, 'diag-2');
    assert.match(state.diagnosticError, /no significa que el alumno no tenga CXC/);
});

test('receipt excess requires approval and changed payments require another confirmation before one submission', async () => {
    const state = workspace(); normal(state);
    let calls = 0; const shown = [];
    state.$flux = { modal: name => ({ show() { shown.push(name); }, close() {} }) };
    state.api = async () => { calls++; return { id: 9 }; };
    state.form.payments = [{ uid: 1, method: '01', amount: '8.00' }, { uid: 2, method: '02', amount: '4.00' }];
    await state.issue();
    assert.equal(calls, 0);
    assert.equal(state.submittedPayload, null);
    assert.equal(state.requestKey, null);
    assert.deepEqual(shown, ['receipt-payment-excess']);
    assert.deepEqual([state.paymentExcess.total, state.paymentExcess.paid, state.paymentExcess.change], [1000, 1200, 200]);
    state.cancelExcessPayment();
    assert.equal(state.paymentExcess, null);
    assert.equal(state.form.payments[0].amount, '8.00');
    await state.issue();
    state.form.payments[0].amount = '9.00';
    await state.confirmExcessPayment();
    assert.equal(calls, 0);
    assert.equal(state.paymentExcess.change, 300);
    await state.confirmExcessPayment();
    await state.confirmExcessPayment();
    assert.equal(calls, 1);
    assert.equal(state.completed.id, 9);
});

test('approved receipt excess preserves its frozen request on retry and a cent of excess still needs approval', async () => {
    const state = workspace(); normal(state); const requests = [];
    state.api = async (url, body) => { requests.push(structuredClone(body)); throw new Error('Sin respuesta'); };
    state.form.payments[0].amount = '10.01';
    await state.issue();
    assert.equal(requests.length, 0);
    assert.equal(state.paymentExcess.change, 1);
    await state.confirmExcessPayment();
    state.form.payments[0].amount = '999';
    await state.issue();
    assert.equal(requests.length, 2);
    assert.deepEqual(requests[0], requests[1]);
    assert.equal(requests[1].payments[0].amount, '10.01');
});

test('noncash receipt overpayment is rejected instead of offering confirmation', async () => {
    const state = workspace(); normal(state); let calls = 0;
    state.api = async () => { calls++; return { id: 9 }; };
    state.form.payments = [{ uid: 1, method: '02', amount: '11.00' }];
    await state.issue();
    assert.equal(calls, 0);
    assert.equal(state.paymentExcess, null);
    assert.match(state.notice, /efectivo/);
});

test('one operation retains one concept and several payment methods with cash-only change', () => {
    const state = workspace();
    normal(state);
    state.form.payments = [{ uid: 1, method: '01', amount: '8.00', reference: '' }, { uid: 2, method: '02', amount: '4.00', reference: 'AUTH' }];

    assert.equal(state.total, 1000);
    assert.equal(state.paid, 1200);
    assert.equal(state.cash, 800);
    assert.equal(state.change, 200);
    assert.equal(state.payload().conceptId, 1);
    assert.equal(state.payload().account, '5101');
    assert.equal('recipients' in state.payload(), false);
    assert.equal('lines' in state.payload(), false);
    assert.deepEqual(state.payload().payments, [{ method: '01', amount: '8.00', reference: null }, { method: '02', amount: '4.00', reference: 'AUTH' }]);
    state.reset();
    assert.equal(state.form.recipient.name, '');
    assert.equal(state.form.payments.length, 1);
});

test('retreats and sacraments require a student solely for history while retaining payer account and amount', async () => {
    const state = workspace();
    state.options.concepts[0].name = 'RETIRO DE CONFIRMA';
    state.options.concepts[0].requiresStudent = true;
    normal(state);
    let calls = 0;
    state.api = async () => { calls++; return { id: 8 }; };
    await state.issue();
    assert.equal(calls, 0);
    assert.match(state.notice, /alumno/);
    state.lookupKind = 'students';
    await state.choose({ id: 501, name: 'Alumno Uno' });
    const input = state.payload();
    assert.equal(input.studentId, '501');
    assert.equal(input.recipient.name, 'Persona sin registro');
    assert.equal(input.account, '5101');
    assert.equal(input.amount, '10.00');
    assert.equal('year' in input, false);
    await state.issue();
    assert.equal(calls, 1);
});

test('photography still requires its student and sends the academic year', () => {
    const state = workspace();
    state.options.concepts[0].kind = 'fotografia';
    normal(state);
    assert.equal(state.requiresStudent, true);
    state.form.studentId = '501'; state.form.year = 2025;
    assert.equal(state.payload().year, 2025);
});

test('an unconfirmed receipt retries the exact request and never opens its PDF automatically', async t => {
    const state = workspace();
    normal(state);
    const calls = [];
    const originalOpen = window.open;
    t.after(() => { window.open = originalOpen; });
    window.open = () => assert.fail('Printing requires an explicit choice');
    state.api = async (url, body) => {
        calls.push(structuredClone(body));
        if (calls.length === 1) throw new Error('Sin confirmación');
        return { id: 7, ticketUrl: '/receipts/7/ticket' };
    };

    await state.issue();
    assert.equal(state.locked, true);
    state.form.amount = '99.00';
    await state.issue();

    assert.deepEqual(calls[0], calls[1]);
    assert.equal(state.completed.id, 7);
    state.finish();
    assert.equal(state.form.recipient.name, '');
    assert.equal(state.submittedPayload, null);
    assert.equal(calls.length, 2);
});

test('double submission does not create concurrent requests and validation permits correcting the draft', async () => {
    const state = workspace();
    normal(state);
    let reject; let calls = 0;
    state.api = async () => { calls++; return new Promise((resolve, fail) => { reject = fail; }); };

    const pending = state.issue();
    await state.issue();
    const error = new Error('Saldo actualizado'); error.status = 422;
    reject(error); await pending;

    assert.equal(calls, 1);
    assert.equal(state.locked, false);
    assert.equal(state.form.recipient.name, 'Persona sin registro');
    assert.equal(state.requestKey, null);
});

test('blocked copies retain completed receipts and retrying the copy only opens their existing PDF', t => {
    const state = workspace();
    normal(state);
    state.completed = { id: 7, ticketUrl: '/receipts/7/ticket' };
    const originalOpen = window.open;
    t.after(() => { window.open = originalOpen; });
    window.open = () => null;

    state.finish(true);
    assert.equal(state.completed.id, 7);
    const opened = []; const tab = { opener: {} };
    window.open = (...args) => { opened.push(args); return tab; };
    state.finish(true);

    assert.deepEqual(opened, [['/receipts/7/ticket', '_blank']]);
    assert.equal(tab.opener, null);
    assert.equal(state.completed, null);
});

test('an earlier person search cannot replace the latest recipient results', async () => {
    const state = workspace();
    const pending = [];
    state.api = async () => new Promise(resolve => pending.push(resolve));
    state.lookupQuery = 'primero';
    const first = state.loadLookup();
    state.lookupQuery = 'segundo';
    const second = state.loadLookup();

    pending[1]({ data: [{ id: '2', name: 'Segundo' }] }); await second;
    pending[0]({ data: [{ id: '1', name: 'Primero' }] }); await first;

    assert.deepEqual(state.lookupRows, [{ id: '2', name: 'Segundo' }]);
    assert.equal(state.lookupLoading, false);
});

test('ambiguous academic invoices require explicit period confirmation and an assigned receivable account', async () => {
    const state = workspace();
    state.mode = 'academic_cxc';
    state.form.sourceId = 9;
    const invoice = { origin: 'legacy', uuid: 'f1', number: 'DTE 1' };
    state.selection = { remaining: '20.00' };
    state.invoices = [invoice];
    state.invoiceChoice = 'legacy|f1';
    state.chooseInvoice();
    state.form.account = '110201010101';
    state.form.amount = '20.00';
    state.exact();
    let calls = 0;
    state.api = async () => { calls++; return { id: 1 }; };

    await state.issue();
    assert.equal(calls, 0);
    assert.match(state.notice, /Confirma/);
    state.form.invoiceConfirmed = true;
    await state.issue();

    assert.equal(calls, 1);
    assert.deepEqual(state.submittedPayload.invoice, { origin: 'legacy', uuid: 'f1', confirmed: true });
    assert.equal(state.submittedPayload.sourceId, 9);
    assert.equal('conceptId' in state.submittedPayload, false);
});
test('adding another academic CXC retains the first and sends one receipt with a combined total', async () => {
    const state = workspace();
    state.mode = 'academic_cxc';
    state.form.sourceId = 1; state.form.studentId = '501';
    state.form.amount = '10.00'; state.form.account = '110201010101';
    state.form.invoice = { origin: 'legacy', uuid: 'f1', number: 'Factura 1' }; state.form.invoiceAutomatic = true;
    state.selection = { remaining: '20.00', name: 'Febrero' };
    state.openLookup = () => assert.fail('Agregar debe volver a la lista de cuotas');
    state.addAcademic();
    assert.equal(state.academicItems.length, 1);
    assert.equal(state.total, 1000);
    assert.equal(state.form.sourceId, null);
    state.academicItems[0].amount = '8.00';
    state.form.sourceId = 2; state.form.amount = '5.00'; state.form.account = '110201010101';
    state.form.invoice = { origin: 'legacy', uuid: 'f2', number: 'Factura 2' }; state.form.invoiceAutomatic = true;
    state.selection = { remaining: '5.00', name: 'Marzo' };
    state.exact();
    state.api = async () => ({ id: 3 });
    await state.issue();
    assert.equal(state.submittedPayload.amount, '13.00');
    assert.equal(state.submittedPayload.academicItems.length, 2);
    assert.equal('name' in state.submittedPayload.academicItems[0], false);
    assert.equal('remaining' in state.submittedPayload.academicItems[0], false);
    assert.equal('sourceId' in state.submittedPayload, false);
    state.finish();
    assert.equal(state.academicItems.length, 0);
});

test('closing quota details cancels a pending search without adding a hidden charge', async () => {
    const state = workspace();
    state.mode = 'academic_cxc'; state.form.sourceId = 1;
    let resolve;
    state.api = async () => new Promise(done => { resolve = done; });
    const pending = state.prepare('Factura');
    state.cancelAcademic();
    resolve({ recipient: { name: 'Alumno' }, studentId: '501', remaining: '50.00', invoices: [], invoice: null });
    await pending;
    assert.equal(state.selection, null);
    assert.equal(state.form.sourceId, null);
    assert.equal(state.total, 0);
    assert.equal(state.academicItems.length, 0);
});
test('completing a receipt payment fills only its missing share without replacing other methods', () => {
    const state = workspace();
    normal(state);
    state.form.payments = [{ uid: 1, method: '01', amount: '4.00', reference: '' }, { uid: 2, method: '02', amount: '', reference: 'AUTH' }];
    state.completePayment(2);
    assert.equal(state.form.payments[0].amount, '4.00');
    assert.deepEqual(state.form.payments[1], { uid: 2, method: '02', amount: '6.00', reference: 'AUTH' });
    state.form.amount = '8.00';
    state.completePayment(2);
    assert.equal(state.form.payments[1].amount, '4.00');
    state.submittedPayload = {};
    state.form.amount = '12.00';
    state.completePayment(2);
    assert.equal(state.form.payments[1].amount, '4.00');
});

test('the searchable history origin sends the correct legacy flag after switching back to current receipts', async () => {
    const state = workspace();
    state.options.historyUrl = '/history';
    const flags = [];
    state.api = async url => {
        flags.push(url.searchParams.get('legacy'));
        return { data: [], page: 1, pages: 1 };
    };
    assert.equal(state.historyOrigin, 'current');
    state.historyOrigin = 'legacy';
    await state.loadHistory();
    state.historyOrigin = 'current';
    await state.loadHistory();
    assert.equal(state.historyLegacy, false);
    assert.deepEqual(flags, ['1', '0']);
});
