# Épinglé (tag semver + digest) — `latest` rendait l'image non reproductible : un
# rebuild pouvait embarquer une paire puppeteer↔Chrome différente de celle qui tourne,
# sans qu'aucun diff ne le montre. 25.12.0 = la version déjà en service (Node 24).
# Mise à jour : Dependabot (écosystème `docker`, dossier docker/pdf-worker) propose
# désormais les bumps — relever le tag ET le digest ensemble, jamais l'un sans l'autre
# (le digest est la vérité immuable, le tag n'est qu'un libellé lisible).
FROM ghcr.io/puppeteer/puppeteer:25.12.0@sha256:60ad89b1d8ca14877120250b9c96694dd19a4eda5890fdbad64f4740c6a27361

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

# SEC-15 — défaut NON-ROOT : pptruser est l'utilisateur prévu par l'image
# puppeteer (sandbox Chromium comprise). Les deux composes l'écrasent
# explicitement (dev : uid hôte pour créer public/exports sur le bind-mount ;
# prod : 1000:1000, le même uid que le www-data de l'image php, pour partager
# le volume exports) — ce USER est le filet si l'image est lancée nue.
USER pptruser

CMD ["node", "worker.js"]
