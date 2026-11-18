@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Santa Cecilia" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center">
            <x-app-logo-icon class="size-10" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Santa Cecilia" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-10 items-center justify-center">
            <x-app-logo-icon class="size-10" />
        </x-slot>
    </flux:brand>
@endif
