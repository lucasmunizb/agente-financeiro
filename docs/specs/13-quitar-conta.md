# Spec 13 — Quitar conta em qualquer superfície (marcar pago · desmarcar · editar)

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os critérios e
> implemente **test-first** (regra 2), **backend antes do frontend** (regra 3). Em qualquer
> dúvida de regra, o **escopo final** e os `docs/` prevalecem — não invente regra financeira.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Correção de cobertura — completa [[spec-12-recorrencia-ocorrencias]] e [[spec-06b-contas-em-atraso]] |
| **Status** | ✅ Implementado (F1–F4) |
| **Depende de** | `installments` + `RegistrarPagamentoParcela` (spec 02) · `recurrence_occurrences` + `PagarOcorrencia` (spec 12) · `ProcessarInteracao` (spec 04b) |
| **Habilita** | Conta fixa quitada pelo mês em qualquer canal · conserto de clique errado sem tocar no banco |
| **Fonte de verdade** | [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) §4.3 (cartão quita pela fatura), §4.4 (status) · [`docs/02-governanca-ia.md`](../02-governanca-ia.md) §3.1 (papéis da IA) |
| **Regras críticas** | 2 (TDD) · 3 (frontend separado) · 4 (IA nunca calcula) · 5 (centavos/fuso SP) · 7 (confirmar antes de gravar) |

---

## 1. Problema

Marcar uma conta como paga **existia**, mas em um lugar só de cada vez — e nunca no mesmo lugar
em que o usuário percebe que precisa fazê-lo:

| Onde o usuário está | Marcar pago | Editar | Desmarcar |
|---|---|---|---|
| Extrato — parcela de lançamento | ❌ só na tela de detalhe | ✅ | ❌ |
| Extrato — ocorrência de recorrência | ✅ | ❌ | ❌ |
| Dashboard — "em atraso" / "a vencer" | ❌ | ❌ | ❌ |
| Bot / chat | ❌ | ❌ | ❌ |

Três defeitos concretos:

1. **Os dois botões eram mutuamente exclusivos.** A linha do extrato usava
   `@if ($pagarUrl) … @elseif ($editarUrl)`: quem ganhava a ação de pagar **perdia** a de editar.
2. **`EditarOcorrencia` estava órfão.** O serviço de domínio existia e era testado desde a
   spec 12, mas **nenhuma rota chegava nele** — a conta fixa daquele mês não tinha como ser
   corrigida pela interface.
3. **Pagamento era irreversível pela interface.** Um clique errado em "marcar pago" só tinha
   conserto no banco — e, até lá, o valor ficava errado no Disponível do mês.

Somado a isso, o quadro de contas do dashboard — exatamente a tela que existe para mostrar a
conta esquecida (spec 06b) — era **somente leitura**: via-se o problema e não havia como resolvê-lo
sem navegar para outra tela.

## 2. Escopo

- **Inclui (backend desta etapa):**
  - Estorno da marcação de pagamento (parcela e ocorrência), com o status de volta **derivado da
    data**, não cravado.
  - Rota e validação para **editar a ocorrência** do mês (o serviço já existia).
  - Alvos de ação (id opaco + flags) no payload de leitura de extrato e dos dois quadros do
    dashboard.
  - Intenção **`pagar`** no bot: a IA extrai o termo, o domínio resolve a conta.
- **Não inclui:**
  - Pagamento de **fatura de cartão** como um todo — depende de [[spec-09-faturas-materializadas]],
    que segue pendente (a fatura hoje é derivada, não existe entidade a quitar).
  - "Desmarcar" nos quadros do dashboard: eles só listam o que **falta** pagar, então a conta
    paga some de lá e não há onde ancorar o botão. O conserto vive no extrato.
  - Propagar a edição da ocorrência para os meses seguintes — isso é `SincronizarRecorrencia`,
    e continua sendo uma ação sobre o **molde**.

## 3. Cenários de aceite (Given-When-Then)

**Estorno da marcação**

- **C1** — **Dado** uma parcela fora de cartão marcada como paga, **quando** o usuário desmarca,
  **então** a `data_pagamento` é apagada e o status volta ao que a **data** manda
  (`agendado` no futuro · `aberto` hoje · `vencido` no passado), nunca `aberto` cravado.
- **C2** — **Dado** um lançamento de 3 parcelas com **todas** pagas, **quando** uma é desmarcada,
  **então** o lançamento agrega como `pago_parcial`; desmarcadas **todas**, agrega como `aberto`.
- **C3** — **Dado** uma parcela nunca paga, **quando** o usuário desmarca, **então** nada muda e
  **nada é auditado** (idempotência).
- **C4 (borda)** — **Dado** uma parcela **de cartão** ou de lançamento **cancelado**, **quando** se
  tenta desmarcar, **então** a operação é recusada e o status permanece.
- **C5 (borda)** — **Dado** um item de **outro usuário**, **quando** se tenta desmarcar, **então**
  404 e nada muda.

**Edição da ocorrência ("só este mês")**

- **C6** — **Dado** uma ocorrência do mês, **quando** o usuário pede a **prévia**, **então** vê o
  que seria salvo (incluindo a competência resultante) e **nada é gravado**.
- **C7** — **Dado** o `PUT` confirmado, **então** descrição/valor/categoria/vencimento mudam **só
  naquela ocorrência**; o **molde** da recorrência fica intacto.
- **C8 (borda)** — **Dado** uma ocorrência **de cartão**, **quando** se tenta editar pela linha,
  **então** é recusada: ela é item de fatura (R8) e divergiria do extrato do cartão.

**Alvos nas listagens**

- **C9** — **Dado** o extrato, **então** cada linha carrega o id **opaco** do seu alvo e as flags
  `pagavel` / `pago` / `editavel`; o id **sobrevive ao pagamento** (é ele que permite desmarcar).
- **C10** — **Dado** o dashboard, **então** cada conta de "em atraso"/"a vencer" carrega
  `parcelaId`/`ocorrenciaId` e `transactionId` opacos; a linha **condensada de fatura** não
  carrega alvo algum.
- **C11 (barreira)** — **Dado** o payload entregue ao **modelo de IA**, **então** ele não contém id
  algum — só descrição, valor e vencimento.

**Quitar pelo bot**

- **C12** — **Dado** "paguei a luz", **então** a IA classifica `pagar` e extrai **só o termo**
  ("luz"); o domínio acha a conta e o bot pede confirmação citando valor e vencimento **vindos do
  banco**. Nada é gravado antes do "sim" (regra 7).
- **C13** — **Dado** que **duas** contas casam com o termo, **então** o bot **lista numerado e
  pergunta** — nunca escolhe. O número escolhe; o "sim" grava.
- **C14** — **Dado** que **nada** casa, **então** o bot diz que não achou e **não inventa** conta.
- **C15 (borda)** — Conta **de cartão** e conta **de outro usuário** nunca aparecem como candidatas.

## 4. Barreiras e invariantes

1. **Cartão nunca é pago na linha** (§4.3 / spec 12 D3), em nenhuma superfície. A fatura é quem
   quita; marcar a compra individual pagaria a mesma conta duas vezes. Na recorrência há uma
   segunda razão: a cobrança de cartão liquida sozinha (`LiquidarOcorrenciasDeCartao`), então
   desmarcá-la só produziria vaivém — o agendador a marcaria paga de novo.
   **"Em nenhuma superfície" é literal** e a lista é fechada: extrato, tela de detalhe, os dois
   quadros do dashboard, bot/chat e qualquer rota futura. Só é pagável a conta **fora de
   cartão** — parcela (`RegistrarPagamentoParcela`) ou ocorrência (`PagarOcorrencia`), ambas
   recusando cartão com `PagamentoNaoPermitidoException::ehCartao()`. A recusa é de **domínio**,
   não de tela: esconder o botão é UX, a barreira é o serviço rejeitar a requisição forjada.
   Vale também para a conta fixa apenas **prevista** (projeção do molde, sem ocorrência ainda):
   materializá-la sob demanda só para poder pagá-la é recusado quando o molde é de cartão — a
   ocorrência sequer é criada, para não deixar rastro de uma conta que o cartão liquidaria
   sozinho depois.
2. **`cancelado` não reabre.** Reabrir devolveria ao Disponível/Consumo um valor já anulado.
3. **O status de volta é derivado, não cravado.** `StatusDaParcela::para(vencimento, hoje)` — ver C1.
4. **Escopo estrito por `user_id`** em todo alvo, em todo canal; 404 para item alheio.
5. **Nenhum id real em URL ou payload** — sempre `OpaqueId`. Id em claro no path ⇒ 404.
6. **A IA nunca vê nem escolhe valor.** O agente do fluxo de pagamento **não tem campo de valor**
   no schema, deliberadamente: um modelo prestativo "confirmaria" um valor sugerido pela frase e a
   regra 4 estaria quebrada no ponto mais caro possível — quitar a conta errada.
7. **Confirmar antes de gravar** (regra 7): modal com prévia na web; "sim" explícito no bot.
   Ambiguidade nunca é resolvida por chute.
8. **Idempotência** em todos os caminhos de pagar/desmarcar, e **auditoria** em cada mudança real.

## 5. Modelo de dados

**Nenhuma migration.** Duas notas:

- `audit_log.acao` ganhou o valor **`desmarcar_pagamento`** — é `string` sem CHECK, então não há
  DDL. É o estorno da **marcação**, distinto de `cancelar` (que anula o gasto) e de `estornado`.
- A fila `telegram_pending_confirmations` ganhou um terceiro valor de `tipo`: **`pagamento`**, ao
  lado de `confirmacao` e `esclarecimento`. Coluna já existente, sem DDL.

## 6. Contratos do domínio

| Classe | Papel |
|---|---|
| `App\Domain\Gasto\ReverterPagamentoParcela::reverter(int $installmentId, int $userId, CarbonImmutable $hoje): Installment` | Inverso de `RegistrarPagamentoParcela`. "Hoje" injetado porque o status de volta é derivado da data. |
| `App\Domain\Gasto\StatusAgregadoDaTransacao::reavaliar(Transaction $t): void` | Derivação única do status do lançamento a partir das parcelas — usada pelos dois lados (pagar e desmarcar). |
| `App\Domain\Recorrencia\ReverterPagamentoOcorrencia::reverter(int $id, int $userId): ?RecurrenceOccurrence` | Inverso de `PagarOcorrencia`; `null` quando não havia o que desfazer. |
| `App\Domain\Pagamento\ContaPagavel` | DTO comum às duas fontes de conta a pagar (parcela · ocorrência), serializável para a fila. |
| `App\Domain\Pagamento\ResolverContaAPagar::para(int $userId, string $termo, CarbonImmutable $hoje): list<ContaPagavel>` | **A peça determinística do bot.** Busca nas duas fontes, fora de cartão, em aberto, na janela [-180d, +45d], ordenado por vencimento asc, teto de 5 candidatos. |
| `App\Domain\Pagamento\PagarContaPagavel::pagar(ContaPagavel, int $userId, CarbonImmutable): void` | Despachante; não reimplementa regra, delega aos dois serviços testados. |
| `App\Domain\Pagamento\PagamentosPendentes` | Fila `tipo=pagamento` (TTL 15 min). Guarda os **candidatos já resolvidos** — no "sim" quita-se exatamente o que foi mostrado, não uma nova busca. |
| `App\Ai\Agents\ExtratorDeContaPaga::extrair(string $texto): ?string` | Devolve **só o termo**. Sem campo de valor (barreira 6). |

**Decisões de regra tomadas nesta etapa**

- **D1 — Janela de busca do bot.** `ResolverContaAPagar` limita a [-180d, +45d] de hoje. Sem isso,
  "internet" casaria com a parcela de dezembro de um parcelamento longo, que não é "a que paguei
  hoje". A janela é larga o bastante para a conta esquecida de meses atrás.
- **D2 — Escolha por número puro.** Na desambiguação, só `^\d+$` conta como escolha. "paguei 2
  contas" não pode selecionar o item 2.
- **D3 — Data do pagamento por tipo de conta.** A **parcela** guarda a data (o usuário informa
  quando pagou); a **ocorrência** registra o instante da confirmação. Por isso o modal pede data
  numa e não na outra.
- **D4 — Edição da ocorrência é inline, não tela nova.** A ocorrência não tem tela de detalhe; o
  form abre na própria linha, preenchido e formatado pelo backend, e o submit é a confirmação.

## 7. Plano de testes (test-first)

1. **Unitários/domínio** — `ReverterPagamentoParcelaTest` (C1–C5 + status derivado + agregação
   zero-pagas) · `ReverterPagamentoOcorrenciaTest` (C1, C3–C5 + cartão + cancelada) ·
   `ResolverContaAPagarTest` (C15, ordenação, curinga do usuário como texto, termo vazio, janela).
2. **Payload de leitura** — `ConsultarLancamentosTest` (C9, incluindo o id que **sobrevive** ao
   pagamento) · `AlvosDeAcaoNasContasTest` (C10 **e C11** — o prompt da IA não contém id).
3. **Contrato/borda web** — `DesmarcarPagamentoWebTest` (as duas rotas, 404 para id em claro e
   para item alheio, cartão sem 500, idempotência na borda) ·
   `EditarOcorrenciaWebTest` (C6–C8, validação, categoria alheia ignorada).
4. **Bot (fakes da SDK)** — `PagarContaViaBotTest` (C12–C15, "não" descarta, ocorrência de
   recorrência, extrator recebe o texto íntegro, redação cita os números do banco).

## 8. Backend agora · Frontend depois

| Backend (F1–F3) | Frontend (F4, commit separado) |
|---|---|
| `ReverterPagamentoParcela` · `ReverterPagamentoOcorrencia` · `StatusAgregadoDaTransacao` | `components/lancamento/row.blade.php`: **dois** botões (ação de dinheiro + editar) |
| Rotas `…/parcela/{parcela}/desmarcar`, `…/recorrencia/{ocorrencia}/desmarcar` | `components/dashboard/bill-row.blade.php`: pagar + editar nos quadros |
| Rotas `…/ocorrencia/{ocorrencia}/previa` e `PUT …/ocorrencia/{ocorrencia}` + `EditarOcorrenciaRequest` | Form inline de edição "só este mês" na linha (D4) |
| Alvos no payload de `ConsultarLancamentos`, `ConsultarProximasContas`, `ConsultarContasVencidas` | Ícone `rotate-ccw`; campo "quando você pagou?" (D3) |
| `Intencao::PAGAR` · `ExtratorDeContaPaga` · `ResolverContaAPagar` · `PagamentosPendentes` · braço em `ProcessarInteracao` | Redação do bot em `RedatorDoChat` (prévia · lista numerada · quitado · não encontrado) |

## 9. Definition of Done

- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas, com teste para cada uma (inclusive C11, o não-vazamento de id
      para o prompt).
- [x] Nenhum segredo/dado sensível persistido ou commitado.
- [x] §10 preenchida com os artefatos reais.
- [ ] Commit local atômico, separando backend de frontend (o usuário commita à mão).

## 10. Estado atual / artefatos

- **Status:** ✅ Implementado. Suíte: **1283 passed** · Pint limpo.

**Domínio (novo)**
`app/Domain/Gasto/ReverterPagamentoParcela.php` · `app/Domain/Gasto/StatusAgregadoDaTransacao.php`
· `app/Domain/Recorrencia/ReverterPagamentoOcorrencia.php` ·
`app/Domain/Pagamento/{ContaPagavel,ResolverContaAPagar,PagarContaPagavel,PagamentosPendentes}.php`
· `app/Ai/Agents/ExtratorDeContaPaga.php`.

**Domínio (tocado)**
`RegistrarPagamentoParcela` (passou a usar a derivação compartilhada) ·
`ConsultarLancamentos`, `ConsultarProximasContas`, `ConsultarContasVencidas` (alvos no payload) ·
`Intencao` (+`PAGAR`) · `ClassificadorDeIntencao` (prompt) · `ProcessarInteracao` (braço + fila) ·
`TipoDeInteracao`/`ResultadoDaInteracao` (4 tipos novos) · `RedatorDoChat` (redação) ·
`AuditLog::ACAO_DESMARCAR_PAGAMENTO` · `Money::formatPtBr()` (valor sem "R$", para campo de form).

**Borda** `routes/web.php` (4 rotas novas) · `LancamentoController` (`desmarcarParcela`,
`desmarcarRecorrencia`, `urlDePagamento`) · `RecorrenciaController` (`previaOcorrencia`,
`atualizarOcorrencia`) · `DashboardController` (`alvoDePagamento`) ·
`app/Http/Requests/EditarOcorrenciaRequest.php`.

**Frontend** `components/lancamento/row.blade.php` · `components/dashboard/bill-row.blade.php` ·
`lancamentos.blade.php` · `home.blade.php` · `components/icon.blade.php` (`rotate-ccw`).

**Testes** `ReverterPagamentoParcelaTest` · `ReverterPagamentoOcorrenciaTest` ·
`ResolverContaAPagarTest` · `AlvosDeAcaoNasContasTest` · `DesmarcarPagamentoWebTest` ·
`EditarOcorrenciaWebTest` · `PagarContaViaBotTest`, mais extensões em `ConsultarLancamentosTest`.

### Bug latente corrigido de passagem

`RegistrarPagamentoParcela::reavaliarStatusDaTransacao` derivava o status agregado como
"todas pagas → `pago`, **senão** `pago_parcial`". Com **zero** parcelas pagas isso devolvia
`pago_parcial` — um status que mente sobre o que foi pago. Não se manifestava porque o único
caminho até ali sempre vinha de um pagamento (havia ao menos uma paga); o estorno criou o caso.
A derivação virou `StatusAgregadoDaTransacao`, compartilhada pelos dois lados.

### Extensão — quitar a conta fixa ainda PREVISTA (2026-07-21)

O quadro do dashboard oferecia "marcar como paga" na ocorrência **real**, mas a linha **prevista**
(projeção do molde, spec 10b) não tinha alvo algum: sem ocorrência no banco, não há id. Quem paga
adiantado — ou navega para o mês seguinte — via a conta e não tinha o que clicar.

- **D5 — O alvo da linha prevista é o MOLDE + a COMPETÊNCIA**, não um id de ocorrência (que não
  existe). `POST /lancamentos/recorrencia-prevista/{recorrencia}/pagar` com `competencia` no
  corpo: o domínio materializa exatamente aquela competência
  (`App\Domain\Recorrencia\MaterializarOcorrencia`, reusando o snapshot de `GerarOcorrencias`) e
  então paga pelo caminho já testado (`PagarOcorrencia`). Nenhuma regra de dinheiro nova.
- **O ponteiro `proxima_em` não se move.** Ele é do agendador; a competência materializada aqui
  cai fora dele pela UNIQUE `(recurrence_id, competencia)` e some da projeção pelo `NOT EXISTS`
  — sem dupla contagem no disponível.
- **Barreiras herdadas:** cartão recusado (D3/§4.3, nem materializa); mês já encerrado recusado
  (projetar no retrato fechado inventaria cobrança); molde alheio/cancelado ⇒ 404; competência
  fora do formato ⇒ 422; idempotente ponta a ponta.
- **Achado colateral corrigido:** o dashboard decidia "estado vazio" olhando só `transactions`.
  Como recorrência não escreve lá (spec 12), quem só tinha conta fixa via a tela vazia — e com
  ela sumia o próprio quadro onde essas contas se pagam.

A extensão vale nas **duas** superfícies de leitura: quadros do dashboard e extrato. A regra do
alvo é a mesma (molde + competência) e o alvo só aparece onde a linha é pagável — em cartão não
há alvo algum, nem no payload.

Artefatos: `app/Domain/Recorrencia/MaterializarOcorrencia.php` · `ProjetarRecorrencias` e
`ConsultarLancamentos` (alvo no payload) · `LancamentoController::pagarRecorrenciaPrevista`,
`urlDePagamento` + rota · `DashboardController` (`alvoDePagamento`, `competencia`, estado) ·
`components/dashboard/bill-row.blade.php` e `components/lancamento/row.blade.php` (campo oculto) ·
`home.blade.php` · `lancamentos.blade.php`. Testes: `MaterializarOcorrenciaTest` ·
`PagarPrevistaWebTest` + extensões em `AlvosDeAcaoNasContasTest`, `DashboardQuadroDeContasTest`,
`ConsultarLancamentosTest` e `LancamentosWebTest`. Suíte: **1311 passed**.

### Pendências conhecidas

- **Precedência `registrar` × `pagar`.** "Paguei 40 no mercado" (gasto novo já quitado) e "paguei a
  luz" (conta existente) são separados **pelo prompt** do classificador: a presença de um valor
  novo puxa para `registrar`. Isso não tem golden set contra o provedor real — vale a ressalva já
  registrada de que os fakes da SDK devolvem o payload que **nós** escrevemos, não a saída do
  modelo (ver [[spec-04c-rotacao-provedores-ia]] e a nota sobre fakes).
- **Pagar a fatura inteira** continua fora do alcance até a [[spec-09-faturas-materializadas]].
- **Editar a linha prevista continua impossível** — e é por construção: sem ocorrência no banco
  não há o que editar "só este mês". Quem quiser mudar o valor do mês paga (materializando) e
  edita a ocorrência resultante, ou muda o molde.
