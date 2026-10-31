# Épinglé (tag semver + digest) — `latest` rendait l'image non reproductible : un
# rebuild pouvait embarquer une paire puppeteer↔Chrome différente de celle qui tourne,
# sans qu'aucun diff ne le montre. 25.12.0 = la version déjà en service (Node 24).
# Mise à jour : Dependabot (écosystème `docker`, dossier docker/pdf-worker) propose
# désormais les bumps — relever le tag ET le digest ensemble, jamais l'un sans l'autre
# (le digest est la vérité immuable, le tag n'est qu'un libellé lisible).
FROM ghcr.io/puppeteer/puppeteer:25.16.0@sha256:956a72e3d523c76606efaad23392fbe33950370189f749715222c7427f57392b

WORKDIR /app/frontend

# worker.js imports node builtins + puppeteer, et c'est le SEUL consommateur.
#
# `frontend/package.json` ne déclare DÉLIBÉRÉMENT pas puppeteer : seule la paire
# puppeteer↔Chrome de l'image de base est garantie cohérente. Un paquet installé
# par npm dériverait de ce cache et le worker ne trouverait aucun navigateur.
# D'où le lien symbolique — et, étant un lien, il se résout à l'exécution contre
# le conteneur courant, donc le volume pdf_worker_node_modules ne peut jamais
# figer une copie périmée.
#
# Le déclarer côté npm n'apporterait rien et coûterait : ni `tsc` (limité à
# `src`) ni ESLint (limité aux `.ts/.tsx`) ne voient ce fichier, aucun test ne
# l'importe, et son postinstall téléchargerait un Chrome complet à chaque
# `npm ci` — deux fois par run de CI — pour un binaire jamais utilisé.
RUN mkdir -p node_modules \
    && ln -s /home/pptruser/node_modules/puppeteer node_modules/puppeteer

COPY frontend/worker.js ./worker.js
# Heartbeat admin (builtins uniquement — AUCUNE dépendance npm, voir worker-heartbeat.js).
COPY frontend/worker-heartbeat.js ./worker-heartbeat.js

# CVE-2026-13221 (2026-10-19) — 12 CRITICAL sur perl/perl-base/libperl5.36/
# perl-modules-5.36 en 5.36.0-7+deb12u3, corrigé par Debian en 5.36.0-7+deb12u4 et
# hérité de la base Debian 12 de l'image puppeteer. Le correctif existe déjà dans apt
# mais la base puppeteer n'a pas encore été reconstruite dessus : on monte ces seuls
# paquets sur place. L'image puppeteer tourne en pptruser (non-root), apt exige root
# → USER root le temps du RUN, puis on redescend en pptruser juste après.
# ⚠ À RETIRER quand Dependabot montera une base puppeteer embarquant deb12u4 (le FROM).
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade \
        perl perl-base libperl5.36 perl-modules-5.36 \
    && rm -rf /var/lib/apt/lists/*

# SEC-15 — défaut NON-ROOT : pptruser est l'utilisateur prévu par l'image
# puppeteer (sandbox Chromium comprise). Les deux composes l'écrasent
# explicitement (dev : uid hôte pour créer var/exports sur le bind-mount ;
# prod : 1000:1000, le même uid que le www-data de l'image php, pour partager
# le volume exports) — ce USER est le filet si l'image est lancée nue.
USER pptruser

CMD ["node", "worker.js"]
