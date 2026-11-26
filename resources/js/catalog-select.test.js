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
        trigger: { id: 'test-select', focus: () => { state.focused = 'trigger'; }, getBoundingClientRect: () => ({ left: 30, top: 100, bottom: 144, width: 400 }),
            dispatchEvent: event => { state.events.push({ name: event.type, detail: event.detail }); } },
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
    assert.deepEqual(state.events, [{ name: 'change', detail: { value: '0' } }]);
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

test('selection notifies the form from its trigger instead of the teleported options panel', () => {
    const state = select();
    const trigger = new EventTarget();
    const changes = [];
    state.$dispatch = () => assert.fail('Selection changes must originate inside the form');
    state.$refs.trigger.dispatchEvent = event => trigger.dispatchEvent(event);
    trigger.addEventListener('change', event => {
        changes.push({ target: event.target, value: event.detail.value, bubbles: event.bubbles });
    });

    state.choose('02');

    assert.equal(changes.length, 1);
    assert.equal(changes[0].target, trigger);
    assert.equal(changes[0].value, '02');
    assert.equal(changes[0].bubbles, true);
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

test('selects inside dialogs keep their search panel in the top layer and remove it on close', () => {
    const state = select();
    const dialog = { appendChild(panel) { panel.parentElement = this; } };
    let showing = false;
    state.$refs.trigger.closest = () => dialog;
    state.$refs.panel = { parentElement: null, showPopover() { showing = true; }, hidePopover() { showing = false; }, matches: () => showing };

    state.openMenu();

    assert.equal(state.$refs.panel.parentElement, dialog);
    assert.equal(showing, true);
    state.choose('02');
    assert.equal(state.value, '02');
    assert.equal(showing, false);
    state.openMenu();
    state.destroy();
    assert.equal(showing, false);
    assert.equal(state.open, false);
});

test('a pending open does not show a stale panel after closing', () => {
    const state = select();
    let onOpen;
    state.$nextTick = callback => { onOpen = callback; };
    state.openMenu();
    state.closeMenu();
    onOpen();
    assert.equal(state.open, false);
    assert.equal(state.focused, '');
});
