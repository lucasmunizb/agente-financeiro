{{-- Lançamentos — lista/extrato (spec FE §7.6). Encaixa no shell padrão (aside + header +
     coluna de chat). Os valores vêm PRONTOS do backend (LancamentoController →
     ConsultarLancamentos determinístico): a UI nunca calcula dinheiro (regra 4) e só exibe
     (formatação pt-BR já feita, regra 5). Filtros são server-side (GET); nada é recalculado
     no cliente.

     Estado ($estado): 'pronto' (há lançamentos no filtro) · 'vazio' (usuário sem nenhum
     lançamento) · 'sem-resultado' (filtro não casou nada) · 'carregando' (skeleton). O
     controller decide pelos dados; ?estado= continua como afordância de revisão. --}}
@php
    $estado = $estado ?? 'pronto';
@endphp
<x-layouts.app title="Lançamentos | Agente Financeiro" active="transacoes" heading="Lançamentos" wide>
    <div class="mx-auto w-full max-w-5xl space-y-8 pb-36">
        {{-- Cabeçalho de conteúdo: intro e, abaixo, uma toolbar com o seletor de mês à
             esquerda e a ação primária à direita — aproveita bem a coluna estreita
             (o chat fixo reserva ~380px à direita). --}}
        <div class="space-y-4">
            <p class="font-body-sm text-body-sm text-on-surface-variant">
                Suas movimentações do mês, como num extrato. Os valores vêm conferidos do sistema.
            </p>
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-1 rounded-control border border-linha bg-surface-container-lowest p-1 shadow-sm">
                    <a href="{{ request()->fullUrlWithQuery(['mes' => $mesAnterior]) }}"
                        class="rounded-md p-1.5 text-on-surface-variant transition-colors hover:bg-surface-container"
                        aria-label="Mês anterior">
                        <x-icon name="chevron-left" class="h-5 w-5" />
                    </a>
                    <span class="min-w-[8.5rem] text-center font-value-label text-value-label font-medium text-on-surface sm:min-w-[9.5rem]">{{ $mesLabel }}</span>
                    <a href="{{ request()->fullUrlWithQuery(['mes' => $mesProximo]) }}"
                        class="rounded-md p-1.5 text-on-surface-variant transition-colors hover:bg-surface-container"
                        aria-label="Próximo mês">
                        <x-icon name="chevron-right" class="h-5 w-5" />
                    </a>
                </div>
                <a href="{{ route('lancamentos.create') }}"
                    class="inline-flex items-center gap-2 rounded-control bg-primary px-4 py-2.5 font-body-sm text-body-sm font-semibold text-on-primary shadow-sm transition-colors hover:bg-primary-container">
                    <x-icon name="plus" class="h-4 w-4" />
                    <span class="hidden sm:inline">Novo lançamento</span>
                    <span class="sm:hidden">Novo</span>
                </a>
            </div>
        </div>

        {{-- Painel de filtros (GET, server-side): agrupa busca, selects e status num só
             bloco calmo, separado da lista. O status é escolhido por chips que preservam
             os demais filtros. --}}
        <form method="GET" action="{{ route('lancamentos') }}"
            class="space-y-4 rounded-card border border-linha bg-surface-container-lowest p-5 shadow-sm">
            <input type="hidden" name="mes" value="{{ $mesAtual }}">
            @if ($filtros['status'])<input type="hidden" name="status" value="{{ $filtros['status'] }}">@endif

            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <label class="relative flex-grow">
                    <span class="sr-only">Buscar por descrição</span>
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-outline" />
                    <input type="text" name="busca" value="{{ $filtros['busca'] }}"
                        placeholder="Buscar descrição…"
                        class="h-11 w-full rounded-control border border-linha bg-superficie pl-10 pr-4 font-body-md text-body-md outline-none transition-colors placeholder:text-outline focus:border-primary focus:ring-1 focus:ring-primary">
                </label>

                <div class="flex flex-wrap items-center gap-2">
                    <select name="categoria"
                        class="h-11 rounded-control border border-linha bg-superficie px-3 font-body-sm text-body-sm text-on-surface-variant outline-none transition-colors focus:border-primary focus:ring-1 focus:ring-primary">
                        {{-- value criptografado (token opaco): o id real NUNCA vai na query
                             (README §"Identificadores nas URLs"). @selected compara o id já
                             decodificado pelo controller. --}}
                        <option value="">Categoria</option>
                        @foreach ($categorias as $categoria)
                            <option value="{{ $categoria->opaqueId() }}" @selected($filtros['categoria'] === $categoria->id)>{{ $categoria->nome }}</option>
                        @endforeach
                    </select>

                    <select name="forma"
                        class="h-11 rounded-control border border-linha bg-superficie px-3 font-body-sm text-body-sm text-on-surface-variant outline-none transition-colors focus:border-primary focus:ring-1 focus:ring-primary">
                        <option value="">Forma</option>
                        @foreach ($formas as $tipo => $label)
                            <option value="{{ $tipo }}" @selected($filtros['forma'] === $tipo)>{{ $label }}</option>
                        @endforeach
                    </select>

                    @if ($cartoes->isNotEmpty())
                        <select name="cartao"
                            class="h-11 rounded-control border border-linha bg-superficie px-3 font-body-sm text-body-sm text-on-surface-variant outline-none transition-colors focus:border-primary focus:ring-1 focus:ring-primary">
                            <option value="">Cartão</option>
                            @foreach ($cartoes as $cartao)
                                <option value="{{ $cartao->opaqueId() }}" @selected($filtros['cartao'] === $cartao->id)>{{ $cartao->descricao }}</option>
                            @endforeach
                        </select>
                    @endif

                    <button type="submit"
                        class="inline-flex h-11 items-center gap-2 rounded-control bg-primary px-4 font-body-sm text-body-sm font-semibold text-on-primary transition-colors hover:bg-primary-container">
                        <x-icon name="filter" class="h-4 w-4" />
                        Filtrar
                    </button>
                </div>
            </div>

            {{-- Chips de status (links que preservam os demais filtros). --}}
            <div class="flex flex-wrap items-center gap-2 border-t border-linha pt-4">
                <span class="mr-1 font-label-sm text-label-sm uppercase tracking-wider text-outline">Status</span>
                @foreach ($statusOpcoes as $valor => $label)
                    @php $ativo = $filtros['status'] === $valor; @endphp
                    <a href="{{ request()->fullUrlWithQuery(['status' => $ativo ? null : $valor]) }}"
                        @class([
                            'rounded-full px-3.5 py-1.5 font-label-sm text-label-sm font-medium transition-colors',
                            'bg-primary text-on-primary shadow-sm' => $ativo,
                            'border border-linha bg-superficie text-on-surface-variant hover:bg-surface-container-high' => !$ativo,
                        ])>{{ $label }}</a>
                @endforeach
            </div>
        </form>

        @if ($estado === 'carregando')
            {{-- Esqueleto sóbrio que espelha o extrato populado (grupos → linhas), para a
                 tela não "pular" quando os dados chegam. Sem números fantasma. --}}
            <div class="space-y-8" role="status" aria-live="polite">
                <span class="sr-only">Carregando os lançamentos…</span>
                @foreach (range(1, 2) as $g)
                    <section class="space-y-3">
                        <div class="skeleton h-5 w-40"></div>
                        <div class="overflow-hidden rounded-card border border-linha bg-surface-container-lowest">
                            @foreach (range(1, 3) as $r)
                                <div class="notebook-line flex items-center justify-between px-4 py-4">
                                    <div class="flex items-center gap-4">
                                        <div class="skeleton h-10 w-10 rounded-full"></div>
                                        <div class="space-y-2">
                                            <div class="skeleton h-4 w-48"></div>
                                            <div class="skeleton h-3 w-28"></div>
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-2">
                                        <div class="skeleton h-4 w-20"></div>
                                        <div class="skeleton h-5 w-16 rounded-full"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @elseif ($estado === 'vazio')
            {{-- Primeiro uso: convite calmo à ação. Decoração de pauta (rule-safe, via
                 token) evoca o caderno sem imagem externa (regra 6/LGPD). --}}
            <div class="relative flex flex-col items-center gap-4 overflow-hidden rounded-card border border-linha bg-surface-container-lowest px-6 py-20 text-center">
                <div class="ledger-grid pointer-events-none absolute inset-0 opacity-[0.06]"></div>
                <span class="relative flex h-24 w-24 items-center justify-center rounded-full bg-surface-container text-outline">
                    <x-icon name="receipt" class="h-11 w-11" />
                </span>
                <div class="relative space-y-2">
                    <p class="font-headline-md text-headline-md text-on-surface">Nenhum lançamento registrado ainda</p>
                    <p class="mx-auto max-w-md font-body-md text-body-md text-on-surface-variant">
                        Comece registrando seus gastos para ver seu extrato aqui — ou mande uma mensagem ao bot no Telegram e ele lança para você.
                    </p>
                </div>
                <a href="{{ route('lancamentos.create') }}"
                    class="relative inline-flex items-center gap-2 rounded-control bg-primary px-5 py-2.5 font-body-md text-body-md font-semibold text-on-primary transition-colors hover:bg-primary-container">
                    <x-icon name="plus" class="h-4 w-4" />
                    Registrar gasto
                </a>
            </div>
        @elseif ($estado === 'sem-resultado')
            {{-- Filtro não casou nada: reconforta e oferece a saída (limpar / registrar). --}}
            <div class="flex flex-col items-center gap-4 rounded-card border border-linha bg-surface-container-lowest px-6 py-20 text-center">
                <span class="flex h-24 w-24 items-center justify-center rounded-full bg-surface-container text-outline">
                    <x-icon name="search" class="h-11 w-11" />
                </span>
                <div class="space-y-2">
                    <p class="font-headline-md text-headline-md text-on-surface">Nenhum lançamento neste filtro</p>
                    <p class="mx-auto max-w-md font-body-md text-body-md text-on-surface-variant">
                        Tente ajustar os filtros ou limpar a busca para encontrar o que procura.
                    </p>
                </div>
                <div class="flex flex-col items-center gap-3 sm:flex-row">
                    <a href="{{ route('lancamentos', ['mes' => $mesAtual]) }}"
                        class="inline-flex items-center gap-2 rounded-control bg-primary px-5 py-2.5 font-body-md text-body-md font-semibold text-on-primary transition-colors hover:bg-primary-container">
                        Limpar filtros
                    </a>
                    <a href="{{ route('lancamentos.create') }}"
                        class="inline-flex items-center gap-2 rounded-control border border-primary px-5 py-2.5 font-body-md text-body-md font-semibold text-primary transition-colors hover:bg-primary/5">
                        <x-icon name="plus" class="h-4 w-4" />
                        Registrar gasto
                    </a>
                </div>
            </div>
        @else
            {{-- Lista agrupada por dia (extrato). Cada dia é uma seção com "régua"
                 (cabeçalho + hairline) para a sensação de caderno contínuo. --}}
            <div class="space-y-7">
                @foreach ($grupos as $grupo)
                    <section class="animate-enter">
                        <div class="mb-3 flex items-center gap-3 px-1">
                            <h2 class="shrink-0 font-headline-md text-headline-md text-on-surface">{{ $grupo['titulo'] }}</h2>
                            <span class="h-px flex-1 bg-linha"></span>
                        </div>
                        <div class="overflow-hidden rounded-card border border-linha bg-surface-container-lowest shadow-sm">
                            @foreach ($grupo['itens'] as $item)
                                <x-lancamento.row
                                    :descricao="$item['descricao']"
                                    :valor="$item['valor']"
                                    :categoria="$item['categoria']"
                                    :forma-label="$item['formaLabel']"
                                    :forma-icone="$item['formaIcone']"
                                    :parcela="$item['parcela']"
                                    :status="$item['status']"
                                    :recorrente="$item['recorrente']"
                                    :prevista="$item['prevista']"
                                    :show-url="$item['showUrl']"
                                    :editar-url="$item['editarUrl']"
                                    :editar-ocorrencia="$item['editarOcorrencia']"
                                    :pagar-url="$item['pagarUrl']"
                                    :desmarcar-url="$item['desmarcarUrl']"
                                    :exige-data-pagamento="$item['exigeDataPagamento']"
                                    :competencia="$item['competencia']"
                                    :hoje-iso="$hojeIso" />
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            {{-- Total exibido (rodapé): o clímax da tela. Faixa de acento à esquerda, valor
                 em destaque e a contagem (não é cálculo — vem pronta do backend, regra 4). --}}
            <div class="sticky bottom-4 z-30 overflow-hidden rounded-card border border-primary/20 bg-surface-container-lowest shadow-lg">
                <div class="flex items-center justify-between gap-4 border-l-4 border-primary px-6 py-4">
                    <div class="flex flex-col">
                        <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Total exibido</span>
                        <span class="font-body-sm text-[13px] text-outline">{{ $registros }} {{ $registros === 1 ? 'lançamento' : 'lançamentos' }}</span>
                    </div>
                    <span class="font-value-display text-[28px] font-semibold text-primary">{{ $totalExibido }}</span>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
