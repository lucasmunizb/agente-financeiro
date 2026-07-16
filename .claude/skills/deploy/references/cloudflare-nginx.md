# Edge: Cloudflare → Nginx → app — referência

Termina o TLS de origem, restaura o IP real do visitante e encaminha para o serviço do
Swarm. Fluxo: **Cliente ─TLS→ Cloudflare ─TLS(Full strict + Origin CA)→ Nginx edge
─proxy_pass→ app (127.0.0.1:porta publicada)**.

## 1. Cloudflare (painel)

- **SSL/TLS mode: Full (strict).** Nunca "Flexible" (deixaria Cloudflare↔origem em texto
  claro) nem "Full" sem strict (aceita cert inválido).
- **Origin CA:** gere um certificado de origem no painel (SSL/TLS → Origin Server → Create
  Certificate). Ele vale só entre Cloudflare e sua origem. Guarde `origin.pem`/`origin.key`
  como **Docker Secret** (`cf_origin_cert`/`cf_origin_key`), nunca no repo.
- **Authenticated Origin Pulls (mTLS):** ative para a origem só aceitar conexões que
  apresentem o certificado cliente da Cloudflare. É o que impede alguém que descubra o IP
  do VPS de falar direto com o Nginx.
- **Always Use HTTPS** + **HSTS** (com cautela: só ative HSTS quando tiver certeza do
  domínio/subdomínios; é difícil de reverter).
- **WAF / Managed Rules** ligado; **Rate Limiting Rules** para rotas sensíveis (login,
  webhook do Telegram); **Bot Fight Mode** se fizer sentido.
- **Proxied (nuvem laranja)** nos registros DNS que passam pela app.
- Cache: só assets estáticos; **nunca** cachear respostas autenticadas.

## 2. Nginx edge — `nginx.conf`

Rode o edge como **serviço do Swarm** publicando 443 (alinha com "tudo em contêiner"). O
cert de origem vem montado de `/run/secrets`.

```nginx
# restaura o IP real do visitante — SEM isto, rate limit e logs veem só a Cloudflare
# faixas oficiais: https://www.cloudflare.com/ips/ (mantenha atualizado)
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
# ... (todas as faixas v4 e v6 da Cloudflare) ...
real_ip_header CF-Connecting-IP;

# opcional: só aceitar da Cloudflare também no Nginx (defesa extra ao firewall)
# geo $cf_ip { default 0; 173.245.48.0/20 1; ... }

server {
    listen 443 ssl;
    http2 on;
    server_name app.exemplo.com;

    ssl_certificate     /run/secrets/cf_origin_cert;   # Origin CA
    ssl_certificate_key /run/secrets/cf_origin_key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    # Authenticated Origin Pulls (mTLS): valida o cert cliente da Cloudflare
    ssl_client_certificate /etc/nginx/cloudflare-origin-pull-ca.pem;
    ssl_verify_client on;

    # headers de segurança (complementam a Cloudflare)
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    # HSTS só quando o domínio estiver 100% em HTTPS
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    client_max_body_size 12m;   # faturas em PDF são efêmeras; limite o upload

    location / {
        proxy_pass http://127.0.0.1:8000;         # porta publicada do serviço app
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;   # já é o IP real (real_ip acima)
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 60s;
    }

    location = /health {          # smoke test / healthcheck do Swarm
        proxy_pass http://127.0.0.1:8000/health;
        access_log off;
    }
}

# 80 → redireciona (a Cloudflare já força HTTPS, mas feche o loop)
server {
    listen 80;
    server_name app.exemplo.com;
    return 301 https://$host$request_uri;
}
```

O CA de Authenticated Origin Pulls é público da Cloudflare (baixe da doc deles) e vai em
`cloudflare-origin-pull-ca.pem`.

## 3. Laravel — confiar nos proxies certos

Sem isto, `$request->ip()`, `secure()`, rate limiter e geração de URL ficam errados atrás do
edge. Em `bootstrap/app.php` (Laravel 12):

```php
->withMiddleware(function (Middleware $middleware) {
    // confie no Nginx edge (proxy local) — restaure o IP já vem do Nginx via X-Forwarded-*
    $middleware->trustProxies(
        at: '127.0.0.1',                 // o edge; NÃO confie em '*'
        headers: Request::HEADER_X_FORWARDED_FOR
               | Request::HEADER_X_FORWARDED_HOST
               | Request::HEADER_X_FORWARDED_PROTO,
    );
})
```

Confiar em `*` reabre spoofing de `X-Forwarded-For`. Confie só no edge; quem sanitiza o
`CF-Connecting-IP` é o Nginx.

## 4. Armadilhas comuns

- **Rate limit inútil:** esqueceu o `set_real_ip_from`/`real_ip_header` → tudo vem do mesmo
  IP (Cloudflare) e o balde estoura junto para todo mundo.
- **fail2ban banindo a Cloudflare:** jail do Nginx lendo `$remote_addr` cru em vez do IP
  real → bane a Cloudflare inteira. Configure o fail2ban para o log com IP real.
- **Loop de redirect / "Too many redirects":** modo SSL "Flexible" na Cloudflare + Nginx
  forçando HTTPS. Use **Full (strict)**.
- **HSTS travado:** ativou HSTS com `includeSubDomains`/`preload` cedo demais e um subdomínio
  não tem HTTPS. Só ligue quando tiver certeza.
- **Webhook do Telegram:** ele chega **pela Cloudflare** também; garanta que a WAF/rate
  limit não bloqueie os IPs do Telegram e que a rota valide o secret/token.
- **Origem exposta:** DNS "cinza" (não-proxied) em algum registro vaza o IP real e permite
  contornar a Cloudflare. Mantenha proxied e/ou use mTLS + firewall só-Cloudflare.
