<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Domain\Calendar\RelativeDate;
use App\Domain\ProximasContas\ConsultarProximasContas as ConsultarProximasContasDoUsuario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Tool `consultar_proximas_contas` (doc 02 §3.2, barreira 3). Itemiza e soma as contas
 * do usuário a vencer dentro de uma janela em dias a partir de hoje, e devolve os
 * números JÁ calculados + a fonte (trace). A IA decide chamá-la; a SDK executa; a IA
 * apenas redige sobre o resultado.
 *
 * Escopo por usuário: a tool é construída AMARRADA ao usuário autenticado, e o modelo
 * só passa `janela` — nunca um identificador de usuário. "Hoje" é resolvido aqui no
 * fuso de São Paulo (a IA não resolve datas). Sem SQL livre, sem escrita. Implementada
 * via Laravel AI SDK (regra inviolável 8).
 */
final class ConsultarProximasContas implements Tool
{
    /** Janela padrão (em dias) quando o modelo não a informa. */
    private const JANELA_PADRAO = 30;

    public function __construct(private readonly User $user)
    {
    }

    public function description(): Stringable|string
    {
        return 'Lista as próximas contas a pagar do usuário: parcelas a vencer dentro de uma '
            .'janela em dias a partir de hoje. Use `janela` (número de dias à frente); se ausente, '
            .'assume 30 dias. Devolve o total e cada conta com seu vencimento. Já pagas, canceladas '
            .'ou estornadas não entram.';
    }

    public function handle(Request $request): Stringable|string
    {
        $janela = $request->filled('janela')
            ? max(1, $request->integer('janela'))
            : self::JANELA_PADRAO;

        return app(ConsultarProximasContasDoUsuario::class)->para(
            userId: $this->user->id,
            hoje: CarbonImmutable::now(RelativeDate::TIMEZONE),
            janelaDias: $janela,
        )->paraPrompt();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'janela' => $schema->integer()
                ->description('Número de dias à frente, a partir de hoje, para listar as contas a vencer '
                    .'(ex.: 7 para a próxima semana, 30 para o próximo mês). Ausente assume 30 dias.'),
        ];
    }
}
