<style>
    h1 { font-size: 10pt; text-align: center; margin: 0; }
    h2 { font-size: 10pt; text-align: center; }
    p { margin: 2px 0; }
    .center { text-align: center; }
    .small { font-size: 7pt; }
    .muted { color: #555; }
    th { border-bottom: 1px solid #333; font-size: 8pt; }
    .total { font-size: 10pt; font-weight: bold; border-top: 1px solid #333; }
</style>
<h1>COLEGIO SANTA CECILIA</h1>
<h2>{{ $summary['title'] }}</h2>
<p class="center"><strong>Caja {{ $summary['register'] }} · Sesión #{{ $summary['sessionId'] }}</strong></p>
<p class="center small">Fecha operativa: {{ $summary['date'] }}</p>
<hr>
<table width="100%" cellpadding="2">
    <tr><td width="23%" class="small">Apertura</td><td width="77%" align="right" class="small">{{ $summary['openedAt'] }}</td></tr>
    <tr><td width="23%" class="small">Cierre</td><td width="77%" align="right" class="small">{{ $summary['closedAt'] }}</td></tr>
</table>
<p class="small"><strong>Cerró:</strong> {{ $summary['closedBy'] }}</p>
<p class="small"><strong>{{ $summary['kind'] === 'ventas' ? 'Ventas' : 'Recibos' }}:</strong> {{ $summary['count'] }}</p>
@if ($summary['operators'])<p class="small"><strong>Operadores:</strong> {{ implode(', ', $summary['operators']) }}</p>@endif
<br>
<table width="100%" cellpadding="4">
    <thead><tr><th width="65%" align="left">Medio de pago</th><th width="35%" align="right">Monto USD</th></tr></thead>
    <tbody>
        @forelse ($summary['methods'] as $method)
            <tr><td width="65%">{{ $method['name'] }}</td><td width="35%" align="right">${{ number_format((float) $method['amount'], 2) }}</td></tr>
        @empty
            <tr><td colspan="2" class="small">Sin cobros registrados.</td></tr>
        @endforelse
        @if ($summary['kind'] === 'ventas' && $summary['credit'] !== '0.00')
            <tr><td width="65%">Crédito / CXC pendiente</td><td width="35%" align="right">${{ number_format((float) $summary['credit'], 2) }}</td></tr>
        @endif
        <tr><td width="65%" class="total">TOTAL {{ $summary['kind'] === 'ventas' ? 'VENTAS' : 'RECIBOS' }}</td><td width="35%" align="right" class="total">${{ number_format((float) $summary['total'], 2) }}</td></tr>
    </tbody>
</table>
@if ($summary['kind'] === 'ventas' && $summary['credit'] !== '0.00')
    <p class="small"><strong>Cobrado al emitir:</strong> ${{ number_format((float) $summary['collected'], 2) }}</p>
    <p class="small muted">El crédito pendiente no representa dinero recibido.</p>
@endif
<br>
@if ($summary['statuses'])
    <p class="small"><strong>{{ $summary['kind'] === 'ventas' ? 'Estado DTE' : 'Estado de recibos' }} al cierre</strong></p>
    @foreach ($summary['statuses'] as $status)<p class="small muted">{{ $status['name'] }}: {{ $status['count'] }}</p>@endforeach
@endif
<hr>
<p class="small muted">Efectivo neto de cambio. Todos los operadores de la sesión.</p>
<p class="small muted">{{ $summary['kind'] === 'ventas' ? 'Los abonos posteriores a CXC se incluyen en el resumen de recibos.' : 'Estos cobros no se suman otra vez como ventas.' }}</p>
<p class="center small">Arqueo #{{ $summary['reconciliationId'] }} · Importes guardados al cierre.</p>
<p class="center small muted">Consulte las incidencias posteriores en el arqueo.</p>