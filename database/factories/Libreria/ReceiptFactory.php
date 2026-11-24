<?php

namespace Database\Factories\Libreria;

use App\Models\Libreria\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'batch_id' => fn (array $attributes) => DB::table('libreria.receipt_batches')->insertGetId(['request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
                'user_id' => $attributes['user_id'], 'environment' => '00', 'issuer' => json_encode(['nombre' => 'Emisor de prueba']), 'created_at' => now(), 'updated_at' => now()]),
            'operator_name' => 'Operador de prueba',
            'employee_code' => 'EMP7',
            'register' => 'P004',
            'recipient_type' => 'manual',
            'recipient_id' => (string) Str::uuid(),
            'recipient_name' => fake()->name(),
            'total' => '10.00', 'paid' => '10.00', 'change' => '0.00',
            'status' => 'EMITIDO',
        ];
    }
}
