Last verified @ 2026-08-24 (**rotation de fraîcheur** — re-vérifié contre le code, **tout juste**,
un cran de dérive de ligne corrigé : `shared/api/client.ts:48` (pas `:47`, une note P4-129 a
décalé le fichier depuis) pose bien un `X-Request-Id` sur chaque requête et le relit sur la
réponse ; `RequestIdListener.php`/`RequestIdMiddleware.php` existent (backend+bus) ; l'endpoint
`POST /api/feedback` existe (`backend/src/Controller/FeedbackController.php:66`) ; `monolog-bundle`
est bien dans `composer.json`. La contradiction corrigée à une passe antérieure — un §1 qui
affirmait encore « aucun canal utilisateur » sous un en-tête « LIVRÉ » — n'est pas revenue)

# Canal signalement, support & reproduction

> **Statut : LIVRÉ (2026-08-13/14, 3 PR).** Ce fichier était le cadrage ; il est désormais la
> spec courante du canal. Besoin fondateur du 2026-08-09 :
> un endroit où un gestionnaire signale un bug, une contrainte manquante, une idée — et de quoi
> **reproduire** ce qu'un utilisateur a rencontré. Base saine d'emblée (pas un `mailto:`
> jetable), sans sur-ingénierie tickets. Ce doc pose l'état des lieux vérifié, les options et
> les décisions à trancher. Les décisions D1-D6 + §3bis/§3ter ci-dessous sont IMPLÉMENTÉES.

## 1. État des lieux AVANT le lot (vérifié au code le 2026-08-13 — **historique**)

> ⚠ **Cette section décrit le dépôt AVANT la livraison de P5-6, et le RESTE** : elle explique
> pourquoi le canal a été construit ainsi. Ne la lisez pas comme l'état courant — le « ce qui
> manque » ci-dessous **a été comblé** (rotation de fraîcheur du 2026-08-19 : `POST /api/feedback`
> existe — `FeedbackController` —, le request-id aussi — `RequestIdListener` +
> `RequestIdMiddleware` —, et Monolog est installé). L'état COURANT est décrit à partir du §2.

**Ce qui existe déjà et qui est FORT — la reproduction d'une génération :**
- `Schedule::$snapshotData` : le payload engine **complet et figé**, écrit AVANT l'appel moteur,
  avec son sha256 — **rejouable tel quel sur `/generate`**. La repro d'un solve est un solved
  problem.
- `ScheduleStructureSnapshot` : photo de la structure au moment du solve (plans SEASON,
  COMPLETED seulement).
- Versions/seed/métriques par solve : `solverVersion`, `seed`, `wallTimeMs`, tailles, score
  (`Schedule` + `solver_metrics`) ; diagnostics par génération (`ScheduleDiagnostic`).

**Ce qui existe à moitié :**
- **Sentry câblé partout, actif nulle part** : bundle backend (erreurs + échecs Messenger),
  init engine, `@sentry/react` + ErrorBoundaries — mais les 3 DSN sont vides (P5-1, le compte
  n'existe pas). Le jour où P5-1 est fait, les erreurs techniques remontent seules — le canal
  signalement n'a PAS à transporter les stack traces.

**Ce qui manquait alors** (comblé depuis, cf. l'avertissement ci-dessus) **:**
- **Aucun canal utilisateur** : zéro mécanisme de feedback in-app, zéro endpoint, le seul
  contact est le `mailto:` placeholder de la landing (hors app).
- **Aucune corrélation** : pas de request-id (rien ne relie une erreur front, une entrée Sentry
  backend et un solve), pas de logging structuré (Monolog absent, logs texte Docker 30 Mo),
  pas de lien génération→utilisateur (`Schedule` porte club+saison, pas l'auteur du clic).

## 2. Les trois familles d'options

### A. In-app minimal (recommandation pressentie)
Un bouton « Signaler » dans l'app → formulaire court (type : bug / contrainte manquante / idée ;
texte libre) → une entité `Feedback` tenant-scopée + **contexte auto-joint** : URL/écran,
`clubId`, `seasonId`, le `scheduleId` courant s'il y en a un (= le snapshot rejouable est
automatiquement référencé), user-agent, version app. Consultation : console superadmin
(liste + détail + statut traité/non traité — PAS un workflow de tickets). Notification :
un email vers `support@` à chaque dépôt (le mail part par le bus, rail déjà async).
- **Pour** : la donnée de repro est LÀ où le signalement naît ; tenant/RGPD maîtrisés maison ;
  zéro dépendance ; s'aligne sur la console SA existante.
- **Contre** : c'est nous qui stockons (purge/retention à définir) ; l'UI de traitement reste
  rudimentaire (voulu).

### B. Externe (Tally/Formbricks/GitHub Issues public…)
- **Pour** : zéro code.
- **Contre** : perd le contexte auto (l'utilisateur recopie à la main — la moitié de la valeur),
  RGPD à contractualiser, identité club à ressaisir, et l'aversion « base saine d'emblée »
  pointait précisément ça.

### C. Email seul (`support@maratech.fr` affiché dans l'app)
- **Pour** : existe dès que la boîte existe.
- **Contre** : c'est le `mailto:` jetable refusé au cadrage — aucun contexte, aucun suivi,
  aucune structure. Peut vivre en COMPLÉMENT (l'alias existe de toute façon), pas en canal
  principal.

## 3. Décisions — TRANCHÉES par le fondateur le 2026-08-13

| # | Décision |
|---|---|
| D1 | **In-app, DEUX portes** : (a) un « Signaler » **contextuel sur la page** (planning/wizard) — contexte auto-joint + champ descriptif du bug ; (b) un « Signaler un bug » **dans le burger** — zone libre : choix d'un topic (bug / contrainte manquante / idée) + commentaire libre. La porte (b) existe partout, la (a) là où il y a un contexte à capturer |
| D2 | **Contexte MAXIMAL, redondance assumée** : « je préfère être redondant et pouvoir reproduire plutôt que devoir redemander » — écran, club, saison, `scheduleId` ET **copie** des diagnostics + du payload rejouable dans le signalement lui-même. Justification technique de la redondance : le planning référencé peut être supprimé/régénéré après coup — la copie rend le signalement **impérissable** |
| D3 | **Signé, et TOUT LE MONDE peut signaler** (Gestionnaires ET Membres) |
| D4 | **Digest quotidien** vers `support@` (pas un email par dépôt) — la console SA reste la vue temps réel |
| D5 | **Lot séparé, mais VOULU** (pas un différé poli) : la corrélation request-id/logs structurés a sa propre ligne roadmap (P5-11) |
| D6 | **Console superadmin : liste des signalements en cours + statut traité/non traité** — « pour ne pas oublier » |

Niveau plan (à trancher à l'implémentation, validés avec le plan) : pages exactes de la porte
contextuelle, heure du digest, rétention/purge (pressenti : alignée sur la rétention club),
taille max du commentaire.

## 3bis. La boucle complète du déclarant (ajout fondateur, 2026-08-13)

Le workflow vécu par le club AAAA qui déclare un bug :
1. **Dépôt** → toast in-app + **email « bien reçu »** au déclarant (« votre signalement est
   enregistré et sera traité ») — DANS ce lot, part par le bus comme les autres emails.
2. **Traitement** → quand le fondateur passe le signalement en « traité » dans la console SA
   (le statut D6 devient le déclencheur), **email « traité + merci d'avoir contribué à
   l'amélioration »** au déclarant — DANS ce lot, zéro machinerie nouvelle.
3. **Visibilité à la release** → l'email « traité » peut pointer vers le **journal de mise à
   jour** — LOT SÉPARÉ (roadmap P5-12) : entrées datées curées AU RYTHME DU FONDATEUR (pas à
   chaque merge — 80 % des PR sont de la plomberie invisible), page dans le burger, modale
   « quoi de neuf » une fois par nouveauté non vue, crédit anonyme possible (« signalé par un
   club — corrigé »). ⚠ Garde-fou : PAS de lien automatique bug→release (« votre bug #12 est
   dans la v1.4 ») — c'est le système de tickets exclu par l'anti-scope ; la boucle personnelle
   est fermée par l'email (2), le journal donne la visibilité publique.

## 3ter. Indicateurs qualité de service du support (ajout fondateur, 2026-08-13 — « pour que l'on s'améliore »)

L'entité signalement porte déjà tout ce qu'il faut (horodatages dépôt/traitement, topic,
statut) — les indicateurs en découlent en SQL pur, AUCUNE collecte supplémentaire :
- **Délai dépôt → traité** (moyenne + p95, par mois) — LA mesure de l'amélioration ;
- **Volume par topic et par période** — où l'app fait mal, et si ça se résorbe ;
- **Part traitée / en attente** (et l'âge du plus vieux non traité — l'oubli visible).

Surface : un petit panneau en tête de la vue console SA (D6), dans CE lot. Pas de dashboard
dédié, pas d'outil externe. Le reste de la qualité de service (taux de réussite des
générations, erreurs techniques, délais de solve) est couvert ailleurs : `solver_metrics` +
monitoring SA existant + Sentry (P5-1) + métriques de capacité (P5-10).

## 4. Ce que ce lot ne sera PAS
Un système de tickets (statuts multiples, assignation, SLA), un chat, un forum, une base de
connaissances, ni le remplaçant de Sentry (les erreurs techniques remontent par P5-1). Pas de
pièces jointes v1 (surface upload = lot sécurité à part entière si le besoin émerge).

## 5. Estimation si option A
S/M : entité + endpoint POST tenant-scopé (management par défaut ? ou tout membre ? — à
trancher en D3bis), formulaire front (bouton global), vue console SA, email par le bus, tests
(tenant, rôles, texte public sans identifiants internes). Axe auth non touché ; axe tenant
touché par la nouvelle entité → NR d'isolation standard (patron des entités tenant-owned).
