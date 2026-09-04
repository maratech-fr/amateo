---
name: audit
description: Audit complet du projet ClubScheduler (doc, besoin, code par brique, sécurité, cybersécurité — surface d'attaque/protection contre les attaques, supply chain, infra, UX — cohérence / simplicité-intuitivité / inclusivité-a11y) avec notes /100, barème fixe, score UX additif noté à part, registre de findings à ID stables et résultat horodaté dans specs/audit/. À invoquer via /audit pour produire une édition comparable aux précédentes.
---

# Audit ClubScheduler — protocole reproductible

Tu produis une **édition d'audit** comparable aux éditions précédentes stockées dans `specs/audit/`. La comparabilité prime sur tout : même barème, même structure, IDs de findings stables.

## Étape 0 — Préparation

1. Lis `CLAUDE.md`, puis la **dernière édition** dans `specs/audit/AUDIT-*.md` (la plus récente par date de nom de fichier). Récupère son registre de findings.
2. Note : SHA HEAD (`git rev-parse --short HEAD`), date du jour, modèle utilisé (ton ID de modèle exact).
3. Vérifie l'état de la stack : `docker compose ps`. Remplis le **tableau de couverture** (voir format de sortie) — chaque axe est `couvert` / `partiel` / `non couvert (raison)`. Un axe non couvert ne donne PAS de note.

## Étape 1 — Collecte (agents parallèles)

Lance 5 agents d'analyse en parallèle (lecture seule, aucun test destructif) :

- **Doc** : exactitude (sonder 5-6 affirmations clés de CLAUDE.md/project-map/AGENTS.md/TENANT.md contre le code), structure (duplication, doc morte), utilité pour un agent IA, tenue du cycle specs (initiales figées / courantes à jour vs PRs récentes / graduation evolution→courantes).
- **Backend** (`backend/`, statique) : architecture (classes > 300 lignes, couplage), multi-tenant (TenantFilter, RLS réel — vérifier `CREATE POLICY` dans migrations + init SQL, PAS seulement la doc), async (failure_transport, retries, idempotence, locks), Doctrine (migrations, N+1), tests (couverture des trous, pas seulement des chemins heureux — notamment entités SANS club_id), config, sécurité (exposition API Platform opération par opération, validation input, contrôles d'appartenance sur les routes custom).
- **Engine** (`engine/`, statique) : architecture, modèle CP-SAT, **vérifier que chaque contrainte parsée depuis le payload backend réel est effectivement appliquée** (suivre le format de bout en bout : frontend → ConstraintSerializer → parse engine → matching ; l'alignement 3 couches est traité à part en **Étape 2 quater**, sur la base de `engine/docs/constraint-vocabulary.md`), concurrence (solve CPU-bound vs event loop), schemas/contrat, tests (les pipelines de test correspondent-ils au chemin prod `main._solve` ?), robustesse (logging, erreurs), **déterminisme & rejouabilité** : même input + `solver_seed` ⇒ même résultat ? — **vérifier le régime réel au code** (`_adaptive_workers` : un portfolio multi-workers *peut* rendre l'*assignment* non déterministe à valeur objective stable ; le **confirmer**, ne pas le présumer — si la politique a changé depuis, le dire) ; un solve/incident est-il **rejouable** depuis le snapshot figé (`GenerateScheduleHandler`, **côté backend** — le lire même si hors `engine/`) ; diagnostics INFEASIBLE **explicables** (cause actionnable pour le gestionnaire, pas un code opaque).
- **Frontend** (`frontend/`, exécuter `npx tsc --noEmit` et `vitest run`) : architecture, types (générés vs manuels, duplication), data-fetching (gestion erreurs queries ET mutations, temps réel), features principales, tests, a11y/qualité.
- **UX** (`frontend/src/`, statique — collecte reproductible pour les axes additifs de l'Étape 2 ter) : inventorier les **primitives partagées** (`shared/components/ui/*` + primitives métier type `SummaryRow`/`VenueSwatch`/`TeamTierAccordion`) et lister les **one-off** qui ré-implémentent un pattern existant ; relever les **valeurs de style en dur** (`#hex`, couleurs hors palette/tokens du thème) ; repérer les **divergences de terminologie** dans les libellés FR visibles (ex. « gymnase » vs « salle », « créneau » vs « slot », « coach » vs « entraîneur ») ; relever le **jargon technique exposé au gestionnaire** (`HARD`/`PREFERRED`, `socle`, `overlay`, `slot`…) ; recenser les cas où l'**information repose sur la seule couleur** (sans icône/label/texte redondant) et l'état des `aria-label`/`role`/`alt`/focus. Rendre des `fichier:ligne`, pas un avis.

## Étape 2 — Checks directs (toi-même, pas les agents)

- **Supply chain** : `npm audit --omit=dev` (frontend, host), `docker compose exec -T php-fpm composer audit`, `docker compose exec -T engine pip-audit` (installer si absent).
- **Infra/Mercure** : lire la config du hub dans `docker-compose.yml` (directives `anonymous`, `cors_origins`, clés JWT), bindings de ports, limites de ressources.
- **Prod-readiness/observabilité** : Sentry câblé ? backups PostgreSQL (`pg_dump`/scripts/volumes) ? limites RAM appliquées ? healthchecks ? config prod distincte ?
- **Secrets** : `git ls-files` sur les emplacements sensibles (`backend/config/jwt/`, `.env*`) — vérifier ce qui est réellement tracké avant d'affirmer une fuite.
- **RGPD (statique)** : purge/rétention implémentées ? audit trail ? données de mineurs/coachs exposées ?
- **Perf (si stack up)** : lancer `make -C backend behat` (feature `generation-du-planning-de-saison.feature`) et relever le wall-time. Sinon marquer l'axe `non couvert`.
- **UX** : traité à part en **Étape 2 ter** (axes additifs cohérence / simplicité / inclusivité).

## Étape 2 bis — Chasse aux angles morts (OBLIGATOIRE)

Chaque édition doit **chercher ce que l'édition précédente n'a pas regardé**. Liste les axes non couverts ou partiels de l'édition précédente : couvre-en au moins un de plus si l'environnement le permet (stack up → perf ; navigateur → UX). Puis pose-toi la question : « quel angle mort aucune édition n'a encore ouvert ? » (exemples de candidats : tests de charge API, migration de données entre versions, comportement offline/latence, i18n, coûts d'infra à N clubs, onboarding d'un 2e développeur, restauration après corruption). Ajoute au moins un axe candidat au tableau de couverture — même marqué `non couvert`, il devient visible et redevable.

## Étape 2 ter — UX : cohérence, simplicité, inclusivité (axes ADDITIFS)

Trois axes **notés à part du `/100` des briques** (voir Étape 4). Ils traduisent « intuitif pour tous » en **proxys objectifs et reproductibles** — jamais en ressenti. Le **statique est toujours couvert** ; le **dynamique (parcours navigateur)** est un bonus quand un navigateur est dispo, sinon l'axe concerné est `partiel`. Persona de référence : **le gestionnaire de club lambda (non technique)**.

### Axe UX-COHÉRENCE (statique — toujours couvert)
« Même chose, au même endroit, de la même façon » — pour que le gestionnaire ne se sente jamais perdu. Mesurer, pas ressentir :
- **Réutilisation des primitives** : à partir de l'inventaire de l'agent UX, compter les **one-off** qui ré-implémentent un composant/pattern partagé (ex. pastille de gymnase recodée en inline au lieu d'un `VenueSwatch`, formulaire ad-hoc au lieu des primitives `ui/*`). Chaque one-off récurrent = un finding `UXC-`.
- **Tokens de design** : compter les **valeurs en dur** (`#hex`, couleurs/espacements hors palette du thème) vs tokens (`accent`/`border`/`muted`/`destructive`…).
- **Terminologie** : un concept = un mot partout. Lister les synonymes divergents dans les libellés FR (« gymnase »/« salle », « créneau »/« slot », « coach »/« entraîneur »).
- **Patterns répétés** : états **vides / erreur / chargement** rendus par les mêmes composants ; en-têtes de section, badges, boutons d'action, emplacements de nav cohérents d'un écran à l'autre.

### Axe UX-SIMPLICITÉ & INTUITIVITÉ (statique + dynamique si navigateur)
Charge cognitive pour le gestionnaire lambda :
- **Flux-clés** : compter les étapes/clics des tâches principales (onboarding wizard, générer un planning, placer un match, signaler une indisponibilité). Signaler tout flux inutilement long ou à embranchements confus.
- **Jargon exposé** : termes techniques visibles sans traduction/explication (`HARD`/`PREFERRED`, `socle`, `overlay`, `slot`…).
- **Charge d'écran** : densité, hiérarchie visuelle, **une action principale claire par écran** ; guidage (libellés d'action explicites, messages d'erreur **actionnables**, états vides qui disent quoi faire).
- **Dynamique (si navigateur)** : parcourir wizard + planning + cockpit via Playwright, **capturer**, juger le parcours réel. Sans navigateur → axe `partiel` (proxys statiques seuls), le dire.

### Axe INCLUSIVITÉ / A11Y (statique + dynamique si navigateur)
« Pour tous » au sens handicap — malvoyants, daltoniens, moteur, lecteurs d'écran :
- **Couleur seule INTERDITE** : toute information portée uniquement par la couleur est un défaut (daltonisme). L'info doit être **redondée** par icône + label + texte (ex. gymnase = pastille **+** nom ; INTERDIT = couleur **+** icône STOP). Chasser les « couleur seule », surtout sur les flux critiques.
- **Contraste** : paires texte/fond conformes **WCAG AA** (statique : inspecter les paires de tokens ; dynamique : outil de contraste). Vérifier clair **et** sombre.
- **Clavier** : focus visible, ordre logique, pas de piège de focus (modales), tout actionnable au clavier.
- **Lecteurs d'écran** : `aria-label`/`role`/`alt` présents ; emojis porteurs d'info doublés d'un label textuel (`title`/`aria-label`) ; landmarks/titres.
- **Cibles** : taille minimale des zones cliquables.

> La notation de ces axes est **extrêmement sévère** (barre = app **simple d'utilisation ET robuste en tout point**) : plafonds durcis, score général = le plus bas des sous-axes — voir Étape 4. Un défaut critique confirmé (couleur seule sur flux critique, flux-clé infaisable au clavier, contraste AA échoué sur texte principal, jargon bloquant) **plafonne son sous-axe à 40**.

## Étape 2 quater — Alignement contraintes 3 couches (OBLIGATOIRE)

Le motif « contrainte saisie ≠ contrainte honorée » (ENG-10/11/12/13/16) est **la** faiblesse récurrente du produit. Cette étape le traque **systématiquement** (findings préfixe `ALIGN-`), au-delà de l'agent Engine bout-en-bout.

**Checklist = les 3 docs de référence** (les lire, puis vérifier au code) : `frontend/docs/constraint-emission.md` (ce que le wizard émet + **table d'alignement**), `engine/docs/constraint-vocabulary.md` (ce que l'engine comprend), `backend/docs/constraint-coverage.md` (besoins gestionnaire couverts).

Pour **chaque ligne** de la table d'alignement (chaque clé de `config` + chaque mode du wizard) :
1. **Front émet ?** — lire `ConstraintsStep.tsx` `build()` : quelle `config` pour ce mode/famille.
2. **Backend transmet/transforme ?** — `ScheduleConstraintBuilder` (targetTag→N TEAM, `venue_closed`→`forbiddenVenueId`, HARD `preferredVenueId`→forcé + exclusivité tag).
3. **Engine honore ?** — `parse_v2_constraints` / `add_time_window_constraints` / `objective.py` : la clé est-elle lue, et le mécanisme (dur/soft) est-il celui promis par l'UI ?

**Findings à sortir :**
- **Scission** (`ALIGN-`, gravité selon l'impact) : une clé émise par le front que le backend droppe ou que l'engine n'applique pas comme promis (ex. ENG-16 : « uniquement » émis en `forcedDays` = « au moins un »). **Sur un flux critique = Élevée minimum.**
- **Angle mort** (`ALIGN-`, gravité selon la valeur métier) : un besoin de `backend/docs/constraint-coverage.md` marqué ❌/🟡 (ex. « au moins une séance dans tel gymnase », `maxEndTime`, anti-jours-consécutifs) — le noter même s'il est connu, pour le rendre redevable.
- **Doc périmée** : une des 3 docs qui ne colle plus au code (comme un mensonge CLAUDE.md) → finding `DOC-`.

**Vérification** : les scissions confirmées se re-vérifient à la main (Étape 3). Le verrou automatique existant (`constraint_matrix.py` + test) ne couvre QUE l'offre wizard↔engine des cellules **offertes** — il ne voit ni la couche backend ni les angles morts ; ne pas s'y fier pour cette étape.

## Étape 2 quinquies — Cybersécurité : surface d'attaque & protections (OBLIGATOIRE)

Axe **dédié et systématique** : « l'app est-elle protégée contre les attaques ? ». La sécurité était jusqu'ici éparse (tenant/RLS en Backend, supply-chain/secrets/Mercure en Étape 2) ; ici on la traque **vecteur par vecteur**, du point de vue d'un **attaquant** (anonyme OU utilisateur authentifié malveillant). **Non destructif** : lecture de code + config uniquement — **aucun test offensif actif** (pas de fuzzing, pas de brute-force réel, pas d'exploit). Findings préfixe **`SEC-`**.

### Liste des attaques les plus probables pour ClubScheduler (à verdicter une par une)

**Colonne vertébrale de l'axe.** Cette liste est **fermée et stable** (comme le registre de findings) : on la parcourt **intégralement** à chaque édition, chaque ligne reçoit un verdict `protégé / partiel / absent / non vérifié` + preuve `fichier:ligne` + `SEC-` si défaut. C'est un **SaaS multi-tenant** (Symfony/API Platform · React SPA · FastAPI/OR-Tools · JWT · Mercure · Redis · Postgres RLS · upload logo · futur import FFBB) — la liste reflète **sa** surface réelle, pas une checklist générique. Ne pas retirer une ligne parce qu'elle « semble couverte » : la vérifier et l'écrire.

| # | Attaque (nommée, concrète) | Protection attendue | Vecteur |
|---|---|---|---|
| A1 | **Accès cross-tenant** — un gestionnaire du club X lit/écrit les données du club Y (forcer `X-Club-Id`/`X-Season-Id`, IDOR sur un UUID d'une autre org) | Résolution tenant serveur (JWT), RLS `FORCE` sur `club_id`, header espoofé → 403 | Autorisation/IDOR |
| A2 | **Brute-force / credential stuffing** sur `/login` (password spray sur emails devinés) | Rate-limit + éventuel lockout, latence/réponse uniforme | Auth · Rate-limit |
| A3 | **Énumération de comptes** — register/login/reset révèlent si un email existe (réponse ou timing) | Réponses & latences uniformes, message générique | Auth |
| A4 | **Falsification de JWT** — `alg:none`, secret faible, token expiré accepté, confusion HS/RS | Algo fixé, clé forte hors repo, exp courte, vérif signature | Auth |
| A5 | **Escalade de privilège** — un membre non-management déclenche une écriture cockpit/gestion | Gate rôle management (SEC-07) sur toutes les écritures sensibles | Autorisation |
| A6 | **Mass assignment / over-posting** — poser `clubId`/`role`/`isActive` via le payload d'un DTO | Champs sensibles non bindables, fixés serveur (processors) | Autorisation |
| A7 | **Injection SQL** — via un champ libre ou le GUC `app.club_id` | Doctrine paramétré partout, GUC en `bindValue`, zéro concat | Injection |
| A8 | **XSS stockée/reflétée** — nom d'équipe/club/coach rendu en HTML, **SVG de logo** exécutable | Échappement React par défaut, pas de `dangerouslySetInnerHTML`, SVG rejeté/assaini | Injection |
| A9 | **CSRF** — écriture déclenchée via un cookie envoyé automatiquement | Auth par `Bearer` (pas de cookie ambiant d'écriture) — à **vérifier** | CSRF |
| A10 | **DoS par bombe de génération** — payload énorme (équipes×gymnases) qui épuise le solveur | Bornes de taille de payload + complexité, timeout solveur | Rate-limit/DoS |
| A11 | **Spam de routes anonymes** — register/reset en masse (email bombing) | Rate-limit sur les routes non authentifiées | Rate-limit/DoS |
| A12 | **SSRF** — URL de logo / futur import FFBB pointée vers un service interne | URL sortante fixe (engine) ; fetch externe borné (pas de redirection vers IP interne, taille/MIME) | SSRF |
| A13 | **Abus d'upload (logo)** — MIME spoofé, fichier géant, SVG, chemin devinable | Validation MIME réelle + taille + type, stockage/serve sûrs | Upload |
| A14 | **Fuite Mercure** — abonnement anonyme ou à un topic d'un autre club (SSE cross-club) | Hub non-anonyme, JWT d'abo scoping le topic `club:{id}:…` | Autorisation |
| A15 | **Exposition de secrets** — clés JWT/DB/Mercure ou `.env` trackés dans git | `git ls-files` propre, secrets hors repo | Secrets |
| A16 | **Fuite via erreurs verboses** — stack trace / `APP_DEBUG=1` / PII dans les logs en prod | `APP_DEBUG=0`, pages d'erreur génériques, logs sans secret/PII | Exposition · Logs |
| A17 | **Clickjacking / absence d'en-têtes** — pas de CSP/HSTS/X-Frame-Options | En-têtes de sécurité posés, CORS restreint | Exposition |
| A18 | **Exploitation d'une dépendance vulnérable** (CVE connue sur un composant exposé) | Supply-chain propre (Étape 2), pas de CVE non traitée | Dépendances |

> Si le stack évolue (nouveau vecteur : websockets tiers, paiement, API publique…), **ajouter une ligne** (numéro `A{n}` jamais réutilisé) plutôt que d'en réécrire une — même discipline d'ID stable que le registre.

Le **détail méthodo par vecteur** (comment vérifier chaque famille) suit ; chaque attaque `A{n}` s'y rattache.

Pour **chaque vecteur** ci-dessous : verdict **protégé / partiel / absent / non vérifié** (même vocabulaire que la liste d'attaques et le tableau de posture), et où (`fichier:ligne`). Adapter au stack (Symfony/API Platform · React · FastAPI/OR-Tools · JWT · Mercure · Redis · Postgres RLS · Docker).

1. **Injection** — SQLi : Doctrine en paramètres liés partout, **zéro** DQL/SQL concaténé avec de l'input (y compris le GUC `app.club_id` via `bindValue`) ; commande/OS (`exec`, `shell_exec`, `proc_open`, `os.system` côté engine) ; **XSS** front : `dangerouslySetInnerHTML`, injection dans `href`/`src`, HTML non échappé ; injection de log/header.
2. **Auth & session** — JWT : algo fixé (pas de `none`/`HS↔RS` confusion), expiration courte, clé hors repo ; **anti-brute-force sur le login** (throttle + éventuel lockout) ; **énumération d'emails** (réponses/latences uniformes register/login/reset) ; token de reset (usage unique, TTL) ; politique de mot de passe ; pas d'auto-login après register sans garde.
3. **Autorisation / IDOR / escalade** — franchissement club/saison **sous angle attaque** (forcer `X-Club-Id`/`X-Season-Id`, IDOR sur un UUID d'entité d'un autre club), escalade de rôle (SEC-07 gate management), opération API Platform exposée sans `security`.
4. **CSRF** — recenser toute mutation qui s'appuie sur un **cookie envoyé automatiquement** ; SPA + JWT `Bearer` = risque faible **si** aucun cookie de session ambiant n'autorise une écriture — le **vérifier**, ne pas le supposer.
5. **Rate-limiting / DoS** — throttle API par utilisateur (SEC-11) et sur les routes anonymes (login/register/reset) ; **taille max de payload** (upload logo, payload de génération) ; **borne de complexité de génération** (bombe combinatoire → solveur) ; timeout solveur ; abus Mercure (abonnements) ; épuisement Redis (locks).
6. **SSRF / requêtes sortantes** — backend→engine sur URL **fixe** (jamais dérivée d'input) ; fetch de logo / futur **import FFBB** : URL externe contrôlée par l'utilisateur ? suivit de redirections ? plage IP interne atteignable ? taille/MIME bornés ?
7. **Exposition & en-têtes** — en-têtes de sécurité (**CSP**, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy) ; **CORS** (origines autorisées, `*` interdit avec credentials) ; `APP_DEBUG=0` + pas de stack trace en prod ; doc API/Swagger non exposée publiquement en prod.
8. **Upload de fichiers** (logo) — validation **MIME réelle** (pas seulement l'extension), taille bornée, **SVG rejeté ou assaini** (XSS), chemin de stockage non devinable, route de service en `Content-Type` sûr + `Content-Disposition`.
9. **Secrets & config** — clés JWT/DB/Mercure hors repo (`git ls-files`), `.env*` non tracké, **aucun credential par défaut en prod**, `APP_ENV=prod`.
10. **Dépendances vulnérables** — croiser avec la supply-chain (Étape 2) : CVE connue sur un composant exposé = `SEC-`/`DEP-`.
11. **Journalisation & détection** — échecs d'auth tracés, **aucun secret/PII dans les logs**, corrélation minimale (qui a fait quoi).

**Synthèse obligatoire** : produire le **tableau de posture par vecteur** (voir Étape 5) — c'est le livrable qui « informe sur la protection contre les attaques ». Un vecteur **critique absent sur un flux critique** (login sans throttle, SQLi, XSS stockée, IDOR inter-club) = `SEC-` **Élevé minimum**, contre-vérifié en Étape 3.

## Étape 3 — Vérification contradictoire (OBLIGATOIRE)

Chaque finding **critique ou élevé** doit être contre-vérifié à la main avant publication : lire les fichiers/lignes cités, chercher la preuve inverse. Issues possibles : `confirmé` / `réfuté` / `non vérifié`. Un finding réfuté reste dans le rapport, barré, avec la preuve de réfutation — c'est ce qui rend l'audit digne de confiance. Ne publie JAMAIS un finding critique non vérifié sans le marquer `non vérifié`.

## Étape 4 — Notation

Barème fixe (ne pas le modifier — la comparabilité en dépend) :

| Tranche | Signification |
|---|---|
| 90–100 | Exemplaire, niveau production commerciale |
| 75–89 | Solide, prod envisageable en l'état |
| 60–74 | Bon socle, ≥1 chantier significatif avant prod sereine |
| 40–59 | Fragile, ≥1 défaut critique vérifié |
| 20–39 | Défaillant, refonte partielle |
| 0–19 | Non fonctionnel ou dangereux |

Pondérations : **Doc** = exactitude 40 / structure 20 / utilité IA 25 / cycle specs 15. **Besoin** = réalité 40 / adéquation 30 / viabilité 30. **Code par brique** = correction+sécurité 40 / architecture 25 / tests 20 / robustesse 15. Un défaut critique **confirmé** plafonne la brique à 60. Les notes sont un indicateur secondaire — la comparaison inter-éditions se fait sur le registre de findings.

**Score UX (axe ADDITIF — noté HORS du `/100` des briques ci-dessus ; notation EXTRÊMEMENT sévère).** But : prémunir des erreurs au plus tôt pour une app **simple d'utilisation ET robuste en tout point**.

- **Point par point** : trois sous-notes `/100` indépendantes — **UX-Cohérence · UX-Simplicité & Intuitivité · Inclusivité-a11y**.
- **De manière générale** : le **Score UX global `/100` = le PLUS BAS des trois sous-scores couverts** (jamais une moyenne). « Robuste en tout point » = la chaîne vaut son maillon le plus faible : un seul axe faible tire tout le score UX vers le bas. Un sous-axe `non couvert` n'entre pas dans le min (le signaler).
- **Barème inversé par défaut (sévérité extrême)** : partir bas et faire *gagner* les points, pas l'inverse. Chaque incohérence, friction ou ambiguïté pour un **gestionnaire lambda** coûte. Grille UX (plus dure que l'échelle générale) :
  - **90–100** : exemplaire, **zéro** finding UX ≥ moyen, cohérence et redondance d'info sans faille.
  - **75–89** : solide, uniquement des broutilles mineures **cosmétiques**.
  - **60–74** : correct mais **≥1 chantier** qui ferait hésiter un gestionnaire.
  - **< 60** : dès qu'**un seul** défaut ferait perdre, bloquer ou tromper un gestionnaire lambda (ou exclut un utilisateur handicapé).
- **Plafonds durcis (plus stricts que les briques)** — un finding UX **confirmé** plafonne SON sous-axe : **moyen → 75**, **élevé → 60**, **critique → 40**. Critiques UX typiques : information portée par la **seule couleur** sur un flux critique, flux-clé **infaisable au clavier**, **contraste AA échoué** sur du texte principal, **jargon bloquant** un gestionnaire non technique.

Règles strictes de comparabilité :
- **Ne PAS re-pondérer** les briques `/100` existantes : leur barème est figé, ces axes s'ajoutent à côté.
- **Nouvel axe = pas de rétro-notation** : les éditions antérieures qui n'avaient pas ces axes sont marquées `non couvert` en baseline ; la trajectoire UX **démarre à l'édition qui introduit l'axe**. Ne jamais inventer une note UX rétroactive pour une édition passée.
- Un sous-axe **non couvert** (ex. dynamique sans navigateur ET proxys statiques insuffisants) ne donne PAS de note — comme tout axe.

La référence de notation est **l'application commercialisable**, pas le stade de développement courant. Noter contre cette barre en permanence : les notes doivent monter au fil des éditions parce que le code s'améliore, jamais parce que l'audit s'est ramolli.

## Étape 5 — Format de sortie

Écrire `specs/audit/AUDIT-<YYYY-MM-DD>-<model-id>.md` :

1. **En-tête** : date, modèle exact, SHA HEAD, tableau de couverture des axes — **inclure les lignes `UX-Cohérence`, `UX-Simplicité/Intuitivité`, `Inclusivité-a11y`**, **`Cybersécurité — surface d'attaque`** et **`Coûts / scalabilité financière`** (chacune `couvert` / `partiel` / `non couvert (raison)`). La ligne coûts (coût par club/génération/stockage, projection à N clubs) reste **`non couvert (pas de données réelles)` tant qu'aucune donnée de facturation/infra prod n'existe** — jamais de chiffres fabriqués ; elle est là pour rester visible et redevable. Cette ligne est **permanente** : elle ne compte **pas** comme le « nouvel axe candidat » qu'exige l'Étape 2 bis (lequel doit être un angle réellement neuf chaque édition). Elle peut passer `partiel` si une estimation **fondée sur des ressources réelles** (limites compose, tailles de payload) est produite — jamais de projection chiffrée sans données.
2. **Synthèse des notes** (/100, uniquement les axes couverts). Le **score UX** est présenté dans un **bloc séparé**, hors du `/100` des briques : les **3 sous-notes `/100`** (Cohérence · Simplicité-Intuitivité · Inclusivité-a11y) **et** le **Score UX général `/100` = le plus bas des sous-axes couverts** (voir Étape 4, notation extrêmement sévère).
3. **Registre des findings** — LE cœur de la comparaison. Table : `ID | Titre court | Zone | Gravité | Statut vérif (confirmé/réfuté/non vérifié) | Statut vs édition précédente (nouveau/ouvert/corrigé/réfuté)`. Règles d'ID : préfixe zone (`SEC-`, `ENG-`, `BCK-`, `FRT-`, `DOC-`, `DEP-`, `INF-`, `ALIGN-` (scission/angle mort d'alignement contraintes 3 couches, Étape 2 quater), `UX-` (générique, hérité), `UXC-` (cohérence), `UXS-` (simplicité/intuitivité), `A11Y-` (inclusivité/accessibilité), `PERF-`, `RGPD-`) + numéro incrémental **jamais réutilisé**. Reprendre les IDs de l'édition précédente pour les findings ouverts ; marquer `corrigé` ceux qui ont disparu (avec preuve).
4. **Tableau de posture cybersécurité** (Étape 2 quinquies) — **une ligne par attaque nommée `A1`…`A{n}`** de la liste « attaques les plus probables pour ClubScheduler » : `attaque | verdict (protégé / partiel / absent / non vérifié) | preuve fichier:ligne | SEC- associé s'il y a défaut`. La liste se parcourt **intégralement** (aucune attaque omise ; une non regardée = `non vérifié`, pas absente du tableau). C'est **le** livrable « suis-je protégé contre les attaques ? » ; il se compare d'une édition à l'autre par les IDs `A{n}`, exactement comme le registre par les `SEC-` (une édition antérieure sans cet axe = baseline `non couvert`, pas de rétro-notation).
5. **Détail par critère** : doc, besoin, chaque brique, supply chain, infra, RGPD, **cybersécurité (surface d'attaque, Étape 2 quinquies)**, **UX (cohérence / simplicité-intuitivité / inclusivité-a11y)** — forces, faiblesses avec `fichier:ligne`.
6. **Avis global + axes d'amélioration priorisés** (P0/P1/P2) — table : `reco | priorité | effort (S/M/L) | traité (✅/⬜, repris de l'édition précédente)`. **Chaque reco de l'édition N-1 est reprise avec son état** : ✅ (traitée, preuve PR/commit) · ⬜ (toujours ouverte — garde sa priorité/effort N-1 sauf changement justifié en une ligne) · ⛔ (abandonnée : refusée par l'utilisateur ou rendue obsolète par un changement d'archi, **avec la raison**). Comme un finding `réfuté`, une reco ⛔ reste visible mais sort des actives — elle ne disparaît jamais silencieusement. L'effort est un tag S/M/L (taille de chantier), jamais un chiffrage ROI inventé.
7. **Features intéressantes à développer** (ratio valeur/effort, tenant compte de l'état réel).
8. **Annexe méthodologie** : ce qui a été exécuté vs statique, findings contre-vérifiés, limites, **confiance par axe** — clé = **degré d'exécution**, pas la présence de preuve (quasi toujours `fichier:ligne`) : **élevée** = axe **exécuté** (outils/tests lancés — tsc/vitest/pip-audit/behat…) ou preuve directe recoupée · **moyenne** = **lecture de code seule** (statique, non exécuté) · **faible** = indices indirects/inférence — une note `/100` à confiance faible se lit comme provisoire, et **auto-question de biais** : « quels biais ai-je introduits (sur-poids de ce qui est facile à grepper, sous-poids de ce qui demande l'exécution) et quelles données m'ont manqué ? » — 3-5 lignes honnêtes, pas un exercice de style.

## Règles

- Ne **jamais commiter** le résultat — le fichier reste en working tree, l'utilisateur décide (mémo projet : jamais de merge sans go explicite).
- **Sévérité par défaut — extrême, assumée.** La barre de notation est l'application **commercialisable** (cible : mi-2027), jamais le stade de développement du moment. Le rôle de l'audit est de **prémunir des erreurs au plus tôt** pour une app **simple d'utilisation ET robuste en tout point** : dans le doute, noter BAS et flaguer. Ne jamais adoucir une note ou une gravité parce que « c'est encore en dev » — l'utilisateur veut voir les angles morts tôt et s'y habituer. Pas de gravité « à terme » : un backup absent est Élevé aujourd'hui, point. Le contexte dev se lit dans la trajectoire du registre (findings corrigés édition après édition), pas dans des notes gonflées.
- **« Ça tourne » n'est JAMAIS un signal de réussite.** La note mesure la **distance au commercialisable**, pas « est-ce que ça démarre ». La fonctionnalité minimale est un **prérequis** (présent dès la zone 60+), pas un palier : une app qui s'exécute sans crash peut parfaitement mériter **20-39** si elle est insécure, illisible, non maintenable ou inutilisable par un gestionnaire lambda. Ne jamais remonter une note basse au motif que « au moins ça marche ». Les 6 tranches ci-dessus ne bougent pas (comparabilité) — leur sens reste stable d'une édition à l'autre.
- En cas de doute sur une gravité, prendre la plus haute. Un finding sur-coté se rétrograde à l'édition suivante ; un finding sous-coté devient un incident en prod.
- Direct et factuel. Un audit qui flatte ne sert à rien ; un audit qui affirme sans vérifier non plus.
- Exactitude quand même : la sévérité porte sur la notation, pas sur les faits. Ex. données personnelles : périmètre réel = coachs + comptes users (email/tél) ; les noms d'équipes type « U13M1 » sont génériques, pas des données personnelles. Ne pas gonfler un finding au-delà des faits pour paraître sévère.
- Si un axe coûte trop cher à couvrir cette fois, le dire dans le tableau de couverture plutôt que de noter au doigt mouillé.
