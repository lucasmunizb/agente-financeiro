<?php

declare(strict_types=1);

namespace App\Domain\IA;

/**
 * Campos de um gasto extraídos pela IA (doc 02 §3.1, papel 2), ainda CRUS. Valor e data
 * permanecem como TEXTO exatamente como o usuário disse ("35 conto", "amanhã"): a IA nunca
 * calcula nem resolve (regra inviolável 4). A normalização determinística — `Money::fromHuman`
 * para centavos e `RelativeDate` no fuso de São Paulo — acontece FORA da IA, na etapa de
 * confirmação/persistência (Bloco 4, item 3). `parcelas` é apenas a quantidade declarada.
 *
 * `recorrenciaDiaTexto` (spec 10c) é o dia-do-mês quando o usuário disse que aquilo REPETE
 * todo mês ("todo dia 10" → "10"), também CRU: quem valida 1..31 e resolve a próxima
 * ocorrência (com clamp no fim do mês) é o domínio. Preenchido ⇒ é recorrência, não gasto.
 */
final readonly class GastoExtraido
{
    public function __construct(
        public string $descricao,
        public string $valorTexto,
        public string $formaPagamento,
        public ?string $cartao,
        public ?string $categoria,
        public ?string $dataTexto,
        public ?int $parcelas,
        public ?string $recorrenciaDiaTexto = null,
    ) {}
}
