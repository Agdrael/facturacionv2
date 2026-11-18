<?php

namespace App\Http\Controllers\Contaduria;

use App\Enum\OrigenVenta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contaduria\StorePackageRequest;
use App\Http\Resources\Contaduria\PackageProductResource;
use App\Models\User;
use App\Services\Contaduria\PackageService;
use App\Services\Libreria\SaleProductCatalogService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    public function index(Request $request, SaleProductCatalogService $catalog): View
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $search = trim($input['q'] ?? '');
        $query = DB::table('public.paquetes_productos')->select(['id_paquete', 'codigo_paquete', 'nombre_paquete', 'p_venta', 'fecha_creacion'])
            ->addSelect(['components_count' => DB::table('public.paquetes_desgloce')->selectRaw('COUNT(DISTINCT producto_id)')->whereColumn('id_paquete', 'paquetes_productos.id_paquete')]);
        $catalog->search($query, $search, ['codigo_paquete', 'nombre_paquete']);

        return view('contaduria.paquetes', ['records' => $query->orderByDesc('id_paquete')->paginate(20)->withQueryString(), 'search' => $search]);
    }

    public function create(Request $request, SaleProductCatalogService $catalog): View
    {
        $old = collect((array) $request->old('items', []))->take(200)->filter(fn ($item): bool => is_array($item));
        $ids = $old->map(fn (array $item): int => (int) ($item['productId'] ?? 0))->all();
        $products = $catalog->query()->whereIn('id_producto', $ids)->get()->keyBy('id_producto');
        $items = $old->map(function (array $item) use ($products, $request): array {
            $id = (int) ($item['productId'] ?? 0);
            $product = $products->get($id);
            $data = $product === null ? ['productId' => $id, 'code' => '', 'name' => 'Producto no disponible', 'netPrice' => '0', 'canSelect' => false] : PackageProductResource::make($product)->resolve($request);

            return [...$data, 'quantity' => is_scalar($item['quantity'] ?? null) ? (string) $item['quantity'] : ''];
        })->values()->all();

        return view('contaduria.paquete-formulario', ['options' => ['productSearchUrl' => route('contaduria.paquetes.productos'), 'items' => $items]]);
    }

    public function products(Request $request, SaleProductCatalogService $catalog): AnonymousResourceCollection
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $catalog->query()->where(fn (Builder $query) => $query->whereNull('grupo')->orWhere('grupo', '!=', OrigenVenta::COLEGIATURA->value));
        $catalog->search($query->getQuery(), trim($input['q'] ?? ''), ['codigo', 'descrip']);

        return PackageProductResource::collection($query->orderBy('descrip')->orderBy('id_producto')->paginate(20));
    }

    public function store(StorePackageRequest $request, PackageService $packages): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $packages->create($request->packageData(), $user);

        return to_route('contaduria.paquetes.index')->with('status', 'Paquete creado correctamente. Ya está disponible para seleccionarlo en ventas.');
    }
}
