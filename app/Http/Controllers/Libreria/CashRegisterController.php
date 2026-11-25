<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Models\Libreria\CashReconciliation;
use App\Models\Libreria\CashRegister;
use App\Models\Libreria\CashSession;
use App\Services\Libreria\CashRegisterService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CashRegisterController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $point = (string) $user->getAttribute('puntoventa');
        $registers = CashRegister::query()->with('latestSession')
            ->when(! $user->can('libreria.cajas.ver_todas'), fn ($query) => $query->where('codigo', $point))->orderBy('codigo')->get();
        $records = $user->can('libreria.arqueos.ver')
            ? CashReconciliation::query()->with('session.register')
                ->when(! $user->can('libreria.arqueos.ver_todos'), fn ($query) => $query->whereHas('session.register', fn ($query) => $query->where('codigo', $point)))
                ->orderByDesc('id')->paginate(15)
            : null;

        return view('libreria.cajas.index', ['registers' => $registers, 'records' => $records, 'point' => $point, 'today' => now(config('cash.timezone'))->toDateString()]);
    }

    public function open(Request $request, CashRegisterService $cash): RedirectResponse
    {
        $cash->open($request->user());

        return to_route('libreria.cajas.index')->with('status', 'Caja abierta. Ya puedes registrar ventas.');
    }

    public function reopen(Request $request, CashRegisterService $cash): RedirectResponse
    {
        $input = $request->validate(['codigo' => ['required', 'string', 'regex:/^[0-9]{6}$/'], 'motivo' => ['required', 'string', 'min:5', 'max:1000']]);
        $cash->open($request->user(), $input['codigo'], $input['motivo']);

        return to_route('libreria.cajas.index')->with('status', 'Caja reabierta. Se inició una nueva sesión.');
    }

    public function close(Request $request, int $session, CashRegisterService $cash): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $record = $cash->close($request->user(), $session);

        return to_route('libreria.arqueos.show', $record->id)->with('status', 'Caja cerrada. Ya puedes imprimir los resúmenes de ventas y recibos, y descargar el Excel del arqueo.');
    }

    public function code(Request $request, int $session, CashRegisterService $cash): Response
    {
        Gate::authorize('libreria.cajas.ver_codigos');
        $session = CashSession::query()->with('register')->findOrFail($session);
        $cash->assertVisible($request->user(), $session->register->codigo);
        abort_unless($session->cerrado_at !== null && $session->codigo_usado_at === null
            && $session->fecha_operativa->toDateString() === now(config('cash.timezone'))->toDateString()
            && (int) $session->register->sessions()->orderByDesc('id')->value('id') === (int) $session->id, 422, 'Este cierre ya no tiene un código vigente.');

        return response()->view('libreria.cajas.codigo', ['cashSession' => $session])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
