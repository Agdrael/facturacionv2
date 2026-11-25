<flux:modal name="sale-history" data-theme="light" variant="flyout" class="w-full md:w-[48rem]" aria-labelledby="sale-history-title">
    <div class="flex flex-col gap-5">
        <div class="sale-modal-heading"><p class="sale-label">Librería</p><h2 id="sale-history-title" class="mt-1 text-xl font-semibold tracking-tight">Historial de ventas</h2><p class="mt-1 text-xs">Últimos 50 DTE generados.</p></div>
        <p x-show="historyLoading" role="status" class="text-sm">Cargando historial…</p><div x-show="historyError" x-cloak role="alert"><p x-text="historyError"></p><flux:button size="sm" x-on:click="loadHistory()">Reintentar</flux:button></div>
        <flux:input icon="magnifying-glass" x-model="historySearch" placeholder="Buscar por número, cliente o estado…" aria-label="Buscar en el historial" />
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-brand-paper/10">
            <table class="w-full text-left text-xs">
                <thead class="bg-zinc-100 text-zinc-500 dark:bg-brand-ink/50 dark:text-zinc-400"><tr><th class="p-3 font-medium">Venta</th><th class="p-3 font-medium">Cliente</th><th class="p-3 text-right font-medium">Total</th><th class="p-3 font-medium">Estado</th><th class="p-3"><span class="sr-only">Detalle</span></th></tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-brand-paper/10">
                    <template x-for="sale in filteredHistory" :key="sale.id">
                        <tr><td class="p-3"><p class="font-mono text-[11px]" x-text="sale.id"></p><p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400" x-text="`${sale.date} · ${sale.time}`"></p></td><td class="p-3" x-text="sale.client"></td><td class="p-3 text-right font-medium tabular-nums" x-text="money(sale.total)"></td><td class="p-3"><span class="rounded-md px-2 py-1 text-[10px] font-medium" :class="sale.status === 'Anulada' ? 'bg-red-500/10 text-red-600 dark:text-red-400' : 'bg-brand-yellow/10 text-brand-ink dark:text-brand-yellow'" x-text="sale.status"></span></td><td class="p-3"><flux:button size="xs" variant="ghost" x-on:click="historySelection = sale">Ver</flux:button></td></tr>
                    </template>
                </tbody>
            </table>
            <p x-show="!filteredHistory.length" class="p-8 text-center text-xs text-zinc-500 dark:text-zinc-400">No hay ventas que coincidan con tu búsqueda.</p>
        </div>
        <div x-show="historySelection" x-cloak class="sale-panel p-4">
            <p class="sale-label">Detalle del DTE generado</p>
            <p class="mt-2 text-sm font-semibold" x-text="historySelection?.id"></p>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-xs"><dt class="text-zinc-500 dark:text-zinc-400">Cliente</dt><dd x-text="historySelection?.client"></dd><dt class="text-zinc-500 dark:text-zinc-400">Hecho por</dt><dd x-text="historySelection?.operator"></dd><dt class="text-zinc-500 dark:text-zinc-400">Total</dt><dd class="font-semibold" x-text="money(historySelection?.total)"></dd></dl>
            <div x-show="historySelection?.canPrint || historySelection?.canManage || historySelection?.canInvalidate" class="mt-5 space-y-4 border-t border-zinc-200 pt-4 dark:border-brand-paper/10">
                <div x-show="historySelection?.canPrint || historySelection?.canDownload" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <flux:button as="a" size="sm" variant="primary" icon="printer" x-show="historySelection?.canPrint" x-bind:href="historySelection?.ticketUrl" target="_blank" rel="noopener" class="w-full sm:w-auto">Imprimir ticket</flux:button>
                    <flux:button as="a" size="sm" icon="arrow-down-tray" x-show="historySelection?.canDownload" x-bind:href="historySelection?.downloadUrl" class="w-full sm:w-auto">Descargar JSON</flux:button>
                </div>
                <div x-show="(historySelection?.canTransmit && !historySelection?.pending && !historySelection?.invalidationState) || (historySelection?.canRetry && historySelection?.pending)" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <flux:button size="sm" x-show="historySelection?.canTransmit && !historySelection?.pending && !historySelection?.invalidationState" x-on:click="transmitDte(historySelection.transmitUrl)" x-bind:disabled="generating" class="w-full sm:w-auto"><span x-text="historySelection?.transmissionState === 'PENDIENTE' ? 'Reintentar envío' : 'Consultar estado'"></span></flux:button>
                    <flux:button size="sm" x-show="historySelection?.canRetry && historySelection?.pending" x-on:click="retryDte(historySelection.retryUrl)" x-bind:disabled="generating" class="w-full sm:w-auto">Reintentar guardado</flux:button>
                </div>
                <div x-show="historySelection?.canInvalidate" class="border-t border-zinc-200 pt-4 dark:border-brand-paper/10"><flux:button size="sm" icon="no-symbol" x-on:click="openInvalidation(historySelection)" x-bind:disabled="generating || invalidating || invalidationLoading" class="w-full sm:w-auto"><span x-text="historySelection?.invalidationState ? 'Consultar invalidación' : 'Anular venta'"></span></flux:button></div>
            </div>
        </div>
    </div>
</flux:modal>

<flux:modal name="sale-invalidation" data-theme="light" class="w-full md:max-w-2xl!" aria-labelledby="sale-invalidation-title">
    <div class="space-y-5">
        <div class="sale-modal-heading"><p class="sale-label">Invalidación DTE · Evento v3</p><h2 id="sale-invalidation-title" class="mt-1 text-xl font-semibold">Anular venta</h2><p class="mt-2 break-all font-mono text-xs" x-text="invalidation.number"></p><p class="mt-2 text-xs" x-text="invalidation.environment === '00' ? 'Ambiente de pruebas · 00' : 'Ambiente productivo · 01'"></p></div>
        <form x-show="!invalidation.event" x-on:submit.prevent="prepareInvalidation()" class="space-y-5" novalidate>
            <flux:select label="Tipo de invalidación" x-model="invalidationForm.reasonType" required><template x-for="option in invalidation.reasonTypes" :key="option.codigo"><option :value="option.codigo" x-text="option.valor"></option></template></flux:select>
            <flux:textarea label="Motivo de la invalidación" x-model="invalidationForm.reason" maxlength="200" rows="2" required />
            <div x-show="invalidationForm.reasonType !== '2'"><flux:input label="UUID del DTE de reemplazo aceptado" x-model="invalidationForm.replacement" maxlength="36" autocomplete="off" /></div>
            <section class="sale-panel space-y-4 p-4"><h3 class="text-sm font-semibold">Responsable del evento</h3><flux:input label="Nombre del responsable" x-model="invalidationForm.responsibleName" maxlength="100" required /><div class="grid gap-4 sm:grid-cols-2"><flux:select label="Tipo de documento del responsable" x-model="invalidationForm.responsibleType" required><template x-for="option in invalidation.identityDocuments" :key="option.codigo"><option :value="option.codigo" x-text="option.valor"></option></template></flux:select><flux:input label="Número de documento" x-model="invalidationForm.responsibleDocument" maxlength="20" required /></div></section>
            <section class="sale-panel space-y-4 p-4"><h3 class="text-sm font-semibold">Persona que solicita la anulación</h3><flux:input label="Nombre del solicitante" x-model="invalidationForm.requesterName" maxlength="100" required /><div class="grid gap-4 sm:grid-cols-2"><flux:select label="Tipo de documento del solicitante" x-model="invalidationForm.requesterType" required><template x-for="option in invalidation.identityDocuments" :key="option.codigo"><option :value="option.codigo" x-text="option.valor"></option></template></flux:select><flux:input label="Número de documento" x-model="invalidationForm.requesterDocument" maxlength="20" required /></div></section>
            <flux:checkbox x-model="invalidationForm.confirmed" label="Confirmo la solicitud. La venta se revertirá después de que Hacienda acepte la invalidación." />
            <div class="flex justify-end gap-3 border-t border-zinc-200 pt-4"><flux:modal.close><flux:button type="button" x-bind:disabled="invalidating">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary" icon="document-text" x-bind:disabled="invalidating">Preparar evento</flux:button></div>
        </form>
        <div x-show="invalidation.event" class="space-y-5">
            <div class="sale-panel space-y-3 p-4"><p class="sale-label">Estado de la invalidación</p><p class="font-semibold" x-text="invalidation.event?.invalidationState"></p><p class="break-all font-mono text-xs" x-text="invalidation.event?.uuid"></p><p class="text-sm" x-text="invalidation.event?.message"></p></div>
            <div class="flex flex-wrap justify-end gap-3"><a x-bind:href="invalidation.event?.downloadUrl" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-semibold">Descargar JSON del evento</a><flux:button variant="primary" icon="paper-airplane" x-show="!invalidation.event?.applied" x-bind:disabled="invalidating" x-on:click="transmitInvalidation()"><span x-text="invalidation.event?.invalidationState === 'PENDIENTE' ? 'Transmitir invalidación' : 'Consultar / conciliar'"></span></flux:button></div>
        </div>
    </div>
</flux:modal>

