<?php

declare(strict_types=1);

namespace App\Domain\IA\Consulta;

use App\Ai\Agents\AssistenteDeConsulta;
use App\Domain\IA\Custo\CalculadoraDeCustoIA;
use App\Domain\IA\Custo\RegistrarUsoDeIA;
use App\Domain\IA\Custo\TipoDeUsoIA;
use App\Domain\IA\Custo\UsoDeIA;
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

    public function __construct(
        private readonly GuardPosGeracao $guard,
        private readonly RegistrarUsoDeIA $registrarUso,
        private readonly CalculadoraDeCustoIA $custo,
    ) {}

    public function responder(User $user, string $pergunta): RespostaDaConsulta
    {
        $coletor = new ColetorDeConsultas;
        $agente = new AssistenteDeConsulta($user, $coletor);

        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS; $tentativa++) {
            $coletor->limpar();

            $inicio = microtime(true);
            $resposta = $agente->prompt($pergunta);
            $this->registrarUsoDaChamada($user, $resposta, $inicio);

            $texto = trim($resposta->text);
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

    /**
     * Registra UMA chamada de IA do chat em ai_usage_log (doc 02 §3.6). Cada geração é uma
     * chamada (logo, um custo): regenerar pelo guard gera mais de uma linha. Só metadados —
     * tokens, custo estimado, latência —, nunca o conteúdo da mensagem.
     */
    private function registrarUsoDaChamada(User $user, object $resposta, float $inicio): void
    {
        $provider = $resposta->meta->provider ?? config('ai.default');
        $model = $resposta->meta->model ?? '';
        $tokensEntrada = $resposta->usage->promptTokens;
        $tokensSaida = $resposta->usage->completionTokens;

        $this->registrarUso->registrar(new UsoDeIA(
            provider: $provider,
            model: $model,
            tokensEntrada: $tokensEntrada,
            tokensSaida: $tokensSaida,
            custoEstimadoCents: $this->custo->centavos($provider, $model, $tokensEntrada, $tokensSaida),
            latenciaMs: (int) round((microtime(true) - $inicio) * 1000),
            tipo: TipoDeUsoIA::MENSAGEM,
            userId: $user->id,
        ));
    }
}
