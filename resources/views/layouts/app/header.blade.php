<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="admin-shell min-h-screen bg-brand-paper text-brand-ink selection:bg-brand-yellow selection:text-brand-ink dark:bg-brand-ink dark:text-brand-paper">
        <flux:header container class="admin-navigation dark border-b border-brand-yellow/20 bg-brand-ink text-brand-paper">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Inicio') }}
                </flux:navbar.item>
                @canany(['libreria.ventas.ver', 'libreria.cajas.ver', 'libreria.recibos.ver'])
                <flux:dropdown>
                    <flux:navbar.item icon="book-open" icon:trailing="chevron-down" :current="request()->routeIs('libreria.*')">Librería</flux:navbar.item>
                    <flux:navmenu>
                        @can('libreria.ventas.ver')
                            <flux:navmenu.item :href="route('libreria.venta')" wire:navigate>Venta</flux:navmenu.item>
                        @endcan
                        @can('libreria.cajas.ver')
                            <flux:navmenu.item :href="route('libreria.cajas.index')" wire:navigate>Caja y arqueos</flux:navmenu.item>
                        @endcan
                        @if (config('receipts.enabled'))
                            @can('libreria.recibos.ver')<flux:navmenu.item :href="route('libreria.recibos.index')" wire:navigate>Recibos</flux:navmenu.item>@endcan
                        @endif
                    </flux:navmenu>
                </flux:dropdown>
                @endcanany
                @canany(array_values(array_filter(array_keys(config('permissions.catalog')), fn ($code) => str_starts_with($code, 'contaduria.') && str_ends_with($code, '.ver'))))
<flux:dropdown>
                    <flux:navbar.item icon="calculator" icon:trailing="chevron-down" :current="request()->routeIs('contaduria.*')">Contaduría</flux:navbar.item>
                    <flux:navmenu>
                        <flux:navmenu.item :href="route('contaduria.catalogos')" wire:navigate>Catálogos</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>
@endcanany
@canany(['administracion.roles.ver', 'administracion.usuarios.ver'])
                <flux:dropdown>
                    <flux:navbar.item icon="shield-check" icon:trailing="chevron-down" :current="request()->routeIs('administracion.*')">Admin</flux:navbar.item>
                    <flux:navmenu>
                        @can('administracion.roles.ver')
                            <flux:navmenu.item :href="route('administracion.roles')" wire:navigate>Roles y permisos</flux:navmenu.item>
                        @endcan
                        @can('administracion.usuarios.ver')
                            <flux:navmenu.item :href="route('administracion.usuarios')" wire:navigate>Usuarios y accesos</flux:navmenu.item>
                        @endcan
                    </flux:navmenu>
                </flux:dropdown>
                @endcanany
            </flux:navbar>

            <flux:spacer />

            <div class="hidden flex-col items-end gap-1.5 sm:flex">
                <span class="rounded-full border border-brand-yellow/25 px-3 py-1.5 text-xs text-brand-yellow">Facturación v2.0</span>
                <span class="text-xs text-brand-paper/60">Punto de venta <span class="ms-1 font-mono font-semibold text-brand-yellow">{{ auth()->user()->puntoventa ?? 'Sin asignar' }}</span></span>
            </div>

            <x-desktop-user-menu />
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="admin-navigation dark border-e border-brand-yellow/20 bg-brand-ink text-brand-paper lg:hidden">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Administración')">
                    <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Inicio') }}
                    </flux:sidebar.item>
                    @canany(['libreria.ventas.ver', 'libreria.cajas.ver', 'libreria.recibos.ver'])
                    <flux:sidebar.group heading="Librería" icon="book-open" expandable :expanded="request()->routeIs('libreria.*')">
                        @can('libreria.ventas.ver')
                            <flux:sidebar.item :href="route('libreria.venta')" :current="request()->routeIs('libreria.venta')" wire:navigate>Venta</flux:sidebar.item>
                        @endcan
                        @can('libreria.cajas.ver')
                            <flux:sidebar.item :href="route('libreria.cajas.index')" :current="request()->routeIs('libreria.cajas.*', 'libreria.arqueos.*')" wire:navigate>Caja y arqueos</flux:sidebar.item>
                        @endcan
                        @if (config('receipts.enabled'))
                            @can('libreria.recibos.ver')<flux:sidebar.item :href="route('libreria.recibos.index')" :current="request()->routeIs('libreria.recibos.*')" wire:navigate>Recibos</flux:sidebar.item>@endcan
                        @endif
                    </flux:sidebar.group>
                    @endcanany
                    @canany(array_values(array_filter(array_keys(config('permissions.catalog')), fn ($code) => str_starts_with($code, 'contaduria.') && str_ends_with($code, '.ver'))))
<flux:sidebar.group heading="Contaduría" icon="calculator" expandable :expanded="request()->routeIs('contaduria.*')">
                        <flux:sidebar.item :href="route('contaduria.catalogos')" :current="request()->routeIs('contaduria.catalogos*')" wire:navigate>Catálogos</flux:sidebar.item>
                    </flux:sidebar.group>
@endcanany
                </flux:sidebar.group>
                <x-admin-sidebar />
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="flex flex-col gap-3 border-t border-brand-paper/10 px-2 pt-4 text-xs">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-brand-paper/60">Facturación</span>
                    <span class="rounded-full border border-brand-yellow/25 px-2 py-1 text-[10px] font-medium text-brand-yellow">v2.0</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-brand-paper/60">Punto de venta</span>
                    <span class="rounded-md bg-brand-yellow/10 px-2 py-1 font-mono font-semibold text-brand-yellow">{{ auth()->user()->puntoventa ?? 'Sin asignar' }}</span>
                </div>
            </div>
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
