<x-layouts::app :title="'Crear paquete · Contaduría'">
    <div class="catalog-workspace mx-auto flex w-full max-w-7xl flex-col gap-6" x-data="contaduriaPackage(@js($options))">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="mb-2 text-xs text-zinc-500"><a href="{{ route('contaduria.paquetes.index') }}" wire:navigate class="underline underline-offset-4">Contaduría / Catálogos / Paquetes</a></p><h1 class="text-2xl font-semibold tracking-tight">Crear paquete</h1><p class="mt-2 text-sm text-zinc-500">Agrupa productos del catálogo con las cantidades que necesita cada paquete.</p></div>
            <flux:button :href="route('contaduria.paquetes.index')" wire:navigate icon="arrow-left">Volver a paquetes</flux:button>
        </header>
        @if ($errors->any())
            <x-catalog-notice name="package-feedback" title="Revisa los datos del paquete" kind="error" :automatic="true"><ul class="list-disc space-y-2 ps-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-catalog-notice>
        @endif
        <form method="POST" action="{{ route('contaduria.paquetes.store') }}" novalidate class="space-y-5">
            @csrf
            <section data-theme="light" class="catalog-panel overflow-hidden">
                <div class="border-b border-brand-yellow/20 bg-brand-ink px-5 py-4 text-brand-paper"><h2 class="text-sm font-semibold">Datos del paquete</h2></div>
                <div class="grid gap-5 p-5 md:grid-cols-[1fr_2fr_1fr]">
                    <flux:input name="code" :value="old('code')" label="Código" maxlength="100" required autocomplete="off" />
                    <flux:input name="name" :value="old('name')" label="Nombre del paquete" maxlength="255" required />
                    <flux:input label="Total sin IVA" x-bind:value="formatPrice(total)" readonly />
                </div>
            </section>
            <section data-theme="light" class="catalog-panel overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-yellow/20 bg-brand-ink px-5 py-4 text-brand-paper"><div><h2 class="text-sm font-semibold">Productos incluidos</h2><p class="mt-1 text-xs text-brand-paper/70" x-text="`${items.length} productos`"></p></div><flux:button type="button" size="sm" icon="plus" variant="primary" x-on:click="openProducts()">Agregar productos</flux:button></div>
                <div class="max-h-[50dvh] overflow-auto"><table class="catalog-table w-full text-left text-sm" x-show="items.length" x-cloak>
                    <thead class="sticky top-0 bg-zinc-100"><tr><th class="px-4 py-3">Código / producto</th><th class="w-32 px-4 py-3 text-center">Cantidad</th><th class="px-4 py-3 text-right">Precio sin IVA</th><th class="px-4 py-3 text-right">Importe sin IVA</th><th class="w-16 px-4 py-3"><span class="sr-only">Quitar</span></th></tr></thead>
                    <tbody><template x-for="(item, index) in items" :key="item.rowKey"><tr>
                        <td><input type="hidden" :name="`items[${index}][productId]`" :value="item.productId" /><span class="catalog-code" x-text="item.code"></span><p class="mt-1 text-sm" x-text="item.name"></p></td>
                        <td><input type="number" :name="`items[${index}][quantity]`" x-model="item.quantity" min="0.01" max="999999.99" step="0.01" required class="w-28 rounded-lg border border-zinc-300 bg-[#fdfcf8] px-3 py-2 text-center text-sm tabular-nums focus:border-brand-yellow focus:outline-none focus:ring-2 focus:ring-brand-yellow" :aria-label="`Cantidad de ${item.name}`" /></td>
                        <td class="text-right tabular-nums" x-text="formatPrice(item.netPrice)"></td><td class="text-right font-semibold tabular-nums" x-text="lineTotal(item)"></td>
                        <td><flux:button type="button" size="xs" variant="ghost" icon="trash" x-on:click="removeProduct(item.rowKey)" x-bind:aria-label="`Quitar ${item.name}`" /></td>
                    </tr></template></tbody>
                </table><div x-show="!items.length" class="flex min-h-48 flex-col items-center justify-center gap-3 p-8 text-center"><flux:icon.cube class="size-8 text-zinc-400" /><p class="text-sm text-zinc-500">Selecciona los productos que formarán este paquete.</p><flux:button type="button" size="sm" x-on:click="openProducts()">Explorar productos</flux:button></div></div>
                <div class="flex items-center justify-between border-t border-zinc-200 px-5 py-4"><span class="text-sm font-medium">Total sin IVA</span><strong class="text-lg tabular-nums" x-text="formatPrice(total)"></strong></div>
            </section>
            <div class="flex justify-end gap-3"><flux:button :href="route('contaduria.paquetes.index')" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check" x-bind:disabled="!items.length">Guardar paquete</flux:button></div>
        </form>
        <flux:modal name="package-products" data-theme="light" class="w-full md:max-w-3xl!" aria-labelledby="package-products-title">
            <div class="space-y-5"><div><flux:heading id="package-products-title" size="lg">Seleccionar productos</flux:heading><p class="mt-2 text-sm text-zinc-500">Busca por código o descripción.</p></div>
                <flux:input icon="magnifying-glass" x-model="search" x-on:input.debounce.300ms="searchProducts()" maxlength="100" placeholder="Buscar productos…" aria-label="Buscar productos para el paquete" />
                <div class="max-h-[42dvh] min-h-48 overflow-auto rounded-xl border border-zinc-200" x-bind:aria-busy="loading">
                    <p x-show="loading" role="status" class="p-8 text-center text-sm text-zinc-500">Cargando productos…</p><div x-show="error" x-cloak class="p-4 text-sm text-red-700" role="alert"><p x-text="error"></p><flux:button type="button" size="xs" class="mt-2" x-on:click="searchProducts(page)">Reintentar</flux:button></div>
                    <template x-for="product in results" :key="product.productId"><div class="flex items-center gap-3 border-b border-zinc-200 p-4 last:border-0"><div class="min-w-0 flex-1"><p class="text-sm font-semibold" x-text="product.name"></p><p class="mt-1 font-mono text-xs text-zinc-500" x-text="product.code"></p><button type="button" x-show="product.configurationError" class="mt-2 text-xs text-amber-700 underline" x-on:click="showNotice(product.configurationError)">Revisar configuración</button></div><span class="text-xs tabular-nums" x-text="formatPrice(product.netPrice)"></span><flux:button type="button" size="sm" icon="plus" x-bind:disabled="!product.canSelect" x-on:click="addProduct(product)" x-bind:aria-label="`Agregar ${product.name}`">Agregar</flux:button></div></template>
                    <p x-show="!loading && !error && !results.length" class="p-8 text-center text-sm text-zinc-500">No se encontraron productos.</p>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3"><span class="text-xs text-zinc-500" x-text="`${totalResults} resultados · Página ${page} de ${pages}`"></span><div class="flex gap-2"><flux:button type="button" size="xs" x-bind:disabled="loading || page <= 1" x-on:click="searchProducts(page - 1)">Anterior</flux:button><flux:button type="button" size="xs" x-bind:disabled="loading || page >= pages" x-on:click="searchProducts(page + 1)">Siguiente</flux:button></div></div>
                <div class="flex items-center justify-between border-t border-zinc-200 pt-4"><span class="text-xs text-zinc-500" x-text="`${items.length} productos en el paquete`"></span><flux:modal.close><flux:button type="button" variant="primary">Volver al paquete</flux:button></flux:modal.close></div>
            </div>
        </flux:modal>
        <x-catalog-notice name="package-message" title="Revisa el producto" kind="error"><p x-text="notice"></p></x-catalog-notice>
    </div>
</x-layouts::app>
