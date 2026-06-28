<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Banco suportado pela importação de fatura (doc 04 / spec 07 §5). Tabela de
 * referência (código único). MVP: apenas Itaú; o pipeline nasce extensível.
 */
class Bank extends Model
{
    public const ITAU = 'itau';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nome',
    ];

    public function pdfParseErrors(): BelongsToMany
    {
        return $this->belongsToMany(PdfParseError::class);
    }
}
