# Specs — desenvolvimento orientado a especificação

Este diretório é a **camada acionável** do projeto: cada etapa (Bloco do roadmap) tem um
**spec autocontido** que serve de **ponto de partida** para implementá-la. O fluxo é
**spec → testes que falham → implementação → testes verdes → (depois) frontend**.

> Os specs **não substituem** os docs de referência em [`/docs`](../) — eles **apontam**
> para eles. Os docs `00`–`11` continuam a **fonte de verdade** detalhada (regras,
> modelo de dados, governança de IA, DevOps); o escopo final (`.docx`) prevalece sobre
> tudo. O spec é o "o quê + como começar"; o doc é o "porquê + a regra completa".

## Como trabalhar a partir de um spec

1. Abra o spec da etapa e leia **§3 Cenários** (Given-When-Then) e **§4 Barreiras**.
2. Escreva os testes de **§7** — eles **devem falhar** (regra inviolável 2, TDD).
3. Implemente o domínio até passarem; depois a borda (handler/API/agent).
4. **Só então**, em etapa/commit separado, o frontend de **§8** (regra inviolável 3).
5. Ao concluir, atualize **§10 Estado atual** e o status na tabela abaixo.

Modelo para criar/editar specs: [`_TEMPLATE.md`](_TEMPLATE.md).

## Mapa das etapas

> Numeração alinhada aos **Blocos** do [`ROADMAP-MVP.md`](../ROADMAP-MVP.md) e
> [`TODO.md`](../TODO.md). A **Importação de PDF** foi deliberadamente movida para o
> **fim** (alto valor / alto risco).

| # | Spec | Status | Depende de | Doc de referência |
|---|---|---|---|---|
| 00 | [Fundações e DevOps](00-fundacoes-devops.md) | ✅ | — | [`11-devops.md`](../11-devops.md) |
| 01 | [Domínio financeiro](01-dominio-financeiro.md) | ✅ | 00 | [`03-regras-financeiras.md`](../03-regras-financeiras.md) |
| 02 | [Cadastro manual + receitas + orçamento](02-cadastro-manual-receitas.md) | ✅ | 01 | [`08-categorias.md`](../08-categorias.md) |
| 03 | [Telegram (vínculo, webhook, roteamento)](03-telegram.md) | ✅ | 00 | [`06-telegram.md`](../06-telegram.md) |
| 04 | [IA de interpretação (Laravel AI SDK)](04-ia-interpretacao.md) | ✅ | 01, 02, 03 | [`02-governanca-ia.md`](../02-governanca-ia.md) |
| 04b | [Confirmação de gasto via bot (fecha o "sim/não")](04b-confirmacao-gasto-bot.md) | ✅ | 02, 03, 04 | [`06-telegram.md`](../06-telegram.md) |
| 04c | [Rotação de provedores de IA (fila LRU + cooldown)](04c-rotacao-provedores-ia.md) | ✅ | 04 | [`02-governanca-ia.md`](../02-governanca-ia.md) |
| 05 | [Chat financeiro (tools + guard)](05-chat-financeiro.md) | ✅ | 01, 03, 04 | [`02-governanca-ia.md`](../02-governanca-ia.md) |
| 06 | [Dashboard (agregações do mês)](06-dashboard.md) | ✅ | 02, 03 | [`05-arquitetura.md`](../05-arquitetura.md) |
| **FE** | [**Frontend (Stitch) — todas as telas**](FE-frontend-stitch.md) | ⬜ | 02–06 | [`05-arquitetura.md`](../05-arquitetura.md) · [`06-telegram.md`](../06-telegram.md) |
| 07 | [Importação de PDF (Itaú) — última feature](07-importacao-pdf.md) | 🟡 | 01 | [`07-importacao-pdf.md`](../07-importacao-pdf.md) |
| 08 | [Segurança e LGPD — portão de fechamento](08-seguranca-lgpd.md) | ⬜ | todas | [`09-nfr-seguranca-lgpd.md`](../09-nfr-seguranca-lgpd.md) |
| 09 | [Faturas materializadas (ciclo de fatura)](09-faturas-materializadas.md) | 🟠 | 01, 02 | [`04-modelo-dados.md`](../04-modelo-dados.md) (`invoices`) |
| 10 | [Recorrência mensal (assinaturas/contas fixas)](10-recorrencia-mensal.md) | 🟡 (backend ✅ · frontend adiado) | 02, 04b | [`03-regras-financeiras.md`](../03-regras-financeiras.md) §4.6 · [`04-modelo-dados.md`](../04-modelo-dados.md) (`recurrences`) |

**Legenda:** ✅ concluído · 🟡 em andamento · ⬜ planejado · 🟠 pendente (proposta a validar — não iniciar).

> **Spec 09** nasce **pendente**: registra uma **proposta** de materializar a fatura (hoje
> derivada). Antes de escrever testes/feature é preciso fechar as **Questões em aberto (§4b)**
> do próprio spec.

> A **fase FE** consolida **todo o frontend** adiado das etapas anteriores (regra 3) em um
> único spec orientado ao **Stitch** — um prompt por tela + design system comum. Roda
> **depois** do backend das features (00–06) e **antes** da importação de PDF (07); as telas
> da 07 ficam no mini-TODO da FE marcadas para gerar **após** o backend da 07.

> O **spec 08** é **transversal**: não é uma feature, e sim o **portão de fechamento** —
> code review de segurança, pen test e testes adversariais de prompt. Roda **depois** das
> features e **antes** de declarar o MVP entregue. Acione a skill `seguranca-ia` ao executá-lo.

## Regras que todo spec herda

As **10 regras invioláveis** do [`CLAUDE.md`](../../CLAUDE.md) valem para todos os specs.
As mais citadas: **(2)** test-first; **(3)** frontend é etapa separada; **(4)** a IA nunca
calcula dinheiro; **(5)** centavos inteiros (BIGINT), fuso America/Sao_Paulo; **(6)** PDF e
texto extraído nunca persistidos; **(7)** confirmar antes de gravar; **(8)** toda IA via
Laravel AI SDK; **(1)** nunca push.
