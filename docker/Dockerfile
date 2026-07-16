# =====================================================================
# Imagem única para app e worker (mesma imagem, comandos diferentes).
# Base FrankenPHP: serve HTTP + PHP no mesmo processo (sem nginx separado).
# Stage `base` = runtime de desenvolvimento (código via bind-mount).
# Stage `prod`  = build de produção (código COPIADO + autoload otimizado).
# =====================================================================
FROM dunglas/frankenphp:1-php8.3 AS base

# Extensões PHP necessárias (PostgreSQL, sqlite p/ testes, intl, bcmath p/ dinheiro)
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        pdo_sqlite \
        intl \
        zip \
        bcmath \
        opcache \
        pcntl

# Bibliotecas de sistema: OCR (Tesseract pt) embutido no worker, utilitários
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        tesseract-ocr \
        tesseract-ocr-por \
        poppler-utils \
        git \
        unzip \
        curl \
    && rm -rf /var/lib/apt/lists/*

# Evita "dubious ownership" do git em diretório montado (bind-mount em dev).
RUN git config --global --add safe.directory '*'

# Composer (binário copiado da imagem oficial)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "-c", "/etc/caddy/Caddyfile"]

# ---------------------------------------------------------------------
# Assets: compila o frontend (Vite + Tailwind v4) num stage Node isolado.
# public/build é gitignored → NÃO vai no Git. Um checkout limpo (o que o CI
# faz) não traz os assets; sem este stage o @vite quebra em produção
# ("Vite manifest not found") e toda página dá 500. Funciona só localmente
# porque o public/build da sua máquina entra no `COPY . /app`.
# ---------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
# Tailwind v4 (@tailwindcss/vite) varre os templates/js para gerar as classes,
# então precisa do fonte completo (resources/ + views/) presente no build.
COPY . .
RUN npm run build

# ---------------------------------------------------------------------
# Produção: copia o código e otimiza. Usado pelo docker-stack.yml (Swarm).
# ---------------------------------------------------------------------
FROM base AS prod

# Tuning de opcache só em prod (validate_timestamps=0 quebraria o bind-mount de dev).
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

COPY . /app
# Assets compilados vêm do stage Node (public/build é gitignored, não está no
# contexto num checkout limpo). Precisa vir DEPOIS do `COPY . /app`.
COPY --from=assets /app/public/build /app/public/build
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && rm -rf /app/.env \
    # /data e /config são do Caddy (XDG_* na imagem FrankenPHP); precisam ser
    # graváveis pelo usuário não-root.
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

# Rootless: RCE via PDF malicioso (poppler/tesseract) não pode virar root no
# contêiner. Porta 8000 é não-privilegiada, então não precisa de setcap.
USER www-data
