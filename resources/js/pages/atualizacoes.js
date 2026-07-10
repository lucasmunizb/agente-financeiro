// Auto-atualizar a tela — polling da "assinatura" de estado (regra 3: só
// apresentação). Quando o usuário confirma uma transação por outro canal (ex.: o
// chat do Telegram), o backend muda a assinatura devolvida por GET /atualizacoes.
// Aqui só detectamos a mudança e recarregamos a tela — nenhum cálculo nem dado
// sensível no cliente; a assinatura é um hash opaco (regra 4). Carregado só no
// dashboard e no extrato (code-split). Espelha o padrão do polling do vínculo
// Telegram (resources/js/pages/telegram.js).
const alvo = document.querySelector('[data-poll-atualizacoes]');
if (alvo) {
    const url = alvo.dataset.url;
    const INTERVALO = 5000; // ms — leve o bastante p/ sempre ativo, ágil o bastante p/ o fluxo.
    let assinatura = null;  // referência: definida na primeira resposta.
    let checando = false;

    // Não recarregar por cima do usuário: adia enquanto ele digita num campo ou
    // um diálogo nativo está aberto (evita perder o que está sendo preenchido no
    // modal "Registrar gasto"). Retoma no ciclo seguinte, quando estiver livre.
    const ocupado = () => {
        const foco = document.activeElement;
        const digitando = foco && ['INPUT', 'TEXTAREA', 'SELECT'].includes(foco.tagName);
        return Boolean(digitando || document.querySelector('dialog[open]'));
    };

    const verificar = async () => {
        if (checando || document.hidden) return;
        checando = true;
        try {
            const resposta = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!resposta.ok) return;
            const nova = (await resposta.json()).assinatura;
            if (assinatura === null) {
                assinatura = nova; // primeira leitura vira a referência da tela carregada.
            } else if (nova !== assinatura && !ocupado()) {
                window.location.reload();
            }
        } catch {
            // silencioso: instabilidade de rede não deve quebrar a tela.
        } finally {
            checando = false;
        }
    };

    setInterval(verificar, INTERVALO);
}
