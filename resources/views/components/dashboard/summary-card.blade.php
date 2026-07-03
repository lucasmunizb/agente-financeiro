@props([
    'label', // rótulo do card (ex.: "Disponível do mês")
    'value', // valor JÁ formatado em pt-BR pelo backend (regra 5); a tela só exibe
    'tone' => 'default', // cor do valor: default | primary (positivo) | ocre (a vencer)
    'sub' => null, // linha secundária opcional (ex.: "Nubank · fecha 28 de junho")
])

{{-- Card de resumo (spec FE §4.6). O valor chega pronto do backend — a UI nunca
     calcula dinheiro (regra 4). Slot opcional `badge` para o canto superior direito
     (ex.: variação vs. mês anterior, contagem de contas). --}}
@php
    $valorCor = match ($tone) {
        'primary' => 'text-primary',
        'ocre' => 'text-ocre',
        default => 'text-on-surface',
    };
@endphp

<div class="notebook-card flex h-32 flex-col justify-between rounded-card p-6">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">{{ $label }}</p>
            @if ($sub)
                <p class="mt-0.5 truncate font-body-sm text-[12px] text-on-surface-variant/70">{{ $sub }}</p>
            @endif
        </div>
        @isset($badge)
            <div class="shrink-0">{{ $badge }}</div>
        @endisset
    </div>
    <p class="font-value-display text-value-display text-right {{ $valorCor }}">{{ $value }}</p>
</div>
