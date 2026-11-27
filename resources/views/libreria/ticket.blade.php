@fragment('styles')
<style>
    h1 { font-size: 11pt; text-align: center; }
    h2 { font-size: 9pt; text-align: center; }
    p { margin: 2px 0; }
    .center { text-align: center; }
    .small { font-size: 6.5pt; }
    th { font-weight: bold; border-bottom: 1px solid #333; }
    td { padding: 3px 0; }
    .total { font-size: 11pt; font-weight: bold; }
</style>
@endfragment
@php
    $id = $document['identificacion'];
    $emitter = $document['emisor'];
    $summary = $document['resumen'];
@endphp
@fragment('header')
<h1>{{ $emitter['nombreComercial'] ?? $emitter['nombre'] }}</h1>
<p class="center small">{{ $emitter['nombre'] }}</p>
<p class="center small">NIT: {{ $emitter['nit'] }} · NRC: {{ $emitter['nrc'] }}</p>
<p class="center small">{{ $emitter['direccion']['complemento'] ?? '' }}</p>
<p class="center small">{{ $emitter['telefono'] ?? '' }} · {{ $emitter['correo'] ?? '' }}</p>
<h2>{{ $id['tipoDte'] === '03' ? 'COMPROBANTE DE CRÉDITO FISCAL' : 'FACTURA' }} ELECTRÓNICA</h2>
@if ($id['ambiente'] !== '01')<p class="center"><strong>AMBIENTE DE PRUEBAS</strong></p>@endif
@if ($journal->anulado ?? false)<h2>DOCUMENTO ANULADO</h2>@endif
<p class="center small"><strong>N.º DTE:</strong><br>{{ $id['numeroControl'] }}</p>
<p class="center small"><strong>Fecha de emisión:</strong> {{ \Carbon\CarbonImmutable::parse($id['fecEmi'])->format('d/m/Y') }} {{ $id['horEmi'] ?? '' }}</p>
@endfragment
@fragment('detail')
<table width="100%" cellpadding="2">
    <thead><tr><th width="68%">Descripción</th><th width="32%" align="right">Importe</th></tr></thead>
    <tbody>
        @foreach ($document['cuerpoDocumento'] as $line)
            <tr><td width="68%">{{ $line['descripcion'] }}<br><span class="small">{{ rtrim(rtrim(number_format((float) $line['cantidad'], 2, '.', ''), '0'), '.') }} × ${{ number_format((float) $line['precioUni'], 2) }}@if ($line['montoDescu'] > 0)<br>Descuento: ${{ number_format((float) $line['montoDescu'], 2) }}@endif</span></td><td width="32%" align="right">${{ number_format((float) $line['ventaGravada'] + (float) $line['ventaExenta'] + (float) $line['ventaNoSuj'], 2) }}</td></tr>
        @endforeach
    </tbody>
</table>
<hr>
<table width="100%" cellpadding="2">
    <tr><td>Ventas gravadas</td><td align="right">${{ number_format((float) $summary['totalGravada'], 2) }}</td></tr>
    <tr><td>Ventas exentas</td><td align="right">${{ number_format((float) $summary['totalExenta'], 2) }}</td></tr>
    <tr><td>Descuentos</td><td align="right">${{ number_format((float) $summary['totalDescu'], 2) }}</td></tr>
    <tr><td>IVA{{ $id['tipoDte'] === '01' ? ' (incluido)' : '' }}</td><td align="right">${{ number_format((float) ($summary['totalIva'] ?? collect($summary['tributos'] ?? [])->where('codigo', '20')->sum('valor')), 2) }}</td></tr>
    <tr><td class="total">TOTAL USD</td><td class="total" align="right">${{ number_format((float) $summary['totalPagar'], 2) }}</td></tr>
    <tr><td>Recibido</td><td align="right">${{ number_format((float) ($payments['paid'] ?? 0), 2) }}</td></tr>
    <tr><td>Cambio</td><td align="right">${{ number_format((float) ($payments['change'] ?? 0), 2) }}</td></tr>
</table>
<p class="small"><strong>Operación:</strong> {{ match ((int) $summary['condicionOperacion']) { 2 => 'Crédito', 3 => 'Otra', default => 'Contado' } }}</p>
@foreach ($summary['pagos'] ?? [] as $payment)
    <p class="small">{{ match ($payment['codigo']) { '01' => 'Efectivo', '02' => 'Tarjeta débito', '03' => 'Tarjeta crédito', '04' => 'Cheque', '05' => 'Transferencia', null => 'Saldo a crédito', default => 'Pago '.$payment['codigo'] } }}: ${{ number_format((float) $payment['montoPago'], 2) }}@if ($payment['referencia'] ?? null) · {{ $payment['referencia'] }}@endif</p>
@endforeach
@if ($summary['observaciones'] ?? null)<p class="small"><strong>Observaciones:</strong> {{ $summary['observaciones'] }}</p>@endif
<p class="center small">Representación gráfica del DTE</p>
@endfragment
