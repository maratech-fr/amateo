# Console superadmin — authentification, télémétrie et API de supervision

Last verified @ 2026-09-05 (rotation `documentation-update`, hors sujet de la PR découpage
début·milieu·fin). Re-confronté au code : firewall `admin` = `pattern: ^/api/admin`,
`provider: super_admin_provider` (`backend/config/packages/security.yaml:33-35`) ✓ ;
`AdminCsrfListener` toujours à la priorité 6
(`#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]`) ✓ ; politique de mot de passe
12 caractères + majuscule + caractère spécial toujours dans `PasswordPolicy::MIN_LENGTH`/
`REQUIREMENT_FR` (`backend/src/Service/PasswordPolicy.php:15-18`) ✓ ; challenge de session
password→TOTP toujours borné à 5 minutes (`time() - $startedAt > 300`,
`backend/src/Controller/AdminAuthController.php:72`) ✓ ; entité `SuperAdmin` toujours séparée
(`backend/src/Entity/SuperAdmin.php:13`) ✓. Reste du fichier non re-confronté cette passe ;
l'historique des vérifications précédentes vit dans
`git log -p --follow specs/courantes/superadmin-auth.md`)

> **État courant** : SA0, SA1, la console read-only SA2, le socle
> d'historisation SA3-A, la supervision SA3-B, la planification fiable SA3-C et
> les relances d'imports SA3-D sont livrés — **plus SA2-stats (usage produit, §SA2 API),
> SA4 v1 (catalogue d'actions support, §Actions de support), l'alerting santé +
> data-freshness (2026-07-18, §Fraîcheur des données et alerting), et la console en
> onglets avec monitoring conteneurs/dépendances externes + les journaux read-only
> audit / échecs async / erreurs système (2026-07-25, §Journaux read-only) + heartbeats
> cron & pdf-worker**. Le redémarrage de conteneur depuis l'UI a été étudié puis **retiré**
> (socle `docker.sock` non transposable en prod — voir console-superadmin.md). Les actions
> cross-tenant restent dans [`../evolution/console-superadmin.md`](../evolution/console-superadmin.md).

Le frontend React SA0 est désormais livré sur `/admin` : client HTTP à cookie de session
séparé, store admin en mémoire uniquement, login mot de passe/TOTP, garde de route, shell
de console et logout CSRF. Il ne lit ni ne persiste le JWT club.

**La console a une palette PROPRE, hors du système de jetons de thème de l'app.** `AdminShell`
et `AdminAuthLayout` sont câblés sur des jetons `--console-*` (`bg-console-surface`,
`text-console-text-strong`, accents `--console-accent`/`--console-warning`…, catalogue et règle
d'aliasing dans [`frontend-components.md`](../../frontend/docs/frontend-components.md)), jamais
sur `--background`/`--foreground` (`src/index.css`) qui varient avec le mode clair/sombre
applicatif — c'est une esthétique assumée pour une surface à persona fondateur, pas une dérive
(décision fermée, [`etat-des-lieux.md`](etat-des-lieux.md) §2). Conséquence directe : une
primitive partagée qui consomme les jetons de **thème** par défaut (ex. `EmptyHint`/`EmptyBlock`,
`text-muted-foreground`) doit demander sa peau `console` explicitement (prop `variant`, foyer
`shared/lib/surfaceSkin.ts::SurfaceSkin`, même patron que les onglets) pour rendre juste sur cette
surface — la majorité des empty states admin l'ont fait (P4-149, 2026-08-30) ; 4 sites restent en
arbitrage visuel, voir [`roadmap.md`](../evolution/roadmap.md) P4-149. `AdminAuthLayout` n'offre donc
**aucune** bascule de thème (retirée le 2026-08-30 : elle basculait bien `.dark` sans rien
changer à l'écran) ; les bascules publique (`AuthLayout`) et applicative (`AppLayout`) restent,
hors de cette surface.

## Identité et frontière de sécurité

- `SuperAdmin` est une identité globale séparée de `User` et `ClubUser` ; elle ne porte
  aucun club, rôle tenant ou saison.
- Le firewall Symfony stateful `admin` couvre exclusivement `/api/admin/**` et utilise
  `SuperAdminProvider`. Un JWT club présenté à cette surface reste anonyme et reçoit 401.
- Les identités et l'audit sont lus/écrits par la connexion Doctrine `admin`, seule porte
  autorisée à franchir RLS. Le rôle runtime `app_user` n'a aucun privilège sur
  `super_admin`, `admin_audit_log` ou `admin_job_run`.

## Parcours d'authentification

1. `POST /api/admin/auth/password` reçoit `{email,password}`. Une réponse 200 crée un
   challenge de session de cinq minutes mais n'authentifie pas encore l'appelant.
2. `POST /api/admin/auth/totp` reçoit le code RFC 6238 à six chiffres. Un code valide
   régénère la session et crée le token `ROLE_SUPER_ADMIN`.
3. `GET /api/admin/auth/me` hydrate la session séparée.
4. `POST /api/admin/auth/logout` exige `X-CSRF-Token`, invalide la session et répond 204.

**Le contrôle CSRF est CENTRAL depuis SEC-18 (2026-08-07).** `AdminCsrfListener`
(`kernel.request`, priorité 6 — après le firewall et le listener tenant) exige le jeton sur
**toute méthode non sûre** sous `/api/admin`, que le contrôleur y pense ou non. Deux
exemptions, et deux seulement : `POST /api/admin/auth/password` et `POST /api/admin/auth/totp`
— les portes de connexion, qui précèdent toute session porteuse de jeton (leur défense est
ailleurs : throttle par IP, mot de passe, puis TOTP obligatoire). Les appels
`AdminSessionCsrf::isValid()` restés dans les contrôleurs deviennent une seconde barrière.
Ce n'était pas un trou — les quatre écritures existantes l'appelaient toutes — mais un piège :
le premier endpoint ajouté sans copier la ligne naissait sans protection, en silence.
`AdminRequestBoundaryTest` ÉNUMÈRE le routeur et exige un 403 sans jeton sur chaque écriture
admin : une route ajoutée demain est couverte le jour où elle est ajoutée.

Les deux étapes publiques partagent une limite glissante de 5 tentatives par IP sur
15 minutes. Les erreurs d'identifiant, de mot de passe et de compte désactivé ne révèlent
pas l'existence du compte. L'état `enabled` est revalidé à chaque restauration de la
session : désactiver une identité révoque donc aussi ses sessions existantes.

## MFA et création de compte

`app:superadmin:create <email>` demande et confirme interactivement un mot de passe
conforme à la politique serveur (12 caractères, une majuscule et un caractère spécial),
crée l'identité, puis affiche une seule fois la clé Base32 et l'URI
`otpauth://` à enregistrer dans une application compatible TOTP. Le secret stocké est
chiffré en AES-256-GCM avec une clé dérivée de `APP_SECRET`.

## Audit et garanties

Chaque réponse `/api/admin/**`, succès comme refus, ajoute une ligne avec acteur éventuel,
route, méthode, statut et horodatage. Aucun corps de requête, mot de passe, code ou secret
TOTP n'est journalisé. Si l'écriture d'audit échoue, la réponse devient 503 et la session
admin est invalidée : la surface échoue fermée.

La non-régression de l'axe auth/memberships est dans `SuperAdminAccessTest` (`phase1`) ;
les primitives TOTP et le fail-closed audit ont leurs tests unitaires.

## Capture métriques SA1

- `solver_metrics` conserve une ligne immutable par tentative de génération : club,
  schedule, issue terminale (`COMPLETED`, `FAILED` ou `INFEASIBLE`), durée solveur, taille du problème, conflits, score,
  version du solveur et horodatage.
- La table porte `club_id`, est sous RLS `FORCE` et respecte `TenantOwnedInterface`.
  Le rôle runtime ne peut jamais lire les métriques d'un autre club ; la connexion
  `admin` les lira pour les agrégations SA2.
- `Club.lastActivityAt` est mis à jour au plus une fois par jour lors d'une activité
  authentifiée et à la mise en file d'une génération. La rétention six mois, le
  partitionnement mensuel et la purge sont reportés au lot d'exploitation dédié.

## Supervision read-only SA2 — API parc et solveur

Deux routes protégées par la session `ROLE_SUPER_ADMIN` exposent des agrégats calculés
sur la connexion Doctrine `admin`. Elles ne positionnent jamais `app.club_id` et ne
réutilisent ni le firewall ni le JWT club :

- `GET /api/admin/overview` retourne le nombre de clubs opérationnels, actifs à 7/30
  jours, nouveaux sur 7 jours et désabonnés, ainsi que les métriques solveur des 30
  derniers jours (volumes, issues, taux `INFEASIBLE`, p50/p95 et série journalière).
  Il porte **aussi** un bloc `usage` (**SA2-stats**, adoption produit du parc) :
  `plansByType` (plans par type, dont validés), `timeToFirstValidation` (délai jusqu'à
  la première validation, par périmètre), `solverByPlanType` (volumes et p50/p95 par
  type de plan) et `clubSizes` (répartition par tranche de taille, avec la médiane de
  gymnases) ;
- `GET /api/admin/clubs?page=1&limit=25&query=...` recherche sur nom, slug ou code FFBB
  et retourne une liste paginée avec dates d'activité, saison courante, volumétrie active
  de la saison et indicateurs solveur sur 30 jours. `limit` est borné à 100 et `query` à
  100 caractères. **L'offre est rendue en DEUX vérités distinctes depuis A1 (2026-08-11)** :
  `plan` (l'offre STOCKÉE — `{code, name}` ou null) et `effectivePlan` (l'offre qui
  S'APPLIQUE, calculée par la même règle que `PlanEntitlements` : payante/bêta effective
  seulement si `paidSeasonYear` couvre l'année-pivot de la saison courante, sinon
  Découverte ; club sans saison → pivot sur l'horloge réelle). `paidSeasonYear` est exposé.
  ⚑ L'ancien champ `planId` (uuid typé `number` côté front — doublement faux) est SUPPRIMÉ :
  le badge console affiche l'effective, avec un sous-texte quand la stockée diverge
  (« Bêta posée — saison non réglée »). Un club démo n'a AUCUN cas spécial ici : badge =
  vérité comptable, le chip « Démo » porte déjà « droits pleins » (décision fondateur).

La « saison courante » est la saison couvrant la date du jour ; en son absence, l'API
retourne la saison la plus récente. Toutes les lectures sont auditées par la garantie
fail-closed SA0. `SuperAdminAccessTest` couvre le rejet d'un JWT club et la lecture
cross-tenant par un superadmin authentifié.

## Santé technique SA2

`GET /api/admin/health` exécute des sondes read-only bornées sur la base admin, Redis,
l'engine et Mercure. Il expose également le backlog et les échecs des transports
Messenger, le nombre de retries depuis minuit UTC, et le dernier heartbeat du worker.

Le worker écrit au plus un heartbeat toutes les 10 secondes dans le cache Redis, avec
une expiration à 60 secondes. L'API le considère `up` jusqu'à 30 secondes ; une absence
de heartbeat est `unknown`, un heartbeat trop ancien est `down`. Messenger passe
`degraded` dès qu'un message est dans la failure queue ou que le backlog atteint 100.

Chaque sonde réseau a un timeout court. Une dépendance indisponible ne fait jamais tomber
l'endpoint : son composant passe `down`/`unknown` et le statut global devient `degraded`.
Les erreurs, DSN et URL internes ne sont jamais incluses dans la réponse. La route reste
protégée et auditée comme toutes les routes `/api/admin/**`.

## Écran de supervision React SA2

La route protégée `/admin` consomme les trois lectures SA2 sans réutiliser le JWT ni le
store club. Elle présente les indicateurs du parc et du solveur, la série quotidienne,
les sondes d'infrastructure, l'état Messenger/worker et la liste paginée des clubs.
La recherche par nom, slug ou code FFBB est envoyée à l'API ; aucun filtrage cross-tenant
n'est réalisé côté navigateur.

La santé est rafraîchie toutes les 30 secondes, l'overview toutes les 60 secondes, et un
bouton permet de rafraîchir les trois panneaux. Chaque flux conserve ses propres états
chargement, erreur et vide afin qu'une sonde indisponible ne masque pas les autres
informations. SA2 ne déclenche aucune mutation ou action de support.

## Socle d'exécution des jobs SA3-A

La table globale `admin_job_run`, accessible uniquement par la connexion Doctrine
`admin`, conserve pour chaque exécution la clé du job, la commande allowlistée, l'origine,
le statut, les horodatages, la durée et le code de sortie. Elle ne stocke ni sortie
console ni texte d'exception afin de ne pas transformer la télémétrie en journal de
données métier.

`app:jobs:run <clé>` est l'unique wrapper opérationnel. À sa livraison, SA3-A a repris
telles quelles les tâches que `cron-runner` exécutait déjà chaque heure : rappels de
périodes et de transition, réconciliation des générations bloquées, purges des comptes
non vérifiés, clubs effacés, comptes inactifs, anciennes saisons et audit. Le catalogue
s'est étoffé depuis (alertes santé & fraîcheur, sauvegarde base, digest des doléances
coach, imports calendaires) — **`AdminJobCatalog` fait foi**, il est la seule source de
la liste. Un verrou advisory PostgreSQL empêche le chevauchement d'un même job ; une
tentative `running` abandonnée est marquée `interrupted` au prochain démarrage acquis.

SA3-A ne changeait pas la cadence existante ; SA3-C la remplace par les horaires décrits
ci-dessous. SA3-D ajoute les deux relances de référence décrites plus bas.

## Supervision read-only des jobs SA3-B

`GET /api/admin/jobs`, protégé par le firewall et la session superadmin séparée, rapproche
le catalogue fermé de la dernière ligne `admin_job_run` de chaque job. La réponse expose
la clé, le libellé, la commande, la cadence déclarée et, lorsqu'elle existe, la dernière
exécution avec son statut, son origine, ses dates, sa durée et son code de sortie. Un JWT
club ne peut pas accéder à cette route.

Le dashboard React `/admin` affiche **tous les jobs du catalogue** dans un panneau indépendant. Un job sans
historique est explicitement marqué « Jamais exécuté » ; une indisponibilité de ce flux ne
masque ni la santé technique, ni les indicateurs, ni les comptes clubs. Le flux est
rafraîchi toutes les 60 secondes et par le bouton d'actualisation global.

## Planification fiable des jobs SA3-C

`cron-runner` lance `app:jobs:run-due` chaque minute. Chaque définition porte une cadence
fermée calculée en `Europe/Paris` :

- réconciliation des générations bloquées toutes les 10 minutes (`--older-than 60`) ;
- **alertes santé & fraîcheur toutes les 10 minutes** (`app:health:alert`, voir plus bas) ;
- **sauvegarde de la base chaque jour à 01:00** (`app:db:backup`) — le tick est bon marché,
  la commande **skippe s'il n'y a eu aucune activité** : la cadence réelle des dumps suit
  l'activité des clubs, pas le calendrier ;
- digest des doléances coach chaque jour à 07:00 — n'envoie que si une réponse est postérieure
  au dernier digest (silence total = aucun email), et pousse le récap final le lendemain de la
  deadline ;
- rappels de périodes et de transition chaque jour à 08:00 ;
- purges comptes non vérifiés à 02:00, clubs effacés à 02:15, comptes inactifs à 02:30,
  saisons à 03:00 et audit à 03:30 ;
- imports des vacances scolaires à 04:00 et des jours fériés à 04:30, le 1er janvier,
  avril, juillet et octobre.

Après un arrêt, le tick rattrape uniquement le dernier créneau dû. `scheduled_for`
identifie ce créneau dans `admin_job_run` et un index PostgreSQL unique sur
`(job_key, scheduled_for)` empêche une seconde exécution ; une ligne `interrupted` libère
le créneau pour permettre le rattrapage d'un processus réellement interrompu. Le verrou
advisory par clé continue d'interdire deux exécutions simultanées.

`GET /api/admin/jobs` expose désormais `nextRunAt`, calculé avec le même modèle et le
dernier créneau enregistré. Le tableau React affiche ce prochain passage avec les
cadences « toutes les 10 minutes », « quotidien » ou « trimestriel ».

## Relances d'imports SA3-D

`POST /api/admin/jobs/{key}/run` exige la session superadmin et son jeton CSRF. Le
catalogue expose `manualTriggerAllowed` et n'autorise la relance que pour
`import-school-holidays` et `import-public-holidays`. La route n'accepte jamais un nom de
commande brut : elle exécute la commande et ses arguments fixes issus du catalogue avec
la source `superadmin` et l'identité de l'acteur dans `admin_job_run`.

L'exécution est synchrone, idempotente et protégée par le même verrou advisory que les
passages planifiés ; une exécution déjà active répond 409. Le dashboard React demande
confirmation, affiche l'état en cours puis rafraîchit l'historique. Les rappels,
réconciliations et purges ne sont pas déclenchables depuis cette route ; en particulier
`app:purge-orphans` reste volontairement manuel.

## Fraîcheur des données et alerting (2026-07-18)

`GET /api/admin/freshness` (`AdminDataFreshnessService::referentials`) répond à « mes
données de référence sont-elles à jour ? ». Chaque ligne porte une clé, un libellé, la
date de dernière mise à jour, le seuil de péremption en jours et un booléen `stale` :
les deux référentiels calendaires (`school-holidays`, `public-holidays`, rapprochés de
leur job d'import et de leur table) et la **couverture de sauvegarde** (`db-backup`,
via `BackupCoverage` : le dernier dump du répertoire de backups confronté à la dernière
activité réelle — un parc sans activité n'est pas « en retard », un parc actif sans dump
l'est).

Le job `health-alerts` (`app:health:alert`, toutes les 10 minutes) croise la santé
technique, ces lignes de fraîcheur et les compteurs solveur sur 24 h via
`HealthAlertEvaluator`, puis notifie. Les règles sont volontairement étroites pour ne
pas fabriquer de faux rouges :

- un service n'alerte que sur `down` — `unknown` est un indéterminé (Mercure non
  configuré, heartbeat worker expiré pendant un déploiement), pas un incident ;
- **exception Messenger** : sa file `unknown` alerte, elle — une file illisible masque
  des générations qui s'empilent, c'est le trou de silence du composant central. Le
  seuil de backlog est **lu du payload santé** (`backlogWarningThreshold`), pour que le
  dashboard et l'alerte ne puissent pas diverger ;
- taux `INFEASIBLE` : au-delà de 50 % sur 24 h, avec un **plancher de volume** — jamais
  d'alerte sur deux générations.

`AdminAlertStateStore` mémorise l'état (`preview` / `commit`) : on notifie sur
**transition**, pas sur état stable — sinon un incident non résolu enverrait un mail
toutes les 10 minutes.

## Actions de support SA4 v1

`GET /api/admin/actions` publie un **catalogue fermé** (`AdminActionCatalog`) : clé,
libellé, description, un drapeau `dangerous` qui pilote la confirmation côté UI, et le
**schéma d'arguments** de l'action (A3, 2026-08-11) — par argument : un `key`, un `label`,
un `required`, une **enum fermée de `choices` `{value,label}`** et, pour un argument
conditionnel, un `gate {argument, forbiddenValues}`. La console rend ses pickers DEPUIS ce
schéma, jamais d'une liste en dur.
Les actions livrées sont `reset-generation-quota` (remise à zéro du compteur de
générations de la saison, non destructive), `ffbb-resync` (P2-18, 2026-08-04 : ré-importe
l'identité FFBB du club — nom, coordonnées, logo, comité/ligue — le même `FfbbClubPopulator`
en mode refresh que le bouton de la fiche club ; échec FRANC si l'organisme est introuvable,
jamais un succès silencieux), `mark-next-season-paid` (P1-5, 2026-08-04 :
enregistre le paiement de la saison SUIVANTE — l'abonnement se paie par saison — et
ouvre le gate de bascule ; idempotente, le marqueur ne recule jamais), `reset-current-season` (vide la saison
courante — le club repart au wizard, la saison et le club survivent) et
`purge-old-seasons` (supprime les saisons au-delà de la rétention).

**Attribution d'offres (A3, 2026-08-11)** : une **seule** entrée `set-plan` (« Offre »)
remplace les six `set-plan-*` figées. Son schéma d'arguments fermé porte `plan` (enum des
codes d'offre — `decouverte`…`beta`, miroir de `SetClubPlanCommand`) et `paidSeason`
(enum `current|next`, la saison encaissée). Règle fondateur portée par le schéma, pas par
un `if` du contrôleur : `paidSeason` est **requis** pour toute offre payante (Bêta
comprise — sans marqueur elle naît expirée) et **interdit** sur `decouverte` (rien à
encaisser). Avec `paidSeason`, la commande pose l'offre ET marque la saison encaissée dans
la MÊME transaction (pivot sur `demo_today` pour un club démo, D6). `reset-credits` (ré-ouvre
le pool de crédits de sortie du plan Découverte) reste une entrée à part. C'est la **seule
porte d'attribution** d'une offre — l'offre Bêta n'a aucun autre chemin par construction,
et le paiement v1 (virement) se matérialise par `set-plan` (offre + saison encaissée)
directement.

**Arbitrages P3-4 (PR B, 2026-08-05)** : `GET /api/admin/club-requests` liste les demandes
de création de club **pending ET expirées** (« le superadmin peut valider si besoin » — le
lien public meurt à J+7, pas la console) ; `POST /api/admin/club-requests/{id}/decision`
approuve (provisionne, comme la page publique) ou refuse — mêmes gardes que les actions
(session + CSRF + audit posé avant toute garde). `GET /api/admin/pending-memberships` +
`POST /api/admin/pending-memberships/{id}/activate` : **activer une adhésion** quand la
passation n'a pas lieu (gestionnaire parti fâché — décision fondateur). ⚠ Depuis P1-1 PR B
(2026-08-10), la porte ne voit et n'active que les adhésions **en attente** (`deactivated_at IS NULL`) —
un membre **désactivé par son club** ne se restaure que par le geste club
(`POST /api/memberships/{id}/reactivate`) : la console ne contourne pas la décision du club. Le job planifié
`club-approval-digest` (quotidien 08:30, relançable) porte les relances (3 j restants +
jour J) et l'expiration.

`POST /api/admin/clubs/{clubId}/actions/{key}` exécute l'action. La route **n'accepte
jamais un nom de commande brut** : elle prend la commande et ses arguments **fixes** du
catalogue, et n'ajoute que des **arguments runtime bornés par le schéma fermé** de l'action
(enum de valeurs seule, aucun texte libre représentable) plus le club — lui-même validé.
Le body JSON optionnel est validé **fail-closed** AVANT toute exécution : clé inconnue,
valeur hors enum, argument requis manquant, argument interdit présent, ou tout body sur une
action SANS schéma → **400**, rien ne tourne. Le **schéma porte la règle** (dont la
conditionnalité de `set-plan`) ; le contrôleur ne fait que l'appliquer. L'ordre des gardes
est délibéré : le contexte d'audit (club visé + action visée) est posé **avant toute garde**,
pour qu'une tentative *refusée* soit tracée ; puis CSRF de session, puis identité
`SuperAdmin`, puis existence de l'action, puis validation du body (schéma), puis forme UUID
du club, puis existence du club. Aucun `requirements` de route sur `clubId` : il ferait un
404 **au routeur, avant le firewall**, ce qui apprendrait la forme attendue à un probe
non authentifié sans rien tracer.

L'historique vit sous la clé `action:{key}`, jamais mélangé au dernier passage du
panneau jobs, et le verrou advisory est **partagé avec le job planifié** quand la
commande en a un (`purge-seasons`) : geste manuel et cron se sérialisent au lieu de se
masquer.

## Journaux read-only (2026-07-25)

Trois lectures paginées complètent la console, toutes protégées et auditées comme le
reste de `/api/admin/**` :

- `GET /api/admin/audit-log` — le journal d'audit SA0, filtrable par `actor` (UUID
  validé), `route` et `since` ;
- `GET /api/admin/messenger/failed` — les messages de la failure queue ; un message dont
  la classe n'existe plus dans le code est rendu « (classe inconnue) » plutôt que de
  faire tomber la lecture ;
- `GET /api/admin/system-errors` — les erreurs système, filtrables par `since` (date ISO).

Toutes trois pratiquent la même pagination (`page`/`limit`, limite par défaut et plafond
côté serveur) et rendent **400** sur un paramètre malformé plutôt que de le laisser
atteindre PostgreSQL. `GET /api/admin/health` a par ailleurs été étendu de façon
**append-only** par `containers[]` et `externalDependencies[]`.

Ces trois routes sont des **contrôleurs purs**, donc invisibles de l'export tant qu'une
entrée ne les déclare pas — elles le sont depuis le 2026-08-11 (P4-47, soldée), avec toute
la surface `/api/admin/**`, dans `AdminJournalPaths::contribute()` (un des 16 contributeurs
par domaine que `CustomRoutesOpenApiFactory` compose depuis P4-138, 2026-08-30 —
`backend/docs/backend-inventory.md` §OpenAPI).
⚠ Le contrat de `/api/admin/messenger/failed` porte explicitement que le **body d'un message
n'est jamais rendu** (PII) : seuls la classe, l'horodatage et le message d'erreur sortent.
