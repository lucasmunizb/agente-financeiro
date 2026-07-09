<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Receita\EditarReceita;
use App\Domain\Receita\ExcluirReceita;
use App\Domain\Receita\ListarReceitas;
use App\Domain\Receita\ReceitasDoMes;
use App\Domain\Receita\RegistrarReceita;
use App\Domain\Shared\Money;
use App\Http\Requests\EditarReceitaRequest;
use App\Http\Requests\ReceitaRequest;
use App\Models\Income;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Receitas (spec FE §7.10). Borda fina: lista as receitas do mês (filtro por tipo) e o total JÁ
 * somado pelo domínio ({@see ReceitasDoMes}; a UI nunca soma, regra 4); só FORMATA em pt-BR
 * (regra 5). Adicionar é em DOIS PASSOS (regra 7): sem `confirmado`, mostra o resumo sem gravar;
 * com `confirmado`, grava reusando {@see RegistrarReceita}. Escopo estrito por usuário.
 */
class ReceitaController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    /** @var array<string, string> */
    private const TIPO_LABEL = [
        Income::TIPO_FIXA => 'Fixa',
        Income::TIPO_VARIAVEL => 'Variável',
    ];

    public function index(Request $request, ListarReceitas $listar, ReceitasDoMes $soma): View
    {
        return view('receitas', $this->viewModel($request, $listar, $soma));
    }

    public function store(ReceitaRequest $request, RegistrarReceita $registrar, ListarReceitas $listar, ReceitasDoMes $soma): RedirectResponse|View
    {
        // Passo 1 (regra 7): resumo do que será salvo, sem gravar.
        if (! $request->confirmado()) {
            $dados = $request->paraDominio();

            return view('receitas', $this->viewModel($request, $listar, $soma) + [
                'confirmar' => [
                    'descricao' => $dados->descricao,
                    'valor' => Money::fromCents($dados->valorCents)->formatBRL(),
                    'tipo' => self::TIPO_LABEL[$dados->tipo] ?? $dados->tipo,
                    'data' => $dados->data->format('d/m/Y'),
                    // Valores crus para o form de confirmação reenviar (o backend revalida).
                    'raw' => [
                        'descricao' => (string) $request->input('descricao'),
                        'valor' => (string) $request->input('valor'),
                        'tipo' => $dados->tipo,
                        'data' => (string) $request->input('data'),
                    ],
                ],
            ]);
        }

        // Passo 2: grava.
        $registrar->registrar($request->paraDominio(), CarbonImmutable::now(self::TZ));

        return redirect()->route('receitas')->with('sucesso', 'Receita adicionada.');
    }

    public function update(EditarReceitaRequest $request, int $receita, EditarReceita $editar): RedirectResponse
    {
        $editar->editar($receita, $request->paraDominio(), CarbonImmutable::now(self::TZ));

        return redirect()->route('receitas')->with('sucesso', 'Receita atualizada.');
    }

    public function destroy(Request $request, int $receita, ExcluirReceita $excluir): RedirectResponse
    {
        $excluir->excluir($receita, $request->user()->id, CarbonImmutable::now(self::TZ));

        return redirect()->route('receitas')->with('sucesso', 'Receita excluída.');
    }

    /**
     * View-model da tela (lista + total + competência), já formatado em pt-BR.
     *
     * @return array<string, mixed>
     */
    private function viewModel(Request $request, ListarReceitas $listar, ReceitasDoMes $soma): array
    {
        $userId = $request->user()->id;
        $hoje = CarbonImmutable::now(self::TZ);
        $mesAlvo = $this->mesAlvo($request, $hoje);
        $mes = $mesAlvo->format('Y-m');
        $tipo = $this->tipoFiltro($request);

        return [
            'mes' => $mes,
            'mesLabel' => $this->rotuloMes($mesAlvo),
            'mesNome' => $mesAlvo->locale('pt_BR')->translatedFormat('F'),
            'mesAnterior' => $mesAlvo->subMonthNoOverflow()->format('Y-m'),
            'mesSeguinte' => $mesAlvo->addMonthNoOverflow()->format('Y-m'),
            'tipoAtivo' => $tipo,
            'total' => Money::fromCents($soma->para($userId, $mes))->formatBRL(),
            'dataPadrao' => $hoje->toDateString(),
            'itens' => $listar->para($userId, $mes, $tipo)->map(fn (Income $r): array => [
                'opaqueId' => $r->getRouteKey(),
                'descricao' => $r->descricao,
                'tipo' => self::TIPO_LABEL[$r->tipo] ?? $r->tipo,
                'tipoCodigo' => $r->tipo,
                'data' => $r->data->format('d/m'),
                'dataIso' => $r->data->toDateString(),
                'valor' => Money::fromCents($r->valor_cents)->formatBRL(),
                // Prefill do form de edição (sem R$; regra 5 na borda).
                'valorInput' => trim(str_replace('R$', '', Money::fromCents($r->valor_cents)->formatBRL())),
            ])->all(),
        ];
    }

    private function tipoFiltro(Request $request): ?string
    {
        $tipo = (string) $request->query('tipo', '');

        return in_array($tipo, [Income::TIPO_FIXA, Income::TIPO_VARIAVEL], true) ? $tipo : null;
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
}
