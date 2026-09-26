Last verified @ 2026-09-26 (`documentation-update`, passe « le présent seulement » — §1
« état des lieux avant le lot » supprimé (le seul fait encore vivant, `Schedule::$snapshotData`,
remonté en tête) ; §3/3bis/3ter dédatés ; D5 (request-id) et le passage journal-de-nouveautés de
§3bis, tous deux référencés comme « lot séparé » à tort (P5-11/P5-12 sont livrés), recalés vers
leurs maisons courantes (`docs/ops/observability.md`, `frontend/docs/frontend-spec.md`) ; §5
(estimation d'effort pré-implémentation, obsolète) supprimé. Re-confronté au code :
`POST /api/feedback` (`FeedbackController.php:68`), `EventListener/RequestIdListener.php` +
`Messenger/RequestIdMiddleware.php`, `monolog-bundle` (`composer.json:31`), digest quotidien
(`FeedbackDigestCommand.php`), `FeedbackMailBuilder.php`, `Schedule::$snapshotData`
(`backend/src/Entity/Schedule.php:123`), `docs/ops/observability.md`,
`frontend/docs/frontend-spec.md:97` (route `/nouveautes`) — tous existent. **Trouvé et non
traité** : les métriques de capacité superadmin (`AdminCapacityService`) n'ont de maison dans
aucune spec courante, seulement une trace de livraison (`etat-des-lieux.md` §3) — signalé, pas
corrigé cette passe.

# Canal signalement, support & reproduction

> Le canal par lequel un gestionnaire signale un bug, une contrainte manquante ou une idée — et
> de quoi **reproduire** ce qu'un utilisateur a rencontré. Base saine (pas un `mailto:` jetable),
> sans sur-ingénierie tickets. Les décisions D1-D6 + §3bis/§3ter ci-dessous sont implémentées.
>
> **La reproduction d'une génération n'est jamais le problème** : `Schedule::$snapshotData`
> (`backend/src/Entity/Schedule.php:123`) porte le payload engine complet et figé, écrit avant
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
| D1 | **In-app, DEUX portes** : (a) un « Signaler » **contextuel sur la page** (planning/wizard) — contexte auto-joint + champ descriptif du bug ; (b) un « Signaler un bug » **dans le burger** — zone libre : choix d'un topic (bug / contrainte manquante / idée) + commentaire libre. La porte (b) existe partout, la (a) là où il y a un contexte à capturer |
| D2 | **Contexte MAXIMAL, redondance assumée** : « je préfère être redondant et pouvoir reproduire plutôt que devoir redemander » — écran, club, saison, `scheduleId` ET **copie** des diagnostics + du payload rejouable dans le signalement lui-même. Justification technique de la redondance : le planning référencé peut être supprimé/régénéré après coup — la copie rend le signalement **impérissable** |
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
délais de solve) est couvert ailleurs : `solver_metrics` + monitoring SA existant + Sentry
(roadmap P5-1, pas encore activé) + métriques de capacité (`AdminCapacityService`,
`backend/src/Service/AdminCapacityService.php`, console superadmin).

## 4. Hors périmètre
Un système de tickets (statuts multiples, assignation, SLA), un chat, un forum, une base de
connaissances, ni le remplaçant de Sentry (les erreurs techniques remonteront par la roadmap
P5-1). Pas de pièces jointes (surface upload = lot sécurité à part entière si le besoin émerge).
