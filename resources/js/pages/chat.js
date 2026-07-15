// Chat financeiro — coluna fixa (spec FE §7.14). JS mínimo, sem framework.
// Conversa com o MESMO motor do Telegram via POST /chat/mensagens (ResponderConsulta +
// guard). A tela NUNCA calcula dinheiro (regra 4): os valores chegam prontos e apenas
// realçamos os R$ em mono. O anexo é SOMENTE PDF e efêmero (regra 6): validado no cliente
// e revalidado por MIME real no servidor; nada do arquivo fica no cliente.

const panel = document.getElementById('chat-panel');

if (panel) {
    const historyEl = document.getElementById('chat-history');
    const emptyEl = document.getElementById('chat-empty');
    const storeUrl = panel.dataset.storeUrl;
    const csrf = panel.dataset.csrf;

    /* ---- Folha do chat (abaixo de lg). No desktop a coluna é permanente. ---- */
    const backdrop = document.getElementById('chat-backdrop');
    const openBtn = document.getElementById('chat-open');
    const closeBtn = document.getElementById('chat-close');

    function setOpen(open) {
        panel.classList.toggle('translate-x-full', !open);
        if (backdrop) backdrop.hidden = !open;
        document.body.classList.toggle('overflow-hidden', open);
        openBtn?.setAttribute('aria-expanded', String(open));
        if (open) input?.focus({ preventScroll: true });
    }
    openBtn?.addEventListener('click', () => setOpen(true));
    closeBtn?.addEventListener('click', () => setOpen(false));
    backdrop?.addEventListener('click', () => setOpen(false));
    // Só fecha se a folha está de fato aberta e nenhuma camada acima (modal)
    // consumiu este Esc — um Esc fecha UMA camada por vez (P3-6).
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || e.defaultPrevented) return;
        if (panel.classList.contains('translate-x-full')) return; // já fechada
        if (document.body.classList.contains('modal-aberto')) return;
        e.preventDefault();
        setOpen(false);
    });

    /* ---- Realce dos valores em mono (mesma regra do TextoDoChat no backend). ---- */
    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }
    // Remove a linha técnica "fonte: <tool> (<filtros>); N registro(s)" que o modelo às vezes
    // ecoa do payload — a fonte aparece nos chips de metadados, nunca no corpo da resposta.
    function semLinhaDeFonte(texto) {
        return (texto ?? '')
            .replace(/^[^\S\n]*fonte:.*registro\(s\)\.?[^\S\n]*$/gim, '')
            .trim();
    }
    function comValoresMono(texto) {
        return escapeHtml(semLinhaDeFonte(texto))
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/R\$\s?\d[\d.]*,\d{2}/g, '<span class="font-value-label text-value-label">$&</span>')
            .replace(/\n/g, '<br>');
    }

    /* ---- Renderização de bolhas (espelha components/chat/message.blade.php). ---- */
    function esconderVazio() {
        if (emptyEl) emptyEl.hidden = true;
    }
    function rolarAoFim() {
        historyEl.scrollTop = historyEl.scrollHeight;
    }
    function anexar(el) {
        esconderVazio();
        historyEl.appendChild(el);
        rolarAoFim();
        return el;
    }
    function elemento(html) {
        const t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstElementChild;
    }

    function bolhaUsuario({ body, temAnexo }) {
        const anexoHtml = temAnexo
            ? `<div class="flex items-center gap-2 rounded-control border border-linha bg-superficie/80 px-3 py-2">
                   <span class="font-label-sm text-label-sm text-on-surface">documento PDF</span>
               </div>`
            : '';
        const textoHtml = body ? `<p class="font-body-md text-body-md text-on-surface">${escapeHtml(body)}</p>` : '';
        return elemento(
            `<div class="flex justify-end"><div class="max-w-[85%] space-y-2 rounded-card rounded-tr-sm bg-papel px-4 py-3">${anexoHtml}${textoHtml}</div></div>`,
        );
    }

    // A resposta é SÓ o corpo — sem chips de fonte nem selo "conferido". A transparência da
    // fonte (barreira 5) é registro interno, nunca parte da bolha enviada ao usuário.
    function bolhaAssistente({ body }) {
        return elemento(
            `<div class="flex justify-start"><div class="notebook-card max-w-[90%] rounded-card rounded-tl-sm px-4 py-4"><p class="font-body-md text-body-md text-on-surface">${comValoresMono(body)}</p></div></div>`,
        );
    }

    function bolhaPensando() {
        return elemento(
            `<div class="flex justify-start" data-chat-pensando><div class="notebook-card inline-flex items-center gap-2.5 rounded-card rounded-tl-sm px-4 py-3"><span class="loading-spinner text-primary-container"></span><span class="font-body-sm text-body-sm text-on-surface-variant">consultando seus dados…</span></div></div>`,
        );
    }

    function avisoInstavel() {
        return elemento(
            `<div class="flex justify-start"><div class="max-w-[90%] rounded-card rounded-tl-sm border border-ocre/40 bg-ocre/10 px-4 py-3 font-body-sm text-body-sm text-on-surface">Instabilidade no momento — tente enviar de novo.</div></div>`,
        );
    }

    /* ---- Anexo: SOMENTE PDF, validado no cliente; efêmero. ---- */
    const attachBtn = document.getElementById('chat-attach');
    const fileInput = document.getElementById('chat-file');
    const attachment = document.getElementById('chat-attachment');
    const filename = document.getElementById('chat-filename');
    const attachNote = document.getElementById('chat-attach-note');
    const removeBtn = document.getElementById('chat-remove');
    const invalid = document.getElementById('chat-invalid');
    let arquivo = null;

    attachBtn?.addEventListener('click', () => fileInput?.click());

    function limparAnexo() {
        arquivo = null;
        if (fileInput) fileInput.value = '';
        if (attachment) attachment.hidden = true;
        if (attachNote) attachNote.hidden = true;
    }

    fileInput?.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        if (invalid) invalid.hidden = true;
        if (!file) return limparAnexo();

        const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
        if (!isPdf) {
            limparAnexo();
            if (invalid) invalid.hidden = false;
            return;
        }
        arquivo = file;
        if (filename) filename.textContent = file.name;
        if (attachment) attachment.hidden = false;
        if (attachNote) attachNote.hidden = false;
        atualizarEnviar();
    });

    removeBtn?.addEventListener('click', () => {
        limparAnexo();
        atualizarEnviar();
    });

    /* ---- Campo de texto: cresce e habilita o enviar. ---- */
    const input = document.getElementById('chat-input');
    const sendBtn = document.getElementById('chat-send');

    // Enquanto uma mensagem processa, o envio fica bloqueado (barra duplo-envio) e a entrada
    // é desabilitada visualmente. A confirmação/gravação continua no servidor (regra 7).
    let enviando = false;

    function atualizarEnviar() {
        if (sendBtn) sendBtn.disabled = enviando || ((input?.value.trim() ?? '') === '' && !arquivo);
    }

    function setEnviando(estado) {
        enviando = estado;
        if (input) input.disabled = estado;
        if (attachBtn) attachBtn.disabled = estado;
        atualizarEnviar();
    }
    input?.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 128) + 'px';
        atualizarEnviar();
    });
    input?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            enviar();
        }
    });
    sendBtn?.addEventListener('click', enviar);

    /* ---- Envio → POST /chat/mensagens (mesmo motor do Telegram). ---- */
    async function enviar() {
        const texto = input?.value.trim() ?? '';
        if (enviando || (texto === '' && !arquivo)) return;
        setEnviando(true);

        const temAnexo = !!arquivo;
        const fd = new FormData();
        if (texto) fd.append('mensagem', texto);
        if (arquivo) fd.append('anexo', arquivo);

        // Eco otimista da mensagem do usuário; limpa a entrada de imediato.
        anexar(bolhaUsuario({ body: texto, temAnexo }));
        if (input) {
            input.value = '';
            input.style.height = 'auto';
        }
        limparAnexo();
        atualizarEnviar();

        const pensando = anexar(bolhaPensando());

        try {
            const resp = await fetch(storeUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: fd,
            });
            const body = await resp.json().catch(() => ({}));
            pensando.remove();

            if (resp.status === 422) {
                const erro = body.errors?.anexo?.[0] || body.errors?.mensagem?.[0] || 'Não consegui enviar.';
                if (invalid) {
                    invalid.textContent = erro;
                    invalid.hidden = false;
                }
                return;
            }
            if (!resp.ok || !body.mensagem) {
                anexar(avisoInstavel());
                return;
            }
            anexar(bolhaAssistente(body.mensagem));
        } catch {
            pensando.remove();
            anexar(avisoInstavel());
        } finally {
            setEnviando(false);
        }
    }

    // Histórico já vem renderizado do servidor; começa rolado ao fim.
    rolarAoFim();
}
