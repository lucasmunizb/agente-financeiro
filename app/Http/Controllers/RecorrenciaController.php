<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
 * Gerenciar recorrências (spec 10). Borda fina: lista as ATIVAS já formatadas em pt-BR
 * (regra 5; a UI nunca calcula, regra 4) e cancela reusando o domínio ({@see CancelarRecorrencia},
 * idempotente + escopo por usuário — 404 para item alheio). Ids sempre por token opaco.
 */
class RecorrenciaController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    /** @var array<string, string> */
    private const FORMA_LABEL = [
        PaymentMethod::CREDITO => 'Crédito',
        PaymentMethod::DEBITO => 'Débito',
        PaymentMethod::PIX => 'Pix',
        PaymentMethod::DINHEIRO => 'Dinheiro',
        PaymentMethod::BOLETO => 'Boleto',
    ];

    public function index(Request $request, ConsultarRecorrencias $consulta): View
    {
        $recorrencias = $consulta->para($request->user()->id);

        // id → tipo da forma (tabela pequena) para rotular sem N+1.
        $formas = PaymentMethod::pluck('tipo', 'id');

        $itens = $recorrencias->map(fn (Recurrence $r): array => [
            'opaqueId' => $r->getRouteKey(),
            'descricao' => $r->descricao,
            'valor' => Money::fromCents($r->valor_cents)->formatBRL(),
            'forma' => self::FORMA_LABEL[$formas[$r->payment_method_id] ?? null] ?? 'Outros',
            'dia' => $r->dia,
            'proxima' => $r->proxima_em?->format('d/m/Y'),
        ])->all();

        return view('recorrencias', ['itens' => $itens]);
    }

    public function cancelar(Request $request, int $recorrencia, CancelarRecorrencia $cancelar): RedirectResponse
    {
        $cancelada = $cancelar->cancelar($recorrencia, $request->user()->id, CarbonImmutable::now(self::TZ));

        return redirect()->route('recorrencias')->with(
            'sucesso',
            $cancelada ? 'Recorrência cancelada. As próximas ocorrências não serão mais geradas.' : 'Esta recorrência já estava cancelada.',
        );
    }
}
