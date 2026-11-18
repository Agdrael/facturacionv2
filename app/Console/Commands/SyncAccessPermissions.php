<?php

namespace App\Console\Commands;

use App\Services\Access\AccessControlService;
use Illuminate\Console\Command;

class SyncAccessPermissions extends Command
{
    protected $signature = 'access:sync';

    protected $description = 'Sincroniza permisos y crea los roles iniciales sin sobrescribir sus ajustes';

    public function handle(AccessControlService $access): int
    {
        $access->synchronize();
        $this->info('Catálogo de permisos y roles sincronizado.');

        return self::SUCCESS;
    }
}
