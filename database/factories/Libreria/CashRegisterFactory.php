<?php

namespace Database\Factories\Libreria;

use App\Models\Libreria\CashRegister;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashRegister> */
class CashRegisterFactory extends Factory
{
    protected $model = CashRegister::class;

    public function definition(): array
    {
        return ['codigo' => fake()->unique()->randomElement(config('cash.registers'))];
    }
}
