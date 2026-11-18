<?php

namespace App\Http\Controllers\Libreria;

use App\Enum\OrigenVenta;
use App\Http\Controllers\Controller;
use App\Http\Resources\Libreria\SaleProductResource;
use App\Services\Libreria\SaleProductCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class SaleProductController extends Controller
{
    public function __invoke(Request $request, SaleProductCatalogService $catalog): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($validated['q'] ?? '');
        $code = trim($validated['code'] ?? '');
        $query = $catalog->query()
            ->where(fn (Builder $query) => $query->whereNull('grupo')->orWhere('grupo', '!=', OrigenVenta::COLEGIATURA->value))
            ->when($code !== '', fn (Builder $query) => $query->whereRaw('LOWER(codigo) = ?', [Str::lower($code)]));
        $catalog->search($query->getQuery(), $search, ['codigo', 'descrip']);
        $products = $query->orderBy('descrip')->orderBy('id_producto')
            ->paginate(20, ['*'], 'page', (int) ($validated['page'] ?? 1));
        $catalog->attachStock($products->getCollection());

        return SaleProductResource::collection($products);
    }
}
