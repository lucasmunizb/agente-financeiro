# Spec 00 — Fundações e DevOps

> **Como usar este spec.** É o **ponto de partida** da etapa: leia, confirme os
> critérios e implemente **test-first** (regra inviolável 2), **backend antes do
> frontend** (regra 3). Em qualquer dúvida de regra, o **escopo final** e os
> `docs/` de referência **prevalecem** sobre suposições — não invente regra financeira.
>
> Um spec é "vivo": ao concluir, marque o status, preencha **§10 Estado atual** com os
> artefatos reais (arquivos, comandos) e registre as decisões que você tomou.

| Campo | Valor |
|---|---|
| **Bloco · Fase** | Bloco 0 · F0 |
| **Status** | ✅ Concluído |
| **Depende de** | — |
| **Habilita** | [[spec-01]] · [[spec-02]] · [[spec-03]] · [[spec-04]] · [[spec-05]] · [[spec-06]] · [[spec-11]] |
| **Fonte de verdade** | seção 12 do escopo · [`docs/11-devops.md`](../11-devops.md) · [`docs/01-decisoes-estruturais.md`](../01-decisoes-estruturais.md) |
| **Regras críticas** | 9 (tudo em contêiner) · 10 (produção com Secrets, sem `.env`) |

---

## 1. Objetivo
Entregar o ambiente **100% conteinerizado** do projeto — `app`, `worker` e `postgres` —
com fila no driver `database`, `Makefile` encapsulando todo o `docker compose` (nada
instalado no host além de Docker + `make`) e o perfil de **produção em Docker Swarm com
Docker Secrets**, sem `.env`. É a fundação sobre a qual todas as demais etapas são
construídas.

## 2. Escopo
- **Inclui (backend/infra desta etapa):**
  - `docker compose` de desenvolvimento com 3 serviços: `app` (HTTP via FrankenPHP),
    `worker` (`queue:work` + `schedule:work`) e `postgres` (banco + backend da fila).
  - `Dockerfile` multi-stage (`base` para dev com bind-mount; `prod` com código copiado e
    autoload otimizado), imagem única para `app` e `worker`, Tesseract (pt) embutido.
  - `Makefile` com alvos finos (`up`, `down`, `test`, `migrate`, `shell`, `logs`, …),
    cada um chamando `docker compose exec/run`.
  - Entrypoint compartilhado que resolve segredos `*_FILE` de `/run/secrets/<nome>` e
    garante diretórios graváveis antes do boot.
  - `docker-stack.yml` (Swarm) com Docker Secrets externos, réplicas e `restart_policy`.
  - `.env.example` (dev) versionado; `.env` real **nunca** versionado.
  - Fila no driver `database`; healthchecks por serviço; volume nomeado do Postgres.
- **Não inclui (outro spec / frontend / pós-MVP):**
  - `ai_usage_log` e logs estruturados de IA → [[spec-04]] (Bloco 4).
  - Domínio financeiro, models e migrations de negócio → [[spec-01]] em diante.
  - Redis/Horizon, nginx/Octane dedicado, observabilidade Prometheus/Grafana, OCR como
    serviço próprio → caminho de escala (documentado, **não** implementado agora).

## 3. Cenários de aceite (Given-When-Then)

- **C1 — `make up` sobe os 3 contêineres.** **Dado** um host com apenas Docker + `make` e
  um `.env` derivado do `.env.example`, **quando** rodo `make up`, **então** sobem
  `app`, `worker` e `postgres`, e o `app` responde em `http://localhost:8000/up`.
- **C2 — Ordem e saúde por healthcheck.** **Dado** que `app` e `worker` dependem do
  `postgres`, **quando** o ambiente sobe, **então** eles só iniciam após o Postgres ficar
  `service_healthy` (`pg_isready`), e o `app` expõe healthcheck em `/up`; o `worker`
  **não** serve HTTP e tem o healthcheck desabilitado.
- **C3 — Worker roda fila + scheduler.** **Dado** o serviço `worker`, **quando** ativo,
  **então** executa `php artisan schedule:work` em background e `php artisan queue:work`
  em foreground, sobre a fila no driver `database` (sem Redis).
- **C4 — Tudo em contêiner via Makefile.** **Dado** que nada além de Docker + `make` está
  instalado, **quando** rodo `make test` / `make migrate` / `make shell`, **então** cada
  alvo executa `docker compose exec app …` (ex.: `make test` → `php artisan test`),
  sem exigir `php`/`composer`/`node` no host.
- **C5 (produção/Swarm) — Segredos via `*_FILE` em `/run/secrets`.** **Dado** o
  `docker-stack.yml` deployado no Swarm **sem `.env`**, **quando** o contêiner inicia,
  **então** o entrypoint lê cada `VAR_FILE` apontando para `/run/secrets/<nome>`
  (`APP_KEY`, `DB_PASSWORD`, `ANTHROPIC_API_KEY`, `TELEGRAM_BOT_TOKEN`) e exporta `VAR`
  antes do boot — nenhum segredo na imagem, no log ou no versionamento.
- **C6 (borda) — Sem `.env` em produção.** **Dado** o stack de produção, **quando**
  inspeciono o ambiente, **então** não existe arquivo `.env` (só variáveis não sensíveis
  no stack + segredos em `/run/secrets`); `.env` existe **apenas** em desenvolvimento.

## 4. Barreiras e invariantes
- **Regra 9 — execução 100% em contêiner.** Nenhuma ferramenta (`composer`, `artisan`,
  `php`, `node`, testes, migrations) roda no host; tudo via `docker compose exec/run`
  encapsulado no `Makefile`. Único pré-requisito local: Docker + `make`.
- **Regra 10 — produção com Docker Secrets, sem `.env`.** Em produção os segredos vêm de
  Docker Secrets em `/run/secrets/<nome>`, resolvidos pelo entrypoint pelo padrão
  `*_FILE`. `.env` só em dev (não versionado) + `.env.example` versionado. Segredos nunca
  vão para imagem, log ou git.
- **Regra 5 (fuso base).** `APP_TIMEZONE=America/Sao_Paulo` fixado no ambiente desde a
  fundação (datas relativas e vencimentos dependem disso).
- **Regra 1 — nunca push.** Push ao remoto exige ordem explícita do usuário; só commits
  locais são permitidos. A barreira é de disciplina (CLAUDE.md, regra inviolável 1) — não
  há hook de git no projeto.
- **Idempotência da fila** por unique constraint (driver `database`), preparando o dedupe
  de Telegram por `update_id` ([[spec-03]]).

## 5. Modelo de dados
**Nenhuma tabela de negócio nesta etapa.** A fundação provê o **mecanismo**: PostgreSQL 16
(`postgres:16-alpine`, volume nomeado `financeiro_pgdata`) e a fila no driver `database`
(tabelas `jobs`/`failed_jobs` criadas pelas migrations padrão do Laravel, junto de
`cache` e `sessions`, pois `CACHE_STORE`/`SESSION_DRIVER=database`). As migrations de
domínio entram a partir de [[spec-01]].

## 6. Contratos do domínio
Esta etapa não tem classes de domínio; seus "contratos" são **artefatos de infra**:

| Artefato | Caminho | Papel |
|---|---|---|
| Compose (dev) | `docker-compose.yml` | 3 serviços, healthchecks, `depends_on` por saúde, bind-mount `.:/app`, volume `pgdata`. |
| Imagem | `docker/Dockerfile` | Multi-stage `base` (dev) / `prod`; FrankenPHP 1 · PHP 8.3; extensões `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `intl`, `bcmath`, `opcache`, `pcntl`; Tesseract (pt) + poppler-utils; Composer 2. |
| Servidor HTTP | `docker/Caddyfile` | FrankenPHP serve `/app/public` na `:8000`; `/up` para healthcheck; logs JSON; `auto_https off` (TLS no LB/Swarm). |
| Entrypoint | `docker/entrypoint.sh` | Resolve `*_FILE` → exporta `VAR` (Docker Secrets); cria dirs graváveis; `exec "$@"`. |
| Orquestração (dev) | `Makefile` | Alvos finos sobre `docker compose` (`up/down/build/test/migrate/fresh/seed/shell/logs/worker-shell/artisan/composer`). |
| Bootstrap | `scripts/bootstrap.sh` | Cria o esqueleto Laravel **por contêiner** e sobe o ambiente. |
| Stack (prod) | `docker-stack.yml` | Swarm: imagem `prod`, `secrets` externos, `replicas`, `restart_policy`, `update_config: start-first`. |
| Config (dev) | `.env.example` | Modelo versionado; `APP_TIMEZONE` SP, `QUEUE/CACHE/SESSION=database`, `AI_PROVIDER`, placeholders `*_FILE` comentados. |

## 7. Plano de testes (test-first — devem falhar primeiro)
Etapa de **infraestrutura**: a verificação é operacional (smoke), não testes de domínio.
Critérios verificáveis equivalentes aos cenários de §3:

1. **Subida e saúde** — `make up` e `make ps` mostram `app`, `worker`, `postgres`; `app`
   fica `healthy` (`curl /up`), `postgres` `healthy` (`pg_isready`), `worker` sem
   healthcheck.
2. **Suíte roda em contêiner** — `make test` executa `php artisan test` dentro do `app`
   (base para o TDD das etapas seguintes), sem PHP no host.
3. **Migrations** — `make migrate` aplica as migrations padrão (jobs/cache/sessions) sobre
   o Postgres conteinerizado.
4. **Worker** — `make logs-worker` evidencia `schedule:work` + `queue:work` ativos.
5. **Produção (validação de config)** — `docker-stack.yml` declara `secrets` externos e
   `*_FILE`; entrypoint resolve `/run/secrets/*`; nenhum `.env` no stack.

> A partir de [[spec-01]] vale plenamente a regra 2 (TDD do domínio com Pest/PHPUnit) e os
> **fakes da Laravel AI SDK** para a camada de IA.

## 8. Backend agora · Frontend depois
| Backend/infra (esta etapa) | Frontend (etapa separada e posterior) |
|---|---|
| Compose, Dockerfile, entrypoint, Makefile, stack Swarm, `.env.example`, fila `database`, healthchecks. | Nenhum. Telas web e mensagens do bot vêm nos seus respectivos specs ([[spec-03]], [[spec-06]], …). |

## 9. Definition of Done
- [x] Cenários de §3 verificados operacionalmente (subida, saúde, worker, Makefile,
      secrets).
- [x] Barreiras de §4 garantidas: tudo em contêiner (regra 9); produção com Secrets sem
      `.env` (regra 10); fuso SP; hook anti-push.
- [x] Sem segredo/PDF/dado sensível persistido ou commitado; `.env` fora do git.
- [x] Commits locais atômicos, em português.
- [x] §10 preenchida com os artefatos reais.

## 10. Estado atual / artefatos
- **Status:** ✅ Concluído.
- **Entregue (caminhos reais):**
  - `docker-compose.yml` — 3 serviços (`app`, `worker`, `postgres`), healthchecks,
    `depends_on` por saúde, bind-mount `.:/app`, volume `financeiro_pgdata`.
  - `docker/Dockerfile` — multi-stage `base`/`prod`, FrankenPHP · PHP 8.3, extensões PHP,
    Tesseract (pt) + poppler-utils, Composer 2.
  - `docker/Caddyfile` — FrankenPHP servindo `/app/public` na `:8000`, `/up`, logs JSON.
  - `docker/entrypoint.sh` — resolução `*_FILE` (Docker Secrets) + dirs graváveis.
  - `Makefile` — alvos finos sobre `docker compose` (incl. `bootstrap`, `test`,
    `migrate`, `shell`, `worker-shell`).
  - `scripts/bootstrap.sh` — criação do esqueleto Laravel por contêiner.
  - `docker-stack.yml` — Swarm + Docker Secrets externos, réplicas, `restart_policy`.
  - `.env.example` — modelo de dev (fuso SP; `QUEUE/CACHE/SESSION=database`; placeholders
    `*_FILE` de produção comentados).
  - Skills iniciais (`.claude/skills/`): `skill-creator`, `laravel-backend`, `devops`.
- **Adiado para:** `ai_usage_log` + logs estruturados de IA → [[spec-04]]; Redis/Horizon,
  nginx/Octane, Prometheus/Grafana, OCR como serviço → caminho de escala (documentado em
  [`docs/11-devops.md`](../11-devops.md), não implementado no MVP).
- **Decisões tomadas:**
  - **FrankenPHP como `app`** (HTTP + PHP no mesmo processo): dispensa nginx separado no
    MVP; `worker` reusa a mesma imagem com comando diferente e healthcheck desabilitado.
  - **Imagem única, dois comandos** para `app` e `worker` (menos divergência dev↔prod).
  - **`base`/`prod` multi-stage:** dev usa bind-mount; prod **copia** o código, instala
    `--no-dev --optimize-autoloader` e remove `.env` da imagem (regra 10).
  - **Fila no driver `database`** (sem Redis no MVP; trocável por env ao escalar).
  - **Entrypoint resolve segredos antes do boot** (evitar `config:cache` com segredos
    embutidos); padrão `*_FILE` único atende dev (env) e prod (secret-file).
