// Vínculo Telegram — JS mínimo de apresentação (regra 3). Nada de cálculo nem
// de estado sensível persistido no cliente. Carregado só nesta tela (code-split).

const toast = document.getElementById('toast');

function mostrarToast(mensagem) {
    if (!toast) return;
    toast.textContent = mensagem;
    toast.classList.remove('opacity-0', 'translate-y-4');
    toast.classList.add('opacity-100', 'translate-y-0');
    clearTimeout(mostrarToast._t);
    mostrarToast._t = setTimeout(() => {
        toast.classList.add('opacity-0', 'translate-y-4');
        toast.classList.remove('opacity-100', 'translate-y-0');
    }, 3000);
}

// Copiar o código de uso único para a área de transferência.
const copyBtn = document.querySelector('[data-copy-token]');
if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
        const token = document.querySelector('[data-token]')?.dataset.token ?? '';
        try {
            await navigator.clipboard.writeText(token);
            mostrarToast('Código copiado');
        } catch {
            mostrarToast('Não foi possível copiar');
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

// Confirmação antes de ação destrutiva (regra 7). A gravação real é backend.
document.querySelectorAll('[data-confirm]').forEach((btn) => {
    btn.addEventListener('click', () => {
        if (window.confirm(btn.dataset.confirm)) {
            mostrarToast('Ação de backend ainda não disponível');
        }
    });
});
