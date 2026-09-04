# JWT applicatif — cookie httpOnly (SEC-16 audit)

> Status : livré le 2026-08-07. Concerne l'identité **club** (`User`). L'identité
> **superadmin** est séparée et déjà en session (`specs/courantes/superadmin-auth.md`).

## Ce qui a changé, et pourquoi

Le JWT vivait dans `localStorage` (`frontend/src/shared/stores/authStore.ts`,
persisté sous la clé `cs-auth`). Toute exécution de JS sur l'origine — XSS,
extension de navigateur, console ouverte sur un poste partagé — pouvait le lire
et le **rejouer ailleurs** : depuis la machine de l'attaquant, pendant toute la
durée de vie du jeton (`token_ttl`, 1 h par défaut).

Il est désormais posé par le serveur en **cookie httpOnly**, que le JS ne voit
pas (`document.cookie` ne le contient pas).

**Ce que cela ne fait PAS.** Une XSS garde l'autorité ambiante : depuis la page,
elle peut toujours émettre des requêtes authentifiées. On supprime
l'**exfiltration** (le rejeu ailleurs, plus tard, en masse), pas l'attaque.
Dire l'inverse serait mentir sur la portée du correctif.

## Le contrat

| Propriété | Valeur | Où |
|---|---|---|
| Nom | `BEARER` | paramètre `app.jwt_cookie_name` (`backend/config/services.yaml`) |
| Chemin | `/api` | `app.jwt_cookie_path` — le navigateur ne l'envoie qu'à l'API, jamais aux assets ni aux exports |
| `httpOnly` | oui | `lexik_jwt_authentication.yaml` (`set_cookies.BEARER`) |
| `SameSite` | `strict` | idem — **c'est toute la défense CSRF** de ce cookie |
| `Secure` | `%env(bool:JWT_COOKIE_SECURE)%` | **défaut `true`** (fail-closed) ; `false` posé explicitement en dev/CI |
| Durée | `token_ttl` (1 h) | héritée, non redéfinie |

Les quatre propriétés qui font tenir le correctif sont gardées **ensemble** par
`backend/tests/Security/JwtCookieContractTest.php` (groupe `phase1`) : le jeton
quitte le corps, le cookie est `httpOnly`, il est `SameSite=Strict`, et il
authentifie **seul** — sans cette dernière, le front aurait besoin d'en garder
une copie lisible, et le jeton reviendrait en `localStorage`.

## Émission, extraction, effacement

- **`POST /api/login`** — handler lexik + `set_cookies`. La réponse est un
  **204 sans corps** : `remove_token_from_body_when_cookies_used` (défaut `true`)
  retire le jeton du JSON, et il ne restait rien d'autre.
- **`POST /api/register/verify`** — ce chemin crée le jeton à la main
  (`AuthController`), donc il pose le cookie via `App\Security\JwtCookieFactory`,
  qui lit **les mêmes paramètres** que le handler. Deux recettes d'attributs
  auraient dérivé.
- **Extraction** : `token_extractors.cookie` **et** `authorization_header` sont
  actifs. Le navigateur s'authentifie par le cookie ; les **scripts d'ops, contexts
  Behat et helpers e2e** continuent en `Bearer` — ils lisent le jeton dans l'en-tête
  `Set-Cookie` (`backend/tests/Behat/BaseContext.php` — `decodeWithHeaders`,
  `backend/scripts/generate-schedule.sh`). Ce n'est pas un reliquat : un script
  n'est pas un navigateur, et le vol de `localStorage` ne le concerne pas.
- **`POST /api/logout`** (nouveau) — efface le cookie. Avec un cookie httpOnly,
  cette route n'est pas un confort : **le JS ne peut pas effacer ce qu'il ne voit
  pas**, donc sans elle « Se déconnecter » laisserait la session vivre jusqu'à son
  terme. `PUBLIC_ACCESS` assumé (`security.yaml`) : le geste est idempotent, ne
  révèle rien, et l'exiger authentifié rendrait indéconnectable une session déjà
  expirée — le cas où l'on en a le plus besoin.

## ⚠ `Secure` vient d'une variable d'env, jamais de `$request->isSecure()`

Les deux extrémités mentent, en sens inverse :

- **en production**, le nginx du front écoute en 80 derrière la terminaison TLS et
  réécrit `X-Forwarded-Proto` avec `$scheme` (`docker/frontend/nginx.conf:53,69,81` — une seule conf depuis P4-118)
  → `isSecure()` répond **faux**, et le cookie serait parti **sans `Secure`** ;
- **en CI**, l'e2e dockerisé tape `http://frontend-dev:5173`
  (`docker-compose.yml:59`), une origine non sûre où un cookie `Secure` ne serait
  **pas stocké du tout** — toute la suite tomberait.

D'où `JWT_COOKIE_SECURE`, explicite des deux côtés. ⚠ **Où la variable est posée
compte autant que sa valeur** — c'est le défaut que la revue sécurité de la PR a
attrapé : `backend/.env` **entre dans l'image de production** (`.dockerignore` l'y
garde délibérément, avec `.env.prod`), donc un `false` posé là pour le confort du
dev **gagne sur le défaut `true`** de `services.yaml` — qui ne s'applique que si
la variable n'existe nulle part — et la prod partirait sans `Secure`.

La répartition qui tient l'invariant :

| Fichier | Valeur | Dans l'image prod ? | Chargé quand |
|---|---|---|---|
| `backend/.env` | **absente** (commentaire explicatif) | oui | toujours |
| `backend/.env.dev` · `backend/.env.test` | `false` | **non** (`.dockerignore`) | seulement pour leur `APP_ENV` |
| `backend/.env.prod` (committé, sans secret) | `true` | oui | `APP_ENV=prod` |
| `config/services.yaml` | défaut `true` | — | si aucun fichier ne la porte |
| `.env.prod` **de la VM** | `true` (runbook) | — | gagne sur tout (vraie variable d'env) |

Gardé par `backend/tests/Unit/JwtCookieSecureDefaultTest.php` (phase1) : le
fichier qui part dans l'image ne peut plus dire `false`, `.env.prod` doit dire
`true`, le défaut du conteneur doit rester `true`, et le `false` de dev doit
rester dans des fichiers exclus de l'image. Ce qu'aucun test ne peut couvrir :
une **vraie variable d'environnement** posée à `false` sur la VM — elle gagne sur
tout fichier, d'où la vérification au runbook (`docs/ops/deploy.md`).

## Le cookie Mercure suit la même règle

`MercureAuthController` pose un second cookie (le jeton de souscription du hub,
`mercureAuthorization`, path `/.well-known/mercure` — voir
[`mercure.md`](mercure.md)). Il lit **le même `JWT_COOKIE_SECURE`** : c'est un
jeton signé lui aussi, exposé au même mensonge de `isSecure()` derrière le nginx
de prod. Une seule question, une seule réponse — deux sources auraient dérivé.
Gardé par `MercureAuthTest::testTheCookieSecureFlagFollowsTheConfigurationNotTheRequestProtocol`
(requête vue comme https, cookie qui doit rester non-`Secure` en test).

## Côté front

`authStore` ne porte plus qu'un booléen `isAuthenticated` — un **indice d'UI**
qui évite un aller-retour clignotant vers `/login`, pas une autorisation : la
frontière reste le serveur, qui répond 401 si le cookie a expiré (le client vide
alors le drapeau et redirige, `frontend/src/shared/api/client.ts`). Un drapeau à
`true` sans cookie valide ne donne accès à rien.

La migration `version: 2` du store **efface** un jeton déjà persisté au lieu de le
reporter : sans elle, le JWT d'un utilisateur connecté avant le déploiement
resterait en `localStorage` — la faille survivrait à sa propre correction.

## Piège de test (browser-kit et les cookies)

Depuis que l'identité est un cookie, `KernelBrowser` la **rejoue tout seul** d'une
requête à la suivante. Une suite qui inscrit deux comptes envoyait le second
`POST /api/register` signé du premier : l'API l'authentifiait, le quota par
utilisateur (SEC-11) comptait ces appels sur le compte précédent, l'inscription
rendait 429 et l'échec ressortait dix lignes plus loin en « user null ». D'où
`App\Tests\StartsFreshBrowserSession`, appelé là où l'identité change. Un vrai
navigateur ferait pareil : ce que le harnais ne faisait pas, c'est **changer de
session**.
