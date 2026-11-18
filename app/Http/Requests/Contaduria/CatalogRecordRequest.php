<?php

namespace App\Http\Requests\Contaduria;

use App\Services\Contaduria\CatalogDefinition;
use App\Services\Contaduria\CatalogRegistry;
use App\Services\Contaduria\CatalogService;
use App\Services\GeographyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CatalogRecordRequest extends FormRequest
{
    private ?CatalogDefinition $definition = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $action = $this->isMethod('POST') ? 'crear' : 'editar';

        return $this->user()?->can('contaduria.'.$this->route('catalog').'.'.$action) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(CatalogRegistry $catalogs, CatalogService $service): array
    {
        $this->definition = $catalogs->get((string) $this->route('catalog'));
        $id = $this->route('record');
        $record = $id !== null ? $service->find($this->definition, (string) $id) : null;

        return $this->definition->rules($record);
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = [];
            foreach ($this->definition->fields ?? [] as $column => $field) {
                if (in_array($field['lookup'] ?? '', ['departments', 'districts', 'municipalities'], true)) {
                    $fields[$field['lookup']] = $column;
                }
            }
            if (! isset($fields['departments'], $fields['districts'], $fields['municipalities']) || $validator->errors()->hasAny(array_values($fields))) {
                return;
            }
            $department = (string) $this->input($fields['departments'], '');
            $district = (string) $this->input($fields['districts'], '');
            $municipality = (string) $this->input($fields['municipalities'], '');
            if ($department === '' && $district === '' && $municipality === '') {
                return;
            }
            if ($department === '' || $district === '' || $municipality === '' || ! app(GeographyService::class)->isValidSelection($department, $district, $municipality)) {
                $validator->errors()->add($fields['districts'], 'Selecciona un departamento y un distrito válidos; el municipio debe corresponder al distrito.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return array_map(fn (array $field): string => $field['label'], $this->definition->fields ?? []);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'email' => 'Introduce un correo electrónico válido.',
            'max' => 'El campo :attribute supera el máximo permitido (:max).',
            'min' => 'El campo :attribute no puede ser menor que :min.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'numeric' => 'El campo :attribute debe ser numérico.',
            'regex' => 'El campo :attribute tiene un formato o una precisión no permitidos.',
            'in' => 'Selecciona una opción válida para :attribute.',
            'exists' => 'La referencia seleccionada para :attribute no existe.',
            'unique' => 'Ya existe un registro con ese valor de :attribute.',
        ];
    }
}
