<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confirmação de gasto pendente entre mensagens do bot (spec 04b §5). Estado efêmero:
 * o `payload` carrega o `DadosGastoManual` normalizado para gravar no "sim" (em centavos,
 * regra 5); `expira_em` aplica o TTL (§6.a) e `token` identifica o pendente para o
 * callback de botão (§C8, opcional). Um por usuário (§6.b).
 */
class TelegramPendingConfirmation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'token',
        'payload',
        'expira_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expira_em' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
