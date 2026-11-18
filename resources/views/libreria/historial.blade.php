<x-layouts::app :title="'Historial de ventas · Librería'">
    <section x-data="libreriaSale(@js($saleCatalogs))" class="space-y-6">
        @if ($cashClosed ?? false)
            <flux:callout icon="lock-closed" variant="warning">
                <flux:callout.heading>La caja está cerrada</flux:callout.heading>
                <flux:callout.text>Abre la caja asignada antes de registrar nuevas ventas. Puedes seguir consultando los documentos existentes.</flux:callout.text>
                @can('libreria.cajas.ver')
                    <flux:button :href="route('libreria.cajas.index')" class="mt-3" wire:navigate>Caja y arqueos</flux:button>
                @endcan
            </flux:callout>
        @endif
        <flux:heading size="xl">Historial de ventas</flux:heading>
        <flux:text>Consulta las ventas y atiende las invalidaciones autorizadas.</flux:text>
        <flux:button variant="primary" x-on:click="openHistory()">Consultar historial</flux:button>
        @include('libreria.partials.venta-historial')
        <x-catalog-notice name="sale-generation-notice" title="Información de la venta"><p class="whitespace-pre-line" x-text="generationNotice"></p></x-catalog-notice>
    </section>
</x-layouts::app>
