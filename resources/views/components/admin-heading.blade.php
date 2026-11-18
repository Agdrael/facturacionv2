@props(['title', 'description', 'active'])

<header class="access-header">
    <div class="access-hero">
        <div class="flex min-w-0 items-start gap-4 sm:gap-5">
            <span class="access-hero-icon"><flux:icon.shield-check class="size-6 sm:size-7" /></span>
            <div class="min-w-0">
                <p class="access-eyebrow">Admin <span class="mx-2 opacity-40">/</span> Control de acceso</p>
                <flux:heading level="1" class="mt-2 text-2xl! font-semibold! tracking-tight text-brand-paper! sm:text-3xl!">{{ $title }}</flux:heading>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-brand-paper/65">{{ $description }}</p>
            </div>
        </div>
        @isset($actions)
            <div class="access-header-actions">{{ $actions }}</div>
        @endisset
    </div>
    <nav class="access-tabs" aria-label="Secciones de Admin">
        @can('administracion.roles.ver')
            <a href="{{ route('administracion.roles') }}" wire:navigate @class(['access-tab', 'is-active' => $active === 'roles']) @if ($active === 'roles') aria-current="page" @endif>
                <flux:icon.key class="size-4" />
                Roles y permisos
            </a>
        @endcan
        @can('administracion.usuarios.ver')
            <a href="{{ route('administracion.usuarios') }}" wire:navigate @class(['access-tab', 'is-active' => $active === 'usuarios']) @if ($active === 'usuarios') aria-current="page" @endif>
                <flux:icon.users class="size-4" />
                Usuarios y accesos
            </a>
        @endcan
    </nav>
</header>
