# CI/CD — GitHub Actions → Swarm — referência

Pipeline: **test (gate) → build → scan → push (Docker Hub) → deploy (SSH → `docker stack
deploy`) → smoke → rollback**. Tag da imagem = **SHA do commit** (imutável, rastreável) +
`latest` móvel para facilitar o `image:` do stack.

O código/imagem fica no **Docker Hub** (`docker.io/<usuário>/<repo>`) para o serviço do
Swarm referenciar direto no compose sem autenticar em registry próprio — repositório
**privado** (a imagem carrega o código da aplicação; não deixe pública).

> **Você (IA) escreve este YAML como arquivo local. Nunca faz `git push`, nunca dispara o
> pipeline, nunca roda o deploy contra a produção.** Ao entregar, diga ao usuário: "faça
> `git push` e o workflow roda". Quem promove produção é ele.

## Princípios

- **Teste é portão.** `deploy` depende de `test`; sem verde, nada publica. Reforça o TDD do
  projeto — o pipeline é onde o TDD vira barreira de entrega.
- **Least privilege.** `permissions:` mínimas por job. Chave SSH de deploy **dedicada** (só
  este VPS, comando restrito se possível). Prefira **OIDC** a segredos de longa duração
  quando o alvo suportar.
- **Segredos só em `secrets`/`env`.** Nunca interpole segredo direto em `run:` sem `env:`;
  nunca `echo` de segredo; o GitHub mascara, mas não confie nisso para vazamentos indiretos.
- **Imagem escaneada.** Trivy falha o build em CVE alto/crítico.
- **Nada de `.env` em produção.** Segredos de runtime já estão como **Docker Secrets** no
  Swarm (ver `hardening-vps.md`). O CI precisa da chave SSH e do login no Docker Hub
  (usuário + **access token**, nunca a senha da conta).

## `.github/workflows/deploy.yml`

```yaml
name: deploy

on:
  push:
    branches: [main]          # ou: tags: ['v*'] para promover só em tag

concurrency:                  # não deixa dois deploys correrem juntos
  group: production
  cancel-in-progress: false

env:
  IMAGE: docker.io/${{ secrets.DOCKERHUB_USERNAME }}/agente-financeiro

jobs:
  test:                       # ── PORTÃO: sem verde, nada publica ──
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build imagem de teste
        run: docker build --target app -t app:test .
      - name: Rodar a suíte (TDD gate)
        run: docker run --rm -e APP_ENV=testing app:test php artisan test

  build-scan-push:
    needs: test
    runs-on: ubuntu-latest
    permissions:
      contents: read          # Docker Hub não usa GITHUB_TOKEN; nada de packages:write
    steps:
      - uses: actions/checkout@v4
      - uses: docker/setup-buildx-action@v3
      - name: Login Docker Hub
        uses: docker/login-action@v3
        with:
          username: ${{ secrets.DOCKERHUB_USERNAME }}
          password: ${{ secrets.DOCKERHUB_TOKEN }}   # access token, escopo Read/Write
      - name: Build
        uses: docker/build-push-action@v6
        with:
          context: .
          target: app
          tags: |
            ${{ env.IMAGE }}:${{ github.sha }}
            ${{ env.IMAGE }}:latest
          load: true
      - name: Scan (Trivy) — falha em HIGH/CRITICAL
        uses: aquasecurity/trivy-action@0.28.0
        with:
          image-ref: ${{ env.IMAGE }}:${{ github.sha }}
          severity: HIGH,CRITICAL
          exit-code: '1'
          ignore-unfixed: true
      - name: Push
        run: |
          docker push ${{ env.IMAGE }}:${{ github.sha }}
          docker push ${{ env.IMAGE }}:latest

  deploy:
    needs: build-scan-push
    runs-on: ubuntu-latest
    environment: production   # exige aprovação manual se você configurar no repo
    steps:
      - name: Deploy no Swarm via SSH
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.VPS_HOST }}
          username: ${{ secrets.VPS_USER }}
          key: ${{ secrets.VPS_SSH_KEY }}         # chave de deploy dedicada
          script_stop: true
        env:
          DOCKERHUB_USERNAME: ${{ secrets.DOCKERHUB_USERNAME }}
          DOCKERHUB_TOKEN: ${{ secrets.DOCKERHUB_TOKEN }}
          SHA: ${{ github.sha }}
          IMAGE: ${{ env.IMAGE }}
        with:
          envs: DOCKERHUB_USERNAME,DOCKERHUB_TOKEN,SHA,IMAGE
          script: |
            set -euo pipefail
            # login no Docker Hub (repo privado) — sem echo do token
            echo "$DOCKERHUB_TOKEN" | docker login -u "$DOCKERHUB_USERNAME" --password-stdin
            docker pull "$IMAGE:$SHA"
            # atualiza só a imagem; secrets/replicas já estão no stack.
            # --with-registry-auth propaga a credencial aos outros nós do Swarm p/ o pull
            docker service update \
              --with-registry-auth \
              --image "$IMAGE:$SHA" \
              --update-order start-first \
              --update-failure-action rollback \
              app_app
            # migrations controladas (uma task, não em cada réplica)
            docker run --rm --network app_default \
              "$IMAGE:$SHA" php artisan migrate --force
            docker logout

  smoke:
    needs: deploy
    runs-on: ubuntu-latest
    steps:
      - name: Healthcheck público
        run: |
          for i in $(seq 1 10); do
            code=$(curl -s -o /dev/null -w '%{http_code}' https://app.exemplo.com/health) || true
            [ "$code" = "200" ] && exit 0
            sleep 6
          done
          echo "health falhou"; exit 1
```

## Como o serviço referencia a imagem no stack (Swarm)

No `docker-stack.yml` (skill `devops`), o serviço aponta para a imagem do Docker Hub. O
pipeline faz `docker service update --image ...:<SHA>`, então o `image:` do stack é só a
linha-base — mantenha uma tag concreta, não deixe implícito:

```yaml
services:
  app:
    image: docker.io/<usuário>/agente-financeiro:latest   # baseline; o CI fixa o SHA no update
    deploy:
      replicas: 2
      update_config: { order: start-first, failure_action: rollback }
    secrets: [app_key, db_password, telegram_token, groq_api_key]
    ports: ["127.0.0.1:8000:8000"]     # só o Nginx edge alcança
```

`docker stack deploy --with-registry-auth -c docker-stack.yml app` no primeiro deploy
propaga a credencial do Docker Hub aos nós para o pull do repositório **privado**.

## Rollout e rollback

- `--update-order start-first` + healthcheck no serviço = **zero-downtime** (sobe a nova
  task antes de derrubar a antiga).
- `--update-failure-action rollback` faz o Swarm **voltar sozinho** se a nova task não ficar
  saudável.
- Rollback manual: `docker service rollback app_app`, ou redeploy da tag SHA anterior (todas
  imutáveis no Docker Hub; a `latest` é só conveniência, **não** use como âncora de rollback).
- Se o `smoke` falhar depois de um deploy "ok", acione o rollback manual — não deixe a
  versão ruim no ar.

## Migrations com segurança

- Rode `migrate --force` **uma vez** por deploy (uma task), nunca no entrypoint de cada
  réplica (corrida de migração).
- Migrations devem ser compatíveis com a versão antiga durante o rolling update
  (expand/contract): primeiro adicione coluna nullable, depois faça o backfill, só num
  deploy seguinte remova o antigo. Evita quebrar as réplicas ainda na versão N-1.

## Segredos que o CI precisa (GitHub → Settings → Secrets)

| Secret | Uso |
|---|---|
| `DOCKERHUB_USERNAME` | usuário/organização do Docker Hub |
| `DOCKERHUB_TOKEN` | **access token** do Docker Hub (escopo Read/Write), nunca a senha |
| `VPS_HOST`, `VPS_USER` | destino SSH do deploy |
| `VPS_SSH_KEY` | chave privada **dedicada** ao deploy (a pública vai no `authorized_keys` do `deploy`) |

Segredos de **runtime** (DB, IA, Telegram, `APP_KEY`) **não** ficam no GitHub — estão como
**Docker Secrets** no Swarm. O CI nunca os vê.

## Checklist

- [ ] `deploy` depende de `test`; teste vermelho barra a publicação.
- [ ] `permissions:` mínimas por job; chave SSH dedicada e restrita.
- [ ] Imagem escaneada; build falha em HIGH/CRITICAL.
- [ ] Tag = SHA; imagem imutável e rastreável.
- [ ] Rolling update `start-first` + `--update-failure-action rollback`.
- [ ] Migrations `--force` uma vez, compatíveis com N-1.
- [ ] Nenhum segredo de runtime no GitHub; só a chave de deploy + token do Docker Hub.
- [ ] Repo do Docker Hub **privado**; `--with-registry-auth` no update/deploy.
- [ ] Você entregou o YAML; **o usuário** dá `git push` e promove o deploy.
