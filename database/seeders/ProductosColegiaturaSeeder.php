<?php

namespace Database\Seeders;

use App\Enum\OrigenVenta;
use App\Models\Contaduria\Cuenta;
use App\Models\Contaduria\Producto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductosColegiaturaSeeder extends Seeder
{
    /** @var array<string, array{string, string}> */
    private const PRODUCTS = [
        'COL-001' => ['Parvularia 4', '51010101030102'],
        'COL-002' => ['Parvularia 5', '51010101010102'],
        'COL-003' => ['Parvularia 6', '51010101020102'],
        'COL-004' => ['Primer grado', '51010102010102'],
        'COL-005' => ['Segundo grado', '51010102020102'],
        'COL-006' => ['Tercer grado', '51010102030102'],
        'COL-007' => ['Cuarto grado', '51010103010102'],
        'COL-008' => ['Quinto grado', '51010103020102'],
        'COL-009' => ['Sexto grado', '51010103030102'],
        'COL-010' => ['Séptimo grado', '51010104010102'],
        'COL-011' => ['Octavo grado', '51010104020102'],
        'COL-012' => ['Noveno grado', '51010104030102'],
        'COL-013' => ['Primer año Bachillerato General', '51010110010102'],
        'COL-014' => ['Segundo año Bachillerato General', '51010110020102'],
        'COL-015' => ['Primer año Electrónica', '51010111010102'],
        'COL-016' => ['Segundo año Electrónica', '51010111020102'],
        'COL-017' => ['Tercer año Electrónica', '51010111030102'],
        'COL-018' => ['Primer año Informática', '51010117010102'],
        'COL-019' => ['Segundo año Informática', '51010117020102'],
        'COL-020' => ['Tercer año Informática', '51010117030102'],
        'COL-021' => ['Primer año Electromecánica', '51010118010102'],
        'COL-022' => ['Segundo año Electromecánica', '51010118020102'],
        'COL-023' => ['Tercer año Electromecánica', '51010118030102'],
        'COL-024' => ['Primer año Diseño Gráfico', '51010119010102'],
        'COL-025' => ['Segundo año Diseño Gráfico', '51010119020102'],
        'COL-026' => ['Tercer año Diseño Gráfico', '51010119030102'],
        'COL-027' => ['Primer año Salud', '51010126010102'],
        'COL-028' => ['Segundo año Salud', '51010126020102'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $incomeAccounts = array_column(self::PRODUCTS, 1);
            $existingAccounts = Cuenta::query()->whereIn('cuenta', $incomeAccounts)->lockForUpdate()->get(['cuenta'])
                ->map(fn (Cuenta $account): string => (string) $account->getAttribute('cuenta'))->all();
            $missing = array_diff($incomeAccounts, $existingAccounts);
            if ($missing !== []) {
                throw new RuntimeException('No se crearon productos. Faltan cuentas de ingreso: '.implode(', ', $missing).'.');
            }

            $existing = Producto::query()->whereIn('codigo', array_keys(self::PRODUCTS))->lockForUpdate()->get()->groupBy('codigo');
            foreach ($existing as $code => $products) {
                if ($products->count() !== 1) {
                    throw new RuntimeException('No se crearon productos. El código '.$code.' está duplicado.');
                }
                $product = $products->first();
                if ($product->getAttribute('grupo') !== OrigenVenta::COLEGIATURA->value || $product->getAttribute('tipo_prod') !== 'S') {
                    throw new RuntimeException('No se crearon productos. El código '.$code.' pertenece a otro producto.');
                }
            }

            foreach (self::PRODUCTS as $code => [$level, $account]) {
                $product = $existing->get($code)?->first() ?? new Producto(['codigo' => $code]);
                $product->fill([
                    'descrip' => 'Colegiatura '.$level,
                    'abreviado' => $level,
                    'grupo' => OrigenVenta::COLEGIATURA->value,
                    'tipo_prod' => 'S',
                    'tipo_prec' => 'F',
                    'unidad' => '99',
                    'p_costo' => 0,
                    'p_venta' => 0,
                    'iva2' => 'E',
                    'c_ing' => $account,
                ]);
                if (! $product->exists || $product->isDirty()) {
                    $product->save();
                }
            }
        });
    }
}
