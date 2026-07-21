# Spec 04 — IA de interpretação (Laravel AI SDK)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões de regra que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 4 · F5 |
| **Status** | ✅ Concluído (alguns acoplamentos ao bot/FE adiados) |
| **Depende de** | [[spec-01-dominio-financeiro]] · [[spec-02-cadastro-manual-receitas]] · [[spec-03-telegram]] |
| **Habilita** | [[spec-05-chat-financeiro]] |
| **Fonte de verdade** | Seção 3 do escopo · [`docs/02-governanca-ia.md`](../02-governanca-ia.md) · [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) §3.4 |
| **Regras críticas** | 2 (test-first) · 4 (IA nunca calcula) · 5 (centavos/fuso SP) · 7 (confirmar antes de gravar) · 8 (tudo via Laravel AI SDK) |

---

## 1. Objetivo
Transformar a **mensagem livre** do usuário em (a) uma **intenção** classificada e (b) uma
**extração estruturada CRUA** de gasto, e então **normalizar deterministicamente** esses
campos e **preparar a confirmação** — calculando a prévia **sem persistir** — tudo pela
Laravel AI SDK, com a IA interpretando e o sistema fazendo a aritmética.

## 2. Escopo
- **Inclui (backend desta etapa):**
  - **Papel 1 — Classificar intenção** (`ClassificadorDeIntencao`): texto → `Intencao` (enum),
    via *structured output*; saída fora do conjunto cai em `DESCONHECIDO` (nunca chuta).
  - **Papel 2 — Extrair gasto CRU** (`ExtratorDeGasto`): texto → `GastoExtraido` com valor e
    data **como texto** (a IA **não** normaliza); campo obrigatório ausente → esclarecimento;
    crédito exige cartão (§3.4).
  - **Papel 3 — Redigir** (`RedatorDeResposta`): a partir de payload já calculado, só formata.
  - **Normalização determinística** (`NormalizadorDeGastoExtraido`): valor → centavos, data →
    fuso SP, forma → id, cartão por token, categoria por lookup — **fora da IA**.
  - **Preparar confirmação** (`PrepararConfirmacaoDeGasto`): normaliza → gera **prévia sem
    gravar** (regra 7) ou devolve esclarecimentos.
  - **Histórico / custo / failover**: expurgo de 60 dias; `ai_usage_log` append-only; failover
    nativo da SDK + listener que loga instabilidade.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - **Guard pós-geração** (barreira 4) e orquestração de consulta → [[spec-05-chat-financeiro]].
  - **Amarração** classificar→extrair→confirmar ao **roteador do Telegram** e a **mensagem de
    confirmação do bot** → **frontend / F5** (regra 3).
  - Auto-save de alta confiança (segue **sempre confirmar** no MVP).

## 3. Cenários de aceite (Given-When-Then)

- **C1 (classificar) — Dado** "gastei 50 no mercado" **Quando** o `ClassificadorDeIntencao`
  roda **Então** o *structured output* mapeia para `Intencao::REGISTRAR`.
- **C2 (fallback de intenção) — Dado** uma saída da IA que não é nenhuma intenção conhecida
  (ou tentativa de manipulação) **Quando** classifica **Então** retorna `Intencao::DESCONHECIDO`
  — nunca lança, nunca chuta (barreira 1).
- **C3 (extrair CRU) — Dado** "paguei 35 conto de uber amanhã" **Quando** o `ExtratorDeGasto`
  roda **Então** `valorTexto = "35 conto"` e `dataTexto = "amanhã"` **exatamente como ditos** —
  a IA **não** converte para centavos nem resolve a data (regra 4).
- **C4 (campo obrigatório ausente) — Dado** um texto sem valor (ou sem descrição/forma)
  **Quando** extrai **Então** `ResultadoDaExtracao::precisaEsclarecer()` é verdadeiro e
  `camposFaltantes` lista o que perguntar — campo ausente vira **pergunta**, nunca chute
  (barreira 1).
- **C5 (crédito exige cartão §3.4) — Dado** forma `credito` sem cartão identificado **Quando**
  extrai **Então** `cartao` entra em `camposFaltantes`.
- **C6 (normalizar valor) — Dado** `valorTexto = "R$ 35,90"` **Quando** normaliza **Então**
  `valorTotalCents = 3590` (>0); texto não-monetário ou zero → esclarecimento `valor`.
- **C7 (normalizar data, fuso SP) — Dado** data ausente → **hoje** (fuso SP); termo relativo
  (`amanhã`) → `RelativeDate`; `dd/mm[/aaaa]`/`aaaa-mm-dd` → parse; incompreendida →
  esclarecimento `data`.
- **C8 (normalizar forma e cartão) — Dado** forma suportada → `PaymentMethod::idFor`; em
  **crédito**, o cartão é casado pelo **token** contido na descrição do cartão do usuário —
  0 ou ≥2 correspondências → esclarecimento `cartao` (escopo estrito por `user_id`).
- **C9 (categoria por lookup) — Dado** a descrição **Quando** normaliza **Então** a categoria
  vem do `LookupDeCategoria` determinístico; ausência **não** bloqueia (null é aceitável).
- **C10 (prévia sem gravar — regra 7) — Dado** uma extração que normaliza **Então**
  `PrepararConfirmacaoDeGasto` devolve `previa` (via `RegistrarGastoManual::preview()`) +
  `dados` para o "sim" posterior, **sem persistir nada**.
- **C11 (esclarecimento na confirmação) — Dado** algo que não resolve na normalização **Então**
  a confirmação devolve só os `esclarecimentos`, sem prévia.
- **C12 (expurgo 60 dias) — Dado** "agora" injetado **Quando** `ExpurgarConversas` roda
  **Então** apaga conversas (e mensagens) com `updated_at` **além** de 60 dias; mantém a borda
  exata de 60 dias.
- **C13 (custo append-only) — Dado** os metadados de uma chamada **Quando** `RegistrarUsoDeIA`
  grava **Então** cria 1 linha em `ai_usage_log` com `custo_estimado_cents` em centavos
  (regra 5), **sem** conteúdo de prompt/resposta; o custo vem da `CalculadoraDeCustoIA`
  (tokens × `config/ai_custos.php`; provedor/modelo fora da tabela → 0).
- **C14 (failover + log) — Dado** indisponibilidade de um provedor **Quando** a SDK faz
  failover para o próximo **Então** o evento `AgentFailedOver` dispara o `LogarFailoverDeIA`,
  que registra agente/provedor/modelo/**classe** da exceção — sem dado sensível.

## 4. Barreiras e invariantes
- **Regra 4 — a IA NUNCA calcula dinheiro.** Extração sai **crua** (valor/data como texto); a
  **normalização** (centavos, fuso SP, id de forma, cartão, categoria) é **camada nossa,
  determinística e testada**. A redação só formata números **já calculados**.
- **Regra 8 — tudo via Laravel AI SDK.** Os três papéis são **Agents** (`HasStructuredOutput`
  na intenção/extração); nada de cliente HTTP próprio. Failover é recurso **nativo** (array de
  provedores). Testes usam os **fakes** da SDK (offline/determinístico).
- **Regra 5 — centavos inteiros + fuso SP.** `Money::fromHuman` → BIGINT centavos; datas
  resolvidas em `America/Sao_Paulo`; `custo_estimado_cents` também em centavos (BIGINT).
- **Regra 7 — confirmar antes de gravar.** A confirmação produz **prévia**, nunca persistência;
  auto-save permanece desligado no MVP.
- **Barreiras anti-alucinação 1–3** (doc 02 §3.3): **(1)** saída estruturada — campo ausente
  vira pergunta; **(2)** confirmação obrigatória antes de persistir; **(3)** escopo estrito por
  usuário (cartão/categoria casados só no `user_id`). **(4)** guard pós-geração → spec 05.
- **Privacidade (doc 09):** `ai_usage_log` e o log de failover guardam só metadados; histórico
  de conversa é expurgado em 60 dias.

## 5. Modelo de dados
- **`ai_usage_log`** (nova — migration `2026_06_27_000001_create_ai_usage_log_table`):
  `id`, `user_id` (FK nullable, `nullOnDelete`), `provider`, `model`, `tokens_entrada` /
  `tokens_saida` (unsigned int), `custo_estimado_cents` (**BIGINT** ≥ 0, CHECK no pgsql),
  `latencia_ms` (nullable), `tipo`, `created_at` (timestamptz). **Append-only** (sem
  `updated_at`). Índices `(user_id, created_at)` e `(tipo, created_at)`.
- **Histórico de conversa**: tabelas do `RemembersConversations` da SDK
  (`Conversation` / `ConversationMessage`) — **lidas/apagadas**, não criadas aqui.
- Demais entidades (cards, payment_methods, categories, transactions) são apenas **lidas**
  pela normalização — pertencem aos Blocos 1/2.

## 6. Contratos do domínio
**Agents (`app/Ai/Agents`)**
- `ClassificadorDeIntencao::classificar(string $texto): Intencao` — *structured output*
  (`schema.intencao` enum) → `Intencao::tentar()` (fallback `DESCONHECIDO`).
- `ExtratorDeGasto::extrair(string $texto): ResultadoDaExtracao` — *structured output*
  (descricao, valor, forma_pagamento, cartao, categoria, data, parcelas, todos **texto/cru**);
  monta `camposFaltantes` (inclui `cartao` quando forma = `credito`).
  **Ampliado depois:** `recorrencia_dia` ([[spec-10c-recorrencia-via-bot]]) e
  `pago`/`data_pagamento` ([[spec-04b-confirmacao-gasto-bot]] §11 — "já foi pago?", slot
  obrigatório fora de cartão).
- `RedatorDeResposta::redigir(string $payload): string` — formata payload já calculado.
- trait `UsaFailoverDeProvedores::provider(): array` → `config('ai.failover')` (vive em
  `app/Ai/Concerns/`, usado pelos 3 agentes acima).

**Domínio IA (`app/Domain/IA`)**
- enum `Intencao` (`registrar|consultar|editar|cancelar|importar|desconhecido`) —
  `tentar(?string): self`, `valores(): list<string>`.
- `GastoExtraido` (VO readonly): `descricao, valorTexto, formaPagamento, cartao?, categoria?,
  dataTexto?, parcelas?, recorrenciaDiaTexto?, pago?, dataPagamentoTexto?` — **cru**.
- `ResultadoDaExtracao(?GastoExtraido $gasto, array $camposFaltantes)` —
  `precisaEsclarecer(): bool`.
- `NormalizadorDeGastoExtraido::normalizar(GastoExtraido $e, int $userId,
  CarbonImmutable $agora): ResultadoDaNormalizacao` — "agora" **injetado**; valor→centavos,
  data→fuso SP, forma→id, cartão por token único, categoria por lookup.
- `ResultadoDaNormalizacao(?DadosGastoManual $dados, array $esclarecimentos)` —
  `precisaEsclarecer(): bool`.
- `PrepararConfirmacaoDeGasto::preparar(GastoExtraido $e, int $userId,
  CarbonImmutable $agora): ConfirmacaoDeGasto` — normaliza → `RegistrarGastoManual::preview()`
  **sem gravar**.
- `ConfirmacaoDeGasto(?PreviaGastoManual $previa, ?DadosGastoManual $dados,
  array $esclarecimentos)` — `precisaEsclarecer()`, `confirmavel()`.

**Histórico (`app/Domain/IA/Historico`)**
- `ExpurgarConversas::executar(CarbonImmutable $agora): int` (const `DIAS_DE_RETENCAO = 60`);
  comando `ai:expurgar-conversas` (`ExpurgarConversasCommand`), agendado em
  `routes/console.php` → `dailyAt('03:30')`.

**Custo (`app/Domain/IA/Custo`)**
- enum `TipoDeUsoIA` (`mensagem|importacao|resumo`).
- `UsoDeIA` (VO): provider, model, tokensEntrada/Saida, custoEstimadoCents, latenciaMs?, tipo,
  userId?.
- `CalculadoraDeCustoIA(array $tabela)::centavos(string $provider, string $model,
  int $in, int $out): int` — tokens × preço/1M → centavos; fora da tabela → 0.
- `RegistrarUsoDeIA::registrar(UsoDeIA $uso): AiUsageLog` — append-only.

**Failover (`app/Listeners`)**
- `LogarFailoverDeIA::handle(AgentFailedOver $evento): void` — log estruturado sem dado
  sensível; registrado em `AppServiceProvider` via `Event::listen(AgentFailedOver::class, …)`.

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio**
   - `Intencao::tentar()` (conhecida → caso; desconhecida/null → `DESCONHECIDO`).
   - `NormalizadorDeGastoExtraido`: valor→centavos / inválido→esclarecimento; datas
     (ausente=hoje, relativa, explícita, incompreendida); forma→id; cartão único/0/≥2;
     categoria por lookup (cobre C6–C9).
   - `CalculadoraDeCustoIA`: tokens × preço → centavos; fora da tabela → 0 (C13).
2. **Contrato/integração (borda — agents com fakes da SDK, comando, model)**
   - `ClassificadorDeIntencao` / `ExtratorDeGasto` com `Ai::fakeAgent` /
     `assertAgentWasPrompted` (C1–C5); valor/data saem crus.
   - `PrepararConfirmacaoDeGasto`: prévia sem gravar + dados; ou esclarecimentos (C10–C11).
   - `ExpurgarConversas`: borda exata de 60 dias, "agora" injetado (C12).
   - `RegistrarUsoDeIA`: 1 linha append-only, custo em centavos, sem conteúdo (C13).
   - `FailoverDeProvedores`: agentes expõem o array de `config('ai.failover')` (C14).

> Cada item de backend só é "feito" com **testes verdes e cobertura**. A camada de IA usa os
> **fakes da Laravel AI SDK** (offline, determinístico).

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend / F5 (etapa separada e posterior) |
|---|---|
| 3 Agents (intenção/extração/redação) via SDK | Mensagem de **confirmação** do bot (texto/botões "sim/não") |
| Normalização determinística + `PrepararConfirmacaoDeGasto` (prévia) | **Amarração** classificar→extrair→confirmar ao **roteador do Telegram** |
| Expurgo 60 dias + comando agendado | Mensagem "instabilidade, tentando novamente" + re-enfileirar / degradar p/ comandos |
| `ai_usage_log` (custo) + failover + listener | — |

## 9. Definition of Done
- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (IA cru/normalização nossa; centavos/fuso; prévia sem gravar;
      escopo por usuário; metadados sem conteúdo).
- [x] Sem segredo/PDF/dado sensível persistido ou commitado (custo e failover só com metadados;
      histórico expurgado em 60 dias).
- [x] Commits locais atômicos, em português, separando backend de frontend.
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ Concluído (backend). Acoplamentos ao bot/FE **adiados**.
- **Entregue:**
  - Agents: `app/Ai/Agents/ClassificadorDeIntencao.php`, `ExtratorDeGasto.php`,
    `RedatorDeResposta.php`; trait `app/Ai/Concerns/UsaFailoverDeProvedores.php`.
  - Domínio: `app/Domain/IA/Intencao.php`, `GastoExtraido.php`, `ResultadoDaExtracao.php`,
    `NormalizadorDeGastoExtraido.php`, `ResultadoDaNormalizacao.php`,
    `PrepararConfirmacaoDeGasto.php`, `ConfirmacaoDeGasto.php`.
  - Histórico: `app/Domain/IA/Historico/ExpurgarConversas.php`,
    `app/Console/Commands/ExpurgarConversasCommand.php`, schedule em `routes/console.php`
    (`dailyAt('03:30')`).
  - Custo: `app/Models/AiUsageLog.php`,
    `database/migrations/2026_06_27_000001_create_ai_usage_log_table.php`,
    `app/Domain/IA/Custo/{TipoDeUsoIA,UsoDeIA,CalculadoraDeCustoIA,RegistrarUsoDeIA}.php`.
  - Failover: `config/ai.php` (`ai.failover`), `config/ai_custos.php`,
    `app/Listeners/LogarFailoverDeIA.php` (registrado em `app/Providers/AppServiceProvider.php`).
  - Testes: `tests/Unit/AI/*`, `tests/Unit/Domain/{IntencaoTest,CalculadoraDeCustoIATest}.php`,
    `tests/Feature/AI/{ClassificadorDeIntencao,ExtratorDeGasto,Normalizador…,PrepararConfirmacao…,Failover…}Test.php`,
    `tests/Feature/Domain/{ExpurgarConversas,RegistrarUsoDeIA}Test.php`.
- **Adiado para:** [[spec-05-chat-financeiro]] (guard pós-geração / barreira 4, orquestração de
  consulta, tools com escopo); **frontend / F5** (amarração ao roteador do Telegram + mensagem
  de confirmação do bot + mensagem de instabilidade/re-enfileiramento).
- **Decisões de regra tomadas:**
  - A **IA devolve valor e data como texto cru**; toda conversão (centavos, fuso SP, id de
    forma, cartão, categoria) é determinística e fora da IA (regra 4).
  - **"Agora" é injetado** em normalização e expurgo (determinismo, regra 4/5); o expurgo
    mantém a borda **exata** de 60 dias.
  - **Cartão** casado por token **único** na descrição do usuário; 0 ou ≥2 → esclarecimento
    (sem chute, escopo por `user_id`).
  - **Custo** é estimativa de governança em centavos; provedor/modelo fora de
    `config/ai_custos.php` → **0** (nunca inventa custo). `ai_usage_log` **append-only**, sem
    conteúdo.
  - **Auto-save** permanece desligado: MVP **sempre confirma** (regra 7).
