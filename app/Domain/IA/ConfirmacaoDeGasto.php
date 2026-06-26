<?php

declare(strict_types=1);

namespace App\Domain\IA;

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\PreviaGastoManual;

/**
 * Resultado da preparação da confirmação de um gasto interpretado pela IA. Ou há uma
 * `previa` calculada (sem persistir — regra inviolável 7) acompanhada do `dados` pronto
 * para persistir no "sim", ou há `esclarecimentos` a resolver antes de seguir. A mensagem
 * de confirmação ao usuário (texto/botões) é frontend, etapa separada.
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
    ) {}

    public function precisaEsclarecer(): bool
    {
        return $this->esclarecimentos !== [];
    }

    public function confirmavel(): bool
    {
        return $this->previa !== null;
    }
}
