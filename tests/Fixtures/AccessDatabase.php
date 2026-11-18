<?php

use App\Models\Role;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\Schema;

/** @param list<string> $codes */
function grantTestRoles(User $user, array $codes = ['admin']): void
{
    if (! Schema::hasTable('users')) {
        (require __DIR__.'/../../database/migrations/0001_01_01_000000_create_users_table.php')->up();
    }
    if (! Schema::hasTable('roles')) {
        (require __DIR__.'/../../database/migrations/2026_11_17_212602_create_access_control_tables.php')->up();
    }
    if (! User::query()->whereKey($user->getKey())->exists()) {
        User::factory()->create(['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email]);
    }
    $access = app(AccessControlService::class);
    $access->synchronize();
    $access->assignRoles($user, Role::query()->whereIn('code', $codes)->pluck('id')->all());
}
