<?php

namespace App\Services\Contaduria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @phpstan-type CatalogField array{label: string, type: string, required?: bool, max?: int, digits?: int, choices?: array<string|int, string>, lookup?: string, suggestions?: string, group?: string, unique?: bool}
 */
class CatalogDefinition
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<literal-string>  $search
     * @param  array<string, string>  $columns
     * @param  array<string, CatalogField>  $fields
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $description,
        public readonly string $icon,
        public readonly string $model,
        public readonly string $labelColumn,
        public readonly array $search,
        public readonly array $columns,
        public readonly array $fields,
    ) {}

    public function newModel(): Model
    {
        return new $this->model;
    }

    /** @return array<string, list<mixed>> */
    public function rules(?Model $record = null): array
    {
        $rules = [];
        foreach ($this->fields as $column => $field) {
            $fieldRules = [($field['required'] ?? false) ? 'required' : 'nullable', ...match ($field['type']) {
                'integer' => ['integer', 'min:0', 'max:2147483647'],
                'decimal' => ['numeric', 'min:0', 'regex:/^\d{1,'.($field['digits'] ?? 20).'}(\.\d{1,6})?$/D'],
                'boolean' => ['boolean'],
                'select' => [Rule::in(array_keys($field['choices'] ?? []))],
                default => ['string', 'max:'.($field['max'] ?? 255)],
            }];
            if ($field['type'] === 'email') {
                $fieldRules[] = 'email';
            }
            if (isset($field['lookup'])) {
                [$table, $key, , $connection] = self::lookupSource($field['lookup']);
                $fieldRules[] = Rule::exists(($connection ?? DB::getDefaultConnection()).'.'.$table, $key);
            }
            if ($field['unique'] ?? false) {
                $rule = Rule::unique($this->model, $column);
                if ($record !== null) {
                    $rule->ignore($record);
                }
                $fieldRules[] = $rule;
            }
            $rules[$column] = $fieldRules;
        }

        return $rules;
    }

    /** @return array{string, string, string, ?string} */
    public static function lookupSource(string $lookup): array
    {
        return match ($lookup) {
            'nationalities' => ['public.nacionalidades', 'id_nacionalidad', 'nacionalidad', null],
            'nationalityNames' => ['public.nacionalidades', 'nacionalidad', 'nacionalidad', null],
            'economicActivities' => ['public.CAT-019', 'codigo', 'valores', 'dtesantacecilia'],
            'departments' => ['public.CAT-012', 'codigo', 'valores', 'dtesantacecilia'],
            'districts' => ['public.distritos', 'id', 'nombre', 'dtesantacecilia'],
            'municipalities' => ['public.municipios', 'codigo', 'nombre', 'dtesantacecilia'],
            'accounts' => ['public.catalogo_contabilidad', 'cuenta', 'descripcion', null],
            'supplierAccounts' => ['public.proveedores', 'cuenta', 'nombre_proveedor', null],
            default => throw new \InvalidArgumentException('Catálogo de referencia inválido.'),
        };
    }
}
