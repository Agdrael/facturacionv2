<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Http\Resources\Libreria\SaleClientResource;
use App\Models\Libreria\Cliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleClientController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($validated['q'] ?? '');

        $clientes = Cliente::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereLike('nombre', '%'.$search.'%')
                        ->orWhereLike('dui', '%'.$search.'%')
                        ->orWhereLike('nit', '%'.$search.'%')
                        ->orWhereLike('email', '%'.$search.'%');

                    $document = preg_replace('/[\s.-]+/u', '', $search) ?? $search;

                    if (ctype_digit($document)) {
                        $query->orWhereRaw("REPLACE(dui, '-', '') LIKE ?", ['%'.$document.'%'])
                            ->orWhereRaw("REPLACE(nit, '-', '') LIKE ?", ['%'.$document.'%']);
                    }
                });
            })
            ->orderBy('nombre')->orderBy('id')->limit(20)
            ->get(['id', 'nombre', 'dui', 'nit', 'registro_fiscal', 'direccion', 'departamento', 'distrito', 'municipio', 'email', 'telefono_1', 'telefono_2', 'actividad_economica']);

        return SaleClientResource::collection($clientes);
    }
}
