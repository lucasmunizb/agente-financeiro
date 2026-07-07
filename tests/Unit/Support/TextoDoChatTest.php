<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TextoDoChat;
use Tests\TestCase;

class TextoDoChatTest extends TestCase
{
    public function test_realca_valores_em_reais_com_fonte_mono(): void
    {
        $html = (string) TextoDoChat::comValoresMono('Você gastou R$ 1.234,56 em futebol.');

        $this->assertStringContainsString(
            '<span class="font-value-label text-value-label">R$ 1.234,56</span>',
            $html,
        );
    }

    public function test_converte_negrito_markdown_em_strong(): void
    {
        $html = (string) TextoDoChat::comValoresMono('Total: **R$ 90,00** em futebol.');

        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('</strong>', $html);
        // Os marcadores literais não devem sobrar na tela.
        $this->assertStringNotContainsString('**', $html);
        // O valor dentro do negrito continua em mono.
        $this->assertStringContainsString('font-value-label', $html);
    }

    public function test_remove_a_linha_tecnica_de_fonte_do_corpo(): void
    {
        $texto = "Você gastou **R$ 90,00** em futebol este mês.\n"
            .'fonte: consultar_gastos (periodo=2026-07, categoria=futebol); 2 registro(s)';

        $html = (string) TextoDoChat::comValoresMono($texto);

        $this->assertStringNotContainsString('fonte:', $html);
        $this->assertStringNotContainsString('consultar_gastos', $html);
        $this->assertStringNotContainsString('registro(s)', $html);
        $this->assertStringContainsString('futebol este mês', $html);
    }

    public function test_remove_a_linha_de_fonte_mesmo_capitalizada(): void
    {
        $texto = "Resumo do mês.\nFonte: consultar_disponivel (mes=2026-07); 1 registro(s)";

        $html = (string) TextoDoChat::comValoresMono($texto);

        $this->assertStringNotContainsString('Fonte:', $html);
        $this->assertStringNotContainsString('consultar_disponivel', $html);
    }

    public function test_escapa_html_do_conteudo(): void
    {
        $html = (string) TextoDoChat::comValoresMono('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
