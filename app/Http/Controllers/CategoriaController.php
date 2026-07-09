<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Categoria\ArquivarCategoria;
use App\Domain\Categoria\CriarCategoria;
use App\Domain\Categoria\EditarCategoria;
use App\Domain\Categoria\ListarCategorias;
use App\Domain\Categoria\PaletaDeCategoria;
use App\Http\Requests\CriarCategoriaRequest;
use App\Http\Requests\EditarCategoriaRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Categorias (spec FE §7.12). Borda fina: lista as categorias com a contagem de uso JÁ calculada
 * ({@see ListarCategorias} — a UI nunca calcula, regra 4) e grava criar/editar/arquivar após ação
 * explícita ({@see CriarCategoria}/{@see EditarCategoria}/{@see ArquivarCategoria}). Arquivar é
 * lógico (não apaga o histórico). Escopo estrito por usuário; o id só sai/entra CRIPTOGRAFADO.
 */
class CategoriaController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    public function index(Request $request, ListarCategorias $listar): View
    {
        $lista = $listar->para($request->user()->id);

        return view('categorias', [
            'categorias' => $lista->map(fn (array $l): array => [
                'opaqueId' => $l['categoria']->getRouteKey(),
                'nome' => $l['categoria']->nome,
                'cor' => $l['categoria']->cor,
                'icone' => PaletaDeCategoria::icone($l['categoria']->icone),
                'corSelecionada' => $l['categoria']->cor,
                'iconeSelecionado' => $l['categoria']->icone,
                'usos' => $l['usos'],
                // Prefill dos campos de tags (texto separado por vírgula; regras já normalizadas).
                'palavrasChave' => $l['categoria']->keywords->pluck('palavra_chave')->implode(', '),
                'apelidos' => $l['categoria']->aliases->pluck('alias')->implode(', '),
            ])->all(),
            'cores' => PaletaDeCategoria::CORES,
            'icones' => PaletaDeCategoria::ICONES,
        ]);
    }

    public function store(CriarCategoriaRequest $request, CriarCategoria $criar): RedirectResponse
    {
        $criar->criar($request->paraDominio(), CarbonImmutable::now(self::TZ));

        return redirect()->route('categorias')->with('sucesso', 'Categoria criada.');
    }

    public function update(EditarCategoriaRequest $request, int $categoria, EditarCategoria $editar): RedirectResponse
    {
        $editar->editar($categoria, $request->paraDominio(), CarbonImmutable::now(self::TZ));

        return redirect()->route('categorias')->with('sucesso', 'Categoria atualizada.');
    }

    public function arquivar(Request $request, int $categoria, ArquivarCategoria $arquivar): RedirectResponse
    {
        $arquivar->arquivar($categoria, $request->user()->id, CarbonImmutable::now(self::TZ));

        return redirect()->route('categorias')->with('sucesso', 'Categoria arquivada.');
    }
}
