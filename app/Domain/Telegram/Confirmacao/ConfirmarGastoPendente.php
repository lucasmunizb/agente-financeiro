<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Confirmacao;

use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Recorrencia\RegistrarRecorrencia;
use App\Models\Recurrence;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Materializa a regra 7 no canal do bot (spec 04b §3/§6): no "sim", recupera o pendente
 * (escopo/TTL — §C4/§C5) e REUSA o domínio para gravar — sem recalcular nada (regra 4).
 * Descarta o pendente na MESMA transação da gravação, garantindo uso único: um segundo
 * "sim" não grava de novo (§C3). Devolve `null` quando não há nada confirmável (inexistente,
 * expirado ou de outro usuário) — nunca grava por engano.
 *
 * O pendente carrega um de dois moldes (spec 10c): um gasto (→ `RegistrarGastoManual`, nasce
 * um lançamento) ou uma recorrência (→ `RegistrarRecorrencia`, nasce só o molde mensal; o
 * lançamento vem depois, pelo materializador da spec 10).
 */
final class ConfirmarGastoPendente
{
    public function __construct(
        private readonly ConfirmacoesPendentes $pendentes,
        private readonly RegistrarGastoManual $registrar,
        private readonly RegistrarRecorrencia $registrarRecorrencia,
    ) {}

    public function confirmar(int $userId, CarbonImmutable $agora): Transaction|Recurrence|null
    {
        return DB::transaction(function () use ($userId, $agora) {
            $confirmacao = $this->pendentes->recuperar($userId, $agora);

            if ($confirmacao === null || ! $confirmacao->confirmavel()) {
                return null;
            }

            $gravado = $confirmacao->ehRecorrencia()
                ? $this->registrarRecorrencia->registrar($confirmacao->recorrencia, $agora)
                : $this->registrar->confirmar($confirmacao->dados, $agora);

            $this->pendentes->descartar($userId);

            return $gravado;
        });
    }
}
