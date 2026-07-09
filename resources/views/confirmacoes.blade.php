{{-- Confirmações pendentes (spec FE §7.9) — espelho web da regra 7: gastos interpretados
     aguardando o "sim". Encaixa no shell padrão (aside + header + chat). Os valores chegam
     PRONTOS do backend (ConfirmacaoPendenteController → domínio): a UI nunca calcula (regra 4)
     e só exibe (pt-BR já formatado, regra 5). "Confirmar" grava (RegistrarGastoManual);
     "Descartar" rejeita — nada é gravado antes do "sim".

     Escopo: esta fila só guarda itens CONFIRMÁVEIS (recorrência/importação). O card de
     "esclarecimento" da §7.9 pertence ao fluxo conversacional (chat/Telegram), não aqui.
     Deferido: "Ajustar" (editar o pendente antes de gravar) e a prévia por parcela (tabela
     datada) — exigem plumbing de edição/geração; hoje o card mostra o total, a forma e Nx. --}}
<x-layouts.app title="Confirmações pendentes | Agente Financeiro" active="confirmacoes" heading="Confirmações">
    <div class="flex w-full max-w-2xl flex-col gap-8">
        {{-- Cabeçalho da página. --}}
        <header class="flex flex-col gap-2">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Confirmações pendentes</h2>
            <p class="font-body-md text-body-md text-on-surface-variant">
                Interpretei estes gastos. Nada foi gravado — confirme para salvar.
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
            {{-- Card de prévia (superfície, cantos 12px). É a própria confirmação (regra 7): a
                 tela é o resumo do que será salvo; "Confirmar" grava direto. --}}
            <article class="notebook-card flex flex-col gap-4 rounded-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <h3 class="font-headline-md text-headline-md text-on-surface">{{ $item['descricao'] }}</h3>
                        <p class="font-value-label text-value-label text-on-surface-variant">
                            {{ $item['forma'] }} · vence {{ $item['data'] }}@if ($item['parcelas'] !== 'à vista') · {{ $item['parcelas'] }}@endif
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1.5">
                        <span class="font-value-display text-value-display text-on-surface">{{ $item['valor'] }}</span>
                        <span class="inline-flex items-center rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-label-sm text-on-surface-variant">
                            {{ $item['origem'] }}
                        </span>
                    </div>
                </div>

                <p class="font-body-sm text-body-sm text-on-surface-variant">Pronto para gravar — confirme.</p>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    {{-- Descartar (rejeita, não grava nada). --}}
                    <form method="POST" action="{{ route('confirmacoes.rejeitar', $item['opaqueId']) }}">
                        @csrf
                        <button type="submit"
                            class="inline-flex h-11 w-full items-center justify-center rounded-control border border-linha px-6 font-body-md text-body-md font-medium text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95 sm:w-auto">
                            Descartar
                        </button>
                    </form>
                    {{-- Confirmar (grava). --}}
                    <form method="POST" action="{{ route('confirmacoes.confirmar', $item['opaqueId']) }}">
                        @csrf
                        <button type="submit"
                            class="inline-flex h-11 w-full items-center justify-center rounded-control bg-primary px-6 font-body-md text-body-md font-semibold text-on-primary transition-colors hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95 sm:w-auto">
                            Confirmar
                        </button>
                    </form>
                </div>
            </article>
        @empty
            {{-- Estado vazio calmo (spec §7.9). --}}
            <div class="flex flex-col items-center gap-3 rounded-card border border-dashed border-linha bg-surface-container-lowest px-6 py-16 text-center">
                <x-icon name="circle-check" class="h-8 w-8 text-outline" />
                <p class="font-headline-md text-headline-md text-on-surface">Nada para confirmar agora.</p>
                <p class="max-w-sm font-body-sm text-body-sm text-on-surface-variant">
                    Gastos que você registrar pelo Telegram aparecem aqui para confirmação.
                </p>
            </div>
        @endforelse

        @if (count($itens) > 0)
            <p class="font-body-sm text-body-sm text-on-surface-variant">Nada é gravado até você confirmar.</p>
        @endif
    </div>
</x-layouts.app>
