<?php

namespace App\Http\Controllers;

use App\Domain\Conta\AlterarSenha;
use App\Domain\Conta\AtualizarPerfil;
use App\Domain\Conta\DadosPerfil;
use App\Domain\Conta\ExcluirConta;
use App\Domain\Conta\ExportarDadosDoUsuario;
use App\Domain\Calendar\RelativeDate;
use App\Http\Requests\Conta\AlterarSenhaRequest;
use App\Http\Requests\Conta\AtualizarPerfilRequest;
use App\Http\Requests\Conta\ExcluirContaRequest;
use App\Models\TelegramLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Tela Configurações & privacidade (spec FE §7.17). Fia a apresentação (regra 3) às ações de
 * domínio já testadas: perfil, senha, exportar (portabilidade LGPD) e excluir conta (dupla
 * confirmação, regra 7). Tudo escopado no usuário autenticado.
 */
class ConfiguracoesController extends Controller
{
    /** Fusos oferecidos no seletor — os do Brasil, com SP em primeiro (default, regra 5). */
    private const FUSOS = [
        'America/Sao_Paulo', 'America/Bahia', 'America/Fortaleza', 'America/Recife',
        'America/Maceio', 'America/Belem', 'America/Araguaina', 'America/Santarem',
        'America/Manaus', 'America/Cuiaba', 'America/Campo_Grande', 'America/Porto_Velho',
        'America/Boa_Vista', 'America/Rio_Branco', 'America/Noronha',
    ];

    public function show(Request $request): View
    {
        $user = $request->user();

        $ativo = TelegramLink::where('user_id', $user->id)
            ->where('status', TelegramLink::ATIVO)
            ->first();

        return view('configuracoes', [
            'user' => $user,
            'fusos' => self::FUSOS,
            'telegramVinculado' => $ativo !== null,
            'telegramHandle' => $ativo !== null ? $this->mascararTelefone($ativo->telefone) : null,
        ]);
    }

    public function atualizarPerfil(AtualizarPerfilRequest $request, AtualizarPerfil $atualizar): RedirectResponse
    {
        $dados = $request->validated();

        $atualizar->atualizar(new DadosPerfil(
            userId: $request->user()->id,
            name: $dados['name'],
            email: $dados['email'],
            timezone: $dados['timezone'],
        ));

        return redirect()->route('configuracoes')->with('sucesso', 'Perfil atualizado.');
    }

    public function alterarSenha(AlterarSenhaRequest $request, AlterarSenha $alterar): RedirectResponse
    {
        $alterar->alterar($request->user()->id, $request->validated()['senha']);

        return redirect()->route('configuracoes')->with('sucesso', 'Senha alterada.');
    }

    public function exportar(Request $request, ExportarDadosDoUsuario $exportar): Response
    {
        $dados = $exportar->exportar($request->user()->id);

        $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nome = 'meus-dados-'.CarbonImmutable::now(RelativeDate::TIMEZONE)->format('Y-m-d').'.json';

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$nome.'"',
        ]);
    }

    public function excluirConta(ExcluirContaRequest $request, ExcluirConta $excluir): RedirectResponse
    {
        $excluir->excluir($request->user()->id);

        // Encerra a sessão (borda): o guard de SoftDeletes já bloqueia novo login.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Sua conta foi excluída.');
    }

    /** Só os últimos 4 dígitos (LGPD — minimização na borda). */
    private function mascararTelefone(?string $telefone): string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone) ?? '';

        return $digitos === '' ? 'Conta conectada' : '•••• '.substr($digitos, -4);
    }
}
