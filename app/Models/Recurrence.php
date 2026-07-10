<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOpaqueRouteId;
use Database\Factories\RecurrenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Recorrência mensal — assinatura/conta fixa (doc 03 §4.6 / doc 04 / spec 10). Entidade
 * própria com ciclo `ativo`/`cancelado`. Guarda o "molde" do gasto em centavos (regra 5);
 * NÃO calcula nem grava lançamento — o materializador enfileira uma confirmação por mês
 * (regra 7). Só fora de cartão e só mensal no MVP. Id nunca em claro na URL
 * ({@see HasOpaqueRouteId}); isolada por usuário; soft delete (LGPD).
 */
class Recurrence extends Model
{
    /** @use HasFactory<RecurrenceFactory> */
    use HasFactory, HasOpaqueRouteId, SoftDeletes;

    /** Ciclo de vida (doc 03 §4.6). */
    public const STATUS_ATIVO = 'ativo';

    public const STATUS_CANCELADO = 'cancelado';

    /** Única periodicidade no MVP da feature (a coluna é extensível). */
    public const PERIODICIDADE_MENSAL = 'mensal';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'descricao',
        'valor_cents',
        'payment_method_id',
        'categoria_id',
        'periodicidade',
        'dia',
        'status',
        'proxima_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valor_cents' => 'integer',
            'dia' => 'integer',
            'proxima_em' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categoria_id');
    }
}
