{{-- Receitas (spec FE §7.10). Lista as receitas do mês e o total (base do "disponível"). O
     total chega PRONTO do backend (ReceitasDoMes): a UI não soma (regra 4) e só exibe (pt-BR,
     regra 5). Adicionar é em DOIS PASSOS (regra 7): "Revisar e confirmar" mostra o resumo sem
     gravar; "Confirmar" grava. Sem JS — o passo de confirmação é server-rendered. --}}
@php $confirmar = $confirmar ?? null; @endphp
<x-layouts.app title="Receitas | Agente Financeiro" active="receitas" heading="Receitas">
    <div class="flex w-full max-w-2xl flex-col gap-8">
        {{-- Cabeçalho + "Adicionar receita". --}}
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Receitas</h2>
            <details class="relative" @if ($errors->any()) open @endif>
                <summary class="inline-flex h-11 cursor-pointer list-none items-center justify-center gap-1.5 rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="plus" class="h-4 w-4" /> Adicionar receita
                </summary>
                <form method="POST" action="{{ route('receitas.store') }}"
                    class="mt-3 flex w-[min(92vw,26rem)] flex-col gap-4 rounded-card border border-linha bg-superficie p-5 shadow-lg sm:absolute sm:right-0 sm:z-10">
                    @csrf
                    <div class="flex flex-col gap-2">
                        <label for="r-descricao" class="font-body-sm text-body-sm text-on-surface-variant">Descrição *</label>
                        <input id="r-descricao" name="descricao" type="text" maxlength="255" value="{{ old('descricao') }}" placeholder="Ex.: Salário"
                            @class(['input-field h-12 rounded-lg px-4 font-body-md text-body-md text-on-surface', 'border-argila' => $errors->has('descricao')]) />
                        @error('descricao')<p class="font-label-sm text-label-sm text-argila">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="r-valor" class="font-body-sm text-body-sm text-on-surface-variant">Valor *</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 font-value-label text-value-label text-on-surface-variant">R$</span>
                            <input id="r-valor" name="valor" type="text" inputmode="decimal" value="{{ old('valor') }}" placeholder="0,00"
                                @class(['input-field h-12 w-full rounded-lg px-4 text-right font-value-label text-value-label text-on-surface', 'border-argila' => $errors->has('valor')]) />
                        </div>
                        @error('valor')<p class="font-label-sm text-label-sm text-argila">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-col gap-2">
                        <span class="font-body-sm text-body-sm text-on-surface-variant">Tipo *</span>
                        <div class="flex gap-1 rounded-lg bg-surface-container p-1">
                            @foreach (['fixa' => 'Fixa', 'variavel' => 'Variável'] as $val => $label)
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="tipo" value="{{ $val }}" class="peer sr-only" @checked(old('tipo', 'fixa') === $val)>
                                    <span class="block rounded-md py-2 text-center font-body-sm text-body-sm font-medium text-on-surface-variant transition-all peer-checked:border peer-checked:border-linha peer-checked:bg-superficie peer-checked:text-primary peer-checked:shadow-sm">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('tipo')<p class="font-label-sm text-label-sm text-argila">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="r-data" class="font-body-sm text-body-sm text-on-surface-variant">Data *</label>
                        <input id="r-data" name="data" type="date" value="{{ old('data', $dataPadrao) }}"
                            @class(['input-field h-12 rounded-lg px-4 font-body-md text-body-md text-on-surface-variant', 'border-argila' => $errors->has('data')]) />
                        @error('data')<p class="font-label-sm text-label-sm text-argila">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit"
                        class="inline-flex h-11 items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                        Revisar e confirmar
                    </button>
                </form>
            </details>
        </header>

        {{-- Passo 2 (regra 7): confirmação com o resumo do que será salvo. --}}
        @if ($confirmar)
            <article class="notebook-card flex flex-col gap-4 rounded-card border-2 border-cedula p-6">
                <h3 class="font-headline-md text-headline-md text-on-surface">Confirme a receita</h3>
                <p class="font-body-sm text-body-sm text-outline">Nada foi salvo ainda — confira e confirme.</p>
                <dl class="space-y-3 rounded-lg border border-linha bg-surface-container-lowest p-4">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="font-body-sm text-body-sm text-outline">Descrição</dt>
                        <dd class="font-body-md text-body-md text-on-surface">{{ $confirmar['descricao'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="font-body-sm text-body-sm text-outline">Valor</dt>
                        <dd class="font-value-display text-value-display text-on-surface">{{ $confirmar['valor'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="font-body-sm text-body-sm text-outline">Tipo</dt>
                        <dd class="font-body-md text-body-md text-on-surface">{{ $confirmar['tipo'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="font-body-sm text-body-sm text-outline">Data</dt>
                        <dd class="font-value-label text-value-label text-on-surface">{{ $confirmar['data'] }}</dd>
                    </div>
                </dl>
                <div class="flex flex-col gap-3 sm:flex-row-reverse">
                    <form method="POST" action="{{ route('receitas.store') }}">
                        @csrf
                        <input type="hidden" name="confirmado" value="1">
                        <input type="hidden" name="descricao" value="{{ $confirmar['raw']['descricao'] }}">
                        <input type="hidden" name="valor" value="{{ $confirmar['raw']['valor'] }}">
                        <input type="hidden" name="tipo" value="{{ $confirmar['raw']['tipo'] }}">
                        <input type="hidden" name="data" value="{{ $confirmar['raw']['data'] }}">
                        <button type="submit"
                            class="inline-flex h-11 w-full items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary sm:w-auto">
                            Confirmar
                        </button>
                    </form>
                    <a href="{{ route('receitas') }}"
                        class="inline-flex h-11 items-center justify-center rounded-control border border-cedula px-6 font-body-md text-body-md font-medium text-cedula transition-colors hover:bg-cedula/5 sm:w-auto">
                        Voltar
                    </a>
                </div>
            </article>
        @endif

        {{-- Resumo do mês + seletor de competência. --}}
        <article class="notebook-card flex flex-col gap-4 rounded-card p-6">
            <div class="flex items-center justify-center gap-3">
                <a href="{{ route('receitas', ['mes' => $mesAnterior, 'tipo' => $tipoAtivo]) }}" rel="prev"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-linha text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="chevron-left" class="h-5 w-5" /><span class="sr-only">Mês anterior</span>
                </a>
                <span class="min-w-40 text-center font-value-label text-value-label text-on-surface">{{ $mesLabel }}</span>
                <a href="{{ route('receitas', ['mes' => $mesSeguinte, 'tipo' => $tipoAtivo]) }}" rel="next"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-linha text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="chevron-right" class="h-5 w-5" /><span class="sr-only">Próximo mês</span>
                </a>
            </div>
            <div class="flex flex-col items-center gap-1">
                <span class="font-body-sm text-body-sm text-on-surface-variant">Receitas de {{ $mesNome }}</span>
                <span class="font-value-display text-value-display text-on-surface">{{ $total }}</span>
            </div>
        </article>

        {{-- Filtro por tipo. --}}
        <div class="flex gap-1 self-start rounded-lg bg-surface-container p-1">
            @foreach (['' => 'Todas', 'fixa' => 'Fixa', 'variavel' => 'Variável'] as $val => $label)
                @php $ativo = ($tipoAtivo ?? '') === $val; @endphp
                <a href="{{ route('receitas', ['tipo' => $val ?: null, 'mes' => $mes]) }}"
                    @class([
                        'rounded-md px-4 py-2 font-body-sm text-body-sm font-medium transition-all',
                        'border border-linha bg-superficie text-primary shadow-sm' => $ativo,
                        'text-on-surface-variant hover:bg-superficie/60' => ! $ativo,
                    ])>{{ $label }}</a>
            @endforeach
        </div>

        {{-- Lista (extrato). Cada linha tem ações: editar (form prefilled) e excluir (soft
             delete, confirmação). A edição reabre a linha certa no erro pelo seu error bag. --}}
        @php $bagEditar = $errors->getBag('editarReceita'); @endphp
        @if (count($itens) > 0)
            <ul class="flex flex-col divide-y divide-linha rounded-card border border-linha bg-surface-container-lowest">
                @foreach ($itens as $item)
                    <li class="flex flex-col gap-3 px-4 py-3">
                        <div class="flex items-center justify-between gap-4">
                            <span class="flex min-w-0 items-center gap-3">
                                <span class="truncate font-body-md text-body-md text-on-surface">{{ $item['descricao'] }}</span>
                                <span class="shrink-0 rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-label-sm text-on-surface-variant">{{ $item['tipo'] }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-3">
                                <span class="font-value-label text-value-label text-outline">{{ $item['data'] }}</span>
                                <span class="font-value-label text-value-label text-on-surface">{{ $item['valor'] }}</span>
                            </span>
                        </div>
                        <div class="flex flex-wrap items-start gap-2">
                            {{-- Editar --}}
                            <details @if (old('_editando') === $item['opaqueId'] && $bagEditar->isNotEmpty()) open @endif>
                                <summary class="inline-flex h-9 cursor-pointer list-none items-center gap-1.5 rounded-control px-3 font-label-sm text-label-sm font-medium text-cedula transition-colors hover:bg-cedula/5 focus:outline-none focus:ring-2 focus:ring-primary">
                                    <x-icon name="pencil" class="h-4 w-4" /> Editar receita
                                </summary>
                                <form method="POST" action="{{ route('receitas.update', $item['opaqueId']) }}"
                                    class="mt-2 flex max-w-md flex-col gap-4 rounded-card border border-linha bg-superficie p-4">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="_editando" value="{{ $item['opaqueId'] }}">
                                    <div class="flex flex-col gap-2">
                                        <label class="font-body-sm text-body-sm text-on-surface-variant">Descrição</label>
                                        <input name="descricao" type="text" maxlength="255" value="{{ old('_editando') === $item['opaqueId'] ? old('descricao', $item['descricao']) : $item['descricao'] }}"
                                            class="input-field h-12 rounded-lg px-4 font-body-md text-body-md text-on-surface" />
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <label class="font-body-sm text-body-sm text-on-surface-variant">Valor</label>
                                        <div class="relative">
                                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 font-value-label text-value-label text-on-surface-variant">R$</span>
                                            <input name="valor" type="text" inputmode="decimal" value="{{ old('_editando') === $item['opaqueId'] ? old('valor', $item['valorInput']) : $item['valorInput'] }}"
                                                @class(['input-field h-12 w-full rounded-lg px-4 text-right font-value-label text-value-label text-on-surface', 'border-argila' => old('_editando') === $item['opaqueId'] && $bagEditar->has('valor')]) />
                                        </div>
                                        @if (old('_editando') === $item['opaqueId'] && $bagEditar->has('valor'))<p class="font-label-sm text-label-sm text-argila">{{ $bagEditar->first('valor') }}</p>@endif
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <span class="font-body-sm text-body-sm text-on-surface-variant">Tipo</span>
                                        <div class="flex gap-1 rounded-lg bg-surface-container p-1">
                                            @php $tipoAtual = old('_editando') === $item['opaqueId'] ? old('tipo', $item['tipoCodigo']) : $item['tipoCodigo']; @endphp
                                            @foreach (['fixa' => 'Fixa', 'variavel' => 'Variável'] as $val => $label)
                                                <label class="flex-1 cursor-pointer">
                                                    <input type="radio" name="tipo" value="{{ $val }}" class="peer sr-only" @checked($tipoAtual === $val)>
                                                    <span class="block rounded-md py-2 text-center font-body-sm text-body-sm font-medium text-on-surface-variant transition-all peer-checked:border peer-checked:border-linha peer-checked:bg-superficie peer-checked:text-primary peer-checked:shadow-sm">{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <label class="font-body-sm text-body-sm text-on-surface-variant">Data</label>
                                        <input name="data" type="date" value="{{ old('_editando') === $item['opaqueId'] ? old('data', $item['dataIso']) : $item['dataIso'] }}"
                                            class="input-field h-12 rounded-lg px-4 font-body-md text-body-md text-on-surface-variant" />
                                    </div>
                                    <button type="submit"
                                        class="inline-flex h-11 w-fit items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary active:scale-95">
                                        Salvar alterações
                                    </button>
                                </form>
                            </details>

                            {{-- Excluir (cancelamento lógico) --}}
                            <details>
                                <summary class="inline-flex h-9 cursor-pointer list-none items-center gap-1.5 rounded-control px-3 font-label-sm text-label-sm font-medium text-error transition-colors hover:bg-error/5 focus:outline-none focus:ring-2 focus:ring-error">
                                    <x-icon name="x" class="h-4 w-4" /> Excluir receita
                                </summary>
                                <form method="POST" action="{{ route('receitas.destroy', $item['opaqueId']) }}"
                                    class="mt-2 flex max-w-sm flex-col gap-3 rounded-control border border-error/30 bg-error/5 p-4">
                                    @csrf
                                    @method('DELETE')
                                    <p class="font-body-sm text-body-sm text-on-surface">A receita sai da lista, mas o histórico é preservado.</p>
                                    <button type="submit"
                                        class="inline-flex h-11 items-center justify-center rounded-control bg-error px-4 font-body-sm text-body-sm font-semibold text-white transition-colors hover:bg-error/90 focus:outline-none focus:ring-2 focus:ring-error active:scale-95">
                                        Confirmar exclusão
                                    </button>
                                </form>
                            </details>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="flex flex-col items-center gap-3 rounded-card border border-dashed border-linha bg-surface-container-lowest px-6 py-16 text-center">
                <x-icon name="arrow-up" class="h-8 w-8 text-outline" />
                <p class="font-headline-md text-headline-md text-on-surface">Nenhuma receita neste mês.</p>
                <p class="max-w-sm font-body-sm text-body-sm text-on-surface-variant">Adicione seu salário e outras entradas para acompanhar o disponível.</p>
            </div>
        @endif
    </div>
</x-layouts.app>
