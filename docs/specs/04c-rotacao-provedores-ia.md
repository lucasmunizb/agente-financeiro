# Spec 04c — Rotação de provedores de IA (fila LRU + cooldown)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Infra de IA · transversal aos agentes (pós-Bloco 04) |
| **Status** | ✅ Concluído |
| **Depende de** | [[spec-04]] (IA de interpretação — trait `UsaFailoverDeProvedores`, `config/ai.php`) |
| **Habilita** | Uso sustentável de 4 tiers grátis (groq · gemini · anthropic · openai) sem estourar rate limit de um só |
| **Fonte de verdade** | [`docs/02-governanca-ia.md`](../02-governanca-ia.md) §3.6 (custo/failover) · regra inviolável 8 (toda IA via Laravel AI SDK) |
| **Regras críticas** | 8 (IA via SDK) · 5 (fuso America/Sao_Paulo, aqui só para TTL de cooldown) · 9 (tudo em contêiner) · 10 (chaves via env/Secrets) |

---

## 1. Objetivo

Distribuir as chamadas de IA entre **vários provedores de free tier**, rotacionando em
**fila LRU** (o provedor recém-usado vai para o fim da fila; o menos-recentemente-usado é
escolhido primeiro) e **benchando** por um período (**cooldown**) quem falhar ou estourar
rate limit — para maximizar a cota grátis somada dos 4 provedores sem sobrecarregar
nenhum. A rotação é **transparente** aos agentes: só reordena, por chamada, a lista que a
SDK já consome em `provider()`, mantendo o **failover nativo** como rede de segurança.

## 2. Escopo

- **Inclui (backend desta etapa):**
  - Serviço de domínio `RotacionadorDeProvedores` — política **fila FIFO/LRU + cooldown**,
    com estado **compartilhado** (cache/lock) entre os contêineres `app` e `worker`.
  - Bloco `rotacao` em `config/ai.php` (habilitar/desabilitar, pool, cooldown, store, lock).
  - Integração via `provider()`: quando `ai.rotacao.enabled`, o trait devolve a **ordem
    rotacionada** (cabeça = escolha; cauda = cadeia de failover); senão, comportamento
    atual (`config('ai.failover')`) — **retrocompatível**.
  - Penalização por falha: listener do evento `AgentFailedOver` aplica cooldown ao provedor
    que caiu (complementa o `LogarFailoverDeIA` já existente, sem substituí-lo).
  - Injeção de relógio (Carbon) para TTL de cooldown **determinístico** nos testes.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - Contagem de cota por janela (RPM/RPD por provedor) para escolher "quem tem mais folga":
    fica registrada como **evolução** (§4 — questão aberta), não no MVP.
  - Troca do store de `database` para **Redis** (rotação atômica mais barata): entra quando
    o roadmap subir Redis/Horizon (ver [`docs/11-devops.md`](../11-devops.md)).
  - Qualquer tela/relatório de uso por provedor (frontend, regra 3).
  - Escolha de **modelo** por provedor — continua com `#[UseCheapestModel]`/SDK; a rotação
    é ortogonal ao modelo (o atributo `#[Model]` é ignorado no failover, ver §4).

## 3. Cenários de aceite (Given-When-Then)

Pool de exemplo em ordem de configuração: `[groq, gemini, anthropic, openai]`.

- **C1 (rotação FIFO) — Dado** o pool cheio e nenhum cooldown, **Quando** `ordenar()` é
  chamado 4× seguidas, **Então** a **cabeça** de cada retorno é, em ordem,
  `groq, gemini, anthropic, openai` (cada escolha vai para o fim da fila) — e no 5º volta a
  `groq`.
- **C2 (a lista inteira é devolvida) — Dado** o pool cheio, **Quando** `ordenar()` retorna,
  **Então** devolve **todos** os provedores disponíveis (não só a cabeça): a cauda é a
  cadeia de failover que a SDK usa se a cabeça cair.
- **C3 (cooldown exclui da cabeça) — Dado** que `groq` foi penalizado agora com cooldown de
  60 s, **Quando** `ordenar()` é chamado, **Então** `groq` **não** aparece entre os
  disponíveis (nem como cabeça nem na cauda) enquanto o cooldown não expirar; a cabeça é o
  próximo elegível.
- **C4 (cooldown expira) — Dado** `groq` benchado às 12:00:00 por 60 s, **Quando** o relógio
  avança para 12:01:01 e `ordenar()` é chamado, **Então** `groq` volta a ser elegível.
- **C5 (sem chave → fora do pool) — Dado** que `OPENAI_API_KEY` está vazio, **Quando**
  `ordenar()` monta a lista, **Então** `openai` é **omitido** (nunca é oferecido à SDK um
  provedor sem credencial).
- **C6 (nunca vazio) — Dado** que **todos** os provedores do pool estão em cooldown,
  **Quando** `ordenar()` é chamado, **Então** devolve o pool completo (com chave)
  **ignorando** os cooldowns — nunca deixa a chamada sem provedor — e registra um `warning`.
- **C7 (falha → cooldown) — Dado** um agente rodando com a rotação ligada, **Quando** a SDK
  emite `AgentFailedOver` para `groq`, **Então** o listener chama `penalizar('groq', …)` e
  `groq` fica benchado pela duração configurada, saindo das próximas rotações.
- **C8 (desligado = comportamento atual) — Dado** `AI_ROTACAO_ENABLED=false`, **Quando**
  `provider()` é chamado, **Então** devolve `config('ai.failover')` **sem** tocar o
  rotacionador (retrocompatibilidade — spec 04 intacta).
- **C9 (concorrência) — Dado** `app` e `worker` chamando `ordenar()` "ao mesmo tempo",
  **Quando** ambos avançam a fila, **Então** a mutação da fila é **atômica** (sob lock): não
  há perda de rotação nem estado corrompido; no pior caso um espera o lock.

## 4. Barreiras e invariantes

- **Regra 8 (IA via SDK):** a rotação **não** cria cliente HTTP próprio nem chama provedor
  direto. Ela apenas **reordena** a lista devolvida por `provider()`; quem executa a chamada
  e faz o failover é a Laravel AI SDK. O guard determinístico (regra 4, IA nunca calcula
  dinheiro) é **ortogonal** e permanece nas Tools/domínio.
- **Failover ignora `#[Model]`:** ao rotacionar entre provedores diferentes, o modelo é
  resolvido por `#[UseCheapestModel]`/padrões da SDK, **não** por `#[Model]` (comportamento
  conhecido — ver memória `ia-economia-tokens`). `providerOptions` por provedor continua
  válido (ex.: `reasoning_effort: low` só na Groq, via `UsaRaciocinioBaixoNaGroq`).
- **Determinismo de "agora":** o TTL de cooldown depende do relógio → o serviço recebe um
  **`Clock`/Carbon injetável** e os testes usam `Carbon::setTestNow()`. Fuso base
  `America/Sao_Paulo` (regra 5), embora aqui só importe a diferença de instantes.
- **Estado compartilhado e atômico:** `app` e `worker` são contêineres distintos → o estado
  (fila + cooldowns) vive no **cache store compartilhado** (`database`, o mesmo Postgres) e
  toda mutação da fila ocorre sob **`Cache::lock`** (regra 9: nada de estado só-em-memória
  de um processo). O `queue:work` mantém código em memória → mudança de binding exige
  `up -d --force-recreate worker` (memória `worker-code-reload`).
- **Sem credencial em claro / regra 10:** as chaves continuam em env (dev) / Docker Secrets
  (prod). A rotação **lê** `config("ai.providers.$nome.key")` só para decidir elegibilidade;
  nunca loga a chave. O listener de penalização segue o `LogarFailoverDeIA`: loga
  **classe** da exceção, **nunca** a mensagem (pode carregar payload).
- **Nunca deixa a chamada órfã (C6):** disponibilidade zero → cai para o pool completo. A
  rotação **degrada** para o failover estático, nunca para "sem IA".
- **Detecção de rate limit é best-effort:** qualquer `AgentFailedOver` aplica o cooldown
  base (privacidade > precisão — não inspeciona a mensagem). Se a exceção for de um tipo
  reconhecível de 429/limite, pode-se aplicar cooldown **mais longo** (opcional, por classe).
  Distinguir 429 de outras falhas com precisão fica como **questão aberta**.

> **Questões em aberto (decidir antes de evoluir, não bloqueiam o MVP):**
> (a) contagem de cota por janela (RPM/RPD) para política "maior folga" — hoje é FIFO puro;
> (b) cooldown diferenciado por código HTTP (429 longo × 5xx curto);
> (c) registrar o provedor **efetivamente usado** (via evento de sucesso da SDK, se
> existir) para uma rotação por uso-real em vez de por escolha-no-pick.

## 5. Modelo de dados

**Nenhuma tabela nova.** O estado é efêmero e vive no **cache** (store `database` → tabela
`cache` já existente do driver de cache/fila). Chaves:

- `ai:rotacao:fila` → lista ordenada de nomes de provedor (a fila FIFO).
- `ai:rotacao:cooldown:<provedor>` → chave com TTL; presente = benchado (o próprio TTL do
  cache expira o cooldown, alinhado a C4).
- `ai:rotacao:lock` → `Cache::lock` para a seção crítica de `ordenar()`/rotação (C9).

## 6. Contratos do domínio

`app/Domain/IA/Rotacao/RotacionadorDeProvedores.php`

```php
final class RotacionadorDeProvedores
{
    public function __construct(
        private readonly Repository $cache,   // store 'ai.rotacao.store'
        private readonly ClockInterface $clock,
        private readonly array $config,       // config('ai.rotacao')
    ) {}

    /**
     * Ordem de provedores para ESTA chamada: [escolha, ...cauda de failover].
     * Sob lock: filtra por chave presente e cooldown ativo (LRU/FIFO), avança a fila
     * (a escolha vai para o fim) e devolve a lista. Nunca vazia (C6).
     *
     * @return array<int, string>
     */
    public function ordenar(): array;

    /** Bencha um provedor por `cooldown` segundos (chamado no failover, C7). */
    public function penalizar(string $provedor, string $motivo = ''): void;

    /** true se o provedor está em cooldown agora (usa o clock injetado). */
    public function emCooldown(string $provedor): bool;
}
```

`app/Ai/Concerns/UsaFailoverDeProvedores.php` (**alterado**): `provider()` passa a
consultar o rotacionador quando `config('ai.rotacao.enabled')`; senão devolve
`config('ai.failover')` como hoje (C8). `timeout()` permanece.

```php
public function provider(): array
{
    return config('ai.rotacao.enabled')
        ? app(RotacionadorDeProvedores::class)->ordenar()
        : config('ai.failover');
}
```

`app/Listeners/PenalizarProvedorNaRotacao.php` (**novo**): ouve `AgentFailedOver`, chama
`penalizar($evento->provider->name(), $evento->exception::class)`. Registrado ao lado de
`LogarFailoverDeIA` no `AppServiceProvider::boot()`.

`ClockInterface` / binding de relógio: um contrato mínimo (`now(): CarbonImmutable`) com
implementação real e substituível nos testes — ou reutilizar o helper de relógio já usado
no domínio financeiro, se houver. **Não** usar `now()` global direto no serviço (quebra a
determinismo dos testes de TTL).

## 7. Plano de testes (test-first — devem falhar primeiro)

1. **Unitários do domínio** (`tests/Unit/IA/RotacionadorDeProvedoresTest.php`), com cache
   `array` e `Carbon::setTestNow()`:
   - C1 rotação FIFO ao longo de 5 chamadas (volta ao início);
   - C2 devolve a lista inteira, não só a cabeça;
   - C3 provedor penalizado sai dos disponíveis;
   - C4 cooldown expira quando o relógio avança além do TTL;
   - C5 provedor sem chave é omitido;
   - C6 todos em cooldown → pool completo + `Log::spy()` recebe `warning`;
   - `penalizar()` grava cooldown com o TTL configurado; `emCooldown()` reflete o clock.
2. **Contrato/integração** (`tests/Feature/IA/…`), com os **fakes da Laravel AI SDK**:
   - C7 emitir `AgentFailedOver` → o listener bencha o provedor (assertar via
     `emCooldown()`); log sem a mensagem da exceção (só a classe);
   - C8 `AI_ROTACAO_ENABLED=false` → `provider()` == `config('ai.failover')` (rotacionador
     não é resolvido — `spy` sem interações);
   - C9 (concorrência) simular duas chamadas sob o mesmo lock e assertar que a fila avançou
     de forma consistente (uma rotação por chamada, sem perda).

> Cada item só é "feito" com **testes verdes e cobertura**. IA sempre com os **fakes da
> SDK** (offline, determinístico) — nunca chamar provedor real no teste.

## 8. Backend agora · Frontend depois

| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| `RotacionadorDeProvedores` + testes | Nenhum (infra pura, sem tela) |
| Bloco `config/ai.php → rotacao` + env | — |
| `provider()` no trait consulta a rotação | — |
| Listener `PenalizarProvedorNaRotacao` | — |
| `ClockInterface` injetável p/ TTL | — |

Eventual painel de "uso por provedor" seria frontend + telemetria — **fora** deste spec.

## 9. Definition of Done

- [x] Cenários C1–C9 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (atômico sob lock; nunca vazio; sem chave/mensagem logada;
      clock injetado; regra 8 — só reordena, não chama provedor).
- [x] Retrocompatível: com `AI_ROTACAO_ENABLED=false` o comportamento é idêntico à spec 04.
- [x] `config/ai.php` documenta o bloco `rotacao`; `.env.example` traz as novas chaves.
- [x] Sem segredo/chave/dado sensível persistido ou commitado.
- [ ] Commit local atômico, em português (ex.: `IA: rotação de provedores em fila LRU + cooldown`) — **feito à mão pelo usuário**.
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos

- **Status:** ✅ Concluído (test-first, 12 cenários verdes; suíte completa 768 passando).
- **Entregue (backend):**
  - `app/Domain/IA/Rotacao/RotacionadorDeProvedores.php` — fila LRU + cooldown, estado no
    cache compartilhado sob `Cache::lock`; `ordenar()` / `penalizar()` / `emCooldown()`.
  - `app/Domain/Shared/Clock.php` + `app/Domain/Shared/SystemClock.php` — relógio injetável
    (`now(): CarbonImmutable`, respeita `Carbon::setTestNow()`).
  - `app/Listeners/PenalizarProvedorNaRotacao.php` — ouve `AgentFailedOver`, bencha o
    provedor (registrado ao lado de `LogarFailoverDeIA` no `AppServiceProvider::boot()`);
    só age com a rotação ligada.
  - `app/Ai/Concerns/UsaFailoverDeProvedores.php` — `provider()` consulta a rotação quando
    `ai.rotacao.enabled`; senão devolve `config('ai.failover')` (C8, retrocompatível).
  - `config/ai.php` — bloco `rotacao`; `.env.example` — novas chaves (`AI_ROTACAO_*`, free tiers).
  - Bindings em `app/Providers/AppServiceProvider.php` (`Clock`→`SystemClock`; singleton do
    `RotacionadorDeProvedores` amarrado ao store `ai.rotacao.store`).
- **Testes:** `tests/Unit/AI/RotacionadorDeProvedoresTest.php` (C1–C6 + `penalizar`/`emCooldown`
  + persistência da fila) · `tests/Feature/AI/RotacaoDeProvedoresTest.php` (C7, C8, C8b, C9).
  Rodar: `make test` (ou `docker compose exec -e APP_ENV=testing -e DB_DATABASE=financeiro_test app php artisan test`).
- **Nota de layout:** os testes ficam em `tests/{Unit,Feature}/AI/` (convenção real do repo),
  não `IA/` como o texto do §7 sugeria.
- **Ligar em runtime:** `AI_ROTACAO_ENABLED=true` (+ chaves dos free tiers) e
  `docker compose up -d --force-recreate app worker` (memórias `docker-env-file-up-d` e
  `worker-code-reload`).
- **Adiado para:** cota por janela / Redis / cooldown por HTTP (§4, questões abertas).
- **Decisões de regra tomadas:** política **FIFO/LRU + cooldown** (escolha do usuário);
  estado no cache `database` sob `Cache::lock`; penalização por `AgentFailedOver`; cooldown
  guardado como instante-limite (comparado pelo clock) com TTL do cache como backstop.

---

## 11. Configuração (como ligar e ajustar)

### 11.1 Bloco em `config/ai.php`

Adicionar após `request_timeout` (mesmo estilo dos blocos existentes):

```php
/*
|----------------------------------------------------------------------
| Rotação de provedores (fila LRU + cooldown) — spec 04c
|----------------------------------------------------------------------
| Distribui as chamadas entre vários free tiers, rotacionando em fila e
| benchando quem falha por `cooldown` segundos. Desligado → usa `failover`.
*/
'rotacao' => [
    'enabled'  => (bool) env('AI_ROTACAO_ENABLED', false),
    'pool'     => array_values(array_filter(array_map('trim', explode(
        ',', env('AI_ROTACAO_POOL', 'groq,gemini,anthropic,openai')
    )))),
    'cooldown' => (int) env('AI_ROTACAO_COOLDOWN', 60),   // segundos benched após falha
    'store'    => env('AI_ROTACAO_STORE', env('CACHE_STORE', 'database')),
    'lock_ttl' => (int) env('AI_ROTACAO_LOCK_TTL', 5),    // segundos de espera pelo lock
],
```

### 11.2 Variáveis de ambiente (`.env` dev / Docker Secrets prod)

| Env | Padrão | O que faz |
|---|---|---|
| `AI_ROTACAO_ENABLED` | `false` | Liga a rotação. `false` = comportamento da spec 04 (failover estático). |
| `AI_ROTACAO_POOL` | `groq,gemini,anthropic,openai` | Ordem inicial da fila. Só entram os que têm chave. |
| `AI_ROTACAO_COOLDOWN` | `60` | Segundos que um provedor fica benchado após falhar/estourar limite. |
| `AI_ROTACAO_STORE` | `CACHE_STORE` (`database`) | Store do estado compartilhado app↔worker. |
| `AI_ROTACAO_LOCK_TTL` | `5` | Espera máxima pelo lock da seção crítica. |
| `GROQ_API_KEY` | — | Chave free da Groq (console.groq.com). Sem ela, `groq` sai do pool. |
| `GEMINI_API_KEY` | — | Chave free do Gemini (aistudio.google.com). |
| `ANTHROPIC_API_KEY` | — | Chave da Anthropic (créditos free). |
| `OPENAI_API_KEY` | — | Chave free/created da OpenAI. |

> As chaves de provedor **já existem** em `config/ai.php`. A rotação apenas passa a
> **exigir** que a chave esteja presente para o provedor participar (C5).

### 11.3 Aplicar a configuração (contêiner — regra 9)

- Editar `.env` **não** basta com `restart`: o `env_file` só relê em
  `docker compose up -d` (memória `docker-env-file-up-d`).
- O `worker` mantém o código/binding em memória → após mexer no serviço/binding:
  `docker compose up -d --force-recreate worker` (memória `worker-code-reload`).
- Rodar os testes: `make test` (com `APP_ENV=testing` e `DB_DATABASE=financeiro_test`,
  conforme memórias `test-env-appenv-testing` e `test-db-separado`).

### 11.4 Produção (Swarm + Secrets — regra 10)

As 4 chaves entram como **Docker Secrets** (`*_FILE`), nunca em `.env`. O bloco `rotacao`
lê só flags/números por env (não sensíveis). O store `database` compartilha o estado entre
réplicas de `app`/`worker` sem infra nova; ao subir Redis, trocar `AI_ROTACAO_STORE=redis`
para lock/rotação atômicos mais baratos (§2, fora do MVP).
