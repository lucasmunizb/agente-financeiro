<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Cartao\CriarCartao;
use App\Domain\Cartao\ListarCartoes;
use App\Domain\FaturaCartao\CicloDaFatura;
use App\Domain\FaturaCartao\ConsultarFaturaCartao;
use App\Domain\Shared\Money;
use App\Domain\Shared\OpaqueId;
use App\Http\Requests\CriarCartaoRequest;
use App\Models\Card;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Cartões & faturas (spec FE §7.13). Borda fina: lista os cartões ({@see ListarCartoes}), exibe
 * a fatura do selecionado por competência ({@see ConsultarFaturaCartao} — total/extrato já
 * calculados; a UI nunca calcula, regra 4) e as datas/status do ciclo ({@see CicloDaFatura},
 * derivado de forma consistente com o §4.2). Cria cartão ({@see CriarCartao}). Só FORMATA em
 * pt-BR (regra 5). O cartão selecionado vai por token opaco (?cartao=); competência por ?mes=.
 * Escopo estrito por usuário.
 */
class CartaoController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    public function index(Request $request, ListarCartoes $listar, ConsultarFaturaCartao $faturas): View
    {
        $userId = $request->user()->id;
        $hoje = CarbonImmutable::now(self::TZ);
        $cartoes = $listar->para($userId);

        if ($cartoes->isEmpty()) {
            return view('cartoes', ['temCartao' => false]);
        }

        $selecionado = $this->cartaoSelecionado($request, $cartoes);
        $mesAlvo = $this->mesAlvo($request, $hoje);
        $mes = $mesAlvo->format('Y-m');

        $fatura = $faturas->paraCartao($userId, $selecionado, $mes);
        $ciclo = CicloDaFatura::paraCompetencia($selecionado->dia_fechamento, $selecionado->dia_vencimento, $mes, $hoje);

        return view('cartoes', [
            'temCartao' => true,
            'cartoes' => $cartoes->map(fn (Card $c): array => [
                'opaqueId' => $c->getRouteKey(),
                'descricao' => $c->descricao,
                'final4' => $c->final_4,
                'diaFechamento' => $c->dia_fechamento,
                'diaVencimento' => $c->dia_vencimento,
                'selecionado' => $c->id === $selecionado->id,
            ])->all(),
            'cartaoSelecionado' => $selecionado->getRouteKey(),
            'mes' => $mes,
            'mesLabel' => $this->rotuloMes($mesAlvo),
            'mesAnterior' => $mesAlvo->subMonthNoOverflow()->format('Y-m'),
            'mesSeguinte' => $mesAlvo->addMonthNoOverflow()->format('Y-m'),
            'faturaTotal' => Money::fromCents($fatura->totalCents)->formatBRL(),
            'fecha' => $this->rotuloDia($ciclo->fecha),
            'vence' => $this->rotuloDia($ciclo->vence),
            'aberta' => $ciclo->aberta,
            'itens' => $this->itens($userId, $fatura->itens),
        ]);
    }

    public function store(CriarCartaoRequest $request, CriarCartao $criar): RedirectResponse
    {
        $card = $criar->criar($request->paraDominio(), CarbonImmutable::now(self::TZ));

        return redirect()
            ->route('cartoes', ['cartao' => $card->getRouteKey()])
            ->with('sucesso', 'Cartão adicionado.');
    }

    /**
     * Cartão selecionado: ?cartao=<token opaco> do próprio usuário; token inválido/ausente/alheio
     * cai no primeiro cartão (nunca vaza dados de outra conta).
     *
     * @param  Collection<int, Card>  $cartoes
     */
    private function cartaoSelecionado(Request $request, Collection $cartoes): Card
    {
        $id = OpaqueId::decode((string) $request->query('cartao', ''));

        return $cartoes->firstWhere('id', $id) ?? $cartoes->first();
    }

    /**
     * Itens da fatura já formatados: fração de parcela na descrição (n/N) e categoria resolvida
     * para chip. A tela só exibe (regra 4).
     *
     * @param  list<array{descricao: string, vencimento: string, cents: int, numero: int, total: int, categoria_id: ?int}>  $itens
     * @return list<array{descricao: string, categoria: ?string, valor: string}>
     */
    private function itens(int $userId, array $itens): array
    {
        $categorias = Category::query()->where('user_id', $userId)->pluck('nome', 'id');

        return array_map(fn (array $i): array => [
            'descricao' => $i['total'] > 1 ? "{$i['descricao']} {$i['numero']}/{$i['total']}" : $i['descricao'],
            'categoria' => $i['categoria_id'] !== null ? ($categorias[$i['categoria_id']] ?? null) : null,
            'valor' => Money::fromCents($i['cents'])->formatBRL(),
        ], $itens);
    }

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

    /** "28 de julho" (dia + mês por extenso), pt-BR. */
    private function rotuloDia(CarbonImmutable $data): string
    {
        return $data->locale('pt_BR')->translatedFormat('j \d\e F');
    }
}
