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
const CAMPOS_INLINE = new Set(['descricao', 'valor', 'categoria_id', 'dia_recorrencia', 'data_pagamento']); // resto cai no aviso geral
const instancias = new Map();

// Toast: fonte única no shell (resources/js/toast.js). Aqui usamos o toast
// AGENDADO (toastAposNavegar): como o gravar sempre recarrega/redireciona, o
// toast deve nascer na NOVA tela (valores já atualizados), não sobre a atual.
const agendarToast = (mensagem) => window.toastAposNavegar?.(mensagem, 'sucesso');

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
    const vencimentoInput = root.querySelector('#rg-vencimento');
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
        // "Já foi pago?" vive no grupo à vista: no crédito ele some E é desligado — senão a
        // data seguiria no envio e o backend recusaria (quem se quita é a fatura, §4.3).
        if (credito) setPago(false);
        // Recorrência sobrevive à troca de forma (spec 12, D3: cartão é permitido) — só o
        // aviso de "nasce paga" aparece/some conforme a forma.
        atualizarAvisoDeCartao();
    }
    formaBtns.forEach((b) => b.addEventListener('click', () => selecionarForma(b)));

    /* ---- Recorrência (spec 12): vale em QUALQUER forma, cartão inclusive. O switch controla
       o hidden `recorrente` e revela os campos (periodicidade + dia da cobrança). Ligar não
       lança gasto avulso nenhum: a conta fixa É a cobrança do mês. O backend decide em que
       mês ela começa (regra 4); aqui só ligamos e sugerimos o dia. ---------------------- */
    const recorrenciaBtn = root.querySelector('[data-rg-recorrencia]');
    const recorrenteInput = root.querySelector('[data-rg-recorrente-input]');
    const recorrenciaFields = root.querySelector('[data-rg-recorrencia-fields]');
    const recorrenciaCartaoAviso = root.querySelector('[data-rg-recorrencia-cartao]');
    const diaInput = root.querySelector('#rg-dia_recorrencia');

    const ehCredito = () => formaInput?.value === 'credito';

    /**
     * Dia sugerido a partir do vencimento já informado (fora de cartão) — só copia o dia da
     * data, não calcula nada. No crédito não há campo de data no formulário, então o dia fica
     * em branco para o usuário dizer qual é.
     */
    function diaSugerido() {
        const venc = ehCredito() ? '' : vencimentoInput?.value;

        return venc ? String(Number(venc.slice(8, 10))) : '';
    }

    function setRecorrencia(ligado) {
        if (!recorrenciaBtn) return;
        recorrenciaBtn.setAttribute('aria-checked', String(ligado));
        if (recorrenteInput) recorrenteInput.value = ligado ? '1' : '0';
        if (recorrenciaFields) recorrenciaFields.hidden = !ligado;
        // Só copia o dia de uma data já informada — não calcula nada (regra 4).
        if (ligado && diaInput && !diaInput.value) diaInput.value = diaSugerido();
        atualizarAvisoDeCartao();
    }

    /** No crédito a cobrança nasce paga (D3): o aviso evita procurar um botão que não existe. */
    function atualizarAvisoDeCartao() {
        if (recorrenciaCartaoAviso) recorrenciaCartaoAviso.hidden = !ehCredito();
    }

    recorrenciaBtn?.addEventListener('click', () => {
        const ligado = recorrenciaBtn.getAttribute('aria-checked') !== 'true';
        setRecorrencia(ligado);
        // Conta que repete todo mês tem o seu próprio "marcar como pago" na cobrança do mês
        // (spec 12) — o backend recusa a combinação; aqui evitamos o usuário chegar no 422.
        if (ligado) setPago(false);
    });

    /* ---- "Já foi pago?" (decisão 2026-07-21): lança uma conta que o usuário já quitou.
       Só fora de cartão — no crédito quem se paga é a fatura (§4.3). O input fica DISABLED
       quando desligado: assim ele nem entra no FormData e nenhuma data velha viaja junto.
       Nada é calculado aqui (regra 4): o backend marca a 1ª parcela e devolve a data. ----- */
    const pagoBtn = root.querySelector('[data-rg-pago]');
    const pagoFields = root.querySelector('[data-rg-pago-fields]');
    const dataPagamentoInput = root.querySelector('#rg-data_pagamento');

    function setPago(ligado) {
        if (!pagoBtn) return;
        pagoBtn.setAttribute('aria-checked', String(ligado));
        if (pagoFields) pagoFields.hidden = !ligado;
        if (!dataPagamentoInput) return;
        dataPagamentoInput.disabled = !ligado;
        // Sugere hoje (o caso comum: "acabei de pagar"), sem sobrescrever o que já foi digitado.
        if (ligado && !dataPagamentoInput.value) dataPagamentoInput.value = dataPagamentoInput.max || '';
        if (!ligado) esconderErro('data_pagamento');
    }

    pagoBtn?.addEventListener('click', () => setPago(pagoBtn.getAttribute('aria-checked') !== 'true'));
    dataPagamentoInput?.addEventListener('input', () => esconderErro('data_pagamento'));

    /* ---- Parcelamento × recorrência: mutuamente exclusivos (o backend também barra).
       Com 2+ parcelas o "Repete todo mês?" não faz sentido (dividir ≠ repetir): desliga
       o switch se estava ligado e o desabilita; volta a habilitar em 1 (à vista). -------- */
    const parcelasInput = root.querySelector('#rg-parcelas');
    const recorrenciaBloqueada = root.querySelector('[data-rg-recorrencia-bloqueada]');

    function aplicarLimiteRecorrencia() {
        if (!recorrenciaBtn) return;
        const parcelado = Number(parcelasInput?.value || 1) >= 2;
        if (parcelado) setRecorrencia(false); // desmarca se estava ligado
        recorrenciaBtn.disabled = parcelado;
        recorrenciaBtn.setAttribute('aria-disabled', String(parcelado));
        if (recorrenciaBloqueada) recorrenciaBloqueada.hidden = !parcelado;
    }
    parcelasInput?.addEventListener('input', aplicarLimiteRecorrencia);
    aplicarLimiteRecorrencia(); // estado inicial (edição pode vir com 2+ parcelas)
    atualizarAvisoDeCartao(); // idem para o aviso "no cartão já nasce paga"

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
    // Acessibilidade (P2-8): os <p data-rg-error> têm role="alert" no Blade (anunciados
    // ao aparecer); aqui ligamos aria-invalid + aria-describedby no input correspondente.
    function limparErros() {
        root.querySelectorAll('[data-rg-error]').forEach((el) => (el.hidden = true));
        root.querySelectorAll('.border-argila').forEach((el) => el.classList.remove('border-argila', 'focus:border-argila'));
        root.querySelectorAll('[aria-invalid="true"]').forEach((el) => {
            el.removeAttribute('aria-invalid');
            el.removeAttribute('aria-describedby');
        });
    }
    function esconderErro(campo) {
        root.querySelector(`[data-rg-error="${campo}"]`)?.setAttribute('hidden', '');
        const input = root.querySelector(`#rg-${campo}`);
        input?.removeAttribute('aria-invalid');
        input?.removeAttribute('aria-describedby');
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
                // Realça o input do campo, quando ele existe (categoria é chips, sem input — no-op).
                const input = root.querySelector(`#rg-${campo}`);
                if (input) {
                    input.classList.add('border-argila', 'focus:border-argila');
                    input.setAttribute('aria-invalid', 'true');
                    if (el?.id) input.setAttribute('aria-describedby', el.id);
                }
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

        // Recorrência não tem parcela: a prévia mostra a COBRANÇA do mês. Rotular a linha
        // como "1/1" prometeria um lançamento parcelado que não vai existir (spec 12).
        const ehRecorrencia = Boolean(previa.recorrencia);
        const legenda = root.querySelector('[data-rg-previa-legenda]');
        if (legenda) {
            legenda.textContent = ehRecorrencia
                ? 'Prévia — cobrança do mês (ainda não gravada)'
                : 'Prévia — calculada pelo sistema (ainda não gravado)';
        }

        const tbody = root.querySelector('[data-rg-parcelas]');
        tbody.innerHTML = '';
        previa.parcelas.forEach((p) => {
            const tr = document.createElement('tr');
            tr.innerHTML =
                `<td class="py-2">${ehRecorrencia ? 'Cobrança' : p.label}</td>` +
                `<td class="py-2 text-right">${p.valor}</td>` +
                `<td class="py-2 text-right text-outline">vence ${p.vencimento}</td>`;
            tbody.appendChild(tr);
        });

        // Nota de "já pago": antes do "sim", o usuário vê que a conta vai nascer quitada
        // (regra 7). A data vem pronta do backend (regra 4/5) — a tela não formata nada.
        const notaPago = root.querySelector('[data-rg-pago-nota]');
        if (notaPago) {
            if (previa.dataPagamento) {
                const parcelado = previa.parcelas.length > 1;
                notaPago.querySelector('span').textContent = parcelado
                    ? `A 1ª parcela será gravada como paga em ${previa.dataPagamento}; as demais seguem em aberto.`
                    : `Será gravado como pago em ${previa.dataPagamento}.`;
                notaPago.hidden = false;
            } else {
                notaPago.hidden = true;
            }
        }

        // Nota de recorrência (quando ligada): o backend diz em que mês ela começa.
        const nota = root.querySelector('[data-rg-recorrencia-nota]');
        if (nota) {
            if (previa.recorrencia) {
                // Cobrança mensal, começando no mês que o backend calculou (regra 4). Deixa
                // explícito que não haverá um gasto avulso além dela (spec 12, R1).
                nota.querySelector('span').textContent =
                    `Cobrança todo dia ${previa.recorrencia.dia}, a partir de ${previa.recorrencia.primeiraEm}. `
                    + 'É a cobrança do mês — nenhum lançamento avulso é criado.';
                nota.hidden = false;
            } else {
                nota.hidden = true;
            }
        }
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
                // SPA-like: atualiza a tela AGORA (valores o quanto antes) e agenda o
                // toast para aparecer na nova tela, já montada — não sobre valores velhos.
                agendarToast(toastOk);
                if (dest) window.location.assign(dest);
                else window.location.reload();
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

    // Fundo inerte enquanto o modal está aberto (P2-6): Tab/leitor de tela não
    // escapam para o conteúdo por trás. `inert` em todos os irmãos do modal.
    function alternarFundoInerte(ativo) {
        [...document.body.children].forEach((el) => {
            if (el === modal || el.contains(modal)) return;
            if (ativo) el.setAttribute('inert', '');
            else el.removeAttribute('inert');
        });
    }

    function abrir() {
        ultimoFoco = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('overflow-hidden', 'modal-aberto');
        alternarFundoInerte(true);
        api?.mostrarPainel('form');
        modal.querySelector('#rg-descricao')?.focus({ preventScroll: true });
    }
    function fechar() {
        alternarFundoInerte(false);
        fecharModal(root);
        api?.setSalvandoReview(false);
        if (ultimoFoco instanceof HTMLElement) ultimoFoco.focus({ preventScroll: true });
    }

    // Focus trap (P2-6): reforço para navegadores/ATs sem suporte pleno a inert —
    // Tab/Shift+Tab circulam apenas entre os focáveis visíveis do diálogo.
    modal.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const focaveis = [...card.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        )].filter((el) => el.offsetParent !== null);
        if (focaveis.length === 0) return;
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        if (e.shiftKey && document.activeElement === primeiro) {
            e.preventDefault();
            ultimo.focus();
        } else if (!e.shiftKey && document.activeElement === ultimo) {
            e.preventDefault();
            primeiro.focus();
        }
    });

    document.querySelectorAll('[data-rg-open]').forEach((b) => b.addEventListener('click', abrir));
    modal.querySelectorAll('[data-rg-close]').forEach((el) => el.addEventListener('click', fechar));
    modal.addEventListener('mousedown', (e) => {
        if (!card.contains(e.target)) fechar();
    });
    // preventDefault sinaliza aos demais overlays (notificações, chat) que este Esc
    // já foi consumido — um Esc fecha UMA camada por vez (P3-6).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !e.defaultPrevented && !modal.hidden) {
            e.preventDefault();
            fechar();
        }
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
