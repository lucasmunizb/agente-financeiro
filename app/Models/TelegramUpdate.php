<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de update_id já processado (doc 06 §2/§3). Append-only: só
 * created_at. A unicidade de `update_id` garante a deduplicação no banco.
 */
class TelegramUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'telegram_updates';

    /** @var list<string> */
    protected $fillable = ['update_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
