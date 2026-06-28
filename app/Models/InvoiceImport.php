<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Controle da pré-importação de fatura (doc 04 / spec 07 §5). Só metadados:
 * hash do nome do arquivo + status do fluxo. O PDF/texto nunca são persistidos.
 */
class InvoiceImport extends Model
{
    public const PENDENTE_REVISAO = 'pendente_revisao';

    public const CONFIRMADA = 'confirmada';

    public const PARCIAL = 'parcial';

    public const CANCELADA = 'cancelada';

    public const ERRO = 'erro';

    /** @var list<string> */
    public const STATUSES = [
        self::PENDENTE_REVISAO,
        self::CONFIRMADA,
        self::PARCIAL,
        self::CANCELADA,
        self::ERRO,
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'card_id',
        'hash_arquivo_nome',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
