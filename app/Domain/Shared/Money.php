<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Value object monetário do motor financeiro.
 *
 * Regras invioláveis:
 * - Dinheiro é SEMPRE centavos inteiros (BIGINT). Nunca float.
 * - Formatação pt-BR acontece SOMENTE na borda ({@see self::formatBRL()}).
 * - Imutável: toda operação devolve uma nova instância.
 *
 * Convenção de parsing humano (pt-BR): vírgula é o separador decimal e
 * ponto é o separador de milhar.
 */
final class Money
{
    private function __construct(private readonly int $cents) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function fromReais(int $reais): self
    {
        return new self($reais * 100);
    }

    /**
     * Interpreta uma entrada humana em pt-BR ("35 conto", "1.234,56", "R$ 10").
     *
     * @throws InvalidArgumentException quando não há nenhum dígito.
     */
    public static function fromHuman(string $input): self
    {
        // Mantém apenas dígitos, separadores e o sinal.
        $clean = preg_replace('/[^0-9,.\-]/', '', $input) ?? '';

        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');

        if ($clean === '' || ! preg_match('/\d/', $clean)) {
            throw new InvalidArgumentException("Valor monetário inválido: \"{$input}\".");
        }

        if (str_contains($clean, ',')) {
            // Vírgula = decimal; pontos são separadores de milhar.
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (! preg_match('/^\d+\.\d{1,2}$/', $clean)) {
            // Sem vírgula: ponto é milhar ("1.500") — EXCETO um único ponto com
            // 1–2 casas no fim ("10.50" digitado à americana), que é decimal;
            // sem isso "10.50" viraria R$ 1.050,00 (erro de 100×).
            $clean = str_replace('.', '', $clean);
        }

        [$reais, $decimal] = array_pad(explode('.', $clean, 2), 2, '');
        $reais = $reais === '' ? '0' : $reais;
        $decimal = substr(str_pad($decimal, 2, '0'), 0, 2); // completa/trunca para 2 casas

        $cents = ((int) $reais) * 100 + (int) $decimal;

        return new self($negative ? -$cents : $cents);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function times(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    /**
     * Distribui o total em N partes inteiras, sem perder nenhum centavo:
     * o resto é distribuído um a um nas primeiras parcelas.
     *
     * @return array<int, self>
     *
     * @throws InvalidArgumentException quando N < 1.
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException("Número de parcelas deve ser >= 1, recebido {$parts}.");
        }

        $base = intdiv($this->cents, $parts);
        $remainder = $this->cents - $base * $parts; // mesmo sinal do total
        $unit = $remainder <=> 0; // +1, 0 ou -1

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $extra = $i < abs($remainder) ? $unit : 0;
            $result[] = new self($base + $extra);
        }

        return $result;
    }

    /**
     * Formatação pt-BR — usar SOMENTE na borda (exibição).
     */
    public function formatBRL(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $abs = abs($this->cents);
        $reais = intdiv($abs, 100);
        $decimal = $abs % 100;

        $reaisFormatted = number_format($reais, 0, ',', '.');

        return sprintf('%sR$ %s,%02d', $sign, $reaisFormatted, $decimal);
    }

    /**
     * Número pt-BR SEM o símbolo ("1.234,56") — para preencher campo de formulário, onde
     * "R$" seria ruído que o usuário teria de apagar. O que volta daqui é aceito de novo por
     * {@see self::fromHuman()}, fechando o ciclo editar → salvar sem perder centavos.
     */
    public function formatPtBr(): string
    {
        return trim(str_replace('R$', '', $this->formatBRL()));
    }
}
