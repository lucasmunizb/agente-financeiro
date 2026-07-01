{{-- Tela inicial (provisória). Mantém o shell do app (barra lateral + topo) e
     serve de destino do login até o Dashboard ("Visão Geral") ser construído.
     Conteúdo em branco, de propósito — só um estado-guia sóbrio. --}}
<x-layouts.app title="Visão Geral | Agente Financeiro" active="dashboard"
    heading="Visão Geral" subheading="Seu painel financeiro aparece aqui em breve.">
    <section class="notebook-card w-full max-w-md rounded-xl p-10 text-center">
        <div class="mx-auto mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-surface-container text-outline">
            <x-icon name="layout-dashboard" class="h-7 w-7" />
        </div>
        <h2 class="mb-2 font-headline-md text-headline-md text-on-surface">Painel em construção</h2>
        <p class="mx-auto max-w-xs font-body-sm text-body-sm text-on-surface-variant">
            Em breve você verá aqui o disponível do mês, contas a vencer e seus últimos lançamentos. Por enquanto, conecte o Telegram para registrar gastos pelo chat.
        </p>
        <a href="{{ route('telegram') }}"
            class="mt-8 inline-flex items-center justify-center gap-2 rounded-lg bg-cedula px-6 py-3 font-body-md text-body-md font-semibold text-superficie shadow-sm transition-colors hover:bg-cedula-clara active:scale-[0.98]">
            <x-icon name="send" class="h-5 w-5" />
            Conectar o Telegram
        </a>
    </section>
</x-layouts.app>
