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
])

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
        <span class="truncate font-body-md text-body-md font-medium text-on-surface">{{ $descricao }}</span>
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
            <x-ui.status-badge :status="$status" />
        </div>
        @if ($editarUrl)
            <a href="{{ $editarUrl }}"
                class="relative z-10 -mr-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-outline transition-colors hover:bg-surface-container-high hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                aria-label="Editar {{ $descricao }}">
                <x-icon name="pencil" class="h-4 w-4" />
            </a>
        @endif
    </div>
</div>
