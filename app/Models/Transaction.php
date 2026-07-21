<?php

namespace App\Models;

use App\Models\Concerns\HasOpaqueRouteId;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lançamento financeiro (doc 04 / doc 03 §4.6).
 *
 * Dinheiro em centavos inteiros (`valor_total_cents`). O valor por parcela é
 * derivado (ver {@see Installment::valor()}), nunca persistido. Isolado por
 * usuário; origem auditável; exclusão lógica (LGPD). O id NUNCA sai em claro na
 * URL: os links de recurso usam o token opaco ({@see HasOpaqueRouteId}).
 *
 * Recorrência NÃO vive aqui (spec 12): a conta fixa de um mês é uma
 * {@see RecurrenceOccurrence}. Um lançamento é sempre uma compra/cobrança avulsa.
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasOpaqueRouteId, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'descricao',
        'valor_total_cents',
        'data_compra',
        'payment_method_id',
        'card_id',
        'account_id',
        'categoria_id',
        'status_id',
        'origem',
        'moeda',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor_total_cents' => 'integer',
            'data_compra' => 'immutable_date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusPagamento::class, 'status_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categoria_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }
}
