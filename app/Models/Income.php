<?php

namespace App\Models;

use Database\Factories\IncomeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Receita — base do "disponível do mês" (doc 04 / doc 03 §4.5).
 * Dinheiro em centavos inteiros; tipo fixa/variável; isolada por usuário; LGPD.
 */
class Income extends Model
{
    /** @use HasFactory<IncomeFactory> */
    use HasFactory, SoftDeletes;

    public const TIPO_FIXA = 'fixa';
    public const TIPO_VARIAVEL = 'variavel';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'descricao',
        'valor_cents',
        'data',
        'tipo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor_cents' => 'integer',
            'data' => 'immutable_date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
