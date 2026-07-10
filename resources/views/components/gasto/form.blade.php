@props([
    'context' => 'page', // 'modal' | 'page' — muda só o chrome (Cancelar fecha × volta)
    'mode' => 'create', // 'create' | 'edit'
    'cartoes' => null, // Collection<Card> do usuário
    'categorias' => null, // Collection<Category> não arquivadas do usuário
    'dados' => null, // array de prefill (edição) ou null (criação)
    'bloqueado' => false, // edição com parcela paga → somente leitura
    'previaUrl', // endpoint da prévia (POST)
    'submitUrl', // endpoint de gravar (POST create | PUT update)
    'submitMethod' => 'POST', // 'POST' | 'PUT' (spoof via _method no JS)
    'redirect' => null, // para onde ir após gravar (página); modal recarrega
    'cancelUrl' => null, // destino do "Cancelar" na página
])

@php
    $cartoes = $cartoes ?? collect();
    $categorias = $categorias ?? collect();
    $dados = $dados ?? [];

    // Forma inicial: prefill (edição) → senão Crédito quando há cartão, senão Pix.
    $formaInicial = $dados['forma'] ?? ($cartoes->isEmpty() ? 'pix' : 'credito');
    $ehCreditoInicial = $formaInicial === 'credito';

    $valDescricao = $dados['descricao'] ?? '';
    $valValor = $dados['valor'] ?? '';
    $valParcelas = $dados['parcelas'] ?? 1;
    $valVencimento = $dados['vencimento'] ?? '';
    $valCardId = $dados['card_id'] ?? null;
    $valCategoria = $dados['categoria_id'] ?? null;
    // Lançamento que já é recorrente (vinculado a uma recorrência): a tela mostra um QUADRO com
    // os dados da regra (dia + próxima), sem o switch — não se recorre de novo o que já é
    // recorrente. `$recData` (dia/proximaEm/ativa) vem pronto do backend (regra 4). O hidden
    // `recorrente` segue "0" (o backend ignora o switch), então não caímos no requiredIf.
    $recData = $dados['recorrencia'] ?? null;
    $recorrenteTravado = ($dados['recorrente'] ?? false) === true;
    $recAtiva = (bool) ($recData['ativa'] ?? false);

    $labelRevisar = 'Revisar e confirmar';
    $labelGravar = $mode === 'edit' ? 'Confirmar alterações' : 'Confirmar e gravar';
    $toastOk = $mode === 'edit' ? 'Lançamento atualizado' : 'Gasto registrado';

    // Ícone da categoria: mapeia o `icone` guardado (livre) para o conjunto SVG inline;
    // cai em 'tag' quando não houver correspondência (sem CDN — regra 6).
    $iconeCategoria = fn (?string $i) => match ($i) {
        'car', 'transporte' => 'car',
        'food', 'restaurant', 'alimentacao', 'utensils' => 'utensils',
        'home', 'moradia' => 'home',
        'shopping-cart', 'mercado' => 'shopping-cart',
        default => 'tag',
    };
@endphp

{{-- Formulário de gasto COMPARTILHADO (spec FE §7.7 página · §7.7b modal). Fonte única
     do formulário: o modal rápido e a página cheia renderizam este mesmo componente. Fluxo
     em dois passos (regra 7): PAINEL 1 (form) → prévia calculada pelo backend → PAINEL 2
     (confirmação) → grava. A tela nunca calcula dinheiro (regra 4); ícones SVG inline
     (regra 6). O JS é dirigido por [data-rg-root] (o mesmo para modal e página). --}}
<div data-rg-root
    data-rg-context="{{ $context }}"
    data-rg-mode="{{ $mode }}"
    data-rg-method="{{ $submitMethod }}"
    data-rg-toast="{{ $toastOk }}"
    data-previa-url="{{ $previaUrl }}"
    data-store-url="{{ $submitUrl }}"
    @if ($redirect) data-rg-redirect="{{ $redirect }}" @endif
    class="flex min-h-0 flex-1 flex-col">

    @if ($bloqueado)
        {{-- Edição travada: há parcela paga; regenerar apagaria o histórico (spec §7.8). --}}
        <div class="mx-gutter mt-gutter flex items-start gap-2 rounded-lg border border-ocre/30 bg-ocre/5 p-4">
            <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-ocre" />
            <p class="font-body-sm text-body-sm text-on-surface-variant">
                Há parcelas pagas — não é possível editar este lançamento; você pode cancelar as parcelas futuras pela lista.
            </p>
        </div>
    @endif

    {{-- ============================ PAINEL 1: FORM ============================ --}}
    <form id="rg-form" data-rg-panel="form" novalidate
        @class(['flex min-h-0 flex-1 flex-col', 'pointer-events-none opacity-60' => $bloqueado])
        @if ($bloqueado) aria-disabled="true" @endif>
        @csrf
        <input type="hidden" name="forma" value="{{ $formaInicial }}" data-rg-forma-input>
        <input type="hidden" name="categoria_id" value="{{ $valCategoria ?? '' }}" data-rg-categoria-input>

        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-gutter py-6">
            <p class="font-label-sm text-label-sm text-outline">* obrigatório</p>

            {{-- Erro geral (validações fora dos campos inline) --}}
            <p data-rg-error="geral" hidden
                class="flex items-center gap-1.5 rounded-control border border-argila/30 bg-argila/5 px-3 py-2 font-label-sm text-label-sm text-argila">
                <x-icon name="alert" class="h-4 w-4 shrink-0" /><span></span>
            </p>

            {{-- Descrição --}}
            <div class="flex flex-col gap-2">
                <label for="rg-descricao" class="font-body-sm text-body-sm text-on-surface-variant">Descrição *</label>
                <input id="rg-descricao" name="descricao" type="text" placeholder="Ex.: mercado do mês" maxlength="255"
                    value="{{ $valDescricao }}"
                    class="input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface" />
                <p data-rg-error="descricao" hidden class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                    <x-icon name="alert" class="h-4 w-4 shrink-0" /><span></span>
                </p>
            </div>

            {{-- Valor --}}
            <div class="flex flex-col gap-2">
                <label for="rg-valor" class="font-body-sm text-body-sm text-on-surface-variant">Valor *</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 font-value-label text-value-label text-on-surface-variant">R$</span>
                    <input id="rg-valor" name="valor" type="text" inputmode="decimal" placeholder="0,00"
                        value="{{ $valValor }}"
                        class="input-field h-12 w-full rounded-lg px-4 text-right font-value-label text-value-label text-on-surface" />
                </div>
                <p data-rg-error="valor" hidden class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                    <x-icon name="alert" class="h-4 w-4 shrink-0" /><span></span>
                </p>
            </div>

            {{-- Forma de pagamento (5 formas) --}}
            <div class="flex flex-col gap-2">
                <span class="font-body-sm text-body-sm text-on-surface-variant">Forma de pagamento *</span>
                <div role="group" aria-label="Forma de pagamento"
                    class="grid grid-cols-3 gap-1 rounded-lg bg-surface-container p-1 sm:grid-cols-5">
                    @foreach (['credito' => 'Crédito', 'debito' => 'Débito', 'pix' => 'Pix', 'dinheiro' => 'Dinheiro', 'boleto' => 'Boleto'] as $key => $label)
                        <button type="button" data-rg-forma="{{ $key }}"
                            aria-pressed="{{ $key === $formaInicial ? 'true' : 'false' }}"
                            @class([
                                'rounded-md py-2 font-body-sm text-body-sm font-medium transition-all',
                                'border border-linha bg-superficie text-primary shadow-sm' => $key === $formaInicial,
                                'text-on-surface-variant hover:bg-superficie/60' => $key !== $formaInicial,
                            ])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            {{-- ===== Campos só de CRÉDITO ===== --}}
            <div data-rg-group="credito" class="space-y-6" @unless ($ehCreditoInicial) hidden @endunless>
                @if ($cartoes->isEmpty())
                    <div class="flex items-start gap-2 rounded-lg border border-ocre/30 bg-ocre/5 p-4">
                        <x-icon name="credit-card" class="mt-0.5 h-5 w-5 shrink-0 text-ocre" />
                        <p class="font-body-sm text-body-sm text-on-surface-variant">
                            Você ainda não tem um cartão cadastrado. Use outra forma de pagamento ou cadastre um cartão.
                        </p>
                    </div>
                @else
                    <div class="flex flex-col gap-2">
                        <label for="rg-card" class="font-body-sm text-body-sm text-on-surface-variant">Cartão *</label>
                        <div class="relative">
                            <select id="rg-card" name="card_id"
                                class="input-field h-12 w-full cursor-pointer appearance-none rounded-lg px-4 font-value-label text-value-label text-on-surface">
                                @foreach ($cartoes as $card)
                                    <option value="{{ $card->id }}" @selected($valCardId === $card->id)>{{ $card->descricao }} •••• {{ $card->final_4 }} — fecha dia {{ $card->dia_fechamento }}</option>
                                @endforeach
                            </select>
                            <x-icon name="chevron-down" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 text-outline" />
                        </div>
                    </div>

                @endif
            </div>

            {{-- Parcelas — vale em cartão E fora de cartão (ex.: combinar pagar alguém em
                 3x via pix; §4.1/decisão 2026-07-08). O vencimento e o valor de cada parcela
                 são calculados pelo backend na revisão (regra 4). 1 = à vista. --}}
            <div class="flex flex-col gap-2">
                <label for="rg-parcelas" class="font-body-sm text-body-sm text-on-surface-variant">Parcelas</label>
                <input id="rg-parcelas" name="parcelas" type="number" inputmode="numeric" value="{{ $valParcelas }}" min="1" max="24"
                    class="input-field h-12 w-24 rounded-lg px-4 font-value-label text-value-label text-on-surface" />
                <p class="font-label-sm text-label-sm text-outline">1 = à vista. Em várias vezes, cada parcela vence +1 mês; o valor é calculado na revisão.</p>
            </div>

            {{-- ===== Campos só À VISTA (débito, pix, dinheiro, boleto) ===== --}}
            <div data-rg-group="avista" class="space-y-6" @if ($ehCreditoInicial) hidden @endif>
                <div class="flex flex-col gap-2">
                    <label for="rg-vencimento" class="font-body-sm text-body-sm text-on-surface-variant">Data de vencimento *</label>
                    <input id="rg-vencimento" name="vencimento" type="date" value="{{ $valVencimento }}"
                        class="input-field h-12 w-full rounded-lg px-4 font-body-md text-body-md text-on-surface-variant" />
                </div>

                {{-- Recorrência (§7.7 · spec 10): só fora de cartão. --}}
                @if ($recorrenteTravado)
                    {{-- Lançamento JÁ recorrente: quadro informativo com os dados da regra (dia +
                         próxima ocorrência), calculados pelo backend (regra 4). Sem switch — não se
                         recorre de novo o que já é recorrente; o alcance da edição é escolhido no
                         painel de confirmação ("só este mês" × "este e os próximos"). --}}
                    <input type="hidden" name="recorrente" value="0" data-rg-recorrente-input>
                    <div data-rg-recorrencia-quadro class="flex items-start gap-3 rounded-lg border border-cedula/30 bg-cedula/5 p-4">
                        <x-icon name="refresh-cw" class="mt-0.5 h-5 w-5 shrink-0 text-cedula" />
                        <div class="flex flex-col gap-1">
                            <p class="font-body-md text-body-md font-medium text-on-surface">Lançamento recorrente</p>
                            @if ($recAtiva)
                                <p class="font-body-sm text-body-sm text-on-surface-variant">
                                    Repete todo mês no dia {{ $recData['dia'] }}@if ($recData['proximaEm']) · próxima em {{ $recData['proximaEm'] }}@endif.
                                </p>
                            @else
                                <p class="font-body-sm text-body-sm text-on-surface-variant">
                                    A recorrência foi encerrada — este lançamento nasceu dela.
                                </p>
                            @endif
                            <a href="{{ route('recorrencias') }}" class="w-fit font-label-sm text-label-sm font-medium text-cedula hover:underline">
                                Gerenciar recorrências
                            </a>
                        </div>
                    </div>
                @else
                    {{-- Lançamento comum: ligar o switch cria uma recorrência mensal a partir do MÊS
                         SEGUINTE — este mês já é lançado como o gasto acima (sem contar em dobro). --}}
                    <div class="space-y-4">
                        <input type="hidden" name="recorrente" value="0" data-rg-recorrente-input>
                        <div class="flex items-center justify-between">
                            <span class="font-body-md text-body-md text-on-surface">Repete todo mês?</span>
                            <button type="button" data-rg-recorrencia role="switch"
                                data-rg-recorrente-lock="0" aria-checked="false"
                                aria-label="Repete todo mês?" class="rg-switch relative h-6 w-12 rounded-full transition-opacity disabled:cursor-not-allowed disabled:opacity-40">
                                <span class="rg-switch__knob absolute left-1 top-1 h-4 w-4 rounded-full bg-white"></span>
                            </button>
                        </div>
                        <p class="-mt-2 font-label-sm text-label-sm text-outline">assinaturas e contas fixas</p>
                        {{-- Parcelamento e recorrência não combinam: com 2+ parcelas o switch é
                             desligado e desabilitado pelo JS (o backend também recusa). --}}
                        <p data-rg-recorrencia-bloqueada hidden class="-mt-2 flex items-center gap-1.5 font-label-sm text-label-sm text-outline">
                            <x-icon name="alert" class="h-4 w-4 shrink-0" />
                            <span>Um lançamento parcelado não repete todo mês. Deixe em 1 parcela para ativar.</span>
                        </p>

                        {{-- Revelado quando ligado: periodicidade (só mensal no MVP) + dia do mês. --}}
                        <div data-rg-recorrencia-fields hidden class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-2">
                                <label for="rg-periodicidade" class="font-body-sm text-body-sm text-on-surface-variant">Periodicidade</label>
                                <div class="relative">
                                    <select id="rg-periodicidade" name="periodicidade"
                                        class="input-field h-12 w-full cursor-pointer appearance-none rounded-lg px-4 font-body-md text-body-md text-on-surface">
                                        <option value="mensal">mensal</option>
                                    </select>
                                    <x-icon name="chevron-down" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 text-outline" />
                                </div>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label for="rg-dia_recorrencia" class="font-body-sm text-body-sm text-on-surface-variant">Dia</label>
                                <input id="rg-dia_recorrencia" name="dia_recorrencia" type="number" inputmode="numeric" min="1" max="31" placeholder="5"
                                    class="input-field h-12 w-full rounded-lg px-4 font-value-label text-value-label text-on-surface" />
                                <p data-rg-error="dia_recorrencia" hidden class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                                    <x-icon name="alert" class="h-4 w-4 shrink-0" /><span></span>
                                </p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Categoria (opcional; chips reais do usuário) --}}
            <div class="flex flex-col gap-3">
                <span class="font-body-sm text-body-sm text-on-surface-variant">Categoria</span>
                @if ($categorias->isEmpty())
                    <p class="font-body-sm text-body-sm text-outline">Nenhuma categoria ainda — você pode organizar depois.</p>
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($categorias as $cat)
                            @php $catAtiva = $valCategoria === $cat->id; @endphp
                            <button type="button" data-rg-categoria="{{ $cat->id }}" aria-pressed="{{ $catAtiva ? 'true' : 'false' }}"
                                @class([
                                    'flex items-center gap-2 rounded-full px-4 py-2 font-body-sm text-body-sm font-medium transition-all active:scale-95',
                                    'bg-primary text-white' => $catAtiva,
                                    'border border-linha bg-surface-container text-on-surface-variant hover:bg-surface-container-high' => !$catAtiva,
                                ])>
                                @if ($cat->cor)
                                    <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $cat->cor }}"></span>
                                @endif
                                <x-icon :name="$iconeCategoria($cat->icone)" class="h-5 w-5" />
                                <span>{{ $cat->nome }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                <p data-rg-error="categoria_id" hidden class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                    <x-icon name="alert" class="h-4 w-4 shrink-0" /><span></span>
                </p>
            </div>
        </div>

        <footer class="flex flex-col gap-3 border-t border-linha bg-surface-container-low p-gutter md:flex-row-reverse">
            <button type="submit" data-rg-review @disabled($bloqueado)
                class="flex h-12 items-center justify-center gap-2 rounded-lg bg-cedula px-6 font-body-md text-body-md font-semibold text-white shadow-sm transition-all hover:bg-cedula-clara active:scale-95 disabled:cursor-not-allowed disabled:opacity-60 md:flex-none md:min-w-[180px]">
                <span class="loading-spinner" data-rg-spinner-review hidden></span>
                <span data-rg-review-label>{{ $labelRevisar }}</span>
            </button>
            @if ($context === 'modal')
                <button type="button" data-rg-close
                    class="flex h-12 items-center justify-center rounded-lg border border-cedula bg-transparent px-6 font-body-md text-body-md font-semibold text-cedula transition-all hover:bg-cedula/5 active:scale-95 md:flex-none md:min-w-[120px]">
                    Cancelar
                </button>
            @else
                <a href="{{ $cancelUrl ?? route('lancamentos') }}"
                    class="flex h-12 items-center justify-center rounded-lg border border-cedula bg-transparent px-6 font-body-md text-body-md font-semibold text-cedula transition-all hover:bg-cedula/5 active:scale-95 md:flex-none md:min-w-[120px]">
                    Cancelar
                </a>
            @endif
        </footer>
    </form>

    {{-- ========================= PAINEL 2: CONFIRMAÇÃO ========================= --}}
    <div data-rg-panel="confirm" hidden class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-gutter py-6">
            <div>
                <h3 class="font-headline-md text-headline-md text-on-surface">Confirme o lançamento</h3>
                <p class="font-body-sm text-body-sm text-outline">Nada é gravado até você confirmar. Os valores foram calculados pelo sistema.</p>
            </div>

            {{-- Aviso de possível duplicata --}}
            <div data-rg-dup hidden class="flex items-start gap-2 rounded-lg border border-ocre/30 bg-ocre/5 p-4">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-ocre" />
                <p class="font-body-sm text-body-sm text-on-surface-variant">Parece um lançamento que já existe. Confira antes de confirmar.</p>
            </div>

            {{-- Resumo --}}
            <dl class="space-y-3 rounded-lg border border-linha bg-surface-container-lowest p-4">
                <div class="flex items-center justify-between gap-4">
                    <dt class="font-body-sm text-body-sm text-outline">Descrição</dt>
                    <dd class="font-body-md text-body-md text-on-surface" data-rg-resumo="descricao"></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="font-body-sm text-body-sm text-outline">Valor total</dt>
                    <dd class="font-value-display text-value-display text-on-surface" data-rg-resumo="valorTotal"></dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="font-body-sm text-body-sm text-outline">Forma</dt>
                    <dd class="font-body-md text-body-md text-on-surface" data-rg-resumo="forma"></dd>
                </div>
                <div class="flex items-center justify-between gap-4" data-rg-resumo-linha="categoria">
                    <dt class="font-body-sm text-body-sm text-outline">Categoria</dt>
                    <dd class="font-body-md text-body-md text-on-surface" data-rg-resumo="categoria"></dd>
                </div>
            </dl>

            {{-- Prévia de parcelas (mono) — preenchida pelo backend --}}
            <div class="space-y-3 rounded-lg border border-linha bg-surface-container-lowest p-4">
                <p class="font-label-sm text-label-sm uppercase tracking-wider text-outline">Prévia — calculada pelo sistema (ainda não gravado)</p>
                <table class="w-full font-value-label text-value-label">
                    <tbody class="divide-y divide-linha text-on-surface-variant" data-rg-parcelas></tbody>
                </table>
            </div>

            {{-- Nota de recorrência (quando o switch está ligado): quando a repetição começa.
                 O mês é calculado pelo backend (regra 4); a tela só exibe. --}}
            <p data-rg-recorrencia-nota hidden
                class="flex items-start gap-2 rounded-lg border border-cedula/30 bg-cedula/5 p-4 font-body-sm text-body-sm text-on-surface-variant">
                <x-icon name="refresh-cw" class="mt-0.5 h-5 w-5 shrink-0 text-cedula" /><span></span>
            </p>

            {{-- Editar um lançamento recorrente: até onde a mudança vale (spec 10, "perguntar na
                 hora"). Os radios ficam FORA do <form> mas ligados a ele por form="rg-form", então
                 entram no FormData sem JS. Padrão "só este mês" (não reescreve a regra sem pedir).
                 O backend ignora este campo em lançamentos não recorrentes. --}}
            @if ($recorrenteTravado && $recAtiva)
                <fieldset class="space-y-3 rounded-lg border border-linha bg-surface-container-lowest p-4">
                    <legend class="flex items-center gap-1.5 font-body-sm text-body-sm text-on-surface-variant">
                        <x-icon name="refresh-cw" class="h-4 w-4 text-cedula" /> Aplicar a
                    </legend>
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="radio" name="escopo_recorrencia" value="este" form="rg-form" checked
                            class="mt-0.5 h-4 w-4 accent-cedula" />
                        <span class="font-body-sm text-body-sm text-on-surface">Só este mês
                            <span class="block font-label-sm text-label-sm text-outline">altera apenas este lançamento</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="radio" name="escopo_recorrencia" value="este_e_proximos" form="rg-form"
                            class="mt-0.5 h-4 w-4 accent-cedula" />
                        <span class="font-body-sm text-body-sm text-on-surface">Este e os próximos
                            <span class="block font-label-sm text-label-sm text-outline">atualiza a recorrência (valor, dia, descrição) para os meses futuros</span>
                        </span>
                    </label>
                </fieldset>
            @endif
        </div>

        <footer class="flex flex-col gap-3 border-t border-linha bg-surface-container-low p-gutter md:flex-row-reverse">
            <button type="button" data-rg-store
                class="flex h-12 items-center justify-center gap-2 rounded-lg bg-cedula px-6 font-body-md text-body-md font-semibold text-white shadow-sm transition-all hover:bg-cedula-clara active:scale-95 disabled:cursor-not-allowed disabled:opacity-60 md:flex-none md:min-w-[180px]">
                <span class="loading-spinner" data-rg-spinner-store hidden></span>
                <span data-rg-store-label>{{ $labelGravar }}</span>
            </button>
            <button type="button" data-rg-back
                class="flex h-12 items-center justify-center rounded-lg border border-cedula bg-transparent px-6 font-body-md text-body-md font-semibold text-cedula transition-all hover:bg-cedula/5 active:scale-95 md:flex-none md:min-w-[120px]">
                Voltar
            </button>
        </footer>
    </div>
</div>
