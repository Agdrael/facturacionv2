<x-layouts::app :title="'Caja y arqueos · Librería'">
    <div class="access-workspace space-y-6">
        <header class="access-header">
            <div class="access-hero"><div class="flex items-start gap-4"><span class="access-hero-icon"><flux:icon.lock-closed class="size-6" /></span><div><p class="access-eyebrow">Librería / Control de caja</p><h1 class="mt-2 text-2xl font-semibold text-brand-paper">Caja y arqueos</h1><p class="mt-2 text-sm text-brand-paper/65">Apertura, cierre y entrega del arqueo de cada sesión.</p></div></div><span class="access-badge border-brand-paper/20! text-brand-paper!">Punto asignado: {{ $point }}</span></div>
        </header>
        @if (session('status'))<flux:callout variant="success">{{ session('status') }}</flux:callout>@endif
        @if ($errors->any())<flux:callout variant="danger"><ul class="list-disc ps-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></flux:callout>@endif
        @if (! in_array($point, config('cash.registers'), true))
            <flux:callout>El punto {{ $point }} no requiere apertura ni cierre de caja. Su operación de ventas continúa como antes.</flux:callout>
        @endif
        <div class="grid items-start gap-4 lg:grid-cols-3">
            @foreach ($registers as $register)
                @php
                    $cashSession = $register->latestSession;
                    $isOpen = $cashSession !== null && $cashSession->cerrado_at === null;
                    $sameDay = $cashSession !== null && $cashSession->fecha_operativa->toDateString() === $today;
                    $isOwn = $register->codigo === $point;
                @endphp
                <section class="access-panel overflow-hidden">
                    <div class="access-panel-heading"><h2 class="font-semibold">Caja {{ $register->codigo }}</h2><span class="access-badge">{{ $isOpen ? 'Abierta' : 'Cerrada' }}</span></div>
                    <div class="space-y-5 p-5">
                        @if ($legacyStates[$register->codigo] ?? false)
                            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                                <p class="font-semibold">Cierre conjunto con V1 activo</p>
                                <p class="mt-1 text-xs leading-5">Al cerrar aquí también se cerrará V1. El arqueo reunirá ventas y recibos de ambos sistemas.</p>
                                @can('libreria.arqueos.revisar')
                                    <flux:button :href="route('libreria.cajas.legacy.show', $register->codigo)" size="sm" class="mt-3">Preparar integración V1</flux:button>
                                @endcan
                            </div>
                        @endif
                        @if ($cashSession)
                            <dl class="grid gap-3 text-xs">
                                <div class="flex justify-between gap-3"><dt class="access-muted">Sesión</dt><dd>#{{ $cashSession->id }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="access-muted">Abrió</dt><dd class="text-right">{{ $cashSession->abierto_nombre }}<br>{{ $cashSession->abierto_at->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') }}</dd></div>
                                @if ($cashSession->cerrado_at)<div class="flex justify-between gap-3"><dt class="access-muted">Cerró</dt><dd class="text-right">{{ $cashSession->cerrado_nombre }}<br>{{ $cashSession->cerrado_at->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') }}</dd></div>@endif
                            </dl>
                        @else
                            <p class="access-muted text-xs leading-6">Sin sesiones registradas. La primera apertura iniciará el control de esta caja.</p>
                        @endif
                        @if ($isOwn && $isOpen)
                            @can('libreria.cajas.cerrar')
                                <form method="POST" action="{{ route('libreria.cajas.close', $cashSession->id) }}" class="space-y-3">
                                    @csrf
                                    <flux:checkbox name="confirmed" value="1" label="Confirmo el cierre y el bloqueo de nuevas ventas." required />
                                    <flux:button type="submit" variant="primary" icon="lock-closed" class="w-full">Cerrar y generar arqueo</flux:button>
                                </form>
                            @endcan
                        @elseif ($isOwn && ! $sameDay)
                            @can('libreria.cajas.abrir')
                                <form method="POST" action="{{ route('libreria.cajas.open') }}">@csrf<flux:button type="submit" variant="primary" class="w-full">Abrir caja</flux:button></form>
                            @endcan
                        @elseif ($isOwn)
                            @can('libreria.cajas.reabrir')
                                <form method="POST" action="{{ route('libreria.cajas.reopen') }}" class="space-y-4">
                                    @csrf
                                    <flux:input name="codigo" label="Código de reapertura" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required />
                                    <flux:textarea name="motivo" label="Motivo" rows="2" minlength="5" maxlength="1000" required>{{ old('motivo') }}</flux:textarea>
                                    <flux:button type="submit" variant="primary" class="w-full">Reabrir en otra sesión</flux:button>
                                </form>
                            @endcan
                        @endif
                        @if (! $isOpen && $sameDay && $cashSession->codigo_usado_at === null)
                            @can('libreria.cajas.ver_codigos')
                                <flux:button :href="route('libreria.cajas.code', $cashSession->id)" size="sm" icon="key" class="w-full">Consultar código de reapertura</flux:button>
                            @endcan
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
        @if ($records !== null)
            <section class="access-panel overflow-hidden">
                <div class="access-panel-heading"><div><h2 class="font-semibold">Historial de arqueos</h2><p class="access-muted mt-1 text-xs">La entrega y firma del Excel se gestionan por sesión.</p></div><span class="access-badge">{{ $records->total() }} arqueos</span></div>
                <div class="overflow-x-auto"><table class="access-user-table"><thead><tr><th>Caja / sesión</th><th>Cierre</th><th>Total documentos</th><th>Estado</th><th></th></tr></thead><tbody>
                    @forelse ($records as $record)
                        <tr><td><p class="font-semibold">{{ $record->session->register->codigo }}</p><p class="access-muted text-xs">Sesión #{{ $record->sesion_id }}</p></td><td><p>{{ $record->session->cerrado_at->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') }}</p><p class="access-muted text-xs">{{ $record->session->cerrado_nombre }}</p></td><td class="tabular-nums">${{ number_format((float) $record->snapshot['total'], 2) }}</td><td><span class="access-badge">{{ match ($record->estado) { 'firmado' => 'Firmado', 'revisado' => 'Revisado', 'recibido' => 'Pendiente de revisión', default => 'Pendiente de Excel' } }}</span></td><td class="text-right"><flux:button size="sm" :href="route('libreria.arqueos.show', $record->id)" wire:navigate>Ver arqueo</flux:button></td></tr>
                    @empty
                        <tr><td colspan="5" class="access-muted py-10! text-center">Los arqueos aparecerán al cerrar una sesión.</td></tr>
                    @endforelse
                </tbody></table></div>
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">{{ $records->links() }}</div>
            </section>
        @endif
    </div>
</x-layouts::app>
