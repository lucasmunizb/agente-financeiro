// Toast — feedback efêmero pós-ação (regra 3: só apresentação; nenhum cálculo).
// Fonte ÚNICA do toast do app: o shell (layouts/app) e o guest renderizam
// <x-ui.toast>; qualquer fluxo — flash do servidor ou JS de página (telegram,
// registrar-gasto) — chama window.toast(mensagem, variante). Sem framework:
// JS vanilla, como o resto de resources/js (o projeto não usa Alpine).

const VARIANTES = new Set(['sucesso', 'erro']);
const CHAVE_PENDENTE = 'toast:pendente';
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

// Agenda um toast para DEPOIS da próxima navegação. Em vez de mostrar o toast
// sobre a tela atual (valores velhos) e só então recarregar, guardamos a
// mensagem e deixamos a NOVA tela — já com os valores atualizados — dispará-la
// ao montar. É o que dá a sensação de SPA: a tela atualiza primeiro, o toast
// vem em seguida. Fallback ao toast imediato se sessionStorage estiver indisponível.
export function toastAposNavegar(mensagem, variante = 'sucesso') {
    if (!mensagem) return;
    try {
        sessionStorage.setItem(CHAVE_PENDENTE, JSON.stringify({ mensagem, variante }));
    } catch {
        toast(mensagem, variante);
    }
}
window.toastAposNavegar = toastAposNavegar;

// Flash do servidor → toast (confirmação pós-redirect clássico). O Blade injeta a
// mensagem já escapada em data-toast-init. Retorna true se disparou algo.
function dispararFlashInicial() {
    const el = document.getElementById('toast');
    const mensagem = el?.dataset.toastInit;
    if (!mensagem) return false;
    toast(mensagem, el.dataset.toastInitVariant || 'sucesso');
    return true;
}

// Toast agendado por uma navegação client-side (sessionStorage). Retorna true se disparou.
function dispararToastPendente() {
    let dados;
    try {
        const bruto = sessionStorage.getItem(CHAVE_PENDENTE);
        if (!bruto) return false;
        sessionStorage.removeItem(CHAVE_PENDENTE);
        dados = JSON.parse(bruto);
    } catch {
        return false;
    }
    if (!dados?.mensagem) return false;
    // Espera o navegador PINTAR a nova tela (valores atualizados) antes de o
    // toast deslizar — assim ele nunca aparece sobre valores velhos.
    requestAnimationFrame(() => requestAnimationFrame(() => toast(dados.mensagem, dados.variante || 'sucesso')));
    return true;
}

// Ordem SPA: a tela já renderizou os valores novos; agora sim o toast. O flash
// do servidor (redirect clássico) tem prioridade; senão, o toast agendado por
// navegação client-side.
function aoMontar() {
    if (!dispararFlashInicial()) dispararToastPendente();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', aoMontar);
} else {
    aoMontar();
}
