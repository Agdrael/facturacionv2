<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Empleado\EmployeeAccessService;
use Illuminate\Console\Command;

class GrantAccessAdmin extends Command
{
    protected $signature = 'access:grant-admin {email : Correo de un usuario que ya inició sesión}';

    protected $description = 'Agrega Admin a un usuario local vinculado con un empleado de DTE';

    public function handle(AccessControlService $access, EmployeeAccessService $employees): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user === null) {
            $this->error('El usuario no existe. Debe iniciar sesión una vez con WorkOS.');

            return self::FAILURE;
        }
        if ($employees->employeeFor($user) === null) {
            $this->error('El usuario no está vinculado a un empleado autorizado de DTE.');

            return self::FAILURE;
        }
        $access->synchronize();
        $user->roles()->syncWithoutDetaching([Role::query()->where('code', 'admin')->sole()->id]);
        $this->info('Rol Admin agregado a '.$user->email.'. Sus demás roles se conservaron.');

        return self::SUCCESS;
    }
}
