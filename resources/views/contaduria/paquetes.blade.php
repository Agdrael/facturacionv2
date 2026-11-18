<x-layouts::app :title="'Paquetes · Contaduría'">
    <div class="catalog-workspace flex w-full flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="mb-2 text-xs text-zinc-500"><a href="{{ route('contaduria.catalogos') }}" wire:navigate class="underline underline-offset-4">Contaduría / Catálogos</a></p><h1 class="text-2xl font-semibold tracking-tight">Paquetes de productos</h1><p class="mt-2 text-sm text-zinc-500">Define los productos y cantidades que se agregarán juntos a una venta.</p></div>
            @can('contaduria.paquetes.crear')<flux:button :href="route('contaduria.paquetes.create')" wire:navigate variant="primary" icon="plus">Crear paquete</flux:button>@endcan
        </header>
        @if (session('status'))
            <x-catalog-notice name="package-feedback" title="Paquete guardado" kind="success" :automatic="true">{{ session('status') }}</x-catalog-notice>
        @endif
        <section data-theme="light" class="catalog-panel overflow-hidden">
            <form method="GET" action="{{ route('contaduria.paquetes.index') }}" class="flex flex-wrap items-end gap-3 border-b border-brand-ink/10 p-5">
                <div class="min-w-48 flex-1 sm:max-w-lg"><flux:input name="q" :value="$search" label="Buscar paquetes" icon="magnifying-glass" placeholder="Código o nombre…" maxlength="100" /></div>
                <flux:button type="submit" icon="magnifying-glass">Buscar</flux:button>
                @if ($search !== '')<flux:button :href="route('contaduria.paquetes.index')" wire:navigate variant="ghost">Limpiar</flux:button>@endif
                <span class="catalog-pill ms-auto">{{ number_format($records->total()) }} paquetes</span>
            </form>
            <div class="overflow-x-auto"><table class="catalog-table w-full text-left text-sm">
                <thead class="bg-brand-ink text-brand-paper"><tr><th class="px-4 py-3">Código</th><th class="px-4 py-3">Nombre</th><th class="px-4 py-3 text-center">Productos</th><th class="px-4 py-3 text-right">Total sin IVA al crear</th><th class="px-4 py-3">Fecha</th></tr></thead>
                <tbody class="divide-y divide-brand-ink/10">
                    @forelse ($records as $record)
                        <tr><td><span class="catalog-code">{{ $record->codigo_paquete }}</span></td><td>{{ $record->nombre_paquete }}</td><td class="text-center">{{ $record->components_count }}</td><td class="text-right tabular-nums">${{ number_format((float) $record->p_venta, 6) }}</td><td>{{ $record->fecha_creacion }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="py-12 text-center text-zinc-500">{{ $search === '' ? 'Aún no hay paquetes registrados. Crea el primero para utilizarlo en ventas.' : 'No se encontraron paquetes con esa búsqueda.' }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            @if ($records->hasPages())<div class="border-t border-brand-ink/10 p-4">{{ $records->links() }}</div>@endif
        </section>
    </div>
</x-layouts::app>
