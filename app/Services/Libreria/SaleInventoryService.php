<?php

namespace App\Services\Libreria;

use App\Models\Contaduria\Producto;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type Stock array{warehouseId: int, consigned: string, own: string, total: string}
 * @phpstan-type Allocation array{productId: int, warehouseId: int, property: string, quantity: string, movementType: string}
 */
class SaleInventoryService
{
    /**
     * The existing inv triggers maintain stock and kardex from these source documents.
     *
     * @param  list<Allocation>  $allocations
     * @param  array<int, string>  $netPrices
     */
    public function recordDocument(array $allocations, array $netPrices, string $invoiceType, string $uuid, string $date, string $operator): ?int
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new \LogicException('La salida debe registrarse dentro de la transacción de venta.');
        }
        if ($allocations === []) {
            return null;
        }
        $docType = $this->documentTypeForInvoice($invoiceType);
        if (! DB::table('inv.inv_cat_doc_tipo')->where('doc_tipo', $docType)->exists()) {
            throw ValidationException::withMessages(['items' => 'Falta configurar '.$docType.' en los documentos de inventario.']);
        }
        $movement = DB::table('inv.inv_cat_tipo_mov')->where('tipo_mov', 'VENTA')->first();
        if ($movement === null || ! $movement->afecta_existencia || $movement->afecta_cpp_global) {
            throw ValidationException::withMessages(['items' => 'Revisa el movimiento VENTA: debe afectar existencias y conservar el costo promedio.']);
        }
        $id = DB::table('inv.inv_documentos')->insertGetId([
            'doc_tipo' => $docType, 'doc_numero' => $uuid, 'fecha_doc' => $date,
            'notas' => 'Venta Librería · usuario Laravel '.$operator,
            'bodega_id' => $allocations[0]['warehouseId'], 'tipo_mov' => 'VENTA',
        ], 'doc_id');
        foreach ($allocations as $allocation) {
            DB::table('inv.inv_documentos_det')->insert([
                'doc_id' => $id, 'producto_id' => $allocation['productId'],
                'bodega_id' => $allocation['warehouseId'], 'propiedad' => $allocation['property'],
                'tipo_mov' => 'VENTA', 'cantidad' => $allocation['quantity'],
                'precio_unit' => $netPrices[$allocation['productId']],
                'notas' => 'Venta Librería · usuario Laravel '.$operator,
            ]);
        }

        return $id;
    }

    public function documentTypeForInvoice(string $invoiceType): string
    {
        return match ($invoiceType) {
            '01' => 'DTE-01',
            '03' => 'DTE-03',
            default => throw ValidationException::withMessages(['documentType' => 'Selecciona Factura (01) o Comprobante de crédito fiscal (03).']),
        };
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, Stock>
     */
    public function availability(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return $this->readStock($productIds, $this->mainWarehouseId(), false);
    }

    /**
     * The operation must persist the sale and its inventory documents before this transaction ends.
     *
     * @template T
     *
     * @param  list<array{productId: int, quantity: int|float|string}>  $items
     * @param  Closure(list<Allocation>): T  $operation
     * @return T
     */
    public function withLockedStock(array $items, Closure $operation): mixed
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Agrega al menos un producto o servicio.']);
        }
        $quantities = [];
        foreach ($items as $item) {
            $quantity = $this->units($item['quantity']);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
            }
            $quantities[$item['productId']] = ($quantities[$item['productId']] ?? 0) + $quantity;
        }

        return DB::transaction(function () use ($quantities, $operation) {
            $products = Producto::query()->whereIn('id_producto', array_keys($quantities))
                ->orderBy('id_producto')->lockForUpdate()->get(['id_producto', 'codigo', 'tipo_prod']);
            if ($products->count() !== count($quantities)) {
                throw ValidationException::withMessages(['items' => 'Uno de los productos seleccionados ya no existe.']);
            }
            if ($products->contains(fn (Producto $product): bool => ! in_array($product->getAttribute('tipo_prod'), ['B', 'S'], true))) {
                throw ValidationException::withMessages(['items' => 'Revisa el tipo de los productos seleccionados.']);
            }

            $goods = $products->filter(fn (Producto $product): bool => $product->getAttribute('tipo_prod') === 'B');
            $allocations = [];
            if ($goods->isNotEmpty()) {
                $warehouseId = $this->mainWarehouseId();
                $stock = $this->readStock(array_values($goods->map(fn (Producto $product): int => (int) $product->getKey())->all()), $warehouseId, true);
                foreach ($goods as $product) {
                    $id = (int) $product->getKey();
                    $requested = $quantities[$id];
                    $available = $stock[$id];
                    if ($requested > $this->units($available['total'])) {
                        throw ValidationException::withMessages(['items' => 'Stock insuficiente para '.$product->getAttribute('codigo').'. Disponible en bodega principal: '.$available['total'].'.']);
                    }
                    $consigned = min($requested, $this->units($available['consigned']));
                    foreach (['CONSIGNADO' => $consigned, 'PROPIO' => $requested - $consigned] as $property => $quantity) {
                        if ($quantity > 0) {
                            $allocations[] = [
                                'productId' => $id, 'warehouseId' => $warehouseId, 'property' => $property,
                                'quantity' => $this->decimal($quantity),
                                'movementType' => $property === 'CONSIGNADO' ? 'VENTA_CONSIGNADO_OUT' : 'VENTA',
                            ];
                        }
                    }
                }
            }

            return $operation($allocations);
        }, 3);
    }

    private function mainWarehouseId(): int
    {
        $id = DB::table('inv.inv_bodegas')->where('codigo', 'PRINCIPAL')->where('activo', true)->value('bodega_id');
        if ($id === null) {
            throw ValidationException::withMessages(['items' => 'La bodega principal no está disponible.']);
        }

        return (int) $id;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, Stock>
     */
    private function readStock(array $productIds, int $warehouseId, bool $lock): array
    {
        $query = DB::table('inv.inv_existencias')->where('bodega_id', $warehouseId)
            ->whereIn('producto_id', $productIds)->whereIn('propiedad', ['CONSIGNADO', 'PROPIO'])
            ->orderBy('producto_id')->orderBy('propiedad');
        if ($lock) {
            $query->lockForUpdate();
        }
        $balances = [];
        foreach ($query->get(['producto_id', 'propiedad', 'stock_actual']) as $row) {
            $balances[(int) $row->producto_id][(string) $row->propiedad] = $this->units((string) $row->stock_actual);
        }
        $result = [];
        foreach ($productIds as $id) {
            $consigned = $balances[$id]['CONSIGNADO'] ?? 0;
            $own = $balances[$id]['PROPIO'] ?? 0;
            $result[$id] = ['warehouseId' => $warehouseId, 'consigned' => $this->decimal($consigned),
                'own' => $this->decimal($own), 'total' => $this->decimal($consigned + $own)];
        }

        return $result;
    }

    private function units(int|float|string $value): int
    {
        if (! preg_match('/^(\d{1,14})(?:\.(\d{1,4}))?$/', (string) $value, $parts)) {
            throw ValidationException::withMessages(['items' => 'La cantidad debe ser positiva y tener como máximo cuatro decimales.']);
        }

        return (int) $parts[1] * 10000 + (int) str_pad($parts[2] ?? '', 4, '0');
    }

    private function decimal(int $units): string
    {
        return intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }
}
