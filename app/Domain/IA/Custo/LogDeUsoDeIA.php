<?php

declare(strict_types=1);

namespace App\Domain\IA\Custo;

use Throwable;

/**
 * Registro de custo de UMA resposta da SDK em ai_usage_log (doc 02 §3.6), usável por
 * qualquer agente (auditoria P3-1: antes só o path de consulta logava — classificador,
 * extrator e sugeridor ficavam invisíveis no custo). Só metadados (provedor, modelo,
 * tokens, custo estimado, latência) — nunca o conteúdo (LGPD, doc 09). Falha em
 * silêncio: telemetria de custo jamais derruba a interação do usuário.
 */
final class LogDeUsoDeIA
{
    public static function registrar(
        object $resposta,
        TipoDeUsoIA $tipo,
        ?int $userId = null,
        ?float $inicio = null,
    ): void {
        try {
            $provider = $resposta->meta->provider ?? config('ai.default');
            $model = $resposta->meta->model ?? '';
            $tokensEntrada = (int) ($resposta->usage->promptTokens ?? 0);
            $tokensSaida = (int) ($resposta->usage->completionTokens ?? 0);

            // Sem uso real não há o que medir (respostas fake dos testes reportam 0).
            if ($tokensEntrada === 0 && $tokensSaida === 0) {
                return;
            }

            app(RegistrarUsoDeIA::class)->registrar(new UsoDeIA(
                provider: $provider,
                model: $model,
                tokensEntrada: $tokensEntrada,
                tokensSaida: $tokensSaida,
                custoEstimadoCents: app(CalculadoraDeCustoIA::class)
                    ->centavos($provider, $model, $tokensEntrada, $tokensSaida),
                latenciaMs: $inicio !== null ? (int) round((microtime(true) - $inicio) * 1000) : null,
                tipo: $tipo,
                userId: $userId,
            ));
        } catch (Throwable) {
            // Telemetria não pode quebrar o fluxo do usuário.
        }
    }
}
