{{-- Página de criar/editar lançamento (spec FE §7.7). É a MESMA tela do modal rápido
     (§7.7b), como página cheia: renderiza o componente compartilhado <x-gasto.form> dentro
     do shell padrão. O fluxo em dois passos (prévia calculada pelo backend → confirmar,
     regra 7), o cálculo determinístico (regra 4) e o JS são os mesmos do modal. --}}
@php
    $titulo = $mode === 'edit' ? 'Editar lançamento' : 'Novo lançamento';
@endphp
<x-layouts.app :title="$titulo.' | Agente Financeiro'" active="transacoes" :heading="$titulo">
    @push('scripts')
        @vite('resources/js/pages/registrar-gasto.js')
    @endpush

    <div class="w-full max-w-2xl">
        <div class="notebook-card flex max-h-[calc(100dvh-9rem)] flex-col overflow-hidden rounded-card">
            @if ($mode === 'edit')
                <x-gasto.form
                    context="page"
                    mode="edit"
                    :cartoes="$cartoes"
                    :categorias="$categorias"
                    :dados="$dados"
                    :bloqueado="$bloqueado"
                    :previa-url="route('lancamentos.previa', $transaction)"
                    :submit-url="route('lancamentos.update', $transaction)"
                    submit-method="PUT"
                    :redirect="route('lancamentos')"
                    :cancel-url="route('lancamentos')" />
            @else
                <x-gasto.form
                    context="page"
                    mode="create"
                    :cartoes="$cartoes"
                    :categorias="$categorias"
                    :previa-url="route('gastos.previa')"
                    :submit-url="route('gastos.store')"
                    submit-method="POST"
                    :redirect="route('lancamentos')"
                    :cancel-url="route('lancamentos')" />
            @endif
        </div>
    </div>
</x-layouts.app>
