<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendar\RelativeDate;
use App\Domain\Gasto\RegistrarGastoManual;
use App\Domain\Recorrencia\OcorrenciaMensal;
use App\Domain\Recorrencia\RegistrarRecorrencia;
use App\Http\Controllers\Concerns\SerializaPreviaDeGasto;
use App\Http\Requests\RegistrarGastoRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Borda web do cadastro de gasto manual (modal §7.7b). Camada fina: valida na
 * borda (Form Request), delega ao domínio já testado ({@see RegistrarGastoManual})
 * e devolve JSON para o modal. Não há cálculo aqui (regra 4).
 *
 * Fluxo em dois passos (regra 7 — confirmar antes de gravar):
 * - {@see self::previa()} calcula e devolve a prévia SEM persistir;
 * - {@see self::store()} persiste após a confirmação do usuário.
 *
 * Recorrência (§7.7, spec 12): com o switch "Repete todo mês?" ligado — inclusive em cartão
 * (D3) — NENHUM lançamento é criado. Nasce o molde e a OCORRÊNCIA do mês corrente (D2), que é
 * a única representação daquela conta no mês (R1). Era exatamente a criação simultânea de
 * gasto avulso + recorrência que duplicava a linha no extrato.
 *
 * "Data de pagamento" (decisão 2026-07-21): quem cadastra um gasto que JÁ pagou informa a
 * data e a 1ª parcela nasce paga ({@see RegistrarGastoManual}). Só fora de cartão e nunca no
 * futuro — a borda recusa o resto ({@see RegistrarGastoRequest}).
 */
class GastoController extends Controller
{
    use SerializaPreviaDeGasto;

    public function previa(RegistrarGastoRequest $request, RegistrarGastoManual $registrar): JsonResponse
    {
        $previa = $registrar->preview($request->paraDominio());

        $json = $this->previaParaJson($previa);
        $json['recorrencia'] = $this->notaDeRecorrencia($request);

        return response()->json($json);
    }

    public function store(
        RegistrarGastoRequest $request,
        RegistrarGastoManual $registrar,
        RegistrarRecorrencia $recorrencias,
    ): JsonResponse {
        // Recorrente ⇒ SÓ o molde + a ocorrência do mês (spec 12, R1). Criar também um gasto
        // avulso aqui era a origem da linha duplicada no extrato: as duas representavam a
        // mesma cobrança do mesmo mês.
        if ($dados = $request->dadosRecorrencia()) {
            $recorrencia = $recorrencias->registrar($dados, CarbonImmutable::now(RelativeDate::TIMEZONE));

            return response()->json(['ok' => true, 'recorrencia' => true, 'id' => $recorrencia->getRouteKey()]);
        }

        $transaction = $registrar->confirmar($request->paraDominio());

        return response()->json(['ok' => true, 'recorrencia' => false, 'id' => $transaction->id]);
    }

    /**
     * Nota de recorrência para o painel de confirmação: o dia e o mês em que a recorrência
     * COMEÇA — o mês CORRENTE (spec 12, D2: ela já vale no mês do cadastro). A data é calculada
     * aqui (regra 4 — a tela não calcula), formatada em pt-BR (regra 5). Null quando não é
     * recorrente.
     *
     * @return array{dia: int, primeiraEm: string}|null
     */
    private function notaDeRecorrencia(RegistrarGastoRequest $request): ?array
    {
        if (! $request->ehRecorrente()) {
            return null;
        }

        $dia = (int) $request->input('dia_recorrencia');
        $primeira = OcorrenciaMensal::aPartirDe(
            $dia,
            CarbonImmutable::now(RelativeDate::TIMEZONE)->startOfMonth(),
        );

        return [
            'dia' => $dia,
            'primeiraEm' => $primeira->locale('pt_BR')->translatedFormat('F \d\e Y'),
        ];
    }
}
