<?php

declare(strict_types=1);

namespace App\Domain\Pagamento;

use App\Domain\Gasto\RegistrarPagamentoParcela;
use App\Domain\Recorrencia\PagarOcorrencia;
use Carbon\CarbonImmutable;

/**
 * Grava o pagamento de uma {@see ContaPagavel}, seja ela parcela de lançamento ou ocorrência
 * de recorrência — o despachante que permite ao bot (e a qualquer outro canal) tratar as duas
 * fontes como "uma conta a pagar".
 *
 * Não reimplementa regra alguma: delega aos dois serviços já testados, que mantêm as próprias
 * barreiras (fora de cartão, escopo por usuário, cancelado não paga, idempotência,
 * auditoria). Só a escolha do caminho mora aqui.
 */
final class PagarContaPagavel
{
    public function __construct(
        private readonly RegistrarPagamentoParcela $parcelas = new RegistrarPagamentoParcela,
        private readonly PagarOcorrencia $ocorrencias = new PagarOcorrencia,
    ) {}

    public function pagar(ContaPagavel $conta, int $userId, CarbonImmutable $agora): void
    {
        match ($conta->tipo) {
            // A parcela guarda a DATA do pagamento; a ocorrência, o INSTANTE da confirmação.
            ContaPagavel::TIPO_PARCELA => $this->parcelas->confirmar($conta->id, $userId, $agora),
            ContaPagavel::TIPO_OCORRENCIA => $this->ocorrencias->pagar($conta->id, $userId, $agora),
            default => null,
        };
    }
}
