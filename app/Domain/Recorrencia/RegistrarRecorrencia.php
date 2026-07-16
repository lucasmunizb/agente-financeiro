<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Shared\Money;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cadastra uma recorrência mensal `ativo` (spec 10). Guarda o "molde" do gasto em centavos
 * (regra 5) e o ponteiro `proxima_em` da próxima ocorrência (a partir de "hoje", injetado —
 * determinismo). Recusa cartão de crédito (crédito usa parcelas, não recorrência). Registra
 * auditoria. NÃO grava lançamento — o materializador é quem enfileira, no dia (regra 7).
 *
 * `$primeiraReferencia` (opcional) muda a data-base da 1ª ocorrência: no form de gasto o mês
 * atual já é lançado como gasto avulso, então a recorrência começa no MÊS SEGUINTE (o caller
 * passa o 1º dia do próximo mês) — evita contar o mês atual em dobro. Ausente ⇒ usa "hoje".
 */
final class RegistrarRecorrencia
{
    /**
     * Prévia do molde, para a confirmação (regra 7) — NÃO persiste nada. Resolve forma de
     * pagamento e categoria em texto aqui, no domínio, para a apresentação não consultar o
     * banco (mesmo contrato de {@see \App\Domain\Gasto\RegistrarGastoManual::preview()}).
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

    public function registrar(
        DadosRecorrencia $dados,
        CarbonImmutable $hoje,
        ?CarbonImmutable $primeiraReferencia = null,
    ): Recurrence {
        $this->recusarCartaoDeCredito($dados->paymentMethodId);

        return DB::transaction(function () use ($dados, $hoje, $primeiraReferencia): Recurrence {
            // A referência chega como instante — normaliza para o calendário de São Paulo antes
            // de resolver o dia-do-mês (o helper trabalha em calendário puro, sem shift).
            $referencia = ($primeiraReferencia ?? $hoje)->setTimezone(RelativeDate::TIMEZONE);
            $proximaEm = OcorrenciaMensal::aPartirDe($dados->dia, $referencia);

            $recorrencia = Recurrence::create([
                'user_id' => $dados->userId,
                'descricao' => $dados->descricao,
                'valor_cents' => $dados->valorCents,
                'payment_method_id' => $dados->paymentMethodId,
                'categoria_id' => $dados->categoriaId,
                'periodicidade' => $dados->periodicidade,
                'dia' => $dados->dia,
                'status' => Recurrence::STATUS_ATIVO,
                'proxima_em' => $proximaEm->format('Y-m-d'),
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
                    'periodicidade' => $recorrencia->periodicidade,
                    'proxima_em' => $proximaEm->format('Y-m-d'),
                ],
                'origem' => 'recorrencia',
            ]);

            return $recorrencia;
        });
    }

    private function recusarCartaoDeCredito(int $paymentMethodId): void
    {
        $tipo = PaymentMethod::whereKey($paymentMethodId)->value('tipo');

        if ($tipo === PaymentMethod::CREDITO) {
            throw new InvalidArgumentException(
                'Recorrência não pode ser em cartão de crédito (crédito usa parcelas).'
            );
        }
    }
}
