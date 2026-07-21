<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingConfirmation>
 */
class PendingConfirmationFactory extends Factory
{
    protected $model = PendingConfirmation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'origem' => PendingConfirmation::ORIGEM_RECORRENCIA,
            'tipo' => PendingConfirmation::TIPO_GASTO,
            // payload no formato do DadosGastoManual (centavos, regra 5). paymentMethodId
            // resolvido em runtime nos testes/uso; aqui um PIX fora de cartão por padrão.
            'payload' => [
                'descricao' => 'Assinatura de streaming',
                'valorTotalCents' => 5590,
                'dataCompra' => '2026-07-05',
                'paymentMethodId' => PaymentMethod::firstOrCreate(['tipo' => PaymentMethod::PIX])->id,
                'parcelas' => 1,
                'cardId' => null,
                'accountId' => null,
                'categoriaId' => null,
                'origem' => 'recorrencia',
            ],
            'status' => PendingConfirmation::STATUS_PENDENTE,
            'transaction_id' => null,
            'expira_em' => null,
            'resolvido_em' => null,
        ];
    }

    public function confirmado(): static
    {
        return $this->state(fn () => ['status' => PendingConfirmation::STATUS_CONFIRMADO, 'resolvido_em' => now()]);
    }

    public function rejeitado(): static
    {
        return $this->state(fn () => ['status' => PendingConfirmation::STATUS_REJEITADO, 'resolvido_em' => now()]);
    }

    public function expirado(?CarbonImmutable $referencia = null): static
    {
        // Relativo ao "agora" DO TESTE (não ao relógio real — senão o teste apodrece)
        // e convertido a UTC antes de gravar (timestamptz).
        $ref = $referencia ?? CarbonImmutable::now('America/Sao_Paulo');

        return $this->state(fn () => ['expira_em' => $ref->subDay()->setTimezone('UTC')]);
    }
}
