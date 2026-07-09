<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOpaqueRouteId;
use Database\Factories\PendingConfirmationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um item da FILA de confirmações pendentes (FE §7.9). N por usuário, revisáveis 1 a 1 na web —
 * base comum para recorrência e importação de PDF. O `payload` é o DadosGastoManual normalizado
 * (centavos, regra 5); no "sim" reusa RegistrarGastoManual (regra 4/7). Id nunca em claro na URL
 * ({@see HasOpaqueRouteId}). O telegram_pending_confirmations (conversacional) segue separado.
 */
class PendingConfirmation extends Model
{
    /** @use HasFactory<PendingConfirmationFactory> */
    use HasFactory, HasOpaqueRouteId;

    /** Origem do pendente (de onde veio). */
    public const ORIGEM_CHAT = 'chat';
    public const ORIGEM_TELEGRAM = 'telegram';
    public const ORIGEM_RECORRENCIA = 'recorrencia';
    public const ORIGEM_IMPORTACAO = 'importacao';

    /** Ciclo de vida. */
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_CONFIRMADO = 'confirmado';
    public const STATUS_REJEITADO = 'rejeitado';
    public const STATUS_EXPIRADO = 'expirado';

    /** Tipo do que se confirma (só gasto no MVP). */
    public const TIPO_GASTO = 'gasto';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'origem',
        'tipo',
        'payload',
        'status',
        'transaction_id',
        'expira_em',
        'resolvido_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expira_em' => 'immutable_datetime',
            'resolvido_em' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
