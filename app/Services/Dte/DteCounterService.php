<?php

namespace App\Services\Dte;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DteCounterService
{
    public static function sequenceName(string $type): string
    {
        $environment = config('services.factec.environment');
        if (! in_array($environment, ['00', '01'], true) || ! in_array($type, ['01', '03'], true)) {
            throw ValidationException::withMessages(['next' => 'Revisa el ambiente y el tipo de documento del contador.']);
        }

        return $environment === '00'
            ? 'libreria.contador_dte_pruebas_'.$type.'_seq'
            : ($type === '01' ? 'public.contador_dte_seq' : 'public.contador_dte_seq_03');
    }

    /** @return list<array{type: string, label: string, next: int}> */
    public function all(): array
    {
        return [
            ['type' => '01', 'label' => 'Factura', 'next' => $this->current('01')],
            ['type' => '03', 'label' => 'Comprobante de crédito fiscal', 'next' => $this->current('03')],
        ];
    }

    public function current(string $type): int
    {
        $sequence = $this->sequence($type);
        $this->ensureSequence($sequence);
        $state = DB::selectOne('SELECT last_value, is_called FROM '.$sequence);

        return (int) $state->last_value + ($state->is_called ? 1 : 0);
    }

    public function update(string $type, int $next, int $expected): void
    {
        if ($next < 1 || $next > 999999999999999) {
            throw ValidationException::withMessages(['next' => 'El siguiente correlativo debe estar entre 1 y 999999999999999.']);
        }
        $sequence = $this->sequence($type);
        $this->ensureSequence($sequence);

        DB::transaction(function () use ($type, $sequence, $next, $expected): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['dte.counter.'.$sequence]);
            DB::statement('ALTER SEQUENCE '.$sequence.' CACHE 1');
            $current = $this->current($type);
            if ($current !== $expected) {
                throw ValidationException::withMessages(['next' => 'El contador cambió mientras editabas. Recarga la página y revisa el siguiente correlativo.']);
            }
            if ($next < $current) {
                throw ValidationException::withMessages(['next' => 'No puedes retroceder el contador. El siguiente correlativo disponible es '.$current.'.']);
            }
            DB::statement('ALTER SEQUENCE '.$sequence.' RESTART WITH '.$next);
        });
    }

    protected function sequence(string $type): string
    {
        return self::sequenceName($type);
    }

    private function ensureSequence(string $sequence): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw ValidationException::withMessages(['next' => 'Los contadores DTE requieren la conexión PostgreSQL activa.']);
        }
        $settings = DB::selectOne('SELECT seqincrement, seqcache, seqcycle FROM pg_catalog.pg_sequence WHERE seqrelid = to_regclass(?)', [$sequence]);
        if ($settings === null) {
            throw ValidationException::withMessages(['next' => 'No se encontró el contador DTE. Revisa las migraciones de la conexión activa.']);
        }
        if ((int) $settings->seqincrement !== 1 || (int) $settings->seqcache !== 1 || $settings->seqcycle) {
            throw ValidationException::withMessages(['next' => 'El contador debe incrementar de uno en uno, sin caché adicional y sin ciclos. Revisa su configuración.']);
        }
    }
}
