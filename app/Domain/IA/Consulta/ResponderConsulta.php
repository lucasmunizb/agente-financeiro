<?php

declare(strict_types=1);

namespace App\Domain\IA\Consulta;

use App\Ai\Agents\AssistenteDeConsulta;
use App\Domain\IA\Guard\GuardPosGeracao;
use App\Models\User;

/**
 * Orquestra o chat financeiro de consulta (Bloco 6, F8). Liga o agente (que decide e
 * chama as tools), o coletor (conjunto-verdade do que as tools calcularam) e o guard
 * pós-geração (barreira 4): valida que TODO número/data do texto redigido existe no
 * payload calculado.
 *
 * Em divergência, regenera (a IA tenta de novo, com as tools de novo); esgotadas as
 * tentativas, devolve um fallback seguro — uma mensagem SEM números — em vez de arriscar
 * um valor alucinado (regra inviolável 4). Sucesso → texto + fontes (barreira 5). A
 * apresentação ao usuário (bot/web) é etapa separada e posterior.
 */
final class ResponderConsulta
{
    /** Quantas gerações tentar antes de cair no fallback. */
    private const MAX_TENTATIVAS = 2;

    /** Fallback seguro: não cita nenhum valor nem data. */
    private const FALLBACK = 'Não consegui confirmar os números com segurança agora. Pode reformular a pergunta?';

    public function __construct(private readonly GuardPosGeracao $guard) {}

    public function responder(User $user, string $pergunta): RespostaDaConsulta
    {
        $coletor = new ColetorDeConsultas;
        $agente = new AssistenteDeConsulta($user, $coletor);

        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS; $tentativa++) {
            $coletor->limpar();

            $texto = trim($agente->prompt($pergunta)->text);
            $veredito = $this->guard->validar($texto, $coletor->payloadCombinado());

            if ($veredito->aprovado) {
                return new RespostaDaConsulta(
                    texto: $texto,
                    aprovado: true,
                    fontes: $coletor->fontes(),
                    tentativas: $tentativa,
                );
            }
        }

        return new RespostaDaConsulta(
            texto: self::FALLBACK,
            aprovado: false,
            fontes: $coletor->fontes(),
            tentativas: self::MAX_TENTATIVAS,
        );
    }
}
