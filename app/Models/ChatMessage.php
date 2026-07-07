<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma mensagem do chat financeiro web (spec FE §7.14). `role` é 'user' ou 'assistant'.
 * Em respostas do assistente, `fontes` traz o trace das tools (barreira 5) e `aprovado`
 * o veredito do guard (barreira 4). `tem_anexo` marca que a mensagem trouxe um PDF — o
 * arquivo em si nunca é persistido (regra 6). Sempre isolada por `user_id`.
 */
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
        'body',
        'fontes',
        'aprovado',
        'tem_anexo',
    ];

    protected function casts(): array
    {
        return [
            'fontes' => 'array',
            'aprovado' => 'boolean',
            'tem_anexo' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
