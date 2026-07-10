// Vínculo Telegram — JS mínimo de apresentação (regra 3). Nada de cálculo nem
// de estado sensível persistido no cliente. Carregado só nesta tela (code-split).

// Toast: fonte única no shell (window.toast, resources/js/toast.js).

// Copiar o código de uso único para a área de transferência.
const copyBtn = document.querySelector('[data-copy-token]');
if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
        const token = document.querySelector('[data-token]')?.dataset.token ?? '';
        try {
            await navigator.clipboard.writeText(token);
            window.toast?.('Código copiado', 'sucesso');
        } catch {
            window.toast?.('Não foi possível copiar', 'erro');
        }
    });
}

// Contagem regressiva do código. O prazo (segundos) vem pronto do backend;
// a UI só formata mm:ss e avisa quando expira — não recalcula regra alguma.
const countdown = document.querySelector('[data-countdown]');
if (countdown) {
    let restante = parseInt(countdown.dataset.seconds ?? '0', 10);
    const render = () => {
        if (restante <= 0) {
            countdown.textContent = 'código expirado';
            return true; // parar
        }
        const min = String(Math.floor(restante / 60)).padStart(2, '0');
        const seg = String(restante % 60).padStart(2, '0');
        countdown.textContent = `expira em ${min}:${seg}`;
        return false;
    };
    if (!render()) {
        const id = setInterval(() => {
            restante -= 1;
            if (render()) clearInterval(id);
        }, 1000);
    }
}

// Polling do vínculo: enquanto a tela está no estado pendente, pergunta ao
// backend se o vínculo já foi confirmado no bot. Quando vira ativo, recarrega —
// a tela passa a renderizar o estado "conectado". Sem cálculo nem dado sensível
// no cliente; só um booleano. Pausa quando a aba está oculta.
const alvoPoll = document.querySelector('[data-poll-status]');
if (alvoPoll) {
    const url = alvoPoll.dataset.pollStatus;
    let checando = false;
    const verificar = async () => {
        if (checando || document.hidden) return;
        checando = true;
        try {
            const resposta = await fetch(url, { headers: { Accept: 'application/json' } });
            if (resposta.ok && (await resposta.json()).vinculado) {
                window.location.reload();
            }
        } catch {
            // silencioso: instabilidade de rede não deve quebrar a tela.
        } finally {
            checando = false;
        }
    };
    setInterval(verificar, 3000);
}

// Confirmação antes de ação destrutiva (regra 7): cancela o envio do form se o
// usuário desistir; caso contrário deixa o POST seguir para o backend.
document.querySelectorAll('[data-confirm]').forEach((btn) => {
    btn.addEventListener('click', (event) => {
        if (!window.confirm(btn.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
