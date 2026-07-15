{{-- Categorias (spec FE §7.12). A contagem de uso chega PRONTA do backend (CategoriaController →
     ListarCategorias): a UI nunca calcula (regra 4) e só exibe. Criar/editar/arquivar são ações
     explícitas com validação server-side (Form Request); arquivar é lógico e não apaga o histórico
     (regra 7 na borda). Cor e ícone saem de uma paleta fixa; palavras-chave e apelidos alimentam o
     lookup determinístico (doc 08) e são gravados normalizados pelo domínio.

     Criar/editar acontecem num MODAL central (mesma lógica dos lançamentos), servindo os dois
     modos a partir do <x-categoria.form> compartilhado. --}}
@php
    // Reabre o modal certo depois de um erro de validação: "nova" usa o bag default;
    // "editar" usa o bag editarCategoria + o id opaco carregado no old input.
    $erroNova = $errors->getBag('default')->isNotEmpty();
    $bagEditar = $errors->getBag('editarCategoria');
    $editandoId = old('_categoria');
@endphp

<x-layouts.app title="Categorias | Agente Financeiro" active="categorias" heading="Categorias">
    @push('scripts')
        @vite('resources/js/pages/categorias.js')
    @endpush

    <div class="flex w-full max-w-3xl flex-col gap-8">
        {{-- Cabeçalho + ação primária (abre o modal de criação). --}}
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-display text-headline-lg font-semibold text-on-surface">Categorias</h2>
            <button type="button" data-cat-open="cat-nova"
                class="inline-flex h-11 items-center gap-2 rounded-control bg-cedula px-5 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-container-low">
                <x-icon name="plus" class="h-5 w-5" />
                Nova categoria
            </button>
        </header>

        {{-- Feedback (POST/PUT server-rendered → flash). --}}
        @if (count($categorias) > 0)
            {{-- Grade de categorias. Cada card: chip (cor + ícone de linha), nome, contagem em mono
                 e o botão "Editar" ancorado à direita da linha, alinhado ao mesmo padding do card
                 que o chip do ícone respeita à esquerda. Editar abre o modal de edição. --}}
            <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($categorias as $cat)
                    @php $abrirEste = $bagEditar->isNotEmpty() && $editandoId === $cat['opaqueId']; @endphp
                    <li class="notebook-card flex items-center gap-4 rounded-card p-5">
                        <span @class(['flex h-11 w-11 shrink-0 items-center justify-center rounded-control', \App\Domain\Categoria\PaletaDeCategoria::classe($cat['cor'] ?? null, 'swatch-empty')])>
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
                        <button type="button" data-cat-open="cat-{{ $cat['opaqueId'] }}"
                            class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-control border border-linha px-3 font-body-sm text-body-sm font-medium text-cedula transition-colors hover:bg-cedula/5 focus:outline-none focus:ring-2 focus:ring-primary">
                            <x-icon name="pencil" class="h-4 w-4" />
                            Editar
                        </button>

                        {{-- Modal de edição desta categoria (prefill server-side; reabre em erro). --}}
                        <x-modal.categoria
                            :id="'cat-' . $cat['opaqueId']"
                            titulo="Editar categoria"
                            :action="route('categorias.update', $cat['opaqueId'])"
                            method="PUT"
                            :categoria="$cat"
                            :cores="$cores"
                            :icones="$icones"
                            submit="Salvar"
                            :errors="$abrirEste ? $bagEditar : null"
                            :open="$abrirEste"
                            :arquivar-action="route('categorias.arquivar', $cat['opaqueId'])" />
                    </li>
                @endforeach
            </ul>
        @else
            {{-- Estado vazio. --}}
            <div class="flex flex-col items-center gap-3 rounded-card border border-dashed border-linha bg-surface-container-lowest px-6 py-14 text-center">
                <x-icon name="tag" class="h-8 w-8 text-outline" />
                <p class="font-headline-md text-headline-md text-on-surface">Crie categorias para organizar seus gastos.</p>
                <p class="font-body-sm text-body-sm text-on-surface-variant">Elas ajudam a classificar os lançamentos automaticamente.</p>
                <button type="button" data-cat-open="cat-nova"
                    class="mt-2 inline-flex h-11 items-center gap-2 rounded-control bg-cedula px-5 font-body-md text-body-md font-semibold text-white transition-colors hover:bg-cedula-clara focus:outline-none focus:ring-2 focus:ring-primary">
                    <x-icon name="plus" class="h-5 w-5" />
                    Nova categoria
                </button>
            </div>
        @endif
    </div>

    {{-- Modal de criação. Fora da grade para existir em qualquer estado; reabre em erro. --}}
    <x-modal.categoria
        id="cat-nova"
        titulo="Nova categoria"
        :action="route('categorias.store')"
        method="POST"
        :cores="$cores"
        :icones="$icones"
        submit="Criar categoria"
        :errors="$errors->getBag('default')"
        :open="$erroNova" />
</x-layouts.app>
