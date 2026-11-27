<x-layouts::app :title="'Arqueo #'.$record->id">
    <div class="access-workspace space-y-6">
        <header class="access-header"><div class="access-hero"><div><p class="access-eyebrow">Librería / Arqueos</p><h1 class="mt-2 text-2xl font-semibold text-brand-paper">Arqueo #{{ $record->id }} · {{ $record->session->register->codigo }}</h1><p class="mt-2 text-sm text-brand-paper/65">Sesión #{{ $record->sesion_id }} · {{ $record->session->cerrado_at->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') }}</p></div><flux:button :href="route('libreria.cajas.index')" icon="arrow-left" wire:navigate>Volver a cajas</flux:button></div></header>
        @if ($errors->any())
            <x-catalog-notice name="reconciliation-feedback" title="No se pudo completar la operación" kind="error" :automatic="true">
                <ul class="list-disc space-y-2 ps-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </x-catalog-notice>
        @elseif (session('status'))
            <x-catalog-notice name="reconciliation-feedback" title="Información del arqueo" kind="success" :automatic="true">{{ session('status') }}</x-catalog-notice>
        @endif
        @if ($incidents->isNotEmpty())
            <flux:callout variant="warning"><flux:callout.heading>Incidencias posteriores al cierre</flux:callout.heading><flux:callout.text>El arqueo conserva sus importes originales. Las invalidaciones de DTE y anulaciones de recibos no registran una devolución automática de dinero.</flux:callout.text><ul class="mt-3 space-y-1 text-xs">@foreach ($incidents as $incident)<li class="break-all font-mono">{{ $incident->numero_control }}</li>@endforeach</ul></flux:callout>
        @endif
        @can('libreria.arqueos.descargar')
            <section class="access-panel p-5">
                <div><p class="access-eyebrow">Documentos del cierre</p><h2 class="mt-2 text-lg font-semibold">Resúmenes para imprimir</h2><p class="access-muted mt-2 text-xs">Tickets PDF de 80 mm con los movimientos guardados de esta sesión.</p></div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><div><h3 class="text-sm font-semibold">Resumen ventas</h3><p class="access-muted mt-1 text-xs">Medios de pago y crédito pendiente.</p></div><flux:button size="sm" icon="arrow-down-tray" :href="route('libreria.arqueos.summary', [$record->id, 'ventas'])" target="_blank" rel="noopener">Abrir PDF</flux:button></div>
                    <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><div><h3 class="text-sm font-semibold">Resumen recibos</h3><p class="access-muted mt-1 text-xs">Cobros por medio de pago, netos de cambio.</p></div>@if (isset($record->snapshot['receipts']))<flux:button size="sm" icon="arrow-down-tray" :href="route('libreria.arqueos.summary', [$record->id, 'recibos'])" target="_blank" rel="noopener">Abrir PDF</flux:button>@else<p class="access-muted text-xs">Este cierre no conserva datos de recibos.</p>@endif</div>
                </div>
            </section>
        @endcan
        @if (isset($record->snapshot['legacy']))
            <section class="access-panel space-y-4 p-5">
                <div><h2 class="font-semibold">Arqueo conjunto V1 + V2</h2><p class="access-muted mt-1 text-xs">Importes guardados al cierre. Cada sistema mantiene su apertura independiente.</p></div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($record->snapshot['origins'] as $source => $amounts)
                        <div class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                            <p class="font-semibold">{{ strtoupper($source) }}</p>
                            <dl class="mt-3 space-y-2">
                                @foreach (['sales' => 'Ventas', 'receipts' => 'Recibos', 'collections' => 'Cobrado'] as $key => $label)
                                    <div class="flex justify-between gap-3"><dt class="access-muted">{{ $label }}</dt><dd class="tabular-nums">${{ number_format((float) $amounts[$key], 2) }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
                @foreach ($record->snapshot['legacy']['sessions'] as $turn)
                    <p class="access-muted text-xs">Turno V1 #{{ $turn['legacyId'] }} · {{ \Carbon\CarbonImmutable::parse($turn['openedAt'])->format('d/m/Y H:i:s') }} → {{ $turn['closedAt'] ? \Carbon\CarbonImmutable::parse($turn['closedAt'])->format('d/m/Y H:i:s') : 'Abierto' }}</p>
                @endforeach
            </section>
        @endif
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="access-stat"><div><p class="access-label">Total documentos</p><p class="access-stat-value mt-2">${{ number_format((float) $record->snapshot['total'], 2) }}</p></div></div>
            <div class="access-stat"><div><p class="access-label">Efectivo neto de cambio</p><p class="access-stat-value mt-2">${{ number_format((float) ($record->snapshot['methods']['01'] ?? 0), 2) }}</p></div></div>
            <div class="access-stat"><div><p class="access-label">Saldo por cobrar</p><p class="access-stat-value mt-2">${{ number_format((float) $record->snapshot['receivable'], 2) }}</p></div></div>
        </div>
        <div class="grid items-start gap-5 xl:grid-cols-2">
            @if (($record->snapshot['version'] ?? 1) >= 2)
                <section class="access-panel p-5 xl:col-span-2"><h2 class="font-semibold">Cobros por recibo</h2><dl class="mt-3 flex flex-wrap gap-6 text-sm"><div><dt class="access-muted">Total recibos</dt><dd class="mt-1 font-semibold tabular-nums">${{ number_format((float) ($record->snapshot['receiptTotal'] ?? 0), 2) }}</dd></div><div><dt class="access-muted">Ingresos netos de todos los medios</dt><dd class="mt-1 font-semibold tabular-nums">${{ number_format((float) ($record->snapshot['collections'] ?? 0), 2) }}</dd></div></dl><p class="access-muted mt-3 text-xs">Los abonos a CXC se muestran como cobros, separados de la venta que originó la deuda.</p></section>
            @endif
            <section class="access-panel space-y-5 p-5">
                <div><h2 class="font-semibold">1. Completar y entregar Excel</h2><p class="access-muted mt-2 text-xs leading-6">Descarga el original y completa efectivo contado, remesas y observaciones. Conserva las hojas y los totales del sistema.</p></div>
                @can('libreria.arqueos.descargar')<flux:button :href="route('libreria.arqueos.download', [$record->id, 'original'])" icon="arrow-down-tray">Descargar original</flux:button>@endcan
                @if ($record->firmado_at === null)
                    @can('libreria.arqueos.cargar')
                        <p class="access-muted text-xs leading-5">Para más cheques, inserta filas en Excel justo antes del TOTAL de Cheques propios o Cheques ajenos. Puedes editar sus sumas como importe o usar SUMA sobre las filas de ese bloque. Las celdas inferiores cambiarán de número al insertar filas.</p>
                        @if ($record->original_path !== null)
                            <div class="space-y-2 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                <p class="text-sm font-medium">¿Descargaste una plantilla anterior?</p>
                                <p class="access-muted text-xs leading-5">Actualiza las fórmulas y los campos de cheques. Se conservan las versiones anteriores; la plantilla actualizada requiere una nueva entrega y revisión. Los cambios que solo tengas en tu computadora debes copiarlos al archivo nuevo.</p>
                                <form method="POST" action="{{ route('libreria.arqueos.template', $record->id) }}">
                                    @csrf
                                    <flux:button type="submit" size="sm">Actualizar plantilla</flux:button>
                                </form>
                            </div>
                        @endif
                        <form method="POST" action="{{ route('libreria.arqueos.upload', $record->id) }}" enctype="multipart/form-data" class="space-y-4 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                            @csrf
                            <label class="block text-sm font-medium" for="cash-workbook">Excel completado · máximo 10 MB</label>
                            <input id="cash-workbook" type="file" name="archivo" accept=".xlsx" required class="w-full min-w-0 rounded-lg border border-zinc-300 p-3 text-xs dark:border-zinc-700" />
                            <flux:button type="submit" variant="primary">Entregar Excel</flux:button>
                        </form>
                    @endcan
                @endif
                <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <h3 class="text-sm font-semibold">Versiones conservadas</h3>
                    @foreach ($record->archivos ?? [] as $index => $file)
                        @if ($file['tipo'] !== 'firma')
                            <div class="flex flex-wrap items-center justify-between gap-3 text-xs"><div><span class="access-badge">{{ ucfirst($file['tipo']) }}</span><span class="access-muted ms-2">{{ \Carbon\CarbonImmutable::parse($file['fecha'])->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') }}</span></div>@can('libreria.arqueos.descargar')<a class="underline underline-offset-4" href="{{ route('libreria.arqueos.download', ['reconciliation' => $record->id, 'kind' => $file['tipo'], 'version' => $index]) }}">Descargar versión</a>@endcan</div>
                        @endif
                    @endforeach
                    @if (empty($record->archivos))<p class="access-muted text-xs">El archivo original se preparará cuando lo descargues.</p>@endif
                </div>
            </section>
            <section class="access-panel space-y-5 p-5">
                <div><h2 class="font-semibold">2. Revisión y firma</h2><p class="access-muted mt-2 text-xs leading-6">Revisa el Excel entregado antes de firmarlo. Una nueva entrega requiere otra revisión.</p></div>
                @if ($record->firmado_at)
                    <flux:callout variant="success"><flux:callout.heading>Arqueo firmado</flux:callout.heading><flux:callout.text>{{ $record->firmado_nombre }} · Empleado {{ $record->firmado_empleado }}<br>{{ $record->firmado_at->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') }}</flux:callout.text></flux:callout>
                    @can('libreria.arqueos.descargar')<flux:button :href="route('libreria.arqueos.download', [$record->id, 'firmado'])" icon="arrow-down-tray">Descargar firmado</flux:button>@endcan
                @elseif ($record->entregado_path === null)
                    <p class="access-muted rounded-xl border border-dashed border-zinc-300 p-5 text-sm dark:border-zinc-700">Pendiente de recibir el Excel completado.</p>
                @else
                    @can('libreria.arqueos.descargar')<flux:button :href="route('libreria.arqueos.download', [$record->id, 'entregado'])" icon="arrow-down-tray">Descargar entregado</flux:button>@endcan
                    @if ($record->revisado_at === null)
                        @can('libreria.arqueos.revisar')<form method="POST" action="{{ route('libreria.arqueos.review', $record->id) }}">@csrf<input type="hidden" name="version" value="{{ hash('sha256', $record->entregado_path) }}" /><flux:button type="submit" variant="primary">Marcar como revisado</flux:button></form>@endcan
                    @else
                        <p class="access-muted text-xs">Revisión registrada el {{ $record->revisado_at->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') }}.</p>
                        @can('libreria.arqueos.firmar')
                            <form method="POST" action="{{ route('libreria.arqueos.sign', $record->id) }}" x-data="cashSignature()" x-on:submit="prepare($event)" class="space-y-4">
                                @csrf
                                <label class="block text-sm font-medium">Firma del responsable de revisión</label>
                                <canvas x-ref="canvas" width="720" height="240" class="w-full rounded-xl border border-zinc-300 bg-white touch-none" aria-label="Área para dibujar la firma" x-on:pointerdown="start($event)" x-on:pointermove="move($event)" x-on:pointerup="stop()" x-on:pointercancel="stop()"></canvas>
                                <input type="hidden" name="firma" x-ref="signature" /><input type="hidden" name="version" value="{{ hash('sha256', $record->entregado_path) }}" />
                                <p x-show="error" x-text="error" class="text-xs text-red-600" role="alert"></p>
                                <flux:button type="button" size="sm" variant="ghost" x-on:click="clear()">Limpiar firma</flux:button>
                                <flux:checkbox name="confirmed" value="1" label="Confirmo la revisión y firma de esta versión del arqueo." required />
                                <flux:button type="submit" variant="primary" x-bind:disabled="!hasInk">Firmar y proteger Excel</flux:button>
                            </form>
                        @endcan
                    @endif
                @endif
            </section>
        </div>
        <section class="access-panel overflow-hidden">
            <div class="access-panel-heading"><h2 class="font-semibold">Movimientos al cierre</h2><span class="access-badge">{{ count($record->snapshot['sales']) }} documentos</span></div>
            <div class="overflow-x-auto"><table class="access-user-table"><thead><tr><th>Documento</th><th>Cliente / operador</th><th>Estado DTE al cierre</th><th>Total</th></tr></thead><tbody>
                @forelse ($record->snapshot['sales'] as $sale)<tr><td class="max-w-64 break-all font-mono text-[10px]">@if (isset($record->snapshot['legacy']))<span class="access-badge mb-1">{{ strtoupper($sale['source'] ?? 'v2') }}</span><br>@endif{{ $sale['number'] }}</td><td><p>{{ $sale['client'] }}</p><p class="access-muted mt-1 text-xs">{{ $sale['operator'] }}</p></td><td>{{ $sale['dteState'] }}</td><td class="tabular-nums">${{ number_format((float) $sale['total'], 2) }}</td></tr>@empty<tr><td colspan="4" class="access-muted py-8! text-center">La sesión no registró ventas vigentes.</td></tr>@endforelse
            </tbody></table></div>
        </section>
        @if (! empty($record->snapshot['receipts']))
            <section class="access-panel overflow-hidden"><div class="access-panel-heading"><h2 class="font-semibold">Recibos al cierre</h2><span class="access-badge">{{ count($record->snapshot['receipts']) }} recibos</span></div><div class="overflow-x-auto"><table class="access-user-table"><thead><tr><th>Recibo</th><th>Destinatario / operador</th><th>Estado al cierre</th><th>Total</th></tr></thead><tbody>@foreach ($record->snapshot['receipts'] as $receipt)<tr><td class="font-mono text-xs">{{ $receipt['number'] }}</td><td><p>{{ $receipt['client'] }}</p><p class="access-muted mt-1 text-xs">{{ $receipt['operator'] }}</p></td><td>{{ $receipt['state'] }}</td><td class="tabular-nums">${{ number_format((float) $receipt['total'], 2) }}</td></tr>@endforeach</tbody></table></div></section>
        @endif
    </div>
</x-layouts::app>
