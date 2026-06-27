# Spec 02 — Cadastro manual, receitas e orçamento

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões de regra que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 2 · F2/F3 |
| **Status** | ✅ Concluído (backend) · frontend é etapa separada |
| **Depende de** | [[spec-01-dominio-financeiro]] |
| **Habilita** | [[spec-04-ia-interpretacao]] · [[spec-06-dashboard]] |
| **Fonte de verdade** | seções 4, 5 e 9 do escopo · [`docs/08-categorias.md`](../08-categorias.md) · [`docs/04-modelo-dados.md`](../04-modelo-dados.md) · [`docs/03-regras-financeiras.md`](../03-regras-financeiras.md) |
| **Regras críticas** | 2 (test-first), 3 (frontend separado), 4 (IA nunca calcula), 5 (centavos), 7 (confirmar antes de gravar) |

---

## 1. Objetivo
Dar ao usuário o **CRUD de gastos** (com status, origem e auditoria), a
**classificação determinística de categoria** (com aprendizado por correção) e o
registro de **receitas** e **orçamento mensal** — a base sobre a qual o "disponível do
mês" e o dashboard são calculados, sempre de forma determinística e sem a IA tocar em
dinheiro.

## 2. Escopo
- **Inclui (backend desta etapa):**
  - CRUD de gasto manual: registrar (com **preview** sem gravar), editar (regenera
    parcelas), cancelar (preserva pagas), excluir (soft delete LGPD); origem `manual` e
    trilha de auditoria em cada operação.
  - Geração de parcelas reusando o motor do Bloco 1 (vencimento → valor derivado →
    status por data); detecção de duplicidade na pré-visualização.
  - Classificação determinística de categoria por **lookup** (alias de
    estabelecimento > palavra-chave) e **aprendizado por correção** (correção vira/atualiza
    um alias).
  - Receitas do mês e orçamento mensal **geral**; **consumo do mês** total e **por
    categoria**.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - **Frontend** (telas web de gastos/categorias/receitas/orçamento e mensagens do bot) —
    etapa separada (regra 3).
  - **Fiação completa do "disponível do mês"** — building blocks aqui; a consulta
    consolidada é etapa posterior ([[spec-06-dashboard]]).
  - **Orçamento por categoria** e **disparo/limiar de alerta** — pós-MVP (decisão de §4).
  - Pagamentos/estornos como ação de usuário, recorrências/assinaturas (entidade própria),
    moeda estrangeira — fora desta etapa.

## 3. Cenários de aceite (Given-When-Then)

- **C1 — Registrar parcelado.** **Dado** um gasto em cartão parcelado em N **Quando**
  confirmo o registro **Então** são geradas as N parcelas (estrutura N/total, vencimentos
  pelo ciclo do cartão, valor de cada parcela **derivado** do total), com status por data
  (futuro `agendado`, hoje `aberto`, vencido `vencido`), origem `manual`, e é registrada
  auditoria de criação.
- **C2 — Preview não grava (regra 7).** **Dado** os dados de um gasto **Quando** peço o
  `preview()` **Então** vejo o que **será** gravado (valor total, parcelas, se há
  duplicidade) **sem** persistir nada no banco.
- **C3 — Editar regenera parcelas.** **Dado** um gasto sem parcela paga **Quando** edito
  (valor/parcelas/cartão) **Então** as parcelas são **regeneradas** deterministicamente do
  zero e o antes/depois é auditado.
- **C4 (borda) — Editar bloqueia se há parcela paga.** **Dado** um gasto com parcela
  `pago`/`pago_parcial` **Quando** tento editar **Então** a operação é **recusada**
  (`EdicaoBloqueadaException`) e nada muda — regenerar apagaria o histórico de pagamento.
- **C5 — Cancelar preserva pagas.** **Dado** um gasto com parcelas em vários status
  **Quando** cancelo **Então** a transaction e as parcelas **não finalizadas** viram
  `cancelado`, mas parcelas `pago`/`pago_parcial`/`estornado` são **preservadas**, a linha
  é mantida (histórico) e há auditoria.
- **C6 — Excluir é soft delete (LGPD).** **Dado** um gasto **Quando** excluo **Então** a
  linha sai das consultas normais mas **permanece** no banco (soft delete), com auditoria
  de exclusão sem dado sensível.
- **C7 — Lookup determinístico de categoria.** **Dado** descrições com palavras-chave/aliases
  do usuário **Quando** classifico **Então** vence o **alias** sobre a palavra-chave e, em
  empate de tipo, a regra mais **longa** (mais específica); sem casamento → `null`. Escopo
  estrito por usuário.
- **C8 — Correção vira alias.** **Dado** uma classificação incorreta **Quando** o usuário
  corrige o estabelecimento para outra categoria **Então** um `merchant_alias` é
  **criado ou atualizado** (único por usuário, normalizado), e a próxima classificação
  daquele estabelecimento já segue a correção.
- **C9 — Consumo do mês (geral e por categoria).** **Dado** parcelas vencendo no mês
  **Quando** consulto o consumo **Então** recebo o total e a quebra **por categoria**
  (gastos sem categoria num balde próprio), excluindo `pendente_revisao`/`cancelado`/`estornado`,
  com o valor de cada parcela **derivado** do total.
- **C10 — Orçamento mensal geral.** **Dado** um limite geral do mês e o consumo do mês
  **Quando** avalio o orçamento **Então** recebo limite, consumido, restante e se
  **estourou** (igualar o limite **não** estoura); sem orçamento, limite = 0. **Sem
  disparo de alerta** no MVP.

## 4. Barreiras e invariantes
- **Regra 4 — a IA nunca calcula dinheiro.** Todo cálculo (parcelas, vencimentos, consumo,
  orçamento, receitas) é determinístico no domínio; a IA não passa por nenhuma classe desta
  etapa. A classificação de categoria também é **determinística** (lookup), não IA.
- **Regra 5 — centavos inteiros (BIGINT).** Valores em `*_cents`; `Money` na fronteira do
  domínio; formatação pt-BR só na borda (frontend). Fuso base **America/Sao_Paulo**; o
  "hoje" é **injetado** (determinismo). Valor de parcela **derivado**, nunca persistido.
- **Regra 7 — confirmar antes de persistir.** `preview()` calcula e mostra **sem gravar**;
  só `confirmar()` persiste, de forma **atômica** (`DB::transaction`).
- **Escopo estrito por usuário.** Toda leitura/escrita filtra por `user_id`; regras de
  categoria de terceiros nunca participam do lookup.
- **Soft delete + auditoria sem dado sensível.** Exclusão é lógica (LGPD); `audit_log`
  guarda antes/depois e origem, sem nome/CPF/endereço.
- **Decisão de escopo (alerta por categoria).** Entrega-se **apenas o consumo por
  categoria**; **disparo e limiar de alerta ficam pós-MVP** (ver doc 04/08). O `percentual()`
  é razão (não dinheiro), exposto só para apresentação futura.

## 5. Modelo de dados
Tabelas criadas/tocadas nesta etapa (PostgreSQL 16, dinheiro em BIGINT centavos):

| Tabela | Campos-chave | Notas |
|---|---|---|
| `categories` | `user_id`, `nome`, `cor`, `icone`, `arquivada`, soft delete | Categoria única por gasto; sem subcategoria. Nome único por usuário entre não excluídas (índice parcial `WHERE deleted_at IS NULL`). |
| `category_keywords` | `category_id`, `palavra_chave` | Lookup determinístico; palavra **normalizada**, única por categoria; cascade ao excluir a categoria. |
| `merchant_aliases` | `user_id`, `category_id`, `alias` | Regra fixa por estabelecimento; alias **normalizado**, único por usuário (`unique(user_id, alias)`); criado/atualizado pela correção. |
| `transactions` (FK) | `categoria_id` → `categories` | FK adicionada com `nullOnDelete`: excluir categoria zera o vínculo, preserva o lançamento. |
| `incomes` | `user_id`, `descricao`, `valor_cents`, `data`, `tipo` (fixa/variavel), soft delete | Base do "disponível". `CHECK` de tipo e `valor_cents >= 0`; índice `(user_id, data)`. |
| `budgets` | `user_id`, `mes` (char 7), `limite_cents`, `categoria_id?` | Geral = `categoria_id NULL`. Índices únicos parciais: um geral por `(user, mes)`; um por `(user, mes, categoria)`. `CHECK limite_cents >= 0`. Coluna por categoria já existe (pós-MVP). |

Reusa do Bloco 1: `transactions`, `installments`, `status_pagamento`, `audit_log`.

## 6. Contratos do domínio
Assinaturas públicas reais (cálculo determinístico; sem IA):

**Gastos — `App\Domain\Gasto`**
- `RegistrarGastoManual::preview(DadosGastoManual $dados, ?CarbonImmutable $hoje = null): PreviaGastoManual` — calcula sem gravar (regra 7); marca duplicidade.
- `RegistrarGastoManual::confirmar(DadosGastoManual $dados, ?CarbonImmutable $hoje = null): Transaction` — persiste atômico (transaction + installments + auditoria), origem `manual`.
- `EditarGastoManual::preview(int $transactionId, DadosGastoManual $novos, ?CarbonImmutable $hoje = null): PreviaGastoManual` — valida posse, mostra regeneração.
- `EditarGastoManual::confirmar(int $transactionId, DadosGastoManual $novos, ?CarbonImmutable $hoje = null): Transaction` — **bloqueia** se houver parcela `pago`/`pago_parcial` (`EdicaoBloqueadaException::parcelaPaga()`); regenera parcelas e audita antes/depois.
- `CancelarGastoManual::confirmar(int $transactionId, int $userId): Transaction` — status `cancelado`; preserva `pago`/`pago_parcial`/`estornado`; mantém a linha.
- `ExcluirGastoManual::confirmar(int $transactionId, int $userId): void` — soft delete (LGPD) + auditoria.
- `MontadorDeParcelas::montar(DadosGastoManual $dados, CarbonImmutable $hoje): array<int, ParcelaPrevia>` — resolve 1º vencimento (cartão usa ciclo da fatura; fora de cartão = data da compra) e deriva status.
- `StatusDaParcela::para(CarbonImmutable $vencimento, CarbonImmutable $hoje): string` — futuro `agendado` · hoje `aberto` · passado `vencido`.
- DTO/VO: `DadosGastoManual` (imutável; `cardId` presente ⇒ cartão), `PreviaGastoManual`, `ParcelaPrevia`.

**Categoria — `App\Domain\Categoria`**
- `LookupDeCategoria::para(int $userId, string $descricao): ?int` — carrega regras do usuário (categorias não arquivadas) e delega ao classificador.
- `ClassificadorDeCategoria::classificar(string $descricao, RegrasDeCategoria $regras): ?int` — puro; precedência alias > palavra-chave; empate → regra mais longa; texto normalizado.
- `AprendizadoPorCorrecao::corrigirEstabelecimento(int $userId, string $descricao, int $categoriaId): MerchantAlias` — `updateOrCreate` do alias normalizado.
- VO: `RegrasDeCategoria` (aliases + keywords).

**Receitas / Orçamento — `App\Domain\Receita`, `App\Domain\Orcamento`, `App\Domain\Shared`**
- `ReceitasDoMes::para(int $userId, string $mes): int` — soma (centavos) das receitas recebidas no mês civil.
- `ConsumoDoMes::para(int $userId, string $mes): ConsumoMensal` — total + por categoria; base = parcelas vencendo no mês; exclui `pendente_revisao`/`cancelado`/`estornado`; valor derivado.
- `ConsumoMensal` (VO: `totalCents`, `porCategoria`, `semCategoriaCents()`; balde `SEM_CATEGORIA = 0`).
- `Orcamento::avaliar(int $limiteCents, int $consumidoCents): ResultadoOrcamento` — puro; estoura só se consumo **>** limite.
- `OrcamentoMensal::para(int $userId, string $mes): ResultadoOrcamento` — limite geral (categoria NULL) vs consumo do mês.
- `ResultadoOrcamento` (VO: `limite`, `consumido`, `restante` em `Money`, `estourou`, `percentual()` razão — sem disparo).
- `PeriodoMensal::fromString(string $mes): self` — janela [início, fim] do mês civil em America/Sao_Paulo; `contem()`.

## 7. Plano de testes (test-first — falharam antes, passam agora)
1. **Unitários do domínio** — `ClassificadorDeCategoriaTest` (precedência/empate/normalização),
   `OrcamentoTest` (estouro, limite igual não estoura), `PeriodoMensalTest` (janela e validação).
2. **Contrato/integração (borda: banco)** — `RegistrarGastoManualTest` (preview não grava;
   parcelado; duplicidade; origem/auditoria), `EditarGastoManualTest` (regenera; **bloqueia
   parcela paga**), `CancelarGastoManualTest` (preserva pagas; mantém linha),
   `ExcluirGastoManualTest` (soft delete), `LookupDeCategoriaTest` (escopo por usuário),
   `AprendizadoPorCorrecaoTest` (correção vira alias), `ConsumoDoMesTest` (total + por
   categoria; status excluídos), `ReceitasDoMesTest`, `OrcamentoMensalTest`,
   `CategoryTest`/`CategoryKeywordTest` (constraints/índices).

> Cada item de backend só é "feito" com **testes verdes e cobertura**. Sem IA nesta etapa,
> portanto sem fakes da SDK aqui.

## 8. Backend agora · Frontend depois
| Backend (esta etapa) ✅ | Frontend (etapa separada e posterior) |
|---|---|
| CRUD de gasto (preview/confirmar/editar/cancelar/excluir) | Telas web de lançamentos e formulário de gasto |
| Lookup de categoria + aprendizado por correção | Telas de categorias (cor/ícone/arquivar) e UI de correção |
| Receitas, consumo do mês e orçamento geral | Telas de receitas e de orçamento; visualização do consumo por categoria |
| VOs/DTOs e auditoria | Mensagens formatadas do bot (confirmação/edição) |

## 9. Definition of Done
- [x] Cenários de §3 cobertos por testes que falhavam antes e agora passam.
- [x] Barreiras de §4 garantidas (preview sem gravar; bloqueio de edição com parcela paga;
      soft delete; escopo por usuário; centavos).
- [x] Sem segredo/PDF/dado sensível persistido ou commitado (auditoria sem dado sensível).
- [x] Commits locais atômicos, em português, separando backend de frontend.
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ Concluído (backend). Frontend pendente como etapa separada.
- **Entregue:**
  - Gastos: `app/Domain/Gasto/` — `RegistrarGastoManual.php`, `EditarGastoManual.php`,
    `CancelarGastoManual.php`, `ExcluirGastoManual.php`, `MontadorDeParcelas.php`,
    `StatusDaParcela.php`, `DadosGastoManual.php`, `PreviaGastoManual.php`,
    `ParcelaPrevia.php`, `EdicaoBloqueadaException.php`.
  - Categoria: `app/Domain/Categoria/` — `LookupDeCategoria.php`,
    `ClassificadorDeCategoria.php`, `AprendizadoPorCorrecao.php`, `RegrasDeCategoria.php`.
  - Receita/Orçamento: `app/Domain/Receita/ReceitasDoMes.php`,
    `app/Domain/Orcamento/` (`ConsumoDoMes.php`, `ConsumoMensal.php`, `Orcamento.php`,
    `OrcamentoMensal.php`, `ResultadoOrcamento.php`), `app/Domain/Shared/PeriodoMensal.php`.
  - Migrations: `2026_06_26_000001_create_categories_table.php`,
    `..._000002_create_category_keywords_table.php`,
    `..._000003_create_merchant_aliases_table.php`,
    `..._000004_add_categoria_fk_to_transactions.php`,
    `..._000005_create_incomes_table.php`, `..._000006_create_budgets_table.php`.
  - Testes: `tests/Unit/Domain/` (`ClassificadorDeCategoriaTest`, `OrcamentoTest`,
    `PeriodoMensalTest`) e `tests/Feature/Domain/` (`RegistrarGastoManualTest`,
    `EditarGastoManualTest`, `CancelarGastoManualTest`, `ExcluirGastoManualTest`,
    `LookupDeCategoriaTest`, `AprendizadoPorCorrecaoTest`, `ConsumoDoMesTest`,
    `ReceitasDoMesTest`, `OrcamentoMensalTest`, `CategoryTest`, `CategoryKeywordTest`).
  - Execução: `make test` (`docker compose exec app php artisan test`).
- **Adiado para:** frontend (telas/bot) — etapa separada (regra 3); "disponível do mês"
  consolidado → [[spec-06-dashboard]]; orçamento por categoria e disparo/limiar de alerta
  → pós-MVP.
- **Decisões de regra tomadas:**
  - **Alerta por categoria:** entregue só o **consumo por categoria**; disparo/limiar fica
    pós-MVP (doc 04/08).
  - **Edição com parcela paga:** **bloqueada** (regenerar perderia histórico de pagamento).
  - **Cancelar vs excluir:** cancelar mantém a linha e preserva parcelas finalizadas;
    excluir é **soft delete** (LGPD), preservando auditoria.
  - **Status inicial da parcela** derivado pela data: futuro `agendado`, hoje `aberto`,
    vencido `vencido`; o cadastro nunca marca `pago`.
  - **Orçamento:** igualar o limite **não** estoura (estoura só se consumo > limite).
</content>
</invoke>
