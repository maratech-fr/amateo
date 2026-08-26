# Refonte du module matchs — cadrage P2-26 (+ programme palier B/C)

> **Statut** : **cadrage / besoin spécifié** (entretien gestionnaire BCCL, 2026-08-17). **Pas un plan** —
> pas de tâches chiffrées ni d'ordre de PR figé ; l'exécution se planifiera lot par lot dans une session
> dédiée (§9).
> **Nature** : coordonne **deux chantiers qui partagent le même écran** — la **refonte UX** (roadmap **P2-26**,
> « l'amener au niveau du wizard ») **et** les **extensions palier B/C** déjà spécifiées dans
> [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md). La roadmap l'exige : *« ne pas refondre ce qui va être
> étendu sans coordonner les deux »* (P2-26).
> **Ce document ne re-spécifie PAS le palier B/C** (dérogation, matrice trajet, annuaire adverse) : il le
> **référence** et se concentre sur (a) la refonte UX et (b) les **besoins net-neufs** sortis de l'entretien
> du 2026-08-17 (rotation A/B, « gardien » à l'ouverture, réconciliation FBI).
> **Le palier A est LIVRÉ** — l'état courant du module vit dans
> [`../courantes/module-matchs.md`](../courantes/module-matchs.md) ; le présent fichier ne décrit que
> l'**ouvert**.
> **RMM-0, RMM-1, RMM-2, RMM-3, RMM-4, RMM-5 et RMM-6 sont LIVRÉS EN ENTIER (2026-08-21 → 2026-08-25)** —
> la refonte UX complète (§6quater L1-L9) est graduée dans
> [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « Refonte UX — RMM-1 », le
> « gardien » à l'ouverture (2 PR, backend puis front) dans § « Le gardien à l'ouverture », la
> réconciliation FBI (3 PR : backend, front, canal API — 2026-08-24) dans § « Réconciliation FBI »,
> canal API compris, la **rotation A/B** (4 PR : modèle, SOFT placement, repos dérivé, SET-UP —
> 2026-08-25, clôt **P2-49**) dans § « Rotation A/B — RMM-5 », et les **échéances ligue/comité**
> (3 PR : champ+outlook backend, éditeur SET-UP front, carte cockpit + escalade login front —
> 2026-08-25, clôt **P2-50**) dans § « Échéances ligue/comité — RMM-6 ». **RMM-7 est FERMÉ SANS CODE**
> (fondateur, 2026-08-25 — la dérogation n'est pas un objet de l'app ; décision au registre
> [`etat-des-lieux.md`](../courantes/etat-des-lieux.md) §2) et **RMM-10 est LIVRÉ (2 PR, 2026-08-26,
> clôt P2-52)** — un match déclaré ne perd plus sa salle en silence, gradué dans
> [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « P2-52 — un match déclaré ne
> perd plus sa salle en silence ». **Ce fichier ne détaille plus AUCUN item ouvert** : ne restent
> que RMM-8/RMM-9 (palier B/C, vision), dont le besoin vit dans
> [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) et que ce fichier se contente de RENVOYER
> (§11) — jamais de re-spécifier. Conservé pour son historique de décision et de livraison (§6-9),
> plus référencé comme « fichier de détail actif » de la roadmap.
> **Passe de conception du 2026-08-22 (fondateur)** : l'enchaînement ACTUEL est relevé clic par clic
> (§6ter) et les **lignes de conception à modifier** sont posées (§6quater, L1-L8) — dont deux faits
> neufs : **aucun geste ne pose `SUBMITTED`** (la boucle ne se boucle pas, L4) et **R1 est tombée**
> (échelle de modale livrée par P4-107, RMM-0 recalibré L8).
> **Revue lot-par-lot du 2026-08-17 (2ᵉ passe, fondateur)** : FBI acquiert le statut de **source de données
> de plein droit** (§4 fait 1, §5) · le **cas fondateur de la rotation A/B** est capturé (SM1/SM2 20h30 sous
> dérogation, §8) · un **registre de défauts de lisibilité** entre au cadrage (§6bis) et fonde le nouveau
> lot **RMM-0**.

---

## 1. Le problème en une phrase — et la MISSION (précisée fondateur, 2026-08-22)

**La vérité ABSOLUE, c'est FBI** : c'est là que les matchs se saisissent, c'est ce que la ligue
voit. Le module n'est PAS un planificateur rival — il est **le guide qui amène la vision du
gestionnaire jusque dans FBI sans rien oublier, dans les délais de réponse, ou vers une dérogation
quand la vision ne passe pas**. Un écart app⇄FBI n'est jamais un conflit d'autorité : c'est une
ALERTE (« ta saisie FBI ne dit pas ce que tu voulais »). Cible humaine nommée : **un gestionnaire
de 50 ans doit s'en sortir facilement**.

« Façon wizard » se lit dans CE cadre — trois qualités à transposer, PAS un tunnel d'étapes
obligées : (a) **il guide** (on sait toujours où on en est et quel est le prochain geste),
(b) **un geste à la fois** (l'écran ne montre que ce qui sert maintenant), (c) **les allers-retours
sont faciles** (circuler librement sans rien perdre). La liberté de circulation fait partie de la
simplicité.

### 1bis. Le problème d'origine

Le module matchs est **fonctionnellement complet** (palier A soldé) mais son écran unique est **surchargé et
non intuitif** : 8 boutons d'action à plat, 4 blocs empilés dans une colonne, 5 modales de même poids, aucune
notion d'étape ni de progression. **L'étalon nommé par le fondateur est le wizard** (structure guidée,
hiérarchie visuelle, états vides, un geste à la fois). C'est la plus-value la plus importante après le
planning.

---

## 2. Ce qui existe déjà (ne PAS re-construire — pointeurs)

> Détail canonique : [`../courantes/module-matchs.md`](../courantes/module-matchs.md). Résumé de réemploi :

- **Modèle** : `Fixture` (match daté : `homeAway`, `externalRef` = n° FBI, `status`
  UNPLACED→PLACED→SUBMITTED→VALIDATED, `placementSource`), `Competition` (poule/phase FFBB, `expectedMatchdays`,
  `ffbbPouleOpponents`), `LeagueMatchWindow` (fenêtres fédé/comité, **globale hors tenant**),
  `VenueMatchWindow` (accès mairie), `TeamMatchHabit` (habitude de match), `TeamLink` (passerelles).
- **Solveur de placement** : engine `POST /place-matches` + rail backend `PlaceMatchesController` →
  `MatchPlacementPayloadBuilder` (3 couches : kinds FIXED/TO_PLACE/AWAY, projection des entraînements
  effectifs, enveloppe ligue) → `MatchPlacementResultApplier`. Le « respect de l'image idéale » **existe déjà**
  comme mécanique.
- **Import FBI** : `FbiFixtureImporter` — analyse dry-run → mapping division→équipe → import idempotent
  (`POST /api/fixtures/import/analyze` + `/import`), garde-fou poule.
- **Radar de conflits** : `MatchConflictDetector` + `GET /api/fixtures/conflicts` — ~15 types gradués en
  sévérité 1→7 (VENUE_OVERLAP, LEAGUE_WINDOW_VIOLATION, MATCH_MATCH, MATCH_TRAINING, ACCESS_WINDOW_LOST,
  TEAM_LINK_OVERLAP, COMPETITION_INCOMPLETE…), recalculé à la volée, **rien persisté**.
- **Front** : `frontend/src/features/matches/` — `MatchesPage`, `WeekendGrid`, `TypicalWeekendGrid`,
  `PlacementPanel`, `ConflictRadar`, dialogues `ImportFbiDialog`, `FfbbEngagementsDialog`, `HabitsLinksDialog`,
  `MatchWindowsEditor`. 36 hooks react-query. **Le tabs du design system existe mais n'est pas exploité** ; le
  **stepper du wizard n'est pas extrait** (codé en dur dans `WizardLayout`).

**Périmètre engagé** (axe structurant §7.1) : une équipe portant ≥1 `Fixture` ne peut être ni supprimée ni
changer de niveau (`TeamEngagementGuard`, gardé par `EngagedTeamGuardTest`). Toute refonte doit le préserver.

---

## 3. L'entretien gestionnaire (BCCL, 2026-08-17) — son geste réel

Deux temps que l'écran actuel **écrase sur une seule page** :

### 3.1 Le SET-UP (rare, début de saison)
- Il dessine son **planning idéal sur 2 semaines (A et B)** : gymnases de match, créneaux de gymnase, **et
  donc les équipes dans les créneaux à domicile**. Cette image n'a « aucune valeur en soi » — c'est le
  **modèle de référence que le solveur devra respecter au maximum**.
- Quelques **contraintes de lieu / horaire** (règles région et comité) — celles déjà fournies dans le backend
  (→ `LeagueMatchWindow`, seed AURA).
- « Et ça clôture déjà le set-up de base. »

### 3.2 Le GESTE RÉCURRENT (chaque semaine)
1. Il va sur **FBI**, filtre **semaine par semaine** pour ne pas se tromper.
2. Il essaie de **coller au modèle** (son image idéale A/B), puis **regarde les litiges**.
3. Contrainte structurelle : il n'a la main **que sur ses matchs à domicile** ; les autres équipes reçoivent
   leur batch de données **plus tard**. **À la réception, il ne sait pas si les données sont à jour** — lui
   seul le sait.
4. Une fois les matchs domicile remplis, il doit les **re-saisir à la main dans FBI** (la partie pénible) puis
   sauvegarder.
5. Il veut être **alerté** si : (a) les données d'un match domicile **diffèrent entre FBI et l'app** (preuve
   d'une mauvaise saisie côté FBI, facile à identifier), (b) il y a des **conflits de créneaux**.
6. Il place parfois des matchs **avant** que les adversaires n'aient placé les leurs, puis découvre un conflit
   **APRÈS** (leur demande ne colle plus). → **il faut l'avertir**.
   **Idée du gestionnaire** : à chaque connexion, vérifier s'il n'a pas fait d'erreur de placement ou un
   conflit avec le modèle actuel vis-à-vis des matchs extérieurs.
7. Le **numéro de rencontre** l'aide à identifier vite un match.
8. Il veut un **rappel des échéances** de remplissage (données par la ligue et le comité) dans le cockpit.

---

### 3.3 Le rythme RÉEL — trois phases (précision fondateur, 2026-08-22)

Le « geste récurrent » du §3.2 n'est pas une boucle hebdomadaire uniforme. Le fondateur le précise :

1. **SET-UP** — la semaine idéale A/B, avec les contraintes et les gymnases disponibles (§3.1).
2. **PLACEMENT par RAFALES** — le batch FBI d'une **phase de poule** arrive (plusieurs semaines de
   matchs d'un coup) ; il est placé « un peu chaque jour », semaine par semaine, jusqu'à épuisement.
3. **AJUSTEMENT continu** — entre deux rafales, rien de spécial **sauf** l'ajustement subi : gymnase
   indispo, dérogation qui oblige à bouger — jusqu'à la rafale de la phase suivante.

Conséquence de conception : la **semaine** reste le grain de travail PENDANT une rafale (le filtre
FBI, L7), mais le rail d'étapes (L3) ne doit pas se présenter comme une boucle hebdo toujours
active — une semaine entre deux rafales est en état « veille / ajustement », et comme L3 dérive
tout des données, cet état tombe naturellement (tout est SUBMITTED → rien à faire).

---

## 4. Le challenge — quatre faits durs qui recadrent le besoin

1. **« Import FBI par API » — impossible aujourd'hui, mais FBI est une SOURCE de plein droit.** L'API FFBB
   expose un index `rencontres` **vide pour les vrais clubs** (documents de test uniquement au niveau
   national ; 0 hit pour un club réel) — mesuré deux fois, cf.
   [`api-ffbb-app-reconnaissance.md`](../../docs/archive/api-ffbb-app-reconnaissance.md) et `backend/docs/ffbb-api.md`.
   **Le seul CANAL d'entrée des rencontres reste l'export Excel FBI manuel** (déjà implémenté) ; on ne peut
   PAS supprimer le geste « télécharger le xlsx » ni la re-saisie manuelle dans FBI — limites **fédérales**,
   pas de notre code. → Ne rien promettre d'automatique côté FBI. **Mais (décision fondateur 2026-08-17) le
   STATUT du fichier est celui d'une source de données au même titre que l'API FFBB** : deux sources
   officielles, un seul modèle — **API FFBB = le périmètre** (équipes, poules, adversaires), **FBI = les
   rencontres** (dates, heures, salles, n°). Chaque dépôt de xlsx est une **ingestion datée** qui alimente
   le diff, le radar et la fraîcheur (§7) — pas une corvée annexe.
   ⚑ **Hiérarchie précisée (fondateur, 2026-08-22) : l'API est un MOYEN, l'import FBI fait FOI.**
   Les deux sources sont CENSÉES porter le même niveau d'information — l'une nourrit l'autre — mais
   cette équivalence est une **présomption à ~90 %, pas une certitude** (fondateur). C'est ce doute
   qui fonde la règle de litige, pas une infériorité de l'API : l'API est un confort — « magique »
   quand elle marche : pré-remplissage, périmètre, zéro saisie — tandis que **FBI est le vrai outil
   du gestionnaire**, là où l'acte officiel se fait. Donc en cas de désaccord entre une donnée venue
   de l'API et une donnée venue d'un dépôt FBI, **le dépôt fait foi** — et un rafraîchissement API
   n'écrase JAMAIS en silence une donnée née d'un dépôt. Corollaire utile : un désaccord API⇄dépôt
   n'est pas du bruit à avaler, c'est un **signal à MONTRER** (il infirme la présomption des 90 % —
   le gestionnaire veut le savoir). La partition « API = périmètre, FBI = rencontres » reste la
   règle de répartition nominale ; la hiérarchie tranche les chevauchements.

2. **« Le numéro de rencontre est unique » — faux au niveau global.** Le « 26 » existe en RMU18 Brassage
   *et* en DF2 (fact mesuré F6). Il est modélisé (`Fixture.externalRef`) mais l'unicité est **composite**
   `(club, saison, équipe, externalRef)` (`Fixture.php:30`). On peut l'**afficher** comme repère, **jamais**
   s'en servir comme clé d'identification globale — il faut toujours résoudre la division/équipe d'abord.

3. **La rotation « A/B » n'est PAS modélisée.** Il existe une vue « week-end type » (`TypicalWeekendGrid`) et
   des `TeamMatchHabit` **à une seule** occurrence par (équipe, jour) (`TeamMatchHabit.php`). L'alternance
   semaine A / semaine B (ex. partage de gymnase une semaine sur deux) est un **besoin de modèle net-neuf**,
   pas de l'UX. **Décision de cette session : c'est une vraie rotation à honorer** (§8).

4. **Trois souhaits ne sont PAS construits — et deux sont déjà fléchés palier B :**
   - **Vérification à la connexion** (alerte au login) : **inexistante**. Le radar se déclenche à l'ouverture
     de `/matchs`, pas au login. → net-neuf (§7 « gardien »).
   - **Réconciliation « données domicile FBI ≠ app »** : **partielle** seulement (garde-fou poule +
     COMPETITION_INCOMPLETE + warnings de ré-import). Pas de comparaison ciblée « ta saisie FBI diffère de ce
     que tu as enregistré ». → net-neuf, borné par le fait #1 (§7).
   - **Échéances ligue/comité** (deadline/rappel cockpit) : **inexistantes** pour les matchs (le cron
     deadlines existe mais seulement pour les campagnes coach et les transitions de saison). → **palier B**
     (`gestion-matchs-ffbb.md` §8).
   - Le **workflow de dérogation/litige** reste **palier B** (`gestion-matchs-ffbb.md` §8) — non ré-ouvert ici.

---

## 5. Décisions de cadrage de cette session (2026-08-17)

- **Périmètre = large** : refonte UX (P2-26) **coordonnée avec** le programme palier B/C, **plus** les
  besoins net-neufs de l'entretien. Le tout se **livrera par lots** (§9), pas en une PR.
- **Rotation A/B = vraie capacité de modèle à honorer** par le solveur (§8) — sort de la refonte UX pure.
- **FBI = source de données de plein droit** (fondateur 2026-08-17), **et depuis le 2026-08-22 la
  source qui FAIT FOI en litige** : API et FBI sont censés porter la même information (l'un nourrit
  l'autre) mais l'équivalence est présumée à ~90 %, pas certaine — d'où la règle : au désaccord, le
  dépôt FBI gagne, jamais d'écrasement silencieux par l'API, et le désaccord se MONTRE (hiérarchie
  et corollaire, §4 fait #1). Le canal est un xlsx manuel, chaque
  dépôt est une **ingestion datée** qui alimente le modèle, le diff et le gardien. La partition
  nominale demeure : **API FFBB = périmètre, FBI = rencontres**. La réconciliation se fait par
  **diff au ré-import** — l'outil sécurise le geste FBI, il ne le remplace pas.
- **Le radar existant devient le fil conducteur** du geste récurrent (« 3 litiges cette semaine → règle-les »),
  pas un pavé de plus dans une colonne pleine.
- **Décisions de la revue lot-par-lot (fondateur 2026-08-17, 2ᵉ passe)** :
  **RMM-0 prioritaire** (les 5 bloquants de lisibilité §6bis se corrigent en une PR courte AVANT la
  refonte) · **écart FBI sur un domicile placé = CHOIX PAR ÉCART** (ni écrasement silencieux ni règle
  figée : l'UI présente chaque écart, le gestionnaire tranche — garder l'app et corriger FBI, ou prendre le
  fichier ; trace conservée) · **image A/B = idéal SOFT sur créneau partagé** (§8) · **séquencement validé**
  (§9).
- **On réutilise** : le stepper/rail du wizard (à extraire dans `shared/`), le composant `tabs`, la grille
  temporelle, `MatchConflictDetector`, tout le rail `/place-matches`. **Aucune ré-écriture du moteur.**

---

## 6. La cible UX (l'étalon wizard) — principes

Ce que le wizard a et que le module matchs n'a pas, à transposer :

1. **Séparer les deux temps.** Un espace **SET-UP** (rare, guidé comme le wizard : gymnases de match →
   créneaux → image idéale A/B → contraintes ligue/comité) distinct de l'espace **GESTE RÉCURRENT**
   (hebdomadaire). Aujourd'hui tout est mélangé sur `MatchesPage`.
2. **Progressive disclosure / un geste à la fois.** N'afficher que ce qui sert l'étape courante. Sortir les
   actions rares (import FBI saisonnier, engagements FFBB, habitudes, accès match) de la barre plate ; les
   ranger dans le SET-UP ou derrière une entrée « Configuration ».
3. **Progression visible & « prochain trou ».** Le geste récurrent est une **boucle semaine par semaine** :
   *importer le batch → coller au modèle A/B → résoudre les litiges (radar) → poser les domiciles → marquer
   « saisi dans FBI »*. Un fil qui dit **où on en est** et **ce qu'il reste**, façon rail d'étapes.
4. **Hiérarchie des actions.** L'action centrale (placer / résoudre un litige) domine visuellement ; le reste
   est secondaire. Fin des 8 boutons équipollents.
5. **Contexte stable.** Supprimer les sauts de colonne (`PlacementPanel` qui apparaît/disparaît) et le « mode
   échange » caché qui change silencieusement le sens des clics.
6. **États vides soignés** (comme le wizard) : « aucun match cette semaine », « aucun litige — tout est
   propre », « importe ton premier fichier FBI ».
7. **Filtrer par semaine** (le geste réel du gestionnaire sur FBI), pas seulement par week-end ; afficher le
   **numéro de rencontre** comme repère (fait #2 : jamais comme clé globale).

> ⚠ **Refonte UX = ZÉRO changement de comportement moteur.** Réorganiser l'écran ne doit pas toucher le
> placement, le radar ni les statuts. C'est ce qui rend la 1re tranche (UX pure) peu risquée.

---

## 6bis. Registre des défauts de lisibilité (audit code, 2026-08-17)

> **Déclencheur** : le fondateur signale que la modale d'appariement tronque les libellés (« DF2 ou DF3 ?
> je clique à l'aveugle »). Audit systématique de cette classe de défauts sur tout `features/matches/` :
> **18 défauts — 5 bloquant-décision · 9 gênants · 4 cosmétiques.** Deux causes racines :
> **R1** — la modale partagée n'a qu'UNE taille (`modal.tsx:51`, `max-w-md` = 448 px, aucun variant ;
> aucun des 5 dialogues du module ne l'élargit) ; **R2** — la colonne gauche de la page est figée à
> 320 px (`MatchesPage.tsx:240`). **Les deux causes racines sont désormais résolues** : R1 par
> l'échelle `size` de la modale (P4-107, note L8) ; **R2 par RMM-1 PR3** — la colonne est passée à
> `lg:grid-cols-[minmax(18rem,22rem)_1fr]` (`MatchesPage.tsx:328`), plus une largeur figée.

Les 5 **bloquant-décision** (un libellé nécessaire à une décision, tronqué sans `title` de secours) :

| ID | Où | Ce qui devient illisible |
|---|---|---|
| B1 | `FfbbEngagementsDialog.tsx:62` | le nom de compétition (~194 px utiles) — le chiffre discriminant (« …Division 2 » vs « …Division 3 ») est en **queue de chaîne**, précisément la partie mangée. **Le cas signalé.** |
| B2 | `FfbbEngagementsDialog.tsx:63-68` | poule · taille · catégorie · niveau · genre — ce qui désambiguïse deux engagements |
| B3 | `ImportFbiDialog.tsx:110-116` | le nom de division FBI du mapping division→équipe + le `fbiTeamLabel` (qui n'existe QUE quand il est indispensable) — mapper à l'aveugle crée des matchs sur la **mauvaise équipe** |
| B4 | `ImportFbiDialog.tsx:123` + `FfbbEngagementsDialog.tsx:72` | la valeur sélectionnée des `TeamSelect` (160-176 px) — vérifier un appariement pré-rempli exige d'ouvrir chaque select un à un |
| B5 | `AwayList.tsx:50-58` | l'heure et le badge « heure estimée » des matchs extérieur — l'info qui porte les conflits de coach — en queue de troncature |

Ironie mesurée : les libellés complets existent dans les `aria-label` — les lecteurs d'écran voient ce que
l'œil ne voit pas. Les 9 gênants (rangées plus larges que la modale dans `HabitsLinksDialog`, selects
d'équipe à 128 px, sélecteur de gymnase à ~90 px dans `PlacementPanel`, en-tête de `TypicalWeekendGrid`
sans `title` alors que sa jumelle datée en a un, barre d'actions sans `flex-wrap`…) et les 4 cosmétiques
sont détaillés dans l'audit du 2026-08-17 — à re-dérouler à l'implémentation de RMM-0/RMM-1.

**Conséquences de cadrage :**
- **RMM-0 (nouveau lot)** : corriger les 5 bloquants **sans attendre la refonte** (variant de taille de
  `Modal`, `title` systématique sur libellé tronqué, selects lisibles).
- **Critère d'acceptation de RMM-1** : *aucun libellé porteur de décision tronqué sans secours*, et R1/R2
  traités à la racine (variants de taille, largeur de colonne).
- Outillage à mobiliser pour que la classe ne revienne pas : passe de design `ui-ux-pro-max`
  (`.claude/rules/frontend.md`) + patron e2e **témoin** (`tests/e2e/modal-reachability.spec.ts`).

---

## 6ter. L'enchaînement ACTUEL — le geste tel qu'il est codé (vérifié 2026-08-22)

> Relevé clic par clic AVANT de dessiner la refonte : chaque constat est ancré `fichier:ligne`.
> C'est la base factuelle des lignes de conception (§6quater) — une session future code sans re-tracer.

1. **Arrivée `/matchs`** : une seule page, barre plate de 6 actions + feedback
   (`MatchesPage.tsx:188-238`) — Placer automatiquement · Habitudes & passerelles · Accès match ·
   Engagements FFBB · Importer FBI · Nouveau match. Aucune hiérarchie, aucun temps séparé.
2. **Import du batch** : « Importer FBI » → dialogue à 3 états internes (fichier → analyse dry-run
   avec table de mapping division→équipe préremplie depuis les mappings persistés → rapport)
   (`ImportFbiDialog.tsx:22-34`). Les matchs naissent `UNPLACED` (`FbiFixtureImporter.php:516`).
3. **Placement** : colonne gauche « À placer » (`UnplacedList`) → un clic sélectionne → le
   `PlacementPanel` **APPARAÎT** dans la colonne, seulement si HOME + enveloppe résolue
   (`MatchesPage.tsx:252`) — c'est le saut de contexte nommé au §6.5. Gymnase (select ~90 px, B-gênant
   §6bis) + heure → « Placer » (PUT full-replace, status→PLACED, `api.ts:466-480`). Ou « Placer
   automatiquement » (rail solveur synchrone) : toast « N placés · M non plaçables », les **raisons de
   non-placement vivent dans une Map React locale** (`MatchesPage.tsx:69`) — un refresh les perd.
4. **Ajustement** : le panel offre Retirer · Ancrer · Échanger · Modifier · Supprimer
   (`PlacementPanel.tsx:170-205`). « Échanger » arme un mode où **le prochain clic grille change de
   sens** (choix du partenaire, `MatchesPage.tsx:140-153`) — signalé par un bandeau (`:325-330`),
   invisible au niveau des cellules : le « mode caché » du §6.5.
5. **Litiges** : radar en colonne gauche, un clic sur un conflit sélectionne le match fautif
   (`ConflictRadar.tsx:133-150`). Recalcul à la volée, rien persisté.
6. **Navigation** : par WEEK-END seulement (précédent/suivant + toggle « semaine type »
   `typicalView`) — ni filtre semaine, ni journée FFBB. Le **n° de rencontre (`externalRef`) n'est
   affiché nulle part** (grep sur `features/matches/`, zéro rendu — il n'existe que comme donnée).
7. **Clôture : IMPOSSIBLE aujourd'hui.** Aucun geste UI ne pose `SUBMITTED` (« saisi dans FBI »).
   L'enum et ses effets existent — panel read-only (`PlacementPanel.tsx:109`), protection DOC-2
   (`DeletionImpact.php:31`) — et **l'API accepte l'écriture du statut**
   (`FixtureStateProcessor.php:74-76`) ; mais aucun bouton ne l'envoie. L'étape 5 de la boucle §6.3
   (« marquer saisi dans FBI ») n'a **pas de geste** : la boucle du gestionnaire ne se boucle pas.

Le SET-UP (§3.1) n'a pas d'espace : Engagements FFBB, Accès match, Habitudes vivent dans la barre
d'actions hebdomadaire, et l'image idéale (TypicalWeekendGrid) se cache derrière un toggle.

---

## 6quater. Les lignes de conception à modifier (livrable, 2026-08-22)

> **Le contrat de RMM-1 reste « zéro comportement moteur »** — chaque ligne ci-dessous est de la
> structure d'écran ou un geste sur une API EXISTANTE. Une ligne = une décision, son ancre, sa cible.
> **Table CLOSE depuis PR4 (2026-08-24) : les 9 lignes sont livrées** (L6 et L9 les deux dernières) —
> le comportement gradue dans [`../courantes/module-matchs.md`](../courantes/module-matchs.md)
> § « Refonte UX — RMM-1 ». Elle reste ici pour son historique de décision (l'ancre AVANT/APRÈS),
> git en gardant le fil.

| # | Ligne de conception | Aujourd'hui (ancre) | Cible |
|---|---|---|---|
| **L1** | **Deux espaces, deux routes** (RMM-1 PR2 — LIVRÉ 2026-08-23, non annoté à l'époque, corrigé ici). | ~~Tout sur `MatchesPage` (§6ter-1)~~ | `/matchs` (la boucle) et `/matchs/configuration` sont deux vraies routes sous `MatchesLayout.tsx` (nav en onglets, garde socle commun) ; `ConfigurationPage.tsx` héberge Engagements FFBB · Accès match · Habitudes & passerelles · **l'image A/B en écran de plein droit** (`TypicalWeekendGrid`, toggle `typicalView` disparu). Import FBI accessible des DEUX côtés (même dialogue, deux entrées). |
| **L2** | **Le rail d'étapes s'extrait** (RMM-2 — LIVRÉ 2026-08-23). | ~~Rail codé en dur dans `WizardLayout.tsx:453-496`~~ (l'ancre `:152-155` d'une édition antérieure était périmée) sur `WIZARD_STEPS` | Primitive `shared/components/ui/step-rail.tsx` (présentation pure : états passés calculés) ; le wizard la consomme À RENDU IDENTIQUE (les 4 suites du rail restent vertes SANS modification). La boucle matchs la consommera avec ses 5 étapes dérivées — **aucune consommation côté matchs dans RMM-2** (elle vit dans RMM-1). |
| **L3** | **Les 5 étapes de la boucle sont DÉRIVÉES, jamais stockées** (RMM-1 PR3 — LIVRÉ 2026-08-24). | ~~Aucune notion d'étape~~ | `features/matches/lib/loopSteps.ts` (`deriveLoopSteps`, pur) : batch importé (des matchs existent) → placés au modèle (0 domicile `UNPLACED` avec habitude) → litiges (compte radar rattaché à la semaine) → domiciles posés (0 HOME `UNPLACED`) → saisi FBI (`SUBMITTED`/`n de HOME`). Store `railStep` (`null` = premier trou, reset au changement de semaine) — zéro état de progression stocké, zéro backend. Consomme `StepRail` (RMM-2). |
| **L4** | **Le geste manquant : « Marquer saisi dans FBI »** (RMM-1 PR1 — LIVRÉ, non annoté à l'époque, corrigé ici). | ~~Aucune UI ne pose `SUBMITTED` (§6ter-7)~~ | `PlacementPanel.tsx` porte `onSubmit`/`onReopen` sur un match PLACED/SUBMITTED (PUT `status`, `FixtureStateProcessor.php:74`) — réversible : un SUBMITTED redevient PLACED par le même panel. Vocabulaire FR (`fixtureStatusLabel.ts`) : Importé · Placé · **Saisi dans FBI** · Validé ligue — jamais les codes. |
| **L5** | **Hiérarchie d'actions par étape** (RMM-1 PR3 — LIVRÉ 2026-08-24). | ~~6 boutons équipollents (§6ter-1)~~ | L'action primaire est celle de l'étape courante (Importer FBI / Placer automatiquement / rien en litiges-fbiEntry) ; les gestes rares (Engagements FFBB, Accès match, Habitudes & passerelles, image A/B) ont quitté la barre pour `/matchs/configuration` (L1) ; « Nouveau match » reste secondaire (`variant="outline"`) dans la barre utilitaire. |
| **L6** | **Contexte stable** (RMM-1 PR4 — LIVRÉ 2026-08-24). | ~~Panel conditionnel (`MatchesPage.tsx:252`) ; mode échange invisible aux cellules (`:140-153`) ; raisons de non-placement volatiles (`:69`)~~ | Slot de panel PERMANENT avec état vide (« Sélectionnez un match ») — fin du saut de colonne ; le mode échange se voit SUR la grille (candidates en anneau `data-swap-candidate`, cellules non-candidates estompées, Échap pour sortir — `WeekendGrid.tsx`) ; les raisons de non-placement (store `unplacedReasons`) restent affichées tant que la semaine est à l'écran et sont purgées au changement de semaine. |
| **L7** | **La semaine devient l'axe primaire** (RMM-1 PR3 — LIVRÉ 2026-08-24) — CONFIRMÉ 2026-08-22 (la préconisation « vue équipe/semaine, miroir de l'écran FBI » tranche de fait). | ~~Week-end seulement, `externalRef` jamais rendu (§6ter-6)~~ | Label « Semaine du {lundi} au {dimanche} » (`weekLabel`, `lib/weekendGrid.ts`) au-dessus du rail ; le bucket reste le week-end (Lun→Dim), seules les bornes affichées changent. Le **n° de rencontre (`externalRef`) s'affiche** dans la grille (`WeekendGrid.tsx`) et les listes (`AwayList.tsx`, `UnplacedList.tsx`, `FbiEntryList.tsx`) — repère discret, jamais une clé. |
| **L8** | **RMM-0 recalibré — R1 est TOMBÉE.** | Le registre §6bis supposait la modale mono-taille ; depuis P4-107 (#675, 2026-08-21) `modal.tsx:30-34` porte l'échelle `size` sm/md/lg, gardée par `modal-size.test.tsx` | Le lot devient une pure CONSOMMATION : `size="lg"` sur les dialogues du module + `title` de secours sur B1-B5 + selects élargis. **Livré par RMM-0 (#722, B1-B5 sans R2)** ; **R2 (colonne `20rem`) a atterri plus tard, dans RMM-1 PR3** (§6bis ci-dessus), pas dans RMM-0. |
| **L9** | **La VUE DE SAISIE FBI** — la matérialisation de « sans qu'il oublie qqch » (mission §1) (RMM-1 PR4 — LIVRÉ 2026-08-24 ; forme minimale livrée en RMM-1 PR3). | ~~La liste « À placer » s'arrête à PLACED ; rien ne présente ce qui RESTE À SAISIR dans FBI~~ | La **liste à recopier**, organisée **par équipe** (`FbiEntryList.tsx`), filtrable équipe · date. Champs : **date + heure** (ce que FBI demande pour un domicile) + n° de rencontre et salle en repères. Cocher = le geste L4 ; **« tout marquer saisi » ne coche QUE les lignes affichées à l'écran** (le filtre borne le lot) sous confirmation ; la checklist se vide, une ligne saisie garde « Corriger ». Zéro backend : une PROJECTION des fixtures existants. **L'échéance de saisie est livrée** (RMM-6 PR-2, 2026-08-25) — chaque ligne porte son échéance effective à côté du n° de rencontre, dépassée en avertissement JAMAIS bloquant. **Reste ouvert, hors RMM-1** : le bonus officiel/arbitre — ⚠ suspendu à la vérification des colonnes du xlsx (§10). |

**Ordre de code inchangé** (séquencement validé §9) : RMM-0 (L8) → RMM-2 (L2) → RMM-1 (L1+L3+L4+L5+L6+L7+**L9**).
L4 est DANS RMM-1 : c'est un geste UI sur une API existante, pas un lot backend. L9 en est le
débouché naturel — la même donnée, présentée pour la transcription.

---

## 6quinquies. Changement de gymnase & dépendances au planning de saison (vérifié 2026-08-22)

Réponses aux deux questions fondateur de la passe de conception — chaque fait lu dans le code.

**Changement de gymnase (l'ajustement de la phase 3) — la chaîne existante et son trou :**
- **Détection** : `VenueUnavailability` (cockpit) + flux d'impact **alerte-seulement** listant les
  `affectedFixtures` avec leur statut (`frontend/src/features/matches/api.ts:331`) ; radar
  `ACCESS_WINDOW_LOST` / `VENUE_OVERLAP`, recalculés à la volée.
- **Réparation** : manuelle — panel, changer gymnase/heure (PUT), ou replacement auto.
- ⚠ **Un match `SUBMITTED` dont le gymnase meurt est COINCÉ** : panel read-only
  (`PlacementPanel.tsx:109`), aucun geste de sortie de `SUBMITTED` (§6ter-7). La réversibilité de
  **L4** n'est donc pas un confort : c'est **LE chemin de réparation** de la phase d'ajustement.
  Enchaînement RMM-4 : après réparation, l'app SAIT que FBI porte l'ancienne salle — elle peut
  marquer « diverge de FBI depuis votre changement » au lieu de s'en remettre à la mémoire du
  gestionnaire.

**Dépendances au planning de saison — à sens unique :**
- **Matchs ← entraînements : forte, câblée.** Le placement projette les entraînements EFFECTIFS
  date par date (`MatchPlacementPayloadBuilder:261-294`) via `EffectiveScheduleResolver` : une
  période active CAPTURE la date (overlay, y compris null = fermeture), sinon la version pointée
  du plan de saison. Le placement voit donc socle, périodes et fermetures.
- **Entraînements ← matchs : quasi nulle, par choix de modèle.** « Repos après jour de match » lisait
  `team.matchDay`, champ DÉCLARÉ (`TeamStateProcessor:98`), jamais dérivé des fixtures réels.
  ⚠ Précision fondateur 2026-08-22 : **le jour de repos se définit par rapport à la semaine idéale
  A/B** — voir l'impact modèle au §8. **LIVRÉ depuis (RMM-5 PR-3, 2026-08-25)** : le champ déclaré
  n'est plus ce qui est ÉMIS au solve hebdo — il reste un repli, la valeur DÉRIVÉE de l'image
  (habitudes ∪ rotations, jamais des `Fixture` réels placés) prime désormais.
- ⚠ **La couture qui n'existe pas** : rouvrir/régénérer le planning de saison ne marque PAS les
  matchs placés — `Fixture` est ABSENT du listener de péremption
  (`ResourceChangeStaleScheduleListener`, liste des entités écoutées vérifiée). Le placement a été
  calculé contre une projection qui n'existe plus ; le seul filet est le radar, qui ne parle que si
  on ouvre le module. **C'est un déclencheur à part entière du « gardien » (RMM-3)** : « le
  planning de saison a changé depuis ta dernière visite », pas seulement les matchs adverses.

---

## 7. Le « gardien » — le cœur de l'angoisse du gestionnaire

> **Les trois volets ci-dessous sont LIVRÉS** — le contrôle d'état à l'ouverture (RMM-3,
> 2026-08-24, gradué dans [`../courantes/module-matchs.md`](../courantes/module-matchs.md)
> § « Le gardien à l'ouverture ») ; la réconciliation FBI et la fraîcheur des données (RMM-4,
> 2026-08-24, gradué § « Réconciliation FBI »).

Le sous-jacent « il ne sait pas si les données reçues sont à jour, et il découvre les conflits **après coup** »
(§3.2 pts 3 & 6) pointe une **capacité net-neuve** : un **contrôle d'état à l'ouverture**.

- **À l'ouverture du module (ou à la connexion)** : recalculer le radar et afficher un résumé
  « depuis ta dernière visite : N nouveaux conflits sur des matchs que tu avais posés » — typiquement parce
  qu'un adversaire a bougé son placement (les matchs extérieurs estimés ont changé).
- **Réconciliation FBI (bornée par le fait #4.1)** : au **ré-import** du xlsx FBI, comparer les matchs
  **domicile** du fichier avec ceux enregistrés dans l'app et **signaler tout écart** (heure, salle, date)
  — preuve d'une mauvaise saisie côté FBI. Réutilise le **diff idempotent** de `FbiFixtureImporter`
  (aujourd'hui il met à jour silencieusement les re-programmations ; il faut en faire une **alerte lisible**).
- **Fraîcheur des données** : introduire une notion de « ce batch est-il à jour ? » (le gestionnaire est le
  seul à le savoir) — a minima horodater le dernier import et le rappeler.

> Ce volet est le **différenciateur ressenti**. Il s'appuie sur le radar existant (aucune nouvelle sémantique
> de conflit) + une couche de **persistance légère** (pour comparer « avant/après visite ») que le radar
> actuel n'a pas (il est stateless).

---

## 8. Rotation A/B — le point modèle à creuser (décision : à honorer)

> **LIVRÉ EN ENTIER (RMM-5, 4 PR, 2026-08-25)** — les quatre décisions fondateur ci-dessous sont
> honorées dans le code et graduées dans
> [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « Rotation A/B — RMM-5 » :
> le modèle N-aire du créneau partagé (PR-1, décision 2), le SOFT de placement jamais bloquant
> (PR-2, décision 1), le repos d'entraînement dérivé de l'image (PR-3, décision 3) et l'écran
> SET-UP + le signal, sans aucun ancrage calendaire déclaré (PR-4, décision 4). Ce qui suit reste
> le cadrage d'origine (pourquoi, le cas SM1/SM2) — les « pistes d'implémentation restantes »
> en fin de section sont **closes**, elles ne décrivent plus un travail ouvert.

**Le cas fondateur (précisé 2026-08-17)** : gros club, **pas assez de créneaux** — l'alternance naît de la
pénurie, pas d'un confort de visualisation. Exemple réel : SM1 joue à 20h30 ; une **dérogation obtenue
auprès de la ligue** fait que SM2 joue **aussi toujours à 20h30**, en décalage — semaine A SM1 reçoit,
semaine B SM2 reçoit, **sur le même créneau**. L'A/B est donc d'abord le **partage d'un créneau rare entre
deux équipes**. À noter : la règle durable est née d'une **dérogation acceptée** — lien direct avec le
tracker de dérogation (RMM-7).

**Deux décisions fondateur (2026-08-17, revue lot-par-lot) :**

1. **Statut : l'image A/B est un IDÉAL SOFT, jamais un HARD.** Verbatim : « c'est un cas parfait auquel on
   se réfère, mais la réalité c'est qu'on ne va jamais l'avoir — ça permet d'avoir une **tendance et une
   aide pour le solveur**, en plus des contraintes qu'on va indiquer. » Les contraintes déclarées (fenêtres
   ligue/comité, accès gymnase, indispos) restent les seules HARD ; l'image A/B **pèse dans l'objectif** et
   ne refuse jamais un placement — ni du solveur, ni manuel. Le radar peut signaler « hors image », il ne
   bloque pas.
**Troisième décision (fondateur, 2026-08-22) : le jour de REPOS se définit par rapport à la semaine
idéale A/B** — pas par un jour unique déclaré à part. Et la passe de tranchage du même jour l'a
SIMPLIFIÉ : dans l'image, **le jour de match À DOMICILE d'une équipe est stable** (U18M/U21M jouent
le dimanche — c'est le lieu/créneau qui alterne entre A et B, pas le jour) ; donc **UN jour de repos
dérivé par équipe suffit** (« à domicile c'est le dimanche → idéalement pas d'entraînement le
lundi. C'est tout »). La tension union/pondération TOMBE. Les matchs extérieurs (jour aléatoire posé
par la FFBB) ne pilotent pas la règle hebdomadaire. À terme, `team.matchDay` se DÉRIVE de l'image
(fin de la double déclaration), et la règle implicite de repos suit.
> **LIVRÉ (RMM-5 PR-3, 2026-08-25)** : `team.matchDay` reste un champ déclaré en base (repli
> legacy, zéro migration) mais n'est plus ÉMIS tel quel — le payload `/generate` porte la valeur
> DÉRIVÉE. Détail : [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « Le repos
> d'entraînement dérivé — RMM-5 PR-3 ».

**Quatrième décision (fondateur, 2026-08-22) : l'image A/B est FICTIVE — AUCUN ancrage calendaire à
déclarer.** La question « qu'est-ce qui ancre A/B sur le calendrier réel » (ex-§10) est dissoute :
on ne mappe pas les semaines réelles sur A/B a priori. La FFBB pose un jour (samedi/dimanche,
aléatoire) par équipe et par week-end ; **le placement fait coller chaque rencontre reçue au bon
jour du modèle quand c'est possible, sinon il respecte au maximum règles et contraintes, a
minima**. Le rattachement A/B d'un week-end est une CORRESPONDANCE constatée (qui reçoit sur le
créneau partagé), jamais une déclaration.

2. **Modélisation : propriété du CRÉNEAU de match partagé** — N équipes déclarées sur un créneau, qui
   alternent (SM1/SM2 sur le 20h30). Continuité naturelle avec la couche SOFT « habitudes » du solveur de
   placement (`TeamMatchHabit` est déjà un SOFT protégé) : l'A/B en est l'**extension à parité**.

Pistes d'implémentation, **toutes closes (RMM-5, 2026-08-25)** :

- **Ancrage calendaire** : ce qui fixe le rythme A/B sur le calendrier réel (numéro de semaine ISO ?
  point d'ancrage saison ? saisie manuelle). **Dissoute par la décision fondateur n°4** ci-dessus,
  livrée PR-4 : aucun ancrage — `TypicalWeekendGrid` reste date-less, le segmenté « Semaine A/B »
  ne fait que prévisualiser une position, jamais choisir une semaine calendaire réelle.
- **Solveur** : le payload `/place-matches` transporte l'alternance attendue par créneau → nouveau SOFT
  (« respecte l'image A/B ») **sans** casser les golden fixtures ni le déterminisme du worker unique. Axe
  **contrat backend↔engine** (§7.1) → NR obligatoire (contract test) + smoke-solver. **Livré PR-2**
  (bloc `slotRotations`, contrat 2.15, `CrossStack/SlotRotationPayloadParityTest`).
- **UI** : le SET-UP doit permettre de **dessiner** les deux semaines (réemploi de `TypicalWeekendGrid` en
  version « A / B »). **Livré PR-4** (`MatchSlotRotationsEditor` + `TypicalWeekendGrid` en semaines A/B).

> ⚠ **Axe structurant §7.1 touché** (contrainte sémantique : « une image A/B saisie doit être honorée par le
> solveur ») → NR sémantique livré avec PR-2 (`test_ab_rotation_image_is_honoured_across_two_weekends`,
> smoke `smoke-place-matches.sh` volet 3).

---

## 9. Programme de travail (lots cadrés — ordre indicatif, pas un plan)

IDs locaux `RMM-n` pour référence dans ce fichier. La colonne **Rattachement** dit à quelle ligne roadmap /
quel palier chaque lot appartient — **ne pas créer de doublon de vérité**.

| ID | Lot | Type | Axe §7.1 → NR | Rattachement |
|---|---|---|---|---|
| **RMM-0** | **LIVRÉ (#722, 2026-08-21).** Correctifs de lisibilité immédiats : les 5 bloquant-décision du registre (§6bis) — `title` sur tout libellé tronqué, selects lisibles, et consommer l'échelle `size` de la modale (P4-107). | Front | — | **P2-26** (correctif anticipé) |
| **RMM-1** | **LIVRÉ EN ENTIER (PR1→PR4, #732-#736, 2026-08-23/24).** Refonte UX pure : séparer SET-UP / geste récurrent, boucle semaine-par-semaine, hiérarchie d'actions, radar en fil conducteur, états vides, filtre par semaine, n° de rencontre affiché, contexte stable, vue de saisie FBI. Critère §6bis honoré : aucun libellé décisionnel tronqué, R1/R2 traités à la racine. **Zéro backend.** Comportement gradué : [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « Refonte UX — RMM-1 ». | Front | — (aucun comportement moteur touché) | **P2-26** (ce fichier = son détail) |
| **RMM-2** | **LIVRÉ (2026-08-23).** Extraire le **rail d'étapes du wizard** dans `shared/` (`step-rail.tsx`), à rendu identique. Prérequis technique de RMM-1. **Resserrement fondateur 2026-08-23** : « exploiter `tabs` » et « mutualiser la grille temporelle » sont REPORTÉS (on ne mutualise pas spéculativement une brique sans son second consommateur) — seule l'extraction du rail était livrée ici. | Front | — | P2-26 |
| **RMM-3** | **LIVRÉ EN ENTIER (2 PR, #737 backend + celle-ci front, 2026-08-24).** Gardien à l'ouverture du module (pas au login) : persistance légère par visite (`MatchModuleVisit`, par utilisateur), empreinte stable d'un conflit (`ConflictFingerprinter`), grâce glissante de 30 min, bandeau résumé (« depuis votre dernière visite : N matchs arrivés · N nouveaux conflits · le planning de saison a changé ») + chips « Nouveau » sur le radar. Comportement gradué : [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « Le gardien à l'ouverture ». | Back + Front | contrainte sémantique (conflits) → NR (`Security/MatchVisitDeltaParityTest`) | **P2-47** (SOLDÉE, quitte la roadmap) |
| **RMM-4** | **LIVRÉ EN ENTIER (3 PR, 2026-08-24).** FBI source de plein droit + réconciliation : chaque dépôt xlsx = **ingestion datée** (fraîcheur affichée) ; le diff présente **chaque écart domicile** (heure/salle/date) au gestionnaire qui tranche — garder l'app (et corriger FBI) ou prendre le fichier — au lieu de mettre à jour en silence (décision §5). **Forme tranchée 2026-08-22 : un écran « état app VS état fichier », choix par écart.** Réutilise le diff de `FbiFixtureImporter`. **PR-1 backend** : deviations à l'analyze, decisions à l'import (keep_app|take_file, jamais d'écrasement par défaut), `FbiIngestion` datée + trace, route de fraîcheur. **PR-2 front** : vue dédiée `/matchs/reconciliation` (**décision fondateur — renverse la modale envisagée en cadrage** : la passe design a jugé l'écran de choix impraticable en modale pour N cartes d'écart), `ReconciliationPanel`, carte + rappel de fraîcheur. **PR-3 le canal API FFBB** : `ReconciliationPanel` — construit délibérément AGNOSTIQUE du canal — est rebranché sur `GET/POST /api/ffbb/rencontres(/apply)` (`FfbbRencontreReconciler`, appariement 3 étages + idempotence, réutilise VERBATIM le moteur de décisions xlsx) ; les rencontres publiées absentes de l'app (les amicaux) sont proposées à la création, jamais imposées ; couverture jamais promise. Comportement gradué : `../courantes/module-matchs.md` § « Réconciliation FBI », canal API compris. | Back + Front | — | **P2-48** (SOLDÉE, quitte la roadmap) |
| **RMM-5** | **LIVRÉ EN ENTIER (4 PR, 2026-08-25).** **Rotation A/B** : modèle (alternance sur **créneau partagé** — cas SM1/SM2, §8) + payload/solveur (SOFT « respecte l'image A/B ») + UI SET-UP deux semaines + **le jour de repos d'entraînement suit l'image** (3ᵉ décision §8 — `matchDay` devient dérivé, la règle implicite côté entraînement aussi). **PR-1** : modèle N-aire `MatchSlotRotation`/`MatchSlotRotationTeam` + CRUD `/api/match_slot_rotations` seuls, rien ne le consomme encore. **PR-2** : bloc `slotRotations` branché au payload `/place-matches` (contrat **2.15**) et consommé en **SOFT** côté solveur — attraction (heure/gymnase, poids à parité stricte des habitudes) + protection de la fenêtre du créneau partagé ; suppléance backend (l'habitude même-jour d'un membre est retirée, jamais les autres jours). **PR-3** : le `matchDay` émis au `/generate` du solve hebdo (`ScheduleConstraintBuilder::deriveMatchDay`) devient `max(jours ISO des habitudes ∪ rotations dont l'équipe est membre)` — le repos suit l'image, 3ᵉ décision §8 honorée ; repli sur le champ déclaré `Team.matchDay` converti 0-based→ISO (correction du bug dormant D-11) ; habitudes/rotations/membres entrent dans `ResourceChangeStaleScheduleListener` (péremption COMPLETED). Zéro engine, contrat 2.15 inchangé. **PR-4 (LIVRÉE, 2026-08-25) : pur frontend.** L'éditeur « Créneaux partagés (alternance) » sur `/matchs/configuration` (déclaration ordonnée, flèches ↑/↓, badge A/B/C, phrase « l'ordre ne commande aucun calendrier ») ; `TypicalWeekendGrid` en semaines A/B (segmenté `Tabs`, invisible sans rotation — grille identique à avant) ; le signal « hors image » étendu (membre de rotation vs créneau, plus le compteur « même week-end »). Zéro backend/engine/contrat touché. Comportement gradué : [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « Rotation A/B — RMM-5 » (les 4 PR). | Model + Engine + Front | **contrat backend↔engine** + **contrainte sémantique** → NR (contract test + smoke-solver) | **P2-49** — SOLDÉE, quitte la roadmap |
| **RMM-6** | **LIVRÉ EN ENTIER (3 PR, 2026-08-25).** **Échéances ligue/comité** — TRANCHÉ 2026-08-22 : la ligue les envoie **par MAIL** au gestionnaire, donc **saisie manuelle**, et **PLUSIEURS échéances concurrentes** par groupes d'équipes (région le 2 sept, département le 10, autres le 15…) — la granularité est le groupe/la compétition, jamais une date unique de club. **PR-1 backend** : champ par compétition + endpoint bulk + défaut communautaire partagé surchargeable + outlook J-7 (`GET /api/matches/deadline-outlook`). **PR-2 front** : l'éditeur « Échéances de saisie » du SET-UP + l'échéance affichée sur `FbiEntryList` (L9). **PR-3 front** : la carte cockpit `FbiDeadlineCard` (pleine largeur sur `/`, rendue SEULEMENT sous fenêtre J-7 ouverte, dépassée = warning qui reste jamais destructif) + l'escalade « dès le login » (décision fondateur : le placement de match est une urgence) + le résumé du gardien fusionné dans la même carte + la lib `visitDeltaSegments` extraite et partagée avec `ModuleVisitBanner`. Comportement gradué : [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « Échéances ligue/comité — RMM-6 ». | Back + Front | — | **P2-50** — SOLDÉE, quitte la roadmap ; besoin : [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) §8 |
| **RMM-7** | **FERMÉ SANS CODE (fondateur, 2026-08-25)** — « la dérogation n'est pas un objet de l'app, c'est juste la réponse à un conflit à résoudre avec le club adverse ; rien de plus chez nous ». La boucle signale→traite→constate existe déjà (radar + gestes sous verdict + réconciliation RMM-4) ; le tracker/états/deadline/rédacteur cadrés le 2026-08-22 étaient une sur-construction. Décision au registre : `etat-des-lieux.md` §2. Le cas d'usage « contact du club adverse » reste porté par RMM-9 seul. | — | — | **décision fermée** |
| **RMM-8** | **Matrice trajet** + conflits spatiaux (empreinte AWAY réelle). Infra partagée avec l'entraînement (FF#5). | Back + Engine | contrainte sémantique → NR | **palier B/vision** — `gestion-matchs-ffbb.md` §7 + roadmap « Matrice de temps de trajet » |
| **RMM-9** | **Annuaire adverse global** (table hors tenant, publique-seulement, enrichie par l'usage) + effet réseau (auto-remplissage heures/positions extérieures). | Back | isolation tenant → **test d'isolation dédié obligatoire** | **palier B/C** — `gestion-matchs-ffbb.md` §5bis/§11 |
| **RMM-10** | **LIVRÉ (2 PR, 2026-08-26).** Un match déclaré ne perd plus sa salle en silence — recadré fondateur 2026-08-25/26 : le restore (exploration) ne touche PLUS aux matchs (pointeur transitoirement pendouillant, assumé) ; la **VALIDATION du planning** est la gâchette principale (`GET /schedules/{id}/validate-impact` annonce « N matchs [dont X déclarés] perdront leur salle » si N>0, `POST /validate` bascule UNPLACED + raison persistante `venue_lost`, heure conservée modifiable) ; la suppression de gymnase (déjà annoncée) gagne la même bascule via le même foyer (`FixtureVenueLossMarker`). Le volet « plages différentes sur un gymnase survivant » était DÉJÀ couvert (`ACCESS_WINDOW_LOST`). Comportement gradué : [`../courantes/module-matchs.md`](../courantes/module-matchs.md) § « P2-52 — un match déclaré ne perd plus sa salle en silence ». | Back + Front | **périmètre engagé** + lifecycle → NR (`EngagedTeamGuardTest` + `DeletionImpactParityTest` étendus) | **P2-52** — SOLDÉE, quitte la roadmap |

**Séquencement — VALIDÉ fondateur 2026-08-17** : **RMM-0 immédiat** (débloque la décision d'appariement
sans attendre la refonte) ; puis RMM-2 → RMM-1 (livrable rapide, faible risque, valeur immédiate) ; puis
RMM-3 + RMM-4 (le « gardien », cœur de l'angoisse) ; puis RMM-5 (A/B, plus lourd car moteur) ; puis
RMM-6 (échéances ligue/comité, point d'insertion préparé dès RMM-3) — **RMM-0 à RMM-6 sont tous
livrés en entier, RMM-7 est fermé sans code, et RMM-10 (P2-52) est livré (2026-08-26)** ; ne reste
que RMM-8/RMM-9 (palier B/C), au rythme de `gestion-matchs-ffbb.md` déjà spécifié. **Chaque
lot est une session d'implémentation à part**, avec sa propre
validation de besoin + `/plan` (CLAUDE.md §7).

---

## 10. Ouvert — à trancher avec le gestionnaire avant de coder les lots concernés

> Passe de tranchage du 2026-08-22 (fondateur) : **cinq des sept questions sont TOMBÉES** — leurs
> décisions vivent dans les sections des lots (§5 · §8 · §9 · §6quater L9). **La TRACE de
> réconciliation (RMM-4) est tombée à son tour, livrée telle que proposée** (PR-1 backend,
> 2026-08-24) : `FbiIngestion.pendingDeviations` n'est pas un journal — un pense-bête qui détecte au
> dépôt suivant qu'une correction FBI promise n'a pas été faite (badge « Écart persistant »), et
> meurt en silence dès qu'un dépôt confirme (fichier revenu à la valeur app, ou fixture disparu) ou
> qu'un « prendre le fichier » résout l'écart — borne « jusqu'au prochain dépôt », pas de délai
> calendaire. RMM-4 est depuis LIVRÉ EN ENTIER (PR-3, canal API FFBB, 2026-08-24). Détail :
> `../courantes/module-matchs.md` § « Réconciliation FBI ». Reste :

- **Officiels / arbitres dans le xlsx FBI (L9)** : le fondateur veut NOTER si la ligue a posé un
  officiel/arbitre — pas pour le gérer (pas notre sujet), mais pour **solliciter des gens du club**
  sur les autres rencontres. ⚠ À vérifier sur un VRAI export avant de promettre : le xlsx
  porte-t-il ces colonnes ?

---

## 11. Coordination avec `gestion-matchs-ffbb.md` (qui possède quoi)

- **`gestion-matchs-ffbb.md`** reste la **référence de besoin** du palier B/C (dérogation, trajet, annuaire
  adverse, catalogue-ligue, empreinte-temps, échéances). Seuls les lots **RMM-8/RMM-9** y renvoient
  encore — **ne pas les re-spécifier ici** ; RMM-6 (échéances) y renvoyait aussi pour le besoin, il
  est désormais livré, et RMM-7 (dérogation) est fermé sans code (§2 de l'état des lieux).
- **Ce fichier** possède la **refonte UX** (RMM-1/2), le **gardien** (RMM-3, **livré**), la
  **réconciliation FBI** (RMM-4, **livrée en entier**), la **rotation A/B** (RMM-5, **livrée en
  entier**), les **échéances ligue/comité** (RMM-6, **livrées en entier**) et la **salle perdue en
  silence** (RMM-10, P2-52, **livrée**) — les six comportements vivent désormais dans
  `../courantes/module-matchs.md`. **Plus aucun item n'est ouvert ici** : ne reste que le palier B/C
  (RMM-8/RMM-9), les net-neufs de l'entretien 2026-08-17, dont le besoin vit ENTIÈREMENT dans
  `gestion-matchs-ffbb.md`.
- **La spec courante** [`../courantes/module-matchs.md`](../courantes/module-matchs.md) reste la vérité du
  **livré** ; chaque lot livré y gradue (et **quitte** ce fichier), trace datée en
  [`../courantes/etat-des-lieux.md`](../courantes/etat-des-lieux.md).

---

## 12. En une phrase

Le module matchs est complet mais son écran est un fourre-tout ; on en fait **le guide qui amène la
vision du gestionnaire jusque dans FBI sans rien oublier** (mission §1 — FBI est la vérité absolue,
l'app prépare, vérifie et liste ce qui reste à saisir), avec les trois qualités du wizard — guider,
un geste à la fois, allers-retours faciles, jamais un tunnel ; on ajoute le **gardien** qui prévient le
gestionnaire des conflits qu'il découvre aujourd'hui trop tard, on rend **honorable la rotation A/B** qu'il
dessine à la main, et on branche progressivement le **palier B déjà spécifié** — sans jamais promettre ce que
la FFBB ne permet pas : le **canal** FBI reste manuel, mais son fichier est traité en **source de données de
plein droit**, au même titre que l'API FFBB.
