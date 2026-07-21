<?php

declare(strict_types=1);

namespace App\Domain\IA;

use App\Domain\Shared\Normalizador;

/**
 * Extração CRUA e possivelmente INCOMPLETA de um gasto, acumulada entre turnos de
 * esclarecimento (spec 04 §3.3; bug do slot-filling multi-turno). Cada campo é opcional:
 * o extrator preenche o que a mensagem trouxer e deixa null o resto (barreira 1). A
 * IA nunca calcula nem normaliza (regra 4) — valor e data seguem como TEXTO cru.
 *
 * `mesclar()` é a acumulação determinística entre mensagens: o campo NÃO-NULO do turno
 * novo vence o anterior (preenche o que faltava e permite correção); null não apaga o
 * que já se sabia. `faltantes()` aplica a MESMA regra de obrigatoriedade do extrator
 * (descrição/valor/forma; crédito exige cartão) para decidir se ainda falta esclarecer.
 *
 * `recorrenciaDiaTexto` (spec 10c) é um slot como os outros — NÃO obrigatório: preenchido
 * significa "isto repete todo mês no dia X" (vira recorrência em vez de gasto avulso). Ele
 * precisa sobreviver ao multi-turno: o usuário diz "todo dia 10" no 1º turno e completa
 * valor/forma no 2º, quando o extrator já não repete o dia.
 *
 * `pago`/`dataPagamentoTexto` (decisão 2026-07-21) respondem "isto já foi pago?". Fora de
 * cartão o slot é OBRIGATÓRIO — sem ele o bot PERGUNTA em vez de assumir "não pago", que
 * deixaria a conta aberta e poluiria as contas em atraso. `false` é resposta legítima e
 * NÃO pode ser confundido com ausente (por isso `?bool`, e a mescla usa `??`). A data segue
 * como TEXTO cru: quem a resolve no fuso SP é o normalizador (regra 4).
 */
final readonly class GastoParcial
{
    /**
     * Formas em que o pagamento é do próprio usuário (há parcela a quitar na hora). Forma
     * ainda desconhecida ou não suportada NÃO entra: a pergunta "já pagou?" só faz sentido
     * depois de sabermos que não é cartão.
     */
    private const FORMAS_FORA_DE_CARTAO = ['debito', 'pix', 'dinheiro', 'boleto'];

    public function __construct(
        public ?string $descricao,
        public ?string $valorTexto,
        public ?string $formaPagamento,
        public ?string $cartao,
        public ?string $categoria,
        public ?string $dataTexto,
        public ?int $parcelas,
        public ?string $recorrenciaDiaTexto = null,
        public ?bool $pago = null,
        public ?string $dataPagamentoTexto = null,
    ) {}

    /**
     * Acumula o turno novo sobre este: campo não-nulo do novo vence; null preserva o atual.
     */
    public function mesclar(self $novo): self
    {
        return new self(
            descricao: $novo->descricao ?? $this->descricao,
            valorTexto: $novo->valorTexto ?? $this->valorTexto,
            formaPagamento: $novo->formaPagamento ?? $this->formaPagamento,
            cartao: $novo->cartao ?? $this->cartao,
            categoria: $novo->categoria ?? $this->categoria,
            dataTexto: $novo->dataTexto ?? $this->dataTexto,
            parcelas: $novo->parcelas ?? $this->parcelas,
            recorrenciaDiaTexto: $novo->recorrenciaDiaTexto ?? $this->recorrenciaDiaTexto,
            pago: $novo->pago ?? $this->pago,
            dataPagamentoTexto: $novo->dataPagamentoTexto ?? $this->dataPagamentoTexto,
        );
    }

    /**
     * Campos obrigatórios ausentes, nos nomes do schema (a etapa de esclarecimento os usa
     * para perguntar). Mesma regra do extrator: crédito sem cartão também falta.
     *
     * @return list<string>
     */
    public function faltantes(): array
    {
        $faltantes = [];

        if ($this->descricao === null) {
            $faltantes[] = 'descricao';
        }

        if ($this->valorTexto === null) {
            $faltantes[] = 'valor';
        }

        if ($this->formaPagamento === null) {
            $faltantes[] = 'forma_pagamento';
        }

        // Compara a forma NORMALIZADA: a IA às vezes devolve "Crédito"/"CREDITO" e a
        // barreira do cartão não pode depender da caixa/acento do que o modelo escreveu
        // (mesma causa raiz do incidente de 2026-07-16 no lado da normalização).
        if (Normalizador::texto((string) $this->formaPagamento) === 'credito' && $this->cartao === null) {
            $faltantes[] = 'cartao';
        }

        if ($this->faltaSaberSePagou()) {
            $faltantes[] = 'pago';
        }

        return $faltantes;
    }

    /**
     * "Já foi pago?" só é pergunta quando há uma PARCELA para pagar agora e o pagamento é
     * do próprio usuário: fora de cartão. Crédito quita pela fatura (§4.3) e recorrência é
     * MOLDE — o lançamento nasce depois, no dia. Enquanto a forma for desconhecida não dá
     * para saber em qual caso estamos: não perguntamos junto, senão o bot pediria "já pagou?"
     * de uma compra que pode ser no cartão.
     */
    private function faltaSaberSePagou(): bool
    {
        if ($this->pago !== null || $this->recorrenciaDiaTexto !== null) {
            return false;
        }

        return in_array(Normalizador::texto((string) $this->formaPagamento), self::FORMAS_FORA_DE_CARTAO, true);
    }

    public function completo(): bool
    {
        return $this->faltantes() === [];
    }

    /**
     * DTO cru pronto para a normalização determinística. Só chame quando `completo()`.
     */
    public function paraExtraido(): GastoExtraido
    {
        return new GastoExtraido(
            descricao: (string) $this->descricao,
            valorTexto: (string) $this->valorTexto,
            formaPagamento: (string) $this->formaPagamento,
            cartao: $this->cartao,
            categoria: $this->categoria,
            dataTexto: $this->dataTexto,
            parcelas: $this->parcelas,
            recorrenciaDiaTexto: $this->recorrenciaDiaTexto,
            pago: $this->pago,
            dataPagamentoTexto: $this->dataPagamentoTexto,
        );
    }
}
