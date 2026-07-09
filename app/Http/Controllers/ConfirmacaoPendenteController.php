<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Confirmacao\ConfirmarPendente;
use App\Domain\Confirmacao\ConsultarPendentes;
use App\Domain\Confirmacao\RejeitarPendente;
use App\Domain\Shared\Money;
use App\Models\PaymentMethod;
use App\Models\PendingConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fila de confirmações pendentes (FE §7.9). Borda fina: delega ao domínio determinístico
 * ({@see ConsultarPendentes}/{@see ConfirmarPendente}/{@see RejeitarPendente}) e só FORMATA em
 * pt-BR para a tela (regra 3/5; a UI nunca calcula, regra 4). Escopo ESTRITO por usuário (o
 * domínio isola por user_id — 404 para item alheio). Ids sempre por token opaco. Confirmar
 * grava reusando RegistrarGastoManual; rejeitar descarta sem gravar (regra 7, sem auto-save).
 */
class ConfirmacaoPendenteController extends Controller
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

    /** @var array<string, string> */
    private const ORIGEM_LABEL = [
        PendingConfirmation::ORIGEM_CHAT => 'Chat',
        PendingConfirmation::ORIGEM_TELEGRAM => 'Telegram',
        PendingConfirmation::ORIGEM_RECORRENCIA => 'Recorrência',
        PendingConfirmation::ORIGEM_IMPORTACAO => 'Importação',
    ];

    public function index(Request $request, ConsultarPendentes $consulta): View
    {
        $userId = $request->user()->id;
        $pendentes = $consulta->para($userId, CarbonImmutable::now(self::TZ));

        // id → tipo da forma (tabela pequena) para rotular sem N+1.
        $formas = PaymentMethod::pluck('tipo', 'id');

        $itens = $pendentes->map(function (PendingConfirmation $p) use ($formas): array {
            $pl = $p->payload;
            $parcelas = (int) ($pl['parcelas'] ?? 1);
            $tipo = $formas[$pl['paymentMethodId']] ?? null;

            return [
                'opaqueId' => $p->getRouteKey(),
                'descricao' => (string) $pl['descricao'],
                'valor' => Money::fromCents((int) $pl['valorTotalCents'])->formatBRL(),
                'data' => CarbonImmutable::parse((string) $pl['dataCompra'], self::TZ)->format('d/m/Y'),
                'parcelas' => $parcelas > 1 ? $parcelas.'x' : 'à vista',
                'forma' => self::FORMA_LABEL[$tipo] ?? 'Outros',
                'origem' => self::ORIGEM_LABEL[$p->origem] ?? $p->origem,
                'origemCodigo' => $p->origem, // hook de estilo do selo (ex.: recorrência)
            ];
        })->all();

        return view('confirmacoes', ['itens' => $itens]);
    }

    public function confirmar(Request $request, int $pendente, ConfirmarPendente $confirmar): RedirectResponse
    {
        $transaction = $confirmar->confirmar($pendente, $request->user()->id, CarbonImmutable::now(self::TZ));

        return redirect()->route('confirmacoes')->with(
            'sucesso',
            $transaction !== null ? 'Lançamento confirmado.' : 'Este item não está mais pendente.',
        );
    }

    public function rejeitar(Request $request, int $pendente, RejeitarPendente $rejeitar): RedirectResponse
    {
        $rejeitar->rejeitar($pendente, $request->user()->id, CarbonImmutable::now(self::TZ));

        return redirect()->route('confirmacoes')->with('sucesso', 'Item descartado.');
    }
}
