<?php

namespace App\Http\Requests\Libreria;

use App\Models\Dte\Cat016;
use App\Models\Dte\Cat017;
use App\Models\Dte\Cat018;
use App\Models\Dte\Cat019;
use App\Models\Dte\Cat022;
use App\Models\Libreria\Cliente;
use App\Services\GeographyService;
use App\Services\Libreria\TuitionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
{
    private const DOCUMENTOS_SOPORTADOS = ['01', '03'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('libreria.ventas.crear') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $prefix = DB::getDefaultConnection().'.';

        return [
            'requestKey' => ['required', 'uuid'],
            'document' => ['required', 'array:type,date,discount,account,thirdPartyType,thirdPartyDocument'],
            'document.type' => ['required', 'string', Rule::in(self::DOCUMENTOS_SOPORTADOS)],
            'document.date' => ['required', 'date_format:Y-m-d', 'in:'.now('America/El_Salvador')->toDateString()],
            'document.discount' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'document.account' => ['required', 'string', 'max:50', Rule::exists($prefix.'public.catalogo_contabilidad', 'cuenta')],
            'document.thirdPartyType' => ['required_with:document.thirdPartyDocument', 'nullable', 'string', 'max:2'],
            'document.thirdPartyDocument' => ['required_with:document.thirdPartyType', 'nullable', 'string', 'max:20'],
            'client' => ['required', 'array:id,name,documentType,document,nrc,address,department,district,municipality,email,phone,phone2,activity'],
            'client.id' => ['nullable', 'integer', Rule::exists($prefix.'libreria.clientes', 'id')],
            'client.name' => ['required', 'string', 'max:250'],
            'client.documentType' => ['nullable', 'string', 'max:2'],
            'client.document' => ['nullable', 'string', 'max:20'],
            'client.nrc' => ['nullable', 'string', 'min:2', 'max:8'],
            'client.address' => ['nullable', 'string', 'max:200'],
            'client.department' => ['nullable', 'string', 'max:2'],
            'client.district' => ['nullable', 'string', 'max:10'],
            'client.municipality' => ['nullable', 'string', 'max:2'],
            'client.email' => ['nullable', 'email:rfc', 'max:100'],
            'client.phone' => ['nullable', 'string', 'min:8', 'max:30'],
            'client.phone2' => ['nullable', 'string', 'max:30'],
            'client.activity' => ['nullable', 'string', 'min:5', 'max:6'],
            'observation' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array:productId,quantity,netPrice,discount,tuition'],
            'items.*.productId' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.99', 'decimal:0,2'],
            'items.*.netPrice' => ['nullable', 'numeric', 'min:0', 'max:99999.999999', 'decimal:0,6'],
            'items.*.discount' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'items.*.tuition' => ['nullable', 'array:studentId,balanceId,fundingType,responsibleType'],
            'items.*.tuition.studentId' => ['required_with:items.*.tuition', 'string', 'regex:/^[0-9]{1,20}$/'],
            'items.*.tuition.balanceId' => ['required_with:items.*.tuition', 'string', 'regex:/^[0-9]{1,20}$/'],
            'items.*.tuition.fundingType' => ['required_with:items.*.tuition', Rule::in(['normal', 'beca', 'bienhechor'])],
            'items.*.tuition.responsibleType' => ['required_with:items.*.tuition', Rule::in(TuitionService::RESPONSIBLES)],
            'operation' => ['required', 'string', 'max:1'],
            'installments' => ['required_if:operation,2', 'nullable', 'integer', 'min:1', 'max:9999'],
            'term' => ['required_if:operation,2', 'nullable', 'string', 'max:2'],
            'total' => [$this->routeIs('libreria.ventas.quote') ? 'nullable' : 'required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'payments' => [$this->routeIs('libreria.ventas.quote') ? 'exclude' : 'required', 'array', 'min:1', 'max:20'],
            'payments.*' => ['array:method,amount,reference'],
            'payments.*.method' => ['required', 'string', 'max:2'],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'payments.*.reference' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->getData();
            $catalogs = [
                'operation' => [Cat016::class, $data['operation']],
                'client.documentType' => [Cat022::class, data_get($data, 'client.documentType')],
                'document.thirdPartyType' => [Cat022::class, data_get($data, 'document.thirdPartyType')],
                'client.activity' => [Cat019::class, data_get($data, 'client.activity')],
            ];
            if ($data['operation'] === '2') {
                $catalogs['term'] = [Cat018::class, $data['term']];
            }
            foreach ($catalogs as $field => [$model, $code]) {
                if ($code !== null && $code !== '' && ! $model::query()->whereKey($code)->exists()) {
                    $validator->errors()->add($field, 'Selecciona una opción válida del catálogo.');
                }
            }
            foreach ($data['payments'] ?? [] as $index => $payment) {
                if (! Cat017::query()->whereKey($payment['method'])->exists()) {
                    $validator->errors()->add('payments.'.$index, 'Selecciona una forma de pago del catálogo.');
                }
            }
            $client = $data['client'];
            if ($data['document']['type'] === '03') {
                $registeredName = empty($client['id']) ? null : Cliente::query()->whereKey($client['id'])->value('nombre');
                if ($registeredName === null || mb_strtoupper(trim($registeredName)) === 'CLIENTES VARIOS' || mb_strtoupper(trim($client['name'])) === 'CLIENTES VARIOS') {
                    $validator->errors()->add('client.id', 'Para emitir un Comprobante de crédito fiscal, selecciona un cliente distinto de Clientes varios.');
                }
            }
            if (! empty($client['document']) && empty($client['documentType'])) {
                $validator->errors()->add('client.documentType', 'Selecciona el tipo de documento del cliente.');
            }
            $document = preg_replace('/[^0-9]/', '', (string) ($client['document'] ?? ''));
            if (! empty($client['document']) && (($client['documentType'] === '13' && strlen($document) !== 9) || ($client['documentType'] === '36' && ! in_array(strlen($document), [9, 14], true)))) {
                $validator->errors()->add('client.document', 'Revisa el formato de DUI o NIT del cliente.');
            }
            $location = array_filter([$client['department'] ?? '', $client['district'] ?? '', $client['municipality'] ?? '', $client['address'] ?? '']);
            if ($location !== [] && (count($location) !== 4 || ! app(GeographyService::class)->isValidSelection((string) ($client['department'] ?? ''), (string) ($client['district'] ?? ''), (string) ($client['municipality'] ?? '')))) {
                $validator->errors()->add('client.district', 'Completa una dirección con departamento, distrito y municipio correspondientes.');
            }
            if ($data['document']['type'] === '03' && (empty($client['document']) || ($client['documentType'] ?? '') !== '36' || empty($client['nrc']) || empty($client['activity']) || count($location) !== 4)) {
                $validator->errors()->add('client', 'Crédito fiscal requiere NIT, NRC, actividad económica y dirección completa.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['required' => 'Completa el campo :attribute.', 'required_if' => 'Completa el campo :attribute.',
            'required_with' => 'Completa la referencia de la cuota (:attribute).', 'document.date.in' => 'La generación del DTE debe tener la fecha actual.',
            'exists' => 'El registro seleccionado ya no existe (:attribute).', 'decimal' => 'Revisa los decimales del campo :attribute.',
            'array' => 'Revisa los datos enviados en :attribute.', 'email' => 'Revisa el correo del cliente.'];
    }
}
