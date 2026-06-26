<?php

declare(strict_types=1);

namespace App\Domain\IA\Guard;

use Carbon\CarbonInterface;

/**
 * Conjunto de dados JÁ calculados pelo motor determinístico que a resposta da IA
 * pode citar (doc 02 §3.3, barreira 4). Guarda os valores monetários permitidos
 * (centavos inteiros) e as datas permitidas, e responde se um valor/data citado
 * pelo texto pertence ao que foi calculado. É a "verdade" contra a qual o
 * {@see GuardPosGeracao} valida o texto redigido.
 */
final class PayloadDeResposta
{
    /** @var list<int> valores permitidos, em centavos inteiros */
    private readonly array $valoresEmCentavos;

    /** @var list<CarbonInterface> datas permitidas (fuso de São Paulo) */
    private readonly array $datas;

    /**
     * @param  array<int, int>  $valoresEmCentavos
     * @param  array<int, CarbonInterface>  $datas
     */
    public function __construct(array $valoresEmCentavos = [], array $datas = [])
    {
        $this->valoresEmCentavos = array_values($valoresEmCentavos);
        $this->datas = array_values($datas);
    }

    /**
     * Funde vários payloads num único conjunto-verdade — usado quando a resposta da IA
     * se apoia em mais de uma tool numa mesma consulta (Bloco 6). Sem argumentos,
     * devolve um payload vazio (não permite nenhum valor/data).
     */
    public static function combinar(self ...$payloads): self
    {
        $valores = [];
        $datas = [];

        foreach ($payloads as $payload) {
            foreach ($payload->valoresEmCentavos as $cents) {
                $valores[] = $cents;
            }

            foreach ($payload->datas as $data) {
                $datas[] = $data;
            }
        }

        return new self($valores, $datas);
    }

    /**
     * O payload permite este valor monetário (em centavos)?
     */
    public function permiteValor(int $cents): bool
    {
        return in_array($cents, $this->valoresEmCentavos, strict: true);
    }

    /**
     * O payload permite esta data? Quando o ano é desconhecido (texto "dd/mm"),
     * basta o dia e o mês baterem com alguma data permitida.
     */
    public function permiteData(int $dia, int $mes, ?int $ano): bool
    {
        foreach ($this->datas as $data) {
            if ($data->day !== $dia || $data->month !== $mes) {
                continue;
            }

            if ($ano === null || $data->year === $ano) {
                return true;
            }
        }

        return false;
    }
}
