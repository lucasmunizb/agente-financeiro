<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Recorrencia\CalcularOcorrencia;
use App\Domain\Recorrencia\CancelarRecorrencia;
use App\Domain\Recorrencia\ConsultarRecorrencias;
use App\Domain\Shared\Money;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gerenciar recorrências (spec 10, revista pela spec 12). Borda fina: lista as ATIVAS já
 * formatadas em pt-BR (regra 5; a UI nunca calcula, regra 4) e cancela reusando o domínio
 * ({@see CancelarRecorrencia}, idempotente + escopo por usuário — 404 para item alheio). Ids
 * sempre por token opaco.
 *
 * A "próxima cobrança" NÃO é o `proxima_em` cru: esse ponteiro passou a ser o 1º dia do
 * primeiro MÊS ainda não gerado (spec 12), então exibi-lo diria "01/08" para uma conta que
 * vence dia 5. A data mostrada vem do domínio ({@see CalcularOcorrencia}), que resolve o dia do
 * molde naquele mês — e, no cartão, o vencimento da fatura em que a cobrança cai.
 */
class RecorrenciaController extends Controller
{
    /** @var array<string, string> */
    private const FORMA_LABEL = [
        PaymentMethod::CREDITO => 'Crédito',
        PaymentMethod::DEBITO => 'Débito',
        PaymentMethod::PIX => 'Pix',
        PaymentMethod::DINHEIRO => 'Dinheiro',
        PaymentMethod::BOLETO => 'Boleto',
    ];

    public function index(Request $request, ConsultarRecorrencias $consulta, CalcularOcorrencia $calcular): View
    {
        $recorrencias = $consulta->para($request->user()->id);

        $itens = $recorrencias->map(fn (Recurrence $r): array => [
            'opaqueId' => $r->getRouteKey(),
            'descricao' => $r->descricao,
            'valor' => Money::fromCents($r->valor_cents)->formatBRL(),
            'forma' => self::FORMA_LABEL[$r->paymentMethod?->tipo] ?? 'Outros',
            'cartao' => $r->card !== null ? $r->card->descricao.' •••• '.$r->card->final_4 : null,
            'dia' => $r->dia,
            'proxima' => $this->proximaCobranca($r, $calcular),
        ])->all();

        return view('recorrencias', ['itens' => $itens]);
    }

    /**
     * Data da próxima cobrança ainda não gerada, em pt-BR — ou null quando não há. Todo o
     * cálculo é do domínio; aqui só formatamos (regra 4).
     */
    private function proximaCobranca(Recurrence $recorrencia, CalcularOcorrencia $calcular): ?string
    {
        if ($recorrencia->proxima_em === null) {
            return null;
        }

        return $calcular->para($recorrencia, $recorrencia->proxima_em->format('Y-m'))
            ->vencimento
            ->format('d/m/Y');
    }

    public function cancelar(Request $request, int $recorrencia, CancelarRecorrencia $cancelar): RedirectResponse
    {
        $cancelada = $cancelar->cancelar($recorrencia, $request->user()->id, CarbonImmutable::now(RelativeDate::TIMEZONE));

        return redirect()->route('recorrencias')->with(
            'sucesso',
            $cancelada
                ? 'Recorrência cancelada. As cobranças futuras não serão mais geradas.'
                : 'Esta recorrência já estava cancelada.',
        );
    }
}
