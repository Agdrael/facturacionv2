<?php

namespace App\Services\Dte;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DteProcessLockService
{
    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(int $journalId, Closure $operation): mixed
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'pgsql') {
            return $operation();
        }
        $key = (string) $journalId;
        $locked = $db->selectOne("SELECT pg_try_advisory_lock(hashtext('libreria.dte'), hashtext(?)) AS acquired", [$key])->acquired;
        if (! $locked) {
            throw ValidationException::withMessages(['api' => 'Este DTE tiene otra operación en curso. Espera a que finalice.']);
        }
        try {
            return $operation();
        } finally {
            $db->select("SELECT pg_advisory_unlock(hashtext('libreria.dte'), hashtext(?))", [$key]);
        }
    }
}
