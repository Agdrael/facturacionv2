<?php

namespace App\Services\Libreria;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use stdClass;

class CashMovementCalculator
{
    public const METHOD_LABELS = ['01' => 'Efectivo', '02' => 'Tarjeta débito', '03' => 'Tarjeta crédito',
        '04' => 'Cheque', '05' => 'Transferencia', '11' => 'Bitcoin', '99' => 'Pago en línea', 'V1_CARD' => 'Tarjeta V1, sin especificar'];

    public function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['caja' => $message]);
        }
    }

    public function cents(mixed $amount): int
    {
        $this->require(is_string($amount) || is_int($amount) || is_float($amount), 'El importe no está registrado; requiere conciliación.');

        return ReceiptMoney::cents((string) BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp));
    }

    /** @param array<array-key, mixed> $received
     * @return array{total: string, paid: string, receivable: string, payments: array<array-key, string>, change: string} */
    public function native(array $received, string $change, string $total, string $operation = '1'): array
    {
        $net = [];
        foreach ($received as $payment) {
            $this->require(is_array($payment) && isset($payment['method'], $payment['amount'])
                && (is_string($payment['method']) || is_int($payment['method']))
                && (is_string($payment['amount']) || is_numeric($payment['amount'])), 'Un medio de pago tiene datos incompletos.');
            $method = (string) $payment['method'];
            $amount = BigDecimal::of((string) $payment['amount']);
            $this->require(! $amount->isNegative(), 'Un medio de pago tiene importe negativo.');
            $net[$method] = BigDecimal::of($net[$method] ?? '0')->plus($amount);
        }
        $returned = BigDecimal::of($change);
        $this->require(! $returned->isNegative(), 'El cambio no puede ser negativo.');
        if ($returned->isPositive()) {
            $net['01'] = BigDecimal::of($net['01'] ?? '0')->minus($returned);
            $this->require(! $net['01']->isNegative(), 'El cambio excede el efectivo registrado.');
        }
        $paid = BigDecimal::zero();
        foreach ($net as $value) {
            $paid = $paid->plus($value);
        }
        $amount = BigDecimal::of($total);
        $this->require(! $amount->isNegative(), 'El documento tiene importe negativo sin anulación.');
        $balance = $operation === '2' && $amount->isGreaterThan($paid) ? $amount->minus($paid) : BigDecimal::zero();

        return ['total' => (string) $amount->toScale(2, RoundingMode::HalfUp), 'paid' => (string) $paid,
            'payments' => array_map(fn (BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HalfUp), $net),
            'change' => $change, 'receivable' => (string) $balance->toScale(2, RoundingMode::HalfUp)];
    }

    /** @param Collection<int, stdClass> $methods
     * @return array{total: string, paid: string, receivable: string, payments: array<array-key, string>, change: string} */
    public function legacySale(stdClass $sale, Collection $methods): array
    {
        $total = $this->cents($sale->total);
        $this->require($total >= 0, 'La venta V1 tiene un importe negativo sin anulación.');
        $this->require(in_array((string) $sale->operacion, ['1', '2', '3'], true), 'La venta V1 no identifica una operación válida.');
        if ((string) $sale->operacion === '2') {
            // V1's credit payment fields held reference values, not actual collections.
            return ['total' => ReceiptMoney::decimal($total), 'paid' => '0.00', 'receivable' => ReceiptMoney::decimal($total), 'payments' => [], 'change' => '0.00'];
        }
        $this->require($methods->count() === 1, 'La venta V1 '.$sale->numero_control.' no tiene un único registro de pagos.');
        $payment = $methods->sole();
        $allowed = array_map('trim', explode(',', $payment->forma_pago ?? ''));
        $net = [];
        foreach (['efectivo' => '01', 'tarjetad' => '02', 'tarjetac' => '03', 'cheque' => '04', 'remesa' => '05', 'bitcoin' => '11', 'linea' => '99', 'qr' => 'V1_CARD'] as $column => $code) {
            $amount = $this->cents($payment->{$column} ?? '0');
            $this->require($amount >= 0, 'Un pago V1 tiene importe negativo.');
            if ($amount === 0) {
                continue;
            }
            $this->require(in_array($code, $allowed, true) || ($code === 'V1_CARD' && (in_array('02', $allowed, true) || in_array('03', $allowed, true))),
                'Los métodos e importes de pago de '.$sale->numero_control.' no coinciden.');
            $net[$code] = ($net[$code] ?? 0) + $amount;
        }
        $change = max(0, array_sum($net) - $total);
        $this->require(($net['01'] ?? 0) >= $change, 'El exceso de pago no puede conciliarse como cambio en efectivo.');
        if ($change > 0) {
            $net['01'] = ($net['01'] ?? 0) - $change;
        }
        $this->require(array_sum($net) === $total, 'Los pagos de '.$sale->numero_control.' no cubren su total. Requiere conciliación en V1.');

        return ['total' => ReceiptMoney::decimal($total), 'paid' => ReceiptMoney::decimal(array_sum($net)), 'receivable' => '0.00',
            'payments' => array_map(ReceiptMoney::decimal(...), $net), 'change' => ReceiptMoney::decimal($change)];
    }

    /** @return array{total: string, paid: string, receivable: string, payments: array<string, string>, change: string} */
    public function legacyReceipt(stdClass $receipt, ?stdClass $movement = null): array
    {
        $confirmed = $movement->confirmed_amount ?? null;
        $methodOverride = $movement->confirmed_method ?? null;
        $total = $this->cents($receipt->precio ?? $confirmed);
        $this->require($total > 0, 'El recibo V1 #'.$receipt->id.' no tiene un importe positivo conciliado.');
        $this->require($receipt->precio === null || $confirmed === null || $this->cents($receipt->precio) === $this->cents($confirmed), 'El importe del recibo V1 contradice el confirmado.');
        $method = $receipt->tarjeta === null ? $methodOverride : ($receipt->tarjeta ? 'V1_CARD' : '01');
        $this->require(in_array($method, ['01', 'V1_CARD'], true), 'El recibo V1 no identifica si se pagó en efectivo o tarjeta.');
        $this->require($methodOverride === null || $methodOverride === $method, 'El medio de pago del recibo V1 cambió después de su conciliación.');

        return ['total' => ReceiptMoney::decimal($total), 'paid' => ReceiptMoney::decimal($total), 'receivable' => '0.00',
            'payments' => [$method => ReceiptMoney::decimal($total)], 'change' => '0.00'];
    }
}
