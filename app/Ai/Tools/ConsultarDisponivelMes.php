<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Disponivel\ConsultarDisponivelDoMes;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Tool `consultar_disponivel_mes` (doc 02 §3.2, barreira 3). Lê o disponível do mês
 * do usuário e devolve os números JÁ calculados + a fonte (trace). A IA decide
 * chamá-la; a SDK executa; a IA apenas redige sobre o resultado.
 *
 * Escopo por usuário: a tool é construída AMARRADA ao usuário autenticado, e o
 * modelo só pode passar `mes` — nunca um identificador de usuário. Sem SQL livre,
 * sem escrita. Implementada via Laravel AI SDK (regra inviolável 8).
 */
final class ConsultarDisponivelMes implements Tool
{
    public function __construct(private readonly User $user)
    {
    }

    public function description(): Stringable|string
    {
        return 'Consulta o "disponível do mês" do usuário: receitas recebidas no mês menos os '
            .'gastos que vencem no mês, mais o previsto do mês seguinte. Use o parâmetro `mes` '
            .'(YYYY-MM); se ausente, assume o mês corrente.';
    }

    public function handle(Request $request): Stringable|string
    {
        $mes = $request->filled('mes')
            ? (string) $request->string('mes')
            : CarbonImmutable::now(RelativeDate::TIMEZONE)->format('Y-m');

        return app(ConsultarDisponivelDoMes::class)
            ->para($this->user->id, $mes)
            ->paraPrompt();
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mes' => $schema->string()
                ->description('Mês de competência no formato YYYY-MM (ex.: "2026-06"). Ausente assume o mês corrente.'),
        ];
    }
}
