# Hardening do VPS — referência

Endurecimento idempotente de um VPS Ubuntu/Debian que roda Docker Swarm atrás da
Cloudflare. Objetivo: superfície mínima, sem senha, patched, e origem que **só** aceita a
Cloudflare. Rode como script (Ansible/cloud-init/bash) — sempre idempotente: rodar de novo
não deve quebrar nada.

> **Você (IA) não executa isto contra a produção do usuário.** Entrega o script/comandos;
> o usuário roda. Nunca faça `ssh ... && <comando destrutivo>` por conta própria.

## 1. Usuário não-root + SSH por chave

```bash
# como root, uma vez
adduser --disabled-password --gecos "" deploy
usermod -aG sudo deploy
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
# cole a chave PÚBLICA do operador (não a do CI ainda)
printf '%s\n' "ssh-ed25519 AAAA... operador" > /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
```

`/etc/ssh/sshd_config.d/hardening.conf` (drop-in, não edite o arquivo principal):

```
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AuthenticationMethods publickey
X11Forwarding no
AllowUsers deploy
MaxAuthTries 3
LoginGraceTime 20
# opcional: porta alta reduz ruído de bots (não é segurança real, só higiene de log)
# Port 2222
```

Valide antes de reiniciar (não se tranque para fora): `sshd -t && systemctl reload ssh`.
Mantenha a sessão atual aberta e teste uma nova em paralelo.

## 2. Firewall — só Cloudflare nas 80/443

Duas escolhas mutuamente exclusivas para não abrir porta na internet:

**Opção A — ufw + faixas da Cloudflare** (origem exposta, mas só à Cloudflare):

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow from <SEU_IP_ADMIN> to any port 22 proto tcp   # SSH só do seu IP
for cidr in $(curl -s https://www.cloudflare.com/ips-v4) $(curl -s https://www.cloudflare.com/ips-v6); do
  ufw allow from "$cidr" to any port 443 proto tcp
  ufw allow from "$cidr" to any port 80  proto tcp
done
ufw --force enable
```

As faixas da Cloudflare mudam — reaplique periodicamente (cron/pipeline). Fonte oficial:
`https://www.cloudflare.com/ips/`.

**Opção B — Cloudflare Tunnel (`cloudflared`)** — recomendada quando possível: nenhuma porta
de entrada aberta; o túnel **sai** do VPS até a Cloudflare. Firewall pode negar todo
inbound exceto SSH do seu IP. Elimina varredura direta da origem.

> Só um dos dois. Com Tunnel, não publique 80/443 na internet.

## 3. fail2ban (SSH e, se exposto, Nginx)

```bash
apt-get install -y fail2ban
```

`/etc/fail2ban/jail.d/local.conf`:

```
[sshd]
enabled  = true
maxretry = 3
bantime  = 1h
findtime = 10m
```

Atrás da Cloudflare, o fail2ban do Nginx deve ler o **IP real** (ver
`cloudflare-nginx.md`), senão bane a própria Cloudflare.

## 4. Patches automáticos

```bash
apt-get install -y unattended-upgrades
dpkg-reconfigure -f noninteractive unattended-upgrades
```

Habilite atualizações de segurança automáticas e `Automatic-Reboot` em janela de baixo
tráfego se aceitável.

## 5. Kernel/rede (sysctl)

`/etc/sysctl.d/99-hardening.conf`:

```
net.ipv4.conf.all.rp_filter = 1
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
kernel.randomize_va_space = 2
```

`sysctl --system` para aplicar. **Cuidado:** Docker gerencia parte do `net.ipv4.ip_forward`
— não desabilite forwarding ou a rede dos contêineres quebra.

## 6. Docker + Swarm

- Instale o Docker pelo repositório oficial (não o pacote da distro, que atrasa patches).
- `docker swarm init --advertise-addr <IP_privado>` — prefira a rede privada se houver.
- Não exponha a API do Docker em TCP. Só socket local.
- Rode contêineres como usuário não-root quando a imagem permitir; `no-new-privileges`.
- Portas dos serviços do Swarm: publique em `127.0.0.1` (ex.: `127.0.0.1:8000:8000`) para
  que só o Nginx edge alcance — nada direto da internet.

## 7. Docker Secrets (uma vez, no manager)

```bash
printf %s "$APP_KEY"            | docker secret create app_key -
printf %s "$DB_PASSWORD"       | docker secret create db_password -
printf %s "$TELEGRAM_TOKEN"    | docker secret create telegram_token -
printf %s "$GROQ_API_KEY"      | docker secret create groq_api_key -
# cert de origem da Cloudflare (ver cloudflare-nginx.md)
docker secret create cf_origin_cert  origin.pem
docker secret create cf_origin_key   origin.key
```

O entrypoint lê `/run/secrets/<nome>` via padrão `*_FILE` (responsabilidade da `devops`).
Nunca coloque o valor em `.env`, em `docker-stack.yml` versionado ou em `RUN` da imagem.

## Checklist final

- [ ] root sem login; senha SSH desativada; `AllowUsers deploy`.
- [ ] Firewall nega inbound por padrão; 80/443 só da Cloudflare (ou Tunnel sem portas).
- [ ] SSH restrito ao IP admin (ou só via Tunnel).
- [ ] fail2ban ativo lendo IP real.
- [ ] unattended-upgrades ligado.
- [ ] Portas dos serviços publicadas só em `127.0.0.1`.
- [ ] Secrets no Swarm; nada sensível em disco versionado.
- [ ] Script inteiro re-executável sem efeito colateral (idempotente).
