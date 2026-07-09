{{-- Cartões & faturas (spec FE §7.13). Lista os cartões e a fatura do selecionado por
     competência. Total e frações de parcela chegam PRONTOS do backend (ConsultarFaturaCartao);
     as datas/status do ciclo, do CicloDaFatura (consistente com §4.2). A UI nunca calcula
     (regra 4) e só exibe (pt-BR, regra 5). Cartão = descrição + 4 dígitos finais (nunca o número
     completo — LGPD/§4.6). --}}
<x-layouts.app title="Cartões & faturas | Agente Financeiro" active="cartoes" heading="Cartões">
    <div class="flex w-full max-w-2xl flex-col gap-8">
        {{-- Cabeçalho + "Adicionar cartão". --}}
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Cartões &amp; faturas</h2>
            <details class="relative" @if ($errors->any()) open @endif>
                <summary class="inline-flex h-11 cursor-pointer list-none items-center justify-center gap-1.5 rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="plus" class="h-4 w-4" /> Adicionar cartão
                </summary>
                <form method="POST" action="{{ route('cartoes.store') }}"
                    class="mt-3 flex w-[min(92vw,26rem)] flex-col gap-4 rounded-card border border-linha bg-superficie p-5 shadow-lg sm:absolute sm:right-0 sm:z-10">
                    @csrf
                    <div class="flex flex-col gap-2">
                        <label for="c-descricao" class="font-body-sm text-body-sm text-on-surface-variant">Descrição</label>
                        <input id="c-descricao" name="descricao" type="text" maxlength="255" value="{{ old('descricao') }}" placeholder="Ex.: Nubank"
                            @class(['input-field h-12 rounded-lg px-4 font-body-md text-body-md text-on-surface', 'border-argila' => $errors->has('descricao')]) />
                        @error('descricao')<p class="font-label-sm text-label-sm text-argila">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="c-final4" class="font-body-sm text-body-sm text-on-surface-variant">4 dígitos finais</label>
                        <input id="c-final4" name="final_4" type="text" inputmode="numeric" maxlength="4" value="{{ old('final_4') }}" placeholder="1234"
                            @class(['input-field h-12 w-28 rounded-lg px-4 font-value-label text-value-label text-on-surface', 'border-argila' => $errors->has('final_4')]) />
                        @error('final_4')<p class="font-label-sm text-label-sm text-argila">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex gap-4">
                        <div class="flex flex-col gap-2">
                            <label for="c-fech" class="font-body-sm text-body-sm text-on-surface-variant">Fecha dia</label>
                            <input id="c-fech" name="dia_fechamento" type="number" inputmode="numeric" min="1" max="31" value="{{ old('dia_fechamento') }}"
                                @class(['input-field h-12 w-24 rounded-lg px-4 font-value-label text-value-label text-on-surface', 'border-argila' => $errors->has('dia_fechamento')]) />
                        </div>
                        <div class="flex flex-col gap-2">
                            <label for="c-venc" class="font-body-sm text-body-sm text-on-surface-variant">Vence dia</label>
                            <input id="c-venc" name="dia_vencimento" type="number" inputmode="numeric" min="1" max="31" value="{{ old('dia_vencimento') }}"
                                @class(['input-field h-12 w-24 rounded-lg px-4 font-value-label text-value-label text-on-surface', 'border-argila' => $errors->has('dia_vencimento')]) />
                        </div>
                    </div>
                    @error('dia_fechamento')<p class="font-label-sm text-label-sm text-argila">{{ $message }}</p>@enderror
                    @error('dia_vencimento')<p class="font-label-sm text-label-sm text-argila">{{ $message }}</p>@enderror
                    <div class="flex flex-col gap-2">
                        <label for="c-limite" class="font-body-sm text-body-sm text-on-surface-variant">Limite (opcional)</label>
                        <div class="relative w-40">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 font-value-label text-value-label text-on-surface-variant">R$</span>
                            <input id="c-limite" name="limite" type="text" inputmode="decimal" value="{{ old('limite') }}" placeholder="0,00"
                                class="input-field h-12 w-full rounded-lg px-4 text-right font-value-label text-value-label text-on-surface" />
                        </div>
                    </div>
                    <button type="submit"
                        class="inline-flex h-11 items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                        Salvar cartão
                    </button>
                </form>
            </details>
        </header>

        @if (session('sucesso'))
            <div role="status" class="flex items-start gap-2 rounded-card border border-primary/30 bg-primary-container/10 p-4">
                <x-icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                <p class="font-body-sm text-body-sm text-on-surface">{{ session('sucesso') }}</p>
            </div>
        @endif

        @if (! $temCartao)
            {{-- Estado sem cartão. --}}
            <div class="flex flex-col items-center gap-3 rounded-card border border-dashed border-linha bg-surface-container-lowest px-6 py-16 text-center">
                <x-icon name="credit-card" class="h-8 w-8 text-outline" />
                <p class="font-headline-md text-headline-md text-on-surface">Cadastre um cartão para acompanhar as faturas.</p>
            </div>
        @else
            {{-- Faixa de cartões (selecionáveis). --}}
            <div class="flex gap-3 overflow-x-auto pb-1">
                @foreach ($cartoes as $card)
                    <a href="{{ route('cartoes', ['cartao' => $card['opaqueId'], 'mes' => $mes]) }}"
                        @if ($card['selecionado']) aria-current="true" @endif
                        @class([
                            'flex min-w-52 shrink-0 flex-col gap-1 rounded-card border-2 bg-superficie p-4 transition-colors focus:outline-none focus:ring-2 focus:ring-primary',
                            'border-cedula' => $card['selecionado'],
                            'border-linha hover:border-nevoa' => ! $card['selecionado'],
                        ])>
                        <span class="font-body-md text-body-md font-semibold text-on-surface">{{ $card['descricao'] }}</span>
                        <span class="font-value-label text-value-label text-on-surface-variant">•••• {{ $card['final4'] }}</span>
                        <span class="font-label-sm text-label-sm text-outline">fecha dia {{ $card['diaFechamento'] }} · vence dia {{ $card['diaVencimento'] }}</span>
                    </a>
                @endforeach
            </div>

            {{-- Fatura do cartão selecionado. --}}
            <article class="notebook-card flex flex-col gap-5 rounded-card p-6">
                {{-- Seletor de mês. --}}
                <div class="flex items-center justify-center gap-3">
                    <a href="{{ route('cartoes', ['cartao' => $cartaoSelecionado, 'mes' => $mesAnterior]) }}" rel="prev"
                        class="flex h-9 w-9 items-center justify-center rounded-full border border-linha text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                        <x-icon name="chevron-left" class="h-5 w-5" /><span class="sr-only">Mês anterior</span>
                    </a>
                    <span class="min-w-40 text-center font-value-label text-value-label text-on-surface">{{ $mesLabel }}</span>
                    <a href="{{ route('cartoes', ['cartao' => $cartaoSelecionado, 'mes' => $mesSeguinte]) }}" rel="next"
                        class="flex h-9 w-9 items-center justify-center rounded-full border border-linha text-on-surface-variant transition-colors hover:bg-surface-container focus:outline-none focus:ring-2 focus:ring-primary">
                        <x-icon name="chevron-right" class="h-5 w-5" /><span class="sr-only">Próximo mês</span>
                    </a>
                </div>

                {{-- Cabeçalho da fatura: total + ciclo + selo. --}}
                <div class="flex flex-wrap items-end justify-between gap-3 border-b border-linha pb-4">
                    <div class="flex flex-col gap-1">
                        <span class="font-body-sm text-body-sm text-on-surface-variant">Total da fatura</span>
                        <span class="font-value-display text-value-display text-on-surface">{{ $faturaTotal }}</span>
                        <span class="font-label-sm text-label-sm text-outline">fecha {{ $fecha }} · vence {{ $vence }}</span>
                    </div>
                    @if ($aberta)
                        <span class="inline-flex items-center rounded-full bg-ocre/15 px-3 py-1 font-label-sm text-label-sm font-medium text-ocre" title="Fatura aberta">aberta</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-surface-container px-3 py-1 font-label-sm text-label-sm font-medium text-on-surface-variant" title="Fatura fechada">fechada</span>
                    @endif
                </div>

                {{-- Extrato da fatura. --}}
                @if (count($itens) > 0)
                    <ul class="flex flex-col divide-y divide-linha">
                        @foreach ($itens as $item)
                            <li class="flex items-center justify-between gap-4 py-3">
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <span class="truncate font-body-md text-body-md text-on-surface">{{ $item['descricao'] }}</span>
                                    @if ($item['categoria'])
                                        <span class="w-fit rounded-full bg-surface-container px-2.5 py-0.5 font-label-sm text-label-sm text-on-surface-variant">{{ $item['categoria'] }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 font-value-label text-value-label text-on-surface">{{ $item['valor'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="py-4 text-center font-body-sm text-body-sm text-on-surface-variant">Nenhuma cobrança nesta competência.</p>
                @endif
            </article>

            {{-- Ações do cartão selecionado: editar (form prefilled, com limite) e remover
                 (cancelamento lógico — soft delete). Cada uma numa <details> (sem JS); a de
                 edição reabre no erro pelo seu error bag próprio (editarCartao). --}}
            @php $bagEditar = $errors->getBag('editarCartao'); @endphp
            <div class="flex flex-wrap items-start gap-3">
                <details class="flex-1" @if ($bagEditar->isNotEmpty()) open @endif>
                    <summary class="inline-flex h-11 cursor-pointer list-none items-center justify-center gap-1.5 rounded-control border border-cedula px-6 font-body-md text-body-md font-medium text-cedula transition-colors hover:bg-cedula/5 focus:outline-none focus:ring-2 focus:ring-primary">
                        <x-icon name="pencil" class="h-4 w-4" /> Editar cartão
                    </summary>
                    <form method="POST" action="{{ route('cartoes.update', $cartaoSelecionado) }}"
                        class="mt-3 flex max-w-md flex-col gap-4 rounded-card border border-linha bg-superficie p-5">
                        @csrf
                        @method('PUT')
                        <div class="flex flex-col gap-2">
                            <label for="e-descricao" class="font-body-sm text-body-sm text-on-surface-variant">Descrição</label>
                            <input id="e-descricao" name="descricao" type="text" maxlength="255" value="{{ old('descricao', $selecionadoDados['descricao']) }}"
                                @class(['input-field h-12 rounded-lg px-4 font-body-md text-body-md text-on-surface', 'border-argila' => $bagEditar->has('descricao')]) />
                            @if ($bagEditar->has('descricao'))<p class="font-label-sm text-label-sm text-argila">{{ $bagEditar->first('descricao') }}</p>@endif
                        </div>
                        <div class="flex flex-col gap-2">
                            <label for="e-final4" class="font-body-sm text-body-sm text-on-surface-variant">4 dígitos finais</label>
                            <input id="e-final4" name="final_4" type="text" inputmode="numeric" maxlength="4" value="{{ old('final_4', $selecionadoDados['final4']) }}"
                                @class(['input-field h-12 w-28 rounded-lg px-4 font-value-label text-value-label text-on-surface', 'border-argila' => $bagEditar->has('final_4')]) />
                            @if ($bagEditar->has('final_4'))<p class="font-label-sm text-label-sm text-argila">{{ $bagEditar->first('final_4') }}</p>@endif
                        </div>
                        <div class="flex gap-4">
                            <div class="flex flex-col gap-2">
                                <label for="e-fech" class="font-body-sm text-body-sm text-on-surface-variant">Fecha dia</label>
                                <input id="e-fech" name="dia_fechamento" type="number" inputmode="numeric" min="1" max="31" value="{{ old('dia_fechamento', $selecionadoDados['diaFechamento']) }}"
                                    @class(['input-field h-12 w-24 rounded-lg px-4 font-value-label text-value-label text-on-surface', 'border-argila' => $bagEditar->has('dia_fechamento')]) />
                            </div>
                            <div class="flex flex-col gap-2">
                                <label for="e-venc" class="font-body-sm text-body-sm text-on-surface-variant">Vence dia</label>
                                <input id="e-venc" name="dia_vencimento" type="number" inputmode="numeric" min="1" max="31" value="{{ old('dia_vencimento', $selecionadoDados['diaVencimento']) }}"
                                    @class(['input-field h-12 w-24 rounded-lg px-4 font-value-label text-value-label text-on-surface', 'border-argila' => $bagEditar->has('dia_vencimento')]) />
                            </div>
                        </div>
                        @if ($bagEditar->has('dia_fechamento'))<p class="font-label-sm text-label-sm text-argila">{{ $bagEditar->first('dia_fechamento') }}</p>@endif
                        @if ($bagEditar->has('dia_vencimento'))<p class="font-label-sm text-label-sm text-argila">{{ $bagEditar->first('dia_vencimento') }}</p>@endif
                        <div class="flex flex-col gap-2">
                            <label for="e-limite" class="font-body-sm text-body-sm text-on-surface-variant">Limite (opcional)</label>
                            <div class="relative w-40">
                                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 font-value-label text-value-label text-on-surface-variant">R$</span>
                                <input id="e-limite" name="limite" type="text" inputmode="decimal" value="{{ old('limite', $selecionadoDados['limite']) }}" placeholder="0,00"
                                    class="input-field h-12 w-full rounded-lg px-4 text-right font-value-label text-value-label text-on-surface" />
                            </div>
                        </div>
                        <button type="submit"
                            class="inline-flex h-11 w-fit items-center justify-center rounded-control bg-cedula px-6 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                            Salvar alterações
                        </button>
                    </form>
                </details>

                <details class="text-left">
                    <summary class="inline-flex h-11 cursor-pointer list-none items-center justify-center gap-1.5 rounded-control border border-error/40 px-6 font-body-md text-body-md font-medium text-error transition-colors hover:bg-error/5 focus:outline-none focus:ring-2 focus:ring-error">
                        <x-icon name="x" class="h-4 w-4" /> Remover cartão
                    </summary>
                    <form method="POST" action="{{ route('cartoes.destroy', $cartaoSelecionado) }}"
                        class="mt-2 flex max-w-sm flex-col gap-3 rounded-control border border-error/30 bg-error/5 p-4">
                        @csrf
                        @method('DELETE')
                        <p class="font-body-sm text-body-sm text-on-surface">
                            O cartão sai da lista, mas os lançamentos já feitos nele permanecem (o histórico é preservado).
                        </p>
                        <button type="submit"
                            class="inline-flex h-11 items-center justify-center rounded-control bg-error px-4 font-body-sm text-body-sm font-semibold text-white transition-colors hover:bg-error/90 focus:outline-none focus:ring-2 focus:ring-error focus:ring-offset-2 focus:ring-offset-surface-container-low active:scale-95">
                            Confirmar remoção
                        </button>
                    </form>
                </details>
            </div>
        @endif
    </div>
</x-layouts.app>
