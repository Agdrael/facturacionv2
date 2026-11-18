<flux:modal name="sale-client-picker" data-theme="light" class="sale-dialog md:max-w-2xl!" aria-labelledby="sale-client-picker-title" x-on:close="clientPickerClosed()">
    <div class="flex flex-col gap-5">
        <div class="sale-modal-heading">
            <p class="sale-label">Datos de facturación</p>
            <h2 id="sale-client-picker-title" class="mt-1 text-xl font-semibold tracking-tight">Seleccionar cliente</h2>
            <p class="mt-1 text-xs">Busca y elige a quién se emitirá la factura.</p>
        </div>
        <flux:input label="Buscar cliente" icon="magnifying-glass" x-model="clientSearch" x-on:input.debounce.300ms="searchClients()" x-on:keydown.enter.prevent="searchClients()" maxlength="100" placeholder="Nombre, DUI, NIT o correo…" autofocus />
        <p x-show="clientLoading" class="text-xs text-zinc-500" role="status">Buscando clientes…</p>
        <p x-show="clientError" x-text="clientError" class="text-xs text-red-600" role="alert"></p>
        <div class="max-h-[45dvh] min-h-40 overflow-auto rounded-xl border border-zinc-200">
            <template x-for="candidate in clientResults" :key="candidate.id">
                <button type="button" class="flex w-full items-center justify-between gap-4 border-b border-zinc-200 px-4 py-3 text-left last:border-0 hover:bg-brand-yellow/10 focus-visible:outline-2 focus-visible:outline-brand-yellow" x-on:click="selectClient(candidate)" x-bind:disabled="clientLoading" :class="clientDraft.id === candidate.id ? 'bg-brand-yellow/15' : ''">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium wrap-break-word" x-text="candidate.name"></span>
                        <span class="mt-1 block text-xs text-zinc-500" x-text="candidate.documents['13'] || candidate.documents['36'] || 'Sin documento registrado'"></span>
                    </span>
                    <span class="shrink-0 text-xs font-semibold" x-text="clientDraft.id === candidate.id ? 'Seleccionado' : 'Seleccionar'"></span>
                </button>
            </template>
            <p x-show="!clientLoading && !clientError && !clientResults.length" class="p-8 text-center text-sm text-zinc-500">No se encontraron clientes. Prueba con otro nombre o documento.</p>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-zinc-200 pt-4">
            <p class="text-xs text-zinc-500">Si el cliente no aparece, afina la búsqueda.</p>
            <flux:modal.close><flux:button>Cancelar</flux:button></flux:modal.close>
        </div>
    </div>
</flux:modal>
