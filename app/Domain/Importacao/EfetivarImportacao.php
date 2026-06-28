<?php

declare(strict_types=1);

namespace App\Domain\Importacao;

use App\Domain\Gasto\DadosGastoManual;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Models\InvoiceImport;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Efetivação dos itens aceitos da pré-importação (spec 07 §6, C8, regra 7).
 *
 * Grava SOMENTE o subconjunto que o usuário confirmou, reusando o motor do Bloco 1
 * ({@see RegistrarGastoManual::confirmar}) com `origem = 'pdf'` e o cartão da fatura.
 * Atualiza o status da `invoice_imports` (confirmada / parcial / cancelada). Escopo
 * estrito por `user_id`. O descarte do PDF/texto é responsabilidade do job (finally).
 */
final class EfetivarImportacao
{
    public function __construct(
        private readonly RegistrarGastoManual $registrar = new RegistrarGastoManual,
    ) {}

    /**
     * @param  array<int, ItemPreImportacao>  $itensAceitos
     * @return array<int, Transaction>
     */
    public function confirmar(
        int $importId,
        int $userId,
        array $itensAceitos,
        int $totalOferecidos,
        ?CarbonImmutable $hoje = null,
    ): array {
        $import = InvoiceImport::query()
            ->where('id', $importId)
            ->where('user_id', $userId)
            ->first();

        if ($import === null) {
            throw new RuntimeException("Importação {$importId} não encontrada para o usuário {$userId}.");
        }

        $creditoId = PaymentMethod::idFor(PaymentMethod::CREDITO);

        return DB::transaction(function () use ($import, $userId, $itensAceitos, $totalOferecidos, $creditoId, $hoje) {
            $transactions = [];
            foreach ($itensAceitos as $item) {
                $l = $item->lancamento;
                $transactions[] = $this->registrar->confirmar(new DadosGastoManual(
                    userId: $userId,
                    descricao: $l->descricao,
                    valorTotalCents: $l->valorCents,
                    dataCompra: $l->data,
                    paymentMethodId: $creditoId,
                    parcelas: $l->parcelas,
                    cardId: $import->card_id,
                    categoriaId: $item->categoriaIdSugerida,
                    origem: 'pdf',
                ), $hoje);
            }

            $import->update(['status' => $this->statusFinal(count($itensAceitos), $totalOferecidos)]);

            return $transactions;
        });
    }

    private function statusFinal(int $aceitos, int $total): string
    {
        return match (true) {
            $aceitos === 0 => InvoiceImport::CANCELADA,
            $aceitos < $total => InvoiceImport::PARCIAL,
            default => InvoiceImport::CONFIRMADA,
        };
    }
}
