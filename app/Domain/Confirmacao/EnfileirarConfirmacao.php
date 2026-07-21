<?php

declare(strict_types=1);

namespace App\Domain\Confirmacao;

use App\Domain\Gasto\DadosGastoManual;
use App\Models\PendingConfirmation;
use Carbon\CarbonImmutable;

/**
 * Coloca um gasto na FILA de confirmações pendentes (FE §7.9). É o ponto de entrada dos
 * PRODUTORES — recorrência mensal e importação de PDF — que nunca gravam direto (regra 7,
 * sem auto-save no MVP): materializam aqui e esperam o "sim" do usuário. O `payload` guarda
 * o {@see DadosGastoManual} já normalizado (centavos, regra 5); nada é recalculado depois.
 *
 * Recorrência NÃO passa mais por aqui (spec 12, D1): ela gera a ocorrência do mês direto, e a
 * regra 7 é honrada uma vez só, no cadastro do molde. Sobrou a importação de PDF — e o chat,
 * que tem a sua própria fila. A coluna `pending_confirmations.recurrence_id` continua no
 * schema (histórico), mas deixou de ser preenchida.
 */
final class EnfileirarConfirmacao
{
    public function enfileirar(
        DadosGastoManual $dados,
        string $origem,
        ?CarbonImmutable $expiraEm = null,
    ): PendingConfirmation {
        return PendingConfirmation::create([
            'user_id' => $dados->userId,
            'origem' => $origem,
            'tipo' => PendingConfirmation::TIPO_GASTO,
            'payload' => PayloadDoGasto::paraArray($dados),
            'status' => PendingConfirmation::STATUS_PENDENTE,
            // timestamptz: converter a UTC antes de gravar, senão o instante corrompe.
            'expira_em' => $expiraEm?->setTimezone('UTC'),
        ]);
    }
}
