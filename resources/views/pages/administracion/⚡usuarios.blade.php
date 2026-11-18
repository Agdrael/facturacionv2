<?php

use App\Models\Role;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $selectedRoles = [];
    public string $notice = '';

    #[Locked]
    public ?int $selectedUserId = null;

    public function boot(): void
    {
        Gate::authorize('administracion.usuarios.ver');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectUser(int $id): void
    {
        Gate::authorize('administracion.usuarios.ver');
        $user = User::query()->findOrFail($id);
        $this->selectedUserId = $user->id;
        $this->selectedRoles = $user->roles()->pluck('roles.id')->map(fn ($id): string => (string) $id)->all();
        $this->resetValidation();
        $this->notice = '';
    }

    public function saveRoles(AccessControlService $access): void
    {
        Gate::authorize('administracion.usuarios.asignar_roles');
        abort_if($this->selectedUserId === null, 422);
        $access->assignRoles(User::query()->findOrFail($this->selectedUserId), $this->selectedRoles);
        $this->notice = 'Roles guardados. Los permisos se aplican en la siguiente petición.';
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $search = mb_substr(trim($this->search), 0, 100);
        $users = User::query()->with('roles')->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))->orderBy('name')->paginate(20);
        $selectedUser = $this->selectedUserId === null ? null : User::query()->with('roles')->find($this->selectedUserId);

        return $this->view(['users' => $users, 'roles' => Role::query()->orderBy('id')->get(), 'selectedUser' => $selectedUser, 'effectivePermissions' => $selectedUser?->permissionCodes() ?? []])
            ->title('Usuarios y accesos');
    }
}; ?>

<section class="access-workspace space-y-6">
    <x-admin-heading title="Usuarios y accesos" description="Asigna roles a tu equipo y consulta los permisos de cada persona." active="usuarios" />

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <div class="access-stat"><span class="access-stat-icon"><flux:icon.users class="size-5" /></span><div><p class="access-stat-value">{{ $users->total() }}</p><p class="access-label mt-1">{{ trim($search) === '' ? 'Usuarios registrados' : 'Usuarios encontrados' }}</p></div></div>
        <div class="access-stat"><span class="access-stat-icon"><flux:icon.user-group class="size-5" /></span><div><p class="access-stat-value">{{ $roles->count() }}</p><p class="access-label mt-1">Roles disponibles</p></div></div>
        <div class="access-stat sm:col-span-2 xl:col-span-1"><span class="access-stat-icon"><flux:icon.shield-check class="size-5" /></span><div><p class="text-sm font-semibold">Accesos que se suman</p><p class="access-label mt-1 leading-5">Cada usuario puede tener varios roles.</p></div></div>
    </div>

    @if ($notice)
        <flux:callout icon="check-circle" variant="success">{{ $notice }}</flux:callout>
    @endif

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(20rem,1fr)]">
        <section class="access-panel overflow-hidden">
            <div class="access-panel-heading">
                <div><h2 class="text-sm font-semibold">Directorio de usuarios</h2><p class="access-muted mt-1 text-xs">Selecciona una persona para revisar sus accesos.</p></div>
                <span class="access-badge">{{ $users->total() }} {{ $users->total() === 1 ? 'usuario' : 'usuarios' }}</span>
            </div>
            <div class="p-4">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" aria-label="Buscar usuario" placeholder="Buscar por nombre o correo…" maxlength="100" />
            </div>
            <div class="overflow-x-auto">
                <table class="access-user-table">
                    <thead><tr><th>Usuario</th><th>Roles</th><th class="text-right"><span class="sr-only">Accesos</span></th></tr></thead>
                    <tbody wire:loading.class="opacity-50" wire:target="search">
                        @forelse ($users as $user)
                            <tr wire:key="user-{{ $user->id }}" @class(['is-selected' => $selectedUserId === $user->id])>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="access-avatar" aria-hidden="true">{{ $user->initials() }}</span>
                                        <div class="min-w-0"><p class="max-w-56 break-words font-semibold">{{ $user->name }}</p><p class="access-muted mt-1 max-w-56 break-all text-[11px]">{{ $user->email }}</p></div>
                                    </div>
                                </td>
                                <td><div class="flex min-w-24 flex-wrap gap-1.5">
                                    @forelse ($user->roles as $assignedRole)
                                        <span @class(['access-badge', 'access-badge--admin' => $assignedRole->code === 'admin'])>{{ $assignedRole->name }}</span>
                                    @empty
                                        <span class="access-badge access-badge--pending">Sin rol asignado</span>
                                    @endforelse
                                </div></td>
                                <td class="text-right"><flux:button size="sm" :variant="$selectedUserId === $user->id ? 'primary' : 'ghost'" wire:click="selectUser({{ $user->id }})" wire:loading.attr="disabled" wire:target="selectUser" :aria-label="'Ver accesos de '.$user->name"><flux:icon.chevron-right class="size-4" /></flux:button></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="flex flex-col items-center gap-3 py-12 text-center"><span class="access-stat-icon"><flux:icon.magnifying-glass class="size-5" /></span><p class="text-sm font-semibold">No se encontraron usuarios</p><p class="access-muted text-xs">Prueba con otro nombre o correo.</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">{{ $users->links() }}</div>
        </section>

        @if ($selectedUser)
            <section wire:key="selected-user-{{ $selectedUser->id }}" class="access-panel overflow-hidden xl:sticky xl:top-6">
                <div class="access-panel-heading"><h2 class="text-sm font-semibold">Detalle de acceso</h2>@if ($selectedUser->id === auth()->id())<span class="access-badge">Tu cuenta</span>@endif</div>
                <div class="space-y-6 p-5">
                    <div class="flex items-center gap-4">
                        <span class="access-avatar access-avatar--large" aria-hidden="true">{{ $selectedUser->initials() }}</span>
                        <div class="min-w-0"><h3 class="break-words text-base font-semibold">{{ $selectedUser->name }}</h3><p class="access-muted mt-1 break-all text-xs">{{ $selectedUser->email }}</p></div>
                    </div>
                    <form wire:submit="saveRoles" class="space-y-4">
                        <fieldset class="space-y-3" @cannot('administracion.usuarios.asignar_roles') disabled @endcannot>
                            <legend class="mb-3 flex w-full items-center justify-between gap-3"><span class="text-sm font-semibold">Roles asignados</span><span class="access-badge">{{ count($selectedRoles) }} seleccionados</span></legend>
                            @foreach ($roles as $role)
                                <div wire:key="assign-role-{{ $role->id }}" @class(['access-role-option', 'is-selected' => in_array((string) $role->id, array_map('strval', $selectedRoles), true)])>
                                    <flux:checkbox wire:model.live="selectedRoles" :value="(string) $role->id" :label="$role->name" />
                                    <p class="access-muted ms-7 mt-1 text-[11px] leading-5">{{ match ($role->code) { 'admin' => 'Administración completa del sistema.', 'contador' => 'Permisos de Contaduría e invalidación.', 'libreria' => 'Operación de ventas y documentos propios.', default => 'Permisos personalizados del equipo.' } }}</p>
                                </div>
                            @endforeach
                        </fieldset>
                        <flux:error name="roles" />
                        <flux:error name="roles.*" />
                        @can('administracion.usuarios.asignar_roles')
                            <div class="space-y-3 pt-1"><flux:button type="submit" variant="primary" icon="check" class="w-full" wire:loading.attr="disabled">Guardar roles</flux:button><p class="access-muted text-center text-[11px] leading-5">Los cambios se aplican al guardar.</p></div>
                        @else
                            <p class="access-muted text-xs">Tienes acceso de consulta a esta información.</p>
                        @endcan
                    </form>
                    <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-semibold">Permisos vigentes</h3><span class="access-badge">{{ count($effectivePermissions) }}</span></div>
                        <p class="access-muted mt-1 text-[11px] leading-5">Resultado de los roles guardados de esta persona.</p>
                        <div class="mt-3 space-y-2">
                            @forelse (collect($effectivePermissions)->groupBy(fn ($permissionCode) => explode('.', $permissionCode)[0]) as $module => $modulePermissions)
                                <details class="access-permission-group">
                                    <summary class="access-permission-summary"><span>{{ ['libreria' => 'Librería', 'contaduria' => 'Contaduría', 'administracion' => 'Admin'][$module] ?? ucfirst($module) }}</span><span class="ms-auto access-muted text-[11px]">{{ $modulePermissions->count() }}</span><flux:icon.chevron-right class="size-4 transition-transform" /></summary>
                                    <ul class="access-permission-list px-4 pb-4">
                                        @foreach ($modulePermissions as $permissionCode)
                                            <li><span class="text-xs leading-5">{{ config('permissions.catalog')[$permissionCode] ?? $permissionCode }}</span><code>{{ $permissionCode }}</code></li>
                                        @endforeach
                                    </ul>
                                </details>
                            @empty
                                <div class="rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-700"><p class="access-muted text-xs leading-5">Esta persona todavía no tiene permisos para operar.</p></div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>
        @else
            <aside class="access-panel flex min-h-80 flex-col items-center justify-center gap-4 p-8 text-center">
                <div class="relative"><span class="access-hero-icon size-16!"><flux:icon.users class="size-7" /></span><span class="absolute -right-1 -bottom-1 grid size-6 place-items-center rounded-full bg-brand-yellow text-brand-ink"><flux:icon.key class="size-3.5" /></span></div>
                <div><h2 class="text-base font-semibold">Revisa los accesos de tu equipo</h2><p class="access-muted mt-2 max-w-xs text-xs leading-6">Selecciona un usuario del directorio para asignar sus roles y consultar sus permisos.</p></div>
                <span class="access-badge"><flux:icon.lock-closed class="size-3" /> Los usuarios sin rol quedan pendientes</span>
            </aside>
        @endif
    </div>
</section>
