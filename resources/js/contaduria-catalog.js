export function createCatalogLocation(districts = [], initial = {}) {
    return {
        districts,
        department: String(initial.departments || ''),
        district: String(initial.districts || ''),
        municipality: String(initial.municipalities || ''),

        init() {
            if (this.selectedDistrict) {
                this.municipality = this.selectedDistrict.municipalityCode;
            }
        },

        get availableDistricts() {
            return this.districts.filter(district => district.department === this.department);
        },

        get selectedDistrict() {
            return this.availableDistricts.find(district => district.value === this.district);
        },

        get municipalityLabel() {
            return this.selectedDistrict
                ? `${this.selectedDistrict.municipalityCode} · ${this.selectedDistrict.municipalityName}`
                : this.municipality;
        },

        changeDepartment() {
            this.district = '';
            this.municipality = '';
        },

        changeDistrict() {
            this.municipality = this.selectedDistrict?.municipalityCode || '';
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('catalogLocation', createCatalogLocation);
});
