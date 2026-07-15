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
 *
 * Cobre os formatos formais E coloquiais (auditoria P1-1): "R$ 1.234,56",
 * "47,90", "R$ 35,9", "R$ 1500", "1500 reais", "dois reais e cinquenta
 * centavos", "mil e quinhentos reais", "10/07", "5 de junho de 2026",
 * "vinte e cinco de dezembro". Inteiros soltos sem marcador monetário
 * ("3 parcelas", "5 dias") seguem deliberadamente ignorados — não são dinheiro.
 */
final class GuardPosGeracao
{
    /**
     * Valor monetário pt-BR em dígitos: "R$ 1.234,56", "R$ 1500", "R$ 35,9",
     * "-R$ 50,00", "47,90". Sem "R$", só com vírgula decimal (inteiro solto não
     * é dinheiro).
     */
    private const VALOR = '/-?R\$\s?\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|-?R\$\s?\d+(?:[.,]\d{1,2})?|-?\d{1,3}(?:\.\d{3})+,\d{1,2}|-?\d+,\d{1,2}/u';

    /** Dígitos com sufixo "reais/real", com centavos opcionais: "1500 reais", "2 reais e 50 centavos". */
    private const VALOR_REAIS_DIGITOS = '/(-?\d+(?:[.,]\d{1,2})?)\s*(?:reais|real)\b(?:\s+e\s+(\d{1,2}|\p{L}+(?:\s+\p{L}+){0,3})\s+centavos?\b)?/iu';

    /** Palavras antes de "reais/real" (número por extenso), com centavos opcionais. */
    private const VALOR_REAIS_EXTENSO = '/((?:\p{L}+\s+){1,8}?)(?:reais|real)\b(?:\s+e\s+(\d{1,2}|\p{L}+(?:\s+\p{L}+){0,3})\s+centavos?\b)?/iu';

    /** Centavos isolados: "50 centavos", "cinquenta centavos". */
    private const CENTAVOS = '/(\d{1,2}|\p{L}+(?:\s+\p{L}+){0,3})\s+centavos?\b/iu';

    /** Data numérica: "dd/mm" ou "dd/mm/aaaa". */
    private const DATA = '/\b\d{2}\/\d{2}(?:\/\d{4})?\b/';

    /** Data por extenso: "5 de junho", "cinco de junho de 2026", "vinte e cinco de dezembro". */
    private const DATA_EXTENSO = '/\b(\d{1,2}|\p{L}+(?:\s+e\s+\p{L}+)?)\s+de\s+(janeiro|fevereiro|mar[cç]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b(?:\s+de\s+(\d{4}))?/iu';

    /** @var array<string, int> meses normalizados (sem acento) → número */
    private const MESES = [
        'janeiro' => 1, 'fevereiro' => 2, 'marco' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8,
        'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    public function validar(string $texto, PayloadDeResposta $payload): ResultadoDoGuard
    {
        $valoresDivergentes = [];
        foreach ($this->valoresCitados($texto) as [$token, $cents]) {
            if (! $payload->permiteValor($cents)) {
                $valoresDivergentes[] = $token;
            }
        }

        $datasDivergentes = [];
        foreach ($this->datasCitadas($texto) as [$token, $dia, $mes, $ano]) {
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
     * Todos os valores monetários citados no texto, em qualquer formato reconhecido.
     *
     * @return list<array{string, int}> pares [token citado, centavos]
     */
    private function valoresCitados(string $texto): array
    {
        $citados = [];
        $consumido = []; // spans [início, fim) já atribuídos a um valor composto

        // 1) "<extenso> reais( e <x> centavos)" — ex.: "mil e quinhentos reais".
        preg_match_all(self::VALOR_REAIS_EXTENSO, $texto, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $palavras = preg_split('/\s+/', trim($m[1][0]), flags: PREG_SPLIT_NO_EMPTY) ?: [];

            // Maior sufixo de palavras que forma um número ("gastou mil e quinhentos" → "mil e quinhentos").
            $reais = null;
            foreach (array_keys($palavras) as $i) {
                if (NumeroPorExtenso::normalizar($palavras[$i]) === 'e') {
                    continue; // token não começa em conectivo
                }

                $reais = NumeroPorExtenso::tentarParse(implode(' ', array_slice($palavras, $i)));

                if ($reais !== null) {
                    break;
                }
            }

            if ($reais === null) {
                continue; // "vários reais" — não há número a validar
            }

            $cents = $reais * 100 + ($this->centavosDe($m) ?? 0);
            $citados[] = [trim($m[0][0]), $cents];
            $consumido[] = [$m[0][1], $m[0][1] + strlen($m[0][0])];
        }

        // 2) "<dígitos> reais( e <x> centavos)" — ex.: "1500 reais", "2 reais e 50 centavos".
        preg_match_all(self::VALOR_REAIS_DIGITOS, $texto, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $cents = Money::fromHuman($m[1][0])->cents() + ($this->centavosDe($m) ?? 0);
            $citados[] = [trim($m[0][0]), $cents];
            $consumido[] = [$m[0][1], $m[0][1] + strlen($m[0][0])];
        }

        // 3) "<x> centavos" isolado — pula os já consumidos como parte de "reais e centavos".
        preg_match_all(self::CENTAVOS, $texto, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            if ($this->dentroDeSpanConsumido($m[0][1], $consumido)) {
                continue;
            }

            $centavos = $this->parseNumeroNoFinal($m[1][0]);

            if ($centavos !== null) {
                $citados[] = [trim($m[0][0]), $centavos];
            }
        }

        // 4) Dígitos com R$ e/ou vírgula decimal (formato formal).
        preg_match_all(self::VALOR, $texto, $matches);
        foreach ($matches[0] as $token) {
            $citados[] = [trim($token), Money::fromHuman($token)->cents()];
        }

        return $citados;
    }

    /**
     * Todas as datas citadas no texto (numéricas e por extenso).
     *
     * @return list<array{string, int, int, int|null}> [token, dia, mês, ano|null]
     */
    private function datasCitadas(string $texto): array
    {
        $datas = [];

        preg_match_all(self::DATA, $texto, $matches);
        foreach ($matches[0] as $token) {
            [$dia, $mes, $ano] = array_pad(array_map('intval', explode('/', $token)), 3, null);
            $datas[] = [trim($token), $dia, $mes, $ano];
        }

        preg_match_all(self::DATA_EXTENSO, $texto, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $dia = $this->parseNumero($m[1]);

            if ($dia === null || $dia < 1 || $dia > 31) {
                continue; // "depois de junho" — não é uma data
            }

            $mes = self::MESES[NumeroPorExtenso::normalizar($m[2])] ?? null;

            if ($mes === null) {
                continue;
            }

            $ano = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
            $datas[] = [trim($m[0]), $dia, $mes, $ano];
        }

        return $datas;
    }

    /** Grupo opcional de centavos de um match de "reais": dígitos ou extenso; null quando ausente/não-numérico. */
    private function centavosDe(array $match): ?int
    {
        if (! isset($match[2]) || $match[2][0] === '') {
            return null;
        }

        return $this->parseNumero($match[2][0]);
    }

    /** Número em dígitos ou por extenso; null quando o trecho não é um número. */
    private function parseNumero(string $trecho): ?int
    {
        $trecho = trim($trecho);

        return ctype_digit($trecho) ? (int) $trecho : NumeroPorExtenso::tentarParse($trecho);
    }

    /**
     * Como {@see parseNumero()}, mas tolera palavras não-numéricas no INÍCIO do trecho
     * ("Faltaram cinquenta" → 50): usa o maior sufixo de palavras que forma um número.
     */
    private function parseNumeroNoFinal(string $trecho): ?int
    {
        $palavras = preg_split('/\s+/', trim($trecho), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_keys($palavras) as $i) {
            $numero = $this->parseNumero(implode(' ', array_slice($palavras, $i)));

            if ($numero !== null) {
                return $numero;
            }
        }

        return null;
    }

    /** @param list<array{int, int}> $spans */
    private function dentroDeSpanConsumido(int $offset, array $spans): bool
    {
        foreach ($spans as [$inicio, $fim]) {
            if ($offset >= $inicio && $offset < $fim) {
                return true;
            }
        }

        return false;
    }
}
