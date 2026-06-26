<?php

declare(strict_types=1);

namespace App\Domain\IA\Consulta;

use App\Domain\IA\Guard\PayloadDeResposta;

/**
 * Coletor do "conjunto-verdade" de uma consulta (Bloco 6, doc 02 §3.3).
 *
 * As tools de IA executam durante a geração e devolvem ao modelo apenas TEXTO; este
 * coletor guarda, em paralelo, o que cada tool realmente calculou — o {@see
 * PayloadDeResposta} (valores/datas permitidos, barreira 4) e o {@see TraceDaConsulta}
 * (fonte, barreira 5). Ao final, o orquestrador valida a redação contra o payload
 * COMBINADO de todas as tools chamadas e anexa as fontes à resposta. Vive por
 * requisição: {@see limpar()} reseta entre tentativas de regeneração.
 */
final class ColetorDeConsultas
{
    /** @var list<PayloadDeResposta> */
    private array $payloads = [];

    /** @var list<TraceDaConsulta> */
    private array $fontes = [];

    public function registrar(PayloadDeResposta $payload, TraceDaConsulta $trace): void
    {
        $this->payloads[] = $payload;
        $this->fontes[] = $trace;
    }

    /**
     * Conjunto-verdade de todas as tools chamadas até agora (vazio se nenhuma).
     */
    public function payloadCombinado(): PayloadDeResposta
    {
        return PayloadDeResposta::combinar(...$this->payloads);
    }

    /**
     * @return list<TraceDaConsulta> fontes (trace) de cada tool chamada, na ordem
     */
    public function fontes(): array
    {
        return $this->fontes;
    }

    public function limpar(): void
    {
        $this->payloads = [];
        $this->fontes = [];
    }
}
