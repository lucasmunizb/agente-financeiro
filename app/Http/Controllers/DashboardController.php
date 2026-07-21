<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Dashboard\AgruparContasDeCartao;
use App\Domain\Dashboard\DiasDeVencimentoNoMes;
use App\Domain\Dashboard\ResumoDoMes;
use App\Domain\Dashboard\ResumoDoMesResultado;
use App\Domain\Gastos\ConsultarGastos;
use App\Domain\Shared\Money;
use App\Models\Card;
use App\Models\Category;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
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

        // "Vazio" é a conta que ainda não tem NADA — não a que só tem conta fixa. Desde a
        // spec 12 a recorrência não escreve em `transactions`, então olhar só essa tabela
        // escondia o dashboard inteiro de quem cadastrou apenas contas fixas (e com ele o
        // quadro onde essas contas são pagas).
        $temDados = Transaction::where('user_id', $userId)->exists()
            || Recurrence::where('user_id', $userId)->where('status', Recurrence::STATUS_ATIVO)->exists()
            || RecurrenceOccurrence::where('user_id', $userId)->exists();
        $estado = $override ?? ($temDados ? 'pronto' : 'vazio');

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
            // Navegação por competência também no estado vazio (auditoria P2-15):
            // as setas eram botões mortos.
            $dados['mesAnterior'] = $ancora->subMonthNoOverflow()->format('Y-m');
            $dados['mesSeguinte'] = $ancora->addMonthNoOverflow()->format('Y-m');
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
        // Janela do quadro "Contas": 15 dias a partir de hoje no mês corrente — o horizonte de
        // "o que preciso pagar agora". Em mês futuro a âncora é o dia 1º e a janela cobre o mês
        // inteiro (a leitura ali é "como fica o mês", não "os próximos dias").
        $janela = $ehMesAtual ? 15 : 30;
        $resumo = $resumoDoMes->para($userId, $hoje, $janela, $agora);
        $mes = $resumo->mes;

        $disponivelCents = $resumo->disponivelCents();
        $totalGastosCents = $resumo->gastos->totalCents;

        // Linhas já agrupadas por fatura: a contagem exibida ("4 contas") tem de bater com o
        // que a lista mostra, então sai daqui — não da contagem crua do domínio.
        $contasVencidas = $this->contasVencidas($resumo);
        $proximasContas = $this->proximasContas($resumo, $hoje);

        return [
            'mesLabel' => $this->rotuloMes($hoje),
            // Navegação por competência (FE §7.5). Fora do mês atual, a régua não marca "hoje"
            // e a view esconde os blocos relativos ao dia (a vencer/contas) — regra "mesmomês".
            'ehMesAtual' => $ehMesAtual,
            // Mês estritamente futuro: habilita a listagem de contas/recorrências PREVISTAS
            // (spec 10b). Histórico continua sendo só o retrato fechado do mês.
            'ehFuturo' => $hoje->format('Y-m') > $agora->format('Y-m'),
            'mesAnterior' => $hoje->subMonthNoOverflow()->format('Y-m'),
            'mesSeguinte' => $hoje->addMonthNoOverflow()->format('Y-m'),
            'today' => $ehMesAtual ? $hoje->day : null,
            'daysInMonth' => $hoje->daysInMonth,
            'dueDays' => app(DiasDeVencimentoNoMes::class)->para($userId, $mes),
            'availablePct' => $this->percentualDisponivel($disponivelCents, $resumo->disponivel->receitasCents),

            'disponivel' => Money::fromCents($disponivelCents)->formatBRL(),
            'disponivelPositivo' => $disponivelCents >= 0,

            'gastos' => Money::fromCents($totalGastosCents)->formatBRL(),
            'comparativo' => $this->comparativo($userId, $mes, $hoje, $totalGastosCents, $agora),

            'previsto' => $this->previstoProximoMes($userId, $hoje, $agora),

            'fatura' => $this->faturaDestaque($resumo, $hoje, $userId),

            'donut' => $this->donut($resumo->gastos->porCategoria, $totalGastosCents),

            // Quadro de contas dividido (spec 06b): "em atraso" (já venceu) + "a vencer".
            'contasVencidas' => $contasVencidas,
            'emAtraso' => [
                'valor' => Money::fromCents($resumo->totalContasVencidasCents())->formatBRL(),
                'contas' => count($contasVencidas),
            ],
            'proximasContas' => $proximasContas,
            'aVencer' => [
                'valor' => Money::fromCents($resumo->totalProximasContasCents())->formatBRL(),
                'contas' => count($proximasContas),
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
    private function comparativo(int $userId, string $mes, CarbonImmutable $hoje, int $atualCents, CarbonImmutable $agora): ?array
    {
        $mesAnterior = $hoje->subMonthNoOverflow()->format('Y-m');
        // "agora" real (não a âncora): se o mês navegado é futuro, o mês anterior também pode ser
        // futuro e deve incluir as recorrências previstas (mesma verdade do donut/extrato).
        $anteriorCents = app(ConsultarGastos::class)->para($userId, $mesAnterior, agora: $agora)->totalCents;

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
     * "Previsto para <mês seguinte>": o que JÁ está registrado para a competência seguinte à
     * navegada — parcelas de cartão cuja fatura vence lá, lançamentos fora de cartão com
     * vencimento lá e as contas fixas (ocorrência real ou projeção do molde).
     *
     * É a MESMA consulta do card "Gastos do mês" ({@see ConsultarGastos}) apontada para o mês
     * seguinte: o número vem pronto e conferido do domínio, sem soma na borda (regra 4). Por
     * isso segue a navegação — em julho o card mostra agosto — e não só o mês corrente.
     *
     * @return array{label: string, valor: string}
     */
    private function previstoProximoMes(int $userId, CarbonImmutable $ancora, CarbonImmutable $agora): array
    {
        $proximo = $ancora->addMonthNoOverflow();
        $totalCents = app(ConsultarGastos::class)
            ->para($userId, $proximo->format('Y-m'), agora: $agora)
            ->totalCents;

        return [
            'label' => 'Previsto para '.self::MESES[$proximo->month],
            'valor' => Money::fromCents($totalCents)->formatBRL(),
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
     * Linhas de "Próximas contas" — TODAS as da janela (a tela rola; cortar em 5 escondia
     * conta a pagar). Urgência (≤7 dias) muda o tom. As cobranças de cada cartão chegam já
     * condensadas numa linha de fatura pelo {@see AgruparContasDeCartao} (a soma é do domínio,
     * regra 4). A flag `prevista` (recorrência projetada, spec 10b) é propagada como dado —
     * o selo é etapa de frontend (regra 3).
     *
     * @return list<array{title: string, due: string, value: string, iconTone: string, icon: string, prevista: bool, recorrente: bool, itens: int, cartao: bool}>
     */
    private function proximasContas(ResumoDoMesResultado $resumo, CarbonImmutable $hoje): array
    {
        $limite7 = $hoje->startOfDay()->addDays(7)->toDateString();

        return array_map(function (array $conta) use ($limite7): array {
            $prevista = $conta['prevista'] ?? false;

            return $this->linhaDeConta($conta, 'vence ') + [
                'iconTone' => $conta['vencimento'] <= $limite7 ? 'ocre' : 'primary',
                'prevista' => $prevista,
                // Prevista é sempre recorrência (projeção do molde); a real herda a flag.
                'recorrente' => ($conta['recorrente'] ?? false) || $prevista,
            ];
        }, app(AgruparContasDeCartao::class)($resumo->proximasContas->contas));
    }

    /**
     * Linhas de "Contas em atraso" — TODAS, já formatadas: o que venceu e segue em aberto
     * (spec 06b). Tom `error` (argila) em todas: atraso é o estado de alerta. Fatura vencida
     * também entra condensada numa linha só.
     *
     * @return list<array{title: string, due: string, value: string, iconTone: string, icon: string, recorrente: bool, itens: int, cartao: bool}>
     */
    private function contasVencidas(ResumoDoMesResultado $resumo): array
    {
        return array_map(fn (array $conta): array => $this->linhaDeConta($conta, 'venceu ') + [
            'iconTone' => 'error',
            'recorrente' => $conta['recorrente'] ?? false,
        ], app(AgruparContasDeCartao::class)($resumo->contasVencidas->contas));
    }

    /**
     * Parte comum das linhas dos dois quadros: rótulo, data por extenso e valor em pt-BR
     * (regra 5 — formatação só na borda). A linha de cartão é a FATURA, não a compra: ganha o
     * nome do cartão, o ícone de cartão e a quantidade de cobranças somadas.
     *
     * @param  array<string, mixed>  $conta
     * @return array{title: string, due: string, value: string, icon: string, itens: int, cartao: bool}
     */
    private function linhaDeConta(array $conta, string $prefixo): array
    {
        $venc = CarbonImmutable::parse($conta['vencimento'], 'America/Sao_Paulo');
        $ehCartao = $conta['cartao'] ?? false;

        return [
            'title' => $ehCartao ? 'Fatura '.$conta['descricao'] : $conta['descricao'],
            'due' => $prefixo.$this->dataExtenso($venc),
            'value' => Money::fromCents($conta['cents'])->formatBRL(),
            'icon' => $ehCartao ? 'credit-card' : 'receipt',
            'itens' => $conta['itens'] ?? 1,
            'cartao' => $ehCartao,
            // Ações da linha (decisão do usuário 2026-07-21): o quadro deixou de ser só
            // leitura. Os quadros mostram apenas o que FALTA pagar, então aqui só existe
            // "marcar pago" — nunca "desmarcar". Fatura de cartão não tem alvo (§4.3) e o
            // agrupador já zera os ids da linha condensada.
            'pagarUrl' => $this->alvoDePagamento($conta),
            'editarUrl' => ($conta['transactionId'] ?? null) !== null
                ? route('lancamentos.show', $conta['transactionId']).'?editar=1'
                : null,
            'hojeIso' => CarbonImmutable::now('America/Sao_Paulo')->toDateString(),
            // Parcela de lançamento guarda a DATA do pagamento; ocorrência de recorrência
            // registra o instante da confirmação e não pede data.
            'exigeDataPagamento' => ($conta['parcelaId'] ?? null) !== null,
            // Só a linha prevista precisa dizer QUAL competência está sendo quitada — nas
            // demais o próprio id da rota já identifica a conta.
            'competencia' => ($conta['recorrenciaId'] ?? null) !== null ? ($conta['competencia'] ?? null) : null,
        ];
    }

    /**
     * URL de "marcar como pago" da linha do quadro, ou null quando ela não é pagável.
     *
     * A linha é OU uma parcela de lançamento OU uma ocorrência de recorrência: a rota sai do
     * id que veio preenchido (sempre opaco). Fatura condensada e cartão caem fora por não
     * terem id — quem quita cartão é o pagamento da fatura.
     *
     * @param  array<string, mixed>  $conta
     */
    private function alvoDePagamento(array $conta): ?string
    {
        if (($conta['pagavel'] ?? false) !== true) {
            return null;
        }

        if (($conta['ocorrenciaId'] ?? null) !== null) {
            return route('lancamentos.recorrencia.pagar', $conta['ocorrenciaId']);
        }

        // Conta fixa ainda PREVISTA: não há ocorrência no banco, então o alvo é o molde — a
        // competência vai no corpo do POST (campo oculto da linha) e o domínio materializa
        // aquela competência antes de pagar.
        if (($conta['recorrenciaId'] ?? null) !== null) {
            return route('lancamentos.recorrencia-prevista.pagar', $conta['recorrenciaId']);
        }

        if (($conta['parcelaId'] ?? null) !== null) {
            return route('lancamentos.parcela.pagar', $conta['parcelaId']);
        }

        return null;
    }
}
