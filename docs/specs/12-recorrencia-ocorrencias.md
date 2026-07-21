# Spec 12 — Recorrência como ocorrência mensal (fim da dupla contagem)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os critérios e
> implemente **test-first** (regra 2), **backend antes do frontend** (regra 3). Em qualquer
> dúvida de regra, o **escopo final** e os `docs/` prevalecem — não invente regra financeira.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Correção estrutural — **substitui** o modelo de materialização de [[spec-10-recorrencia-mensal]], [[spec-10b-recorrencia-previsao-dashboard]] e [[spec-10c-recorrencia-via-bot]] |
| **Status** | ✅ Implementado (F1–F7) |
| **Depende de** | `recurrences` (spec 10) · `status_pagamento` · `cards` + `CalculadoraDeVencimento` (doc 03 §4.2) |
| **Habilita** | Recorrência em **cartão de crédito** · "marcar recorrência como paga no mês" · fim da duplicação recorrência × lançamento |
| **Fonte de verdade** | [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) §4.2 (vencimento), §4.4 (status), §4.5 (disponível), §4.6 (recorrências) |
| **Regras críticas** | 2 (TDD) · 3 (frontend separado) · 4 (IA nunca calcula) · 5 (centavos/fuso SP) · 7 (confirmação — ver §4, decisão D1) |

---

## 1. Problema

Hoje uma recorrência vive em **três representações concorrentes** e o resultado final soma as
três em situações de borda:

1. **Molde** (`recurrences`) — projetado read-only por `ProjetarRecorrencias`.
2. **Fila** (`pending_confirmations`, origem `recorrencia`) — projetada por
   `ProjetarRecorrenciasPendentes`.
3. **Lançamento real** (`transactions` + `installments`, com `transactions.recurrence_id`).

A separação entre as três é feita por um **ponteiro** (`recurrences.proxima_em`), não por uma
chave. Basta o ponteiro e a data do lançamento discordarem para a mesma conta aparecer duas
vezes no extrato, no donut e no disponível. O caso reportado é o **primeiro lançamento**: o
formulário grava um gasto avulso do mês corrente **e** cria a recorrência
(`GastoController::store` / `LancamentoFormController::update`); quando a data do lançamento cai
no mesmo mês da primeira ocorrência do molde, o mês exibe **as duas linhas**.

Além disso o modelo atual **proíbe recorrência em cartão de crédito**
(`RegistrarRecorrencia::recusarCartaoDeCredito`), o que não corresponde ao uso real
(assinaturas quase sempre caem no cartão).

## 2. Objetivo

Tornar a **ocorrência mensal** a única representação de uma recorrência num mês, numa tabela
própria (`recurrence_occurrences`), com chave única `(recurrence_id, competencia)`. Recorrência
**nunca** gera linha em `transactions`/`installments`. A anti-dupla-contagem passa a ser uma
**constraint do banco**, não um guard de calendário.

## 3. Decisões de regra (tomadas pelo usuário — 2026-07-21)

- **D1 — A ocorrência substitui a fila de confirmação.** O agendador gera a ocorrência do mês
  direto, com status `aberto`; **não** enfileira `pending_confirmations`. A regra 7 é honrada
  **no cadastro da recorrência** (o usuário confirma uma vez o molde que autoriza as cobranças
  mensais); repetir a confirmação todo mês é ruído, não proteção. `PendingConfirmation` com
  origem `recorrencia` deixa de ser usada e a cascata "rejeitar → cancela a recorrência" (C7)
  é removida.
- **D2 — A recorrência vale já no mês do cadastro.** Criar "todo dia 5" no dia 21/07 gera a
  ocorrência de **07/2026** (venc. 05/07, já vencida ⇒ exibida em atraso, com o botão de marcar
  paga) — e **nenhuma** linha em `transactions`.
- **D3 — Cartão de crédito é permitido e liquida sozinho, pela DATA DE COBRANÇA.** A ocorrência
  de cartão guarda **duas datas**: a `data_cobranca` (o dia do molde — quando o cartão é
  efetivamente debitado) e o `vencimento` da fatura em que ela cai
  (`CalculadoraDeVencimento::cartao`, doc 03 §4.2), que define a **competência**. Ela é marcada
  `pago` **automaticamente assim que `data_cobranca <= hoje`** — sem botão, sem esperar o
  vencimento da fatura: do ponto de vista do usuário a cobrança já aconteceu, o cartão cuida do
  resto. `data_pagamento` recebe a própria `data_cobranca` (verdade histórica, não "hoje").
  Fora de cartão, `data_cobranca == vencimento` (o dia do molde) e a liquidação **nunca** é
  automática: depende do **"marcar como paga"** do usuário.
  - **Corolário (o outro lado da mesma regra): "marcar como paga" SÓ existe fora de cartão.**
    Os dois caminhos são mutuamente exclusivos — o que o agendador liquida, o usuário não
    marca; o que o usuário marca, o agendador não toca. Oferecer o botão no cartão pagaria a
    conta duas vezes (a cobrança já entra na fatura, doc 03 §4.3) e entraria em vaivém com
    `LiquidarOcorrenciasDeCartao`. A recusa vale em **toda superfície** e é do domínio
    (`PagarOcorrencia`/`ReverterPagamentoOcorrencia` lançam
    `PagamentoNaoPermitidoException::ehCartao()`), não da tela — ver R10/R10b e spec 13 §4.1.
  - Vale **inclusive no cadastro**: criar "todo dia 5" no cartão em 21/07 nasce **já `pago`**.
    Criar "todo dia 25" em 21/07 nasce `aberto` e é liquidado sozinho quando o dia 25 chegar.
- **D4 — Histórico legado é convertido.** Migration de dados: cada `transaction` com
  `recurrence_id` vira uma ocorrência na competência do vencimento da sua parcela, preservando
  status e `data_pagamento`; a transaction e suas parcelas são **removidas**. A coluna
  `transactions.recurrence_id` é dropada.
- **D5 (decorrente de D4) — Ligar o switch "é recorrente" num lançamento existente converte.**
  A transaction editada é substituída pela ocorrência da sua competência (mesma regra da
  migration), em vez de coexistir com o molde.

## 4. Barreiras e invariantes

- **Uma ocorrência por recorrência por competência** — `UNIQUE (recurrence_id, competencia)`.
  Toda geração é idempotente por construção (`insertOrIgnore`/upsert); rodar o agendador duas
  vezes no mesmo dia não duplica.
- **Recorrência nunca escreve em `transactions`/`installments`.** Teste explícito em cada
  caminho de cadastro (web, form, bot).
- **Fonte única por competência.** Competência `<=` mês corrente ⇒ **linha real** em
  `recurrence_occurrences`. Competência `>` último mês gerado ⇒ **projeção read-only**
  (`ProjetarRecorrencias`), que passa a excluir por `NOT EXISTS` a competência já
  materializada — não mais pelo ponteiro.
- **Centavos BIGINT / fuso America/Sao_Paulo / "agora" injetado** (regras 4 e 5). Nenhum
  serviço de domínio lê o relógio global.
- **Escopo estrito por `user_id`** em toda query; `user_id` é coluna própria da ocorrência
  (não só via `recurrence_id`), para índice e isolamento diretos.
- **Ocorrência é uma cobrança auto-contida:** guarda o *snapshot* de descrição, valor,
  categoria, forma e cartão. Editar o molde ("este e os próximos") **não** reescreve o passado.
- **Soft delete + auditoria** (`audit_log`) na ocorrência (LGPD).
- **A IA não calcula** — valor e vencimento vêm do molde e da `CalculadoraDeVencimento`.

## 5. Modelo de dados

### 5.1 `recurrence_occurrences` (nova)

| Coluna | Tipo | Nota |
|---|---|---|
| `id` | bigserial | |
| `user_id` | FK `users` cascade | escopo/índice direto |
| `recurrence_id` | FK `recurrences` cascade | |
| `competencia` | `char(7)` | `YYYY-MM` — **mês de vencimento** (§4.5) |
| `descricao` | string | snapshot do molde |
| `valor_cents` | bigint, `>= 0` | regra 5 |
| `data_cobranca` | date | **quando sai do bolso**: dia do molde na competência de origem. Fora de cartão == `vencimento`. Gatilho da liquidação automática de cartão (D3) |
| `vencimento` | date | fora de cartão = dia do molde; cartão = vencimento da **fatura** (define a competência) |
| `payment_method_id` | FK `payment_methods` | snapshot |
| `card_id` | FK `cards`, nullable | preenchido quando a forma é `credito` |
| `categoria_id` | bigint nullable | snapshot |
| `status_id` | FK `status_pagamento` | `aberto` · `pago` · `cancelado` |
| `data_pagamento` | timestamptz nullable | |
| timestamps + softDeletes (tz) | | |

**Constraints/índices:** `UNIQUE (recurrence_id, competencia)` (parcial, `deleted_at IS NULL`);
`INDEX (user_id, vencimento)`; `INDEX (user_id, status_id)`;
`CHECK (valor_cents >= 0)`; `CHECK (card_id IS NOT NULL OR payment_method_id <> <credito>)`
→ como o id de `credito` não é constante, a regra fica no **domínio** (não em CHECK).

### 5.2 `recurrences` (alteração)

- `+ card_id` FK `cards` nullable — obrigatório (no domínio) quando a forma é `credito`.
- `proxima_em` **muda de semântica**: passa a ser "primeira competência ainda não gerada"
  (continua `date`, sempre o 1º dia da competência não gerada). Mantido para varredura barata.

### 5.3 Remoções

- `transactions.recurrence_id` (drop, após a conversão de D4).
- `pending_confirmations.recurrence_id` deixa de ser preenchido (coluna mantida, sem uso, para
  não quebrar histórico) e `origem = 'recorrencia'` deixa de ser gerada.

### 5.4 Migration de dados (D4)

Para cada `transaction` com `recurrence_id` não nulo (incluindo soft-deleted? **não** — só as
vivas), tomando a **primeira parcela** (recorrência é sempre 1×): cria a ocorrência na
competência do `vencimento`, com `status_id`/`data_pagamento` da parcela e o snapshot da
transaction; depois apaga parcelas e transaction (hard delete — o `audit_log` preserva o
rastro). Conflito com ocorrência já existente ⇒ ignora (idempotência).

## 6. Contratos do domínio (`App\Domain\Recorrencia\`)

| Classe | Papel |
|---|---|
| `RegistrarRecorrencia` (alterada) | Aceita cartão (`cardId`); **gera a ocorrência da competência corrente** (D2) — já `pago` se for cartão com `data_cobranca <= hoje` (D3) — e aponta `proxima_em` para a competência seguinte. Some o `recusarCartaoDeCredito`. |
| `GerarOcorrencias` (nova, substitui `MaterializarRecorrencias`) | Varre recorrências `ativo` com `proxima_em <= 1º dia do mês corrente` e gera **todas** as competências faltantes até o mês corrente (recupera agendador parado), sob lock, idempotente pela unique. |
| `CalcularOcorrencia` (nova, pura) | `(Recurrence, competência) → {dataCobranca, vencimento, competenciaEfetiva}`: `dataCobranca` = `OcorrenciaMensal` do dia do molde no mês pedido; fora de cartão ⇒ `vencimento = dataCobranca`; cartão ⇒ `vencimento = CalculadoraDeVencimento::cartao(dataCobranca, …)` e a competência passa a ser a do **vencimento resultante**. |
| `LiquidarOcorrenciasDeCartao` (nova) | Marca `pago` com `data_pagamento = data_cobranca` as ocorrências **de cartão** `aberto` cuja `data_cobranca <= hoje` (D3). Roda no mesmo comando agendado. Nunca toca ocorrência fora de cartão. |
| `PagarOcorrencia` (nova, substitui `PagarRecorrenciaPendente`) | "Marcar como paga" — só **fora de cartão**; escopo por usuário; idempotente; auditoria. |
| `ProjetarRecorrencias` (alterada) | Projeta só competências **sem ocorrência** (`NOT EXISTS`), do mês corrente em diante. |
| `ConsultarOcorrencias` (nova) | Leitura das ocorrências reais por competência/janela, com status de exibição derivado por data (`pago` · `previsto` · `atraso`) e id **opaco**. |
| `ProjetarRecorrenciasPendentes` | **Removida** (D1). |
| `SincronizarRecorrencia` (alterada) | Continua propagando ao molde; deixa de recusar cartão; passa a atualizar as ocorrências **futuras já geradas** (nenhuma, no desenho atual) e o `proxima_em`. |
| `EditarOcorrencia` (nova) | "Só este mês": edita valor/descrição/categoria/vencimento da ocorrência, com auditoria. |
| `CancelarRecorrencia` (alterada) | Ao cancelar, as ocorrências **em aberto de competências futuras** viram `cancelado`; as passadas ficam como estão. |

### Consumidores a religar

`ConsultarLancamentos` (extrato) · `ConsultarGastos` (donut/bot) · `ConsultarProximasContas` ·
`ConsultarContasVencidas` · `ResumoDoMes` · `ConsultarDisponivelDoMes` (ocorrências do mês
abatem o disponível) · `ConsultarFaturaCartao` (ocorrências de cartão entram na fatura da
competência) · `GastoController::store` · `LancamentoFormController::update` ·
`ConfirmarGastoPendente` (bot) · rota `POST /lancamentos/recorrencia/{pendente}/pagar` →
`{ocorrencia}`.

## 7. Cenários de aceite (Given-When-Then)

**Anti-duplicação (o bug)**
- **R1 — Dado** o formulário de gasto com "é recorrente, dia 5" em 21/07, **Quando** o usuário
  salva, **Então** existe **1** recorrência, **1** ocorrência em `2026-07` (venc. 05/07) e
  **0** linhas em `transactions`/`installments`.
- **R2 — Dado** R1, **Quando** o extrato de 07/2026 é montado, **Então** a conta aparece
  **uma única vez**, com status `atraso`; o donut e o disponível a contam **uma vez**.
- **R3 — Dado** R1, **Quando** o usuário navega para 08/2026 (mês futuro, ainda não gerado),
  **Então** a ocorrência aparece **projetada** (`prevista = true`) uma única vez.
- **R4 — Dado** R1 e o agendador rodando em 05/08, **Quando** 08/2026 é montado, **Então** a
  linha é a **ocorrência real** (não a projeção) — sem duplicar.

**Geração**
- **R5 — Dado** o agendador parado por 3 meses, **Quando** ele roda, **Então** as 3
  competências faltantes são geradas, uma cada, e uma segunda execução no mesmo dia **não**
  gera nada (idempotência pela unique).
- **R6 — Dado** `dia = 31`, **Quando** a competência é fevereiro, **Então** o vencimento é
  28/29 (clamp de `OcorrenciaMensal`).

**Cartão (D3)**
- **R7 — Dado** uma recorrência em cartão (fecha dia 20, vence dia 28) "todo dia 25",
  **Quando** a ocorrência de 07/2026 é gerada, **Então** `data_cobranca` = **25/07**, o
  vencimento é **28/08** (compra após o fechamento entra na fatura seguinte) e a competência é
  **2026-08**.
- **R8 — Dado** R7, **Quando** a fatura de 08/2026 é consultada, **Então** a ocorrência
  aparece itemizada e soma no total — **mesmo já estando `pago`** (a fatura é extrato, §4.4).
- **R9 (cadastro já pago) — Dado** "hoje" = 21/07 e uma recorrência **em cartão** "todo dia 5",
  **Quando** o usuário a cadastra, **Então** a ocorrência nasce **`pago`** com `data_pagamento`
  = **05/07** (`data_cobranca <= hoje`), sem nenhuma ação do usuário e sem botão de pagar.
- **R9b (cobrança futura) — Dado** "hoje" = 21/07 e uma recorrência em cartão "todo dia 25",
  **Quando** ela é cadastrada, **Então** a ocorrência nasce **`aberto`**; **Quando** o comando
  agendado roda em 25/07, **Então** ela passa a `pago` com `data_pagamento` = 25/07.
- **R9c (fora de cartão não liquida sozinho) — Dado** uma recorrência **PIX** "todo dia 5" e
  "hoje" = 21/07, **Quando** ela é cadastrada e o comando agendado roda, **Então** a ocorrência
  continua `aberto` (exibida em `atraso`) — só o usuário a marca como paga.
- **R10 — Dado** uma ocorrência de **cartão**, **Quando** o usuário tenta "marcar como paga",
  **Então** a operação é **recusada** (cartão liquida sozinho) — **em qualquer superfície**:
  extrato, quadros do dashboard, bot. A linha nem sequer oferece o botão, e a recusa é do
  domínio (`PagarOcorrencia`), não da tela.
- **R10b — Dado** uma recorrência de **cartão** cuja competência ainda é só **previsão**
  (`ProjetarRecorrencias`, sem ocorrência gerada), **Quando** se tenta materializá-la sob
  demanda para pagá-la, **Então** a operação é **recusada e nada é criado** — a cobrança em
  cartão nasce e liquida pelo agendador (D3), nunca por clique do usuário.

**Fora de cartão (marcar paga)**
- **R11 — Dado** uma ocorrência PIX `aberto` vencida, **Quando** o usuário marca como paga,
  **Então** vira `pago` com `data_pagamento` = agora, sai do quadro de vencidas e o extrato a
  mostra com selo `pago`. Um segundo clique é **idempotente** (nada muda).
- **R12 — Dado** uma ocorrência de outro usuário, **Quando** se tenta pagá-la, **Então** 404 —
  nada muda.

**Ciclo de vida / legado**
- **R13 — Dado** uma recorrência cancelada, **Quando** o agendador roda, **Então** nenhuma
  ocorrência nova nasce e as futuras em aberto ficam `cancelado`; as passadas permanecem.
- **R14 — Dado** o banco legado com uma transaction recorrente paga, **Quando** a migration
  roda, **Então** existe a ocorrência equivalente (`pago`, mesma `data_pagamento`,
  mesma competência) e a transaction/parcelas não existem mais; totais do mês **inalterados**.
- **R15 — Dado** o bot ("Netflix 55,90 todo dia 5"), **Quando** o usuário confirma, **Então**
  nasce o molde + a ocorrência do mês corrente e **nenhuma** transaction.

## 8. Plano de testes (test-first — devem falhar primeiro)

1. `tests/Feature/Domain/RecorrenciaOcorrenciaTest.php` — R1, R5, R6, R13 (geração/idempotência).
2. `tests/Feature/Domain/OcorrenciaCartaoTest.php` — R7, R9, R9b, R9c, R10 (ciclo do cartão +
   liquidação pela data de cobrança).
3. `tests/Feature/Domain/PagarOcorrenciaTest.php` — R11, R12.
4. `tests/Feature/Domain/ConsultarLancamentosTest.php` (+) e `ConsultarGastosTest.php` (+) —
   R2, R3, R4 (fonte única por competência).
5. `tests/Feature/Domain/ResumoDoMesTest.php` / `ConsultarDisponivelDoMes` / `ConsultarFaturaCartao` — R2, R8.
6. `tests/Feature/Gasto/RegistrarGastoWebTest.php` e `Lancamentos/FormularioLancamentoWebTest.php` — R1, D5.
7. `tests/Feature/IA/RecorrenciaViaBotTest.php` — R15.
8. `tests/Feature/Migrations/ConverterRecorrenciasLegadasTest.php` — R14.
9. **Remoções:** `PagarRecorrenciaPendenteTest`, `ProjetarRecorrenciasNaJanelaTest`,
   `MaterializarRecorrenciasCommandTest` (substituído por `GerarOcorrenciasCommandTest`).

## 9. Fases de implementação

| Fase | Conteúdo | Commit |
|---|---|---|
| **F1** | Migrations (`recurrence_occurrences`, `recurrences.card_id`) + model + factory | backend |
| **F2** | `CalcularOcorrencia`, `GerarOcorrencias`, `LiquidarOcorrenciasDeCartao`, comando agendado | backend |
| **F3** | `RegistrarRecorrencia` (cartão + ocorrência do mês) · `PagarOcorrencia` · `EditarOcorrencia` · `CancelarRecorrencia` | backend |
| **F4** | Religar consultas: extrato, donut, próximas contas, vencidas, disponível, fatura, `ResumoDoMes`, `ProjetarRecorrencias` | backend |
| **F5** | Bordas: `GastoController`, `LancamentoFormController`, bot, rota de pagar; remover fila/`PagarRecorrenciaPendente`/`ProjetarRecorrenciasPendentes` | backend |
| **F6** | Migration de dados (D4) + drop de `transactions.recurrence_id` | backend |
| **F7** | **Frontend** (etapa separada, regra 3): selo/estado da ocorrência, botão "marcar paga" só fora de cartão, switch de recorrência em qualquer forma, tela de recorrências | frontend |

## 10. Definition of Done

- [x] R1–R15 cobertos por testes que falhavam antes e passam depois.
- [x] Nenhum caminho de recorrência escreve em `transactions`/`installments` (asserção explícita
      em `RecorrenciaOcorrenciaTest`, `GerarOcorrenciasCommandTest`, `RegistrarGastoWebTest`,
      `FormularioLancamentoWebTest` e `RecorrenciaViaBotTest`).
- [x] `UNIQUE (recurrence_id, competencia)` presente (índice parcial `WHERE deleted_at IS NULL`)
      e exercitada pelo teste de idempotência do agendador (R5).
- [x] Suíte completa verde após a remoção dos testes da fila — **1176 passed**.
- [x] Migration de dados idempotente e testada com banco legado povoado
      (`ConverterRecorrenciasLegadasTest`).
- [ ] Commits locais atômicos por fase; frontend em commit separado. *(commits são do usuário)*
- [x] §11 preenchida com os artefatos reais.

## 11. Estado atual / artefatos

**Status:** ✅ Backend (F1–F6) e frontend (F7) implementados em 2026-07-21.

### Schema (F1 · F6)
- `database/migrations/2026_07_21_000001_create_recurrence_occurrences_table.php`
- `database/migrations/2026_07_21_000002_add_card_id_to_recurrences_table.php`
- `database/migrations/2026_07_21_000003_converter_recorrencias_legadas.php` (D4, SQL puro)
- `database/migrations/2026_07_21_000004_drop_recurrence_id_from_transactions_table.php`
- `app/Models/RecurrenceOccurrence.php` · `database/factories/RecurrenceOccurrenceFactory.php`

### Domínio (`app/Domain/Recorrencia/`)
| Arquivo | Papel |
|---|---|
| `OcorrenciaCalculada.php` | VO: `{dataCobranca, vencimento, competencia}` |
| `CalcularOcorrencia.php` | puro: molde + mês de origem → as duas datas + competência |
| `GerarOcorrencias.php` | agendador: gera as competências faltantes, sob lock, idempotente |
| `LiquidarOcorrenciasDeCartao.php` | D3: `pago` quando `data_cobranca <= hoje`, fora de cartão nunca |
| `PagarOcorrencia.php` | "marcar como paga" (só fora de cartão, idempotente, auditado) |
| `EditarOcorrencia.php` | "só este mês" (a competência acompanha o novo vencimento) |
| `ConsultarOcorrencias.php` | leitura por competência/janela/cartão + `totalDoMes` |
| `ConverterLancamentoEmRecorrencia.php` | D5: substitui o lançamento pela recorrência |
| `RegistrarRecorrencia.php` (alt.) | aceita cartão; gera a ocorrência do mês (D2) |
| `ProjetarRecorrencias.php` (alt.) | projeta só o que não tem ocorrência (`NOT EXISTS`) |
| `CancelarRecorrencia.php` (alt.) | cancela as ocorrências futuras em aberto |
| `SincronizarRecorrencia.php` (alt.) | não recusa mais cartão; não mexe no ponteiro |

**Removidos:** `MaterializarRecorrencias`, `ProjetarRecorrenciasPendentes`,
`PagarRecorrenciaPendente`, `App\Listeners\CancelarRecorrenciaAoRejeitar`,
`App\Events\PendenteRecorrenteRejeitado` (D1 — a cascata C7 deixou de existir).

### Agendador
- `app/Console/Commands/GerarOcorrenciasCommand.php` (`recorrencia:gerar`) substitui
  `recorrencia:materializar`; `routes/console.php` agenda diário às 06:00.

### Consumidores religados (F4 · F5)
`ConsultarLancamentos` · `ConsultarGastos` · `ConsultarDisponivelDoMes` (ocorrências abatem por
competência) · `ConsultarFaturaCartao` (R8) · `ResumoDoMes` · `ConsultarProximasContas` /
`ConsultarContasVencidas` (`recorrente` sempre false — recorrência saiu de `transactions`) ·
`AssinaturaDeAtualizacoes` (passa a agregar `recurrence_occurrences`) · `GastoController` ·
`LancamentoFormController` · `LancamentoController::pagarRecorrencia` ·
`RegistrarGastoRequest` (aceita crédito + `cardId`) · rota
`POST /lancamentos/recorrencia/{ocorrencia}/pagar` + binding opaco `{ocorrencia}`.

### Testes
Novos: `RecorrenciaOcorrenciaTest` · `OcorrenciaCartaoTest` · `PagarOcorrenciaTest` ·
`GerarOcorrenciasCommandTest` · `ConverterRecorrenciasLegadasTest`.
Reescritos: `RecorrenciaTest` · `ProjetarRecorrenciasTest` · `ConsultarLancamentosTest` ·
`ConsultarGastosTest` · `ConsultarFaturaCartaoTest` · `ResumoDoMesTest` ·
`ConsultarProximasContasTest` · `ConsultarContasVencidasTest` · `RegistrarGastoWebTest` ·
`FormularioLancamentoWebTest` · `LancamentosWebTest` · `LancamentoDetalheWebTest` ·
`RecorrenciaViaBotTest` · `AtualizacoesTest`.
Removidos: `PagarRecorrenciaPendenteTest` · `ProjetarRecorrenciasNaJanelaTest` ·
`MaterializarRecorrenciasCommandTest`.

### Decisões de implementação (além do spec)
- **`proxima_em` = 1º dia do primeiro MÊS DE ORIGEM não gerado.** Fora de cartão coincide com a
  competência; em cartão a competência é a da fatura e fica à frente, então o ponteiro segue o
  mês de origem. A anti-duplicação real continua sendo a UNIQUE.
- **A projeção respeita `proxima_em` como limite inferior** (`competência >= proxima_em`): sem
  isso, uma recorrência que começa em setembro apareceria prevista em julho.
- **A regra de liquidação de cartão vive num lugar só** (`LiquidarOcorrenciasDeCartao`): a
  geração a chama, em vez de reimplementar o "nasce pago" (R9).
- **A recorrência via bot segue fora de cartão** — não por proibição, mas porque o canal de
  texto não resolve *qual* cartão sem chutar; pede esclarecimento.

### Frontend (F7 — commit separado, regra 3)
- `resources/views/components/gasto/form.blade.php` — o switch "Repete todo mês?" saiu do bloco
  "só à vista" e vale em **qualquer forma, cartão inclusive** (D3); sumiram o quadro "Lançamento
  recorrente" e os radios de `escopo_recorrencia` (não existe mais lançamento recorrente, D4);
  o aviso explica que ligar o switch **substitui** o lançamento pela cobrança mensal (D5) e que
  no cartão ela já nasce paga.
- `resources/js/pages/registrar-gasto.js` — recorrência sobrevive à troca de forma; sugere o dia
  a partir do vencimento (fora de cartão); na confirmação a prévia deixa de rotular a linha como
  parcela ("1/1" → "Cobrança") e a legenda vira "Prévia — cobrança do mês".
- `resources/views/components/lancamento/row.blade.php` — o selo passa a usar o status real da
  ocorrência (pago · previsto · atraso); só a **projeção** é traduzida para "Previsto". Botão
  "marcar como paga" (não "como pago") apenas quando há alvo pagável.
- `resources/views/recorrencias.blade.php` + `RecorrenciaController` — a tela fala de **cobranças
  por mês** (não de fila de confirmação), identifica a recorrência em cartão pelo cartão e mostra
  a próxima cobrança pela data real (`CalcularOcorrencia`), não pelo ponteiro `proxima_em` cru
  (que hoje é sempre o dia 1). `ConsultarRecorrencias` passou a carregar forma e cartão.
- `resources/js/shell/dia-do-mes.js` (novo, importado em `app.js`) — campos de dia-do-mês contêm
  a faixa 1..31 **na digitação** (cartão: fechamento/vencimento; recorrência: dia da cobrança).
  Correção pedida à parte da spec: a validação de servidor e o CHECK do banco continuam sendo a
  verdade; isto só evita que o erro aconteça.

**Testes de F7:** `RecorrenciasWebTest` (próxima cobrança no dia certo, cartão na linha, sem fila)
· `FormularioLancamentoWebTest` (switch no crédito, aviso de substituição, sem escopo)
· `LancamentosWebTest` (ocorrência de cartão paga e sem botão; cancelada fora do extrato).
Suíte: **1185 passed**. Verificado também no app real (Playwright): cadastro → 1 linha no extrato
com ícone de recorrência e botão de pagar; nenhum lançamento avulso.

### Ajustes de layout do dashboard (feitos junto do F7, a pedido)
Ao conferir a `Visão Geral` no app real apareceu um defeito **pré-existente** e independente da
spec 12: os grids usavam breakpoints de **viewport** (`lg:grid-cols-4`), mas o canvas perde
256px de aside e 380px de chat — num monitor de 1280px sobravam ~600px, então quatro cards de
~140px empilhavam o rótulo em quatro linhas e derramavam o valor para fora da caixa, e as
descrições das contas viravam "Alu…"/"Geladeir…".

- `home.blade.php` e `components/dashboard/loading.blade.php` passaram a usar **container
  queries** (Tailwind v4): `@lg:grid-cols-2 @5xl:grid-cols-4` nos cards e `@3xl:grid-cols-3`
  no par donut+contas. O `@container` foi posto em wrappers próprios dos grids, **não** no
  canvas do layout — `container-type: inline-size` cria bloco de contenção e quebraria o FAB
  e os modais `fixed`.
- `components/dashboard/summary-card.blade.php`: `min-h-32` no lugar de `h-32` (dinheiro nunca
  pode ser cortado — o card cresce), valor com `tabular-nums` e um degrau abaixo do token
  quando o card fica estreito.
- `home.blade.php` ganhou `pb-28`: o FAB "Registrar gasto" é `fixed` e, no fim da rolagem,
  ficava **por cima** do último card, escondendo conteúdo que não havia como revelar.

Medido com Playwright em 1024/1280/1600/1920: nenhum dos 4 cards transborda em nenhuma largura,
e o FAB não cobre mais card algum no fim da rolagem.
