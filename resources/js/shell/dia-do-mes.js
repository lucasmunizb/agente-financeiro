/**
 * Campos de DIA-DO-MÊS (1..31): fecham a faixa na digitação.
 *
 * `type="number"` com min/max só reclama no submit — até lá o campo aceita "45" ou "0" e o
 * usuário só descobre o erro depois de enviar. Aqui o valor é contido enquanto se digita:
 * o que passa do máximo é cortado (não zerado — apagar o que a pessoa escreveu é pior que
 * corrigir), e o que fica abaixo do mínimo sobe para ele ao sair do campo.
 *
 * Vale para os dias de fechamento/vencimento do cartão e para o dia da recorrência. A
 * validação de servidor (`between:1,31`) e o CHECK do banco continuam sendo a verdade — isto
 * é só a borda, para o erro não acontecer.
 */
const LIMITES = { min: 1, max: 31 };

function limites(input) {
    return {
        min: Number(input.min) || LIMITES.min,
        max: Number(input.max) || LIMITES.max,
    };
}

/** Contém enquanto digita: só corta o excesso, nunca apaga um campo em construção. */
function conter(input) {
    const { max } = limites(input);
    const digitos = input.value.replace(/\D/g, '');

    if (digitos === '') {
        input.value = '';

        return;
    }

    // "312" digitado depois de "31" vira "31": mantém o prefixo válido em vez de sumir tudo.
    let valor = Number(digitos);
    let texto = digitos;
    while (valor > max && texto.length > 1) {
        texto = texto.slice(0, -1);
        valor = Number(texto);
    }

    input.value = String(Math.min(valor, max));
}

/** Ao sair do campo, resolve o que ficou abaixo do mínimo (ex.: "0" → 1). Vazio segue vazio. */
function normalizar(input) {
    if (input.value === '') return;

    const { min, max } = limites(input);
    input.value = String(Math.min(Math.max(Number(input.value) || min, min), max));
}

document.addEventListener('input', (e) => {
    const alvo = e.target;
    if (alvo instanceof HTMLInputElement && alvo.hasAttribute('data-dia-do-mes')) conter(alvo);
});

document.addEventListener(
    'blur',
    (e) => {
        const alvo = e.target;
        if (alvo instanceof HTMLInputElement && alvo.hasAttribute('data-dia-do-mes')) normalizar(alvo);
    },
    true, // captura: `blur` não borbulha
);
