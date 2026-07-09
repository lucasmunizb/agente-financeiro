<?php

namespace App\Models;

use App\Domain\Shared\Money;
use Database\Factories\InstallmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parcela de um lançamento (doc 04 / doc 03 §4.1).
 *
 * Guarda a estrutura N/total + vencimento + status. NÃO guarda valor: ele é
 * derivado do total da transaction via {@see Money::allocate()} — o resto vai
 * para as primeiras parcelas, sem perder centavos.
 */
class Installment extends Model
{
    /** @use HasFactory<InstallmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'transaction_id',
        'numero',
        'total',
        'vencimento',
        'data_pagamento',
        'status_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'total' => 'integer',
            'vencimento' => 'immutable_date',
            'data_pagamento' => 'immutable_date',
        ];
    }

    /**
     * Valor desta parcela, DERIVADO do total da transaction (nunca persistido).
     */
    public function valor(): Money
    {
        return Money::fromCents($this->transaction->valor_total_cents)
            ->allocate($this->total)[$this->numero - 1];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusPagamento::class, 'status_id');
    }
}
