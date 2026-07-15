// Drawer do menu (mobile): abre/fecha o aside. Vive num arquivo Vite — script
// inline no layout era bloqueado pela CSP estrita de produção (script-src 'self').
(function () {
    const aside = document.getElementById('app-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const openBtn = document.getElementById('sidebar-open');
    const closeBtn = document.getElementById('sidebar-close');
    if (!aside) return;

    function setOpen(open) {
        aside.classList.toggle('-translate-x-full', !open);
        backdrop.hidden = !open;
        document.body.classList.toggle('overflow-hidden', open);
        openBtn?.setAttribute('aria-expanded', String(open));
    }

    openBtn?.addEventListener('click', () => setOpen(true));
    closeBtn?.addEventListener('click', () => setOpen(false));
    backdrop?.addEventListener('click', () => setOpen(false));
    // Um Esc fecha UMA camada por vez (P3-6): só age com o drawer aberto e se
    // nenhuma camada acima consumiu o evento.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || e.defaultPrevented) return;
        if (aside.classList.contains('-translate-x-full')) return; // já fechado (mobile)
        if (backdrop?.hidden !== false) return; // desktop: aside permanente, nada a fechar
        e.preventDefault();
        setOpen(false);
    });
    // Fecha ao navegar (mobile); no desktop o aside fica sempre visível.
    aside.querySelectorAll('a[href]:not([aria-disabled])').forEach((a) =>
        a.addEventListener('click', () => setOpen(false))
    );
})();
