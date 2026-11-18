<?php

namespace App\Http\Middleware;

use App\Services\Empleado\EmployeeAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsEmpleado
{
    public function __construct(private EmployeeAccessService $employeeAccessService) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $empleado = $this->employeeAccessService->employeeFor($request->user());

        if ($empleado) {
            $request->session()->put('codigo_empleado', $empleado->codigo);

            return $next($request);
        }

        return redirect()->route('employee-access.rejected');
    }
}
