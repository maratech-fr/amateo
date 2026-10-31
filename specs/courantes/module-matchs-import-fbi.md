# Module matchs (FFBB) — écran Importer

> Le dépôt FBI (xlsx), le canal API FFBB, l'appariement des salles, le terrain neutre, « validé
> ligue » et le workflow de traitement NEW/OUT_OF_SYNC/REVIEWED. Découpé mécaniquement de
> [`module-matchs.md`](module-matchs.md) (ancien §7) — contenu déplacé VERBATIM, non réédité,
> plafond `DocSizeCeilingTest`.

## 7. Écran Importer (`/matchs/importer`)

Carte « Données de match » (dépôt FBI, canal API, Engagements FFBB, fraîcheur) + file de traitement
par équipe (`ReviewQueue`, `AccordionSection` contrôlée, deep-link `?equipe=`).

### Engagements FFBB — le niveau d'une équipe JEUNE suit son engagement, par clic

Dans la modale « Engagements FFBB » (appariement 3 étages, mécanique côté serveur détaillée dans
[`ffbb-api.md`](../../backend/docs/ffbb-api.md) § Engagements + compétitions), chaque ligne d'une
catégorie JEUNE (U9–U18) en championnat ou brassage porte un niveau DÉDUIT de sa division FFBB
(D→Départemental, R→Régional, N→National ; jamais Élite ; pré-régional/national → rien). Contre
l'équipe SUGGÉRÉE pour cette ligne, un écart s'affiche — pastille d'état (« Niveau non renseigné »
si l'équipe n'a aucun niveau, « Fiche : <niveau actuel> » sinon) + bouton d'action (« Renseigner :
<déduit> » / « Aligner sur <déduit> ») — **jamais pré-coché**. Un clic bascule la pastille en neutre
(« Sera aligné à la confirmation » + « Annuler ») ; l'écriture ne part qu'au « Confirmer » global,
en bloc avec les autres appariements. **Annonce lecteur d'écran** (P4-289) : une région
`role="status" aria-live="polite"` au niveau de la modale (vide au repos) annonce le nombre
d'alignements de niveau en attente de confirmation — jusque-là la bascule de la pastille n'était
que visuelle, muette pour un lecteur d'écran. **La proposition ne tient que sur l'équipe suggérée** : changer
l'équipe d'une ligne la fait tomber jusqu'à réouverture de la modale — un écart calculé contre une
équipe n'a plus de sens si une autre équipe a été choisie depuis. Quand les lignes jeunes d'une même
équipe divergent de niveau, le serveur arbitre par la date du dernier match importé de chaque
compétition (la plus tardive l'emporte) ; indécidable (aucune date, ou égalité entre niveaux
différents) → rien n'est proposé pour cette équipe. **Un niveau non renseigné n'est jamais comblé en
douce** : sans clic du gestionnaire, il reste vide.

C'est l'**exception nommée** au périmètre engagé (§11) : la garde générique qui refuse tout
changement de `Team.level` sur une équipe engagée (409) reste souveraine pour le PUT générique — le
wizard reste grisé pour cette équipe, le bandeau de `TeamsStep` renvoie vers Matchs › Importer ›
« Engagements FFBB ». Seul ce chemin, et seulement sur clic explicite, écrit un niveau sur une
équipe engagée — toujours la valeur RE-DÉDUITE côté serveur (jamais un niveau fourni par le client).
L'ÉQUIPE elle-même doit être jeune (U9–U18 d'après sa catégorie, `TeamTagService::isYouthTeam`) : une ligne jeune appariée à une équipe U21 ou senior ne propose rien et un `alignLevel` sur elle est sans effet. Rang/tier restent intouchés, aucune régénération n'est déclenchée.

### Import FBI (xlsx, une passe)

Fichier GLOBAL club (colonnes Division · N° de match · Équipes · Date · Heure · Salle). `analyze()`
(dry-run) résout les correspondances Division↔équipe (`Competition`, type inféré `BRASSAGE`/
`CHAMPIONSHIP` du nom, disambiguïsé par `fbiTeamLabel` si deux équipes club dans la même division) →
le gestionnaire complète → `import()` reçoit `{file, mappings}` et persiste tout en une passe. **Identité
d'une rencontre importée = équipe + DIVISION du fichier (normalisée) + numéro** (D1/D2, M15,
2026-10-28 — colonne `Fixture::externalDivision`, posée à la création à côté d'`externalRef`,
jamais `competition.name` qui peut être réutilisé/renommé ; index unique élargi
`(club, saison, équipe, division, ref)`) : au 2ᵉ tour une équipe change de poule/division et FBI
réutilise ses numéros — sous l'ancienne clé `(team, externalRef)` seule, le n°X de la 2ᵉ phase
réécrivait le n°X de la 1ʳᵉ phase, y compris une date déjà passée (bug M15, § « Réparation des
rencontres M15 » ci-dessous). Diff par `(team, division, externalRef)` : date/switch → **dé-placement**
+ warning ; heure réelle → mise à jour en place ; `00:00` = sentinelle « heure non fixée », n'écrase
jamais une heure posée ; `Exempt` sauté. **Un adversaire différent sous le MÊME (team, division,
externalRef)** n'est jamais un écrasement — décision fondateur 2026-10-27, « un match ne change pas
d'adversaire, même forfait/repêchage : si ça arrive c'est un bug FFBB » — c'est une **anomalie**
`OPPONENT_CHANGED` : la rencontre existante n'est touchée en rien (ni adversaire, ni date, ni statut),
la ligne du fichier est écartée et nommée au rapport (« <division> n°<ref> : le fichier annonce un
autre adversaire (X au lieu de Y) — ligne ignorée, vérifiez dans FBI »), comparaison NORMALISÉE
(`VenueLabelNormalizer`, une simple dérive de graphie n'est pas une anomalie).
Onglets par famille (Brassage · Coupe ARA · Coupes CRM · Amicaux · Départemental · Régional ·
Autres — classification PURE présentationnelle, `divisionFamily.ts`, jamais un comportement métier)
avec compteur d'appariement ; recherche/`Listbox` avec champ de recherche au-delà de 8 options.
Garde-fou poule (`PR F2`) : adversaires du fichier confrontés à la liste des clubs de la poule
appariée — > 50 % d'inconnus = division refusée nommée et sautée, 1..50 % = warning
`POULE_MISMATCH`.

**Garde-fou mapping : une division ne peut pas greffer son numérotation sur un championnat FBI déjà
peuplé sous un AUTRE libellé** (D4, M15, 2026-10-28, décision fondateur « ce sont deux championnats
différents » — cas U13F3 prod BCCL : la division de la 2ᵉ phase s'appelait `DFU13-3-P2`, distincte de
`DFU13-3`, donc D1 seul suffisait déjà à les distinguer ; D4 couvre le cas où le gestionnaire
mapperait quand même la 2ᵉ phase SUR le championnat de la 1ʳᵉ). `FbiMappingGuards::
rejectSecondPhaseOntoFilledCompetition` refuse (nommé, division DROPPÉE des mappings — sautée sans
jamais écrire, les autres passent) tout mapping dont la compétition cible porte déjà des rencontres
FBI (`externalRef` non nul) sous une division différente — sinon `persistMappings` la RENOMMERAIT,
absorbant le championnat d'origine. Message : « Cette division a déjà son championnat « <nom> » :
créez un nouveau championnat pour la 2ᵉ phase. » Un mapping sans compétition cible, vers une
compétition peuplée UNIQUEMENT par le canal API (`externalRef` nul — pas un championnat FBI), ou dont
l'id est forgé/étranger (l'import crée alors le propre championnat du club) n'est jamais bloqué.
`analyze()` ne SUGGÈRE plus non plus une telle cible (même prédicat partagé,
`fbiFilledDivisionConflicting`) — le gestionnaire ne se voit jamais pré-rempli ce que le garde
refuserait à l'import. Fichier lu en mémoire (snapshot `arrayBuffer`) une fois à la sélection —
insensible à une réouverture externe du fichier entre analyse et import. Chaque libellé de salle
AWAY importé est auto-localisé (`OpponentVenueAutoLocator`, un des trois hooks d'appel, § « Table
TENANT `OpponentVenueLink` ») s'il matche UNE salle fédérale exacte ; sinon il reste « à apparier »
(§9 « Écran Adversaires ») — jamais deviné à l'import.

### « Pas de salles appariées, pas d'import » (chantier A PR 2)

**Décision fondateur 2026-10-22** (retours Rillieux, chantier A, PR 2/5) : un domicile IMPORTABLE
et CRÉÉ par ce dépôt ne peut plus entrer sans gymnase du club. `analyze()` rend, en plus des
divisions, deux inventaires LECTURE SEULE pour la modale d'import : `venues[]` — les salles à
DOMICILE du fichier agrégées par libellé normalisé (`FbiFixtureImporter::analyzeHomeVenues`),
chacune avec son compte de lignes, son gymnase déjà CONFIRMÉ (alias), une suggestion floue (jamais
le confirmé) et si elle est déjà mémorisée neutre — et `homesWithoutVenue[]` — les divisions dont
des domiciles n'ont AUCUNE salle dans le fichier (`analyzeHomesWithoutVenue`), par division
(`fbiTeamLabel` seulement si deux équipes du club la partagent). La modale (`ImportFbiDialog`, **dernier onglet
« Salles (appariées/total) »**, après les onglets de familles de divisions ; absent s'il n'y a ni salle
ni domicile sans salle) propose un gymnase du club par ligne — pré-rempli (confirmé > suggestion >
vide) — ou « Terrain neutre » ; « Importer » reste **grisé** (`disabledReason`, A11Y-30) tant qu'une
ligne n'a pas de choix. Depuis un onglet de famille, une ligne `text-warning` sous la barre d'onglets
compte les salles restantes et renvoie à l'onglet Salles ; sur l'onglet Salles lui-même elle est
absente (les lignes le disent déjà).

`POST /api/fixtures/import` accepte deux champs multipart JSON en plus de `mappings`/`decisions` :
`venueMappings` (`{label, venueId}[]`, un libellé → un gymnase OU la sentinelle `'NEUTRAL'`) et
`venueChoices` (`{division, venueId}[]`, une division sans salle → un gymnase OU `'NEUTRAL'`) — le
contrôleur borne la forme (liste JSON, uuid ou `NEUTRAL`, 400 sur un corps forgé, jamais relayé au
service tel quel). `FbiFixtureImporter::applyVenueCoverage` les applique **avant** la vérification
de couverture ET l'auto-validation « validé ligue » : un `venueId` uuid APPARIE le libellé (rejoue
`VenueExternalLabelAttacher::attach` — alias + backfill, gardes 422 incluses, et un gymnase
étranger/inconnu au club → 400) ou pose le gymnase choisi d'une division sans salle ; `'NEUTRAL'`
mémorise le libellé (ou la division) comme terrain neutre pour CE dépôt — refusé (422) si le
libellé est déjà un alias confirmé (on ne déclare pas neutre une salle qu'on a déjà). Puis
`assertImportableHomesHaveVenue` (lecture seule, mêmes sauts que la boucle de création — division
non mappée, mapping refusé, poule bloquante) refuse tout le dépôt (422 nommant les libellés/
divisions manquants) **avant toute écriture** si un domicile IMPORTABLE créé par ce dépôt reste sans
gymnase et n'est pas neutre. **Ne concerne que les CRÉATIONS** : un domicile déjà existant qui
perdrait son gymnase par ailleurs (§11, fermetures à la source) n'est pas soumis à cette garde, et
les divisions non appariées de l'écran principal n'exigent toujours rien (comportement inchangé).

### Terrain neutre (coupe/playoff joué hors de nos murs)

Un domicile dont le libellé (ou, sans salle, la division) est déclaré `'NEUTRAL'` naît `HOME`,
`UNPLACED`, **sans** `venueId`, `unplacedReason = NEUTRAL_GROUND`, et déjà **TRAITÉ**
(`markReviewed`) — garde son `fbiVenueLabel` (porte de sortie : apparier le libellé plus tard le
rattache, le backfill d'alias efface le drapeau neutre via `Fixture::setVenueId`). C'est le SEUL
chemin par lequel un domicile importé peut rester sans gymnase du club — un choix du gestionnaire,
jamais un défaut. Exclu partout où « sans gymnase » voudrait dire « à corriger » : jamais « validé
ligue » ni dans `toTreat` (`LeagueValidationOutlook::isCandidate`, sans quoi `NO_VENUE` le
signalerait à tort), jamais dans les files « sans gymnase »/« à placer » de l'écran (`isUnattachedHome`,
`isUnplacedHome`, `neutralGround` exclu des deux), jamais confié au solveur comme un match à placer
(`MatchPlacementPayloadBuilder` le traite comme un extérieur — il ne porte que SA date, `team_dates`
— le contrat engine ne bouge pas). Pastille dédiée « Terrain neutre » dans la file de traitement
(`ReviewQueueRow`). **Mémoire dérivée** : un libellé déjà porté par une rencontre neutre de la
saison (`FbiFixtureImporter::derivedNeutralLabels`) reste neutre aux dépôts suivants SANS nouveau
`venueMapping` — pré-coché neutre dans la modale, appliqué sans geste si le gestionnaire ne touche
rien. Champ additif `FixtureResource.neutralGround` (lecture seule, dérivé de `unplacedReason`) :
le front l'affiche, il ne recalcule jamais la règle (miroir interdit, `.claude/rules/frontend.md`).

### « Validé ligue » : auto au dépôt FBI (chantier A) + rattrapage en lot (`LeagueValidatedFixturesController`)

Un club qui démarre l'application EN COURS de saison importe un fichier FBI dont des domiciles sont
déjà datés côté fédération : date, heure et gymnase déjà enregistrés. Confirmer chaque placement à la
main n'a pas de sens. **Décision fondateur 2026-10-22** (retours Rillieux, chantier A, PR 1/5) :
le dépôt lui-même bascule maintenant ces domiciles en lot, SANS geste supplémentaire du
gestionnaire. La doctrine antérieure (« le chemin de création de l'import ne pose jamais `VALIDATED`,
une confirmation chiffrée séparée est seule à le faire ») est **amendée**, pas abrogée dans sa forme :
le chemin de CRÉATION continue, lui, à créer tout en `UNPLACED` (`FbiFixtureImporter::
attachConfirmedVenue` pose le gymnase, jamais le statut) ; c'est **après le flush de création**, dans
une PASSE SÉPARÉE du même `FbiFixtureImporter::import`, que les domiciles validables d'un championnat
déjà COMMENCÉ sont relus et basculés « validé ligue ». Le `POST /api/fixtures/league-validation`
(ci-dessous) reste disponible mais devient un **RATTRAPAGE** : il ne traite plus que les championnats
qui mûrissent ENTRE deux dépôts (l'échéance tombe, ou le premier match se joue, après le dernier
import).

**Le déclencheur est que le championnat ait COMMENCÉ, jamais une propriété du domicile
lui-même** — décision fondateur : « la date d'échéance est de validation des dates pour un
championnat entier, donc toutes les dates du championnat peuvent être validées à partir de la date
d'échéance ». Un championnat est « commencé » de DEUX façons, l'une ou l'autre suffit : (1) son
échéance de SAISIE est passée (jour de l'échéance INCLUS, `CompetitionDeadlineResolver::resolve` +
comparaison `<= aujourd'hui`) ; (2) OU son PREMIER match est déjà joué (`min(matchDate)`
strictement dans le passé, tous domiciles/extérieurs confondus) — un championnat sans échéance
renseignée mais dont les dates sont déjà tombées n'est plus provisoire, il a démarré. Raisonner
rencontre par rencontre sans aucune de ces deux conditions serait **dangereux** : en octobre les
brassages se terminent, une nouvelle vague de rencontres jeunes arrive (nouvelle phase, nouvelle
poule, parfois un changement de niveau) avec des horaires **provisoires**, sans échéance passée ni
match déjà joué — les proposer à la validation les verrouillerait en ancres fixes pour le solveur
au moment précis où il faut encore pouvoir les déplacer. Tant qu'aucune des deux conditions n'est
remplie, **rien n'est proposé pour ce championnat**.

Toute la LECTURE, le prédicat ET l'écriture vivent désormais dans la **maison unique**
`App\Service\LeagueValidationOutlook` (extraite du contrôleur pour être consommée aussi par le
cockpit, §4, et par le dépôt FBI, chantier A) : elle porte `compute()` (la lecture détaillée par
championnat, avec `maturedBy` `deadline`/`firstMatchPlayed` et `firstMatchDate` quand c'est le
premier match qui a fait mûrir), `fixturesToConfirm()` (les domiciles à basculer, recalculés au
moment de l'application — consommée à la fois par la passe post-flush du dépôt et par le `POST` de
rattrapage) et `markLeagueValidated()` (l'écriture elle-même : ancre `MANUAL` puis statut
`VALIDATED`, horodaté — foyer UNIQUE partagé par les deux appelants, jamais une seconde copie, ce
qui rend les deux ancres byte-identiques). L'échéance effective d'un championnat suit la règle « le
club gagne, sinon le
défaut communautaire » — maison unique `App\Service\CompetitionDeadlineResolver`, consommée par
`CompetitionResource::fromEntity` (la lecture des compétitions), `EntryDeadlineOutlook` (les
fenêtres J-7 du cockpit, §4) et `LeagueValidationOutlook` (la maturité « validé ligue »).

Deux routes `GET`/`POST /api/fixtures/league-validation` (management + saison écrivable + socle
pointé). **GET rend une lecture détaillée PAR CHAMPIONNAT**, pas un simple compte :
- `matured[]` — les championnats COMMENCÉS, chacun avec son nom, son échéance (nullable — un
  championnat mûri par son premier match joué n'en a pas forcément), sa provenance
  (`club`/`community`), **comment** il a mûri (`maturedBy` : `deadline`/`firstMatchPlayed`), la date
  de son premier match quand c'est elle qui l'a fait mûrir (`firstMatchDate`), et son compte de
  domiciles validables ;
- `toTreat[]` — les domiciles d'un championnat commencé qui NE seront PAS validés, **nommés**, jamais
  écartés en silence : équipe, date, adversaire, et la raison (`NO_KICKOFF`, `NO_VENUE`,
  `PENDING_DEVIATION`) ;
- `missingDeadline[]` — les championnats **sans échéance renseignée et pas encore commencés**
  (aucun premier match joué non plus) qui ont pourtant des rencontres prêtes (heure + gymnase) —
  signal qu'une échéance manque à saisir, jamais une validation proposée ;
- `totalValidatable` — le total tous championnats commencés confondus.

Le prédicat « validable » d'un candidat (`passesPredicate`, ex-`isEligible`) est inchangé dans sa
forme : un domicile `UNPLACED` rattaché à un championnat (`isCandidate` — **un amical est exclu**,
qu'il s'agisse d'un domicile SANS compétition ou d'une compétition dont le LIBELLÉ est un amical
(token exact « amical », maison unique `FbiDivisionSignature::isFriendlyCode` — couvre par exemple
« Amical PNF »/« Amical PNM » typées `CHAMPIONSHIP` côté import) portant une heure ET un `venueId`
(gymnase identifié, jamais le libellé brut), sans écart en attente. Il ne suffit pas à lui seul — il
faut EN PLUS que le championnat du candidat soit commencé.

**Les amicaux ne rejoignent jamais ce lot** — « validé ligue » n'a pas de sens sans championnat.
Un amical passé (domicile ET extérieur, même reconnaissance par libellé) se valide TOUT SEUL,
sans confirmation chiffrée : `App\Service\FriendlyAutoValidator::sweep` bascule `VALIDATED`
(source `MANUAL` posée sur le domicile seulement, l'extérieur garde sa source) tout amical
strictement passé, non déjà saisi (`SUBMITTED`/`VALIDATED` exclus) et sans écart en attente ;
idempotent, ne flushe que s'il y a eu un changement. Ce balayage est un effet de bord d'une
LECTURE, déclenché **uniquement quand un GESTIONNAIRE** charge la lecture « validé ligue »
(`guard()` de ce contrôleur) ou le cockpit (`EntryDeadlineOutlook`, §4) — un Membre, qui peut ouvrir
ces deux écrans, ne fait jamais écrire la base.

**`POST` reste SANS corps** : le serveur ne reçoit AUCUN championnat choisi par le client — il
**recalcule les championnats commencés au moment de l'application** (`fixturesToConfirm`, l'horloge
injectée + les échéances/premiers matchs du moment font foi, jamais ce que l'écran affichait à
l'ouverture). Bascule en lot, via `LeagueValidationOutlook::markLeagueValidated`, chaque domicile
`UNPLACED` d'un championnat commencé qui passe le prédicat : statut `VALIDATED`
(horodaté) + `placementSource` `MANUAL` — le `MANUAL` n'est pas cosmétique, la formule du cadenas de
la grille et l'ancre `FIXED` du solveur de placement l'exigent (§3), sinon les rencontres basculées
s'afficheraient déverrouillées alors que le solveur les traite déjà en ancres. **On ne dévalide
jamais** : le prédicat ne regarde que les rencontres `UNPLACED`, donc rejouable sans effet sur une
rencontre déjà `VALIDATED` par ce geste, par l'auto-validation au dépôt (chantier A) ou par la
réconciliation D9 : aucune de ces rencontres n'est jamais reprise. Depuis le chantier A, ce `POST`
**ne couvre plus le cas nominal** (le dépôt l'a déjà fait) : il ne reste utile que pour un
championnat qui mûrit ENTRE deux dépôts — le rapport de dépôt porte désormais `leagueValidated`
(compte des domiciles basculés par la passe post-flush), distinct de `totalValidatable` (ce qu'il
reste à rattraper, lu par le `GET`).

**Un seul geste, détaillé par championnat (décision fondateur)** — le front a écarté les cases à
cocher (choisir championnat par championnat) et les confirmations successives (une par championnat) :
la confirmation liste chaque championnat commencé avec son compte, puis une seule validation en
bascule tous les domiciles éligibles de tous les championnats commencés d'un coup.

⚠ **Divergence ASSUMÉE avec le contrôle d'accès du geste unitaire** : le placement
manuel d'un domicile refuse en 422 hors des créneaux d'accès match déclarés du gymnase
(`FixtureStateProcessor::assertVenueAccessAllowed`, §3) — le prédicat de ce lot ne fait PAS ce
contrôle, volontairement. La fédération a déjà enregistré cette réalité (date, heure, gymnase joués ou
programmés côté FBI) ; l'application la reflète au lieu de la nier. L'incohérence n'est pas tue :
le radar de conflits la signale (`ACCESS_WINDOW_LOST`, §2) — une rencontre basculée hors créneau
reste `VALIDATED` (le lot ne bloque jamais une réalité fédérale), le radar alerte. Figé par un NR
(`LeagueValidatedFixturesControllerTest`, cas hors créneau) : une passe future qui « corrigerait »
cet écart en y ajoutant le contrôle unitaire romprait le geste — c'est exactement le type de
divergence qu'un futur agent pourrait refermer par accident en croyant boucher un oubli.

**Isolation de saison, défense en profondeur** : les deux routes refusent
explicitement en 409 si aucune saison ne se résout (`_season_id` absent), au lieu de lire/écrire sur
TOUTES les saisons du club via un `findBy([])` non scopé si le filtre Doctrine venait à ne pas
s'activer — aucun chemin d'exploitation trouvé en revue, mais le comportement est maintenant explicite
et visible côté API (409, pas un silence qui élargirait le périmètre). Le POST refuse aussi en 409,
message actionnable en français, sur une collision d'écriture simultanée (verrou optimiste `Fixture`,
deux onglets ou un double-clic) — aucun verrou ajouté, l'écriture reste idempotente.

**Écran : proposé PARTOUT où le gestionnaire regarde (décision fondateur 2026-09-27, jamais
silencieux)**, même confirmation chiffrée partagée (`LeagueValidationConfirmDialog`, lib pure
`lib/leagueValidation.ts`) — refuser n'écrit rien. Quatre points d'ancrage :
- **Importer** (`LeagueValidationBanner`, sous la carte « Données de match ») : muet si les trois
  listes sont vides — couvre donc aussi les rencontres déjà en base sans re-déposer de fichier —,
  rendant TROIS blocs indépendants selon ce que le backend sert : les championnats commencés prêts
  (bouton de confirmation), les domiciles commencés à traiter (`LeagueToTreatNotice`, nommés, renvoi
  qui scrolle vers la file de traitement de la même page) et les championnats sans échéance
  (`MissingDeadlineNotice`, renvoi vers les « Échéances de saisie »). Ce MÊME bandeau se rend aussi
  sur le **Calendrier** (`CalendarPage`, gardé `canManage`) — un gestionnaire qui n'ouvre jamais
  l'onglet Importer le voit quand même.
- La section du rapport de fin d'import (`LeagueValidationReportEntry`) ouvre la même confirmation,
  muette si `totalValidatable` est nul.
- Le **cockpit** (`FbiDeadlineCard`, §4) porte une ligne dédiée « N matchs déjà dans FBI à
  confirmer » (compte `toConfirmCount`, servi par `EntryDeadlineOutlook`) + son propre bouton qui
  ouvre la MÊME confirmation — réservée au gestionnaire, jamais affichée à un Membre.

Le front n'a **aucune règle** : c'est le backend qui décide QUOI est proposé (championnat commencé),
le front affiche la lecture servie (`useLeagueValidationOutlook`). **Vocabulaire (retours Nico,
BCCL 2026-10-27, M3)** : la pastille de statut EMPLOIE désormais les mêmes mots — `VALIDATED` se dit
« Déjà dans FBI » (plus « Attesté FBI », §7 « Workflow de traitement »), et cette confirmation
« Marquer « déjà dans FBI » » (`LEAGUE_VALIDATION_CONFIRM_LABEL`, `lib/leagueValidation.ts`, plus
« validé ligue ») — le fondateur a demandé à revoir toute la table après l'avoir lui-même trouvée
peu parlante.

**Deux faits mesurés** : l'échéance d'un championnat est **saisie à la main** par le gestionnaire
(`EntryDeadlinesEditor`) — jamais reprise automatiquement du fichier fédéral, rien dans FBI ne la
porte. Sur la base réelle du fondateur, les compétitions de la saison ont TOUTES leur échéance
renseignée — une absence (`missingDeadline`) y serait donc une anomalie plutôt qu'un cas courant.

**`VALIDATED` n'est PAS un cul-de-sac (décision fondateur)** — un commentaire du code affirme
encore « VALIDATED is fully read-only — the league owns it. », c'est faux aujourd'hui (signalé aux
mainteneurs du fichier) : ce commentaire, comme le texte affiché (« Attesté par FBI : … »), ne
décrit que le chemin de réconciliation D9, pas la bascule en lot. `PlacementPanel` offre
**« Corriger — repasser en Placé »** sur `VALIDATED` comme sur `SUBMITTED` — quel que soit le
CHEMIN qui a posé `VALIDATED` (réconciliation D9 ou bascule en lot). Raison : une bascule en lot
peut porter sur des centaines de rencontres d'un coup, dont le gymnase peut venir d'un appariement
AUTOMATIQUE (§1, `OpponentVenueAutoLocator`) — sans sortie, un mauvais appariement les figerait
toutes, la seule échappatoire étant de supprimer le gymnase. Le texte affiché sous la pastille est
générique aux deux chemins (« Ce match est ancré sur les date, heure et salle enregistrées côté
ligue. Corrigez-le si l'une d'elles doit changer… »), sans attribuer l'ancrage au seul fichier
fédéral.

### Canal API FFBB (à la demande, `FfbbRencontreReconciler`)

FBI (xlsx) fait foi, l'API est un confort — bandeau d'honnêteté à chaque ouverture. Appariement à 3
étages + tier-0 d'idempotence (id national déjà connu) : compétition appariée pour UNE seule équipe
→ suggère l'équipe ; date exacte ; adversaire normalisé. Une coupe non appariée devient une vraie
`Competition` `CUP` (le libellé fédéral tranche, jamais l'absence d'appariement) — seul le token
`amical` laisse `competitionId` null. Les rencontres publiées sans fixture correspondante sont
**proposées, jamais imposées** (`TeamSelect` par ligne, rien créé si vide) via la vue dédiée
`/matchs/reconciliation` (zéro état serveur, payload en mémoire, renvoi propre sans payload), triées
côté front **date croissante · heure croissante (une heure absente en dernier de son jour) ·
adversaire en collation française** (`lib/creatableSort.ts`, appliqué une fois à la source — la
liste ET la dérivation `creations` héritent du même ordre) plutôt que l'ordre brut du backend.
`POST /api/ffbb/rencontres/apply` re-fetche côté serveur (jamais les valeurs client), écrit sa propre
`FbiIngestion source=FFBB_API`. Ses décisions partagent le même `venueId` optionnel que l'arbitrage
hors dépôt sur un écart `venue` (appariement INLINE, § « Arbitrage d'un écart de salle » ci-dessus,
chantier A PR 4).

**Un amical ou une coupe créés par ce canal sans gymnase : statu quo (décision fondateur
2026-10-23, option « pour l'instant »).** Une rencontre créée par `FfbbRencontreReconciler::newFixture`
sans salle naît `UNPLACED` « à placer », exactement comme un match saisi à la main — aucun
traitement spécial, aucun code dédié. Le gestionnaire la place (ou non) depuis l'écran comme
n'importe quel match sans salle. Le cas sera revu plus tard si le besoin se confirme.

### Workflow de traitement — NEW / OUT_OF_SYNC / REVIEWED

Deux axes distincts sur une `Fixture` : `status` (placement) et `reviewState` (a-t-elle été
EXAMINÉE). **`FixtureReviewState`** : `NEW` (jamais examinée) · `OUT_OF_SYNC` (déphasée, un écart
pendant subsiste) · `REVIEWED` (traitée). `pendingDeviations` (JSON) porte les écarts ouverts, un
par champ (`date`/`kickoff`/`venue`), `autoApplied` marque une valeur imposée hors périmètre. Maison
unique du statut : `Fixture::setStatus` (passer PLACÉ traite la rencontre, sauf écart pendant).

Règles de naissance : un extérieur, ou une rencontre passée/dans la semaine ISO en cours, naît déjà
`REVIEWED` — « un extérieur n'est jamais à traiter par le club », « un match imminent ou déjà joué
n'est pas nouveau » (`treatOnArrival`, foyer partagé aux deux canaux, **inchangé** par M15 : cette
fenêtre de NAISSANCE reste tout le passé + la semaine ISO en cours). Rattrapage
(`catchUpReview`/commande `app:fixtures:catch-up-review`) pour une rencontre `NEW` restée figée
avant ce prédicat. Un domicile PLACÉ/SUBMITTED que la source renvoie identique sur date+heure+salle
devient `VALIDATED` + traité (D9 — « attesté FBI », plus un geste du gestionnaire). Un champ
divergent sans décision → `OUT_OF_SYNC`, valeur app gardée — **sauf** si la fenêtre « FBI fait foi »
s'applique (app OU source), qui applique la source d'office (« pris en compte », `autoApplied`) :
cette fenêtre-ci, DISTINCTE de celle de naissance ci-dessus, est **restreinte à la semaine ISO en
cours, lundi ET dimanche inclus** (D3, M15, 2026-10-28, `FbiArrivalReview::sourceIsAuthoritativeForWindow`)
— avant, un simple plafond « ≤ dimanche » faisait foi pour N'IMPORTE QUELLE date passée, aussi
lointaine soit-elle ; un domicile PLACÉ de plusieurs semaines que la source re-date loin devant (cas
réel BCCL : un match du 10/10 réécrit en office au 14/11 par la numérotation réutilisée du 2ᵉ tour)
était ainsi réécrit SANS arbitrage. Un déphasage dont AUCUNE des deux dates ne tombe dans la semaine
ISO en cours (passé ancien, ou entièrement futur) reste désormais toujours un arbitrage —
**sauf sur la SALLE** (chantier A PR 4, § ci-dessus) : une salle inconnue (ni alias confirmé ni choix
inline) n'est **jamais** appliquée d'office même dans cette fenêtre, le gymnase actuel est conservé,
l'écart reste à trancher et la rencontre reste `OUT_OF_SYNC`. Une rencontre absente d'un dépôt n'est
jamais touchée (import partiel légitime).

Périmètre de réconciliation par écart (choix explicite, jamais un écrasement) : seuls les domiciles
**déjà placés** peuvent diverger sur date/heure/salle — un `UNPLACED` reste hors périmètre sur date/
heure, mais son écart de SALLE a son propre détecteur (§1). Conséquence par champ :
date/salle **dé-placent** ; heure reste en place mais **rétrograde** un `SUBMITTED`/`VALIDATED` en
`PLACED`.

**Salle du périmètre PLACÉ, la MÊME clause alias que le non placé.** Un libellé fichier dont
l'alias confirmé pointe le gymnase où la rencontre est placée n'ouvre pas d'écart — sans cette
clause, un faux écart reviendrait à chaque dépôt quand la source nomme le gymnase par un alias que
l'appli connaît déjà, si seul le fuzzy nom↔libellé était lu ici. Identité STRICTE :
un alias qui désigne un AUTRE gymnase que celui du placement lève toujours l'écart. Comme la
source atteste alors les trois champs, la rencontre passe en `VALIDATED` (ci-dessus, D9) — ce
n'est pas un effet de bord, c'est l'attestation qui fonctionne enfin sur ce champ.

**Deux signaux complémentaires, DEUX chemins de code séparés — décision fondateur (`etat-des-lieux.md`
§2)** : `analyze()` (dry-run, ci-dessus — mêmes deux canaux xlsx et API) ne consulte JAMAIS le
registre et produit un enregistrement d'écart à CHAQUE dépôt tant que le fichier répète le libellé
divergent, même après un « garder l'appli » précédent — c'est la fonction, pas un défaut : le
gestionnaire doit continuer à être averti tant que FBI affiche la mauvaise valeur, jusqu'à ce qu'il
la corrige lui-même dans le portail fédéral. `import()`/`apply()`, eux, ne recréent PAS d'écart sur
la rencontre pour ce même cas (moteur partagé `processPerimeterFields`, mécanisme ledger) : ils
RE-DATENT seulement l'entrée du registre « à corriger dans FBI »
(`lastSeenInFbiAt`, ci-dessous) — le rappel persistant vit dans cette liste, pas dans un nouvel
écart de la rencontre. **Leçon** : analyser et appliquer ne partagent PAS ce filtre — les lire comme
un seul mécanisme fait conclure à tort que le dialogue s'est tu.

Deux routes de traitement (management + saison écrivable + socle pointé) : `POST
/api/fixtures/review` (`{fixtureIds}` ligne, ou `{teamId}` masse — les rencontres à écart pendant
sont sautées et NOMMÉES, jamais tranchées en masse) ; `POST /api/fixtures/review/deviations`
(`{fixtureId, field, choice: keep_app|take_source, venueId?}`, rejoue le moteur partagé depuis la
valeur PERSISTÉE — `venueId` OPTIONNEL sur `field: 'venue'` + `take_source`, l'appariement INLINE
ci-dessus, chantier A PR 4). `ReviewQueueRow` : icône domicile/extérieur, heure ou « heure non publiée », salle
résolue ; bouton **« Vu »** (NEW sans écart, OU REVIEWED qui porte encore une alerte `autoApplied`
seule — même mot pour les deux cas, retours Nico BCCL 2026-10-27 M3, remplace « Valider »/« Pris en
compte » : la ligne ne « valide » rien, elle ACQUITTE une mise à jour déjà appliquée par l'import),
une phrase UNIQUE pour l'alerte `autoApplied` (maison `lib/autoAppliedPhrase.ts` : date+heure
reprogrammées fusionnent en « {source} a déplacé ce match : {ancienne date} → {nouvelle date} à
{heure} », un seul champ garde sa forme « (champ) : ancien → nouveau », une combinaison rare liste
chaque champ dans le même bloc — jamais des dates ISO brutes à l'écran), tranche champ par champ
sinon (deux colonnes Amateo/source) ; « Replacer » seulement sur un domicile À VENIR.

### Réparation post-déploiement des rencontres touchées par le bug M15 (2026-10-28)

> Procédure **ponctuelle**, jouée une seule fois après le déploiement du correctif D1-D4 ci-dessus,
> sur les clubs déjà en prod à cette date. Elle écrit en base réelle de club : **ne se lance que sur
> GO explicite du fondateur**, jamais en autonomie (`go-explicite-avant-tout-process`). Aucune donnée
> personnelle n'est manipulée (noms d'équipes/divisions/numéros FBI seulement).

**Portée mesurée (balayage LINK, lecture seule, 2026-10-27 soir)**, avant tout correctif : sur les
clubs en prod à cette date, des rencontres de la 1ʳᵉ phase ont été réécrites d'office par le dépôt
du 2ᵉ tour qui réutilisait leur numéro sous une autre division — BCCL : 10 rencontres / 7 équipes
(dépôt du 27/10) ; Rillieux : 4 rencontres / 4 équipes (dépôt du 23/10). Un troisième club (démo) :
aucune rencontre touchée. Le correctif D1-D4 empêche la récidive ; il ne répare pas, par construction,
les rencontres déjà réécrites avant son déploiement (leur ancienne valeur n'est conservée nulle part).

**Avant de commencer — l'adversaire d'origine est le plus souvent perdu lui aussi** : la version du
code en prod au moment du bug réécrivait `opponentLabel` en silence au passage (écrasement retiré par
ce correctif, § « Import FBI » ci-dessus), pas seulement la date. Une rencontre de la 1ʳᵉ phase
réécrite par le 2ᵉ tour porte donc le plus souvent **la date ET l'adversaire** de la 2ᵉ phase. Or,
avec le nouveau garde `OPPONENT_CHANGED`, un simple re-dépôt de l'export de la 1ʳᵉ phase sur une telle
rencontre ne la répare PAS : l'adversaire du fichier (correct) diffère de celui en base (corrompu) →
la ligne entière est écartée et SIGNALÉE (« ligne ignorée, vérifiez dans FBI ») — rien n'est écrit, y
compris la date. Le garde protège désormais l'avenir, il ne défait pas le passé.

**Procédure, par club touché, dans cet ordre** :
1. **Repérer les rencontres de la 1ʳᵉ phase dont l'adversaire a changé.** Avant tout re-dépôt,
   comparer (lecture seule) l'export de la 1ʳᵉ phase à l'état actuel de chaque rencontre concernée :
   si SEULE la date diffère (adversaire intact), elle sera un simple écart à trancher à l'étape 2 ;
   si l'adversaire diffère AUSSI, elle doit d'abord être **supprimée** (`DELETE
   /api/fixtures/{id}`, § écran existant) pour que le re-dépôt la RECRÉE au lieu de tenter un diff
   qui échouera sur `OPPONENT_CHANGED`. Une rencontre déjà PLACÉE qui est supprimée puis recréée perd
   son placement (gymnase/heure) — le gestionnaire la replace après coup, ou la bascule « validé
   ligue » la reprend d'office si son championnat est déjà commencé (§ ci-dessus).
2. **Re-déposer l'export FBI de la 1ʳᵉ phase.** Avec la nouvelle identité (équipe + division +
   numéro), chaque rencontre de la 1ʳᵉ phase (recréée à l'étape 1, ou restée intacte si seule sa date
   avait divergé) redevient reconnaissable sous SA propre division. Une rencontre recréée (étape 1)
   naît simplement avec les bonnes données ; une rencontre restée en place dont seule la date diverge
   remonte un écart (`OUT_OF_SYNC`, « à trancher », M3) — **décision : prendre la source**
   (`take_source`, § « Arbitrage » ci-dessus), l'export de la 1ʳᵉ phase restaure la vérité fédérale
   d'origine.
3. **Re-déposer l'export FBI de la 2ᵉ phase.** Chaque rencontre de la 2ᵉ phase a sa PROPRE division
   dans le fichier — le gestionnaire la mappe vers un **nouveau championnat** (jamais vers celui de la
   1ʳᵉ phase : le garde D4 ci-dessus le refuserait nommé si la cible est déjà peuplée de rencontres
   FBI sous un autre libellé). La 2ᵉ phase crée alors ses propres rencontres, distinctes, qui
   coexistent avec celles de la 1ʳᵉ phase déjà réparée.
4. **Vérifier** qu'aucune rencontre de la 1ʳᵉ phase ne reste absente ou doublée sous la 2ᵉ division
   (compte de rencontres par équipe cohérent avec le nombre de journées des deux phases).

**Ce que voit le gestionnaire** : pour l'essentiel, rien de spécifique à cette réparation — le même
écran Importer, la même file de traitement et le même vocabulaire (M3) qu'un dépôt ordinaire ; aucun
message ne nomme « M15 » ni le bug. Seule l'étape 1 (suppression ciblée avant re-dépôt) est un geste
hors écran courant, à faire une fois pour les rencontres dont l'adversaire a aussi été corrompu.

