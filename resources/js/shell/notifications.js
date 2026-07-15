// Painel de notificações do header (shell). Vive num arquivo Vite — script inline
// no Blade era bloqueado pela CSP estrita de produção (script-src 'self', P3-13).
(function () {
    const trigger = document.getElementById('notif-trigger');
    const panel = document.getElementById('notif-panel');
    if (!trigger || !panel) return;

    let closeTimer = null;

    function open() {
        clearTimeout(closeTimer);
        panel.hidden = false;
        // força reflow antes de animar para a transição valer.
        void panel.offsetWidth;
        panel.classList.remove('scale-95', 'opacity-0');
        panel.classList.add('scale-100', 'opacity-100');
        trigger.setAttribute('aria-expanded', 'true');
    }

    function close() {
        panel.classList.add('scale-95', 'opacity-0');
        panel.classList.remove('scale-100', 'opacity-100');
        trigger.setAttribute('aria-expanded', 'false');
        closeTimer = setTimeout(() => {
            if (trigger.getAttribute('aria-expanded') === 'false') panel.hidden = true;
        }, 160);
    }

    function isOpen() {
        return trigger.getAttribute('aria-expanded') === 'true';
    }

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        isOpen() ? close() : open();
    });

    // Fecha ao clicar fora ou com Esc.
    document.addEventListener('click', (e) => {
        if (isOpen() && !panel.contains(e.target) && !trigger.contains(e.target)) close();
    });
    // Um Esc fecha UMA camada por vez: ignora se outra camada (modal) já o
    // consumiu (defaultPrevented) ou se o painel nem está aberto (P3-6).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !e.defaultPrevented && isOpen()) {
            e.preventDefault();
            close();
            trigger.focus();
        }
    });

    // Marcação de leitura — afago visual (sem backend de notificações no
    // MVP; nada é persistido). Tira o realce/ponto e ressincroniza o selo.
    const markAll = document.getElementById('notif-mark-all');

    function marcarLida(item) {
        const dot = item.querySelector('.notif-dot');
        if (!dot) return; // já lida
        item.classList.remove('bg-cedula/5');
        item.classList.add('hover:bg-surface-container');
        dot.remove();
    }

    function sincronizarBadge() {
        const restantes = panel.querySelectorAll('.notif-dot').length;
        const badge = document.getElementById('notif-badge');
        if (restantes === 0) {
            badge?.remove();
            markAll?.remove();
        } else if (badge) {
            badge.textContent = restantes > 9 ? '9+' : restantes;
        }
    }

    // Clique numa notificação não lida → marca só aquela.
    panel.querySelectorAll('[data-notif-item]').forEach((item) => {
        item.addEventListener('click', () => {
            marcarLida(item);
            sincronizarBadge();
        });
    });

    // "Marcar todas como lidas".
    markAll?.addEventListener('click', (e) => {
        e.stopPropagation();
        panel.querySelectorAll('[data-notif-item]').forEach(marcarLida);
        sincronizarBadge();
    });
})();
