<?php

namespace App\Models;

use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cartão identificado por 4 dígitos finais + descrição (doc 03 §4.6).
 * Limite opcional em centavos inteiros. Isolado por usuário; exclusão lógica (LGPD).
 */
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'descricao',
        'final_4',
        'limite_cents',
        'dia_fechamento',
        'dia_vencimento',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limite_cents' => 'integer',
            'dia_fechamento' => 'integer',
            'dia_vencimento' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
