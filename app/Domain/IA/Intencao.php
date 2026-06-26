<?php

declare(strict_types=1);

namespace App\Domain\IA;

/**
 * Intenção interpretada pela IA (doc 02 §3.1) — o papel 1 dos três únicos papéis da
 * IA: classificar a intenção do usuário. Distinto do enum `App\Domain\Telegram\Comando`,
 * que é roteamento DETERMINÍSTICO por slash-command: este é o vocabulário com que a IA
 * classifica texto livre. DESCONHECIDO é o fallback seguro — qualquer saída fora deste
 * conjunto NUNCA vira chute (barreira 1, §3.3). A IA nunca calcula dinheiro aqui.
 */
enum Intencao: string
{
    case REGISTRAR = 'registrar';
    case CONSULTAR = 'consultar';
    case EDITAR = 'editar';
    case CANCELAR = 'cancelar';
    case IMPORTAR = 'importar';
    case DESCONHECIDO = 'desconhecido';

    /**
     * Converte uma saída textual da IA em intenção, caindo em DESCONHECIDO quando não
     * corresponde a nenhuma intenção conhecida (nunca lança, nunca chuta).
     */
    public static function tentar(?string $valor): self
    {
        return self::tryFrom($valor ?? '') ?? self::DESCONHECIDO;
    }

    /**
     * Valores possíveis — alimenta o enum do schema do classificador.
     *
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $caso) => $caso->value, self::cases());
    }
}
