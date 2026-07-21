<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Recorrencia\CalcularOcorrencia;
use App\Domain\Recorrencia\CancelarRecorrencia;
use App\Domain\Recorrencia\ConsultarRecorrencias;
use App\Domain\Recorrencia\EditarOcorrencia;
use App\Domain\Recorrencia\SincronizarRecorrencia;
use App\Domain\Shared\Money;
use App\Http\Requests\EditarOcorrenciaRequest;
use App\Models\PaymentMethod;
use App\Models\Recurrence;
use App\Models\RecurrenceOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
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

    /**
     * PRÉVIA da edição de uma ocorrência (1º passo da regra 7): devolve, em pt-BR, o que
     * seria salvo — sem gravar nada. A conferência de posse acontece aqui (404 para
     * ocorrência alheia) para a prévia não vazar a existência de dado de terceiro.
     */
    public function previaOcorrencia(EditarOcorrenciaRequest $request, int $ocorrencia): JsonResponse
    {
        $this->ocorrenciaDoUsuario($ocorrencia, $request->user()->id);

        $vencimento = CarbonImmutable::parse((string) $request->input('vencimento'), RelativeDate::TIMEZONE);

        return response()->json([
            'previa' => [
                'descricao' => $request->descricao(),
                'valor' => Money::fromCents($request->valorCents())->formatBRL(),
                'vencimento' => $vencimento->format('d/m/Y'),
                // A competência acompanha o vencimento (§4.5): mover a data move o mês em
                // que a conta pesa — vale avisar antes de gravar.
                'competencia' => $vencimento->format('Y-m'),
            ],
        ]);
    }

    /**
     * Grava a edição da ocorrência do mês (2º passo). Borda fina: delega ao domínio
     * ({@see EditarOcorrencia}), que isola por usuário, só aceita categoria do próprio
     * usuário e NÃO toca no molde — para propagar aos meses seguintes existe
     * {@see SincronizarRecorrencia}.
     *
     * Cobrança em CARTÃO é recusada: ela é item de fatura (R8) e editá-la avulsa divergiria
     * do extrato do cartão — nesse caso a correção é no molde.
     */
    public function atualizarOcorrencia(EditarOcorrenciaRequest $request, int $ocorrencia, EditarOcorrencia $editar): RedirectResponse
    {
        $alvo = $this->ocorrenciaDoUsuario($ocorrencia, $request->user()->id);

        if ($alvo->ehCartao()) {
            return back()->withErrors([
                'geral' => 'Esta cobrança entra na fatura do cartão. Para mudá-la, edite a recorrência.',
            ]);
        }

        $editar->editar(
            ocorrenciaId: $ocorrencia,
            userId: $request->user()->id,
            descricao: $request->descricao(),
            valorCents: $request->valorCents(),
            categoriaId: $request->categoriaId(),
            vencimento: CarbonImmutable::parse((string) $request->input('vencimento'), RelativeDate::TIMEZONE),
        );

        return back()->with('sucesso', 'Conta deste mês atualizada.');
    }

    /** Ocorrência do PRÓPRIO usuário — 404 para qualquer outra (escopo estrito, R12). */
    private function ocorrenciaDoUsuario(int $ocorrenciaId, int $userId): RecurrenceOccurrence
    {
        return RecurrenceOccurrence::query()
            ->where('user_id', $userId)
            ->findOrFail($ocorrenciaId);
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
