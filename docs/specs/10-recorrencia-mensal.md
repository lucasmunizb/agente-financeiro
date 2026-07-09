# Spec 10 — Recorrência mensal (assinaturas e contas fixas)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Pós-MVP · recorrência (destrava o switch "Repete todo mês?" das telas §7.7/§7.7b) |
| **Status** | 🟡 Em andamento |
| **Depende de** | [[spec-02-cadastro-manual-receitas]] (RegistrarGastoManual) · [[spec-04b-confirmacao-gasto-bot]] (fila `pending_confirmations`) |
| **Habilita** | Frontend do switch de recorrência (FE §7.7 / §7.7b) |
| **Fonte de verdade** | [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) §4.6 (`recorrências/assinaturas: tabela específica, status ativo/cancelado`) · [`docs/04-modelo-dados.md`](../04-modelo-dados.md) (tabela `recurrences`) · FE §7.9 (a fila é a base comum que a recorrência alimenta) |
| **Regras críticas** | 2 (TDD) · 3 (frontend separado) · 4 (IA nunca calcula) · 5 (centavos/fuso SP) · 7 (confirmar antes de gravar / sem auto-save) |

---

## 1. Objetivo
Permitir que o usuário cadastre um gasto **recorrente mensal** (assinatura/conta fixa) uma
única vez; a cada mês, no **dia** configurado, o sistema **enfileira uma confirmação
pendente** — o usuário confirma e ela vira lançamento. Nada é gravado sem o "sim" (regra 7).

## 2. Escopo
- **Inclui (backend desta etapa):**
  - Entidade própria `recurrences` (status `ativo`/`cancelado`), com `dia` do mês e
    `periodicidade` (só **`mensal`** no MVP da feature).
  - Domínio: **registrar**, **cancelar** e **materializar** (o motor do comando agendado).
  - A materialização **enfileira em `pending_confirmations`** (reuso total da base FE §7.9),
    marcando `origem = recorrencia` e ligando o pendente à recorrência (`recurrence_id`).
  - Comando artisan agendado (`recorrencia:materializar`) rodando sob o `schedule:work`.
  - Ampliação do CHECK `transactions.origem` para aceitar `recorrencia` (procedência honesta
    no lançamento gerado).
- **Não inclui (outro spec / frontend / pós-MVP):**
  - **Frontend** (regra 3): ligar o switch "Repete todo mês?" + Periodicidade + Dia dos
    formulários §7.7/§7.7b ao novo endpoint; exibir a origem "recorrência" na fila. Etapa
    separada e posterior.
  - Recorrência **em cartão de crédito** (crédito usa parcelas — FE §7.9 `:627`): fora do
    escopo. A recorrência é **só fora de cartão** (pix/débito/dinheiro/boleto).
  - Periodicidades além de **mensal** (semanal/anual): a coluna é extensível, mas não há
    regra nem UI para outros valores no MVP.
  - Recorrência de **receitas** (a coluna `incomes.recorrencia?` do doc 04 continua adiada).

## 3. Cenários de aceite (Given-When-Then)

- **C1 (registrar) — Dado** um usuário e uma forma **fora de cartão**, **Quando** registra
  uma recorrência "Netflix R$ 55,90, dia 5", **Então** nasce `ativo`, com `proxima_em` na
  **próxima** ocorrência do dia 5 (este mês se hoje ≤ dia 5; senão mês que vem) e uma trilha
  de auditoria `criar`.
- **C2 (dia > fim do mês) — Dado** `dia = 31`, **Quando** a ocorrência cai em fevereiro,
  **Então** `proxima_em` é **clampado** ao último dia do mês (28/29).
- **C3 (rejeita crédito) — Dado** uma forma de pagamento tipo **crédito**, **Quando** tenta
  registrar recorrência, **Então** o domínio recusa (crédito usa parcelas, não recorrência).
- **C4 (materializar no dia) — Dado** uma recorrência `ativo` com `proxima_em ≤ hoje`,
  **Quando** o comando roda, **Então** **enfileira UM** `pending_confirmation` (origem
  `recorrencia`, payload com `dataCompra = proxima_em`, valor em centavos, forma correta,
  `parcelas = 1`) e **avança** `proxima_em` para a ocorrência do mês seguinte. **Nada** é
  gravado como lançamento (regra 7).
- **C5 (idempotente) — Dado** que o comando já rodou hoje, **Quando** roda de novo no mesmo
  dia, **Então** não enfileira outra ocorrência (o ponteiro `proxima_em` já está no futuro).
- **C6 (confirmar) — Dado** o pendente enfileirado, **Quando** o usuário confirma (fluxo
  FE §7.9 já existente), **Então** vira lançamento via `RegistrarGastoManual` com
  `transactions.origem = recorrencia`, sem recálculo (regra 4).
- **C7 (rejeitar cancela — decisão do usuário) — Dado** o pendente de uma recorrência,
  **Quando** o usuário **rejeita**, **Então** aquele lançamento é descartado **e** a
  recorrência é marcada `cancelado` (deixa de gerar). Rejeitar = "não quero mais isto".
- **C8 (cancelar) — Dado** uma recorrência `ativo`, **Quando** é cancelada, **Então** vira
  `cancelado`, `proxima_em` fica nulo, deixa de materializar; idempotente (2º cancelamento
  não faz nada); auditoria `cancelar`.
- **C9 (escopo) — Dado** recorrências de dois usuários, **Quando** materializa/cancela,
  **Então** cada operação é **estritamente isolada por `user_id`** (nunca toca a do outro;
  404 para recorrência alheia).
- **C10 (futuro não materializa) — Dado** `proxima_em` no futuro, **Quando** o comando roda,
  **Então** nada é enfileirado para essa recorrência.

## 4. Barreiras e invariantes
- **Regra 7 / sem auto-save:** a recorrência **nunca grava lançamento sozinha** — só
  produz um pendente na fila; o lançamento nasce no "sim" do usuário.
- **Regra 4:** o valor/vencimento do lançamento é calculado pelo motor determinístico
  (`RegistrarGastoManual`/`MontadorDeParcelas`) no momento da confirmação; a recorrência só
  guarda o "molde" em centavos (regra 5).
- **Regra 5:** dinheiro em BIGINT centavos; `dia`/datas no fuso **America/Sao_Paulo**;
  "agora"/"hoje" **sempre injetado** (determinismo, nunca lê o relógio global no domínio).
- **Escopo estrito por `user_id`** em toda query e operação.
- **Fila é a base comum (FE §7.9):** a recorrência é apenas mais um **produtor** de
  `pending_confirmations` (como a importação de PDF será). Não duplica lógica de confirmação.

## 5. Modelo de dados
- **Nova tabela `recurrences`** (doc 03 §4.6 / doc 04): `id`, `user_id` (FK, cascade),
  `descricao`, `valor_cents` (BIGINT, CHECK ≥ 0), `payment_method_id` (FK), `categoria_id`
  (nullable, sem FK — como em `transactions`, a tabela `categories` é do bloco próprio),
  `periodicidade` (CHECK `IN ('mensal')`), `dia` (smallint, CHECK 1..31), `status`
  (CHECK `IN ('ativo','cancelado')`, default `ativo`), `proxima_em` (date, nullable — nula
  quando `cancelado`), timestamps + soft delete (LGPD). Índices `[user_id, status]` e
  `[status, proxima_em]` (varredura do materializador).
- **`pending_confirmations` (+coluna):** `recurrence_id` (FK nullable, `nullOnDelete`) —
  liga o pendente à recorrência que o produziu (só produtores de recorrência preenchem).
- **`transactions.origem` (CHECK ampliado):** passa a aceitar `recorrencia` além de
  `manual`, `telegram`, `pdf` (precedente: `allow_boleto_in_payment_methods_check`).

## 6. Contratos do domínio (`App\Domain\Recorrencia\`)
- `DadosRecorrencia` — DTO imutável: `userId, descricao, valorCents, paymentMethodId, dia,
  categoriaId=null, periodicidade='mensal'`.
- `OcorrenciaMensal::aPartirDe(int $dia, CarbonImmutable $data): CarbonImmutable` — próxima
  data que casa o `dia` **em/depois** de `$data`, com **clamp** ao fim do mês. Determinístico.
- `RegistrarRecorrencia::registrar(DadosRecorrencia $d, CarbonImmutable $hoje): Recurrence` —
  valida forma **≠ crédito**, cria `ativo` com `proxima_em = OcorrenciaMensal::aPartirDe(dia,
  hoje)`, audita `criar`.
- `MaterializarRecorrencias::paraTodos(CarbonImmutable $hoje): int` — para cada `ativo` com
  `proxima_em ≤ hoje`: monta `DadosGastoManual` (origem `recorrencia`, `dataCompra =
  proxima_em`, `parcelas = 1`), **enfileira** via `EnfileirarConfirmacao` (com
  `recurrenceId`), avança `proxima_em`. Retorna quantas ocorrências enfileirou.
- `CancelarRecorrencia::cancelar(int $id, int $userId, CarbonImmutable $agora): bool` —
  escopo por usuário (`findOrFail`), `ativo → cancelado` + `proxima_em = null` + audita;
  idempotente (já cancelado → `false`).
- **Cascata rejeitar→cancelar (C7):** `RejeitarPendente`, ao rejeitar um pendente com
  `recurrence_id`, dispara `App\Events\PendenteRecorrenteRejeitado`; o listener
  `CancelarRecorrenciaAoRejeitar` chama `CancelarRecorrencia`. Mantém `Confirmacao`
  desacoplado de `Recorrencia` (produtor → fila → evento; a dependência é só de ida).
- **Borda agendada:** `MaterializarRecorrenciasCommand` (`recorrencia:materializar`) resolve
  "hoje" em SP e delega ao domínio; agendado `dailyAt` em `routes/console.php`.

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Domínio** (`tests/Feature/Domain/RecorrenciaTest.php`): C1–C10 acima — registrar
   (`proxima_em`/clamp/rejeita crédito/audit), cancelar (idempotência/escopo/audit),
   materializar (enfileira 1/avança ponteiro/idempotente/futuro-não/isolamento/payload),
   e a cascata rejeitar→cancela (integração com o evento real).
2. **Comando** (`tests/Feature/Console/MaterializarRecorrenciasCommandTest.php`): roda o
   artisan, confere que enfileira e a saída só tem contagem (sem dado sensível).

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| `recurrences` + domínio + comando agendado + fila alimentada + CHECK origem; borda web (o `store` do gasto cria a recorrência a partir do mês seguinte, atômico) | ✅ Switch "Repete todo mês?" + Periodicidade + Dia (§7.7/§7.7b) ligados ao `store` + nota na confirmação. ✅ Selo "recorrência" na fila §7.9. **Ainda deferido:** tela de gerenciar recorrências (listar/cancelar) |

## 9. Definition of Done
- [ ] Cenários C1–C10 cobertos por testes que falhavam antes e agora passam.
- [ ] Barreiras de §4 garantidas (sem auto-save; centavos; determinismo de "hoje"; escopo).
- [ ] Sem segredo/PDF/dado sensível persistido ou commitado.
- [ ] Commit local atômico (backend), separado do frontend.
- [ ] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ Backend concluído (frontend adiado — regra 3).
- **Entregue (backend):**
  - Migrations: `database/migrations/2026_07_10_000001_create_recurrences_table.php`,
    `..._000002_add_recurrence_id_to_pending_confirmations_table.php`,
    `..._000003_allow_recorrencia_in_transactions_origem_check.php`.
  - Model/factory: `app/Models/Recurrence.php`, `database/factories/RecurrenceFactory.php`.
  - Domínio (`app/Domain/Recorrencia/`): `DadosRecorrencia`, `OcorrenciaMensal`,
    `RegistrarRecorrencia`, `CancelarRecorrencia`, `MaterializarRecorrencias`.
  - Fila estendida: `EnfileirarConfirmacao` (param `recurrenceId`) + coluna
    `pending_confirmations.recurrence_id`.
  - Cascata C7: `app/Events/PendenteRecorrenteRejeitado.php` +
    `app/Listeners/CancelarRecorrenciaAoRejeitar.php` (disparo em `RejeitarPendente`,
    registro em `AppServiceProvider::boot`).
  - Comando agendado: `app/Console/Commands/MaterializarRecorrenciasCommand.php`
    (`recorrencia:materializar`), agendado `dailyAt('06:00')` em `routes/console.php`.
  - Testes: `tests/Feature/Domain/RecorrenciaTest.php` (15) +
    `tests/Feature/Console/MaterializarRecorrenciasCommandTest.php` (1) — todos verdes;
    suíte completa 784 passando.
  - **Operação:** o worker precisa de `up -d --force-recreate worker` para o `schedule:work`
    enxergar o novo comando (código fica em memória — ver memória do projeto).
- **Entregue (borda web + frontend, decisão "lança agora + repete no mês seguinte"):**
  - Borda: `RegistrarGastoRequest` (campos `recorrente`/`periodicidade`/`dia_recorrencia` +
    `ehRecorrente()`/`dadosRecorrencia()`), `GastoController@store` (cria a recorrência junto
    do gasto, atômico, começando no mês seguinte) e `@previa` (nota "começa em <mês>").
    `RegistrarRecorrencia` ganhou o parâmetro opcional `$primeiraReferencia`.
  - Frontend: `resources/views/components/gasto/form.blade.php` (switch revela Periodicidade
    + Dia; nota na confirmação) e `resources/js/pages/registrar-gasto.js` (envia os campos,
    sugere o dia pelo vencimento, mostra a nota; corrigido o realce inline de categoria).
  - Testes: `RegistrarGastoWebTest` (+5) e `RecorrenciaTest` (+1). Suíte: **790 verdes**.
- **Entregue (selo na fila §7.9):** `ConfirmacaoPendenteController` passa `origemCodigo`;
  `resources/views/confirmacoes.blade.php` mostra o selo distinto (ícone `refresh-cw` + tom
  cédula + `title`) para itens de recorrência. Teste em `ConfirmacoesTelaTest` (+1).
- **Adiado para:** tela de gerenciar recorrências (listar/cancelar).
- **Decisões de regra tomadas:**
  - Materialização = **enfileira 1 confirmação no dia** (just-in-time, sem materializar
    meses à frente). Casa com "fila revisável 1 a 1" + regra 7.
  - **Rejeitar uma ocorrência cancela a recorrência inteira** (decisão do usuário): rejeitar
    é "não quero mais". Cancelamento explícito também existe (C8).
  - Recorrência **só fora de cartão** e **só mensal** no MVP da feature.
  - Confirmações de recorrência **não expiram** (`expira_em = null`): uma conta fixa
    esquecida espera na fila em vez de sumir.
