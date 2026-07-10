# Spec 10b — Previsão de recorrências no dashboard (visão de mês futuro)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra 2), **backend antes do frontend** (regra 3).
> Em qualquer dúvida de regra, o **escopo final** e os `docs/` prevalecem — não invente
> regra financeira.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Pós-MVP · extensão de [[spec-10-recorrencia-mensal]] (leitura/dashboard) |
| **Status** | ✅ Backend concluído (frontend adiado — regra 3) |
| **Depende de** | [[spec-10-recorrencia-mensal]] (tabela `recurrences`, `OcorrenciaMensal`) · [[spec-06-dashboard]] (`ResumoDoMes`, navegação por competência) |
| **Habilita** | Frontend: selo "prevista" nas próximas contas + legenda "disponível projetado" (etapa separada) |
| **Fonte de verdade** | [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) §4.5 (disponível) e §4.6 (recorrências) · spec 10 §10 (decisão "materializar just-in-time, sem materializar meses à frente") |
| **Regras críticas** | 2 (TDD) · 3 (frontend separado) · 4 (IA nunca calcula) · 5 (centavos/fuso SP) · 7 (previsão NÃO grava/confirma) |

---

## 1. Objetivo

Hoje o dashboard só enxerga **lançamentos reais já materializados** (`transactions`/
`installments`). Recorrências (`recurrences`) só viram lançamento **no dia**, via o comando
`recorrencia:materializar`, que enfileira **uma** confirmação quando `proxima_em ≤ hoje`
(spec 10 §10, decisão _just-in-time_). Consequência atual: ao navegar para um **mês futuro**
(`?mes=YYYY-MM`), as contas fixas que certamente vão cair naquele mês **não aparecem**, e o
"disponível" do mês futuro fica **artificialmente alto** (nada foi abatido).

Esta etapa adiciona uma camada **read-only de _previsão_**: a partir do "molde" em
`recurrences`, o dashboard **projeta** as ocorrências de um mês futuro e as exibe entre as
próximas contas (marcadas como **previstas**), abatendo o **disponível projetado** daquele
mês. **Nada é gravado, nada é confirmado** — a previsão coexiste com o _just-in-time_ e
**não** o substitui.

## 2. Escopo

- **Inclui (backend desta etapa):**
  - Serviço de domínio `ProjetarRecorrencias` (puro, read-only): dado um usuário + mês-alvo +
    "agora" injetado, devolve as ocorrências projetadas daquele mês (valor em centavos, data
    de vencimento clampada, flag `prevista`).
  - Integração na agregação do dashboard (`ResumoDoMes`): mesclar as previstas nas
    **próximas contas** e **abater o disponível** do mês projetado.
  - Ligação na borda (`DashboardController`): passar o "agora" real (distinto da âncora do
    mês navegado) e propagar a flag `prevista` no view-model.
- **Não inclui (frontend / etapa separada / fora do escopo):**
  - **Frontend** (regra 3): o **selo visual** "prevista" nas linhas de próximas contas, o
    "~" (valor aproximado) e a **legenda** "disponível projetado" na visão futura. Etapa
    separada e posterior — este spec só entrega os dados.
  - **Mês corrente e passados:** seguem **inalterados** (só lançamentos reais + fila). A
    previsão vale **apenas para meses estritamente futuros** (§4, decisão anti-dupla-contagem).
  - **Materializar/confirmar antecipado:** a decisão _just-in-time_ da spec 10 permanece.
    Previsão **nunca** cria `pending_confirmations` nem `transactions`.
  - **`previstoProximoMes` do disponível:** continua sendo derivado só de parcelas reais
    (não recebe as previstas de M+1) — fora do escopo desta etapa.

## 3. Cenários de aceite (Given-When-Then)

- **P1 (aparece no mês futuro) — Dado** uma recorrência `ativo` "Netflix R$ 55,90, dia 5"
  fora de cartão, **Quando** o usuário abre o dashboard de um **mês futuro** (`?mes=` M+1),
  **Então** "Netflix" aparece nas **próximas contas** daquele mês, com vencimento no dia 5
  (clampado), valor R$ 55,90 e flag `prevista = true`.
- **P2 (abate o disponível projetado) — Dado** o cenário P1 com receitas do mês futuro,
  **Quando** o dashboard do mês futuro é montado, **Então** o **disponível** daquele mês
  subtrai o total das previstas (recorrências são fora de cartão), além dos gastos reais.
- **P3 (mês corrente inalterado) — Dado** a mesma recorrência, **Quando** o usuário abre o
  **mês corrente**, **Então** ela **não** é projetada (o dia dela é servido pela fila de
  confirmação _just-in-time_ quando chegar) — próximas contas e disponível ficam idênticos
  ao comportamento atual. **Sem dupla contagem.**
- **P4 (mês passado inalterado) — Dado** navegação para um **mês anterior**, **Quando** o
  dashboard é montado, **Então** nenhuma previsão é injetada (só lançamentos reais).
- **P5 (clamp de dia) — Dado** `dia = 31`, **Quando** o mês futuro projetado é fevereiro,
  **Então** a ocorrência prevista cai em 28/29 (reusa `OcorrenciaMensal`).
- **P6 (só começa no futuro) — Dado** uma recorrência criada este mês cuja `proxima_em` é o
  **mês seguinte** (caso do form de gasto), **Quando** o usuário vê um mês **anterior ao
  início** dela, **Então** ela **não** aparece; a partir do mês em que começa, aparece.
- **P7 (cancelada/soft delete não projeta) — Dado** uma recorrência `cancelado` (ou
  soft-deleted), **Quando** um mês futuro é montado, **Então** ela **não** é projetada.
- **P8 (escopo por usuário) — Dado** recorrências de dois usuários, **Quando** um deles vê
  seu mês futuro, **Então** só as **próprias** recorrências são projetadas (isolamento
  estrito por `user_id`).
- **P9 (nada é persistido) — Dado** qualquer visualização de mês futuro, **Quando** a
  previsão é calculada, **Então** nenhuma linha é criada em `pending_confirmations` nem em
  `transactions`/`installments` (read-only puro; regra 7 intacta).
- **P10 (ordenação e total) — Dado** múltiplas previstas + contas reais no mês futuro,
  **Quando** as próximas contas são montadas, **Então** a lista sai **ordenada por
  vencimento** e o `totalCents` de próximas contas = reais + previstas.

## 4. Barreiras e invariantes

- **Só meses estritamente futuros (anti-dupla-contagem):** a previsão só age quando
  `mesAlvo > mesCorrente` (comparação de `YYYY-MM` no fuso SP a partir do "agora" injetado).
  O mês corrente já é coberto pela materialização _just-in-time_ + fila; passados, pelos
  lançamentos reais. Assim **uma ocorrência nunca é contada duas vezes**.
- **Read-only / regra 7:** `ProjetarRecorrencias` **não** escreve nada. A confirmação
  continua obrigatória — ela nasce quando o mês chega e o comando enfileira o pendente.
- **Regra 4 (IA nunca calcula; domínio calcula):** o valor previsto é o **molde**
  (`recurrences.valor_cents`); a data é resolvida por `OcorrenciaMensal` (determinístico).
  O abatimento do disponível reusa o calculador puro `DisponivelDoMes::calcular` — a
  agregação só soma o **total já pré-somado** pela previsão ao componente fora-de-cartão.
- **Regra 5:** centavos BIGINT; datas no fuso **America/Sao_Paulo**; **"agora" injetado**
  (nunca lê o relógio global no domínio).
- **Escopo estrito por `user_id`** em toda query.
- **Valor é aproximado:** o real pode diferir se o usuário editar na confirmação — por isso
  a flag `prevista` (o FE mostra "~"). O domínio marca; a borda propaga.
- **Fora de cartão:** recorrências são só fora de cartão (spec 10) → entram como componente
  **fora-de-cartão** do disponível projetado.

## 5. Modelo de dados

**Nenhuma migration.** Reusa `recurrences` (spec 10) tal como está. O índice
`[status, proxima_em]` já existe e serve à varredura. Puramente leitura.

## 6. Contratos do domínio (`App\Domain\Recorrencia\`)

- **`ProjetarRecorrencias`** (novo):
  ```
  para(int $userId, string $mesAlvo, CarbonImmutable $agora): ResultadoProjecaoRecorrencias
  ```
  - Normaliza `agora` para SP; `mesCorrente = agora->format('Y-m')`.
  - **Guard:** se `$mesAlvo <= $mesCorrente` ⇒ resultado **vazio** (não projeta corrente/passado).
  - Query: `Recurrence` `where(user_id)` `where(status = ativo)` `whereNotNull(proxima_em)`
    `whereDate(proxima_em <= fim do mês-alvo)` (só recorrências que **já começaram** até o
    mês-alvo). Soft-deleted já excluídas pelo `SoftDeletes`.
  - Para cada: `ocorrencia = OcorrenciaMensal::aPartirDe($rec->dia, $inicioDoMesAlvo)`
    (a partir do 1º dia do mês-alvo ⇒ devolve o `dia` clampado **naquele** mês).
  - Item: `['descricao', 'vencimento' => Y-m-d, 'cents' => valor_cents, 'prevista' => true]`.
    Ordena por `vencimento` asc; soma `totalCents`.
- **`ResultadoProjecaoRecorrencias`** (novo VO imutável): `int $totalCents`,
  `list<array{descricao,vencimento,cents,prevista:true}> $ocorrencias`,
  `TraceDaConsulta $trace` (ferramenta `projetar_recorrencias`, filtros `{mes}`, registros).
- **`OcorrenciaMensal`** — **sem alteração**: `aPartirDe($dia, 1ºDiaDoMesAlvo)` já entrega a
  ocorrência clampada do mês-alvo (ref = início do mês ⇒ candidato ≥ ref).

### Integração — `App\Domain\Dashboard\ResumoDoMes`
Nova assinatura (retrocompatível):
```
para(int $userId, CarbonImmutable $ancora, int $janelaProximasContas = 30, ?CarbonImmutable $agora = null)
```
- `$agora ??= $ancora` (chamadas atuais, sem `agora`, ⇒ `mesAlvo == mesCorrente` ⇒ previsão
  vazia ⇒ **comportamento idêntico ao atual**; toda a suíte existente continua verde).
- Após as consultas reais, chama `ProjetarRecorrencias::para($userId, $mes, $agora)`.
- **Mescla próximas contas:** novo `ResultadoConsultaProximasContas` com
  `contas = reais(prevista=false) + previstas`, reordenado por `vencimento`,
  `totalCents = real + previsto`.
- **Abate o disponível:** recompõe via o calculador puro —
  `DisponivelDoMes::calcular(receitas, gastosReais + previstoTotal, foraDeCartao=0, previstoProximoMes)`
  — e reembrulha em `ResultadoConsultaDisponivel` mantendo `receitasCents` e o trace.
- Quando a previsão é vazia (mês corrente/passado), **nada muda** (mesclas são no-op).

### Borda — `App\Http\Controllers\DashboardController`
- Distingue **âncora** (mês navegado) de **agora** (hoje real) e passa **ambos** ao
  `ResumoDoMes` (`para(userId, ancora, 30, agoraReal)`).
- Propaga a flag `prevista` nas linhas de `proximasContas()` do view-model (dado; o **selo**
  é frontend).

## 7. Plano de testes (test-first — devem falhar primeiro)

1. **Domínio** (`tests/Feature/Domain/ProjetarRecorrenciasTest.php`): P1, P5, P6, P7, P8, P10
   + o **guard** (mês corrente ⇒ vazio; mês passado ⇒ vazio) + P9 (asserção de que
   `pending_confirmations`/`transactions` seguem vazios após projetar).
2. **Agregação** (`tests/Feature/Domain/ResumoDoMesTest.php` ou o existente do dashboard):
   mês futuro ⇒ próximas contas contêm a prevista (flag) **e** disponível abatido (P1/P2/P10);
   mês corrente ⇒ resultado **idêntico** ao atual (P3, regressão); mês passado ⇒ idem (P4).
3. **Borda/web** (`tests/Feature/.../DashboardCompetenciaTest.php`): `GET /?mes=<futuro>` ⇒
   a recorrência prevista aparece na resposta e o disponível reflete o abatimento; `?mes=`
   corrente ⇒ sem previstas (mesma foto de hoje).

## 8. Backend agora · Frontend depois

| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| `ProjetarRecorrencias` + `ResultadoProjecaoRecorrencias`; mescla em `ResumoDoMes` (próximas contas + disponível); wiring do `agora` no `DashboardController` + flag `prevista` no view-model; testes P1–P10 | Selo "prevista" (ícone/tom) nas linhas de próximas contas; "~" no valor previsto; legenda "disponível **projetado**" na visão futura; possível nota "previsão de contas fixas" |

> **Nota para o frontend (carve-out da regra "mesmomês").** Hoje o quadro "Próximas contas"
> é **escondido** em meses não-atuais (FE §7.5, `@if ($vm['ehMesAtual'])` em `home.blade.php`).
> No mês futuro o **disponível abatido** já aparece (faz parte do "retrato do mês"), mas para
> **exibir a lista de previstas** o frontend precisará abrir uma exceção: em meses **futuros**
> (não nos passados) mostrar as próximas contas quando houver ocorrências `prevista`. O dado já
> chega pronto no view-model (`$vm['proximasContas'][*]['prevista']`).

## 9. Definition of Done

- [ ] Cenários P1–P10 cobertos por testes que falhavam antes e agora passam.
- [ ] Barreiras de §4 garantidas (só futuro; read-only; determinismo de "agora"; escopo;
      centavos; sem dupla contagem).
- [ ] Suíte existente **inteira verde** (retrocompatibilidade de `ResumoDoMes`).
- [ ] Nenhuma escrita durante a previsão (assert em `pending_confirmations`/`transactions`).
- [ ] Commit local atômico (backend), separado do frontend.
- [ ] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos

- **Status:** ✅ Backend concluído (frontend adiado — regra 3).
- **Entregue (backend):**
  - Domínio (`app/Domain/Recorrencia/`): `ProjetarRecorrencias` (projeção read-only, guard
    só-futuro, escopo por usuário) + `ResultadoProjecaoRecorrencias` (VO). Sem migration.
  - Agregação (`app/Domain/Dashboard/ResumoDoMes.php`): assinatura ganhou `?CarbonImmutable
    $agora = null` (retrocompatível); mescla `mesclarPrevistas()` (próximas contas + flag
    `prevista`, reordena, soma) e `abaterPrevistas()` (recompõe o disponível via o calculador
    puro `DisponivelDoMes`).
  - Borda (`app/Http/Controllers/DashboardController.php`): passa o "agora" real distinto da
    âncora (`viewModel(..., $agora, ...)`) e propaga `prevista` nas linhas de próximas contas.
  - Testes: `tests/Feature/Domain/ProjetarRecorrenciasTest.php` (9),
    `tests/Feature/Domain/ResumoDoMesTest.php` (+3), `tests/Feature/Dashboard/DashboardCompetenciaTest.php`
    (+2). **Suíte completa: 916 verdes.**
- **Decisões de regra tomadas (usuário):**
  - Previsão entra **nas próximas contas** (selo "prevista") **e abate o disponível
    projetado** do mês futuro.
  - Vale **só para meses estritamente futuros** (mês corrente/passado inalterados) — evita
    dupla contagem com a materialização _just-in-time_.
  - Mantém a decisão da spec 10: materialização segue _just-in-time_; a previsão é uma
    camada de **leitura** paralela, que **não grava nada**.
