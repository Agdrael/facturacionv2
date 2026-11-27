export function createReceiptWorkspace(options = {}) {
    const blank = () => ({ recipient: { name: '', clientId: null, documentType: '', document: '' }, conceptId: '', sourceId: null, sourceKey: null,
        studentId: '', studentName: '', year: options.year, funding: 'normal', invoice: null, invoiceAutomatic: false, invoiceConfirmed: false,
        account: '', amount: '', observation: '', payments: [{ uid: 1, method: options.methods?.[0]?.codigo || '01', amount: '', reference: '' }] });
    return {
        options, mode: 'catalog', form: blank(), sequence: 1, busy: false, notice: '', requestKey: null, submittedPayload: null, completed: null,
        paymentExcess: null, paymentApproval: null,
        selection: null, selecting: false, selectionSequence: 0, invoices: [], invoiceQuery: '', invoiceChoice: '', academicItems: [],
        lookupKind: 'clients', lookupQuery: '', lookupYear: '', lookupFunding: 'normal', lookupRows: [], lookupPage: 1, lookupPages: 1, lookupLoading: false, lookupSequence: 0,
        cxcDiagnostic: null, diagnosticLoading: false, diagnosticError: '', diagnosticSequence: 0,
        history: [], historyLoading: false, historyQuery: '', historyLegacy: false, historyPage: 1, historyPages: 1, historySequence: 0,
        get historyOrigin() { return this.historyLegacy ? 'legacy' : 'current'; },
        set historyOrigin(value) { this.historyLegacy = value === 'legacy'; },
        annulment: { row: null, reason: '' },
        get locked() { return this.busy || this.submittedPayload !== null || this.completed !== null; },
        get concept() { return options.concepts.find(row => String(row.id) === String(this.form.conceptId)) || null; },
        get requiresStudent() { return this.mode === 'catalog' && (this.concept?.requiresStudent || this.concept?.kind === 'fotografia'); },
        get total() { return this.cents(this.form.amount) + this.academicItems.reduce((sum, row) => sum + this.cents(row.amount), 0); },
        get paid() { return this.form.payments.reduce((sum, row) => sum + this.cents(row.amount), 0); },
        get cash() { return this.form.payments.filter(row => row.method === '01').reduce((sum, row) => sum + this.cents(row.amount), 0); },
        get change() { return Math.max(0, this.paid - this.total); },
        fundingLabel(value) { return { normal: 'Familiar', beca: 'Beca', bienhechor: 'Bienhechor' }[value] || value; },
        money(cents) { return new Intl.NumberFormat('es-SV', { style: 'currency', currency: 'USD' }).format(cents / 100); },
        cents(value) { return Math.round((Number(value) || 0) * 100); },
        statusLabel(state) { return { EMITIDO: 'Emitido', PENDIENTE_APLICACION: 'Pendiente de aplicación', REVISION: 'Requiere revisión', ANULACION_PENDIENTE: 'Anulación pendiente', ANULADO: 'Anulado', HISTORICO: 'Histórico' }[state] || state; },
        async api(url, body = null) {
            const response = await fetch(url, { method: body === null ? 'GET' : 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', ...(body === null ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }) },
                ...(body === null ? {} : { body: JSON.stringify(body) }) });
            let data;
            try { data = await response.json(); } catch { const error = new Error('El servidor no confirmó la operación. Consulta el historial o reintenta la misma solicitud.'); error.status = response.status; throw error; }
            if (!response.ok) { const error = new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'No se pudo completar la operación.'); error.status = response.status; throw error; }
            return data;
        },
        selectConcept() {
            if (this.locked) return;
            this.form.amount = this.concept?.price || ''; this.form.account = this.concept?.account || '';
            this.form.studentId = ''; this.form.studentName = ''; this.notice = '';
            if (this.concept?.kind === 'deuda' || this.concept?.requiresAccount) { this.setMode('client_cxc'); }
        },
        setMode(mode) {
            if (this.locked || this.selecting) return;
            this.mode = mode; this.form = blank(); this.selection = null; this.academicItems = []; this.invoices = []; this.invoiceChoice = ''; this.invoiceQuery = ''; this.notice = '';
            this.paymentExcess = null; this.paymentApproval = null;
            if (mode !== 'catalog') this.openLookup(mode);
        },
        reset() {
            if (this.busy || this.submittedPayload || this.completed) return;
            this.selectionSequence++; this.selecting = false; this.mode = 'catalog'; this.form = blank(); this.selection = null;
            this.academicItems = []; this.invoices = []; this.invoiceChoice = ''; this.invoiceQuery = ''; this.requestKey = null; this.notice = '';
            this.paymentExcess = null; this.paymentApproval = null;
            this.$flux.modal('receipt-reset').close();
        },
        async openLookup(kind) {
            if (this.locked || this.selecting) return;
            this.lookupKind = kind; this.lookupQuery = kind === 'academic_cxc' && this.academicItems.length ? this.form.studentId : ''; this.lookupRows = []; this.lookupYear = ''; this.lookupFunding = this.form.funding;
            this.$flux.modal('receipt-lookup').show(); await this.loadLookup();
        },
        async loadLookup(page = 1) {
            const sequence = ++this.lookupSequence; this.lookupLoading = true;
            try {
                const source = this.lookupKind === 'academic_cxc' ? options.cxcUrl : (this.lookupKind === 'client_cxc' ? options.clientCxcUrl : options.recipientsUrl);
                const url = new URL(source, window.location.origin);
                url.searchParams.set('q', this.lookupQuery); url.searchParams.set('page', page);
                if (this.lookupKind === 'academic_cxc') {
                    url.searchParams.set('funding', this.lookupFunding); if (this.lookupYear) url.searchParams.set('year', this.lookupYear);
                } else if (!['client_cxc', 'academic_cxc'].includes(this.lookupKind)) url.searchParams.set('type', this.lookupKind === 'students' ? 'alumno' : 'cliente');
                const result = await this.api(url);
                if (sequence === this.lookupSequence) { this.lookupRows = result.data; this.lookupPage = result.page || 1; this.lookupPages = result.pages || 1; }
            } catch (error) { if (sequence === this.lookupSequence) { this.lookupRows = []; this.notice = error.message; } }
            finally { if (sequence === this.lookupSequence) this.lookupLoading = false; }
        },
        async diagnoseCxc() {
            if (this.lookupKind !== 'academic_cxc' || this.diagnosticLoading || this.selecting) return;
            const sequence = ++this.diagnosticSequence;
            this.cxcDiagnostic = null; this.diagnosticError = ''; this.diagnosticLoading = true;
            this.$flux.modal('receipt-cxc-diagnostic').show();
            try {
                const student = this.lookupQuery.trim();
                if (!/^\d+$/.test(student)) throw new Error('Escribe el carnet completo, usando solo números, para consultar el diagnóstico.');
                const url = new URL(options.cxcUrl, window.location.origin);
                url.searchParams.set('q', student); url.searchParams.set('funding', this.lookupFunding);
                url.searchParams.set('diagnostic', '1'); url.searchParams.set('_', String(Date.now()));
                if (this.lookupYear) url.searchParams.set('year', this.lookupYear);
                const result = await this.api(url);
                if (sequence !== this.diagnosticSequence) return;
                if (!result.diagnostic?.reference) throw new Error('El servidor no devolvió el diagnóstico. Comprueba que los cambios PHP estén desplegados y activos en producción.');
                this.cxcDiagnostic = result.diagnostic;
                if (!result.diagnostic.complete) this.diagnosticError = 'El servidor no pudo completar la consulta del diagnóstico. Conserva la referencia para revisar el error; esto no significa que el alumno no tenga CXC.';
            } catch (error) { if (sequence === this.diagnosticSequence) this.diagnosticError = error.message; }
            finally { if (sequence === this.diagnosticSequence) this.diagnosticLoading = false; }
        },
        diagnosticReason(value) { return String(value).replace(/^[A-Z_]+:\s*/, ''); },
        diagnosticValue(value) { return value === null || value === undefined ? 'NULL' : (typeof value === 'string' ? JSON.stringify(value) : String(value)); },
        async choose(row) {
            if (this.locked || this.selecting) return;
            if (this.lookupKind === 'clients') {
                this.form.recipient = { name: row.name, clientId: Number(row.id), documentType: row.documentType === 'NIT' ? '36' : (row.document ? '13' : ''), document: row.document || '' };
            } else if (this.lookupKind === 'students') {
                this.form.studentId = String(row.id); this.form.studentName = row.name;
            } else {
                if (this.lookupKind === 'academic_cxc' && this.academicItems.length) {
                    if (String(row.studentId) !== this.form.studentId) { this.notice = 'Selecciona CXC del mismo alumno para este recibo.'; return; }
                    if (this.academicItems.some(item => item.sourceId === row.cxcId && item.funding === this.lookupFunding)) { this.notice = 'Esa CXC y responsable ya están agregados.'; return; }
                }
                const previous = this.form;
                this.mode = this.lookupKind; this.form = blank(); this.form.sourceId = this.mode === 'academic_cxc' ? row.cxcId : null;
                if (this.academicItems.length) { this.form.recipient = previous.recipient; this.form.payments = previous.payments; this.form.observation = previous.observation; }
                this.form.sourceKey = this.mode === 'client_cxc' ? row.key : null; this.form.funding = this.lookupFunding;
                await this.prepare();
                if (!this.selection) return;
            }
            this.lookupSequence++; this.$flux.modal('receipt-lookup').close();
            if (this.lookupKind === 'academic_cxc') this.$flux.modal('receipt-academic-detail').show();
        },
        async prepare(search = '') {
            const sequence = ++this.selectionSequence; this.selecting = true; this.notice = '';
            try {
                const url = new URL(options.prepareUrl, window.location.origin);
                for (const [key, value] of Object.entries({ mode: this.mode, funding: this.form.funding,
                    ...(this.mode === 'academic_cxc' ? { sourceId: this.form.sourceId } : { sourceKey: this.form.sourceKey }), ...(search ? { q: search } : {}) })) url.searchParams.set(key, value);
                const result = await this.api(url);
                if (sequence !== this.selectionSequence) return;
                this.selection = result;
                if (!this.academicItems.length || !this.form.recipient.name.trim()) this.form.recipient.name = result.payerName || result.recipient.name;
                this.form.studentId = result.studentId || ''; this.form.studentName = result.studentId ? result.recipient.name : '';
                this.form.year = result.year || options.year; this.form.account = result.account || ''; this.form.amount = result.remaining;
                this.invoices = [...result.invoices];
                if (result.invoice && !this.invoices.some(row => row.uuid === result.invoice.uuid && row.origin === result.invoice.origin)) this.invoices.unshift(result.invoice);
                this.form.invoice = result.invoice; this.form.invoiceAutomatic = !!result.invoice; this.form.invoiceConfirmed = !!result.invoice;
                this.invoiceChoice = result.invoice ? `${result.invoice.origin}|${result.invoice.uuid}` : '';
            } catch (error) { if (sequence === this.selectionSequence) { this.selection = null; this.notice = error.message; } }
            finally { if (sequence === this.selectionSequence) this.selecting = false; }
        },
        chooseInvoice() {
            this.form.invoice = this.invoices.find(row => `${row.origin}|${row.uuid}` === this.invoiceChoice) || null;
            this.form.invoiceAutomatic = false; this.form.invoiceConfirmed = false;
        },
        academicReady() {
            if (!this.selection || !this.form.invoice || !this.form.account || this.cents(this.form.amount) <= 0 || this.cents(this.form.amount) > this.cents(this.selection.remaining)) {
                this.notice = 'Selecciona la factura, su cuenta y un abono dentro del saldo disponible.'; return false;
            }
            if (!this.form.invoiceAutomatic && !this.form.invoiceConfirmed) { this.notice = 'Confirma que la factura corresponde al alumno, concepto y año seleccionados.'; return false; }
            return true;
        },
        academicDraft() {
            return { sourceId: this.form.sourceId, funding: this.form.funding, account: this.form.account, amount: String(this.form.amount),
                ...(!this.form.invoiceAutomatic ? { invoice: { origin: this.form.invoice.origin, uuid: this.form.invoice.uuid, confirmed: this.form.invoiceConfirmed } } : {}) };
        },
        addAcademic() {
            if (this.locked || this.selecting || !this.academicReady()) return;
            if (this.academicItems.some(row => row.sourceId === this.form.sourceId && row.funding === this.form.funding)) { this.notice = 'Esa CXC y responsable ya están agregados.'; return; }
            this.academicItems.push({ ...this.academicDraft(), name: this.selection.name, invoiceNumber: this.form.invoice.number, remaining: this.selection.remaining });
            this.clearAcademic();
            this.$flux.modal('receipt-academic-detail').close();
        },
        cancelAcademic() {
            if (this.locked) return;
            this.selectionSequence++; this.selecting = false; this.clearAcademic();
        },
        clearAcademic() {
            if (this.locked || this.selecting) return;
            this.form.sourceId = null; this.form.amount = ''; this.form.invoice = null; this.form.account = '';
            this.selection = null; this.invoices = []; this.invoiceChoice = ''; this.invoiceQuery = ''; this.notice = '';
        },
        removeAcademic(index) { if (!this.locked && !this.selecting) this.academicItems.splice(index, 1); },
        exact() { if (!this.locked && options.methods.some(row => row.codigo === '01')) this.form.payments = [{ uid: ++this.sequence, method: '01', amount: (this.total / 100).toFixed(2), reference: '' }]; },
        completePayment(uid) {
            if (this.locked || this.selecting) return;
            const payment = this.form.payments.find(row => row.uid === uid);
            if (!payment) return;
            const remaining = this.total - (this.paid - this.cents(payment.amount));
            if (remaining > 0) payment.amount = (remaining / 100).toFixed(2);
        },
        addPayment() { if (!this.locked) this.form.payments.push({ uid: ++this.sequence, method: options.methods[0]?.codigo || '01', amount: '', reference: '' }); },
        removePayment(uid) { if (!this.locked && this.form.payments.length > 1) this.form.payments = this.form.payments.filter(row => row.uid !== uid); },
        payload() {
            return { requestKey: this.requestKey, mode: this.mode, recipient: { name: this.form.recipient.name || null,
                clientId: this.form.recipient.clientId || null, documentType: this.form.recipient.documentType || null, document: this.form.recipient.document || null },
                ...(this.mode === 'catalog' ? { conceptId: Number(this.form.conceptId),
                    ...(this.form.studentId ? { studentId: this.form.studentId, ...(this.concept?.kind === 'fotografia' ? { year: Number(this.form.year) } : {}) } : {}) }
                    : (this.mode === 'academic_cxc' ? (this.academicItems.length ? { academicItems: [...this.academicItems.map(({ name, invoiceNumber, remaining, ...item }) => ({ ...item })), ...(this.form.sourceId ? [this.academicDraft()] : [])] } : { sourceId: this.form.sourceId }) : { sourceKey: this.form.sourceKey })),
                funding: this.form.funding, account: this.form.account || null, amount: (this.total / 100).toFixed(2), observation: this.form.observation || null,
                ...(this.mode === 'academic_cxc' && !this.academicItems.length && this.form.invoice && !this.form.invoiceAutomatic ? { invoice: { origin: this.form.invoice.origin, uuid: this.form.invoice.uuid, confirmed: this.form.invoiceConfirmed } } : {}),
                payments: this.form.payments.map(row => ({ method: row.method, amount: String(row.amount), reference: row.reference || null })) };
        },
        async issue() {
            if (this.busy || this.selecting || this.completed || !options.canCreate) return;
            if (!this.submittedPayload) {
                if (this.mode === 'catalog' && (!this.concept || !this.form.recipient.name.trim())) { this.notice = 'Indica el nombre y selecciona el tipo de recibo.'; return; }
                if (this.concept?.kind === 'anticipo' && !this.form.recipient.clientId) { this.notice = 'Selecciona el cliente del catálogo actual para registrar su anticipo.'; return; }
                if (this.requiresStudent && !this.form.studentId) { this.notice = this.concept?.kind === 'fotografia' ? 'Selecciona el alumno y el año académico para fotografías.' : 'Selecciona un alumno para guardar este recibo en su historial.'; return; }
                if (this.mode === 'client_cxc' && (!this.selection || !this.form.invoice || !this.form.account)) { this.notice = 'Selecciona la factura y su cuenta por cobrar antes de emitir.'; return; }
                if (this.mode === 'academic_cxc' && ((!this.form.sourceId && !this.academicItems.length) || (this.form.sourceId && !this.academicReady()))) return;
                if (this.academicItems.some(row => this.cents(row.amount) <= 0 || this.cents(row.amount) > this.cents(row.remaining))) { this.notice = 'Revisa los abonos: deben ser mayores que cero y no superar el saldo de cada cuota.'; return; }
                if (this.total <= 0 || this.paid < this.total || this.change > this.cash) { this.notice = 'Completa el pago. El cambio solo puede devolverse del efectivo recibido.'; return; }
                const snapshot = JSON.stringify({ ...this.payload(), requestKey: null });
                if (this.change > 0 && this.paymentApproval !== snapshot) {
                    this.paymentExcess = { snapshot, total: this.total, paid: this.paid, change: this.change };
                    this.$flux.modal('receipt-payment-excess').show();
                    return;
                }
            }
            this.requestKey ||= crypto.randomUUID(); this.submittedPayload ||= this.payload(); this.busy = true; this.notice = '';
            try { this.completed = await this.api(options.storeUrl, this.submittedPayload); this.$flux.modal('receipt-copy').show(); }
            catch (error) {
                this.notice = error.message;
                if (error.status >= 400 && error.status < 500) { this.submittedPayload = null; this.requestKey = null; }
                else this.notice += ' Conservamos la solicitud para reintentar sin duplicar el cobro.';
            } finally { this.busy = false; }
        },
        confirmExcessPayment() {
            if (!this.paymentExcess || this.busy) return;
            this.paymentApproval = this.paymentExcess.snapshot; this.paymentExcess = null;
            this.$flux.modal('receipt-payment-excess').close();
            return this.issue();
        },
        cancelExcessPayment() {
            this.paymentExcess = null; this.paymentApproval = null;
            this.$flux.modal('receipt-payment-excess').close();
        },
        finish(copy = false) {
            if (this.busy || !this.completed) return;
            if (copy) {
                let tab;
                try { tab = window.open(this.completed.ticketUrl, '_blank'); if (tab) tab.opener = null; } catch {}
                if (!tab) { this.notice = 'Permite las ventanas emergentes para abrir la copia. También está disponible en el historial.'; return; }
            }
            this.completed = null; this.submittedPayload = null; this.$flux.modal('receipt-copy').close(); this.reset();
        },
        openHistory() { this.$flux.modal('receipt-history').show(); this.loadHistory(); },
        async loadHistory(page = 1) {
            const sequence = ++this.historySequence; this.historyLoading = true;
            try {
                const url = new URL(options.historyUrl, window.location.origin);
                for (const [key, value] of Object.entries({ q: this.historyQuery, legacy: this.historyLegacy ? '1' : '0', page })) url.searchParams.set(key, value);
                const result = await this.api(url);
                if (sequence === this.historySequence) { this.history = result.data; this.historyPage = result.page; this.historyPages = result.pages; }
            } catch (error) { if (sequence === this.historySequence) { this.history = []; this.notice = error.message; } }
            finally { if (sequence === this.historySequence) this.historyLoading = false; }
        },
        async retry(row) {
            if (this.busy) return; this.busy = true;
            try { await this.api(row.retryUrl, {}); await this.loadHistory(this.historyPage); }
            catch (error) { this.notice = error.message; } finally { this.busy = false; }
        },
        async annul() {
            if (this.busy || !this.annulment.row) return; this.busy = true;
            try { await this.api(this.annulment.row.annulUrl, { reason: this.annulment.reason }); this.$flux.modal('receipt-annul').close(); await this.loadHistory(this.historyPage); }
            catch (error) { this.notice = error.message; } finally { this.busy = false; }
        },
    };
}

document.addEventListener('alpine:init', () => window.Alpine.data('libreriaReceipts', createReceiptWorkspace));
