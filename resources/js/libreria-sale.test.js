import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {} };
globalThis.window = { location: { origin: 'http://localhost' } };
const { createSaleWorkspace } = await import('./libreria-sale.js');

const options = {
    documents: [{ codigo: '01', valor: 'Factura' }, { codigo: '03', valor: 'Comprobante de crédito fiscal' }],
    identityDocuments: [{ codigo: '13', valor: 'DUI' }, { codigo: '36', valor: 'NIT' }],
    operations: [{ codigo: '1', valor: 'Contado' }, { codigo: '2', valor: 'A crédito' }],
    paymentMethods: [{ codigo: '01', valor: 'Billetes y monedas' }, { codigo: '02', valor: 'Tarjeta Débito' }],
    creditTerms: [{ codigo: '01', valor: 'Días' }, { codigo: '03', valor: 'Años' }],
    economicActivities: [{ codigo: '12345', valor: 'Actividad de prueba' }, { codigo: '67890', valor: 'Otra actividad' }],
    geography: {
        departments: [{ codigo: '01', valor: 'Departamento uno' }, { codigo: '02', valor: 'Departamento dos' }],
        districts: [
            { codigo: '7', valor: 'Distrito uno', department: '01', municipalityCode: '01', municipalityName: 'Municipio uno' },
            { codigo: '8', valor: 'Distrito dos', department: '02', municipalityCode: '01', municipalityName: 'Municipio dos' },
        ],
    },
};

function sale(overrides = {}) {
    const workspace = createSaleWorkspace({ ...options, ...overrides });
    workspace.$flux = { modal: () => ({ show() {}, close() {} }) };
    workspace.$nextTick = () => {};
    return workspace;
}

function client() {
    return { id: 44, name: 'Cliente seleccionado', documents: { '13': '01234567-8', '36': '0614-123456-789-0' }, department: '01', district: '7', municipality: '99', phone2: null, activity: '12345' };
}

test('sales start without a selected client and use actual catalog codes', () => {
    const workspace = sale();
    assert.equal(workspace.client.id, null);
    assert.equal(workspace.document.type, '01');
    assert.equal(workspace.document.thirdPartyType, '');
    assert.equal(workspace.operation, '1');
    assert.equal(workspace.term, '01');
    assert.equal(workspace.payments[0].method, '01');
    workspace.document.type = '03';
    assert.equal(workspace.documentLabel, 'Comprobante de crédito fiscal');
});

test('sales start with the configured default client and open its editor directly', () => {
    const workspace = sale({ defaultClient: { ...client(), name: 'CLIENTES VARIOS' } });
    const shown = [];
    workspace.$flux = { modal: name => ({ show() { shown.push(name); }, close() {} }) };

    workspace.editClient();

    assert.equal(workspace.hasBillingClient, true);
    assert.equal(workspace.client.name, 'CLIENTES VARIOS');
    assert.equal(workspace.client.documentType, '13');
    assert.equal(workspace.client.document, '01234567-8');
    assert.deepEqual(shown, ['sale-client']);
    assert.equal(workspace.clientDraft.id, 44);
});

test('credit fiscal requests another client and opens the client picker from its popup', async () => {
    const workspace = sale({ defaultClient: { ...client(), name: 'CLIENTES VARIOS' } });
    const shown = [];
    workspace.$flux = { modal: name => ({ show() { shown.push(name); }, close() {} }) };
    workspace.$nextTick = callback => callback();
    workspace.document.type = '03';
    workspace.client.name = 'Nombre editado';

    await workspace.openPayment();
    workspace.chooseFiscalClient();

    assert.deepEqual(shown, ['sale-credit-client', 'sale-client-picker']);
    assert.equal(workspace.clientDraft.id, 44);
    workspace.selectClient({ ...client(), id: 55 });
    workspace.saveClient();
    assert.equal(workspace.needsFiscalClient, false);
    assert.equal(workspace.requireFiscalClientChange(), false);
});

test('a new sale restores the default client after editing or replacing it', () => {
    const defaultClient = { ...client(), name: 'CLIENTES VARIOS', documents: {} };
    const workspace = sale({ defaultClient });
    workspace.editClient();
    workspace.clientDraft.name = 'Nombre solo para esta venta';
    workspace.clientDraft.documents['13'] = '11234567-8';
    workspace.saveClient();
    assert.equal(workspace.clientIsDefault, false);
    workspace.selectClient({ ...client(), id: 55 });
    workspace.saveClient();

    workspace.newSale();

    assert.equal(workspace.hasBillingClient, true);
    assert.equal(workspace.client.id, 44);
    assert.equal(workspace.client.name, 'CLIENTES VARIOS');
    assert.equal(workspace.client.document, '');
    assert.deepEqual(workspace.client.documents, {});
    assert.equal(workspace.clientIsDefault, true);
    assert.equal(workspace.clientDraft.id, undefined);
});

test('selecting a real client loads billing data and changing identification uses the selected document', () => {
    const workspace = sale();
    workspace.selectClient(client());
    assert.equal(workspace.client.id, null);
    assert.equal(workspace.clientDraft.documentType, '13');
    assert.equal(workspace.clientDraft.document, '01234567-8');
    assert.equal(workspace.clientDraft.municipality, '01');
    assert.equal(workspace.draftMunicipalityName, 'Municipio uno');
    workspace.clientDraft.documentType = '36';
    workspace.changeClientDocument();
    assert.equal(workspace.clientDraft.document, '0614-123456-789-0');
    workspace.saveClient();
    assert.equal(workspace.client.id, 44);
    assert.equal(workspace.client.documentType, '36');
});

test('client details cannot be accepted without selecting a real client', () => {
    const workspace = sale();
    workspace.clientDraft = { name: 'Nombre manual' };
    workspace.saveClient();
    assert.equal(workspace.client.id, null);
});

test('parent billing can be reviewed before adding fees and cancelling the picker retains the draft', () => {
    const workspace = sale();
    const opened = [];
    workspace.$nextTick = callback => callback();
    workspace.$flux.modal = name => ({ show: () => opened.push(name), close() {} });
    workspace.tuitionStudent = { id: 1001 };
    workspace.tuitionChoosingStudent = false;
    workspace.tuitionResponsibleType = 'custom_275';
    workspace.tuitionResponsibleTypes = [{ id: 'custom_275', billing: {
        name: 'Padre de prueba', documentType: '13', document: '01234567-8', email: 'padre@example.test',
        department: 'DEPARTAMENTO UNO', district: 'Dístrito uno', municipality: 'Dato antiguo',
    } }];

    workspace.editTuitionClient();

    assert.deepEqual(opened, ['sale-client']);
    assert.equal(workspace.hasBillingDraft, true);
    assert.equal(workspace.hasBillingClient, false);
    assert.equal(workspace.clientDraft.department, '01');
    assert.equal(workspace.clientDraft.district, '7');
    assert.equal(workspace.clientDraft.municipality, '01');
    workspace.clientDraft.email = 'corregido@example.test';
    workspace.openClientPicker();
    workspace.clientPickerClosed();
    assert.equal(opened.at(-1), 'sale-client');
    assert.equal(workspace.clientDraft.email, 'corregido@example.test');
    assert.equal(workspace.hasBillingClient, false);

    workspace.saveClient();
    workspace.editTuitionClient();
    assert.equal(workspace.clientDraft.email, 'corregido@example.test');
    assert.equal(workspace.client.documentType, '13');
    assert.equal(workspace.client.document, '01234567-8');
});

test('explicitly using another responsible replaces billing only on acceptance and clears previous third party data', () => {
    const workspace = sale();
    workspace.selectClient(client());
    workspace.saveClient();
    workspace.tuitionStudent = { id: 1001 };
    workspace.tuitionChoosingStudent = false;
    workspace.tuitionResponsibleType = 'custom_300';
    workspace.tuitionResponsibleTypes = [{ id: 'custom_300', billing: { name: 'Otro responsable', documentType: '36', document: '0614-123456-789-0', department: '1', district: '7' } }, { id: 'beca', billing: { name: 'Clientes varios' } }];

    workspace.editTuitionClient();
    assert.equal(workspace.client.id, 44);
    assert.equal(workspace.clientDraft.department, '01');
    workspace.saveClient();
    assert.equal(workspace.client.name, 'Otro responsable');
    assert.equal(workspace.client.documentType, '36');
    workspace.document.thirdPartyType = '13';
    workspace.document.thirdPartyDocument = '01234567-8';
    workspace.tuitionResponsibleType = 'beca';
    workspace.editTuitionClient();
    assert.equal(workspace.client.name, 'Otro responsable');
    workspace.clientDraft.email = 'beca@example.test';
    workspace.saveClient();
    assert.equal(workspace.client.name, 'Clientes varios');
    assert.equal(workspace.client.email, 'beca@example.test');
    assert.equal(workspace.client.document, '');
    assert.equal(workspace.document.thirdPartyType, '');
    assert.equal(workspace.document.thirdPartyDocument, '');
});

test('reviewing a responsible without selecting a loaded student leaves client data unchanged', () => {
    const workspace = sale();
    const initialClient = structuredClone(workspace.client);
    workspace.editTuitionClient();
    assert.deepEqual(workspace.client, initialClient);
    assert.deepEqual(workspace.clientDraft, {});
    assert.equal(workspace.hasBillingDraft, false);
});

for (const [description, documents, expectedNumber] of [
    ['missing DUI', { '13': null, '36': '0614-123456-789-0' }, '0614-123456-789-0'],
    ['empty DUI', { '13': '', '36': '0614-123456-789-0' }, '0614-123456-789-0'],
    ['whitespace DUI', { '13': '   ', '36': '0614-123456-789-0' }, '0614-123456-789-0'],
    ['no registered documents', {}, ''],
]) {
    test(`client with ${description} automatically selects NIT and loads its available number`, () => {
        const workspace = sale();

        workspace.selectClient({ ...client(), documents });

        assert.equal(workspace.clientDraft.documentType, '36');
        assert.equal(workspace.clientDraft.document, expectedNumber);
        workspace.saveClient();
        assert.equal(workspace.client.documentType, '36');
        assert.equal(workspace.client.document, expectedNumber);
    });
}

test('a registered DUI takes priority over NIT and ignores surrounding whitespace', () => {
    const workspace = sale();

    workspace.selectClient({ ...client(), documents: { '13': ' 01234567-8 ', '36': '0614-123456-789-0' } });

    assert.equal(workspace.clientDraft.documentType, '13');
    assert.equal(workspace.clientDraft.document, '01234567-8');
});

test('new client selection opens a separate picker and choosing a client returns to its details', () => {
    const workspace = sale();
    const opened = [];
    const closed = [];
    workspace.$nextTick = callback => callback();
    workspace.$flux = { modal: name => ({ show() { opened.push(name); }, close() { closed.push(name); } }) };

    workspace.editClient();
    assert.deepEqual(opened, ['sale-client-picker']);
    assert.equal(workspace.client.id, null);

    workspace.selectClient(client());
    assert.equal(closed.at(-1), 'sale-client-picker');
    workspace.clientPickerClosed();
    assert.equal(opened.at(-1), 'sale-client');
    assert.equal(workspace.clientDraft.id, 44);
    assert.equal(workspace.client.id, null);
});

test('cancelling the client picker preserves changes in the current billing draft', () => {
    const workspace = sale();
    workspace.selectClient(client());
    workspace.saveClient();
    workspace.editClient();
    workspace.clientDraft.name = 'Cambio en preparación';
    workspace.openClientPicker();
    workspace.clientPickerClosed();
    assert.equal(workspace.clientDraft.name, 'Cambio en preparación');
    assert.equal(workspace.client.name, 'Cliente seleccionado');
});

test('client economic activity is preselected from CAT-019 and remains in the billing data', () => {
    const workspace = sale();
    workspace.selectClient(client());
    assert.equal(workspace.clientDraft.activity, '12345');
    assert.equal(workspace.hasKnownActivity, true);
    workspace.clientDraft.activity = '67890';
    workspace.saveClient();
    assert.equal(workspace.client.activity, '67890');
});

test('missing economic activity stays empty and unknown codes are preserved for review', () => {
    const workspace = sale();
    workspace.selectClient({ ...client(), activity: null });
    assert.equal(workspace.clientDraft.activity, '');
    workspace.selectClient({ ...client(), activity: '99999' });
    assert.equal(workspace.clientDraft.activity, '99999');
    assert.equal(workspace.hasKnownActivity, false);
});

test('changing department clears dependent geography and only lists its own districts', () => {
    const workspace = sale();
    workspace.selectClient(client());
    workspace.clientDraft.department = '02';
    workspace.changeDraftDepartment();
    assert.equal(workspace.clientDraft.district, '');
    assert.equal(workspace.clientDraft.municipality, '');
    assert.deepEqual(workspace.availableDistricts.map(district => district.codigo), ['8']);
    workspace.clientDraft.district = '8';
    workspace.syncDraftLocation();
    assert.equal(workspace.clientDraft.municipality, '01');
    assert.equal(workspace.draftMunicipalityName, 'Municipio dos');
});

test('a district from another department cannot assign a municipality', () => {
    const workspace = sale();
    workspace.clientDraft = { department: '01', district: '8', municipality: '99' };
    workspace.syncDraftLocation();
    assert.equal(workspace.clientDraft.district, '');
    assert.equal(workspace.clientDraft.municipality, '');
});

test('payments cannot be opened before selecting a client', () => {
    const workspace = sale();
    workspace.items = [{ quantity: 1, price: 1000, discount: 0 }];
    let opens = 0;
    workspace.$flux = { modal: () => ({ show() { opens++; }, close() {} }) };
    workspace.openPayment();
    assert.equal(opens, 0);
    workspace.selectClient(client());
    workspace.saveClient();
    workspace.openPayment();
    assert.equal(opens, 1);
});

test('cash change and exact payments use CAT-017 code 01', () => {
    const workspace = sale();
    workspace.items = [{ quantity: 1, price: 1000, discount: 0 }];
    workspace.payments = [{ method: '02', amount: 15 }];
    assert.equal(workspace.change, 0);
    workspace.payments = [{ method: '01', amount: 15 }];
    assert.equal(workspace.change, 500);
    workspace.exactPayment();
    assert.equal(workspace.payments[0].method, '01');
    assert.equal(workspace.payments[0].amount, '10.00');
    workspace.addPayment();
    assert.equal(workspace.payments[1].method, '02');
});

test('resetting a sale clears the selected client and restores catalog defaults', () => {
    const workspace = sale();
    workspace.selectClient(client());
    workspace.saveClient();
    workspace.operation = '2';
    workspace.term = '03';
    workspace.document.thirdPartyType = '36';
    workspace.newSale();
    assert.equal(workspace.client.id, null);
    assert.equal(workspace.document.thirdPartyType, '');
    assert.equal(workspace.operation, '1');
    assert.equal(workspace.term, '01');
    assert.equal(workspace.payments[0].method, '01');
});

test('leaving credit operation clears credit settings using the catalog default', () => {
    const workspace = sale();
    let onOperationChange;
    workspace.$watch = (property, callback) => { if (property === 'operation') onOperationChange = callback; };
    workspace.init();
    workspace.installments = 4;
    workspace.term = '03';
    onOperationChange('1');
    assert.equal(workspace.installments, 1);
    assert.equal(workspace.term, '01');
});

test('changing the selected client clears third party identification from the previous client', () => {
    const workspace = sale();
    workspace.client.id = 1;
    workspace.document.thirdPartyType = '36';
    workspace.document.thirdPartyDocument = '0614-123456-789-0';
    workspace.selectClient(client());
    workspace.saveClient();
    assert.equal(workspace.document.thirdPartyType, '');
    assert.equal(workspace.document.thirdPartyDocument, '');
});

test('outdated client search results cannot replace a more recent search', async (t) => {
    const workspace = sale();
    workspace.saleCatalogs = { ...options, clientSearchUrl: '/libreria/clientes' };
    t.mock.property(globalThis, 'window', { location: { origin: 'http://localhost' } });
    let firstResolve;
    t.mock.method(globalThis, 'fetch', async url => {
        if (url.searchParams.get('q') === 'primero') {
            return new Promise(resolve => { firstResolve = resolve; });
        }
        return { ok: true, json: async () => ({ data: [{ id: 2, name: 'Segundo' }] }) };
    });
    workspace.clientSearch = 'primero';
    const first = workspace.searchClients();
    workspace.clientSearch = 'segundo';
    await workspace.searchClients();
    firstResolve({ ok: true, json: async () => ({ data: [{ id: 1, name: 'Primero' }] }) });
    await first;
    assert.deepEqual(workspace.clientResults, [{ id: 2, name: 'Segundo' }]);
    assert.equal(workspace.clientLoading, false);
});

test('failed client lookup clears results and exposes a retry message', async (t) => {
    const workspace = sale();
    workspace.saleCatalogs = { ...options, clientSearchUrl: '/libreria/clientes' };
    t.mock.property(globalThis, 'window', { location: { origin: 'http://localhost' } });
    t.mock.method(globalThis, 'fetch', async () => ({ ok: false }));
    await workspace.searchClients();
    assert.equal(workspace.clientLoading, false);
    assert.deepEqual(workspace.clientResults, []);
    assert.match(workspace.clientError, /Intenta buscar nuevamente/);
});
