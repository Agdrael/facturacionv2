<?php

namespace App\Http\Requests\Libreria;

use App\Models\Dte\Cat022;
use App\Models\Dte\Cat024;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InvalidateSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $owner = DB::table('libreria.venta_dtes')->where('id', $this->route('journal'))->value('user_id');

        return $owner !== null && Gate::allows('libreria.ventas.invalidar');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reasonType' => ['required', 'string', Rule::in(['1', '2', '3']), Rule::exists(Cat024::class, 'codigo')],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
            'replacement' => ['nullable', 'uuid', 'required_if:reasonType,1,3', 'prohibited_if:reasonType,2'],
            'responsibleName' => ['required', 'string', 'max:100'],
            'responsibleType' => ['required', 'string', Rule::exists(Cat022::class, 'codigo')],
            'responsibleDocument' => ['required', 'string', 'max:20'],
            'requesterName' => ['required', 'string', 'max:100'],
            'requesterType' => ['required', 'string', Rule::exists(Cat022::class, 'codigo')],
            'requesterDocument' => ['required', 'string', 'max:20'],
            'confirmed' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'replacement', 'responsibleName', 'responsibleDocument', 'requesterName', 'requesterDocument'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reasonType' => 'tipo de invalidación', 'reason' => 'motivo', 'replacement' => 'UUID del DTE de reemplazo',
            'responsibleName' => 'nombre del responsable', 'responsibleType' => 'tipo de documento del responsable', 'responsibleDocument' => 'documento del responsable',
            'requesterName' => 'nombre del solicitante', 'requesterType' => 'tipo de documento del solicitante', 'requesterDocument' => 'documento del solicitante'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.',
            'min' => 'El campo :attribute debe tener al menos :min caracteres.', 'max' => 'El campo :attribute admite hasta :max caracteres.',
            'in' => 'Selecciona un :attribute válido.', 'exists' => 'Selecciona un :attribute del catálogo.',
            'uuid' => 'El campo :attribute debe ser un UUID válido.', 'replacement.required_if' => 'Este tipo de invalidación requiere un DTE de reemplazo aceptado.',
            'replacement.prohibited_if' => 'Al rescindir la operación no se indica un DTE de reemplazo.',
            'confirmed.accepted' => 'Confirma la solicitud de invalidación antes de continuar.'];
    }
}
