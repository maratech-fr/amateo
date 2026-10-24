# Canal signalement, support & reproduction

> Le canal par lequel un gestionnaire signale un bug, une contrainte manquante ou une idée — et
> de quoi **reproduire** ce qu'un utilisateur a rencontré. Base saine (pas un `mailto:` jetable),
> sans sur-ingénierie tickets. Les décisions D1-D6 + §3bis/§3ter/§3quater ci-dessous sont
> implémentées.
>
> **La reproduction d'une génération n'est jamais le problème** : `Schedule::$snapshotData`
> (`backend/src/Entity/Schedule.php:96`) porte le payload engine complet et figé, écrit avant
> l'appel moteur, avec son sha256 — rejouable tel quel sur `/generate`. Ce que le canal
> signalement capte en plus, c'est le reste : l'écran, le geste, l'intention de l'utilisateur.

## 2. Les trois familles d'options

### A. In-app minimal (l'option retenue)
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

## 3. Décisions retenues

| # | Décision |
|---|---|
| D1 | **In-app, DEUX portes** : (a) un « Signaler » **contextuel**, dans l'en-tête (`PageHeader`, `frontend/docs/frontend-components.md`) de chaque écran principal et du wizard — contexte auto-joint (écran, club, saison, `scheduleId` s'il y en a un) + champ descriptif du bug ; (b) un « Signaler un bug » **dans le burger** — zone libre : choix d'un topic (bug / contrainte manquante / idée) + commentaire libre. Les deux portes existent partout. **Exception motivée (lot B)** : à l'étape Génération du wizard, l'écran embarqué (`PlanningPage`) masque sa propre porte (a) — celle de l'EN-TÊTE DU WIZARD reste SEULE, pour ne jamais en doubler deux sur le même écran, et joint le planning AFFICHÉ par l'écran embarqué (store wizard `displayedScheduleId`, éphémère) ; un planning ouvert via `/planning` autonome garde sa propre porte (a) |
| D2 | **Contexte MAXIMAL, redondance assumée** : « je préfère être redondant et pouvoir reproduire plutôt que devoir redemander » — écran, club, saison, `scheduleId` ET **copie** des diagnostics + du payload rejouable dans le signalement lui-même. Justification technique de la redondance : le planning référencé peut être supprimé/régénéré après coup — la copie rend le signalement **impérissable**. Le contexte serveur est détaillé en §3quater (`context.auto`) |
| D3 | **Signé, et TOUT LE MONDE peut signaler** (Gestionnaires ET Membres) |
| D4 | **Digest quotidien** vers `support@` (pas un email par dépôt) — la console SA reste la vue temps réel |
| D5 | **La corrélation request-id/logs structurés vit à part** : chaîne complète documentée dans [`docs/ops/observability.md`](../../docs/ops/observability.md) |
| D6 | **Console superadmin : liste des signalements en cours + statut traité/non traité** — « pour ne pas oublier » |

## 3bis. La boucle complète du déclarant

Le workflow vécu par le club AAAA qui déclare un bug :
1. **Dépôt** → toast in-app + **email « bien reçu »** au déclarant (« votre signalement est
   enregistré et sera traité »), part par le bus comme les autres emails.
2. **Traitement** → quand le fondateur passe le signalement en « traité » dans la console SA
   (le statut D6 devient le déclencheur), **email « traité + merci d'avoir contribué à
   l'amélioration »** au déclarant.
3. **Visibilité à la release** → l'email « traité » peut pointer vers le **journal de nouveautés**
   ([`frontend/docs/frontend-spec.md`](../../frontend/docs/frontend-spec.md), route `/nouveautes`) :
   entrées datées curées au rythme du fondateur (pas à chaque merge — la plupart des PR sont de la
   plomberie invisible), page dans le burger, modale « quoi de neuf » une fois par nouveauté non
   vue, crédit anonyme possible (« signalé par un club — corrigé »). ⚠ Garde-fou : PAS de lien
   automatique bug→release (« votre bug #12 est dans la v1.4 ») — c'est le système de tickets
   exclu par l'anti-scope ; la boucle personnelle est fermée par l'email (2), le journal donne la
   visibilité publique.

## 3ter. Indicateurs qualité de service du support (« pour que l'on s'améliore »)

L'entité signalement porte déjà tout ce qu'il faut (horodatages dépôt/traitement, topic,
statut) — les indicateurs en découlent en SQL pur, AUCUNE collecte supplémentaire :
- **Délai dépôt → traité** (moyenne + p95, par mois) — LA mesure de l'amélioration ;
- **Volume par topic et par période** — où l'app fait mal, et si ça se résorbe ;
- **Part traitée / en attente** (et l'âge du plus vieux non traité — l'oubli visible).

Surface : un petit panneau en tête de la vue console SA (D6). Pas de dashboard dédié, pas d'outil
externe. Le reste de la qualité de service (taux de réussite des générations, erreurs techniques,
délais de solve) est couvert ailleurs : `solver_metrics` + monitoring SA existant + Sentry (actif
sur les 3 zones, `etat-des-lieux.md` §3 2026-10-06) + métriques de capacité
(`AdminCapacityService`, console superadmin — détail [`superadmin-auth.md`](superadmin-auth.md)
§ Capacité).

## 3quater. Contexte automatique large (`context.auto`)

En plus du contexte léger whitelisté (D2) et de la copie du planning nommé, **tout** signalement
(planning nommé ou non) porte une clé `context.auto` calculée **côté serveur** par
`FeedbackContextEnricher` (`backend/src/Service/FeedbackContextEnricher.php`), pour que le support
se resitue sans rien redemander à l'auteur :

| Clé | Contenu |
|---|---|
| `role` | rôle de l'auteur dans le club (adhésion active) |
| `season` | saison courante résolue serveur : id/nom/dates |
| `offer` | offre EFFECTIVE du club (Découverte par défaut) — même calcul que `/api/me` |
| `counters` | compteurs d'inventaire (équipes, gymnases) |
| `socle` | le plan SEASON (ADR-0002) : `inForce` (une version pointée) + `validatedAt` (1ʳᵉ validation, `null` tant qu'il n'est pas validé) |
| `period` | la fenêtre CLOSURE/HOLIDAY qui contient AUJOURD'HUI (horloge du club) : type/nom/dates/avancement — `null` hors période (« pas de période » est une information) |
| `versions` | `displayed` (le planning déjà joint par le client, ré-vérifié tenant) vs `pointed` (la version choisie du socle) — numéro + nom de chacun, `null` si absent |
| `generation.running` | une génération est-elle en cours pour ce club (`ClubGenerationLock`) |
| `simulatedToday` | horloge simulée du club (démos/dev), `null` pour tout club à l'heure réelle |

**Toujours résolu depuis le club AUTHENTIFIÉ, jamais depuis un id client** : `clubId`/`seasonId`/
`planId` envoyés dans `context` ne sont pas dans `LIGHT_CONTEXT_FIELDS`, donc déjà tombés avant
d'atteindre l'enrichisseur — gardé par le NR d'axe *tenant isolation*
`Security/FeedbackContextIsolationTest` (bloquant, `docs/testing/blocking-tests.md`). Best-effort :
une lecture qui échoue n'annule jamais le dépôt (contexte partiel, erreur tracée sans PII).

Le client peut aussi joindre des repères TEXTE libres pour situer l'incident (whitelistés,
longueur bornée, stockés verbatim — jamais exécutés) : `screenLabel`, `activeFilters`,
`displayedRange`, `lastClick`, `incidentStatus`/`incidentUrl`/`incidentCode` (trio d'un appel
réseau fautif). Leur **collecte** côté écran (wizard, module matchs) et leur **affichage/recherche**
en console superadmin restent à livrer (chantier en cours, prochaine PR).

## 4. Hors périmètre
Un système de tickets (statuts multiples, assignation, SLA), un chat, un forum, une base de
connaissances, ni le remplaçant de Sentry (les erreurs techniques remontent par Sentry, pas par ce
canal). Pas de pièces jointes (surface upload = lot sécurité à part entière si le besoin émerge).
