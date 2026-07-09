<?php

declare(strict_types=1);

namespace App\Domain\Categoria;

/**
 * Paleta fixa de cores e ícones de linha para as categorias (spec FE §7.12).
 *
 * Restringe a escolha do usuário a amostras harmônicas com o design system "caderno de contas"
 * (nada de cores berrantes) e a ícones de LINHA que existem no conjunto SVG do frontend
 * ({@see \x-icon}). É a fonte única: o Form Request valida contra estas listas e a tela oferece
 * exatamente estas opções — sem hex solto nem ícone órfão.
 */
final class PaletaDeCategoria
{
    /** @var list<string> Cores #RRGGBB derivadas dos tokens do tema (doc 08 §5 usa este conjunto). */
    public const CORES = [
        '#1F6E5A', // cédula (primária)
        '#2E8B72', // cédula-clara
        '#005543', // cédula-escura
        '#C9852A', // ocre
        '#875300', // ocre-escuro
        '#B4452F', // argila
        '#8A2714', // argila-escura
        '#6B6F66', // névoa (neutro)
    ];

    /** @var list<string> Ícones de linha oferecidos no seletor (nomes do componente x-icon). */
    public const ICONES = [
        'tag',
        'utensils',
        'shopping-cart',
        'home',
        'car',
        'wallet',
        'credit-card',
        'receipt',
        'droplet',
        'zap',
        'calendar',
        'sparkles',
    ];

    /**
     * Mapeia um ícone gravado para um nome válido do componente x-icon, com fallback em "tag".
     * Cobre ícones legados (ex.: "food"/"health" das categorias sugeridas antigas) sem quebrar a
     * tela quando o valor salvo não está mais na paleta.
     */
    public static function icone(?string $icone): string
    {
        $legado = ['food' => 'utensils', 'health' => 'droplet'];
        $icone = $legado[$icone] ?? $icone;

        return in_array($icone, self::ICONES, true) ? $icone : 'tag';
    }
}
