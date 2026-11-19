export function createGenerationState() {
    return {
        requestKey: null, generating: false, quoteLoading: false, generated: null,
        quotedTotal: null, quotedSnapshot: '', historyLoading: false, historyError: '', generationNotice: '',
        invalidationLoading: false, invalidating: false,
        invalidation: { number: '', reasonTypes: [], identityDocuments: [], event: null, storeUrl: null },
        invalidationForm: { reasonType: '2', reason: '', replacement: null, responsibleName: '', responsibleType: '13', responsibleDocument: '', requesterName: '', requesterType: '13', requesterDocument: '', confirmed: false },

        showGenerationNotice(message) {
            this.generationNotice = message;
            this.$flux.modal('sale-generation-notice').show();
        },

        generationBase() {
            const clientKeys = ['id', 'name', 'documentType', 'document', 'nrc', 'address', 'department', 'district', 'municipality', 'email', 'phone', 'phone2', 'activity'];
            return {
                document: Object.fromEntries(['type', 'date', 'discount', 'account', 'thirdPartyType', 'thirdPartyDocument'].map(key => [key, this.document[key]])),
                client: Object.fromEntries(clientKeys.map(key => [key, this.client[key] ?? null])),
                observation: this.observation, operation: String(this.operation), installments: this.installments, term: this.term,
                items: this.items.map(item => ({ productId: item.productId, quantity: String(item.quantity), netPrice: item.netPrice ?? null, discount: item.discount || 0,
                    tuition: item.tuition ? Object.fromEntries(['studentId', 'balanceId', 'fundingType', 'responsibleType'].map(key => [key, String(item.tuition[key])])) : null })),
            };
        },

        async postGeneration(url, body = {}) {
            let response;
            try { response = await fetch(url, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector?.('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify(body),
            }); } catch { throw new Error('No se recibió confirmación del servidor. Reintenta la misma operación o consulta el historial.'); }
            let data;
            try { data = await response.json(); }
            catch { throw new Error('No se recibió una confirmación válida. Reintenta la misma operación o consulta el historial.'); }
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || 'No se pudo completar la solicitud. Revisa los datos e intenta nuevamente.');
            return data;
        },

        async quoteSale() {
            if (!this.saleCatalogs.quoteUrl) return true;
            this.quoteLoading = true;
            const base = this.generationBase();
            this.requestKey ||= globalThis.crypto.randomUUID();
            try {
                const quote = await this.postGeneration(this.saleCatalogs.quoteUrl, { ...base, requestKey: this.requestKey });
                if (JSON.stringify(base) !== JSON.stringify(this.generationBase())) return false;
                const cents = Math.round(Number(quote.total) * 100);
                if (!Number.isSafeInteger(cents) || cents <= 0) throw new Error('El total recibido no es válido.');
                this.quotedTotal = cents;
                this.quotedSnapshot = JSON.stringify(base);
                return true;
            } catch (error) {
                this.showGenerationNotice(error.message || 'No se pudo preparar el DTE. Intenta nuevamente.');
                return false;
            } finally {
                this.quoteLoading = false;
            }
        },

        async generateSale() {
            if (this.generating || this.generated || this.quoteLoading || this.packageAdding || !this.items.length || !this.hasBillingClient) return;
            if (this.requireFiscalClientChange()) return;
            this.generating = true;
            this.requestKey ||= globalThis.crypto.randomUUID();
            const payload = { ...this.generationBase(), requestKey: this.requestKey, total: (this.total / 100).toFixed(2),
                payments: this.payments.map(payment => ({ method: payment.method, amount: String(payment.amount || '0'), reference: payment.reference || null })) };
            try {
                this.generated = await this.postGeneration(this.saleCatalogs.generateUrl, payload);
                this.$flux.modal('sale-payment').close();
                this.$flux.modal('sale-generated').show();
            } catch (error) {
                this.showGenerationNotice(error.message || 'No se recibió confirmación. Reintenta sin comenzar otra venta o consulta el historial.');
            } finally {
                this.generating = false;
            }
        },

        finishSale(printCopy = false) {
            if (this.generating || !this.generated) return;
            if (printCopy) {
                let ticketWindow = null;
                try {
                    if (this.generated.ticketUrl) ticketWindow = globalThis.window?.open?.(this.generated.ticketUrl, '_blank') ?? null;
                    if (ticketWindow) ticketWindow.opener = null;
                } catch {}
                if (!ticketWindow) {
                    this.showGenerationNotice('No se pudo abrir el comprobante. Permite las ventanas emergentes e intenta de nuevo; también puedes obtenerlo desde el historial.');
                    return;
                }
            }
            this.$flux.modal('sale-generated').close();
            this.newSale();
        },

        async retryDte(url = this.generated?.retryUrl) {
            if (this.generating || !url) return;
            this.generating = true;
            try {
                const result = await this.postGeneration(url);
                if (this.generated?.id === result.id) this.generated = result;
                this.showGenerationNotice(result.message);
                await this.loadHistory();
            } catch (error) { this.showGenerationNotice(error.message); }
            finally { this.generating = false; }
        },

        async transmitDte(url = this.generated?.transmitUrl) {
            if (this.generating || !url) return;
            this.generating = true;
            try {
                const result = await this.postGeneration(url);
                if (this.generated?.id === result.id) this.generated = { ...this.generated, ...result };
                this.showGenerationNotice(result.message);
                await this.loadHistory();
            } catch (error) { this.showGenerationNotice(error.message); }
            finally { this.generating = false; }
        },

        async openHistory() {
            this.$flux.modal('sale-history').show();
            await this.loadHistory();
        },

        async openInvalidation(sale) {
            if (this.invalidating || this.invalidationLoading || !sale?.canInvalidate || !sale.invalidationUrl) return;
            this.invalidationLoading = true;
            try {
                const response = await fetch(sale.invalidationUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('No se pudo abrir la invalidación. Revisa tus permisos e intenta nuevamente.');
                const data = await response.json();
                this.invalidation = data;
                this.invalidationForm = { ...data.defaults };
                this.$flux.modal('sale-invalidation').show();
            } catch (error) { this.showGenerationNotice(error.message || 'No se pudo cargar la solicitud.'); }
            finally { this.invalidationLoading = false; }
        },

        async prepareInvalidation() {
            if (this.invalidating || !this.invalidation.storeUrl || this.invalidation.event) return;
            if (!this.invalidationForm.confirmed) {
                this.showGenerationNotice('Confirma la solicitud de invalidación antes de continuar.');
                return;
            }
            this.invalidating = true;
            try {
                const payload = { ...this.invalidationForm, replacement: this.invalidationForm.reasonType === '2' ? null : this.invalidationForm.replacement };
                const result = await this.postGeneration(this.invalidation.storeUrl, payload);
                this.invalidation.event = result;
                this.showGenerationNotice(result.message);
                await this.loadHistory();
            } catch (error) { this.showGenerationNotice(error.message); }
            finally { this.invalidating = false; }
        },

        async transmitInvalidation() {
            const event = this.invalidation.event;
            if (this.invalidating || !event?.transmitUrl || event.applied) return;
            this.invalidating = true;
            try {
                const result = await this.postGeneration(event.transmitUrl);
                this.invalidation.event = result;
                if (this.generated?.id === result.id) this.generated = { ...this.generated, invalidationState: result.invalidationState };
                this.showGenerationNotice(result.message);
                await this.loadHistory();
            } catch (error) { this.showGenerationNotice(error.message); }
            finally { this.invalidating = false; }
        },

        async loadHistory() {
            if (!this.saleCatalogs.historyUrl) return;
            this.historyLoading = true;
            this.historyError = '';
            try {
                const response = await fetch(this.saleCatalogs.historyUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                this.history = (await response.json()).data;
                if (this.historySelection) this.historySelection = this.history.find(row => row.id === this.historySelection.id) || null;
            } catch { this.historyError = 'No se pudo cargar el historial. Intenta nuevamente.'; this.showGenerationNotice(this.historyError); }
            finally { this.historyLoading = false; }
        },
    };
}
