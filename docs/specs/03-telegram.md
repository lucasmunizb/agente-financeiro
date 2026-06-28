# Spec 03 — Telegram (vínculo, webhook, roteamento)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 3 · F4 |
| **Status** | ✅ Concluído (backend) — frontend movido para [[spec-FE-frontend-stitch]] |
| **Depende de** | [[spec-00-fundacoes-devops]] |
| **Habilita** | [[spec-04-ia-interpretacao]] · [[spec-05-chat-financeiro]] |
| **Fonte de verdade** | seção 7 do escopo · [`docs/06-telegram.md`](../06-telegram.md) · [`docs/04-modelo-dados.md`](../04-modelo-dados.md) |
| **Regras críticas** | 2 (test-first) · 3 (frontend separado) · 8 (IA só via SDK — e **nenhuma IA** nesta etapa) |

---

## 1. Objetivo
Receber uma mensagem do Telegram **com o usuário já identificado**, de forma **segura**
(segredo do webhook + token de vínculo só em hash) e **idempotente** (dedupe por
`update_id`), classificando deterministicamente a intenção e entregando-a a um ponto de
extensão — sem que nenhuma regra de negócio nem IA rode neste adaptador.

## 2. Escopo
- **Inclui (backend desta etapa):**
  - **Vínculo seguro** (doc 06 §1): geração de token único/aleatório com expiração curta
    (só o **hash** persiste), consumo do token capturando `telegram_user_id` + telefone e
    ativação de **um único vínculo ativo por conta**.
  - **Autenticação em toda mensagem**: `telegram_user_id` → usuário do vínculo ativo.
  - **Webhook** (doc 06 §3): validação do segredo no header, **CSRF isento**, **dedupe**
    por `update_id` e resposta **sempre 200**, delegando a um `RoteadorDeMensagem`.
  - **Roteamento determinístico**: classificação da mensagem em
    `registrar/editar/cancelar/buscar/ajuda` ou `DESCONHECIDO` (texto livre), com despacho
    por manipulador de intenção (inertes por ora).
- **Não inclui (outro spec / frontend / pós-MVP):**
  - **Execução** de cada comando = extração via IA + confirmação → [[spec-04-ia-interpretacao]]
    (Bloco 4) e o chat de consulta → [[spec-05-chat-financeiro]].
  - **Mensagens formatadas do bot** (curtas, sem botões) e **vínculo via bot**
    (token + `request_contact`) — **frontend** consolidado em [[spec-FE-frontend-stitch]] (regra 3).
  - Anexos PDF, lembretes/alertas, preferências pelo bot — fora desta etapa / pós-MVP.

## 3. Cenários de aceite (Given-When-Then)
Base dos testes de §7.

- **C1 (vínculo — geração) — Dado** um usuário na web **Quando** gera um token de vínculo
  **Então** cria uma linha `pendente` guardando **apenas o hash** (sha-256) do token + a
  expiração; o token em claro nunca é persistido; **gerar de novo revoga a pendência
  anterior** (uma pendência por conta).
- **C2 (vínculo — consumo) — Dado** um token `pendente` e não expirado **Quando** o
  vínculo é confirmado com `telegram_user_id` + telefone **Então** a linha vira `ativo`,
  o token é **consumido** (hash nulo) e qualquer vínculo ativo anterior da mesma conta é
  **revogado** (um ativo por conta).
- **C3 (vínculo — borda) — Dado** um token inexistente, **expirado** ou **já consumido**
  **Quando** se tenta confirmar **Então** lança `TokenInvalidoException` e nada é
  vinculado.
- **C4 (autenticação) — Dado** um `telegram_user_id` com vínculo ativo **Quando** chega
  uma mensagem **Então** resolve o `User`; **sem** vínculo ativo, nenhuma mensagem é
  processada como autenticada (vai para `naoVinculado`).
- **C5 (webhook — segredo) — Dado** o header `X-Telegram-Bot-Api-Secret-Token`
  **ausente** ou **divergente** **Quando** chega o POST **Então** responde **403** e nada
  chega ao controller (comparação em tempo constante).
- **C6 (webhook — sempre 200) — Dado** um POST com segredo válido **Quando** processado
  (mesmo sem `update_id`, sem mensagem/remetente, ou em erro de rota) **Então** responde
  **200** para evitar reentregas em loop; a rota é **CSRF-isenta**.
- **C7 (dedupe) — Dado** um `update_id` já visto **Quando** chega a reentrega **Então**
  **mantém a primeira** mensagem e **não reprocessa** (idempotência por UNIQUE, sem Redis).
- **C8 (classificação) — Dado** o texto de uma mensagem **Quando** classificado **Então**
  a **primeira palavra** (slash-command `/registrar`, com ou sem `@bot`, ou verbo
  `registra/cancela/...`) mapeia para a intenção pela **palavra inteira** (nunca prefixo),
  insensível a maiúsculas; o restante vira **argumentos brutos** (não interpretados).
- **C9 (texto livre) — Dado** um texto que não casa nenhum comando **Quando** classificado
  **Então** vira `DESCONHECIDO` **preservando o texto original íntegro** para o
  interpretador de IA (Bloco 4) — sem extrair valor/data aqui.

## 4. Barreiras e invariantes
- **Escopo estrito por usuário** — toda mensagem é resolvida ao `user_id` do vínculo ativo
  antes de qualquer roteamento; nada roda anônimo.
- **Segredo do webhook** — só entra requisição com o header correto; sem segredo
  configurado, recusa (403). Comparação `hash_equals` (tempo constante).
- **Token só em hash** — em claro só em memória/entrega ao usuário; no banco, `token_hash`
  (sha-256) + expiração; consumido → hash nulo. Nada de PDF/dado sensível (regra 6); o
  único dado pessoal é o **telefone verificado**, necessário ao vínculo.
- **Idempotência** — `update_id` UNIQUE; reentrega não reprocessa (mantém a primeira).
- **Determinismo, zero IA aqui** — o roteamento é puramente determinístico (regra 8: a IA
  só entra no Bloco 4, e sempre via SDK). Este adaptador **não calcula dinheiro** (regra 4)
  e **não interpreta linguagem natural**.
- **Adaptador fino** — webhook/roteador não contêm regra de negócio; processamento pesado
  (IA/PDF) fica fora do request (worker).

## 5. Modelo de dados
Doc 04 §"telegram_links"/"telegram_updates". Duas tabelas:

- **`telegram_links`** — `user_id` (FK, cascade), `telegram_user_id` (bigint, nullable),
  `telefone` (nullable), `token_hash` (char 64, nullable), `token_expira_em` (timestamptz),
  `status` (`pendente`/`ativo`/`revogado`, CHECK), `vinculado_em` (timestamptz), timestamps.
  Índices **parciais únicos** (PostgreSQL):
  - `(user_id) WHERE status = 'ativo'` — **um vínculo ativo por conta**;
  - `(telegram_user_id) WHERE status = 'ativo'` — **um `telegram_user_id` ativo**;
  - `(token_hash) WHERE token_hash IS NOT NULL` — hash único entre pendências.
- **`telegram_updates`** — append-only: `update_id` (bigint **UNIQUE**) + `created_at`.
  Garante o dedupe **sem Redis**.

> Instantes gravados em **UTC** (timestamptz; `app.timezone=UTC`) preservando o instante
> absoluto; fuso base de negócio America/Sao_Paulo só na borda (regra 5).

## 6. Contratos do domínio
Assinaturas reais (`app/Domain/Telegram/`, exceto borda HTTP):

```php
// Vínculo (doc 06 §1)
final class GerarTokenDeVinculo {
    public const EXPIRACAO_MINUTOS = 15;
    public function para(int $userId, ?CarbonImmutable $agora = null): TokenDeVinculo;
}
final class TokenDeVinculo { // só em memória; banco guarda o hash
    public function __construct(
        public readonly string $token,
        public readonly CarbonImmutable $expiraEm,
    ) {}
}
final class VincularTelegram {
    public function confirmar(
        string $token, int $telegramUserId, string $telefone,
        ?CarbonImmutable $agora = null,
    ): TelegramLink; // atômico (DB::transaction + lockForUpdate)
}
final class AutenticarTelegram {
    public function resolver(int $telegramUserId): ?User; // vínculo ativo, ou null
    public function usuario(int $telegramUserId): User;    // lança se não houver
}

// Idempotência (doc 06 §2/§3)
final class DedupeDeUpdate {
    public function ehNovo(int $updateId): bool; // insertOrIgnore: true na 1ª vez
}

// Roteamento determinístico (doc 06 §2)
enum Comando: string { case REGISTRAR; case EDITAR; case CANCELAR;
                       case BUSCAR; case AJUDA; case DESCONHECIDO; }
final readonly class ComandoRecebido {
    public function __construct(
        public Comando $comando,
        public string $argumentos,    // texto após o comando, ainda cru
        public string $textoOriginal, // íntegro, para a IA quando DESCONHECIDO
    ) {}
}
final class ClassificadorDeComando {
    public function classificar(string $texto): ComandoRecebido; // sem banco/framework
}
interface ManipuladorDeComando {
    public function manipular(User $user, ComandoRecebido $comando): void;
}
final class ManipuladorInerte implements ManipuladorDeComando { /* no-op por ora */ }

interface RoteadorDeMensagem {
    public function autenticado(User $user, array $update): void;
    public function naoVinculado(int $telegramUserId, array $update): void;
}
final class RoteadorDeComandos implements RoteadorDeMensagem {
    // @param array<string, ManipuladorDeComando> $manipuladores  Comando->value → manipulador
    public function __construct(
        ClassificadorDeComando $classificador,
        ManipuladorDeComando $padrao,
        array $manipuladores = [],
    );
}

// Exceções
final class TokenInvalidoException extends RuntimeException { static invalido(); }
final class VinculoInexistenteException extends RuntimeException { static semVinculo(); }
```

Borda HTTP:

```php
// app/Http/Middleware/VerificaSegredoTelegram.php — header X-Telegram-Bot-Api-Secret-Token
//   (hash_equals; 403 se ausente/divergente/sem segredo configurado).
// app/Http/Controllers/TelegramWebhookController.php — __invoke(Request, DedupeDeUpdate,
//   AutenticarTelegram, RoteadorDeMensagem): dedupe → autenticar → autenticado()/naoVinculado();
//   responde sempre 200.
// routes/web.php — POST /telegram/webhook + middleware VerificaSegredoTelegram (CSRF isento
//   em bootstrap/app.php: validateCsrfTokens(except: ['telegram/webhook'])).
// app/Providers/AppServiceProvider.php — bind RoteadorDeMensagem → RoteadorDeComandos
//   (ClassificadorDeComando + ManipuladorInerte; mapa de manipuladores vazio por ora).
```

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio**
   - `ClassificadorDeComando` — slash-commands básicos; argumentos após o comando; sufixo
     `@bot`; insensível a caixa; slash desconhecido → `DESCONHECIDO`; verbo como 1ª palavra;
     **palavra inteira** (não casa por prefixo); texto livre → `DESCONHECIDO` preservando o
     **texto íntegro**; vazio/espaços → `DESCONHECIDO` com argumentos vazios.
2. **Contrato/integração (borda)**
   - **Vínculo** (`TelegramVinculoTest`) — gera token único + pendência com hash/expiração;
     token em claro nunca persistido; re-geração substitui a pendência; consumo captura
     `telegram_user_id`+telefone e consome; recusa token inexistente/expirado/consumido;
     um ativo por conta; **mesmo `telegram_user_id` não vincula a duas contas** (índice
     parcial → `QueryException`); autenticação resolve usuário; recusa sem vínculo.
   - **Dedupe** (`DedupeDeUpdateTest`) — aceita `update_id` novo; rejeita repetido; aceita
     distintos.
   - **Webhook** (`WebhookTest`) — 403 sem header / com segredo errado; 200 + registra
     `update_id` com segredo válido; idempotente em reentrega; despacha autenticado /
     não vinculado; 200 sem mensagem/remetente; 200 sem `update_id`.
   - **Roteador** (`RoteadorDeComandosTest`) — despacha cada intenção ao manipulador
     registrado; fallback no padrão; não quebra sem texto; `naoVinculado` é no-op.

> Cada item de backend só é "feito" com **testes verdes e cobertura**. Nesta etapa **não há
> IA**, logo nenhum fake da SDK é necessário; o determinismo é nativo.

## 8. Backend agora · Frontend depois
> Todo o frontend desta etapa foi **consolidado** em [[spec-FE-frontend-stitch]] (regra 3).
> A tabela abaixo é o registro do que foi adiado para lá.

| Backend (esta etapa) | Frontend → [[spec-FE-frontend-stitch]] |
|---|---|
| Token de vínculo (hash + expiração), consumo atômico, um ativo por conta | **Fluxo de vínculo via bot**: receber o token, pedir telefone via `request_contact`, confirmar |
| Webhook seguro (segredo, CSRF isento, sempre 200), dedupe, autenticação | **Mensagens formatadas do bot**: respostas **curtas, sem botões** (doc 06 §2) |
| Classificação determinística + roteamento por intenção + ligação aos Blocos 4/5 (worker) | Redação das respostas de cada comando + tela web de vínculo do Telegram |

## 9. Definition of Done
- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (segredo, token só em hash, dedupe, escopo por usuário,
      zero IA) — com teste para cada uma crítica.
- [x] Sem segredo/PDF/dado sensível persistido ou commitado (só telefone do vínculo).
- [x] Commit local atômico, em português, separando backend de frontend.
- [x] **Frontend** (mensagens do bot + vínculo via bot) — **movido** para [[spec-FE-frontend-stitch]] (regra 3).
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ Concluído (backend) — vínculo + webhook + roteamento + ligação aos Blocos 4/5.
  Toda a **apresentação** (mensagens do bot, fluxo de vínculo via bot, tela web de vínculo)
  foi consolidada em [[spec-FE-frontend-stitch]] (regra 3).
- **Entregue (✅):**
  - Migrations: `database/migrations/2026_06_26_000007_create_telegram_links_table.php`
    (token só em hash, expiração, partial uniques: 1 ativo por conta / 1 `telegram_user_id`
    ativo / hash único; CHECK de status) e
    `2026_06_26_000008_create_telegram_updates_table.php` (`update_id` UNIQUE, append-only).
  - Domínio `app/Domain/Telegram/`: `GerarTokenDeVinculo`, `TokenDeVinculo`,
    `VincularTelegram`, `AutenticarTelegram`, `DedupeDeUpdate`, `Comando` (enum),
    `ComandoRecebido` (VO), `ClassificadorDeComando`, `ManipuladorDeComando` +
    `ManipuladorInerte`, `RoteadorDeMensagem` + `RoteadorDeComandos`,
    `TokenInvalidoException`, `VinculoInexistenteException`. Model `app/Models/TelegramLink.php`.
  - Borda HTTP: `app/Http/Controllers/TelegramWebhookController.php` (adaptador fino,
    sempre 200), `app/Http/Middleware/VerificaSegredoTelegram.php` (header
    `X-Telegram-Bot-Api-Secret-Token`), `routes/web.php` (`POST /telegram/webhook`),
    CSRF isento em `bootstrap/app.php`, rebind em `app/Providers/AppServiceProvider.php`,
    config em `config/services.php` (`telegram.bot_token`, `telegram.webhook_secret`).
  - Testes: `tests/Unit/Domain/ClassificadorDeComandoTest.php`,
    `tests/Feature/Domain/TelegramVinculoTest.php` (inclui a barreira de unicidade global:
    o mesmo `telegram_user_id` não vincula a duas contas — índice parcial → `QueryException`),
    `tests/Feature/Domain/DedupeDeUpdateTest.php`,
    `tests/Feature/Telegram/WebhookTest.php`,
    `tests/Feature/Telegram/RoteadorDeComandosTest.php`.
  - **Ligação aos Blocos 4/5 (execução de comando — backend):** `app/Jobs/ProcessarMensagemDoBot.php`
    (worker; resolve intenção forçada/classificada → registro via `ExtratorDeGasto` +
    `PrepararConfirmacaoDeGasto`, ou consulta via `ResponderConsulta`; fallback "não entendi"),
    `app/Domain/Telegram/ManipuladorQueEnfileira.php` (adaptador fino que só enfileira —
    barreira §4), porta de saída `app/Domain/Telegram/Resposta/` (`RespostaAoUsuario`,
    `ResultadoDaInteracao`, `TipoDeInteracao`, `RespostaInerte`), rebind do mapa em
    `app/Providers/AppServiceProvider.php` (`/registrar`→REGISTRAR, `/buscar`→CONSULTAR,
    texto livre→classificação no worker; editar/cancelar/ajuda seguem inertes). Testes:
    `tests/Feature/Telegram/ManipuladorQueEnfileiraTest.php`,
    `tests/Feature/Telegram/ProcessarMensagemDoBotTest.php`.
- **Adiado para:**
  - **Editar/cancelar via texto livre:** dependem de um extrator de IA para "qual lançamento"
    (não entregue no [[spec-04-ia-interpretacao]]); por ora caem no fallback "não entendi".
  - **Importar (PDF)** → [[spec-07-importacao-pdf]].
  - **Frontend:** mensagens formatadas do bot (curtas, sem botões) e fluxo de vínculo via
    bot (token + `request_contact`); `RoteadorDeComandos::naoVinculado` é no-op até lá. A
    porta de saída `RespostaAoUsuario` está inerte (`RespostaInerte`) até a redação/envio.
- **Decisões de regra tomadas:**
  - Token persistido **só em hash** (sha-256), expiração de **15 min** (`EXPIRACAO_MINUTOS`);
    consumo zera o hash. "Agora" injetável (`CarbonImmutable`) para determinismo (regra 5).
  - Webhook **sempre 200** (mesmo sem `update_id`/mensagem/remetente) para o Telegram não
    reentregar em loop; o `update_id` é registrado para dedupe ainda que o update não seja
    roteado (`edited_message`, callbacks).
  - Dedupe via `insertOrIgnore` (INSERT … ON CONFLICT DO NOTHING) — idempotente sob
    concorrência sem abortar transação.
  - Classificação por **palavra inteira** da primeira palavra (slash ou verbo), nunca por
    prefixo; texto livre vira `DESCONHECIDO` carregando o **texto íntegro** para a IA —
    nenhuma interpretação de valor/data acontece neste adaptador (regra 4/8).
