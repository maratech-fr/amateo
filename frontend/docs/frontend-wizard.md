# Wizard — saisie des données (tranche 3, LIVRÉ)

Last verified @ 2026-08-31 (P2-51 PR-6 — le trou de transition sous-ligne fermé : `mutualisedTeammateLabel` confronté à `lib/sharedTraining.ts` (fusion groupe K + bloc, sans doublon) et son câblage dans `TeamsStep.tsx`/`PeriodStructure.tsx` ; la section « Entraînements mutualisés » du picker `SlotReservationModal.tsx` confrontée au code (préfixes `block:`/`group:`, dédoublonnage `teamSetSignature`, rail batch `sharedTrainingBlockId`/`sharedTrainingGroupId`). Reste du fichier non re-contrôlé ligne à ligne cette passe — un stamp REMPLACE, l'historique des passes précédentes vit dans git)

> ⚠️ **Réalité livrée — canonique.** Le draft "4 étapes" plus bas est **historique/superseded** : le wizard a été reconstruit dans `frontend/src/features/wizard` avec un flux plus granulaire, décidé avec le PO. Les sections 1+ ci-dessous ne décrivent plus l'implémentation.

## Flux réel (6 étapes, `WizardLayout` + registre `lib/steps.ts`)

1. **Équipes** — CRUD + classement (⚑ **largeurs de colonnes au foyer unique `lib/teamColumns.ts` depuis le 2026-08-21, P4-107** : les trois rendus des mêmes colonnes — en-tête du formulaire, formulaire, en-tête + lignes de la liste — avaient DÉJÀ divergé, `w-24` en haut contre `w-20` en bas, et les sélecteurs coupaient leur propre valeur, « Homn » pour « Homme ». Les largeurs sont dimensionnées sur la valeur la plus longue du catalogue, le nom est plafonné ; un test de parité garde les trois sites, la troncature réelle se mesure en Playwright) : groupées par **rang** (`PriorityTier` S/A/B/C/D, affiché « S · Fanion » … « D · Bonus » via `TIER_MEANING`, **home unique** `shared/lib/teamTiers`). Le rang se choisit **à la création** (sélecteur « Rang » du formulaire d'ajout) ; il ne se change **plus** via un dropdown en ligne sur la ligne équipe — la reclassification passe **uniquement** par le mode **Trier** (drag&drop inter-rang + monter/descendre), ordre **intra-rang** via `Team.tierOrder`, le tri en attente **committé au démontage** de l'étape (plus seulement au bouton « Terminer le tri »). Champs par équipe : **catégorie** (tranches d'âge **non genrées** — Baby/U5…U21/Senior/Vétéran/Loisir, source unique `App\Service\Basketball\CategoryCatalog`, seedée par `BasketballInit` + `AuthController::seedNewClub`), **genre** (Homme/Femme/Mixte, champ autonome depuis le dégenrage des catégories), **niveau de jeu** (`TeamLevel` : Élite/National/Régional/…/Loisir — lu+écrit via `TeamResource.level` + `TeamStateProcessor`), séances/sem. **Warning non bloquant** si équipe compétitive (niveau ≠ Loisir) classée rang D (Bonus). Le titre interne « Équipes » est retiré (le header sticky `WizardLayout` porte déjà « Étape 1/6 · Équipes »). Validation : ≥1 équipe. **Ajout sans nom** → erreur « Le nom de l'équipe est obligatoire » sous le formulaire + autofocus sur le champ (plus de no-op silencieux). **Amendé 2026-08-01 (P4-36, retour terrain)** : l'en-tête des colonnes ne vivait que dans la branche « au moins une équipe », alors que le formulaire d'ajout est AU-DESSUS — un club neuf saisissait à l'aveugle ; il **nomme désormais les champs du formulaire en permanence**. Le **rang s'affiche sur chaque ligne** (badge S/A/B/C/D) et les **flèches ↑↓ sont sorties du mode Trier** : déplacer dans son rang ne demande plus d'entrer en mode Trier, qui reste la seconde manière (drag & drop inter-rang). **Les colonnes sont triables** — rang par défaut, plus nom, catégorie, genre, niveau, séances. ⚠ **Le rang garde les SECTIONS, toute autre colonne bascule en LISTE PLATE** : appliquer le tri à l'intérieur de chaque section donnerait cinq listes triées séparément, ce qui ne répond pas à « je veux voir mes équipes par catégorie ». Le badge de ligne empêche d'y perdre l'information que les titres de section portaient, et les flèches disparaissent hors ordre par rang — elles déplacent AU SEIN d'un rang, notion sans objet dans une liste plate. **La catégorie n'a plus de valeur par défaut** (revue #347) : le catalogue réordonné faisait de « Vétéran » la pré-sélection de tous les clubs, si bien qu'un club de jeunes saisissant ses vingt équipes d'affilée les classait toutes en vétéran — la catégorie pilotant les contraintes d'âge. Un choix explicite est demandé, refusé à la soumission comme l'est déjà un nom vide. Le **niveau** se trie sur la **hiérarchie affichée** (`LEVELS`), pas sur le code de l'enum — l'alphabet plaçait Départemental avant Élite et les équipes sans niveau en tête. Le **sens** du tri vaut aussi pour le rang (les sections s'inversent), les **flèches se taisent tant qu'un ordre est en vol** (un clic juste après « Terminer le tri » repartait d'un cache périmé et annulait le glisser-déposer), la colonne **#** ne s'affiche qu'en ordre par rang (ailleurs elle se lirait comme un défaut), et le regroupement passe par `groupTeamsByTier` — son seau « Autres » garantit qu'**aucune équipe n'est perdue** quand son rang a dérivé. La catégorie se trie sur l'ordre **servi** par l'API, jamais sur son nom (« U11 » avant « U9 » alphabétiquement donnerait un second ordre de catégories dans l'application). ⚠ **L'ordre des catégories est SERVI, il ne se devine pas** : `SportCategoryStateProvider` trie sur `sortOrder`. Avant le 2026-08-01, RIEN ne triait — le provider générique n'ajoutait qu'un `ORDER BY e.id` sur des UUID v4, si bien que le sélecteur de catégories sortait dans un ordre **aléatoire, différent d'un club à l'autre**, et que la colonne `sortOrder` du catalogue était morte. Le catalogue va désormais du plus âgé au plus jeune (Vétéran → U5, puis Baby basket et Loisir) ; une migration renumérote les clubs existants, dont les catégories sont des COPIES faites à l'inscription. ⚠ Les catégories **personnalisées** sont rangées APRÈS le bloc standard (leur ordre relatif est conservé) : créées sans `sortOrder`, elles valaient 0 — le numéro de « Vétéran » — et sautaient donc en tête de tous les sélecteurs dès que le tri est devenu actif. Les nouvelles sont ajoutées à la fin (`SportCategoryStateProcessor::nextSortOrder`). **Découpage S/A/B/C/D partagé** : tout sélecteur qui liste des équipes (contraintes « Équipe »/« Cible », coachs, matchs `FixtureFormDialog`/`ImportFbiDialog`) réutilise ce classement via `shared/components/ui/TeamSelect` (optgroups par rang, même ordre que l'étape équipes) — reclasser une équipe met à jour l'ordre dans **tous** les sélecteurs. **Sous-ligne des liens (P2-27 → P2-45 → P2-51 PR-6)** : sous le nom d'une équipe, en rang comme en liste plate, se lit « **Mutualisée avec …** » (membre d'un `SharedTrainingGroup` **OU** d'un `SharedTrainingBlock` du **SOCLE**, portée saison — `TeamsStep` ne travaille jamais une période ; les deux sources sont fusionnées **sans doublon** par le helper pur `mutualisedTeammateLabel`, `lib/sharedTraining.ts` ; co-équipières **nommées**, jamais un pictogramme) **· Passerelle avec X (Préféré)** (P2-45, 2026-08-22 — l'intensité est SERVIE par `useTeamLinks`, régime 1, jamais recalculée). Une équipe **sans groupe ni passerelle** ne gagne AUCUN texte (densité nominale). **Affordance « Liens de {équipe} »** — icône `Link2` en fin de ligne, à côté de la corbeille — ouvre `TeamLinksModal` (`features/wizard/steps/`) : deux sections, **PASSERELLES puis MUTUALISATION**, filtrées sur l'équipe d'ouverture (pré-cochée dans un nouveau groupe via `initialTeamId`). La section passerelles est `TeamLinksSection` **extraite de `HabitsLinksDialog`** (`features/matches/` — le dialog la RECOMPOSE, comportement `/matchs` inchangé) ; en SAISON elle est ÉDITABLE (créer/changer l'intensité/supprimer), en période/vacances **LECTURE SEULE** (tranchage fondateur : `TeamLink` est de la structure de SAISON — la liste s'affiche avec son intensité, mais la raison est DITE à l'écran « les passerelles se déclarent au niveau de la saison », jamais une section grisée muette). La section mutualisation est **`SharedTrainingBlockPanel` depuis le 2026-08-31 (P2-51 PR-4, D13 : UNE seule notion)** — vocabulaire écran « groupe à mutualiser », 2..10 équipes, **liste déroulante** du nombre de séances communes bornée `1..min(séances effectives des membres)` (borne FAIL-SAFE calculée par `lib/sharedTrainingBlock.ts`, le serveur reste juge — la garde Σ du processor s'affiche verbatim), multi-appartenance libre (badge informatif « déjà dans N groupes »), écrivant sur le socle (`schedulePlanId` null en saison). L'ancien `MutualisationPanel` (groupes K) est **DÉMONTÉ de la modale** — le modèle K vit sous le capot (contrat + seed) jusqu'au lot de nettoyage PR-7. **Trou de transition FERMÉ par PR-6 (2026-08-31)** : la sous-ligne « Mutualisée avec … » des lignes d'équipes fusionne désormais groupe K ET bloc sans doublon (`mutualisedTeammateLabel`, ci-dessus §1) — un bloc déclaré via le nouveau panneau y apparaît immédiatement. Le picker de l'onglet **Réserver** (`SlotReservationModal`) offre aussi le bloc, dans la MÊME section que le groupe K (détail item 4 ci-dessous) ; le geste **« Déplacer le groupe »** sur le planning (§6.7 de `frontend-spec.md`) complète les 3 gestes. **`MutualisationPanel` (internes, déménagés de l'onglet Contraintes)** : cocher les candidates (équipes en pause exclues) fait apparaître, dès la première coche (l'équipe cochée = **ancre**), un bloc « **Équipes liées** » puis, replié derrière « Afficher toutes les équipes », le reste — `splitByLinks`/`teamsLinkedTo` (`lib/sharedTraining.ts`) est un ORDRE D'AFFICHAGE porté par les passerelles SERVIES (`useTeamLinks`, régime 1), jamais une permission ; sans passerelle pour l'ancre, liste plate (toutes visibles). Pré-validation **FAIL-SAFE, pas un miroir déclaré** : K plafonné au plus petit `sessionsPerWeek` effectif (override période inclus, miroir de `SharedTrainingGroupStateProcessor::effectiveSessionsPerWeek`) ; > 3 cochées = avertissement, jamais blocage (le serveur en accepte 10) ; `SharedTrainingGroupStateProcessor::assertDeclarationValid` reste seul juge, son 422 est géré/affiché (`apiErrorMessage`). Modifier (crayon → `PUT`) ou supprimer (`ConfirmDialog`) un groupe existant. Une équipe déjà membre d'un AUTRE groupe de la portée est **grisée, raison en texte** (« déjà mutualisée avec … »).
**Supprimer une salle, une équipe ou un coach (P3-16, 2026-08-18)** — le geste reste un **hard delete** en cascade, mais la modale (`DeleteConfirm`) annonce d'abord ce qu'il détruit, **compté par le SERVEUR** (`GET /api/{venues|teams|coaches}/{id}/deletion-impact`). ⚑ Les comptes ET les libellés viennent du serveur : gardés côté écran, une famille ajoutée à la cascade aurait disparu de la modale faute de traduction — et l'écran ne peut de toute façon pas voir les matchs, les contraintes ni les séances des autres plannings (avant ce lot il annonçait 2 familles sur 10 pour un gymnase). Trois précisions décident du geste : le **refus** du périmètre engagé (une équipe qui joue en compétition n'est pas supprimable — l'écran ne l'offre plus), le nombre de séances touchées vivant dans le planning **en vigueur** (les plannings terminés passent en « périmé », `ResourceChangeStaleScheduleListener`), et les **matchs déjà déclarés à la fédération** qui perdront leur salle — annoncés, jamais bloquants (DOC-2 : un gymnase qui ferme, ça arrive ; le match redevient « à placer »). La confirmation reste **désactivée** tant que l'impact n'a pas répondu, et un impact illisible se DIT au lieu de passer pour un impact vide. **La suppression d'un CRÉNEAU suit la même règle (2026-08-18)** : elle annonce ses DEUX épinglages — la réservation **et le verrou HARD qu'elle a matérialisé**, que l'écran taisait. Ses enfants ne citent jamais l'id du créneau : ils s'y rattachent par le triplet (gymnase, jour, heure) **et par la couche**, donc l'impact est borné à la couche du créneau — un créneau de période n'annonce ni n'emporte l'épinglage du planning principal. Plus aucun compte de suppression n'est dérivé du cache : la prop locale de `DeleteConfirm` a disparu avec son dernier appelant. ⚠ Reste dérivé du cache, et c'est légitime : le compteur de la confirmation de **déplacement** d'un créneau — un autre geste, qui ne détruit rien en cascade.

2. **Gymnases** — CRUD + **grille hebdo cliquable par gymnase** (⚑ **la vue s'ouvre sur la bande UTILE depuis le 2026-08-21, P4-107** : la plage reste 08:00→23:00 — on y crée des créneaux au clic, rogner rendrait 09:00 inatteignable et P4-37 interdit de masquer ce qui existe — mais le défilement est positionné une heure avant le premier créneau du gymnase affiché. Instantané (`useLayoutEffect`, jamais `smooth` : au montage il n'y a aucun saut à adoucir), rejoué au changement de gymnase, **jamais** repris après un défilement de l'utilisateur ; aucune annonce lecteur d'écran — le DOM ne change pas, et la gouttière d'heures collante dit déjà qu'on n'est pas au début) (`VenueAvailabilityGrid`), **graduée par tranches de 15 min** (#131), couvrant les **sept jours** (lundi→dimanche) de **08h à 23h** (`lib/weekGrid.ts` — source unique de géométrie partagée avec la grille « Réserver » et la grille de période) : clic sur une case = pose un créneau `VenueTrainingSlot` (barre « À poser : [durée] — cliquez la grille pour ajouter un créneau **d'entraînement** » — le mot seul devenait ambigu depuis que les plages match s'affichent dans la même grille), clic sur un créneau = ouvre l'éditeur. **Frontières de JOUR épaissies + pause méridienne 12h-14h en fond rosé** (2026-08-05 — isoler un jour d'un coup d'œil ; teinte de FOND seulement, les créneaux posés à z-10/fond opaque gardent leur couleur — vaut pour la grille Gymnases, la grille de période qui la réutilise, et la grille Réserver). **Défilement interne borné** (`max-h-[min(70vh,40rem)] overflow-auto`, en-têtes de jours + gouttière d'heures + coin figés en sticky, empilement strict créneaux < gouttière < jours < coin — à égalité c'est l'ordre du DOM qui tranche ; **même traitement pour la grille « Réserver »**, qui partage la géométrie) · **la plage verticale est l'UNION de 08h→23h et de ce que les créneaux occupent** : un créneau hors plage n'est ni relogé ni tronqué, la grille s'étend (deux tentatives ratées avant celle-là — laisser la ligne libre affichait un créneau de 07:00 à 22:45, la BORNER le posait sur la première ligne où il recouvrait des cellules cliquables et masquait un créneau légitime) plutôt que de pousser la page (P4-37, 2026-08-01 — la grille n'avait aucune borne verticale et s'arrêtait au samedi/22h, alors qu'un créneau du dimanche ou de 22h-23h existait déjà en base, servi au solveur, invisible à l'écran ; `ReservationGrid` avait le même défaut sur l'axe jour — un `findIndex` rendant -1 sur un dimanche en SUPPRIMAIT le créneau plutôt que de le rendre, corrigé par la même source unique) — **les fenêtres d'accès match entrent dans cette même union** (2026-08-04), sans quoi une fenêtre hors 08h–23h rejouerait le `grid-row` négatif, en pire : un fantôme relogé ment sans qu'on puisse le cliquer pour s'en apercevoir. **Les fenêtres d'accès match du gymnase s'affichent en FANTÔME sur la grille** (2026-08-04, retour terrain : on ajoutait une fenêtre et il ne se passait rien à l'écran — elle ne vivait que dans la liste texte sous la grille) : bande hachurée `aria-hidden` **non interactive** (`pointer-events-none`), rendue à `z-0` donc **toujours derrière un créneau posé**, et le clic la **traverse** pour atteindre la cellule vide — un entraînement peut être accordé DANS la plage match, la case doit rester posable. Prop `matchWindows` **optionnelle, vide par défaut** : une fenêtre est saison-globale (aucun `schedulePlanId` sur `VenueMatchWindow`), donc l'éditeur de PÉRIODE ne la passe pas. Légende (« accès match modifiable en bas de l'écran uniquement » — elle DIT où le geste se trouve, elle ne se contente pas d'interdire) affichée **seulement s'il y a une fenêtre** : une hachure sans légende est un motif muet. Aucune perte pour un lecteur d'écran : `MatchWindowsEditor` liste les mêmes fenêtres en texte intégral juste dessous. **Un créneau qui tombe un jour de fermeture datée est barré + libellé** (P2-22 PR 2, `closures` optionnelles/vide par défaut sur `VenueAvailabilityGrid` — la grille de SAISON n'a rien à montrer, seule la grille de PÉRIODE passe des fermetures) : `line-through opacity-60`, texte visible sous l'heure (« Indispo du 1/5 au 10/5 — titre »), jamais une bande de remplacement — grain JOUR strict (`closureForSlot` lit `weekdays`, une appartenance, pas un calcul de dates). Le nom accessible du bouton CONTIENT le texte visible (WCAG 2.5.3), fermeture comprise, et reste cliquable pour l'ajuster. **Panneau « Gymnases à proximité (FFBB) »** (P2-21 lot D) : salles proches du club triées par distance (rayon auto 3→20 km ou palier manuel), « + Ajouter » crée le gymnase (nom officiel + numéro fédéral + GPS, « renommez ensuite à votre main » — reconnaissance par NUMÉRO, jamais par nom) ; **« Associer à… » (2026-08-05)** lie une salle à un gymnase déjà saisi à la main (menu custom, pastilles DANS la liste) — pose `externalRef` + GPS sur LE SIEN sans jamais le renommer (règle NOMMER), sans quoi « + Ajouter » dupliquait. **Pastille de couleur AVANT le nom dans TOUS les sélecteurs de gymnase** (`VenueSelect` partagé, 6 sites — pastille du SÉLECTIONNÉ accolée au champ, `<select>` natif conservé ; limite assumée : les `<option>` natives ne portent pas de pastille, seule la liste custom d'Associer les a). Case **« Terrain divisible »** (`Venue.canSplit`) : décochée → le sélecteur de capacité **disparaît**, capacité forcée à 1 ; **la décocher alors que le gymnase porte des créneaux à capacité ≥ 2 ouvre une modale d'impact** (2026-08-14, patron « confirmation informée + cascade ») : liste des créneaux concernés (jour + heure) + nombre de réservations qui seront vidées — confirmer envoie `confirmSplitCascade: true` et le serveur **cascade atomiquement** (capacités → 1, libellés de groupe effacés, réservations ET leurs verrous HARD matérialisés supprimés — pas d'équipe « survivante » arbitraire, on re-réserve ; plannings marqués périmés par le listener ressources) ; sans le flag (API brute, modale contournée) le serveur refuse en **422 français nommant les créneaux** — le filet par défaut. Le sens créneau→gymnase était déjà gardé (`VenueTrainingSlotStateProcessor`, message francisé au même passage) ; l'avertissement de l'onglet Réserver **nomme désormais la cause** quand la limite vient de la divisibilité (« le gymnase X n'est pas déclaré divisible ») et non de la capacité du créneau ; cochée → sélecteur 1/2/3 équipes **dans la modale d'édition** (3 depuis le 2026-08-05 — certains terrains se divisent en 3 en travers, cas ADN ; toute la chaîne aval — backend, engine `ge=1`, récap, réservations — était déjà générique sur `capacity`) (la capacité ne se règle QUE là — le champ capacité de la barre « À poser » a été retiré, un nouveau créneau naît à 1) ; **choisir une capacité ≥ 2 affiche un hint** (`SharedSlotHint`, module `slotFields` partagé — les éditeurs de saison ET de période l'ont) : réservez les équipes qui occuperont le créneau (étape Contraintes, onglet Réserver), sans réservation le **système** les associera lui-même (P3-8 clos 2026-08-04 : guider, pas configurer). **Sous ce hint, un libellé de groupe optionnel** (`GroupLabelField`, module `slotFields` partagé, P2-17, 2026-08-14) — ≤ 40 caractères, placeholder « CEC3 » — **n'apparaît que sous les mêmes conditions que le hint** (capacité choisie ≥ 2) : redescendre à 1 équipe fait disparaître le champ et l'éditeur envoie `null` plutôt que de risquer le 422 backend (« capacité ≥ 2 » gardée serveur). Purement esthétique — titre la carte fusionnée de la vue planning côté gymnase (`frontend-spec.md` §6.2), ne rejoint jamais le payload solveur. Sélecteur de couleur doublé d'un champ hexa (pastille `VenueSwatch`). **Durées proposées de 45 min à 2h30 par pas de 15** (`lib/days.ts`, `DURATIONS`), affichées en heures (`shared/lib/formatDuration`). **Éditeur de créneau = modale** partagée (`Modal` + `useModalA11y`) — son select « Jour » lit la MÊME géométrie que la grille (les sept jours) : il portait sa propre liste amputée du dimanche, si bien qu'un créneau du dimanche s'y ouvrait sur un champ vide (P4-37) — titrée « Modifier le créneau » (croix haut-droite, Supprimer + Enregistrer alignés à droite) — #131. **Bornes de pose** (`lib/slotOverlap.ts`, `slotPlacementError` — foyer unique appelé par les quatre sites : création au clic et édition, en saison comme en période) : l'heure de début doit être **lisible** (le champ n'est ni `required` ni dans un `<form>` : vidé en cours de frappe il rendait `NaN`, que la borne de minuit laissait passer et sur lequel `findSlotConflict` ne trouvait plus aucun chevauchement — toute comparaison avec `NaN` étant fausse), un créneau doit finir **dans sa journée** (la borne est **minuit**, pas la fin de la grille — 22:00–23:30 est légitime), et le select « Durée » **complète la liste offerte de la valeur STOCKÉE autant que de la valeur éditée** (`durationOptions`, variadique — recevoir le seul état du select faisait disparaître l'option d'origine dès le premier changement), sinon un créneau dont la durée n'est pas au catalogue s'ouvrirait sur un champ vide — même règle que le select « Jour ». La **période a la même barre « À poser »** depuis P4-43 (2026-08-02) : les quatre sites règlent la durée, et le message de minuit la NOMME partout — le point de surcharge par appelant (`midnightMessage`), qui n'existait que pour la période à 90 min figées, a été retiré avec sa raison d'être. **Anti-chevauchement** : deux créneaux d'un même gymnase ne peuvent jamais se superposer le même jour — garde-fou front (message + blocage à l'ajout et à l'édition, soi-même exclu) **et** validation backend (`VenueTrainingSlotStateProcessor` → 422). **Section « Accès match » (P1-4 PR B)** sous la grille du gymnase sélectionné : l'éditeur des fenêtres d'accès MATCH (`MatchWindowsEditor`, composant du module matchs — même éditeur que le dialog « Accès match » de `/matchs`, une seule vérité) — le document qui donne les créneaux d'entraînement donne aussi les accès des jours de match, un document = un écran. ⚠ **Les libellés ne nomment pas « la mairie »** (2026-08-04) : c'est le cas du BCCL, pas de tous les clubs — conseil départemental, lycée, salle privée. La règle vaut pour les quatre sites user-facing du sujet (`VenuesStep`, `MatchWindowsEditor`, `MatchesPage`, `ConflictRadar` — dont le finding `ACCESS_WINDOW_LOST` dit désormais « L'accès match ne couvre plus ce match »). Validation : chaque gymnase a **≥1 créneau d'entraînement OU ≥1 fenêtre match** (P1-4 PR B — un gymnase loué pour les matchs seulement est légitime sans créneau ; l'exemption vaut au gate `useStepValidation` — socle ET période — comme au bandeau local, et se FERME si les fenêtres n'ont pas pu être lues : plus strict, jamais moins) — message affiché **sous le formulaire d'ajout**. **Géolocalisation d'un gymnase** (`VenueGeocodeField`, sous la barre de nom/couleur/divisible, P2-53 RMM-8, 2026-08-26) : champ adresse + « Localiser » (proxy `GET /api/geocode` → BAN, jamais un appel tiers direct) → liste de candidats **sans score chiffré** (le premier porte « Recommandé », un score faible « correspondance approximative ») → clic pose `address`+`latitude`+`longitude` (PUT partiel). Un gymnase déjà géolocalisé (import FFBB P2-20, ou géocodage antérieur) s'affiche replié « Localisé » et ne réécrit **rien** tant que « Modifier l'adresse » n'est pas cliqué — jamais d'écrasement silencieux. **Bouton de pied de page « Trajets entre gymnases »** (`useWizardFooter`, patron du « Trier » des Équipes, offert dès ≥2 gymnases géolocalisés ou non) ouvre `TravelMatrixModal` : première ouverture (aucune ligne de matrice) = **consentement passif à l'autofill IGN**, jamais lancé sans clic ; ensuite, la matrice **groupée « Depuis {gymnase} »**, deux colonnes (voiture / à pied), badge d'origine **AUTO/MANUEL** (icône + texte, jamais couleur seule) — éditer une valeur la passe MANUEL côté serveur, « Recalculer les trajets » préserve toujours les MANUEL ; les couples non résolus s'affichent « À saisir » avec leur raison (`missing_geo`/`routing_failed`/`budget_exceeded` — libellés d'une table EXHAUSTIVE, `TravelMatrixModal.tsx`, plus de repli muet possible sur un code inconnu — verdict servi, jamais deviné) ; les gymnases sans adresse sont nommés avec un lien direct vers leur fiche. La règle de trajet qui en résulte (`travelTime`) s'affiche en lecture seule côté Contraintes (§4, `TravelRuleNotice`) — détail moteur+contrat : `backend/docs/geo-api.md`.
3. **Coachs** — CRUD (+ `Coach.isEmployee` salarié, writable) + liens `TeamCoach` (coach/adjoint, bouton « Lier ») et `CoachPlayerMembership` (joueur). **Case « Véhiculé »** (`Coach.isVehicled`, P2-53 RMM-8, 2026-08-26 — défaut décoché) : détermine le barème de trajet appliqué aux enchaînements du coach (voiture s'il est véhiculé, à pied sinon) une fois la matrice de trajet renseignée (voir étape Gymnases) ; aide **persistante** en petit texte à côté de la case, pas un tooltip. Validation : ≥1 coach ; coach sans équipe = warning.
4. **Contraintes** — onglets **« Base » · « Bien-être »** (P2-28 PR 3, 2026-08-15 — les règles du SYSTÈME, avant les familles) puis par famille (TIME/DAY/FACILITY/COACH_AVAILABILITY — la capacité vit sur l'écran Gymnases, par CRÉNEAU ; la famille `FACILITY_CAPACITY` a été retirée le 2026-08-08, aucun chemin UI ne la créait). Cible = **Toutes les équipes / un groupe (tag) / une équipe** : un groupe pose une contrainte CLUB + `config.targetTag` que `ScheduleConstraintBuilder::resolveTagToTeamIds` éclate en N contraintes équipe (ex. groupe `JEUNE` → pas de créneau après 19h50). Onglet **« Réserver »** : fixer une équipe sur un créneau de dispo existant → **entité `Reservation` persistée serveur** (`POST/DELETE /api/reservations`, listée par `calendarEntryId` : NULL = plan de base, sinon overlay période — même stratification que les contraintes). **En mode période, les sélecteurs ne proposent que les gymnases et les équipes ACTIFS** (P2-15, décision fondateur) : ce qui sort du payload solveur ne doit pas être offert — le geste serait sans effet, et rien ne l'attrape (`OrphanPinGuard` ne regarde que gymnase/jour/heure : la génération PASSE et l'équipe épinglée n'a de séance nulle part). Un gymnase désactivé reste barré dans l'onglet Gymnases, où l'information a du sens. **Deux causes d'indisponibilité, un motif distinct (P2-37 D6, 2026-08-18 — sourcing recalé indispo informative PR2, même jour)** : `useActiveVenues(schedulePlanId, disabledVenueIds, fullyClosedVenueIds)` fond DÉSACTIVÉ et ENTIÈREMENT FERMÉ dans le même `disabledIds`, les DEUX désormais des champs SERVIS par `/calendar-entries/{id}/conflicts` (`disabledVenueIds`/`fullyClosedVenueIds`) que l'appelant passe tels quels — plus d'union locale dérivée des overrides (l'ancienne dérivation `activeLayer.disabledVenueIds` a disparu de ce chemin), mais l'écran dit le BON motif — le sélecteur de gymnase de « Réserver » annote « (fermé cette période) » plutôt que « (désactivé pour cette période) », et le bandeau/la modale de réservation reprennent le refus serveur mot pour mot (« indisponible sur toute la période — {titre} », D3) plutôt que le texte générique « désactivé ». ⚠ **Trois usages, trois règles** — CHOISIR (sélecteurs) → liste active ; NOMMER (libellés, groupement, valeur courante d'un formulaire d'édition) → liste COMPLÈTE, sinon un libellé rend « ? » et un select d'édition rend blanc sur une contrainte qui nomme pourtant un gymnase ; ATTEINDRE (geste correctif) → un gymnase désactivé qui porte ENCORE une réservation reste joignable dans « Réserver », **marqué et fermé à l'ajout** (on ne peut qu'y retirer) — sans quoi `OrphanPinGuard` refuse la génération en nommant un gymnase que l'écran capable d'enlever l'épinglage ne montre plus (cause racine backend : P3-20). **UI slot-centrée (comme l'écran Gymnases)** : une **grille hebdo par gymnase** (`ReservationGrid`, géométrie partagée `lib/weekGrid.ts`) affiche les créneaux de dispo du gymnase sélectionné, chacun montrant les **équipes réservées** (nom en texte) sur fond couleur-gymnase + le compteur `réservé/capacité`. **Libellé de groupe en badge** (P2-17, 2026-08-14) : un créneau qui porte un `groupLabel` non vide l'affiche en petite ligne au-dessus des équipes — **discret, sans fusionner les cases** (contrairement à la grille de planning, `frontend-spec.md` §6.2) : l'écran de saisie reste slot-centré, une case par créneau. **Plage horaire dynamique** : seules les heures contenant au moins un créneau du gymnase sont affichées (pas de scroll dans un matin vide) — spécifique à Réserver, l'écran Gymnases garde 08–23 pour la création (P4-37). **Cellules vides inertes** (on réserve un créneau existant, on n'en crée pas ici). **Un créneau d'un jour de fermeture est barré comme sur Gymnases** (P2-22 PR 2) mais suit une règle DIFFÉRENTE — désactivé (`disabled`) SANS réservation existante (rien à y ajouter), cliquable AVEC (le geste correctif : retirer un épinglage devenu orphelin) ; `ReservationPanel` referme la grille entière derrière `LoadErrorHint` si la lecture des conflits échoue (fail-closed — sans les fermetures la grille paraîtrait pleinement réservable, et un épinglage posé sur un jour fermé bloquerait la génération). Clic sur un créneau → **modale partagée** (`Modal` + `useModalA11y`, `SlotReservationModal`) : équipes déjà posées (retirables) + **picker `assignableTeams`** rendu via `TeamSelect` — optgroups par rang, fanion S→A→B→C→D, même découpage que partout (2026-08-04) — jusqu'à la capacité (`canSplit ? capacity : 1`), qui **exclut** les équipes déjà sur le créneau **et** celles ayant atteint leur plafond de `sessionsPerWeek` réservations (une équipe à N séances avec N réservations disparaît partout). **Section « Entraînements mutualisés » en tête du picker (P2-46 PR-3, 2026-08-23 ; étendue au bloc par P2-51 PR-6, 2026-08-31)** : une entrée par groupe K/bloc **POSABLE** (`offerableGroups`, mêmes règles pour les deux — capacité, membre en pause, K/`commonSessions` déjà atteint, raison NOMMÉE en liste si bloqué, jamais une absence muette) ; poser une entrée écrit d'un coup les réservations de TOUS ses membres (rail batch `POST /api/reservations/group`, `sharedTrainingBlockId`/`sharedTrainingGroupId`) et le groupe/bloc occupe alors SEUL la case. Blocs et groupes K partagent la MÊME section (D13) — **dédoublonnés sur l'ensemble d'équipes exact** (le bloc gagne ; PR-7 retirera le repli groupe côté backend). Le panneau est **grille seule** (épuré) — le récapitulatif rang-trié vit dans l'étape **Récap**. La génération inclut **toujours** les réservations dans le payload moteur comme verrous **HARD** (`ScheduleConstraintBuilder` les sérialise en `slotTemplates`). Distinct de `ScheduleSlotTemplate` qui, lui, stocke les **résultats** du solveur liés à un `Schedule` éphémère (l'ancien flux « store client → template au lancement » est supprimé — B2). Nom auto-généré, règle par défaut **PREFERRED**. **L'onglet « Mutualisation » a été RETIRÉ de cet écran (P2-45, 2026-08-22)** : la mutualisation — et les passerelles — vivent désormais dans l'étape **Équipes**, via la modale **« Liens de {équipe} »** par ligne (détail complet du panneau `MutualisationPanel` et de la section passerelles en **§1**). `ConstraintsStep` ne monte plus `MutualisationPanel` ni le mode `"mutualise"` ; rien n'est dupliqué ici. **Un club NEUF arrive avec 5 contraintes de base déjà listées** (P2-16, 2026-08-04 — `DefaultConstraintSeeder`, `source='onboarding_seed'`, toutes PREFERRED : jeunes ≤ 19h30 · baby ≤ 18h30 · EMB ≤ 19h · seniors ≥ 19h · pas le dimanche) : mêmes noms que ceux que l'écran génère, modifiables et supprimables comme le reste — semées à la création seulement, jamais au changement de saison. **La liste des contraintes est un TABLEAU depuis le 2026-08-21 (P4-107, 4ᵉ tranche)** — colonnes **Cible / Règle / Valeur / Niveau / actions**, `<table>` + `<thead>`/`<tbody>` sémantiques (pas une grille de `<div>`), regroupements conservés en lignes d'en-tête `<th scope="rowgroup">` (« Âge », « S · Fanion »… — `scope="rowgroup"` et non `colgroup`, sinon elles se glisseraient parmi les en-têtes de colonnes). ⚑ **Les colonnes Règle et Valeur sont dérivées de `config`, PAS du `name`** : elles lisent le foyer unique `features/planning/lib/describeConstraint.ts` (`constraintPredicateParts` / `constraintTarget`), celui-là même qui alimente le panneau de créneau du planning — un `name` est un texte libre qui peut être périmé ou copié d'une autre règle (c'est le défaut fondateur « SM2 au moins 1 seance a Mateo » lu sur un créneau U11). Une contrainte TIME rend ses **trois** bornes (n'en montrer qu'une mentirait par omission) ; une règle non descriptible fidèlement (clé inconnue, `forcedDays` LEGACY ambigu, gymnase supprimé) **retombe sur le nom complet**, jamais sur une cellule vide. **Édition** (#120) : chaque contrainte existante est **modifiable** (bouton crayon → formulaire partagé pré-rempli, `PUT /api/constraints/{id}`) — pas seulement ajout/suppression. Modes durs (toujours HARD, pas de sélecteur de règle) : FACILITY **« impose »** = `forcedVenueId` (l'équipe joue dans ce gymnase ; sur un groupe, le gymnase lui est **réservé** — interdit hors tag) ; DAY **« uniquement »** = `allowedDays` (**whitelist** : l'engine interdit tous les autres jours — ⚠ **pas** `forcedDays` qui ne veut dire QUE « au moins une séance ces jours-là », audit ENG-16). Modes soft : FACILITY préfère/évite, DAY à éviter (`forbiddenDays`). **Modes ajoutés (#127, toujours HARD)** : TIME **« Fini avant »** = `maxEndTime` (fin = début + durée ; HARD-only car le chemin soft ne lit que min/maxStartTime) ; FACILITY **« au moins N »** = `minAtVenueId` + `minAtVenueCount` (plancher de séances dans un gymnase, ≠ forçage ; fail-fast backend si N > séances/semaine, fail-soft engine sinon). Règle **implicite soft** `spacing` : l'engine préfère espacer les jours d'entraînement d'une équipe (jamais bloquant). **Onglets « Base » et « Bien-être »** (P2-28 PR 3, 2026-08-15 — remplacent l'encart replié P4-55 ; `ImplicitRulesPanel.tsx` exporte `ProductRulesPanel`/`WellbeingRulesPanel`, rendus comme PREMIERS onglets de la barre de familles — onglets de PRÉSENTATION : ils ne créent aucune contrainte, l'offre métier et la matrice moteur ne bougent pas). **« Base »** = les règles immuables en lecture seule (capacité, coach mono-gymnase — même gymnase autorisé D-14 —, coach-joueur, équipe non dédoublée, une séance/jour, + « vos saisies sont toujours honorées » et la cible de séances) — pas de titre de section, le nom de l'onglet porte l'information (décision fondateur). **`TravelRuleNotice`** (P2-53 RMM-8, 2026-08-26) s'y ajoute, à la suite de `ProductRulesPanel` : une entrée « Trajet entre gymnases · Actif », affichée **seulement si la matrice de trajet porte ≥1 ligne** (même dérivation que le backend — l'ACTIVATION reste DÉRIVÉE de la présence de matrice, jamais un `ImplicitRuleSetting` stocké). **L'INTENSITÉ, elle, est un vrai levier depuis PR-4** (2026-08-26) : sélecteur Préféré/Obligatoire lu de `venue_travel_rule_setting` (résolu, défaut Préféré) et posté au choix du gestionnaire (`useTravelRuleSetting`/`useUpdateTravelRuleSetting`), patron exact de l'intensité des passerelles (`TeamLinksSection`) — la copie dit le risque d'Obligatoire (« peut rendre le planning infaisable »), toujours visible même en Préféré. Lecture seule (span, pas de select) uniquement sur une saison archivée. **« Bien-être »** = les 4 règles réglables (P2-28) : sélecteur **Obligatoire/Objectif** (HARD/PREFERRED côté API), seuils bornés (repos 1-4, enchaînements 2-6), « Réinitialiser » (DELETE, visible hors défaut — le GET résolu expose `isDefault`), mention honnête « une règle en Objectif peut être dépassée — chaque dépassement est signalé au planning ». **Portée par période (P2-35 PR2, 2026-08-18)** : en mode période, le panneau règle et affiche la COPIE du plan (`schedulePlanId`, ADR-0002 inv. 5), derrière le même ancrage que « Réserver » (`PeriodAnchorGate` — le panneau attend l'ancre avant de s'afficher) ; hors période, la saison (`null`, comportement historique inchangé). La portée voyage dans le GET (query), le PUT (corps) et le DELETE (query), et dans la clé de cache react-query (`["wizard","implicit_rule_settings", planId ?? "season"]`) — deux portées montées sous le même `QueryClient` ne partagent jamais leurs valeurs. Une phrase de portée s'affiche en tête (« Ces réglages ne valent que pour cette période — copiés du planning de saison à sa création ») et chaque règle affiche la valeur de SAISON en repère (« Saison : Objectif, 1 jour ») — lecture seule, aucun bouton « revenir à la saison » ni indicateur calculé (décision fondateur explicite, posée puis retirée en cours de route : en période, exactement le même choix qu'en saison). **« Réinitialiser » change de sens en période** : il RE-COPIE la valeur de saison courante (jamais le défaut moteur) au lieu de supprimer la ligne — l'invariant « une période porte ses 4 règles » ne se brise jamais. Régime 1 strict : le front affiche le GET résolu et poste le choix, le serveur juge les bornes ; saison archivée = contrôles désactivés. **Boucle diagnostic → réglage** : un diagnostic « règle assouplie par vous » du planning porte « Ajuster cette règle » (`?step=constraints&rule=<ruleKey>&from=planning`) → l'étape s'ouvre sur Bien-être, la ligne visée scrollée et surlignée (patron P2-25/P4-95). Gels Vitest recalés des deux panneaux + du lien. Détail : `docs/architecture/constraint-matrix.md`, `frontend/docs/constraint-emission.md`.
5. **Récapitulatif** — compteurs + accordéons (composant partagé `AccordionSection`, accent au survol) + **gate pré-solveur** (`POST /api/constraints/validate`). **En mode période, il décrit LA PÉRIODE** (P2-15) : les compteurs comptent les équipes et gymnases ACTIFS pour cette période, une équipe en pause reste listée **barrée** (« en pause pour cette période » — on doit voir ce qu'on a mis de côté), et une lecture d'overrides ratée n'escamote rien : elle est annoncée — **charger et échouer se disent différemment** (un bandeau d'alerte qui se déclenche à chaque ouverture n'alerte plus de rien). Le **verdict** (`useStepValidation`) compte les MÊMES actifs que les compteurs : une période dont toutes les équipes sont en pause bloque la génération en disant quoi faire, au lieu d'afficher « Équipes 0 » et « Tout est prêt » dans la même vue. La règle vit en fonctions pures (`features/wizard/lib/activeLayer.ts`), les hooks `useActiveTeams`/`useActiveVenues` n'étant que le câblage. Listes enrichies : **équipes triées par rang** (`orderedTeams`) avec coach principal en *italique* + niveau de jeu ; **gymnases** avec pastille de couleur ; **coachs** en « Prénom (équipes) » (ex. « Maxime (SM1) », « Emerick (SF1, U15F1) ») + statut salarié/coach-joueur ; **accordéon « Réservations »** (rang-trié fanion→D, « équipe → gymnase · jour heure », chaque ligne **retirable**) — le récapitulatif déménagé de l'onglet Réserver ; c'est le seul écran qui liste TOUTES les réservations, donc le seul où le geste correctif (la poubelle) peut vivre. **Réservations NON SERVIES (P2-37 D5, 2026-08-18 — lecture recalée sur l'état EFFECTIF, indispo informative PR2, même jour)** : une ligne se signale et se marque retirable dès qu'AUCUNE génération ne la servira — prédicat LARGE `unservedReservationIds` (miroir déclaré de `OrphanPinGuard::unservedReservationIds`, parité gardée), union de : triplet gymnase/jour/heure ∉ grille (créneau supprimé/déplacé), gymnase hors service (`disabledVenueIds` servi — désactivé OU entièrement fermé), ou couple (gymnase, jour) fermé selon l'état EFFECTIF servi (`effectiveClosedWeekdays`, incident × masque manuel composé serveur) — plus les fermetures brutes désormais lues seulement pour le TITRE affiché, jamais la décision : un jour rouvert par le masque manuel (`dayOverrides` à `OPEN`) n'y figure plus, alors qu'une fermeture brute le couvrait encore. Le MOTIF affiché nomme la cause en clair — « gymnase fermé — {titre de la fermeture} » (fermeture prioritaire sur le mode, gymnase entièrement fermé ou jour fermé), « gymnase désactivé pour cette période » (override, une fois la fermeture écartée), ou « créneau supprimé ou déplacé » (repli) — jamais un simple surlignage muet. ⚠ **Aucune suppression automatique** : le serveur refuse la réservation À LA SOURCE (422) et l'escamote du payload, mais le front n'en efface aucune d'office — décision fondateur « on ne fait pas de modification passive, on alerte ». L'ancien prédicat étroit (triplet ∉ grille seul, `orphanReservationIds`) reste utilisé par l'onglet « Réserver » (sa grille boucle sur les créneaux, une réservation hors grille n'y a aucune case). **Accordéon « Mutualisation »** (P2-27 PR B) : une ligne par groupe (`sharedGroupLabel` — « SM1 + SM2 — 1 séance commune », pluriel géré), lecture seule ; en mode période ne compte que les groupes **de la période** (le provider renvoie socle+périodes, filtré sur `schedulePlanId`). **Avertissements « créneau partagé »** (P3-8 clos, 2026-08-04) : pour chaque créneau à capacité effective ≥ 2 (règle pure `sharedSlotStatuses`, créneaux ET réservations lus sur la couche courante), le récap affiche — **sans réservation** : bandeau neutre « le système associera les équipes lui-même » (information, le choix du système est légitime) ; **partiellement réservé** : bandeau warning « le système ne complétera pas ce créneau, la place restante restera vide » (ALIGN-07 : une réservation ferme le créneau ENTIER au système) ; plein : rien. **Jamais bloquant** — le gate `useStepValidation` n'en sait rien, c'est voulu. **Une fermeture de gymnase (contrainte `venue_closed`) se range sous SON gymnase** (P2-22 PR 2, `lib/constraintOrder.ts` — la même fonction `groupConstraints` partagée par cet écran et l'onglet Contraintes) : les contraintes FACILITY portent normalement leur gymnase dans `config` (préfère/évite/impose), mais une fermeture le porte dans `scopeTargetId` — un repli dédié l'y range plutôt que de la perdre sous « Autres » ; sa ligne affiche les dates (`du X au Y`, format court) en meta plutôt que l'enum de règle. ⚠ Vocabulaire : « le **système** », jamais « le solveur », dans tout libellé gestionnaire (règle 2026-08-04, tous écrans — la console superadmin garde « solveur »). Le bouton **« Continuer vers la génération »** avance vers l'étape 6 (ne lance plus directement). **Un solve FAILED s'explique à l'écran** (2026-08-05, « en prod ça ne passera pas ») : l'étape Génération charge les DIAGNOSTICS du schedule échoué (`useDiagnostics`) et affiche les messages ERROR du moteur + ses pistes (suggestions), au lieu du générique « une erreur est survenue » — qui ne reste que pour le timeout, l'échec de LANCEMENT (422 nommé conservé) et l'absence de diagnostic lisible.
6. **Génération** — étape **pleine largeur** (nav gauche masquée, retour via « ← Retour aux étapes »). Bouton **Lancer** → crée un `Schedule` DRAFT et lance la génération (les réservations sont déjà persistées serveur, collectées par le backend au build — plus de POST au lancement). **Écran d'attente animé** (mark du club pulse/fade, phrases défilantes, « 1 à 3 min ») + **poll de statut** (`useScheduleStatus`) + **garde anti-boucle** (timeout 5 min · statut FAILED · erreur POST → « Réessayer »). Dès qu'une version du plan courant est COMPLETED (ou en vol), le **planning s'affiche inline** dans cette même étape (`PlanningPage` embarqué, transition ajax) — la boucle de travail vit dans le flow, sans changer de route ; régénérer garde le planning affiché. **En mode période, cet écran embarqué est PORTÉ sur le plan de la période** (`scopePlanId`, corrigé le 2026-08-19 — bug fondateur : avant, il n'avait aucune portée et pouvait retomber sur le plan de SAISON) : il n'atterrit et ne liste QUE les versions de cette période, jamais le socle, et une période sans version affiche un état vide explicite plutôt qu'un repli — détail du mécanisme (câblage, ceinture de purge de sélection) : `specs/courantes/generation-pipeline.md` §2. **Diagnostics contextuels** (P4-40, 2026-08-01) : le panneau (`DiagnosticsPanel`) est **déplié d'emblée dès qu'il a quelque chose à montrer** dans ce contexte embarqué (une génération sans aucun diagnostic le laisse replié — sinon 20rem de largeur partaient afficher « le planning est propre » dans une hauteur embarquée déjà courte) — « sinon on risque de ne pas le voir si on n'est pas familier avec l'écran génération » — et ouvre en plus le **groupe le plus sévère présent** (ordre ERROR → WARNING → INFO → SUCCESS, prop `openMostSevere`). ⚠ **L'amorce est indexée sur l'identité de la VERSION affichée** (`seedToken`), jamais sur la forme des diagnostics : changer de version ré-amorce, filtrer un gymnase à version constante ne défait pas un repli manuel. Et tant que la lecture est en vol, le panneau dit « Lecture des diagnostics… » — charger n'est pas être propre ; en boucle de travail (`/planning` standalone) il reste **replié par défaut**, comportement inchangé (demande utilisateur d'origine).

**Chrome commun (`WizardLayout`) :** un **seul** titre d'étape, porté par le **header sticky** « Étape N/6 · … » — aucun `<h2>` interne dans les 6 steps (dédup). **Footer sticky** Précédent/Suivant collé au **bas réel** (colonne `min-h-[calc(100vh-5.5rem)] flex-col` + `mt-auto` → footer flush en bas de viewport même sur étape courte, épinglé au scroll ; `5.5rem` = header AppLayout 3.5rem + padding-haut du main 2rem). Nav gauche `md:w-44` — le **rail d'étapes est désormais la primitive partagée `step-rail`** (`shared/components/ui/step-rail.tsx`, RMM-2, 2026-08-23), de présentation pure : `WizardLayout` lui PASSE les états calculés (coche « étape terminée », verrou guidé/génération) et arme le voile dans son `onSelect` — le composant n'en sait rien. Repliable « Plein écran ». Sur l'étape Génération, `PlanningPage` reçoit `embedded` → grille raccourcie (`calc(100vh-24rem)`) pour ne pas passer sous le footer.

**Principes actés (divergent du draft historique) :**
- **Sauvegarde au fil de l'eau, par entité** (POST/PUT/DELETE immédiats, mutations TanStack). « Suivant » ne fait que **valider + naviguer**. → **pas** de draft-blob (`/api/clubs/{id}/draft` **abandonné**, jamais implémenté).
- **3 modes, mêmes écrans** : *libre* (club ayant déjà généré) · *onboarding guidé* (nav verrouillée vers l'avant) · **mode période (palier B)** = adaptation d'un `CalendarEntry`. Le mode vit dans le store wizard (`mode`, `calendarEntryId`, `startPeriodMode`/`exitPeriodMode`, **persist v4**), déclenché par le cockpit (radar « Adapter » → `startPeriodMode(entryId)` + navigate `/wizard`, atterrissage sur l'étape **Contraintes**).
  - **Mode période — chrome, deux lignes** (`WizardLayout.tsx`, amendé 2026-08-01, P4-38 — retour terrain : sur un titre long, la forme à une ligne d'origine accolait les dates au titre et passait sous les quatre actions alignées à droite). **Ligne 1** = QUOI + les gestes qui sortent du mode : icône + « Mode période — {titre de l'entrée} » à gauche (le titre porte déjà le repère de semaine pour une semaine-enfant, `cockpit/queries.ts:349` compose « {mère} — semaine du {lundi} » — le bandeau ne le réécrit pas) ; à droite le bouton de **suppression du planning de période** (masqué sur l'étape Génération) puis **« Retour à l'accueil »** (renommé depuis « Quitter », qui se lisait comme « abandonner ma saisie » alors que le geste ramène au cockpit). **Ligne 2** = QUAND + le geste qui reste dans le mode : « du {date} au {date} » à gauche, bouton **« Doléances »** à droite (#10 — ouvre la todo-list des doléances coachs, filtrée sur la semaine du plan courant ; affiché seulement si la période s'y prête et qu'une entrée mère existe). La ligne 2 ne se rend que si elle a quelque chose à montrer (dates chargées ou Doléances accessible), sinon pas de bande vide sous le titre.
  - **Mode période — la période POSSÈDE sa grille (#8, 2026-07-24)** : les `VenueTrainingSlot` de la période sont une **copie** du modèle de saison prise à la naissance du plan ; la construction de l'overlay ne s'unit **jamais** aux créneaux de la saison. Conséquence directe sur les écrans :
    - **Gymnases : ÉDITABLE**, ce n'est plus un résumé en lecture seule (`PeriodVenues`). Un **sélecteur de gymnase** (un panneau à la fois, exactement comme en saison — pas « une carte par gymnase, toutes montées », qui avait fait ressurgir durée figée, absence d'éditeur, mur de boutons et clavier cassé), **barre « À poser »** identique à celle de la saison (durée choisie AVANT le clic, son état vit dans le panneau parent pour survivre au changement de gymnase — le panneau enfant est remonté par `key`), pose de créneau au clic, ajustement dans la modale d'éditeur partagée, anti-chevauchement, suppression. Avant P4-43 la pose était figée à 90 min : un créneau de 2h demandait deux gestes, et une pose tardive était refusée pour une durée que le gestionnaire n'avait pas choisie. **Une fermeture de gymnase se DIT au grain JOUR** (P2-22 PR 2, 2026-08-14 — remplace l'ancien badge tout-ou-rien « INTERDIT cette période ») : `wizard/lib/venueClosures.ts` (régime 1 — `weekdays` est LU depuis `closures` de `/conflicts`, jamais dérivé) fabrique `closurePeriodLabel` (« Indispo ven–dim du 1/5 au 10/5 — titre », ou « Indispo toute la période du X au Y — titre » quand les 7 jours sont fermés) affiché à trois endroits : dans l'option du sélecteur de gymnase, dans le bandeau d'ensemble qui liste TOUS les gymnases fermés d'un coup, et en badge sur l'en-tête du panneau du gymnase sélectionné. Un créneau posé sur un jour que la grille n'affiche pas (donnée aberrante — la grille elle-même couvre désormais les sept jours, P4-37 ; le cas d'origine était le dimanche, traité à la cause) est rendu **visible et supprimable** plutôt que servi en silence au solveur.
    - **Par gymnase : DEUX contrôles, pas trois positions** (arbitrage fondateur). (1) Un **état** persisté *actif / désactivé* (`VenuePeriodOverride.mode = DISABLED`) qui ne touche **jamais** la grille — désactiver la gèle dans un `<fieldset disabled>` (inerte à la souris **et au clavier** ; un `pointer-events-none` ne bloquait que la souris), réactiver ne coûte donc aucune saisie déjà faite. (2) Deux **actions** destructives — « **Reprendre la grille** » (recopier celle du planning principal) et « **Vider la grille** » —, chacune confirmée en annonçant les réservations qu'elle emporte. **« Hériter » n'est pas un état** : c'est le défaut, l'absence de ligne (table *sparse*). « Vider » est une **action atomique**, jamais un `PUT` de mode `BLANK` (qui serait un no-op).
    - **Équipes** : activables / désactivables pour la fenêtre (`PeriodTeams`). **Coachs** : hérités en lecture seule (`ReadonlyCoaches`). **Repère « Mutualisée avec … · Passerelle avec … »** (P2-27, étendu P2-45, fusionné groupe K + bloc par P2-51 PR-6) posé aussi ici, sur les groupes ET blocs de **LA PÉRIODE** (`SharedTrainingGroup`/`SharedTrainingBlock` du `schedulePlanId` de l'entrée, fusionnés sans doublon par `mutualisedTeammateLabel`) et les passerelles servies — le poser seulement au socle mentirait par omission en reprise, précisément le terrain que P2-27 sert. La modale **« Liens de {équipe} »** (P2-45) s'ouvre AUSSI ici (`PeriodTeamsPanel`) : mutualisation ÉDITABLE (ancrée au plan de la période, `schedulePlanId`), passerelles en **LECTURE SEULE** (structure de saison — la raison est dite à l'écran).
  - **Mode période — contraintes** : les contraintes **propres à la période** sont scopées à l'entrée (`listConstraints({calendarEntryId})`, chaque contrainte créée porte `calendarEntryId`). ⚠ **Semaine ENFANT → résolution MÈRE avant de lire ET avant de créer** (P2-22 D5, 2026-08-14) : `ConstraintsStep`/`RecapStep` lisent `currentEntry?.parentEntryId ?? periodEntryId`, et une contrainte créée en mode période porte l'id de cette source, jamais l'id de l'entrée courante — sans ça une datée créée depuis une semaine enfant serait invisible à `PeriodConstraintSelector` (backend), qui lit lui-même la mère (`CalendarEntry::datedConstraintSourceId()`). C'est un miroir COMMENTÉ, pas déclaré au registre `FrontRederivationRegistryTest` (résolution d'id, pas un branchement sur un enum de contrainte). En plus, les contraintes **permanentes** du club sont **héritées** et rendues **dans leur onglet-famille**, chacune **bascule-able pour la fenêtre** via un `ConstraintPeriodOverride` *sparse* qui **dévie du défaut intelligent** : pas de ligne = le défaut s'applique. Défauts — *fermeture* : tout est gardé ; *reprise* : CLUB et COACH gardées, une contrainte TEAM gardée seulement si son équipe reprend, FACILITY abandonnée. Le plan de base et le `Constraint.isActive` ne sont **jamais** modifiés.
  - **Mode période — génération** : produit l'**overlay** (`POST /api/schedules {calendarEntryId}` ou régénère l'overlay existant), complétion keyée sur l'id de l'overlay, sélection dans le work-loop puis grille embarquée. Jamais `["me"]` invalidé (onboarding intact) ; invalide `["calendar-entries"]`.
- **2 modes de base, mêmes écrans** : *libre* vs *onboarding guidé* (nav verrouillée vers l'avant) selon **`me.seasonPlan.hasFinishedVersion`** — le plan de saison porte-t-il au moins une version terminée, c.-à-d. le club a-t-il déjà généré une fois. Le flag legacy `club.onboardingCompleted` **n'est plus lu pour le routage** : le critère est **dérivé** et **indépendant du pointeur**, si bien que rouvrir un planning ne renvoie pas le gestionnaire dans le wizard guidé. En guidé, `AuthGuard` verrouille sur `/wizard` **sauf `/profile` et `/club`** (constante `ONBOARDING_ALLOWED`) ; toute autre route (dont l'accueil `/`) redirige vers `/wizard`, et une tentative d'accès au **cockpit `/`** ajoute un toast éphémère « Lancez votre première génération d'abord ».
  ⚠️ **Écart connu, non tranché** : `/confidentialite` figure au menu compte mais **pas** dans `ONBOARDING_ALLOWED` — un club en onboarding qui y clique est renvoyé au wizard. Décision fondateur en attente.
- **Reprise sur le premier trou** (guidé) : à l'entrée du wizard on se positionne sur la première étape incomplète (pas d'équipe → Équipes, gymnase sans créneau → Gymnases, pas de coach → Coachs) via `store.jumpTo` ; tout rempli → on ne bouge pas. Les clubs ayant déjà généré arrivent sur le planning (AuthGuard).
- **Périmètre engagé (P2-7a)** — axe structurant (`CLAUDE.md` §7.1) : une équipe qui joue en compétition porte **`Team.isEngaged`**, **dit par le serveur** (`TeamResource.isEngaged`) et **jamais recalculé côté front**. À l'étape Équipes, sa **suppression** et son **changement de niveau de jeu** sont grisés — ses matchs sont déposés à la fédération. Une légende explicative n'apparaît que si au moins une équipe du club est engagée. Garde serveur : `EngagedTeamGuardTest`.
- **Tenant** : le front n'envoie **aucun** header `X-Club-Id` (club résolu serveur depuis le JWT — voir `backend/docs/TENANT.md`).
- **URIs API** : snake_case (`/api/team_coaches`, `/api/venue_training_slots`, `/api/sport_categories`, `/api/priority_tiers`…), **pas** les tirets du draft.
- **Différé (évolution)** : import Excel/CSV, mode démo, fermetures exceptionnelles, rôles non-admin & gestion des membres — suivis en roadmap (**P3-7**, **P2-4**, **P1-1**) ; la transition de saison est livrée ([`etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §1.7).
- Garanti par : `backend/tests/.../OnboardingFlowTest`, `backend/scripts/onboarding-smoke.sh`, `frontend/.../WizardPage.test`.

---

<details><summary>Historique — draft "4 étapes" (superseded, conservé pour trace)</summary>

## 1. Wizard Decision — Draft hybride, 4 étapes (HISTORIQUE)

### Décision

Le wizard initial passe de 6 étapes (v3 spec §9.1) à **4 étapes** consolidées.
Le gestionnaire arrive avec ses données en vrac (Excel, papier, mémoire). Le
wizard le guide sans le perdre, sauvegarde automatiquement, et valide en temps
réel.

**Pourquoi 4 et non 6 :** les étapes Club, Salles et Coaches étaient trop
fragmentées. Le gestionnaire saisit ses salles et ses entraîneurs dans la même
session "Infrastructure". Les priorités (tier list) sont des contraintes de
scheduling, pas des données d'onboarding — elles vont dans l'étape Contraintes.

### Les 4 étapes

| Étape | Nom | Contenu | Endpoints backend |
|-------|-----|---------|-------------------|
| 1 | Infrastructure | Salles (venues) + disponibilités + fermetures | `GET/POST /api/venues`, `GET/POST /api/venue-training-slots` |
| 2 | Ressources | Équipes (teams) + Entraîneurs (coaches) + import Excel moderne | `GET/POST /api/teams`, `GET/POST /api/coaches`, `GET/POST /api/team-coaches`, `POST /api/clubs/{id}/import-teams`, `GET /api/sport-categories` |
| 3 | Contraintes | Contraintes permanentes + priorités (tier list S/A/B/C/D) | `GET/POST /api/constraints`, `GET /api/priority-tiers` |
| 4 | Récapitulatif | Review global + validation Zod + submit | `PUT /api/clubs/{id}` (marquer `onboarding_completed`) |

> Référence endpoints : `specs/courantes/openapi-snapshot.json` (paths
> `\/api\/venues`, `\/api\/teams`, `\/api\/coaches`, `\/api\/constraints`,
> `\/api\/priority-tiers`, `\/api\/sport-categories`, `\/api\/clubs`).
> Référence contrôleurs : `../../backend/docs/backend-inventory.md` §2 (20
> ressources API Platform) et §3 (custom controllers).

### Mode démo

Club basket fictif pré-rempli accessible depuis l'étape 1. Génération en 30s
pour démontrer la valeur avant saisie des vraies données. Le bouton "Mode démo"
pré-remplit les 4 étapes avec des données fictives (Gymnase A, U13 Masculin,
etc.) et permet de soumettre directement.

### Guard de redirection

`clubs.onboarding_completed === false` → redirect `/wizard` (voir
`frontend-spec.md` ligne 68-69). Le guard s'active sur `/`, `/dashboard`,
`/schedules/:id`, `/teams`, `/priorities`, `/profile`. `/login` et `/register`
sont exemptés.

---

## 2. Step 1 — Infrastructure

### Objectif

Le gestionnaire saisit ses salles (venues), leurs disponibilités hebdomadaires
(tranches 15min, lun-sam), et les fermetures exceptionnelles.

### Données saisies

| Champ | Type | Validation Zod | Endpoint |
|-------|------|----------------|----------|
| Nom de la salle | `string` | `z.string().min(1).max(100)` | `POST /api/venues` body `name` |
| Adresse | `string` | `z.string().min(1)` | `POST /api/venues` body `address` |
| Disponibilités | `VenueSlot[]` | `z.array(VenueSlotSchema).min(1)` | `POST /api/venue-training-slots` |
| Fermetures | `VenueClosure[]` | `z.array(VenueClosureSchema)` | `POST /api/venues/{id}/closures` (gap — voir §7) |

### Schéma de validation Zod (step 1)

```typescript
// Illustration — pas un fichier .ts
const VenueSlotSchema = z.object({
  venueId: z.string().uuid(),
  dayOfWeek: z.number().int().min(1).max(6),   // 1=lun, 6=sam
  startTime: z.string().regex(/^\d{2}:\d{2}$/), // "18:00"
  endTime: z.string().regex(/^\d{2}:\d{2}$/),
});

const VenueClosureSchema = z.object({
  venueId: z.string().uuid(),
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/), // "2026-07-14"
  reason: z.string().max(200).optional(),
});

const Step1Schema = z.object({
  venues: z.array(z.object({
    name: z.string().min(1).max(100),
    address: z.string().min(1),
  })).min(1, "Au moins une salle est requise"),
  slots: z.array(VenueSlotSchema),
  closures: z.array(VenueClosureSchema),
});
```

### UX

- Grille de disponibilité visuelle : 6 colonnes (lun-sam) × tranches 15min
- Click sur une cellule = toggle disponible/indisponible
- Bouton "Ajouter une salle" ouvre un formulaire inline
- Bouton "Importer CSV" pour import en masse de salles
- Validation temps réel : bordure rouge sur champ invalide + message `role="alert"`

### Test Cases

#### Test Cases — Step 1

**Given** le gestionnaire "Maxence Dupont" (`maxence.dupont@example.com`) est sur l'étape 1 Infrastructure avec zéro salle saisie
**When** il clique sur "Suivant"
**Then** un message d'erreur s'affiche avec `role="alert"` : "Au moins une salle est requise"
**And** le wizard reste sur l'étape 1

**Given** le gestionnaire saisit la salle "Gymnase A" avec l'adresse "12 Rue du Sport, Lyon" et ajoute une disponibilité le lundi 18:00-19:30
**When** il clique sur "Suivant"
**Then** l'étape 2 Ressources s'affiche
**And** `POST /api/venues` a été appelé avec `{ name: "Gymnase A", address: "12 Rue du Sport, Lyon" }`
**And** `POST /api/venue-training-slots` a été appelé avec le créneau lundi 18:00-19:30
**And** `step1Completed` passe à `true` dans le state

**Given** le gestionnaire a saisi "Gymnase A" et "Gymnase B" avec des disponibilités
**When** il ajoute une fermeture pour "Gymnase A" le 2026-07-14 (Fête Nationale)
**Then** la fermeture apparaît dans la liste des fermetures de "Gymnase A"
**And** l'auto-save déclenche après 2s d'inactivité (voir §7)

**Given** le gestionnaire est sur l'étape 1 avec "Gymnase A" saisi
**When** il clique sur "Mode démo"
**Then** les salles "Gymnase A", "Gymnase B", "Gymnase C" sont pré-remplies avec des disponibilités fictives
**And** le wizard reste sur l'étape 1 pour validation manuelle

---

## 3. Step 2 — Ressources

### Objectif

Le gestionnaire saisit ses équipes (teams) et ses entraîneurs (coaches), les
associe via TeamCoach, et peut importer un fichier Excel avec mapping de
colonnes moderne.

### Données saisies

| Champ | Type | Validation Zod | Endpoint |
|-------|------|----------------|----------|
| Équipe — nom | `string` | `z.string().min(1).max(100)` | `POST /api/teams` body `name` |
| Équipe — catégorie | `SportCategory` | `z.string().uuid()` | `POST /api/teams` body `sportCategoryId` |
| Équipe — tier | `PriorityTier` | `z.enum(["S","A","B","C","D"])` | `POST /api/teams` body `priorityTierId` |
| Coach — prénom | `string` | `z.string().min(1)` | `POST /api/coaches` body `firstName` |
| Coach — nom | `string` | `z.string().min(1)` | `POST /api/coaches` body `lastName` |
| Coach — email | `string` | `z.string().email()` | `POST /api/coaches` body `email` |
| Assignation TeamCoach | `TeamCoach` | `z.object({ teamId, coachId, role })` | `POST /api/team-coaches` |

### Import Excel moderne

L'import Excel (`POST /api/clubs/{id}/import-teams`) est modernisé côté frontend :

1. **Upload fichier** : drag-and-drop ou click pour sélectionner un `.xlsx`
2. **Column mapping** : le frontend détecte les colonnes du fichier et propose
   un mapping automatique vers les champs `Team` (nom, catégorie, tier). Le
   gestionnaire peut ajuster le mapping manuellement.
3. **Paste-rows** : le gestionnaire peut coller des lignes directement depuis
   Excel (clipboard) sans upload de fichier. Le frontend parse les lignes
   tab-separated.
4. **Preview** : tableau de prévisualisation avant soumission, avec lignes
   valides en vert et lignes en erreur en rouge (`role="alert"` sur les
   erreurs).
5. **Submit** : `POST /api/clubs/{id}/import-teams` avec le fichier mappé.

> Référence backend : `backend-inventory.md` §3 — `ImportController` accepte
> `file` (.xlsx) + `seasonId`, délègue à `FfbbExcelImporter`, retourne
> `{ created, skipped, errors }`.

### Schéma de validation Zod (step 2)

```typescript
// Illustration — pas un fichier .ts
const TeamSchema = z.object({
  name: z.string().min(1).max(100),
  sportCategoryId: z.string().uuid(),
  priorityTierId: z.string().uuid(),
  minSessionsPerWeek: z.number().int().min(1).max(7).optional(),
});

const CoachSchema = z.object({
  firstName: z.string().min(1).max(100),
  lastName: z.string().min(1).max(100),
  email: z.string().email(),
  isEmployee: z.boolean().optional(),
});

const TeamCoachSchema = z.object({
  teamId: z.string().uuid(),
  coachId: z.string().uuid(),
  role: z.enum(["head", "assistant"]),
  isRequired: z.boolean().default(true),
});

const Step2Schema = z.object({
  teams: z.array(TeamSchema).min(1, "Au moins une équipe est requise"),
  coaches: z.array(CoachSchema).min(1, "Au moins un entraîneur est requis"),
  assignments: z.array(TeamCoachSchema),
});
```

### UX

- Deux onglets dans l'étape : "Équipes" et "Entraîneurs"
- Onglet Équipes : liste + formulaire inline + bouton import Excel
- Onglet Entraîneurs : liste + formulaire inline + bouton import CSV
- Drag-and-drop d'un coach sur une équipe pour créer une assignation TeamCoach
- Validation temps réel sur chaque champ

### Test Cases

#### Test Cases — Step 2

**Given** le gestionnaire est sur l'étape 2 Ressources, onglet "Équipes", avec zéro équipe saisie
**When** il saisit l'équipe "U13 Masculin" avec la catégorie "U13" (`sportCategoryId` from `GET /api/sport-categories`) et le tier "B"
**Then** l'équipe apparaît dans la liste avec un badge vert "Validé"
**And** `POST /api/teams` a été appelé avec `{ name: "U13 Masculin", sportCategoryId: "<uuid>", priorityTierId: "<uuid-tier-B>" }`

**Given** le gestionnaire est sur l'onglet "Entraîneurs" et saisit le coach "Maxence Dupont" avec l'email `maxence.dupont@example.com`
**When** il clique sur "Ajouter"
**Then** le coach apparaît dans la liste
**And** `POST /api/coaches` a été appelé avec `{ firstName: "Maxence", lastName: "Dupont", email: "maxence.dupont@example.com" }`

**Given** le gestionnaire a saisi l'équipe "U13 Masculin" et le coach "Maxence Dupont"
**When** il glisse le coach "Maxence Dupont" sur l'équipe "U13 Masculin" et sélectionne le rôle "Head Coach"
**Then** une assignation TeamCoach est créée
**And** `POST /api/team-coaches` a été appelé avec `{ teamId: "<uuid-u13>", coachId: "<uuid-maxence>", role: "head", isRequired: true }`
**And** l'équipe "U13 Masculin" affiche le badge "Head: Maxence Dupont"

**Given** le gestionnaire clique sur "Importer Excel" et sélectionne le fichier `equipes_saison_2026.xlsx`
**When** le frontend parse le fichier et détecte les colonnes "Nom", "Catégorie", "Niveau"
**Then** un écran de column mapping s'affiche avec les correspondances proposées : "Nom" → `name`, "Catégorie" → `sportCategoryId`, "Niveau" → `priorityTierId`
**And** le gestionnaire peut ajuster le mapping manuellement

**Given** le column mapping est validé et le gestionnaire clique sur "Confirmer l'import"
**When** `POST /api/clubs/{id}/import-teams` répond 200 avec `{ created: 8, skipped: 2, errors: [] }`
**Then** 8 équipes apparaissent dans la liste avec un badge vert
**And** un message de succès s'affiche : "8 équipes importées, 2 ignorées"
**And** si `errors` n'est pas vide, chaque erreur s'affiche avec `role="alert"`

**Given** le gestionnaire colle 5 lignes tab-separated depuis Excel dans le champ "Paste-rows"
**When** le frontend parse les lignes
**Then** un tableau de prévisualisation s'affiche avec 5 lignes
**And** les lignes valides ont un fond vert, les invalides un fond rouge avec `role="alert"`

---

## 4. Step 3 — Contraintes

### Objectif

Le gestionnaire saisit les contraintes permanentes de scheduling et définit les
priorités des équipes via la tier list drag & drop (S/A/B/C/D).

### Données saisies

| Champ | Type | Validation Zod | Endpoint |
|-------|------|----------------|----------|
| Contrainte — type | `enum` | `z.enum(["venue_exclusion", "coach_unavailability", "team_link", "max_consecutive_days", ...])` | `POST /api/constraints` body `type` |
| Contrainte — scope | `enum` | `z.enum(["global", "venue", "coach", "team"])` | `POST /api/constraints` body `scope` |
| Contrainte — params | `object` | `z.record(z.unknown())` (dépend du type) | `POST /api/constraints` body `params` |
| Contrainte — reason | `string` | `z.string().max(500)` | `POST /api/constraints` body `reason` |
| Tier assignment | `Record<teamId, tier>` | `z.record(z.enum(["S","A","B","C","D"]))` | `PUT /api/teams/{id}` body `priorityTierId` |

### Regroupement par scope/family

Les contraintes sont regroupées visuellement par famille :

| Famille | Types de contraintes | Couleur |
|----------|----------------------|---------|
| Salle | `venue_exclusion`, `venue_closure_recurring` | Bleu |
| Entraîneur | `coach_unavailability`, `coach_max_consecutive` | Vert |
| Équipe | `team_link`, `team_min_sessions`, `team_max_consecutive_days` | Orange |
| Globale | `max_daily_slots`, `rest_day_constraint` | Gris |

### Tier list drag & drop

- 5 colonnes : S, A, B, C, D (de la plus haute à la plus basse priorité)
- Les équipes créées à l'étape 2 apparaissent dans la colonne D par défaut
- `@dnd-kit` pour le drag-and-drop entre colonnes
- `PUT /api/teams/{id}` avec `priorityTierId` au drop
- `GET /api/priority-tiers` pour résoudre les UUIDs des tiers

### Schéma de validation Zod (step 3)

```typescript
// Illustration — pas un fichier .ts
const ConstraintSchema = z.object({
  type: z.enum([
    "venue_exclusion",
    "coach_unavailability",
    "team_link",
    "max_consecutive_days",
    "rest_day_constraint",
  ]),
  scope: z.enum(["global", "venue", "coach", "team"]),
  params: z.record(z.unknown()),
  reason: z.string().max(500).optional(),
});

const TierAssignmentSchema = z.record(
  z.string().uuid(),
  z.enum(["S", "A", "B", "C", "D"])
);

const Step3Schema = z.object({
  constraints: z.array(ConstraintSchema),
  tierAssignments: TierAssignmentSchema,
});
```

### UX

- Accordion par famille de contraintes (Salle, Entraîneur, Équipe, Globale)
- Bouton "Ajouter une contrainte" dans chaque accordion
- Tier list en bas de page, draggable
- Validation temps réel sur les paramètres des contraintes

### Test Cases

#### Test Cases — Step 3

**Given** le gestionnaire est sur l'étape 3 Contraintes avec l'équipe "U13 Masculin" dans la colonne D
**When** il glisse "U13 Masculin" de la colonne D vers la colonne B
**Then** l'équipe "U13 Masculin" apparaît dans la colonne B
**And** `PUT /api/teams/{id}` a été appelé avec `{ priorityTierId: "<uuid-tier-B>" }`
**And** l'auto-save déclenche après 2s

**Given** le gestionnaire ouvre l'accordion "Entraîneur" et clique sur "Ajouter une contrainte"
**When** il sélectionne le type `coach_unavailability`, le coach "Maxence Dupont", et les créneaux indisponibles : mercredi toute la journée
**Then** la contrainte apparaît dans l'accordion "Entraîneur" avec le libellé "Maxence Dupont — Indisponible le mercredi"
**And** `POST /api/constraints` a été appelé avec `{ type: "coach_unavailability", scope: "coach", params: { coachId: "<uuid>", dayOfWeek: 3 }, reason: "Indisponible le mercredi" }`

**Given** le gestionnaire ouvre l'accordion "Salle" et ajoute une contrainte `venue_exclusion` pour "Gymnase A" le samedi
**When** il valide la contrainte
**Then** la contrainte apparaît dans l'accordion "Salle" avec le libellé "Gymnase A — Exclu le samedi"
**And** `POST /api/constraints` a été appelé avec `{ type: "venue_exclusion", scope: "venue", params: { venueId: "<uuid-gymnase-a>", dayOfWeek: 6 } }`

**Given** le gestionnaire a saisi 3 contraintes et assigné les tiers de 8 équipes
**When** il clique sur "Suivant"
**Then** l'étape 4 Récapitulatif s'affiche
**And** `step3Completed` passe à `true`

---

## 5. Step 4 — Récapitulatif

### Objectif

Le gestionnaire révise l'ensemble des données saisies, corrige si nécessaire,
et soumet le wizard pour marquer l'onboarding comme terminé.

### Données affichées

| Section | Contenu | Source |
|---------|---------|--------|
| Salles | Liste des venues + dispos + fermetures | `wizardState.step1Data` |
| Équipes | Liste des teams + catégorie + tier | `wizardState.step2Data` |
| Entraîneurs | Liste des coaches + assignations | `wizardState.step2Data` |
| Contraintes | Liste groupée par famille | `wizardState.step3Data` |
| Tier list | Récapitulatif visuel S/A/B/C/D | `wizardState.step3Data` |

### Validation Zod globale

Avant soumission, une validation Zod globale s'exécute sur l'ensemble des 4
étapes réunies :

```typescript
// Illustration — pas un fichier .ts
const WizardDataSchema = z.object({
  step1: Step1Schema,
  step2: Step2Schema,
  step3: Step3Schema,
});

// À l'étape 4, on valide tout :
const result = WizardDataSchema.safeParse(wizardState.allData);
if (!result.success) {
  // Afficher les erreurs par étape avec role="alert"
}
```

### Soumission

1. Validation Zod globale → si erreurs, afficher par étape avec `role="alert"`
2. Si valide, `PUT /api/clubs/{id}` avec `{ onboardingCompleted: true }`
3. Redirection vers `/dashboard`

> **Correction (ex-gap G7, fermé) :** le champ **existe** dans l'OpenAPI en
> camelCase `Club.onboardingCompleted` (boolean, default false) — l'ancien
> claim de gap (snake_case `onboarding_completed` absent) était une erreur de
> doc. Décisions tracées dans [`etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §2.

### UX

- Vue récapitulative en lecture seule, organisée par étape
- Bouton "Modifier" sur chaque section → retour à l'étape correspondante
- Bouton "Générer le planning" (submit) en bas de page
- Si erreurs de validation globale : panneau d'erreurs en haut avec `role="alert"`

### Test Cases

#### Test Cases — Step 4 et Intégration

**Given** le gestionnaire a complété les étapes 1-3 avec "Gymnase A", l'équipe "U13 Masculin", le coach "Maxence Dupont", et 2 contraintes
**When** il arrive sur l'étape 4 Récapitulatif
**Then** un récapitulatif s'affiche avec 4 sections : Salles (1), Équipes (1), Entraîneurs (1), Contraintes (2)
**And** chaque section a un bouton "Modifier"

**Given** le gestionnaire est sur l'étape 4 et le récapitulatif est complet
**When** il clique sur "Générer le planning"
**Then** la validation Zod globale s'exécute sur `WizardDataSchema`
**And** si valide, `PUT /api/clubs/{id}` est appelé avec `{ onboarding_completed: true }`
**And** la redirection se fait vers `/dashboard`

**Given** le gestionnaire est sur l'étape 4 et l'étape 2 a 0 équipe (invalidation Zod)
**When** il clique sur "Générer le planning"
**Then** la validation Zod globale échoue
**And** un panneau d'erreurs s'affiche en haut avec `role="alert"` : "Étape 2 — Ressources : Au moins une équipe est requise"
**And** le bouton "Modifier" de l'étape 2 est mis en évidence

**Given** le gestionnaire clique sur "Modifier" sur la section Contraintes
**When** le wizard revient à l'étape 3
**Then** les données précédemment saisies sont restaurées depuis `wizardState.step3Data`
**And** `currentStep` passe à 3
**And** le focus se déplace sur le titre de l'étape 3

**Given** le gestionnaire a quitté le wizard à l'étape 2 sans soumettre, puis rouvre `/wizard` dans une nouvelle session
**When** le wizard se charge
**Then** les données de l'étape 1 sont restaurées depuis le draft serveur (ou sessionStorage en fallback)
**And** `currentStep` est restauré à 2
**And** un message discret s'affiche : "Brouillon restauré"

---

## 6. State Machine — useReducer

### Type WizardState

```typescript
// Illustration — pas un fichier .ts
type WizardStep = 1 | 2 | 3 | 4;

type DraftStatus = "idle" | "dirty" | "saving" | "saved" | "error";

interface WizardState {
  currentStep: WizardStep;
  visited: Set<WizardStep>;
  completed: Record<WizardStep, boolean>;
  draftStatus: DraftStatus;
  step1Data: Step1Data | null;
  step2Data: Step2Data | null;
  step3Data: Step3Data | null;
  errors: Partial<Record<WizardStep, ZodError>>;
  isDemoMode: boolean;
}

const initialWizardState: WizardState = {
  currentStep: 1,
  visited: new Set([1]),
  completed: { 1: false, 2: false, 3: false, 4: false },
  draftStatus: "idle",
  step1Data: null,
  step2Data: null,
  step3Data: null,
  errors: {},
  isDemoMode: false,
};
```

### Actions et transitions

| Action | Payload | Transition | Guard |
|--------|---------|------------|-------|
| `NEXT` | — | `currentStep++`, `visited.add(currentStep+1)` | `currentStep < 4` && `completed[currentStep] === true` |
| `PREV` | — | `currentStep--` | `currentStep > 1` |
| `JUMP` | `target: WizardStep` | `currentStep = target` | `visited.has(target)` (navigation libre vers étapes visitées) |
| `UPDATE_DATA` | `{ step, data }` | `step{N}Data = data`, `draftStatus = "dirty"` | — |
| `MARK_COMPLETED` | `{ step }` | `completed[step] = true` | Validation Zod de l'étape passe |
| `SET_ERRORS` | `{ step, errors }` | `errors[step] = errors` | — |
| `CLEAR_ERRORS` | `{ step }` | `delete errors[step]` | — |
| `SET_DRAFT_STATUS` | `status: DraftStatus` | `draftStatus = status` | — |
| `LOAD_DRAFT` | `WizardState` | Remplace tout le state | — |
| `TOGGLE_DEMO` | — | `isDemoMode = !isDemoMode` | — |
| `RESET` | — | Retour à `initialWizardState` | — |

### Reducer

```typescript
// Illustration — pas un fichier .ts
function wizardReducer(state: WizardState, action: WizardAction): WizardState {
  switch (action.type) {
    case "NEXT":
      if (state.currentStep >= 4 || !state.completed[state.currentStep]) return state;
      const next = (state.currentStep + 1) as WizardStep;
      return { ...state, currentStep: next, visited: new Set([...state.visited, next]) };

    case "PREV":
      if (state.currentStep <= 1) return state;
      return { ...state, currentStep: (state.currentStep - 1) as WizardStep };

    case "JUMP":
      if (!state.visited.has(action.target)) return state;
      return { ...state, currentStep: action.target };

    case "UPDATE_DATA":
      return {
        ...state,
        [`step${action.step}Data`]: action.data,
        draftStatus: "dirty",
      };

    case "MARK_COMPLETED":
      return { ...state, completed: { ...state.completed, [action.step]: true } };

    case "SET_ERRORS":
      return { ...state, errors: { ...state.errors, [action.step]: action.errors } };

    case "CLEAR_ERRORS":
      const { [action.step]: _, ...rest } = state.errors;
      return { ...state, errors: rest };

    case "SET_DRAFT_STATUS":
      return { ...state, draftStatus: action.status };

    case "LOAD_DRAFT":
      return action.state;

    case "TOGGLE_DEMO":
      return { ...state, isDemoMode: !state.isDemoMode };

    case "RESET":
      return initialWizardState;

    default:
      return state;
  }
}
```

### Test Cases

#### Test Cases — State Machine

**Given** le state est `{ currentStep: 1, completed: { 1: false, 2: false, 3: false, 4: false } }`
**When** l'action `NEXT` est dispatchée
**Then** le state reste inchangé car `completed[1] === false` (guard échoue)

**Given** le state est `{ currentStep: 1, completed: { 1: true, 2: false, 3: false, 4: false } }`
**When** l'action `NEXT` est dispatchée
**Then** `currentStep` passe à 2 et `visited` contient `{ 1, 2 }`

**Given** le state est `{ currentStep: 3, visited: { 1, 2, 3 } }`
**When** l'action `JUMP` avec `target: 1` est dispatchée
**Then** `currentStep` passe à 1 car `visited.has(1) === true`

**Given** le state est `{ currentStep: 3, visited: { 1, 2, 3 } }`
**When** l'action `JUMP` avec `target: 4` est dispatchée
**Then** le state reste inchangé car `visited.has(4) === false` (guard échoue)

**Given** le state a `draftStatus: "idle"` et `step1Data: null`
**When** l'action `UPDATE_DATA` avec `{ step: 1, data: { venues: [{ name: "Gymnase A" }] } }` est dispatchée
**Then** `step1Data` contient les données et `draftStatus` passe à `"dirty"`

---

## 7. Auto-Save

### Stratégie : Draft hybride (server + sessionStorage)

L'auto-save fonctionne en trois couches :

1. **Debounce 2s** : après chaque modification (`UPDATE_DATA`), un timer de 2s
   démarre. Si aucune nouvelle modification n'arrive, l'auto-save déclenche.
2. **Server draft** : `PUT /api/clubs/{id}/draft` avec le `WizardState` sérialisé
   en JSON. Le backend stocke dans `clubs.transition_data` (jsonb).
3. **sessionStorage crash recovery** : en parallèle, le state est écrit dans
   `sessionStorage` sous la clé `wizard:draft:{clubId}`. Si le serveur est
   injoignable, le fallback sessionStorage permet de restaurer au refresh.

### Flux d'auto-save

```
UPDATE_DATA → draftStatus = "dirty"
  → debounce 2s
    → sessionStorage.setItem("wizard:draft:{clubId}", JSON.stringify(state))
    → PUT /api/clubs/{id}/draft (body: WizardState)
      → 200: draftStatus = "saved"
      → erreur réseau: draftStatus = "error", sessionStorage reste valide
```

### Restauration au chargement

```
Wizard mount
  → GET /api/clubs/{id}/draft
    → 200: LOAD_DRAFT avec les données serveur
    → 404 ou erreur: fallback sessionStorage.getItem("wizard:draft:{clubId}")
      → si présent: LOAD_DRAFT avec les données sessionStorage
      → si absent: initialWizardState
```

### Ex-gap backend — Server draft endpoint (tranché : abandonné)

> **Décision (2026-07, ex-gaps G1/G2, fermés)** : le draft serveur
> `GET/PUT /api/clubs/{id}/draft` est **abandonné** — le wizard persiste **par
> entité** (chaque salle/équipe/coach est POST/PUT à la saisie ; le store
> wizard ne tient aucune donnée d'étape), ce qui couvre déjà « ne rien
> perdre » ; un draft-blob serait une 2e source de vérité. Le champ
> `onboardingCompleted` existe (camelCase, voir §5). Trace :
> [`etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §2.

### Test Cases

#### Test Cases — Auto-Save

**Given** le gestionnaire modifie le nom de la salle "Gymnase A" en "Gymnase Central" sur l'étape 1
**When** 2 secondes s'écoulent sans nouvelle modification
**Then** `draftStatus` passe à `"saving"`
**And** `sessionStorage` est mis à jour avec la clé `wizard:draft:{clubId}`
**And** `PUT /api/clubs/{id}/draft` est appelé avec le `WizardState` sérialisé
**And** sur succès 204, `draftStatus` passe à `"saved"`

**Given** le serveur est injoignable (réseau coupé) et l'auto-save tente `PUT /api/clubs/{id}/draft`
**When** la requête échoue
**Then** `draftStatus` passe à `"error"`
**And** un message discret s'affiche : "Sauvegarde locale — connexion perdue"
**And** `sessionStorage` contient toujours les données les plus récentes

**Given** le gestionnaire refresh la page `/wizard` après avoir saisi l'étape 1 et 2
**When** le wizard se remonte
**Then** `GET /api/clubs/{id}/draft` est appelé
**And** si 200, les données sont restaurées et `currentStep` revient à 2
**And** si 404, le fallback `sessionStorage` est utilisé
**And** un message "Brouillon restauré" s'affiche

**Given** le gestionnaire a soumis le wizard avec succès (`onboarding_completed: true`)
**When** le wizard se démonte
**Then** `sessionStorage.removeItem("wizard:draft:{clubId}")` est appelé
**And** le draft serveur peut être purgé par le backend

---

## 8. ARIA / Accessibility

### Structure ARIA

| Élément | Attribut ARIA | Valeur |
|---------|---------------|--------|
| Stepper (liste des étapes) | `role="navigation"` `aria-label="Étapes du wizard"` | — |
| Étape courante dans le stepper | `aria-current="step"` | Appliqué sur l'`<li>` de l'étape active |
| Étape complétée | `aria-current="step"` + classe `.completed` | — |
| Étape non visitée | `hidden` sur le contenu de l'étape | — |
| Panneau d'erreurs | `role="alert"` | Annonce automatique par le screen reader |
| Message d'erreur par champ | `role="alert"` + `aria-describedby` sur le champ | — |
| Bouton "Suivant" | `aria-disabled="true"` si guard échoue | — |
| Contenu de l'étape | `role="region"` `aria-label="Étape {N}: {nom}"` | — |

### Focus management

1. **Changement d'étape** : le focus se déplace sur le titre `<h2>` de la
   nouvelle étape (`h2.focus()`).
2. **Erreur de validation** : le focus se déplace sur le panneau d'erreurs
   `role="alert"`.
3. **Retour à une étape** : le focus se déplace sur le titre de l'étape.
4. **Modal d'import Excel** : trap focus dans la modal, `Escape` ferme.

### Keyboard navigation

| Touche | Action |
|--------|--------|
| `Tab` | Navigation séquentielle dans l'étape |
| `Shift+Tab` | Navigation arrière |
| `Enter` sur "Suivant" | `NEXT` si guard passe |
| `Enter` sur "Précédent" | `PREV` |
| `Escape` dans modal import | Ferme la modal |

### Test Cases

#### Test Cases — ARIA/Accessibility

**Given** le gestionnaire est sur l'étape 1 et un lecteur d'écran est actif
**When** le wizard se charge
**Then** le stepper annonce "Étape 1 sur 4 : Infrastructure" via `aria-current="step"`
**And** le contenu de l'étape 1 a `role="region"` et `aria-label="Étape 1: Infrastructure"`
**And** les contenus des étapes 2, 3, 4 ont `hidden`

**Given** le gestionnaire clique sur "Suivant" sans avoir saisi de salle
**When** l'erreur de validation s'affiche
**Then** le panneau d'erreurs a `role="alert"` et le focus se déplace dessus
**And** le lecteur d'écran annonce "Au moins une salle est requise"

**Given** le gestionnaire passe de l'étape 2 à l'étape 3
**When** la transition `NEXT` s'exécute
**Then** le focus se déplace sur le titre `<h2>` "Étape 3 : Contraintes"
**And** l'étape 3 a `aria-current="step"` dans le stepper
**And** l'étape 2 perd `aria-current="step"`

**Given** le gestionnaire ouvre la modal d'import Excel
**When** il appuie sur `Escape`
**Then** la modal se ferme et le focus revient sur le bouton "Importer Excel"

---

## 9. Endpoints consommés — synthèse

| Endpoint | Méthode | Étape | Statut OpenAPI |
|----------|---------|-------|----------------|
| `/api/venues` | GET, POST | 1 | ✅ Présent dans `openapi-snapshot.json` |
| `/api/venue-training-slots` | GET, POST | 1 | ✅ Présent |
| `/api/teams` | GET, POST | 2 | ✅ Présent |
| `/api/coaches` | GET, POST | 2 | ✅ Présent |
| `/api/team-coaches` | GET, POST | 2 | ✅ Présent |
| `/api/clubs/{id}/import-teams` | POST | 2 | ✅ Présent (custom controller) |
| `/api/sport-categories` | GET | 2 | ✅ Présent |
| `/api/constraints` | GET, POST | 3 | ✅ Présent |
| `/api/priority-tiers` | GET | 3 | ✅ Présent |
| `/api/teams/{id}` | PUT | 3 | ✅ Présent |
| `/api/clubs/{id}` | GET, PUT | 4 | ✅ Présent |
| `/api/clubs/{id}/draft` | GET, PUT | Auto-save | ✂️ **Abandonné (ex-G1/G2)** — persistance par entité, voir §7 |
| `/api/clubs/{id}` (`onboardingCompleted`) | PUT | 4 | ✅ Présent (camelCase — ex-G7, erreur de doc corrigée) |

> Référence : `specs/courantes/openapi-snapshot.json` (paths vérifiés au
> 2026-06-30, backend SHA `6e35a6ce`). Décisions sur les ex-gaps :
> [`etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §2.

---

## 10. File structure (réservé au frontend)

```
frontend/src/
├── routes/
│   └── wizard/
│       ├── index.tsx              # WizardLayout + stepper
│       ├── step1-infrastructure.tsx
│       ├── step2-ressources.tsx
│       ├── step3-contraintes.tsx
│       └── step4-recapitulatif.tsx
├── features/
│   └── wizard/
│       ├── reducer.ts             # wizardReducer + WizardState
│       ├── schemas.ts             # Zod schemas (Step1-4 + WizardData)
│       ├── useAutoSave.ts         # Hook debounce 2s + server draft
│       ├── useDraftRestore.ts     # Hook restauration au mount
│       └── components/
│           ├── Stepper.tsx        # Stepper ARIA
│           ├── VenueGrid.tsx      # Grille dispos 15min
│           ├── ExcelImport.tsx    # Upload + column mapping + paste-rows
│           ├── TierList.tsx       # Drag & drop S/A/B/C/D
│           └── ErrorPanel.tsx     # role="alert" errors
```

> Aucun fichier `.test.ts` ou `.test.tsx` n'est créé dans le cadre de cette
> spécification. Les test cases sont en prose Given/When/Then dans ce fichier.

</details>
