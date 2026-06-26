<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Conta bancária para PIX/débito (doc 04 / doc 03 §4.6).
 * Isolada por usuário; exclusão lógica (LGPD).
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'banco',
        'descricao',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
