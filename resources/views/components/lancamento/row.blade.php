@props([
    'descricao',
    'valor',            // já formatado em pt-BR pelo backend (regra 5)
    'categoria' => null, // ['nome' => ..., 'cor' => ...] ou null
    'formaLabel' => '',  // cartão (descrição) ou forma pt-BR
    'formaIcone' => 'wallet',
    'parcela' => null,   // "2/3" ou null
    'status' => 'a_vencer', // pago | a_vencer | atraso | cancelado
    'showUrl' => null,   // detalhe do lançamento (a linha inteira abre daqui)
    'editarUrl' => null, // detalhe já com o modal de edição aberto (?editar=1)
    'recorrente' => false, // nasceu de recorrência → ícone de repetição (spec 10)
    'prevista' => false, // ocorrência de recorrência ainda NÃO paga (previsto/atraso, spec 10)
    'pagarUrl' => null, // ocorrência na fila (pendente) → botão "marcar como pago" (spec 10)
])

@php
    // Selo da ocorrência: real usa o próprio status; a recorrência ainda não paga vira
    // "previsto" (não venceu) ou "atraso" (venceu) — nunca "pago" enquanto não materializada.
    $selo = $prevista ? ($status === 'atraso' ? 'atraso' : 'previsto') : $status;
@endphp

{{-- Linha de lançamento no estilo EXTRATO (spec FE §4.6/§7.6): descrição + chip de
     categoria + forma/cartão à esquerda; valor em mono e selo de status à direita. A linha
     inteira abre o DETALHE (link esticado sobre o card); o botão "Editar" leva ao detalhe
     já com o modal de edição aberto (fica ACIMA do link esticado — z-10 — para não aninhar
     âncoras). Nada é calculado aqui — os valores chegam prontos do backend (regra 4). --}}
<div class="notebook-line relative flex items-center justify-between gap-4 px-5 py-4 transition-colors @if ($showUrl) hover:bg-surface-container-low @endif">
    @if ($showUrl)
        <a href="{{ $showUrl }}" class="absolute inset-0 z-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary" aria-label="Ver detalhe de {{ $descricao }}"></a>
    @endif

    <div class="pointer-events-none flex min-w-0 flex-col gap-1.5">
        <span class="flex items-center gap-1.5 truncate font-body-md text-body-md font-medium text-on-surface">
            <span class="truncate">{{ $descricao }}</span>
            @if ($recorrente)
                <x-icon name="refresh-cw" class="h-3.5 w-3.5 shrink-0 text-nevoa" title="Recorrente" />
                <span class="sr-only">recorrente</span>
            @endif
        </span>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            @if ($categoria)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-label-sm text-on-surface-variant">
                    <span class="h-2 w-2 shrink-0 rounded-full"
                        style="background-color: {{ $categoria['cor'] ?? '#6B6F66' }}"></span>
                    {{ $categoria['nome'] }}
                </span>
            @endif
            <span class="inline-flex items-center gap-1 font-body-sm text-[12px] text-outline">
                <x-icon :name="$formaIcone" class="h-3.5 w-3.5" />
                {{ $formaLabel }}@if ($parcela) <span class="font-value-label">({{ $parcela }})</span>@endif
            </span>
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-2">
        <div class="pointer-events-none flex flex-col items-end gap-1.5">
            <span class="font-value-label text-value-label font-semibold text-on-surface">{{ $valor }}</span>
            <x-ui.status-badge :status="$selo" />
        </div>
        @if ($pagarUrl)
            {{-- Ocorrência de recorrência na fila: "marcar como pago" com prévia + confirmação
                 embutida (regra 7, sem JS) — mesmo padrão <details> do detalhe. Fica ACIMA do
                 link esticado (z-10). Ao confirmar, materializa o lançamento pago (spec 10). --}}
            <details class="relative z-10 text-left">
                <summary class="flex h-9 w-9 cursor-pointer list-none items-center justify-center rounded-full text-primary transition-colors hover:bg-primary-container/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    aria-label="Marcar {{ $descricao }} como pago" title="Marcar como pago">
                    <x-icon name="check" class="h-4 w-4" />
                </summary>
                <form method="POST" action="{{ $pagarUrl }}"
                    class="absolute right-0 z-20 mt-2 flex min-w-[13rem] flex-col gap-2 rounded-control border border-linha bg-surface-container-lowest p-3 shadow-md">
                    @csrf
                    <p class="font-label-sm text-label-sm text-on-surface-variant">
                        Marcar <span class="text-on-surface">{{ $descricao }}</span> como pago —
                        <span class="font-value-label text-on-surface">{{ $valor }}</span>?
                    </p>
                    <button type="submit"
                        class="inline-flex h-10 items-center justify-center rounded-control bg-primary px-4 font-body-sm text-body-sm font-semibold text-on-primary transition-colors hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-primary active:scale-95">
                        Confirmar pagamento
                    </button>
                </form>
            </details>
        @elseif ($editarUrl)
            <a href="{{ $editarUrl }}"
                class="relative z-10 -mr-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-outline transition-colors hover:bg-surface-container-high hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                aria-label="Editar {{ $descricao }}">
                <x-icon name="pencil" class="h-4 w-4" />
            </a>
        @endif
    </div>
</div>
