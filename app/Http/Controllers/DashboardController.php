<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Dashboard\DiasDeVencimentoNoMes;
use App\Domain\Dashboard\ResumoDoMes;
use App\Domain\Dashboard\ResumoDoMesResultado;
use App\Domain\Gastos\ConsultarGastos;
use App\Domain\Shared\Money;
use App\Models\Card;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard "Visão Geral" (spec 06 / FE §7.5). Borda fina: compõe os números JÁ
 * calculados pelo domínio ({@see ResumoDoMes} + consultas determinísticas) e apenas
 * FORMATA em pt-BR para a tela (regra 3/5). A UI nunca calcula dinheiro (regra 4);
 * percentuais/ticks são geometria de exibição derivada de valores prontos.
 *
 * Navegação por competência (?mes=YYYY-MM, default = mês atual): a âncora é o "hoje" real
 * no mês corrente e o 1º dia do mês nos históricos. Regra "mesmomês": os blocos relativos ao
 * hoje (a vencer 7d, tick da régua, quadros 06b) só aparecem no mês atual — a view decide pelo
 * `ehMesAtual`. O estado (pronto | vazio | carregando) vem dos dados reais; `?estado=` continua
 * como afordância de revisão das telas.
 */
class DashboardController extends Controller
{
    /** @var array<int, string> */
    private const MESES = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    /** Cores do donut (cicladas por categoria) — chaves de token do design system. */
    private const CORES_DONUT = ['primary', 'secondary', 'tertiary', 'outline'];

    public function index(Request $request, ResumoDoMes $resumoDoMes): View
    {
        $userId = $request->user()->id;

        $override = in_array($request->query('estado'), ['vazio', 'carregando'], true)
            ? $request->query('estado')
            : null;

        $temTransacoes = Transaction::where('user_id', $userId)->exists();
        $estado = $override ?? ($temTransacoes ? 'pronto' : 'vazio');

        $hoje = CarbonImmutable::now('America/Sao_Paulo');
        $mesAlvo = $this->mesAlvo($request, $hoje);
        $ehMesAtual = $mesAlvo->format('Y-m') === $hoje->format('Y-m');
        // No mês atual a âncora é o HOJE real (status/próximas contas são relativos ao dia);
        // em meses históricos, o 1º dia do mês visto — aí só as figuras MENSAIS são exibidas
        // (regra "mesmomês": os blocos relativos ao hoje somem). Ver DashboardCompetenciaTest.
        $ancora = $ehMesAtual ? $hoje : $mesAlvo;

        $dados = [
            'estado' => $estado,
            // Cartões e categorias do próprio usuário alimentam o modal (escopo por usuário).
            'cartoes' => Card::where('user_id', $userId)->orderBy('descricao')->get(),
            'categorias' => Category::where('user_id', $userId)
                ->where('arquivada', false)->orderBy('nome')->get(),
        ];

        if ($estado === 'pronto') {
            // $hoje é o "agora" real; $ancora é o mês navegado. A distinção habilita a previsão
            // de recorrências na visão de mês futuro (spec 10b) — no mês atual são iguais.
            $dados['vm'] = $this->viewModel($userId, $ancora, $hoje, $ehMesAtual, $resumoDoMes);
        } else {
            $dados['mesLabel'] = $this->rotuloMes($ancora);
        }

        return view('home', $dados);
    }

    /**
     * Competência escolhida (1º dia do mês, fuso SP). Aceita ?mes=YYYY-MM válido; qualquer
     * outra coisa (ausente, forjada, mês fora de 01–12) cai no mês corrente. Não é id — mês
     * pode ir em claro na URL (mesma convenção do filtro da lista de lançamentos).
     */
    private function mesAlvo(Request $request, CarbonImmutable $hoje): CarbonImmutable
    {
        $mes = (string) $request->query('mes', '');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $mes.'-01', 'America/Sao_Paulo');
        }

        return $hoje->startOfMonth();
    }

    /**
     * Monta o view-model do dashboard com tudo já formatado em pt-BR.
     *
     * @return array<string, mixed>
     */
    private function viewModel(int $userId, CarbonImmutable $hoje, CarbonImmutable $agora, bool $ehMesAtual, ResumoDoMes $resumoDoMes): array
    {
        // $hoje é a âncora (mês navegado); $agora é o "hoje" real. Em mês futuro eles diferem e
        // a projeção de recorrências previstas (spec 10b) entra nas próximas contas + disponível.
        $resumo = $resumoDoMes->para($userId, $hoje, 30, $agora);
        $mes = $resumo->mes;

        $disponivelCents = $resumo->disponivelCents();
        $totalGastosCents = $resumo->gastos->totalCents;

        return [
            'mesLabel' => $this->rotuloMes($hoje),
            // Navegação por competência (FE §7.5). Fora do mês atual, a régua não marca "hoje"
            // e a view esconde os blocos relativos ao dia (a vencer/contas) — regra "mesmomês".
            'ehMesAtual' => $ehMesAtual,
            'mesAnterior' => $hoje->subMonthNoOverflow()->format('Y-m'),
            'mesSeguinte' => $hoje->addMonthNoOverflow()->format('Y-m'),
            'today' => $ehMesAtual ? $hoje->day : null,
            'daysInMonth' => $hoje->daysInMonth,
            'dueDays' => app(DiasDeVencimentoNoMes::class)->para($userId, $mes),
            'availablePct' => $this->percentualDisponivel($disponivelCents, $resumo->disponivel->receitasCents),

            'disponivel' => Money::fromCents($disponivelCents)->formatBRL(),
            'disponivelPositivo' => $disponivelCents >= 0,

            'gastos' => Money::fromCents($totalGastosCents)->formatBRL(),
            'comparativo' => $this->comparativo($userId, $mes, $hoje, $totalGastosCents),

            'aVencer7' => $this->aVencer7Dias($resumo, $hoje),

            'fatura' => $this->faturaDestaque($resumo, $hoje, $userId),

            'donut' => $this->donut($resumo->gastos->porCategoria, $totalGastosCents),

            // Quadro de contas dividido (spec 06b): "em atraso" (já venceu) + "a vencer".
            'contasVencidas' => $this->contasVencidas($resumo),
            'emAtraso' => [
                'valor' => Money::fromCents($resumo->totalContasVencidasCents())->formatBRL(),
                'contas' => count($resumo->contasVencidas->contas),
            ],
            'proximasContas' => $this->proximasContas($resumo, $hoje),
            'aVencer' => [
                'valor' => Money::fromCents($resumo->totalProximasContasCents())->formatBRL(),
                'contas' => count($resumo->proximasContas->contas),
            ],
        ];
    }

    private function rotuloMes(CarbonImmutable $data): string
    {
        return ucfirst(self::MESES[$data->month]).' de '.$data->year;
    }

    private function dataExtenso(CarbonImmutable $data): string
    {
        return $data->day.' de '.self::MESES[$data->month];
    }

    /** Fração do mês ainda disponível (0..100), como % da receita. Só exibição. */
    private function percentualDisponivel(int $disponivelCents, int $receitasCents): int
    {
        if ($receitasCents <= 0 || $disponivelCents <= 0) {
            return 0;
        }

        return (int) min(100, round($disponivelCents / $receitasCents * 100));
    }

    /**
     * Comparativo de gastos vs. mês anterior. Devolve null quando não há base
     * (mês anterior sem gastos). Percentual é estatística de exibição.
     *
     * @return array{texto: string, tom: string}|null
     */
    private function comparativo(int $userId, string $mes, CarbonImmutable $hoje, int $atualCents): ?array
    {
        $mesAnterior = $hoje->subMonthNoOverflow()->format('Y-m');
        $anteriorCents = app(ConsultarGastos::class)->para($userId, $mesAnterior)->totalCents;

        if ($anteriorCents <= 0) {
            return null;
        }

        $delta = round(($atualCents - $anteriorCents) / $anteriorCents * 100, 1);
        $sinal = $delta > 0 ? '+' : ($delta < 0 ? '−' : '');

        return [
            'texto' => $sinal.number_format(abs($delta), 1, ',', '.').'%',
            // Gastar mais que o mês anterior é alerta (error); gastar menos, positivo.
            'tom' => $delta > 0 ? 'error' : ($delta < 0 ? 'primary' : 'neutral'),
        ];
    }

    /**
     * "A vencer (7 dias)": subconjunto das próximas contas com vencimento até hoje+7.
     *
     * @return array{valor: string, contas: int}
     */
    private function aVencer7Dias(ResumoDoMesResultado $resumo, CarbonImmutable $hoje): array
    {
        $limite = $hoje->startOfDay()->addDays(7)->toDateString();

        $noPrazo = array_filter(
            $resumo->proximasContas->contas,
            fn (array $conta): bool => $conta['vencimento'] <= $limite,
        );

        return [
            'valor' => Money::fromCents((int) array_sum(array_column($noPrazo, 'cents')))->formatBRL(),
            'contas' => count($noPrazo),
        ];
    }

    /**
     * Fatura em destaque: a de maior total entre os cartões do usuário. Null quando
     * não há cartão/fatura com valor.
     *
     * @return array{valor: string, sub: string}|null
     */
    private function faturaDestaque(ResumoDoMesResultado $resumo, CarbonImmutable $hoje, int $userId): ?array
    {
        $faturas = array_filter($resumo->faturas, fn ($f): bool => $f->totalCents > 0);
        if ($faturas === []) {
            return null;
        }

        usort($faturas, fn ($a, $b): int => $b->totalCents <=> $a->totalCents);
        $fatura = $faturas[0];

        $card = Card::where('user_id', $userId)->where('final_4', $fatura->cartaoFinal4)->first();
        $fecha = $card !== null
            ? ' · fecha '.$card->dia_fechamento.' de '.self::MESES[$hoje->month]
            : '';

        return [
            'valor' => Money::fromCents($fatura->totalCents)->formatBRL(),
            'sub' => $fatura->cartaoDescricao.$fecha,
        ];
    }

    /**
     * Segmentos do donut "gastos por categoria" (top 3 + "Outros"), com percentual e
     * offset acumulado para o SVG. Percentuais são geometria de exibição.
     *
     * @param  list<array{nome: string, cents: int}>  $porCategoria
     * @return array{total: string, segmentos: list<array{nome: string, valor: string, pct: int, offset: int, cor: string}>}
     */
    private function donut(array $porCategoria, int $totalCents): array
    {
        // Agrupa a cauda em "Outros" para no máximo 4 fatias.
        if (count($porCategoria) > 4) {
            $principais = array_slice($porCategoria, 0, 3);
            $resto = (int) array_sum(array_column(array_slice($porCategoria, 3), 'cents'));
            $principais[] = ['nome' => 'Outros', 'cents' => $resto];
            $porCategoria = $principais;
        }

        $segmentos = [];
        $acumulado = 0;
        foreach ($porCategoria as $i => $linha) {
            $pct = $totalCents > 0 ? (int) round($linha['cents'] / $totalCents * 100) : 0;
            $segmentos[] = [
                'nome' => $linha['nome'],
                'valor' => Money::fromCents($linha['cents'])->formatBRL(),
                'pct' => $pct,
                'offset' => -$acumulado,
                'cor' => self::CORES_DONUT[$i % count(self::CORES_DONUT)],
            ];
            $acumulado += $pct;
        }

        return [
            'total' => Money::fromCents($totalCents)->formatBRL(),
            'segmentos' => $segmentos,
        ];
    }

    /**
     * Linhas de "Próximas contas" (até 5), já formatadas. Urgência (≤7 dias) muda o tom.
     * A flag `prevista` (recorrência projetada na visão de mês futuro, spec 10b) é propagada
     * como dado — o selo/"~" é etapa de frontend (regra 3).
     *
     * @return list<array{title: string, due: string, value: string, iconTone: string, prevista: bool}>
     */
    private function proximasContas(ResumoDoMesResultado $resumo, CarbonImmutable $hoje): array
    {
        $limite7 = $hoje->startOfDay()->addDays(7)->toDateString();

        return array_map(function (array $conta) use ($limite7): array {
            $venc = CarbonImmutable::parse($conta['vencimento'], 'America/Sao_Paulo');

            return [
                'title' => $conta['descricao'],
                'due' => 'vence '.$this->dataExtenso($venc),
                'value' => Money::fromCents($conta['cents'])->formatBRL(),
                'iconTone' => $conta['vencimento'] <= $limite7 ? 'ocre' : 'primary',
                'prevista' => $conta['prevista'] ?? false,
            ];
        }, array_slice($resumo->proximasContas->contas, 0, 5));
    }

    /**
     * Linhas de "Contas em atraso" (até 5), já formatadas — o que venceu e segue em
     * aberto (spec 06b). Tom `error` (argila) em todas: atraso é o estado de alerta.
     *
     * @return list<array{title: string, due: string, value: string, iconTone: string}>
     */
    private function contasVencidas(ResumoDoMesResultado $resumo): array
    {
        return array_map(function (array $conta): array {
            $venc = CarbonImmutable::parse($conta['vencimento'], 'America/Sao_Paulo');

            return [
                'title' => $conta['descricao'],
                'due' => 'venceu '.$this->dataExtenso($venc),
                'value' => Money::fromCents($conta['cents'])->formatBRL(),
                'iconTone' => 'error',
            ];
        }, array_slice($resumo->contasVencidas->contas, 0, 5));
    }
}
