function convertPrice(value, numerator, denominator) {
    const match = /^(\d+)(?:\.(\d{0,6}))?$/.exec(String(value));
    if (!match) return null;
    const units = BigInt(match[1]) * 1000000n + BigInt((match[2] || '').padEnd(6, '0'));
    const divisor = BigInt(denominator);
    const result = (units * BigInt(numerator) + divisor / 2n) / divisor;
    const fraction = String(result % 1000000n).padStart(6, '0').replace(/0+$/, '');
    return `${result / 1000000n}${fraction ? `.${fraction}` : ''}`;
}

export function createProductPricing(price = '', taxType = 'A') {
    return {
        netPrice: String(price ?? ''),
        grossPrice: '',
        grossError: '',
        taxType: ['A', 'E'].includes(taxType) ? taxType : '',

        get hasTaxType() {
            return ['A', 'E'].includes(this.taxType);
        },

        init() {
            this.fromNet(this.netPrice);
        },

        fromNet(value) {
            this.netPrice = String(value);
            this.grossError = '';
            this.grossPrice = this.hasTaxType ? (convertPrice(this.netPrice, this.taxType === 'A' ? 113 : 100, 100) ?? '') : '';
        },

        fromGross(value) {
            this.grossPrice = String(value);
            this.grossError = '';
            if (this.grossPrice === '') {
                this.netPrice = '';
                return;
            }
            const converted = this.hasTaxType ? convertPrice(this.grossPrice, 100, this.taxType === 'A' ? 113 : 100) : null;
            if (converted === null) {
                this.grossError = 'Introduce un precio válido con hasta seis decimales.';
                return;
            }
            this.netPrice = converted;
        },

        changeTaxType() {
            this.fromNet(this.netPrice);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('productPricing', createProductPricing);
});
