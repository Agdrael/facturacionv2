@php
    $mode = $data['mode'] ?? 'welcome';
    $s = $data['summary'] ?? [];
    $partial = ($s['issues'] ?? 0) > 0 || ! ($data['receiptAvailable'] ?? true) || collect($data['coverage'] ?? [])->contains('partial', true);
    $money = fn ($value) => '$'.number_format((float) $value, 2);
    $amount = fn ($value, $count = null) => $partial && ($count ?? (($s['salesCount'] ?? 0) + ($s['receiptCount'] ?? 0))) === 0 ? '—' : $money($value);
    $stateLabels = ['ACEPTADO' => 'Aceptado', 'PENDIENTE' => 'Pendiente', 'ENVIANDO' => 'Enviando', 'RECHAZADO' => 'Rechazado', 'REVISION' => 'Requiere revisión', 'SIN_CONFIRMAR' => 'Sin confirmar', 'ERROR' => 'Error', 'ANULADO' => 'Anulado', 'EMITIDO' => 'Emitido', 'PENDIENTE_APLICACION' => 'Pendiente de aplicación', 'ANULACION_PENDIENTE' => 'Anulación pendiente'];
@endphp

<section class="access-workspace space-y-6" wire:poll.30s.visible="refreshData">
    <header class="access-header">
        <div class="access-hero">
            <div class="flex items-start gap-4">
                <span class="access-hero-icon"><flux:icon.chart-bar class="size-6" /></span>
                <div>
                    <p class="access-eyebrow">Santa Cecilia / Facturación</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-brand-paper">{{ $mode === 'executive' ? 'Resumen ejecutivo' : ($mode === 'cashier' ? 'Mi caja · '.$data['assigned'] : 'Bienvenido') }}</h1>
                    <p class="mt-2 max-w-xl text-sm text-brand-paper/65">{{ $mode === 'executive' ? 'Cobros, documentos y actividad de todas las cajas.' : ($mode === 'cashier' ? 'El día de tu caja, con todos sus operadores y aperturas.' : 'Tu espacio de trabajo en Facturación Santa Cecilia.') }}</p>
                </div>
            </div>
            <div class="flex flex-col items-start gap-2 sm:items-end">
                <flux:button wire:click="refreshData" wire:loading.attr="disabled" icon="arrow-path">Actualizar</flux:button>
                <p class="text-xs text-brand-paper/60">Última consulta: {{ $data['updatedAt'] ?? 'Sin consulta' }}</p>
            </div>
        </div>
    </header>

    @if ($loadError)
        <div role="alert" class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">{{ $loadError }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-red-500/40 bg-red-500/10 p-4 text-sm">Revisa el período y la caja seleccionados. La fecha final no puede ser posterior a hoy.</div>
    @endif
    <div wire:offline class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">Sin conexión. Los importes corresponden a la última consulta.</div>

    @if ($mode === 'welcome')
        <div class="access-panel p-8">
            <h2 class="text-lg font-semibold">Hola, {{ auth()->user()->name }}</h2>
            <p class="access-muted mt-2 text-sm">Usa el menú para acceder a las opciones habilitadas para tu cuenta.</p>
        </div>
    @elseif ($s)
        @if ($mode === 'executive')
            <form wire:submit="refreshData" class="access-panel space-y-4 p-5">
                <div class="flex flex-wrap gap-2">
                    @foreach ([1 => 'Hoy', 7 => 'Últimos 7 días', 30 => 'Últimos 30 días'] as $days => $label)
                        <flux:button size="sm" wire:click="period({{ $days }})" wire:key="period-{{ $days }}">{{ $label }}</flux:button>
                    @endforeach
                </div>
                <div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto]">
                    <flux:input type="date" label="Desde" wire:model="from" />
                    <flux:input type="date" label="Hasta" wire:model="to" />
                    <flux:select label="Caja" wire:model="register"><option value="all">Todas las cajas</option>@foreach (\App\Services\Dashboard\DashboardMetricsService::REGISTERS as $point)<option value="{{ $point }}">{{ $point }}</option>@endforeach</flux:select>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Aplicar filtros</flux:button>
                </div>
            </form>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
            <p class="access-muted">{{ \Carbon\CarbonImmutable::parse($data['from'])->format('d/m/Y') }}{{ $data['from'] !== $data['to'] ? ' — '.\Carbon\CarbonImmutable::parse($data['to'])->format('d/m/Y') : '' }} · Hora de El Salvador</p>
            <span class="access-badge {{ $partial ? 'access-badge--pending' : '' }}">{{ $partial ? 'Cobertura parcial · importes verificados' : 'Información actualizada' }}</span>
        </div>
        @if ($partial)
            <div class="rounded-xl border border-amber-500/35 bg-amber-500/10 px-5 py-4 text-sm">
                Los importes corresponden a los documentos verificables. Revisa la cobertura V1 y las incidencias al final del panel; un dato desconocido se muestra como «—».
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="access-panel border-t-4! border-t-brand-yellow! p-5">
                <p class="access-label">Total cobrado</p><p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ $amount($s['collections']) }}</p>
                <p class="access-muted mt-3 text-xs">DTE + recibos · cambio descontado</p>
            </article>
            <article class="access-panel p-5">
                <p class="access-label">DTE · {{ $s['salesCount'] }} verificados</p><p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ $amount($s['sales'], $s['salesCount']) }}</p>
                <p class="access-muted mt-3 text-xs">Cobrado al emitir: {{ $amount($s['saleCollected'], $s['salesCount']) }}</p>
            </article>
            <article class="access-panel p-5">
                <p class="access-label">Recibos · {{ $s['receiptCount'] }} verificados</p><p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ $amount($s['receipts'], $s['receiptCount']) }}</p>
                <p class="access-muted mt-3 text-xs">Incluye abonos CXC; no suma otra venta</p>
            </article>
            <article class="access-panel p-5">
                @if ($mode === 'executive')
                    <p class="access-label">Cajas abiertas ahora</p><p class="mt-2 text-3xl font-semibold tabular-nums">{{ $data['openV2'] }} <span class="access-muted text-base font-normal">en V2</span></p>
                    <p class="access-muted mt-3 text-xs">{{ $data['openV1'] }} en V1 · P004 sin control de apertura</p>
                @else
                    <p class="access-label">Efectivo neto</p><p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ $amount($s['cash']) }}</p>
                    <p class="access-muted mt-3 text-xs">Efectivo recibido menos cambio</p>
                @endif
            </article>
        </div>

        @if ($mode === 'executive')
            @include('dashboard.charts')
        @endif

        <div class="grid items-start gap-6 xl:grid-cols-[1.35fr_1fr]">
            <section class="access-panel overflow-hidden">
                <div class="access-panel-heading"><div><h2 class="font-semibold">¿Cómo se ha cobrado?</h2><p class="access-muted mt-1 text-xs">Dinero recibido, separado del importe facturado.</p></div><span class="access-badge">Neto de cambio</span></div>
                <div class="overflow-x-auto">
                    <table class="access-user-table">
                        <thead><tr><th>Medio de pago</th><th class="text-right">Cobrado por DTE</th><th class="text-right">Cobrado por recibos</th><th class="text-right">Total</th></tr></thead>
                        <tbody>
                            @forelse ($s['methods'] as $code => $method)
                                <tr wire:key="method-{{ $code }}"><td>{{ $method['label'] }}</td><td class="text-right tabular-nums">{{ $money($method['sales']) }}</td><td class="text-right tabular-nums">{{ $money($method['receipts']) }}</td><td class="text-right font-semibold tabular-nums">{{ $money($method['total']) }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="access-muted">{{ $partial ? 'No hay cobros verificables en este período.' : 'No hay cobros en este período.' }}</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot><tr><td class="font-semibold">Total</td><td class="text-right tabular-nums">{{ $amount($s['saleCollected'], $s['salesCount']) }}</td><td class="text-right tabular-nums">{{ $amount($s['receiptCollected'], $s['receiptCount']) }}</td><td class="text-right font-semibold tabular-nums">{{ $amount($s['collections']) }}</td></tr></tfoot>
                    </table>
                </div>
            </section>
            <section class="access-panel overflow-hidden">
                <div class="access-panel-heading"><h2 class="font-semibold">Documentos y crédito</h2></div>
                <dl class="space-y-4 p-5 text-sm">
                    @foreach (['01' => 'Factura', '03' => 'Crédito fiscal'] as $type => $label)
                        <div class="flex justify-between gap-3" wire:key="document-{{ $type }}"><dt class="access-muted">{{ $label }} <span class="access-badge ml-1">{{ $s['documents'][$type]['count'] ?? 0 }}</span></dt><dd class="font-semibold tabular-nums">{{ $amount($s['documents'][$type]['amount'] ?? '0', $s['documents'][$type]['count'] ?? 0) }}</dd></div>
                    @endforeach
                    <div class="flex justify-between gap-3 border-t border-[var(--access-border)] pt-4"><dt class="access-muted">Cobrado al emitir DTE</dt><dd class="font-semibold tabular-nums">{{ $amount($s['saleCollected'], $s['salesCount']) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="access-muted">Crédito pendiente al emitir</dt><dd class="font-semibold tabular-nums">{{ $amount($s['credit'], $s['salesCount']) }}</dd></div>
                </dl>
                <p class="access-muted px-5 pb-5 text-xs">El crédito corresponde al momento de emisión. No representa el saldo actual de CXC.</p>
            </section>
        </div>

        <section class="access-panel overflow-hidden">
            <div class="access-panel-heading"><div><h2 class="font-semibold">{{ $mode === 'executive' ? 'Cajas y actividad' : 'Estado de mi caja' }}</h2><p class="access-muted mt-1 text-xs">Los estados de apertura son actuales, aunque consultes otro período.</p></div></div>
            <div class="overflow-x-auto">
                <table class="access-user-table">
                    <thead><tr><th>Caja</th><th>V2 · ahora</th><th>V1 · ahora</th><th class="text-right">Cobrado DTE</th><th class="text-right">Cobrado recibos</th><th class="text-right">Total cobrado</th></tr></thead>
                    <tbody>
                        @foreach ($data['boxes'] as $box)
                            @php
                                $boxUnknown = ! $box['hasVerified'] && (! $box['complete'] || $box['coverage']['partial'] || ! $data['receiptAvailable']);
                            @endphp
                            <tr wire:key="box-{{ $box['register'] }}">
                                <td><p class="font-semibold">{{ $box['register'] }}</p>@if ($box['issues'])<p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ $box['issues'] }} por revisar</p>@endif</td>
                                <td><span class="access-badge {{ $box['state']['v2'] === 'Abierta' ? 'access-badge--admin' : '' }}">{{ $box['state']['v2'] }}</span>@if ($box['state']['openedAt'])<p class="access-muted mt-1 text-xs">{{ $box['state']['openedAt'] }} · {{ $box['state']['openedBy'] }}</p>@endif</td>
                                <td><span class="access-badge {{ $box['state']['v1'] === 'Abierta' ? 'access-badge--admin' : '' }}">{{ $box['state']['v1'] }}</span></td>
                                <td class="text-right tabular-nums">{{ $boxUnknown ? '—' : $money($box['saleCollected']) }}</td><td class="text-right tabular-nums">{{ $boxUnknown ? '—' : $money($box['receiptCollected']) }}</td><td class="text-right font-semibold tabular-nums">{{ $boxUnknown ? '—' : $money($box['collections']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[1fr_1fr]">
            <section class="access-panel overflow-hidden">
                <div class="access-panel-heading"><h2 class="font-semibold">Por atender</h2><span class="access-badge">{{ $s['pendingDte'] + $s['pendingReceipt'] + $s['issues'] }}</span></div>
                <dl class="space-y-4 p-5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="access-muted">DTE pendientes, rechazados o sin confirmar</dt><dd class="font-semibold">{{ $s['pendingDte'] }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="access-muted">Recibos pendientes de aplicación o revisión</dt><dd class="font-semibold">{{ $s['pendingReceipt'] }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="access-muted">Movimientos con datos por conciliar</dt><dd class="font-semibold">{{ $s['issues'] }}</dd></div>
                    <div class="flex justify-between gap-3 border-t border-[var(--access-border)] pt-4"><dt class="access-muted">Anulados · {{ $s['annulledCount'] }} documentos</dt><dd class="font-semibold tabular-nums">{{ $s['annulledUnknown'] ? 'Importe incompleto' : $money($s['annulledAmount']) }}</dd></div>
                </dl>
                <p class="access-muted px-5 pb-5 text-xs">Los anulados se muestran aparte de los importes vigentes. No implican devolución automática ni modifican arqueos anteriores.</p>
            </section>
            <section class="access-panel overflow-hidden">
                <div class="access-panel-heading"><h2 class="font-semibold">Origen y cobertura</h2></div>
                <div class="grid grid-cols-2 gap-4 p-5">
                    @foreach (['v1' => 'Facturación V1', 'v2' => 'Facturación V2'] as $source => $label)
                        @php
                            $origin = $s['origins'][$source] ?? null;
                            $originUnknown = $origin ? $origin['count'] === 0 && $origin['issues'] > 0 : ($source === 'v1' && $partial) || ($source === 'v2' && ! $data['receiptAvailable']);
                        @endphp
                        <div class="rounded-xl bg-[var(--access-subtle)] p-4" wire:key="source-{{ $source }}">
                            <p class="access-muted text-xs">{{ $label }}</p><p class="mt-2 text-xl font-semibold tabular-nums">{{ $originUnknown ? '—' : $money($origin['collections'] ?? '0') }}</p>
                            <p class="access-muted mt-3 text-xs">DTE: {{ $originUnknown ? '—' : $money($origin['sales'] ?? '0') }}</p><p class="access-muted mt-1 text-xs">Recibos: {{ $originUnknown ? '—' : $money($origin['receipts'] ?? '0') }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="space-y-2 px-5 pb-5 text-xs">
                    @foreach ($data['coverage'] as $point => $coverage)
                        <p class="access-muted" wire:key="coverage-{{ $point }}"><strong>{{ $point }}</strong> · {{ $coverage['message'] }}</p>
                    @endforeach
                    @if (! $data['receiptAvailable'])<p class="text-amber-600 dark:text-amber-400">Recibos V2 no está disponible en esta base.</p>@endif
                </div>
            </section>
        </div>

        @if ($data['issues'])
            <section class="access-panel overflow-hidden">
                <div class="access-panel-heading"><h2 class="font-semibold">Revisar antes de conciliar</h2><span class="access-badge">Hasta 20 incidencias</span></div>
                <ul class="divide-y divide-[var(--access-border)]">
                    @foreach ($data['issues'] as $issue)
                        <li class="px-5 py-4 text-sm" wire:key="issue-{{ $issue['source'] }}-{{ $issue['kind'] }}-{{ $issue['id'] }}"><p class="font-medium">{{ $issue['register'] }} · {{ strtoupper($issue['source']) }} · {{ $issue['number'] }}</p><p class="access-muted mt-1">{{ $issue['message'] }}</p></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($mode === 'cashier')
            <section class="access-panel overflow-hidden">
                <div class="access-panel-heading"><h2 class="font-semibold">Últimos movimientos de la caja</h2><span class="access-badge">Hoy · hasta 10</span></div>
                <div class="overflow-x-auto"><table class="access-user-table"><thead><tr><th>Documento</th><th>Operador / fecha</th><th>Estado</th><th class="text-right">Importe</th></tr></thead><tbody>
                    @forelse ($data['recent'] as $row)
                        <tr wire:key="recent-{{ $row['source'] }}-{{ $row['kind'] }}-{{ $row['id'] }}"><td><p class="max-w-72 break-all font-mono text-xs">{{ $row['number'] }}</p><p class="access-muted mt-1 text-xs">{{ strtoupper($row['source']) }} · {{ $row['kind'] === 'sale' ? 'DTE' : 'Recibo' }}</p></td><td><p>{{ $row['operator'] }}</p><p class="access-muted mt-1 text-xs">{{ $row['timeKnown'] ? \Carbon\CarbonImmutable::parse($row['stamp'])->format('d/m H:i') : 'Sin hora registrada' }}</p></td><td><span class="access-badge">{{ $stateLabels[$row['state']] ?? $row['state'] }}</span></td><td class="text-right tabular-nums">{{ $row['amount'] === null ? '—' : $money($row['amount']) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="access-muted">No hay movimientos identificados para hoy.</td></tr>
                    @endforelse
                </tbody></table></div>
            </section>
        @endif

        <nav aria-label="Accesos de operación" class="flex flex-wrap gap-3">
            @can('libreria.ventas.ver')<flux:button :href="route('libreria.venta')" wire:navigate icon="shopping-bag">Ir a Ventas</flux:button>@endcan
            @if (config('receipts.enabled')) @can('libreria.recibos.ver')<flux:button :href="route('libreria.recibos.index')" wire:navigate icon="document-text">Ir a Recibos</flux:button>@endcan @endif
            @can('libreria.cajas.ver')<flux:button :href="route('libreria.cajas.index')" wire:navigate icon="lock-closed">Caja y arqueos</flux:button>@endcan
        </nav>
        <p class="access-muted text-xs">Consulta de solo lectura · actualización cada 30 segundos mientras el panel está visible.</p>
    @endif
</section>
