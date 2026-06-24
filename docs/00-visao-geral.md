# 00 · Visão Geral do Produto

> **Projeto:** Gestão de Contas Pessoais com Telegram e IA
> **Stack:** Laravel 12 · PHP 8.3 · PostgreSQL 16 · Docker
> _Ver seções 0, 1, 13 e 14 do escopo._

## O que é

Produto que reduz o atrito do controle financeiro pessoal. Em vez de formulários, o
usuário registra gastos por **linguagem natural**, importa **faturas em PDF** (com
revisão) e consulta as finanças **por conversa**. A interface web e o bot são apenas
*canais*; o coração do sistema é um **domínio financeiro estruturado, determinístico e
auditável**.

## Princípio inegociável

> **A IA nunca calcula dinheiro.**

Toda informação monetária — valores, saldos, parcelas, vencimentos, "disponível do mês"
— é calculada de forma **determinística** (SQL / motor financeiro testado). A IA apenas
**interpreta, classifica e formata** respostas sobre números que o sistema já calculou.

## Os 3 (e somente 3) papéis da IA

| Papel | Entrada | Saída | Calcula dinheiro? |
|---|---|---|---|
| **Classificar intenção** | Texto do usuário | registrar / consultar / editar / cancelar / importar | Não |
| **Extrair campos** | Texto do usuário | JSON estruturado validado por schema (tool use) | Não |
| **Redigir resposta** | Dados já calculados pelo motor financeiro | Texto em linguagem natural | Não — só formata |

_Detalhes em `docs/02-governanca-ia.md` (seção 3 do escopo)._

## Canais

- **Telegram** — alternativa rápida; entrada por texto livre + comandos; confirma antes
  de persistir; respostas curtas, sem botões.
- **Web** — revisão detalhada, edição em lote e dashboard.

O **backend é agnóstico de canal**: toda regra vive no domínio; os canais são bordas
finas. Isso prepara WhatsApp e outros canais futuros sem tocar na regra.

## MVP (resumido)

**Entra no MVP:** autenticação básica e usuário individual; cadastro manual de gastos;
Telegram com confirmação; categorias básicas; cartões; parcelamento; status de
pagamento; receitas; orçamento mensal geral + alerta; dashboard simples; consulta por IA
sobre dados estruturados; importação de PDF (Itaú) com revisão; prevenção de
duplicidade; auditoria de origem.

**Fica para depois (pós-MVP):** WhatsApp; OCR avançado; áudio; imagem de comprovante;
multiusuário/família; recomendações financeiras; orçamento por categoria; exportação;
conciliação bancária; metas; subcategorias; RAG documental.

_Roadmap completo em `docs/ROADMAP-MVP.md` e `docs/ROADMAP-POS-MVP.md`._

## Regra de processo (vale para todo o roadmap)

1. **Nada é implementado antes dos testes (TDD).** Para cada feature: testes que falham
   → implementar até passarem.
2. **Frontend nunca é construído junto com o backend.** Backend (domínio + testes +
   API/handlers) primeiro; apresentação (mensagens do bot / telas web) é tarefa
   separada e posterior.

## Ordem de leitura recomendada

Conforme o escopo (seção 0): comece por **decisões estruturais** e **governança de IA**,
pois destravam todo o resto; depois regras e dados; por fim MVP, roadmap e TODO.

1. `docs/01-decisoes-estruturais.md`
2. `docs/02-governanca-ia.md`
3. `docs/03-regras-financeiras.md`
4. `docs/04-modelo-dados.md`
5. `docs/05-arquitetura.md`

## Índice das docs

| Doc | Conteúdo | Seção do escopo |
|---|---|---|
| [`00-visao-geral.md`](00-visao-geral.md) | Este documento | 0, 1, 13, 14 |
| [`01-decisoes-estruturais.md`](01-decisoes-estruturais.md) | Stack e fundações | 2 |
| [`02-governanca-ia.md`](02-governanca-ia.md) | Determinismo e anti-alucinação | 3 |
| [`03-regras-financeiras.md`](03-regras-financeiras.md) | Parcelas, vencimentos, disponível | 4 |
| [`04-modelo-dados.md`](04-modelo-dados.md) | Entidades principais | 5 |
| [`05-arquitetura.md`](05-arquitetura.md) | Backend e serviços | 6 |
| [`06-telegram.md`](06-telegram.md) | Integração com Telegram | 7 |
| [`07-importacao-pdf.md`](07-importacao-pdf.md) | Pipeline de faturas | 8 |
| [`08-categorias.md`](08-categorias.md) | Classificação | 9 |
| [`09-nfr-seguranca-lgpd.md`](09-nfr-seguranca-lgpd.md) | NFRs, segurança e LGPD | 10 |
| [`10-estrategia-testes.md`](10-estrategia-testes.md) | Estratégia de testes (TDD) | 11 |
| [`11-devops.md`](11-devops.md) | DevOps e infraestrutura | 12 |
| [`ROADMAP-MVP.md`](ROADMAP-MVP.md) | Fases do MVP (backend-first) | 14 |
| [`ROADMAP-POS-MVP.md`](ROADMAP-POS-MVP.md) | Evolução pós-MVP | 15 |
| [`TODO.md`](TODO.md) | Sequência prática de desenvolvimento | 18 |
| [`GLOSSARIO.md`](GLOSSARIO.md) | Termos do produto | 19 |
