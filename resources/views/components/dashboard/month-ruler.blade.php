@props([
    'monthLabel' => 'Junho de 2026', // rótulo do mês já formatado
    'today' => 18, // dia de "hoje" (marcador); null se o mês exibido não é o atual
    'dueDays' => [5, 10, 20, 25, 30], // dias com vencimento (ticks ocre)
    'daysInMonth' => 30, // total de dias do mês exibido
    'availablePct' => 65, // % da faixa de "disponível" (calculada no backend)
    'prevUrl' => null, // navegação por competência (mês anterior); null desabilita
    'nextUrl' => null, // navegação por competência (mês seguinte); null desabilita
])

{{-- ELEMENTO-ASSINATURA — "A régua do mês" (spec FE §4.5). Régua horizontal do dia 1
     ao último, com o marcador de hoje (verde), ticks de vencimento (ocre) e a faixa
     do disponível. Ticks gerados server-side (bom para CLS e funciona sem JS). --}}
<section class="animate-enter overflow-hidden rounded-card border border-linha bg-surface-container-lowest p-6 shadow-sm">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-y-2">
        <div class="flex items-center gap-2">
            <a @if ($prevUrl) href="{{ $prevUrl }}" @else aria-disabled="true" tabindex="-1" @endif aria-label="Mês anterior"
                @class([
                    'rounded-full p-1 transition-colors',
                    'text-on-surface-variant hover:bg-surface-container-high focus:outline-none focus:ring-2 focus:ring-primary' => $prevUrl,
                    'pointer-events-none text-outline-variant opacity-50' => ! $prevUrl,
                ])>
                <x-icon name="chevron-left" class="h-5 w-5" />
            </a>
            <h2 class="font-headline-md text-headline-md text-on-surface">{{ $monthLabel }}</h2>
            <a @if ($nextUrl) href="{{ $nextUrl }}" @else aria-disabled="true" tabindex="-1" @endif aria-label="Próximo mês"
                @class([
                    'rounded-full p-1 transition-colors',
                    'text-on-surface-variant hover:bg-surface-container-high focus:outline-none focus:ring-2 focus:ring-primary' => $nextUrl,
                    'pointer-events-none text-outline-variant opacity-50' => ! $nextUrl,
                ])>
                <x-icon name="chevron-right" class="h-5 w-5" />
            </a>
        </div>
        <div class="flex gap-4 font-label-sm text-label-sm text-on-surface-variant">
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-ocre"></span> Vencimentos</span>
            @unless ($today === null)
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary-container"></span> Hoje</span>
            @endunless
        </div>
    </div>

    <div class="relative px-2 pb-2 pt-6">
        {{-- Faixa do "disponível" ao longo do mês (vem calculada do backend). --}}
        <div class="absolute left-2 right-2 top-8 h-1 overflow-hidden rounded-full bg-surface-container">
            <div class="h-full rounded-full bg-primary-container/25" style="width: {{ (int) $availablePct }}%"></div>
        </div>

        <div class="relative flex justify-between">
            @for ($dia = 1; $dia <= $daysInMonth; $dia++)
                @php
                    $isHoje = $dia === $today;
                    $isVenc = in_array($dia, $dueDays, true);
                    $altura = $isHoje ? 'h-6' : ($isVenc ? 'h-5' : 'h-3');
                    $cor = $isHoje ? 'bg-primary-container' : ($isVenc ? 'bg-ocre' : 'bg-outline-variant');
                    $rotuloVisivel = ($dia % 5 === 0 || $dia === 1 || $dia === $daysInMonth)
                        ? 'opacity-100'
                        : 'opacity-0 group-hover:opacity-100';
                @endphp
                <div class="group flex cursor-default flex-col items-center">
                    <div @class(['ruler-tick w-[2px] rounded-full', $altura, $cor, 'scale-x-150' => $isHoje])></div>
                    <span class="mt-2 font-value-label text-[10px] text-on-surface-variant transition-opacity {{ $rotuloVisivel }}">{{ $dia }}</span>
                </div>
            @endfor
        </div>
    </div>
</section>
