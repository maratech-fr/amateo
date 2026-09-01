# Les 3 types de planning — référence produit

Last verified @ 2026-09-02 (lot overlay Mateo PR-1, `documentation-update`). Exemple de nom de
plan de fermeture recalé (l'ancien incident « travaux — semaines du 7 sept. » remplacé par
« Matéo indisponible (incident) — du 31 août 2026 au 16 oct. 2026 », D2). Re-confronté au code :
`SchedulePlanProvisioner::ensurePeriodPlanId` (**:716**, recalé de :714) ✓ ·
`TranscribePeriodPlanController` (`POST /api/schedule_plans/{id}/transcribe-from-socle`) existe ✓ ·
défaut auto-transcription sur fermeture au front (`GenerateStep.tsx:52`, `"closure" === periodType`) ✓ ·
`PeriodWindowUniquenessGuard` maison du 409 `window_already_planned` ✓. **Nouveau (D10bis)** : la
naissance d'un plan de FERMETURE copie aussi les blocs de mutualisation du socle.

> **Rôle de ce document** : la trace durable du modèle métier des plannings, validé avec le
> fondateur le 2026-07-12. C'est LA référence à consulter avant tout travail sur la
> génération : quel type se déclenche quand, ce qu'on y manipule, quel besoin il comble,
> et où l'implémentation actuelle diverge de la cible.
>
> **Ce doc = le PRODUIT** (déclenchement, manipulation, besoin). **Le modèle TECHNIQUE**
> (entité Plan, versions, pointeur, invariants, vocabulaire) vit dans
> [`ADR-0002 — pattern « Plan »`](../../docs/architecture/adr-0002-pattern-plan.md) — un
> concept = une maison, pas de duplication.
> Mécanique temporelle : [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md)
> (CalendarEntry, cockpit) · entités et gardes serveur :
> [`backend-inventory.md`](../../backend/docs/backend-inventory.md) §2.

## Vue d'ensemble

| | 1. Planning principal (socle) | 2. Overlay d'ajustement | 3. Planning de reprise (vacances) |
|---|---|---|---|
| **Déclenchement** | **Automatique** : création du compte / démarrage de saison. Anticipable dès la saison N-1 (transition). | Une **indisponibilité est déclarée d'abord** ; puis **le gestionnaire décide** de s'y ajuster. Rien d'automatique dans le déclenchement. | **Manuel** : le gestionnaire **choisit les semaines** qu'il veut travailler parmi celles disponibles dans les vacances. |
| **Couverture** | **Toute la saison** | **1 semaine** (découpage auto en semaines englobant l'indispo une fois la décision prise) | **1 semaine** choisie à l'intérieur des vacances (ou N semaines identiques, voir règle) |
| **Structure** | Saisie complète (wizard) | **Verrouillée** : équipes, gymnases/créneaux, coachs non modifiables — **exception : les séances/équipe sont ajustables** (3→2, 0 = pas de créneau cette semaine) | Équipes **cochables/décochables** (défaut : **Fanion + importantes**), créneaux gym **redéfinissables** (prêts mairie), coachs lecture seule |
| **Contraintes** | Toutes (permanentes) | **C'est ce qui bouge** : héritées + datées, ajustables pour la semaine | Héritées avec défaut intelligent (suit les équipes) + propres à la période |
| **Ce que ça comble** | Le plan de base de l'année — le process **le mieux rodé** | **Réparer un souci ponctuel** (gym fermé, coach absent) sans toucher le socle | La **reprise progressive** semaine par semaine (vacances, effectif réduit) |
| **Nom par défaut** | `Planning de la saison 20XX-20XX` | **le TITRE de son entrée de calendrier** (décision fondateur 2026-08-23 — une seule identité ; ex. `Matéo indisponible (incident) — du 31 août 2026 au 16 oct. 2026`) | **le TITRE de son entrée** (ex. `Vacances de la Toussaint — du 20 oct. 2026 au 2 nov. 2026`) |

## Règle transverse : le SEGMENT est l'unité

**Tout planning qui n'est pas le socle couvre un SEGMENT — un bloc de semaines calendaires
pleines et contiguës (lundi→dimanche, clamp saison admis aux deux bords) ; la semaine simple est
le segment de taille 1.** Vrai côté API (`CalendarEntryStateProcessor::assertValidWeekChild`,
[ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md)) **et côté écran depuis P2-41 PR-C
(2026-08-19)** : le picker (`WeekPickerDialog`) propose des SEGMENTS aux ruptures GÉOMÉTRIQUES de
l'offre (`segmentsFromOffer`, `frontend/src/features/cockpit/lib/date.ts`) — semaine d'entame/fin
partielle de l'événement, discontinuité de l'offre (exclusion vacances P2-40, filtre temporel) —
**précochés**, avec deux gestes nommés : **SCISSION** (déplier un segment en ses semaines) et
**FUSION** (assembler des segments adjacents dans l'offre, y compris par-dessus une rupture — le
serveur ne borne que contiguïté + enveloppe, la liberté est donc totale). Un segment multi-semaines
porte une phrase pédagogique de présentation (le sur-ferme du solveur sur des semaines qui
diffèrent), jamais une décision. Un enfant naît toujours avec SON plan (rail 1 entrée = 1 plan),
quelle que soit la largeur de son segment.

- **Overlay** : une indispo à cheval (jeudi → jeudi suivant) ⇒ **2 overlays auto** (un par
  semaine englobée). On gère le premier ; l'outil **notifie qu'un second planning est
  ouvert à compléter**. Semaine 1 : jeudi–vendredi indisponibles ; semaine 2 : lundi–jeudi
  indisponibles — le vendredi de la semaine 2 **garde ses créneaux du socle**.
- **Reprise (écran actuel)** : le gestionnaire coche des SEGMENTS parmi ceux proposés (précochés
  par défaut, éditables par scission/fusion) — un segment coché devient un enfant, avec son plan.
  **N semaines cochées EN UN SEGMENT = N semaines IDENTIQUES** (un planning répliqué, exact si les
  semaines du segment sont réellement identiques, sur-ferme sinon) ; deux segments distincts =
  deux plannings.

## 1. Planning principal (socle)

- **Déclenchement** : automatique à la création du compte (onboarding wizard) ou au
  démarrage d'une nouvelle saison ; préparable en avance via « Préparer la saison
  suivante » (transition N→N+1).
- **Contenu** : toute la structure du club (équipes, gymnases + créneaux, coachs, liens,
  contraintes permanentes) → génération CP-SAT → plan de saison.
- **Cycle de vie (ADR-0002, livré)** : des **versions** (V1, V2… nom auto) ; **valider = le
  plan pointe** sur la version choisie et les autres sont **supprimées** ; **rouvrir =
  dépointer**, la version survit ; **pointeur null = espace de travail**. Générer ne pointe
  jamais — seul le gestionnaire choisit. Modifier le socle invalide les plans construits
  dessus (confirmation proportionnée).
- **État** : ✅ livré et rodé — c'est le flux de référence (cycle de vie basculé sur le pointeur du plan, ADR-0002, 2026-07-16).

## 2. Overlay d'ajustement (indisponibilité)

- **Séquence** : (1) l'indisponibilité est **déclarée** (« Signaler une indispo » — gym
  fermé, etc.) — **aucun plan n'existe encore** (ADR-0002 amendé 2026-07-24 : le plan naît
  du geste d'ADAPTER, la déclaration n'est qu'un fait au calendrier, le radar en lit
  l'impact par les contraintes datées) ; (2) **le gestionnaire décide** de traiter cette
  indispo (« Adapter ») — **c'est là que le plan naît** (`POST /schedule_plans`), sans
  version ; (3) l'outil découpe **automatiquement** en autant de plannings que de **semaines
  englobées** ; (4) il gère le premier, est **notifié** des suivants à compléter.
- **Naissance de la V1 — deux voies (P2-44, [ADR-0004](../../docs/architecture/adr-0004-period-plan-birth-as-socle-copy.md), 2026-08-19)** :
  un plan de période vierge peut obtenir sa V1 par le **solve complet** habituel (`generate`), ou
  par **transcription sans solveur** (`POST /api/schedule_plans/{id}/transcribe-from-socle`) — le
  socle POINTÉ recopié à l'identique, moins ce que la sélection de période filtre (équipe
  désactivée, gymnase/jour fermé, réduction de séances), verrous HARD révocables, séances qui ne
  passent plus nommées « à replacer ». **Écran (PR-2)** : un bouton « Partir du planning de
  saison » propose la transcription à côté du bouton de génération tant que le plan est vierge
  (`GenerateStep`) ; l'écran embarqué affiche ensuite le panneau « à replacer » et une modale de
  comparaison avec le socle — détail : `frontend/docs/frontend-spec.md` §6.7 bis. **Sur une
  fermeture, la transcription est désormais le DÉFAUT (P2-44 PR-4, 2026-08-20)** : elle se
  déclenche automatiquement à l'arrivée sur l'étape, sans clic — le bouton manuel reste le geste de
  repli. **Restriction assumée** : ce défaut ne s'applique QU'aux périodes de type fermeture — voir
  §3 pour les vacances, qui gardent le bouton manuel à l'octet près.
- **Une fois la V1 née — TROIS gestes désormais (générer / transcrire / combler, P2-44 PR-3)** :
  après une transcription (ou tout autre solve) qui laisse des séances « à replacer », le
  gestionnaire n'est plus borné à « régénérer entièrement » ou « déplacer une par une » — un
  bouton **« Combler automatiquement »** (`PlanningPage`, visible dès que la dérive porte des
  entrées « à replacer ») déclenche `POST /api/schedules/{id}/fill` : une V+1 où **tout ce qui est
  déjà placé reste EXACTEMENT en place** (les placements de la version source sont épinglés HARD
  **dans le payload du solve seulement**, jamais persistés) et le solveur ne place QUE les trous.
  Miroir « Régénérer » côté rail (savepoint, verrou de génération, Mercure, import — tous
  réutilisés, zéro changement moteur/contrat) mais borné à une version de PÉRIODE ; le socle se
  régénère toujours via `/regenerate`. Détail : `backend/docs/backend-inventory.md` §3,
  `frontend/docs/frontend-spec.md` §6.7 bis.
- **Manipulation** : structure verrouillée (équipes entières, gymnases/créneaux, coachs) ;
  **exception validée** : le **nombre de séances par équipe** est ajustable — un
  gestionnaire réel passe une équipe de 3 à 2 créneaux, ou supprime les créneaux d'une
  équipe loisir pour la semaine. Les **contraintes** sont l'outil principal d'ajustement.
- **Résultat** : un calendrier secondaire borné à la semaine ; hors des jours d'indispo,
  les créneaux du socle restants **sont conservés**.
- **État** : 🟢 rodé sur les axes livrés — découpage hebdo + granularité JOUR (E1/5b),
  contraintes héritées cochables (#211), **séances/équipe ajustables dans l'UI** (champ 1–7
  + toggle = 0 séance, E4 via `TeamPeriodOverride`), **défaut = tout le club actif** (E3,
  structure verrouillée), **nom auto** = le titre de l'entrée (E6, recalé 2026-08-23). Reste la
  notification multi-semaines (cadrage à venir). Voir « Écarts » ci-dessous.

## 3. Planning de reprise (vacances)

- **La transcription du socle (§2) reste MANUELLE ici, à l'octet près (P2-44 PR-4, décision
  fondateur 2026-08-20)** : contrairement à une fermeture, une reprise de vacances n'obtient
  jamais sa V1 automatiquement — le bouton « Partir du planning de saison » reste le seul chemin.
  Deux raisons : de sens (« les vacances sont TOTALEMENT différentes d'un incident de saison,
  c'est un planning TOUT nouveau, régénérer de zéro y est accepté, je ne veux pas de copie du
  socle ici ») et technique (une reprise dont la grille est réécrite en journée verrait les
  séances du soir du socle copiées en verrous HARD hors grille — `OrphanPinGuard` refuserait
  alors 422 « Régénérer » ET « Combler », enfermant le gestionnaire).
- **Séquence** : depuis le cockpit (radar vacances ou clic sur un jour de vacances), le
  gestionnaire **choisit les semaines** à travailler parmi celles des vacances → chaque
  sélection ouvre le wizard en mode période → génération. **Chaque semaine cochée naît
  avec SON plan (c'est le geste)** ; la mère, pur ancrage, **n'a jamais de plan** — sauf
  chemin « d'un bloc » explicite, où le clic Adapter le crée. **Découper une mère au
  plan-bloc commencé (0 version) supprime ce plan et ses réglages** ; revenir au bloc =
  supprimer soi-même chaque semaine puis re-Adapter (jamais de bascule automatique —
  décision fondateur 2026-07-24).
- **Manipulation** : équipes **cochables/décochables** — défaut : **Fanion + importantes**
  (rangs S + A) pré-cochées ; séances/équipe surchargables ; **créneaux gym
  redéfinissables** (un gymnase prêté par la mairie juste pour la fenêtre) ; contraintes
  **héritées** avec défaut intelligent (club/coach gardées, équipe-en-pause et gymnase
  décochées) + contraintes propres à la période.
- **Exemples réels** :
  - **Toussaint** : 2 semaines différentes → **2 plannings**.
  - **Noël** : 1 semaine blanche (aucun planning) + 1 semaine de reprise → **1 planning**.
  - **Été** : rien pendant l'été, puis **2 semaines de reprise dégradée** → **2 plannings**.
- **Collecte des doléances coachs (LIVRÉ #10, 2026-07-25)** : bouton **« Doléances »**
  (todo-list par équipe × semaine, coche « traité ») + **« Solliciter les coachs »**
  (campagne → lien tokenisé sans login `/doleances/{token}` → page publique pré-remplie →
  emails + digest quotidien + relance) + **badge radar** « X/Y répondu · N à traiter ».
  Un souhait, jamais une contrainte. Détail : E5 ci-dessous ·
  [`backend-inventory.md`](../../backend/docs/backend-inventory.md) §2 (entités, token, page publique).
- **État** : 🟢 rodé sur les axes livrés — héritage contraintes + défaut intelligent (#212),
  équipes on/off + séances, **grille de gymnases possédée par la période** (#8 — copie du
  modèle de saison, éditable gymnase par gymnase à l'écran), **choix des semaines** (E1), été inclus (E2),
  **défaut équipes = Fanion + importantes** (E3), **nom auto** `{label vacances} — {repère}` (E6),
  **collecte des doléances coachs** (E5 — C1/C2/C3, feature CLOSE). Reste
  optionnel, non demandé : un flux guidé « reprise progressive » (bonus P2-1). Voir « Écarts ».

## Écarts implémentation ↔ cible (actés 2026-07-12)

| # | Écart | Type touché | Cible |
|---|---|---|---|
| E1 | ✅ **Livré (2026-07-18, version fondateur — remplace la cible d'origine)** : adapter une période couvrant **plusieurs semaines** ouvre le **choix des semaines** (lun→dim, clampées à la saison) — chaque semaine cochée = une `CalendarEntry` **enfant** (`parentEntryId`) avec **son plan indépendant** (rail 1 entrée = 1 plan intact ; « N cochées ensemble = identiques » abandonné). Le chemin « d'un bloc » reste offert ; exclusivité bloc/semaines gardée serveur (422/409). Couverture visible (chips par semaine au radar + DayDialog). Datées héritées de la mère. **Granularité JOUR livrée (5b, 2026-07-18)** : un gymnase fermé une partie de la semaine n'est indispo QUE ses jours réellement fermés (incident ∩ fenêtre) — ses créneaux sont retirés du payload ces jours-là (`VenueClosureDays`), pas de forbid tous-jours ; conflits day-précis aussi. Zéro engine | 2 + 3 | — |
| E2 | ✅ **Livré (2026-07-18)** : exclusion été levée (`isAdaptableHoliday` supprimé), dates clampées à la saison | 3 | — |
| E3 | ✅ **Livré (2026-07-19)** : défaut équipes reprise = **Fanion + importantes** (2 premiers rangs) pré-cochés ; **fermeture = tout le club actif** (structure verrouillée, équipes loisir décochables à la main). Le seed de `PeriodTeams` est désormais conscient du `periodType` | 3 | — |
| E4 | ✅ **Livré (arrivé avec #262, tracé 2026-07-19)** : `PeriodTeams` expose l'ajustement des séances/équipe (champ 1–7) **et** le toggle actif/inactif (= 0 séance) dans le flux période — pour la **fermeture comme la reprise** (`TeamPeriodOverride`) | 2 | — |
| E5 | ✅ **C1 livré (2026-07-25, #10)** : la modale **« Doléances des coachs »** est une todo-list par (équipe × semaine), ancrée à l'entrée MÈRE des vacances, ouverte de deux portes — bandeau du wizard (filtrée sur la semaine du plan courant) et carte de période au cockpit (toutes semaines). Filtres coach/équipe, coche « traité », saisie « au nom d'un coach ». **Amendé 2026-08-01 (P3-14, retour terrain)** : les deux filtres se lisaient dans l'ordre BRUT de l'API — ils reprennent les regroupements qui existaient déjà ailleurs (coachs par staffing via `groupedCoaches`, équipes par RANG via `groupTeamsByTier`), pas un tri de plus. À la SAISIE, le coach est borné aux **coachs PRINCIPAUX (MAIN) de l'équipe choisie** (décision fondateur : « je veux que les MAIN coach ») : le select listait tout le club alors que le défaut pré-sélectionne déjà le MAIN, si bien qu'on pouvait consigner « U18F1 — Emerick » quand Emerick n'encadre que SF1 et U15F1, sans que rien ne l'attrape. Et une **équipe sans coach principal sort du formulaire** — « comment avoir une doléance de coach si une équipe n'a pas de coach ? ben c'est pas possible » — le bouton « Ajouter » est alors désactivé et l'écran dit ce qui manque, plutôt que d'ouvrir un select vide. ⚠ Deux asymétries VOULUES : le **filtre** garde toutes les équipes (il sert à LIRE des doléances existantes, dont celles d'une équipe qui a perdu son coach depuis), et le select conserve **la valeur courante** d'une doléance dont le coach n'encadre plus l'équipe, marquée — la filtrer viderait le champ sur une doléance qui nomme pourtant quelqu'un, et « combler le trou » la réattribuerait en silence. Masquer n'est légitime que pour un CHOIX. ✅ **C2 livré (2026-07-25, #10)** : **collecte SANS email** — bouton « Solliciter les coachs » (campagne modifiable : semaines / équipes / deadline) → un **lien personnel par coach** (`/doleances/{token}`, token en clair, réutilisable/révisable jusqu'à la deadline) que le gestionnaire **copie dans WhatsApp** → **page publique sans login** pré-remplie, le coach saisit ses souhaits (n'envoie que les sections modifiées) → tombe dans la todo-list C1 (écrase + « à retraiter ») → **badge radar** « X/Y coachs ont répondu · N à traiter ». ✅ **C3 livré (2026-07-25, #10 — feature CLOSE)** : bouton « Envoyer les liens par email » (global + individuel, badge « pas d'email »/« envoyé le… »), **digest quotidien 7h** aux gestionnaires (seulement si nouvelle réponse ; récap final une fois après la deadline), **relance des silencieux** (1×/jour). Le dialog campagne porte un **filtre par équipe + statut** (répondu / en attente / pas d'email) sur la liste des coachs (n'affecte que la liste, pas les boutons d'envoi). **Amendé 2026-08-01 (P3-15, retour terrain)** : la modale était « BEAUCOUP TROP longue » — recadré par le fondateur, **le problème était qu'elle affichait TOUTES les équipes**, une par ligne (49 sur un club réel). Trois remèdes : le choix des équipes se **replie derrière une ligne de résumé** (« Toutes les équipes (49) · Modifier ») et se déplie en **puces qui s'enroulent, groupées par RANG**, avec « tout cocher / tout décocher » globaux et par groupe ; une **nouvelle** collecte démarre avec **toutes** les équipes ayant un coach (le cas courant ne demande plus aucun geste — une campagne existante rouvre sur SA sélection, jamais sur « toutes ») ; et la liste des coachs passe dans un **second onglet** (« Réglages » / « Coachs »), absent tant que rien n'est enregistré, sélectionné d'office à la ré-ouverture puisque suivre et envoyer est alors le geste fréquent. Les puces de filtre par équipe suivent le rang elles aussi. Le composant d'onglets est **partagé** (`shared/components/ui/tabs.tsx`, deux peaux : `console` pour l'admin, `app` pour le club) — déplacé plutôt que dupliqué, un motif ARIA en double étant deux comportements clavier qui divergent. ⚠ **Une peau par défaut est un piège** (revue #346) : un `variant` oublié sur un site d'appel peint des tokens clairs sur la coque sombre du superadmin et rend l'onglet actif illisible — c'est arrivé aux sous-onglets Journaux. Le site d'appel est désormais gardé côté PAGE, pas seulement côté composant. Trois autres garanties tiennent par des tests dédiés : la création **bascule** sur l'onglet Coachs (sinon les liens naissent dans un panneau caché et on croit à un échec) ; une campagne dont une semaine retenue est **révolue** s'ouvre sur Réglages, pour ne pas reléguer derrière un clic l'avertissement que #344 avait imposé ; et le sélecteur **montre tout ce qui est sélectionné**, y compris une équipe qui a perdu son coach — sinon « tout décocher » la laissait en place et l'enregistrement la postait quand même. | 3 | — |
| E6 | ✅ **Livré (2026-07-19), gabarits recalés le 2026-07-31, REFONDU le 2026-08-23** : à sa naissance, un plan de PÉRIODE (CLOSURE et HOLIDAY) prend pour nom **le TITRE de son entrée de calendrier** (`SchedulePlanProvisioner::ensurePeriodPlanId`, source unique serveur, ADR-0002 inv. 12 intact — le nom n'est posé qu'UNE fois, le renommage manuel reste sacré). Décision fondateur née du parcours e2e P4-122 : le même overlay portait DEUX noms sans lien visible (titre d'entrée au radar/wizard, gabarit « Ajustement {gymnase} — {repère} » dans « Tous les plannings »). **La date reste lisible dans le nom** — « le gestionnaire, d'un coup d'œil, confirme s'il a fait une erreur » — par CONVENTION et non par heuristique : tout titre de période porte sa fenêtre (le seul chemin qui y dérogeait, la vacance adaptée d'un bloc, l'a acquise côté front le même jour). Les anciens gabarits (« Ajustement {gymnase} — {repère} », « {label} — {repère} ») et le recalage `refreshClosurePlanName` sont SUPPRIMÉS — le recalage était de toute façon inopérant depuis que le plan naît du geste d'Adapter (il tournait avant que le plan n'existe). Aucune migration des plans anciens (précédent #339) : ils gardent leur nom, renommables à la main. SEASON inchangé (`Planning de la saison …`, pas d'entrée). `windowLabel` survit (consommé par le 409 et `GET /api/planned-windows`) | 1 + 2 + 3 | — |

> **Décisions de conception figées de la collecte (fondateur, 2026-07-25/26)** — le *pourquoi*
> derrière E5, à ne pas re-poser : **token stocké EN CLAIR** (« copier le lien » doit
> re-fonctionner à tout moment pour WhatsApp, ce qu'un hash interdit ; le privilège est borné par
> construction — n'écrit qu'un souhait, dans le périmètre du token, et meurt à la deadline) ·
> **D1** `sentAt` **par token** (un coach ajouté tardivement garde sa propre date) · **D2** le
> bouton global n'est **pas** un renvoi général — uniquement les jamais-servis (un changement de
> périmètre ne re-notifie pas) · **D3** blocage de relance = **jour calendaire**, pas 24 h
> glissantes · **D5** le récap final **part toujours**, même à 0/8 (c'est le signal d'agir
> autrement) · **D6** deadline **incluse** · **D8** format email validé à la saisie **et** à
> l'envoi. Hors périmètre, non demandés : tracking d'ouverture, préférences de notification, SMS.

> Suivi : ces écarts sont des items de backlog dans
> [`../evolution/roadmap.md`](../evolution/roadmap.md) — ils se cadrent et se livrent
> PR par PR, avec validation du besoin avant chaque lot (règle CLAUDE.md §7).

## Historique des décisions

- **2026-07-12** — **pattern « Plan » arbitré point par point (A→H)** → formalisé dans
  [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md) : entité Plan (type, nom
  public, période propre, pointeur), Schedule = version, valider = pointer + supprimer
  les autres, réglages de période sur le Plan, structure partagée + photo.
- **2026-07-12** — modèle des 3 types validé avec le fondateur (cette page) : semaine =
  unité hors socle ; overlay = décision du gestionnaire après déclaration, structure
  verrouillée sauf séances ; reprise = semaines choisies, défaut Fanion + importantes,
  demandes coach en futur.
- **2026-07-11/12** — overlays spontanés / différentiels (couche diff éparse, socle
  jamais touché) : `TeamPeriodOverride`, `VenueTrainingSlot.calendarEntryId`,
  `ConstraintPeriodOverride` (#208, #210, #211, #212).
  > ⚠ **Dépassé, ne pas coder dessus.** Deux choses ont bougé depuis. (1) **L'ancre** : les
  > jumeaux sparse se sont ré-ancrés au **plan** (`schedulePlanId`, lot C2/C3 du 2026-07-17),
  > `VenueTrainingSlot.calendarEntryId` n'existe plus. (2) **Le modèle lui-même, pour les
  > créneaux** : depuis #8 (2026-07-24) la période **possède** sa grille — les créneaux de
  > saison y sont **copiés** à la naissance du plan, et le build overlay ne les unit **jamais**
  > avec ceux de la saison. Seuls `TeamPeriodOverride` (activation + séances) et
  > `ConstraintPeriodOverride` (toggle) sont restés des diffs épars. Les contraintes **datées**,
  > elles, sont restées sur la `CalendarEntry` — c'est un fait de calendrier, pas un réglage
  > de plan (à ne pas confondre avec les ancres ci-dessus).
