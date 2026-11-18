<?php

namespace Database\Factories\Libreria;

use App\Models\Libreria\CashReconciliation;
use App\Models\Libreria\CashSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashReconciliation> */
class CashReconciliationFactory extends Factory
{
    protected $model = CashReconciliation::class;

    public function definition(): array
    {
        return ['sesion_id' => CashSession::factory()->closed(), 'estado' => 'pendiente_excel', 'archivos' => [],
            'snapshot' => ['version' => 1, 'sessionId' => 1, 'register' => 'P001', 'date' => now(config('cash.timezone'))->toDateString(),
                'openedAt' => now()->toIso8601String(), 'closedAt' => now()->toIso8601String(), 'openedBy' => 'Operador', 'closedBy' => 'Operador',
                'total' => '0.00', 'receivable' => '0.00', 'methods' => [], 'documents' => ['01' => '0.00', '03' => '0.00'], 'sales' => []]];
    }
}
