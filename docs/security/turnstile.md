# Turnstile — anti-robot sur le register (livré inerte)

> Livré 2026-08-13 (P5-3b). **Inerte par défaut** : sans clé configurée, le register est
> byte-identique à l'avant-Turnstile et aucun script tiers ne se charge. Ce doc est le runbook
> d'activation + les décisions qui tiennent le design.

## Activer (2 gestes, zéro redéploiement frontend)

1. Créer le widget sur le dashboard Cloudflare (Turnstile est gratuit — plan Standard) :
   mode **Managed** recommandé (adaptatif — c'est un réglage dashboard, pas du code).
2. Poser les deux clés dans l'environnement du **backend** (`.env.prod.local`, jamais commité —
   `EnvHygieneTest` interdit `TURNSTILE_SECRET` dans `.env.prod`) :
   `TURNSTILE_SECRET=...` et `TURNSTILE_SITEKEY=...`, puis recharger php-fpm.

Le frontend découvre la sitekey **au runtime** via `GET /api/register/config` et n'affiche le
widget que si elle existe — pas de variable de build, pas de rebuild d'image. Désactivation =
vider les clés.

## Décisions (ne pas re-trancher sans fait nouveau)

- **Sitekey servie au runtime, pas de chaîne `VITE_*`** : l'activation est atomique côté
  backend ; l'option build-time créait un mode de panne (secret posé sans rebuild front =
  register briqué) et exigeait un rebuild par bascule.
- **Fail-open sur transport, fail-closed sur verdict** (`TurnstileVerifier`) : `success:false`
  → 403 ; Cloudflare injoignable (timeout 5 s) → on laisse passer en loggant. Une panne du
  tiers ne coûte jamais un inscrit ; le limiteur register 5/15 min, la vérification email
  obligatoire et les quotas restent en dessous.
- **SSRF-safe** : URL `siteverify` en constante, jamais dérivée d'une entrée, `max_redirects: 0`
  (invariant CLAUDE.md §6, patron FFBB).
- **Anti-oracle préservé** : la vérification s'insère avant tout lookup — le 403 ne dépend que
  du token, jamais de l'existence de l'email (gardé par `RegisterTurnstileTest`, corps
  identique email frais/existant).
- **Exception CSP posée d'office** (décision fondateur) : `challenges.cloudflare.com` en
  `script-src` + `frame-src` dans `docker/frontend/csp.conf` — seul écart au « aucun hôte
  tiers », gardé par `frontend/tooling/turnstileCsp.test.ts` ; `connect-src` non requis
  (l'iframe du challenge appelle son propre origin). Le script tiers ne se charge que si notre
  code l'injecte, ce qui n'arrive que sitekey présente.

## Gardes

`Security/RegisterTurnstileTest` (step de `blocking-tests`, liste `docs/testing/blocking-tests.md`) : sans clé →
register intact (le kill-switch) ; avec clé → token exigé et vérifié sur l'URL en dur ;
fail-open transport ; 429 du limiteur toujours premier. Périmètre : **register uniquement** —
étendre à login/forgot/coach-wish serait un nouveau lot.
