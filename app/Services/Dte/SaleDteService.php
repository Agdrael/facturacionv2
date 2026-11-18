<?php

namespace App\Services\Dte;

use App\Models\Dte\Cat019;
use App\Services\GeographyService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaleDteService
{
    public function __construct(private GeographyService $geography) {}

    public function nextControlNumber(string $type, string $point): string
    {
        $emitter = DB::table('public.opciones_factura_electronica_emisor')->first();
        $establishment = (string) ($emitter->cod_esta_mh ?? '');
        if (! preg_match('/^[MBSP][0-9]{3}$/', $establishment) || ! preg_match('/^P[0-9]{3}$/', $point)) {
            throw ValidationException::withMessages(['document' => 'Revisa el código de establecimiento y punto de venta.']);
        }
        $environment = (string) config('services.factec.environment', '00');
        $sequence = DteCounterService::sequenceName($type);
        $counter = DB::connection()->getDriverName() === 'pgsql'
            ? DB::transaction(function () use ($sequence): int {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['dte.counter.'.$sequence]);

                return (int) DB::selectOne('SELECT nextval(?::regclass) AS counter', [$sequence])->counter;
            })
            : ($environment === '00' ? (int) DB::table('libreria.venta_dtes')->max('id') + 1 : (int) DB::table('public.historial_dte')->where('tipo_documento', $type)->max('contador') + 1);
        if ($counter < 1 || strlen((string) $counter) > 15) {
            throw ValidationException::withMessages(['document' => 'El correlativo del DTE está fuera de rango.']);
        }
        $number = 'DTE-'.$type.'-'.$establishment.$point.'-'.str_pad((string) $counter, 15, '0', STR_PAD_LEFT);
        if ($environment === '01') {
            DB::table('public.historial_dte')->insert(['fecha' => now('America/El_Salvador')->toDateString(), 'contador' => $counter, 'dte' => $number, 'tipo_documento' => $type]);
        }

        return $number;
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>  $calculation
     * @return array<string, mixed>
     */
    public function build(array $data, array $calculation, string $uuid, string $number, string $point): array
    {
        $config = (array) DB::table('public.opciones_factura_electronica')->first();
        $config['id_ambiente'] = config('services.factec.environment', '00');
        $emitter = (array) DB::table('public.opciones_factura_electronica_emisor')->first();
        $rules = array_fill_keys(['nit', 'nrc', 'nombre', 'codigo_act', 'desc_act', 'depa', 'muni', 'distrito', 'complemento', 'telefono', 'correo', 'cod_esta'], ['required']);
        $rules['correo'] = ['required', 'email:rfc', 'max:100'];
        $rules['complemento'] = ['required', 'string', 'max:200'];
        $rules['nombre'] = ['required', 'string', 'max:250'];
        $rules['desc_act'] = ['required', 'string', 'min:5', 'max:150'];
        $rules['nrc'] = ['required', 'string', 'min:2', 'max:8'];
        $rules['codigo_act'] = ['required', 'string', 'min:5', 'max:6'];
        $rules['telefono'] = ['required', 'string', 'min:8', 'max:30'];
        $rules['cod_esta'] = ['required', 'string', 'size:4'];
        $rules['nombre_comercial'] = ['nullable', 'string', 'max:150'];
        if (Validator::make($emitter, $rules)->fails() || ! in_array($config['id_ambiente'] ?? null, ['00', '01'], true) || ($config['id_modelo'] ?? null) !== 1 || ($config['id_operacion'] ?? null) !== 1) {
            throw ValidationException::withMessages(['document' => 'Completa la configuración del emisor. Esta etapa admite transmisión normal, sin contingencia.']);
        }
        $type = $data['document']['type'];
        $client = $data['client'];
        $activity = empty($client['activity']) ? null : Cat019::query()->whereKey($client['activity'])->value('valores');
        $identity = $this->nullable($client['document'] ?? null);
        if ($identity !== null && in_array($client['documentType'] ?? '', ['13', '36'], true)) {
            $identity = preg_replace('/[^0-9]/', '', $identity);
        }
        $receiver = ['nrc' => $this->nullable($client['nrc'] ?? null), 'nombre' => $client['name'],
            'codActividad' => $this->nullable($client['activity'] ?? null), 'descActividad' => $activity,
            'direccion' => empty($client['address']) ? null : $this->geography->fiscalAddress((string) $client['department'], (string) $client['municipality'], (string) $client['district'], (string) $client['address']),
            'telefono' => $this->nullable($client['phone'] ?? null), 'correo' => $this->nullable($client['email'] ?? null)];
        $receiver = $type === '01' ? ['tipoDocumento' => $identity === null ? null : $client['documentType'], 'numDocumento' => $identity, ...$receiver]
            : ['nit' => $identity, ...$receiver, 'nombreComercial' => null];
        $body = [];
        foreach ($calculation['lines'] as $index => $line) {
            $entry = ['numItem' => $index + 1, 'tipoItem' => $line['productType'] === 'S' ? 2 : 1,
                'numeroDocumento' => null, 'codigo' => $line['code'], 'codTributo' => null, 'descripcion' => $line['name'],
                'cantidad' => (float) $line['quantity'], 'uniMedida' => $line['unit'],
                'precioUni' => (float) ($type === '01' ? $line['grossPrice'] : $line['netPrice']),
                'montoDescu' => (float) $line['itemDiscount'], 'ventaNoSuj' => 0,
                'ventaExenta' => $line['taxable'] ? 0 : (float) $line['amount'], 'ventaGravada' => $line['taxable'] ? (float) $line['amount'] : 0,
                'tributos' => $type === '03' && $line['taxable'] ? ['20'] : null, 'psv' => 0, 'noGravado' => 0];
            if ($type === '01') {
                $entry['ivaItem'] = $line['taxable'] ? (float) BigDecimal::of($line['amount'])->multipliedBy(13)->dividedBy(113, 8, RoundingMode::HalfUp)->__toString() : 0;
            }
            $body[] = $entry;
        }
        $payments = $data['payments'] ?? [];
        $remainingChange = BigDecimal::of((string) ($calculation['change'] ?? '0'));
        $dtePayments = [];
        foreach ($payments as $payment) {
            $amount = BigDecimal::of((string) $payment['amount']);
            if ($payment['method'] === '01' && ! $remainingChange->isZero()) {
                $deduct = BigDecimal::min($amount, $remainingChange);
                $amount = $amount->minus($deduct);
                $remainingChange = $remainingChange->minus($deduct);
            }
            if ($amount->isZero()) {
                continue;
            }
            $dtePayments[] = ['codigo' => $payment['method'], 'montoPago' => (float) (string) $amount,
                'referencia' => $payment['method'] === '01' ? null : $this->nullable($payment['reference'] ?? null), 'plazo' => null, 'periodo' => null];
        }
        if ($data['operation'] === '2') {
            $credit = BigDecimal::of($calculation['total'])->minus((string) ($calculation['paid'] ?? '0'));
            if ($credit->isGreaterThan(0)) {
                $dtePayments[] = ['codigo' => null, 'montoPago' => (float) (string) $credit, 'referencia' => null, 'plazo' => $data['term'], 'periodo' => (int) $data['installments']];
            }
        }
        $summary = ['totalNoSuj' => 0, 'totalExenta' => (float) $calculation['exempt'], 'totalGravada' => (float) $calculation['taxed'],
            'subTotalVentas' => (float) (string) BigDecimal::of($calculation['exempt'])->plus($calculation['taxed']),
            'descuNoSuj' => 0, 'descuExenta' => (float) $calculation['exemptDiscount'], 'descuGravada' => (float) $calculation['taxedDiscount'],
            'porcentajeDescuento' => (float) ($data['document']['discount'] ?? 0),
            'totalDescu' => (float) (string) BigDecimal::of($calculation['itemDiscount'])->plus($calculation['exemptDiscount'])->plus($calculation['taxedDiscount']),
            'tributos' => $type === '03' && (float) $calculation['vat'] > 0 ? [['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => (float) $calculation['vat']]] : null,
            'subTotal' => (float) $calculation['subtotal'], 'ivaRete' => 0, 'montoTotalOperacion' => (float) $calculation['total'],
            'totalNoGravado' => 0, 'totalPagar' => (float) $calculation['total'], 'totalLetras' => null, 'saldoFavor' => 0,
            'condicionOperacion' => (int) $data['operation'], 'pagos' => $dtePayments ?: null,
            'numPagoElectronico' => null, 'observaciones' => $this->nullable($data['observation'] ?? null)];
        if ($type === '01') {
            $summary['totalIva'] = (float) $calculation['vat'];
        } else {
            $summary['ivaPerci'] = 0;
        }
        $appendix = null;
        if (! empty($data['document']['thirdPartyDocument'])) {
            $appendix = [['campo' => 'documentoTercero', 'etiqueta' => 'Documento tercero ('.$data['document']['thirdPartyType'].')', 'valor' => $data['document']['thirdPartyDocument']]];
        }

        return ['identificacion' => ['version' => $type === '01' ? 2 : 4, 'ambiente' => $config['id_ambiente'], 'tipoDte' => $type,
            'numeroControl' => $number, 'codigoGeneracion' => $uuid, 'tipoModelo' => 1, 'tipoOperacion' => 1,
            'tipoContingencia' => null, 'motivoContin' => null, 'fecEmi' => $data['document']['date'],
            'horEmi' => now('America/El_Salvador')->format('H:i:s'), 'tipoMoneda' => 'USD'],
            'documentoRelacionado' => null, 'emisor' => ['nit' => preg_replace('/[^0-9]/', '', (string) $emitter['nit']), 'nrc' => $emitter['nrc'],
                'nombre' => $emitter['nombre'], 'codActividad' => $emitter['codigo_act'], 'descActividad' => $emitter['desc_act'],
                'nombreComercial' => $this->nullable($emitter['nombre_comercial'] ?? null),
                'direccion' => $this->geography->fiscalAddress((string) $emitter['depa'], (string) $emitter['muni'], (string) $emitter['distrito'], (string) $emitter['complemento'], 'document'),
                'telefono' => $emitter['telefono'], 'correo' => $emitter['correo'], 'codEstable' => $emitter['cod_esta'], 'codPuntoVenta' => $point],
            'receptor' => $receiver, 'otrosDocumentos' => null, 'ventaTercero' => null, 'cuerpoDocumento' => $body, 'resumen' => $summary, 'apendice' => $appendix];
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }
}
