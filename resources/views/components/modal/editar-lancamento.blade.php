@props([
    'transaction',        // Transaction a editar (id nas rotas)
    'open' => false,      // abre já no carregamento (?editar=1)
    'cartoes' => null,    // cartões do usuário (Collection<Card>)
    'categorias' => null, // categorias não arquivadas do usuário (Collection<Category>)
    'dados' => null,      // prefill do lançamento
])

{{-- Modal "Editar lançamento" (spec §7.8) — wrapper fino: chrome do diálogo (overlay, card,
     cabeçalho, fechar) + o formulário COMPARTILHADO <x-gasto.form> em modo EDIÇÃO. Reusa o
     MESMO id/hook do modal de registrar (`modal-registrar-gasto` + `data-rg-*`), então o
     `registrar-gasto.js` dirige abrir/fechar/esc/backdrop/auto-abrir sem mudança. O fluxo em
     dois passos (prévia calculada pelo backend → confirmar, regra 7) e o cálculo
     determinístico (regra 4) vivem no componente e no JS. Após gravar, volta para a lista
     (redirect do update). Ícones SVG inline (regra 6). --}}
<div id="modal-registrar-gasto"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-backdrop"
    data-rg-autoopen="{{ $open ? '1' : '0' }}"
    data-rg-initial=""
    @unless ($open) hidden @endunless>

    <div class="modal-card-enter notebook-card flex max-h-[92dvh] w-full max-w-[640px] flex-col overflow-hidden rounded-card"
        role="dialog" aria-modal="true" aria-labelledby="rg-title">

        <header class="flex items-center justify-between border-b border-linha px-gutter py-4">
            <h2 id="rg-title" class="font-headline-md text-headline-md text-on-surface">Editar lançamento</h2>
            <button type="button" data-rg-close aria-label="Fechar"
                class="flex h-11 w-11 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-surface-container active:scale-95">
                <x-icon name="x" class="h-5 w-5" />
            </button>
        </header>

        <x-gasto.form
            context="modal"
            mode="edit"
            :cartoes="$cartoes"
            :categorias="$categorias"
            :dados="$dados"
            :previa-url="route('lancamentos.previa', $transaction)"
            :submit-url="route('lancamentos.update', $transaction)"
            submit-method="PUT"
            :redirect="route('lancamentos')" />
    </div>
</div>
