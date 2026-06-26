<?php

declare(strict_types=1);

namespace App\Domain\Duplicidade;

/**
 * Detector determinístico de duplicidade (doc 07 §2).
 *
 * Trabalha sobre {@see ChaveDeDuplicidade} (valor + descrição + data + nº de
 * parcelas), nunca sobre a parcela atual. É puro: a consulta dos lançamentos já
 * existentes é responsabilidade da camada de importação/persistência.
 */
final class DetectorDeDuplicidade
{
    /**
     * Indica se a chave já existe no conjunto informado.
     *
     * @param  array<int, ChaveDeDuplicidade>  $existentes
     */
    public static function ehDuplicado(ChaveDeDuplicidade $candidato, array $existentes): bool
    {
        foreach ($existentes as $existente) {
            if ($candidato->igualA($existente)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filtra os candidatos novos: descarta os que já existem e as repetições
     * dentro do próprio lote (mantém a primeira ocorrência), preservando a ordem.
     *
     * @param  array<int, ChaveDeDuplicidade>  $candidatos
     * @param  array<int, ChaveDeDuplicidade>  $existentes
     * @return array<int, ChaveDeDuplicidade>
     */
    public static function apenasNovos(array $candidatos, array $existentes): array
    {
        $vistos = [];
        foreach ($existentes as $existente) {
            $vistos[$existente->hash()] = true;
        }

        $novos = [];
        foreach ($candidatos as $candidato) {
            $hash = $candidato->hash();
            if (isset($vistos[$hash])) {
                continue;
            }

            $vistos[$hash] = true;
            $novos[] = $candidato;
        }

        return $novos;
    }
}
