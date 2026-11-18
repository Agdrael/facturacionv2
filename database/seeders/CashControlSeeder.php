<?php

namespace Database\Seeders;

use App\Models\Libreria\CashRegister;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Access\AccessControlService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CashControlSeeder extends Seeder
{
    public function run(AccessControlService $access): void
    {
        DB::transaction(function () use ($access): void {
            $access->synchronize();
            $initialize = ! CashRegister::query()->exists();
            foreach (config('cash.registers') as $code) {
                CashRegister::query()->firstOrCreate(['codigo' => $code]);
            }
            if ($initialize) {
                foreach (config('cash.role_permissions') as $code => $permissions) {
                    $role = Role::query()->where('code', $code)->sole();
                    $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('code', $permissions)->pluck('id')->all());
                }
            }
        });
    }
}
