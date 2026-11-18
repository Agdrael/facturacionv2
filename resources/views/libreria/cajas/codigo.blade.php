<x-layouts::app :title="'Código de reapertura'">
    <section class="access-workspace max-w-2xl! space-y-6">
        <flux:button :href="route('libreria.cajas.index')" icon="arrow-left" wire:navigate>Volver a cajas</flux:button>
        <div class="access-panel space-y-5 p-7">
            <span class="access-hero-icon"><flux:icon.key class="size-6" /></span>
            <flux:heading size="xl">Reapertura de {{ $cashSession->register->codigo }}</flux:heading>
            <p class="access-muted text-sm">Código del cierre de la sesión #{{ $cashSession->id }}. Entrégalo únicamente al operador autorizado.</p>
            <p class="rounded-xl bg-brand-yellow px-5 py-6 text-center font-mono text-4xl font-semibold tracking-[.2em] text-brand-ink">{{ $cashSession->codigo_reapertura }}</p>
            <p class="access-muted text-xs leading-6">Es de un solo uso y solo corresponde a esta caja y jornada. La reapertura exige registrar un motivo.</p>
        </div>
    </section>
</x-layouts::app>
