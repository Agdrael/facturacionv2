<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="admin-shell min-h-screen bg-brand-paper text-brand-ink selection:bg-brand-yellow selection:text-brand-ink dark:bg-brand-ink dark:text-brand-paper">
        <flux:sidebar sticky collapsible="mobile" class="admin-navigation dark border-e border-brand-yellow/20 bg-brand-ink text-brand-paper">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Administración')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
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
                        <flux:sidebar.item :href="route('contaduria.catalogos')" :current="request()->routeIs('contaduria.catalogos*', 'contaduria.contadores.*')" wire:navigate>
                            Catálogos
                        </flux:sidebar.item>
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

            <x-desktop-user-menu class="hidden lg:block" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="dark border-b border-brand-yellow/20 bg-brand-ink text-brand-paper lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
