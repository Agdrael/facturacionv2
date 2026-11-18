<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Http\Resources\Libreria\SalePackageResource;
use App\Http\Resources\Libreria\SaleProductResource;
use App\Models\Contaduria\Producto;
use App\Services\Libreria\SaleProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalePackageController extends Controller
{
    public function index(Request $request, SaleProductCatalogService $catalog): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = DB::table('public.paquetes_productos')
            ->select(['id_paquete', 'codigo_paquete', 'nombre_paquete'])
            ->addSelect(['components_count' => DB::table('public.paquetes_desgloce')->selectRaw('COUNT(DISTINCT producto_id)')
                ->whereColumn('id_paquete', 'paquetes_productos.id_paquete')]);
        $catalog->search($query, trim($validated['q'] ?? ''), ['codigo_paquete', 'nombre_paquete']);

        return SalePackageResource::collection($query->orderBy('nombre_paquete')->orderBy('id_paquete')
            ->paginate(20, ['*'], 'page', (int) ($validated['page'] ?? 1)));
    }

    public function show(Request $request, int $package, SaleProductCatalogService $catalog): JsonResponse
    {
        $header = DB::table('public.paquetes_productos')->where('id_paquete', $package)->first();
        abort_if($header === null, 404);
        $details = DB::table('public.paquetes_desgloce')->where('id_paquete', $package)->orderBy('id')
            ->get(['producto_id', 'cantidad']);
        if ($details->isEmpty()) {
            throw ValidationException::withMessages(['package' => 'El paquete no tiene productos configurados.']);
        }
        $quantities = [];
        foreach ($details as $detail) {
            $quantity = (float) $detail->cantidad;
            $id = (int) $detail->producto_id;
            if ($id <= 0 || ! is_finite($quantity) || $quantity <= 0 || $quantity > 999999.99 || abs($quantity * 100 - round($quantity * 100)) > 0.000001) {
                throw ValidationException::withMessages(['package' => 'El paquete tiene una cantidad o un producto inválido. Revisa su configuración.']);
            }
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) round($quantity * 100);
            if ($quantities[$id] > 99999999) {
                throw ValidationException::withMessages(['package' => 'La cantidad acumulada de un producto del paquete excede el límite permitido.']);
            }
        }
        $products = $catalog->query()->whereIn('id_producto', array_keys($quantities))->orderBy('id_producto')->get();
        if ($products->count() !== count($quantities)) {
            throw ValidationException::withMessages(['package' => 'El paquete contiene productos que ya no existen. Revisa su configuración.']);
        }
        $catalog->attachStock($products);

        return response()->json([
            'id' => $package, 'code' => $header->codigo_paquete, 'name' => $header->nombre_paquete,
            'products' => $products->map(fn (Producto $product): array => [
                ...SaleProductResource::make($product)->resolve($request),
                'quantity' => $quantities[(int) $product->getKey()] / 100,
            ])->values()->all(),
        ]);
    }
}
