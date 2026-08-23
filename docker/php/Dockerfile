FROM php:8.4-fpm AS base

ARG UID=1000
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="/root/.composer/vendor/bin:${PATH}"

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        ca-certificates \
        unzip \
        libicu-dev \
        libzip-dev \
        libpq-dev \
        libonig-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        pkg-config \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        sockets \
        zip \
    # P4-89 — phpredis via PECL (miroir pecl.php.net), pas via codeload GitHub :
    # trois jobs CI tués le 2026-08-12 par un 503 GitHub sur cette couche.
    #
    # P4-124 — et le RETRY que P4-89 n'avait pas : changer de source n'avait pas traité le
    # mode d'échec, seulement déplacé (le 2026-08-22, un téléchargement TRONQUÉ — 95 714 octets
    # sur 399 284 — a tué la CI de la même façon que les 503 de 2026-08-12). Trois essais,
    # back-off 10 s/20 s, et le cache de téléchargement PEAR est PURGÉ avant chaque essai :
    # sans cette purge, le .tgz tronqué du 1er essai fait échouer le 2e sur « unable to
    # unpack » alors que le réseau, lui, est revenu. PAS de `--force` : il masquerait un
    # échec au lieu de le rejouer. Si les trois essais échouent, le build échoue FRANCHEMENT
    # — c'est `docker-php-ext-enable` qui reste le contrôle d'intégrité final (pas de .so,
    # pas d'image). Prouvé en cassant volontairement le téléchargement (voir la PR P4-124).
    && for attempt in 1 2 3; do \
         rm -rf /tmp/pear/download; \
         if pecl install redis-6.3.0; then break; fi; \
         if [ "$attempt" = 3 ]; then echo "pecl install redis: échec après 3 essais" >&2; exit 1; fi; \
         sleep $((attempt * 10)); \
       done \
    && docker-php-ext-enable redis \
    && echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/zz-memory.ini \
    && echo "max_execution_time = 0" >> /usr/local/etc/php/conf.d/zz-memory.ini \
    && rm -rf /var/lib/apt/lists/*

# Client PostgreSQL 16 (pg_dump/pg_restore/psql) pour app:db:backup / restore-check :
# le client Debian par défaut est en 15 et pg_dump refuse un serveur plus récent —
# on prend la version EXACTE du serveur (postgres:16) via le dépôt PGDG officiel.
RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg lsb-release \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/pgdg.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 \
    && rm -rf /var/lib/apt/lists/*

RUN usermod -u ${UID} www-data && groupmod -g ${GID} www-data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

EXPOSE 9000

# SEC-15 (2026-08-07) — cet étage `base` sert le DEV et la CI uniquement : le
# master php-fpm y reste root (il se setuide, les workers tournent www-data —
# pool www) parce que le bind-mount dev appartient à l'uid hôte et que les
# cibles Makefile passent déjà `-u` là où il faut. L'image de PROD, elle, est
# non-root : l'étage `prod` ci-dessous termine par `USER www-data`.
# nosemgrep: dockerfile.security.missing-user.missing-user
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# prod — self-contained image: the code and vendor/ live IN the image, no bind
# mount. Deploys pull a tagged image; rollback = run the previous tag. The
# default (base) stage above stays untouched for dev/CI compose builds.
FROM base AS prod

# rclone backs the optional BACKUP_SYNC_COMMAND off-site hook (DatabaseBackupCommand).
RUN apt-get update \
    && apt-get install -y --no-install-recommends rclone \
    && rm -rf /var/lib/apt/lists/*

# Prod PHP overrides (zz-prod sorts after zz-memory, so these win): bounded
# execution instead of the dev max_execution_time=0, and opcache that never
# re-stats files — safe ONLY because the code in this image cannot change.
# NB: NO global memory_limit here — the CLI SAPI (messenger-worker, cron jobs,
# pg_dump ticks) keeps the base image's 512M so Messenger's --memory-limit=256M
# graceful stop stays reachable. The 192M request cap is FPM-only, set as
# php_admin_value in the pool below.
RUN { \
        echo "max_execution_time = 60"; \
        echo "opcache.enable = 1"; \
        echo "opcache.validate_timestamps = 0"; \
        echo "opcache.memory_consumption = 192"; \
        echo "opcache.max_accelerated_files = 20000"; \
        echo "expose_php = Off"; \
    } > /usr/local/etc/php/conf.d/zz-prod.ini \
    && { \
        echo "[www]"; \
        echo "pm = dynamic"; \
        echo "pm.max_children = 4"; \
        echo "pm.start_servers = 2"; \
        echo "pm.min_spare_servers = 1"; \
        echo "pm.max_spare_servers = 3"; \
        echo "php_admin_value[memory_limit] = 192M"; \
    } > /usr/local/etc/php-fpm.d/zz-prod-pool.conf
# Memory model (worst case fits the cgroup): 4 children × 192M request limit
# + 192M opcache shm + ~30M master ≈ 990M < the 1g container cap
# (docker-compose.prod.yml) — a heavy request dies with a clean PHP memory
# error, the OOM-killer never takes the FPM master, and 4 children keep two
# slow 60s requests from freezing the whole API.

# .dockerignore keeps var/, vendor/, config/jwt and every *.local env file out
# of the build context — only committed code and safe env defaults get in.
COPY backend/ /app/backend/
WORKDIR /app/backend

ENV APP_ENV=prod
# --no-scripts: flex auto-scripts (cache:clear) would need runtime env/DB.
# Both steps are STRICT (no fail-open): a failed composer install must never
# ship a vendor-less image, and a failed assets:install would silently strip
# public/bundles from the nginx-prod stage below.
RUN composer install --no-dev --classmap-authoritative --no-interaction --no-progress --no-scripts
# assets:install just copies bundle assets but boots the kernel, and
# ProdSecretGuard rightly refuses every committed dev secret in prod (its 5
# GUARDED_KEYS). Throwaway build-time values satisfy it; they protect nothing
# (assets:install produces no state, opens no connection) and the real secrets
# come from the runtime env at deploy.
RUN APP_SECRET=build-throwaway \
    JWT_PASSPHRASE=build-throwaway \
    MERCURE_JWT_SECRET=build-throwaway \
    DATABASE_URL=postgresql://build:build@build:5432/build?serverVersion=16 \
    DATABASE_ADMIN_URL=postgresql://build:build@build:5432/build?serverVersion=16 \
    php bin/console assets:install public --env=prod --no-interaction

# Runtime-written dirs (volumes mount over most of them in the prod compose):
# var/backups (dumps), var/storage/logos, public/exports (pdf-worker), var
# cache/log, config/jwt (mounted read-only from the host at deploy time).
RUN mkdir -p var/cache var/log var/backups var/storage/logos public/exports config/jwt \
    && chown -R www-data:www-data var public/exports

USER www-data

# ---------------------------------------------------------------------------
# nginx-prod — the backend edge, built FROM the prod stage's public/ so it
# ships the assets:install output (public/bundles is gitignored: a COPY from
# the build context would silently miss it in a clean CI checkout, breaking
# /api/docs assets in every ghcr-built image).
FROM nginx:1.27-alpine AS nginx-prod
# SEC A19 — l'image de base traîne parfois des paquets Alpine troués (1er run
# Trivy : OpenSSL 3.3.3 avec CVE-2026-31789 CRITICAL, correctif déjà publié
# côté Alpine). L'upgrade au build ramène les paquets au niveau des dépôts —
# le gate Trivy de build-docker vérifie derrière.
RUN apk upgrade --no-cache

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /app/backend/public /app/backend/public

# Default (last) stage = base, so a target-less build — the dev compose's
# php-fpm/messenger-worker/cron-runner services — still gets the dev runtime.
# Prod builds pick their stage explicitly (--target prod / nginx-prod).
FROM base