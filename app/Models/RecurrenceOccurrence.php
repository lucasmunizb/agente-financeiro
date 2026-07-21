<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOpaqueRouteId;
use Database\Factories\RecurrenceOccurrenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ocorrência mensal de uma recorrência (spec 12) — a ÚNICA representação de uma conta fixa
 * num mês. Recorrência NÃO gera mais {@see Transaction}/{@see Installment}: a dupla contagem
 * morre na constraint `UNIQUE (recurrence_id, competencia)`, não num guard de calendário.
 *
 * Auto-contida: carrega o snapshot do molde (descrição, valor em centavos — regra 5 —,
 * categoria, forma e cartão) no momento em que foi gerada, para a edição do molde não
 * reescrever o passado. `data_cobranca` é o dia do molde (quando sai do bolso, gatilho da
 * liquidação automática de cartão, D3); `vencimento` é o da fatura no cartão e o próprio dia
 * fora dele. Isolada por usuário; soft delete (LGPD); id nunca em claro na URL.
 */
class RecurrenceOccurrence extends Model
{
    /** @use HasFactory<RecurrenceOccurrenceFactory> */
    use HasFactory, HasOpaqueRouteId, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'recurrence_id',
        'competencia',
        'descricao',
        'valor_cents',
        'data_cobranca',
        'vencimento',
        'payment_method_id',
        'card_id',
        'categoria_id',
        'status_id',
        'data_pagamento',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valor_cents' => 'integer',
            'data_cobranca' => 'immutable_date',
            'vencimento' => 'immutable_date',
            'data_pagamento' => 'immutable_datetime',
        ];
    }

    /** Cobrança em cartão de crédito? Só estas liquidam sozinhas (D3) e entram na fatura. */
    public function ehCartao(): bool
    {
        return $this->card_id !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Recurrence, $this> */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(Recurrence::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categoria_id');
    }

    /** @return BelongsTo<StatusPagamento, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusPagamento::class, 'status_id');
    }
}
