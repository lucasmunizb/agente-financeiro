<?php

namespace App\Models;

use Database\Factories\CategoryKeywordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Palavra-chave do lookup determinístico de categoria (doc 08 §1).
 * Única por categoria; armazenada normalizada para comparação por conteúdo.
 */
class CategoryKeyword extends Model
{
    /** @use HasFactory<CategoryKeywordFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'category_id',
        'palavra_chave',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
