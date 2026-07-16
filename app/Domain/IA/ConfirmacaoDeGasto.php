<?php

declare(strict_types=1);

namespace App\Domain\IA;

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\PreviaGastoManual;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\PreviaRecorrencia;

/**
 * Resultado da preparação da confirmação de um gasto interpretado pela IA. Ou há uma
 * `previa` calculada (sem persistir — regra inviolável 7) acompanhada do `dados` pronto
 * para persistir no "sim", ou há `esclarecimentos` a resolver antes de seguir. A mensagem
 * de confirmação ao usuário (texto/botões) é frontend, etapa separada.
 *
 * A mesma mensagem pode descrever uma RECORRÊNCIA mensal (spec 10c): nesse caso viaja o par
 * `previaRecorrencia`/`recorrencia` no lugar de `previa`/`dados`, e o "sim" chama
 * `RegistrarRecorrencia` em vez de `RegistrarGastoManual`. A prévia da recorrência mostra o
 * MOLDE (valor, dia, forma) — não há parcelas a projetar: o lançamento só nasce quando o
 * materializador enfileirar, no dia (spec 10).
 */
final readonly class ConfirmacaoDeGasto
{
    /**
     * @param  list<string>  $esclarecimentos
     */
    public function __construct(
        public ?PreviaGastoManual $previa,
        public ?DadosGastoManual $dados,
        public array $esclarecimentos,
        public ?DadosRecorrencia $recorrencia = null,
        public ?PreviaRecorrencia $previaRecorrencia = null,
    ) {}

    public function precisaEsclarecer(): bool
    {
        return $this->esclarecimentos !== [];
    }

    public function confirmavel(): bool
    {
        return $this->previa !== null || $this->recorrencia !== null;
    }

    /** Distingue os dois moldes confirmáveis sem obrigar o caller a inspecionar cada slot. */
    public function ehRecorrencia(): bool
    {
        return $this->recorrencia !== null;
    }
}
