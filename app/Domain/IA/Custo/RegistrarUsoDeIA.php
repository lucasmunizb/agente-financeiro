<?php

declare(strict_types=1);

namespace App\Domain\IA\Custo;

use App\Models\AiUsageLog;

/**
 * Persiste o custo/uso de uma chamada de IA em ai_usage_log (doc 02 §3.6). Recebe apenas
 * metadados ({@see UsoDeIA}) — nunca conteúdo de mensagem. Append-only.
 */
final class RegistrarUsoDeIA
{
    public function registrar(UsoDeIA $uso): AiUsageLog
    {
        return AiUsageLog::query()->create([
            'user_id' => $uso->userId,
            'provider' => $uso->provider,
            'model' => $uso->model,
            'tokens_entrada' => $uso->tokensEntrada,
            'tokens_saida' => $uso->tokensSaida,
            'custo_estimado_cents' => $uso->custoEstimadoCents,
            'latencia_ms' => $uso->latenciaMs,
            'tipo' => $uso->tipo,
        ]);
    }
}
