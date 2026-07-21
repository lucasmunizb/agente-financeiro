<?php

declare(strict_types=1);

namespace App\Domain\Recorrencia;

use App\Domain\Calendar\RelativeDate;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\RecurrenceOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edição de UMA ocorrência — o escopo "só este mês" (spec 12). Como a ocorrência é um SNAPSHOT
 * auto-contido, alterar o mês corrente não toca o molde nem o passado; para propagar aos meses
 * seguintes existe {@see SincronizarRecorrencia}, que age sobre a `recurrence`.
 *
 * Altera descrição, valor (centavos, regra 5), categoria e vencimento. A competência acompanha
 * o novo vencimento — é ela que define em que mês a conta pesa (§4.5) —, o que pode mover a
 * ocorrência de mês; a UNIQUE `(recurrence_id, competencia)` impede que ela colida com outra já
 * existente. Escopo ESTRITO por usuário (404 para ocorrência alheia); registra auditoria.
 */
final class EditarOcorrencia
{
    private const ORIGEM = 'recorrencia';

    public function editar(
        int $ocorrenciaId,
        int $userId,
        ?string $descricao = null,
        ?int $valorCents = null,
        ?int $categoriaId = null,
        ?CarbonImmutable $vencimento = null,
    ): RecurrenceOccurrence {
        return DB::transaction(function () use ($ocorrenciaId, $userId, $descricao, $valorCents, $categoriaId, $vencimento): RecurrenceOccurrence {
            /** @var RecurrenceOccurrence $ocorrencia */
            $ocorrencia = RecurrenceOccurrence::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($ocorrenciaId);

            $antes = [
                'descricao' => $ocorrencia->descricao,
                'valor_cents' => $ocorrencia->valor_cents,
                'categoria_id' => $ocorrencia->categoria_id,
                'vencimento' => $ocorrencia->vencimento->toDateString(),
                'competencia' => $ocorrencia->competencia,
            ];

            $mudancas = [];

            if ($descricao !== null) {
                $mudancas['descricao'] = $descricao;
            }

            if ($valorCents !== null) {
                $mudancas['valor_cents'] = $valorCents;
            }

            // Categoria vira null quando o usuário a remove — então o "não informado" é o
            // parâmetro ausente, não o valor: usa-se o número de argumentos do chamador.
            $mudancas['categoria_id'] = $this->categoriaDoUsuario($categoriaId, $userId, $ocorrencia->categoria_id);

            if ($vencimento !== null) {
                $novo = $vencimento->setTimezone(RelativeDate::TIMEZONE)->startOfDay();
                $mudancas['vencimento'] = $novo->toDateString();
                // A competência é sempre a do vencimento: mover a data move o mês em que pesa.
                $mudancas['competencia'] = $novo->format('Y-m');
            }

            $ocorrencia->update($mudancas);

            AuditLog::create([
                'user_id' => $userId,
                'entidade' => 'recurrence_occurrence',
                'entidade_id' => $ocorrencia->id,
                'acao' => AuditLog::ACAO_EDITAR,
                'antes' => $antes,
                'depois' => [
                    'descricao' => $ocorrencia->descricao,
                    'valor_cents' => $ocorrencia->valor_cents,
                    'categoria_id' => $ocorrencia->categoria_id,
                    'vencimento' => $ocorrencia->vencimento->toDateString(),
                    'competencia' => $ocorrencia->competencia,
                ],
                'origem' => self::ORIGEM,
            ]);

            return $ocorrencia->refresh();
        });
    }

    /**
     * Só aceita categoria do PRÓPRIO usuário (nunca um id alheio vindo da borda); `null`
     * mantém a categoria atual — remover categoria é um caminho próprio, ainda não exposto.
     */
    private function categoriaDoUsuario(?int $categoriaId, int $userId, ?int $atual): ?int
    {
        if ($categoriaId === null) {
            return $atual;
        }

        $id = Category::query()->where('user_id', $userId)->whereKey($categoriaId)->value('id');

        return $id !== null ? (int) $id : $atual;
    }
}
