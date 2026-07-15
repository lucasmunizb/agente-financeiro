@props([
    'monthLabel' => 'Junho de 2026',
    'prevUrl' => null, // navegação por competência (mês anterior); null desabilita
    'nextUrl' => null, // navegação por competência (mês seguinte); null desabilita
])

{{-- Estado VAZIO do dashboard (spec FE §7.5) — primeiro mês, nada registrado.
     Texto-guia que convida à ação (nunca um "nada aqui" seco). A régua aparece
     "resetada" e o convite reforça o registro pelo Telegram. --}}
<div class="w-full space-y-gutter">
    {{-- Régua vazia --}}
    <section>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="font-headline-md text-headline-md text-on-surface">{{ $monthLabel }}</h2>
            {{-- Navegação real por competência (P2-15) — mesmo padrão do month-ruler. --}}
            <div class="flex items-center gap-2 text-on-surface-variant">
                <a @if ($prevUrl) href="{{ $prevUrl }}" @else aria-disabled="true" tabindex="-1" @endif aria-label="Mês anterior"
                    @class([
                        'rounded-full p-1 transition-colors',
                        'hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary' => $prevUrl,
                        'pointer-events-none text-outline-variant opacity-50' => ! $prevUrl,
                    ])>
                    <x-icon name="chevron-left" class="h-5 w-5" />
                </a>
                <span class="font-label-sm text-label-sm uppercase tracking-wider">Hoje</span>
                <a @if ($nextUrl) href="{{ $nextUrl }}" @else aria-disabled="true" tabindex="-1" @endif aria-label="Próximo mês"
                    @class([
                        'rounded-full p-1 transition-colors',
                        'hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary' => $nextUrl,
                        'pointer-events-none text-outline-variant opacity-50' => ! $nextUrl,
                    ])>
                    <x-icon name="chevron-right" class="h-5 w-5" />
                </a>
            </div>
        </div>
        <div class="relative overflow-hidden rounded-card border border-linha bg-surface-container-lowest p-6">
            <div class="relative z-10 flex h-24 items-end justify-between">
                @foreach ([['01', 'h-16'], ['08', 'h-12'], ['15', 'h-20'], ['22', 'h-14'], ['29', 'h-16']] as [$dia, $altura])
                    <div class="flex flex-1 flex-col items-center">
                        <div class="mb-2 w-2 {{ $altura }} rounded-t-full bg-surface-container opacity-40"></div>
                        <span class="font-label-sm text-label-sm text-outline">{{ $dia }}</span>
                    </div>
                @endforeach
            </div>
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <p class="font-body-sm text-body-sm italic text-outline">Nenhuma atividade registrada este mês</p>
            </div>
        </div>
    </section>

    {{-- Bento vazio: convite principal + onboarding --}}
    <div class="grid grid-cols-12 gap-gutter">
        {{-- Convite principal --}}
        <div class="col-span-12 flex min-h-[400px] flex-col items-center justify-center rounded-card border border-linha bg-surface-container-lowest p-8 text-center md:p-12 lg:col-span-8">
            <div class="relative mb-8">
                <div class="absolute -inset-4 rounded-full bg-primary/5 blur-2xl" aria-hidden="true"></div>
                <span class="relative text-primary/25">
                    <x-icon name="wallet" class="h-24 w-24" />
                </span>
            </div>
            <h3 class="mb-2 font-headline-lg text-headline-lg text-on-surface">Seu livro de contas está limpo.</h3>
            <p class="mb-8 max-w-md font-body-lg text-body-lg text-on-surface-variant">
                Comece a mapear seu mês hoje. Registre seu primeiro gasto para a organização começar a acontecer.
            </p>
            <button type="button" data-rg-open
                class="rounded-control bg-primary px-8 py-3 font-body-md text-body-md font-semibold text-on-primary shadow-lg shadow-primary/10 transition-all hover:bg-cedula-clara active:scale-95">
                Registrar gasto
            </button>
            <div class="mt-12 w-full max-w-sm border-t border-linha/60 pt-8">
                <div class="flex items-center justify-center gap-2 text-on-surface-variant">
                    <x-icon name="send" class="h-5 w-5 shrink-0 text-primary" />
                    <p class="font-body-sm text-body-sm">
                        Dica: registre gastos mandando uma mensagem ao robô no
                        <a href="{{ route('telegram') }}" class="font-semibold text-primary underline underline-offset-2 hover:text-cedula-clara">Telegram</a>.
                    </p>
                </div>
            </div>
        </div>

        {{-- Onboarding lateral --}}
        <div class="col-span-12 space-y-gutter lg:col-span-4">
            <div class="relative overflow-hidden rounded-card bg-primary p-8 text-on-primary">
                <div class="absolute right-0 top-0 p-4 opacity-20" aria-hidden="true">
                    <x-icon name="sparkles" class="h-16 w-16" />
                </div>
                <h4 class="relative z-10 mb-4 font-headline-md text-headline-md">Inteligência a seu favor</h4>
                <p class="relative z-10 mb-6 font-body-sm text-body-sm opacity-90">
                    Ao registrar seus gastos, a IA categoriza tudo automaticamente. Os números vêm do seu banco de dados — a IA nunca os inventa.
                </p>
                <span class="relative z-10 font-label-sm text-label-sm underline underline-offset-4 decoration-on-primary/30">Número conferido, sempre</span>
            </div>

            <div class="rounded-card border border-linha bg-surface-container-lowest p-6">
                <h4 class="mb-4 flex items-center font-body-md text-body-md font-semibold text-on-surface">
                    <x-icon name="rocket" class="mr-2 h-5 w-5 text-ocre" /> Próximos passos
                </h4>
                <ul class="space-y-4">
                    <li class="flex items-start gap-3">
                        <x-icon name="circle-check" class="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                        <div>
                            <p class="font-body-sm text-body-sm font-medium text-on-surface">Criar sua conta</p>
                            <p class="text-[12px] text-outline">Concluído</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3 opacity-60">
                        <x-icon name="circle" class="mt-0.5 h-5 w-5 shrink-0 text-outline" />
                        <div>
                            <p class="font-body-sm text-body-sm font-medium text-on-surface">Primeiro registro</p>
                            <p class="text-[12px] text-outline">Aguardando</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3 opacity-60">
                        <x-icon name="circle" class="mt-0.5 h-5 w-5 shrink-0 text-outline" />
                        <div>
                            <p class="font-body-sm text-body-sm font-medium text-on-surface">Conectar Telegram</p>
                            <p class="text-[12px] text-outline">Opcional</p>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
