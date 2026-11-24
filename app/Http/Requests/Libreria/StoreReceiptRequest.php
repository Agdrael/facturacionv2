<?php

namespace App\Http\Requests\Libreria;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('libreria.recibos.crear') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'requestKey' => ['required', 'uuid'],
            'mode' => ['required', 'in:catalog,client_cxc,academic_cxc'],
            'recipient' => ['required', 'array:name,clientId,documentType,document'],
            'recipient.name' => ['nullable', 'string', 'max:255', 'required_if:mode,catalog'],
            'recipient.clientId' => ['nullable', 'integer', 'min:1'],
            'recipient.documentType' => ['nullable', 'in:13,36,02,03,37'],
            'recipient.document' => ['nullable', 'string', 'max:40'],
            'conceptId' => ['nullable', 'integer', 'min:1', 'required_if:mode,catalog', 'prohibited_unless:mode,catalog'],
            'sourceId' => ['exclude_unless:mode,academic_cxc', 'nullable', 'integer', 'min:1', 'required_without:academicItems'],
            'academicItems' => ['nullable', 'array', 'min:1', 'max:50', 'prohibited_unless:mode,academic_cxc'],
            'academicItems.*' => ['array:sourceId,funding,account,amount,invoice'],
            'academicItems.*.sourceId' => ['required', 'integer', 'min:1'],
            'academicItems.*.funding' => ['required', 'in:normal,beca,bienhechor'],
            'academicItems.*.account' => ['nullable', 'string', 'max:32'],
            'academicItems.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'academicItems.*.invoice' => ['nullable', 'array:origin,uuid,confirmed'],
            'academicItems.*.invoice.origin' => ['required_with:academicItems.*.invoice', 'in:native,legacy'],
            'academicItems.*.invoice.uuid' => ['required_with:academicItems.*.invoice', 'uuid'],
            'academicItems.*.invoice.confirmed' => ['exclude_without:academicItems.*.invoice', 'required_with:academicItems.*.invoice', 'accepted'],
            'sourceKey' => ['nullable', 'string', 'max:150', 'required_if:mode,client_cxc', 'prohibited_unless:mode,client_cxc'],
            'studentId' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'funding' => ['nullable', 'in:normal,beca,bienhechor'],
            'invoice' => ['nullable', 'array:origin,uuid,confirmed', 'prohibited_unless:mode,academic_cxc'],
            'invoice.origin' => ['required_with:invoice', 'in:native,legacy'],
            'invoice.uuid' => ['required_with:invoice', 'uuid'],
            'invoice.confirmed' => ['exclude_without:invoice', 'required_with:invoice', 'accepted'],
            'account' => ['nullable', 'string', 'max:32'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'observation' => ['nullable', 'string', 'max:500'],
            'payments' => ['required', 'array', 'min:1', 'max:20'],
            'payments.*' => ['array:method,amount,reference'],
            'payments.*.method' => ['required', 'string', 'max:2'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'recipients' => ['prohibited'], 'lines' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['required' => 'Completa el campo :attribute.', 'gt' => 'El importe debe ser mayor que cero.', 'decimal' => 'Usa como máximo dos decimales.',
            'invoice.confirmed.accepted' => 'Confirma que la factura corresponde al alumno, concepto y año seleccionados.',
            'academicItems.*.invoice.confirmed.accepted' => 'Confirma la factura y el período de cada CXC seleccionada.',
            'recipients.prohibited' => 'Cada operación emite un solo recibo para una persona.', 'lines.prohibited' => 'Selecciona un solo concepto o una sola factura por operación.'];
    }
}
