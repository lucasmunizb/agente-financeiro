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
    'recorrente' => false, // é uma cobrança de recorrência → ícone de repetição (spec 12)
    'prevista' => false, // PROJEÇÃO: competência ainda não gerada (mês futuro, spec 12)
    'pagarUrl' => null,     // linha pagável (fora de cartão, em aberto) → "marcar como paga"
    'desmarcarUrl' => null, // linha JÁ paga → desfazer a marcação (clique errado tem conserto)
    'exigeDataPagamento' => false, // parcela de lançamento pede a data; ocorrência não
    'hojeIso' => null,      // AAAA-MM-DD (vem do backend, regra 4) — valor padrão do campo
    'competencia' => null,  // AAAA-MM da conta fixa ainda PREVISTA (o molde + o mês é o alvo)
    // Ocorrência de recorrência: dados JÁ formatados pelo backend para o form de edição
    // "só este mês" — ['url', 'descricao', 'valor', 'vencimento'] ou null.
    'editarOcorrencia' => null,
])

@php
    // Selo. A OCORRÊNCIA real já chega com o seu status derivado por data
    // (pago | previsto | atraso) — usa-se como está. Só a PROJEÇÃO precisa de tradução: ela
    // vem como "a_vencer" e é exibida como "Previsto", porque ainda não é uma cobrança
    // gerada — a competência dela nem existe no banco (spec 12).
    $selo = $prevista ? 'previsto' : $status;

    // Id estável e único por linha para amarrar cada gatilho ao seu <dialog>. Sai das URLs
    // de ação (já opacas) — nada de id real vazando para o DOM.
    $uid = substr(sha1(($pagarUrl ?? '').'|'.($desmarcarUrl ?? '').'|'.($editarOcorrencia['url'] ?? '').'|'.$descricao), 0, 10);
    $acaoUrl = $pagarUrl ?? $desmarcarUrl;
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
                <x-icon name="refresh-cw" class="h-3.5 w-3.5 shrink-0 text-nevoa" title="Cobrança recorrente" />
                <span class="sr-only">recorrente</span>
            @endif
        </span>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            @if ($categoria)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-label-sm text-on-surface-variant">
                    <span @class(['h-2 w-2 shrink-0 rounded-full', \App\Domain\Categoria\PaletaDeCategoria::classe($categoria['cor'] ?? null)])></span>
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
        {{-- Ações da linha: até DOIS botões (decisão do usuário 2026-07-21) — o de dinheiro
             ("marcar paga" OU "desmarcar", nunca os dois) e o de editar. Cada um abre um
             MODAL (<dialog> nativo, decisão do usuário 2026-07-21): o painel de <details>
             que havia aqui era recortado pelo card da lista e ficava atrás da linha de
             baixo, e abrir um não fechava o outro. O modal vai para a top layer — não é
             recortado, só um fica aberto por vez, e foco preso/Esc vêm do elemento. Os
             gatilhos ficam ACIMA do link esticado (z-10) para não aninhar âncoras. Cartão
             não chega aqui: a fatura é quem quita (§4.3 / D3). --}}
        @if ($acaoUrl)
            <button type="button" data-dialog-open="acao-{{ $uid }}"
                class="relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $pagarUrl ? 'text-primary hover:bg-primary-container/10' : 'text-outline hover:bg-surface-container-high hover:text-on-surface' }}"
                aria-label="{{ $pagarUrl ? 'Marcar '.$descricao.' como paga' : 'Desmarcar o pagamento de '.$descricao }}"
                title="{{ $pagarUrl ? 'Marcar como paga' : 'Desmarcar pagamento' }}">
                <x-icon :name="$pagarUrl ? 'check' : 'rotate-ccw'" class="h-4 w-4" />
            </button>

            <dialog id="acao-{{ $uid }}" class="modal-dialog" data-dialog aria-labelledby="acao-{{ $uid }}-title">
                <div class="modal-card-enter notebook-card flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-card text-left">
                    <header class="flex items-center justify-between border-b border-linha px-gutter py-4">
                        <h2 id="acao-{{ $uid }}-title" class="font-headline-md text-headline-md text-on-surface">
                            {{ $pagarUrl ? 'Marcar como paga' : 'Desmarcar pagamento' }}
                        </h2>
                        <button type="button" data-dialog-close aria-label="Fechar"
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus-visible:ring-2 focus-visible:ring-primary active:scale-95">
                            <x-icon name="x" class="h-5 w-5" />
                        </button>
                    </header>

                    {{-- Prévia do que será gravado (regra 7): o quê e por quanto, não só
                         "tem certeza?". O valor já vem formatado do backend (regra 4/5). --}}
                    <form method="POST" action="{{ $acaoUrl }}" class="flex min-h-0 flex-col gap-4 overflow-y-auto px-gutter py-5">
                        @csrf
                        <div class="flex items-baseline justify-between gap-4 rounded-control bg-surface-container px-4 py-3">
                            <span class="min-w-0 truncate font-body-md text-body-md text-on-surface">{{ $descricao }}</span>
                            <span class="shrink-0 font-value-label text-value-label font-semibold text-on-surface">{{ $valor }}</span>
                        </div>

                        <p class="font-body-sm text-body-sm text-on-surface-variant">
                            @if ($pagarUrl)
                                Ela sai das contas a pagar do mês e passa a contar como paga.
                            @else
                                Ela volta a contar como conta a pagar do mês.
                            @endif
                        </p>

                        @if ($pagarUrl && $competencia !== null)
                            {{-- Linha prevista: a conta fixa daquele mês ainda não existe no banco.
                                 O mês vai junto para o domínio materializar exatamente a competência
                                 que está na tela — nunca outra. --}}
                            <input type="hidden" name="competencia" value="{{ $competencia }}" />
                        @endif

                        @if ($pagarUrl && $exigeDataPagamento)
                            {{-- Parcela de lançamento guarda QUANDO foi paga (a ocorrência de
                                 recorrência não pede: ela registra o instante da confirmação). --}}
                            <label class="flex flex-col gap-1.5 font-body-sm text-body-sm text-on-surface-variant">
                                Quando você pagou?
                                <input type="date" name="data_pagamento" value="{{ $hojeIso }}" max="{{ $hojeIso }}" required
                                    class="input-field h-12 w-full rounded-lg px-4 font-value-label text-value-label text-on-surface" />
                            </label>
                        @endif

                        <div class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end">
                            {{-- Um foco só: a confirmação é o botão cheio, cancelar recua para
                                 texto. Com os dois contornados, nada dizia qual era a ação. --}}
                            <button type="button" data-dialog-close
                                class="inline-flex h-12 items-center justify-center rounded-control px-4 font-body-sm text-body-sm font-semibold text-on-surface-variant transition-colors hover:bg-surface-container-high focus:outline-none focus-visible:ring-2 focus-visible:ring-primary active:scale-95">
                                Cancelar
                            </button>
                            <button type="submit"
                                class="inline-flex h-12 items-center justify-center rounded-control bg-primary px-5 font-body-sm text-body-sm font-semibold text-on-primary transition-colors hover:bg-primary-container focus:outline-none focus-visible:ring-2 focus-visible:ring-primary active:scale-95">
                                {{ $pagarUrl ? 'Confirmar pagamento' : 'Desmarcar pagamento' }}
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        @if ($editarUrl)
            <a href="{{ $editarUrl }}"
                class="relative z-10 -mr-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-outline transition-colors hover:bg-surface-container-high hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                aria-label="Editar {{ $descricao }}">
                <x-icon name="pencil" class="h-4 w-4" />
            </a>
        @elseif ($editarOcorrencia)
            {{-- Ocorrência de recorrência: edição do escopo "só este mês" (spec 12) direto na
                 linha — ela não tem tela de detalhe. Também em modal, pelo mesmo motivo do
                 bloco acima (o painel flutuante era recortado pela lista). Os campos já vêm
                 preenchidos e formatados pelo backend; o submit é a confirmação explícita
                 (nunca auto-save, regra 7). O molde não é tocado: os meses seguintes seguem
                 como estavam. --}}
            <button type="button" data-dialog-open="editar-{{ $uid }}"
                class="relative z-10 -mr-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-outline transition-colors hover:bg-surface-container-high hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                aria-label="Editar {{ $descricao }}" title="Editar só este mês">
                <x-icon name="pencil" class="h-4 w-4" />
            </button>

            <dialog id="editar-{{ $uid }}" class="modal-dialog" data-dialog aria-labelledby="editar-{{ $uid }}-title">
                <div class="modal-card-enter notebook-card flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-card text-left">
                    <header class="flex items-center justify-between border-b border-linha px-gutter py-4">
                        <h2 id="editar-{{ $uid }}-title" class="font-headline-md text-headline-md text-on-surface">Editar só este mês</h2>
                        <button type="button" data-dialog-close aria-label="Fechar"
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus-visible:ring-2 focus-visible:ring-primary active:scale-95">
                            <x-icon name="x" class="h-5 w-5" />
                        </button>
                    </header>

                    <form method="POST" action="{{ $editarOcorrencia['url'] }}" class="flex min-h-0 flex-col gap-4 overflow-y-auto px-gutter py-5">
                        @csrf
                        @method('PUT')
                        <p class="font-body-sm text-body-sm text-on-surface-variant">
                            Muda <strong class="font-semibold text-on-surface">só este mês</strong>.
                            Os próximos seguem pela recorrência.
                        </p>
                        <label class="flex flex-col gap-1.5 font-body-sm text-body-sm text-on-surface-variant">
                            Descrição
                            <input type="text" name="descricao" value="{{ $editarOcorrencia['descricao'] }}" required maxlength="255"
                                class="input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface" />
                        </label>
                        <label class="flex flex-col gap-1.5 font-body-sm text-body-sm text-on-surface-variant">
                            Valor
                            <input type="text" name="valor" value="{{ $editarOcorrencia['valor'] }}" required inputmode="decimal"
                                class="input-field h-12 w-full rounded-lg px-4 text-right font-value-label text-value-label text-on-surface" />
                        </label>
                        <label class="flex flex-col gap-1.5 font-body-sm text-body-sm text-on-surface-variant">
                            Vencimento
                            <input type="date" name="vencimento" value="{{ $editarOcorrencia['vencimento'] }}" required
                                class="input-field h-12 w-full rounded-lg px-4 font-value-label text-value-label text-on-surface" />
                        </label>

                        <div class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end">
                            <button type="button" data-dialog-close
                                class="inline-flex h-12 items-center justify-center rounded-control px-4 font-body-sm text-body-sm font-semibold text-on-surface-variant transition-colors hover:bg-surface-container-high focus:outline-none focus-visible:ring-2 focus-visible:ring-primary active:scale-95">
                                Cancelar
                            </button>
                            <button type="submit"
                                class="inline-flex h-12 items-center justify-center rounded-control bg-primary px-5 font-body-sm text-body-sm font-semibold text-on-primary transition-colors hover:bg-primary-container focus:outline-none focus-visible:ring-2 focus-visible:ring-primary active:scale-95">
                                Salvar este mês
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif
    </div>
</div>
