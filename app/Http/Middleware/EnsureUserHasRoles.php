<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRoles
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->roles()->exists()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Acceso pendiente de asignación.'], 403);
            }

            return redirect()->route('access.pending');
        }

        return $next($request);
    }
}
