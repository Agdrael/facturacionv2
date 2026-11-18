@canany(['administracion.roles.ver', 'administracion.usuarios.ver'])
    <flux:sidebar.group heading="Admin" icon="shield-check" expandable :expanded="request()->routeIs('administracion.*')">
        @can('administracion.roles.ver')
            <flux:sidebar.item :href="route('administracion.roles')" :current="request()->routeIs('administracion.roles')" wire:navigate>
                Roles y permisos
            </flux:sidebar.item>
        @endcan
        @can('administracion.usuarios.ver')
            <flux:sidebar.item :href="route('administracion.usuarios')" :current="request()->routeIs('administracion.usuarios')" wire:navigate>
                Usuarios y accesos
            </flux:sidebar.item>
        @endcan
    </flux:sidebar.group>
@endcanany
