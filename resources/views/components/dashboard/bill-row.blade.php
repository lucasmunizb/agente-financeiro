@props([
    'icon' => 'receipt', // nome do ícone da categoria/conta (ver componente icon)
    'iconTone' => 'primary', // primary | ocre | error — acompanha a urgência
    'title', // descrição da conta
    'due', // texto de vencimento já formatado (ex.: "vence 20 de junho")
    'value', // valor já formatado em pt-BR pelo backend
    'status' => 'a_vencer', // pago | a_vencer | atraso | cancelado
    'recorrente' => false, // nasceu de recorrência → ícone de repetição (spec 10)
    'prevista' => false, // ocorrência PROJETADA de mês futuro → selo "previsto" (spec 10b)
])

{{-- Linha de "próxima conta" / lançamento (spec FE §4.6). Estilo extrato: valor em
     mono à direita + selo de status. A ruling (borda) para 16px antes da borda do
     card, como no caderno. Nada é calculado aqui — dados chegam prontos. --}}
@php
    $iconCor = match ($iconTone) {
        'ocre' => 'text-ocre',
        'error' => 'text-error',
        default => 'text-primary',
    };
    // Recorrência ainda não paga: "previsto" (não venceu) ou "atraso" (venceu) — nunca "pago".
    $selo = $prevista ? ($status === 'atraso' ? 'atraso' : 'previsto') : $status;
@endphp

<div class="group -mx-2 flex items-center justify-between rounded-lg border-b border-linha px-2 py-4 transition-colors last:border-0 hover:bg-surface-container-lowest/60">
    <div class="flex min-w-0 items-center gap-4">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-container {{ $iconCor }}">
            <x-icon :name="$icon" class="h-5 w-5" />
        </span>
        <div class="min-w-0">
            <p class="flex items-center gap-1.5 truncate font-body-md text-body-md font-semibold text-on-surface">
                <span class="truncate">{{ $title }}</span>
                @if ($recorrente)
                    <x-icon name="refresh-cw" class="h-3.5 w-3.5 shrink-0 text-nevoa" title="Recorrente" />
                    <span class="sr-only">recorrente</span>
                @endif
            </p>
            <p class="font-body-sm text-[12px] text-on-surface-variant">{{ $due }}</p>
        </div>
    </div>
    <div class="flex shrink-0 items-center gap-3 sm:gap-6">
        <span class="font-value-label text-value-label text-on-surface">{{ $value }}</span>
        <x-ui.status-badge :status="$selo" />
    </div>
</div>
