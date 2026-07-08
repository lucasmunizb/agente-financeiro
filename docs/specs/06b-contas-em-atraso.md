# Spec 06b — Contas em atraso no dashboard

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Extensão incremental do [[spec-06-dashboard]] (já concluído): o quadro de contas do
> dashboard hoje só mostra o que está **a vencer**. Esta etapa acrescenta o que **já
> venceu e não foi pago** (contas **em atraso**), como consulta determinística própria,
> e liga ao dashboard.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 6 · F8 (incremento) |
| **Status** | ✅ Concluído (backend) |
| **Depende de** | [[spec-06-dashboard]] · [[spec-01-dominio-financeiro]] |
| **Habilita** | — |
| **Fonte de verdade** | seção 6 do escopo · [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) §4.4 · [`docs/02-governanca-ia.md`](../02-governanca-ia.md) §3.2 |
| **Regras críticas** | 2, 3, 4, 5 |

---

## 1. Objetivo
Dar ao usuário, no dashboard, uma visão do que **já venceu e continua em aberto** —
separada do que está **a vencer** — reusando o padrão de consulta determinística já
existente, sem que a IA nem a UI calculem dinheiro.

## 2. Escopo
- **Inclui (backend desta etapa):**
  - Uma **consulta determinística** `App\Domain\ContasVencidas\ConsultarContasVencidas`
    (espelho retrospectivo de `ConsultarProximasContas`): itemiza e soma as parcelas com
    `vencimento` **anterior a hoje** que ainda são **conta a pagar**, escopo estrito por
    `user_id`, "hoje" **injetado**.
  - Um **VO de saída** `ResultadoConsultaContasVencidas` (total + lista + `trace` +
    `payload()` + `paraPrompt()`), na mesma forma dos demais resultados de consulta.
  - **Ligação ao agregador:** `ResumoDoMes` passa a expor também `contasVencidas`
    (o VO), sem recalcular nada; `ResumoDoMesResultado` ganha o acessor
    `totalContasVencidasCents()` e inclui o novo `trace` em `traces()`.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - **Tela** do dashboard (dividir o card em "Em atraso" + "A vencer", formatação pt-BR,
    tons/selos): **frontend, etapa separada** (regra 3, §8) — feita em commit próprio.
  - Qualquer **novo cálculo** financeiro: a consulta só **lê** parcelas e soma centavos já
    derivados por `Installment::valor()`.
  - **Nova tool de IA** `consultar_contas_vencidas` exposta ao agente: o VO já nasce no
    formato das tools (`trace`/`payload`/`paraPrompt`) para reuso futuro, mas **registrar a
    tool no agente é fora do escopo** desta etapa (pós-MVP / spec de IA).
  - Notificações/lembretes de atraso (pós-MVP).

## 3. Cenários de aceite (Given-When-Then)
Base dos testes de §7. Em todos, o usuário é estritamente escopado e "hoje" é **injetado**
(nunca lido do relógio global). "Hoje" de referência nos exemplos: `2026-06-26` (fuso SP).

- **C1 (soma o que já venceu) —** **Dado** parcelas com vencimento **anterior a hoje**
  ainda em aberto, **Quando** pedir as contas em atraso, **Então** recebo o total (centavos)
  e a lista itemizada dessas parcelas — e **nada** com vencimento hoje ou no futuro.
- **C2 (fronteira "hoje" exclusiva) —** **Dado** uma parcela vencendo **hoje** e outra
  **ontem**, **Quando** pedir as contas em atraso, **Então** a de **ontem** entra e a de
  **hoje NÃO** (hoje ainda não está em atraso; é a fronteira que a `ConsultarProximasContas`
  inclui como limite inferior — as duas consultas se encaixam sem sobreposição nem buraco).
- **C3 (exclui o que não é mais conta a pagar) —** **Dado** parcelas vencidas com status
  `pago`, `pendente_revisao`, `cancelado` ou `estornado`, **Quando** pedir as contas em
  atraso, **Então** elas são **excluídas** (mesma regra da §4.4 usada em próximas contas);
  `aberto`, `agendado`, `vencido` e `pago_parcial` **entram**.
- **C4 (janela retrospectiva opcional) —** **Dado** `janelaDias` informado, **Quando** pedir
  as contas em atraso, **Então** só entram parcelas com vencimento em `[hoje − janelaDias,
  ontem]`; **sem** `janelaDias` (default), entram **todas** as vencidas em aberto (não há
  limite inferior). O dashboard usa o default (todas).
- **C5 (itemização e ordem) —** **Dado** várias vencidas, **Quando** pedir a lista, **Então**
  cada item traz `descricao`, `vencimento` (YYYY-MM-DD) e `cents`, **ordenada por vencimento
  ascendente** (a mais antiga primeiro).
- **C6 (escopo por usuário) —** **Dado** parcelas vencidas de outro usuário, **Quando** pedir
  as minhas, **Então** as dele **não** aparecem.
- **C7 (borda: nada em atraso) —** **Dado** um usuário sem parcelas vencidas em aberto,
  **Quando** pedir, **Então** recebo total **zero** e lista **vazia** (nunca erro/`null`).
- **C8 (integração ao agregador) —** **Dado** o `ResumoDoMes`, **Quando** ele monta o resumo,
  **Então** inclui `contasVencidas` com os **mesmos** números que a consulta isolada devolve
  para o mesmo `userId`/"hoje" (reuso, não recálculo), e o `trace`
  `consultar_contas_vencidas` aparece em `traces()`.

## 4. Barreiras e invariantes
- **Regra 4 — a IA nunca calcula dinheiro.** A consulta é 100% determinística; a IA não
  participa. O total é soma de centavos já derivados por `Installment::valor()`.
- **Regra 5 — centavos inteiros (BIGINT), fuso America/Sao_Paulo.** Todo valor é `int` de
  centavos; a fronteira de "hoje" é resolvida em SP antes de comparar datas. Formatação
  pt-BR só na borda (frontend).
- **Escopo estrito por `user_id`** via `whereHas('transaction')`, igual às demais consultas.
- **Determinismo de "agora".** "Hoje" é **injetado** (`CarbonImmutable $hoje`); nunca o
  relógio global. Testes fixam o instante.
- **Encaixe com próximas contas.** "Em atraso" = `vencimento < hoje`; "a vencer" =
  `vencimento ≥ hoje` (a `ConsultarProximasContas` inclui hoje como limite inferior). As
  duas partições são **disjuntas e completas** em torno de "hoje" — sem dupla contagem.
- **Definição por DATA, não por rótulo de status.** Uma parcela está em atraso pelo
  `vencimento < hoje`, independentemente de o `status` já ter sido virado para `vencido`
  (o rótulo pode estar defasado) — coerente com o critério de "a vencer" (também por data).
- **Nada sensível persistido.** Read-only; não grava nem loga dado sensível.

## 5. Modelo de dados
**Nenhuma** tabela nova nem coluna nova. Lê `transactions`/`installments`/`status_pagamento`
já existentes. Índice de leitura em `installments(vencimento)` já cobre próximas contas e
serve à consulta retrospectiva (par com `dba-postgres` só se o plano piorar sob carga).

## 6. Contratos do domínio
> **Espelho** de `ConsultarProximasContas` (existente, reusado como referência de forma).

- **`App\Domain\ContasVencidas\ConsultarContasVencidas`** — consulta determinística:
  ```php
  public function para(int $userId, CarbonImmutable $hoje, ?int $janelaDias = null): ResultadoConsultaContasVencidas
  ```
  Passos: resolve `hoje` em SP → filtra `Installment` com `vencimento < hoje`,
  `status_id NOT IN {pago, pendente_revisao, cancelado, estornado}`, `whereHas('transaction'
  do user)`; se `$janelaDias` != null, adiciona `vencimento >= hoje − janelaDias`; ordena por
  `vencimento` asc; soma `Installment::valor()->cents()`.
- **`App\Domain\ContasVencidas\ResultadoConsultaContasVencidas`** — VO imutável:
  `totalCents:int`, `contas: list<{descricao, vencimento, cents}>` (asc), `trace`
  (`ferramenta='consultar_contas_vencidas'`, filtros `janela_dias`/`de`/`ate`, `registros`),
  `payload(): PayloadDeResposta` (total + cada conta + datas) e `paraPrompt(): string`.

### Ligação ao agregador (reuso — não recalcula)
- `App\Domain\Dashboard\ResumoDoMes` recebe `ConsultarContasVencidas` por injeção e chama
  `->para($userId, $hoje)` (default: todas as vencidas). Passa o VO adiante.
- `App\Domain\Dashboard\ResumoDoMesResultado` ganha `public readonly ResultadoConsultaContasVencidas $contasVencidas`,
  o acessor `totalContasVencidasCents(): int` e inclui o novo `trace` em `traces()`.

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio (`ConsultarContasVencidas`)** — `tests/Feature/Domain/ConsultarContasVencidasTest.php`:
   - **C1/C2:** soma só o que venceu **antes** de hoje; parcela de **hoje** não entra;
     parcela de **ontem** entra (fronteira exclusiva).
   - **C3:** exclui `pago`/`pendente_revisao`/`cancelado`/`estornado`; inclui
     `aberto`/`agendado`/`vencido`/`pago_parcial`.
   - **C4:** com `janelaDias` corta pelo limite inferior; sem `janelaDias` traz todas.
   - **C5:** itemização (descricao/vencimento/cents) ordenada por vencimento asc.
   - **C6:** escopo por usuário (não vaza de outro).
   - **C7:** borda zerada → total 0, lista `[]`.
   - **trace/payload:** `trace` com ferramenta/janela/registros; `payload()` permite o total
     e cada conta e rejeita valor de fora.
2. **Integração ao agregador (`ResumoDoMes`)** — acrescentar a `tests/Feature/Domain/ResumoDoMesTest.php`:
   - **C8:** `resumo->contasVencidas` bate com a consulta isolada; `totalContasVencidasCents()`
     é `int`; `traces()` contém `consultar_contas_vencidas`; borda zerada → lista vazia.

> Cada item de backend só é "feito" com **testes verdes e cobertura**. A camada de IA **não
> participa** desta etapa; não há agente a faquear aqui.

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| `ConsultarContasVencidas` + `ResultadoConsultaContasVencidas` (determinístico, centavos) | Dividir o card do dashboard em "Em atraso" (topo, acento argila) + "A vencer" |
| Ligação em `ResumoDoMes`/`ResumoDoMesResultado` (reuso, trace) | Formatação pt-BR ("venceu 5 de junho"), selo `atraso`, contagem/total do bloco |
| Testes unitários + integração | Estados vazio/misto (só a vencer, só atraso, ambos, nenhum) |

## 9. Definition of Done
- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (escopo por usuário, "hoje" injetado, centavos, fronteira
      disjunta com próximas contas, IA ausente).
- [x] Consulta **espelha** o padrão existente — sem duplicar fórmula de valor (usa
      `Installment::valor()`); agregador **reusa** a consulta (provado por valor-idêntico).
- [x] Sem segredo/PDF/dado sensível persistido ou commitado.
- [ ] Commit local do **backend** separado do commit de **frontend** (regras 1 e 3). *(o usuário commita à mão)*
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ **Backend concluído** (test-first). Frontend (§8) feito em commit separado.
- **Criado nesta etapa:**
  - `app/Domain/ContasVencidas/ConsultarContasVencidas.php` — consulta retrospectiva
    determinística; `para(int $userId, CarbonImmutable $hoje, ?int $janelaDias = null)`.
  - `app/Domain/ContasVencidas/ResultadoConsultaContasVencidas.php` — VO imutável (total +
    contas + `trace` + `payload()` + `paraPrompt()`).
  - `tests/Feature/Domain/ConsultarContasVencidasTest.php` — C1–C7 + trace/payload.
- **Alterado:**
  - `app/Domain/Dashboard/ResumoDoMes.php` — injeta `ConsultarContasVencidas`, expõe
    `contasVencidas`.
  - `app/Domain/Dashboard/ResumoDoMesResultado.php` — campo `contasVencidas`,
    `totalContasVencidasCents()`, `trace` em `traces()`.
  - `tests/Feature/Domain/ResumoDoMesTest.php` — C8.
  - `app/Http/Controllers/DashboardController.php` — expõe `contasVencidas`/`emAtraso`
    formatados à view (borda, formatação pt-BR).
  - **Frontend (commit separado):** `resources/views/home.blade.php` — card "Contas" com
    seções "Em atraso" (topo, argila) + "A vencer".
- **Reusado como está (NÃO reimplementado):** `ConsultarProximasContas` (referência de
  forma), `Installment::valor()`, `PayloadDeResposta`, `TraceDaConsulta`.
- **Decisões de regra registradas:**
  - **"Em atraso" = `vencimento < hoje`** (fronteira exclusiva); "a vencer" inclui hoje —
    partições disjuntas e completas, sem dupla contagem.
  - **Status excluídos = mesmos da §4.4** de próximas contas (`pago`, `pendente_revisao`,
    `cancelado`, `estornado`); atraso é definido por **data**, não pelo rótulo `vencido`.
  - **Sem limite inferior por default** (`janelaDias = null` → todas as vencidas em aberto):
    o valor "em atraso" no dashboard reflete tudo que ainda se deve. `janelaDias` fica
    disponível (injetável) para horizontes menores.
