---
name: deploy
description: Use sempre que for colocar este projeto em PRODUÇÃO num VPS, automatizar entrega via CI/CD no GitHub, ou endurecer a segurança do caminho de deploy — provisionar/endurecer o servidor, publicar imagem em registry, rodar `docker stack deploy` no Swarm, configurar o edge Cloudflare → Nginx (proxy reverso) → app, TLS/certificados de origem, rollback e zero-downtime. Dispare quando o usuário mencionar "deploy", "colocar em produção", "publicar", "ir pro ar", "VPS", "servidor", "CI/CD", "pipeline", "GitHub Actions", "workflow", "Cloudflare", "Nginx", "proxy reverso", "certificado", "TLS/SSL", "hardening", "endurecer o servidor", "firewall", "SSH", "fail2ban", "rollout", "rollback", "registry", "Docker Hub" ou "image" — mesmo sem pedir explicitamente. Complementa a `devops` (que constrói compose/Dockerfile/Makefile/stack) e a `pentest`/`seguranca-ia` (defesa da aplicação). NÃO use para escrever compose/Dockerfile do zero (isso é `devops`) nem regra financeira/telas.
---

# deploy — VPS, CI/CD no GitHub e segurança do caminho de produção

Leva os artefatos de infra até um servidor real, de forma **automatizada, reprodutível e
segura**. A `devops` *constrói* (compose, Dockerfile, Makefile, `docker-stack.yml`,
Secrets); esta skill *entrega*: provisiona e endurece o VPS, publica a imagem, faz o
`stack deploy`, configura o edge e cuida de rollout/rollback.

**Arquitetura-alvo deste projeto:**

```
Cliente ──TLS──▶ Cloudflare (Universal SSL + WAF + rate limit + cache)
                     │  TLS "Full (strict)" com Origin CA
                     ▼
                 VPS (só portas 80/443 abertas p/ IPs da Cloudflare)
                     │
                 Nginx edge (proxy reverso, termina o cert de origem)
                     │  proxy_pass → porta publicada do serviço
                     ▼
                 Docker Swarm: app (Laravel) · worker · postgres  +  Docker Secrets
```

Fonte de verdade da infra: [`docs/11-devops.md`](../../../docs/11-devops.md). Segredos e
runtime seguem o CLAUDE.md (§10): **produção é Swarm com Docker Secrets, SEM `.env`**.

---

## Fronteira com as outras skills (não duplicar)

| Assunto | Skill dona |
|---|---|
| `docker-compose.yml`, `Dockerfile`, entrypoint, Makefile, `docker-stack.yml` | **devops** |
| Provisionar/endurecer o VPS, pipeline CI/CD, edge Cloudflare+Nginx, rollout/rollback | **deploy** (esta) |
| Vulnerabilidades no código da aplicação (IDOR, XSS, SQLi…) | **pentest** |
| Defesa da camada de IA (prompt injection etc.) | **seguranca-ia** |
| Bases legais, retenção, direitos do titular | **lgpd** |

Quando a tarefa for "montar o stack file" ou "ajustar o entrypoint", chame a `devops`.
Aqui tratamos o que acontece **entre o `git push` do usuário e o app no ar**.

---

## Regras invioláveis aplicadas ao deploy

Estas valem para qualquer trabalho no repo (ver CLAUDE.md); no contexto de deploy elas
pegam com força:

1. **NUNCA `git push` nem operação em remoto.** Isto é delicado aqui porque CI/CD *vive*
   no remoto. A regra vale para **você (a IA)**: você **escreve** o workflow
   (`.github/workflows/*.yml`) e os scripts de deploy como arquivos locais, mas **nunca**
   executa `git push`, nunca dispara o pipeline, nunca roda `docker stack deploy` contra a
   produção do usuário. Quem faz `push` e quem promove o deploy é **o usuário** (ou o
   próprio runner, acionado por ele). Ao terminar, diga o que ele precisa rodar — não rode
   por ele.
2. **TDD é o portão do deploy.** O pipeline **só** publica se a suíte passar. A etapa de
   testes é *gate* obrigatório: `php artisan test` verde antes de build/push/deploy.
   Deploy nunca "pula os testes".
3. **Segredos nunca entram em imagem, log, Git ou registry.** Em produção vêm de **Docker
   Secrets** (`/run/secrets/*`), lidos pelo entrypoint via padrão `*_FILE`. No CI, ficam
   em **GitHub Secrets/OIDC** — nunca em `echo`, nunca em `run:` interpolado sem `env:`,
   nunca commitados. Evite `config:cache` com segredo embutido no build.
4. **Tudo em contêiner.** O edge Nginx roda como **serviço do Swarm** (ou contêiner), não
   como pacote no host — mantém o host limpo (só Docker + `make`). Um Nginx no host é
   variante aceitável e documentada, não o padrão.
5. **Frontend é etapa separada.** Deploy não é lugar para mexer em Blade/assets; só
   garante que o build do Vite (`npm run build`) já roll em imagem e que os assets são
   servidos. Alterar telas é tarefa da skill `frontend`, em outro commit.

---

## Procedimento de um deploy (ordem)

Anuncie o que vai fazer antes de tocar em arquivos. Sequência recomendada:

1. **Provisionar/endurecer o VPS** (uma vez, idempotente) — ver
   [`references/hardening-vps.md`](references/hardening-vps.md): usuário não-root, SSH sem
   senha e sem root, firewall (só Cloudflare nas 80/443 + SSH restrito), fail2ban,
   `unattended-upgrades`, sysctl, Docker + `swarm init`.
2. **Registrar os Docker Secrets no Swarm** (uma vez) — `printf %s "<valor>" | docker
   secret create <nome> -`. Nunca via arquivo versionado. Chaves: DB, provedores de IA,
   token do Telegram, `APP_KEY`, cert de origem Cloudflare.
3. **Configurar o edge** Cloudflare + Nginx — ver
   [`references/cloudflare-nginx.md`](references/cloudflare-nginx.md): Origin CA, modo Full
   (strict), Authenticated Origin Pulls, real-IP, headers de segurança, `TrustProxies` no
   Laravel.
4. **Escrever o pipeline** GitHub Actions — ver
   [`references/github-actions.md`](references/github-actions.md): `test` (gate) → `build`
   → `scan` (Trivy) → `push` (Docker Hub) → `deploy` (SSH → `docker stack deploy`) → `smoke` →
   rollback automático se o healthcheck falhar.
5. **Rollout e verificação** — rolling update do Swarm (zero-downtime); rodar migrations de
   forma controlada; smoke test em `/health`; purgar cache da Cloudflare se preciso.
6. **Rollback** — `docker service rollback <serviço>` ou redeploy da tag anterior. Toda tag
   de imagem é imutável (SHA do commit), então voltar é trocar a tag.

Pare e confirme com o usuário nos pontos que tocam produção (registrar secret, primeiro
`stack deploy`, mudança de DNS/Cloudflare).

---

## Pilares de segurança (defense in depth)

- **Superfície mínima no VPS.** Só 80/443 (e só para faixas da Cloudflare) e SSH numa porta
  restrita a IPs conhecidos. Considere **Cloudflare Tunnel (`cloudflared`)** para não abrir
  porta nenhuma na internet — o túnel sai do VPS, nada entra.
- **TLS ponta a ponta.** Cliente↔Cloudflare por Universal SSL; Cloudflare↔origem por
  **Origin CA + Full (strict)** e, idealmente, **Authenticated Origin Pulls (mTLS)** para
  que a origem só aceite a Cloudflare. Nunca "Flexible".
- **Real IP + rate limit corretos.** Sem restaurar `CF-Connecting-IP` (`set_real_ip_from`
  faixas da Cloudflare) o Laravel e o rate limiter enxergam o IP da Cloudflare e o controle
  vira inócuo. Configure `TrustProxies` para os proxies certos.
- **Least privilege no CI.** Chave de deploy dedicada (só o necessário, sem acesso a outros
  hosts), `permissions:` mínimas no workflow, OIDC quando possível em vez de chave longa.
- **Supply chain.** Escanear a imagem (**Trivy**) e falhar em CVE alto/crítico; fixar
  versões base; imagem por **digest**; tag = SHA do commit (imutável, rastreável).
- **Segredos fora do runtime observável.** `docker secret`, não env em `docker inspect`;
  entrypoint lê `*_FILE`; nada de segredo em `RUN`/camada de imagem.

Detalhes e configs completos estão nas `references/`.

---

## Exemplos (Input → Output esperado)

**Input:** "quero colocar no ar pela primeira vez, do zero"
**Output:** Roteiro na ordem acima — 1) script de hardening do VPS (ponteiro para a
reference), 2) comandos `docker secret create` para cada chave, 3) config Cloudflare (Full
strict + Origin CA) e `nginx.conf` do edge, 4) workflow do GitHub Actions com gate de teste,
5) instruções do que **o usuário** roda (`git push`, primeiro `stack deploy`). Reforçar:
você não faz push nem o deploy; entrega os arquivos e os comandos.

**Input:** "o rate limit do Laravel não está funcionando atrás da Cloudflare"
**Output:** Diagnóstico de real-IP: sem `set_real_ip_from` nas faixas da Cloudflare +
`CF-Connecting-IP`, todos os requests chegam com o IP da Cloudflare e caem no mesmo balde.
Corrigir no Nginx edge e ajustar `TrustProxies`. Ponteiro para
[`references/cloudflare-nginx.md`](references/cloudflare-nginx.md).

**Input:** "adiciona um passo de deploy no pipeline que sobe pro VPS"
**Output:** Job `deploy` que só roda após `test` verde: autentica por SSH (chave em GitHub
Secret, least-privilege), faz `docker stack deploy` com a tag = SHA, espera o healthcheck e
faz `docker service rollback` se falhar. Escrever o YAML localmente; lembrar que **quem dá
push e aciona é o usuário**.

**Input:** "como deixo o servidor seguro?"
**Output:** Disparar o hardening completo (SSH, firewall só-Cloudflare, fail2ban,
unattended-upgrades, não-root, sysctl) — ponteiro para
[`references/hardening-vps.md`](references/hardening-vps.md) — mais o edge (Origin CA + mTLS
+ headers). Enquadrar como defense in depth, não uma medida só.

---

## Definition of Done

- VPS endurecido de forma **idempotente** (rodar o script de novo não quebra nada).
- Só portas necessárias abertas; origem só aceita tráfego da Cloudflare (firewall ou mTLS).
- TLS Full (strict) com Origin CA válido; real-IP restaurado; headers de segurança presentes.
- Pipeline com **gate de teste** antes de qualquer publicação; imagem escaneada; tag = SHA.
- Deploy com **rolling update** (sem downtime) e **rollback** testado.
- Nenhum segredo em Git/imagem/log/registry; todos via Docker Secrets / GitHub Secrets.
- Você entregou arquivos e comandos; **o usuário** fez push e promoveu o deploy.
