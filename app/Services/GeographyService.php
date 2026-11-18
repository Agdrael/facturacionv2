<?php

namespace App\Services;

use App\Models\Dte\Cat012;
use App\Models\Dte\Distrito;
use App\Models\Dte\Municipio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class GeographyService
{
    /**
     * The legacy CAT-013 retains department-scoped municipality codes, now used
     * as fiscal district codes. The IDs in distritos are internal references.
     *
     * @return array{departamento: string, municipio: string, distrito: string, complemento: string}
     */
    public function fiscalAddress(string $department, string $municipality, string $districtId, string $address, string $errorKey = 'client.district'): array
    {
        $district = Distrito::query()->whereKey($districtId)->where('departamento_codigo', $department)->where('municipio_codigo', (int) $municipality)->first();

        if ($district === null || ! Municipio::query()->where('departamento_codigo', $department)->where('codigo', $municipality)->exists()) {
            throw ValidationException::withMessages([$errorKey => 'Selecciona un distrito que corresponda al departamento y municipio de la dirección.']);
        }

        $normalize = fn (string $name): string => Str::upper(Str::ascii(preg_replace('/\s+/u', ' ', trim($name)) ?? ''));

        $name = $normalize((string) $district->getAttribute('nombre'));
        $codes = DB::connection('dtesantacecilia')->table('public.CAT-013')->where('departamento', $department)->get(['codigo', 'valores'])
            ->filter(fn (object $row): bool => $normalize((string) $row->valores) === $name)
            ->pluck('codigo')->map(fn ($code): string => (string) $code)->unique()->values();

        $code = $codes->count() === 1 ? $codes->first() : null;

        if (! is_string($code) || preg_match('/^[0-9]{2}$/D', $code) !== 1) {
            throw ValidationException::withMessages([$errorKey => 'No hay un código fiscal único para el distrito '.$district->getAttribute('nombre').' del departamento '.$department.'. Revisa la correspondencia del catálogo; no se enviará el ID interno a Hacienda.']);
        }

        return ['departamento' => $department, 'municipio' => $municipality, 'distrito' => $code, 'complemento' => $address];
    }

    public function isValidSelection(string $department, string $district, string $municipality): bool
    {
        return Municipio::query()->where('departamento_codigo', $department)->where('codigo', $municipality)->exists()
            && Distrito::query()->whereKey($district)->where('departamento_codigo', $department)
                ->where('municipio_codigo', (int) $municipality)->exists();
    }

    /**
     * @return array{departments: list<array{codigo: string, valor: string}>, districts: list<array{codigo: string, valor: string, department: string, municipalityCode: string, municipalityName: string}>}
     */
    public function options(): array
    {
        $municipios = Municipio::query()
            ->get(['codigo', 'nombre', 'departamento_codigo'])
            ->keyBy(fn (Municipio $municipio): string => $municipio->getAttribute('departamento_codigo').':'.(int) $municipio->getAttribute('codigo'));

        $distritos = Distrito::query()->orderBy('nombre')->orderBy('id')->get()
            ->map(function (Distrito $distrito) use ($municipios): array {
                $departamento = (string) $distrito->getAttribute('departamento_codigo');
                $municipio = $municipios->get($departamento.':'.(int) $distrito->getAttribute('municipio_codigo'));

                if ($municipio === null) {
                    throw new UnexpectedValueException('El distrito '.$distrito->getKey().' no tiene un municipio correspondiente en su departamento.');
                }

                return [
                    'codigo' => (string) $distrito->getKey(),
                    'valor' => (string) $distrito->getAttribute('nombre'),
                    'department' => $departamento,
                    'municipalityCode' => (string) $municipio->getAttribute('codigo'),
                    'municipalityName' => (string) $municipio->getAttribute('nombre'),
                ];
            })->values()->all();

        return ['departments' => array_values(Cat012::catalogOptions()->all()), 'districts' => array_values($distritos)];
    }
}
