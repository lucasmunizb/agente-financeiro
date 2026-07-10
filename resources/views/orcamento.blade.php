{{-- Orçamento do mês (spec FE §7.11). Limite e consumo (total + por categoria) chegam PRONTOS
     do backend (OrcamentoController → OrcamentoMensal/ConsumoDoMes): a UI nunca calcula (regra 4)
     e só exibe (pt-BR já formatado, regra 5). No MVP só há limite GERAL; por categoria mostra-se
     apenas o consumo ("sem limite"). Definir o limite é uma ação explícita (form direto). --}}
<x-layouts.app title="Orçamento do mês | Agente Financeiro" active="orcamento" heading="Orçamento">
    <div class="flex w-full max-w-2xl flex-col gap-8">
        {{-- Cabeçalho + seletor de mês (competência). --}}
        <header class="flex flex-col gap-3">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Orçamento do mês</h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('orcamento', ['mes' => $mesAnterior]) }}" rel="prev"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-linha text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="chevron-left" class="h-5 w-5" />
                    <span class="sr-only">Mês anterior</span>
                </a>
                <span class="min-w-40 text-center font-value-label text-value-label text-on-surface">{{ $mesLabel }}</span>
                <a href="{{ route('orcamento', ['mes' => $mesSeguinte]) }}" rel="next"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-linha text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="chevron-right" class="h-5 w-5" />
                    <span class="sr-only">Próximo mês</span>
                </a>
            </div>
        </header>

        {{-- Feedback (POST server-rendered → flash). --}}
        @if ($temOrcamento)
            {{-- Card geral: limite, consumido, barra, % e saldo. --}}
            <article class="notebook-card flex flex-col gap-5 rounded-card p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex flex-col gap-1">
                        <span class="font-body-sm text-body-sm text-on-surface-variant">Limite do mês</span>
                        <span class="font-value-display text-value-display text-on-surface">{{ $limite }}</span>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <span class="font-body-sm text-body-sm text-on-surface-variant">Consumido</span>
                        <span class="font-value-display text-value-display {{ $estourou ? 'text-error' : 'text-on-surface' }}">{{ $consumido }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-surface-container" role="progressbar"
                        aria-valuenow="{{ $percentual }}" aria-valuemin="0" aria-valuemax="100" aria-label="Consumo do orçamento">
                        <div class="h-full rounded-full {{ $estourou ? 'bg-error' : 'bg-cedula' }}" style="width: {{ $barra }}%"></div>
                    </div>
                    <span class="w-12 shrink-0 text-right font-value-label text-value-label {{ $estourou ? 'text-error' : 'text-on-surface-variant' }}">{{ $percentual }}%</span>
                </div>

                <p class="flex items-center gap-1.5 font-body-md text-body-md {{ $estourou ? 'font-semibold text-error' : 'text-on-surface-variant' }}">
                    @if ($estourou)
                        <x-icon name="alert" class="h-4 w-4 shrink-0" />
                        Acima do limite em {{ $restante }}
                    @else
                        Resta {{ $restante }}
                    @endif
                </p>
            </article>
        @else
            {{-- Estado sem orçamento. --}}
            <div class="flex flex-col items-center gap-3 rounded-card border border-dashed border-linha bg-surface-container-lowest px-6 py-14 text-center">
                <x-icon name="wallet" class="h-8 w-8 text-outline" />
                <p class="font-headline-md text-headline-md text-on-surface">Defina um limite para acompanhar o consumo.</p>
            </div>
        @endif

        {{-- Definir/editar o limite geral. Form direto (nada é calculado — a ação é explícita);
             validação server-side (Form Request). Abre já aberto se houver erro de validação. --}}
        <details class="notebook-card rounded-card p-6" @if ($errors->any()) open @endif>
            <summary class="inline-flex h-11 cursor-pointer list-none items-center justify-center rounded-control {{ $temOrcamento ? 'border border-cedula px-6 font-medium text-cedula hover:bg-cedula/5' : 'bg-cedula px-6 font-semibold text-white hover:bg-cedula-clara' }} font-body-md text-body-md transition-colors focus:outline-none focus:ring-2 focus:ring-primary">
                {{ $temOrcamento ? 'Editar limite' : 'Definir limite do mês' }}
            </summary>
            <form method="POST" action="{{ route('orcamento.definir') }}" class="mt-4 flex flex-col gap-3">
                @csrf
                <input type="hidden" name="mes" value="{{ $mes }}">
                <label for="orc-valor" class="font-body-sm text-body-sm text-on-surface-variant">Limite do mês</label>
                <div class="relative max-w-xs">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 font-value-label text-value-label text-on-surface-variant">R$</span>
                    <input id="orc-valor" name="valor" type="text" inputmode="decimal" placeholder="0,00" value="{{ old('valor', $valorAtual) }}"
                        @class([
                            'input-field h-12 w-full rounded-lg px-4 text-right font-value-label text-value-label text-on-surface',
                            'border-argila focus:border-argila' => $errors->has('valor'),
                        ]) />
                </div>
                @error('valor')
                    <p class="flex items-center gap-1.5 font-label-sm text-label-sm text-argila">
                        <x-icon name="alert" class="h-4 w-4 shrink-0" />{{ $message }}
                    </p>
                @enderror
                <button type="submit"
                    class="inline-flex h-11 w-full max-w-xs items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                    Salvar limite
                </button>
            </form>
        </details>

        {{-- Consumo por categoria (só exibição; no MVP não há limite por categoria). --}}
        @if (count($porCategoria) > 0)
            <section class="flex flex-col gap-3">
                <h3 class="font-body-md text-body-md font-semibold text-on-surface">Por categoria</h3>
                <ul class="flex flex-col divide-y divide-linha rounded-card border border-linha bg-surface-container-lowest">
                    @foreach ($porCategoria as $linha)
                        <li class="flex items-center justify-between gap-4 px-4 py-3">
                            <span class="inline-flex items-center gap-2 font-body-sm text-body-sm text-on-surface">
                                @if ($linha['cor'])
                                    <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $linha['cor'] }}"></span>
                                @endif
                                {{ $linha['nome'] }}
                            </span>
                            <span class="flex items-center gap-3">
                                <span class="font-value-label text-value-label text-on-surface">{{ $linha['valor'] }}</span>
                                <span class="rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-label-sm text-on-surface-variant">sem limite</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.app>
