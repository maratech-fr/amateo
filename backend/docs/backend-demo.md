# Backend — Module démo & horloge simulée

> Le module démo (seed, création de club de démonstration), la boîte aux lettres d'un club à horloge simulée et le cockpit temporel (overlays période/événement). Découpé mécaniquement de `backend-inventory.md` (DOC-59).

Last verified @ 2026-10-08 (P4-266, `documentation-update`) : la mention de `ResourceChangeStaleScheduleListener` (§ Re-dater une racine CLOSURE) recalée — le listener est SUPPRIMÉ, la péremption se dérive désormais de l'empreinte de structure (`structureHashOfPlan`) ; précisé que le simple déplacement de fenêtre ne fait PAS diverger l'empreinte (seule l'entrée/sortie d'une contrainte datée le fait — confronté à `CalendarEntryStateProcessor.php` et au scénario Behat `plan-de-periode-en-overlay.feature`). Reste du fichier non re-confronté cette passe ; l'historique de vérification vit dans `git log -p --follow` ce fichier.

### Module démo

Deux mécanismes distincts, à ne pas confondre :

1. **Horloge simulée PAR CLUB** — un seul module décide : `App\Clock\ClubClock`
   (`src/Clock/ClubClock.php`, décore le service `clock`, `services.yaml`). Son point d'entrée
   unique `simulatedTodayFor(Club $club)` lit `Club::$simulatedToday`
   (`src/Entity/Club.php:127`, colonne `club.simulated_today`, ex `demo_today` — renommage pur,
   `Version20261002090000`) ; si posée **ET que le club est démo**, `now()` rend la **date
   simulée** à l'**heure réelle** dans le fuseau réel pour ce club ; sinon l'horloge est vraie.
   **Réservée à un compte de DÉMONSTRATION** (`is_demo = TRUE` — décision fondateur 2026-10-02,
   revirement sur une capacité un temps généralisée à tout club : décaler l'horloge d'un vrai club
   lui donnerait la main sur des mécanismes datés qui ne le concernent pas, radar/bascule de
   saison/e-mails), **au niveau LECTURE** (`ClubClock::simulatedTodayFor`, pas seulement les
   écritures — SEC-30, 2026-10-04 : la lecture seule ne contrôlait pas encore `is_demo`), et par
   une contrainte **CHECK** en base (`club_simulated_today_demo_only` —
   `simulated_today IS NULL OR is_demo`, `Version20261004140000`, backstop si un chemin
   applicatif oubliait la garde). Trois chemins écrivent `simulatedToday`, tous gardés `is_demo` :
   la commande CLI `app:club:clock`
   (`src/Command/ClubClockCommand.php`, options `--club` id|code FFBB, `--date`, `--clear`, refus
   franc sur un club non démo — `--yes` n'existe plus) ; deux routes superadmin (`AdminDemoController`,
   ci-dessous) `POST /api/admin/demos/{bccl|prospect}/clock` (club démo courant d'un compte démo,
   résolu SERVEUR) ; et, APPLICATIVE, `POST /api/club/clock` (`ClubClockController`, widget
   d'en-tête du compte démo, posée par son gestionnaire — rôle MANAGER, 403 si le club résolu
   depuis le JWT n'est pas démo). Drapeau DEV `APP_CLUB_CLOCK_ALL` (`.env`=0,
   `.env.dev`=1, défaut 0, `%app.club_clock_all%` dans `services.yaml`) : si actif et
   l'environnement ≠ `prod`, un club SANS `simulatedToday` emprunte le pin global du widget
   DevClock (point 2 ci-dessous) — neutralisé en production quel que soit le réglage.
   **Bornes de la date simulée** (BCK-34, décision fondateur 2026-10-02) : une date posée via
   `ClubClockController` ou `AdminDemoController` doit tomber entre le **début de la saison EN
   COURS** et la **fin de la saison SUIVANTE** du club — `SeasonResolver::simulatedClockBoundsAmong`
   (`src/Service/SeasonResolver.php`), confrontée à l'horloge **RÉELLE** (jamais la date déjà
   simulée, qui se re-validerait circulairement) ; hors bornes → 422 nommant les deux dates ; sans
   saison suivante, le plafond est PROJETÉ à un an après la fin de la saison en cours. **Horloge
   RÉELLE explicite** (`app.clock.real`, alias public:false du service `clock` natif AVANT sa
   décoration par `ClubClock`, `services.yaml`) : injectée (`#[Autowire(service: 'app.clock.real')]`)
   partout où une DURÉE ou un HORODATAGE DE SÉCURITÉ ne doit JAMAIS suivre la date simulée d'un
   club démo — TTL du jeton Mercure (`MercureAuthController`), lien de changement d'e-mail
   (`EmailChangeVerifier`), délai d'effacement RGPD (`AccountErasureService`), préavis de
   suppression d'un compte orphelin (`OrphanAccountNotifier`), horodatage du journal d'audit
   (`AuditTrail`). **P4-304** (2026-10-05, trois restes fermés) : TTL du lien de vérification
   d'e-mail (`EmailVerifier`) et son horodatage `emailVerifiedAt`
   (`EmailVerificationService`), péremption d'une demande de création de club
   (`ClubApprovalController`), preuve de consentement `termsAcceptedAt`
   (`RegisterService`), dernière connexion `lastLoginAt` (`LoginSuccessListener`) — toutes
   publiques ou déclenchées par un JWT, donc exposées à la date simulée d'un club démo via
   `TenantFilterListener`. Les DATES MÉTIER (saisons, échéances) continuent de traverser le
   service `clock` décoré. **Rétention RGPD des comptes inactifs** (`PurgeInactiveUsersCommand`,
   `app:users:purge-inactive`) exclut désormais les comptes `is_demo` (P4-304, warn() ET erase()) —
   même motif que `OrphanAccountNotifier`, une horloge simulée souvent passée les ferait paraître
   inactifs dès leur création.
2. **`DevClockController`** (`/api/dev/clock`, GET/POST) est un mécanisme **global**, sans
   rapport avec `simulatedToday` d'un club précis : il pin/relâche l'horloge de TOUTE l'app dans
   Redis (`DevClockStore`), lue par `SimulatedClock` (alias de `ClockInterface` en dev) et — via
   `APP_CLUB_CLOCK_ALL` — par `ClubClock` pour les clubs sans horloge propre (point 1). Gardé par
   `%kernel.debug%` — 404 en environnement non-debug (donc en prod).

Le club de démonstration permanent (BCCL) est créé/réinitialisé par `app:demo:seed`
(`src/Command/DemoSeedCommand.php`, connexion `admin`, options
`--password`/`--email`/`--if-absent`) via
`BcclSeeder` + `BcclSeedProfile` (`src/Seed/`, autant d'identités fictives que de coachs du
seed dev, substituées de façon positionnelle et déterministe — liste courte = refus,
`BcclSeedProfile::FICTIONAL_COACHES`). Le profil **dev** porte, en plus de la structure (équipes,
gymnases, créneaux, coachs, contraintes) et de la transcription du planning réel (§ci-dessous),
les **liens réels du club** (`BcclSeeder::seedTeamLinksAndSharedBlocks`, données fondateur
posées à la lettre) : **10 `TeamLink`** de type `NOT_SIMULTANEOUS` (équipes qui partagent des
joueurs, intensité entraînement au défaut `PREFERRED`) et **8 `SharedTrainingBlock` de SOCLE**
(`schedulePlanId` NULL, `commonSessions=1` chacun — les 3 CEC du mercredi + 5 paires jeunes,
purgés et recréés à chaque run, idempotent). Les créneaux `VenueTrainingSlot` des 8 cases
partagées portent une **capacité de 1** (pas de palliatif de capacité 2/3 — un bloc complet
compte pour UN occupant, `ReservationGroupOccupancy` §SharedTrainingBlock ci-dessus) et les
réservations socle posées sur ces cases sont EXACTEMENT les membres du bloc correspondant.

Les profils **dev ET prod** portent aussi (section 13bis de `BcclSeeder`,
`BcclSeedProfile::seedWeekendMatchLayout`, `false` en démo et en charge), la **répartition WE
réelle des matchs** du club (données fondateur, relevées de la base réelle le 2026-09-29) : 10
`VenueMatchWindow` (fenêtres d'accès match des gymnases), 32 `TeamMatchHabit` — le créneau idéal
(jour + coup d'envoi + gymnase exacts + tag `week`) de chaque équipe qui reçoit le week-end, dont
8 paires d'Armand/Debarros qui portent la même heure+gymnase en semaine A et B (l'alternance,
P4-271 — plus aucune entité de rotation) — zéro `Fixture` (les équipes ne sont pas engagées tant
que le calendrier FFBB n'est pas importé). Idempotent (purge+recréation des fenêtres,
find-or-create des créneaux idéaux). Les durées de match par catégorie (`SportCategory.matchMinutes`/
`warmupMinutes`, résolues par `MatchDurationResolver`) sont également recalées sur la base réelle
(Senior 120 min + 45 d'échauffement, U15 105 min sans échauffement dédié, U21 120 min sans
échauffement dédié), réappliquées à chaque run. Détail complet :
[`module-matchs.md`](../../specs/courantes/module-matchs.md).

Les profils **dev ET prod** portent aussi (section 13ter, `BcclSeedProfile::seedOpponentData`,
`false` en démo et en charge) l'**amorçage du module « adversaires »** depuis
`BcclOpponentData` (`src/Seed/BcclOpponentData.php`, données FÉDÉRALES PUBLIQUES relevées de la
base réelle du club) : 75 `OpponentDirectoryEntry` (table GLOBALE, upsert natif idempotent), 101
`OpponentVenueLink` du club (find-or-create, un lien `MANUAL` déjà posé par un gestionnaire n'est
jamais écrasé, seul un lien `AUTO` est réactualisé), 15 `OpponentVenueSuggestion` (table GLOBALE,
upserts natifs, **compte de choix jamais fabriqué** — un prod frais n'a pas encore de choix). But
de cet amorçage : que le ré-import des matchs en prod retrouve ses localisations sans re-résoudre.

Un club de démonstration **prospect** (à partir d'un code
FFBB réel) se crée par `app:demo:create` (`src/Command/DemoCreateCommand.php`, options
`--ffbb`, `--name`, `--animator-email`, `--animator-password`), dont le cœur (déplacement de
l'animateur, provisioning, populate FFBB + import des équipes engagées, best-effort synchrone)
vit dans `DemoClubMaterializer::materialize()` (`src/Service/DemoClubMaterializer.php`). Trois
contrôleurs relaient ces gestes en environnement e2e/test/démo : `POST /api/dev/approve-club-request`
(`DevClubApprovalController`, approuve la demande PENDING de l'appelant) et
`POST /api/dev/mark-season-paid` (`DevSeasonPaymentController`, marque payée la saison SUIVANTE
du club courant — respecte l'horloge simulée) restent gardés `%kernel.debug%` seul (404 en prod).
**`POST /api/dev/demo-register`** (`DevDemoRegisterController`) — le raccourci « effet waouw » :
appelé par `RegisterPage` juste APRÈS le 202 neutre du vrai register (rail register/verify
byte-intact), il fait naître le club DU PROSPECT depuis le formulaire réel plutôt qu'un terminal,
pour l'adresse démo fixe SEULE (`app.demo_animator_email`, MAISON UNIQUE =
`DemoCreateCommand::DEFAULT_ANIMATOR_EMAIL`, `demo@amateo.fr` — toute autre adresse : 422
`not_demo_account` sans effet) — **n'a plus 404 hors debug** (`DevDemoRegisterController.php:115`) :
en debug elle se comporte comme avant (aucune fenêtre requise, les e2e du register en dépendent) ;
en PROD elle n'agit QUE si la **fenêtre d'activation** du compte animateur est ouverte
(`animatorWindowIsOpen()`, `DevDemoRegisterController.php:247`), sinon le MÊME 422
`not_demo_account` qu'une adresse quelconque (aucun oracle « fenêtre fermée »). Ordre des gardes
AVANT toute écriture : mot de passe d'un compte existant **VÉRIFIÉ, jamais écrasé** (401 sans
effet) ; code FFBB visé remplaçable seulement s'il porte la propre démo ISOLÉE de l'animateur —
un club réel, la démo d'un autre animateur ou une démo partagée refusent en 409 ; démontage du
club démo précédent de l'animateur VALIDÉ intégralement avant la moindre destruction (purge du
workspace + suppression de la ligne `club`, pour libérer son code FFBB —
`DemoClubMaterializer::teardownPreviousDemo()`, `DemoTeardownRefusedException` en 409 sinon rien
détruit). Une trace d'audit **globale** (`AuditAction::DEMO_SHORTCUT`, hors périmètre club — elle
doit survivre à la destruction de la ligne club) est posée. **Elle ne connecte plus** (PR C,
2026-09-30) : succès → `JsonResponse` `{membershipStatus, clubId}` SANS cookie JWT
(`DevDemoRegisterController.php:214`, les deux dépendances JWT ont été retirées du contrôleur) —
le front (`RegisterPage.tsx`) montre alors un écran « Démonstration » puis invite à se connecter
normalement par `/api/login`. La route est exposée au front par
`GET /api/register/config` (champs additifs `demoShortcut`/`demoEmail`) **quand `kernel.debug`
OU la fenêtre d'activation de l'animateur est ouverte** (`AuthController::registerConfig()`,
`AuthController.php:236`) — sinon les deux champs sont nuls, aucun oracle ; exposer `demoEmail`
fenêtre ouverte est un mini-oracle limité à la fenêtre, assumé. `ProdSecretGuard::assertForEnvironment()`
(`src/Security/ProdSecretGuard.php`, invoqué depuis `Kernel::boot()`) refuse de démarrer en
environnement `prod` avec `APP_DEBUG` résolu à `1`/`true` — un verrou qui couvre `approve-club-request`
et `mark-season-paid` d'un seul coup, indépendamment d'un oubli de garde individuelle ; il ne
concerne plus `demo-register`, désormais joignable en prod par construction (gardée par la fenêtre,
pas par `kernel.debug`).

**Drapeau d'identité `app_user.is_demo`** (SEC-28, 2026-10-04) : distinct de la fenêtre
d'activation ci-dessous — il vaut `true` en PERMANENCE pour un compte démo (animateur, BCCL),
fenêtre ouverte ou non, posé à la création (`DemoCreateCommand`, `DevDemoRegisterController`,
`DemoSeedCommand`) et backfillé sur les comptes existants par adresse
(`Version20261004150000`). Remplace la reconnaissance historique **par adresse** sur les gestes
d'identité : `UserChecker::checkPostAuth` (gate connexion hors fenêtre), `AuthController::
updateMe/changePassword/requestEmailChange` et `DeleteAccountController` (`refuseDemoMutation()`,
403 « Ce compte de démonstration ne peut pas être modifié. ») refusent désormais sur CE drapeau —
un compte démo ne peut ni changer de prénom/nom/e-mail/mot de passe ni se supprimer, et il est
hors de la règle des comptes orphelins (`OrphanAccountNotifier`). Côté écran, le profil d'un
compte démo est en lecture seule (bandeau, `frontend/src/features/profile/ProfilePage.tsx`),
depuis `/api/me.isDemo`.

**Fenêtre d'activation démo** (décision fondateur 2026-09-30) : les deux comptes démo — animateur
`demo@amateo.fr` et gestionnaire BCCL `demo-bccl@amateo.fr` (`app.demo_bccl_email`, MAISON UNIQUE
en `services.yaml`, lu par `DemoSeedCommand` pour le défaut de son option `--email`) — ne se
connectent que pendant leur fenêtre : `User::$demoActiveUntil` (`User.php:98`, colonne
`app_user.demo_active_until`, additive nullable, NULL = inactif par défaut) et
`User::isDemoWindowOpen(DateTimeImmutable $now)` (`User.php:326`, `$demoActiveUntil > $now`) —
toujours confrontée à l'horloge **RÉELLE**, jamais à `simulated_today` (un club démo ne doit pas
pouvoir rouvrir sa propre porte). `UserChecker::checkPostAuth()` (`UserChecker.php:36-50`) refuse
la connexion des deux comptes démo hors fenêtre d'une manière **byte-identique** à un mauvais mot
de passe (`Invalid credentials.`, aucun oracle « fenêtre fermée ») ; tout autre compte est
insensible à la colonne. Le seed (`app:demo:seed`) n'ouvre jamais la fenêtre lui-même : c'est la
**console superadmin** qui pose/retire l'activation (`AdminDemoController`, ci-dessous). NR
bloquant `DemoWindowTest` + feature Behat `la-demo-ne-s-ouvre-que-pendant-sa-fenetre`.

**Console démo** (`AdminDemoController`, `/api/admin/demos*`, PR B — mêmes gardes que les
actions de support SA4, connexion `admin`, **aucun `club_id` posé**) : `GET /demos` lit l'état
des deux comptes (fenêtre ISO, club démo courant résolu SERVEUR depuis l'adhésion active, et
`simulated_today` pour les **deux** comptes, bccl ET prospect) **ainsi que la liste des clubs démo
CONSERVÉS** (`retained`, P4-294 — lue par la TABLE `club.demo_retained_until`, jamais par une
adhésion : un club conservé est détaché de l'animateur) : nom + échéance, triés par échéance
croissante, aucune action de prolongation, et l'état du reset BCCL en cours (`reset`, voir
ci-dessous) ;
`POST /demos/{bccl|prospect}/activate` pose `demo_active_until` à now+4 h à l'horloge **RÉELLE** —
un re-clic **REDÉMARRE** la fenêtre, jamais une addition ; `POST /demos/{target}/deactivate` la
ferme (`NULL`).

**`POST /demos/bccl/reset` — rail ASYNCHRONE (BCK-35, lot robustesse 2026-10-03)** : le reset du
seed BCCL tournait auparavant en sous-processus SYNCHRONE depuis la requête HTTP (`DemoResetRunner`,
patron `Process`) — nginx coupait à 120 s pendant que le seed continuait (504 trompeur côté
console), et deux clics rapprochés lançaient deux seeds concurrents. Le contrôleur prend d'abord
un verrou Redis (`DemoResetTracker::begin()`, `src/Service/DemoResetTracker.php` — clé
`demo_reset:bccl:lock`, `SET NX EX 900`) : échec d'acquisition → **409** net, pas de double seed.
Succès → enfile `ResetDemoBcclMessage` (Messenger) et répond **202**. Le worker
(`ResetDemoBcclHandler`, `src/MessageHandler/ResetDemoBcclHandler.php`) relance `app:demo:seed` en
sous-processus (toujours `DemoResetRunner`/`DATABASE_ADMIN_URL`), remet `club.simulated_today` à
`NULL` **sans toucher la fenêtre du compte**, pose l'issue terminale (`succeeded`/`failed`) et
relâche le verrou par compare-and-delete du token en `finally` — un worker tué (`SIGKILL`)
n'enlise jamais le verrou au-delà de son TTL (900 s, au-dessus du budget max du seed). L'état
(`running`/`succeeded`/`failed` + horodatage) est exposé par `GET /api/admin/demos` (clé `reset`)
et dérivé du VERROU pour `running` (un `running` sans verrou vivant — crash non relâché — est
rendu comme absent, jamais figé à l'écran) ; la console affiche « Réinitialisation en cours… » et
bloque le bouton pendant l'exécution. `POST /demos/{target}/clock` pose (`date`) ou relâche
(`clear`) `simulated_today` du club démo COURANT de `bccl` ou `prospect`, résolu SERVEUR depuis le
compte, gardé `is_demo = TRUE` — même garde de calendrier que `ClubClockCommand` (`2026-02-31`
refusé), jamais de confirmation (un compte démo a les droits pleins, aucun e-mail réel en jeu).
**Vider la boîte aux lettres** (P4-16, ci-dessous) : `reset` la vide TOUJOURS (les e-mails
interceptés d'une démo précédente ne survivent pas à une réinitialisation) ; `clock` ne la vide
que sur **`clear`** — poser/changer une date ne la touche pas, seul le retour à « aujourd'hui »
le fait (décision fondateur : hors horloge, le club redevient réel et enverrait pour de vrai, les
e-mails boxés n'ont plus de raison d'être). L'écriture et le vidage de boîte au `clear` passent par
`writeClock()`, maison unique du contrôleur.

**Conserver le club démo prospect 14 jours** (`POST /demos/prospect/retain`, P4-294, décision
fondateur 2026-10-03 option B) : refusée en **409** tant que la fenêtre démo prospect est OUVERTE
(conservation et fenêtre d'accès ne se chevauchent jamais) ; geste atomique sinon — l'horloge
revient à `NULL` (boîte vidée, même `writeClock(..., clear:true)` que `clock`), l'animateur est
**DÉTACHÉ** du club (`DELETE FROM club_user`, sinon le raccourci démo suivant refuse en 409 et la
purge nocturne par adhésion le détruirait), et `club.demo_retained_until` reçoit aujourd'hui + 14 j
(Europe/Paris, horloge RÉELLE, durée FIXE sans prolongation — `AdminDemoController::RETENTION_DAYS`).
Reprise ensuite par l'approbation P3-4 du contact officiel homonyme (`ClubRepository::
findRetainedDemoByFfbbCode`, ci-dessus) ou détruit à expiration par la purge nocturne (ci-dessous).

**L'horloge simulée ne vit que pour un compte de démonstration** (décision fondateur 2026-10-02) :
il n'existe plus de route superadmin posant `simulated_today` sur un club réel — `AdminDemoController`
ne porte plus que les deux routes `/demos/{target}/clock` ci-dessus, et la liste des comptes clubs
de la console ne porte plus de bouton d'horloge ni de champ `simulatedToday`
(`AdminMonitoringService`/`AdminMonitoringPaths`). Un gestionnaire démo pose désormais l'horloge
**depuis l'app** : `POST /api/club/clock` (`ClubClockController`, ci-dessous).

Front des deux cartes démo : 8ᵉ onglet « Démos » (`frontend/src/features/admin/tabs/tabsConfig.ts`),
`DemosSection.tsx` — carte « Date simulée » (`ClockCard`, maison unique factorisée) rendue sur les
**deux** comptes (BCCL ET prospect), heures de fenêtre rendues à l'heure de Paris. NR bloquant
`Integration/Admin/AdminDemoResetTest` (401/403, 400 sur date malformée, `clear` →
`simulatedToday` NULL + boîte vidée + autre club intact).

**Widget d'horloge du compte démo** (`POST /api/club/clock`, `ClubClockController`, front
`app/DemoClockWidget.tsx`) : le gestionnaire d'un club démo pose/relâche lui-même l'horloge depuis
l'en-tête de l'app, sans passer par la console. Tenant résolu SERVEUR depuis le JWT (`_club_id`),
réservé au rôle MANAGER (`ManagementAccessGuard::assertManager`, 403 sinon) ; le club résolu est
ensuite vérifié `is_demo` — un vrai club est **refusé en 403**, avant toute lecture du corps.
Même forme de corps que les routes console (`{date}` xor `{clear:true}`, date qui se relit à
l'identique, `2026-02-31` refusé ⇒ 422), même vidage de boîte au `clear` via la maison unique
`ClubMailboxPurgerInterface`. Après l'écriture, le front invalide `/api/me` (→ `clock.ts`
`useApplySimulatedClock`) et tous les écrans datés se recalent. NR bloquant
`Integration/Api/ClubClockEndpointTest` (démo manager 200 ; démo non-manager 403 ; vrai club
manager 403 + horloge intacte ; date malformée 422 ; `clear` → NULL + boîte vidée ; un autre club
jamais touché).

**Purge nocturne du prospect périmé** : `app:demo:purge-stale`
(`src/Command/DemoPurgeStaleCommand.php`, cron-runner quotidien **03:15**, clé
`demo-purge-stale` dans `AdminJobCatalog`) détruit les clubs démo de l'animateur PROSPECT créés
AVANT le jour courant (Europe/Paris) — la ligne `club` part, ce qui libère son code FFBB ; une
réactivation le même jour réutilise le club existant. Chemin sûr
`DemoClubMaterializer::teardownStaleDemos()` (miroir de `teardownPreviousDemo()` ci-dessus) : un
club non démo ou démo PARTAGÉ (un autre membre) est SAUTÉ, jamais détruit ; un club créé le JOUR
MÊME est gardé ; la démo BCCL permanente n'est jamais une adhésion de l'animateur prospect, donc
hors scope par construction. **Purge aussi les clubs démo CONSERVÉS EXPIRÉS** (P4-294,
`DemoClubMaterializer::teardownExpiredRetainedDemos()`, indépendante du compte animateur) :
sélection par la TABLE `club` (`is_demo AND demo_retained_until < aujourd'hui` — un club vit
jusqu'à la FIN de son jour d'échéance), jamais par adhésion (un club conservé en est détaché,
sinon il serait immortel) ; skip défensif si un membre actif existe (repris entre-temps). NR
bloquant `Integration/Command/DemoPurgeStaleCommandTest` + `Security/DemoRetainedClubReclaimTest`.

Distinct du club de démonstration : `app:bccl:seed` (`src/Command/BcclSeedCommand.php`) seede le
club **dev BCCL** (identités FICTIVES par défaut — gestionnaire `dev-bccl@amateo.local`, coachs aux
surnoms ; vraies identités via le fichier local gitignoré `config/seed/bccl.identities.local.json`,
code FFBB ARA0069036) via le même `BcclSeeder` + `BcclSeedProfile::dev()`. **CREATE-ONLY** — à l'inverse d'`app:demo:seed` (créer OU
RESET, purge le workspace à chaque appel sauf `--if-absent`) : cette commande ne fait RIEN
(SUCCESS, aucune écriture) si le club existe déjà ; le reset délibéré passe par `make db-empty`
(ou `make reset`, racine) sur une base jetable — aucune fixture Doctrine ne porte plus ce rôle.
Exclue de
l'auto-enregistrement (`services.yaml:96-99`), déclarée seulement dans
`services_dev.yaml`/`services_test.yaml` (jamais dans le conteneur de prod) et gardée en runtime
(refuse hors `dev`/`test`) : invisible en prod par construction — **seule** commande démo/seed à
porter cette restriction, `app:demo:seed` n'en a aucune. Connexion admin requise, comme
`app:demo:seed`. Appelée par `make play` (`backend/docs/commands.md`).

Le pendant PRODUCTION du club BCCL réel est `app:bccl:seed-prod`
(`src/Command/BcclProdSeedCommand.php`) — même `BcclSeeder` + `BcclSeedProfile::prod()`,
**CREATE-ONLY** comme `app:bccl:seed`, mais **AUTO-ENREGISTRÉE** (disponible en prod, à l'inverse
d'`app:bccl:seed` qui reste dev-only). Le gestionnaire (fondateur) arrive 100 % par
`--email`/`--first-name`/`--last-name`/`--password` (prompt masqué `askHidden` si le mot de passe
est absent, min. 12 caractères) — AUCUN prénom, nom ni credential en dur (AUD-SEC-29) ; d'éventuels
co-gestionnaires et vrais noms de coachs viennent du fichier local gitignoré. Comptes posés
**pré-vérifiés** (le rail `/register` est mort sans e-mail sortant en prod). Connexion admin
requise (RLS). **Anti-usurpation** : si un compte existe DÉJÀ pour `--email`
(vérifié ou non — ex. inscrit via `/register` entre le déploiement et le seed), la commande
échoue AVANT tout prompt et ne crée rien, au lieu d'adopter ce compte (et son mot de passe) en
gestionnaire du BCCL. NR bloquant : `BcclProdSeedCommandTest`. Runbook jour J complet :
[`docs/ops/deploy.md`](../../docs/ops/deploy.md) §1.10. Détail commande : `backend/docs/commands.md`.

### Boîte aux lettres d'un club à horloge simulée (P4-16)

Corollaire de l'horloge générique par club (§ ci-dessus) : un club dont `simulatedToday` est posé
ne doit **jamais** envoyer de vrai e-mail (décision fondateur 2026-10-02, « option A ») — chaque
e-mail est rangé dans sa « boîte » plutôt que parti.

- **`App\EventListener\ClockedClubMailInterceptor`** (`src/EventListener/ClockedClubMailInterceptor.php`,
  `kernel.event_subscriber`) écoute `Symfony\Mailer\Event\MessageEvent` à **priorité 100**, et
  n'agit **qu'à l'ENFILAGE** (`$event->isQueued()` — l'app envoie tout via le bus Messenger,
  `messenger.yaml` route `SendEmailMessage` en async ; rejeter ici empêche la mise en file, le
  worker ne voit donc jamais le message). **Liste blanche, défaut fermé** (revue sécurité
  2026-10-02) : seuls les e-mails MÉTIER marqués à la source par `App\Mail\ClubBusinessMail`
  (`src/Mail/ClubBusinessMail.php`, posé par les trois builders `PeriodReminderMailBuilder`,
  `TransitionReminderMailBuilder`, `CoachWishMailBuilder`) sont candidats ; les e-mails de COMPTE
  (reset de mot de passe, vérification/changement d'adresse, inscription, feedback, health) ne
  portent pas le marqueur et partent **toujours réellement**, même vers un membre du club — sans
  quoi un membre connecté d'un club à horloge pouvait capter dans la boîte le lien de reset d'un
  utilisateur d'un AUTRE club (`POST /api/password/forgot` est public mais le GUC est posé dès
  qu'un JWT est présent). Deuxième garde : **tous** les destinataires (To/Cc/Cci) doivent
  appartenir au club du GUC (membre actif `club_user`→`app_user.email`, ou `coach.email` — les
  campagnes de vœux partent vers des coachs non-utilisateurs), comparaison normalisée ; un
  destinataire hors club ⇒ envoi réel + warning sans contenu. Le club courant vient du GUC
  `app.club_id` lu en SQL brut (`current_setting`), jamais d'un header ; hors contexte club le GUC
  est vide et l'e-mail part réellement. Un club dont `ClubClock::simulatedTodayFor()` rend `null`
  (pas d'horloge active) enfile lui aussi normalement. Le corps est capté **tel qu'il est à l'enfilage** — donc **avant** la
  signature de marque (`EmailSignatureListener`, posée chez le worker qui ne tourne jamais ici) :
  `body_text` porte le texte métier, `body_html` reste en général nul.
- **`App\Entity\ClubMailboxMessage`** / table `club_mailbox_message` (migration
  `Version20261002120000`) : tenant + RLS **au patron standard** (`tenant_isolation FOR ALL`,
  porte `admin_all`, policy `readonly_tenant` — rien d'exceptionnel, couvert par l'énumération
  dynamique de `RlsIsolationTest`/`ReadOnlyRoleTest`, `docs/security/rls.md`), **append-only**
  (ni `version` ni `updated_at` — jamais modifié après l'enfilage) et `ON DELETE CASCADE` sur
  `club_id` (la purge d'un prospect emporte sa boîte sans ménage applicatif). `simulated_date` =
  le jour **simulé** du club à l'interception (`ClubDay::todayFor`) ; `created_at` = l'instant
  **réel** d'écriture (tri chronologique fiable, indépendant de l'horloge rejouée).
- **`GET /api/mailbox`** / **`GET /api/mailbox/{id}`** (`MailboxController`, lecture seule) :
  club résolu depuis `_club_id` (JWT, tenant pur, aucune garde gestionnaire — tout membre du club
  lit sa boîte) ; liste triée du plus récent (`createdAt` desc), détail ajoute `bodyText`/`bodyHtml`.
- **Vidage** : au reset de la démo BCCL et à la désactivation de l'horloge (`clear`, quel que soit
  l'appelant — console, CLI, ou le widget d'en-tête), `App\Service\ClubMailboxPurger`
  (`src/Service/ClubMailboxPurger.php`, maison UNIQUE du geste derrière `ClubMailboxPurgerInterface`)
  vide la boîte sur la connexion **admin** (`DELETE FROM club_mailbox_message WHERE club_id = …` —
  un `DELETE` sur la connexion runtime serait fail-closed, le firewall admin/une commande support
  ne posant jamais de GUC tenant). Détail § « Console démo » ci-dessus.
- **RGPD** : porte de sortie `ErasedClubPurger` (l'effacement RGPD **garde** la fiche club —
  identité FFBB — donc sans ce `DELETE` par `clubId` les adresses + corps d'e-mail resteraient) ;
  hors `SeasonDataPurger` (club-scoped sans saison, comme `opponent_venue_link`) ; exclue de
  l'export RGPD (`RgpdExportService` — artefact interne de démo, jamais de la donnée d'un
  workspace réel, les adresses y figurant sont déjà exportées via `club_user`/`app_user`).
- **Front** : entrée de nav « Boîte aux lettres » dans la barre du haut
  (`frontend/src/features/mailbox/MailboxNavItem.tsx`, compteur serveur), visible seulement si
  `me.club.simulatedToday` est posé ; écran `/boite-aux-lettres`
  (`frontend/src/features/mailbox/MailboxPage.tsx`, liste + détail).
- NR bloquant `Integration/Mail/ClockedClubMailInterceptTest` (`docs/testing/blocking-tests.md`).

### Cockpit temporel (overlays période/événement)

Détail : [`accueil-cockpit-temporel.md`](../../specs/courantes/accueil-cockpit-temporel.md). `CalendarEntry` (kind PERIOD/EVENT) est le **déclencheur daté** ; le planning de période est un `SchedulePlan` ancré à l'entrée, et c'est **le plan** qui pointe sa version (`chosenScheduleId`). Le pointeur inverse `overlayScheduleId` a été supprimé par ADR-0002 lot D-b.

**Re-dater une racine CLOSURE « d'un bloc » (D3 v1)** — une période qui porte un plan a normalement son identité GELÉE en écriture (`CalendarEntryStateProcessor::updateEntityFromInput`, 422 « Supprimez la période… ») ; ce cas précis se dégèle : `CalendarEntryPeriodType::CLOSURE`, `parentEntryId === null`, zéro semaine-enfant (`hasWeekChildren`). `PUT` change alors `startDate`/`endDate` dans les deux sens, sous le verrou de scope de `processPut` — `lockClubWindows(clubId, seasonId)` puis `lockPlanScope` : (1) `PeriodWindowUniquenessGuard::assertWindowFree` tranche AVANT toute mutation (409 franc, famille exclue) ; (2) le parent applique le PUT ; (3) `SchedulePlanProvisioner::resyncPeriodPlanWindow` (SQL direct, `start_date`/`end_date`/`version+1`) déplace la fenêtre du plan — le plan reste un gabarit hebdo SANS dates ; (4) les contraintes `venue_closed` dont `config.startDate`/`endDate` == l'ANCIENNE fenêtre EXACTEMENT suivent (une fermeture datée plus finement par le gestionnaire ne bouge pas) ; (5) `SchedulePlanProvisioner::renamePeriodPlanIfStillNamed` recale le nom du plan si le titre de l'entrée portait encore l'ancien libellé (inv. 12 : un renommage manuel reste souverain). Rien de neuf à écrire pour la péremption : `structureHashOfPlan` recalcule le payload de ce plan à chaque lecture, sans code dédié au re-datage (P4-266). Le simple déplacement de la fenêtre NE fait PAS diverger l'empreinte — `buildForPeriodPlan` ne dépend des dates de l'entrée QUE via la SÉLECTION des contraintes datées retenues (`PeriodConstraintSelection`) ; un re-datage qui ne fait entrer ni sortir aucune contrainte datée laisse l'empreinte à l'identique, donc le planning MUET. Elle ne diverge que si le re-datage change réellement cette sélection. **Tous les autres cas restent gelés** (message 422 distinct) : une racine `holiday` (liée au référentiel des vacances scolaires), une mère découpée, une semaine-enfant, et — même sur une racine CLOSURE redatable — `kind`/`periodType`/`schoolHolidayId`. NR : `Security/PeriodRedateTest`. Le geste d'édition vit à l'écran cockpit — `specs/courantes/accueil-cockpit-temporel.md` §5bis. Le prédicat « re-datable » vit UNE fois dans `App\Service\CalendarEntryRedatability::isRedatable()` (racine `closure`, sans mère, avec plan, sans semaines-enfants), consommé par le processor et par la sortie API — `CalendarEntryResource.redatable` (bool servi, une seule requête `EXISTS` par collection, mémoïsée par requête HTTP) ; le re-datage refuse en 422 une fenêtre hors saison (`assertWindowWithinSeason`) et début > fin (déjà porté par `CalendarEntryInput::validateShape`, POST et PUT). **Le re-datage refuse aussi en 422 une nouvelle fenêtre qui se décomposerait en plus d'un segment début·milieu·fin** — `processPut` appelle `App\Service\ClosureSegmentation::fullSegments` (géométrie PLEINE, indépendante de l'horloge, sur la NOUVELLE fenêtre) : `count(...) > 1` ⇒ « Cette indisponibilité aurait une semaine entamée : re-datez-la sur des semaines complètes, ou adaptez-la par début, milieu, fin » — sans cette garde, D3 contournerait le découpage imposé au POST. Une mère déjà découpée en semaines-enfants reste, elle, hors du périmètre `isRedatable` — elle a son propre geste, D3 v2 ci-dessous.

**Re-dater une mère DÉCOUPÉE, sous aperçu puis confirmation (D3 v2)** — le prédicat `App\Service\CalendarEntryRedatability::redateNeedsPreview()` (racine `closure`, sans mère, ≥ 1 semaine-enfant, SANS plan-bloc — exclusif de `isRedatable`) sert `CalendarEntryResource.redateNeedsPreview`. Le re-datage n'est plus un `PUT` direct : `POST /api/calendar_entries/{id}/redate-preview` (`RedatePreviewController`, LECTURE PURE, aucun persist) rend `{effects, token}` calculés par le foyer unique `App\Service\SplitMotherRedatePlanner` — il apparie chaque ancien enfant à un nouveau segment de la fenêtre visée (le RÔLE prime : start↔start, end↔end sans condition ; les milieux par recouvrement de lundis décroissant) et rend un verdict par ligne : `keep`, `shift` (l'enfant et son plan glissent, versions conservées, marqués à régénérer), `absorb`/`vanish` (plan supprimé en cascade), `birth` (enfant + plan neufs VIDES), `holiday_takes_over` (la nouvelle fenêtre recoupe une entrée HOLIDAY à plan — pas de 409, `PeriodWindowUniquenessGuard::governingWindows`/`assertWindowFree` gagnent `closuresOnly` pour cet effet). `token` = sha256 canonique de l'état lu (mère + fenêtres + par enfant trié) ; le `PUT` (`CalendarEntryStateProcessor::prepareSplitMotherRedate`/`applySplitMotherRedate`) applique EXACTEMENT ce plan sous `previewToken` — 422 sans jeton, 409 si un recalcul SOUS VERROU diverge (la période a bougé depuis l'aperçu). NR bloquant `Security/SplitMotherRedateTest`.

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/calendar_entries/{id}/redate-preview` | POST | `RedatePreviewController` | Aperçu des effets d'un re-datage sur une mère DÉCOUPÉE (D3 v2) — lecture pure, `{effects, token}`. |
| `/api/calendar-entries/{id}/conflicts` | GET | `CalendarEntryConflictsController` | Conflits d'un overlay période vs le planning socle (créneaux impactés). Sert aussi `closures` (fermetures datées : gymnase, titre, bornes, jours fermés) et `fullyClosedVenueIds` — les gymnases ENTIÈREMENT fermés sur la fenêtre (niveau GYMNASE et non par fermeture : deux fermetures qui se relaient ne s'exprimeraient pas par un drapeau par fermeture). Pour une entrée qui PORTE un plan, ces trois sorties sont TRANSVERSALES (`PlanVenueClosures::forEntry` — toutes les fermetures du club+saison, chacune bornée à sa propre entrée porteuse ∩ la fenêtre de l'entrée), pas seulement les datées de cette entrée ; une entrée SANS plan (jamais adaptée) garde le périmètre par-entrée historique. **Indispo informative** : pour une entrée qui porte un PLAN DE PÉRIODE, la route gagne deux clés — `disabledVenueIds` (gymnases hors service, désactivés OU effectivement fermés-total) et `effectiveClosedWeekdays` (venueId → jour ISO → provenance `default-incident`\|`manual`) — toutes deux dérivées de l'état EFFECTIF (`PlanVenueClosures::effectiveStateForPlan`, incident × masque manuel) ; le front les lit telles quelles, il ne redérive jamais la composition. |

