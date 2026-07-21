# Spec 04b — Confirmação de gasto via bot (fechar o "sim/não")

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Spec **prospectivo**: descreve o que vamos construir. Nada aqui ainda existe, salvo o
> que §6/§10 marcarem como reusável. Ao concluir, marque o status, preencha **§10 Estado
> atual** com os artefatos reais e registre as decisões de regra que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 4/5 · F5 |
| **Status** | ✅ Concluído (backend) · `callback_query` (C8) adiado · **emenda 2026-07-21 ("já foi pago?") concluída — §11, backend + frontend** |
| **Depende de** | [[spec-02-cadastro-manual-receitas]] · [[spec-03-telegram]] · [[spec-04-ia-interpretacao]] |
| **Habilita** | [[spec-FE-frontend-stitch]] (mensagem/tela de confirmação) |
| **Fonte de verdade** | seções 4 e 5 do escopo · [`docs/02-governanca-ia.md`](../02-governanca-ia.md) · [`docs/06-telegram.md`](../06-telegram.md) |
| **Regras críticas** | 2 (test-first), 3 (frontend separado), 4 (IA nunca calcula), 5 (centavos/fuso), 6 (nada sensível persistido), 7 (confirmar antes de gravar) |

---

## 1. Objetivo
Fechar o último elo do cadastro de gasto pelo bot: depois que a IA interpreta "gastei X
em Y" e o domínio gera a **prévia sem gravar** (spec 04), o usuário responde **"sim"** (ou
"não") e o gasto é **persistido de forma determinística** (ou descartado). Hoje a prévia é
gerada e **jogada fora** — não há onde guardar a confirmação pendente entre mensagens nem
quem trate a resposta. Esta etapa é o **backend** desse fluxo; a **redação/envio** da
mensagem do bot fica para o frontend (regra 3).

## 2. Escopo
- **Inclui (backend desta etapa):**
  - **Estado de confirmação pendente** por usuário: persistir o `ConfirmacaoDeGasto`
    preparado (na prática o `DadosGastoManual` pronto para gravar), com **expiração (TTL)**
    e **escopo estrito por `user_id`**.
  - **Interpretação determinística de "sim"/"não"** quando há uma confirmação pendente para
    o usuário (não usa a IA para isso — ver §4/§6).
  - **Persistir no "sim"**: recuperar o pendente e chamar `RegistrarGastoManual::confirmar()`
    (atômico), descartando o pendente; **descartar no "não"**; **uso único** (não grava duas
    vezes).
  - **Ligação no worker** `ProcessarMensagemDoBot`: antes de classificar intenção, se houver
    pendente, tratar a mensagem como resposta de confirmação.
  - **(Opcional, decisão §6) `callback_query`**: aceitar no webhook a resposta vinda de botão
    inline, mapeando `callback_data` → pendente. A **renderização dos botões** é frontend.
- **Não inclui (frontend / outro spec / pós-MVP):**
  - **Redação e envio** da mensagem "Confirma? (sim/não)" e do recibo pós-gravação, e a
    **tela web** "Confirmações pendentes" — frontend (regra 3), [[spec-FE-frontend-stitch]]
    §7.9 / B3.
  - Confirmação de **edição/cancelamento/importação** via bot — fora desta etapa (o enum
    `Intencao` já prevê `EDITAR`/`CANCELAR`/`IMPORTAR`, mas não há fluxo).
  - Qualquer **novo cálculo** financeiro — esta etapa só **lê** a prévia já calculada e
    manda **gravar** pelo serviço existente.

## 3. Cenários de aceite (Given-When-Then)
Em todos, o usuário é estritamente escopado e "agora" é **injetado** (nunca lido do relógio
global) — igual ao resto do domínio.

- **C1 — "sim" grava.** **Dado** um usuário com uma confirmação pendente confirmável (prévia
  + `DadosGastoManual`) **Quando** ele responde "sim" **Então** o gasto é persistido via
  `RegistrarGastoManual::confirmar()` (transaction + parcelas + auditoria, origem `manual`),
  o pendente é **apagado**, e o resultado de domínio carrega a `Transaction` gravada.
- **C2 — "não" descarta.** **Dado** o mesmo pendente **Quando** ele responde "não" **Então**
  **nada** é gravado, o pendente é apagado, e o resultado indica cancelamento.
- **C3 — uso único (idempotência).** **Dado** um pendente já confirmado **Quando** chega um
  segundo "sim" **Então** **não** há segunda gravação (o pendente não existe mais); o
  resultado indica "nada a confirmar".
- **C4 — expiração (TTL).** **Dado** um pendente mais antigo que o TTL **Quando** o usuário
  responde "sim" **Então** **nada** é gravado, o pendente expirado é tratado como inexistente
  e o resultado indica "expirou — refaça o lançamento".
- **C5 — escopo por usuário.** **Dado** uma confirmação pendente do usuário A **Quando** o
  usuário B responde "sim" **Então** o pendente de A **não** é tocado (B não confirma gasto
  de A); resultado de B = "nada a confirmar".
- **C6 — sem pendente segue o fluxo normal.** **Dado** um usuário **sem** pendente **Quando**
  ele manda "gastei 50 no mercado" **Então** o fluxo da spec 04 roda igual (classifica →
  extrai → prepara prévia) e **um novo pendente é guardado**; e se ele manda um "sim" solto
  sem pendente, recebe "nada a confirmar" (nunca grava por engano).
- **C7 — resposta ambígua não grava.** **Dado** um pendente **Quando** a resposta não é
  reconhecível como sim/não (ex.: "talvez", "?", outra frase) **Então** **nada** é gravado, o
  pendente é **mantido**, e o resultado pede confirmação de novo (não chuta — barreira 1).
- **C8 (borda, opcional §6) — callback de botão.** **Dado** que o frontend enviou botões
  inline **Quando** chega um `callback_query` com o `callback_data` do pendente **Então** o
  webhook o roteia, autentica pelo `callback_query.from.id` e cai no mesmo caminho de C1/C2.

## 4. Barreiras e invariantes
- **Regra 7 — confirmar antes de gravar.** A gravação só acontece no "sim"; a prévia (spec
  04) continua **sem** persistir. Esta etapa é literalmente a materialização da regra 7 no
  canal do bot.
- **Regra 4 — a IA nunca calcula dinheiro.** A interpretação de "sim/não" é **determinística**
  (não-IA) e **não recalcula nada**: o `DadosGastoManual` guardado é exatamente o que a spec
  04 já normalizou; o "sim" só manda gravar. Nenhum valor é recomputado aqui.
- **Regra 5 — centavos inteiros e fuso.** O `DadosGastoManual` serializado preserva
  `valorTotalCents` como `int` e a `dataCompra` como **instante** correto. Atenção ao
  serializar `CarbonImmutable`: gravar/reidratar mantendo o fuso (ver memória "timestamptz
  store UTC" — o instante não pode corromper no round-trip).
- **Regra 6 — nada sensível persistido.** O pendente guarda só o necessário para gravar
  (descrição do gasto, valores, ids de forma/cartão/categoria) e a prévia para auditoria —
  **sem** PDF, sem texto bruto da IA, sem nome/CPF/endereço. O TTL garante que pendentes não
  virem retenção indevida.
- **Escopo estrito por `user_id`.** Recuperar/confirmar/descartar **sempre** filtram pelo
  dono; o token de vínculo não atravessa usuários (C5).
- **Determinismo de "agora".** TTL e a gravação recebem `CarbonImmutable $agora` **injetado**;
  os testes fixam o instante.
- **Uso único.** Confirmar apaga o pendente na **mesma transação** da gravação (sem janela
  para gravar duas vezes — C3).

## 5. Modelo de dados
Uma tabela nova (PostgreSQL 16). **Nenhuma** coluna nova nas tabelas financeiras — o gasto
em si nasce pelo `RegistrarGastoManual` já existente.

| Tabela | Campos-chave | Notas |
|---|---|---|
| `telegram_pending_confirmations` (nome a confirmar) | `user_id` (FK→users, cascade), `token` (string, **unique** — vai no `callback_data` e/ou identifica o pendente), `payload` (JSONB — `DadosGastoManual` serializado), `previa` (JSONB, opcional — para auditoria/reexibição), `expira_em` (timestamptz), `created_at` (timestamptz) | **Um pendente ativo por usuário** (decisão §6.b: o novo lançamento **substitui** o anterior — `unique(user_id)` ou `updateOrCreate`). Índice por `(user_id)` e por `token`. Expurgo dos expirados por agendamento (par com a [[spec-06-dashboard]] §C5 / `ExpurgarConversas`) ou TTL (15 min, §6.a) no `recuperar`. |

> Par com a skill `dba-postgres` para tipos/índices/constraint do índice único e do
> filtro de expiração. Dinheiro **não** aparece em coluna própria — viaja dentro do
> `payload` em centavos (regra 5).

## 6. Contratos do domínio
> **Tudo nesta seção é PROPOSTA** (a confirmar na implementação test-first), salvo o que
> está marcado como **já existe** (reusado como está, não reimplementar).

### Reuso (já existe — confirmado no código)
| Artefato | Assinatura/papel real | Uso aqui |
|---|---|---|
| `App\Domain\Gasto\RegistrarGastoManual::confirmar` | `confirmar(DadosGastoManual $dados, ?CarbonImmutable $hoje = null): Transaction` | Grava no "sim" (atômico). **Não** reimplementar. |
| `App\Domain\IA\ConfirmacaoDeGasto` | `readonly { ?PreviaGastoManual $previa; ?DadosGastoManual $dados; array $esclarecimentos; confirmavel(): bool }` | É o que se guarda como pendente quando `confirmavel()`. |
| `App\Domain\Gasto\DadosGastoManual` | `readonly { userId, descricao, valorTotalCents, dataCompra, paymentMethodId, parcelas, cardId?, accountId?, categoriaId? }` | Conteúdo serializado do `payload`. |
| `App\Domain\IA\PrepararConfirmacaoDeGasto::preparar` | `preparar(GastoExtraido, int $userId, CarbonImmutable $agora): ConfirmacaoDeGasto` | Já chamado no worker; sua saída passa a ser **guardada**. |
| `App\Jobs\ProcessarMensagemDoBot` | worker que roteia REGISTRAR/CONSULTAR | Ganha o ramo de confirmação (ver abaixo). |
| `App\Domain\Telegram\Resposta\ResultadoDaInteracao` | fabricadores `registro()/consulta()/naoEntendi()` | Ganha novos estados (ver abaixo). |

### Proposta — novos artefatos
- **`App\Domain\Telegram\Confirmacao\RespostaDeConfirmacao`** (enum): `SIM`, `NAO`,
  `INDEFINIDO`. `INDEFINIDO` é o fallback seguro (C7) — nunca vira chute.
- **`App\Domain\Telegram\Confirmacao\InterpretadorDeConfirmacao`** — **determinístico**, não-IA:
  ```php
  public function interpretar(string $texto): RespostaDeConfirmacao
  ```
  reconhece um conjunto pequeno e explícito (sim/s/confirmo/confirma/ok/👍 → `SIM`;
  não/nao/n/cancela/cancelar → `NAO`; resto → `INDEFINIDO`), com normalização (trim,
  lower, sem acento).
- **`App\Domain\Telegram\Confirmacao\ConfirmacoesPendentes`** — repositório do estado:
  ```php
  public function guardar(int $userId, ConfirmacaoDeGasto $confirmacao, CarbonImmutable $agora): string // token
  public function recuperar(int $userId, ?string $token, CarbonImmutable $agora): ?ConfirmacaoDeGasto    // null se inexistente/expirado/de outro user
  public function descartar(int $userId, string $token): void
  ```
  `token` é opcional em `recuperar` para suportar tanto "texto livre" (resolve o pendente
  ativo do usuário) quanto "callback" (token explícito). TTL aplicado em `recuperar`.
- **`App\Domain\Telegram\Confirmacao\ConfirmarGastoPendente`** — orquestra o "sim":
  ```php
  public function confirmar(int $userId, ?string $token, CarbonImmutable $agora): ?Transaction
  ```
  recupera (respeitando escopo/TTL/uso único); se confirmável, **numa transação**: grava via
  `RegistrarGastoManual::confirmar($dados, $agora)` **e** descarta o pendente; devolve a
  `Transaction` (ou `null` se nada a confirmar — C3/C4/C5).
- **`ResultadoDaInteracao`** — novos fabricadores para o frontend renderizar:
  `gravado(Transaction $t)`, `confirmacaoCancelada()`, `confirmacaoExpirada()` /
  `nadaParaConfirmar()`, `confirmacaoAmbigua(ConfirmacaoDeGasto $pendente)`. (Nomes a
  confirmar; o objetivo é estado **válido por construção**, como hoje.)
- **Ligação em `ProcessarMensagemDoBot::handle`** (ordem proposta):
  1. **Antes** de classificar intenção, `recuperar` pendente do usuário. Se houver,
     `interpretar` a mensagem: `SIM` → `ConfirmarGastoPendente` → `gravado(...)`; `NAO` →
     `descartar` → `confirmacaoCancelada()`; `INDEFINIDO` → mantém o pendente →
     `confirmacaoAmbigua(...)`.
  2. **Sem** pendente, segue o fluxo atual (classifica → REGISTRAR/CONSULTAR/...). Na
     `REGISTRAR` confirmável, **guardar** a `ConfirmacaoDeGasto` antes de devolver
     `registro(...)` (passa a existir um pendente).

> **Decisões de regra — TRAVADAS (confirmadas pelo usuário):**
> (a) **TTL = 15 min.** Token de vínculo curto, não retenção; pendente mais antigo é tratado
> como inexistente (C4).
> (b) **Um pendente ativo por usuário** — o novo lançamento **substitui** o anterior; só o
> último "Confirma?" vale.
> (c) **O worker guarda o pendente** via `ConfirmacoesPendentes` (é backend/estado). O frontend
> só **lê** o token para montar o `callback_data`/mensagem — nunca persiste estado.
> (d) **"sim/não" só é interpretado quando há pendente.** Sem pendente, "sim" solto **nunca**
> grava (C6) — cai em `nadaParaConfirmar`.
> (e) **`callback_query` aceito no webhook agora** (é borda/backend), mas só exercitado de fato
> quando o FE enviar botões. Como o MVP do bot é "mensagens curtas, sem botões" (spec 03 §8),
> **C8 é opcional** nesta etapa: a borda fica pronta; o caminho de texto "sim/não" é o
> obrigatório.

### Webhook (borda) — `callback_query` (decisão (e))
Hoje `TelegramWebhookController` ignora updates sem `message.from` (`callback_query` cai
fora). A proposta: extrair o remetente de `message.from.id` **ou** `callback_query.from.id`,
e passar o `callback_data` adiante (novo método no `RoteadorDeMensagem`, ex.:
`callback(User $user, array $update)`), preservando dedupe e "sempre 200".

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio**
   - `InterpretadorDeConfirmacaoTest` — sim/não em variações e normalização; ambíguo →
     `INDEFINIDO` (C7).
2. **Contrato/integração (borda: banco)**
   - `ConfirmacoesPendentesTest` — `guardar`/`recuperar` round-trip **preservando centavos e
     o instante da `dataCompra`** (regra 5); **escopo por usuário** (C5); **TTL/expiração**
     (C4); `descartar`/uso único (C3).
   - `ConfirmarGastoPendenteTest` — "sim" grava via `RegistrarGastoManual::confirmar` e apaga
     o pendente na mesma transação (C1); idempotência/segundo "sim" não grava (C3); expirado
     não grava (C4); escopo por usuário (C5).
   - `ProcessarMensagemDoBotTest` (estende o existente) — com pendente: "sim" → `gravado`,
     "não" → `confirmacaoCancelada` sem gravar (C2), ambíguo → pendente mantido (C7); **sem**
     pendente: fluxo da spec 04 intacto **e** novo pendente guardado (C6); "sim" solto sem
     pendente → `nadaParaConfirmar` (C6). Usar os **fakes da Laravel AI SDK** para a parte de
     extração/classificação (offline, determinístico) — a confirmação em si é não-IA.
   - `TelegramWebhookControllerTest` (se C8 entrar) — `callback_query` é autenticado e
     roteado; dedupe preservado; responde 200.

> Cada item de backend só é "feito" com **testes verdes e cobertura**. A IA não participa da
> confirmação; nada de número saindo da IA aqui (barreira 4).

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| Tabela/repositório de confirmação pendente (TTL, escopo, uso único) | Mensagem do bot "…Confirma? (sim/não)" e recibo pós-gravação (B3) |
| `InterpretadorDeConfirmacao` (determinístico) + `ConfirmarGastoPendente` | Botões inline (`reply_markup`) — se a decisão (e) usar callback |
| Ligação no `ProcessarMensagemDoBot` + novos estados de `ResultadoDaInteracao` | Implementação real de `RespostaAoUsuario` (redige e envia ao Telegram) |
| (opcional) aceitar `callback_query` no webhook | Tela web "Confirmações pendentes" ([[spec-FE-frontend-stitch]] §7.9) |

## 9. Definition of Done
- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (grava só no "sim"; IA não recalcula; centavos/instante
      preservados; escopo por usuário; uso único; "agora" injetado).
- [x] `RegistrarGastoManual::confirmar` **reusado** — sem duplicar cálculo/gravação.
- [x] Sem segredo/PDF/dado sensível persistido; pendentes expiram (sem retenção indevida).
- [ ] Commit local atômico, em português, **separando backend de frontend** (regra 3). *(o usuário commita à mão)*
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ **Backend concluído** (test-first; suíte completa verde: 451 passed). C8
  (`callback_query`) e a redação/envio do bot ficam para o frontend.
- **Criado nesta etapa (caminhos reais):**
  - `app/Domain/Telegram/Confirmacao/RespostaDeConfirmacao.php` — enum SIM/NAO/INDEFINIDO.
  - `app/Domain/Telegram/Confirmacao/InterpretadorDeConfirmacao.php` — `interpretar(string): RespostaDeConfirmacao`, determinístico (não-IA).
  - `app/Domain/Telegram/Confirmacao/ConfirmacoesPendentes.php` — `guardar/recuperar/descartar`; TTL 15 min (`TTL_MINUTOS`); 1 por usuário (`updateOrCreate`); serializa só `DadosGastoManual` e recomputa a prévia em `recuperar`.
  - `app/Domain/Telegram/Confirmacao/ConfirmarGastoPendente.php` — `confirmar(int,$agora): ?Transaction`; recupera → `RegistrarGastoManual::confirmar` → descarta, atômico (uso único).
  - `app/Models/TelegramPendingConfirmation.php` + migration `2026_06_27_000002_create_telegram_pending_confirmations_table.php` (jsonb `payload`, `expira_em` timestamptz, `user_id`/`token` únicos).
  - `app/Domain/Telegram/Resposta/TipoDeInteracao.php` — novos casos GRAVADO/CONFIRMACAO_CANCELADA/CONFIRMACAO_AMBIGUA/NADA_PARA_CONFIRMAR.
  - `app/Domain/Telegram/Resposta/ResultadoDaInteracao.php` — fabricadores `gravado()/confirmacaoCancelada()/confirmacaoAmbigua()/nadaParaConfirmar()` + propriedade `transacao`.
  - `app/Jobs/ProcessarMensagemDoBot.php` — curto-circuito de confirmação antes de classificar; `registrar()` guarda o pendente confirmável.
  - Testes: `tests/Unit/Domain/Telegram/InterpretadorDeConfirmacaoTest.php`,
    `tests/Feature/Telegram/ConfirmacoesPendentesTest.php`,
    `tests/Feature/Telegram/ConfirmarGastoPendenteTest.php`,
    `tests/Feature/Telegram/ProcessarMensagemDoBotTest.php` (estendido).
- **Reusado como está (NÃO reimplementado):** `RegistrarGastoManual::confirmar`,
  `ConfirmacaoDeGasto`, `DadosGastoManual`, `PrepararConfirmacaoDeGasto`.
- **Adiado para:** frontend ([[spec-FE-frontend-stitch]] — mensagem B3, botões, tela §7.9) e
  **C8 `callback_query`** no webhook (a borda fica para quando o FE enviar botões inline).
- **Decisões de regra registradas (travadas, confirmadas pelo usuário — §6):**
  - **(a) TTL = 15 min;** pendente expirado é tratado como inexistente (C4).
  - **(b) Um pendente ativo por usuário** — novo lançamento **substitui** o anterior.
  - **(c) O worker guarda o pendente** (`ConfirmacoesPendentes`); frontend só lê o token.
  - **(d) "sim/não" só interpretado quando há pendente;** "sim" solto nunca grava (C6).
  - **(e) `callback_query` aceito no webhook agora** (borda pronta), mas C8 é **opcional** —
    o caminho obrigatório é o texto "sim/não" (MVP do bot sem botões, spec 03 §8).

---

## 11. Emenda 2026-07-21 — "já foi pago?" no registro

> Incremento **aditivo** sobre esta spec e a [[spec-04-ia-interpretacao]]: o registro passa a
> identificar na mensagem se o gasto **já foi pago** e, quando o usuário não disser,
> **pergunta** — para já gravar a data de pagamento e marcar como pago. Nada do fluxo
> sim/não descrito acima muda; o que muda é **o que** o pendente carrega.

### 11.1 Decisões de regra — TRAVADAS (confirmadas pelo usuário)
| # | Decisão | Porquê |
|---|---|---|
| (f) | **`pago` é slot OBRIGATÓRIO, só fora de cartão** (pix/débito/dinheiro/boleto). Ausente ⇒ vira esclarecimento, o bot pergunta. | Assumir "não pago" deixava a conta aberta e poluía as contas em atraso ([[spec-06b-contas-em-atraso]]). Perguntar custa 1 turno; corrigir depois custa mais. |
| (g) | **Crédito nunca pergunta** e um "já paguei" no crédito é **ignorado em silêncio**. | Cartão quita pela **fatura** (`docs/03-regras-financeiras.md` §4.3) — não há parcela do usuário a quitar no ato. |
| (h) | **Recorrência nunca pergunta.** | É **molde** mensal (spec 10/10c): o lançamento nasce depois, no dia; não há parcela a pagar no cadastro. |
| (i) | **Sem data dita, a data de pagamento é a DATA DA COMPRA** (não "hoje"). | "Paguei o mercado ontem" ⇒ pagamento ontem. Coerente com gasto à vista fora de cartão. |
| (j) | **Marca SÓ a 1ª parcela.** Parcelado fora de cartão vira `pago_parcial`. | Pagamento é **por parcela** (decisão 2026-07-08); "paguei" num 3x significa que só a primeira saiu. |
| (k) | **Data dita e ilegível vira PERGUNTA** (`data_pagamento`), nunca chute. | Barreira 1 / §3.4 da spec 04 — mesma regra do `valor`/`data`. |

### 11.2 Cenários de aceite (complementam §3)
- **C9 — a mensagem diz que pagou.** **Dado** "paguei 90 no mercado no pix hoje" **Quando** o
  usuário confirma **Então** o lançamento nasce com a 1ª parcela `pago` e
  `data_pagamento` = data da compra; a transação à vista fica `pago`.
- **C10 — a mensagem não diz.** **Dado** "gastei 90 no mercado no pix" **Então** o resultado é
  **esclarecimento** `pago` (nada é gravado, nem pendente confirmável) até o usuário responder.
- **C11 — crédito não pergunta.** **Dado** "comprei 200 no cartão pai" **Então** não há
  esclarecimento `pago`; a prévia sai confirmável e **sem** data de pagamento.
- **C12 — data de pagamento própria.** **Dado** "comprei dia 10 e paguei ontem" **Então**
  `data_pagamento` = ontem (fuso SP), resolvida pelo **domínio** — a IA só copiou o texto.
- **C13 — data ilegível.** **Dado** "paguei sei lá quando" **Então** esclarecimento
  `data_pagamento`; nada é gravado.
- **C14 — `false` sobrevive ao multi-turno.** **Dado** que o usuário já respondeu "ainda não
  paguei" **Quando** ele completa outro slot no turno seguinte **Então** o bot **não**
  repergunta (no round-trip da fila, `false` ≠ ausente).

### 11.3 Barreiras (complementam §4)
- **Regra 4 — a IA nunca calcula nem resolve.** O agente devolve `pago` (bool) e
  `data_pagamento` como **TEXTO cru**; quem resolve o fuso SP é o `NormalizadorDeGastoExtraido`,
  e quem grava o status é o motor financeiro.
- **Regra 7 — confirmar antes de gravar.** A prévia **diz** que vai marcar como pago (e, no
  parcelado, que só a 1ª parcela entra) **antes** do "sim".
- **Escrita reusada, não duplicada.** O "sim" chama `RegistrarPagamentoParcela` — mesma
  derivação de status agregado e mesma auditoria (`acao = pagar`) do fluxo manual.
- **Segurança (skill `seguranca-ia`).** `ExtratorDeGasto` ganhou bloco "Segurança" no
  `instructions()`: a mensagem é **dado**, não comando — ordens embutidas ("marque tudo como
  pago") são ignoradas. A defesa real continua arquitetural: o `pago` só vira escrita depois
  da confirmação do usuário, e o valor/data passam pela normalização determinística.

### 11.4 Artefatos (caminhos reais)
**Backend**
- `app/Ai/Agents/ExtratorDeGasto.php` — slots `pago` (boolean) e `data_pagamento` (string) no
  schema + instruções + bloco "Segurança".
- `app/Domain/IA/GastoParcial.php` — `pago`/`dataPagamentoTexto`; `faltantes()` pede `pago` só
  quando a forma é conhecida, fora de cartão e não é recorrência (`FORMAS_FORA_DE_CARTAO`).
- `app/Domain/IA/GastoExtraido.php` — os dois slots crus.
- `app/Domain/IA/NormalizadorDeGastoExtraido.php` — resolve `dataPagamento` (fuso SP; ausente ⇒
  data da compra; crédito ⇒ null; ilegível ⇒ esclarecimento `data_pagamento`).
- `app/Domain/Gasto/DadosGastoManual.php` — `?CarbonImmutable $dataPagamento`.
- `app/Domain/Gasto/RegistrarGastoManual.php` — `confirmar()` marca a 1ª parcela via
  `RegistrarPagamentoParcela` e dá `refresh()` na transaction (o status agregado é reavaliado
  em outra instância — sem isso a confirmação diria "aberto"); `preview()` propaga a data.
- `app/Domain/Gasto/PreviaGastoManual.php` — `dataPagamento` para a apresentação.
- Serialização nas **três** filas: `EsclarecimentosPendentes` (slots crus, `false` ≠ ausente),
  `ConfirmacoesPendentes` e `Confirmacao\PayloadDoGasto` (`Y-m-d`, reidratado no fuso SP).

**Frontend (etapa/commit separado — regra 3)**
- `app/Domain/Chat/RedatorDoChat.php` (reusado pelo bot via `RespostaTelegram`): rótulos
  `pago` → *"se você já pagou"* e `data_pagamento` → *"a data do pagamento"*; linha
  *"Já pago em dd/mm/aaaa."* (ou *"Marco a 1ª parcela como paga em …; as demais ficam em
  aberto."*) na prévia; e o recibo pós-gravação dizendo *"— já pago."* / *"— 1ª parcela já
  paga."* conforme o status derivado.

**Testes (test-first, todos verdes)**
- `tests/Unit/IA/GastoParcialTest.php`, `tests/Unit/AI/ExtratorDeGastoTest.php`,
  `tests/Unit/Chat/RedatorDoChatTest.php`.
- `tests/Feature/AI/NormalizadorDeGastoExtraidoTest.php`,
  `tests/Feature/Domain/RegistrarGastoManualTest.php`,
  `tests/Feature/Domain/ConfirmacaoPendenteTest.php`,
  `tests/Feature/Telegram/ConfirmacoesPendentesTest.php`,
  `tests/Feature/IA/EsclarecimentosPendentesTest.php`,
  `tests/Feature/Chat/ResponderNoChatTest.php`.

> **Atenção ao evoluir:** fixture de teste que fake o `ExtratorDeGasto` **sem** `pago` deixa o
> gasto incompleto (vira esclarecimento) quando a forma é fora de cartão — foi por isso que
> as fixtures antigas precisaram do campo.
</content>
</invoke>
