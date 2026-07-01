// Onboarding — consentimento LGPD. O botão "Começar" só habilita ao marcar o
// consentimento (invariante da tela). O servidor também valida o aceite: isto
// é só progressive enhancement na borda, não a barreira real.

const check = document.querySelector('[data-consent-check]');
const submit = document.querySelector('[data-consent-submit]');

if (check && submit) {
    const sync = () => {
        submit.disabled = !check.checked;
    };
    sync();
    check.addEventListener('change', sync);
}

// Estado "carregando" ao enviar (reaproveita o padrão data-loading-*).
const form = document.querySelector('[data-loading-form]');
if (form) {
    form.addEventListener('submit', () => {
        const btn = form.querySelector('[data-submit]');
        if (!btn || btn.disabled) return;
        const label = btn.dataset.loadingLabel || 'Enviando…';
        form.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        btn.classList.add('cursor-not-allowed');
        btn.innerHTML = `<span class="loading-spinner"></span><span>${label}</span>`;
    });
}
