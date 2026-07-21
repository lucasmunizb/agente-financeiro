<?php

declare(strict_types=1);

namespace App\Domain\Pagamento;

use Carbon\CarbonImmutable;

/**
 * Uma conta que o usuário ainda pode marcar como paga — candidata do "paguei a luz" (bot).
 *
 * Existem duas fontes de conta a pagar no app e elas não compartilham tabela: a PARCELA de um
 * lançamento e a OCORRÊNCIA de uma recorrência (spec 12 — recorrência nunca vira transaction).
 * Este DTO é o denominador comum, para que a conversa e a confirmação não precisem saber de
 * qual das duas se trata: quem sabe é {@see PagarContaPagavel}, no momento de gravar.
 *
 * Valor SEMPRE em centavos (regra 5) e já vindo do banco — a IA nunca preenche estes campos,
 * só o termo que originou a busca.
 */
final readonly class ContaPagavel
{
    public const TIPO_PARCELA = 'parcela';

    public const TIPO_OCORRENCIA = 'ocorrencia';

    public function __construct(
        public string $tipo,
        public int $id,
        public string $descricao,
        public int $cents,
        public CarbonImmutable $vencimento,
    ) {}

    /**
     * Forma serializável para a fila de confirmação pendente (o pendente atravessa mensagens
     * e precisa sobreviver a um round-trip em JSON sem perder o instante nem os centavos).
     *
     * @return array<string, mixed>
     */
    public function paraArray(): array
    {
        return [
            'tipo' => $this->tipo,
            'id' => $this->id,
            'descricao' => $this->descricao,
            'cents' => $this->cents,
            'vencimento' => $this->vencimento->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    public static function deArray(array $dados): self
    {
        return new self(
            tipo: (string) $dados['tipo'],
            id: (int) $dados['id'],
            descricao: (string) $dados['descricao'],
            cents: (int) $dados['cents'],
            // Data de CALENDÁRIO (não instante): reidrata direto no fuso do app, sem
            // setTimezone — converter aqui deslocaria o dia.
            vencimento: CarbonImmutable::parse((string) $dados['vencimento'], 'America/Sao_Paulo'),
        );
    }
}
