---
name: devops
description: Use sempre que for provisionar, operar, depurar ou evoluir a infraestrutura deste projeto — docker-compose, Dockerfile, entrypoint, Makefile, healthchecks, subir/derrubar ambiente, migrations/seeds, logs, worker/fila, OCR (Tesseract), produção em Docker Swarm com Docker Secrets, ou o caminho de escala (Redis/Horizon, bot dedicado, observabilidade). Dispare quando o usuário mencionar "subir o ambiente", "make up", "docker", "compose", "container", "worker", "fila", "secret", "swarm", "produção", "deploy", "bootstrap" ou "Makefile" — mesmo sem pedir explicitamente.
---

# devops — infraestrutura deste projeto

Provisiona e opera a infra. **Filosofia:** MVP enxuto (3 contêineres), mas pronto para
escalar e para produção **sem reescrita** — tudo trocável por variável de ambiente/perfil.
Fonte de verdade: [`docs/11-devops.md`](../../../docs/11-devops.md).

## Princípio nº 1: execução 100% em contêiner
Nada é instalado no host além de **Docker + `make`**. `composer`, `artisan`, `php`, `node`,
testes, migrations: **todos** via `docker compose exec`/`run`, encapsulados no `Makefile`.
**Inclusive a criação do esqueleto** do projeto é por contêiner (ex.: `docker run --rm
composer create-project …`), nunca por composer local. **Nunca** instrua o usuário a rodar
php/composer/node no host.

## Runtime do MVP — 3 contêineres

| Serviço | Papel |
|---|---|
| **app** | PHP 8.3 / Laravel 12 servindo HTTP (API + **webhook do Telegram**). Servidor web junto (php-fpm+nginx na mesma imagem ou FrankenPHP/Octane). |
| **worker** | Mesma imagem do app; comando `queue:work` + `schedule:work`. Processa PDF e IA. **Tesseract (OCR) é biblioteca nesta imagem**, não serviço. |
| **postgres** (16) | Banco relacional **e backend da fila** no MVP (`QUEUE_CONNECTION=database`). Volume nomeado. |

**Não viram contêiner no MVP** (e por quê): nginx separado (servido pelo app),
`telegram-bot` (webhook no app), `redis` (fila em database; idempotência por unique
constraint no `update_id`), `ocr` (biblioteca no worker), `prometheus`/`grafana` (logs +
`ai_usage_log` cobrem), `mailpit` (e-mail é pós-MVP).

## Makefile — alvos finos (cada um chama docker)
`up`, `down`, `build`, `test` (→ `docker compose exec app php artisan test`), `migrate`,
`seed`, `logs`, `shell` (→ `docker compose exec app bash`), `worker`, `artisan`,
`composer`. **Nenhum** alvo assume ferramentas no host.

## Requisitos do esqueleto
- Laravel 12 (PHP 8.3) criado **via contêiner**; Pest/PHPUnit configurado para TDD.
- Fila `database` (tabela de jobs migrada); worker como processo dedicado e **escalável**
  (réplicas independentes do app).
- Idempotência do Telegram por **unique constraint** em `update_id`.
- Healthchecks por serviço; volume nomeado para Postgres; logging estruturado (sem dados
  sensíveis) e tabela `ai_usage_log`.
- `.gitignore` adequado e aviso reforçando que **push é proibido** sem ordem explícita.

## Configuração e segredos — dev vs. produção

| Ambiente | Configuração | Segredos |
|---|---|---|
| **Dev** | `.env` (ignorado pelo Git) + `.env.example` versionado | valores no `.env` local |
| **Produção (Swarm)** | variáveis não sensíveis no stack; **SEM `.env`** | **Docker Secrets** em `/run/secrets/<nome>` |

- **`docker-stack.yml`** para Swarm: `deploy:`/`replicas` + `secrets:`.
- O **entrypoint** lê os segredos de `/run/secrets` e os disponibiliza ao app **antes do
  boot**. Padrão recomendado: variáveis **`*_FILE`** apontando para o arquivo do segredo,
  com o app lendo o conteúdo (suporta env em dev e secret-file em produção).
- **Evite `config:cache`** com segredos embutidos em build. Segredos **nunca** vão para
  imagem, logs ou versionamento. Chaves cobertas: DB, provedores de IA, token do Telegram,
  `APP_KEY`.

## Caminho de escala (deixe pronto, não implemente tudo agora)
Por env/perfil/stack, sem reescrever:
- Fila cresce → `QUEUE_CONNECTION=redis` + **Horizon** (serviço `redis`).
- Tráfego HTTP cresce → escalar `app` (réplicas no Swarm atrás de load balancer; Octane).
- Bot vira gargalo/multicanal → promover o adaptador a serviço `telegram-bot`.
- Métricas visuais → `prometheus` + `grafana` (perfil de produção).
- OCR pesado → mover Tesseract para contêiner `ocr` acionado pela fila.

Mantenha **canais e processamento desacoplados no código** para que nada exija reescrita.

## Critério de "pronto" do bootstrap
`make up` sobe os 3 contêineres e `make test` roda a suíte (mesmo inicial) com sucesso —
**tudo dentro de contêiner**.

## Regra inviolável
**NUNCA `git push`** nem qualquer operação em remoto (push, force-push, remote, PR, branch
remota). Commits **locais** apenas. Remoto só com ordem explícita do usuário, por escrito,
naquele momento. Considere reforçar com um hook local de `pre-push` que bloqueia/avisa.
