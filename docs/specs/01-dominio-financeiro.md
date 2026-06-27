# Spec 01 — Domínio financeiro

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões de regra que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 1 · F1 |
| **Status** | ✅ Concluído |
| **Depende de** | [[spec-00-fundacoes-devops]] |
| **Habilita** | [[spec-02-cadastro-manual-receitas]] · [[spec-04-ia-interpretacao]] · [[spec-05-chat-financeiro]] · [[spec-06-dashboard]] · [[spec-07-importacao-pdf]] |
| **Fonte de verdade** | seção 4 do escopo · [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) · [`docs/04-modelo-dados.md`](../04-modelo-dados.md) |
| **Regras críticas** | 2 (test-first) · 4 (IA nunca calcula) · 5 (centavos BIGINT, fuso SP) |

---

## 1. Objetivo
Entregar o **motor financeiro determinístico** — dinheiro em centavos, datas no fuso
America/Sao_Paulo, geração de parcelas, vencimento (cartão vs. fora de cartão), fórmula do
"disponível do mês" e detecção de duplicidade. É a **base de tudo**: todo valor monetário
e toda data nascem aqui, 100% testados, para que a IA jamais precise calcular (regra 4).

## 2. Escopo
- **Inclui (backend desta etapa):**
  - VO monetário `Money` (centavos ↔ pt-BR, soma/subtração, rateio sem perder centavo).
  - Resolução de datas relativas no fuso SP com "agora" injetado (`RelativeDate`).
  - Geração de parcelas futuras e cálculo da parcela vigente (`GeradorDeParcelas`/`Parcela`).
  - Cálculo de vencimento por ciclo de fatura do cartão e fora de cartão (`CalculadoraDeVencimento`).
  - Fórmula do disponível do mês + previsto do próximo mês (`DisponivelDoMes`/`ResultadoDisponivel`).
  - Detecção de duplicidade por chave canônica (`DetectorDeDuplicidade`/`ChaveDeDuplicidade`).
  - Schema de referência e suporte: `payment_methods`, `status_pagamento`, `accounts`,
    `cards`, `transactions`, `installments` + ajustes em `users`.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - CRUD de gastos/receitas/orçamento e categorização → [[spec-02-cadastro-manual-receitas]].
  - Consulta SQL que **soma** os gastos por mês de vencimento (alimenta `DisponivelDoMes`) → Blocos 2/3.
  - Interpretação/redação por IA → [[spec-04-ia-interpretacao]] · [[spec-05-chat-financeiro]].
  - Mensagens do bot e telas web (qualquer formatação de saída) → frontend, etapa separada.
  - Moeda estrangeira (campo `moeda` já existe, mas cálculo é pós-MVP).

## 3. Cenários de aceite (Given-When-Then)

**Money (centavos ↔ pt-BR)**
- **C1 — Dado** a entrada humana `"1.234,56"`, **Quando** `Money::fromHuman()`, **Então**
  guarda `123456` centavos; `formatBRL()` devolve `"R$ 1.234,56"` (formatação só na borda).
- **C2 — Dado** um total de `10000` centavos em **3** parcelas, **Quando** `allocate(3)`,
  **Então** devolve `[3334, 3333, 3333]` — soma exata, resto nas primeiras (zero centavo perdido).
- **C3 (borda) — Dado** uma string sem dígito (`"abc"`), **Quando** `fromHuman()`, **Então**
  lança `InvalidArgumentException`; `allocate(0)` também lança (N < 1).

**Datas relativas (fuso SP)**
- **C4 — Dado** um `now` em UTC e o termo `"amanhã"`, **Quando** `RelativeDate::resolve()`,
  **Então** resolve no fuso America/Sao_Paulo, no início do dia (determinístico, "agora" injetado).
- **C5 (borda) — Dado** `"mês que vem"` com dia 31 e mês seguinte de 30 dias, **Então** faz
  clamp para o último dia válido (sem overflow); termo desconhecido lança exceção.

**Geração de parcelas**
- **C6 — Dado** total `R$ 100,00`, **3** parcelas, 1º vencimento `10/01`, **Quando**
  `GeradorDeParcelas::gerar()`, **Então** 3 parcelas `1/3, 2/3, 3/3` vencendo `10/01, 10/02,
  10/03`, valores `[3334, 3333, 3333]`, sempre contadas a partir do primeiro (sem drift).
- **C7 (borda) — Dado** 1º vencimento `31/01`, **Então** a parcela seguinte faz clamp em
  `28/02` (mês curto). **A parcela vigente é sempre derivada** (`parcelaVigente()`), nunca fixada.

**Vencimento (cartão vs. fora de cartão)**
- **C8 — Dado** uma compra **fora de cartão** (PIX/débito/dinheiro/boleto), **Quando**
  `foraDeCartao()`, **Então** o vencimento é a própria data da compra/parcela.
- **C9 — Dado** uma compra **em cartão** (fechamento dia 20, vencimento dia 5) feita **antes**
  do fechamento, **Então** vence no ciclo da fatura corrente; feita **depois** do fechamento,
  cai na fatura seguinte — sempre o vencimento do cartão (regra §4.2).

**Disponível do mês**
- **C10 — Dado** receitas, gastos de cartão **com vencimento no mês** e gastos fora de cartão,
  **Quando** `DisponivelDoMes::calcular()`, **Então** `disponível = receitas − cartãoNoMês −
  foraDeCartão`; cobranças do mês seguinte não entram, compõem `previstoProximoMes`.
- **C11 (regra) — Dado** uma **fatura ainda aberta** cujo vencimento cai no mês corrente,
  **Então** seus gastos **já entram** no disponível (visão antecipada); cada gasto pertence a
  **um único** mês de vencimento (sem dupla contagem).

**Duplicidade**
- **C12 — Dado** um lançamento existente, **Quando** chega outro com **mesmo valor +
  descrição (normalizada) + data + nº de parcelas**, **Então** `ehDuplicado()` é `true`.
- **C13 (borda) — Dado** que a **parcela atual NÃO** compõe a chave, **Então** `2/6` e `3/6`
  do mesmo plano não se anulam por duplicidade; `apenasNovos()` descarta repetições do lote
  e preexistentes, preservando ordem.

## 4. Barreiras e invariantes
- **Regra 4 — A IA nunca calcula dinheiro.** Tudo nesta etapa é determinístico e testado; a
  IA não toca em nenhum cálculo. Estes serviços são o "conjunto-verdade" que o guard de IA
  (Bloco 5) usa para barrar números alucinados.
- **Regra 5 — Centavos inteiros (BIGINT), fuso SP.** Nenhum `float`: `Money` opera só em
  `int`. Toda data passa pelo fuso `America/Sao_Paulo` com `CarbonImmutable` e "agora"
  **injetado** (nunca relógio global) — determinismo total. Formatação pt-BR só na borda
  (`formatBRL()`, `rotulo()`).
- **Parcela vigente nunca persistida.** A estrutura N/total é gravada; a parcela atual é
  sempre derivada na exibição (`parcelaVigente()`).
- **Valor por parcela nunca persistido.** Derivado do total via `Money::allocate()`; nem
  `transactions` nem `installments` têm coluna de valor por parcela.
- **Sem perda de centavo.** `allocate()` distribui o resto nas primeiras parcelas; a soma das
  partes é sempre igual ao total.
- **Duplicidade nunca usa a parcela atual.** A chave canônica sequer aceita esse campo.

## 5. Modelo de dados
Tabelas de referência e suporte criadas/tocadas nesta etapa (migrations em
`database/migrations/`; par com a skill `dba-postgres`):

| Tabela | Colunas-chave | Notas |
|---|---|---|
| `users` (ajuste) | `timezone` (default `America/Sao_Paulo`), `aceite_lgpd_em`, `deleted_at`, `name` nullable | Fuso base + consentimento LGPD + soft delete. |
| `payment_methods` | `id`, `tipo` (unique, CHECK) | Referência global. `credito` é a única em cartão; demais fora de cartão. `boleto` adicionado depois (Bloco 4). |
| `status_pagamento` | `id`, `codigo` (unique), `descricao` | Referência global, 8 códigos (§4.4). |
| `accounts` | `id`, `user_id`, `banco`, `descricao` | PIX/débito. Soft delete; unique parcial `(user_id, banco, descricao) WHERE deleted_at IS NULL`. |
| `cards` | `id`, `user_id`, `descricao`, `final_4`, `limite_cents?` (BIGINT), `dia_fechamento`, `dia_vencimento` | Identidade `(user_id, final_4, descricao)` única entre ativos; CHECK dias 1..31 e limite ≥ 0 (pgsql). |
| `transactions` | `id`, `user_id`, `descricao`, `valor_total_cents` (BIGINT), `data_compra`, `payment_method_id`, `card_id?`, `account_id?`, `categoria_id?`, `status_id`, `origem` (CHECK manual/telegram/pdf), `moeda` (default BRL) | Lançamento. Sem valor por parcela. Soft delete. |
| `installments` | `id`, `transaction_id`, `numero`, `total`, `vencimento`, `status_id` | **Sem coluna de valor** (derivado). Unique `(transaction_id, numero)`; CHECK `1 ≤ numero ≤ total` (pgsql). |

Convenções: dinheiro sempre `bigInteger ...\_cents`; instantes em `timestamptz`
(`timestampsTz`/`softDeletesTz`); datas de negócio em `date`.

## 6. Contratos do domínio
Assinaturas públicas reais (todas as classes `final`, estáticas e puras; `CarbonImmutable`):

```php
// app/Domain/Shared/Money.php  — VO monetário imutável (centavos)
Money::fromCents(int $cents): self
Money::fromReais(int $reais): self
Money::fromHuman(string $input): self          // pt-BR: vírgula=decimal, ponto=milhar
->cents(): int
->plus(Money): self / ->minus(Money): self / ->times(int): self / ->equals(Money): bool
->allocate(int $parts): array<int,Money>       // rateio sem perder centavo (resto nas 1ªs)
->formatBRL(): string                          // borda: "R$ 1.234,56"

// app/Domain/Calendar/RelativeDate.php  — datas relativas, fuso SP, "agora" injetado
RelativeDate::TIMEZONE = 'America/Sao_Paulo'
RelativeDate::resolve(string $term, CarbonImmutable $now, ?int $diaPadrao = null): CarbonImmutable
// termos: hoje, ontem, amanhã, "mês que vem"

// app/Domain/Parcelamento/GeradorDeParcelas.php  + Parcela (numero,total,vencimento,valor)
GeradorDeParcelas::gerar(int $totalCents, int $parcelas, CarbonImmutable $primeiroVencimento): array<int,Parcela>
GeradorDeParcelas::parcelaVigente(CarbonImmutable $primeiroVencimento, int $parcelas, CarbonImmutable $referencia): int

// app/Domain/Vencimento/CalculadoraDeVencimento.php  — doc 03 §4.2
CalculadoraDeVencimento::foraDeCartao(CarbonImmutable $dataParcela): CarbonImmutable
CalculadoraDeVencimento::cartao(CarbonImmutable $dataCompra, int $diaFechamento, int $diaVencimento): CarbonImmutable

// app/Domain/Disponivel/DisponivelDoMes.php  + ResultadoDisponivel (disponivel, previstoProximoMes)
DisponivelDoMes::calcular(int $receitasCents, int $cartaoVencendoNoMesCents, int $foraDeCartaoCents, int $cobrancasProximoMesCents = 0): ResultadoDisponivel

// app/Domain/Duplicidade/DetectorDeDuplicidade.php  + ChaveDeDuplicidade
ChaveDeDuplicidade::de(int $valorCents, string $descricao, CarbonImmutable $data, int $parcelas): self   // normaliza descrição; SEM parcela atual
DetectorDeDuplicidade::ehDuplicado(ChaveDeDuplicidade $candidato, array $existentes): bool
DetectorDeDuplicidade::apenasNovos(array $candidatos, array $existentes): array
```

> A IA não entra nesta etapa: estes contratos são **só cálculo**. Quando houver IA
> (Blocos 4/5), ela apenas aciona estes serviços e redige sobre os números já calculados.

## 7. Plano de testes (test-first — devem falhar primeiro)
1. **Unitários do domínio** (`tests/Unit/Domain/`):
   - `MoneyTest` — `fromHuman`/`formatBRL` ida-e-volta pt-BR, `allocate` sem perda de centavo
     (incl. negativos), erros (sem dígito, N < 1).
   - `RelativeDateTest` — hoje/ontem/amanhã/"mês que vem" no fuso SP com `now` injetado,
     clamp de mês curto, termo inválido.
   - `GeradorDeParcelasTest` — N parcelas, vencimentos a partir do primeiro (sem drift),
     clamp 31→28/02, `parcelaVigente` com clamp `[1, total]`.
   - `CalculadoraDeVencimentoTest` — fora de cartão = própria data; cartão antes/depois do
     fechamento; vencimento < fechamento cai no mês seguinte; clamp de dias.
   - `DisponivelDoMesTest` — fórmula oficial; fatura aberta no mês corrente entra; previsto
     do próximo mês separado; sem dupla contagem.
   - `DuplicidadeTest` — igualdade por chave (valor+descrição+data+parcelas), descrição
     normalizada, parcela atual fora da chave, `apenasNovos` (dedupe de lote + preexistentes).
2. **Contrato/integração** (`tests/Feature/Domain/`) — schema e referências:
   - `PaymentMethodTest`, `StatusPagamentoTest`, `AccountTest`, `CardTest` — tabelas de
     referência/suporte, uniques parciais, CHECKs, soft delete.

> Cada item de backend só é "feito" com **testes verdes e cobertura**. Use os **fakes da
> Laravel AI SDK** para a camada de IA (offline, determinístico) — não há IA nesta etapa.

## 8. Backend agora · Frontend depois
| Backend (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| Motor determinístico: `Money`, `RelativeDate`, parcelas, vencimento, disponível, duplicidade + schema de referência/suporte. | Qualquer exibição: mensagens do bot e telas web mostrando valores formatados, parcelas, disponível e alertas. |
| Formatação só nos métodos de borda (`formatBRL`, `rotulo`), sem montar mensagem/tela. | Layout, textos, gráficos do dashboard. |

## 9. Definition of Done
- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (centavos/BIGINT, fuso SP, "agora" injetado, parcela e valor por parcela derivados).
- [x] Sem segredo/PDF/dado sensível persistido ou commitado.
- [x] Commits locais atômicos, em português, separando backend de frontend.
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ Concluído (backend).
- **Entregue:**
  - `app/Domain/Shared/Money.php`
  - `app/Domain/Calendar/RelativeDate.php`
  - `app/Domain/Parcelamento/GeradorDeParcelas.php` · `app/Domain/Parcelamento/Parcela.php`
  - `app/Domain/Vencimento/CalculadoraDeVencimento.php`
  - `app/Domain/Disponivel/DisponivelDoMes.php` · `app/Domain/Disponivel/ResultadoDisponivel.php`
  - `app/Domain/Duplicidade/DetectorDeDuplicidade.php` · `app/Domain/Duplicidade/ChaveDeDuplicidade.php`
  - Migrations: `2026_06_25_000001_adjust_users_for_domain.php`,
    `..._000002_create_payment_methods_table.php`, `..._000003_create_status_pagamento_table.php`,
    `..._000004_create_accounts_table.php`, `..._000005_create_cards_table.php`,
    `..._000006_create_transactions_table.php`, `..._000007_create_installments_table.php`.
  - Testes: `tests/Unit/Domain/{MoneyTest, RelativeDateTest, GeradorDeParcelasTest,
    CalculadoraDeVencimentoTest, DisponivelDoMesTest, DuplicidadeTest}.php`;
    `tests/Feature/Domain/{PaymentMethodTest, StatusPagamentoTest, AccountTest, CardTest}.php`.
  - Executar: `make test`.
- **Adiado para:**
  - Varredura SQL que soma gastos por mês de vencimento (alimenta `DisponivelDoMes`) → Blocos 2/3.
  - `StatusDaParcela` (status inicial pela data) e CRUD → [[spec-02-cadastro-manual-receitas]].
  - Uso destes serviços pela IA com guard → [[spec-04-ia-interpretacao]] · [[spec-05-chat-financeiro]].
  - Toda apresentação (bot/web) → frontend.
- **Decisões de regra tomadas:**
  - **Disponível inclui o cartão pelo mês de VENCIMENTO**, incluindo **fatura aberta** cujo
    vencimento cai no mês corrente (visão antecipada). Cada gasto pertence a um único mês de
    vencimento — sem dupla contagem (doc 03 §4.5; revoga a regra original do §4.5 do escopo).
  - **`DisponivelDoMes` é puro:** recebe totais já consultados (centavos) e aplica a fórmula;
    a soma por mês de vencimento direto do banco é da camada de consulta.
  - **`installments` sem coluna de valor**: valor por parcela derivado de `valor_total_cents`
    via `Money::allocate()` (resto nas primeiras); parcela vigente sempre calculada.
  - **"Agora" sempre injetado** (`RelativeDate`, `parcelaVigente`, status) para determinismo
    total — nunca o relógio global.
