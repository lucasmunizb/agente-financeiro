# CLAUDE.md — Orientação permanente da IA

Projeto: **Gestão de Contas Pessoais com Telegram e IA**.
Stack: **Laravel 12 · PHP 8.3 · PostgreSQL 16 · Docker**.

A **fonte de verdade** do escopo é `gestao_contas_ia_ESCOPO_FINAL.docx`, destrinchado em
[`/docs`](docs/). Em qualquer dúvida de regra de negócio, o escopo prevalece sobre
suposições. **Não invente regra financeira:** se faltar, pergunte ou cite a seção do
documento.

---

## 1. Regras invioláveis (NUNCA quebre)

1. **NUNCA `git push`** — nem qualquer operação em repositório remoto (push, force-push,
   criar/alterar remote, abrir PR, deletar branch remota). Commits **locais** são
   permitidos. Qualquer ação que toque o remoto exige ordem explícita do usuário, por
   escrito, naquele momento.
2. **Test-first (TDD) é obrigatório.** Nenhum código de feature antes dos testes. Para
   cada feature: escreva primeiro os testes (unitários e de contrato quando aplicável)
   que **falham**, depois implemente até passarem, garantindo cobertura. Nunca
   implemente a feature e "depois" os testes.
3. **Frontend é sempre etapa separada.** As mensagens formatadas do bot **e** as telas do
   webapp **nunca** são construídas junto com a feature de backend correspondente.
   Backend (domínio + testes + API/handlers) primeiro; a apresentação vem depois, como
   tarefa/commit separado.
4. **A IA nunca calcula dinheiro.** Todo valor monetário (valores, saldos, parcelas,
   vencimentos, "disponível do mês") é calculado de forma **determinística** (motor
   financeiro testado / SQL). A IA apenas interpreta, classifica e redige respostas sobre
   números já calculados. Aplique as barreiras anti-alucinação (ver
   [`docs/02-governanca-ia.md`](docs/02-governanca-ia.md)).
5. **Dinheiro em centavos inteiros (BIGINT).** Nunca float. Formatação pt-BR só na borda.
   Fuso base **America/Sao_Paulo**.
6. **PDF e texto extraído NUNCA são persistidos.** Processamento efêmero; descartar ao
   final. Nenhum dado sensível (nome, endereço, CPF, nascimento) é armazenado.
7. **Confirmação antes de persistir** em todo registro/edição no MVP.
8. **Toda a IA é implementada pela Laravel AI SDK (`laravel/ai`).** Nada de cliente HTTP
   próprio para provedores. Use os recursos nativos: Agents (`make:agent`), Tools
   (`make:tool`), `HasStructuredOutput`, `RemembersConversations`, failover entre
   provedores (array de provedores / enum `Lab`), filas (`queue()`) e os **fakes** da SDK
   nos testes. O guard determinístico (IA nunca calcula dinheiro) é camada **nossa** por
   cima da SDK.
9. **Tudo roda em contêiner — nada é instalado localmente.** Único pré-requisito local:
   Docker + `make`. `composer`, `artisan`, `php`, `node`, testes, migrations: **todos**
   via `docker compose exec`/`run`, encapsulados em alvos do `Makefile`. Inclusive a
   criação do esqueleto é por contêiner. Nunca instrua o usuário a rodar php/composer/node
   no host.
10. **Produção é Docker Swarm com Docker Secrets — SEM `.env`.** `.env` existe apenas em
    desenvolvimento (não versionado) + `.env.example`. Em produção, as chaves (DB,
    provedores de IA, token do Telegram, `APP_KEY`) vêm de **Docker Secrets** montados em
    `/run/secrets/`, com padrão `*_FILE`. O esqueleto suporta os dois modos.

### Lembretes adicionais
- **RAG documental está fora do escopo.** Não retemos documentos; não crie infra vetorial
  no MVP (ver [`docs/ROADMAP-POS-MVP.md`](docs/ROADMAP-POS-MVP.md), veredito de RAG).
- **Auto-save** só é habilitado quando a acurácia móvel por intenção atingir **≥95% nas
  últimas 100 interações**. Até lá, **sempre confirmar**.

---

## 2. Fluxo de trabalho por feature (TDD + backend-first)

Para **cada** feature, anuncie antes de codar:
- (a) os testes que vai escrever;
- (b) o que será **backend agora**;
- (c) o que fica para a **etapa de frontend depois**.

Ordem obrigatória (ver [`docs/10-estrategia-testes.md`](docs/10-estrategia-testes.md)):

1. Especificar comportamento e critérios de aceite (Given-When-Then).
2. Escrever testes unitários do domínio → **devem falhar**.
3. Implementar o domínio até passarem.
4. Escrever testes de contrato/integração da API/handler → **devem falhar**.
5. Implementar a borda até passarem.
6. **Só então**, em etapa separada e posterior: a apresentação (resposta do bot / tela web).

Pare e confirme com o usuário nos pontos de decisão.

---

## 3. Convenções de execução (contêiner)

Nunca rode ferramentas no host. Use o `Makefile`:

| Ação | Comando |
|---|---|
| Subir ambiente | `make up` |
| Derrubar | `make down` |
| Rodar testes | `make test` → `docker compose exec app php artisan test` |
| Migrations | `make migrate` |
| Shell no app | `make shell` → `docker compose exec app bash` |
| Worker | `make worker` |
| Artisan/Composer arbitrário | `make artisan ...` / `make composer ...` |

Runtime do MVP = **3 contêineres**: `app` (HTTP: API + webhook Telegram), `worker`
(`queue:work` + `schedule:work`, Tesseract embutido), `postgres` (banco + fila driver
`database`). Detalhes em [`docs/11-devops.md`](docs/11-devops.md).

---

## 4. Convenções de commit (locais apenas)

- Commits **locais**, pequenos e atômicos. **Nunca push.**
- Separe commit de **backend** do commit de **frontend** (regra inviolável 3).
- Mensagens no imperativo, em português, referenciando a fase/bloco do roadmap quando útil
  (ex.: `F1: motor de parcelas + testes`).
- Não commite segredos, `.env`, PDFs ou texto extraído de faturas.

---

## 5. Índice

### Documentação (`/docs`)
- [`00-visao-geral.md`](docs/00-visao-geral.md) — visão do produto e índice
- [`01-decisoes-estruturais.md`](docs/01-decisoes-estruturais.md) — stack e fundações
- [`02-governanca-ia.md`](docs/02-governanca-ia.md) — determinismo e anti-alucinação
- [`03-regras-financeiras.md`](docs/03-regras-financeiras.md) — parcelas, vencimentos, disponível
- [`04-modelo-dados.md`](docs/04-modelo-dados.md) — entidades
- [`05-arquitetura.md`](docs/05-arquitetura.md) — serviços e fluxos
- [`06-telegram.md`](docs/06-telegram.md) — vínculo seguro e bot
- [`07-importacao-pdf.md`](docs/07-importacao-pdf.md) — pipeline efêmero de faturas
- [`08-categorias.md`](docs/08-categorias.md) — lookup determinístico
- [`09-nfr-seguranca-lgpd.md`](docs/09-nfr-seguranca-lgpd.md) — NFRs, segurança, LGPD
- [`10-estrategia-testes.md`](docs/10-estrategia-testes.md) — TDD e separação BE/FE
- [`11-devops.md`](docs/11-devops.md) — infra, contêiner, Swarm, secrets
- [`ROADMAP-MVP.md`](docs/ROADMAP-MVP.md) · [`ROADMAP-POS-MVP.md`](docs/ROADMAP-POS-MVP.md) · [`TODO.md`](docs/TODO.md) · [`GLOSSARIO.md`](docs/GLOSSARIO.md)

### Skills (`.claude/skills`)
- **`skill-creator`** — como autorar novas skills com qualidade (reforça as regras invioláveis).
- **`laravel-backend`** — convenções de backend deste projeto (TDD, domínio financeiro, IA via SDK).
- **`dba-postgres`** — modelagem e operação do schema PostgreSQL 16 (tipos, FKs, índices, constraints, migrations); atua em par com `laravel-backend`.
- **`devops`** — provisionar/operar a infra (compose, Makefile, Swarm + Secrets, escala).

Skills adiadas (criar depois com a `skill-creator`): `governanca-ia`, `telegram`,
`importacao-pdf` e as de frontend.
