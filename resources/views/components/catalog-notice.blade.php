@props(['name', 'title', 'kind' => 'info', 'automatic' => false])

@php
    $icon = match ($kind) { 'success' => 'check-circle', 'error' => 'exclamation-triangle', default => 'information-circle' };
@endphp

<div x-data @if ($automatic) x-init="$nextTick(() => $flux.modal(@js($name)).show())" @endif>
    <flux:modal :name="$name" :dismissible="false" :closable="false" data-theme="light" class="w-full max-w-lg rounded-2xl">
        <div class="space-y-6">
            <div class="flex items-start gap-4">
                <span @class(['flex size-12 shrink-0 items-center justify-center rounded-2xl border', 'border-red-200 bg-red-50 text-red-700' => $kind === 'error', 'border-brand-yellow bg-brand-yellow/20 text-brand-ink' => $kind !== 'error'])><flux:icon :name="$icon" class="size-6" /></span>
                <flux:heading size="lg" class="self-center">{{ $title }}</flux:heading>
            </div>
            <div role="{{ $kind === 'error' ? 'alert' : 'status' }}" class="max-h-80 overflow-y-auto rounded-xl border border-zinc-200 bg-zinc-50 p-5 text-sm leading-7 text-zinc-700">{{ $slot }}</div>
            <div class="flex justify-end border-t border-zinc-200 pt-4">
                <flux:modal.close><flux:button type="button" variant="primary" icon="check">Entendido</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
