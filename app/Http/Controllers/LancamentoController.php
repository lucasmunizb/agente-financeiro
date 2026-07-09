<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Lancamentos\ConsultarLancamentos;
use App\Domain\Shared\Money;
use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Lançamentos — lista/extrato (FE §7.6). Borda fina: delega ao domínio determinístico
 * ({@see ConsultarLancamentos}) e apenas FORMATA em pt-BR para a tela (regra 3/5). A UI
 * nunca calcula dinheiro (regra 4). Escopo estrito por usuário.
 *
 * Filtros pela query (todos opcionais): mes (YYYY-MM), busca, categoria (id), forma (tipo),
 * cartao (id), status (aberto|pago|atraso|cancelado). `?estado=` continua como afordância
 * de revisão das telas; o estado real (vazio | sem-resultado | pronto) vem dos dados.
 */
class LancamentoController extends Controller
{
    /** @var array<int, string> */
    private const MESES = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    /** Rótulos pt-BR das formas de pagamento (doc 03 §4.6). */
    private const FORMA_LABEL = [
        PaymentMethod::CREDITO => 'Crédito',
        PaymentMethod::DEBITO => 'Débito',
        PaymentMethod::PIX => 'Pix',
        PaymentMethod::DINHEIRO => 'Dinheiro',
        PaymentMethod::BOLETO => 'Boleto',
    ];

    /** Ícone (conjunto SVG do app) por forma de pagamento. */
    private const FORMA_ICONE = [
        PaymentMethod::CREDITO => 'credit-card',
        PaymentMethod::DEBITO => 'wallet',
        PaymentMethod::PIX => 'zap',
        PaymentMethod::DINHEIRO => 'wallet',
        PaymentMethod::BOLETO => 'file-text',
    ];

    /** Status de exibição aceitos no filtro (casam com o seletor e o selo). */
    private const STATUS_VALIDOS = [
        ConsultarLancamentos::STATUS_A_VENCER,
        ConsultarLancamentos::STATUS_PAGO,
        ConsultarLancamentos::STATUS_ATRASO,
        ConsultarLancamentos::STATUS_CANCELADO,
    ];

    public function index(Request $request, ConsultarLancamentos $consulta): View
    {
        $userId = $request->user()->id;
        $hoje = CarbonImmutable::now('America/Sao_Paulo');

        // Período (mês): valida YYYY-MM; fora do formato, cai no mês corrente.
        $mes = (string) $request->query('mes', '');
        $periodo = preg_match('/^\d{4}-\d{2}$/', $mes) === 1 ? $mes : $hoje->format('Y-m');
        $refMes = CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01', 'America/Sao_Paulo')->startOfMonth();

        // Filtros vindos da query (escopo por usuário: só ids do próprio usuário valem).
        $busca = trim((string) $request->query('busca', '')) ?: null;
        $status = in_array($request->query('status'), self::STATUS_VALIDOS, true) ? $request->query('status') : null;
        $forma = in_array($request->query('forma'), PaymentMethod::TIPOS, true) ? $request->query('forma') : null;

        $categorias = Category::where('user_id', $userId)->where('arquivada', false)->orderBy('nome')->get();
        $cartoes = Card::where('user_id', $userId)->orderBy('descricao')->get();

        $categoriaId = $this->idPertencente($request->query('categoria'), $categorias);
        $cartaoId = $this->idPertencente($request->query('cartao'), $cartoes);

        $resultado = $consulta->para(
            userId: $userId,
            periodo: $periodo,
            hoje: $hoje,
            busca: $busca,
            categoriaId: $categoriaId,
            forma: $forma,
            cartaoId: $cartaoId,
            status: $status,
        );

        // Estado real: sem nenhum lançamento → vazio (primeiro uso); com dados mas filtro
        // sem casar → sem-resultado; senão pronto. `?estado=` sobrepõe para revisão.
        $temAlgum = Transaction::where('user_id', $userId)->exists();
        $override = in_array($request->query('estado'), ['vazio', 'sem-resultado', 'carregando'], true)
            ? $request->query('estado')
            : null;
        $estado = $override ?? match (true) {
            ! $temAlgum => 'vazio',
            $resultado->registros === 0 => 'sem-resultado',
            default => 'pronto',
        };

        $filtros = [
            'busca' => $busca,
            'categoria' => $categoriaId,
            'forma' => $forma,
            'cartao' => $cartaoId,
            'status' => $status,
        ];

        return view('lancamentos', [
            'estado' => $estado,
            'mesLabel' => $this->rotuloMes($refMes),
            'mesAtual' => $periodo,
            'mesAnterior' => $refMes->subMonthNoOverflow()->format('Y-m'),
            'mesProximo' => $refMes->addMonthNoOverflow()->format('Y-m'),
            'grupos' => $this->grupos($resultado->grupos, $hoje),
            'totalExibido' => Money::fromCents($resultado->totalExibidoCents)->formatBRL(),
            'registros' => $resultado->registros,
            'filtros' => $filtros,
            'temFiltroAtivo' => $busca !== null || $categoriaId !== null || $forma !== null || $cartaoId !== null || $status !== null,
            'categorias' => $categorias,
            'cartoes' => $cartoes,
            'formas' => self::FORMA_LABEL,
            'statusOpcoes' => [
                ConsultarLancamentos::STATUS_A_VENCER => 'Aberto',
                ConsultarLancamentos::STATUS_PAGO => 'Pago',
                ConsultarLancamentos::STATUS_ATRASO => 'Atraso',
                ConsultarLancamentos::STATUS_CANCELADO => 'Cancelado',
            ],
        ]);
    }

    /**
     * Resolve um id vindo da query só se ele pertencer à coleção do usuário (evita vazar
     * escopo e filtros forjados). Devolve null quando ausente/estranho.
     *
     * @param  Collection<int, Model>  $colecao
     */
    private function idPertencente(mixed $valor, $colecao): ?int
    {
        if ($valor === null || ! ctype_digit((string) $valor)) {
            return null;
        }

        $id = (int) $valor;

        return $colecao->contains('id', $id) ? $id : null;
    }

    /**
     * Formata os grupos do domínio para a tela: cabeçalho do dia (Hoje/Ontem/data extenso)
     * e cada linha com valor em pt-BR, rótulo/ícone da forma e chave do selo de status.
     *
     * @param  list<array{data: CarbonImmutable, itens: list<array<string, mixed>>}>  $grupos
     * @return list<array{titulo: string, itens: list<array<string, mixed>>}>
     */
    private function grupos(array $grupos, CarbonImmutable $hoje): array
    {
        return array_map(function (array $grupo) use ($hoje): array {
            return [
                'titulo' => $this->rotuloDia($grupo['data'], $hoje),
                'itens' => array_map(fn (array $item): array => [
                    'descricao' => $item['descricao'],
                    'valor' => Money::fromCents($item['cents'])->formatBRL(),
                    'categoria' => $item['categoria'],
                    'forma' => $item['forma'],
                    'formaLabel' => $item['cartaoDescricao'] ?? (self::FORMA_LABEL[$item['forma']] ?? 'Outros'),
                    'formaIcone' => self::FORMA_ICONE[$item['forma']] ?? 'wallet',
                    'parcela' => $item['parcela'],
                    'status' => $item['status'],
                    'editUrl' => route('lancamentos.edit', $item['transactionId']),
                ], $grupo['itens']),
            ];
        }, $grupos);
    }

    private function rotuloMes(CarbonImmutable $data): string
    {
        return ucfirst(self::MESES[$data->month]).' de '.$data->year;
    }

    private function rotuloDia(CarbonImmutable $data, CarbonImmutable $hoje): string
    {
        $dia = $data->startOfDay();
        $hojeData = $hoje->setTimezone('America/Sao_Paulo')->startOfDay();

        return match ($dia->toDateString()) {
            $hojeData->toDateString() => 'Hoje · '.$this->dataExtenso($data),
            $hojeData->subDay()->toDateString() => 'Ontem · '.$this->dataExtenso($data),
            default => $this->dataExtenso($data),
        };
    }

    private function dataExtenso(CarbonImmutable $data): string
    {
        return $data->day.' de '.self::MESES[$data->month];
    }
}
