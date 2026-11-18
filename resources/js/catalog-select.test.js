import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {} };
globalThis.window = { innerWidth: 1200, innerHeight: 800 };
const { createCatalogSelect } = await import('./catalog-select.js');

const options = [
    { value: '', label: 'Sin especificar' },
    { value: '01', label: 'Crédito fiscal' },
    { value: '02', label: 'Educación básica' },
    { value: '0', label: 'No' },
];

function select(choices = options, value = '', disabled = false) {
    const state = createCatalogSelect(choices, value, disabled);
    state.events = [];
    state.focused = '';
    state.$dispatch = (name, detail) => state.events.push({ name, detail });
    state.$nextTick = callback => callback();
    state.$refs = {
        trigger: { id: 'test-select', focus: () => { state.focused = 'trigger'; }, getBoundingClientRect: () => ({ left: 30, top: 100, bottom: 144, width: 400 }) },
        search: { focus: () => { state.focused = 'search'; } },
        list: { querySelector: () => null },
    };
    return state;
}

test('search matches names and codes regardless of accents case and word order', () => {
    const state = select();

    state.query = 'BASICA educacion';
    assert.deepEqual(state.visibleOptions.map(option => option.value), ['02']);
    state.query = '01 CREDITO';
    assert.deepEqual(state.visibleOptions.map(option => option.value), ['01']);
    state.query = 'inexistente';
    assert.deepEqual(state.visibleOptions, []);
});

test('large catalogs cap visible rows while searching the complete catalog', () => {
    const state = select(Array.from({ length: 6500 }, (_, index) => ({ value: String(index), label: `Cuenta ${index}` })));

    assert.equal(state.visibleOptions.length, 60);
    assert.equal(state.matches.length, 6500);
    state.query = '6499';
    assert.deepEqual(state.visibleOptions, [{ value: '6499', label: 'Cuenta 6499' }]);
});

test('selection preserves raw codes including zero and dispatches the change after closing', () => {
    const state = select();
    state.open = true;

    state.choose('0');

    assert.equal(state.value, '0');
    assert.equal(state.selectedLabel, 'No');
    assert.equal(state.open, false);
    assert.equal(state.focused, 'trigger');
    assert.deepEqual(state.events, [{ name: 'change', detail: undefined }]);
});

test('disabled and unknown options cannot modify the selected value', () => {
    const state = select(options, '01', true);

    state.openMenu();
    state.choose('02');
    assert.equal(state.open, false);
    assert.equal(state.value, '01');
    state.disabled = false;
    state.choose('999');
    assert.equal(state.value, '01');
    assert.deepEqual(state.events, []);
});

test('keyboard navigation opens the menu focuses search and selects the active option', () => {
    const state = select(options, '01');

    state.move(1);
    assert.equal(state.open, true);
    assert.equal(state.focused, 'search');
    assert.equal(state.active, 1);
    state.move(1);
    state.chooseActive();

    assert.equal(state.value, '02');
    assert.equal(state.open, false);
    assert.equal(state.focused, 'trigger');
});

test('opening near the bottom places the panel above and within the viewport', () => {
    const state = select();
    state.$refs.trigger.getBoundingClientRect = () => ({ left: 1100, top: 700, bottom: 744, width: 400 });

    state.openMenu();

    assert.equal(state.panelStyle.top, 'auto');
    assert.equal(state.panelStyle.bottom, '106px');
    assert.equal(state.panelStyle.left, '788px');
    assert.equal(state.panelStyle.width, '400px');
    assert.equal(state.panelStyle.maxHeight, '360px');
});

test('dependent options update without losing their code or exposing choices from another department', () => {
    const state = select([{ value: '7', label: 'Santa Tecla' }], '7');

    state.setOptions([{ value: '8', label: 'Otro distrito' }], true);

    assert.deepEqual(state.visibleOptions.map(option => option.value), ['8']);
    state.choose('8');
    assert.equal(state.value, '7');
    state.setOptions([{ value: '8', label: 'Otro distrito' }]);
    state.choose('8');
    assert.equal(state.value, '8');
});
