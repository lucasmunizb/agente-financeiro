<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Domain\Calendar\RelativeDate;
use App\Domain\FaturaCartao\ConsultarFaturaCartao as ConsultarFaturaCartaoDoUsuario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Tool `consultar_fatura_cartao` (doc 02 §3.2, barreira 3). Itemiza e soma a fatura de
 * um cartão numa competência (cobranças cujo vencimento cai no mês) e devolve os números
 * JÁ calculados + a fonte (trace). A IA decide chamá-la; a SDK executa; a IA apenas
 * redige sobre o resultado.
 *
 * Escopo por usuário: a tool é construída AMARRADA ao usuário autenticado, e o modelo
 * só passa `cartao` e `competencia` — nunca um identificador de usuário. Sem SQL livre,
 * sem escrita. Implementada via Laravel AI SDK (regra inviolável 8).
 */
final class ConsultarFaturaCartao implements Tool
{
    public function __construct(private readonly User $user) {}

    public function description(): Stringable|string
    {
        return 'Consulta a fatura de um cartão numa competência: as cobranças (parcelas) cujo '
            .'vencimento cai no mês. Use `cartao` (descrição, ex.: "cartão pai", ou os 4 dígitos '
            .'finais) e `competencia` (YYYY-MM); se a competência for omitida, assume o mês corrente. '
            .'Devolve o total e cada cobrança (parcela n/N quando parcelado). Canceladas, estornadas '
            .'e pendentes de revisão não entram.';
    }

    public function handle(Request $request): Stringable|string
    {
        $competencia = $request->filled('competencia')
            ? (string) $request->string('competencia')
            : CarbonImmutable::now(RelativeDate::TIMEZONE)->format('Y-m');

        return app(ConsultarFaturaCartaoDoUsuario::class)->para(
            userId: $this->user->id,
            cartao: (string) $request->string('cartao'),
            competencia: $competencia,
        )->paraPrompt();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cartao' => $schema->string()
                ->description('Cartão da fatura: a descrição (ex.: "cartão pai") ou os 4 dígitos finais.'),
            'competencia' => $schema->string()
                ->description('Mês de competência da fatura no formato YYYY-MM (ex.: "2026-06"). '
                    .'Ausente assume o mês corrente.'),
        ];
    }
}
