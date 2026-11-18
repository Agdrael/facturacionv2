<?php

use App\Models\Role;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ?int $editingId = null;

    public bool $showEditor = false;
    public string $code = '';
    public string $name = '';
    public array $permissions = [];
    public string $notice = '';

    public function boot(): void
    {
        Gate::authorize('administracion.roles.ver');
    }

    public function createRole(): void
    {
        Gate::authorize('administracion.roles.crear');
        $this->reset(['editingId', 'code', 'name', 'permissions', 'notice']);
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function editRole(int $id): void
    {
        Gate::authorize('administracion.roles.editar');
        $role = Role::query()->findOrFail($id);
        abort_if($role->code === 'admin', 403);
        $this->editingId = $role->id;
        $this->code = $role->code;
        $this->name = $role->name;
        $this->permissions = $role->permissions()->pluck('code')->all();
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function saveRole(AccessControlService $access): void
    {
        Gate::authorize($this->editingId === null ? 'administracion.roles.crear' : 'administracion.roles.editar');
        $access->saveRole($this->editingId, $this->code, $this->name, $this->permissions);
        $this->showEditor = false;
        $this->notice = 'Rol y permisos guardados.';
    }

    public function deleteRole(int $id, AccessControlService $access): void
    {
        Gate::authorize('administracion.roles.eliminar');
        $access->deleteRole($id);
        $this->notice = 'Rol eliminado.';
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $groups = collect(config('permissions.catalog'))->groupBy(fn (string $description, string $code): string => implode('.', array_slice(explode('.', $code), 0, 2)), preserveKeys: true);

        return $this->view(['roles' => Role::query()->with('permissions')->withCount('users')->orderBy('id')->get(), 'groups' => $groups])
            ->title('Roles y permisos');
    }
}; ?>

<section class="access-workspace space-y-6">
    <x-admin-heading title="Roles y permisos" description="Define qué puede hacer cada equipo y organiza los accesos del sistema." active="roles">
        @can('administracion.roles.crear')
            <x-slot:actions><flux:button variant="primary" icon="plus" wire:click="createRole">Crear rol</flux:button></x-slot:actions>
        @endcan
    </x-admin-heading>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="access-stat"><span class="access-stat-icon"><flux:icon.user-group class="size-5" /></span><div><p class="access-stat-value">{{ $roles->count() }}</p><p class="access-label mt-1">Roles configurados</p></div></div>
        <div class="access-stat"><span class="access-stat-icon"><flux:icon.key class="size-5" /></span><div><p class="access-stat-value">{{ count(config('permissions.catalog')) }}</p><p class="access-label mt-1">Permisos disponibles</p></div></div>
        <div class="access-stat"><span class="access-stat-icon"><flux:icon.users class="size-5" /></span><div><p class="access-stat-value">{{ $roles->sum('users_count') }}</p><p class="access-label mt-1">Asignaciones de rol</p></div></div>
    </div>

    @if ($notice)
        <flux:callout icon="check-circle" variant="success">{{ $notice }}</flux:callout>
    @endif
    @error('role')
        <flux:callout variant="danger">{{ $message }}</flux:callout>
    @enderror

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><h2 class="text-base font-semibold">Roles del sistema</h2><p class="access-muted mt-1 text-xs">Cada usuario puede reunir los permisos de varios roles.</p></div>
        <span class="access-badge"><flux:icon.lock-closed class="size-3" /> Admin está protegido</span>
    </div>

    <div class="grid items-start gap-4 md:grid-cols-2 2xl:grid-cols-3">
        @foreach ($roles as $role)
            @php
                $roleIcon = match ($role->code) { 'admin' => 'shield-check', 'contador' => 'calculator', 'libreria' => 'book-open', default => 'key' };
                $roleDescription = match ($role->code) {
                    'admin' => 'Administración completa de accesos y operaciones.',
                    'contador' => 'Accesos de Contaduría y gestión de invalidaciones.',
                    'libreria' => 'Operación de ventas y documentos propios.',
                    default => 'Accesos personalizados para las funciones del equipo.',
                };
                $modules = $role->permissions->pluck('code')->map(fn ($code) => explode('.', $code)[0])->unique();
            @endphp
            <article wire:key="role-{{ $role->id }}" class="access-role-card" data-role="{{ $role->code }}">
                <div class="space-y-5 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <span class="access-role-icon"><flux:icon :name="$roleIcon" class="size-5" /></span>
                        <span class="access-badge">{{ in_array($role->code, ['admin', 'contador', 'libreria'], true) ? 'Rol del sistema' : 'Personalizado' }}</span>
                    </div>
                    <div><h3 class="text-lg font-semibold">{{ $role->name }}</h3><p class="access-muted mt-1.5 text-xs leading-5">{{ $roleDescription }}</p></div>
                    <div class="grid grid-cols-2 gap-4 rounded-xl bg-brand-ink/[0.025] p-3.5 dark:bg-brand-paper/[0.025]">
                        <div><p class="text-xl font-semibold tabular-nums">{{ $role->permissions->count() }}</p><p class="access-label mt-1">Permisos</p></div>
                        <div><p class="text-xl font-semibold tabular-nums">{{ $role->users_count }}</p><p class="access-label mt-1">Usuarios asignados</p></div>
                    </div>
                    <div class="flex min-h-6 flex-wrap gap-1.5">
                        @forelse ($modules as $module)
                            <span class="access-badge">{{ ['libreria' => 'Librería', 'contaduria' => 'Contaduría', 'administracion' => 'Admin'][$module] ?? ucfirst($module) }}</span>
                        @empty
                            <span class="access-muted text-xs">Todavía no tiene permisos.</span>
                        @endforelse
                    </div>
                    <details>
                        <summary class="access-permission-summary"><span>Consultar permisos</span><flux:icon.chevron-right class="size-4 transition-transform" /></summary>
                        <ul class="access-permission-list">
                            @forelse ($role->permissions as $permission)
                                <li><span class="text-xs leading-5">{{ $permission->description }}</span><code>{{ $permission->code }}</code></li>
                            @empty
                                <li class="access-muted text-xs">Edita el rol para asignar sus primeros permisos.</li>
                            @endforelse
                        </ul>
                    </details>
                </div>
                <footer class="access-role-footer">
                    @if ($role->code === 'admin')
                        <span class="access-muted inline-flex items-center gap-2 text-xs"><flux:icon.lock-closed class="size-3.5" /> Permisos protegidos</span>
                        <span class="access-muted font-mono text-[10px]">{{ $role->code }}</span>
                    @else
                        @can('administracion.roles.editar')
                            <flux:button size="sm" icon="pencil-square" wire:click="editRole({{ $role->id }})">Editar rol</flux:button>
                        @else
                            <span class="access-muted text-xs">Solo consulta</span>
                        @endcan
                        @if (! in_array($role->code, ['contador', 'libreria'], true))
                            @can('administracion.roles.eliminar')
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteRole({{ $role->id }})" wire:confirm="¿Eliminar este rol? Solo se permite si no tiene usuarios." :aria-label="'Eliminar rol '.$role->name" />
                            @endcan
                        @else
                            <span class="access-muted font-mono text-[10px]">{{ $role->code }}</span>
                        @endif
                    @endif
                </footer>
            </article>
        @endforeach
    </div>

    <flux:modal wire:model="showEditor" variant="flyout" class="w-full md:w-[48rem]">
        <form wire:submit="saveRole" class="access-workspace space-y-6">
            <div class="flex items-start gap-4 pe-8">
                <span class="access-role-icon"><flux:icon.key class="size-5" /></span>
                <div><p class="access-label uppercase tracking-wider">Configuración del rol</p><flux:heading size="lg" class="mt-1">{{ $editingId === null ? 'Crear rol' : 'Editar rol' }}</flux:heading><p class="access-muted mt-2 text-xs leading-5">Selecciona las acciones que podrán realizar sus usuarios.</p></div>
            </div>
            <div class="grid gap-4 rounded-xl border border-zinc-200 p-4 sm:grid-cols-2 dark:border-zinc-700">
                <flux:input wire:model="name" label="Nombre del rol" placeholder="Ej. Supervisor" maxlength="100" />
                <flux:input wire:model="code" label="Código del rol" :readonly="$editingId !== null" placeholder="Ej. supervisor" maxlength="80" description="Identificador estable en minúsculas." />
            </div>
            <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-semibold">Permisos por área</h3><span class="access-badge">{{ count($permissions) }} seleccionados</span></div>
            <flux:error name="permissions" />
            <flux:error name="permissions.*" />
            <div class="space-y-3">
                @foreach ($groups as $group => $options)
                    @php
                        [$module, $resource] = explode('.', $group);
                        $availableOptions = $options->filter(fn ($description, $permissionCode) => $permissionCode !== 'libreria.ventas.invalidar' || $code === 'contador');
                    @endphp
                    <details wire:key="group-{{ $group }}" class="access-permission-group" open>
                        <summary class="access-permission-summary">
                            <span><span class="access-label block text-[10px] uppercase tracking-wider">{{ ['libreria' => 'Librería', 'contaduria' => 'Contaduría', 'administracion' => 'Admin'][$module] ?? $module }}</span><span class="mt-1 block">{{ ucfirst($resource) }}</span></span>
                            <span class="ms-auto access-badge">{{ count(array_intersect($permissions, $availableOptions->keys()->all())) }} / {{ $availableOptions->count() }}</span>
                            <flux:icon.chevron-right class="size-4 transition-transform" />
                        </summary>
                        <div class="access-permission-options sm:grid-cols-2">
                            @foreach ($availableOptions as $permissionCode => $description)
                                <div wire:key="permission-{{ $permissionCode }}" class="min-w-0">
                                    <flux:checkbox wire:model.live="permissions" :value="$permissionCode" :label="$description" />
                                    <p class="access-muted ms-7 mt-1 break-all font-mono text-[10px]">{{ $permissionCode }}</p>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
            <div class="sticky bottom-0 flex flex-wrap items-center justify-end gap-3 border-t border-zinc-200 bg-brand-paper py-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:button type="button" wire:click="$set('showEditor', false)">Cancelar</flux:button>
                <flux:button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">Guardar rol</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
