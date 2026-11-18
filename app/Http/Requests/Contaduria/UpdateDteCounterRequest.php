<?php

namespace App\Http\Requests\Contaduria;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDteCounterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('contaduria.contadores.editar') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'string', Rule::in([config('services.factec.environment')])],
            'expected' => ['required', 'integer', 'min:1', 'max:1000000000000000'],
            'next' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'confirmed' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $next = $this->input('next');
        if (is_string($next) && preg_match('/^[0-9]{1,15}$/D', trim($next)) === 1) {
            $this->merge(['next' => ltrim(trim($next), '0') ?: '0']);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'environment.required' => 'No se recibió el ambiente del contador. Recarga la página.',
            'environment.string' => 'El ambiente del contador no es válido.',
            'environment.in' => 'El ambiente cambió. Recarga la página antes de modificar el contador.',
            'expected.required' => 'No se recibió el valor actual. Recarga la página.',
            'expected.integer' => 'El valor actual del contador no es válido.',
            'expected.min' => 'El valor actual del contador no es válido.',
            'expected.max' => 'El valor actual del contador está fuera de rango.',
            'next.required' => 'Indica el siguiente correlativo que se utilizará.',
            'next.integer' => 'El correlativo debe contener únicamente números enteros.',
            'next.min' => 'El correlativo debe ser mayor que cero.',
            'next.max' => 'El correlativo admite como máximo 15 dígitos.',
            'confirmed.accepted' => 'Confirma el cambio del contador antes de guardar.',
        ];
    }
}
