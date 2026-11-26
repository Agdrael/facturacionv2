<?php

namespace Database\Seeders;

use App\Models\Contaduria\Producto;
use App\Models\Plataforma\GradoEstudiante;
use App\Services\Libreria\TuitionProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ServiciosAcademicosSeeder extends Seeder
{
    /** @var array<string, array{string, string}> */
    private const ASSIGNMENTS = [
        'COL-001' => ['Parvularia 4', ''],
        'COL-002' => ['Parvularia 5', ''],
        'COL-003' => ['Parvularia 6', ''],
        'COL-004' => ['1°', ''],
        'COL-005' => ['2°', ''],
        'COL-006' => ['3°', ''],
        'COL-007' => ['4°', ''],
        'COL-008' => ['5°', ''],
        'COL-009' => ['6°', ''],
        'COL-010' => ['7°', ''],
        'COL-011' => ['8°', ''],
        'COL-012' => ['9°', ''],
        'COL-013' => ['1er. Año', 'Bachillerato General'],
        'COL-014' => ['2° Año', 'Bachillerato General'],
        'COL-015' => ['1er. Año', 'Electrónica'],
        'COL-016' => ['2° Año', 'Electrónica'],
        'COL-017' => ['3er. Año', 'Electrónica'],
        'COL-018' => ['1er. Año', 'Informática'],
        'COL-019' => ['2° Año', 'Informática'],
        'COL-020' => ['3er. Año', 'Informática'],
        'COL-021' => ['1er. Año', 'Electromecánica'],
        'COL-022' => ['2° Año', 'Electromecánica'],
        'COL-023' => ['3er. Año', 'Electromecánica'],
        'COL-024' => ['1er. Año', 'Diseño Gráfico'],
        'COL-025' => ['2° Año', 'Diseño Gráfico'],
        'COL-026' => ['3er. Año', 'Diseño Gráfico'],
        'COL-027' => ['1er. Año', 'Salud'],
        'COL-028' => ['2° Año', 'Salud'],
    ];

    public function run(): void
    {
        $grades = GradoEstudiante::query()->get(['id', 'title'])
            ->groupBy(fn (GradoEstudiante $grade): string => TuitionProductService::normalize((string) $grade->getAttribute('title')));
        DB::transaction(function () use ($grades): void {
            $products = Producto::query()->whereIn('codigo', array_keys(self::ASSIGNMENTS))->lockForUpdate()->get()->groupBy('codigo');
            $rows = [];
            $now = now();
            foreach (self::ASSIGNMENTS as $code => [$title, $specialty]) {
                $matches = $products->get($code);
                if ($matches === null || $matches->count() !== 1) {
                    throw new RuntimeException('Debe existir un único producto '.$code.'. Ejecuta primero ProductosColegiaturaSeeder o corrige el catálogo.');
                }
                $product = $matches->sole();
                if ($product->getAttribute('grupo') !== 'COLEGIATURA' || $product->getAttribute('tipo_prod') !== 'S'
                    || $product->getAttribute('tipo_prec') !== 'F' || $product->getAttribute('iva2') !== 'E') {
                    throw new RuntimeException('El producto '.$code.' debe ser un servicio de colegiatura exento y de precio fijo.');
                }
                $matchingGrades = $grades->get(TuitionProductService::normalize($title));
                if ($matchingGrades === null || $matchingGrades->isEmpty()) {
                    throw new RuntimeException('No se encontró el grado '.$title.' en la plataforma.');
                }
                foreach ($matchingGrades as $grade) {
                    $specialties = $specialty === 'Informática' ? ['Informática', 'Desarrollo de Software'] : [$specialty];
                    foreach ($specialties as $name) {
                        $rows[] = [
                            'producto_id' => $product->getKey(), 'grado_id' => (int) $grade->getKey(),
                            'especialidad' => TuitionProductService::normalize($name), 'financiamiento' => 'normal',
                            'concepto' => 'colegiatura', 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
            }
            DB::table('libreria.servicios_academicos')->upsert($rows,
                ['grado_id', 'especialidad', 'financiamiento', 'concepto'], ['producto_id', 'updated_at']);
            // V1 shares tuition income accounts; each funding source keeps its own academic balances.
            foreach (['beca', 'bienhechor'] as $funding) {
                DB::table('libreria.servicios_academicos')->insertOrIgnore(array_map(
                    fn (array $row): array => array_replace($row, ['financiamiento' => $funding]), $rows,
                ));
            }
        });
    }
}
