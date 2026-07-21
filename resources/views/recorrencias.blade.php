{{-- Gerenciar recorrências (spec 10, revista pela spec 12). Lista as ATIVAS (assinaturas e
     contas fixas) e permite cancelar. Encaixa no shell padrão (aside + header + chat). Os
     valores e datas chegam PRONTOS do backend (RecorrenciaController → ConsultarRecorrencias
     + CalcularOcorrencia): a UI nunca calcula (regra 4) e só exibe (pt-BR, regra 5).

     Cancelar é destrutivo → confirmado na própria tela por <details> (sem JS): as cobranças
     futuras em aberto são canceladas junto; as passadas ficam no extrato como história. --}}
<x-layouts.app title="Recorrências | Agente Financeiro" active="recorrencias" heading="Recorrências">
    <div class="flex w-full max-w-2xl flex-col gap-8">
        {{-- Cabeçalho da página. --}}
        <header class="flex flex-col gap-2">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Recorrências</h2>
            <p class="font-body-md text-body-md text-on-surface-variant">
                Suas assinaturas e contas fixas. Cada uma vira <strong class="font-medium text-on-surface">uma
                cobrança por mês</strong> — ela aparece no extrato do mês, sem virar um lançamento à parte.
                Fora de cartão, você marca como paga; no cartão, a cobrança já entra na fatura.
            </p>
        </header>

        {{-- Feedback (POST server-rendered → flash). --}}
        @forelse ($itens as $item)
            <article class="notebook-card flex flex-col gap-4 rounded-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex min-w-0 flex-col gap-1">
                        <h3 class="font-headline-md text-headline-md text-on-surface">{{ $item['descricao'] }}</h3>
                        <p class="font-value-label text-value-label text-on-surface-variant">
                            {{ $item['cartao'] ?? $item['forma'] }} · todo dia {{ $item['dia'] }}
                        </p>
                        @if ($item['proxima'])
                            {{-- Data da próxima cobrança AINDA NÃO gerada. No cartão é o vencimento
                                 da fatura em que ela cai, não o dia da compra (calculado no backend). --}}
                            <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-outline">
                                <x-icon name="refresh-cw" class="h-3.5 w-3.5 text-cedula" aria-hidden="true" />
                                próxima cobrança em {{ $item['proxima'] }}
                            </p>
                        @endif
                    </div>
                    <span class="shrink-0 font-value-display text-value-display text-on-surface">{{ $item['valor'] }}</span>
                </div>

                {{-- Cancelar (destrutivo): <details> puro (sem JS, mesmo padrão da tela de
                     detalhe) — o summary abre/fecha a confirmação (regra 7). Cor `error` (uso
                     parco); a confirmação explicita o que acontece e é irreversível. --}}
                <details class="self-start text-left sm:self-end">
                    <summary class="inline-flex h-11 cursor-pointer list-none items-center justify-center gap-1.5 rounded-control border border-error/40 px-6 font-body-md text-body-md font-medium text-error transition-colors hover:bg-error/5 focus:outline-none focus:ring-2 focus:ring-error">
                        <x-icon name="x" class="h-4 w-4" /> Cancelar recorrência
                    </summary>
                    <form method="POST" action="{{ route('recorrencias.cancelar', $item['opaqueId']) }}"
                        class="mt-2 flex max-w-sm flex-col gap-3 rounded-control border border-error/30 bg-error/5 p-4">
                        @csrf
                        <p class="font-body-sm text-body-sm text-on-surface">
                            Encerra esta recorrência: as cobranças futuras deixam de ser geradas e as que ainda
                            estavam em aberto são canceladas. As cobranças de meses já passados continuam no
                            extrato. Não dá para desfazer.
                        </p>
                        <button type="submit"
                            class="inline-flex h-11 items-center justify-center rounded-control bg-error px-4 font-body-sm text-body-sm font-semibold text-white transition-colors hover:bg-error/90 focus:outline-none focus:ring-2 focus:ring-error focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                            Confirmar cancelamento
                        </button>
                    </form>
                </details>
            </article>
        @empty
            {{-- Estado vazio calmo. --}}
            <div class="flex flex-col items-center gap-3 rounded-card border border-dashed border-linha bg-surface-container-lowest px-6 py-16 text-center">
                <x-icon name="refresh-cw" class="h-8 w-8 text-outline" />
                <p class="font-headline-md text-headline-md text-on-surface">Nenhuma recorrência ativa.</p>
                <p class="max-w-sm font-body-sm text-body-sm text-on-surface-variant">
                    Ao registrar um gasto, ligue "Repete todo mês?" para criar uma assinatura ou conta fixa —
                    em qualquer forma de pagamento, cartão inclusive. Ela aparece aqui.
                </p>
            </div>
        @endforelse
    </div>
</x-layouts.app>
