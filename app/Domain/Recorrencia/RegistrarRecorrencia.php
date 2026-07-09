<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Models\AuditLog;
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
 */
final class RegistrarRecorrencia
{
    public function registrar(DadosRecorrencia $dados, CarbonImmutable $hoje): Recurrence
    {
        $this->recusarCartaoDeCredito($dados->paymentMethodId);

        return DB::transaction(function () use ($dados, $hoje): Recurrence {
            // "hoje" chega como instante — normaliza para o calendário de São Paulo antes
            // de resolver o dia-do-mês (o helper trabalha em calendário puro, sem shift).
            $proximaEm = OcorrenciaMensal::aPartirDe($dados->dia, $hoje->setTimezone(RelativeDate::TIMEZONE));

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
