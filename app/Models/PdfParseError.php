<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Erro de parsing de fatura (doc 04 / spec 07 §5): descrição NÃO sensível de um
 * trecho que o parser não reconheceu, para evoluir o parser. Nunca guarda o trecho
 * do PDF nem dado sensível. Relação N:N com {@see Bank}.
 */
class PdfParseError extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'descricao_erro',
    ];

    public function banks(): BelongsToMany
    {
        return $this->belongsToMany(Bank::class);
    }
}
