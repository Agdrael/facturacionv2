<?php

namespace App\Services\Contaduria;

use App\Models\Contaduria\Cuenta;
use App\Models\Libreria\Cliente;
use App\Services\GeographyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function __construct(private GeographyService $geography) {}

    private const ACCENTS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        "\u{0301}" => '', "\u{0308}" => '', "\u{0303}" => '',
    ];

    /** @return LengthAwarePaginator<int, Model> */
    public function records(CatalogDefinition $catalog, string $search, int $page): LengthAwarePaginator
    {
        $model = $catalog->newModel();
        $query = $model->newQuery();
        foreach (preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $query->where(function (Builder $query) use ($catalog, $word): void {
                foreach ($catalog->search as $column) {
                    $this->orWhereText($query, $column, $word);
                }
            });
        }

        return $query->orderBy($model instanceof Cuenta ? 'cuenta' : $catalog->labelColumn)->orderBy($model->getKeyName())
            ->paginate(20, ['*'], 'page', $page)->withQueryString();
    }

    /**
     * @param  Builder<Model>  $query
     * @param  literal-string  $column
     */
    private function orWhereText(Builder $query, string $column, string $word): void
    {
        $expression = '"'.$column.'"';
        $bindings = [];
        foreach (self::ACCENTS as $accent => $letter) {
            $expression = 'REPLACE('.$expression.', ?, ?)';
            $bindings[] = $accent;
            $bindings[] = $letter;
        }
        $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(strtr($word, self::ACCENTS)));
        $query->orWhereRaw('LOWER('.$expression.") LIKE ? ESCAPE '!'", [...$bindings, '%'.$search.'%']);
    }

    public function find(CatalogDefinition $catalog, string $id): Model
    {
        abort_unless(ctype_digit($id) && (int) $id <= 2147483647, 404);

        return $catalog->newModel()->newQuery()->findOrFail($id);
    }

    /** @return array<string, list<array{value: string, label: string, department?: string, municipalityCode?: string, municipalityName?: string}>> */
    public function options(CatalogDefinition $catalog): array
    {
        $options = [];
        foreach ($catalog->fields as $field) {
            if (isset($field['lookup']) && ! isset($options[$field['lookup']])) {
                if ($field['lookup'] === 'districts') {
                    $options['districts'] = array_map(fn (array $district): array => [
                        'value' => $district['codigo'], 'label' => $district['valor'],
                        'department' => $district['department'], 'municipalityCode' => $district['municipalityCode'],
                        'municipalityName' => $district['municipalityName'],
                    ], $this->geography->options()['districts']);
                } elseif ($field['lookup'] === 'supplierAccounts') {
                    $accounts = [];
                    foreach (DB::table('public.proveedores')->select(['cuenta', 'nombre_proveedor'])->distinct()
                        ->whereNotNull('cuenta')->where('cuenta', '<>', '')->orderBy('cuenta')->orderBy('nombre_proveedor')->get() as $supplier) {
                        $code = (string) $supplier->cuenta;
                        if (trim($code) === '') {
                            continue;
                        }
                        if (isset($accounts[$code])) {
                            $accounts[$code]['label'] .= ' / '.$supplier->nombre_proveedor;
                        } else {
                            $accounts[$code] = ['value' => $code, 'label' => (string) $supplier->nombre_proveedor];
                        }
                    }
                    $options['supplierAccounts'] = array_values($accounts);
                } else {
                    [$table, $id, $name, $connection] = CatalogDefinition::lookupSource($field['lookup']);
                    $options[$field['lookup']] = array_values(DB::connection($connection)->table($table)
                        ->select([$id, $name])->distinct()->orderBy($field['lookup'] === 'accounts' ? $id : $name)->orderBy($id)->get()
                        ->map(fn (object $row): array => ['value' => (string) $row->{$id}, 'label' => (string) $row->{$name}])->all());
                }
            }
        }

        return $options;
    }

    /** @param array<string, mixed> $data */
    public function create(CatalogDefinition $catalog, array $data): Model
    {
        $record = $catalog->newModel();
        $record->fill($data);
        $record->save();

        return $record;
    }

    /** @param array<string, mixed> $data */
    public function update(CatalogDefinition $catalog, string $id, array $data): void
    {
        DB::transaction(function () use ($catalog, $id, $data): void {
            $record = $catalog->newModel()->newQuery()->lockForUpdate()->findOrFail($id);
            if ($record instanceof Cuenta && isset($data['cuenta']) && $data['cuenta'] !== $record->getAttribute('cuenta') && $this->accountIsUsed($record)) {
                throw ValidationException::withMessages(['cuenta' => 'Esta cuenta está en uso. No se puede cambiar su código.']);
            }
            $record->fill($data);
            $record->save();
        });
    }

    public function delete(CatalogDefinition $catalog, string $id): void
    {
        DB::transaction(function () use ($catalog, $id): void {
            $record = $catalog->newModel()->newQuery()->lockForUpdate()->findOrFail($id);
            if ($record instanceof Cuenta && $this->accountIsUsed($record)) {
                throw ValidationException::withMessages(['catalog' => 'Esta cuenta está en uso y no se puede eliminar.']);
            }
            $record->delete();
        });
    }

    private function accountIsUsed(Cuenta $account): bool
    {
        $code = (string) $account->getAttribute('cuenta');
        foreach ([
            'public.productos' => ['c_inv', 'c_gas', 'c_ing'],
            'public.clientes' => ['cta_cte'],
            (new Cliente)->getTable() => ['cuenta_contable'],
            'public.proveedores' => ['cuenta'],
            'public.catalogo_recibos' => ['cuenta_abono'],
            'public.recibos' => ['cuenta_cargo', 'cuenta_abono'],
        ] as $table => $columns) {
            if (DB::table($table)->where(function ($query) use ($columns, $code): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, $code);
                }
            })->exists()) {
                return true;
            }
        }

        return false;
    }
}
