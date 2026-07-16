<?php

namespace App\Models;

use App\Domain\Shared\Normalizador;
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
    public const BOLETO = 'boleto';

    /** @var list<string> Conjunto fixo de tipos (doc 03 §4.6). Boleto é "fora de cartão". */
    public const TIPOS = [
        self::CREDITO,
        self::DEBITO,
        self::PIX,
        self::DINHEIRO,
        self::BOLETO,
    ];

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['tipo'];

    /**
     * Resolve o id da forma de pagamento pelo seu tipo, casando de forma determinística e
     * tolerante à borda: o texto é normalizado (caixa/acento/espaço) antes do lookup, e só
     * então confrontado com o conjunto fixo de TIPOS.
     *
     * Incidente 2026-07-16: o match era pelo literal cru, então um "PIX" vindo da IA virava
     * null e o bot pedia a forma de pagamento que o usuário JÁ tinha dito. Normalizar aqui
     * conserta a borda inteira (bot, chat e importação) sem afrouxar a regra: o que não
     * pertence a TIPOS continua devolvendo null — vira pergunta, nunca chute.
     */
    public static function idFor(string $tipo): ?int
    {
        $tipo = Normalizador::texto($tipo);

        if (! in_array($tipo, self::TIPOS, true)) {
            return null;
        }

        return static::where('tipo', $tipo)->value('id');
    }
}
