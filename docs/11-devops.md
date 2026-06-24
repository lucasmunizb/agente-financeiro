# 11. DevOps e Infraestrutura

> Fonte de verdade: seção 12 do escopo final.

## Filosofia

O MVP precisa ser **rápido de subir, simples de manter e escalável sem reescrita**. Por isso o runtime mínimo tem apenas **3 contêineres**, mas o código e a configuração já nascem prontos para o perfil de produção (mais réplicas, fila em Redis, bot e observabilidade dedicados) trocando **variáveis de ambiente** — não reescrevendo arquitetura.

**Por que 3 contêineres:** para um único usuário, uma camada de coordenação dedicada (Redis/Kafka) é desnecessária. A fila roda no driver `database`, a idempotência usa unique constraint, o bot chega por webhook no próprio app e o OCR é uma biblioteca no worker. O banco resolve o que antes exigiria infra especializada. Quando o volume justificar, cada peça vira um serviço dedicado por configuração.

## Serviços do MVP (runtime mínimo)

| Serviço | Papel |
| --- | --- |
| **app** | Aplicação PHP/Laravel servindo HTTP (API + webhook do Telegram). Inclui o servidor web. |
| **worker** | Processa a fila (importação de PDF, IA) e o scheduler. Mesma imagem do app, comando diferente. OCR (Tesseract) embutido. |
| **postgres** | Banco relacional: fonte de verdade financeira e backend da fila (driver `database`) no MVP. |

### Itens que NÃO viram contêiner no MVP

| Item | Por quê |
| --- | --- |
| nginx separado | HTTP servido pelo próprio `app`. |
| telegram-bot | Webhook recebido dentro do `app` (adaptador isolado no código). |
| redis | Fila roda no driver `database`. |
| ocr | Tesseract é biblioteca dentro do `worker`. |
| prometheus / grafana | Logs estruturados + `ai_usage_log` cobrem observabilidade no MVP. |
| mailpit | E-mail é pós-MVP. |

## Caminho de escala e produção (sem reescrita)

Tudo abaixo é alternado por **variável de ambiente** ou **perfil do compose**, com o domínio já desacoplado no código:

| Gatilho | Mudança | Como |
| --- | --- | --- |
| Volume de fila/jobs cresce | Fila no Redis + Horizon | Adicionar serviço `redis` e `QUEUE_CONNECTION=redis`; ligar Horizon. Sem mudar jobs. |
| Tráfego HTTP cresce | Escalar `app` horizontalmente | Réplicas do `app` atrás de load balancer; nginx/Octane no perfil de produção. |
| Bot vira gargalo / multicanal | Extrair adaptador para serviço próprio | O adaptador já é isolado; promove-se a contêiner `telegram-bot`. WhatsApp reaproveita o domínio. |
| Precisa de métricas visuais | Observabilidade dedicada | Subir `prometheus` + `grafana` (perfil de produção); app já expõe métricas/logs estruturados. |
| OCR pesado | OCR como serviço | Tesseract sai do `worker` para um contêiner `ocr` acionado pela fila. |
| Isolamento de ambientes | Perfis do compose | Perfis `dev` e `prod` no mesmo `docker-compose` (profiles), com `.env` por ambiente. |
| Produção | Docker Swarm + Docker Secrets | Compose evolui para stack do Swarm (deploy, replicas, secrets). Segredos em `/run/secrets`, sem `.env`. |

## Padrões de DevOps (já prontos para produção)

- **Makefile** com atalhos: `up`, `down`, `build`, `test`, `migrate`, `seed`, `logs`, `shell`, `worker`.
- **Configuração 100% por ambiente:** `.env` (nunca versionado) + `.env.example` versionado; `QUEUE_CONNECTION`, `AI_PROVIDER`, `OCR_DRIVER` e afins trocáveis sem código.
- **Worker como processo dedicado e escalável** (réplicas independentes do `app`).
- **Healthchecks** por serviço; **volume nomeado** para Postgres; **migrations versionadas e idempotentes**.
- Separação de processamento pesado (PDF/IA) da requisição principal via fila.
- Perfis dev/prod no mesmo compose; em produção, `app` atrás de servidor HTTP/Octane com múltiplas réplicas.
- Skill de DevOps dedicada para a IA operar a infraestrutura com segurança e **respeitar a proibição de push remoto**.

## Execução 100% em contêiner (nada local)

**Regra:** nada é instalado na máquina local além do `make`. Todo comando — `composer`, `artisan`, `php`, `node`, testes, migrations — roda dentro do contêiner via `docker compose exec`, encapsulado em alvos do Makefile. Isso garante ambiente idêntico em qualquer máquina e em produção.

- O próprio **esqueleto do projeto é criado por contêiner** (ex.: contêiner `composer`/`php` temporário), não por composer local.
- **Alvos do Makefile são finos:** cada um chama `docker compose exec/run`. Ex.: `make test` → `docker compose exec app php artisan test`; `make shell` → `docker compose exec app bash`.
- Não há dependência de PHP, Composer ou Node no host. O único pré-requisito local é **Docker + make**.

## Configuração e segredos (dev vs. produção)

| Ambiente | Configuração | Segredos |
| --- | --- | --- |
| **Desenvolvimento** | `.env` (não versionado) + `.env.example` | Valores no `.env` local. |
| **Produção (Swarm)** | Variáveis não sensíveis no stack; **SEM `.env`** | Docker Secrets montados em `/run/secrets/<nome>`. |

- Em produção **não existe arquivo `.env`**. As chaves (DB, provedores de IA, token do Telegram, `APP_KEY`) vêm de **Docker Secrets**.
- O **entrypoint** do contêiner lê os segredos de `/run/secrets` e os disponibiliza ao app **antes do boot**; **evite `config:cache` com segredos embutidos** em build.
- **Padrão recomendado:** variáveis `*_FILE` apontando para `/run/secrets/<nome>`, com o app lendo o conteúdo do arquivo (suporte a env em dev e secret-file em produção).
- **Segredos nunca** vão para a imagem, para logs ou para o versionamento.
