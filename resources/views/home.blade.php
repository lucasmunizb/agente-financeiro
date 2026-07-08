{{-- Dashboard / Visão geral (spec FE §7.5 / spec 06) — tela-assinatura. Encaixa no
     shell padrão (aside + header). Os valores vêm PRONTOS do backend
     (DashboardController → domínio determinístico): a UI nunca calcula dinheiro
     (regra 4) e só exibe (formatação pt-BR já feita, regra 5).

     Estado ($estado): 'pronto' (dados reais) · 'vazio' (sem lançamentos) ·
     'carregando' (skeleton). O controller decide pelos dados; ?estado= continua
     como afordância de revisão das telas. --}}
@php
    $estado = $estado ?? 'pronto';
    $mesLabel = $mesLabel ?? ($vm['mesLabel'] ?? 'Este mês');

    // Afordância de revisão do modal: ?modal=aberto|erro|salvando.
    $modal = request('modal');
    $modalAberto = in_array($modal, ['aberto', 'erro', 'salvando'], true);
    $modalInicial = in_array($modal, ['erro', 'salvando'], true) ? $modal : null;

    // Mapas literais das cores do donut (strings completas p/ o Tailwind detectar).
    $corTexto = ['primary' => 'text-primary', 'secondary' => 'text-secondary', 'tertiary' => 'text-tertiary', 'outline' => 'text-outline'];
    $corFundo = ['primary' => 'bg-primary', 'secondary' => 'bg-secondary', 'tertiary' => 'bg-tertiary', 'outline' => 'bg-outline'];
@endphp
<x-layouts.app title="Visão Geral | Agente Financeiro" active="dashboard" heading="Visão Geral" wide>
    @push('scripts')
        @vite('resources/js/pages/registrar-gasto.js')
    @endpush

    @if ($estado === 'carregando')
        <x-dashboard.loading />
    @elseif ($estado === 'vazio')
        <x-dashboard.empty-state :month-label="$mesLabel" />
    @else
    <div class="w-full space-y-gutter">
        {{-- Elemento-assinatura: a régua do mês. --}}
        <x-dashboard.month-ruler
            :month-label="$vm['mesLabel']"
            :today="$vm['today']"
            :due-days="$vm['dueDays']"
            :days-in-month="$vm['daysInMonth']"
            :available-pct="$vm['availablePct']" />

        {{-- Cards de resumo (bento). Valores em mono, alinhados à direita. --}}
        <div class="grid animate-enter grid-cols-1 gap-gutter sm:grid-cols-2 lg:grid-cols-4" style="animation-delay: 0.1s">
            <x-dashboard.summary-card label="Disponível do mês" :value="$vm['disponivel']"
                :tone="$vm['disponivelPositivo'] ? 'primary' : 'default'" />

            <x-dashboard.summary-card label="Gastos do mês" :value="$vm['gastos']">
                @if ($vm['comparativo'])
                    <x-slot:badge>
                        @php $tomCmp = $vm['comparativo']['tom']; @endphp
                        <span @class([
                            'rounded px-1.5 py-0.5 font-value-label text-[10px]',
                            'bg-error-container/30 text-error' => $tomCmp === 'error',
                            'bg-primary-container/15 text-primary' => $tomCmp === 'primary',
                            'text-on-surface-variant' => $tomCmp === 'neutral',
                        ])>{{ $vm['comparativo']['texto'] }}</span>
                    </x-slot:badge>
                @endif
            </x-dashboard.summary-card>

            <x-dashboard.summary-card label="A vencer (7 dias)" :value="$vm['aVencer7']['valor']" tone="ocre">
                <x-slot:badge>
                    <span class="font-body-sm text-label-sm font-medium text-ocre">{{ $vm['aVencer7']['contas'] }} {{ $vm['aVencer7']['contas'] === 1 ? 'conta' : 'contas' }}</span>
                </x-slot:badge>
            </x-dashboard.summary-card>

            @if ($vm['fatura'])
                <x-dashboard.summary-card label="Fatura do cartão" :value="$vm['fatura']['valor']" :sub="$vm['fatura']['sub']" />
            @else
                <x-dashboard.summary-card label="Fatura do cartão" value="—" sub="Nenhum cartão cadastrado" />
            @endif
        </div>

        {{-- Meio: gastos por categoria (donut) + próximas contas. --}}
        <div class="grid animate-enter grid-cols-1 gap-gutter lg:grid-cols-3" style="animation-delay: 0.2s">
            {{-- Donut "gastos por categoria" --}}
            <div class="notebook-card flex flex-col items-center rounded-card p-8 lg:col-span-1">
                <h3 class="mb-8 w-full font-headline-md text-headline-md text-on-surface">Gastos por categoria</h3>

                @if (count($vm['donut']['segmentos']) === 0)
                    <p class="py-12 font-body-sm text-body-sm text-outline">Nenhum gasto categorizado este mês.</p>
                @else
                    <div class="relative mb-8 h-48 w-48">
                        <svg class="h-full w-full -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
                            <path class="text-surface-container" fill="none" stroke="currentColor" stroke-width="3"
                                d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            @foreach ($vm['donut']['segmentos'] as $seg)
                                <path class="{{ $corTexto[$seg['cor']] }}" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                    stroke-dasharray="{{ $seg['pct'] }}, 100" stroke-dashoffset="{{ $seg['offset'] }}"
                                    d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            @endforeach
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="font-label-sm text-label-sm text-on-surface-variant">Total</span>
                            <span class="font-value-label text-value-label text-on-surface">{{ $vm['donut']['total'] }}</span>
                        </div>
                    </div>

                    <div class="w-full space-y-3">
                        @foreach ($vm['donut']['segmentos'] as $seg)
                            <div class="flex items-center justify-between font-body-sm text-body-sm">
                                <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ $corFundo[$seg['cor']] }}"></span> {{ $seg['nome'] }}</span>
                                <span class="font-value-label text-value-label">{{ $seg['valor'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Contas: dividido em "em atraso" (o que já venceu) + "a vencer" (spec 06b).
                 Em atraso vem primeiro, com acento argila (error), por ser o estado de
                 alerta. Valores/contagens chegam PRONTOS do backend (regras 4/5). --}}
            <div class="notebook-card rounded-card p-8 lg:col-span-2">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="font-headline-md text-headline-md text-on-surface">Contas</h3>
                </div>

                @php $temAtraso = count($vm['contasVencidas']) > 0; $temAVencer = count($vm['proximasContas']) > 0; @endphp

                @if (! $temAtraso && ! $temAVencer)
                    <p class="py-8 text-center font-body-sm text-body-sm text-outline">Nenhuma conta em atraso ou a vencer nos próximos dias.</p>
                @else
                    {{-- Seção "Em atraso" — só aparece quando há algo vencido. --}}
                    @if ($temAtraso)
                        <div class="mt-4">
                            <div class="flex items-center justify-between border-b border-linha pb-2">
                                <span class="inline-flex items-center gap-1.5 font-label-sm text-label-sm font-semibold uppercase tracking-wider text-error">
                                    <x-icon name="alert" class="h-4 w-4" />
                                    Em atraso
                                </span>
                                <span class="font-value-label text-value-label text-error">
                                    {{ $vm['emAtraso']['contas'] }} {{ $vm['emAtraso']['contas'] === 1 ? 'conta' : 'contas' }} · {{ $vm['emAtraso']['valor'] }}
                                </span>
                            </div>
                            @foreach ($vm['contasVencidas'] as $conta)
                                <x-dashboard.bill-row icon="alert" :icon-tone="$conta['iconTone']"
                                    :title="$conta['title']" :due="$conta['due']" :value="$conta['value']" status="atraso" />
                            @endforeach
                        </div>
                    @endif

                    {{-- Seção "A vencer". --}}
                    <div class="{{ $temAtraso ? 'mt-8' : 'mt-4' }}">
                        <div class="flex items-center justify-between border-b border-linha pb-2">
                            <span class="font-label-sm text-label-sm font-semibold uppercase tracking-wider text-on-surface-variant">
                                A vencer
                            </span>
                            @if ($temAVencer)
                                <span class="font-value-label text-value-label text-on-surface-variant">
                                    {{ $vm['aVencer']['contas'] }} {{ $vm['aVencer']['contas'] === 1 ? 'conta' : 'contas' }} · {{ $vm['aVencer']['valor'] }}
                                </span>
                            @endif
                        </div>
                        @if ($temAVencer)
                            @foreach ($vm['proximasContas'] as $conta)
                                <x-dashboard.bill-row icon="receipt" :icon-tone="$conta['iconTone']"
                                    :title="$conta['title']" :due="$conta['due']" :value="$conta['value']" status="a_vencer" />
                            @endforeach
                        @else
                            <p class="py-6 text-center font-body-sm text-body-sm text-outline">Nenhuma conta a vencer nos próximos dias.</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- FAB "Registrar gasto" — abre o modal de captura rápida (spec §7.7b). --}}
    <button type="button" data-rg-open
        class="group fixed bottom-8 right-8 z-40 inline-flex items-center gap-3 rounded-full bg-primary-container px-6 py-4 font-body-md text-body-md font-semibold text-on-primary shadow-lg transition-all hover:bg-cedula-clara active:scale-95 lg:right-[calc(380px+2rem)]">
        <x-icon name="plus" class="h-5 w-5 transition-transform group-hover:rotate-90" />
        <span>Registrar gasto</span>
    </button>
    @endif

    {{-- Modal de registro rápido (§7.7b). Fica fora do @if para funcionar em
         qualquer estado do dashboard e responder à afordância ?modal=… Recebe os
         cartões e categorias reais do usuário (escopo por usuário). --}}
    <x-modal.registrar-gasto :open="$modalAberto" :initial="$modalInicial"
        :cartoes="$cartoes ?? null" :categorias="$categorias ?? null" />
</x-layouts.app>
