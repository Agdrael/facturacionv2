<?php

namespace App\Console\Commands;

use App\Services\Libreria\LegacyCashBridgeService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CashLegacyBridge extends Command
{
    protected $signature = 'cash:legacy-bridge {action : activate, deactivate o status} {--register= : P001, P002 o P003}';

    protected $description = 'Controla el parche temporal de cierre conjunto V1 y V2';

    public function handle(LegacyCashBridgeService $bridge): int
    {
        $action = $this->argument('action');
        $register = $this->option('register');
        if (! is_string($register) || ! in_array($action, ['activate', 'deactivate', 'status'], true) || ! in_array($register, config('cash.registers'), true)) {
            $this->error('Usa activate, deactivate o status y --register=P001, P002 o P003.');

            return self::FAILURE;
        }
        try {
            if ($action === 'activate') {
                $bridge->activate($register);
            } elseif ($action === 'deactivate') {
                $bridge->deactivate($register);
            }
            $active = $bridge->active($register);
            $this->info($register.': integración '.($active ? 'ACTIVA' : 'DESACTIVADA').'.');
            if ($active) {
                $this->line('Pendientes de conciliación inicial: '.$bridge->preparation($register)['pending']->total());
                $this->line('Revisa Caja y arqueos → Preparar integración V1 antes del primer cierre.');
            }

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }
    }
}
