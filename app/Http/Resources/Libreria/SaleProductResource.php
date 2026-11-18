<?php

namespace App\Http\Resources\Libreria;

use App\Models\Contaduria\Producto;
use App\Services\Libreria\SaleProductCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Producto $product */
        $product = $this->resource;
        $netPrice = $product->getAttribute('p_venta');
        $taxType = trim((string) $product->getAttribute('iva2'));
        $priceType = trim((string) $product->getAttribute('tipo_prec'));
        $productType = trim((string) $product->getAttribute('tipo_prod'));
        /** @var array{warehouseId: int, consigned: string, own: string, total: string}|null $stock */
        $stock = $product->getAttribute('sale_stock');
        $error = SaleProductCatalogService::configurationError($product);

        return [
            'id' => 'product-'.$product->getKey(),
            'productId' => (int) $product->getKey(),
            'code' => (string) $product->getAttribute('codigo'),
            'name' => (string) $product->getAttribute('descrip'),
            'category' => 'productos',
            'productType' => $productType,
            'netPrice' => $netPrice === null ? null : (string) $netPrice,
            'price' => is_numeric($netPrice) ? (float) $netPrice * ($taxType === 'A' ? 113 : 100) : 0,
            'taxable' => $taxType === 'A',
            'taxType' => $taxType,
            'priceType' => $priceType,
            'unit' => (string) $product->getAttribute('unidad'),
            'origin' => (string) $product->getAttribute('grupo'),
            'incomeAccount' => (string) $product->getAttribute('c_ing'),
            'inventoryAccount' => (string) $product->getAttribute('c_inv'),
            'expenseAccount' => (string) $product->getAttribute('c_gas'),
            'discountAllowed' => (bool) $product->getAttribute('descuento'),
            'stock' => $stock,
            'canSelect' => $error === null && ($productType !== 'B' || (float) ($stock['total'] ?? 0) >= 0.01),
            'configurationError' => $error,
        ];
    }
}
