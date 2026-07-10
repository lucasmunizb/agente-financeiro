// Toast — feedback efêmero pós-ação (regra 3: só apresentação; nenhum cálculo).
// Fonte ÚNICA do toast do app: o shell (layouts/app) e o guest renderizam
// <x-ui.toast>; qualquer fluxo — flash do servidor ou JS de página (telegram,
// registrar-gasto) — chama window.toast(mensagem, variante). Sem framework:
// JS vanilla, como o resto de resources/js (o projeto não usa Alpine).

const VARIANTES = new Set(['sucesso', 'erro']);
let timer;

export function toast(mensagem, variante = 'sucesso') {
    const el = document.getElementById('toast');
    if (!el || !mensagem) return;
    if (!VARIANTES.has(variante)) variante = 'sucesso';

    const texto = el.querySelector('[data-toast-text]');
    if (texto) texto.textContent = mensagem;

    el.classList.remove('toast--sucesso', 'toast--erro', 'toast--show');
    el.classList.add(`toast--${variante}`);
    // Reinicia a transição em toasts consecutivos (força reflow).
    void el.offsetWidth;
    el.classList.add('toast--show');

    clearTimeout(timer);
    timer = setTimeout(() => el.classList.remove('toast--show'), 3200);
}

// Global para os módulos de página e para o disparo por flash sem import.
window.toast = toast;

// Flash do servidor → toast (confirmação pós-redirect). O Blade injeta a
// mensagem já escapada em data-toast-init; disparamos após o layout montar.
function dispararFlashInicial() {
    const el = document.getElementById('toast');
    const mensagem = el?.dataset.toastInit;
    if (mensagem) toast(mensagem, el.dataset.toastInitVariant || 'sucesso');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dispararFlashInicial);
} else {
    dispararFlashInicial();
}
