<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Domain\Gasto\PreviaGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\IA\Consulta\TraceDaConsulta;
use App\Domain\Pagamento\ContaPagavel;
use App\Domain\Recorrencia\PreviaRecorrencia;
use App\Domain\Shared\Money;
use App\Domain\Telegram\Resposta\ResultadoDaInteracao;
use App\Domain\Telegram\Resposta\TipoDeInteracao;
use App\Models\Recurrence;
use App\Models\StatusPagamento;
use App\Models\Transaction;
use App\Support\TextoDoChat;

/**
 * Redação (apresentação, regra 3) das respostas do chat financeiro na web a partir do
 * {@see ResultadoDaInteracao} de domínio — o análogo web da redação das mensagens do bot.
 * Texto DETERMINÍSTICO: só transcreve valores JÁ calculados pelo domínio (a UI/redação
 * nunca calcula dinheiro — regra 4). Na consulta, repassa o texto da IA já validado pelo
 * guard (barreira 4) e as fontes (barreira 5). No registro, monta a prévia com "sim/não"
 * (confirmar antes de gravar — regra 7).
 */
final class RedatorDoChat
{
    private const NAO_ENTENDI = 'Não entendi. Posso registrar um gasto (ex.: "gastei 50 no mercado no pix") ou responder sobre suas finanças (ex.: "quanto gastei em junho?").';

    /** Rótulos pt-BR dos campos que a extração pode pedir para esclarecer. */
    private const ROTULOS = [
        'valor' => 'o valor',
        'descricao' => 'a descrição',
        'forma_pagamento' => 'a forma de pagamento',
        'data' => 'a data',
        'cartao' => 'o cartão',
        'parcelas' => 'as parcelas',
        'recorrencia_dia' => 'o dia do mês em que repete',
        // Fora de cartão o "já pagou?" é obrigatório: a pergunta é de sim/não, por isso o
        // rótulo entra como oração ("me diga também se você já pagou"), não como substantivo.
        'pago' => 'se você já pagou',
        'data_pagamento' => 'a data do pagamento',
    ];

    public function redigir(ResultadoDaInteracao $resultado): RespostaDoChat
    {
        return match ($resultado->tipo) {
            // A resposta entregue é SÓ o corpo: um eventual eco da linha técnica "fonte:" que o
            // modelo cospe é removido aqui, na origem, para sair pronto em qualquer canal (web e
            // bot). A fonte segue registrada (barreira 5), mas nunca faz parte do texto enviado.
            TipoDeInteracao::CONSULTA => new RespostaDoChat(
                TextoDoChat::semLinhaDeFonte($resultado->consulta->texto),
                $resultado->consulta->aprovado,
                $this->fontes($resultado->consulta->fontes),
            ),
            TipoDeInteracao::REGISTRO => $this->registro($resultado->registro),
            TipoDeInteracao::GRAVADO => new RespostaDoChat($this->gravado($resultado->transacao), null, []),
            TipoDeInteracao::RECORRENCIA_GRAVADA => new RespostaDoChat($this->recorrenciaGravada($resultado->recorrencia), null, []),
            TipoDeInteracao::CONFIRMACAO_CANCELADA => new RespostaDoChat('Cancelei — nada foi gravado.', null, []),
            TipoDeInteracao::CONFIRMACAO_AMBIGUA => new RespostaDoChat(
                'Não entendi sua resposta. Responda "sim" para gravar ou "não" para cancelar.', null, [],
            ),
            TipoDeInteracao::NADA_PARA_CONFIRMAR => new RespostaDoChat('Não há nada pendente para confirmar agora.', null, []),
            // "Paguei a luz" (2026-07-21). Os números citados aqui vêm do BANCO, via
            // ContaPagavel — a redação só transcreve (regra 4).
            TipoDeInteracao::PAGAMENTO_A_CONFIRMAR => new RespostaDoChat(
                $this->pagamentoAConfirmar($resultado->contaAPagar), null, [],
            ),
            TipoDeInteracao::PAGAMENTO_AMBIGUO => new RespostaDoChat(
                $this->pagamentoAmbiguo($resultado->contasCandidatas), null, [],
            ),
            TipoDeInteracao::PAGAMENTO_REGISTRADO => new RespostaDoChat(
                $this->pagamentoRegistrado($resultado->contaAPagar), null, [],
            ),
            TipoDeInteracao::CONTA_A_PAGAR_NAO_ENCONTRADA => new RespostaDoChat(
                $this->contaNaoEncontrada($resultado->termoBuscado), null, [],
            ),
            default => new RespostaDoChat(self::NAO_ENTENDI, null, []),
        };
    }

    private function registro(ConfirmacaoDeGasto $confirmacao): RespostaDoChat
    {
        if ($confirmacao->precisaEsclarecer()) {
            return new RespostaDoChat($this->esclarecimentos($confirmacao->esclarecimentos), null, []);
        }

        if ($confirmacao->ehRecorrencia()) {
            return new RespostaDoChat($this->previaDaRecorrencia($confirmacao->previaRecorrencia), null, []);
        }

        return new RespostaDoChat($this->previa($confirmacao->previa), null, []);
    }

    /**
     * Prévia do molde mensal (spec 10c). Diz explicitamente que REPETE e que nada foi gravado
     * ainda — o usuário não pode confundir isto com um gasto lançado hoje.
     */
    private function previaDaRecorrencia(PreviaRecorrencia $previa): string
    {
        $linha = "{$previa->descricao} — {$previa->valor->formatBRL()}, todo dia {$previa->dia}, no {$previa->formaPagamento}";
        $categoria = $previa->categoria !== null ? "\nCategoria: {$previa->categoria}." : '';

        return "Confirme a recorrência:\n{$linha}.{$categoria}"
            ."\nTodo mês eu te lembro para confirmar o pagamento."
            ."\nResponda \"sim\" para gravar ou \"não\" para cancelar.";
    }

    /**
     * O "sim" cadastrou o molde — NENHUM lançamento nasceu. A redação evita "registrei" para
     * não sugerir que o gasto do mês já está lançado (ele nasce no dia, via materializador).
     */
    private function recorrenciaGravada(Recurrence $recorrencia): string
    {
        $valor = Money::fromCents((int) $recorrencia->valor_cents)->formatBRL();

        return "Pronto, criei a recorrência: {$recorrencia->descricao} — {$valor}, todo dia {$recorrencia->dia}."
            ."\nTe lembro no dia para confirmar o pagamento.";
    }

    private function previa(PreviaGastoManual $previa): string
    {
        $linha = "{$previa->descricao} — {$previa->valorTotal->formatBRL()}";

        if (count($previa->parcelas) > 1) {
            $linha .= sprintf(' em %dx de %s', count($previa->parcelas), $previa->parcelas[0]->valor->formatBRL());
        }

        $categoria = $this->linhaCategoria($previa);
        $pagamento = $this->linhaPagamento($previa);
        $duplicado = $previa->ehDuplicado ? "\nAtenção: parece um lançamento repetido." : '';

        return "Confirme o gasto:\n{$linha}.{$categoria}{$pagamento}{$duplicado}\nResponda \"sim\" para gravar ou \"não\" para cancelar.";
    }

    /**
     * Linha da categoria pré-selecionada. Quando a IA sugeriu (fallback do lookup), é DICA a
     * confirmar ("sugerida"); quando veio de regra aprendida, é afirmativa; sem categoria,
     * nada é mostrado (não polui a confirmação). O "sim" grava com a categoria pré-selecionada.
     */
    private function linhaCategoria(PreviaGastoManual $previa): string
    {
        if ($previa->categoria === null) {
            return '';
        }

        return $previa->categoriaSugeridaPorIa
            ? "\nCategoria sugerida: {$previa->categoria}."
            : "\nCategoria: {$previa->categoria}.";
    }

    /**
     * Aviso de que o "sim" já grava o gasto como PAGO. No parcelado, diz explicitamente que
     * só a 1ª parcela entra paga (as demais seguem abertas) — o usuário precisa ver isso
     * ANTES de confirmar, senão descobre depois no extrato. A data vem pronta do domínio.
     */
    private function linhaPagamento(PreviaGastoManual $previa): string
    {
        if ($previa->dataPagamento === null) {
            return '';
        }

        $data = $previa->dataPagamento->format('d/m/Y');

        return count($previa->parcelas) > 1
            ? "\nMarco a 1ª parcela como paga em {$data}; as demais ficam em aberto."
            : "\nJá pago em {$data}.";
    }

    /**
     * @param  list<string>  $campos
     */
    private function esclarecimentos(array $campos): string
    {
        $faltam = array_map(fn (string $c): string => self::ROTULOS[$c] ?? $c, $campos);

        return 'Para registrar, me diga também '.$this->lista($faltam).'.';
    }

    /**
     * Confirmação do que foi gravado. Quando o gasto entrou já quitado, o texto DIZ isso —
     * o usuário não deve precisar abrir o extrato para saber se o "já paguei" pegou. O
     * status vem derivado do domínio (regra 4): a redação só transcreve.
     */
    private function gravado(Transaction $transacao): string
    {
        $valor = Money::fromCents((int) $transacao->valor_total_cents)->formatBRL();

        $pagamento = match ($transacao->status_id) {
            StatusPagamento::idFor(StatusPagamento::PAGO) => ' — já pago.',
            StatusPagamento::idFor(StatusPagamento::PAGO_PARCIAL) => ' — 1ª parcela já paga.',
            default => '.',
        };

        return "Pronto, registrei: {$transacao->descricao} — {$valor}{$pagamento}";
    }

    /** Prévia do que será quitado — o "sim" é que grava (regra 7). */
    private function pagamentoAConfirmar(ContaPagavel $conta): string
    {
        $valor = Money::fromCents($conta->cents)->formatBRL();
        $venc = $conta->vencimento->format('d/m');

        return "Marcar {$conta->descricao} — {$valor}, vence {$venc} — como paga? Responda \"sim\" para confirmar ou \"não\" para cancelar.";
    }

    /**
     * Mais de uma conta casa: lista numerada para o usuário desempatar. Escolher por ele
     * arriscaria quitar a conta errada.
     *
     * @param  list<ContaPagavel>  $candidatos
     */
    private function pagamentoAmbiguo(array $candidatos): string
    {
        $linhas = [];

        foreach ($candidatos as $i => $conta) {
            $valor = Money::fromCents($conta->cents)->formatBRL();
            $linhas[] = ($i + 1).". {$conta->descricao} — {$valor}, vence ".$conta->vencimento->format('d/m');
        }

        return "Achei mais de uma conta com esse nome. Qual delas você pagou?\n".implode("\n", $linhas)
            ."\nResponda com o número.";
    }

    private function pagamentoRegistrado(ContaPagavel $conta): string
    {
        $valor = Money::fromCents($conta->cents)->formatBRL();

        return "Pronto, marquei {$conta->descricao} — {$valor} — como paga.";
    }

    private function contaNaoEncontrada(?string $termo): string
    {
        if ($termo === null) {
            return 'Qual conta você pagou? Me diga o nome dela (ex.: "paguei a luz").';
        }

        return "Não achei nenhuma conta em aberto com \"{$termo}\". Confira o nome — cobrança de cartão não entra aqui, porque ela é quitada no pagamento da fatura.";
    }

    /**
     * @param  list<TraceDaConsulta>  $fontes
     * @return list<array<string, mixed>>
     */
    private function fontes(array $fontes): array
    {
        return array_map(
            static fn (TraceDaConsulta $t): array => [
                'ferramenta' => $t->ferramenta,
                'filtros' => $t->filtros,
                'registros' => $t->registros,
                'resumo' => $t->resumo(),
            ],
            $fontes,
        );
    }

    /**
     * @param  list<string>  $itens
     */
    private function lista(array $itens): string
    {
        if (count($itens) <= 1) {
            return $itens[0] ?? '';
        }

        $ultimo = array_pop($itens);

        return implode(', ', $itens).' e '.$ultimo;
    }
}
