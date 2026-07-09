<?php

declare(strict_types=1);

namespace App\Domain\IA\Rotacao;

use App\Domain\Shared\Clock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Rotação de provedores de IA em fila LRU + cooldown (spec 04c).
 *
 * Distribui as chamadas entre os free tiers configurados: o menos-recentemente-usado é a
 * cabeça (a escolha); quem falha/estoura limite fica benchado por `cooldown` segundos. A
 * rotação é TRANSPARENTE à SDK — apenas REORDENA a lista que os agentes expõem em
 * `provider()` (regra inviolável 8: nunca cria cliente HTTP nem chama provedor direto).
 *
 * Estado compartilhado entre os contêineres `app` e `worker` no cache (store
 * `ai.rotacao.store`); toda mutação da fila ocorre sob `Cache::lock` (atômica). O "agora"
 * do TTL de cooldown vem de um {@see Clock} injetado, para os testes serem determinísticos.
 */
final class RotacionadorDeProvedores
{
    private const CHAVE_FILA = 'ai:rotacao:fila';

    private const CHAVE_LOCK = 'ai:rotacao:lock';

    private const PREFIXO_COOLDOWN = 'ai:rotacao:cooldown:';

    /**
     * @param  Repository  $cache  store compartilhado (config `ai.rotacao.store`).
     * @param  array<string, mixed>  $config  bloco `config('ai.rotacao')`.
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly Clock $clock,
        private readonly array $config,
    ) {}

    /**
     * Ordem de provedores para ESTA chamada: `[escolha, ...cauda de failover]`.
     *
     * Sob lock: filtra por chave presente (C5) e cooldown ativo (C3), avança a fila LRU
     * (a escolha vai para o fim) e devolve a lista inteira (C2 — a cauda é a cadeia de
     * failover da SDK). Nunca vazia: todos benchados → cai para o pool completo (C6).
     *
     * @return array<int, string>
     */
    public function ordenar(): array
    {
        $base = $this->poolComChave();

        // Degradação máxima: nenhum provedor tem chave → devolve o pool bruto configurado
        // (nunca deixa a chamada órfã; a SDK ainda tentará o que houver).
        if ($base === []) {
            return $this->pool();
        }

        return $this->cache->lock(self::CHAVE_LOCK, $this->lockTtl())->block(
            $this->lockTtl(),
            function () use ($base): array {
                $fila = $this->filaReconciliada($base);
                $disponiveis = array_values(array_filter(
                    $fila,
                    fn (string $p): bool => ! $this->emCooldown($p),
                ));

                // C6: todos em cooldown → ignora os cooldowns e devolve o pool completo
                // (com chave), registrando um aviso. Degrada para o failover, nunca p/ "sem IA".
                if ($disponiveis === []) {
                    Log::warning('IA: rotação sem provedor elegível — todos em cooldown; caindo para o pool completo', [
                        'pool' => $base,
                    ]);

                    return $base;
                }

                $escolha = $disponiveis[0];
                $this->avancarFila($fila, $escolha);

                return $disponiveis;
            },
        );
    }

    /** Bencha um provedor por `cooldown` segundos (chamado no failover, C7). */
    public function penalizar(string $provedor, string $motivo = ''): void
    {
        $cooldown = $this->cooldown();
        $ate = $this->clock->now()->addSeconds($cooldown)->getTimestamp();

        // Valor = instante-limite (comparado pelo clock injetado); TTL do cache = backstop
        // que remove a chave sozinho (alinhado a C4). O `motivo` (classe da exceção) NÃO é
        // persistido — pode carregar payload (barreira §4 / LogarFailoverDeIA).
        $this->cache->put(self::PREFIXO_COOLDOWN.$provedor, $ate, $cooldown);
    }

    /** true se o provedor está em cooldown agora (usa o clock injetado). */
    public function emCooldown(string $provedor): bool
    {
        $ate = $this->cache->get(self::PREFIXO_COOLDOWN.$provedor);

        return $ate !== null && $this->clock->now()->getTimestamp() < (int) $ate;
    }

    /**
     * Fila persistida reconciliada com o pool atual (com chave): preserva a ordem LRU
     * gravada, descarta quem saiu do pool e anexa (ao fim) quem entrou.
     *
     * @param  array<int, string>  $base
     * @return array<int, string>
     */
    private function filaReconciliada(array $base): array
    {
        $armazenada = $this->cache->get(self::CHAVE_FILA, []);
        $armazenada = is_array($armazenada) ? $armazenada : [];

        $fila = array_values(array_filter(
            $armazenada,
            fn (string $p): bool => in_array($p, $base, true),
        ));

        foreach ($base as $p) {
            if (! in_array($p, $fila, true)) {
                $fila[] = $p;
            }
        }

        return $fila;
    }

    /**
     * Move a escolha para o fim da fila (LRU) e persiste. Chamado dentro do lock.
     *
     * @param  array<int, string>  $fila
     */
    private function avancarFila(array $fila, string $escolha): void
    {
        $fila = array_values(array_filter($fila, fn (string $p): bool => $p !== $escolha));
        $fila[] = $escolha;

        $this->cache->forever(self::CHAVE_FILA, $fila);
    }

    /**
     * Pool configurado filtrado por credencial presente (C5): só entra na rotação o
     * provedor com `config("ai.providers.$nome.key")` não vazio. NUNCA loga a chave.
     *
     * @return array<int, string>
     */
    private function poolComChave(): array
    {
        return array_values(array_filter(
            $this->pool(),
            fn (string $p): bool => filled(config("ai.providers.$p.key")),
        ));
    }

    /** @return array<int, string> */
    private function pool(): array
    {
        /** @var array<int, string> $pool */
        $pool = $this->config['pool'] ?? [];

        return array_values($pool);
    }

    private function cooldown(): int
    {
        return (int) ($this->config['cooldown'] ?? 60);
    }

    private function lockTtl(): int
    {
        return (int) ($this->config['lock_ttl'] ?? 5);
    }
}
