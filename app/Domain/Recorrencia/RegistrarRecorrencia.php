<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Shared\Money;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cadastra uma recorrência mensal `ativo` (spec 10, revista pela spec 12). Guarda o "molde" do
 * gasto em centavos (regra 5) e JÁ GERA a ocorrência do mês corrente (D2): criar "todo dia 5"
 * em 21/07 produz a ocorrência de 07/2026, vencida em 05/07 — exibida em atraso, com o botão
 * de marcar paga. Nenhuma linha nasce em `transactions`/`installments` (invariante da spec 12),
 * e nenhuma confirmação é enfileirada (D1): a regra 7 é honrada AQUI, na confirmação do molde
 * que autoriza as cobranças mensais.
 *
 * Cartão de crédito é permitido (D3) e exige `cardId` — dele saem o ciclo de fatura, o
 * vencimento e a competência da ocorrência. Quando a data de cobrança já passou, a ocorrência
 * de cartão nasce `pago` (R9), pela mesma regra do agendador
 * ({@see LiquidarOcorrenciasDeCartao}). Registra auditoria; "hoje" é injetado (regras 4 e 5).
 */
final class RegistrarRecorrencia
{
    public function __construct(
        private readonly GerarOcorrencias $gerar = new GerarOcorrencias,
    ) {}

    /**
     * Prévia do molde, para a confirmação (regra 7) — NÃO persiste nada. Resolve forma de
     * pagamento e categoria em texto aqui, no domínio, para a apresentação não consultar o
     * banco (mesmo contrato de {@see RegistrarGastoManual::preview()}).
     */
    public function preview(DadosRecorrencia $dados): PreviaRecorrencia
    {
        return new PreviaRecorrencia(
            descricao: $dados->descricao,
            valor: Money::fromCents($dados->valorCents),
            dia: $dados->dia,
            formaPagamento: (string) PaymentMethod::whereKey($dados->paymentMethodId)->value('tipo'),
            categoria: $this->nomeDaCategoria($dados),
        );
    }

    /** Escopo estrito por usuário; null quando não há categoria (ou não é do usuário). */
    private function nomeDaCategoria(DadosRecorrencia $dados): ?string
    {
        if ($dados->categoriaId === null) {
            return null;
        }

        return Category::query()
            ->where('id', $dados->categoriaId)
            ->where('user_id', $dados->userId)
            ->value('nome');
    }

    /**
     * @param  CarbonImmutable|null  $primeiraReferencia  mês em que a recorrência começa a valer;
     *                                                    ausente ⇒ o mês de "hoje" (D2).
     */
    public function registrar(
        DadosRecorrencia $dados,
        CarbonImmutable $hoje,
        ?CarbonImmutable $primeiraReferencia = null,
    ): Recurrence {
        $cardId = $this->resolverCartao($dados);

        return DB::transaction(function () use ($dados, $hoje, $primeiraReferencia, $cardId): Recurrence {
            // A referência chega como instante — normaliza para o calendário de São Paulo antes
            // de resolver o mês (o ponteiro trabalha em calendário puro, sem shift).
            $primeiroMes = ($primeiraReferencia ?? $hoje)->setTimezone(RelativeDate::TIMEZONE)->startOfMonth();

            $recorrencia = Recurrence::create([
                'user_id' => $dados->userId,
                'descricao' => $dados->descricao,
                'valor_cents' => $dados->valorCents,
                'payment_method_id' => $dados->paymentMethodId,
                'card_id' => $cardId,
                'categoria_id' => $dados->categoriaId,
                'periodicidade' => $dados->periodicidade,
                'dia' => $dados->dia,
                'status' => Recurrence::STATUS_ATIVO,
                // Primeiro mês de origem ainda não gerado; a geração abaixo o avança.
                'proxima_em' => $primeiroMes->toDateString(),
            ]);

            AuditLog::create([
                'user_id' => $dados->userId,
                'entidade' => 'recurrence',
                'entidade_id' => $recorrencia->id,
                'acao' => AuditLog::ACAO_CRIAR,
                'antes' => null,
                'depois' => [
                    'descricao' => $recorrencia->descricao,
                    'valor_cents' => $recorrencia->valor_cents,
                    'dia' => $recorrencia->dia,
                    'card_id' => $cardId,
                    'periodicidade' => $recorrencia->periodicidade,
                    'proxima_em' => $primeiroMes->toDateString(),
                ],
                'origem' => 'recorrencia',
            ]);

            // A recorrência vale já no mês em que começa (D2): a ocorrência nasce junto com o
            // molde, sem passar pelo agendador. Meses futuros ficam para ele (ou para a projeção).
            $this->gerar->paraRecorrencia($recorrencia->id, $hoje);

            return $recorrencia->refresh();
        });
    }

    /**
     * Cartão é obrigatório quando a forma é `credito` e ignorado fora dela; o cartão tem de ser
     * do PRÓPRIO usuário (nunca aceitar um id alheio vindo da borda). Devolve o id a persistir.
     */
    private function resolverCartao(DadosRecorrencia $dados): ?int
    {
        $ehCredito = PaymentMethod::whereKey($dados->paymentMethodId)->value('tipo') === PaymentMethod::CREDITO;

        if (! $ehCredito) {
            return null;
        }

        if ($dados->cardId === null) {
            throw new InvalidArgumentException('Recorrência em cartão de crédito exige um cartão.');
        }

        $cardId = Card::query()
            ->where('user_id', $dados->userId)
            ->whereKey($dados->cardId)
            ->value('id');

        if ($cardId === null) {
            throw new InvalidArgumentException('Cartão inexistente ou de outro usuário.');
        }

        return (int) $cardId;
    }
}
