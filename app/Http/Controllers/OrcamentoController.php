<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orcamento\ConsumoDoMes;
use App\Domain\Orcamento\ConsumoMensal;
use App\Domain\Orcamento\DefinirOrcamento;
use App\Domain\Orcamento\OrcamentoMensal;
use App\Domain\Shared\Money;
use App\Http\Requests\DefinirOrcamentoRequest;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Orçamento do mês (spec FE §7.11). Borda fina: exibe o limite/consumo/estouro JÁ avaliados
 * pelo domínio ({@see OrcamentoMensal}) e a quebra por categoria ({@see ConsumoDoMes}); só
 * FORMATA em pt-BR (regra 5) — a UI nunca calcula (regra 4). Define o limite geral reusando
 * {@see DefinirOrcamento}. Navegação por competência (?mes=YYYY-MM; default = mês atual).
 * Escopo estrito por usuário no domínio.
 */
class OrcamentoController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    public function index(Request $request, OrcamentoMensal $orcamentoMensal, ConsumoDoMes $consumoDoMes): View
    {
        $userId = $request->user()->id;
        $hoje = CarbonImmutable::now(self::TZ);
        $mesAlvo = $this->mesAlvo($request, $hoje);
        $mes = $mesAlvo->format('Y-m');

        $resultado = $orcamentoMensal->para($userId, $mes);
        $consumo = $consumoDoMes->para($userId, $mes);

        $temOrcamento = $resultado->limite->cents() > 0;
        $restanteCents = $resultado->restante->cents();

        return view('orcamento', [
            'mesLabel' => $this->rotuloMes($mesAlvo),
            'mesAnterior' => $mesAlvo->subMonthNoOverflow()->format('Y-m'),
            'mesSeguinte' => $mesAlvo->addMonthNoOverflow()->format('Y-m'),
            'mes' => $mes,
            'temOrcamento' => $temOrcamento,
            'limite' => $resultado->limite->formatBRL(),
            'consumido' => $resultado->consumido->formatBRL(),
            // Módulo do restante: "Resta X" (dentro) ou "Acima do limite em X" (estouro).
            'restante' => Money::fromCents(abs($restanteCents))->formatBRL(),
            'estourou' => $resultado->estourou,
            'percentual' => (int) round($resultado->percentual()),
            // Prévia de barra: capa em 100% (o número mostra o real, mesmo > 100).
            'barra' => min(100, (int) round($resultado->percentual())),
            // Prefill do campo de edição (só formatação, sem R$; regra 5 na borda).
            'valorAtual' => $temOrcamento ? trim(str_replace('R$', '', $resultado->limite->formatBRL())) : '',
            'porCategoria' => $this->porCategoria($userId, $consumo),
        ]);
    }

    public function definir(DefinirOrcamentoRequest $request, DefinirOrcamento $definir): RedirectResponse
    {
        $mes = $request->mesAlvo();

        $definir->definir($request->user()->id, $mes, $request->limiteCents(), CarbonImmutable::now(self::TZ));

        return redirect()
            ->route('orcamento', ['mes' => $mes])
            ->with('sucesso', 'Limite do mês definido.');
    }

    /**
     * Consumo por categoria, já formatado e ordenado do maior para o menor. No MVP só há
     * limite geral, então cada linha mostra apenas o consumo + a etiqueta "sem limite".
     *
     * @return list<array{nome: string, cor: ?string, valor: string}>
     */
    private function porCategoria(int $userId, ConsumoMensal $consumo): array
    {
        $categorias = Category::query()->where('user_id', $userId)->get()->keyBy('id');

        return collect($consumo->porCategoria)
            ->map(fn (int $cents, int $catId): array => [
                'nome' => $catId === ConsumoMensal::SEM_CATEGORIA
                    ? 'Sem categoria'
                    : (string) ($categorias[$catId]->nome ?? 'Sem categoria'),
                'cor' => $catId === ConsumoMensal::SEM_CATEGORIA ? null : ($categorias[$catId]->cor ?? null),
                'valor' => Money::fromCents($cents)->formatBRL(),
                'cents' => $cents,
            ])
            ->sortByDesc('cents')
            ->map(fn (array $l): array => ['nome' => $l['nome'], 'cor' => $l['cor'], 'valor' => $l['valor']])
            ->values()
            ->all();
    }

    /**
     * Competência escolhida (1º dia do mês, fuso SP). ?mes=YYYY-MM válido; qualquer outra coisa
     * cai no mês corrente. Não é id — mês pode ir em claro na URL (convenção do dashboard/lista).
     */
    private function mesAlvo(Request $request, CarbonImmutable $hoje): CarbonImmutable
    {
        $mes = (string) $request->query('mes', '');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $mes.'-01', self::TZ);
        }

        return $hoje->startOfMonth();
    }

    private function rotuloMes(CarbonImmutable $mes): string
    {
        return ucfirst($mes->locale('pt_BR')->translatedFormat('F \d\e Y'));
    }
}
