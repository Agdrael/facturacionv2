<style>
    h1 { font-size: 11pt; text-align: center; }
    h2 { font-size: 10pt; text-align: center; }
    p { margin: 2px 0; }
    .small { font-size: 7pt; }
    .center { text-align: center; }
    th { border-bottom: 1px solid #333; font-weight: bold; }
    .total { font-size: 11pt; font-weight: bold; }
</style>
<h1>{{ $emitter->nombre_comercial ?? $emitter->nombre ?? 'Colegio Santa Cecilia' }}</h1>
<p class="center small">{{ $emitter->nombre ?? '' }}</p>
<h2>RECIBO {{ $receipt['number'] }}</h2>
@if ($receipt['test'])<p class="center"><strong>MUESTRA · PRUEBAS</strong></p>@endif
@if ($receipt['annulled'])<h2>ANULADO</h2>@endif
<p class="small">{{ $receipt['date'] }} · Caja {{ $receipt['register'] }}</p>
<p><strong>Destinatario:</strong> {{ $receipt['recipient'] }}</p>
@if ($receipt['payer'])<p class="small"><strong>Recibido de:</strong> {{ $receipt['payer'] }}</p>@endif
@if ($receipt['document'] ?? null)<p class="small"><strong>Documento:</strong> {{ $receipt['document'] }}</p>@endif
@if ($receipt['email'])<p class="small">{{ $receipt['email'] }}</p>@endif
@foreach ($receipt['students'] ?? [] as $student)
<p><strong>Alumno:</strong> {{ $student['name'] ?: $student['id'] }}</p>
<p class="small"><strong>Carnet:</strong> {{ $student['id'] }} · <strong>Año académico:</strong> {{ $student['year'] }}</p>
<p class="small"><strong>Grado:</strong> {{ $student['grade'] ?: 'No disponible' }} · <strong>Sección:</strong> {{ $student['section'] ?: 'No disponible' }}</p>
@endforeach
<table width="100%" cellpadding="3">
    <thead><tr><th width="70%">Descripción</th><th width="30%" align="right">Importe</th></tr></thead>
    <tbody>
    @foreach ($receipt['lines'] as $line)
        <tr><td width="70%">{{ $line['name'] }}@if ($line['invoice'])<br><span class="small">{{ $line['invoice'] }}</span>@endif @if ($line['funding'])<br><span class="small">{{ ['normal' => 'Responsable familiar', 'beca' => 'Beca', 'bienhechor' => 'Bienhechor'][$line['funding']] ?? $line['funding'] }} · {{ $line['year'] }}</span>@endif @if (($line['payer'] ?? null) && $line['payer'] !== $receipt['payer'])<br><span class="small">{{ $line['payer'] }} {{ $line['email'] ?? '' }}</span>@endif</td><td width="30%" align="right">${{ number_format((float) $line['amount'], 2) }}</td></tr>
    @endforeach
    </tbody>
</table>
<hr>
<table width="100%" cellpadding="3">
    <tr><td class="total">TOTAL USD</td><td class="total" align="right">${{ number_format((float) $receipt['total'], 2) }}</td></tr>
    <tr><td>Recibido</td><td align="right">${{ number_format((float) $receipt['paid'], 2) }}</td></tr>
    <tr><td>Cambio</td><td align="right">${{ number_format((float) $receipt['change'], 2) }}</td></tr>
</table>
@foreach ($receipt['payments'] as $payment)
<p class="small">{{ ['01' => 'Efectivo', '02' => 'Tarjeta débito', '03' => 'Tarjeta crédito', '04' => 'Cheque', '05' => 'Transferencia'][$payment['method']] ?? 'Medio '.$payment['method'] }}: ${{ number_format((float) $payment['amount'], 2) }} @if ($payment['reference'])· {{ $payment['reference'] }}@endif</p>
@endforeach
<p class="small"><strong>Estado:</strong> {{ ['EMITIDO' => 'Emitido', 'PENDIENTE_APLICACION' => 'Pendiente de aplicación', 'REVISION' => 'Requiere revisión', 'ANULACION_PENDIENTE' => 'Anulación pendiente', 'ANULADO' => 'Anulado', 'HISTORICO' => 'Histórico'][$receipt['status']] ?? $receipt['status'] }}</p>
@if ($receipt['observation'])<p class="small">{{ $receipt['observation'] }}</p>@endif
<p class="small">Atendió: {{ $receipt['operator'] }}</p>
