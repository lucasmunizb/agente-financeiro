<?php

declare(strict_types=1);

namespace App\Domain\IA;

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
 */
final readonly class GastoParcial
{
    public function __construct(
        public ?string $descricao,
        public ?string $valorTexto,
        public ?string $formaPagamento,
        public ?string $cartao,
        public ?string $categoria,
        public ?string $dataTexto,
        public ?int $parcelas,
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

        if ($this->formaPagamento === 'credito' && $this->cartao === null) {
            $faltantes[] = 'cartao';
        }

        return $faltantes;
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
        );
    }
}
