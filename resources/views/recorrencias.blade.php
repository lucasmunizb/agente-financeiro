{{-- Gerenciar recorrências (spec 10). Lista as recorrências ATIVAS (assinaturas/contas fixas)
     e permite cancelar. Encaixa no shell padrão (aside + header + chat). Os valores chegam
     PRONTOS do backend (RecorrenciaController → ConsultarRecorrencias): a UI nunca calcula
     (regra 4) e só exibe (pt-BR já formatado, regra 5). Cancelar é destrutivo → confirmado na
     própria tela por <details> (sem JS): encerra a geração das próximas ocorrências; os
     lançamentos já criados permanecem. --}}
<x-layouts.app title="Recorrências | Agente Financeiro" active="recorrencias" heading="Recorrências">
    <div class="flex w-full max-w-2xl flex-col gap-8">
        {{-- Cabeçalho da página. --}}
        <header class="flex flex-col gap-2">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Recorrências</h2>
            <p class="font-body-md text-body-md text-on-surface-variant">
                Suas assinaturas e contas fixas. A cada mês, no dia marcado, a cobrança aparece na fila de
                confirmações — nada é lançado sem o seu sim.
            </p>
        </header>

        {{-- Feedback (POST server-rendered → flash). --}}
        @if (session('sucesso'))
            <div role="status" class="flex items-start gap-2 rounded-card border border-primary/30 bg-primary-container/10 p-4">
                <x-icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                <p class="font-body-sm text-body-sm text-on-surface">{{ session('sucesso') }}</p>
            </div>
        @endif

        @forelse ($itens as $item)
            <article class="notebook-card flex flex-col gap-4 rounded-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex min-w-0 flex-col gap-1">
                        <h3 class="font-headline-md text-headline-md text-on-surface">{{ $item['descricao'] }}</h3>
                        <p class="font-value-label text-value-label text-on-surface-variant">
                            {{ $item['forma'] }} · todo dia {{ $item['dia'] }}
                        </p>
                        @if ($item['proxima'])
                            <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-outline">
                                <x-icon name="refresh-cw" class="h-3.5 w-3.5 text-cedula" aria-hidden="true" />
                                próxima em {{ $item['proxima'] }}
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
                            Encerra esta recorrência: as próximas cobranças deixam de ser geradas. Os lançamentos
                            já registrados permanecem. Não dá para desfazer.
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
                    Ao registrar um gasto fora de cartão, ligue "Repete todo mês?" para criar uma assinatura ou
                    conta fixa — ela aparece aqui.
                </p>
            </div>
        @endforelse
    </div>
</x-layouts.app>
