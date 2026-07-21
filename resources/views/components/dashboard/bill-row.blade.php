@props([
    'icon' => 'receipt', // nome do ícone da categoria/conta (ver componente icon)
    'iconTone' => 'primary', // primary | ocre | error — acompanha a urgência
    'title', // descrição da conta
    'due', // texto de vencimento já formatado (ex.: "vence 20 de junho")
    'value', // valor já formatado em pt-BR pelo backend
    'status' => 'a_vencer', // pago | a_vencer | atraso | cancelado
    'recorrente' => false, // nasceu de recorrência → ícone de repetição (spec 10)
    'prevista' => false, // ocorrência PROJETADA de mês futuro → selo "previsto" (spec 10b)
    'itens' => 1, // nº de cobranças condensadas na linha (fatura de cartão); 1 = linha simples
    'pagarUrl' => null, // conta pagável (fora de cartão) → "marcar como paga" aqui mesmo
    'editarUrl' => null, // detalhe do lançamento já com o modal de edição aberto
    'exigeDataPagamento' => false, // parcela pede a data do pagamento; ocorrência não
    'hojeIso' => null, // AAAA-MM-DD vindo do backend (regra 4) — padrão do campo de data
    'competencia' => null, // AAAA-MM da conta fixa ainda PREVISTA (o molde + o mês é o alvo)
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
    // Sem ação possível (fatura de cartão — quem quita é o pagamento da fatura, §4.3) a linha
    // continua sendo só leitura: nada de afordância que não leva a lugar nenhum.
    $temAcoes = $pagarUrl !== null || $editarUrl !== null;
@endphp

{{-- A tag do invólucro é dinâmica (`details` quando há ação, `div` quando é só leitura) para
     não duplicar a linha inteira em dois ramos do template. --}}
<{{ $temAcoes ? 'details' : 'div' }} class="group -mx-2 block border-b border-linha px-2 last:border-0">
    {{-- Com ações, a linha inteira é o gatilho de divulgação progressiva: em vez de dois
         ícones de 36px espremidos no fim da linha (ruins no toque e ilegíveis a 360px), ela
         abre revelando as ações com rótulo. E o painel fica NO FLUXO, empurrando as linhas de
         baixo — dentro da caixa de rolagem do quadro, um painel flutuante seria recortado sem
         o usuário ter como alcançá-lo. HTML nativo: sem uma linha de JS. --}}
    <{{ $temAcoes ? 'summary' : 'div' }} @class([
        '-mx-2 flex items-center justify-between rounded-lg px-2 py-4 transition-colors hover:bg-surface-container-lowest/60',
        'cursor-pointer list-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary [&::-webkit-details-marker]:hidden' => $temAcoes,
    ])>
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
                <p class="font-body-sm text-[12px] text-on-surface-variant">
                    {{ $due }}@if ($itens > 1) · {{ $itens }} {{ $itens === 1 ? 'compra' : 'compras' }}@endif
                </p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-3 sm:gap-4">
            <span class="font-value-label text-value-label text-on-surface">{{ $value }}</span>
            <x-ui.status-badge :status="$selo" />
            @if ($temAcoes)
                <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-outline transition-transform group-open:rotate-180" />
                <span class="sr-only">ações desta conta</span>
            @endif
        </div>
    </{{ $temAcoes ? 'summary' : 'div' }}>

    @if ($temAcoes)
        {{-- Confirmação com prévia antes de gravar (regra 7): diz o que será marcado e por
             quanto, não só "tem certeza?". Os quadros só listam o que FALTA pagar, então não há
             "desmarcar" aqui; isso vive no extrato. --}}
        <div class="flex flex-col gap-3 pb-4 pl-14 sm:flex-row sm:items-end">
            @if ($pagarUrl)
                <form method="POST" action="{{ $pagarUrl }}" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    @csrf
                    @if ($competencia !== null)
                        {{-- Linha prevista: a conta fixa daquele mês ainda não existe no banco.
                             O mês vai junto para o domínio materializar exatamente a competência
                             que está na tela — nunca outra. --}}
                        <input type="hidden" name="competencia" value="{{ $competencia }}" />
                    @endif
                    @if ($exigeDataPagamento)
                        <label class="flex flex-col gap-1 font-label-sm text-label-sm text-on-surface-variant">
                            Quando você pagou?
                            <input type="date" name="data_pagamento" value="{{ $hojeIso }}" max="{{ $hojeIso }}" required
                                class="h-11 rounded-control border border-linha bg-surface-container-lowest px-2 font-value-label text-value-label text-on-surface focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        </label>
                    @endif
                    <button type="submit"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-control bg-primary px-4 font-body-sm text-body-sm font-semibold text-on-primary transition-colors hover:bg-primary-container focus:outline-none focus-visible:ring-2 focus-visible:ring-primary active:scale-95">
                        <x-icon name="check" class="h-4 w-4" />
                        Marcar como paga — {{ $value }}
                    </button>
                </form>
            @endif

            @if ($editarUrl)
                <a href="{{ $editarUrl }}"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-control border border-linha px-4 font-body-sm text-body-sm text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-on-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                    <x-icon name="pencil" class="h-4 w-4" />
                    Editar
                </a>
            @endif
        </div>
    @endif
</{{ $temAcoes ? 'details' : 'div' }}>
