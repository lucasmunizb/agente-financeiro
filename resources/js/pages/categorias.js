// Modais de categoria (criar/editar) — JS mínimo de apresentação (regra 3). Usa o <dialog>
// nativo: foco preso, tecla Esc e a semântica de modal vêm de graça do elemento; aqui só
// ligamos os gatilhos de abrir, o fechar pelo X, o clique no backdrop e a reabertura do
// diálogo certo após um erro de validação (data-open, posto pelo servidor).
//
// A gravação em si é server-side (o formulário faz POST/PUT e o backend redireciona);
// categoria não é cálculo de dinheiro (regra 4), então não há passo de prévia como no
// modal de lançamento — o diálogo só apresenta e captura.

function marcarCorpo() {
    document.body.classList.add('modal-aberto', 'overflow-hidden');
}
function desmarcarCorpoSeUltimo() {
    if (!document.querySelector('dialog.modal-dialog[open]')) {
        document.body.classList.remove('modal-aberto', 'overflow-hidden');
    }
}

// Gatilhos: cada botão aponta (data-cat-open) para o id do <dialog> que deve abrir.
document.querySelectorAll('[data-cat-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const dlg = document.getElementById(btn.dataset.catOpen);
        if (!dlg) return;
        dlg.showModal();
        marcarCorpo();
    });
});

document.querySelectorAll('dialog.modal-dialog').forEach((dlg) => {
    // Fechar pelo X.
    dlg.querySelectorAll('[data-cat-close]').forEach((b) =>
        b.addEventListener('click', () => dlg.close()));

    // Clique no backdrop (área fora do card) fecha. Num <dialog> nativo, o clique no
    // ::backdrop tem o próprio <dialog> como alvo.
    dlg.addEventListener('click', (e) => {
        if (e.target === dlg) dlg.close();
    });

    // Ao fechar (X, Esc ou backdrop), solta o corpo se nenhum outro modal ficou aberto.
    dlg.addEventListener('close', desmarcarCorpoSeUltimo);

    // Reabertura após erro de validação: o servidor marcou data-open no diálogo certo.
    if (dlg.hasAttribute('data-open')) {
        dlg.showModal();
        marcarCorpo();
    }
});
