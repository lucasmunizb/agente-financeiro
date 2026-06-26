<?php

declare(strict_types=1);

namespace App\Domain\IA\Guard;

use App\Domain\Shared\Money;

/**
 * Guard pós-geração — barreira 4 (doc 02 §3.3). Camada NOSSA por cima da SDK: a
 * "regra de ouro" diz que a IA nunca produz um número que não veio do motor
 * determinístico. Antes de enviar, extraímos TODOS os valores monetários e datas
 * do texto redigido e validamos que cada um existe no {@see PayloadDeResposta}
 * calculado. Qualquer divergência reprova — o caller bloqueia e regenera/usa
 * fallback. Determinístico, sem IA.
 */
final class GuardPosGeracao
{
    /**
     * Valor monetário pt-BR: "R$ 35,90", "-R$ 50,00", "R$ 1.234,56" ou um decimal
     * com vírgula e duas casas ("47,90"). Inteiros sem vírgula nem "R$" (contagens
     * como "3 parcelas", "5 dias") são deliberadamente ignorados — não são dinheiro.
     */
    private const VALOR = '/-?R\$\s?\d{1,3}(?:\.\d{3})*(?:,\d{2})?|-?\d{1,3}(?:\.\d{3})*,\d{2}/u';

    /** Data numérica: "dd/mm" ou "dd/mm/aaaa". */
    private const DATA = '/\b\d{2}\/\d{2}(?:\/\d{4})?\b/';

    public function validar(string $texto, PayloadDeResposta $payload): ResultadoDoGuard
    {
        $valoresDivergentes = [];
        foreach (self::tokens(self::VALOR, $texto) as $token) {
            if (! $payload->permiteValor(Money::fromHuman($token)->cents())) {
                $valoresDivergentes[] = $token;
            }
        }

        $datasDivergentes = [];
        foreach (self::tokens(self::DATA, $texto) as $token) {
            [$dia, $mes, $ano] = array_pad(array_map('intval', explode('/', $token)), 3, null);

            if (! $payload->permiteData($dia, $mes, $ano)) {
                $datasDivergentes[] = $token;
            }
        }

        return new ResultadoDoGuard(
            aprovado: $valoresDivergentes === [] && $datasDivergentes === [],
            valoresDivergentes: $valoresDivergentes,
            datasDivergentes: $datasDivergentes,
        );
    }

    /**
     * @return list<string> tokens crus casados pelo padrão, na ordem do texto
     */
    private static function tokens(string $padrao, string $texto): array
    {
        preg_match_all($padrao, $texto, $matches);

        return array_map('trim', $matches[0]);
    }
}
