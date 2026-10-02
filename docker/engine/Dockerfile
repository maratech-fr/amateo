# ---------------------------------------------------------------------------
# base — runtime commun : système, code applicatif, dépendances de PROD SEULES
# (`pip install "."`, sans l'extra [dev]). L'étage `prod` ci-dessous s'arrête ici ;
# l'étage `dev` (le dernier, défaut des builds sans --target) ajoute les outils.
FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1
ENV PIP_NO_CACHE_DIR=1

WORKDIR /app/engine

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates libgomp1 && rm -rf /var/lib/apt/lists/*

COPY engine/pyproject.toml ./pyproject.toml
COPY engine/CONTRACT_VERSION ./CONTRACT_VERSION
COPY engine/app ./app

# Dépendances de PROD UNIQUEMENT : `.` SANS l'extra [dev] — l'image de prod
# n'embarque donc NI pytest, NI ruff, NI mypy, NI bandit, NI deptry. Ces outils
# vivent dans l'étage `dev` ci-dessous (dev + CI), pas dans l'image déployée.
RUN pip install --upgrade pip && pip install "."

# SEC-15 — le solveur n'a AUCUNE raison de tourner root : il ne lit que son code
# et n'écrit rien (stateless, port 8000 > 1024). uid/gid 1000 délibérément :
# c'est l'uid hôte standard en dev (bind-mount ./ → les caches pytest/ruff
# écrits par `make test` restent possédés par l'hôte), et l'uid déjà utilisé
# par pdf-worker en prod. Le chown du WORKDIR couvre l'image immuable de prod
# (en dev, le bind-mount recouvre /app de toute façon).
RUN groupadd --gid 1000 engine && useradd --uid 1000 --gid 1000 --no-create-home engine \
    && chown -R engine:engine /app
USER engine

EXPOSE 8000

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]

# ---------------------------------------------------------------------------
# prod — cible du DÉPLOIEMENT (`deploy.yml` --target prod, `docker-compose.prod.yml`).
# Strictement `base` : aucun outil dev, pas de `tests/`. Image minimale, non-root,
# CMD/uvicorn hérités de `base`. La laisser nommée garde la couture là où une vraie
# différence prod se poserait un jour, et donne une cible explicite au build de prod.
FROM base AS prod

# ---------------------------------------------------------------------------
# dev — étage PAR DÉFAUT (le dernier) : ce que `docker compose build engine` (sans
# --target) produit pour le DEV et la CI (engine-tests, engine-semantics,
# engine-coverage, engine-perf) et `make -C engine test`. Ajoute les tests et les
# outils dev ([dev]). Repasse root le temps d'installer/copier (SEC-15 : l'uid
# engine, sans home et sans droit sur les site-packages, ne peut pas pip install),
# puis repart non-root.
FROM base AS dev
USER root
COPY engine/tests ./tests
RUN pip install ".[dev]" && chown -R engine:engine /app
USER engine

# Les caches des outils SORTENT du bind-mount : en CI le checkout appartient au
# runner (uid 1001), pas à `engine` (1000) — ruff refusait net (« Permission
# denied » sur .ruff_cache, attrapé au premier run CI de cette PR), et sur un
# poste dev ils cessent de polluer le dépôt. /tmp est writable pour tout uid.
ENV RUFF_CACHE_DIR=/tmp/ruff-cache \
    MYPY_CACHE_DIR=/tmp/mypy-cache
