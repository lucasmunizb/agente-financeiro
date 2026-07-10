<?php

declare(strict_types=1);

namespace App\Domain\Confirmacao;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\DadosGastoManual;
use Carbon\CarbonImmutable;

/**
 * Serializa/reidrata o {@see DadosGastoManual} de/para o `payload` (jsonb) de um
 * pending_confirmation. O `user_id` mora na coluna da linha — não no payload — e é
 * reinjetado na reidratação (escopo por usuário). Datas em `Y-m-d` (fuso SP); dinheiro
 * já em centavos (regra 5). Nenhum cálculo aqui: só tradução.
 */
final class PayloadDoGasto
{
    /** @return array<string, mixed> */
    public static function paraArray(DadosGastoManual $d): array
    {
        return [
            'descricao' => $d->descricao,
            'valorTotalCents' => $d->valorTotalCents,
            'dataCompra' => $d->dataCompra->format('Y-m-d'),
            'paymentMethodId' => $d->paymentMethodId,
            'parcelas' => $d->parcelas,
            'cardId' => $d->cardId,
            'accountId' => $d->accountId,
            'categoriaId' => $d->categoriaId,
            'categoriaSugeridaPorIa' => $d->categoriaSugeridaPorIa,
            'origem' => $d->origem,
            'recurrenceId' => $d->recurrenceId,
        ];
    }

    /** @param  array<string, mixed>  $p */
    public static function paraDados(array $p, int $userId): DadosGastoManual
    {
        return new DadosGastoManual(
            userId: $userId,
            descricao: (string) $p['descricao'],
            valorTotalCents: (int) $p['valorTotalCents'],
            dataCompra: CarbonImmutable::parse((string) $p['dataCompra'], RelativeDate::TIMEZONE),
            paymentMethodId: (int) $p['paymentMethodId'],
            parcelas: (int) ($p['parcelas'] ?? 1),
            cardId: isset($p['cardId']) ? (int) $p['cardId'] : null,
            accountId: isset($p['accountId']) ? (int) $p['accountId'] : null,
            categoriaId: isset($p['categoriaId']) ? (int) $p['categoriaId'] : null,
            origem: (string) ($p['origem'] ?? 'manual'),
            recurrenceId: isset($p['recurrenceId']) ? (int) $p['recurrenceId'] : null,
            categoriaSugeridaPorIa: (bool) ($p['categoriaSugeridaPorIa'] ?? false),
        );
    }
}
