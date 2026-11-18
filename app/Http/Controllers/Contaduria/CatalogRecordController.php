<?php

namespace App\Http\Controllers\Contaduria;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contaduria\CatalogRecordRequest;
use App\Services\Contaduria\CatalogRegistry;
use App\Services\Contaduria\CatalogService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CatalogRecordController extends Controller
{
    public function __construct(private CatalogRegistry $catalogs, private CatalogService $service) {}

    public function index(Request $request, string $catalog): View
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $definition = $this->catalogs->get($catalog);
        $search = trim($input['q'] ?? '');
        $records = $this->service->records($definition, $search, (int) ($input['page'] ?? 1));

        return view('contaduria.registros', ['catalog' => $definition, 'records' => $records, 'search' => $search]);
    }

    public function create(string $catalog): View
    {
        $definition = $this->catalogs->get($catalog);

        return view('contaduria.formulario', ['catalog' => $definition, 'record' => null, 'options' => $this->service->options($definition)]);
    }

    public function store(CatalogRecordRequest $request, string $catalog): RedirectResponse
    {
        try {
            $this->service->create($this->catalogs->get($catalog), $request->validated());
        } catch (QueryException $exception) {
            $this->databaseError($exception);
        }

        return to_route('contaduria.catalogos.registros.index', $catalog)->with('status', 'Registro creado correctamente.');
    }

    public function edit(string $catalog, string $record): View
    {
        $definition = $this->catalogs->get($catalog);

        return view('contaduria.formulario', [
            'catalog' => $definition, 'record' => $this->service->find($definition, $record), 'options' => $this->service->options($definition),
        ]);
    }

    public function update(CatalogRecordRequest $request, string $catalog, string $record): RedirectResponse
    {
        try {
            $this->service->update($this->catalogs->get($catalog), $record, $request->validated());
        } catch (QueryException $exception) {
            $this->databaseError($exception);
        }

        return to_route('contaduria.catalogos.registros.index', $catalog)->with('status', 'Registro actualizado correctamente.');
    }

    public function destroy(string $catalog, string $record): RedirectResponse
    {
        $definition = $this->catalogs->get($catalog);
        $this->service->find($definition, $record);
        try {
            $this->service->delete($definition, $record);
        } catch (QueryException $exception) {
            $this->databaseError($exception);
        }

        return to_route('contaduria.catalogos.registros.index', $catalog)->with('status', 'Registro eliminado correctamente.');
    }

    private function databaseError(QueryException $exception): never
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['catalog' => 'Ya existe un registro con uno de los valores introducidos.']);
        }
        if ($exception->getCode() === '23503' || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')) {
            throw ValidationException::withMessages(['catalog' => 'El registro tiene movimientos o referencias relacionadas y no se puede eliminar o modificar.']);
        }

        throw $exception;
    }
}
