import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {} };
const { createCatalogLocation } = await import('./contaduria-catalog.js');

const districts = [
    { value: '7', label: 'Santa Tecla', department: '05', municipalityCode: '09', municipalityName: 'La Libertad Sur' },
    { value: '8', label: 'Otro distrito', department: '01', municipalityCode: '01', municipalityName: 'Otro municipio' },
];

test('editing restores the district and assigns its actual municipality', () => {
    const location = createCatalogLocation(districts, { departments: '05', districts: '7', municipalities: '99' });

    location.init();

    assert.deepEqual(location.availableDistricts.map(option => option.value), ['7']);
    assert.equal(location.district, '7');
    assert.equal(location.municipality, '09');
    assert.equal(location.municipalityLabel, '09 · La Libertad Sur');
});

test('changing department clears the old district and municipality and filters the next choices', () => {
    const location = createCatalogLocation(districts, { departments: '05', districts: '7', municipalities: '09' });

    location.department = '01';
    location.changeDepartment();

    assert.equal(location.district, '');
    assert.equal(location.municipality, '');
    assert.deepEqual(location.availableDistricts.map(option => option.value), ['8']);
});

test('selecting a district sets the municipality while clearing or invalid districts remove it', () => {
    const location = createCatalogLocation(districts, { departments: '05' });

    location.district = '7';
    location.changeDistrict();
    assert.equal(location.municipality, '09');

    location.district = '';
    location.changeDistrict();
    assert.equal(location.municipality, '');

    location.district = '8';
    location.changeDistrict();
    assert.equal(location.municipality, '');
});

test('new forms start without a location or districts until a department is selected', () => {
    const location = createCatalogLocation(districts);

    location.init();

    assert.equal(location.department, '');
    assert.equal(location.district, '');
    assert.equal(location.municipalityLabel, '');
    assert.deepEqual(location.availableDistricts, []);
});
