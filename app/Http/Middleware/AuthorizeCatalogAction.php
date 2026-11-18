<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeCatalogAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = match ($request->route()->getActionMethod()) {
            'index' => 'ver', 'create', 'store' => 'crear', 'edit', 'update' => 'editar', 'destroy' => 'eliminar',
            default => abort(403),
        };
        Gate::authorize('contaduria.'.$request->route('catalog').'.'.$action);

        return $next($request);
    }
}
