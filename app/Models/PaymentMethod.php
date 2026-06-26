<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Forma de pagamento — tabela de referência (doc 03 §4.6).
 * Conjunto fixo populado por seeder; apontada por FK nos lançamentos.
 */
class PaymentMethod extends Model
{
    public const CREDITO = 'credito';
    public const DEBITO = 'debito';
    public const PIX = 'pix';
    public const DINHEIRO = 'dinheiro';

    /** @var list<string> Conjunto fixo de tipos (doc 03 §4.6). */
    public const TIPOS = [
        self::CREDITO,
        self::DEBITO,
        self::PIX,
        self::DINHEIRO,
    ];

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['tipo'];

    /**
     * Resolve o id da forma de pagamento pelo seu tipo.
     */
    public static function idFor(string $tipo): ?int
    {
        return static::where('tipo', $tipo)->value('id');
    }
}
