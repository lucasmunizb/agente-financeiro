{{-- Categorias (spec FE §7.12). A contagem de uso chega PRONTA do backend (CategoriaController →
     ListarCategorias): a UI nunca calcula (regra 4) e só exibe. Criar/editar/arquivar são ações
     explícitas com validação server-side (Form Request); arquivar é lógico e não apaga o histórico
     (regra 7 na borda). Cor e ícone saem de uma paleta fixa; palavras-chave e apelidos alimentam o
     lookup determinístico (doc 08) e são gravados normalizados pelo domínio. --}}
@php
    // Reabre o formulário certo depois de um erro de validação: "nova" usa o bag default;
    // "editar" usa o bag editarCategoria + o id opaco carregado no old input.
    $erroNova = $errors->getBag('default')->isNotEmpty();
    $bagEditar = $errors->getBag('editarCategoria');
    $editandoId = old('_categoria');
@endphp

<x-layouts.app title="Categorias | Agente Financeiro" active="categorias" heading="Categorias">
    <div class="flex w-full max-w-3xl flex-col gap-8">
        {{-- Cabeçalho + ação primária. --}}
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Categorias</h2>
            <details class="group" @if ($erroNova) open @endif>
                <summary class="inline-flex h-11 cursor-pointer list-none items-center gap-2 rounded-control bg-cedula px-5 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low">
                    <x-icon name="plus" class="h-5 w-5" />
                    Nova categoria
                </summary>
                <div class="mt-4 w-full">
                    <x-categoria.form
                        :action="route('categorias.store')"
                        method="POST"
                        :cores="$cores"
                        :icones="$icones"
                        submit="Criar categoria"
                        :errors="$errors->getBag('default')" />
                </div>
            </details>
        </header>

        {{-- Feedback (POST/PUT server-rendered → flash). --}}
        @if (session('sucesso'))
            <div role="status" class="flex items-start gap-2 rounded-card border border-primary/30 bg-primary-container/10 p-4">
                <x-icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                <p class="font-body-sm text-body-sm text-on-surface">{{ session('sucesso') }}</p>
            </div>
        @endif

        @if (count($categorias) > 0)
            {{-- Grade de categorias. Cada card: chip (cor + ícone de linha), nome, contagem em mono
                 e "Editar" (revela o formulário de edição na própria célula). --}}
            <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($categorias as $cat)
                    @php $abrirEste = $bagEditar->isNotEmpty() && $editandoId === $cat['opaqueId']; @endphp
                    <li class="notebook-card flex flex-col rounded-card p-5">
                        <div class="flex items-center gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-control"
                                style="background-color: {{ $cat['cor'] ?? 'var(--color-surface-container)' }}">
                                <x-icon :name="$cat['icone']" @class([
                                    'h-5 w-5',
                                    'text-white' => $cat['cor'],
                                    'text-on-surface-variant' => ! $cat['cor'],
                                ]) />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-body-md text-body-md font-semibold text-on-surface">{{ $cat['nome'] }}</p>
                                <p class="font-value-label text-value-label text-on-surface-variant">{{ $cat['usos'] }} {{ $cat['usos'] === 1 ? 'uso' : 'usos' }}</p>
                            </div>
                        </div>

                        <details class="mt-1" @if ($abrirEste) open @endif>
                            <summary class="inline-flex h-9 cursor-pointer list-none items-center gap-1.5 self-start rounded-control px-2 font-body-sm text-body-sm text-cedula transition-colors hover:bg-cedula/5 focus:outline-none focus:ring-2 focus:ring-primary">
                                <x-icon name="pencil" class="h-4 w-4" />
                                Editar
                            </summary>
                            <div class="mt-4 border-t border-linha pt-4">
                                <x-categoria.form
                                    :action="route('categorias.update', $cat['opaqueId'])"
                                    method="PUT"
                                    :categoria="$cat"
                                    :cores="$cores"
                                    :icones="$icones"
                                    submit="Salvar"
                                    :errors="$abrirEste ? $bagEditar : null" />

                                {{-- Arquivar: não apaga o histórico; sai da grade e do lookup. --}}
                                <form method="POST" action="{{ route('categorias.arquivar', $cat['opaqueId']) }}" class="mt-3">
                                    @csrf
                                    <button type="submit"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-control px-2 font-body-sm text-body-sm text-argila transition-colors hover:bg-argila/5 focus:outline-none focus:ring-2 focus:ring-argila">
                                        <x-icon name="x" class="h-4 w-4" />
                                        Arquivar
                                    </button>
                                </form>
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        @else
            {{-- Estado vazio. --}}
            <div class="flex flex-col items-center gap-3 rounded-card border border-dashed border-linha bg-surface-container-lowest px-6 py-14 text-center">
                <x-icon name="tag" class="h-8 w-8 text-outline" />
                <p class="font-headline-md text-headline-md text-on-surface">Crie categorias para organizar seus gastos.</p>
                <p class="font-body-sm text-body-sm text-on-surface-variant">Elas ajudam a classificar os lançamentos automaticamente.</p>
            </div>
        @endif
    </div>
</x-layouts.app>
