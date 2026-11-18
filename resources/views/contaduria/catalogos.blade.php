<x-layouts::app :title="'Catálogos · Contaduría'">
    <div class="catalog-workspace flex h-full w-full flex-1 flex-col gap-6">
        <header>
            <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">Contaduría / Catálogos</p>
            <h1 class="text-2xl font-semibold tracking-tight">Catálogos</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Selecciona un catálogo para consultar y administrar sus registros.</p>
        </header>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($catalogs as $catalog)
                <a href="{{ route('contaduria.catalogos.registros.index', $catalog->slug) }}" wire:navigate data-theme="light" class="catalog-panel catalog-card group flex flex-col overflow-hidden transition hover:border-brand-yellow focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-yellow">
                    <div class="flex items-center justify-between border-b border-brand-yellow/20 bg-brand-ink px-5 py-5 text-brand-paper">
                        <span class="catalog-icon"><flux:icon :name="$catalog->icon" class="size-6" /></span>
                        <span class="rounded-full border border-brand-paper/15 px-3 py-1.5 text-xs text-brand-paper/70">{{ number_format($counts[$catalog->slug]) }} registros</span>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-5">
                        <h2 class="text-base font-semibold">{{ $catalog->title }}</h2>
                        <p class="flex-1 text-sm leading-6 text-zinc-500">{{ $catalog->description }}</p>
                        <span class="mt-3 flex items-center justify-between text-xs font-semibold">Administrar catálogo <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-1" /></span>
                    </div>
                </a>
            @endforeach
            @can('contaduria.contadores.ver')
<a href="{{ route('contaduria.contadores.index') }}" wire:navigate data-theme="light" class="catalog-panel catalog-card group flex flex-col overflow-hidden transition hover:border-brand-yellow focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-yellow">
                <div class="flex items-center justify-between border-b border-brand-yellow/20 bg-brand-ink px-5 py-5 text-brand-paper"><span class="catalog-icon"><flux:icon.document-text class="size-6" /></span><span class="text-xs text-brand-paper/70">Factura y crédito fiscal</span></div>
                <div class="flex flex-1 flex-col gap-3 p-5"><h2 class="text-base font-semibold">Contadores DTE</h2><p class="flex-1 text-sm leading-6 text-zinc-500">Consulta y ajusta el siguiente correlativo del ambiente activo.</p><span class="mt-3 flex items-center justify-between text-xs font-semibold">Administrar contadores <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-1" /></span></div>
            </a>
@endcan
            @can('contaduria.paquetes.ver')
<a href="{{ route('contaduria.paquetes.index') }}" wire:navigate data-theme="light" class="catalog-panel catalog-card group flex flex-col overflow-hidden transition hover:border-brand-yellow focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-yellow">
                <div class="flex items-center justify-between border-b border-brand-yellow/20 bg-brand-ink px-5 py-5 text-brand-paper"><span class="catalog-icon"><flux:icon.cube class="size-6" /></span><span class="text-xs text-brand-paper/70">Productos agrupados</span></div>
                <div class="flex flex-1 flex-col gap-3 p-5"><h2 class="text-base font-semibold">Catálogo de paquetes</h2><p class="flex-1 text-sm leading-6 text-zinc-500">Crea paquetes con los productos del catálogo y sus cantidades.</p><span class="mt-3 flex items-center justify-between text-xs font-semibold">Administrar paquetes <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-1" /></span></div>
            </a>
@endcan
        </div>
    </div>
</x-layouts::app>
