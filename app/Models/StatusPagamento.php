<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Status de pagamento — tabela de referência (doc 03 §4.4).
 * Conjunto inicial de 8 códigos populado por seeder; FK (status_id) nos lançamentos.
 */
class StatusPagamento extends Model
{
    public const ABERTO = 'aberto';
    public const PAGO = 'pago';
    public const PAGO_PARCIAL = 'pago_parcial';
    public const VENCIDO = 'vencido';
    public const CANCELADO = 'cancelado';
    public const ESTORNADO = 'estornado';
    public const PENDENTE_REVISAO = 'pendente_revisao';
    public const AGENDADO = 'agendado';

    /** @var array<string, string> Código => descrição (doc 03 §4.4). */
    public const CODIGOS = [
        self::ABERTO => 'Em aberto, ainda não pago',
        self::PAGO => 'Quitado integralmente',
        self::PAGO_PARCIAL => 'Pago parcialmente; saldo remanescente',
        self::VENCIDO => 'Vencimento anterior à data atual e não pago',
        self::CANCELADO => 'Cancelado pelo usuário (mantém histórico)',
        self::ESTORNADO => 'Estorno/reembolso registrado',
        self::PENDENTE_REVISAO => 'Extraído de PDF, aguardando confirmação humana',
        self::AGENDADO => 'Parcela futura ainda não vencida',
    ];

    protected $table = 'status_pagamento';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['codigo', 'descricao'];

    /**
     * Resolve o id do status pelo seu código.
     */
    public static function idFor(string $codigo): ?int
    {
        return static::where('codigo', $codigo)->value('id');
    }
}
