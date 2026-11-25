<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Services\Libreria\LegacyCashBridgeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LegacyCashBridgeController extends Controller
{
    public function show(Request $request, string $register, LegacyCashBridgeService $bridge): View
    {
        Gate::authorize('libreria.arqueos.revisar');
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return view('libreria.cajas.legacy', $bridge->preparation($register, (int) ($input['page'] ?? 1)));
    }

    public function confirm(Request $request, string $register, LegacyCashBridgeService $bridge): RedirectResponse
    {
        Gate::authorize('libreria.arqueos.revisar');
        $input = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'assignments' => ['required', 'array', 'min:1', 'max:50'],
            'assignments.*' => ['required', 'array:session,amount,method,reason'],
            'assignments.*.session' => ['required', 'integer', 'min:0'],
            'assignments.*.amount' => ['nullable', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'assignments.*.method' => ['nullable', 'string', 'in:01,V1_CARD'],
            'assignments.*.reason' => ['nullable', 'string', 'max:1000'],
            'confirmed' => ['accepted'],
        ]);
        $bridge->confirm($request->user(), $register, $input['version'], $input['assignments']);

        return to_route('libreria.cajas.legacy.show', $register)->with('status', 'Selección registrada. Los movimientos confirmados conservarán su turno e importe.');
    }
}
