FROM node:24-alpine AS tooling

WORKDIR /app/frontend

COPY frontend/package*.json frontend/.npmrc ./
RUN npm ci --legacy-peer-deps

COPY frontend/ ./
COPY docker/frontend/ /app/docker/frontend/
COPY docker/nginx/default.conf /app/docker/nginx/default.conf
# P4-166 (B3) — le cliquet de couverture lit le plancher dans la maison UNIQUE à la
# racine du dépôt ; l'image tooling COPIE le code, il faut donc l'y copier pour que
# `../coverage-floor.json` (relatif à /app/frontend) résolve comme sur l'hôte et en CI.
COPY coverage-floor.json /app/coverage-floor.json

FROM tooling AS build

RUN npm run build

FROM nginx:1.27-alpine AS runtime
# SEC A19 — même règle que nginx-prod backend : ramener les paquets Alpine au
# niveau des dépôts (le gate Trivy vérifie derrière).
RUN apk upgrade --no-cache

COPY docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/frontend/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY docker/frontend/csp.conf /etc/nginx/snippets/csp.conf
COPY --from=build /app/frontend/dist /usr/share/nginx/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget --no-verbose --tries=1 --spider http://localhost/ || exit 1

# prod — **strictement le même runtime** (P4-118, 2026-08-21).
#
# Cet étage portait une seconde conf, `nginx.prod.conf`, qui se justifiait par une prod « sans le
# proxy /engine/ de debug ». Ce proxy a été retiré du DEV aussi le 2026-07-31 : les deux fichiers
# étaient depuis IDENTIQUES aux commentaires près, et on maintenait deux copies d'une même vérité.
# ⚠ La divergence aurait été SILENCIEUSE — personne ne diffe deux confs nginx — et elle se serait
# jouée du mauvais côté : durcir le dev sans que la prod suive, alors que c'est la prod qui sert
# les vrais clubs. `nginx.prod.conf` est donc supprimé, il n'y a plus qu'UNE conf.
#
# ⚑ L'étage, lui, RESTE — vide. Le nom `prod` est un contrat nommé à deux endroits : le scan
# Trivy de la CI (`docker build --target prod`) et `docker-compose.prod.yml`. Le garder coûte une
# ligne et laisse la couture nommée là où une vraie différence prod se poserait un jour ; le
# supprimer obligerait à toucher ces deux fichiers pour ne gagner qu'un nom.
FROM runtime AS prod

# Étage par défaut (le dernier) = le même runtime, pour que `docker build` nu et le compose dev
# marchent sans `--target`.
FROM runtime
