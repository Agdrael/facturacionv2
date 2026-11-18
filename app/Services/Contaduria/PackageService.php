<?php

namespace App\Services\Contaduria;

use App\Models\User;
use App\Services\Libreria\SaleProductCatalogService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PackageService
{
    public function __construct(private SaleProductCatalogService $products) {}

    /** @param array{code: string, name: string, items: list<array{productId: int, quantity: int|float|string}>} $data */
    public function create(array $data, User $creator): int
    {
        return DB::transaction(function () use ($data, $creator): int {
            $code = trim($data['code']);
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('contaduria.paquetes'), hashtext(?))", [Str::lower($code)]);
            }
            if (DB::table('public.paquetes_productos')->whereRaw('LOWER(TRIM(codigo_paquete)) = ?', [Str::lower($code)])->exists()) {
                throw ValidationException::withMessages(['code' => 'Ya existe un paquete con ese código.']);
            }
            $ids = array_column($data['items'], 'productId');
            $products = $this->products->query()->whereIn('id_producto', $ids)->orderBy('id_producto')->lockForUpdate()->get()->keyBy('id_producto');
            $total = BigDecimal::of('0');
            foreach ($data['items'] as $index => $item) {
                $product = $products->get($item['productId']);
                if ($product === null) {
                    throw ValidationException::withMessages(['items.'.$index.'.productId' => 'El producto seleccionado ya no está disponible.']);
                }
                $error = SaleProductCatalogService::configurationError($product);
                if ($error !== null) {
                    throw ValidationException::withMessages(['items.'.$index.'.productId' => $error]);
                }
                $total = $total->plus(BigDecimal::of((string) $product->getAttribute('p_venta'))->multipliedBy((string) $item['quantity']));
            }
            if ($total->isGreaterThan('99999999999999999999.999999')) {
                throw ValidationException::withMessages(['items' => 'El precio total del paquete supera el máximo permitido.']);
            }
            $id = DB::table('public.paquetes_productos')->insertGetId([
                'codigo_paquete' => $code, 'nombre_paquete' => trim($data['name']),
                'p_venta' => (string) $total->toScale(6, RoundingMode::HalfUp),
                'fecha_creacion' => now()->toDateString(), 'id_usuario' => $creator->id,
            ], 'id_paquete');
            DB::table('public.paquetes_desgloce')->insert(array_map(fn (array $item): array => [
                'id_paquete' => $id, 'producto_id' => $item['productId'], 'cantidad' => $item['quantity'],
            ], $data['items']));

            return $id;
        }, 3);
    }
}
