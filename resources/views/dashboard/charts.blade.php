@php
    $palette = ['#c7a600', '#50857b', '#829bc2', '#ad7955', '#9a85b5', '#6e929e', '#b18d49', '#ab7289'];
    $barMax = max(1, ...array_values(array_map(fn ($box) => (float) $box['collections'], $data['boxes'])));
    $dailyMax = max(1, ...array_map(fn ($day) => max((float) $day['saleCollected'], (float) $day['receiptCollected']), $data['days']));
    $dayCount = count($data['days']);
    $coordinates = function ($key) use ($data, $dailyMax, $dayCount, $partial) {
        $segments = [[]];
        foreach ($data['days'] as $i => $day) {
            if ($partial && ! $day['hasVerified']) {
                $segments[] = [];
                continue;
            }
            $segments[array_key_last($segments)][] = round(40 + ($dayCount === 1 ? 138 : 276 * $i / ($dayCount - 1)), 2).','.round(170 - 130 * (float) $day[$key] / $dailyMax, 2);
        }
        return array_map(fn ($segment) => implode(' ', $segment), array_filter($segments));
    };
@endphp
<div class="grid items-stretch gap-6 xl:grid-cols-3">
    <section class="access-panel overflow-hidden">
        <div class="access-panel-heading"><h2 class="font-semibold">Cobros por caja</h2><span class="access-badge">DTE + recibos</span></div>
        <div class="p-5">
            <svg viewBox="0 0 360 230" role="img" aria-label="Cobros por caja en el período seleccionado" class="w-full">
                @foreach ($data['boxes'] as $point => $box)
                    @php
                        $y = 22 + $loop->index * 52;
                        $saleWidth = 200 * (float) $box['saleCollected'] / $barMax;
                        $receiptWidth = 200 * (float) $box['receiptCollected'] / $barMax;
                        $barUnknown = ! $box['hasVerified'] && ($box['coverage']['partial'] || ! $box['complete'] || ! $data['receiptAvailable']);
                    @endphp
                    <g wire:key="bar-{{ $point }}">
                        <text x="0" y="{{ $y + 13 }}" fill="currentColor" font-size="11">{{ $point }}</text>
                        <rect x="44" y="{{ $y }}" width="200" height="19" rx="4" fill="var(--access-subtle)" />
                        @if (! $barUnknown)
                            <rect x="44" y="{{ $y }}" width="{{ round($saleWidth, 3) }}" height="19" fill="#c7a600"><title>{{ $point }} · DTE: {{ $money($box['saleCollected']) }}</title></rect>
                            <rect x="{{ round(44 + $saleWidth, 3) }}" y="{{ $y }}" width="{{ round($receiptWidth, 3) }}" height="19" fill="#50857b"><title>{{ $point }} · Recibos: {{ $money($box['receiptCollected']) }}</title></rect>
                        @endif
                        <text x="354" y="{{ $y + 13 }}" text-anchor="end" fill="currentColor" font-size="11">{{ $barUnknown ? '—' : $money($box['collections']) }}</text>
                    </g>
                @endforeach
            </svg>
            <p class="access-muted text-xs"><span class="mr-1 inline-block size-2 rounded-sm bg-[#c7a600]"></span> DTE <span class="ml-4 mr-1 inline-block size-2 rounded-sm bg-[#50857b]"></span> Recibos</p>
        </div>
    </section>
    <section class="access-panel overflow-hidden">
        <div class="access-panel-heading"><h2 class="font-semibold">Evolución diaria</h2><span class="access-badge">Cobros</span></div>
        <div class="p-5">
            @if ($s['hasVerified'])
                <svg viewBox="0 0 360 230" role="img" aria-label="Evolución diaria del dinero cobrado por DTE y recibos" class="w-full">
                    @foreach ([0, 0.5, 1] as $fraction)
                        <line x1="40" x2="316" y1="{{ 170 - 130 * $fraction }}" y2="{{ 170 - 130 * $fraction }}" stroke="var(--access-border)" stroke-dasharray="3 5" />
                        <text x="36" y="{{ 174 - 130 * $fraction }}" text-anchor="end" fill="var(--access-muted)" font-size="9">{{ number_format($dailyMax * $fraction, 0) }}</text>
                    @endforeach
                    @foreach (['saleCollected' => '#c7a600', 'receiptCollected' => '#50857b'] as $key => $color)
                        @foreach ($coordinates($key) as $segment)
                            <polyline points="{{ $segment }}" fill="none" stroke="{{ $color }}" stroke-width="3" stroke-linejoin="round" />
                        @endforeach
                        @foreach ($data['days'] as $index => $day)
                            @if ($partial && ! $day['hasVerified']) @continue @endif
                            <circle cx="{{ 40 + ($dayCount === 1 ? 138 : 276 * $index / ($dayCount - 1)) }}" cy="{{ 170 - 130 * (float) $day[$key] / $dailyMax }}" r="{{ $dayCount > 31 ? 2 : 3 }}" fill="{{ $color }}">
                                <title>{{ $day['date'] }} · {{ $key === 'saleCollected' ? 'DTE' : 'Recibos' }}: {{ $money($day[$key]) }}{{ $day['issues'] ? ' · datos incompletos' : '' }}</title>
                            </circle>
                        @endforeach
                    @endforeach
                    <text x="40" y="200" fill="var(--access-muted)" font-size="10">{{ \Carbon\CarbonImmutable::parse($data['days'][0]['date'])->format('d/m/Y') }}</text>
                    <text x="316" y="200" text-anchor="end" fill="var(--access-muted)" font-size="10">{{ \Carbon\CarbonImmutable::parse($data['to'])->format('d/m/Y') }}</text>
                </svg>
            @else
                <div class="access-muted flex aspect-[360/230] items-center justify-center text-sm">{{ $partial ? 'Sin cobros verificables para graficar.' : 'Sin cobros en el período.' }}</div>
            @endif
            <p class="access-muted text-xs"><span class="mr-1 inline-block size-2 rounded-sm bg-[#c7a600]"></span> DTE <span class="ml-4 mr-1 inline-block size-2 rounded-sm bg-[#50857b]"></span> Recibos</p>
        </div>
    </section>
    <section class="access-panel overflow-hidden">
        <div class="access-panel-heading"><h2 class="font-semibold">Medios de pago</h2><span class="access-badge">Distribución</span></div>
        <div class="p-5">
            @if ((float) $s['collections'] > 0)
                @php
                    $offset = 0;
                @endphp
                <svg viewBox="0 0 360 230" role="img" aria-label="Distribución de cobros por medio de pago" class="mx-auto w-full">
                    <circle cx="180" cy="112" r="73" fill="none" stroke="var(--access-subtle)" stroke-width="23" />
                    @foreach ($s['methods'] as $code => $method)
                        @php
                            $length = 458.672527 * (float) $method['total'] / (float) $s['collections'];
                            $color = $palette[$loop->index % count($palette)];
                        @endphp
                        <circle wire:key="slice-{{ $code }}" cx="180" cy="112" r="73" fill="none" stroke="{{ $color }}" stroke-width="23" stroke-dasharray="{{ $length }} {{ 458.672527 - $length }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 180 112)">
                            <title>{{ $method['label'] }}: {{ $money($method['total']) }}</title>
                        </circle>
                        @php
                            $offset += $length;
                        @endphp
                    @endforeach
                    <text x="180" y="109" text-anchor="middle" fill="currentColor" font-size="20" font-weight="600">{{ $money($s['collections']) }}</text>
                    <text x="180" y="130" text-anchor="middle" fill="var(--access-muted)" font-size="11">Total cobrado</text>
                </svg>
                <ul class="grid gap-2 text-xs">
                    @foreach ($s['methods'] as $code => $method)
                        <li class="flex items-center justify-between gap-3" wire:key="legend-{{ $code }}"><span class="flex items-center gap-2"><span class="inline-block size-2 shrink-0 rounded-sm" style="background: {{ $palette[$loop->index % count($palette)] }}"></span>{{ $method['label'] }}</span><span class="tabular-nums">{{ $money($method['total']) }}</span></li>
                    @endforeach
                </ul>
            @else
                <div class="access-muted flex aspect-[360/230] items-center justify-center text-sm">{{ $partial ? 'Sin cobros verificables para graficar.' : 'Sin cobros en el período.' }}</div>
            @endif
        </div>
    </section>
</div>
@if ($partial)
    <p class="access-muted -mt-3 text-xs">Los gráficos representan únicamente los importes verificados. Los días sin datos verificables se dejan sin trazo.</p>
@endif
