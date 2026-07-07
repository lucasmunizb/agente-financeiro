// Modal "Registrar gasto" (spec §7.7b) — JS mínimo de apresentação (regra 3).
// Fluxo em DOIS passos (regra 7 — confirmar antes de gravar):
//   1. formulário → POST /gastos/previa → o BACKEND calcula (parcelas, vencimento,
//      valor por parcela) e devolve a prévia; a tela só exibe (regra 4).
//   2. confirmação → POST /gastos → grava e devolve ok.
// A validação é a mesma do backend (Form Request) — a borda é a fonte única da
// verdade; aqui só exibimos os erros que ele devolve. Nada sensível é persistido
// no cliente (regra 6). Carregado só nesta tela (code-split).

const modal = document.getElementById('modal-registrar-gasto');

if (modal) {
    const card = modal.querySelector('[role="dialog"]');
    const form = modal.querySelector('#rg-form');
    const painelForm = modal.querySelector('[data-rg-panel="form"]');
    const painelConfirm = modal.querySelector('[data-rg-panel="confirm"]');
    const grupoCredito = modal.querySelector('[data-rg-group="credito"]');
    const grupoAvista = modal.querySelector('[data-rg-group="avista"]');
    const formaInput = modal.querySelector('[data-rg-forma-input]');
    const categoriaInput = modal.querySelector('[data-rg-categoria-input]');
    const toast = document.getElementById('toast');
    const csrf = form.querySelector('input[name="_token"]')?.value ?? '';

    // Campos que têm erro inline próprio; o resto cai no aviso geral.
    const CAMPOS_INLINE = new Set(['descricao', 'valor', 'categoria_id']);
    let ultimoFoco = null;

    /* ---- Toast (reaproveita o do shell) --------------------------------- */
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

    /* ---- Abrir / fechar -------------------------------------------------- */
    function abrir() {
        ultimoFoco = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('overflow-hidden');
        mostrarPainel('form');
        modal.querySelector('#rg-descricao')?.focus({ preventScroll: true });
    }

    function fechar() {
        modal.hidden = true;
        document.body.classList.remove('overflow-hidden');
        limparSalvandoStore();
        limparSalvandoReview();
        if (ultimoFoco instanceof HTMLElement) ultimoFoco.focus({ preventScroll: true });
    }

    document.querySelectorAll('[data-rg-open]').forEach((b) => b.addEventListener('click', abrir));
    modal.querySelectorAll('[data-rg-close]').forEach((el) => el.addEventListener('click', fechar));
    modal.addEventListener('mousedown', (e) => {
        if (!card.contains(e.target)) fechar();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) fechar();
    });

    /* ---- Alternância de painéis ----------------------------------------- */
    function mostrarPainel(qual) {
        painelForm.hidden = qual !== 'form';
        painelConfirm.hidden = qual !== 'confirm';
    }
    modal.querySelector('[data-rg-back]')?.addEventListener('click', () => mostrarPainel('form'));

    /* ---- Forma de pagamento: crédito × à vista -------------------------- */
    const formaBtns = modal.querySelectorAll('[data-rg-forma]');

    function selecionarForma(alvo) {
        formaBtns.forEach((b) => {
            const ativo = b === alvo;
            b.setAttribute('aria-pressed', String(ativo));
            b.classList.toggle('bg-superficie', ativo);
            b.classList.toggle('text-primary', ativo);
            b.classList.toggle('shadow-sm', ativo);
            b.classList.toggle('border', ativo);
            b.classList.toggle('border-linha', ativo);
            b.classList.toggle('text-on-surface-variant', !ativo);
            b.classList.toggle('hover:bg-superficie/60', !ativo);
        });

        const forma = alvo.dataset.rgForma;
        formaInput.value = forma;
        const credito = forma === 'credito';
        alternarGrupo(grupoCredito, credito);
        alternarGrupo(grupoAvista, !credito);
    }

    function alternarGrupo(el, mostrar) {
        if (!el) return;
        if (mostrar) {
            el.hidden = false;
            el.classList.remove('field-reveal');
            void el.offsetWidth;
            el.classList.add('field-reveal');
        } else {
            el.hidden = true;
        }
    }

    formaBtns.forEach((b) => b.addEventListener('click', () => selecionarForma(b)));

    /* ---- Recorrência (só à vista; pós-MVP, não enviado) ----------------- */
    const recorrenciaBtn = modal.querySelector('[data-rg-recorrencia]');
    recorrenciaBtn?.addEventListener('click', () => {
        recorrenciaBtn.setAttribute('aria-checked', String(recorrenciaBtn.getAttribute('aria-checked') !== 'true'));
    });

    /* ---- Categoria: chip único ------------------------------------------ */
    const categoriaBtns = modal.querySelectorAll('[data-rg-categoria]');
    categoriaBtns.forEach((b) => {
        b.addEventListener('click', () => {
            const jaAtivo = b.getAttribute('aria-pressed') === 'true';
            categoriaBtns.forEach((outro) => {
                const ativo = outro === b && !jaAtivo;
                outro.setAttribute('aria-pressed', String(ativo));
                outro.classList.toggle('bg-primary', ativo);
                outro.classList.toggle('text-white', ativo);
                outro.classList.toggle('border', !ativo);
                outro.classList.toggle('border-linha', !ativo);
                outro.classList.toggle('bg-surface-container', !ativo);
                outro.classList.toggle('text-on-surface-variant', !ativo);
                outro.classList.toggle('hover:bg-surface-container-high', !ativo);
            });
            // clicar de novo desmarca (categoria é opcional)
            categoriaInput.value = jaAtivo ? '' : b.dataset.rgCategoria;
            esconderErro('categoria_id');
        });
    });

    /* ---- Máscara de valor (pt-BR, acumulador de centavos) --------------- */
    // A tela só formata a digitação para o padrão pt-BR (regra 3/5); quem
    // converte para centavos é o backend (Money::fromHuman). Acumulador da
    // direita: cada dígito empurra as casas — "1"→0,01, "123456"→1.234,56 —
    // então o campo está SEMPRE bem-formado durante o preenchimento.
    const valorInput = modal.querySelector('#rg-valor');

    function formatarValor(bruto) {
        const digitos = String(bruto).replace(/\D/g, '').slice(0, 13); // limite defensivo
        if (digitos === '') return '';
        const centavos = digitos.padStart(3, '0');
        const inteiro = centavos.slice(0, -2).replace(/^0+(?=\d)/, '');
        const dec = centavos.slice(-2);
        return `${inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${dec}`;
    }

    valorInput?.addEventListener('input', () => {
        valorInput.value = formatarValor(valorInput.value); // cursor vai ao fim (mono, alinhado à direita)
        valorInput.classList.remove('border-argila', 'focus:border-argila');
        esconderErro('valor');
    });

    /* ---- Erros ----------------------------------------------------------- */
    function limparErros() {
        modal.querySelectorAll('[data-rg-error]').forEach((el) => (el.hidden = true));
        modal.querySelectorAll('.border-argila').forEach((el) => el.classList.remove('border-argila', 'focus:border-argila'));
    }

    function esconderErro(campo) {
        modal.querySelector(`[data-rg-error="${campo}"]`)?.setAttribute('hidden', '');
    }

    function mostrarErros(errors) {
        const geral = [];
        Object.entries(errors).forEach(([campo, msgs]) => {
            const msg = Array.isArray(msgs) ? msgs[0] : String(msgs);
            if (CAMPOS_INLINE.has(campo)) {
                const el = modal.querySelector(`[data-rg-error="${campo}"]`);
                if (el) {
                    el.querySelector('span').textContent = msg;
                    el.hidden = false;
                }
                const input = modal.querySelector(`#rg-${campo === 'categoria_id' ? 'x' : campo}`);
                input?.classList.add('border-argila', 'focus:border-argila');
            } else {
                geral.push(msg);
            }
        });
        if (geral.length) {
            const el = modal.querySelector('[data-rg-error="geral"]');
            el.querySelector('span').textContent = geral.join(' ');
            el.hidden = false;
        }
    }

    /* ---- Spinners -------------------------------------------------------- */
    const btnReview = modal.querySelector('[data-rg-review]');
    const btnStore = modal.querySelector('[data-rg-store]');

    function toggleBusy(btn, spinnerSel, labelSel, busy, textoBusy, textoIdle) {
        btn.disabled = busy;
        btn.querySelector(spinnerSel).hidden = !busy;
        btn.querySelector(labelSel).textContent = busy ? textoBusy : textoIdle;
    }
    const setSalvandoReview = (b) => toggleBusy(btnReview, '[data-rg-spinner-review]', '[data-rg-review-label]', b, 'Revisando…', 'Revisar e confirmar');
    const setSalvandoStore = (b) => toggleBusy(btnStore, '[data-rg-spinner-store]', '[data-rg-store-label]', b, 'Salvando…', 'Confirmar e gravar');
    const limparSalvandoReview = () => setSalvandoReview(false);
    const limparSalvandoStore = () => setSalvandoStore(false);

    /* ---- Chamada ao backend --------------------------------------------- */
    async function enviar(url) {
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: new FormData(form),
        });
        const body = await resp.json().catch(() => ({}));
        return { status: resp.status, body };
    }

    const FORMA_LABEL = { credito: 'Crédito', debito: 'Débito', pix: 'Pix', dinheiro: 'Dinheiro', boleto: 'Boleto' };

    function preencherConfirmacao(previa) {
        modal.querySelector('[data-rg-resumo="descricao"]').textContent = previa.descricao;
        modal.querySelector('[data-rg-resumo="valorTotal"]').textContent = previa.valorTotal;
        modal.querySelector('[data-rg-resumo="forma"]').textContent = FORMA_LABEL[formaInput.value] ?? formaInput.value;

        // Categoria (linha some se nenhuma escolhida)
        const linhaCat = modal.querySelector('[data-rg-resumo-linha="categoria"]');
        const catBtn = modal.querySelector('[data-rg-categoria][aria-pressed="true"]');
        if (catBtn) {
            modal.querySelector('[data-rg-resumo="categoria"]').textContent = catBtn.querySelector('span:last-child').textContent.trim();
            linhaCat.hidden = false;
        } else {
            linhaCat.hidden = true;
        }

        // Aviso de duplicidade
        modal.querySelector('[data-rg-dup]').hidden = !previa.ehDuplicado;

        // Tabela de parcelas (mono) — calculada pelo backend
        const tbody = modal.querySelector('[data-rg-parcelas]');
        tbody.innerHTML = '';
        previa.parcelas.forEach((p) => {
            const tr = document.createElement('tr');
            tr.innerHTML =
                `<td class="py-2">${p.label}</td>` +
                `<td class="py-2 text-right">${p.valor}</td>` +
                `<td class="py-2 text-right text-outline">vence ${p.vencimento}</td>`;
            tbody.appendChild(tr);
        });
    }

    /* ---- Passo 1: revisar (prévia) -------------------------------------- */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        limparErros();
        setSalvandoReview(true);
        try {
            const { status, body } = await enviar(modal.dataset.previaUrl);
            if (status === 422) {
                mostrarErros(body.errors ?? {});
                return;
            }
            if (status !== 200) {
                mostrarErros({ geral: ['Não consegui revisar agora. Tente de novo.'] });
                return;
            }
            preencherConfirmacao(body);
            mostrarPainel('confirm');
        } catch {
            mostrarErros({ geral: ['Falha de conexão. Tente de novo.'] });
        } finally {
            setSalvandoReview(false);
        }
    });

    /* ---- Passo 2: confirmar (gravar) ------------------------------------ */
    btnStore?.addEventListener('click', async () => {
        setSalvandoStore(true);
        try {
            const { status } = await enviar(modal.dataset.storeUrl);
            if (status === 200) {
                fechar();
                mostrarToast('Gasto registrado');
                // Recarrega o dashboard para refletir o novo gasto (disponível,
                // gastos do mês, próximas contas, régua). Pequeno atraso para o
                // toast de confirmação ser visto antes do reload.
                setTimeout(() => window.location.reload(), 800);
                return;
            }
            if (status === 422) {
                mostrarPainel('form'); // algo mudou; volta para corrigir
                const { body } = await enviar(modal.dataset.previaUrl).catch(() => ({ body: {} }));
                mostrarErros(body?.errors ?? { geral: ['Revise os dados e tente de novo.'] });
            } else {
                mostrarPainel('form');
                mostrarErros({ geral: ['Não consegui gravar agora. Tente de novo.'] });
            }
        } catch {
            mostrarPainel('form');
            mostrarErros({ geral: ['Falha de conexão. Tente de novo.'] });
        } finally {
            setSalvandoStore(false);
        }
    });

    /* ---- Afordância de revisão: ?modal=aberto|erro|salvando ------------- */
    if (modal.dataset.rgAutoopen === '1') {
        abrir();
        const inicial = modal.dataset.rgInitial;
        if (inicial === 'erro') {
            modal.querySelector('#rg-descricao').value = '';
            modal.querySelector('#rg-valor').value = '';
            btnReview.click();
        } else if (inicial === 'salvando') {
            setSalvandoReview(true);
        }
    }
}
