// Configurações — JS mínimo de apresentação (regra 3). Vive num arquivo Vite —
// script inline no Blade era bloqueado pela CSP estrita de produção (script-src 'self').

// Cortesia de latência: habilita "Excluir definitivamente" só quando "EXCLUIR" é
// digitado. O guard REAL é server-side (ExcluirContaRequest); isto é só UX (regra 7).
(function () {
    const root = document.querySelector('[data-excluir-conta]');
    if (!root) return;
    const input = root.querySelector('[data-excluir-input]');
    const submit = root.querySelector('[data-excluir-submit]');
    if (!input || !submit) return;
    const sync = () => { submit.disabled = input.value !== 'EXCLUIR'; };
    input.addEventListener('input', sync);
    sync();
})();
