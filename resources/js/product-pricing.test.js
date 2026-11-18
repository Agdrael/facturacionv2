import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { addEventListener() {} };
const { createProductPricing } = await import('./product-pricing.js');

test('taxable products calculate the gross price while keeping the stored net price', () => {
    const price = createProductPricing('10', 'A');

    price.init();

    assert.equal(price.netPrice, '10');
    assert.equal(price.grossPrice, '11.3');
    price.fromNet('0.1');
    assert.equal(price.grossPrice, '0.113');
});

test('entering a gross price immediately calculates the net price to six decimals', () => {
    const price = createProductPricing('', 'A');

    price.fromGross('4.15');

    assert.equal(price.netPrice, '3.672566');
    assert.equal(price.grossPrice, '4.15');
    price.fromGross('113');
    assert.equal(price.netPrice, '100');
});

test('exempt products mirror both prices without adding or removing tax', () => {
    const price = createProductPricing('4.123456', 'E');

    price.init();
    assert.equal(price.grossPrice, '4.123456');
    price.fromGross('9.99');
    assert.equal(price.netPrice, '9.99');
});

test('changing classification preserves the net price and recalculates its gross counterpart', () => {
    const price = createProductPricing('10', 'A');
    price.init();

    price.taxType = 'E';
    price.changeTaxType();
    assert.equal(price.netPrice, '10');
    assert.equal(price.grossPrice, '10');
    price.taxType = 'A';
    price.changeTaxType();
    assert.equal(price.grossPrice, '11.3');
});

test('clearing either field clears its counterpart while zero remains a valid price', () => {
    const price = createProductPricing('10', 'A');

    price.fromNet('');
    assert.equal(price.grossPrice, '');
    price.fromGross('0');
    assert.equal(price.netPrice, '0');
    price.fromGross('');
    assert.equal(price.netPrice, '');
});

test('legacy N classification cannot calculate tax until a supported classification is chosen', () => {
    const price = createProductPricing('10', 'N');

    price.init();

    assert.equal(price.taxType, '');
    assert.equal(price.hasTaxType, false);
    assert.equal(price.netPrice, '10');
    assert.equal(price.grossPrice, '');
});

test('invalid helper input does not overwrite the last valid net price and reports an error', () => {
    const price = createProductPricing('10', 'A');

    for (const value of ['-1', '1e2', '1.1234567', 'abc']) {
        price.fromGross(value);
        assert.equal(price.netPrice, '10');
        assert.notEqual(price.grossError, '');
    }
    price.fromGross('11.30');
    assert.equal(price.netPrice, '10');
    assert.equal(price.grossError, '');
});

test('decimal conversion rounds precisely at the database precision and maximum net price', () => {
    const price = createProductPricing('99999.999999', 'A');

    price.init();

    assert.equal(price.grossPrice, '112999.999999');
    price.fromGross(price.grossPrice);
    assert.equal(price.netPrice, '99999.999999');
    price.fromNet('1.000001');
    assert.equal(price.grossPrice, '1.130001');
});
