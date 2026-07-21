// Diálogos genéricos (<dialog> nativo) — JS mínimo de apresentação (regra 3).
//
// Por que existe: as confirmações da linha do extrato eram <details> com um painel
// absoluto. Dentro de uma lista com overflow, o painel era RECORTADO pelo card e ficava
// atrás da linha de baixo; e abrir um não fechava o outro, empilhando duas caixas de
// confirmação na tela (relato do usuário). O <dialog> aberto com showModal() vai para a
// top layer: não é recortado por nenhum ancestral, só um fica aberto por vez, e foco
// preso + Esc + backdrop vêm de graça do elemento.
//
// Contrato (opt-in, para não colidir com o wiring próprio de outras telas):
//   <button data-dialog-open="id-do-dialog">      → abre
//   <dialog id="…" class="modal-dialog" data-dialog>  → o diálogo
//   <button data-dialog-close>                    → fecha (dentro do diálogo)
//
// Nada aqui calcula ou grava: o <form> de dentro faz o POST server-side (regra 4/7).

function marcarCorpo() {
    document.body.classList.add('modal-aberto', 'overflow-hidden');
}

function soltarCorpoSeUltimo() {
    if (!document.querySelector('dialog[open]')) {
        document.body.classList.remove('modal-aberto', 'overflow-hidden');
    }
}

// Delegação: as linhas do extrato são reconstruídas a cada navegação de mês/filtro, e o
// polling de atualizações pode trocar o conteúdo — ouvir no documento evita religar tudo.
document.addEventListener('click', (e) => {
    const gatilho = e.target.closest('[data-dialog-open]');
    if (gatilho) {
        const dlg = document.getElementById(gatilho.dataset.dialogOpen);
        if (!dlg) return;
        e.preventDefault();
        if (!dlg.open) dlg.showModal();
        marcarCorpo();
        return;
    }

    const fechar = e.target.closest('[data-dialog-close]');
    if (fechar) {
        const dlg = fechar.closest('dialog');
        if (dlg) {
            e.preventDefault();
            dlg.close();
        }
        return;
    }

    // Clique no backdrop: num <dialog> nativo o alvo é o próprio elemento.
    if (e.target instanceof HTMLDialogElement && e.target.hasAttribute('data-dialog')) {
        e.target.close();
    }
});

// Ao fechar (X, Esc ou backdrop), solta a rolagem do corpo se não sobrou modal aberto.
document.addEventListener(
    'close',
    (e) => {
        if (e.target instanceof HTMLDialogElement) soltarCorpoSeUltimo();
    },
    true, // 'close' não borbulha — captura.
);
