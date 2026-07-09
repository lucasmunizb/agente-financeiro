<?php

namespace App\Models;

use App\Models\Concerns\HasOpaqueRouteId;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Categoria do gasto (doc 08 / doc 04).
 *
 * Categoria única por gasto; sem subcategoria no MVP. Tem cor e ícone; pode ser
 * arquivada sem perder histórico. Isolada por usuário; exclusão lógica (LGPD). Na
 * borda web o id só aparece CRIPTOGRAFADO ({@see HasOpaqueRouteId::opaqueId()} no
 * filtro da lista de lançamentos).
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasOpaqueRouteId, SoftDeletes;

    /** @var list<string> Categorias sugeridas na criação de um usuário (doc 08 §5). */
    public const SUGERIDAS = [
        'Alimentação',
        'Apostas',
        'Futebol',
        'Moradia',
        'Transporte',
        'Saúde',
        'Lazer',
        'Educação',
        'Assinaturas',
        'Cartão/Taxas',
        'Outros',
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'nome',
        'cor',
        'icone',
        'arquivada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arquivada' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(CategoryKeyword::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MerchantAlias::class);
    }
}
