// Formulário de gasto (spec §7.7 página · §7.7b modal) — JS mínimo de apresentação
// (regra 3). Fonte única: o MESMO componente <x-gasto.form> roda no modal do dashboard e
// na página de criar/editar; este script é dirigido por [data-rg-root] e serve os dois.
//
// Fluxo em DOIS passos (regra 7 — confirmar antes de gravar):
//   1. formulário → POST prévia → o BACKEND calcula (parcelas, vencimento, valor por
//      parcela) e devolve a prévia; a tela só exibe (regra 4).
//   2. confirmação → grava (POST no cadastro; PUT via _method na edição) e redireciona.
// A validação é a mesma do backend (Form Request) — a borda é a fonte da verdade; aqui só
// exibimos os erros que ela devolve. Nada sensível persiste no cliente (regra 6).

const FORMA_LABEL = { credito: 'Crédito', debito: 'Débito', pix: 'Pix', dinheiro: 'Dinheiro', boleto: 'Boleto' };
const CAMPOS_INLINE = new Set(['descricao', 'valor', 'categoria_id']); // resto cai no aviso geral
const instancias = new Map();

/* ---- Toast (reaproveita o do shell) ------------------------------------- */
function mostrarToast(mensagem) {
    const toast = document.getElementById('toast');
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

/* ======================================================================== */
/* Inicializa o formulário de gasto de um root ([data-rg-root]).            */
/* ======================================================================== */
function initGastoForm(root) {
    const form = root.querySelector('#rg-form');
    if (!form) return null;

    const painelForm = root.querySelector('[data-rg-panel="form"]');
    const painelConfirm = root.querySelector('[data-rg-panel="confirm"]');
    const grupoCredito = root.querySelector('[data-rg-group="credito"]');
    const grupoAvista = root.querySelector('[data-rg-group="avista"]');
    const formaInput = root.querySelector('[data-rg-forma-input]');
    const categoriaInput = root.querySelector('[data-rg-categoria-input]');
    const valorInput = root.querySelector('#rg-valor');
    const btnReview = root.querySelector('[data-rg-review]');
    const btnStore = root.querySelector('[data-rg-store]');
    const csrf = form.querySelector('input[name="_token"]')?.value ?? '';

    const method = (root.dataset.rgMethod || 'POST').toUpperCase();
    const previaUrl = root.dataset.previaUrl;
    const storeUrl = root.dataset.storeUrl;
    const redirect = root.dataset.rgRedirect || null;
    const toastOk = root.dataset.rgToast || 'Salvo';
    const isModal = root.dataset.rgContext === 'modal';

    /* ---- Alternância de painéis ----------------------------------------- */
    function mostrarPainel(qual) {
        painelForm.hidden = qual !== 'form';
        painelConfirm.hidden = qual !== 'confirm';
    }
    root.querySelector('[data-rg-back]')?.addEventListener('click', () => mostrarPainel('form'));

    /* ---- Forma de pagamento: crédito × à vista -------------------------- */
    const formaBtns = root.querySelectorAll('[data-rg-forma]');

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
    formaBtns.forEach((b) => b.addEventListener('click', () => selecionarForma(b)));

    /* ---- Recorrência (só à vista; pós-MVP, não enviado) ----------------- */
    const recorrenciaBtn = root.querySelector('[data-rg-recorrencia]');
    recorrenciaBtn?.addEventListener('click', () => {
        recorrenciaBtn.setAttribute('aria-checked', String(recorrenciaBtn.getAttribute('aria-checked') !== 'true'));
    });

    /* ---- Categoria: chip único ------------------------------------------ */
    const categoriaBtns = root.querySelectorAll('[data-rg-categoria]');
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
            categoriaInput.value = jaAtivo ? '' : b.dataset.rgCategoria; // reclicar desmarca
            esconderErro('categoria_id');
        });
    });

    /* ---- Máscara de valor (pt-BR, acumulador de centavos) --------------- */
    function formatarValor(bruto) {
        const digitos = String(bruto).replace(/\D/g, '').slice(0, 13);
        if (digitos === '') return '';
        const centavos = digitos.padStart(3, '0');
        const inteiro = centavos.slice(0, -2).replace(/^0+(?=\d)/, '');
        const dec = centavos.slice(-2);
        return `${inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${dec}`;
    }
    valorInput?.addEventListener('input', () => {
        valorInput.value = formatarValor(valorInput.value);
        valorInput.classList.remove('border-argila', 'focus:border-argila');
        esconderErro('valor');
    });

    /* ---- Erros ----------------------------------------------------------- */
    function limparErros() {
        root.querySelectorAll('[data-rg-error]').forEach((el) => (el.hidden = true));
        root.querySelectorAll('.border-argila').forEach((el) => el.classList.remove('border-argila', 'focus:border-argila'));
    }
    function esconderErro(campo) {
        root.querySelector(`[data-rg-error="${campo}"]`)?.setAttribute('hidden', '');
    }
    function mostrarErros(errors) {
        const geral = [];
        Object.entries(errors).forEach(([campo, msgs]) => {
            const msg = Array.isArray(msgs) ? msgs[0] : String(msgs);
            if (CAMPOS_INLINE.has(campo)) {
                const el = root.querySelector(`[data-rg-error="${campo}"]`);
                if (el) {
                    el.querySelector('span').textContent = msg;
                    el.hidden = false;
                }
                root.querySelector(`#rg-${campo === 'categoria_id' ? 'x' : campo}`)?.classList.add('border-argila', 'focus:border-argila');
            } else {
                geral.push(msg);
            }
        });
        if (geral.length) {
            const el = root.querySelector('[data-rg-error="geral"]');
            el.querySelector('span').textContent = geral.join(' ');
            el.hidden = false;
        }
    }

    /* ---- Spinners -------------------------------------------------------- */
    function toggleBusy(btn, spinnerSel, labelSel, busy, textoBusy, textoIdle) {
        if (!btn) return;
        btn.disabled = busy;
        btn.querySelector(spinnerSel).hidden = !busy;
        btn.querySelector(labelSel).textContent = busy ? textoBusy : textoIdle;
    }
    const idleReview = btnReview?.querySelector('[data-rg-review-label]')?.textContent ?? 'Revisar e confirmar';
    const idleStore = btnStore?.querySelector('[data-rg-store-label]')?.textContent ?? 'Confirmar';
    const setSalvandoReview = (b) => toggleBusy(btnReview, '[data-rg-spinner-review]', '[data-rg-review-label]', b, 'Revisando…', idleReview);
    const setSalvandoStore = (b) => toggleBusy(btnStore, '[data-rg-spinner-store]', '[data-rg-store-label]', b, 'Salvando…', idleStore);

    /* ---- Chamada ao backend --------------------------------------------- */
    // PUT (edição) é enviado como POST + _method — multipart/form-data só popula os campos
    // no POST; o spoof do Laravel roteia para a rota PUT.
    async function enviar(url, spoof = null) {
        const fd = new FormData(form);
        if (spoof) fd.append('_method', spoof);
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: fd,
        });
        const body = await resp.json().catch(() => ({}));
        return { status: resp.status, body };
    }

    function preencherConfirmacao(previa) {
        root.querySelector('[data-rg-resumo="descricao"]').textContent = previa.descricao;
        root.querySelector('[data-rg-resumo="valorTotal"]').textContent = previa.valorTotal;
        root.querySelector('[data-rg-resumo="forma"]').textContent = FORMA_LABEL[formaInput.value] ?? formaInput.value;

        const linhaCat = root.querySelector('[data-rg-resumo-linha="categoria"]');
        const catBtn = root.querySelector('[data-rg-categoria][aria-pressed="true"]');
        if (catBtn) {
            root.querySelector('[data-rg-resumo="categoria"]').textContent = catBtn.querySelector('span:last-child').textContent.trim();
            linhaCat.hidden = false;
        } else {
            linhaCat.hidden = true;
        }

        root.querySelector('[data-rg-dup]').hidden = !previa.ehDuplicado;

        const tbody = root.querySelector('[data-rg-parcelas]');
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
            const { status, body } = await enviar(previaUrl);
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
            const { status, body } = await enviar(storeUrl, method === 'PUT' ? 'PUT' : null);
            if (status === 200) {
                const dest = body.redirect || redirect;
                if (isModal) fecharModal(root);
                mostrarToast(toastOk);
                // Pequeno atraso para o toast ser visto antes de sair.
                setTimeout(() => (dest ? window.location.assign(dest) : window.location.reload()), 800);
                return;
            }
            mostrarPainel('form');
            if (status === 422) {
                mostrarErros(body.errors ?? { geral: ['Revise os dados e tente de novo.'] });
            } else {
                mostrarErros({ geral: ['Não consegui gravar agora. Tente de novo.'] });
            }
        } catch {
            mostrarPainel('form');
            mostrarErros({ geral: ['Falha de conexão. Tente de novo.'] });
        } finally {
            setSalvandoStore(false);
        }
    });

    const api = { root, form, mostrarPainel, setSalvandoReview, limparErros, btnReview };
    instancias.set(root, api);
    return api;
}

/* ---- Fecha o modal que contém um root (quando em contexto modal) -------- */
function fecharModal(root) {
    const modal = root.closest('#modal-registrar-gasto');
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('overflow-hidden', 'modal-aberto');
}

/* ======================================================================== */
/* Inicialização                                                            */
/* ======================================================================== */
document.querySelectorAll('[data-rg-root]').forEach(initGastoForm);

/* ---- Chrome do MODAL (abrir/fechar/backdrop/esc/autoopen) --------------- */
const modal = document.getElementById('modal-registrar-gasto');
if (modal) {
    const root = modal.querySelector('[data-rg-root]');
    const api = instancias.get(root);
    const card = modal.querySelector('[role="dialog"]');
    let ultimoFoco = null;

    function abrir() {
        ultimoFoco = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('overflow-hidden', 'modal-aberto');
        api?.mostrarPainel('form');
        modal.querySelector('#rg-descricao')?.focus({ preventScroll: true });
    }
    function fechar() {
        fecharModal(root);
        api?.setSalvandoReview(false);
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

    // Afordância de revisão: ?modal=aberto|erro|salvando
    if (modal.dataset.rgAutoopen === '1') {
        abrir();
        const inicial = modal.dataset.rgInitial;
        if (inicial === 'erro') {
            modal.querySelector('#rg-descricao').value = '';
            modal.querySelector('#rg-valor').value = '';
            api?.btnReview?.click();
        } else if (inicial === 'salvando') {
            api?.setSalvandoReview(true);
        }
    }
}
