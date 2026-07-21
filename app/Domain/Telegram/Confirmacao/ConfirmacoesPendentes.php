<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Confirmacao;

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\IA\ConfirmacaoDeGasto;
use App\Domain\Recorrencia\DadosRecorrencia;
use App\Domain\Recorrencia\RegistrarRecorrencia;
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

    /**
     * Discriminador DENTRO do payload (não confundir com a coluna `tipo`, que discrimina a
     * FILA): o mesmo pendente carrega ou o molde de um gasto, ou o de uma recorrência
     * (spec 10c). Ausente ⇒ gasto — pendentes gravados antes da 10c seguem válidos.
     */
    private const MOLDE_GASTO = 'gasto';

    private const MOLDE_RECORRENCIA = 'recorrencia';

    private const TZ = 'America/Sao_Paulo';

    public function __construct(
        private readonly RegistrarGastoManual $registrar,
        private readonly RegistrarRecorrencia $registrarRecorrencia,
    ) {}

    public function guardar(int $userId, ConfirmacaoDeGasto $confirmacao, CarbonImmutable $agora): string
    {
        $token = (string) Str::uuid();

        TelegramPendingConfirmation::updateOrCreate(
            ['user_id' => $userId],
            [
                'tipo' => self::TIPO,
                'token' => $token,
                'payload' => $confirmacao->ehRecorrencia()
                    ? $this->serializarRecorrencia($confirmacao->recorrencia)
                    : $this->serializar($confirmacao->dados),
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

        if (($pendente->payload['molde'] ?? self::MOLDE_GASTO) === self::MOLDE_RECORRENCIA) {
            $recorrencia = $this->desserializarRecorrencia($pendente->payload);

            return new ConfirmacaoDeGasto(
                null, null, [],
                recorrencia: $recorrencia,
                previaRecorrencia: $this->registrarRecorrencia->preview($recorrencia),
            );
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
     * Molde da recorrência (spec 10c). Não há data/parcelas/cartão: recorrência é sempre
     * mensal e fora de cartão. O `dia` é inteiro validado (1..31); `proxima_em` NÃO é
     * serializada — é recalculada pelo domínio no "sim" (regra 4, determinismo).
     *
     * @return array<string, mixed>
     */
    private function serializarRecorrencia(DadosRecorrencia $dados): array
    {
        return [
            'molde' => self::MOLDE_RECORRENCIA,
            'userId' => $dados->userId,
            'descricao' => $dados->descricao,
            'valorCents' => $dados->valorCents,
            'paymentMethodId' => $dados->paymentMethodId,
            'dia' => $dados->dia,
            'categoriaId' => $dados->categoriaId,
            'periodicidade' => $dados->periodicidade,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function desserializarRecorrencia(array $payload): DadosRecorrencia
    {
        return new DadosRecorrencia(
            userId: (int) $payload['userId'],
            descricao: (string) $payload['descricao'],
            valorCents: (int) $payload['valorCents'],
            paymentMethodId: (int) $payload['paymentMethodId'],
            dia: (int) $payload['dia'],
            categoriaId: isset($payload['categoriaId']) ? (int) $payload['categoriaId'] : null,
            periodicidade: (string) $payload['periodicidade'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(DadosGastoManual $dados): array
    {
        return [
            'molde' => self::MOLDE_GASTO,
            'userId' => $dados->userId,
            'descricao' => $dados->descricao,
            'valorTotalCents' => $dados->valorTotalCents,
            'dataCompra' => $dados->dataCompra->toIso8601String(),
            'paymentMethodId' => $dados->paymentMethodId,
            'parcelas' => $dados->parcelas,
            'cardId' => $dados->cardId,
            'accountId' => $dados->accountId,
            'categoriaId' => $dados->categoriaId,
            'categoriaSugeridaPorIa' => $dados->categoriaSugeridaPorIa,
            // Data de calendário (não instante): serializa como Y-m-d e reidrata no fuso SP.
            'dataPagamento' => $dados->dataPagamento?->format('Y-m-d'),
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
            categoriaSugeridaPorIa: (bool) ($payload['categoriaSugeridaPorIa'] ?? false),
            dataPagamento: isset($payload['dataPagamento'])
                ? CarbonImmutable::parse((string) $payload['dataPagamento'], self::TZ)
                : null,
        );
    }
}
