# Spec FE — Frontend (Stitch): todas as telas do sistema

> **Como usar este spec.** Diferente das demais specs (backend, test-first), esta é a
> **fase de apresentação** (regra inviolável 3): consolida **todo o frontend** adiado das
> etapas 00–06 (as telas de importação de PDF são pós-MVP — [[spec-11-importacao-pdf]]).
> O fluxo aqui é **design-first com o Stitch**: parte-se do **design system**
> (§4), gera-se **uma tela por prompt** (§7) e só depois vem a **ligação técnica** ao
> Laravel (Inertia/Blade) e a redação das **mensagens do bot** (§8), em código.
>
> Spec "vivo": ao gerar/aprovar cada tela, marque o item no **mini-TODO (§6)** e registre
> em **§10** o artefato real (arquivo exportado / componente).

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Fase **FE** (frontend consolidado) |
| **Status** | ⬜ Planejado |
| **Depende de** | [[spec-02-cadastro-manual-receitas]] · [[spec-03-telegram]] · [[spec-04-ia-interpretacao]] · [[spec-05-chat-financeiro]] · [[spec-06-dashboard]] |
| **Habilita** | MVP apresentável (web + bot) |
| **Ordem** | Após o backend das features (00–06). As telas de importação de PDF **não** são do MVP: acompanham a [[spec-11-importacao-pdf]] (pós-MVP), geradas após o backend dela. |
| **Fonte de verdade** | [`docs/05-arquitetura.md`](../05-arquitetura.md) · [`docs/06-telegram.md`](../06-telegram.md) · [`docs/08-categorias.md`](../08-categorias.md) · [`docs/09-nfr-seguranca-lgpd.md`](../09-nfr-seguranca-lgpd.md) |
| **Regras críticas** | 3 (frontend separado) · 4 (a IA nunca calcula — UI **nunca** recalcula dinheiro no cliente) · 5 (centavos no backend; pt-BR só na borda) · 7 (confirmar antes de gravar) · 6 (nada sensível persistido) |

---

## 1. Objetivo
Dar ao usuário uma leitura **calma, clara e confiável** das suas contas — na web e no bot —
gerando todas as telas com o **Stitch** a partir de um **design system único**, sem que a
camada visual recalcule dinheiro nem invente número (todo valor vem do backend, regra 4).

## 2. Escopo
- **Inclui:**
  - O **design system** (§4): paleta, tipografia, espaçamento, componentes, voz e a tela-assinatura.
  - **Um prompt de Stitch por tela** (§7), pronto para colar, derivado do design system.
  - O **mini-TODO** (§6) com todas as telas a gerar e seu estado.
  - As **mensagens do bot** (§8) — texto curto, sem botões salvo confirmação — como *copy* de referência.
- **Não inclui (etapas posteriores / outras specs):**
  - **Ligação técnica** das telas geradas ao Laravel (rotas, Inertia/Blade, chamadas à API,
    validação server-side). É tarefa de implementação **após** aprovar o desenho de cada tela.
  - Qualquer **regra de negócio ou cálculo** (já vive no backend — specs 01–06).
  - Telas de **importação de PDF** ficam **fora do MVP**: acompanham a [[spec-11-importacao-pdf]]
    (pós-MVP, 1ª etapa), geradas após o backend dela. Constam no mini-TODO marcadas como pós-MVP.

## 3. Princípios e invariantes de apresentação
Valem para **toda** tela e todo prompt (são as "barreiras" desta fase):

1. **Dinheiro é leitura, nunca cálculo no cliente.** A UI **exibe** valores já calculados
   (centavos → pt-BR na borda) e **captura** entradas; **nunca** soma, parcela ou projeta
   no front (regra 4/5). Totais, parcelas e "disponível" chegam prontos do backend.
2. **Confirmar antes de gravar (regra 7).** Todo registro/edição/cancelamento passa por uma
   confirmação explícita com **prévia** do que será gravado. Auto-save é proibido no MVP.
3. **Transparência de IA (barreira 5).** Toda resposta gerada por IA exibe a **fonte**
   (período, filtros, nº de registros consultados) e o selo **"número conferido"**; um aviso
   discreto lembra que a IA **não inventa valores**. Em degradação, mostra-se o fallback
   **sem números** com um aviso de instabilidade.
4. **Escopo estrito por usuário.** Nenhuma tela mostra dado de terceiros; "não encontrado"
   nunca revela existência de dados de outra conta.
5. **Nada sensível em tela persistida (regra 6).** PDF/texto extraído é efêmero; a revisão
   de importação trabalha sobre dados em memória da sessão, descartados ao final.
6. **pt-BR e fuso America/São Paulo na borda.** `R$ 1.234,56`, `10 de junho`, `3x`,
   datas e percentuais sempre formatados na exibição.
7. **Piso de qualidade.** Responsivo até **360px**, **foco de teclado visível**, contraste
   **WCAG AA**, alvos de toque ≥ 44px, `prefers-reduced-motion` respeitado.

## 4. Design System (a base de todos os prompts)
> Direção deliberada — **calma, clareza e confiança**, fugindo dos clichês de fintech (azul
> corporativo) e dos defaults de IA (cream+serif / preto+verde-ácido / jornal). Mobile-first.

### 4.1 Conceito
**"Caderno de contas"** — a precisão de um extrato, com a calma de um caderno. O dinheiro é
tratado como **dado tabular** (monoespaçado, alinhado por coluna, como num extrato bancário),
o resto da interface é arejado e humano.

### 4.2 Paleta (modo dia)
| Token | Hex | Uso |
|---|---|---|
| `tinta` | `#1C1B17` | texto principal (quase-preto **quente**) |
| `papel` | `#EDF0E8` | fundo da aplicação (névoa pálida verde-acinzentada) |
| `superficie` | `#FBFBF8` | cartões e campos |
| `cedula` | `#1F6E5A` | primária — saldo positivo, ações, marca (verde-cédula) |
| `cedula-clara`| `#2E8B72` | hover/ativo da primária |
| `ocre` | `#C9852A` | atenção / **a vencer** |
| `argila` | `#B4452F` | negativo / **em atraso** (uso parco) |
| `linha` | `#DDE0D7` | hairlines, bordas, divisores |
| `nevoa` | `#6B6F66` | texto secundário, labels |

**Modo noite (opcional):** `tinta`↔`papel` invertem para `#14150F` (fundo) / `#ECEEE6`
(texto); `cedula` clareia para `#3FA589`. Manter contraste AA.

### 4.3 Tipografia
- **Display (títulos):** *Bricolage Grotesque* — característica, usada com moderação.
- **Corpo (UI/texto):** *IBM Plex Sans*.
- **Dados/dinheiro (mono):** *IBM Plex Mono* — **assinatura tipográfica**: **todo** valor em
  R$, data, %, contagem de parcelas é **monoespaçado e alinhado por coluna**, como num extrato.
- Escala (rem): 2.5 / 2 / 1.5 / 1.25 / 1 / 0.875 / 0.75. Sentence case sempre.

### 4.4 Forma, espaço e movimento
- **Raio:** 12px (cartões), 8px (inputs/botões), pill (chips/selos).
- **Espaço:** base 4px; respiros generosos; densidade só nas listas/extratos.
- **Sombra:** difusa e discreta (calma); nunca dura.
- **Motion:** comedido — entrada dos cards `fade+rise` ~150ms, micro-hover; respeitar
  `prefers-reduced-motion`. Excesso de animação **não**.

### 4.5 Tela-assinatura: **"A régua do mês"**
Uma **régua horizontal do mês** (dia 1 → último dia) com: marcador do **hoje**, **ticks** de
vencimentos (em `ocre`), e uma faixa sutil do **disponível** decrescendo ao longo do mês.
Vive no **Dashboard** e ecoa como uma **fina espinha de progresso** no topo das telas do mês.
É o elemento memorável — tudo ao redor permanece quieto.

### 4.6 Componentes recorrentes
Card de resumo · chip de **categoria** (cor + ícone) · selo de **status** (aberto/pago/atraso/
cancelado) · chip de **fonte** da IA · linha de **lançamento** (descrição · categoria · valor
mono · data · status) · barra de **orçamento** (consumo/limite) · **prévia de parcelas**
(tabela mono) · banner de **transparência de IA** · estados **vazio/carregando/erro** com texto-guia.

### 4.7 Voz (copy)
pt-BR, sentence case, verbos diretos, sem jargão técnico. Exemplos canônicos:
"Disponível do mês", "A vencer", "Confirmar gasto", "Conferido". Erros **diretos e úteis**
(o que houve + como resolver), na voz da interface, sem pedir desculpas vagas.

## 5. Como gerar no Stitch
1. **Crie um projeto** no Stitch e cole **uma vez** o **Prompt-base de tema** (§7.0) para fixar
   paleta, tipografia e tom — ele orienta todas as telas seguintes.
2. Para cada tela do mini-TODO, cole o **prompt da tela** (§7.x). Cada prompt é autocontido e
   **reafirma o tema** (caso o Stitch perca contexto entre gerações).
3. **Itere** com ajustes curtos ("aumente o respiro", "alinhe os valores à direita em mono").
4. **Exporte** o design/código e registre em §10. A **ligação ao Laravel** (Inertia/Blade,
   dados reais, validação) é a etapa técnica seguinte — fora do Stitch.

> **Shell padrão (aside + header + coluna de chat) — já criado.** Todas as telas **logadas**
> compartilham o mesmo **shell**. No desktop ele tem **três zonas**: (1) uma **barra lateral
> (aside)** de navegação à esquerda, (2) o **conteúdo da tela** no centro e (3) uma **coluna de
> chat fixa, sempre aberta**, à direita (o Chat financeiro, §7.14). Há ainda o **cabeçalho
> (header)** no topo. Aside e header já foram **gerados no Stitch** e viram um **layout Blade**
> reutilizado; a coluna de chat entra como a **terceira zona** do mesmo shell. Por isso, os
> prompts das telas logadas (§7.5–§7.17) pedem **apenas o conteúdo da área principal** — o Stitch
> **não** deve redesenhar aside, header nem a coluna de chat nelas. **No mobile** (não cabem três
> colunas) a coluna de chat **recolhe** para um lançador e abre como folha (regra do layout, ver
> §7.14). As telas **pré-login** (§7.1–§7.4) não têm shell (sem navegação do app).

## 6. Mini-TODO — telas a gerar
> Marque conforme gerar/aprovar. As telas de importação de PDF (15/16) são **pós-MVP** e dependem
> do backend da [[spec-11-importacao-pdf]] — não bloqueiam a entrega do frontend do MVP.

**Tema**
- [x] 0. Prompt-base de tema aplicado no projeto Stitch

**A. Entrada & onboarding**
- [x] 1. Login
- [x] 2. Criar conta
- [x] 3. Onboarding + consentimento LGPD
- [x] 4. Vínculo do Telegram

**B. Núcleo financeiro**
- [x] 5. Dashboard / Visão geral *(tela-assinatura: a régua do mês; shell aside + header — **implementado em Blade e ligado ao backend (spec-06)**: valores reais do domínio, estado vazio/pronto pelos dados, coberto por testes de feature)*
- [x] 6. Lançamentos — lista *(extrato agrupado por dia; shell aside + header — **gerada no Stitch** ("Lançamentos - Lista Principal/Estado Vazio/Filtro sem resultado/Carregando") e **implementada em Blade e ligada ao backend**: nova consulta de domínio determinística `App\Domain\Lancamentos\ConsultarLancamentos` (uma linha por PARCELA vencendo no mês — mesma base do dashboard/`ConsultarGastos`; status de exibição derivado por data, spec 06b; "total exibido" já somado; filtros busca/categoria/forma/cartão/status server-side; escopo por usuário) → `LancamentoController` (rota `/lancamentos`, nav "Transações"). Estados vazio/sem-resultado/carregando tratados; seletor de mês. Coberto por testes de domínio + borda web. **Deferido:** ações de linha (editar/cancelar/excluir) e "abrir detalhe" seguem com §7.7/§7.8; o rodapé "total exibido" é sticky no conteúdo (não fixo na viewport) por causa da 3ª coluna de chat do shell.)*
- [x] 7. Lançamento — criar/editar (com prévia de parcelas) *(**mesmo formulário do modal 7b como PÁGINA cheia** — não gerou tela nova no Stitch. Extraído para o componente compartilhado `<x-gasto.form>`: o modal (`components/modal/registrar-gasto.blade.php`) virou wrapper fino e a página usa o MESMO componente e JS (dirigido por `[data-rg-root]`, suporta redirect e `PUT` via spoof). Fluxo em dois passos idêntico (prévia calculada pelo backend → confirmar, regra 7). **Criar** reusa `gastos.previa`/`gastos.store`; **editar** tem os seus (`lancamentos.previa` + `PUT lancamentos.update` via `EditarGastoManual`, preservando a data de compra no crédito), preenchido a partir do lançamento, com **bloqueio quando há parcela paga** (aviso + submit desabilitado — spec §7.8). Rotas `/lancamentos/novo` e `/lancamentos/{id}/editar`; a lista linka cada linha para editar e tem botão "Novo lançamento". Coberto por testes de borda web (`FormularioLancamentoWebTest`). **Deferido:** marcar como pago e recorrência (backend pós-MVP); tela de **detalhe** §7.8 (hoje a linha do extrato vai direto para a edição).)*
- [x] 7b. Registrar gasto — **modal rápido** (FAB do dashboard) *(valor, forma, cartão, vencimento, pagamento opcional, recorrente, categoria)* — **gerado no Stitch**, **implementado em Blade** e **ligado ao backend** (persiste de verdade): fluxo em dois passos (formulário → prévia calculada pelo backend → confirmar → grava, regra 7), cartões/categorias reais do usuário, validação server-side (Form Request), reuso de `RegistrarGastoManual`. Coberto por testes de borda web. **Deferido:** marcar como pago (data de pagamento) e recorrência (backend pós-MVP).
- [x] 8. Lançamento — detalhe (parcelas + status) *(**gerada no Stitch** e **implementada em Blade e ligada ao backend**: nova consulta de domínio determinística `App\Domain\Lancamentos\ConsultarLancamentoDetalhe` (+ DTO `DetalheDoLancamento`) — UMA transação do usuário com metadados e status POR PARCELA derivado por DATA reusando `App\Domain\Gasto\StatusDaParcela` (futuro `agendado` · hoje `aberto` · passado `vencido`; pago/pago_parcial → pago; cancelado/estornado → cancelado), valor por parcela derivado (`Money::allocate`), status geral do cabeçalho por precedência (vencido›aberto›agendado›pago) e `temParcelaPaga`; escopo estrito por usuário (404 p/ transação alheia), "hoje" injetado → `LancamentoController::show` (rota `GET /lancamentos/{transaction}` `lancamentos.show`, borda fina que formata pt-BR). Tela `lancamentos/detalhe.blade.php` (cabeçalho, metadados, tabela de parcelas, ações); `status-badge` estendido (aberto/agendado/vencido). **Edição por MODAL na própria tela** reusando o form compartilhado `<x-gasto.form mode=edit>` (`components/modal/editar-lancamento.blade.php`, mesmos hooks do `registrar-gasto.js`): abre pelo botão "Editar" e **auto-abre com `?editar=1`**; **bloqueada quando há parcela paga** (aviso + Editar desabilitado, regra 7). Helpers de edição extraídos p/ o trait `Concerns\PreparaEdicaoDeGasto` (compartilhado com a página §7.7). A **lista** agora abre o detalhe ao clicar na linha e o botão "Editar" leva ao detalhe já aberto (`show?editar=1`). Coberto por testes de domínio (`ConsultarLancamentoDetalheTest`) + borda web (`LancamentoDetalheWebTest`). **"Cancelar (esta e as próximas)" ligado:** o botão antes "em breve" virou ação real — borda `POST /lancamentos/{transaction}/cancelar` (`LancamentoController@cancelar`, token opaco, escopo por usuário) delegando ao domínio já testado `CancelarGastoManual` (marca a transaction + parcelas ainda não finalizadas como `cancelado`, **preserva as pagas/parciais/estornadas**, mantém a linha/histórico, grava auditoria). Confirmação **antes de gravar** por `<details>` puro (sem JS, mesmo padrão do "Marcar pago") com **prévia** das parcelas que serão canceladas (regra 7); rótulo honesto "Cancelar restantes"; oferecido inclusive quando a edição está bloqueada por parcela paga, e inerte quando nada resta cancelável. Testes: borda (`CancelarLancamentoWebTest`) + asserções de tela no `LancamentoDetalheWebTest`.)*
- [x] 9. Confirmações pendentes *(espelho web do "Confirma?" — regra 7; **gerada no Stitch** e **implementada em Blade e ligada ao backend**). **Base nova e aditiva** (o `telegram_pending_confirmations` conversacional fica intacto): tabela `pending_confirmations` — FILA de N itens por usuário, revisáveis 1 a 1 — que **recorrência** ([[spec]] §7) e **importação de PDF** ([[spec-11-importacao-pdf]]) vão alimentar (nada nasce gravado sem o "sim", regra 7 / sem auto-save). Domínio determinístico `App\Domain\Confirmacao\` — `EnfileirarConfirmacao` (produtores), `ConsultarPendentes` (lista pendentes não-expirados por usuário), `ConfirmarPendente` (reusa `RegistrarGastoManual`, marca confirmado + linka `transaction_id` + auditoria, **idempotente**, guard de expiração/escopo), `RejeitarPendente` (descarta sem gravar), `PayloadDoGasto` (DadosGastoManual ↔ payload jsonb, centavos). Borda `ConfirmacaoPendenteController` (rotas `/confirmacoes`, `…/confirmar`, `…/rejeitar`; `{pendente}` opaco; 404 alheio) + item de nav "Confirmações". Tela `confirmacoes.blade.php`: cards de prévia (descrição, valor mono, forma·vencimento·Nx, selo de origem), **Confirmar** (grava) + **Descartar** (rejeita), estado vazio e "nada é gravado até confirmar". Cobertura: `ConfirmacaoPendenteTest` (domínio, 9) + `ConfirmacoesWebTest` (ações, 8) + `ConfirmacoesTelaTest` (tela, 4). **Escopo:** a fila só guarda itens **confirmáveis** — o card de "esclarecimento da IA" (§7.9) é do fluxo conversacional, não desta fila. **Deferido:** "Ajustar" (editar o pendente antes de gravar) e a prévia por parcela (tabela datada) — plumbing de edição/geração; badge de contagem na nav; origem `recorrencia` no constraint de `transactions.origem`.)* **Recorrência concluída (backend + frontend)** ([[spec-10-recorrencia-mensal]]): backend (produtor + comando agendado + cascata rejeitar→cancela + CHECK ampliado) e o **switch "Repete todo mês?" + Periodicidade + Dia** (§7.7/§7.7b) já ligados — o `Confirmar` lança o gasto do mês E cria a recorrência a partir do mês seguinte (sem duplicar), com nota na confirmação ("começa em <mês>"). O **selo de origem "recorrência"** na fila §7.9 já está ligado (ícone + tom cédula) e a **tela de gerenciar recorrências** (`/recorrencias` — listar + cancelar via `<details>` sem JS, com item de nav próprio) está no ar. Recorrência **completa** (backend + frontend).
- [x] 10. Receitas *(**gerada no Stitch** e **implementada em Blade + ligada ao backend**: domínio de leitura `App\Domain\Receita\ListarReceitas` (por mês + filtro de tipo) e de escrita `RegistrarReceita` (+ `DadosReceita`, audit) reusando a soma já pronta (`ReceitasDoMes`) → `ReceitaController` (rotas `receitas`/`receitas.store`, item de nav "Receitas") formata pt-BR (regra 4/5). Tela `receitas.blade.php`: card de resumo (total do mês), seletor de competência, filtro segmentado Todas/Fixa/Variável, lista-extrato (descrição·chip tipo·data·valor), estado vazio, e **"Adicionar receita" em DOIS PASSOS** (regra 7): "Revisar e confirmar" mostra o resumo sem gravar → "Confirmar" grava (server-rendered, sem JS). **Editar e excluir** também ligados: `Income` ganhou `HasOpaqueRouteId`; `EditarReceita` (audit) e `ExcluirReceita` (**cancelamento lógico** — soft delete, histórico preservado, audit); rotas `PUT`/`DELETE /receitas/{receita}` (opaco), `EditarReceitaRequest` (error bag `editarReceita` p/ reabrir a linha certa); na lista, cada receita tem "Editar receita" (form prefilled, sem 2º passo — a revisão é inerente) e "Excluir receita" (confirmação via `<details>`). Cobertura: `ReceitaCrudTest` (4) + `EditarExcluirReceitaTest` (4) + `ReceitaWebTest` (14).)*
- [x] 11. Orçamento do mês *(**gerada no Stitch** e **implementada em Blade + ligada ao backend**: novo domínio de escrita `App\Domain\Orcamento\DefinirOrcamento` (updateOrCreate do limite GERAL por (usuário, mês), audit) reusando a leitura já pronta (`OrcamentoMensal`/`ConsumoDoMes`) → `OrcamentoController` (rotas `orcamento`/`orcamento.definir`, item de nav "Orçamento") formata pt-BR (regra 4/5). Tela `orcamento.blade.php`: card geral (limite/consumido/barra/%/resta), estouro em `error`, estado sem-orçamento com "Definir limite do mês", "por categoria" só consumo + "sem limite" (limite por categoria é pós-MVP); seletor de mês (?mes=YYYY-MM). Definir é ação explícita (form direto, validação server-side — nada calculado a revisar). Cobertura: `DefinirOrcamentoTest` (domínio, 4) + `OrcamentoWebTest` (borda/tela, 8).)*
- [x] 12. Categorias *(**gerada no Stitch** e **implementada em Blade + ligada ao backend**: domínio de leitura `App\Domain\Categoria\ListarCategorias` (categorias ativas + **contagem de uso já calculada** por query agregada, escopo estrito por usuário — a UI nunca calcula, regra 4) e de escrita `CriarCategoria`/`EditarCategoria`/`ArquivarCategoria` (+ `DadosCategoria`, audit em toda escrita) reusando `App\Domain\Shared\Normalizador`; as **palavras-chave** (escopo categoria) e os **apelidos de estabelecimento** (escopo usuário, `updateOrCreate` re-aponta o alias) alimentam o lookup determinístico (doc 08 §1/§2) e são gravados **normalizados e deduplicados** (trait `Concerns\SincronizaRegras`), coerentes com o `AprendizadoPorCorrecao` do bot. Cor e ícone saem de uma **paleta fixa** (`PaletaDeCategoria::CORES`/`ICONES` — nada de hex solto/ícone órfão; mapeia ícones legados p/ o x-icon com fallback "tag") → `CategoriaController` (rotas `categorias`/`categorias.store`/`PUT categorias/{categoria}`/`POST …/arquivar`, `{categoria}` opaco, item de nav "Categorias") formata só na borda. Tela `categorias.blade.php` + componente `<x-categoria.form>` (criar/editar): grade de cards (chip cor+ícone de linha, nome, contagem em mono, "Editar"), form de criação em `<details>` "Nova categoria", edição inline por card, seletores de cor/ícone por **radios nativos** (seleção única, teclado, sem JS), campos de palavras-chave/apelidos (texto separado por vírgula), **Arquivar** (lógico — não apaga histórico nem regras, sai da grade e do lookup), estado vazio. `CriarCategoriaRequest` (nome único por usuário entre não-excluídas, cor/ícone na paleta) + `EditarCategoriaRequest` (unicidade ignora a própria, error bag `editarCategoria` p/ reabrir o card certo via `_categoria` no old input). Cobertura: `CategoriaCrudTest` (domínio, 6) + `CategoriaWebTest` (borda/tela, 13).)*
- [x] 13. Cartões & faturas *(**gerada no Stitch** e **implementada em Blade + ligada ao backend**: domínio de escrita `App\Domain\Cartao\CriarCartao` (+ `DadosCartao`, audit) e de leitura `ListarCartoes`; **`App\Domain\FaturaCartao\CicloDaFatura`** deriva fecha/vence/aberta consistente com o §4.2 (competência = mês de vencimento; só apresentação, NÃO materializa fatura — spec 09 segue pendente); `ConsultarFaturaCartao` ganhou `paraCartao($card)` (por id, sem string ambígua) + `categoria_id` nos itens → `CartaoController` (rotas `cartoes`/`cartoes.store`, item de nav "Cartões") formata pt-BR (regra 4/5). Tela `cartoes.blade.php`: faixa de cartões selecionáveis (?cartao= opaco), fatura do selecionado (total + extrato com fração de parcela + chip de categoria), datas do ciclo + selo aberta(ocre)/fechada, seletor de mês, form "Adicionar cartão" (validação server-side, unicidade), estado sem-cartão. **Editar e remover** também ligados: `EditarCartao` (atualiza campos + limite, audit) e `ExcluirCartao` (**cancelamento lógico** — soft delete, histórico/lançamentos preservados, audit); rotas `PUT`/`DELETE /cartoes/{cartao}` (opaco), `EditarCartaoRequest` (unicidade ignora o próprio, error bag `editarCartao` p/ reabrir o form certo); na tela, no cartão selecionado, ações "Editar cartão" (form prefilled com limite) e "Remover cartão" (confirmação via `<details>`). Cobertura: `CicloDaFaturaTest` (4) + `CriarCartaoTest` (2) + `EditarExcluirCartaoTest` (4) + `CartaoWebTest` (12).)*

**C. IA**
- [x] 14. Chat financeiro — **coluna fixa (3ª coluna do shell, sempre aberta)** *(histórico no topo · entrada de texto · anexo somente-PDF · fonte/trace + estados; NÃO é overlay — o body fica entre a nav e o chat; recolhe no mobile)* — **gerado no Stitch** ("Chat financeiro — coluna fixa") e **implementado em Blade** como a 3ª zona do `x-layouts.app`: componente `x-chat.panel` (cabeçalho, banner de transparência, histórico rolável, entrada com anexo somente-PDF). Sempre aberta a partir de `lg` (conteúdo com `lg:pr-[380px]`); abaixo de `lg` recolhe para folha, aberta pelo lançador do header. JS mínimo (folha, validação de PDF, campo que cresce). Estado vazio + estados de interação (pensando · instável · anexado · inválido) conduzidos pelo `chat.js`. **Ligado ao backend (real), com paridade do Telegram:** a mensagem passa pelo **mesmo motor do bot** — orquestração compartilhada `ProcessarInteracao` (confirmação pendente → classificação de intenção → **registrar** ou **consultar**) via `POST /chat/mensagens`. **Registrar** um gasto por linguagem natural funciona no chat, com **confirmação "sim/não" antes de gravar** (regra 7), estado pendente persistido entre requisições; **consultar** aplica guard barreira 4 + fontes barreira 5. Histórico **real** e isolado por usuário em `chat_messages` (retenção 60 dias no expurgo `ai:expurgar-conversas`), injetado pelo view composer; redação por `RedatorDoChat`. Anexo **PDF-only validado por MIME real** no servidor (`seguranca-ia`) e **efêmero** (nunca persistido — regra 6/`lgpd`); extração de fatura ([[spec-11-importacao-pdf]]) segue pós-MVP (PDF válido recebe aviso honesto). **Deferido:** memória de conversa livre (contexto multi-turno) e importação de fatura pelo chat.

**D. Importação de PDF — pós-MVP (gerar após o backend da [[spec-11-importacao-pdf]])**
- [ ] 15. Importar fatura (upload) — _pós-MVP_
- [ ] 16. Revisão da importação (lote + duplicados + confirmar) — _pós-MVP_

**E. Conta**
- [x] 17. Configurações & privacidade (perfil, fuso, vínculo, transparência de IA, exclusão LGPD) *(**gerada no Stitch** e **implementada em Blade + ligada ao backend**: novo domínio `App\Domain\Conta\` — `AtualizarPerfil` (nome/e-mail/fuso, audita sem senha), `AlterarSenha` (cast `hashed`, audita só o FATO), `ExportarDadosDoUsuario` (portabilidade LGPD art. 18 — JSON estruturado só do `user_id`: cadastro + lançamentos/receitas/cartões/categorias/orçamentos/recorrências + telegram mascarado; nunca senha nem sensível/efêmero, regra 6; audita `exportar`) e `ExcluirConta` (**soft delete** do usuário — decisão do usuário: dados intactos porém inacessíveis; `audit_log` preservado sem PII; o guard de SoftDeletes bloqueia novo login) → `ConfiguracoesController` (rotas `configuracoes`, `.perfil` PUT, `.senha` PUT, `.exportar` GET download, `.excluir` DELETE) + Form Requests `Conta\{AtualizarPerfil,AlterarSenha,ExcluirConta}Request` (e-mail único ignora a própria conta; `current_password`; fuso via regra `timezone`; dupla confirmação "EXCLUIR"; error bags `perfil`/`senha`/`excluir` p/ reabrir a seção certa). Tela `configuracoes.blade.php` com as 5 seções (§7.17): Perfil, Preferências (fuso EDITÁVEL + microcopy honesto "os cálculos seguem São Paulo" — motor permanece em SP, regra 5), Telegram (selo + "Gerenciar vínculo" → `/telegram`), IA e transparência, Privacidade (baixar dados + "Excluir minha conta" em argila com dupla confirmação e botão habilitado por JS mínimo só quando digita "EXCLUIR"; guard real server-side). Item de nav "Configurações" ligado (deixou de ser "em breve"). Cobertura: `PerfilESenhaTest` (2) + `ExportarDadosTest` (3) + `ExcluirContaTest` (2) + `ConfiguracoesWebTest` (10).)*

**Mensagens do bot (texto, §8 — não-Stitch)**
- [ ] B1–B11 redigidas e implementadas em código (ver §8)

---

## 7. Prompts de Stitch (um por tela)

### 7.0 Prompt-base de tema (colar uma vez no projeto)
```text
Você é meu designer de UI. Vou gerar várias telas de um app de FINANÇAS PESSOAIS em
português do Brasil, mobile-first (responsivo até 360px) e também usável em desktop.
Sensação-alvo: CALMA, CLAREZA e CONFIANÇA — finanças sem ansiedade. NÃO use azul de fintech
nem visual genérico. Conceito: "caderno de contas" — a precisão de um extrato com a calma
de um caderno.

PALETA:
- Texto/quase-preto quente: #1C1B17
- Fundo (névoa pálida verde-acinzentada): #EDF0E8
- Cartões/superfícies: #FBFBF8
- Primária (verde-cédula): #1F6E5A  | hover: #2E8B72
- Atenção / "a vencer": #C9852A
- Negativo / "em atraso": #B4452F (uso parco)
- Linhas/bordas: #DDE0D7
- Texto secundário/labels: #6B6F66

TIPOGRAFIA:
- Títulos: "Bricolage Grotesque" (característica, com moderação)
- Texto de interface: "IBM Plex Sans"
- VALORES (R$), datas, %, parcelas: "IBM Plex Mono" — SEMPRE monoespaçado e alinhado à
  direita por coluna, como num extrato bancário. Esta é a assinatura visual.
- Tudo em sentence case.

FORMA: cantos 12px (cartões) / 8px (botões e campos) / pill (chips). Respiros generosos,
sombras difusas e suaves (nunca duras). Animação comedida.

REGRAS DE CONTEÚDO:
- Valores sempre em pt-BR: "R$ 1.234,56". Datas: "10 de junho". Parcelas: "3x".
- A interface NUNCA calcula dinheiro; apenas exibe valores prontos e captura entradas.
- Respostas de IA mostram a FONTE (período/filtros) e um selo "número conferido".
- Acessível: contraste AA, foco de teclado visível (anel verde-cédula), alvos ≥ 44px.

ESTADOS PADRÃO (valem para todas as telas): vazio (texto-guia que convida à ação, nunca
um "nada aqui" seco), carregando (esqueleto sóbrio ou rótulo no próprio botão) e erro
(mensagem direta — o que houve + como resolver — na voz da interface, sem desculpas vagas).
TELAS PRÉ-LOGIN (login, criar conta, onboarding, vínculo): sem barra de navegação do app;
um card calmo e centralizado, com bastante respiro.

Use conteúdo de exemplo realista pt-BR (mercado, transporte, fatura do cartão, aluguel).
Confirme que entendeu o tema e aguarde o pedido da primeira tela.
```

### 7.1 Login
**Objetivo:** entrar com calma e confiança. **Estados:** erro de credencial; carregando.
```text
Gere a tela de LOGIN de um app de finanças pessoais em pt-BR. Ignore qualquer versão anterior —
comece do zero. Gere a tela principal + duas variações de estado (no fim).

━━━ CONTEXTO (tela PRÉ-LOGIN) ━━━
Esta é uma tela ANTES do login: NÃO há navegação do app (sem barra lateral, sem header, sem coluna
de chat). É a tela inteira: mobile-first e, no desktop, um card calmo e centrado com bastante respiro.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere navegação do app, barra lateral, header, coluna de chat, menu, logo genérico, avatar,
  hero ilustrado, imagem ou banner.
- NÃO adicione, remova, renomeie nem reordene NENHUM campo/elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, "entrar com Google"/social login nem
  qualquer conteúdo que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA ━━━
- Topo enxuto: a marca em TEXTO "Caderno de contas" (verde-cédula #1F6E5A), com um hairline
  (#DDE0D7) abaixo. Sem logo genérico.
- CARD central (superfície #FBFBF8, cantos 12px, sombra difusa), campos UM POR LINHA:
  - "E-mail" — input de e-mail.
  - "Senha" — input de senha com um botão "mostrar/ocultar".
  - Um link discreto "Esqueci a senha" alinhado à direita.
  - Botão primário "Entrar" (verde-cédula, largura total, alvo ≥ 44px).
- Abaixo do card, um divisor sutil e a linha "Novo por aqui? Criar conta" ("Criar conta" é link verde-cédula).
- Rodapé minúsculo em texto secundário: "Seus números vêm do seu banco de dados — a IA nunca os inventa."

━━━ VARIAÇÃO — ERRO DE CREDENCIAL ━━━
Mensagem inline direta sob os campos, em argila: "E-mail ou senha incorretos."
━━━ VARIAÇÃO — CARREGANDO ━━━
O botão vira "Entrando…" com um spinner sóbrio; os campos ficam desabilitados.

━━━ INVARIANTES ━━━
A calma vem do espaço e da tipografia, não de ilustração. Acessível: contraste AA, foco de teclado
visível (anel verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Login", "Login — erro" e "Login — carregando".
```

### 7.2 Criar conta
**Objetivo:** cadastro mínimo. **Estados:** validação inline; carregando.
```text
Gere a tela CRIAR CONTA de um app de finanças pessoais em pt-BR. Ignore qualquer versão anterior —
comece do zero. Gere a tela principal + duas variações (validação; carregando).

━━━ CONTEXTO (tela PRÉ-LOGIN) ━━━
Esta é uma tela ANTES do login: NÃO há navegação do app (sem barra lateral, sem header, sem coluna
de chat). É a tela inteira: mobile-first, o mesmo card calmo e centrado do login.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere navegação do app, barra lateral, header, coluna de chat, menu, logo genérico, avatar,
  hero ilustrado, imagem ou banner.
- NÃO adicione, remova, renomeie nem reordene NENHUM campo/elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, "criar com Google"/social login, upsell
  nem qualquer conteúdo que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA ━━━
- CARD central (superfície #FBFBF8, cantos 12px), campos UM POR LINHA:
  - "Nome"
  - "E-mail"
  - "Senha" — com um medidor de força simples em 3 níveis, rótulos "fraca" / "ok" / "forte".
  - "Confirmar senha"
- Checkbox "Li e aceito os termos e a política de privacidade" — com "termos" e "política de
  privacidade" como dois links sublinhados.
- Botão primário "Criar conta" (largura total). Abaixo, a linha "Já tenho conta — entrar"
  ("entrar" é link verde-cédula).

━━━ VARIAÇÃO — VALIDAÇÃO INLINE ━━━
Mensagens diretas em argila: "Use um e-mail válido."; "As senhas não conferem."; e, com o
consentimento não marcado, "Aceite os termos para continuar."
━━━ VARIAÇÃO — CARREGANDO ━━━
O botão vira "Criando conta…"; os campos ficam desabilitados.

━━━ INVARIANTES ━━━
Tom acolhedor e breve, sem upsell. Acessível: contraste AA, foco de teclado visível (anel
verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Criar conta", "Criar conta — validação" e "Criar conta — carregando".
```

### 7.3 Onboarding + consentimento LGPD
**Objetivo:** explicar finalidades e obter consentimento (doc 09). **Estados:** —
```text
Gere a tela ONBOARDING / CONSENTIMENTO de um app de finanças pessoais em pt-BR. Ignore qualquer
versão anterior — comece do zero. É UM único passo, honesto e curto — a primeira tela depois de
criar a conta. É SÓ esta tela.

━━━ CONTEXTO (tela PRÉ-LOGIN) ━━━
Esta é uma tela ANTES de entrar no app: NÃO há navegação do app (sem barra lateral, sem header,
sem coluna de chat). É a tela inteira, calma e centrada.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere navegação do app, barra lateral, header, coluna de chat, menu, logo genérico, avatar,
  ilustração grande, imagem ou banner.
- NÃO adicione, remova, renomeie nem reordene NENHUM bloco/elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips nem qualquer conteúdo que não esteja
  escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA ━━━
- Título display "Antes de começar" (Bricolage Grotesque).
- TRÊS blocos curtos, cada um com um ícone de LINHA (não preenchido) e um texto:
  1) "Acompanhe seus gastos" — "registre na web ou direto no Telegram."
  2) "IA que interpreta, não inventa" — "a IA classifica e redige; os números vêm do seu banco
     de dados, calculados pelo sistema."
  3) "Privacidade" — "PDFs de fatura são processados e descartados na hora; conversas ficam por
     até 60 dias; nenhum dado sensível é guardado."
- CAIXA de consentimento destacada (superfície, borda fina #DDE0D7): checkbox "Concordo com o
  tratamento dos meus dados para as finalidades acima" + link "política de privacidade".
- Botão primário "Começar" (DESABILITADO até marcar o consentimento).
- Link discreto ao final: "Você pode excluir seus dados quando quiser."

━━━ INVARIANTES ━━━
Tom direto e tranquilo; sem ilustração grande — os três ícones e o espaço bastam. Acessível:
contraste AA, foco de teclado visível (anel verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue como UMA tela: "Onboarding".
```

### 7.4 Vínculo do Telegram
**Objetivo:** parear a conta ao bot. **Estados:** pendente (token válido); expirado; **vinculado**.
```text
Gere a tela VÍNCULO DO TELEGRAM de um app de finanças pessoais em pt-BR. Ignore qualquer versão
anterior — comece do zero. Aqui a ORDEM dos passos importa de verdade — numere os passos. Gere a
tela principal (estado PENDENTE) + duas variações (VINCULADO; EXPIRADO).

━━━ CONTEXTO (tela PRÉ-LOGIN) ━━━
Esta é uma tela sem navegação do app (sem barra lateral, sem header, sem coluna de chat).
Mobile-first, um card calmo e centrado.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem e o TOKEN: "IBM Plex Mono". Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere navegação do app, barra lateral, header, coluna de chat, menu, logo genérico, avatar,
  ilustração, imagem ou banner.
- NÃO adicione, remova, renomeie nem reordene NENHUM passo/elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips nem qualquer conteúdo que não esteja
  escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (estado PENDENTE, token válido) ━━━
- Título "Conectar o Telegram" e uma linha "Registre e consulte gastos direto no chat."
- CARD de passos NUMERADOS (1, 2):
  1) "Abra o bot" — botão primário "Abrir no Telegram".
  2) "Confirme o telefone" — "O bot vai pedir para compartilhar seu contato. É assim que eu
     confirmo que o número é seu."
- TOKEN em destaque MONOESPAÇADO, grande e copiável (com um ícone de copiar de LINHA), com a
  etiqueta "código de uso único".
- Contador "expira em 14:32" (MONO); vira ocre quando faltam menos de 2 minutos.
- Botão secundário "Gerar novo código".

━━━ VARIAÇÃO — VINCULADO ━━━
Card em verde-cédula suave, selo "Conectado ✓", o "@usuario" em MONO e um botão secundário "Desconectar".
━━━ VARIAÇÃO — EXPIRADO ━━━
Token esmaecido, aviso direto em argila "Este código expirou." e botão primário "Gerar novo código".

━━━ INVARIANTES ━━━
Deixe claro que o token só aparece nesta tela e serve uma única vez. Acessível: contraste AA, foco
de teclado visível (anel verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Vínculo do Telegram", "Vínculo do Telegram — vinculado" e "Vínculo do Telegram — expirado".
```

### 7.5 Dashboard / Visão geral — *tela-assinatura*
**Objetivo:** leitura instantânea do mês. **Estados:** vazio (primeiro mês); carregando.

> **Shell já criado.** A barra lateral (aside) e o cabeçalho (header) padrão do app já foram
> gerados no Stitch e serão o layout base reutilizado por todas as telas logadas — por isso
> este prompt (e os §7.6–§7.17) pede **apenas o conteúdo** da área principal.
```text
Gere a tela DASHBOARD / VISÃO GERAL de um app de finanças pessoais em pt-BR — o CONTEÚDO da área
principal, dentro do shell. ESTA É A TELA-ASSINATURA. Ignore qualquer versão anterior — comece do
zero. Gere a tela principal + a variação VAZIO (no fim).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro), que será encaixado dentro desse shell — mobile-first, vira grade no desktop.
NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips nem qualquer conteúdo que não esteja
  escrito aqui entre aspas. Todo texto visível está entre aspas: use-o LITERALMENTE.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ELEMENTO-ASSINATURA — "A RÉGUA DO MÊS" ━━━
Uma régua HORIZONTAL do dia 1 ao último dia do mês, logo abaixo do topo do conteúdo, com: um
marcador do "hoje", TICKS nas datas de vencimento (em ocre #C9852A) e uma faixa sutil mostrando o
"disponível" diminuindo ao longo do mês. É o herói visual.

━━━ ESTRUTURA ━━━
- SELETOR DE MÊS "Junho de 2026" com as setas "‹" e "›", acima ou junto da régua.
- CARDS DE RESUMO (valores MONO, alinhados à direita):
  - "Disponível do mês" — em destaque, "R$ 2.480,00" (verde-cédula por ser positivo).
  - "Gastos do mês" — "R$ 3.120,00", com um mini-comparativo discreto "vs. mês anterior".
  - "A vencer (7 dias)" — "R$ 540,00" em ocre, com "3 contas".
  - "Fatura do cartão" — "Nubank · fecha 28 de junho" e "R$ 1.870,00".
- GRÁFICO: um donut "Gastos por categoria" com legenda — "Mercado", "Transporte", "Restaurante",
  "Moradia", "Lazer" —, cada item com a cor/ícone do seu chip.
- LISTA "Próximas contas" — 3 linhas, colunas "descrição · valor · vencimento · status":
      "Aluguel"    "R$ 1.500,00"   "vence 30 de junho"   selo "em aberto"
      "Internet"   "R$ 120,00"     "vence 28 de junho"   selo "em aberto"
      "Energia"    "R$ 210,00"     "vence 25 de junho"   selo "vencido"

━━━ VARIAÇÃO — VAZIO (primeiro mês) ━━━
Sem cards preenchidos; um convite "Registre seu primeiro gasto" com a dica "você também pode usar
o Telegram".

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro — todos os valores (disponível, gastos, a vencer, fatura, donut,
régua) chegam prontos do backend; a tela só EXIBE. Nada pisca; só a régua se move (animação sutil de
entrada). Acessível: contraste AA, foco de teclado visível (anel verde-cédula), alvos ≥ 44px, 360px.

Entregue: "Visão geral" e "Visão geral — vazio".
```

### 7.6 Lançamentos — lista
**Objetivo:** ver/filtrar/agir sobre lançamentos. **Estados:** vazio; filtro sem resultado; carregando.
```text
Gere a tela LANÇAMENTOS de um app de finanças pessoais em pt-BR — o CONTEÚDO da área principal,
dentro do shell. Ignore qualquer versão anterior — comece do zero. Estilo EXTRATO: denso, legível,
valores em mono à direita. Gere a tela principal + duas variações (VAZIO; FILTRO SEM RESULTADO).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips nem qualquer conteúdo que não esteja
  escrito aqui entre aspas. Todo texto visível está entre aspas: use-o LITERALMENTE.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) ━━━
- Título da página "Lançamentos".
- BARRA DE FILTROS (chips): "Período" (mês), "Categoria", "Forma/Cartão" e "Status"; e um campo
  de busca com placeholder "Buscar por descrição". Os chips de status possíveis, use APENAS
  estes rótulos: "em aberto", "pago", "vencido", "cancelado".
- LISTA agrupada por DIA (um subtítulo de data por grupo, ex.: "5 de julho"). Cada linha, colunas
  "descrição · categoria · forma/cartão · valor · status", valores MONO à direita; em parcelado,
  a fração MONO "2/3" na descrição:
      "Mercado do mês"   chip "Mercado"      "Crédito · Nubank"   "R$ 450,00"   selo "em aberto"
      "Uber"             chip "Transporte"   "Pix"                "R$ 32,90"    selo "pago"
      "Geladeira 2/3"    chip "Moradia"      "Crédito · Nubank"   "R$ 800,00"   selo "em aberto"
  Cada linha abre o detalhe ao toque; um menu por linha traz "Editar", "Cancelar" e "Excluir".
- RODAPÉ fixo com o total filtrado MONO, rotulado "total exibido" (apenas leitura, vem pronto).

━━━ VARIAÇÃO — VAZIO ━━━
Sem lista; um estado calmo "Nenhum lançamento ainda." com a dica "registre na web ou pelo Telegram".
━━━ VARIAÇÃO — FILTRO SEM RESULTADO ━━━
Sem linhas; a mensagem "Nenhum lançamento neste filtro." com a ação "Limpar filtros".

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro: valores, frações de parcela e o "total exibido" chegam prontos
do backend — a tela só EXIBE. Acessível: contraste AA, foco de teclado visível (anel verde-cédula),
alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Lançamentos", "Lançamentos — vazio" e "Lançamentos — sem resultado".
```

### 7.7 Lançamento — criar/editar (com prévia de parcelas)
**Objetivo:** capturar um gasto e **confirmar** antes de gravar (regra 7). **Estados:** validação; crédito exige cartão; prévia de parcelas.

> **⚠️ Resolvido pelo modal 7b — NÃO gerar no Stitch.** Este formulário já existe, idêntico,
> como o **modal "Registrar gasto"** (§7.7b, `components/modal/registrar-gasto.blade.php`): a
> "tela 7" é esse mesmo form "encaixado num modal". Mesmos campos, mesmo fluxo em dois passos
> (form → prévia calculada pelo backend → confirmar), mesma barreira anti-cálculo (regra 4) e
> confirmação (regra 7). Uma versão **página inteira** só se justifica para **editar** um
> lançamento existente (carregar valores + PUT), e mesmo aí reaproveita o form extraído como
> **partial Blade** — sem desenho novo. O prompt abaixo fica **apenas como referência**.

```text
Gere a tela CRIAR/EDITAR LANÇAMENTO de um app de finanças pessoais em pt-BR — o CONTEÚDO da área
principal, dentro do shell. Ignore qualquer versão anterior desta tela — comece do zero. Gere
DUAS variações (detalhadas no fim): "Novo lançamento — Crédito" e "Novo lançamento — Pix/à vista".

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM campo além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, ícones decorativos nem qualquer
  conteúdo que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (igual nas duas variações) ━━━
- Título da página "Novo lançamento" (Bricolage Grotesque) e, abaixo, a nota discreta "* obrigatório".
- Formulário em cartão (#FBFBF8, cantos 12px), campos empilhados UM POR LINHA (no desktop pode
  usar 2 colunas), cada um com rótulo acima. Obrigatórios com "*".

━━━ CAMPOS FIXOS (nas DUAS variações, nesta ordem) ━━━
1. "Descrição" * — input de texto, valor: "Mercado do mês".
2. "Valor" * — input MONO com prefixo "R$" e o número alinhado à direita, valor: "R$ 450,00".
3. "Data da compra" * — campo de data, valor: "05/07/2026".
4. "Forma de pagamento" * — controle segmentado com EXATAMENTE 5 opções, nesta ordem:
   "Crédito", "Débito", "Pix", "Dinheiro", "Boleto". Quebre em duas linhas de largura igual se
   não couber — NÃO junte nem remova opções. O segmento selecionado em verde-cédula #1F6E5A com
   texto claro; os demais neutros.
… (aqui entram os campos condicionais, que MUDAM por variação — ver abaixo) …
ÚLTIMO campo fixo (sempre por último, nas duas variações):
"Categoria" * — seletor de chips (pill) com ícone + rótulo, nesta ordem: "Mercado",
   "Restaurante", "Transporte", "Moradia", "Lazer", "Outros". O chip "Mercado" está SELECIONADO
   (fundo verde-cédula) e traz um selo pequeno "sugerido". Os demais neutros.

━━━ VARIAÇÃO A — forma selecionada: "Crédito" ━━━
Entre o campo 4 e a Categoria, mostre NESTA ordem:
- "Cartão" * — dropdown MONO, valor: "Nubank •••• 1234 — fecha dia 28".
- "Parcelas" — seletor numérico de 1 a 24, valor: "3". Logo abaixo, uma TABELA MONO rotulada
  "Prévia — calculada pelo sistema (ainda não gravado)", 3 linhas (colunas: nº · valor ·
  vencimento), alinhadas à direita:
      "1/3"  "R$ 150,00"  "vence 05/08"
      "2/3"  "R$ 150,00"  "vence 05/09"
      "3/3"  "R$ 150,00"  "vence 05/10"
- "Data de vencimento" * — NÃO é editável: mostre um CHIP somente-leitura, texto:
  "vence 5 de agosto · calculado pelo cartão".
- "Data de pagamento" — rótulo "Data de pagamento (opcional)", campo de data VAZIO, com apoio
  discreto "vazio = em aberto".
NESTA variação NÃO existe o switch de recorrência (crédito usa parcelas). Não o desenhe aqui.

━━━ VARIAÇÃO B — forma selecionada: "Pix / à vista" ━━━
Segmento "Pix" selecionado (o comportamento de débito/dinheiro/boleto é idêntico). Entre o campo
4 e a Categoria, mostre NESTA ordem — e NÃO mostre "Cartão", "Parcelas" nem a tabela de prévia:
- "Data de vencimento" * — campo de DATA EDITÁVEL, valor: "05/07/2026".
- "Data de pagamento" — rótulo "Data de pagamento (opcional)", campo de data VAZIO, com apoio
  discreto "vazio = em aberto".
- "Gasto recorrente" — um SWITCH rotulado "Repete todo mês?", mostrado LIGADO, com apoio discreto
  "assinaturas e contas fixas". Por estar ligado, revela abaixo: "Periodicidade" (dropdown, valor
  "mensal") e "Dia" (seletor de dia do mês, valor "5").

━━━ RODAPÉ (nas duas variações) ━━━
Linha fina (#DDE0D7) acima e dois botões: primário "Revisar e confirmar" (verde-cédula, à
direita) e secundário "Cancelar" (contorno verde-cédula, à esquerda). "Revisar e confirmar" NÃO
grava direto — abre um PAINEL DE CONFIRMAÇÃO com o resumo do lançamento (descrição, valor, forma,
cartão/parcelas, vencimento, categoria) e os botões "Confirmar" e "Voltar"; acima do resumo, a
frase "Confira antes de gravar — nada foi salvo ainda.".

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro nem vencimento: o valor por parcela, a prévia e o vencimento do
cartão chegam prontos do backend — a tela só EXIBE; e nada é gravado sem a confirmação explícita.
Acessível: contraste AA, foco de teclado visível (anel verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue as DUAS variações como telas separadas: "Novo lançamento — Crédito" e
"Novo lançamento — Pix/à vista".
```

### 7.7b Registrar gasto — *modal rápido (FAB do dashboard)*
**Objetivo:** registrar um gasto **sem sair da tela atual**, por um **modal** disparado pelo
FAB "Registrar gasto" do Dashboard (§7.5, hoje "em breve"). É o **atalho de captura rápida**;
o formulário completo com edição e histórico permanece na página §7.7. Ambos gravam o **mesmo**
lançamento e obedecem às mesmas barreiras (regra 4: a tela não calcula; regra 7: confirmar
antes de gravar).

> **Campos e obrigatoriedade** derivam do domínio (doc 03 §4.6): forma de pagamento
> ∈ {crédito, débito, pix, dinheiro, boleto}; **crédito** é a única em cartão. **Datas
> separadas:** compra/vencimento/pagamento. **Vencimento é determinístico** — no crédito ele é
> **calculado pelo cartão** (nunca digitado); fora de cartão, é a própria data. **Recorrência**
> existe no modelo (assinaturas) mas o **backend é pós-MVP** — no design o switch já aparece;
> a ligação real fica pendente. **Visibilidade do switch (decisão de design):** só nas formas
> **à vista** (pix, débito, dinheiro, boleto); **oculto no crédito**, pois crédito usa parcelas
> e recorrência/parcelamento não se combinam.

> **Prompt fechado (anti-invenção).** O Stitch preenche buraco com invenção; a defesa é não
> deixar buraco: cada campo, rótulo e valor literal, uma lista explícita do que é **proibido**
> gerar, e os campos condicionais **separados por variação** (crédito × à vista). Cole do zero.

```text
Gere um COMPONENTE MODAL "Registrar gasto" de um app de finanças pessoais em pt-BR. Ignore
qualquer versão anterior desta tela — comece do zero. Gere DUAS TELAS (variações) deste mesmo
modal (detalhadas no fim). É só este modal: NÃO gere nenhuma outra tela, fluxo ou página.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo (backdrop atrás do modal): #EDF0E8
- Superfície do card e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px no card, 8px em campos/botões, pill nos chips. Sombra difusa e suave.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), menu, abas, breadcrumb, logo, avatar,
  ilustração, imagem, banner, gráfico, nem rodapé de página. Só o modal sobre o backdrop.
- NÃO adicione, remova, renomeie nem reordene NENHUM campo além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, ícones decorativos, contadores,
  "termos", social login, nem qualquer conteúdo que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA DO MODAL (igual nas duas variações) ━━━
- Backdrop escurecido cobrindo a tela; o modal é um card central #FBFBF8, cantos 12px, sombra
  difusa; largura confortável no desktop, quase full-width no mobile (funciona a 360px).
- Topo: título "Registrar gasto" (Bricolage Grotesque) à esquerda e botão "X" (fechar) à direita.
- Uma nota discreta abaixo do título: "* obrigatório".
- Campos empilhados, UM POR LINHA, cada um com seu rótulo acima. Obrigatórios com "*".
- Rodapé fixo do card, com uma linha fina (#DDE0D7) acima: botão primário "Revisar e confirmar"
  (verde-cédula, à direita) e botão secundário "Cancelar" (contorno verde-cédula, à esquerda).

━━━ CAMPOS FIXOS (aparecem nas DUAS variações, nesta ordem) ━━━
1. "Descrição" * — input de texto, valor: "Mercado do mês".
2. "Valor" * — input MONO com prefixo "R$" e o número alinhado à direita, valor: "R$ 450,00".
3. "Forma de pagamento" * — controle segmentado com EXATAMENTE 5 opções, nesta ordem:
   "Crédito", "Débito", "Pix", "Dinheiro", "Boleto". (Se não couber numa linha, quebre em duas
   linhas de segmentos de largura igual — NÃO junte nem remova opções.) O segmento selecionado
   fica em verde-cédula #1F6E5A com texto claro; os demais neutros.
… (aqui entram os campos condicionais, que MUDAM por variação — ver abaixo) …
ÚLTIMO campo (sempre por último, nas duas variações):
"Categoria" * — seletor de chips (pill) com ícone + rótulo, nesta ordem: "Mercado",
   "Restaurante", "Transporte", "Moradia", "Lazer", "Outros". O chip "Mercado" está
   SELECIONADO (fundo verde-cédula) e traz um selo pequeno "sugerido". Os demais neutros.

━━━ VARIAÇÃO A — forma selecionada: "Crédito" ━━━
Segmento "Crédito" selecionado. Entre o campo 3 e a Categoria, mostre NESTA ordem:
- "Cartão" * — dropdown MONO, valor: "Nubank •••• 1234 — fecha dia 28".
- "Parcelas" — seletor numérico, valor: "3". Logo abaixo, uma TABELA MONO rotulada
  "Prévia — calculada pelo sistema (ainda não gravado)", com 3 linhas (colunas: nº · valor ·
  vencimento), alinhadas à direita:
      "1/3"  "R$ 150,00"  "vence 05/07"
      "2/3"  "R$ 150,00"  "vence 05/08"
      "3/3"  "R$ 150,00"  "vence 05/09"
- "Data de vencimento" * — NÃO é um campo editável: mostre um CHIP somente-leitura, texto:
  "vence 5 de julho · calculado pelo cartão".
- "Data de pagamento" — rótulo "Data de pagamento (opcional)", campo de data VAZIO, com apoio
  discreto: "vazio = em aberto".
NESTA variação NÃO existe o switch de recorrência (crédito usa parcelas). Não o desenhe aqui.

━━━ VARIAÇÃO B — forma selecionada: "Pix / Dinheiro" (à vista) ━━━
Segmento "Pix" selecionado (o comportamento de "Dinheiro" é idêntico). Entre o campo 3 e a
Categoria, mostre NESTA ordem — e NÃO mostre "Cartão", "Parcelas" nem a tabela de prévia:
- "Data de vencimento" * — campo de DATA EDITÁVEL, valor: "05/07/2026".
- "Data de pagamento" — rótulo "Data de pagamento (opcional)", campo de data VAZIO, com apoio
  discreto: "vazio = em aberto".
- "Gasto recorrente" — um SWITCH rotulado "Repete todo mês?", mostrado LIGADO, com apoio
  discreto: "assinaturas e contas fixas". Por estar ligado, revela abaixo dois campos:
      "Periodicidade" — dropdown, valor: "mensal".
      "Dia" — seletor de dia do mês, valor: "5".

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro nem vencimento: o valor por parcela, a prévia e o vencimento
do cartão chegam prontos do backend — a tela só exibe. Acessível: contraste AA, foco de teclado
visível (anel verde-cédula), alvos de toque ≥ 44px, funciona a partir de 360px.

Entregue as DUAS variações como telas separadas: "Registrar gasto — Crédito" e
"Registrar gasto — Pix/Dinheiro".
```

> **Estados adicionais (gerar depois, como variações à parte — não no prompt principal):**
> validação inline ("Informe uma descrição."; "Informe um valor."; "Escolha a forma de
> pagamento."; "Crédito exige um cartão."; "Escolha uma categoria."); **crédito sem cartão
> cadastrado** (aviso + link "cadastrar um cartão", "Revisar e confirmar" desabilitado);
> **salvando** (botão "Salvando…", campos desabilitados). Ficam fora do prompt fechado acima
> para não poluir as duas variações-base; gere um de cada vez, com o mesmo rigor literal.

### 7.8 Lançamento — detalhe (parcelas + status)
**Objetivo:** ver um lançamento e suas parcelas. **Estados:** com parcela paga (edição bloqueada).
```text
Gere a tela DETALHE DO LANÇAMENTO de um app de finanças pessoais em pt-BR — o CONTEÚDO da área
principal, dentro do shell. Ignore qualquer versão anterior — comece do zero. É SÓ esta tela.

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, ícones decorativos nem qualquer
  conteúdo que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA ━━━
1. CABEÇALHO DO CONTEÚDO (não é o header do app): à esquerda a descrição "Mercado do mês"
   (Bricolage Grotesque) com um chip de categoria pill "Mercado" logo abaixo; à direita o valor
   total MONO "R$ 450,00" e um selo de status pill "em aberto" (ocre).
2. BLOCO DE METADADOS (linhas "rótulo → valor"; datas e números em MONO):
   - "Forma de pagamento": "Crédito"
   - "Cartão": "Nubank •••• 1234"
   - "Data da compra": "05/07/2026"
   - "Vencimento": "5 de agosto · calculado pelo cartão"
   - "Origem": "manual"  (os únicos valores possíveis deste campo são "manual", "Telegram" ou "fatura PDF")
3. TABELA DE PARCELAS (MONO, alinhada à direita), com cabeçalho de colunas "nº · valor ·
   vencimento · status" e 3 linhas:
      "1/3"  "R$ 150,00"  "05/08"  selo "pago"
      "2/3"  "R$ 150,00"  "05/09"  selo "em aberto"
      "3/3"  "R$ 150,00"  "05/10"  selo "agendado"
   Selos (pill): "pago" verde-cédula suave · "em aberto" ocre · "agendado" neutro · "vencido"
   argila. Use APENAS estes rótulos de status.
4. AÇÕES no rodapé: botão secundário "Editar" e botão secundário "Cancelar". Como HÁ uma parcela
   paga nesta tela, "Editar" fica DESABILITADO, com a explicação em texto secundário:
   "Há parcelas pagas — não é possível editar; você pode cancelar as futuras."

━━━ INVARIANTES ━━━
A interface NUNCA calcula nem recalcula dinheiro: valor total, valor por parcela, parcela vigente
e status chegam prontos do backend — a tela só EXIBE. Acessível: contraste AA, foco de teclado
visível (anel verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue como UMA tela: "Detalhe do lançamento".
```

### 7.9 Confirmações pendentes — *espelho web do "Confirma?"*
**Objetivo:** materializar a regra 7 na web (gastos interpretados aguardando "sim"). **Estados:** vazio.
```text
Gere a tela CONFIRMAÇÕES PENDENTES de um app de finanças pessoais em pt-BR — o CONTEÚDO da área
principal, dentro do shell. Ignore qualquer versão anterior — comece do zero. Gere a tela
principal + a variação VAZIO (no fim).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, ícones decorativos nem qualquer
  conteúdo que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) ━━━
- Título da página "Confirmações pendentes" (Bricolage Grotesque) e uma linha secundária:
  "Interpretei estes gastos. Nada foi gravado — confirme para salvar."
- LISTA de cards de prévia (superfície #FBFBF8, cantos 12px), um por item:
  CARD 1 (gasto pronto): descrição "Uber", valor MONO "R$ 32,90", chip de categoria "Transporte"
    com selo pequeno "sugerido", linha de metadados "Pix · vence 05/07", e a frase "Pronto para
    gravar — confirme". Dois botões: primário "Confirmar" e secundário "Ajustar".
  CARD 2 (parcelado): descrição "Geladeira", valor MONO "R$ 2.400,00", chip "Moradia", linha
    "Crédito · Nubank •••• 1234", e uma TABELA MONO curta (colunas nº · valor · vencimento):
        "1/3"  "R$ 800,00"  "vence 05/08"
        "2/3"  "R$ 800,00"  "vence 05/09"
        "3/3"  "R$ 800,00"  "vence 05/10"
    Mesma dupla de botões: primário "Confirmar" e secundário "Ajustar".
  CARD 3 (esclarecimento pedido pela IA): descrição "Almoço", valor MONO "R$ 68,00", e uma
    pergunta destacada "Qual cartão?" com dois botões de opção "Nubank" e "Itaú"; NESTE card
    ainda NÃO aparecem os botões "Confirmar"/"Ajustar" (a escolha vem primeiro).
- Deixe explícito, em texto secundário: "Nada é gravado até você confirmar."

━━━ VARIAÇÃO — VAZIO ━━━
Sem cards; um estado vazio calmo: "Nada para confirmar agora." com a linha de apoio "Gastos que
você registrar pelo Telegram aparecem aqui para confirmação."

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro: valores e prévia de parcelas chegam prontos do backend. "Ajustar"
abre o formulário completo; "Confirmar" grava — e nada é gravado antes disso. Acessível: contraste
AA, foco de teclado visível (anel verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Confirmações pendentes" e "Confirmações pendentes — vazio".
```

### 7.10 Receitas
**Objetivo:** cadastrar/listar receitas (base do disponível). **Estados:** vazio.
```text
Gere a tela RECEITAS de um app de finanças pessoais em pt-BR — o CONTEÚDO da área principal,
dentro do shell. Ignore qualquer versão anterior — comece do zero. Gere a tela principal + a
variação FORMULÁRIO "Adicionar receita" (no fim).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, ícones decorativos nem qualquer
  conteúdo que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) ━━━
- Título da página "Receitas".
- CARD de resumo no topo: rótulo "Receitas de julho" e o valor MONO em destaque "R$ 6.500,00".
- FILTRO por tipo: controle segmentado com 3 opções, nesta ordem: "Todas", "Fixa", "Variável".
  "Todas" selecionado (verde-cédula).
- LISTA (estilo extrato, valores MONO à direita), colunas "descrição · tipo · data · valor":
      "Salário"        chip "Fixa"      "05/07"   "R$ 5.000,00"
      "Freela"         chip "Variável"  "12/07"   "R$ 1.200,00"
      "Pix recebido"   chip "Variável"  "20/07"   "R$ 300,00"
- Botão primário "Adicionar receita" (no topo à direita).

━━━ VARIAÇÃO — FORMULÁRIO "Adicionar receita" ━━━
Um cartão/painel com os campos, UM POR LINHA (obrigatórios com "*"):
- "Descrição" * — valor: "Salário".
- "Valor" * — input MONO com prefixo "R$", valor: "R$ 5.000,00".
- "Tipo" * — controle segmentado "Fixa" / "Variável", "Fixa" selecionado.
- "Data" * — campo de data, valor: "05/07/2026".
Rodapé com linha fina (#DDE0D7) acima: primário "Revisar e confirmar" e secundário "Cancelar".
Não grava direto — abre uma confirmação com o resumo antes de salvar.

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro: o total do resumo vem pronto do backend — a tela não soma
receitas. Nada é gravado sem a confirmação. Acessível: contraste AA, foco de teclado visível
(anel verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Receitas" e "Receitas — adicionar".
```

### 7.11 Orçamento do mês
**Objetivo:** ver limite e consumo (total + por categoria). **Estados:** sem orçamento definido; estouro.
```text
Gere a tela ORÇAMENTO DO MÊS de um app de finanças pessoais em pt-BR — o CONTEÚDO da área
principal, dentro do shell. Ignore qualquer versão anterior — comece do zero. Gere a tela
principal + duas variações (SEM ORÇAMENTO; ESTOURO).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, ícones decorativos nem qualquer
  conteúdo que não esteja escrito aqui entre aspas.
- NÃO crie campo de "limite por categoria" (no MVP só existe limite mensal GERAL; por categoria
  mostra-se apenas o consumo).
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) ━━━
- Título da página "Orçamento do mês" e o seletor de mês "Julho de 2026" com as setas "‹" e "›".
- CARD GERAL no topo: rótulo "Limite do mês" com o valor MONO "R$ 4.000,00" e rótulo "Consumido"
  com "R$ 3.120,00"; uma barra de progresso calma (verde-cédula) parcialmente preenchida e, à
  direita dela, MONO "78%". Abaixo, a linha de saldo "Resta R$ 880,00".
- SEÇÃO "Por categoria" — lista onde CADA linha tem: chip de categoria, o consumo MONO à direita
  e uma barra fina. Como no MVP só existe limite GERAL, cada categoria mostra APENAS o consumo e
  a etiqueta "sem limite":
      chip "Moradia"      "R$ 1.500,00"   "sem limite"
      chip "Mercado"      "R$ 820,00"     "sem limite"
      chip "Transporte"   "R$ 500,00"     "sem limite"
      chip "Lazer"        "R$ 300,00"     "sem limite"

━━━ VARIAÇÃO — SEM ORÇAMENTO ━━━
Sem o card geral preenchido; um estado calmo com "Defina um limite para acompanhar o consumo." e
um botão primário "Definir limite do mês".

━━━ VARIAÇÃO — ESTOURO ━━━
No card geral, "Consumido" "R$ 4.300,00" acima do "Limite do mês" "R$ 4.000,00"; a barra usa
argila #B4452F e mostra "108%"; a linha de saldo vira "Acima do limite em R$ 300,00" (em argila).

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro: limite, consumo, percentuais e saldo chegam prontos do
backend — a tela só EXIBE. Acessível: contraste AA, foco de teclado visível (anel verde-cédula),
alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Orçamento do mês", "Orçamento do mês — sem orçamento" e "Orçamento do mês — estouro".
```

### 7.12 Categorias
**Objetivo:** gerenciar categorias (cor, ícone, palavras-chave, arquivar). **Estados:** —
```text
Gere a tela CATEGORIAS de um app de finanças pessoais em pt-BR — o CONTEÚDO da área principal,
dentro do shell. Ignore qualquer versão anterior — comece do zero. Gere a tela principal + a
variação EDITAR CATEGORIA (no fim).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas, tooltips, cores berrantes nem qualquer conteúdo
  que não esteja escrito aqui entre aspas.
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) ━━━
- Título da página "Categorias" e, à direita, botão primário "Nova categoria".
- GRADE de categorias (cards pequenos), cada card com um chip (cor + ícone de LINHA), o nome e a
  contagem de uso em MONO, e um botão discreto "Editar". Use EXATAMENTE estas, nesta ordem:
      "Alimentação"   "128 usos"
      "Transporte"    "64 usos"
      "Moradia"       "12 usos"
      "Lazer"         "40 usos"
      "Saúde"         "9 usos"
      "Assinaturas"   "6 usos"
      "Educação"      "4 usos"
      "Outros"        "21 usos"

━━━ VARIAÇÃO — EDITAR CATEGORIA ━━━
Painel/cartão de edição da categoria "Alimentação", campos UM POR LINHA (obrigatórios com "*"):
- "Nome" * — valor: "Alimentação".
- "Cor" — uma paleta restrita de amostras harmônicas com o tema (sem cores berrantes); uma
  amostra selecionada.
- "Ícone" — uma grade pequena de ícones de LINHA para escolher; um selecionado.
- "Palavras-chave" — campo de tags (chips removíveis), valores: "mercado", "restaurante", "ifood".
- "Apelidos de estabelecimento" — campo de tags (chips removíveis), valores: "Pão de Açúcar", "iFood".
Rodapé com linha fina (#DDE0D7) acima: primário "Salvar" e secundário "Arquivar" (arquivar não
apaga o histórico).

━━━ INVARIANTES ━━━
A contagem de uso vem pronta do backend; a tela só EXIBE. A edição só é gravada ao "Salvar".
Acessível: contraste AA, foco de teclado visível (anel verde-cédula), alvos ≥ 44px, funciona a
partir de 360px. Tom de organização tranquila, sem excesso de cor.

Entregue: "Categorias" e "Categorias — editar".
```

### 7.13 Cartões & faturas
**Objetivo:** ver cartões e a fatura por competência. **Estados:** sem cartão; fatura fechada vs. aberta.
```text
Gere a tela CARTÕES & FATURAS de um app de finanças pessoais em pt-BR — o CONTEÚDO da área
principal, dentro do shell. Ignore qualquer versão anterior — comece do zero. Gere a tela
principal + duas variações (SEM CARTÃO; FATURA FECHADA).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente logotipos de bandeira, número de cartão completo, valores, dicas, tooltips nem
  qualquer conteúdo que não esteja escrito aqui entre aspas. (Cartão é identificado só por
  descrição + os 4 últimos dígitos.)
- Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) ━━━
- Título da página "Cartões & faturas" e, à direita, botão primário "Adicionar cartão".
- FAIXA de cartões cadastrados (cards selecionáveis), cada um com a descrição, os 4 dígitos MONO
  e os dias de ciclo:
      "Nubank"   "•••• 1234"   "fecha dia 28 · vence dia 5"
      "Itaú"     "•••• 9876"   "fecha dia 20 · vence dia 1"
  O cartão "Nubank" está SELECIONADO (borda verde-cédula).
- Abaixo, a FATURA do cartão selecionado por competência:
  - Seletor de mês "Julho de 2026" com as setas "‹" e "›".
  - Cabeçalho da fatura: total MONO em destaque "R$ 1.870,00", as datas "fecha 28 de julho ·
    vence 5 de agosto" e um selo pill "aberta" (ocre).
  - LISTA de lançamentos da fatura, estilo extrato (colunas "descrição · categoria · valor"),
    valores MONO à direita; em parcelado, a fração MONO na descrição:
        "Mercado do mês"   chip "Mercado"      "R$ 450,00"
        "Geladeira 2/3"    chip "Moradia"      "R$ 800,00"
        "Uber"             chip "Transporte"   "R$ 32,90"

━━━ VARIAÇÃO — SEM CARTÃO ━━━
Sem a faixa de cartões nem a fatura; um estado calmo "Cadastre um cartão para acompanhar as
faturas." e um botão primário "Adicionar cartão".

━━━ VARIAÇÃO — FATURA FECHADA ━━━
Igual à tela principal, mas o selo da fatura é "fechada" (neutro) em vez de "aberta" (ocre).

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro: o total da fatura e as frações de parcela são calculados pelo
sistema — a tela só EXIBE. Acessível: contraste AA, foco de teclado visível (anel verde-cédula),
alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Cartões & faturas", "Cartões & faturas — sem cartão" e "Cartões & faturas — fatura fechada".
```

### 7.14 Chat financeiro — *coluna fixa (3ª coluna do shell, sempre aberta)*
**Objetivo:** conversar sobre as finanças na web — como no bot — por uma **coluna de chat fixa,
sempre aberta, à direita** (a terceira coluna do shell), com o **histórico no topo**, uma
**entrada de texto** e um **anexo que aceita SOMENTE PDF**. Substitui a versão de página cheia:
esta é **a** forma do chat na web. **Estados:** vazio; PDF anexado; anexo inválido; pensando;
instabilidade/re-tentativa; fallback sem números.

> **Coluna fixa, NÃO overlay.** O chat é um **companheiro persistente** (como o bot está sempre
> a um toque): é a **terceira coluna** de um layout de três colunas — **nav** à esquerda ·
> **conteúdo da tela** no centro · **chat** à direita. Os três **coexistem**: o body reflui e
> fica **entre** a nav e o chat; nada é esmaecido, não há backdrop e a coluna **não cobre** o
> conteúdo. Por isso o prompt pede **apenas o conteúdo da coluna de chat** — como os demais
> prompts logados pedem só a área principal (§7.6–§7.17) e não redesenham o shell.
>
> **Responsividade (regra 360px).** "Sempre aberta" vale no **desktop/telas largas**; três
> colunas **não** cabem no mobile. Em tela estreita (a partir de ~1024px pra baixo) a coluna
> **recolhe** para um lançador e abre como folha por cima. Esse colapso é regra do **layout
> Blade** (`x-layouts.app`), não do desenho do rail — fica **fora** do prompt.
>
> **Anexo PDF × importação (§7.15/§7.16).** O anexo aqui é só a **afordância de entrada**: ao
> enviar um PDF, o fluxo continua na **revisão efêmera** já speccada (§7.16) — nada do documento
> é guardado (regra 6). As telas dedicadas de importar/revisar **permanecem** e só são geradas
> **após** o backend da [[spec-11-importacao-pdf]]; aqui desenhamos apenas o anexo e o retorno
> do bot com o link "Revisar importação".

> **Prompt fechado (anti-invenção).** Mesmo rigor do §7.7b: cada elemento, rótulo e valor é
> literal; há uma lista do que é **proibido** gerar; os estados extras vêm **depois**, como
> variações à parte. Cole do zero.

```text
Gere UMA tela: o CONTEÚDO de uma COLUNA FIXA de "Chat financeiro" — a terceira coluna,
à DIREITA, de um app de finanças pessoais em pt-BR. Ignore qualquer versão anterior desta
tela — comece do zero. É SÓ o conteúdo dessa coluna de chat.
Nome da tela: "Chat financeiro — coluna fixa".

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL de TRÊS COLUNAS: (1) barra lateral de navegação à esquerda,
(2) o conteúdo da tela atual no centro, (3) esta COLUNA DE CHAT à direita — SEMPRE ABERTA,
fixa, coexistindo com as outras. Ela NÃO é um overlay: não há fundo esmaecido, não há
backdrop, não cobre o conteúdo. O conteúdo do centro NÃO fica apagado — os três convivem.
Gere APENAS o conteúdo da coluna de chat (itens 1 a 4 abaixo). NÃO desenhe a navegação
esquerda, o header do app, nem o conteúdo do centro.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície da coluna e bolhas: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cards, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples (clipe de papel, seta, documento). Sem ícones preenchidos,
  coloridos ou decorativos.

━━━ FORMATO DA COLUNA ━━━
- Coluna vertical de ALTURA TOTAL da janela, largura fixa confortável (~380px no desktop),
  superfície #FBFBF8, separada do conteúdo à esquerda por uma linha fina (#DDE0D7). Sem sombra
  forte de "flutuante" — ela é parte do layout, encostada na borda direita.
- SEM botão de fechar e SEM backdrop: a coluna está sempre presente.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere navegação, menu, abas, breadcrumb, logo, avatar, gráfico, banner promocional,
  header do app, conteúdo central, nem fundo esmaecido. SÓ a coluna de chat.
- NÃO adicione, remova nem renomeie NENHUM elemento além dos listados abaixo.
- NÃO invente valores, dicas, tooltips, ícones decorativos, "termos", nem conteúdo que não
  esteja escrito aqui entre aspas. Todo texto visível está entre aspas: use-o LITERALMENTE.
- O anexo aceita SOMENTE PDF — NÃO desenhe opção de imagem, câmera, foto, planilha nem outro tipo.

━━━ ESTRUTURA DA COLUNA (de cima para baixo) ━━━
1. CABEÇALHO da coluna: título "Chat financeiro" (Bricolage Grotesque) à esquerda. Sem botão X.
2. BANNER de transparência (discreto, uma linha, sobre superfície levemente destacada): "Respostas
   geradas com IA. Os números vêm do seu banco de dados — a IA nunca os inventa."
3. HISTÓRICO (área ROLÁVEL, ocupa a MAIOR PARTE da altura — é o topo do chat): bolhas de conversa,
   das mais antigas (em cima) às mais recentes (embaixo):
   - Bolha do USUÁRIO (à direita, neutra #EDF0E8): "Quanto gastei no mercado em junho?"
   - Bolha do ASSISTENTE (à esquerda, #FBFBF8 com borda #DDE0D7): "Você gastou R$ 1.234,56 em
     Mercado em junho." — o valor em MONO. LOGO ABAIXO da bolha, na mesma linha: um CHIP DE FONTE
     (pill, secundário) "fonte: gastos · junho · categoria Mercado · 12 registros" e um selo pill
     verde-cédula suave "número conferido".
   - Bolha do USUÁRIO com ANEXO (à direita): um chip de arquivo "fatura-nubank.pdf · PDF" (ícone
     de documento, de linha) acima do texto "Segue minha fatura".
   - Bolha do ASSISTENTE respondendo ao anexo (à esquerda): "Fatura lida ✓ Encontrei 18 lançamentos
     (R$ 4.210,00)." com um botão secundário (contorno verde-cédula) "Revisar importação". Abaixo,
     em texto secundário minúsculo: "O PDF já foi descartado."
4. ÁREA DE ENTRADA fixa no rodapé da coluna, com uma linha fina (#DDE0D7) acima:
   - Um botão de ANEXO à esquerda (ícone de clipe de papel, de linha), rótulo acessível "Anexar PDF".
   - Um campo de texto que cresce, placeholder "Pergunte sobre seus gastos…".
   - Um botão primário de ENVIAR à direita (verde-cédula, ícone de seta de linha, alvo ≥ 44px).

━━━ INVARIANTES ━━━
A interface NUNCA calcula dinheiro: todo valor na resposta vem pronto do backend; a tela só exibe.
O anexo é SOMENTE PDF e é efêmero — processado e descartado, nada do documento fica armazenado
(daí a nota "O PDF já foi descartado"). Acessível: contraste AA, foco de teclado visível (anel
verde-cédula), alvos ≥ 44px.

Entregue como UMA tela: "Chat financeiro — coluna fixa".
```

> **Estados adicionais (gerar depois, como variações à parte — não no prompt principal, com o
> mesmo rigor literal):**
> - **VAZIO (primeiro uso):** histórico só com um texto-guia calmo, sem bolhas — "Pergunte sobre
>   seus gastos ou anexe a fatura em PDF."
> - **PDF ANEXADO (pronto para enviar):** acima do campo de texto, um chip "fatura-nubank.pdf · PDF"
>   com um "✕ remover", e a nota discreta "O PDF é processado e descartado — nada fica armazenado."
> - **ANEXO INVÁLIDO:** aviso inline em argila junto à entrada — "Aceito apenas PDF."
> - **PENSANDO:** a última bolha do assistente vira um indicador sutil "consultando seus dados…".
> - **INSTABILIDADE:** aviso ocre — "Instabilidade no momento — tentando de novo…".
> - **FALLBACK (sem números):** bolha do assistente SEM nenhum número — "Não consegui confirmar os
>   números com segurança agora. Pode reformular a pergunta?"

### 7.15 Importar fatura (upload) — *gerar após backend da 07*
**Objetivo:** enviar o PDF da fatura. **Estados:** arrastando; arquivo inválido; **PDF com senha**; enviando.
```text
Gere a tela IMPORTAR FATURA de um app de finanças pessoais em pt-BR — o CONTEÚDO da área
principal, dentro do shell. [Gerar somente APÓS o backend da importação de PDF.] Ignore qualquer
versão anterior — comece do zero. Gere a tela principal + as variações de estado (no fim).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- O upload aceita SOMENTE PDF — NÃO desenhe opção de imagem, câmera, foto, planilha nem outro tipo.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas nem qualquer conteúdo que não esteja escrito aqui
  entre aspas. Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) ━━━
- Título da página "Importar fatura".
- ÁREA DE UPLOAD grande (borda tracejada #DDE0D7, cantos 12px): um ícone de documento de LINHA,
  o texto "Arraste o PDF aqui ou" e um botão secundário "Selecionar arquivo". Abaixo, a linha
  discreta "Aceito apenas PDF.".
- BANNER de privacidade em destaque (superfície levemente destacada): "Seu PDF é processado e
  descartado — nada do documento fica armazenado."
- Rodapé: botão primário "Enviar para revisão" (desabilitado enquanto não há arquivo).

━━━ VARIAÇÃO — ARRASTANDO ━━━
A área de upload realçada (borda verde-cédula) com o texto "Solte para enviar".
━━━ VARIAÇÃO — ARQUIVO INVÁLIDO ━━━
Aviso inline em argila junto à área: "Aceito apenas PDF."
━━━ VARIAÇÃO — PDF COM SENHA ━━━
Aviso inline em argila: "Este PDF está protegido por senha — envie uma versão sem senha."
━━━ VARIAÇÃO — ENVIANDO ━━━
Um chip do arquivo "fatura-nubank.pdf · PDF" com uma barra de progresso sóbria; o botão vira
"Enviando…" e fica desabilitado.

━━━ INVARIANTES ━━━
O PDF é EFÊMERO — processado e descartado, nada do documento é armazenado (daí o banner de
privacidade). Acessível: contraste AA, foco de teclado visível (anel verde-cédula), alvos
≥ 44px, funciona a partir de 360px.

Entregue as telas: "Importar fatura", "Importar fatura — arrastando", "Importar fatura —
inválido", "Importar fatura — com senha" e "Importar fatura — enviando".
```

### 7.16 Revisão da importação (lote) — *gerar após backend da 07*
**Objetivo:** revisar itens extraídos e **confirmar** (regra 7), marcando duplicados. **Estados:** com duplicados; nada para importar.
```text
Gere a tela REVISÃO DA IMPORTAÇÃO de um app de finanças pessoais em pt-BR — o CONTEÚDO da área
principal, dentro do shell. [Gerar somente APÓS o backend da importação de PDF.] Ignore qualquer
versão anterior — comece do zero. Gere a tela principal + a variação NADA PARA IMPORTAR (no fim).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, nº de parcela: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUM elemento além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas nem qualquer conteúdo que não esteja escrito aqui
  entre aspas. Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) ━━━
- Título da página "Revisão da importação".
- CABEÇALHO do conteúdo: "Encontrados 18 lançamentos · R$ 4.210,00" (números MONO) e, ao lado,
  "selecionados 16".
- FILTRO: uma caixa de seleção "Ocultar duplicados" e uma ação "Marcar/desmarcar todos".
- TABELA/LISTA em lote, uma linha por item, colunas "‹caixa de seleção› · descrição · categoria ·
  data · parcela · valor" (datas/valores MONO, valor à direita):
      [x] "Mercado Extra"   chip "Mercado"       "05/07"   "—"     "R$ 320,00"
      [x] "Posto Shell"     chip "Transporte"    "07/07"   "—"     "R$ 180,00"
      [x] "Geladeira"       chip "Moradia"       "02/07"   "1/3"   "R$ 800,00"
      [ ] "Netflix"         chip "Assinaturas"   "03/07"   "—"     "R$ 55,90"
          — este item vem DESMARCADO e sinalizado, em texto secundário: "já existe nos seus lançamentos".
- RODAPÉ fixo: total selecionado MONO "R$ 4.154,10" e dois botões: primário "Confirmar
  importação" e secundário "Cancelar". Acima do rodapé, a linha "Nada é gravado até confirmar;
  o PDF já foi descartado."

━━━ VARIAÇÃO — NADA PARA IMPORTAR ━━━
Sem tabela; um estado calmo "Nada para importar — todos os lançamentos desta fatura já existem."
e um botão secundário "Voltar".

━━━ INVARIANTES ━━━
Nada é gravado até "Confirmar importação"; o PDF é efêmero (nada do documento é armazenado); os
totais e valores chegam prontos do backend — a tela só EXIBE. Acessível: contraste AA, foco de
teclado visível (anel verde-cédula), alvos ≥ 44px, funciona a partir de 360px.

Entregue: "Revisão da importação" e "Revisão da importação — nada para importar".
```

### 7.17 Configurações & privacidade
**Objetivo:** perfil, fuso, vínculo, transparência de IA, direitos LGPD. **Estados:** confirmação de exclusão.
```text
Gere a tela CONFIGURAÇÕES & PRIVACIDADE de um app de finanças pessoais em pt-BR — o CONTEÚDO da
área principal, dentro do shell. Ignore qualquer versão anterior — comece do zero. Gere a tela
principal + a variação CONFIRMAR EXCLUSÃO (no fim).

━━━ CONTEXTO DE LAYOUT (NÃO redesenhe estas partes) ━━━
O app já tem um SHELL: barra lateral de navegação à esquerda, o conteúdo da tela no centro, uma
coluna de chat fixa à direita e um cabeçalho (header) no topo. Gere APENAS o CONTEÚDO da área
principal (o centro). NÃO desenhe a navegação esquerda, o header, nem a coluna de chat.

━━━ TEMA (use EXATAMENTE estas cores/fontes; não escolha outras) ━━━
Conceito "caderno de contas": a precisão de um extrato com a calma de um caderno.
- Texto/quase-preto quente: #1C1B17 · Fundo do app: #EDF0E8
- Superfície de cartões e campos: #FBFBF8 · Primária (verde-cédula): #1F6E5A · hover: #2E8B72
- Atenção/ocre: #C9852A · Negativo/argila: #B4452F · Linhas/bordas: #DDE0D7 · Secundário: #6B6F66
- Títulos: "Bricolage Grotesque". Texto de interface: "IBM Plex Sans".
- TODO valor em R$, data, %, contagem: "IBM Plex Mono", alinhado à direita. Sentence case.
- Cantos: 12px em cartões, 8px em campos/botões, pill nos chips. Sombra difusa e suave.
- Ícones: apenas de LINHA, simples. Sem ícones preenchidos, coloridos ou decorativos.

━━━ PROIBIDO (para você NÃO inventar nada) ━━━
- NÃO gere barra lateral (aside), cabeçalho (header), coluna de chat, menu, abas, breadcrumb,
  logo, avatar, ilustração, imagem ou banner. Só o conteúdo da área central.
- NÃO adicione, remova, renomeie nem reordene NENHUMA seção ou campo além dos listados abaixo.
- NÃO invente valores, textos de ajuda, dicas nem qualquer conteúdo que não esteja escrito aqui
  entre aspas. Todo texto visível está entre aspas: use-o LITERALMENTE, sem parafrasear.
- Se algo não foi especificado, deixe de fora — não preencha com placeholder inventado.

━━━ ESTRUTURA (tela principal) — seções empilhadas, cada uma um cartão com título ━━━
1. "Perfil": campos "Nome" (valor "Lucas") e "E-mail" (valor "lucas@exemplo.com") e um botão
   secundário "Alterar senha".
2. "Preferências": "Fuso horário" (valor "America/São Paulo") e "Mês de referência" (valor
   "Mês corrente").
3. "Telegram": uma linha de status com selo pill "Conectado ✓" e o "@usuario" em MONO, e um
   botão secundário "Gerenciar vínculo".
4. "IA e transparência": o texto "A IA faz três coisas: classifica seus gastos, extrai dados de
   faturas e redige as respostas. A IA nunca calcula dinheiro — os números vêm do seu banco de
   dados." e um link "Política de privacidade".
5. "Privacidade (LGPD)": a linha "Conversas são guardadas por até 60 dias."; um botão secundário
   "Baixar meus dados"; e, CLARAMENTE SEPARADO ao final, um botão de ação destrutiva "Excluir
   minha conta" em argila #B4452F.

━━━ VARIAÇÃO — CONFIRMAR EXCLUSÃO ━━━
Um painel de confirmação (dupla confirmação) sobre a seção de privacidade: título "Excluir minha
conta", o texto direto "Isto apaga sua conta e seus lançamentos. Esta ação não pode ser desfeita.",
um campo "Digite EXCLUIR para confirmar" e dois botões: destrutivo "Excluir definitivamente"
(argila, desabilitado até digitar) e secundário "Cancelar".

━━━ INVARIANTES ━━━
Nada é excluído sem a confirmação dupla; a ação destrutiva é claramente separada e em argila.
Acessível: contraste AA, foco de teclado visível (anel verde-cédula), alvos ≥ 44px, funciona a
partir de 360px. Tom sóbrio e honesto.

Entregue: "Configurações" e "Configurações — confirmar exclusão".
```

## 8. Mensagens do bot (texto — implementadas em código, não no Stitch)
Curtas, sem botões (salvo confirmação), pt-BR, mesma voz do §4.7. *Copy* de referência:

| # | Gatilho | Mensagem (modelo) |
|---|---|---|
| B1 | Vínculo: pedir contato | "Tudo certo! Agora toque em **Compartilhar contato** para eu confirmar seu número." |
| B2 | Vínculo concluído | "Pronto, conta conectada ✓ Pode registrar e consultar gastos por aqui." |
| B3 | Confirmação de gasto | "Gasto de **R$ 150,00** em 3x no crédito (Nubank), categoria Mercado, 1ª vence **10 de junho**. Confirma? (responda *sim* ou *não*)" |
| B4 | Esclarecimento | "Qual cartão? **Nubank** ou **Itaú**?" |
| B5 | Confirmação de edição/cancelamento | "Cancelei as parcelas futuras desse gasto. As já pagas foram preservadas." |
| B6 | Alerta de orçamento | "Você atingiu **R$ 4.000,00** do limite do mês." |
| B7 | Resposta de consulta (com fonte) | "Você gastou **R$ 1.234,56** em Mercado em junho. _(fonte: 12 lançamentos · junho)_" |
| B8 | Instabilidade / re-tentativa | "Tive uma instabilidade ao processar — já estou tentando de novo." |
| B9 | Fallback sem números | "Não consegui confirmar os números com segurança agora. Pode reformular a pergunta?" |
| B10 | Recusa (fora de escopo) | "Eu só ajudo com suas finanças — gastos, saldos, faturas e contas a vencer. Como posso ajudar nisso?" |
| B11 | Importação: resumo | "Fatura lida ✓ Encontrei **18 lançamentos** (R$ 4.210,00). Abra a revisão na web para confirmar." |
| B12 | Importação: PDF com senha | "Esse PDF está protegido por senha. Envie uma versão sem senha, por favor." |
| B13 | Ajuda (/ajuda) | "Posso registrar gastos ('gastei 50 no mercado'), e responder sobre saldo, faturas e contas a vencer. É só falar." |

> As mensagens reais saem pela porta `RespostaAoUsuario` (hoje `RespostaInerte`, ver
> [[spec-03-telegram]] §10): o frontend do bot implementa o *sender* do Telegram que formata
> o `ResultadoDaInteracao`/`RespostaDaConsulta` usando estes modelos.

## 9. Definition of Done
- [ ] Design system (§4) aplicado no projeto Stitch (prompt-base 7.0).
- [ ] Todas as telas do mini-TODO (§6) geradas, aprovadas e marcadas — exceto 15/16, que são
      **pós-MVP** e aguardam o backend da [[spec-11-importacao-pdf]].
- [ ] Cada tela respeita os invariantes de §3 (sem cálculo no cliente; confirmar antes de
      gravar; fonte/transparência da IA; pt-BR; acessibilidade AA/360px).
- [ ] Mensagens do bot (§8) redigidas e prontas para implementação em código.
- [ ] §10 preenchida com os artefatos reais (telas exportadas / componentes).
- [ ] Commit local atômico (regra 1: sem push); separado do backend (regra 3).

## 10. Estado atual / artefatos
- **Status:** 🟡 Em andamento — **grupo A concluído** (tema + entrada & onboarding).
- **Entregue:** **tema (§7.0)** e **grupo A geradas e aprovadas no Stitch** — §7.1 Login,
  §7.2 Criar conta, §7.3 Onboarding+LGPD, §7.4 Vínculo do Telegram. Prompts refinados (estados
  como variações, copy na voz da interface, conteúdo pt-BR realista, acessibilidade embutida).
  _(Registrar aqui os nomes dos artefatos exportados do Stitch quando disponíveis.)_
- **Dashboard / Visão geral (§7.5): 🟢 implementado em Blade (dados fake).** Shell padrão já
  extraído para o layout `x-layouts.app` (com prop `wide` para o canvas largo). O conteúdo da
  Visão geral segue fielmente o design do Stitch, com componentes reutilizáveis:
  `x-dashboard.month-ruler` (a régua do mês — ticks gerados server-side, sem JS para o layout),
  `x-dashboard.summary-card`, `x-dashboard.bill-row` e `x-ui.status-badge`; donut e FAB inline
  em `home.blade.php`. Material Symbols (CDN) do Stitch foi trocado por SVG inline no `x-icon`
  (regra 6/LGPD: sem CDN de terceiros). Tokens novos no design system: `secondary`, `tertiary`,
  `error`, `on-primary`, `surface-container-highest`. Os **três estados** do Stitch estão
  implementados: `pronto` (dados), `x-dashboard.empty-state` (vazio) e `x-dashboard.loading`
  (skeleton). O FAB "Registrar gasto" abre o modal §7.7b.
- **Dashboard — ligação ao backend: 🟢 concluída (dados reais).** `DashboardController@index`
  (rota `home`) compõe os números JÁ calculados pelo domínio (`ResumoDoMes` + as 4 consultas
  determinísticas) e apenas FORMATA em pt-BR para a tela (regra 3/5; a UI nunca calcula, regra 4).
  Preenche a régua do mês (novo domínio `App\Domain\Dashboard\DiasDeVencimentoNoMes` para os ticks
  de vencimento), os 4 cards (disponível, gastos do mês + comparativo vs. mês anterior, a vencer em
  7 dias, fatura do cartão em destaque), o donut por categoria (top 3 + "Outros") e a lista de
  próximas contas. Estado `vazio` quando o usuário não tem lançamentos; `?estado=` segue como
  afordância de revisão. Cobertura: `tests/Feature/Dashboard/DashboardTest.php` (login, vazio,
  números reais formatados, isolamento por usuário) e `tests/Feature/Domain/DiasDeVencimentoNoMesTest.php`.
  **Navegação por competência ligada:** `?mes=YYYY-MM` (default = mês atual; valor forjado/inválido cai
  no atual — mês não é id, vai em claro na URL como no filtro da lista). A âncora é o "hoje" real no mês
  corrente e o 1º dia do mês nos históricos; os chevrons da régua viraram links prev/next
  (`mesAnterior`/`mesSeguinte`). **Regra "mesmomês" (decisão do usuário):** os blocos relativos ao HOJE —
  card "a vencer (7 dias)", o tick "hoje" da régua e os quadros 06b (Próximas contas / Em atraso) —
  aparecem **só no mês atual**; em meses históricos o dashboard é o retrato fechado do mês (disponível,
  gastos, comparativo vs. mês anterior, fatura, donut) e o donut ocupa a largura do quadro de contas.
  Cobertura nova: `tests/Feature/Dashboard/DashboardCompetenciaTest.php`.
- **Registrar gasto — modal rápido (§7.7b): 🟢 implementado em Blade (dados fake).**
  Componente `x-modal.registrar-gasto` (`resources/views/components/modal/registrar-gasto.blade.php`),
  fiel à tela do Stitch "Registrar gasto — Crédito": mesmos estilos de campo (`.input-field`)
  e botões, prévia mono "calculada pelo sistema", chip de vencimento somente-leitura. Material
  Symbols (CDN) trocado por SVG inline no `x-icon` (regra 6/LGPD). Comportamento em
  `resources/js/pages/registrar-gasto.js` (vanilla, code-split): abrir pelo **FAB** do dashboard,
  troca de forma (crédito × à vista, com Cartão/Parcelas/prévia vs. vencimento editável +
  recorrência), seleção de categoria, **validação inline** (variação de erro em argila) e a
  **animação de salvando** (spinner + campos desabilitados). Afordância de revisão
  `?modal=aberto|erro|salvando` (mesmo padrão do `?estado` do dashboard).
- **Registrar gasto — ligação ao backend: 🟢 concluída (persiste no banco).** Borda web fina
  reusando o domínio já testado (`App\Domain\Gasto\RegistrarGastoManual`): `RegistrarGastoRequest`
  (validação + tradução form→`DadosGastoManual`, valor pt-BR→centavos, escopo por usuário),
  `GastoController@previa` (calcula sem gravar) e `@store` (grava), rotas `gastos.previa`/
  `gastos.store`. **Fluxo em dois passos (regra 7):** o formulário manda para a prévia → o backend
  calcula parcelas/vencimento/valor (regra 4, a tela nunca calcula) → o painel de confirmação mostra
  o resumo + tabela mono + aviso de duplicidade → só então grava (transaction + installments +
  auditoria, atômico). A home carrega os **cartões e categorias reais** do usuário no modal. Cobertura:
  `tests/Feature/Gasto/RegistrarGastoWebTest.php` (auth, validação, mapeamento, prévia sem persistir,
  gravação à vista e no crédito, isolamento por usuário, duplicidade). **Deferido (feature própria,
  com migration + TDD):** "data de pagamento" (marcar como pago) e recorrência — aceitos na UI, ainda
  não persistidos.
- **Registro histórico (Stitch):**
  Projeto Stitch `Caderno de Contas Sereno` (`projects/9921880157030532605`), design system
  `Caderno de Contas` (`assets/ea051d20127a4459af28c95eb27d2eb6`). Screen id
  `ac72979b888345569d88d18bfc8b974c` — "Modal Registrar Gasto - Crédito Parcelado", desktop.
  Estado principal: forma **crédito** revelando Cartão (`Nubank •••• 1234 — fecha dia 28`) +
  Parcelas 3 com **prévia mono** ("calculada pelo sistema"), chip somente-leitura "vence 5 de
  julho · calculado pelo cartão", data de pagamento opcional, switch "Repete todo mês?" e
  Categoria com selo **"sugerido"** no Mercado. Correção aplicada na 2ª iteração: seletor de
  forma de pagamento com as **5 formas distintas** (crédito · débito · pix · dinheiro · boleto),
  desfazendo o "Pix / Dinheiro" fundido e incluindo boleto. **Pendências para a ligação ao
  Blade:** trocar Material Symbols (CDN) por SVG inline (regra 6/LGPD, como no Dashboard);
  gerar as **variações de estado** ainda não desenhadas (fora-de-cartão com vencimento
  editável; crédito sem cartão cadastrado; recorrente ligado; validações inline; salvando).
- **Lançamentos — lista (§7.6): 🟡 gerada no Stitch (só a tela).** Desenho aprovado no Stitch;
  **falta a integração** ao Laravel (layout `x-layouts.app`, rota/controller, filtros server-side,
  dados reais). Pendências previsíveis na ligação: trocar Material Symbols (CDN) por SVG inline
  no `x-icon` (regra 6/LGPD, como no Dashboard/modal) e reusar o shell (sem redesenhar aside/header).
- **Próximo:** grupo B (núcleo financeiro) — §7.7 e §7.8 a §7.13. A ligação técnica das
  telas A, do Dashboard e da lista de lançamentos (§7.6) ao Laravel (Blade+Tailwind, rotas, auth,
  validação) fica para a etapa de implementação.
- **Decisão de arquitetura de UI:** o **shell (aside + header) é único** e reutilizado por
  todas as telas logadas; os prompts §7.5–§7.17 pedem **apenas o conteúdo** da área principal
  (ver nota do §5). Telas pré-login (§7.1–§7.4) não têm shell.
- **Decisão de design aplicada (§7.14, Chat financeiro):** o chat na web deixou de ser página
  cheia — e também **deixou de ser overlay/drawer**. Virou uma **coluna fixa, sempre aberta**, à
  direita: a **terceira coluna** de um layout de **três colunas** (nav · conteúdo · chat). Os três
  **coexistem** — o body reflui e fica **entre** a nav e o chat; nada é esmaecido, sem backdrop,
  a coluna **não cobre** o conteúdo (revoga a versão "painel docado sobre um app esmaecido"). O
  chat passa a ser a **terceira zona do shell** (`x-layouts.app`), presente em toda rota logada.
  **Responsividade:** "sempre aberta" vale no desktop; no mobile (≲1024px) a coluna **recolhe**
  para um lançador e abre como folha — colapso é regra do **layout Blade**, fora do prompt do
  Stitch. Prompt reescrito no estilo **fechado (anti-invenção)** do §7.7b, pedindo **apenas o
  conteúdo da coluna** (sem redesenhar o shell) e **sem** botão de fechar. O anexo é só a
  **afordância de entrada**: o PDF continua no fluxo de **revisão efêmera** (§7.16) e é descartado
  (regra 6); as telas dedicadas §7.15/§7.16 seguem no mini-TODO, geradas **após** o backend da
  [[spec-11-importacao-pdf]].
- **Decisão de autoria de prompt aplicada:** **TODOS os prompts de tela (§7.1–§7.17)** foram
  padronizados no formato **fechado (anti-invenção)** do §7.7b/§7.14 — blocos literais TEMA ·
  CONTEXTO (shell nas logadas §7.5–§7.17 / PRÉ-LOGIN sem navegação nas §7.1–§7.4) · PROIBIDO ·
  ESTRUTURA com todo texto entre aspas · variações de estado separadas · INVARIANTES. O prompt-base
  de tema §7.0 permanece como referência única (é dele que sai o bloco TEMA). Domínio conferido nos
  docs 03/04/08: 5 formas de pagamento, crédito = única em cartão, vencimento determinístico
  "calculado pelo cartão", datas compra/vencimento/pagamento separadas, status reais
  (em aberto/pago/agendado/vencido/cancelado), **orçamento por categoria é pós-MVP** (§7.11 mostra
  só consumo, sem limite por categoria), cartão por descrição + 4 dígitos (sem "bandeira"), PDF
  efêmero (§7.15/§7.16). Observação: §7.1–§7.6 já haviam sido geradas/implementadas antes desta
  conversão — a reescrita alinha o texto do spec (referência canônica), sem exigir regeração.
- **Decisão de design aplicada:** token `papel` ajustado `#F3F4EF` → `#EDF0E8` (§4.2 e §7.0)
  para o fundo ler claramente como verde-acinzentado e fugir do "cream" (default de IA).
- **Adiado para etapa técnica posterior:** ligação das telas ao Laravel (Inertia/Blade, dados
  reais, validação server-side) e implementação do *sender* do bot (formatação via §8).
- **Decisões de design tomadas:** conceito "caderno de contas"; assinatura "a régua do mês";
  dinheiro sempre em mono tabular; paleta verde-cédula/ocre/argila fugindo do azul de fintech
  e dos defaults de IA. *(preencher conforme gerar)*
