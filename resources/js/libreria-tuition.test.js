import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {} };
const { createSaleWorkspace } = await import('./libreria-sale.js');

function prepareSale(options = {}) {
    const sale = createSaleWorkspace({ studentSearchUrl: '/libreria/alumnos', ...options });
    sale.items = [];
    sale.$nextTick = () => {};
    sale.$flux = { modal: () => ({ show() {}, close() {} }) };
    sale.tuitionRequest = async url => {
        const parsed = new URL(url, 'https://example.test');
        if (parsed.pathname === '/libreria/alumnos') return { data: [student()], current_page: 1, last_page: 1, total: 1 };
        const id = parsed.pathname.split('/')[3];
        const selected = student();
        if (id === 'student-second') Object.assign(selected, { id, code: 'TEST-002', name: 'Segundo alumno' });
        if (!parsed.pathname.endsWith('/cuotas')) return { student: selected, responsibles: Object.entries(selected.billing).map(([id, billing]) => ({ id, label: id, billing })) };
        const responsible = parsed.searchParams.get('responsable');
        const funding = ['beca', 'bienhechor'].includes(responsible) ? responsible : 'normal';
        return { fees: selected.fees.map(fee => ({ ...fee, balanceId: fee.id, schoolYear: 2025, month: 2, fundingType: funding, product: academicProduct(funding) })) };
    };
    return sale;
}

function student() {
    return {
        id: 'student-test',
        name: 'Alumno de prueba',
        code: 'TEST-001',
        billing: {
            custom_275: { name: 'Responsable familiar de prueba', documentType: '13', document: '01234567-8', email: 'responsable@example.test', address: 'Dirección familiar', phone: '2222-3333' },
            custom_300: { name: 'Segundo responsable de prueba' },
            custom_304: { name: 'Tercer responsable de prueba' },
            clientes_varios: { name: 'Clientes varios' },
            bienhechor: { name: 'Bienhechor de prueba' },
            beca: { name: 'Beca de prueba' },
        },
        fees: [
            { id: 'paid', code: 'MAT-01', name: 'Matrícula', quota: 10000, paid: 10000, remaining: 0, product: academicProduct(), canSelect: true },
            { id: 'partial', code: 'COL-01', name: 'Colegiatura con abono', quota: 10000, paid: 2500, remaining: 7500, product: academicProduct(), canSelect: true },
            { id: 'pending', code: 'COL-02', name: 'Colegiatura pendiente', quota: 10000, paid: 0, remaining: 10000, product: academicProduct(), canSelect: true },
        ],
    };
}

function academicProduct(funding = 'normal') {
    const id = { normal: 501, beca: 502, bienhechor: 503 }[funding];
    return { id, code: `COL-${id}`, name: 'Producto de colegiatura', incomeAccount: `${id}001`, origin: 'COLEGIATURA', taxType: 'E', priceType: 'F', unit: '99' };
}

test('academic sale rows keep real product identifiers account month and student while charging the remaining balance', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'clientes_varios';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['partial'];

    sale.confirmTuitionFees();

    assert.equal(sale.items[0].id, 'tuition:student-test:partial');
    assert.equal(sale.items[0].productId, 501);
    assert.equal(sale.items[0].code, 'COL-501');
    assert.equal(sale.items[0].incomeAccount, '501001');
    assert.equal(sale.items[0].priceType, 'F');
    assert.equal(sale.items[0].taxable, false);
    assert.equal(sale.items[0].price, 7500);
    assert.equal(sale.items[0].tuition.amount, 7500);
    assert.equal(sale.items[0].tuition.barcode, 'COL-01');
    assert.equal(sale.items[0].tuition.studentId, 'student-test');
    assert.equal(sale.items[0].tuition.month, 2);
});

test('fees without a configured product remain visible but cannot be selected or added even by setting their identifiers', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'clientes_varios';
    sale.tuitionRequest = async () => ({ fees: [{ ...student().fees[1], product: null, canSelect: false, configurationError: 'Falta producto.' }] });
    const shown = [];
    sale.$flux.modal = name => ({ show: () => shown.push(name), close() {} });

    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['partial'];
    sale.confirmTuitionFees();

    assert.equal(sale.tuitionFees.length, 1);
    assert.deepEqual(sale.selectableTuitionFees, []);
    assert.equal(sale.items.length, 0);
    assert.equal(sale.tuitionConfigurationErrors.length, 1);
    assert.deepEqual(shown, ['sale-tuition-configuration']);
});

test('academic amounts stay fixed when quantity line discounts and general discounts are changed', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'clientes_varios';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['partial'];
    sale.confirmTuitionFees();
    sale.items[0].quantity = 3;
    sale.items[0].discount = 100;
    sale.items[0].price = 1;
    sale.document.discount = 50;
    sale.addProduct({ id: 'ordinary', code: 'P1', name: 'Producto', price: 10000, taxable: true, discountAllowed: true });

    assert.equal(sale.lineSubtotal(sale.items[0]), 7500);
    assert.equal(sale.lineDiscount(sale.items[0]), 0);
    assert.equal(sale.subtotal, 17500);
    assert.equal(sale.discount, 5000);
    assert.equal(sale.total, 12500);
});

for (const type of ['custom_275', 'custom_300', 'custom_304', 'clientes_varios', 'bienhechor', 'beca']) {
    test(`${type} adds the remaining balance and preserves the student and responsible type`, async () => {
        const sale = prepareSale();
        const selectedStudent = student();
        await sale.selectTuitionStudent(selectedStudent);
        assert.equal(sale.tuitionFees.length, 0);
        sale.tuitionResponsibleType = type;
        await sale.loadTuitionFees();
        sale.tuitionSelectedFeeIds = ['paid', 'partial', 'unknown'];

        assert.equal(sale.tuitionSelectedTotal, 7500);
        sale.confirmTuitionFees();

        assert.equal(sale.items.length, 1);
        assert.equal(sale.total, 7500);
        assert.equal(sale.client.id, null);
        assert.equal(sale.client.name, selectedStudent.billing[type].name);
        assert.equal(sale.hasBillingClient, true);
        assert.equal(sale.items[0].tuition.studentId, 'student-test');
        assert.equal(sale.items[0].tuition.feeId, 'partial');
        assert.equal(sale.items[0].tuition.responsibleType, type);
    });
}

for (const edited of [false, true]) {
    test(`tuition ${edited ? 'preserves an edited' : 'replaces the automatic'} default billing client`, async () => {
        const sale = prepareSale({ defaultClient: { id: 9, name: 'CLIENTES VARIOS', documents: {} } });
        if (edited) {
            sale.editClient();
            sale.clientDraft.email = 'manual@example.test';
            sale.saveClient();
        }
        await sale.selectTuitionStudent(student());
        sale.tuitionResponsibleType = 'custom_275';
        await sale.loadTuitionFees();
        sale.tuitionSelectedFeeIds = ['partial'];

        sale.confirmTuitionFees();

        assert.equal(sale.items.length, 1);
        assert.equal(sale.client.name, edited ? 'CLIENTES VARIOS' : student().billing.custom_275.name);
        assert.equal(sale.client.id, edited ? 9 : null);
        assert.equal(sale.clientIsDefault, false);
        if (edited) assert.equal(sale.client.email, 'manual@example.test');
    });
}

test('already added and fully paid fees cannot be added a second time', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'custom_275';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['partial'];
    sale.confirmTuitionFees();
    const initialClient = structuredClone(sale.client);

    sale.tuitionResponsibleType = 'custom_300';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['paid', 'partial'];
    sale.confirmTuitionFees();
    assert.equal(sale.items.length, 1);
    assert.deepEqual(sale.client, initialClient);
    sale.addProduct(sale.items[0]);
    assert.equal(sale.items[0].quantity, 1);

    sale.toggleAllTuitionFees();
    assert.deepEqual(sale.tuitionSelectedFeeIds, ['pending']);
    assert.equal(sale.tuitionSelectedTotal, 10000);
});

test('adding fees loads the parent billing details and allows editing and payment without selecting a catalog client', async () => {
    const sale = prepareSale();
    const opened = [];
    sale.$flux.modal = name => ({ show: () => opened.push(name), close() {} });
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'custom_275';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['pending'];

    sale.confirmTuitionFees();
    sale.editClient();

    assert.equal(sale.client.id, null);
    assert.equal(sale.clientDraft.name, 'Responsable familiar de prueba');
    assert.equal(sale.clientDraft.document, '01234567-8');
    assert.equal(sale.clientDraft.email, 'responsable@example.test');
    assert.equal(sale.clientDraft.address, 'Dirección familiar');
    assert.equal(sale.clientDraft.phone, '2222-3333');
    assert.equal(sale.hasBillingDraft, true);
    assert.deepEqual(opened, ['sale-client']);

    sale.clientDraft.email = 'corregido@example.test';
    sale.saveClient();
    sale.openPayment();

    assert.equal(sale.client.email, 'corregido@example.test');
    assert.equal(opened.at(-1), 'sale-payment');
});

test('adding another student with fallback billing preserves the first parent and manual corrections', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'custom_275';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['partial'];
    sale.confirmTuitionFees(true);
    sale.editClient();
    sale.clientDraft.email = 'corregido@example.test';
    sale.saveClient();
    const billing = structuredClone(sale.client);

    await sale.selectTuitionStudent({ ...student(), id: 'student-second' });
    sale.tuitionResponsibleType = 'beca';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['pending'];
    sale.confirmTuitionFees();

    assert.equal(sale.items.length, 2);
    assert.deepEqual(sale.client, billing);
    sale.newSale();
    assert.equal(sale.hasBillingClient, false);
    assert.equal(sale.hasBillingDraft, false);
});

test('changing the responsible type clears selection and changing student clears both type and fees', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'custom_275';
    await sale.loadTuitionFees();
    sale.toggleAllTuitionFees();
    assert.equal(sale.tuitionSelectedTotal, 17500);

    sale.tuitionResponsibleType = 'clientes_varios';
    await sale.loadTuitionFees();
    assert.equal(sale.tuitionSelectedTotal, 0);
    await sale.selectTuitionStudent({ ...student(), id: 'second-student' });
    assert.equal(sale.tuitionResponsibleType, '');
    assert.equal(sale.tuitionFees.length, 0);
    assert.equal(sale.selectedTuitionFees.length, 0);
});

test('confirming without a student or selection leaves the sale unchanged and resetting clears the student', async () => {
    const sale = prepareSale();
    const initialClient = structuredClone(sale.client);
    sale.confirmTuitionFees();
    assert.equal(sale.items.length, 0);
    assert.deepEqual(sale.client, initialClient);

    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'beca';
    await sale.loadTuitionFees();
    sale.confirmTuitionFees();
    assert.equal(sale.items.length, 0);

    sale.newSale();
    assert.equal(sale.tuitionStudent, null);
    assert.equal(sale.tuitionResponsibleType, '');
    assert.equal(sale.tuitionFees.length, 0);
});

test('tuition keeps the real billing client unchanged and stores the student on each line', async () => {
    const sale = prepareSale();
    sale.client = { ...sale.client, id: 44, name: 'Cliente real', documentType: '36', document: '0614-123456-789-0' };
    const initialClient = structuredClone(sale.client);
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'custom_275';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['pending'];

    sale.confirmTuitionFees();

    assert.deepEqual(sale.client, initialClient);
    assert.equal(sale.items[0].tuition.studentName, 'Alumno de prueba');
    assert.equal(sale.items[0].tuition.studentCode, 'TEST-001');
});

test('one sale accepts different fees from different students without losing their metadata or billing client', async () => {
    const sale = prepareSale();
    sale.client = { ...sale.client, id: 44, name: 'Responsable de ambos alumnos' };
    const billingClient = structuredClone(sale.client);
    const firstStudent = student();
    const secondStudent = { ...student(), id: 'student-second', code: 'TEST-002', name: 'Segundo alumno' };
    await sale.selectTuitionStudent(firstStudent);
    sale.tuitionResponsibleType = 'custom_275';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['partial', 'pending'];
    sale.confirmTuitionFees(true);

    assert.equal(sale.tuitionStudent, null);
    assert.equal(sale.tuitionChoosingStudent, true);
    assert.equal(sale.items.length, 2);
    const firstFee = structuredClone(sale.items[0]);

    await sale.selectTuitionStudent(secondStudent);
    sale.tuitionResponsibleType = 'bienhechor';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['pending'];
    sale.confirmTuitionFees();

    assert.equal(sale.items.length, 3);
    assert.equal(sale.total, 27500);
    assert.equal(new Set(sale.items.map(item => item.id)).size, 3);
    assert.deepEqual(sale.items[0], firstFee);
    assert.equal(sale.items[2].tuition.studentCode, 'TEST-002');
    assert.equal(sale.items[2].tuition.responsibleType, 'bienhechor');
    assert.deepEqual(sale.client, billingClient);
    assert.deepEqual(sale.tuitionStudentsInSale, [
        { id: 'student-test', name: 'Alumno de prueba', code: 'TEST-001', feeCount: 2 },
        { id: 'student-second', name: 'Segundo alumno', code: 'TEST-002', feeCount: 1 },
    ]);
    assert.equal(sale.tuitionStudentsLabel, '2 alumnos');
});

test('the same fee is allowed for another student while returning to the original student prevents duplicates', async () => {
    const sale = prepareSale();
    const firstStudent = student();
    const secondStudent = { ...student(), id: 'student-second', code: 'TEST-002', name: 'Segundo alumno' };
    for (const selected of [firstStudent, secondStudent]) {
        await sale.selectTuitionStudent(selected);
        sale.tuitionResponsibleType = 'custom_275';
        await sale.loadTuitionFees();
        sale.tuitionSelectedFeeIds = ['pending'];
        sale.confirmTuitionFees(true);
    }

    await sale.selectTuitionStudent(firstStudent);
    sale.tuitionResponsibleType = 'custom_300';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['pending'];
    sale.confirmTuitionFees();

    assert.equal(sale.items.length, 2);
    assert.equal(sale.items[0].quantity, 1);
    assert.equal(sale.items[1].quantity, 1);
    assert.equal(sale.total, 20000);
    assert.equal(sale.selectedTuitionFees.length, 0);
});

test('removing a student fee updates the student summary without removing another student or regular products', async () => {
    const sale = prepareSale();
    sale.addProduct({ id: 'product-test', code: 'P1', name: 'Producto', category: 'productos', price: 100, taxable: true });
    const firstStudent = student();
    const secondStudent = { ...student(), id: 'student-second', code: 'TEST-002', name: 'Segundo alumno' };
    for (const selected of [firstStudent, secondStudent]) {
        await sale.selectTuitionStudent(selected);
        sale.tuitionResponsibleType = 'custom_275';
        await sale.loadTuitionFees();
        sale.tuitionSelectedFeeIds = ['pending'];
        sale.confirmTuitionFees();
    }

    sale.removeProduct('tuition:student-test:pending');

    assert.equal(sale.items.length, 2);
    assert.equal(sale.total, 10100);
    assert.equal(sale.tuitionStudentsLabel, 'Segundo alumno');
    assert.equal(sale.tuitionStudentsInSale[0].code, 'TEST-002');
    sale.removeProduct('tuition:student-second:pending');
    assert.equal(sale.tuitionStudentsLabel, '');
    assert.equal(sale.tuitionStudentsInSale.length, 0);
    assert.equal(sale.items[0].id, 'product-test');
});

test('student searches send the query and page to the server and use its pagination', async () => {
    const sale = prepareSale();
    sale.tuitionStudentSearch = ' Ana Maria ';
    sale.tuitionRequest = async url => {
        assert.equal(url, '/libreria/alumnos?q=Ana+Maria&page=2');
        return { data: [student()], current_page: 2, last_page: 5, total: 42 };
    };
    await sale.searchTuitionStudents(2);
    assert.equal(sale.tuitionStudents.length, 1);
    assert.equal(sale.tuitionStudentPage, 2);
    assert.equal(sale.tuitionStudentPages, 5);
    assert.equal(sale.tuitionTotalStudents, 42);
    assert.equal(sale.tuitionStudentsLoading, false);
});

test('late student searches cannot overwrite the latest result', async () => {
    const sale = prepareSale();
    let completeFirst;
    sale.tuitionRequest = url => url.includes('q=first')
        ? new Promise(resolve => { completeFirst = resolve; })
        : Promise.resolve({ data: [student()], current_page: 1, last_page: 1, total: 1 });
    sale.tuitionStudentSearch = 'first';
    const first = sale.searchTuitionStudents();
    sale.tuitionStudentSearch = 'second';
    await sale.searchTuitionStudents();
    completeFirst({ data: [], current_page: 2, last_page: 2, total: 0 });
    await first;
    assert.equal(sale.tuitionStudents[0].id, 'student-test');
    assert.equal(sale.tuitionStudentPage, 1);
    assert.equal(sale.tuitionTotalStudents, 1);
});

test('late responsible details cannot replace the newly selected student', async () => {
    const sale = prepareSale();
    const request = sale.tuitionRequest;
    let completeFirst;
    sale.tuitionRequest = url => url.endsWith('/student-test')
        ? new Promise(resolve => { completeFirst = resolve; }) : request(url);
    const first = sale.selectTuitionStudent(student());
    await sale.selectTuitionStudent({ ...student(), id: 'student-second' });
    completeFirst({ student: student(), responsibles: [] });
    await first;
    assert.equal(sale.tuitionStudent.id, 'student-second');
    assert.equal(sale.tuitionResponsibleTypes.length, 6);
    assert.equal(sale.tuitionStudentLoading, false);
});

test('changing responsible discards the previous fee response and clears the old selection during loading', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'custom_275';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['partial'];
    let completeFirst;
    sale.tuitionRequest = url => url.includes('responsable=custom_300')
        ? new Promise(resolve => { completeFirst = resolve; })
        : Promise.resolve({ fees: [{ ...student().fees[2], id: 'beca:pending', fundingType: 'beca' }] });
    sale.tuitionResponsibleType = 'custom_300';
    const first = sale.loadTuitionFees();
    assert.equal(sale.selectedTuitionFees.length, 0);
    assert.equal(sale.tuitionFeesLoading, true);
    sale.confirmTuitionFees();
    assert.equal(sale.items.length, 0);
    sale.tuitionResponsibleType = 'beca';
    await sale.loadTuitionFees();
    completeFirst({ fees: student().fees });
    await first;
    assert.equal(sale.tuitionFees[0].id, 'beca:pending');
    assert.equal(sale.tuitionFeesLoading, false);
});

test('a reset discards pending student and fee responses', async () => {
    const sale = prepareSale();
    let completeDetails;
    const request = sale.tuitionRequest;
    sale.tuitionRequest = () => new Promise(resolve => { completeDetails = resolve; });
    const details = sale.selectTuitionStudent(student());
    sale.resetTuition();
    completeDetails({ student: student(), responsibles: [] });
    await details;
    assert.equal(sale.tuitionStudent, null);
    sale.tuitionRequest = request;
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'custom_275';
    let completeFees;
    sale.tuitionRequest = () => new Promise(resolve => { completeFees = resolve; });
    const fees = sale.loadTuitionFees();
    sale.newSale();
    completeFees({ fees: student().fees });
    await fees;
    assert.equal(sale.tuitionStudent, null);
    assert.deepEqual(sale.tuitionFees, []);
    assert.equal(sale.tuitionFeesLoading, false);
});

test('lookup failures expose a retry message and never reuse old fees or responsible data', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionResponsibleType = 'custom_275';
    await sale.loadTuitionFees();
    sale.tuitionSelectedFeeIds = ['partial'];
    sale.tuitionRequest = async () => { throw new Error('network'); };
    await sale.loadTuitionFees();
    sale.confirmTuitionFees();
    assert.equal(sale.items.length, 0);
    assert.deepEqual(sale.tuitionFees, []);
    assert.match(sale.tuitionFeesError, /Intenta nuevamente/);
    assert.equal(sale.tuitionFeesLoading, false);
    await sale.selectTuitionStudent({ ...student(), id: 'student-second' });
    assert.deepEqual(sale.tuitionResponsibleTypes, []);
    assert.match(sale.tuitionStudentError, /Intenta nuevamente/);
    assert.equal(sale.tuitionStudentLoading, false);
    await sale.searchTuitionStudents();
    assert.deepEqual(sale.tuitionStudents, []);
    assert.match(sale.tuitionStudentsError, /Intenta nuevamente/);
    assert.equal(sale.tuitionStudentsLoading, false);
});

test('a normal balance and scholarship part can be combined and the authoritative remaining value is preserved', async () => {
    const sale = prepareSale();
    await sale.selectTuitionStudent(student());
    sale.tuitionRequest = async url => {
        const source = url.includes('responsable=beca') ? 'beca' : 'normal';
        return { fees: [{ ...student().fees[1], id: `${source}:12`, balanceId: '12', schoolYear: 2025, month: 3,
            fundingType: source, remaining: source === 'normal' ? 6000 : 1000 }] };
    };
    for (const [responsible, funding] of [['custom_275', 'normal'], ['beca', 'beca']]) {
        sale.tuitionResponsibleType = responsible;
        await sale.loadTuitionFees();
        sale.tuitionSelectedFeeIds = [`${funding}:12`];
        sale.confirmTuitionFees();
    }
    assert.equal(sale.items.length, 2);
    assert.equal(sale.total, 7000);
    assert.equal(sale.items[1].tuition.fundingType, 'beca');
    assert.equal(sale.items[0].tuition.balanceId, '12');
    assert.equal(sale.items[0].tuition.month, 3);
    assert.equal(sale.items[0].tuition.schoolYear, 2025);
    assert.equal(sale.items[0].tuition.barcode, 'COL-01');
});
