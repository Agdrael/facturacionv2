<x-layouts::app :title="'Contadores DTE · Contaduría'">
    <div class="catalog-workspace mx-auto flex w-full max-w-5xl flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">Contaduría / Catálogos</p>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">Contadores DTE</h1>
                    <span class="rounded-full border border-brand-yellow bg-brand-yellow px-3 py-1 text-xs font-semibold text-brand-ink">{{ $environment === '00' ? 'Pruebas · 00' : 'Producción · 01' }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:modal.trigger name="counter-help"><flux:button icon="information-circle">Cómo funciona</flux:button></flux:modal.trigger>
                <flux:button :href="route('contaduria.catalogos')" wire:navigate icon="arrow-left">Volver a catálogos</flux:button>
            </div>
        </header>

        @if (session('status'))
            <x-catalog-notice name="counter-success" title="Contador actualizado" kind="success" :automatic="true">{{ session('status') }}</x-catalog-notice>
        @endif
        @if ($errors->any())
            <x-catalog-notice name="counter-errors" title="No se pudo actualizar el contador" kind="error" :automatic="true">
                <ul class="list-disc space-y-2 ps-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </x-catalog-notice>
        @endif

        <div class="grid gap-5 md:grid-cols-2">
            @foreach ($counters as $counter)
                <section data-theme="light" class="catalog-panel overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-brand-yellow/20 bg-brand-ink px-5 py-4 text-brand-paper">
                        <h2 class="text-sm font-semibold">{{ $counter['label'] }}</h2>
                        <span class="rounded-md border border-brand-paper/20 px-2 py-1 font-mono text-xs">{{ $counter['type'] }}</span>
                    </div>
                    <div class="space-y-6 p-5">
                        <div><p class="text-xs font-medium text-zinc-500">Siguiente correlativo</p><p class="mt-3 break-all font-mono text-2xl font-semibold tracking-wide tabular-nums">{{ str_pad((string) $counter['next'], 15, '0', STR_PAD_LEFT) }}</p></div>
                        @can('contaduria.contadores.editar')<flux:modal.trigger :name="'counter-edit-'.$counter['type']"><flux:button icon="pencil-square" variant="primary" class="w-full">Modificar contador</flux:button></flux:modal.trigger>@endcan
                    </div>
                </section>

                <flux:modal :name="'counter-edit-'.$counter['type']" data-theme="light" class="w-full md:max-w-lg!">
                    <form method="POST" action="{{ route('contaduria.contadores.update', $counter['type']) }}" class="space-y-6" novalidate x-data="{ next: @js(old('counter_type') === $counter['type'] ? old('next', $counter['next']) : $counter['next']) }">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="counter_type" value="{{ $counter['type'] }}" />
                        <input type="hidden" name="environment" value="{{ $environment }}" />
                        <input type="hidden" name="expected" value="{{ $counter['next'] }}" />
                        <div><flux:heading size="lg">Modificar contador</flux:heading><p class="mt-2 text-sm text-zinc-500">{{ $counter['label'] }} · {{ $environment === '00' ? 'Pruebas' : 'Producción' }}</p></div>
                        <flux:input name="next" label="Siguiente correlativo" x-model="next" inputmode="numeric" maxlength="15" autocomplete="off" required />
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><p class="text-xs text-zinc-500">El próximo DTE utilizará este correlativo</p><p class="mt-2 break-all font-mono text-lg font-semibold tabular-nums" x-text="String(next).padStart(15, '0')"></p></div>
                        <flux:checkbox name="confirmed" value="1" label="Confirmo el cambio del siguiente correlativo de este documento." />
                        <div class="flex justify-end gap-3 border-t border-zinc-200 pt-4"><flux:modal.close><flux:button type="button">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary" icon="check">Guardar cambio</flux:button></div>
                    </form>
                </flux:modal>
            @endforeach
        </div>
        <x-catalog-notice name="counter-help" title="Cómo funcionan los contadores">
            <ul class="list-disc space-y-3 ps-4">
                <li>El valor indica el número que recibirá el próximo DTE al generar una venta.</li>
                <li>Solo se permite adelantar el contador para evitar reutilizar correlativos.</li>
                <li>Factura y crédito fiscal tienen contadores independientes, compartidos por los puntos de venta.</li>
                <li>Se modifica únicamente el ambiente mostrado. Las pruebas no cambian los contadores productivos.</li>
                <li>Si alguien genera una venta mientras editas, deberás recargar y revisar el valor actualizado.</li>
                <li>Los DTE ya generados conservan su número de control.</li>
            </ul>
        </x-catalog-notice>
    </div>
</x-layouts::app>
