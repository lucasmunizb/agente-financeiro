<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Vencimento\CalculadoraDeVencimento;

/**
 * Agrupa as contas do quadro do dashboard (spec 06b) por CARTÃO: as cobranças de um mesmo
 * cartão que caem na MESMA fatura viram uma linha só — somatório + o vencimento da fatura —,
 * enquanto o que é fora de cartão (PIX, débito, boleto, conta fixa) continua individual.
 *
 * O quadro é "o que preciso pagar até tal dia": no cartão o usuário não paga cada compra, paga
 * a fatura. Listar 20 compras avulsas afogava as contas que ele de fato precisa pagar uma a uma.
 *
 * Chave do agrupamento = `cartaoId` + `vencimento`. O vencimento da parcela de cartão JÁ é o
 * dia de vencimento da fatura ({@see CalculadoraDeVencimento}), então
 * duas faturas do mesmo cartão nunca se misturam.
 *
 * Transformação PURA (sem banco, sem relógio): entra lista de contas em centavos, sai lista de
 * contas em centavos — a soma é determinística e mora aqui, nunca na tela (regra 4). Total
 * preservado por construção. A formatação pt-BR e o rótulo ("Fatura Nubank") são borda (regra 5).
 */
final class AgruparContasDeCartao
{
    /**
     * @param  list<array<string, mixed>>  $contas  linhas do quadro (proximasContas/contasVencidas)
     * @return list<array<string, mixed>> ordenadas por vencimento asc
     */
    public function __invoke(array $contas): array
    {
        $agrupadas = [];

        foreach ($contas as $conta) {
            $cartaoId = $conta['cartaoId'] ?? null;

            if ($cartaoId === null) {
                $agrupadas[] = $conta + ['cartao' => false, 'itens' => 1, 'prevista' => false];

                continue;
            }

            $chave = 'cartao:'.$cartaoId.'|'.$conta['vencimento'];

            if (! isset($agrupadas[$chave])) {
                $agrupadas[$chave] = [
                    // Sem descrição do cartão (cadastro incompleto) a linha ainda precisa de um
                    // rótulo — o quadro nunca mostra uma conta anônima.
                    'descricao' => $conta['cartaoDescricao'] ?? 'Cartão',
                    'vencimento' => $conta['vencimento'],
                    'cents' => 0,
                    'cartaoId' => $cartaoId,
                    'cartaoDescricao' => $conta['cartaoDescricao'] ?? null,
                    'cartao' => true,
                    'itens' => 0,
                    // Fatura não é "conta fixa": mesmo que uma assinatura recorrente esteja
                    // dentro dela, o que vence é a fatura.
                    'recorrente' => false,
                    // Só é projeção se TUDO na fatura for projeção; uma compra real já torna a
                    // cobrança real (e o selo "previsto" seria mentira).
                    'prevista' => true,
                    // A linha condensada não tem alvo individual: são N cobranças, e a fatura é
                    // quem quita (§4.3) — não se marca "pago" numa compra de cartão.
                    'pagavel' => false,
                    'parcelaId' => null,
                    'transactionId' => null,
                ];
            }

            $agrupadas[$chave]['cents'] += (int) $conta['cents'];
            $agrupadas[$chave]['itens']++;
            $agrupadas[$chave]['prevista'] = $agrupadas[$chave]['prevista'] && ($conta['prevista'] ?? false);
        }

        $agrupadas = array_values($agrupadas);
        usort($agrupadas, static fn (array $a, array $b): int => $a['vencimento'] <=> $b['vencimento']);

        return $agrupadas;
    }
}
