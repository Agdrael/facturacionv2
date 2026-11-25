<?php

namespace App\Services\Libreria;

use App\Models\Libreria\CashSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use stdClass;

class LegacyCashBridgeService
{
    public function __construct(private LegacyCashSnapshotService $snapshots) {}

    public function active(string $register): bool
    {
        return Schema::hasTable('libreria.legacy_cash_registers')
            && DB::table('libreria.legacy_cash_registers')->where('register', $register)->where('active', true)->exists();
    }

    /** @return array<string, bool> */
    public function states(): array
    {
        return Schema::hasTable('libreria.legacy_cash_registers')
            ? DB::table('libreria.legacy_cash_registers')->pluck('active', 'register')->map(fn ($active): bool => (bool) $active)->all() : [];
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['caja' => $message]);
        }
    }

    private function register(string $register): void
    {
        $this->require(in_array($register, config('cash.registers'), true), 'La integración solo admite P001, P002 y P003.');
    }

    private function lockBox(string $register): stdClass
    {
        $boxes = DB::table('public.cajas')->where('codigo_caja', $register)->lockForUpdate()->get();
        $this->require($boxes->count() === 1, 'V1 debe tener una única caja con código '.$register.'.');
        $box = $boxes->sole();
        $openTurns = DB::table('public.inicio_cierre_caja')->where('id_caja', $box->id)->whereNull('hora_cierre')->lockForUpdate()->get();
        $open = $openTurns->count();
        $this->require($open <= 1 && (bool) $box->estado_caja === ($open === 1), 'El estado de caja y los turnos abiertos de V1 no coinciden. Revisa V1 antes de continuar.');

        return $box;
    }

    public function activate(string $register): void
    {
        $this->register($register);
        $this->require(DB::getDriverName() === 'pgsql', 'La integración requiere PostgreSQL y las tablas V1 y V2 en la misma base.');
        $this->require(Schema::hasTable('libreria.legacy_cash_registers'), 'Ejecuta las migraciones de V2 antes de activar la integración.');
        $identitySql = 'SELECT current_database() AS name, inet_server_addr()::text AS host, inet_server_port() AS port';
        $local = DB::selectOne($identitySql);
        $legacy = DB::connection(config('receipts.legacy_connection'))->selectOne($identitySql);
        $this->require((array) $local === (array) $legacy, 'La conexión anterior de Recibos y V2 deben apuntar a la misma base PostgreSQL para evitar copias duplicadas.');
        foreach (['public.cajas', 'public.inicio_cierre_caja', 'public.ventas', 'public.recibos', 'public.metodos_pago_venta', 'public.libreria_recibo_documentos'] as $table) {
            $this->require(Schema::hasTable($table), 'No se encuentra '.$table.' en la base de V2. Revisa la base compartida y las migraciones.');
        }
        DB::transaction(function () use ($register): void {
            DB::table('libreria.cajas')->where('codigo', $register)->lockForUpdate()->firstOrFail();
            // Drain writes predating activation before capturing the initial candidate set.
            DB::statement('LOCK TABLE public.cajas, public.inicio_cierre_caja, public.ventas, public.recibos, public.metodos_pago_venta IN SHARE ROW EXCLUSIVE MODE');
            $box = $this->lockBox($register);
            DB::connection()->getPdo()->exec(File::get(database_path('sql/legacy_cash_bridge.sql')));
            if ($this->active($register)) {
                return;
            }
            $date = now(config('cash.timezone'))->toDateString();
            $turns = DB::table('public.inicio_cierre_caja')->where('id_caja', $box->id)
                ->where(fn ($query) => $query->where('fecha', '>=', $date)->orWhereNull('hora_cierre'))->orderBy('id')->get();
            $prior = DB::table('public.inicio_cierre_caja')->where('id_caja', $box->id)->where('fecha', CarbonImmutable::parse($date)->subDay()->toDateString())
                ->whereNotNull('hora_cierre')->whereColumn('hora_cierre', '<', 'hora_inicio')->get();
            $turns = $turns->concat($prior)->unique('id');
            foreach ($turns as $turn) {
                $opened = $this->legacyDate($turn->fecha, $turn->hora_inicio);
                $closed = $turn->hora_cierre === null ? null : $this->legacyDate($turn->fecha, $turn->hora_cierre);
                if ($closed !== null && $closed->lessThan($opened)) {
                    $closed = $closed->addDay();
                }
                DB::table('libreria.legacy_cash_sessions')->insertOrIgnore(['legacy_id' => $turn->id, 'register' => $register, 'opened_at' => $opened, 'closed_at' => $closed]);
                DB::table('libreria.legacy_cash_sessions')->where('legacy_id', $turn->id)->whereNull('reconciliation_id')->update(['closed_at' => $closed]);
            }
            $start = $turns->min('fecha') ?? $date;
            $sessions = DB::table('libreria.legacy_cash_sessions')->where('register', $register)->whereNull('reconciliation_id')->get();
            $sales = DB::table('public.ventas as v')->where('v.numero_control', 'like', '%'.$register.'-%')->where('v.fecha_sistema', '>=', $start)
                ->where(fn ($query) => $query->whereNull('v.anulado')->orWhere('v.anulado', false))
                ->where(fn ($query) => $query->whereNull('v.operacion')->orWhere('v.operacion', '!=', '05'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('libreria.venta_dtes as d')->whereColumn('d.codigo_generacion', 'v.numero_factura'))->get();
            foreach ($sales as $sale) {
                $timestamp = $this->saleDate($sale->fecha_sistema, $sale->hora);
                $matches = $timestamp === null ? collect() : $sessions->filter(fn ($turn): bool => $timestamp->greaterThanOrEqualTo(CarbonImmutable::parse($turn->opened_at))
                    && ($turn->closed_at === null || $timestamp->lessThanOrEqualTo(CarbonImmutable::parse($turn->closed_at))));
                DB::table('libreria.legacy_cash_movements')->insertOrIgnore(['kind' => 'venta', 'legacy_id' => $sale->id_venta, 'register' => $register,
                    'legacy_session_id' => $matches->count() === 1 ? $matches->first()->legacy_id : null, 'recorded_at' => $timestamp]);
            }
            $receipts = DB::table('public.recibos as r')->where('r.numero_caja', $register)->where('r.fecha_sistema', '>=', $start)
                ->where(fn ($query) => $query->whereNull('r.anulado')->orWhere('r.anulado', false))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('public.libreria_recibo_documentos as d')->whereColumn('d.legacy_receipt_id', 'r.id'))->get();
            foreach ($receipts as $receipt) {
                DB::table('libreria.legacy_cash_movements')->insertOrIgnore(['kind' => 'recibo', 'legacy_id' => $receipt->id, 'register' => $register]);
            }
            DB::table('libreria.legacy_cash_registers')->updateOrInsert(['register' => $register], ['active' => true, 'activated_at' => now()]);
            $this->audit($register, 'activate', null, ['sales' => $sales->count(), 'receipts' => $receipts->count()]);
        }, 3);
    }

    public function deactivate(string $register): void
    {
        $this->register($register);
        DB::transaction(function () use ($register): void {
            DB::table('libreria.cajas')->where('codigo', $register)->lockForUpdate()->firstOrFail();
            $this->lockBox($register);
            DB::table('libreria.legacy_cash_registers')->where('register', $register)->update(['active' => false]);
            $this->audit($register, 'deactivate', null, []);
        });
    }

    public function legacyDate(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time, config('cash.timezone'));
    }

    private function saleDate(?string $date, ?string $time): ?CarbonImmutable
    {
        if ($date === null || ! preg_match('/^\d{2}:\d{2}:\d{2}(?:\s*(?:AM|PM))?$/i', trim($time ?? ''))) {
            return null;
        }
        try {
            // V1 appended AM/PM to an already 24-hour value in some paths.
            return $this->legacyDate($date, substr(trim($time), 0, 8));
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function preparation(string $register, int $page = 1): array
    {
        $this->register($register);
        $this->require($this->active($register), 'Primero activa la integración mediante cash:legacy-bridge.');
        $pending = DB::table('libreria.legacy_cash_movements as m')->leftJoin('libreria.legacy_cash_sessions as s', 's.legacy_id', '=', 'm.legacy_session_id')
            ->where('m.register', $register)->where('m.excluded', false)->whereNull('s.reconciliation_id')
            ->where(fn ($query) => $query->whereNull('m.legacy_session_id')->orWhere(fn ($query) => $query->where('m.kind', 'recibo')
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('public.recibos as r')->whereColumn('r.id', 'm.legacy_id')
                    ->where(fn ($query) => $query->where(fn ($query) => $query->whereNull('r.precio')->whereNull('m.confirmed_amount'))
                        ->orWhere(fn ($query) => $query->whereNull('r.tarjeta')->whereNull('m.confirmed_method'))))))
            ->select('m.*')->orderBy('m.id')->paginate(50, ['*'], 'page', $page);
        $rows = $this->preparationRows($pending->getCollection());

        return ['pending' => $pending, 'rows' => $rows, 'version' => $this->version($rows), 'register' => $register,
            'turns' => DB::table('libreria.legacy_cash_sessions')->where('register', $register)->whereNull('reconciliation_id')->orderBy('opened_at')->get()];
    }

    /** @param Collection<int, stdClass> $movements
     * @return array<int, array<string, mixed>> */
    private function preparationRows(Collection $movements): array
    {
        $sales = DB::table('public.ventas')->whereIn('id_venta', $movements->where('kind', 'venta')->pluck('legacy_id'))->get()->keyBy('id_venta');
        $receipts = DB::table('public.recibos')->whereIn('id', $movements->where('kind', 'recibo')->pluck('legacy_id'))->get()->keyBy('id');

        return $movements->map(function ($movement) use ($sales, $receipts): array {
            $row = $movement->kind === 'venta' ? $sales->get($movement->legacy_id) : $receipts->get($movement->legacy_id);
            $this->require($row !== null, 'Un movimiento V1 ya no existe. Requiere revisión antes de cerrar.');

            return ['id' => $movement->id, 'legacyId' => $movement->legacy_id, 'kind' => $movement->kind, 'session' => $movement->legacy_session_id,
                'date' => $row->fecha_sistema, 'number' => $movement->kind === 'venta' ? $row->numero_control : 'Recibo V1 #'.$row->id,
                'name' => $row->nombre_cliente ?? '', 'amount' => $movement->kind === 'venta' ? $row->total : $row->precio, 'source' => (array) $row,
                'methodMissing' => $movement->kind === 'recibo' && $row->tarjeta === null,
                'confirmation' => [$movement->confirmed_by, $movement->confirmed_at, $movement->confirmed_amount, $movement->confirmed_method, $movement->excluded]];
        })->all();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function version(array $rows): string
    {
        return hash_hmac('sha256', json_encode($rows, JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** @param array<int|string, array{session: string|int, amount?: ?string, method?: ?string, reason?: ?string}> $assignments */
    public function confirm(User $user, string $register, string $version, array $assignments): void
    {
        Gate::forUser($user)->authorize('libreria.arqueos.revisar');
        $this->register($register);
        DB::transaction(function () use ($user, $register, $version, $assignments): void {
            DB::table('libreria.cajas')->where('codigo', $register)->lockForUpdate()->firstOrFail();
            $this->lockBox($register);
            $this->require($this->active($register), 'La integración está desactivada.');
            $movements = DB::table('libreria.legacy_cash_movements')->where('register', $register)->whereIn('id', array_keys($assignments))->orderBy('id')->lockForUpdate()->get();
            $rows = $this->preparationRows($movements);
            $this->require($movements->count() === count($assignments) && hash_equals($this->version($rows), $version), 'La lista cambió. Recarga y confirma los movimientos actuales.');
            foreach ($rows as $row) {
                $input = $assignments[$row['id']];
                $session = (int) $input['session'];
                $reason = trim($input['reason'] ?? '');
                $this->require($session > 0 || mb_strlen($reason) >= 5, 'Explica por qué el movimiento queda fuera del corte (al menos 5 caracteres).');
                if ($session > 0) {
                    $this->require(DB::table('libreria.legacy_cash_sessions')->where('legacy_id', $session)->where('register', $register)->whereNull('reconciliation_id')->exists(), 'El turno elegido no pertenece a esta caja o ya fue incorporado.');
                }
                $this->require(! DB::table('libreria.legacy_cash_sessions')->where('legacy_id', $row['session'])->whereNotNull('reconciliation_id')->exists(), 'Este movimiento ya pertenece a un arqueo cerrado.');
                $amount = null;
                if ($session > 0 && $row['amount'] === null) {
                    $amount = ReceiptMoney::decimal(ReceiptMoney::cents((string) ($input['amount'] ?? '')));
                    $this->require(ReceiptMoney::cents($amount) > 0 && mb_strlen($reason) >= 5, 'Confirma el importe real y su justificación para el recibo sin monto.');
                }
                $method = null;
                if ($session > 0 && $row['methodMissing']) {
                    $method = $input['method'] ?? null;
                    $this->require(in_array($method, ['01', 'V1_CARD'], true) && mb_strlen($reason) >= 5, 'Confirma el medio de pago y su respaldo para el recibo que no lo registra.');
                }
                DB::table('libreria.legacy_cash_movements')->where('id', $row['id'])->update(['legacy_session_id' => $session ?: null, 'excluded' => $session === 0,
                    'confirmed_amount' => $amount, 'confirmed_method' => $method, 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'reason' => $reason ?: 'Turno confirmado en conciliación inicial']);
            }
            $this->audit($register, 'confirm', $user->id, ['movements' => $assignments]);
        }, 3);
    }

    /** Called inside the V2 close transaction, after locking the V2 register.
     * @return array<string, mixed>|null */
    public function close(CashSession $session): ?array
    {
        $register = $session->register->codigo;
        if (! $this->active($register)) {
            return null;
        }
        $box = $this->lockBox($register);
        $this->require($this->preparation($register)['pending']->total() === 0, 'Hay movimientos de V1 pendientes de conciliación. Admin o Contador debe confirmarlos en Caja y arqueos antes de cerrar.');
        $date = $session->fecha_operativa->toDateString();
        $turns = DB::table('libreria.legacy_cash_sessions')->where('register', $register)->whereNull('reconciliation_id')
            ->where(fn ($query) => $query->whereNull('closed_at')
                ->orWhereRaw("(opened_at AT TIME ZONE 'America/El_Salvador')::date = ?", [$date])
                ->orWhereRaw("(closed_at AT TIME ZONE 'America/El_Salvador')::date = ?", [$date])
                ->orWhere('closed_at', '>=', $session->abierto_at))->orderBy('legacy_id')->lockForUpdate()->get();
        if ((bool) $box->estado_caja) {
            $open = DB::table('public.inicio_cierre_caja')->where('id_caja', $box->id)->whereNull('hora_cierre')->sole();
            $this->require($turns->contains('legacy_id', $open->id), 'El turno V1 abierto no está registrado en la integración.');
            DB::select("SELECT set_config('libreria.cash_closed_at', ?, true)", [$session->cerrado_at->toIso8601String()]);
            DB::table('public.cajas')->where('id', $box->id)->update(['estado_caja' => false]);
            DB::table('public.inicio_cierre_caja')->where('id', $open->id)->update(['hora_cierre' => $session->cerrado_at->setTimezone(config('cash.timezone'))->format('H:i:s'), 'codigo_reapertura' => (string) random_int(100000, 999999)]);
            $turns = DB::table('libreria.legacy_cash_sessions')->whereIn('legacy_id', $turns->pluck('legacy_id'))->orderBy('legacy_id')->get();
        }

        return $this->snapshots->build($register, $turns);
    }

    /** @param array<string, mixed> $legacy */
    public function claim(array $legacy, int $reconciliation, User $user): void
    {
        $ids = array_column($legacy['sessions'], 'legacyId');
        DB::table('libreria.legacy_cash_sessions')->whereIn('legacy_id', $ids)->whereNull('reconciliation_id')->update(['reconciliation_id' => $reconciliation]);
        $this->audit($legacy['register'], 'close', $user->id, ['reconciliationId' => $reconciliation, 'sessions' => $ids]);
    }

    /** @param array<string, mixed> $data */
    private function audit(string $register, string $action, ?int $user, array $data): void
    {
        DB::table('libreria.legacy_cash_audits')->insert(['register' => $register, 'action' => $action, 'user_id' => $user, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
