# Spec 09 — Faturas materializadas (ciclo de fatura de cartão)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> **⚠️ PENDENTE — não implementar ainda.** Este spec registra uma **proposta** de
> modelagem trazida pelo usuário. Antes de escrever qualquer teste/feature é preciso
> **fechar as "Questões em aberto" (§4b)**. Enquanto elas não forem decididas, os
> cenários (§3), o modelo (§5) e o plano de testes (§7) são um **rascunho**.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | A definir (pós-MVP incremental sobre o domínio F1) |
| **Status** | 🟠 Pendente (proposta a validar — não iniciar) |
| **Depende de** | [[spec-01-dominio-financeiro]] (parcelas, vencimento, disponível) · [[spec-02-cadastro-manual-receitas]] (CRUD de gasto) |
| **Habilita** | [[spec-06-dashboard]] (agregação por fatura) · [[spec-11-importacao-pdf]] (casar importação com fatura) |
| **Fonte de verdade** | seção 4 do escopo · [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) (§4.1, §4.3, §4.5) · [`docs/04-modelo-dados.md`](../04-modelo-dados.md) (`invoices`) |
| **Regras críticas** | 2 (test-first) · 4 (IA nunca calcula) · 5 (centavos BIGINT, fuso SP) · 7 (confirmar antes de gravar) |

---

## 1. Objetivo
**Materializar a fatura de cartão** como entidade de primeira classe — hoje ela é
**100% derivada** (`App\Domain\FaturaCartao\ConsultarFaturaCartao` soma as cobranças
cujo vencimento cai na competência; **não há tabela `invoices`**). Materializar dá à
fatura um **lugar próprio** para o que não cabe na cobrança individual: **data de
pagamento do boleto** (§4.5 — "no dia do vencimento registra-se o pagamento do boleto
da fatura fechada"), **status da fatura** e o **snapshot do valor fechado/importado**.

## 2. Escopo
- **Inclui (backend desta etapa, se aprovado):**
  - Tabela `invoices` materializada (fatura por cartão × competência) + model.
  - Vínculo determinístico entre **parcela** (`installments`) e a fatura da sua competência.
  - Serviço de **find-or-create** da fatura ao gerar/mover parcelas (sem dupla contagem).
  - Registro de **pagamento da fatura** (`data_pagamento`, status) — o "boleto" do §4.5.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - Mudar a **fórmula** do disponível do mês — continua determinística (regra 4, §4.5);
    a fatura materializada deve **refletir** a mesma soma, nunca recalculá-la.
  - Telas web / mensagens do bot sobre fatura → frontend, etapa separada (regra 3).
  - Importação de PDF em si → [[spec-11-importacao-pdf]] (mas passa a **casar** com a fatura).

## 3. Cenários de aceite (Given-When-Then) — RASCUNHO (depende de §4b)

> Escritos na leitura literal da proposta do usuário. Serão reescritos conforme a
> decisão de §4b (sobretudo o nível do vínculo: parcela↔fatura vs cobrança↔fatura).

- **C1 — Dado** um gasto **parcelado em cartão** (1/N), **Quando** as N parcelas são
  geradas, **Então** cada parcela passa a ter uma **fatura em aberto** da sua competência
  (uma fatura por `card_id` × competência), criada se ainda não existir.
- **C2 — Dado** que chega/existe uma parcela cuja competência **ainda não tem fatura**,
  **Quando** o vínculo é resolvido, **Então** uma **fatura nova em aberto** é criada e a
  parcela é ligada a ela (nunca duas faturas para a mesma competência do mesmo cartão).
- **C3 — Dado** uma fatura fechada, **Quando** o usuário registra o **pagamento do boleto**
  no vencimento, **Então** a fatura recebe `data_pagamento` e status `pago` — sem contradizer
  o status das cobranças que a compõem.
- **C4 (invariante) — Dado** qualquer cobrança/parcela de cartão, **Então** ela pertence a
  **exatamente uma** fatura (uma competência) — nunca é contada em duas (§4.5, C11 do spec 01).

## 4. Barreiras e invariantes
- **Regra 4 — A IA nunca calcula dinheiro.** A fatura é um **agregado persistido** que
  reflete a soma das cobranças/parcelas; a fonte da verdade do valor continua sendo o
  motor determinístico. A IA só lê/redije sobre números já calculados.
- **Regra 5 — Centavos (BIGINT), fuso SP.** Qualquer valor na fatura é `*_cents`;
  `vencimento`/`competencia` em `date`; instantes (pagamento) em `timestamptz`.
- **Um gasto → um único mês de vencimento** (§4.5). O modelo **não pode** permitir a mesma
  parcela em duas faturas.
- **Não duplicar valor derivável** (convenção `dba-postgres`). O valor da fatura **aberta**
  é a soma das parcelas — derivável. Ver §4b-Q2 para o snapshot do valor **fechado**.
- **Regra 7 — Confirmar antes de gravar** pagamento/fechamento no MVP.

## 4b. Questões em aberto (DECIDIR antes de escrever testes)

> O usuário trouxe a estrutura como **ideia**. Estes pontos têm tensão com invariantes já
> testados (spec 01) e precisam de decisão explícita — não invente regra financeira.

- **Q1 · Nível e cardinalidade do vínculo — N:N (proposto) vs `installments.invoice_id` (N:1).**
  A proposta é uma tabela N:N `cobrancas_fatura (invoice_id, transaction_id)`. Porém uma
  cobrança **à vista** cai em **uma** fatura, mas uma cobrança **parcelada** espalha suas
  parcelas por **várias** faturas (1/3 jan, 2/3 fev, 3/3 mar). Logo o vínculo natural **não**
  é cobrança↔fatura, e sim **parcela → fatura (N:1)**: cada `installment` cai em exatamente
  uma fatura (seu mês de vencimento). Vantagens do N:1 em `installments.invoice_id`:
  - respeita o invariante "cada parcela em um único mês" (§4.5 / C11) — uma N:N **permitiria**
    a mesma parcela em duas faturas, **violando** a regra;
  - dispensa a tabela de ligação; a relação cobrança↔fatura vira **derivada** (via parcelas);
  - à vista = transaction com 1 parcela (1/1) → 1 fatura (uniforme).
  **Decisão:** adotar `installments.invoice_id` (nullable, só cartão) **ou** manter a N:N?

- **Q2 · `valor_cents` na fatura: derivar ou armazenar?** O valor da fatura **aberta** é a
  **soma** das parcelas → derivável; a convenção é **não persistir valor derivável**
  (`installments` não tem `valor_cents`). Exceção defensável: ao **fechar** a fatura ou ao
  **importar do PDF**, gravar um `valor_fechado_cents` = o que o **banco** realmente cobrou
  (snapshot para conferência/divergência), distinto da soma dos lançamentos.
  **Decisão:** só derivar (aberta) **ou** derivar + snapshot no fechamento/importação?

- **Q3 · Quando materializar (criação eager vs lazy).** A proposta ("quando passar a parcela
  e não existir, criar") exige **scheduler** e cria uma janela em que a parcela existe sem
  fatura. Alternativa determinística: **find-or-create no momento em que a parcela é gerada**
  — como todas as N parcelas nascem juntas (§4.1), todas as faturas de competência futura
  nascem junto (uma por `card_id` × competência), **sem scheduler e sem janela**. Um scheduler
  ainda pode servir só para **transição de status** (aberta→vencida no vencimento), não para criar.
  **Decisão:** eager find-or-create na geração de parcelas **ou** criação lazy por scheduler?

- **Q4 · Status: cobrança vs fatura.** Hoje o status é por cobrança (`status_pagamento`).
  Materializar a fatura adiciona status **no nível da fatura**. Pagamento parcial/antecipado
  (§4.3) precisa ser reconciliado para nunca haver contradição (fatura "paga" com cobrança
  "aberta"). **Decisão:** status da fatura é derivado dos itens, próprio, ou ambos com regra
  de reconciliação?

- **Q5 · Impacto em `DisponivelDoMes` / `ResumoDoMes` / `ConsultarFaturaCartao`.** Já derivam
  da soma por vencimento. Materializar **não pode** regredir esses cálculos (regra 4). Definir
  se passam a ler a fatura materializada ou continuam derivando (a fatura como cache/agregado).

## 5. Modelo de dados — RASCUNHO (depende de Q1/Q2)

> Colunas mínimas na leitura da proposta; a forma final sai de §4b. Par com `dba-postgres`.

| Tabela | Colunas-chave (rascunho) | Notas |
|---|---|---|
| `invoices` (nova) | `id`, `user_id`, `card_id`, `competencia` (date, 1º dia do mês), `vencimento` (date), `data_pagamento` (timestamptz, null), `status_id` (FK `status_pagamento`), `valor_fechado_cents?` (BIGINT, null — ver Q2) | UNIQUE `(user_id, card_id, competencia)` (find-or-create). Índice `(user_id, card_id, competencia)` e `(user_id, vencimento)`. Soft delete. **Sem `valor_cents` da fatura aberta** (derivado). |
| `installments` (ajuste, se Q1=N:1) | + `invoice_id` (nullable, FK `invoices`, `ON DELETE set null`) | Só preenchido para parcela **em cartão**; fora de cartão fica null. |
| ~~`cobrancas_fatura`~~ (N:N — só se Q1 mantiver N:N) | `invoice_id`, `transaction_id`, PK composta | **Desaconselhado** (ver Q1): permite parcela em duas faturas e fere §4.5. |

Convenções: dinheiro `bigInteger('*_cents')`; datas de negócio `date`; instantes
`timestamptz`; toda FK usada em filtro/JOIN ganha índice.

## 6. Contratos do domínio — RASCUNHO
```php
// app/Domain/FaturaCartao/... (a definir)
// find-or-create determinístico da fatura da competência de uma parcela
ResolverFaturaDaParcela::para(int $userId, int $cardId, CarbonImmutable $competencia): Invoice
// registro do pagamento do boleto (regra 7: confirmar antes)
RegistrarPagamentoDeFatura::registrar(int $invoiceId, CarbonImmutable $pago, ...): void
```
> A IA não entra no cálculo (regra 4): só aciona/lê. `ConsultarFaturaCartao` continua a
> borda de leitura para a IA — passa a ler a fatura materializada (Q5).

## 7. Plano de testes (test-first) — RASCUNHO (só após §4b fechada)
1. **Unitários do domínio** — find-or-create da fatura por `(card, competencia)` (idempotente);
   parcela ligada a exatamente uma fatura; invariante "sem dupla contagem" (§4.5).
2. **Contrato/integração** — UNIQUE `(user_id, card_id, competencia)`; `invoice_id` só em
   cartão; pagamento da fatura reconciliado com status das cobranças (Q4); disponível do mês
   **não regride** (regra 4).

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| `invoices` materializada, vínculo parcela↔fatura, find-or-create, pagamento da fatura, testes. | Tela/mensagem de fatura (itens, total, vencimento, "pagar boleto"), destaque de divergência importada. |

## 9. Definition of Done
- [ ] **§4b resolvido e registrado** (as 5 questões) **antes** de qualquer teste.
- [ ] Cenários de §3 (reescritos pós-§4b) cobertos por testes que falhavam e agora passam.
- [ ] Barreiras de §4 garantidas: fatura não recalcula dinheiro; sem dupla contagem; centavos/fuso.
- [ ] `DisponivelDoMes`/`ResumoDoMes` **sem regressão** (suíte do spec 01/06 verde).
- [ ] Commit local atômico, em português, backend separado do frontend.
- [ ] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** 🟠 Pendente — **proposta a validar**, nada implementado.
- **Contexto atual (antes desta etapa):** fatura é **derivada**, sem tabela `invoices`.
  Existe só `invoice_imports` (metadados de importação). Leitura via
  `App\Domain\FaturaCartao\ConsultarFaturaCartao`, `ResumoDoMes`, tool `ConsultarFaturaCartao`.
- **A decidir:** as 5 questões de §4b (vínculo N:1 vs N:N, valor derivado vs snapshot,
  criação eager vs lazy, status fatura vs cobrança, impacto no disponível).
- **Origem:** proposta do usuário (fatura com vencimento/cartão/pagamento/valor + ligação
  cobrança↔fatura + criar fatura em aberto por parcela). Registrada aqui para virar
  testes/feature **após** validação.
</content>
</invoke>
