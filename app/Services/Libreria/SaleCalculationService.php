<?php

namespace App\Services\Libreria;

use App\Enum\OrigenVenta;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleCalculationService
{
    public function __construct(private SaleProductCatalogService $products, private TuitionService $tuition) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function calculate(array $data): array
    {
        $products = $this->products->query()->whereIn('id_producto', array_column($data['items'], 'productId'))->get()->keyBy('id_producto');
        $lines = [];
        $seen = [];
        $feeCache = [];
        foreach ($data['items'] as $index => $item) {
            $product = $products->get((int) $item['productId']);
            if ($product === null) {
                throw ValidationException::withMessages(['items.'.$index => 'El producto ya no existe.']);
            }
            $academic = $item['tuition'] ?? null;
            $name = (string) $product->getAttribute('descrip');
            $price = (string) $product->getAttribute('p_venta');
            $quantity = (string) $item['quantity'];
            if ($academic !== null) {
                $cacheKey = $academic['studentId'].':'.$academic['responsibleType'];
                $feeCache[$cacheKey] ??= $this->tuition->fees((string) $academic['studentId'], (string) $academic['responsibleType'])['fees'];
                $fee = collect($feeCache[$cacheKey])->first(fn (array $fee): bool => $fee['balanceId'] === (string) $academic['balanceId'] && $fee['fundingType'] === $academic['fundingType']);
                if ($fee === null || ! $fee['canSelect'] || $fee['remaining'] <= 0 || $fee['product']['id'] !== (int) $item['productId'] || ! BigDecimal::of($quantity)->isEqualTo(1)) {
                    throw ValidationException::withMessages(['items.'.$index => 'La cuota cambió o ya no está disponible. Vuelve a seleccionarla.']);
                }
                $academic = [...$academic, 'schoolYear' => $fee['schoolYear'], 'month' => $fee['month']];
                $key = 'fee:'.$academic['balanceId'].':'.$academic['fundingType'].':'.$academic['schoolYear'];
                if (DB::table('libreria.venta_cuotas')->where('saldo_id', $academic['balanceId'])->where('financiamiento', $academic['fundingType'])->where('anio', $academic['schoolYear'])->where('ambiente', config('services.factec.environment', '00'))->where('reserva_activa', true)->exists()) {
                    throw ValidationException::withMessages(['items.'.$index => 'Esta cuota ya está vinculada a un DTE generado.']);
                }
                $price = (string) BigDecimal::of((string) $fee['remaining'])->dividedBy(100, 2);
                $name = $fee['name'].' · Carnet '.$academic['studentId'];
            } else {
                $error = SaleProductCatalogService::configurationError($product);
                if ($error !== null) {
                    throw ValidationException::withMessages(['items.'.$index => $error]);
                }
                $key = 'product:'.$product->getKey();
                if ($product->getAttribute('tipo_prec') === 'V') {
                    $price = (string) ($item['netPrice'] ?? $price);
                }
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['items.'.$index => 'No repitas un mismo producto o cuota; ajusta la cantidad.']);
            }
            $seen[$key] = true;
            if (mb_strlen((string) $product->getAttribute('codigo')) > 25) {
                throw ValidationException::withMessages(['items.'.$index => 'El código del producto supera los 25 caracteres admitidos por el DTE.']);
            }
            if (trim($name) === '' || mb_strlen($name) > 255) {
                throw ValidationException::withMessages(['items.'.$index => 'La descripción debe tener entre 1 y 255 caracteres.']);
            }
            $unit = (int) ($product->getAttribute('unidad') ?: ($product->getAttribute('tipo_prod') === 'S' ? 99 : 59));
            if ($unit < 1 || $unit > 99) {
                throw ValidationException::withMessages(['items.'.$index => 'Revisa la unidad de medida del producto.']);
            }
            $allowed = $academic === null && (bool) $product->getAttribute('descuento');
            $discount = BigDecimal::of((string) ($item['discount'] ?? 0));
            if (! $allowed && ! $discount->isZero()) {
                throw ValidationException::withMessages(['items.'.$index => 'Este producto no permite descuentos.']);
            }
            $base = BigDecimal::of($price)->multipliedBy($quantity);
            if (BigDecimal::of($price)->multipliedBy($product->getAttribute('iva2') === 'A' ? '1.13' : '1')->isGreaterThan('99999999.99999999')) {
                throw ValidationException::withMessages(['items.'.$index => 'El precio unitario supera el máximo permitido.']);
            }
            if ($base->isGreaterThan('99999999.99999999')) {
                throw ValidationException::withMessages(['items.'.$index => 'El importe de este detalle supera el máximo permitido.']);
            }
            $itemDiscount = $base->multipliedBy($discount)->dividedBy(100, 8, RoundingMode::HalfUp);
            $globalDiscount = $allowed ? $base->minus($itemDiscount)->multipliedBy((string) ($data['document']['discount'] ?? 0))->dividedBy(100, 8, RoundingMode::HalfUp) : BigDecimal::of(0);
            $net = $base->minus($itemDiscount)->minus($globalDiscount)->toScale(8, RoundingMode::HalfUp);
            $taxable = $product->getAttribute('iva2') === 'A';
            $factor = $data['document']['type'] === '01' && $taxable ? '1.13' : '1';
            $lines[] = [
                'productId' => (int) $product->getKey(), 'code' => (string) $product->getAttribute('codigo'), 'name' => $name,
                'quantity' => $quantity, 'netPrice' => $price, 'taxable' => $taxable,
                'productType' => (string) $product->getAttribute('tipo_prod'), 'unit' => $unit,
                'incomeAccount' => (string) $product->getAttribute('c_ing'), 'origin' => (string) ($product->getAttribute('grupo') ?: OrigenVenta::LIBRERIA->value),
                'discountPercent' => (string) $discount, 'netDiscount' => (string) $itemDiscount->plus($globalDiscount),
                'itemDiscount' => (string) $itemDiscount->multipliedBy($factor)->toScale(8, RoundingMode::HalfUp),
                'globalDiscount' => (string) $globalDiscount->multipliedBy($factor)->toScale(8, RoundingMode::HalfUp),
                'netAmount' => (string) $net,
                'amount' => (string) $base->minus($itemDiscount)->multipliedBy($factor)->toScale(8, RoundingMode::HalfUp),
                'finalAmount' => (string) $net->multipliedBy($factor)->toScale(8, RoundingMode::HalfUp),
                'grossPrice' => (string) BigDecimal::of($price)->multipliedBy($taxable ? '1.13' : '1')->toScale(8, RoundingMode::HalfUp), 'tuition' => $academic,
            ];
        }
        $exempt = $this->sum($lines, 'amount', false);
        $taxed = $this->sum($lines, 'amount', true);
        $exemptDiscount = $this->sum($lines, 'globalDiscount', false);
        $taxedDiscount = $this->sum($lines, 'globalDiscount', true);
        $subtotal = $exempt->plus($taxed)->minus($exemptDiscount)->minus($taxedDiscount);
        $taxBase = $taxed->minus($taxedDiscount);
        $vat = $data['document']['type'] === '03' ? $taxBase->multipliedBy('0.13')->toScale(2, RoundingMode::HalfUp) : $taxBase->multipliedBy(13)->dividedBy(113, 2, RoundingMode::HalfUp);
        $total = $subtotal->plus($data['document']['type'] === '03' ? $vat : 0)->toScale(2, RoundingMode::HalfUp);
        if ($total->isLessThanOrEqualTo(0) || $total->isGreaterThan('99999999.99')) {
            throw ValidationException::withMessages(['items' => 'El total debe ser mayor que cero y no superar 99999999.99.']);
        }

        return ['lines' => $lines, 'exempt' => (string) $exempt, 'taxed' => (string) $taxed,
            'exemptDiscount' => (string) $exemptDiscount, 'taxedDiscount' => (string) $taxedDiscount,
            'itemDiscount' => (string) $this->sum($lines, 'itemDiscount'), 'subtotal' => (string) $subtotal,
            'vat' => (string) $vat, 'total' => (string) $total];
    }

    /** @param list<array<string, mixed>> $lines */
    private function sum(array $lines, string $field, ?bool $taxable = null): BigDecimal
    {
        $sum = BigDecimal::of(0);
        foreach ($lines as $line) {
            if ($taxable === null || $line['taxable'] === $taxable) {
                $sum = $sum->plus((string) $line[$field]);
            }
        }

        return $sum->toScale(2, RoundingMode::HalfUp);
    }
}
