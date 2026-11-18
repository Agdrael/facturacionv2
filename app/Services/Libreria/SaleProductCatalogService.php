<?php

namespace App\Services\Libreria;

use App\Enum\OrigenVenta;
use App\Models\Contaduria\Cuenta;
use App\Models\Contaduria\Producto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SaleProductCatalogService
{
    public function __construct(private SaleInventoryService $inventory) {}

    public static function configurationError(Producto $product): ?string
    {
        $netPrice = $product->getAttribute('p_venta');

        return match (true) {
            $product->getAttribute('grupo') === OrigenVenta::COLEGIATURA->value => 'Agrega las matrículas y colegiaturas desde la selección de alumno y cuotas.',
            trim((string) $product->getAttribute('codigo')) === '' || trim((string) $product->getAttribute('descrip')) === '' => 'Completa el código y la descripción en el catálogo.',
            ! in_array(trim((string) $product->getAttribute('tipo_prod')), ['B', 'S'], true) => 'Configura el tipo de producto: bien o servicio.',
            ! in_array(trim((string) $product->getAttribute('iva2')), ['A', 'E'], true) => 'Configura la clasificación de IVA: afecta o exenta.',
            ! in_array(trim((string) $product->getAttribute('tipo_prec')), ['F', 'V'], true) => 'Configura el tipo de precio: fijo o variable.',
            ! is_numeric($netPrice) || (float) $netPrice < 0 => 'Configura un precio de venta válido.',
            ! $product->getAttribute('income_account_exists') => 'Configura una cuenta de ingreso válida.',
            default => null,
        };
    }

    /** @return Builder<Producto> */
    public function query(): Builder
    {
        return Producto::query()
            ->select(['id_producto', 'codigo', 'descrip', 'grupo', 'tipo_prod', 'tipo_prec', 'unidad',
                'iva2', 'p_venta', 'descuento', 'c_inv', 'c_gas', 'c_ing'])
            ->addSelect(['income_account_exists' => Cuenta::query()->selectRaw('1')
                ->whereColumn('cuenta', 'productos.c_ing')->limit(1)]);
    }

    /** @param Collection<int, Producto> $products */
    public function attachStock(Collection $products): void
    {
        $stock = $this->inventory->availability(array_values($products
            ->filter(fn (Producto $product): bool => $product->getAttribute('tipo_prod') === 'B')
            ->map(fn (Producto $product): int => (int) $product->getKey())->all()));
        foreach ($products as $product) {
            $product->setAttribute('sale_stock', $stock[(int) $product->getKey()] ?? null);
        }
    }

    /** @param list<literal-string> $columns */
    public function search(QueryBuilder $query, string $search, array $columns): void
    {
        if ($search === '') {
            return;
        }
        $accents = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ', "\u{0301}", "\u{0308}", "\u{0303}"];
        $letters = ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'A', 'E', 'I', 'O', 'U', 'U', 'N', '', '', ''];
        $term = '%'.Str::lower(str_replace($accents, $letters, $search)).'%';
        $query->where(function (QueryBuilder $query) use ($columns, $accents, $letters, $term): void {
            foreach ($columns as $column) {
                $expression = $column;
                $bindings = [];
                foreach ($accents as $index => $accent) {
                    $expression = 'REPLACE('.$expression.', ?, ?)';
                    array_push($bindings, $accent, $letters[$index]);
                }
                $query->orWhereRaw('LOWER('.$expression.') LIKE ?', [...$bindings, $term]);
            }
        });
    }
}
