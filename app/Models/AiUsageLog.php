<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\IA\Custo\TipoDeUsoIA;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de custo/uso de IA (ai_usage_log, doc 02 §3.6). Append-only: só `created_at`,
 * sem `updated_at`. Não carrega conteúdo — apenas metadados de custo.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $provider
 * @property string $model
 * @property int $tokens_entrada
 * @property int $tokens_saida
 * @property int $custo_estimado_cents
 * @property int|null $latencia_ms
 * @property TipoDeUsoIA $tipo
 */
class AiUsageLog extends Model
{
    protected $table = 'ai_usage_log';

    /** Append-only: o Eloquent não gerencia updated_at. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'tokens_entrada' => 'integer',
        'tokens_saida' => 'integer',
        'custo_estimado_cents' => 'integer',
        'latencia_ms' => 'integer',
        'tipo' => TipoDeUsoIA::class,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
