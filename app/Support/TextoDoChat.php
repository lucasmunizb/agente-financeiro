<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Formatação de apresentação do texto do chat (spec FE §7.14). Remove a linha técnica de
 * "fonte:" que o modelo às vezes ecoa do payload, escapa o texto (é conteúdo do usuário/IA
 * — nunca confiar), aplica o negrito de markdown e envolve os valores em R$ com a fonte MONO
 * (assinatura do design system: dinheiro é dado tabular monoespaçado). Não calcula nada — só
 * apresenta o que já veio pronto do backend (regra 4/5). O mesmo tratamento existe no
 * chat.js para as mensagens anexadas em tempo real — mantenha os dois em sintonia.
 */
final class TextoDoChat
{
    public static function comValoresMono(string $texto): HtmlString
    {
        $texto = self::semLinhaDeFonte($texto);

        $html = e($texto);

        // Negrito de markdown: **texto** → <strong>. Depois do escape — os marcadores ** não
        // são HTML, então o conteúdo interno já está seguro.
        $html = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);

        // Realça os valores em R$ com a fonte mono (assinatura do design system).
        $html = preg_replace(
            '/R\$\s?\d[\d.]*,\d{2}/u',
            '<span class="font-value-label text-value-label">$0</span>',
            $html,
        );

        return new HtmlString(nl2br($html));
    }

    /**
     * Remove a linha técnica "fonte: <tool> (<filtros>); N registro(s)" que o modelo às vezes
     * ecoa do payload para dentro da resposta. O corpo entregue é só a resposta em linguagem
     * natural, sem o nome cru da ferramenta nem a sintaxe de filtros — a fonte (barreira 5) é
     * registro interno, não faz parte do texto enviado a nenhum canal. Público para ser o
     * strip canônico reusado na redação de origem (RedatorDoChat), cobrindo web e Telegram.
     */
    public static function semLinhaDeFonte(string $texto): string
    {
        $texto = preg_replace('/^\h*fonte:.*registro\(s\)\.?\h*$/imu', '', $texto);

        return trim($texto);
    }
}
