<div align="center">

# 💸 Gestão de Contas com IA

**Controle financeiro pessoal por linguagem natural — no Telegram, na web e com importação de faturas em PDF.**

O coração é um **domínio financeiro determinístico e auditável**. A IA interpreta, classifica e redige —
**nunca calcula dinheiro**.

<br/>

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?style=for-the-badge&logo=postgresql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=for-the-badge&logo=docker&logoColor=white)

![FrankenPHP](https://img.shields.io/badge/FrankenPHP-runtime-00ADD8?style=flat-square)
![Pest](https://img.shields.io/badge/Pest-TDD-5965E0?style=flat-square&logo=pest&logoColor=white)
![Laravel AI SDK](https://img.shields.io/badge/laravel%2Fai-Agents%20%7C%20Tools-FF2D20?style=flat-square)
![Tesseract](https://img.shields.io/badge/Tesseract-OCR-5C3EE8?style=flat-square)
![Swarm](https://img.shields.io/badge/Prod-Docker%20Swarm%20%2B%20Secrets-2496ED?style=flat-square)
![Status](https://img.shields.io/badge/Fase-F2%20Cadastro%20manual%20🟡-blue?style=flat-square)

</div>

---

## ✨ O que é

Em vez de formulários, você registra gastos conversando — *"paguei 35 conto de uber"* — importa a fatura do
cartão em PDF (com revisão) e consulta suas finanças por pergunta — *"quanto gastei com comida esse mês?"*.

A web e o bot são apenas **canais**. Toda regra de negócio vive no **motor financeiro**: um domínio
determinístico, modelado em **centavos inteiros (BIGINT)**, 100% coberto por testes.

> ### 🧠 Princípio inegociável
> **A IA nunca calcula dinheiro.** Valores, saldos, parcelas, vencimentos e o *"disponível do mês"* são
> calculados de forma determinística (SQL / motor financeiro). A IA só **interpreta, classifica e formata**
> respostas sobre números que o sistema já calculou — com um *guard pós-geração* que valida cada número.

---

## 🧱 Stack

| Camada | Tecnologia | Por quê |
|---|---|---|
| **Linguagem / framework** | PHP 8.3 · Laravel 12 | Produtividade, filas, testes, ecossistema maduro |
| **Banco** | PostgreSQL 16 | Precisão financeira, constraints fortes, `pgvector` se necessário |
| **Dinheiro** | `BIGINT` em centavos | Sem erro de ponto flutuante; formatação pt-BR só na borda |
| **Runtime HTTP** | FrankenPHP | Serve HTTP + PHP em um único processo (API + webhook do Telegram) |
| **Fila** | Driver `database` | Sem Redis no MVP; idempotência por *unique constraint* |
| **IA** | Laravel AI SDK (`laravel/ai`) | Agents, Tools, structured output, failover e *fakes* p/ testes |
| **OCR** | Tesseract (no worker) | Biblioteca embutida; não é contêiner no MVP |
| **Testes** | Pest 3 / PHPUnit | TDD obrigatório (test-first) |
| **Contêineres** | Docker Compose (3 serviços) | `app` · `worker` · `postgres` |
| **Produção** | Docker Swarm + Docker Secrets | Sem `.env`; segredos em `/run/secrets` |

**Fuso base:** `America/Sao_Paulo`.

---

## 🚀 Início rápido

> **Pré-requisito único no host:** Docker + `make`. **Nada mais** é instalado localmente — `php`, `composer`,
> `artisan`, `node` e os testes rodam todos dentro do contêiner.

```bash
# 1. Primeira instalação: cria o esqueleto Laravel via contêiner,
#    sobe os 3 serviços, instala deps, gera APP_KEY e migra.
make bootstrap

# 2. Confirme que está tudo no ar
make ps

# 3. Rode a suíte de testes
make test
```

Pronto. A aplicação responde em **http://localhost:8000** (health check em `/up`).

No dia a dia:

```bash
make up      # sobe os contêineres
make down    # derruba (mantém o volume do Postgres)
make logs    # acompanha os logs
```

---

## 🧪 Como testar (TDD)

O projeto é **test-first**: para cada feature, escreve-se o teste que **falha** antes da implementação.

```bash
make test                                    # roda a suíte inteira (php artisan test)
make pest                                    # roda o Pest diretamente
make artisan c="test --filter=Disponivel"    # um teste específico
make artisan c="test --coverage"             # com cobertura
```

Exemplo do estilo de teste do domínio financeiro (Pest):

```php
it('calcula o disponível do mês pela fórmula oficial', function () {
    // Receitas 5.000,00 − cartão vencendo no mês 1.200,00 − PIX do mês 300,00
    $disponivel = app(DisponivelDoMes::class)->para($user, '2026-06');

    expect($disponivel->cents())->toBe(350000); // R$ 3.500,00
});
```

> A camada de IA é testada com os **fakes da Laravel AI SDK** — determinístico, sem chamar provedor real.

---

## 🔁 Validar o pipeline localmente (antes do `git push`)

O deploy é automatizado por GitHub Actions (`.github/workflows/deploy.yml`):
**`test` (gate TDD) → `build` → `scan` (Trivy) → `deploy` (Swarm) → `smoke`**. Para não
descobrir falha só depois do push, dá para **reproduzir localmente os estágios que não
tocam produção**:

```bash
make ci-local      # pipeline inteiro sem produção: test + build + scan
make ci-test       # só o gate de teste
make ci-build      # só o build do target de produção
make ci-scan       # só o scan Trivy (HIGH/CRITICAL) da última imagem
```

**Por que não basta `make test`:** o `ci-*` roda contra uma **exportação limpa do git**
(`git archive`), igual ao `actions/checkout` do runner — **sem** `public/build`, `vendor`
nem `node_modules` (todos gitignored). Isso reproduz bugs que só aparecem no checkout
limpo (ex.: *"Vite manifest not found"*), que um `make test` na sua árvore de trabalho
mascararia. Inclui suas mudanças **em arquivos rastreados** ainda não commitadas; ignora
o que o git ignora.

> `deploy` e `smoke` tocam a produção e **não** rodam localmente — são promovidos só por
> você via `git push` (regra inviolável 1). Detalhes em [`scripts/ci-local.sh`](scripts/ci-local.sh).

---

## 🛠️ Comandos úteis (Makefile)

Todos os alvos encapsulam `docker compose` — nenhum comando roda no host.

| Comando | O que faz |
|---|---|
| `make help` | Lista todos os alvos disponíveis |
| `make bootstrap` | Primeira instalação (esqueleto + up + migrate) |
| `make up` / `make down` | Sobe / derruba os contêineres |
| `make stop` / `make restart` | Para / reinicia sem remover |
| `make build` / `make rebuild` | Builda a imagem (com / sem cache) |
| `make ps` | Status dos contêineres |
| `make logs` · `make logs-app` · `make logs-worker` | Logs (segue) |
| `make shell` | Bash no contêiner `app` |
| `make worker-shell` | Bash no contêiner `worker` |
| `make test` | Roda a suíte de testes |
| `make pest` | Roda o Pest |
| `make ci-local` | Reproduz o pipeline (test + build + scan) contra árvore limpa do git |
| `make ci-test` · `make ci-build` · `make ci-scan` | Estágios individuais do CI local |
| `make migrate` · `make fresh` · `make seed` | Migrations / recriar do zero / seeds |
| `make key` | Gera a `APP_KEY` |
| `make tinker` | Abre o Tinker |
| `make artisan c="..."` | Qualquer comando `artisan` |
| `make composer c="..."` | Qualquer comando `composer` |

---

## 🐳 Arquitetura de contêineres

```
┌─────────────────────────────────────────────────────────────┐
│  MVP — docker-compose.yml (3 contêineres)                    │
│                                                              │
│   app        FrankenPHP · HTTP (API + webhook Telegram)      │
│   worker     queue:work + schedule:work · Tesseract (OCR)    │
│   postgres   banco + backend da fila (driver database)       │
└─────────────────────────────────────────────────────────────┘
                              │  escala sem reescrita (por env/perfil)
                              ▼
   Redis + Horizon · réplicas do app · bot dedicado · Prometheus/Grafana
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  PRODUÇÃO — docker-stack.yml (Docker Swarm)                  │
│  SEM .env · segredos em /run/secrets (Docker Secrets)        │
└─────────────────────────────────────────────────────────────┘
```

**Configuração & segredos:**
- **Dev:** `.env` (não versionado) + `.env.example`.
- **Produção:** sem `.env`. Chaves (DB, IA, Telegram, `APP_KEY`) vêm de **Docker Secrets**, lidas pelo
  *entrypoint* via padrão `*_FILE` apontando para `/run/secrets/<nome>`.

---

## 📂 Estrutura do projeto

```
.
├── app/ routes/ config/ database/ tests/   # esqueleto Laravel 12
├── docker/
│   ├── Dockerfile         # imagem app+worker (FrankenPHP + Tesseract)
│   ├── Caddyfile          # config do servidor HTTP
│   └── entrypoint.sh      # resolve segredos *_FILE antes do boot
├── docker-compose.yml     # dev: app, worker, postgres
├── docker-stack.yml       # prod: Swarm + Docker Secrets
├── Makefile               # alvos finos que encapsulam o Docker
├── scripts/bootstrap.sh   # cria tudo por contêiner (idempotente)
├── docs/                  # escopo destrinchado (00 → 11, ROADMAP, TODO)
├── CLAUDE.md              # regras invioláveis + fluxo de trabalho
└── .claude/skills/        # skill-creator · laravel-backend · devops
```

---

## 📚 Documentação

Comece por [`docs/00-visao-geral.md`](docs/00-visao-geral.md). Destaques:

| Doc | Conteúdo |
|---|---|
| [`01-decisoes-estruturais`](docs/01-decisoes-estruturais.md) | Stack e fundações |
| [`02-governanca-ia`](docs/02-governanca-ia.md) | Determinismo e barreiras anti-alucinação |
| [`03-regras-financeiras`](docs/03-regras-financeiras.md) | Parcelas, vencimentos, disponível do mês |
| [`04-modelo-dados`](docs/04-modelo-dados.md) | Entidades |
| [`05-arquitetura`](docs/05-arquitetura.md) | Serviços e fluxos |
| [`06-telegram`](docs/06-telegram.md) · [`07-importacao-pdf`](docs/07-importacao-pdf.md) · [`08-categorias`](docs/08-categorias.md) | Canais e pipelines |
| [`09-nfr-seguranca-lgpd`](docs/09-nfr-seguranca-lgpd.md) · [`10-estrategia-testes`](docs/10-estrategia-testes.md) · [`11-devops`](docs/11-devops.md) | NFRs, testes, infra |
| [`ROADMAP-MVP`](docs/ROADMAP-MVP.md) · [`TODO`](docs/TODO.md) | Fases F0–F9 (backend-first) |

---

## 🗺️ Roadmap (resumo)

| Fase | Entrega | Status |
|---|---|---|
| **F0** | Bootstrap DevOps (compose, esqueleto, fila, Makefile) | ✅ **Pronto** |
| **F1** | Domínio financeiro + testes (parcelas, vencimentos, disponível, duplicidade) | ✅ **Pronto** |
| **F2** | Cadastro manual de gastos (status, origem, auditoria) | 🟡 **em andamento** (registro, categorias e lookup prontos; falta editar/cancelar) |
| **F3** | Receitas · orçamento mensal geral · consumo por categoria | ✅ **Pronto** (alerta por categoria adiado p/ pós-MVP) |
| **F4–F6** | Telegram (vínculo, auth) · interpretação por IA · correção por conversa | ⬜ |
| **F7–F9** | Importação de PDF (Itaú) · chat financeiro · dashboard | 🟡 **em andamento** (tools de consulta com escopo por usuário: gastos, disponível do mês e próximas contas prontas; falta fatura do cartão + guard pós-geração) |

> **Frontend é sempre etapa separada** — mensagens do bot e telas web nunca são construídas junto com a
> feature de backend correspondente.

---

## 🔒 Identificadores nas URLs

**Regra inegociável: nenhum id real de recurso aparece em claro numa URL.** Todo
identificador que sairia num parâmetro — seja no _path_ de uma rota
(`/lancamentos/{id}`), seja num valor de filtro na _query string_
(`?categoria={id}`) — é **sempre criptografado** com a `APP_KEY` e só existe em claro
dentro do servidor. Nunca use o valor real num parâmetro de URL.

**Como funciona**

- [`App\Domain\Shared\OpaqueId`](app/Domain/Shared/OpaqueId.php) — value object que
  `encode(int)` → token opaco e `decode(string)` → id (ou `null`). Usa `Crypt`
  (AES keyed pela `APP_KEY`), com **IV aleatório** (o mesmo id gera tokens diferentes
  a cada render — não dá para enumerar/correlacionar recursos pela URL) e saída
  **base64 URL-safe** (`[A-Za-z0-9_-]`, sem `+ / =`). Sem TTL: o link continua válido.
- [`App\Models\Concerns\HasOpaqueRouteId`](app/Models/Concerns/HasOpaqueRouteId.php) —
  aplicado a `Transaction`, `Category`, `Card`. Sobrescreve `getRouteKey()` (então
  `route('...', $model)` já emite o token) e expõe `opaqueId()` para usos fora de rota
  (ex.: `value` de `<option>` no filtro).
- **Decodificação na borda:** o parâmetro de rota `{transaction}` é resolvido por
  `Route::bind` (em [`AppServiceProvider`](app/Providers/AppServiceProvider.php)) — token
  inválido **ou id em claro** ⇒ **404**. Os filtros da query são decodificados no
  controller ([`LancamentoController`](app/Http/Controllers/LancamentoController.php));
  id em claro é simplesmente ignorado. O **escopo por usuário** continua no
  controller/domínio (`findOrFail` por `user_id`).

> Consequência de segurança: rotas antigas enumeráveis (`/lancamentos/123`) deixam de
> existir — só o token abre a tela. Sempre gere links passando o **model**
> (`route('lancamentos.show', $tx)`) ou `OpaqueId::encode($id)`; **nunca** o id cru.
> Não vaza em log/URL, e não há IDOR por adivinhação de id sequencial.

**O que NÃO é criptografado** (não são ids de recurso): período (`?mes=YYYY-MM`), busca
livre (`?busca=`), forma/status (enums) e a afordância de revisão `?estado=`.

---

## 📐 Regras invioláveis

1. **Nunca `git push`** sem ordem explícita (commits locais apenas).
2. **Test-first (TDD)** — testes que falham antes de qualquer código de feature.
3. **Frontend é etapa separada** do backend.
4. **A IA nunca calcula dinheiro** (cálculo determinístico + guard pós-geração).
5. **Dinheiro em centavos inteiros**; fuso `America/Sao_Paulo`.
6. **PDF/texto de fatura nunca são persistidos** (processamento efêmero, sem dados sensíveis).
7. **Confirmação antes de persistir** em todo registro/edição no MVP.
8. **Toda IA via Laravel AI SDK** (`laravel/ai`).
9. **Tudo roda em contêiner** — nada no host além de Docker + `make`.
10. **Produção = Docker Swarm + Docker Secrets**, sem `.env`.

Detalhes completos em [`CLAUDE.md`](CLAUDE.md).

---

<div align="center">
<sub>Projeto pessoal · backend-first · TDD · IA determinística por design.</sub>
</div>
