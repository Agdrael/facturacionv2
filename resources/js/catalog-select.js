function normalize(text) {
    return String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

export function createCatalogSelect(options = [], value = '', disabled = false) {
    return {
        options,
        value: String(value ?? ''),
        disabled,
        open: false,
        query: '',
        active: 0,
        panelStyle: {},

        get matches() {
            const words = normalize(this.query).trim().split(/\s+/).filter(Boolean);
            return this.options.filter(option => words.every(word => normalize(`${option.value} ${option.label}`).includes(word)));
        },

        get visibleOptions() {
            return this.matches.slice(0, 60);
        },

        get selectedLabel() {
            return this.options.find(option => String(option.value) === this.value)?.label || this.value;
        },

        setOptions(options, disabled = false) {
            this.options = options;
            this.disabled = disabled;
        },

        openMenu(last = false) {
            if (this.disabled) return;
            this.$dispatch('catalog-select-open', this.$refs.trigger.id);
            const rect = this.$refs.trigger.getBoundingClientRect();
            const below = window.innerHeight - rect.bottom - 16;
            const above = rect.top - 16;
            const upwards = below < 260 && above > below;
            const height = Math.min(360, Math.max(120, upwards ? above : below));
            const width = Math.min(Math.max(rect.width, 280), window.innerWidth - 24);
            this.panelStyle = {
                left: `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`,
                top: upwards ? 'auto' : `${rect.bottom + 6}px`,
                bottom: upwards ? `${window.innerHeight - rect.top + 6}px` : 'auto',
                width: `${width}px`,
                maxHeight: `${height}px`,
            };
            this.query = '';
            this.active = last ? this.visibleOptions.length - 1 : Math.max(0, this.visibleOptions.findIndex(option => String(option.value) === this.value));
            this.open = true;
            this.$nextTick(() => {
                if (!this.open) return;
                const panel = this.$refs.panel;
                const dialog = this.$refs.trigger.closest?.('dialog');
                if (panel && dialog && panel.parentElement !== dialog) dialog.appendChild(panel);
                panel?.showPopover?.();
                this.$refs.search.focus();
                this.scrollActive();
            });
        },

        closeMenu(focus = false) {
            this.open = false;
            const panel = this.$refs.panel;
            if (panel?.hidePopover && panel.matches(':popover-open')) panel.hidePopover();
            if (focus) this.$refs.trigger.focus();
        },

        destroy() {
            this.closeMenu();
        },

        move(step) {
            if (!this.open) return this.openMenu(step < 0);
            this.active = Math.max(0, Math.min(this.visibleOptions.length - 1, this.active + step));
            this.$nextTick(() => this.scrollActive());
        },

        scrollActive() {
            this.$refs.list.querySelector(`[data-option-index="${this.active}"]`)?.scrollIntoView({ block: 'nearest' });
        },

        choose(value) {
            if (this.disabled || !this.options.some(option => String(option.value) === String(value))) return;
            this.value = String(value);
            this.closeMenu(true);
            this.$nextTick(() => this.$refs.trigger.dispatchEvent(new CustomEvent('change', {
                bubbles: true, detail: { value: this.value },
            })));
        },

        chooseActive() {
            const option = this.visibleOptions[this.active];
            if (option) this.choose(option.value);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('catalogSelect', createCatalogSelect);
});
