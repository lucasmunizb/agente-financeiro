@props([
    'open' => false, // abre já no carregamento (afordância de revisão, ?modal=…)
    'initial' => null, // estado inicial para revisão: 'erro' | 'salvando' | null
    'cartoes' => null, // cartões do usuário (Collection<Card>)
    'categorias' => null, // categorias não arquivadas do usuário (Collection<Category>)
])

{{-- Modal "Registrar gasto" (spec §7.7b) — wrapper fino: a chrome do diálogo (overlay,
     card, cabeçalho, fechar) + o formulário COMPARTILHADO <x-gasto.form> em modo criação.
     A MESMA tela de criar/editar (§7.7) usa esse componente como página cheia. O fluxo em
     dois passos, o cálculo determinístico (regra 4) e a confirmação (regra 7) vivem no
     componente e no JS (dirigido por [data-rg-root]). Ícones SVG inline (regra 6). --}}
<div id="modal-registrar-gasto"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-backdrop"
    data-rg-autoopen="{{ $open ? '1' : '0' }}"
    data-rg-initial="{{ $initial ?? '' }}"
    @unless ($open) hidden @endunless>

    <div class="modal-card-enter notebook-card flex max-h-[92dvh] w-full max-w-[640px] flex-col overflow-hidden rounded-card"
        role="dialog" aria-modal="true" aria-labelledby="rg-title">

        <header class="flex items-center justify-between border-b border-linha px-gutter py-4">
            <h2 id="rg-title" class="font-headline-md text-headline-md text-on-surface">Registrar gasto</h2>
            <button type="button" data-rg-close aria-label="Fechar"
                class="flex h-11 w-11 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-surface-container active:scale-95">
                <x-icon name="x" class="h-5 w-5" />
            </button>
        </header>

        <x-gasto.form
            context="modal"
            mode="create"
            :cartoes="$cartoes"
            :categorias="$categorias"
            :previa-url="route('gastos.previa')"
            :submit-url="route('gastos.store')"
            submit-method="POST" />
    </div>
</div>
