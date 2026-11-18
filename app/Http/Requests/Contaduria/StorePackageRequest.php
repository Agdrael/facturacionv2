<?php

namespace App\Http\Requests\Contaduria;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StorePackageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('contaduria.paquetes.crear') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $prefix = DB::getDefaultConnection().'.public.';

        return [
            'code' => ['required', 'string', 'max:100', Rule::unique($prefix.'paquetes_productos', 'codigo_paquete')],
            'name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array:productId,quantity'],
            'items.*.productId' => ['required', 'integer', 'min:1', 'distinct', Rule::exists($prefix.'productos', 'id_producto')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.99', 'decimal:0,2'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /** @return array{code: string, name: string, items: list<array{productId: int, quantity: string}>} */
    public function packageData(): array
    {
        $data = $this->validated();
        $items = [];
        foreach ($data['items'] as $item) {
            $items[] = ['productId' => (int) $item['productId'], 'quantity' => (string) $item['quantity']];
        }

        return ['code' => (string) $data['code'], 'name' => (string) $data['name'], 'items' => $items];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'código', 'name' => 'nombre', 'items' => 'productos', 'items.*.productId' => 'producto', 'items.*.quantity' => 'cantidad'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute supera el máximo permitido (:max).',
            'array' => 'Revisa el listado de productos del paquete.',
            'integer' => 'Selecciona un producto válido.',
            'numeric' => 'La cantidad debe ser numérica.',
            'gt' => 'La cantidad debe ser mayor que cero.',
            'decimal' => 'La cantidad admite como máximo dos decimales.',
            'distinct' => 'No repitas un producto; ajusta su cantidad en una sola fila.',
            'exists' => 'El producto seleccionado ya no está disponible.',
            'code.unique' => 'Ya existe un paquete con ese código.',
        ];
    }
}
