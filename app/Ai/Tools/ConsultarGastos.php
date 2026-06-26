<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gastos\ConsultarGastos as ConsultarGastosDoMes;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Tool `consultar_gastos` (doc 02 §3.2, barreira 3). Soma os gastos do usuário num
 * período, com filtros opcionais (categoria, cartão, status), e devolve os números JÁ
 * calculados + a quebra por categoria + a fonte (trace). A IA decide chamá-la; a SDK
 * executa; a IA apenas redige sobre o resultado.
 *
 * Escopo por usuário: a tool é construída AMARRADA ao usuário autenticado, e o modelo
 * só passa filtros — nunca um identificador de usuário. Sem SQL livre, sem escrita.
 * Implementada via Laravel AI SDK (regra inviolável 8).
 */
final class ConsultarGastos implements Tool
{
    public function __construct(private readonly User $user) {}

    public function description(): Stringable|string
    {
        return 'Consulta os gastos do usuário num período (parcelas que vencem no mês). '
            .'Use `periodo` (YYYY-MM); se ausente, assume o mês corrente. Filtros opcionais: '
            .'`categoria` (nome da categoria), `cartao` (descrição ou 4 dígitos finais) e '
            .'`status` (ex.: aberto, pago, vencido, cancelado). Devolve o total e a quebra por categoria.';
    }

    public function handle(Request $request): Stringable|string
    {
        $periodo = $request->filled('periodo')
            ? (string) $request->string('periodo')
            : CarbonImmutable::now(RelativeDate::TIMEZONE)->format('Y-m');

        return app(ConsultarGastosDoMes::class)->para(
            userId: $this->user->id,
            periodo: $periodo,
            categoria: $request->filled('categoria') ? (string) $request->string('categoria') : null,
            cartao: $request->filled('cartao') ? (string) $request->string('cartao') : null,
            status: $request->filled('status') ? (string) $request->string('status') : null,
        )->paraPrompt();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'periodo' => $schema->string()
                ->description('Mês de competência no formato YYYY-MM (ex.: "2026-06"). Ausente assume o mês corrente.'),
            'categoria' => $schema->string()
                ->description('Filtra por nome da categoria (ex.: "Alimentação"). Opcional.'),
            'cartao' => $schema->string()
                ->description('Filtra por cartão: a descrição (ex.: "cartão pai") ou os 4 dígitos finais. Opcional.'),
            'status' => $schema->string()
                ->description('Filtra por status de pagamento (ex.: aberto, pago, vencido, cancelado). Opcional.'),
        ];
    }
}
