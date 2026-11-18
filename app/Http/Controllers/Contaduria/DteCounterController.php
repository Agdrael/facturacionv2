<?php

namespace App\Http\Controllers\Contaduria;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contaduria\UpdateDteCounterRequest;
use App\Services\Dte\DteCounterService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DteCounterController extends Controller
{
    public function index(DteCounterService $counters): View
    {
        return view('contaduria.contadores', ['counters' => $counters->all(), 'environment' => config('services.factec.environment')]);
    }

    public function update(UpdateDteCounterRequest $request, string $type, DteCounterService $counters): RedirectResponse
    {
        $data = $request->validated();
        try {
            $counters->update($type, (int) $data['next'], (int) $data['expected']);
        } catch (QueryException $error) {
            report($error);
            throw ValidationException::withMessages(['next' => 'No se pudo modificar el contador. Revisa la conexión y los permisos de la secuencia PostgreSQL.']);
        }
        Log::info('Contador DTE actualizado.', ['user_id' => $request->user()?->getAuthIdentifier(), 'environment' => $data['environment'], 'type' => $type, 'previous_next' => $data['expected'], 'next' => $data['next']]);

        return to_route('contaduria.contadores.index')->with('status', 'Contador actualizado. El siguiente correlativo será '.str_pad((string) $data['next'], 15, '0', STR_PAD_LEFT).'.');
    }
}
