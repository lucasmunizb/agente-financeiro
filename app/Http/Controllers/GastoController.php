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
use Illuminate\Support\Facades\DB;

/**
 * Borda web do cadastro de gasto manual (modal §7.7b). Camada fina: valida na
 * borda (Form Request), delega ao domínio já testado ({@see RegistrarGastoManual})
 * e devolve JSON para o modal. Não há cálculo aqui (regra 4).
 *
 * Fluxo em dois passos (regra 7 — confirmar antes de gravar):
 * - {@see self::previa()} calcula e devolve a prévia SEM persistir;
 * - {@see self::store()} persiste após a confirmação do usuário.
 *
 * Recorrência (§7.7, spec 10): com o switch "Repete todo mês?" ligado (só fora de cartão), o
 * gasto do mês atual é lançado normalmente E uma recorrência é criada começando no MÊS
 * SEGUINTE (não conta o mês atual em dobro). A recorrência nunca grava sozinha — enfileira
 * confirmações no dia (regra 7); o materializador é quem roda mês a mês.
 *
 * Nota: "Data de pagamento" (marcar como pago na criação) ainda não é suportada
 * pelo domínio — o campo é aceito, porém ignorado; será uma feature própria (com
 * migration + TDD) quando entrar no escopo.
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
        // Gasto do mês + recorrência (quando houver) na MESMA transação: ou grava os dois, ou
        // nenhum (nada de gasto órfão sem a regra que o repete, nem o contrário).
        $transaction = DB::transaction(function () use ($request, $registrar, $recorrencias) {
            $tx = $registrar->confirmar($request->paraDominio());

            if ($dados = $request->dadosRecorrencia()) {
                $hoje = CarbonImmutable::now(RelativeDate::TIMEZONE);
                // Este mês já virou gasto acima; a recorrência começa no mês seguinte.
                $recorrencia = $recorrencias->registrar($dados, $hoje, $hoje->startOfMonth()->addMonthNoOverflow());
                // Liga o gasto DESTE mês à recorrência que nasceu junto: assim ele já aparece como
                // recorrente na tela (com dia/próxima) e a edição oferece o alcance "este e os
                // próximos". Os meses seguintes materializam outros lançamentos, todos vinculados.
                $tx->update(['recurrence_id' => $recorrencia->id]);
            }

            return $tx;
        });

        return response()->json(['ok' => true, 'id' => $transaction->id]);
    }

    /**
     * Nota de recorrência para o painel de confirmação: o dia e o mês em que a recorrência
     * COMEÇA (mês seguinte). A data é calculada aqui (regra 4 — a tela não calcula), formatada
     * em pt-BR (regra 5). Null quando o gasto não é recorrente.
     *
     * @return array{dia: int, primeiraEm: string}|null
     */
    private function notaDeRecorrencia(RegistrarGastoRequest $request): ?array
    {
        if (! $request->ehRecorrente()) {
            return null;
        }

        $dia = (int) $request->input('dia_recorrencia');
        $hoje = CarbonImmutable::now(RelativeDate::TIMEZONE);
        $primeira = OcorrenciaMensal::aPartirDe($dia, $hoje->startOfMonth()->addMonthNoOverflow());

        return [
            'dia' => $dia,
            'primeiraEm' => $primeira->locale('pt_BR')->translatedFormat('F \d\e Y'),
        ];
    }
}
