<?php

namespace App\Services\Libreria;

use App\Models\Libreria\CashReconciliation;
use App\Models\Libreria\CashRegister;
use App\Models\Libreria\CashSession;
use App\Models\User;
use App\Services\Empleado\EmployeeAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CashRegisterService
{
    public function __construct(private CashSnapshotService $snapshots, private EmployeeAccessService $employees, private LegacyCashBridgeService $legacy, private LegacyCashSnapshotService $legacySnapshots) {}

    public function controlled(string $code): bool
    {
        return in_array($code, config('cash.registers'), true);
    }

    public function lockRegister(string $code): ?CashRegister
    {
        if (! $this->controlled($code)) {
            return null;
        }

        return CashRegister::query()->where('codigo', $code)->lockForUpdate()->firstOrFail();
    }

    public function saleSession(User $user): ?int
    {
        $register = $this->lockRegister((string) $user->getAttribute('puntoventa'));
        if ($register === null) {
            return null;
        }
        $session = $register->sessions()->whereNull('cerrado_at')->first();
        if ($session === null) {
            throw ValidationException::withMessages(['caja' => 'La caja está cerrada. Ábrela desde Caja y arqueos para registrar nuevas ventas.']);
        }

        return (int) $session->id;
    }

    public function assertVisible(User $user, string $code, string $allPermission = 'libreria.cajas.ver_todas'): void
    {
        abort_unless($this->controlled($code), 404);
        abort_unless($user->can($allPermission) || (string) $user->getAttribute('puntoventa') === $code, 403);
    }

    public function open(User $user, ?string $code = null, ?string $reason = null): CashSession
    {
        Gate::forUser($user)->authorize($code === null ? 'libreria.cajas.abrir' : 'libreria.cajas.reabrir');
        $point = (string) $user->getAttribute('puntoventa');
        abort_unless($this->controlled($point), 422, 'Este punto de venta no utiliza sesiones de caja.');
        if ($code !== null) {
            $key = 'cash-reopen:'.$user->id.':'.$point;
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw ValidationException::withMessages(['codigo' => 'Demasiados intentos. Espera un minuto antes de intentar nuevamente.']);
            }
            RateLimiter::hit($key, 60);
        }

        return DB::transaction(function () use ($user, $point, $code, $reason): CashSession {
            $register = $this->lockRegister($point);
            $current = $register->sessions()->whereNull('cerrado_at')->first();
            if ($current !== null) {
                return $current;
            }
            $date = now(config('cash.timezone'))->toDateString();
            $last = $register->sessions()->whereDate('fecha_operativa', $date)->orderByDesc('id')->first();
            if ($last !== null) {
                if ($code === null || $last->codigo_usado_at !== null || ! hash_equals((string) $last->codigo_reapertura, $code)) {
                    throw ValidationException::withMessages(['codigo' => 'Esta caja requiere un código de reapertura válido de su último cierre.']);
                }
                if (mb_strlen(trim($reason ?? '')) < 5) {
                    throw ValidationException::withMessages(['motivo' => 'Explica el motivo de la reapertura (al menos 5 caracteres).']);
                }
                $last->update(['codigo_usado_at' => now()]);
            } elseif ($code !== null) {
                throw ValidationException::withMessages(['codigo' => 'La primera apertura de esta jornada no necesita un código.']);
            }
            $employee = $this->employees->employeeFor($user);
            abort_if($employee === null, 403);

            return $register->sessions()->create([
                'fecha_operativa' => $date, 'abierto_por' => $user->id, 'abierto_nombre' => $user->name,
                'abierto_empleado' => $employee->codigo, 'abierto_at' => now(),
                'sesion_anterior_id' => $last?->id, 'motivo_reapertura' => $last === null ? null : trim($reason),
            ]);
        }, 3);
    }

    public function close(User $user, int $id): CashReconciliation
    {
        Gate::forUser($user)->authorize('libreria.cajas.cerrar');
        $session = CashSession::query()->with('register')->findOrFail($id);
        abort_unless((string) $user->getAttribute('puntoventa') === $session->register->codigo, 403);

        return DB::transaction(function () use ($user, $session): CashReconciliation {
            $this->lockRegister($session->register->codigo);
            $session->refresh();
            if ($session->cerrado_at !== null) {
                return $session->reconciliation()->sole();
            }
            $employee = $this->employees->employeeFor($user);
            abort_if($employee === null, 403);
            $session->update(['cerrado_por' => $user->id, 'cerrado_nombre' => $user->name, 'cerrado_empleado' => $employee->codigo, 'cerrado_at' => now(), 'codigo_reapertura' => (string) random_int(100000, 999999)]);

            $legacy = $this->legacy->close($session);
            $snapshot = $this->snapshots->build($session);
            if ($legacy !== null) {
                $snapshot = $this->legacySnapshots->merge($snapshot, $legacy);
            }
            $record = $session->reconciliation()->create(['snapshot' => $snapshot, 'estado' => 'pendiente_excel', 'archivos' => []]);
            if ($legacy !== null) {
                $this->legacy->claim($legacy, $record->id, $user);
            }

            return $record;
        }, 3);
    }
}
