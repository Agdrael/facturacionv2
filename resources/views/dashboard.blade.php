<x-layouts::app :title="__('Panel de administración')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 pb-6 dark:border-brand-yellow/20">
            <div class="flex flex-col gap-2">
                <p class="text-xs font-semibold tracking-[0.18em] text-brand-ink/60 uppercase dark:text-brand-yellow">Santa Cecilia · Facturación</p>
                <h1 class="text-2xl font-semibold tracking-tight text-brand-ink dark:text-brand-paper">Panel de administración</h1>
            </div>
            <span class="rounded-full border border-brand-ink/15 bg-brand-yellow px-3 py-1.5 text-xs font-medium text-brand-ink">Versión 2.0</span>
        </div>
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="relative aspect-video overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-ink/[0.02] dark:border-brand-yellow/20 dark:bg-brand-paper/[0.02]">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-brand-ink/10 dark:stroke-brand-yellow/10" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-ink/[0.02] dark:border-brand-yellow/20 dark:bg-brand-paper/[0.02]">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-brand-ink/10 dark:stroke-brand-yellow/10" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-ink/[0.02] dark:border-brand-yellow/20 dark:bg-brand-paper/[0.02]">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-brand-ink/10 dark:stroke-brand-yellow/10" />
            </div>
        </div>
        <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-ink/[0.02] dark:border-brand-yellow/20 dark:bg-brand-paper/[0.02]">
            <x-placeholder-pattern class="absolute inset-0 size-full stroke-brand-ink/10 dark:stroke-brand-yellow/10" />
        </div>
    </div>
</x-layouts::app>
