<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Services\Libreria\SaleInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleStockController extends Controller
{
    public function __invoke(Request $request, SaleInventoryService $inventory): JsonResponse
    {
        $validated = $request->validate([
            'documentType' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.productId' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.99', 'decimal:0,2'],
        ]);

        $inventoryDocumentType = $inventory->documentTypeForInvoice($validated['documentType']);

        return $inventory->withLockedStock($validated['items'], fn (array $allocation): JsonResponse => response()->json([
            'invoiceDocumentType' => $validated['documentType'],
            'inventoryDocumentType' => $inventoryDocumentType,
            'allocation' => $allocation,
        ]));
    }
}
