<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo seguro Telegram (doc 06 §1). Guarda apenas o HASH do token (nunca o
 * token em claro) + expiração. `status`: pendente → ativo → revogado. Um vínculo
 * ativo por conta (índice parcial no banco).
 */
class TelegramLink extends Model
{
    public const PENDENTE = 'pendente';

    public const ATIVO = 'ativo';

    public const REVOGADO = 'revogado';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'telegram_user_id',
        'telefone',
        'token_hash',
        'token_expira_em',
        'status',
        'vinculado_em',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'token_expira_em' => 'datetime',
            'vinculado_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
