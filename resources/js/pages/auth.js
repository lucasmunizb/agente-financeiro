// JS mínimo da tela de login — apenas apresentação (regra 3). Sem cálculo,
// sem estado sensível persistido. Carregado só nesta página (code-split).

const eyeOpen = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>`;
const eyeOff = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a13.2 13.2 0 0 1-2.16 2.98"/><path d="M6.6 6.6A13.1 13.1 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4.29-1.05"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="m2 2 20 20"/></svg>`;

// Mostrar/ocultar senha.
const toggle = document.querySelector('[data-password-toggle]');
if (toggle) {
    const input = document.getElementById('password');
    toggle.innerHTML = eyeOpen;
    toggle.addEventListener('click', () => {
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        toggle.innerHTML = showing ? eyeOpen : eyeOff;
        toggle.setAttribute('aria-pressed', String(!showing));
        toggle.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
    });
}

// Estado "carregando": ao enviar, desabilita e troca o rótulo do botão.
// (A autenticação real — validação + guard — é a etapa de backend.)
const form = document.querySelector('[data-login-form]');
if (form) {
    form.addEventListener('submit', () => {
        const btn = form.querySelector('[data-submit]');
        if (!btn) return;
        form.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        btn.classList.add('cursor-not-allowed');
        btn.innerHTML =
            '<span class="loading-spinner"></span><span>Entrando…</span>';
        form.querySelectorAll('input').forEach((el) => (el.readOnly = true));
    });
}
