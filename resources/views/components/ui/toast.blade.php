{{--
    Toast — feedback efêmero pós-ação (confirmação de criação/edição/cancelamento).
    Fonte ÚNICA do toast do app: o shell e o guest renderizam este componente uma vez;
    qualquer fluxo (flash do servidor OU JS de página) o aciona via window.toast(msg, variante).

    Mantém a linguagem visual da confirmação atual (card verde `primary` + "conferido"),
    agora flutuando ao pé da tela em vez de banner inline. Só apresentação (regra 3):
    a mensagem já vem pronta do backend; nada é calculado aqui.

    Flash do servidor → toast: injetamos a mensagem (já escapada) em data-toast-init;
    o resources/js/toast.js dispara após o layout montar. `sucesso` cobre os redirects
    com ->with('sucesso', ...); `status` cobre o "Sua conta foi excluída." (login).
--}}
@php
    $mensagemInicial = session('sucesso') ?? session('status');
@endphp

<div id="toast" role="status" aria-live="polite" aria-atomic="true"
    @if ($mensagemInicial)
        data-toast-init="{{ $mensagemInicial }}" data-toast-init-variant="sucesso"
    @endif
    class="toast pointer-events-none fixed bottom-10 left-1/2 z-[100] flex max-w-[min(28rem,calc(100vw-2rem))] items-start gap-2 rounded-card p-4 shadow-xl">
    <span data-toast-icon="sucesso" class="mt-0.5 h-5 w-5 shrink-0">
        <x-icon name="check" class="h-5 w-5" />
    </span>
    <span data-toast-icon="erro" class="mt-0.5 h-5 w-5 shrink-0">
        <x-icon name="alert" class="h-5 w-5" />
    </span>
    <p data-toast-text class="font-body-sm text-body-sm text-on-surface"></p>
</div>
