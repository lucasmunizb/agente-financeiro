# Spec 06 — Dashboard (agregações do mês)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Spec **prospectivo**: descreve o que vamos construir. Nada aqui ainda existe, salvo o
> que §10 marcar como reusável. Ao concluir, marque o status, preencha **§10 Estado atual**
> com os artefatos reais e registre as decisões de regra que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 6 · F8 |
| **Status** | ⬜ Planejado |
| **Depende de** | [[spec-02-cadastro-manual-receitas]] · [[spec-03-telegram]] · [[spec-05-chat-financeiro]] |
| **Habilita** | — |
| **Fonte de verdade** | seção 6 do escopo · [`docs/05-arquitetura.md`](../05-arquitetura.md) · [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) |
| **Regras críticas** | 2, 3, 4, 5 |

---

## 1. Objetivo
Expor, num único agregador determinístico, os números do mês corrente de um usuário —
total de gastos, próximas contas, fatura do(s) cartão(ões) e disponível — prontos para o
dashboard, **reusando** o motor financeiro já existente (sem recalcular nada).

## 2. Escopo
- **Inclui (backend desta etapa):**
  - Um **serviço agregador** (proposta §6: `app/Domain/Dashboard/ResumoDoMes`) que, dado
    `userId` + "hoje"/"mês" **injetados**, monta o resumo do mês chamando as consultas
    determinísticas já testadas — **não duplica SQL nem fórmula**.
  - Um **VO de saída** com os blocos do dashboard (gastos, próximas contas, faturas,
    disponível), em **centavos**.
  - **Job de expurgo de mensagens (60 dias):** verificar e, se necessário, completar a
    ligação do `ExpurgarConversas` já existente (ver §6 e §10) — só o que faltar.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - **Telas e gráficos** do dashboard, formatação pt-BR de apresentação, endpoints HTTP de
    UI — tudo isso é **frontend, etapa separada** (regra 3, §8).
  - Qualquer **novo cálculo** financeiro: o agregador só **lê** números já calculados.
  - Notificações/lembretes (pós-MVP).

## 3. Cenários de aceite (Given-When-Then)
Base dos testes de §7. Em todos, o usuário é estritamente escopado e "hoje"/"mês" são
**injetados** (nunca lidos do relógio global).

- **C1 (agregação do mês, escopo por usuário) —** **Dado** um usuário com gastos, contas a
  vencer, fatura de cartão e receitas no mês, **e** um "hoje" injetado, **Quando** pedir o
  resumo do mês, **Então** recebo um VO com total de gastos do mês, lista de próximas
  contas, fatura(s) do cartão na competência corrente e o disponível — todos em centavos —
  e **nenhum** dado de outro usuário aparece.
- **C2 (reuso, não recálculo) —** **Dado** o agregador, **Quando** ele monta cada bloco,
  **Então** delega às consultas existentes (`ConsultarGastos`, `ConsultarProximasContas`,
  `ConsultarFaturaCartao`, `ConsultarDisponivelDoMes`) e **não** soma parcelas nem aplica a
  fórmula do disponível por conta própria (verificável por teste de colaboração/valores
  idênticos aos das consultas isoladas).
- **C3 (determinismo de tempo) —** **Dado** o mesmo dado de banco e dois "hoje" diferentes,
  **Quando** pedir o resumo, **Então** os recortes (mês corrente, janela de próximas
  contas, competência da fatura) mudam de forma **determinística** com o "hoje" injetado —
  sem dependência do relógio do processo.
- **C4 (sem cartão / mês vazio — borda) —** **Dado** um usuário sem cartões ou sem
  movimento no mês, **Quando** pedir o resumo, **Então** recebo um VO **bem-formado** com
  totais **zero** e listas **vazias** (nunca erro, nunca `null` solto).
- **C5 (expurgo de mensagens 60 dias) —** **Dado** o comando agendado de expurgo, **Quando**
  ele roda com "agora" injetado, **Então** conversas/mensagens com mais de 60 dias são
  apagadas e as de exatamente 60 dias são mantidas (já coberto por `ExpurgarConversas`;
  esta etapa só **confirma/liga** o que faltar — ver §6/§10).

## 4. Barreiras e invariantes
- **Regra 4 — a IA nunca calcula dinheiro.** O dashboard é 100% determinístico; **a IA não
  participa** desta etapa. O agregador apenas **lê** números já calculados pelo domínio.
- **Regra 5 — centavos inteiros (BIGINT), fuso America/Sao_Paulo.** Todo valor no VO é
  `int` de centavos (ou `Money`); **nada** de float. Formatação pt-BR só na borda (frontend).
- **Escopo estrito por `user_id`** em cada bloco — herdado das consultas reusadas, nunca
  relaxado pelo agregador.
- **Determinismo de "agora".** "Hoje"/"mês"/competência são **injetados** no agregador
  (igual a `ConsultarProximasContas::para(..., CarbonImmutable $hoje, ...)` e ao
  `ExpurgarConversas::executar(CarbonImmutable $agora)`); testes fixam o instante.
- **Nada sensível persistido.** O agregador é read-only; não grava nem loga dado sensível.

## 5. Modelo de dados
**Nenhuma** tabela nova nem coluna nova. O dashboard só **lê** o que os Blocos 1–5 já
modelaram (`transactions`/`installments`, `incomes`, `cards`, `categories`,
`status_pagamento`) e o histórico da Laravel AI SDK (`conversations`/
`conversation_messages`) para o expurgo. Par com a skill `dba-postgres` apenas se algum
índice de leitura se mostrar necessário sob carga (não previsto agora).

## 6. Contratos do domínio
> **Tudo nesta seção é PROPOSTA** (a confirmar na implementação test-first), salvo os
> serviços já existentes, que são **reusados como estão**.

### Reuso (já existe — confirmado no código, não reimplementar)
| Serviço | Assinatura pública real | Papel no dashboard |
|---|---|---|
| `App\Domain\Gastos\ConsultarGastos` | `para(int $userId, string $periodo, ?string $categoria=null, ?string $cartao=null, ?string $status=null): ResultadoConsultaGastos` | Total de gastos do mês + quebra por categoria |
| `App\Domain\ProximasContas\ConsultarProximasContas` | `para(int $userId, CarbonImmutable $hoje, int $janelaDias): ResultadoConsultaProximasContas` | Contas a vencer na janela rolante a partir de hoje |
| `App\Domain\FaturaCartao\ConsultarFaturaCartao` | `para(int $userId, string $cartao, string $competencia): ResultadoConsultaFaturaCartao` | Fatura do cartão na competência corrente |
| `App\Domain\Disponivel\ConsultarDisponivelDoMes` | `para(int $userId, string $mes): ResultadoConsultaDisponivel` | Disponível do mês + previsto do próximo |
| `App\Domain\IA\Historico\ExpurgarConversas` | `executar(CarbonImmutable $agora): int` | Expurgo de mensagens (60 dias) — já agendado |

Cada consulta já devolve um VO com `payload()`/`paraPrompt()` e um `TraceDaConsulta`
(fonte). O agregador **não** mexe nesses números; só os compõe.

### Proposta — agregador e VO de saída
- **`App\Domain\Dashboard\ResumoDoMes`** — orquestrador read-only. Recebe por
  injeção as quatro consultas acima; método proposto:
  ```php
  public function para(int $userId, CarbonImmutable $hoje, int $janelaProximasContas = 30): ResumoDoMesResultado
  ```
  Passos (todos delegando, sem cálculo próprio):
  1. resolve `mes`/`competencia` = `$hoje->setTimezone('America/Sao_Paulo')->format('Y-m')`
     (determinismo);
  2. **gastos** = `ConsultarGastos::para($userId, $mes)`;
  3. **próximas contas** = `ConsultarProximasContas::para($userId, $hoje, $janelaProximasContas)`;
  4. **disponível** = `ConsultarDisponivelDoMes::para($userId, $mes)`;
  5. **faturas** = para cada cartão do usuário (escopo por `user_id`),
     `ConsultarFaturaCartao::para($userId, <descricao|final_4>, $competencia)` — lista de
     faturas da competência corrente (vazia se o usuário não tem cartão).
- **`App\Domain\Dashboard\ResumoDoMesResultado`** — VO imutável de saída, agregando os
  VOs já existentes (ou seus números em centavos):
  `totalGastosCents:int`, `proximasContas` (lista a vencer), `faturas` (lista por cartão),
  `disponivel` (do `ResultadoConsultaDisponivel`), e o conjunto de `traces`/`fontes` para
  auditoria. **Sem** método de formatação pt-BR (isso é frontend).

> A escolha exata de **quais cartões** entram em "cartão atual" (todos os do usuário vs. só
> os com movimento na competência) é decisão de regra a confirmar na implementação — citar
> a seção do escopo; na dúvida, perguntar antes de inventar.

### Job de expurgo de mensagens — o que já existe vs. o que falta
- **Já existe e está ligado:** `App\Console\Commands\ExpurgarConversasCommand`
  (`ai:expurgar-conversas`) resolve "agora" em SP e delega a `ExpurgarConversas::executar`;
  **agendado diário às 03:30** em `routes/console.php`
  (`Schedule::command('ai:expurgar-conversas')->dailyAt('03:30')`).
- **O item "Job de expurgo de mensagens (60 dias)" do Bloco 6 já está coberto** pelo Bloco
  4. Esta etapa **não recria** nada: só **verifica** a cobertura por teste e, se faltar
  algo (ex.: garantir que o agendamento está ativo no `worker`/scheduler, ou um teste de
  borda dos exatos 60 dias), completa o mínimo. **Não duplicar** comando, domínio nem
  agendamento.

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio (`ResumoDoMes`)**
   - **C1/C2:** com seed de gastos, contas, fatura e receitas, o agregador devolve os
     mesmos números que as consultas isoladas devolvem para o mesmo `userId`/`mes` (prova de
     **reuso, não recálculo**); usar os fakes/stubs das consultas para assertar
     **colaboração** (chamadas com os parâmetros esperados) além dos valores.
   - **C1 (escopo):** dados de um segundo usuário **não** vazam para o resumo do primeiro.
   - **C3 (determinismo):** dois "hoje" injetados produzem recortes (mês, janela,
     competência) determinísticos e diferentes; mesmo "hoje" → mesmo resultado.
   - **C4 (borda):** usuário sem cartão / sem movimento → VO bem-formado, totais zero,
     listas vazias, sem exceção.
   - **Regra 5:** asserts garantem `int` de centavos em todo o VO (nada de float).
2. **Contrato/integração**
   - **C5 (expurgo):** teste do comando `ai:expurgar-conversas` com "agora" injetado
     confirmando o corte de 60 dias (apaga >60 dias, mantém ==60 dias) e que o agendamento
     diário 03:30 está registrado (assert no schedule) — completar só o que faltar.
   - (Se a etapa expor uma borda de leitura para a UI no futuro, o teste de contrato dela
     entra **junto do frontend**, não aqui.)

> Cada item de backend só é "feito" com **testes verdes e cobertura**. A camada de IA **não
> participa** desta etapa; não há agente a faquear aqui.

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| `ResumoDoMes` + `ResumoDoMesResultado` (agregação determinística, centavos) | Telas e **gráficos** do dashboard (cards, barras, donut) |
| Reuso das 4 consultas + escopo por usuário + "hoje" injetado | Formatação pt-BR, layout, interatividade |
| Confirmar/ligar o expurgo de mensagens (60 dias) | Endpoint HTTP/Inertia que serve o VO à UI |
| Testes unitários + contrato | Testes de UI/e2e |

## 9. Definition of Done
- [ ] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [ ] Barreiras de §4 garantidas (escopo por usuário, "hoje" injetado, centavos, IA ausente).
- [ ] Agregador **reusa** as consultas existentes — sem SQL/fórmula duplicados (provado por teste).
- [ ] Expurgo de mensagens (60 dias) verificado/ligado, sem duplicar comando/agendamento.
- [ ] Sem segredo/PDF/dado sensível persistido ou commitado.
- [ ] Commit local atômico, em português, separando backend de frontend.
- [ ] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ⬜ Planejado — **a implementar** (nada de §6 "proposta" existe ainda).
- **Já existe e será reusado (NÃO reimplementar):**
  - `app/Domain/Gastos/ConsultarGastos.php` (+ `ResultadoConsultaGastos`).
  - `app/Domain/ProximasContas/ConsultarProximasContas.php` (+ `ResultadoConsultaProximasContas`).
  - `app/Domain/FaturaCartao/ConsultarFaturaCartao.php` (+ `ResultadoConsultaFaturaCartao`).
  - `app/Domain/Disponivel/ConsultarDisponivelDoMes.php` / `DisponivelDoMes.php` (+ VOs).
  - `app/Domain/IA/Historico/ExpurgarConversas.php`, `app/Console/Commands/ExpurgarConversasCommand.php`
    e o agendamento em `routes/console.php` (diário 03:30) — **o "Job de expurgo (60 dias)"
    do Bloco 6 já está coberto pelo Bloco 4**.
- **A criar (test-first):** `app/Domain/Dashboard/ResumoDoMes.php` e
  `app/Domain/Dashboard/ResumoDoMesResultado.php` (nomes propostos).
- **Adiado para:** **frontend** (telas/gráficos do dashboard, §8) — etapa separada.
- **Decisões de regra a registrar na implementação:** critério de "cartão atual" (todos os
  cartões vs. só os com movimento na competência) e tamanho default da janela de próximas
  contas no dashboard — citando a seção do escopo.
