<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\CancelarGastoManual;
use App\Domain\Gasto\PagamentoNaoPermitidoException;
use App\Domain\Gasto\RegistrarPagamentoParcela;
use App\Domain\Lancamentos\ConsultarLancamentoDetalhe;
use App\Domain\Lancamentos\ConsultarLancamentos;
use App\Domain\Recorrencia\PagarRecorrenciaPendente;
use App\Domain\Shared\Money;
use App\Domain\Shared\OpaqueId;
use App\Http\Controllers\Concerns\PreparaEdicaoDeGasto;
use App\Http\Requests\PagarParcelaRequest;
use App\Models\Card;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
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
    use PreparaEdicaoDeGasto;

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
        PaymentMethod::PIX => 'banknote',
        PaymentMethod::DINHEIRO => 'wallet',
        PaymentMethod::BOLETO => 'file-text',
    ];

    /** Rótulo pt-BR da origem do lançamento (doc 04; §7.8). */
    private const ORIGEM_LABEL = [
        'manual' => 'manual',
        'telegram' => 'Telegram',
        'pdf' => 'fatura PDF',
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
        // Há registros (inclusive recorrências PREVISTAS de mês futuro) ⇒ pronto — mesmo que o
        // usuário ainda não tenha nenhum lançamento materializado (só molde de recorrência).
        $estado = $override ?? match (true) {
            $resultado->registros > 0 => 'pronto',
            ! $temAlgum => 'vazio',
            default => 'sem-resultado',
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
     * Detalhe de UM lançamento (FE §7.8). Borda fina: delega ao domínio determinístico
     * ({@see ConsultarLancamentoDetalhe}) — que já isola por usuário (404 para transação
     * alheia) e deriva o status por parcela — e apenas FORMATA em pt-BR (regra 3/5). A UI
     * nunca calcula dinheiro (regra 4). O modal de edição reusa o form compartilhado.
     */
    public function show(Request $request, int $transaction, ConsultarLancamentoDetalhe $consulta): View
    {
        $userId = $request->user()->id;
        $detalhe = $consulta->para($userId, $transaction, CarbonImmutable::now('America/Sao_Paulo'));

        // Recarrega a transação (escopo por usuário) para alimentar o modal de edição.
        $tx = Transaction::with(['installments', 'paymentMethod', 'recurrence'])
            ->where('user_id', $userId)->findOrFail($transaction);

        // Só é possível marcar pago FORA DE CARTÃO (cartão quita pela fatura, §4.3).
        // Mapa numero→id (opaco) para o formulário de pagamento por parcela.
        $foraDeCartao = $tx->card_id === null;
        $idPorNumero = $tx->installments->keyBy('numero');
        $naoPagavel = [ConsultarLancamentoDetalhe::STATUS_PAGO, ConsultarLancamentoDetalhe::STATUS_CANCELADO];

        return view('lancamentos.detalhe', [
            'transaction' => $tx,
            'descricao' => $detalhe->descricao,
            'valorTotal' => Money::fromCents($detalhe->valorTotalCents)->formatBRL(),
            'status' => $detalhe->status,
            'categoria' => $detalhe->categoria,
            'formaLabel' => self::FORMA_LABEL[$detalhe->forma] ?? 'Outros',
            'cartaoLinha' => $detalhe->cartaoDescricao !== null
                ? $detalhe->cartaoDescricao.' •••• '.$detalhe->cartaoFinal4
                : null,
            'dataCompra' => $detalhe->dataCompra->format('d/m/Y'),
            'vencimentoLabel' => $detalhe->ehCredito
                ? $this->dataExtenso($detalhe->vencimento).' · calculado pelo cartão'
                : $detalhe->vencimento->format('d/m/Y'),
            'origemLabel' => self::ORIGEM_LABEL[$detalhe->origem] ?? $detalhe->origem,
            'parcelas' => array_map(function (array $p) use ($foraDeCartao, $idPorNumero, $naoPagavel): array {
                $pagavel = $foraDeCartao && ! in_array($p['status'], $naoPagavel, true);

                return [
                    'label' => $p['total'] > 1 ? "{$p['numero']}/{$p['total']}" : 'Única',
                    'valor' => Money::fromCents($p['cents'])->formatBRL(),
                    'vencimento' => $p['vencimento']->format('d/m'),
                    'status' => $p['status'],
                    'pagavel' => $pagavel,
                    // Id opaco só quando pagável (a URL de pagamento nunca expõe id real).
                    'opaqueId' => $pagavel ? OpaqueId::encode((int) $idPorNumero[$p['numero']]->id) : null,
                ];
            }, $detalhe->parcelas),
            'hojeIso' => CarbonImmutable::now('America/Sao_Paulo')->toDateString(),
            'bloqueado' => $detalhe->temParcelaPaga,
            'dados' => $this->prefill($tx),
            'abrirEdicao' => $request->query('editar') === '1' && ! $detalhe->temParcelaPaga,
        ] + $this->opcoesDoUsuario($userId));
    }

    /**
     * Marca UMA parcela como paga (FE §7.8, fora de cartão). Borda fina: valida a data e
     * delega ao domínio determinístico ({@see RegistrarPagamentoParcela}), que isola por
     * usuário (404 para parcela alheia), recusa cartão e não toca nas irmãs. Volta ao
     * detalhe do lançamento. A UI nunca calcula (regra 4); confirmação já veio da tela.
     */
    public function pagarParcela(PagarParcelaRequest $request, int $parcela, RegistrarPagamentoParcela $pagar): RedirectResponse
    {
        $dataPagamento = CarbonImmutable::parse((string) $request->input('data_pagamento'), RelativeDate::TIMEZONE);

        try {
            $paga = $pagar->confirmar($parcela, $request->user()->id, $dataPagamento);
        } catch (PagamentoNaoPermitidoException $e) {
            return back()->withErrors(['data_pagamento' => $e->getMessage()]);
        }

        return redirect()
            ->route('lancamentos.show', OpaqueId::encode($paga->transaction_id))
            ->with('sucesso', 'Pagamento registrado.');
    }

    /**
     * Marca como paga uma ocorrência de recorrência que está na FILA (confirmação pendente,
     * spec 10 — fila e extrato coexistem). Borda fina: delega ao domínio
     * ({@see PagarRecorrenciaPendente}), que reusa confirmar+pagar (regra 4), isola por usuário
     * (404 para pendente alheio) e é idempotente. A confirmação (regra 7) veio da tela antes do
     * POST. Volta ao extrato (preserva o mês navegado).
     */
    public function pagarRecorrencia(Request $request, int $pendente, PagarRecorrenciaPendente $pagar): RedirectResponse
    {
        $transacao = $pagar->pagar($pendente, $request->user()->id, CarbonImmutable::now(RelativeDate::TIMEZONE));

        return back()->with(
            'sucesso',
            $transacao !== null ? 'Recorrência marcada como paga.' : 'Esta ocorrência já foi resolvida.',
        );
    }

    /**
     * Cancela o lançamento e as parcelas ainda NÃO finalizadas ("esta e as próximas"),
     * preservando as já pagas/parciais/estornadas (FE §7.8). Borda fina: delega ao domínio
     * determinístico ({@see CancelarGastoManual}), que isola por usuário (404 para lançamento
     * alheio, via findOrFail) e mantém o histórico (não apaga a linha). Volta ao detalhe. A
     * confirmação (regra 7) veio da tela antes do POST.
     */
    public function cancelar(Request $request, int $transaction, CancelarGastoManual $cancelar): RedirectResponse
    {
        $cancelada = $cancelar->confirmar($transaction, $request->user()->id);

        return redirect()
            ->route('lancamentos.show', OpaqueId::encode($cancelada->id))
            ->with('sucesso', 'Lançamento cancelado.');
    }

    /**
     * Resolve um id de filtro vindo da query só se ele pertencer à coleção do usuário
     * (evita vazar escopo e filtros forjados). O valor chega SEMPRE criptografado (token
     * opaco — README §"Identificadores nas URLs"): decodifica antes de conferir. Devolve
     * null quando ausente, forjado, ou — de propósito — quando vem um id em claro.
     *
     * @param  Collection<int, Model>  $colecao
     */
    private function idPertencente(mixed $valor, $colecao): ?int
    {
        $id = OpaqueId::decode(is_string($valor) ? $valor : null);

        if ($id === null) {
            return null;
        }

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
                'itens' => array_map(function (array $item): array {
                    // Previstas (recorrência projetada de mês futuro) não têm lançamento real:
                    // sem id ⇒ sem detalhe/edição (a linha não abre).
                    $temDetalhe = $item['transactionId'] !== null;
                    // Id criptografado no path (nunca o valor real — README §"Identificadores
                    // nas URLs"). O domínio devolve o id inteiro; opacificamos na borda.
                    $showUrl = $temDetalhe ? route('lancamentos.show', OpaqueId::encode($item['transactionId'])) : null;

                    return [
                        'descricao' => $item['descricao'],
                        'valor' => Money::fromCents($item['cents'])->formatBRL(),
                        'categoria' => $item['categoria'],
                        'forma' => $item['forma'],
                        'formaLabel' => $item['cartaoDescricao'] ?? (self::FORMA_LABEL[$item['forma']] ?? 'Outros'),
                        'formaIcone' => self::FORMA_ICONE[$item['forma']] ?? 'wallet',
                        'parcela' => $item['parcela'],
                        'status' => $item['status'],
                        'recorrente' => $item['recorrente'] ?? false,
                        'prevista' => $item['prevista'] ?? false,
                        'showUrl' => $showUrl,
                        'editarUrl' => $temDetalhe ? $showUrl.'?editar=1' : null,
                        // Ocorrência de recorrência na fila (pendente): habilita "marcar como pago"
                        // (o id já vem opaco do domínio). As demais linhas não têm.
                        'pagarUrl' => ($item['pendenteId'] ?? null) !== null
                            ? route('lancamentos.recorrencia.pagar', $item['pendenteId'])
                            : null,
                    ];
                }, $grupo['itens']),
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
