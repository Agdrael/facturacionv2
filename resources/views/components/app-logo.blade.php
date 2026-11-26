@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand {{ $attributes->class('flex-1 px-0!') }} aria-label="Facturación Santa Cecilia">
        <x-slot name="logo" class="h-10 w-full min-w-0 justify-start">
            <img src="{{ asset('images/logo_factv2_blanco_color.png') }}" alt="Facturación Santa Cecilia v2.0" width="1808" height="221" class="h-auto w-full max-w-56 object-contain object-left" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand {{ $attributes->class('min-w-0') }} aria-label="Facturación Santa Cecilia">
        <x-slot name="logo" class="h-10 w-52 max-w-full justify-start">
            <img src="{{ asset('images/logo_factv2_blanco_color.png') }}" alt="Facturación Santa Cecilia v2.0" width="1808" height="221" class="h-auto w-full object-contain object-left" />
        </x-slot>
    </flux:brand>
@endif
