# Roadmap do MVP (backend-first)

> Fonte de verdade: seções 13 e 14 do escopo final.
>
> **Cada fase tem um spec** (ponto de partida para implementar test-first) em
> [`docs/specs/`](specs/README.md). A numeração dos specs segue os Blocos do
> [`TODO.md`](TODO.md).

## Lembrete de processo (vale para todo o roadmap)

1. **Cada fase começa pelos testes (TDD)** e entrega **só o backend**. Nada é implementado antes dos testes que falham; implementa-se até passarem, com cobertura.
2. **Frontend nunca é acoplado** à feature de backend. As mensagens formatadas do bot e as telas do webapp são **etapas separadas e posteriores** (fase FE), construídas só depois que o backend correspondente estiver testado e pronto.

## Fases (F0–F10 + FE)

| Fase | Entrega | Pré-requisito | Critério de conclusão |
| --- | --- | --- | --- |
| **F0** | Bootstrap DevOps: docker compose (app, worker, postgres), esqueleto Laravel, fila em `database`, Makefile, logs estruturados. | — | Ambiente sobe com `make up`. |
| **F1** | Modelo financeiro + testes (parcelas, vencimentos, disponível, duplicidade). | F0 | Suite verde no domínio. |
| **F2** | Cadastro manual de gastos (backend + testes). | F1 | Gasto manual completo e auditado. |
| **F3** | Receitas + orçamento mensal + alerta (backend + testes). | F2 | Disponível calculado corretamente. |
| **F4** | Telegram: vínculo, autenticação, recepção (backend + testes). | F0 | Mensagem recebida com usuário identificado. |
| **F5** | Interpretação de mensagem (IA: intenção + extração) com confirmação. | F4 | Cadastro via Telegram validado por testes. |
| **F6** | Correção por conversa. | F5 | Usuário corrige sem web. |
| **F7** | Chat financeiro de consulta + guard determinístico. | F1, F3 | Respostas só com dados consultados. |
| **F8** | Dashboard (dados agregados). | F2, F3 | Agregações do mês disponíveis. |
| **F9** | Importação de PDF (Itaú): pré-importação, extração, descarte, duplicidade. **Movida para o fim** (alto valor/alto risco): entregue após todas as demais features. | F1 | Itens extraídos para revisão; PDF descartado. |
| **F10** | **Segurança e LGPD — portão de fechamento** (transversal): code review de segurança, pen test e testes adversariais de prompt (injeção/jailbreak/exfiltração). | todas | Suíte adversarial verde; pen test sem achado crítico; LGPD verificada. |
| **FE** | Frontends (web + formatação do bot) — **consolidados** em um único spec orientado ao Stitch (um prompt por tela + design system): [`docs/specs/FE-frontend-stitch.md`](specs/FE-frontend-stitch.md). Executada **após** o backend das features (F1–F8) e **antes** da importação de PDF (F9); as telas da F9 são geradas após o backend dela. | Backend das features pronto | Telas geradas no Stitch a partir dos prompts. |

## MVP em 10 etapas (backend vs. frontend)

Cada etapa de backend é independente da sua apresentação. A coluna **Frontend** só é construída **depois** que o backend correspondente estiver testado e pronto.

| # | Backend (domínio + testes + API) | Frontend (etapa separada e posterior) |
| --- | --- | --- |
| 1 | Modelo financeiro: cartões, contas, formas de pagamento, status, categorias, parcelas, vencimentos, disponível. | — |
| 2 | Cadastro manual de gastos (CRUD, status, origem, auditoria). | Telas web de cadastro/lista. |
| 3 | Receitas (base do disponível). | Tela web de receitas. |
| 4 | Orçamento mensal geral + alerta por categoria. | Tela web de orçamento. |
| 5 | Vínculo e autenticação do Telegram. | Mensagens do bot de vínculo. |
| 6 | Interpretação de mensagem (intenção + extração estruturada) com confirmação. | Respostas/confirmações formatadas no bot. |
| 7 | Correção/cancelamento por conversa. | Fluxo de correção no bot. |
| 8 | Chat financeiro: ferramentas de consulta + guard determinístico. | Apresentação das respostas (web/bot). |
| 9 | Dashboard: agregações do mês (dados). | Telas e gráficos do dashboard. |
| 10 | Importação de PDF: pré-importação, extração, descarte, duplicidade. **(Reordenada para última)** | Tela web de revisão + resumo no bot. |

## Entra no MVP

Autenticação básica e usuário individual; cadastro manual; Telegram com confirmação; categorias básicas; cartões; parcelamento; status; receitas; orçamento mensal geral + alerta; dashboard simples; consulta por IA sobre dados estruturados; importação de PDF (Itaú) com revisão; prevenção de duplicidade; auditoria de origem.

## Fica para depois (pós-MVP)

WhatsApp; OCR avançado; áudio; imagem de comprovante; multiusuário/família; recomendações financeiras; orçamento por categoria; exportação; conciliação bancária; metas; subcategorias; RAG documental. (Detalhes e ordem em `ROADMAP-POS-MVP.md`.)
