<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePuntoVentaById
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $puntoVenta = $this->resolve($request);

        $user->setAttribute('puntoventa', $puntoVenta);

        return $next($request);
    }

    public function resolve(Request $request): string
    {
        return config('puntoventa')[$request->ip()] ?? 'P004';
    }
}
