<?php

namespace App\Services\Access;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccessControlService
{
    public function synchronize(): void
    {
        DB::transaction(function (): void {
            foreach (config('permissions.catalog', []) as $code => $description) {
                Permission::query()->updateOrCreate(['code' => $code], ['description' => $description]);
            }
            foreach (['admin' => 'Admin', 'contador' => 'Contador', 'libreria' => 'Librería'] as $code => $name) {
                $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $name]);
                if ($code !== 'admin' && ! $role->wasRecentlyCreated) {
                    continue;
                }
                $codes = array_keys(config('permissions.catalog', []));
                if ($code === 'contador') {
                    $codes = array_values(array_filter($codes, fn (string $permission): bool => str_starts_with($permission, 'contaduria.') || in_array($permission, ['libreria.ventas.ver', 'libreria.ventas.invalidar'], true)));
                } elseif ($code === 'libreria') {
                    $codes = array_values(array_filter($codes, fn (string $permission): bool => str_starts_with($permission, 'libreria.ventas.') && $permission !== 'libreria.ventas.invalidar'));
                }
                $codes = array_values(array_unique([...$codes, ...config('cash.role_permissions.'.$code, [])]));
                $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id')->all());
            }
        });
    }

    /** @param list<string> $permissions */
    public function saveRole(?int $id, string $code, string $name, array $permissions): Role
    {
        return DB::transaction(function () use ($id, $code, $name, $permissions): Role {
            $role = $id === null ? new Role : Role::query()->lockForUpdate()->findOrFail($id);
            if ($role->exists) {
                $code = $role->code;
            }
            if ($code === 'admin') {
                throw ValidationException::withMessages(['name' => 'El rol Admin y sus permisos están protegidos.']);
            }
            Validator::make(['code' => $code, 'name' => trim($name), 'permissions' => $permissions], [
                'code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:100'],
                'permissions' => ['array'],
                'permissions.*' => ['string', 'distinct', Rule::in(array_keys(config('permissions.catalog', [])))],
            ], ['code.unique' => 'Ya existe un rol con ese código.', 'code.regex' => 'Usa letras minúsculas, números y guion bajo para el código.'])->validate();
            if ($code !== 'contador' && in_array('libreria.ventas.invalidar', $permissions, true)) {
                throw ValidationException::withMessages(['permissions' => 'Solo Admin y Contador pueden recibir el permiso de invalidación.']);
            }
            $role->fill(['code' => $code, 'name' => trim($name)])->save();
            $role->permissions()->sync(Permission::query()->whereIn('code', $permissions)->pluck('id')->all());

            return $role;
        });
    }

    public function deleteRole(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $role = Role::query()->lockForUpdate()->findOrFail($id);
            if (in_array($role->code, ['admin', 'contador', 'libreria'], true)) {
                throw ValidationException::withMessages(['role' => 'Los roles iniciales no se pueden eliminar.']);
            }
            if ($role->users()->exists()) {
                throw ValidationException::withMessages(['role' => 'Retira el rol de sus usuarios antes de eliminarlo.']);
            }
            $role->delete();
        });
    }

    /** @param list<int|string> $ids */
    public function assignRoles(User $user, array $ids): void
    {
        Validator::make(['roles' => $ids], ['roles' => ['array'], 'roles.*' => ['integer', 'distinct', 'exists:roles,id']])->validate();
        DB::transaction(function () use ($user, $ids): void {
            $admin = Role::query()->where('code', 'admin')->lockForUpdate()->first();
            $roles = Role::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($roles->count() !== count($ids)) {
                throw ValidationException::withMessages(['roles' => 'Uno de los roles ya no está disponible.']);
            }
            if ($admin !== null && ! in_array((int) $admin->id, array_map('intval', $ids), true)) {
                $this->protectLastAdmin($user);
            }
            $user->roles()->sync($ids);
            $user->unsetRelation('roles');
        });
    }

    public function protectLastAdmin(User $user): void
    {
        $admin = Role::query()->where('code', 'admin')->lockForUpdate()->first();
        if ($admin !== null && $admin->users()->whereKey($user->getKey())->exists() && ! $admin->users()->where('users.id', '!=', $user->getKey())->exists()) {
            throw ValidationException::withMessages(['roles' => 'Debe permanecer al menos un usuario con el rol Admin.']);
        }
    }
}
