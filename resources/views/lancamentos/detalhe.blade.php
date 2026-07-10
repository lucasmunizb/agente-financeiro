{{-- Detalhe do lançamento (spec FE §7.8). Encaixa no shell padrão (aside + header + coluna
     de chat). Todos os valores vêm PRONTOS do backend (LancamentoController::show →
     ConsultarLancamentoDetalhe determinístico): a UI nunca calcula dinheiro (regra 4) e só
     exibe (pt-BR já formatado, regra 5). A edição acontece por MODAL nesta mesma tela
     (reusa <x-gasto.form mode=edit>); fica BLOQUEADA quando há parcela paga (regra 7). --}}
<x-layouts.app title="Detalhe do lançamento | Agente Financeiro" active="transacoes" heading="Lançamento">
    @unless ($bloqueado)
        @push('scripts')
            @vite('resources/js/pages/registrar-gasto.js')
        @endpush
    @endunless

    <div class="flex w-full max-w-3xl flex-col gap-8">
        {{-- Voltar --}}
        <a href="{{ route('lancamentos') }}"
            class="inline-flex w-fit items-center gap-1.5 rounded-control font-body-sm text-body-sm text-on-surface-variant transition-colors hover:text-on-surface focus:outline-none focus:ring-2 focus:ring-primary">
            <x-icon name="arrow-left" class="h-4 w-4" />
            Lançamentos
        </a>

        {{-- Feedback do pagamento por parcela (POST server-rendered → flash). --}}
        @if (session('sucesso'))
            <div role="status" class="flex items-start gap-2 rounded-card border border-primary/30 bg-primary-container/10 p-4">
                <x-icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                <p class="font-body-sm text-body-sm text-on-surface">{{ session('sucesso') }}</p>
            </div>
        @endif
        @error('data_pagamento')
            <div role="alert" class="flex items-start gap-2 rounded-card border border-error/30 bg-error/5 p-4">
                <x-icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-error" />
                <p class="font-body-sm text-body-sm text-on-surface">{{ $message }}</p>
            </div>
        @enderror

        {{-- Cabeçalho: descrição + categoria à esquerda; valor total + status à direita. --}}
        <header class="flex flex-col gap-4 border-b border-linha pb-6 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <h2 class="font-display text-headline-lg font-semibold text-on-surface">{{ $descricao }}</h2>
                @if ($categoria)
                    <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-surface-container px-3 py-1 font-label-sm text-label-sm text-on-surface-variant">
                        <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $categoria['cor'] ?? '#6B6F66' }}"></span>
                        {{ $categoria['nome'] }}
                    </span>
                @endif
            </div>
            <div class="flex flex-col items-start gap-2 sm:items-end">
                <span class="font-value-display text-value-display text-on-surface">{{ $valorTotal }}</span>
                <x-ui.status-badge :status="$status" />
            </div>
        </header>

        {{-- Recorrência: quadro de destaque quando o lançamento é vinculado a uma recorrência.
             Os dados (dia + próxima) vêm prontos do backend (regra 4). --}}
        @if (($dados['recorrencia'] ?? null))
            @php $rec = $dados['recorrencia']; @endphp
            <section class="flex items-start gap-3 rounded-card border border-cedula/30 bg-cedula/5 p-gutter">
                <x-icon name="refresh-cw" class="mt-0.5 h-5 w-5 shrink-0 text-cedula" />
                <div class="flex flex-col gap-1">
                    <p class="font-body-md text-body-md font-medium text-on-surface">Lançamento recorrente</p>
                    @if ($rec['ativa'])
                        <p class="font-body-sm text-body-sm text-on-surface-variant">
                            Repete todo mês no dia {{ $rec['dia'] }}@if ($rec['proximaEm']) · próxima em {{ $rec['proximaEm'] }}@endif.
                        </p>
                    @else
                        <p class="font-body-sm text-body-sm text-on-surface-variant">A recorrência foi encerrada — este lançamento nasceu dela.</p>
                    @endif
                    <a href="{{ route('recorrencias') }}" class="w-fit font-label-sm text-label-sm font-medium text-cedula hover:underline">
                        Gerenciar recorrências
                    </a>
                </div>
            </section>
        @endif

        {{-- Metadados (rótulo → valor; datas e números em mono). --}}
        <section class="notebook-card relative overflow-hidden rounded-card p-gutter">
            <div class="absolute inset-y-0 left-0 w-2 border-r border-linha bg-primary/5"></div>
            <dl class="grid grid-cols-1 gap-x-gutter gap-y-4 pl-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <dt class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Forma de pagamento</dt>
                    <dd class="font-body-md text-body-md text-on-surface">{{ $formaLabel }}</dd>
                </div>
                @if ($cartaoLinha)
                    <div class="flex flex-col gap-1">
                        <dt class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Cartão</dt>
                        <dd class="font-value-label text-value-label text-on-surface">{{ $cartaoLinha }}</dd>
                    </div>
                @endif
                <div class="flex flex-col gap-1">
                    <dt class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Data da compra</dt>
                    <dd class="font-value-label text-value-label text-on-surface">{{ $dataCompra }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Vencimento</dt>
                    <dd class="font-value-label text-value-label text-on-surface">{{ $vencimentoLabel }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Origem</dt>
                    <dd class="font-body-md text-body-md text-on-surface">{{ $origemLabel }}</dd>
                </div>
            </dl>
        </section>

        {{-- Parcelas (mono, alinhado à direita). Valor por parcela derivado pelo backend. --}}
        <section class="notebook-card relative overflow-hidden rounded-card p-gutter">
            <div class="absolute inset-y-0 left-0 w-2 border-r border-linha bg-primary/5"></div>
            <div class="pl-4">
                <h3 class="mb-4 font-headline-md text-headline-md text-on-surface">Parcelas</h3>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[34rem] border-collapse">
                        <thead>
                            <tr class="border-b border-linha font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                <th class="pb-2 text-left font-medium">Nº</th>
                                <th class="pb-2 text-right font-medium">Valor</th>
                                <th class="pb-2 text-right font-medium">Vencimento</th>
                                <th class="pb-2 text-right font-medium">Status</th>
                                <th class="pb-2 text-right font-medium"><span class="sr-only">Ação</span></th>
                            </tr>
                        </thead>
                        <tbody class="font-value-label text-value-label text-on-surface">
                            @foreach ($parcelas as $parcela)
                                <tr class="border-b border-linha last:border-0 align-top">
                                    <td class="py-3 text-left">{{ $parcela['label'] }}</td>
                                    <td class="py-3 text-right">{{ $parcela['valor'] }}</td>
                                    <td class="py-3 text-right text-on-surface-variant">{{ $parcela['vencimento'] }}</td>
                                    <td class="py-3 text-right">
                                        <x-ui.status-badge :status="$parcela['status']" />
                                    </td>
                                    <td class="py-3 text-right">
                                        {{-- Marcar pago (só fora de cartão e ainda não paga/cancelada). `<details>`
                                             puro (sem JS): funciona mesmo com a tela bloqueada por outra parcela
                                             paga. Confirmação com prévia embutida (regra 7); grava por POST. --}}
                                        @if ($parcela['pagavel'])
                                            <details class="inline-block text-left">
                                                <summary class="inline-flex cursor-pointer list-none items-center gap-1 rounded-control border border-linha px-3 py-1.5 font-label-sm text-label-sm font-medium text-primary transition-colors hover:bg-primary-container/10 focus:outline-none focus:ring-2 focus:ring-primary">
                                                    <x-icon name="check" class="h-4 w-4" /> Marcar pago
                                                </summary>
                                                <form method="POST" action="{{ route('lancamentos.parcela.pagar', $parcela['opaqueId']) }}"
                                                    class="mt-2 flex min-w-[13rem] flex-col gap-2 rounded-control border border-linha bg-surface-container-lowest p-3">
                                                    @csrf
                                                    <p class="font-label-sm text-label-sm text-on-surface-variant">
                                                        Confirmar pagamento de <span class="font-value-label text-on-surface">{{ $parcela['valor'] }}</span> (parcela {{ $parcela['label'] }}).
                                                    </p>
                                                    <label for="pg-{{ $loop->index }}" class="font-label-sm text-label-sm text-on-surface-variant">Data de pagamento</label>
                                                    <input id="pg-{{ $loop->index }}" name="data_pagamento" type="date" required value="{{ $hojeIso }}"
                                                        class="input-field h-11 rounded-control px-3 font-value-label text-value-label text-on-surface" />
                                                    <button type="submit"
                                                        class="inline-flex h-11 items-center justify-center rounded-control bg-primary px-4 font-body-sm text-body-sm font-semibold text-on-primary transition-colors hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                                                        Confirmar pagamento
                                                    </button>
                                                </form>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Ações. "Editar" abre o modal (regra 7); trava com parcela paga. "Cancelar
             restantes" cancela esta e as próximas parcelas em aberto (as pagas são mantidas),
             com prévia + confirmação embutida no <details> (regra 7, sem JS); grava por POST. --}}
        @php
            // Filtro de APRESENTAÇÃO (não é cálculo de dinheiro): as parcelas que o domínio vai
            // cancelar = as que não estão pagas nem canceladas. O status de exibição já mapeia
            // pago_parcial→pago e estornado→cancelado, então isto casa exatamente com o conjunto
            // preservado por CancelarGastoManual. Sem parcela cancelável, o botão fica inerte.
            $parcelasCancelaveis = array_values(array_filter(
                $parcelas,
                fn ($p) => ! in_array($p['status'], ['pago', 'cancelado'], true),
            ));
            $podeCancelar = count($parcelasCancelaveis) > 0;
        @endphp
        <footer class="flex flex-col gap-4">
            @if ($bloqueado)
                <div class="flex items-start gap-2 text-on-surface-variant">
                    <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
                    <p class="font-body-sm text-body-sm">Há parcelas pagas — não é possível editar; você ainda pode cancelar as restantes.</p>
                </div>
            @endif

            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-end">
                {{-- Cancelar (destrutivo): mesmo padrão do "Marcar pago" — <details> puro, com a
                     prévia do que será cancelado e confirmação antes de gravar (regra 7). --}}
                @if ($podeCancelar)
                    <details class="inline-block text-left sm:mr-auto">
                        <summary class="inline-flex h-11 cursor-pointer list-none items-center justify-center gap-1.5 rounded-control border border-error/40 px-6 font-body-md text-body-md font-medium text-error transition-colors hover:bg-error/5 focus:outline-none focus:ring-2 focus:ring-error">
                            <x-icon name="x" class="h-4 w-4" /> Cancelar restantes
                        </summary>
                        <form method="POST" action="{{ route('lancamentos.cancelar', $transaction) }}"
                            class="mt-2 flex max-w-sm flex-col gap-3 rounded-control border border-error/30 bg-error/5 p-4">
                            @csrf
                            <p class="font-body-sm text-body-sm text-on-surface">
                                Isto cancela {{ count($parcelasCancelaveis) === 1 ? 'a parcela em aberto' : 'as ' . count($parcelasCancelaveis) . ' parcelas em aberto' }} deste lançamento. As parcelas já pagas são mantidas e o histórico é preservado. Não dá para desfazer.
                            </p>
                            <ul class="flex flex-col gap-1 border-y border-error/20 py-2 font-value-label text-value-label">
                                @foreach ($parcelasCancelaveis as $p)
                                    <li class="flex items-center justify-between gap-3 text-on-surface-variant">
                                        <span>{{ $p['label'] }} · vence {{ $p['vencimento'] }}</span>
                                        <span class="text-on-surface">{{ $p['valor'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <button type="submit"
                                class="inline-flex h-11 items-center justify-center rounded-control bg-error px-4 font-body-sm text-body-sm font-semibold text-white transition-colors hover:bg-error/90 focus:outline-none focus:ring-2 focus:ring-error focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                                Confirmar cancelamento
                            </button>
                        </form>
                    </details>
                @else
                    <button type="button" disabled title="Nada a cancelar"
                        class="inline-flex h-11 items-center justify-center rounded-control border border-linha px-6 font-body-md text-body-md font-medium text-on-surface-variant opacity-60 sm:mr-auto">
                        Cancelar restantes
                    </button>
                @endif

                @if ($bloqueado)
                    <button type="button" disabled
                        class="inline-flex h-11 items-center justify-center rounded-control bg-primary px-6 font-body-md text-body-md font-semibold text-on-primary opacity-50">
                        Editar
                    </button>
                @else
                    <button type="button" data-rg-open
                        class="inline-flex h-11 items-center justify-center rounded-control bg-primary px-6 font-body-md text-body-md font-semibold text-on-primary transition-colors hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                        Editar
                    </button>
                @endif
            </div>
        </footer>
    </div>

    {{-- Modal de edição (só quando editável). Reusa o form compartilhado + o registrar-gasto.js.
         `open` reflete ?editar=1 (o botão "Editar" da lista chega já aberto). --}}
    @unless ($bloqueado)
        <x-modal.editar-lancamento
            :transaction="$transaction"
            :open="$abrirEdicao"
            :cartoes="$cartoes"
            :categorias="$categorias"
            :dados="$dados" />
    @endunless
</x-layouts.app>
