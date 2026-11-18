<?php

namespace Database\Factories\Libreria;

use App\Models\Libreria\CashRegister;
use App\Models\Libreria\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashSession> */
class CashSessionFactory extends Factory
{
    protected $model = CashSession::class;

    public function definition(): array
    {
        return ['caja_id' => CashRegister::factory(), 'fecha_operativa' => now(config('cash.timezone'))->toDateString(),
            'abierto_por' => User::factory(), 'abierto_nombre' => fake()->name(), 'abierto_empleado' => fake()->numerify('EMP###'), 'abierto_at' => now()];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['cerrado_por' => User::factory(), 'cerrado_nombre' => fake()->name(), 'cerrado_empleado' => 'EMP002', 'cerrado_at' => now(), 'codigo_reapertura' => '123456']);
    }
}
