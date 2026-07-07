<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Formatação de apresentação do texto do chat (spec FE §7.14). Escapa o texto (é conteúdo
 * do usuário/IA — nunca confiar) e envolve os valores em R$ com a fonte MONO (assinatura do
 * design system: dinheiro é dado tabular monoespaçado). Não calcula nada — só realça o que
 * já veio pronto do backend (regra 4/5). O mesmo realce existe no chat.js para as mensagens
 * anexadas em tempo real.
 */
final class TextoDoChat
{
    public static function comValoresMono(string $texto): HtmlString
    {
        $html = preg_replace(
            '/R\$\s?\d[\d.]*,\d{2}/u',
            '<span class="font-value-label text-value-label">$0</span>',
            e($texto),
        );

        return new HtmlString(nl2br($html));
    }
}
