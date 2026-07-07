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
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setOpen(false);
    });

    /* ---- Realce dos valores em mono (mesma regra do TextoDoChat no backend). ---- */
    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }
    function comValoresMono(texto) {
        return escapeHtml(texto)
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

    function bolhaAssistente({ body, aprovado, fontes }) {
        const chips = (fontes || [])
            .map(
                (f) =>
                    `<span class="inline-flex items-center gap-1.5 rounded-full border border-linha bg-superficie px-2.5 py-1 font-label-sm text-label-sm text-on-surface-variant">${escapeHtml(f.resumo || f.ferramenta || 'fonte')}</span>`,
            )
            .join('');
        const conferido = aprovado
            ? `<span class="inline-flex items-center gap-1.5 rounded-full bg-primary-container/10 px-2.5 py-1 font-label-sm text-label-sm text-primary-container">número conferido</span>`
            : '';
        const meta = chips || conferido
            ? `<div class="flex flex-wrap gap-2 border-t border-linha/60 pt-3">${chips}${conferido}</div>`
            : '';
        return elemento(
            `<div class="flex justify-start"><div class="notebook-card max-w-[90%] space-y-3 rounded-card rounded-tl-sm px-4 py-4"><p class="font-body-md text-body-md text-on-surface">${comValoresMono(body)}</p>${meta}</div></div>`,
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

    function atualizarEnviar() {
        if (sendBtn) sendBtn.disabled = (input?.value.trim() ?? '') === '' && !arquivo;
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
    let enviando = false;

    async function enviar() {
        const texto = input?.value.trim() ?? '';
        if (enviando || (texto === '' && !arquivo)) return;
        enviando = true;

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
            enviando = false;
        }
    }

    // Histórico já vem renderizado do servidor; começa rolado ao fim.
    rolarAoFim();
}
