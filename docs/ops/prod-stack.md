# Stack de production — `docker-compose.prod.yml`

> Livré 2026-07-25 (solde l'item P1 « config prod d'orchestration » + INF-03).
> Le déploiement en une commande (tag `v*` → ghcr.io → SSH) et le runbook
> première-mise-en-prod : [`deploy.md`](deploy.md).

## Principe

Fichier **autonome** (pas un overlay du compose dev) : images **immuables**
pulled par tag depuis ghcr.io, **zéro bind-mount de code**, pas de services dev
(mailpit, frontend-dev, frontend-tooling). La VM n'a besoin que de :

```
docker-compose.prod.yml     # ce fichier
.env.prod                   # secrets (chmod 600) — poussé par le deploy depuis .env.prod.gpg (repo)
jwt/                        # keypair JWT généré sur place (private.pem + public.pem)
```

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d
```

`.env.prod` nourrit à la fois l'interpolation compose et l'env runtime des
conteneurs PHP (`env_file`). Template exhaustif : `.env.prod.dist` (racine).
Sa source de vérité est le **`.env.prod.gpg` commité** (GPG symétrique,
`make env-encode@prod` / `env-decode@prod`) que le job deploy décode et pousse
sur la VM — détail : [`deploy.md`](deploy.md) § Secrets chiffrés.

## Images (self-contained — dev les obtient par bind-mount, prod par COPY)

| Image | Build | Contenu spécifique prod |
|---|---|---|
| `scheduler-php` | `docker/php/Dockerfile` target `prod` | code + `composer install --no-dev`, opcache `validate_timestamps=0`, `max_execution_time=60`, **rclone** (hook off-site), USER www-data |
| `scheduler-nginx` | `docker/php/Dockerfile` target `nginx-prod` | `backend/public` copié **depuis le stage php prod** (inclut `public/bundles` d'`assets:install`, gitignoré — un COPY du contexte le raterait en CI et casserait `/api/docs`) |
| `scheduler-frontend` | `docker/frontend/Dockerfile` target `prod` | conf edge **sans `location /engine/`** — le solveur n'a pas d'auth, il ne doit JAMAIS être joignable de l'extérieur. ⚑ **Une seule conf depuis P4-118** (`docker/frontend/nginx.conf`) : `nginx.prod.conf` en était une copie identique, et la cible `prod` du Dockerfile est désormais un alias vide de `runtime` — le nom survit parce que la CI et `docker-compose.prod.yml` le nomment |
| `scheduler-postgres` | `docker/postgres/Dockerfile.prod` | scripts init RLS/rôles copiés (la VM n'a pas le repo) ; `02-users.sh` lit `APP_USER_PASSWORD` de l'env — plus de mot de passe en dur au premier init |
| `scheduler-engine` / `scheduler-pdf-worker` | Dockerfiles existants | déjà self-contained (identiques dev) |

La CI (`build-docker`) build les targets prod à chaque commit : une casse du
stage prod red le job même si aucun build dev ne l'utilise.

## Sécurité réseau

- **Un seul port publié** : `frontend` sur `127.0.0.1:${FRONTEND_PORT}` — le
  reverse-proxy TLS de l'hôte (Caddy) est la vraie porte d'entrée.
- postgres, redis, engine, mercure, nginx : réseau interne uniquement.
- Mercure : `cors_origins` = `PUBLIC_BASE_URL` seul ; le navigateur passe par le
  proxy frontend (`/.well-known/mercure`).

## Accès opérateur à la base — jamais un port ouvert (décision 2026-08-21)

**Postgres ne publie AUCUN port hôte en prod** (`docker-compose.prod.yml`, service `postgres` :
pas de `ports:`) — il n'existe que sur le réseau Docker interne. **Ne l'ouvre jamais.** Un
Postgres joignable depuis Internet est balayé en heures, et cette base contient des données
personnelles de licenciés, mineurs compris.

**Pas de bastion.** Un bastion se justifie sur un réseau privé à plusieurs machines, pour avoir un
point d'entrée unique et audité. Sur une machine unique, l'hôte **EST** ce point d'entrée : ajouter
un bastion, c'est une seconde machine à patcher et à surveiller pour zéro sécurité de plus.
✅ **L'hébergeur est CHOISI : Scaleway, produit Instances** (décision fondateur 2026-08-21) — une
VM auto-gérée qui porte toute la stack Docker, **pas** de base managée. Le raisonnement ci-dessus
s'applique donc tel quel. ⚠ Si un jour la base passait sur un **Postgres managé** (Scaleway
Database ou autre), l'accès passerait par le réseau privé du fournisseur et ses ACL d'IP, et le
tunnel ci-dessous n'aurait plus lieu d'être — la présente section serait à réécrire, pas à adapter.

Deux gestes, par ordre de préférence :

```bash
# 1. Rien n'écoute, rien n'est publié — le plus sûr.
ssh <hôte> 'docker compose exec postgres psql -U <rôle> -d amateo'

# 2. Tunnel SSH, seulement si un client graphique est nécessaire (DBeaver, TablePlus).
#    Exige de publier le port sur la LOOPBACK de l'hôte (127.0.0.1:5432:5432), JAMAIS 0.0.0.0.
ssh -N -L 5433:localhost:5432 <hôte>   # puis se connecter à localhost:5433
```

SSH **par clé, authentification par mot de passe désactivée**.

### Avec quel rôle — la question qui compte

Trois rôles, et confondre les deux premiers est le risque réel (→ [`../security/rls.md`](../security/rls.md)) :

| Rôle | Portée | Usage |
|---|---|---|
| `amateo_app` | NOSUPERUSER, DML, **scopé par RLS** | la connexion runtime de l'application, jamais un humain |
| `amateo_owner` | propriétaire non-superuser, policies `admin_all` → **traverse le RLS, voit TOUS les clubs** | migrations et gestes de support qui l'exigent vraiment |
| *(à créer, P5)* lecture seule | `SELECT` seulement, **sans** `admin_all` donc scopé lui aussi | l'exploration courante depuis un poste |

⚠ **Le danger n'est pas théorique** : ouvrir un client graphique sur une session `amateo_owner`,
c'est rapatrier sur un portable les données personnelles de **tous** les clubs — et un `UPDATE`
mal collé y touche de vraies données de vrais clients, sans filet. Le rôle lecture seule existe
pour que le geste courant ne puisse rien casser ni tout voir.

## Limites RAM (INF-03) & logs

`mem_limit` par service (base v3 §2.2, ajustés pour laisser la limite PHP mordre
AVANT l'OOM-killer) : **php-fpm 640M** (2 children × `memory_limit` 192M +
opcache 192M + master — pool `zz-prod-pool.conf`) · nginx 64M · postgres 512M ·
redis 256M · **messenger-worker 384M** et **cron-runner 384M** (au-dessus du
`--memory-limit=256M` applicatif / du pg_dump des jobs) · engine 512M ·
pdf-worker 512M · mercure 128M · frontend 64M.
Rotation logs : `json-file` 10 Mo × 3 par service (ancre `x-logging`).
`restart: unless-stopped` partout. Healthchecks réels : php-fpm = accept
FastCGI (`fsockopen :9000`, pas un parse de conf) ; cron-runner = témoin de
**succès** (`/tmp/last-tick-ok` < 5 min — un `run-due` qui échoue en boucle
passe rouge au lieu de se cacher derrière « la boucle tourne »).

## Persistance (volumes nommés)

| Volume | Monté sur | Contient |
|---|---|---|
| `postgres_data` | postgres | la base |
| `redis_data` | redis (AOF) | queue Messenger + rate-limiters (survit au restart — le dev n'en a volontairement pas) |
| `backups` | php, worker, cron | dumps `pg_dump` (`var/backups`) |
| `logo_storage` | php, worker, cron | logos clubs (`var/storage`) |
| `exports` | pdf-worker (rw), php + worker (rw), nginx (ro) | PDF générés (`public/exports`) — uid 1000 partagé php/pdf-worker, vérifié |

`./jwt` est un bind **read-only** vers `config/jwt` (le keypair ne vit jamais
dans une image ni dans git). ⚠ Les conteneurs php tournent en **uid 1000** :
après génération du keypair sur la VM, `chown -R 1000:1000 jwt && chmod 600
jwt/private.pem && chmod 644 jwt/public.pem` — sinon stack verte mais **tous
les logins en 500** (commandes complètes dans `.env.prod.dist`).

## Répétition locale (validée 2026-07-25)

```bash
# secrets factices + keypair local, puis :
docker compose -p amateo-prod -f docker-compose.prod.yml --env-file .env.prod up -d
```

⚠ `-p amateo-prod` **obligatoire en local** : sans lui, compose réutilise
les volumes du projet dev (même dossier) — le cluster postgres dev serait monté
tel quel et les scripts d'init prod ne joueraient pas. Sur la VM le problème
n'existe pas. Preuve du 2026-07-25 : 10/10 healthy, migrations sur cluster
vierge, génération **COMPLETED** bout-en-bout, hook off-site exécuté, limites et
rotation vérifiées (`docker inspect`), `/engine/` renvoie le SPA (plus de proxy).

## Ce qui reste à l'hébergeur / au runbook deploy

TLS + domaine (Caddy), snapshots disque de la VM (couche « disque mort » des
backups — `backup-restore.md`), création VM/bucket, secrets réels, ghcr.io.
