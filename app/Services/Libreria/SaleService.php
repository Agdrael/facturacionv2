<?php

namespace App\Services\Libreria;

use App\Models\Libreria\Cliente;
use App\Models\User;
use App\Services\Dte\SaleDteService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(private SaleInventoryService $inventory, private SaleCalculationService $calculator, private SaleDteService $dtes, private CashRegisterService $cash) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function quote(array $data): array
    {
        return $this->inventory->withLockedStock($this->stockItems($data), fn (): array => $this->calculator->calculate($data));
    }

    /** @param array<string, mixed> $data */
    public function generate(array $data, User $user): int
    {
        foreach ($data['items'] as $item) {
            if (($item['tuition'] ?? null) !== null && config('libreria.academic_payment_timing') !== 'api') {
                throw ValidationException::withMessages(['items' => 'La generación de DTE con colegiaturas está pendiente de definir cuándo se aplicará el pago en la plataforma.']);
            }
        }

        return DB::transaction(function () use ($data, $user): int {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('libreria.generar_dte'), hashtext(?))", [$data['requestKey']]);
            }
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = DB::table('libreria.venta_dtes')->where('request_key', $data['requestKey'])->first();
            if ($existing !== null) {
                abort_unless((int) $existing->user_id === (int) $user->id, 403);
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['requestKey' => 'La solicitud ya fue registrada con otros datos. Consulta el historial.']);
                }

                return (int) $existing->id;
            }

            $sessionId = $this->cash->saleSession($user);

            return $this->inventory->withLockedStock($this->stockItems($data), function (array $allocations) use ($data, $user, $hash, $sessionId): int {
                $calculation = $this->calculator->calculate($data);
                if (! BigDecimal::of((string) $data['total'])->isEqualTo($calculation['total'])) {
                    throw ValidationException::withMessages(['total' => 'El total cambió a $'.$calculation['total'].'. Vuelve a confirmar los productos y revisa el pago.']);
                }
                $client = empty($data['client']['id']) ? Cliente::query()->whereRaw('UPPER(TRIM(nombre)) = ?', ['CLIENTES VARIOS'])->sole() : Cliente::query()->whereKey((int) $data['client']['id'])->firstOrFail();
                $debitAccount = $data['document']['account'];
                if ($data['operation'] === '2') {
                    $debitAccount = (string) $client->getAttribute('cuenta_contable');
                    if (! $client->getAttribute('credito') || $debitAccount === '' || ! DB::table('public.catalogo_contabilidad')->where('cuenta', $debitAccount)->exists() || $debitAccount === '110204010122') {
                        throw ValidationException::withMessages(['operation' => 'El cliente debe tener crédito autorizado y una cuenta contable válida.']);
                    }
                }
                $paid = BigDecimal::of(0);
                $cash = BigDecimal::of(0);
                foreach ($data['payments'] as $payment) {
                    $paid = $paid->plus((string) $payment['amount']);
                    if ($payment['method'] === '01') {
                        $cash = $cash->plus((string) $payment['amount']);
                    }
                }
                $change = $paid->minus($calculation['total']);
                if (($data['operation'] !== '2' && $change->isLessThan(0)) || $change->isGreaterThan($cash)) {
                    throw ValidationException::withMessages(['payments' => 'Completa el pago. El excedente solo puede devolverse de lo recibido en efectivo.']);
                }
                $calculation['paid'] = (string) $paid;
                $calculation['change'] = $change->isGreaterThan(0) ? (string) $change : '0';
                $uuid = Str::upper((string) Str::uuid());
                $point = (string) $user->getAttribute('puntoventa');
                $number = $this->dtes->nextControlNumber($data['document']['type'], $point);
                $document = $this->dtes->build($data, $calculation, $uuid, $number, $point);
                $saleId = DB::table('libreria.ventas')->insertGetId([
                    'cliente_id' => $client->getKey(), 'fecha' => $data['document']['date'], 'caja' => $point,
                    'tipo_documento' => $data['document']['type'], 'uuidv4' => $uuid, 'numero_control' => $number,
                    'operacion' => $data['operation'], 'pagos' => $data['operation'] === '2' ? (string) $data['installments'] : null,
                    'plazos' => $data['operation'] === '2' ? $data['term'] : null, 'total' => $calculation['total'],
                    'estado' => 'GENERADO', 'created_at' => now(), 'updated_at' => now(),
                    ...($sessionId === null ? [] : ['caja_sesion_id' => $sessionId]),
                ]);
                $prices = [];
                foreach ($calculation['lines'] as $line) {
                    $prices[$line['productId']] = $line['netPrice'];
                    DB::table('libreria.venta_detalles')->insert([
                        'venta_id' => $saleId, 'producto_id' => $line['productId'], 'carnet_estudiante' => $line['tuition']['studentId'] ?? null,
                        'nombre' => $line['name'], 'cantidad' => $line['quantity'], 'valor_unitario' => $line['netPrice'], 'valor_unitario_iva' => $line['grossPrice'],
                        'porcentaje_descuento' => $line['discountPercent'], 'valor_descuento' => $line['netDiscount'],
                        'valor_final' => (string) BigDecimal::of($line['netAmount'])->multipliedBy($line['taxable'] ? '1.13' : '1')->toScale(8, RoundingMode::HalfUp),
                        'cuenta_cargo' => $debitAccount, 'cuenta_abono' => $line['incomeAccount'], 'origen' => $line['origin'], 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($line['tuition'] !== null) {
                        $fee = $line['tuition'];
                        DB::table('libreria.venta_cuotas')->insert(['venta_id' => $saleId, 'saldo_id' => $fee['balanceId'], 'financiamiento' => $fee['fundingType'],
                            'carnet_estudiante' => $fee['studentId'], 'anio' => $fee['schoolYear'], 'mes' => $fee['month'], 'monto' => $line['netAmount'], 'ambiente' => $document['identificacion']['ambiente']]);
                    }
                }
                $inventoryId = $this->inventory->recordDocument($allocations, $prices, $data['document']['type'], $uuid, $data['document']['date'], (string) $user->id);

                return DB::table('libreria.venta_dtes')->insertGetId([
                    'venta_id' => $saleId, 'user_id' => $user->id, 'request_key' => $data['requestKey'], 'request_hash' => $hash,
                    'codigo_generacion' => $uuid, 'numero_control' => $number, 'documento' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    'cliente' => json_encode($data['client'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'pagos' => json_encode(['received' => $data['payments'], 'paid' => (string) $paid, 'change' => $calculation['change']], JSON_THROW_ON_ERROR),
                    'inventario_doc_id' => $inventoryId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        }, 3);
    }

    /** @param array<string, mixed> $data
     * @return list<array{productId: int, quantity: string}>
     */
    private function stockItems(array $data): array
    {
        return array_values(array_map(fn (array $item): array => ['productId' => (int) $item['productId'], 'quantity' => (string) BigDecimal::of((string) $item['quantity'])->toScale(2)], $data['items']));
    }
}
