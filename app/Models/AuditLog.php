<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha de auditoria (doc 04). Registro append-only (imutável), SEM dados
 * sensíveis — apenas metadados e o diff antes/depois do registro auditado.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const ACAO_CRIAR = 'criar';
    public const ACAO_EDITAR = 'editar';
    public const ACAO_CANCELAR = 'cancelar';
    public const ACAO_EXCLUIR = 'excluir';
    public const ACAO_IMPORTAR = 'importar';

    protected $table = 'audit_log';

    /** Registro imutável: não há updated_at. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'entidade',
        'entidade_id',
        'acao',
        'antes',
        'depois',
        'origem',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entidade_id' => 'integer',
            'antes' => 'array',
            'depois' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
