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
# Produção: copia o código e otimiza. Usado pelo docker-stack.yml (Swarm).
# ---------------------------------------------------------------------
FROM base AS prod

COPY . /app
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && rm -rf /app/.env \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache
