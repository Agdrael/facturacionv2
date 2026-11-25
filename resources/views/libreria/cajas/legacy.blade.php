<x-layouts::app :title="'Integración V1 · '.$register">
    <div class="access-workspace space-y-6">
        <header class="access-header"><div class="access-hero">
            <div><p class="access-eyebrow">Librería / Caja y arqueos</p><h1 class="mt-2 text-2xl font-semibold text-brand-paper">Preparar integración V1 · {{ $register }}</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-brand-paper/65">Confirma los movimientos anteriores a la activación. Los recibos antiguos no conservan hora; selecciona el turno al que pertenecen.</p></div>
            <flux:button :href="route('libreria.cajas.index')" icon="arrow-left">Volver a cajas</flux:button>
        </div></header>
        @if (session('status'))<flux:callout variant="success">{{ session('status') }}</flux:callout>@endif
        @if ($errors->any())<flux:callout variant="danger"><ul class="list-disc ps-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></flux:callout>@endif
        <section class="access-panel space-y-4 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold">Conciliación inicial</h2><p class="access-muted mt-1 text-sm">El cierre queda bloqueado mientras existan movimientos pendientes.</p></div><span class="access-badge">{{ $pending->total() }} pendientes</span></div>
            <div class="grid gap-3 md:grid-cols-3">
                @forelse ($turns as $turn)
                    <div class="rounded-xl border border-zinc-200 p-3 text-xs dark:border-zinc-700">
                        <p class="font-semibold">Turno V1 #{{ $turn->legacy_id }}</p>
                        <p class="access-muted mt-2">{{ \Carbon\CarbonImmutable::parse($turn->opened_at)->setTimezone(config('cash.timezone'))->format('d/m/Y H:i:s') }} → {{ $turn->closed_at ? \Carbon\CarbonImmutable::parse($turn->closed_at)->setTimezone(config('cash.timezone'))->format('d/m/Y H:i:s') : 'Abierto' }}</p>
                    </div>
                @empty
                    <p class="access-muted text-sm">V1 no registra turnos pendientes. Los movimientos anteriores al corte deben justificarse al excluirlos.</p>
                @endforelse
            </div>
        </section>
        @if ($rows)
            <form method="POST" action="{{ route('libreria.cajas.legacy.confirm', $register) }}" class="access-panel overflow-hidden">
                @csrf
                <input type="hidden" name="version" value="{{ $version }}">
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($rows as $row)
                        <div class="grid gap-4 p-5 lg:grid-cols-3">
                            <div class="min-w-0 text-sm">
                                <p class="break-all font-semibold">{{ $row['number'] }}</p><p class="access-muted mt-1">{{ $row['name'] }}</p>
                                <p class="access-muted mt-1 text-xs">Registro {{ $row['date'] }} · {{ $row['kind'] }}</p>
                                <p class="mt-2 font-semibold">{{ $row['amount'] === null ? 'Importe no registrado' : '$'.number_format((float) $row['amount'], 2) }}</p>
                            </div>
                            <div class="space-y-3">
                                <flux:select name="assignments[{{ $row['id'] }}][session]" label="Turno confirmado" required>
                                    <option value="">Selecciona el turno</option>
                                    @foreach ($turns as $turn)
                                        <option value="{{ $turn->legacy_id }}" @selected((string) old('assignments.'.$row['id'].'.session', $row['session']) === (string) $turn->legacy_id)>#{{ $turn->legacy_id }} · {{ \Carbon\CarbonImmutable::parse($turn->opened_at)->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') }}</option>
                                    @endforeach
                                    <option value="0" @selected(old('assignments.'.$row['id'].'.session') === '0')>Fuera del corte — requiere motivo</option>
                                </flux:select>
                                @if ($row['amount'] === null)
                                    <flux:input name="assignments[{{ $row['id'] }}][amount]" label="Importe real comprobado" type="number" min="0.01" step="0.01" :value="old('assignments.'.$row['id'].'.amount')" />
                                @endif
                                @if ($row['methodMissing'])
                                    <flux:select name="assignments[{{ $row['id'] }}][method]" label="Medio de pago comprobado">
                                        <option value="">Selecciona el medio</option>
                                        <option value="01">Efectivo</option>
                                        <option value="V1_CARD">Tarjeta V1, sin especificar</option>
                                    </flux:select>
                                @endif
                            </div>
                            <flux:textarea name="assignments[{{ $row['id'] }}][reason]" label="Justificación / respaldo" rows="2" maxlength="1000" placeholder="Obligatoria al excluir o completar un importe">{{ old('assignments.'.$row['id'].'.reason') }}</flux:textarea>
                        </div>
                    @endforeach
                </div>
                <div class="space-y-4 border-t border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:checkbox name="confirmed" value="1" label="Verifiqué los comprobantes y confirmo la asignación de esta página." required />
                    <flux:button type="submit" variant="primary">Guardar conciliación</flux:button>
                </div>
            </form>
        @else
            <flux:callout variant="success">No hay movimientos pendientes de conciliación inicial. El cierre incluirá los turnos V1 correspondientes.</flux:callout>
        @endif
        {{ $pending->links() }}
    </div>
</x-layouts::app>
