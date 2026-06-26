<?php

namespace App\Models;

use Database\Factories\MerchantAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regra fixa por estabelecimento (doc 08 §1/§2): "sempre Uber = transporte".
 * Isolado por usuário; alias único por usuário; armazenado normalizado.
 */
class MerchantAlias extends Model
{
    /** @use HasFactory<MerchantAliasFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'category_id',
        'alias',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
