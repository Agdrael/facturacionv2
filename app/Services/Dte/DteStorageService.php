<?php

namespace App\Services\Dte;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class DteStorageService
{
    public function save(int $journalId): void
    {
        DB::transaction(function () use ($journalId): void {
            $journal = DB::table('libreria.venta_dtes')->where('id', $journalId)->lockForUpdate()->first();
            if ($journal === null) {
                throw new RuntimeException('DTE no encontrado.');
            }
            if ($journal->guardado_externo_at !== null) {
                return;
            }
            $remote = DB::connection('dtesantacecilia');
            $remote->transaction(function () use ($remote, $journal): void {
                if ($remote->getDriverName() === 'pgsql') {
                    $remote->select("SELECT pg_advisory_xact_lock(hashtext('libreria.dte_json'), hashtext(?))", [$journal->codigo_generacion]);
                }
                $existing = $remote->table('public.dte_json')->where('dte_id', $journal->codigo_generacion)->get(['resultado']);
                $document = json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR);
                foreach ($existing as $row) {
                    if (json_decode($row->resultado, true, 512, JSON_THROW_ON_ERROR) != $document) {
                        throw new RuntimeException('Existe otro contenido para el mismo código de generación.');
                    }
                }
                if ($existing->isEmpty()) {
                    $remote->table('public.dte_json')->insert(['dte_id' => $journal->codigo_generacion, 'resultado' => $journal->documento]);
                }
            });
            DB::table('libreria.venta_dtes')->where('id', $journalId)->update(['guardado_externo_at' => now(), 'updated_at' => now()]);
        });
    }
}
