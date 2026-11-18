<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Http\Resources\Libreria\SaleClientResource;
use App\Models\Libreria\CashSession;
use App\Models\Libreria\Cliente;
use App\Services\Libreria\CashRegisterService;
use App\Services\Libreria\SaleCatalogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __invoke(Request $request, SaleCatalogService $catalogs): View
    {
        if (! $request->user()->can('libreria.ventas.crear')) {
            return view('libreria.historial', ['saleCatalogs' => ['historyUrl' => route('libreria.ventas.history')]]);
        }

        $point = (string) $request->user()->getAttribute('puntoventa');
        if (app(CashRegisterService::class)->controlled($point)
            && ! CashSession::query()->whereHas('register', fn ($query) => $query->where('codigo', $point))->whereNull('cerrado_at')->exists()) {
            return view('libreria.historial', ['saleCatalogs' => ['historyUrl' => route('libreria.ventas.history')], 'cashClosed' => true]);
        }

        $defaultClient = Cliente::query()->whereRaw('UPPER(TRIM(nombre)) = ?', ['CLIENTES VARIOS'])->orderBy('id')->first();

        return view('libreria.venta', ['saleCatalogs' => [
            ...$catalogs->options(),
            'defaultClient' => $defaultClient === null ? null : SaleClientResource::make($defaultClient)->resolve(),
            'clientSearchUrl' => route('libreria.clientes.index'),
            'productSearchUrl' => route('libreria.productos.index'),
            'packageSearchUrl' => route('libreria.paquetes.index'),
            'stockCheckUrl' => route('libreria.venta.disponibilidad'),
            'quoteUrl' => route('libreria.ventas.quote'),
            'generateUrl' => route('libreria.ventas.store'),
            'historyUrl' => route('libreria.ventas.history'),
            'dteEnvironment' => config('services.factec.environment', '00'),
            'studentSearchUrl' => route('libreria.alumnos.index'),
        ]]);
    }
}
