<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Confirmacao;

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Models\TelegramPendingConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Estado da confirmação de gasto pendente entre mensagens do bot (spec 04b §5/§6).
 *
 * Guarda o `DadosGastoManual` já normalizado (centavos, regra 5) para gravar no "sim", com
 * TTL de 15 min (§6.a) e UM pendente ativo por usuário (§6.b: o novo substitui o anterior).
 * A prévia (apresentação) é recomputada deterministicamente em `recuperar` — não persistimos
 * Money/parcelas. Escopo estrito por `user_id`; "agora" é injetado (determinismo). A
 * dataCompra é serializada com offset e reidratada no fuso de São Paulo (regra 5) — o
 * instante não corrompe no round-trip.
 */
final class ConfirmacoesPendentes
{
    public const TTL_MINUTOS = 15;

    /** Discriminador na fila compartilhada `telegram_pending_confirmations` (ver EsclarecimentosPendentes). */
    public const TIPO = 'confirmacao';

    private const TZ = 'America/Sao_Paulo';

    public function __construct(
        private readonly RegistrarGastoManual $registrar,
    ) {}

    public function guardar(int $userId, ConfirmacaoDeGasto $confirmacao, CarbonImmutable $agora): string
    {
        $token = (string) Str::uuid();

        TelegramPendingConfirmation::updateOrCreate(
            ['user_id' => $userId],
            [
                'tipo' => self::TIPO,
                'token' => $token,
                'payload' => $this->serializar($confirmacao->dados),
                'expira_em' => $agora->addMinutes(self::TTL_MINUTOS)->setTimezone('UTC'),
            ],
        );

        return $token;
    }

    public function recuperar(int $userId, CarbonImmutable $agora): ?ConfirmacaoDeGasto
    {
        $pendente = TelegramPendingConfirmation::query()
            ->where('user_id', $userId)
            ->where('tipo', self::TIPO)
            ->where('expira_em', '>=', $agora->setTimezone('UTC'))
            ->first();

        if ($pendente === null) {
            return null;
        }

        $dados = $this->desserializar($pendente->payload);
        $previa = $this->registrar->preview($dados, $agora);

        return new ConfirmacaoDeGasto($previa, $dados, []);
    }

    public function descartar(int $userId): void
    {
        TelegramPendingConfirmation::query()->where('user_id', $userId)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(DadosGastoManual $dados): array
    {
        return [
            'userId' => $dados->userId,
            'descricao' => $dados->descricao,
            'valorTotalCents' => $dados->valorTotalCents,
            'dataCompra' => $dados->dataCompra->toIso8601String(),
            'paymentMethodId' => $dados->paymentMethodId,
            'parcelas' => $dados->parcelas,
            'cardId' => $dados->cardId,
            'accountId' => $dados->accountId,
            'categoriaId' => $dados->categoriaId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function desserializar(array $payload): DadosGastoManual
    {
        return new DadosGastoManual(
            userId: (int) $payload['userId'],
            descricao: (string) $payload['descricao'],
            valorTotalCents: (int) $payload['valorTotalCents'],
            dataCompra: CarbonImmutable::parse($payload['dataCompra'])->setTimezone(self::TZ),
            paymentMethodId: (int) $payload['paymentMethodId'],
            parcelas: (int) $payload['parcelas'],
            cardId: isset($payload['cardId']) ? (int) $payload['cardId'] : null,
            accountId: isset($payload['accountId']) ? (int) $payload['accountId'] : null,
            categoriaId: isset($payload['categoriaId']) ? (int) $payload['categoriaId'] : null,
        );
    }
}
