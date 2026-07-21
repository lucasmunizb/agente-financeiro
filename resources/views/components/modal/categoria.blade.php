@props([
    'id',                     // id do <dialog> (ex.: 'cat-nova' ou 'cat-<opaqueId>')
    'titulo',                 // título do cabeçalho do modal
    'action',                 // rota de gravação (store ou update)
    'method' => 'POST',       // POST (criar) ou PUT (editar, via spoof)
    'categoria' => null,      // array de prefill na edição; null ao criar
    'cores' => [],            // paleta de cores (#RRGGBB)
    'icones' => [],           // paleta de ícones de linha (nomes do x-icon)
    'submit' => 'Salvar',     // rótulo do botão primário
    'errors' => null,         // MessageBag DESTE formulário (ou null)
    'open' => false,          // reabrir já no carregamento (pós-erro de validação)
    'arquivarAction' => null, // rota de arquivar (só na edição); null oculta a ação
])

{{-- Modal de categoria (criar/editar) — mesma lógica dos lançamentos: um diálogo central
     sobre backdrop esfumaçado, servindo criação E edição a partir do MESMO formulário
     compartilhado <x-categoria.form>. Usa o <dialog> nativo (foco preso, esc e backdrop de
     graça — menos JS, regra 3). A gravação é server-side (POST/PUT → redirect): categoria
     não é cálculo de dinheiro, então não há passo de prévia; a validação é do Form Request.
     Após erro, o servidor marca data-open e o categorias.js reabre o diálogo certo. --}}
<dialog id="{{ $id }}" class="modal-dialog" @if ($open) data-open @endif aria-labelledby="{{ $id }}-title">
    <div class="modal-card-enter notebook-card flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-card">
        <header class="flex items-center justify-between border-b border-linha px-gutter py-4">
            <h2 id="{{ $id }}-title" class="font-headline-md text-headline-md text-on-surface">{{ $titulo }}</h2>
            <button type="button" data-cat-close aria-label="Fechar"
                class="flex h-11 w-11 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-surface-container active:scale-95 focus:outline-none focus:ring-2 focus:ring-primary">
                <x-icon name="x" class="h-5 w-5" />
            </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-gutter py-5">
            <x-categoria.form
                :action="$action"
                :method="$method"
                :categoria="$categoria"
                :cores="$cores"
                :icones="$icones"
                :submit="$submit"
                :errors="$errors" />

            {{-- Arquivar: ação lógica (não apaga o histórico; sai da grade e do lookup).
                 Fora do <form> de edição (formulários não aninham). --}}
            @if ($arquivarAction)
                <form method="POST" action="{{ $arquivarAction }}" class="mt-5 border-t border-linha pt-4">
                    @csrf
                    <button type="submit"
                        class="inline-flex h-9 items-center gap-1.5 rounded-control px-2 font-body-sm text-body-sm text-argila transition-colors hover:bg-argila/5 focus:outline-none focus:ring-2 focus:ring-argila">
                        <x-icon name="x" class="h-4 w-4" />
                        Arquivar categoria
                    </button>
                </form>
            @endif
        </div>
    </div>
</dialog>
