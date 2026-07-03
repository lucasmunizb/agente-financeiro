{{-- Dashboard / Visão geral (spec FE §7.5) — tela-assinatura. Encaixa no shell
     padrão (aside + header). Todos os valores são DADOS FAKE nesta etapa; a
     integração com o backend (spec-06) vem depois. A UI nunca calcula dinheiro
     (regra 4): quando ligada ao backend, os valores chegarão já formatados.

     Estado da tela ($estado): 'pronto' (dados) · 'vazio' (primeiro mês) ·
     'carregando' (skeleton). Hoje vem da query (?estado=…) só para revisão; ao
     ligar o backend, o controller decide o estado a partir dos dados reais. --}}
@php $estado = $estado ?? 'pronto'; @endphp
<x-layouts.app title="Visão Geral | Agente Financeiro" active="dashboard" heading="Visão Geral" wide>
    @if ($estado === 'carregando')
        <x-dashboard.loading />
    @elseif ($estado === 'vazio')
        <x-dashboard.empty-state month-label="Junho de 2026" />
    @else
    <div class="w-full space-y-gutter">
        {{-- Elemento-assinatura: a régua do mês. --}}
        <x-dashboard.month-ruler
            month-label="Junho de 2026"
            :today="18"
            :due-days="[5, 10, 20, 25, 30]"
            :days-in-month="30"
            :available-pct="65" />

        {{-- Cards de resumo (bento). Valores em mono, alinhados à direita. --}}
        <div class="grid animate-enter grid-cols-1 gap-gutter sm:grid-cols-2 lg:grid-cols-4" style="animation-delay: 0.1s">
            <x-dashboard.summary-card label="Disponível do mês" value="R$ 2.480,00" tone="primary" />

            <x-dashboard.summary-card label="Gastos do mês" value="R$ 3.120,00">
                <x-slot:badge>
                    <span class="rounded bg-error-container/30 px-1.5 py-0.5 font-value-label text-[10px] text-error">+4,2%</span>
                </x-slot:badge>
            </x-dashboard.summary-card>

            <x-dashboard.summary-card label="A vencer (7 dias)" value="R$ 540,00" tone="ocre">
                <x-slot:badge>
                    <span class="font-body-sm text-label-sm font-medium text-ocre">3 contas</span>
                </x-slot:badge>
            </x-dashboard.summary-card>

            <x-dashboard.summary-card label="Fatura do cartão" value="R$ 1.870,00" sub="Nubank · fecha 28 de junho" />
        </div>

        {{-- Meio: gastos por categoria (donut) + próximas contas. --}}
        <div class="grid animate-enter grid-cols-1 gap-gutter lg:grid-cols-3" style="animation-delay: 0.2s">
            {{-- Donut "gastos por categoria" --}}
            <div class="notebook-card flex flex-col items-center rounded-card p-8 lg:col-span-1">
                <h3 class="mb-8 w-full font-headline-md text-headline-md text-on-surface">Gastos por categoria</h3>

                <div class="relative mb-8 h-48 w-48">
                    <svg class="h-full w-full -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
                        <path class="text-surface-container" fill="none" stroke="currentColor" stroke-width="3"
                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        {{-- Mercado 35% --}}
                        <path class="text-primary" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                            stroke-dasharray="35, 100" stroke-dashoffset="0"
                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        {{-- Moradia 25% --}}
                        <path class="text-secondary" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                            stroke-dasharray="25, 100" stroke-dashoffset="-35"
                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        {{-- Restaurante 15% --}}
                        <path class="text-tertiary" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                            stroke-dasharray="15, 100" stroke-dashoffset="-60"
                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        {{-- Outros 25% --}}
                        <path class="text-outline" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                            stroke-dasharray="25, 100" stroke-dashoffset="-75"
                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="font-label-sm text-label-sm text-on-surface-variant">Total</span>
                        <span class="font-value-label text-value-label text-on-surface">R$ 3.120</span>
                    </div>
                </div>

                <div class="w-full space-y-3">
                    <div class="flex items-center justify-between font-body-sm text-body-sm">
                        <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-primary"></span> Mercado</span>
                        <span class="font-value-label text-value-label">R$ 1.092,00</span>
                    </div>
                    <div class="flex items-center justify-between font-body-sm text-body-sm">
                        <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-secondary"></span> Moradia</span>
                        <span class="font-value-label text-value-label">R$ 780,00</span>
                    </div>
                    <div class="flex items-center justify-between font-body-sm text-body-sm">
                        <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-tertiary"></span> Restaurante</span>
                        <span class="font-value-label text-value-label">R$ 468,00</span>
                    </div>
                    <div class="flex items-center justify-between font-body-sm text-body-sm">
                        <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-outline"></span> Outros</span>
                        <span class="font-value-label text-value-label">R$ 780,00</span>
                    </div>
                </div>
            </div>

            {{-- Próximas contas --}}
            <div class="notebook-card rounded-card p-8 lg:col-span-2">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-headline-md text-headline-md text-on-surface">Próximas contas</h3>
                    <button type="button" aria-disabled="true" title="Em breve"
                        class="cursor-default font-body-sm text-label-sm font-semibold text-outline/60">Ver todas</button>
                </div>

                <div>
                    <x-dashboard.bill-row icon="wifi" icon-tone="primary"
                        title="Internet Fibra" due="vence 20 de junho" value="R$ 120,00" status="pago" />
                    <x-dashboard.bill-row icon="zap" icon-tone="ocre"
                        title="Energia elétrica" due="vence 25 de junho" value="R$ 285,40" status="a_vencer" />
                    <x-dashboard.bill-row icon="droplet" icon-tone="error"
                        title="Saneamento" due="vence 30 de junho" value="R$ 84,20" status="atraso" />
                </div>
            </div>
        </div>
    </div>

    {{-- FAB "Registrar gasto". O registro pela web (spec §7.7) ainda não existe;
         marcado como "em breve" (mesmo padrão dos itens de menu). Registrar hoje é
         pelo Telegram. --}}
    <button type="button" aria-disabled="true" title="Em breve — registro pela web"
        class="group fixed bottom-8 right-8 z-40 inline-flex items-center gap-3 rounded-full bg-primary-container px-6 py-4 font-body-md text-body-md font-semibold text-on-primary shadow-lg transition-all hover:bg-cedula-clara active:scale-95">
        <x-icon name="plus" class="h-5 w-5 transition-transform group-hover:rotate-90" />
        <span>Registrar gasto</span>
    </button>
    @endif
</x-layouts.app>
