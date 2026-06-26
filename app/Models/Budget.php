<?php

namespace App\Models;

use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orçamento mensal (doc 04 / doc 08 §6).
 * No MVP, o geral (categoria_id nulo); limite em centavos inteiros; mes "YYYY-MM".
 */
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'mes',
        'limite_cents',
        'categoria_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limite_cents' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categoria_id');
    }
}
