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
             futuras" é backend pós-MVP nesta borda — fica como afordância "em breve". --}}
        <footer class="flex flex-col gap-4">
            @if ($bloqueado)
                <div class="flex items-start gap-2 text-on-surface-variant">
                    <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
                    <p class="font-body-sm text-body-sm">Há parcelas pagas — não é possível editar; você pode cancelar as futuras.</p>
                </div>
            @endif

            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                <button type="button" disabled title="Em breve"
                    class="inline-flex h-11 items-center justify-center rounded-control border border-linha px-6 font-body-md text-body-md font-medium text-on-surface-variant opacity-60">
                    Cancelar futuras
                </button>
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
