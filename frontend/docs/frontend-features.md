# Frontend — Surface fonctionnelle & écrans

> Les besoins produit identifiés par l'expérience (onboarding, grille `WeekGrid`, tri des équipes, diagnostics, export, multi-tenant, cycle de vie côté UI, fiche club, stats gymnases), les écrans système (loading/erreur/503) et les largeurs d'écran. Découpé mécaniquement de `frontend-spec.md` (DOC-59).

Last verified @ 2026-10-10 (rotation documentation-update) : les 6 étapes du wizard recalées contre `WizardLayout.tsx` (`teams`/`venues`/`coaches`/`constraints`/`recap`/`generate`) ; l'absence d'en-tête `X-Club-Id` côté serveur recalée contre `TenantFilterListener.php` (AUD-SEC-25) ; `POST /api/teams/reorder` recalé contre `ReorderTeamsController.php`. Reste du fichier non re-confronté cette passe ; l'historique de vérification vit dans `git log -p --follow` ce fichier.

## 6. Besoins identifiés par l'expérience (forward)

Cette section capture les besoins frontend qui émergent de l'expérience produit, pas du
code existant. Ils guident le rebuild.

### 6.1 Onboarding guidé non-négociable

Le gestionnaire arrive avec ses données en vrac (Excel, papier, mémoire). Le frontend doit
le guider étape par étape sans le perdre. Le wizard livré compte **6 étapes** (Équipes →
Gymnases → Coachs → Contraintes → Récapitulatif → Génération — détail : `frontend-wizard.md`).
Le frontend doit :

- Sauvegarder à chaque étape (mutations API immédiates)
- Permettre la navigation arrière sans perte
- Valider chaque étape (`useStepValidation`, erreurs bloquantes + avertissements non bloquants)

### 6.2 Visualisation planning = `WeekGrid` (custom)

Le planning est une semaine type (**lundi→dimanche** — `lib/grid.ts:312` filtre `dayOfWeek >= 1 && <= 7`), rendu par le composant maison `WeekGrid`
(`src/features/planning/WeekGrid.tsx` + `lib/grid.ts`) — pas de FullCalendar :

- Créneaux colorés, filtre par ressource (`ResourceFilter` : équipe / coach / salle). Il vit **ligne 1 de `PlanningToolbar`, contre le sélecteur de vue** dont il suit le libellé (« Par gymnase » → « Gymnases : … ») — séparés, c'étaient deux contrôles sur les mêmes ressources à deux endroits, dont le second passait inaperçu (P4-43). Un filtre **posé se voit** : bordure et texte en accent, graisse medium. **`Échap` ferme la puce et rend le focus au bouton déclencheur** (P4-184, 2026-09-11) — listener `keydown` **natif** sur le wrapper (`stopPropagation`, même patron que `shared/components/ui/listbox.tsx`), nécessaire car deux de ces puces vivent aussi dans le `Modal` des vœux de coach dont `useModalA11y` écoute Échap en natif sur le panel ; le voile de clic-hors, lui, ne restitue pas le focus (geste souris), inchangé. Détail : `specs/courantes/module-matchs.md` §5 « Écran Calendrier ». ⚠ **Sans fond teinté, délibérément** — mesuré, `text-accent` sur `bg-accent/10` tombe à 4.18:1 en thème clair, sous les 4.5:1 de WCAG 1.4.3 ; le jeton est verrouillé par `a11y-contrast.spec.ts`. ⚠ **L'export ne connaît pas ce filtre** : `ExportMenu` porte son propre périmètre gymnase et le rendu est serveur.
- Click sur créneau → détail (`SlotDetail` : équipe, coach, salle, verrou). **Sous-ligne compacte (2026-08-16, volet B)** : une seule ligne discrète sous le titre — `<catégorie> · <durée> min · Coach <nom>` (séparateur « · », un segment vide omis sans « · » orphelin). **Enrichi par P2-2/F1 (2026-08-12)** : le wrap dit **POURQUOI** le créneau est verrouillé (« Réservation gymnase » / « Épinglé manuellement » / « Origine inconnue ») et liste les **contraintes applicables**, composées côté client depuis `GET /api/constraints` (aucun calcul serveur nouveau). ⚠ « Origine inconnue » se lit comme une **ignorance**, jamais comme une absence de verrou — c'est cette nuance qui décide si le gestionnaire ose déplacer. **Le panneau de créneau dit ce que chaque règle FAIT (2026-08-12)** — `describeConstraint` dérive la substance de `family`+`config` (« Samedi interdit », « Au moins 1 séance à Matéo », « Préfère Matéo »), rendue sur **UNE seule ligne par contrainte** : le nom libre n'apparaît **que faute de description dérivable** (repli, jamais en doublon). Les contraintes d'un même groupe se séparent désormais par un simple trait (`divide-y`) au lieu d'un espacement vertical. **La description NOMME aussi sa cible depuis P4-94 (2026-08-14)** — forme « \<cible\> · \<prédicat\> », même vocabulaire que l'auto-nommage du wizard (`ConstraintsStep.build()`) : équipe/coach → leur nom résolu depuis les lookups du planning, `CLUB`+`targetTag` → « Groupe \<tag\> », `CLUB` nu → « Toutes les équipes » ; cible introuvable (équipe/coach supprimé) → **prédicat seul**, jamais « ? · … ». Le choix du libellé de cible reste de la PRÉSENTATION (le branchement sur `scope` ne décide rien d'applicabilité, `applicableConstraints` reste seul juge). ⚑ **Pourquoi** : le nom est saisi par le gestionnaire, il peut être périmé ou copié — une contrainte réellement « samedi interdit, tout le club » mais nommée « SM2 au moins 1 seance a Mateo » s'affichait sur un créneau U11 et rendait le produit **invérifiable**. C'est de la PRÉSENTATION (autorisée), jamais une décision d'applicabilité (interdite au front, cf. `.claude/rules/frontend.md`). ⚠ Familles décrites : DAY (`forbiddenDays`/`allowedDays`), FACILITY (les 4 clés de gymnase), TIME, COACH_AVAILABILITY. **Tout le reste retombe sur le nom — sans inventer** : `forcedDays` legacy (sens ambigu), gymnase introuvable, clé inconnue. Une description approximative serait le même mensonge sous une autre forme. **Les deux panneaux latéraux sont bornés à la hauteur de la grille** et défilent en interne (mesuré : `cardBottom == rowBottom`, `scrolled > 0`). **Sélectionner un créneau REPLIE les diagnostics** — ils ne disparaissent plus : la barre repliée garde le compte et la sévérité max (« Diagnostics du système (12) · 3 alertes »), rouvrable d'un clic, restaurée à la fermeture du créneau. L'exception « sauf les ERROR » du 2026-08-12 est **retirée** : le repli rend la place sans rien enterrer, donc le cas particulier n'a plus lieu d'être. **Le panneau de créneau (2026-08-12)** : contraintes **repliées par défaut** avec leur NOMBRE visible replié (« Contraintes applicables (3) » — on sait s'il y a à ouvrir sans ouvrir), liste bornée en hauteur qui **défile en interne** au lieu d'agrandir l'aside, et deux groupes libellés **« Cette équipe » / « Tout le club »**. Sélectionner un créneau **masque les diagnostics** — ⚠ **sauf s'il reste des `ERROR`** : une erreur grave qui disparaît sur un clic serait le prochain défaut de confiance. C'est un état DÉRIVÉ, pas une mutation : fermer le créneau restaure le panneau à l'identique. ⚑ **Une contrainte `CLUB` portant un `targetTag` ne s'affiche que sur les équipes TAGUÉES** — miroir de l'éclatement backend (`ScheduleConstraintBuilder.php:846-870`) ; tag qui ne résout aucune équipe → affichée **nulle part** (miroir du NO-OP). Avant, `case "CLUB": return true` l'affichait partout : le panneau annonçait une règle que le solveur n'avait jamais appliquée à cette équipe. **Une bannière UNIQUE de péremption (2026-08-12)** nomme sa ou ses causes — « modifié manuellement » et/ou « une contrainte a changé depuis la génération » — plutôt que d'empiler deux bandeaux que le gestionnaire finirait par ignorer tous les deux. Le planning n'est pas FAUX, il est **périmé** : il décrit un état antérieur des règles, et l'action est de **régénérer pour savoir**. ⚠ Sur un planning **validé** (lecture seule), elle propose « Rouvrez ce planning, puis régénérez » — jamais un « Régénérer » nu qui rendrait 409. **Et depuis F2b (2026-08-12) il PEUT déplacer sûrement** : le geste passe par `/move`, donc par le verdict du moteur — un refus s'affiche **avec ses motifs nommés** (« le coach X a déjà… »), une génération en cours bloque le geste, et un déplacement accepté pose une bannière **« score périmé »** (le score affiché décrivait le planning d'avant). **Un diagnostic `conflict` resserre sur SON instant depuis P4-95 (2026-08-14, corrigé le 2026-08-29)** — jusque-là un clic ne faisait que surligner un rapprochement équipe/gymnase/coach (`DiagnosticsPanel`, comme l'« unused_slot » qui amène la colonne du gymnase à l'écran) ; un `conflict` PORTE désormais (jour, heure) + **au moins un discriminant parmi gymnase/coach/équipe** (deux champs additifs sur `schedule_diagnostic`, `dayOfWeek`/`startTime`, nullable, contrat backend⇄engine inchangé, le schéma 2.6 les portait déjà côté engine) — donc `concernedSlots` resserre sur les créneaux EXACTS de cet instant (heure à la minute) au lieu de surligner un ensemble large. ⚠ **Ouvrir n'a de sens QUE si ces créneaux tiennent dans UNE case** (`DiagnosticsPanel.tsx` — même gymnase+jour+heure) : une sur-capacité de gymnase désigne une case unique et l'ouvre (`data-slot-id`, `WeekGrid`, comportement inchangé depuis 2026-08-14) ; un conflit de PERSONNE (coach ou joueur) s'étale structurellement sur DEUX gymnases (la personne y est attendue aux deux à la fois — `diag-conflict-coach-*` et, depuis le lot 8 (2026-09-23), `diag-locked-person-*` ne portent jamais de `venueId`) : le panneau surligne les deux et n'ouvre rien (décision fondateur 2026-08-29, `etat-des-lieux.md` §2 — arbitrer lequel déplacer appartient au gestionnaire, et en ouvrir un masquerait l'autre moitié du choc). **Lot 8 (2026-09-23)** aligne `startTime` de ces deux familles sur le début du CHEVAUCHEMENT (`max` des deux débuts, jamais celui de la 1ʳᵉ séance — sinon une seule des deux cases matcherait sur des débuts décalés, que le front ouvrirait à tort) et résout la personne par les lookups existants (`slotCoachId`/`teamPlayerCoaches`), jamais `slot.coachId` en direct (qui ratait le coach résolu depuis l'équipe et ratait TOUJOURS le cas joueur) ; gymnase et équipe gardent l'égalité stricte du début. Le même lot resserre aussi le rapprochement « jour seul » (`diag-locked-team-day-*`, `startTime` volontairement absent) sur `dayOfWeek` — il surlignait avant toutes les séances de l'équipe, toutes semaines confondues. `diag-implicit-age-*` reste hors de ce mécanisme, inchangé. Les autres types de diagnostic restent au rapprochement large ou à des coordonnées partielles — résidu réduit à `diag-locked-team-day-*`/`diag-implicit-age-*`, `roadmap.md` P4-95
- Lecture seule quand le plan **pointe** la version affichée (`Schedule.isChosen` — le verrou d'édition)
- Pas de vue mensuelle — le planning est hebdomadaire type
- **Libellé de groupe fusionné, vue GYMNASE seulement (P2-17, 2026-08-14)** : quand un `VenueTrainingSlot` porte un `groupLabel` non vide ET que ≥ 2 équipes partagent son gymnase/jour/heure-de-début, `lib/grid.ts` fusionne leurs cellules en **une seule carte titrée par le libellé** — chaque équipe reste **individuellement cliquable** (`GridCellMember.slotId` ouvre le même `SlotDetail` qu'une carte séparée). Une seule équipe sous un libellé retombe sur la cellule ordinaire (pas de carte à un membre). Les vues **équipe** et **coach** sont inchangées — la fusion n'existe que côté gymnase. Purement esthétique : le libellé est **affiché**, jamais redérivé (le backend le calcule et le normalise) et ne rejoint jamais le payload solveur. Saisi dans le wizard (`GroupLabelField`, étape Gymnases — `frontend-wizard.md` item 2), affiché en **badge** (sans fusion) sur la grille « Réserver » (`frontend-wizard.md` item 4)
- **Le diagnostic d'une séance manquante NOMME la règle en cause et y mène (P4-99, 2026-08-15)** : sur un `session_below_effective_min`, `DiagnosticsPanel` rend **une ligne par cause** — le **NOM de la contrainte** en information principale, sa famille en complément (« 8 créneaux fermés par « Groupe EMB · pas après 17:30 » (une plage horaire trop étroite) »), plus un `WizardStepLink step="constraints" params={{edit: constraintId}} from="planning"` **« Corriger cette règle »** — même rail `?edit=` que P2-25/P4-95. ⚑ **Le nom, pas seulement la famille** : trois causes `time_window` issues de trois règles différentes rendraient sinon trois lignes IDENTIQUES avec trois liens divergents — illisible au moment précis où l'écran doit éclairer (falsifié par un test « deux causes de même `kind` doivent être distinguables »). **Dégradations, toutes explicites** : `constraintId` null → la cause s'affiche **sans lien** (jamais de lien mort) ; `label` null ou vide → repli sur la famille (jamais « null », jamais le code brut) ; `kind` inconnu (donnée future de l'engine) → le compte seul, aucun plantage. **`openCandidates` n'est PAS une cause** et a sa phrase dédiée (« 5 créneaux restaient disponibles — le planning y a placé une autre séance »), affichée **seulement si non-null ET > 0** : `0` (« aucun n'est resté ouvert ») et `null` (« non mesuré ») ne se confondent pas. ⚑ **Zéro redérivation** — le front AFFICHE ce que le backend a mesuré à la pose des contraintes ; le `Record<DiagnosticCauseKind, string>` est un choix de **libellé** (présentation autorisée), sur un `kind` hors des `POLICED_ENUMS`, jamais un décideur de comportement. Les 10 autres types de diagnostic sont inchangés (résidu P4-95)
- **4e vue « Par jour » (P2-33, 2026-08-17)** : `ViewMode` (`planning/store.ts`) gagne `"jour"`, choisi comme les trois autres dans `PlanningToolbar`. En vue jour, la ressource **FILTRABLE** devient le **jour ISO** de la semaine (`ResourceFilter` affiche « Jours : … », libellés **en toutes lettres** — `dayLabelLong`, `shared/lib/days.ts` — au lieu de l'abrégé des en-têtes de grille), défaut = tous les jours cochés. ⚑ **Les colonnes de grille, elles, restent les gymnases** : `lib/grid.ts` n'a pas de second moteur de layout, il aiguille toute la composition (colonnes, libellés, couleurs, fusion P2-17) sur un alias interne `columnView` (= `"gymnase"` quand `viewMode === "jour"`, sinon `columnView === viewMode` comme avant) — seul le **filtre** lit le vrai `viewMode`. Les jours du filtre sont triés en **ordre ISO** (lundi→dimanche), jamais alphabétique. Les cases vides restent incluses (mode cible P2-30 inchangé). Utilité : un club à beaucoup de gymnases (le wizard notamment) peut filtrer sur un jour et retomber à quelques colonnes au lieu du scroll horizontal permanent
- **5ᵉ vue « Par club » (P3-20, 2026-08-18)** : `ViewMode` gagne `"club"`. ⚑ **Ce n'est PAS une 5ᵉ mise en forme de la grille** — c'est la matrice **équipes × jours** que seuls les exports savaient produire (section 2 du PDF, feuille « Équipes × jours » du XLSX), donc un rendu à part (`lib/clubView.ts` pour la projection, `ClubViewTable.tsx` pour le rendu) : `buildGrid` ne la connaît pas et n'est pas réécrit (la page ne lui passe même aucun créneau dans cette vue). **Contenu = les règles des exports, à l'identique** — une ligne par équipe de la saison **y compris sans aucune séance** (le trou est ce qu'il faut voir : la ligne le DIT, « aucune séance »), deux séances le même jour = deux entrées triées par heure, colonnes = les jours réellement utilisés en ordre ISO, **aucun coach** (décision fondateur des exports : il vit dans la grille et n'encombrerait que le balayage), les fenêtres VIDES n'y entrent pas (elles n'appartiennent à aucune équipe). Lignes groupées par RANG comme la vue « Par équipe » ; l'axe FILTRABLE est l'équipe (« Équipes : … »). ⚑ **Une vue différente, les MÊMES gestes** (décision fondateur) : `ClubViewTable` porte exactement le contrat de props de `WeekGrid` et reçoit les mêmes handlers — sélection → `SlotDetail`, cadenas en un clic (bouton **frère**, jamais imbriqué), lentille de verrous, priorité surlignage conflit > mode cible > lentille, `data-slot-id` pour le clic-diagnostic. ⚠ **Sa seule limite, dite à l'écran** : en mode cible, désigner une séance existante fonctionne (la destination se déduit de son placement), mais une case (équipe, jour) **vide** n'est pas une destination — un couple équipe/jour ne porte ni gymnase ni horaire. Un bandeau `role="status"` renvoie alors vers « Par gymnase »/« Par jour », au lieu de laisser cliquer dans le vide
- **La grille se VOILE pendant tout (re)chargement des créneaux, jamais « Planning vide » avant réponse (2026-08-17, retour fondateur « ça mouline »)** : `useSlots` (`planning/queries.ts`) porte `placeholderData: (previous) => previous` — changer de version/période garde l'ancienne grille à l'écran le temps que les nouveaux créneaux arrivent, au lieu de la vider brutalement. `PlanningPage` lit la requête ENTIÈRE (pas juste `data`) ; `slotsBusy = isFetching` sur la version affichée pilote deux effets : (1) le conteneur de `WeekGrid` passe en `opacity-40 pointer-events-none` (le voile capte les clics, rien ne « passe au travers » vers une grille périmée) avec un indicateur centré superposé (« Chargement des créneaux… », `Loader2` animé, `role="status" aria-live="polite"`) ; (2) au **premier** chargement d'une version (aucune donnée précédente à voiler), l'état `slotsBusy` remplace l'`EmptyState` « Planning vide » qui aurait sinon menti tant que la requête n'a pas répondu — une fois la réponse arrivée et RÉELLEMENT vide, `slotsBusy` retombe et « Planning vide » s'affiche. N'intervient jamais par-dessus `GenerationWaiting` (génération en cours), qui a son propre rendu.
- **Le VOILE bloquant — « bloquer les impatients » (lot C, 2026-08-21, design fondateur)** : `app/ActionVeil.tsx`, monté dans `Providers` (pas dans `RootShell` : le voile est scopé **react-query**, or les étapes du wizard sont du zustand, invisibles de `useNavigation`). ⚠ **Deux temps, et le MOMENT du blocage dépend du contexte — c'est le cœur du réglage** : le blocage (`inert` natif React 19 sur un wrapper `display:contents` + overlay `pointer-events`, dérivé `blocking`) commence **dès 0 ms pour `enregistrement` et `traitement long`** — ils protègent un geste RÉELLEMENT parti, dont le blocage immédiat mange le 2ᵉ clic — mais **à 250 ms seulement pour `changement de page`**, à l'instant où le voile devient VISIBLE (GO fondateur 2026-08-21). Pourquoi cette asymétrie : sur un CHARGEMENT rien n'est parti, aucune double-soumission à empêcher — rien à protéger avant que l'utilisateur ne VOIE pourquoi il est bloqué ; bloquer à 0 ms alors que le voile est encore invisible mangerait des frappes **en silence** (le pire échec : croire son clavier mort), à l'arrivée comme sur une transition rapide. Le voile n'est de toute façon **visible qu'après 250 ms** — sinon il clignoterait à chaque enregistrement de 90 ms. L'asymétrie est gardée par le NR `ActionVeil.test` (`enregistrement` inert dès 0 ms, `page` pas avant 250 ms) : interdiction de fondre les deux régimes. Le `<Toaster />` reste **hors** du wrapper inert : une erreur doit rester lisible au moment précis où elle survient. Scrim opaque `bg-background/60`, **jamais de flou** (le flou signale « cliquable pour fermer », faux ici), `z-[60]` au-dessus de la barre de navigation `z-50`, focus rendu à l'élément déclencheur à la levée. Scène propre : le tableau tactique du coach (SVG+CSS pur, boucle 2 s, figée sous `prefers-reduced-motion`) — `GenerationWaiting` garde sa grille et son ballon, les deux attentes ne se confondent jamais. **Trois contextes** (priorité long > enregistrement > page). ⚠ **« Changement de page » ne s'arme QUE sur une TRANSITION déclenchée par le gestionnaire** (changement d'étape du wizard, de vue/version du planning — déclencheur `shared/stores/navTransitionStore`, ⚠ **armé dans les handlers de CLIC** de `WizardLayout`/`PlanningPage`, jamais dans les actions de store, qui servent aussi au guidage automatique au montage), **jamais sur le simple montage d'un écran** (règle corrigée le 2026-08-21, GO fondateur) : le blocage à 0 ms sert à manger le 2ᵉ clic d'un geste **déjà parti** ; une arrivée n'a rien lancé, et comme le voile est invisible sous 250 ms, geler un formulaire déjà peint mange les frappes **sans retour visuel** — pire que pas de voile (l'ancienne règle « tout premier chargement voile » gelait l'étape 1 du wizard à l'arrivée : `journey.spec.ts`, `veil-double-click.spec.ts`). Le premier chargement **sans cache** (`undefined === q.state.data`) qui suit la transition voile ; un refetch d'arrière-plan jamais. Quatre phrases chacun dans le ton des pages d'erreur (P5-14) : `Enregistrement` et `Changement de page` tournent à 2,6 s ; `Traitement long` avance par **paliers** ~0/5/12/25 s dont le dernier persiste — pas de boucle, parce que le bout-en-bout d'un verdict moteur est mesuré **> 30 s** sur un club dense et qu'une phrase qui revient ferait croire au plantage. **Deux régimes de sortie** : les contextes courts préviennent **et relâchent** au-delà de 10 s (une panne réseau ne doit pas condamner l'app jusqu'au F5) ; le contexte long **ne relâche jamais au chrono** — relâcher autoriserait un second déplacement par-dessus le premier — sa sortie est le bouton **« Abandonner ce déplacement »**, qui `abort()` la requête et resynchronise le même paquet qu'un déplacement accepté, **parce que le serveur a pu l'appliquer quand même** (le message le dit, il ne ment pas). **A11y, deux régimes assumés** : sans bouton `role="status"` + `aria-live="polite"` ; avec le bouton d'abandon le voile EST un dialogue → `role="dialog"` + `aria-modal` (jamais `alertdialog`, qui volerait le focus pour une attente sans urgence). Seule la phrase stable vit dans la région live, la rotation est `aria-hidden` (AUD-FRT-23/24). **Le régime est global par défaut, l'exemption se déclare** (`meta: { veil: false }`) — règle et liste des exemptions légitimes : `frontend/AGENTS.md` §« Toute mutation VOILE l'écran ». Effet de bord voulu : le blocage jusque-là MUET des flèches de réordonnancement dans `TeamsStep` (`reorderBusy`) gagne enfin sa raison visible, sans que le composant soit touché.
- **QUAND l'écran d'attente s'affiche : « une version du plan EN PORTÉE est en vol » (lot C PR-1, retour terrain fondateur 2026-08-21)** — maison unique : le dérivé `showGenerationWaiting = isGenerating || scopeInFlight` de `PlanningPage`. ⚠ Le défaut réparé : `isGenerating` ne dérivait que de la **sélection**, or au lancement la nouvelle version PENDING naît alors que la sélection embarquée pointe encore l'ancienne COMPLETED (ou rien, le temps que la liste se rafraîchisse) — sur un **overlay**, ce trou tombait donc sur le petit voile « Chargement des créneaux… » au lieu du MÊME écran qu'en saison, ce que le gestionnaire a rapporté tel quel (« un chargement qui mouline ?? au lieu d'utiliser le même écran »). `scopeInFlight` regarde la LISTE des versions, bornée à la portée : en portée période, les versions de CE plan (`schedulePlanId`) seul ; sinon celles de la saison. **La portée est une vraie borne, falsifiée dans les deux sens** : une version en vol d'un autre plan (autre période, ou la saison quand on est en portée période) ne déclenche RIEN. **Ce dérivé est la SEULE porte** : les huit gardes qui se taisent ou se grisent « pendant une génération » (bouton de suppression d'overlay, marqueur « périmé », barre d'outils, comparaison, actions, `DriftBanner`, bannière de séances périmées, bannière d'échec) le lisent toutes — sinon ces bannières flotteraient AU-DESSUS de l'écran d'attente, alors que la règle dit qu'il **remplace** le contenu. **Décision fondateur assumée** : pendant un vol, sélectionner manuellement une ancienne version COMPLETED montre malgré tout l'écran d'attente, sans les gestes — c'est la lettre de la règle. Côté `GenerateStep`, `showPlanning` est INCHANGÉ (le correctif du 2026-08-19, « revenir avec deux COMPLETED affiche le planning », tient) ; l'étape n'ajoute que **la fenêtre locale qu'elle seule connaît** — entre le POST et le premier refetch, la version fraîche n'est pas encore dans la liste, donc la portée ne peut pas la voir.
- **L'écran d'attente de génération (`GenerationWaiting.tsx`) est une SCÈNE animée, pas un logo pulsé (design fondateur, 2026-08-17)** — consommé identiquement par `PlanningPage` (`showGenerationWaiting`, voir juste après) et `wizard/steps/GenerateStep.tsx`, sans prop : le composant ne prend plus `initial`/`logoUrl` — `GenerateStep` n'a donc plus besoin de `useMe()` du tout (l'appel a été retiré) ; `PlanningPage` le garde pour ses autres usages. Cadre `bg-card`/`border-border` **pleine largeur, en hauteur bornée** (`h-[22rem] sm:h-[26rem]`) contenant **deux SVG décoratifs ancrés aux bords** (`role="img"` sur UNE seule des deux, un seul texte alternatif) — bandes latérales en terrain de basket filigrané, grille de créneaux qui se remplissent (coche à l'accent), ballon qui rebondit de case en case — et un centre HTML superposé (lisible au lecteur d'écran, `role="status" aria-live="polite"`) : mini-grille 4×4 qui se remplit + ligne de balayage, titre + phrase tournante (rotation 3 s), note de durée. **Aucun logo ni initiale du club** n'y est rendu (voir `identite-visuelle-club.md`). **Toutes les couleurs lisent les tokens de thème et l'accent du club** (`var(--card|muted|border|muted-foreground|accent)`) — jamais un littéral hex/oklch — donc une seule scène pour les deux thèmes et n'importe quel accent. `prefers-reduced-motion` coupe toutes les animations (`GenerationWaiting.css`, classe `.gw-anim` neutralisée) et fige le ballon sur sa dernière case plutôt que de le figer à mi-course. **Pleine largeur depuis le 2026-08-18 (2ᵉ tranche de P4-107)** : le cadre ne porte plus de `max-w`. ⚠ Retirer le cap ne suffisait pas — le décor était UN svg `800×500` en `h-auto`, donc sa hauteur suivait sa largeur (~1100 px de haut sur un écran 1800 px, à scroller pendant toute la génération). Le décor ne vivant que dans **deux bandes latérales** (le centre est la zone protégée des textes du design), il est scindé en deux `<svg>` cadrés par leur seule **`viewBox`** — `0 0 230 500` à gauche, `570 0 230 500` à droite — ancrés `left-0`/`right-0` : **aucune coordonnée du dessin ni aucune keyframe n'a été réécrite** (les translations du ballon sont en unités utilisateur du svg et suivent l'échelle de leur bande), et le `clipPath` qui découpait les deux fenêtres dans le svg unique devient inutile. `max-w-[26%]` par bande laisse au centre 48 % du cadre à toute largeur ; **sous `sm` les bandes s'effacent** et le centre prend tout — deux bandes à leur taille minimale mangeraient le texte. La seconde bande est `aria-hidden` : deux `role="img"` feraient lire la scène deux fois. La scène est générique (motif basketball codé en dur, pas encore un asset par sport) — l'habillage par sport reste ouvert, `roadmap.md` P5-16.

### 6.3 Tri des équipes drag & drop (mode « Trier » du wizard)

La priorisation des équipes (S/A/B/C/D) vit dans l'étape Équipes du wizard
(`TeamsStep`, bouton « Trier » / « Terminer le tri ») :

- @dnd-kit (`useSortable` + zones droppables par tier) — **drag & drop inter-tier** :
  une équipe peut être déposée dans un autre tier, flèches haut/bas en fallback clavier/a11y
- Couleurs et libellés de tiers cohérents avec le planning
- Sauvegarde **en bulk atomique** à la fin du tri : `POST /api/teams/reorder` avec
  `{ items: [{ id, priorityTierId, tierOrder }] }` (une transaction — remplace les N
  `PUT /api/teams/{id}` concurrents qui perdaient des mises à jour sur le lock optimiste)

### 6.4 Diagnostics en langage gestionnaire

Le rapport post-génération affiche les `schedule_diagnostics` avec :

- Regroupement par severity (error > warning > info)
- Messages tels que rédigés côté backend (langage gestionnaire, pas technique)
- Liens directs vers l'entité à corriger (équipe, coach, salle)
- Pas d'auto-correction MVP — l'utilisateur clique → navigue vers l'entité

### 6.5 Export du planning — LIVRÉ

`ExportMenu` (`src/features/planning/ExportMenu.tsx`) → hook `useScheduleExport`
(`features/planning/queries.ts`) → `POST /api/schedules/{id}/export-pdf` (asynchrone,
Messenger ; handler backend `ExportPdfHandler`).

- **Périmètre au choix** : tous les gymnases, ou **un seul** (`{ venueId }` dans le body) —
  chaque export tient sur une page paysage.
- **Deux formats, deux vues, aucun réglage de vue (2026-08-21)** : PDF et Excel, chacun portant
  **les DEUX** vues — la grille (jours × gymnases) puis la matrice **équipes × jours** (section 2
  du PDF, 2ᵉ feuille de l'Excel). Il n'y a plus de sélecteur : il n'existait que pour l'**image
  PNG**, retirée du produit le même jour (décision fondateur — elle *photographiait* une des deux
  sections déjà rendues, sans rien apporter qu'un format qui ne se feuillette pas ; décision
  fermée dans l'état des lieux §2).
- **La matrice est INCONDITIONNELLE depuis cette date.** Elle dépendait de « ≥ 2 gymnases parmi
  les placements », au motif qu'elle « lève l'ambiguïté sur le gymnase ». C'était la justification
  d'un **déclencheur**, prise pour la raison d'être de la vue : les deux répondent à deux
  questions distinctes — la grille dit *qui occupe quel gymnase ce jour-là*, la matrice dit *quand
  s'entraîne CETTE équipe*, une ligne à lire — et le second besoin existe avec un seul gymnase.
  ⚠ **Les LIGNES de la matrice dépendent en revanche de la PORTÉE** : export « tous les gymnases »
  → toutes les équipes de la saison (une équipe sans séance est un trou du planning, à voir) ;
  export limité à UN gymnase → seules les équipes qui y ont une séance, sinon une équipe
  s'entraînant ailleurs passerait pour une équipe sans entraînement sur un document remis aux
  familles. Cette règle vivait dans le PDF depuis P3-20 ; l'Excel la porte depuis le retrait du
  seuil, qui la lui tenait lieu de garde.
  ⚠ **Les lignes de la matrice suivent la PORTÉE** : sur « tous les gymnases », toutes les
  équipes de la saison (une équipe sans séance est le trou qu'il faut voir) ; sur **un seul
  gymnase**, seules les équipes qui y sont placées — les données d'export portent toujours
  toutes les équipes du club, donc les lister toutes ferait passer une équipe qui s'entraîne
  ailleurs pour une équipe sans entraînement.
- Les fichiers produits sont servis sous **`/exports`** : proxifié par Vite en dev
  (`vite.config.ts`) et par le Nginx frontend en prod (`docker/frontend/nginx.conf`).

### 6.6 Multi-tenant transparent

Le gestionnaire ne voit jamais le concept de `club_id` ou `season_id`. Le frontend :

- N'envoie **aucun** header `X-Club-Id` : le backend dérive le club de la membership du JWT
  (`TenantFilterListener`)
- N'affiche jamais de sélecteur de club (un user = un club en MVP)
- La **saison**, elle, est visible et choisissable : `SeasonSelector` (dans `app/`) écrit dans
  `seasonStore`, qui alimente `X-Season-Id`. Sans sélection, le serveur dérive la saison
  courante (pivot du 15 juillet) — un club mono-saison ne voit donc jamais le sujet. Le
  bandeau `ReadonlySeasonBanner` signale une saison archivée (écritures → 409).

### 6.6 bis Cycle de vie du planning (le pointeur du plan)

- **Valider ↔ Rouvrir sont les deux sorties symétriques du cycle de vie** (arbitrage fondateur,
  symétrie stricte 2026-08-20) : Valider vit dans l'espace de travail (wizard, `embedded`) et en
  est la SORTIE — son succès navigue vers `/planning` ; Rouvrir vit sur `/planning` autonome
  (`!embedded`, l'écran de la version en vigueur) et en est la sortie inverse — il ramène au
  wizard. Le badge de statut et la pastille « Période », eux, sont visibles dans les DEUX modes
  (`PlanningToolbar.tsx`) : conséquence assumée, la pastille « Période » apparaît donc aussi en
  standalone sur un overlay.
- Un planning `COMPLETED` peut être **validé** (bouton « Valider » de la toolbar, visible
  seulement `embedded` — voir §route `/planning` ci-dessus) → modale de confirmation
  (`ValidateDialog`, avertit si des alertes subsistent, nomme le plan réel dans son titre) →
  `POST /api/schedules/{id}/validate` → **le plan pointe cette version** et **ses versions sœurs
  sont supprimées** (ADR-0002 inv. 1) ; le planning passe en **lecture seule** (grille non
  éditable, renommage et régénération masqués). Le statut, lui, **reste `COMPLETED`** : « validé »
  se lit sur le pointeur (`Schedule.isChosen`). Le succès navigue vers `/planning`
  (`PlanningPage.tsx`, `validate()` `onSuccess`) — vaut pour le socle saison comme pour un plan
  de période. **Effet de bord RMM-10 (P2-52)** : à l'ouverture de la modale, `useValidateImpact`
  interroge `GET /api/schedules/{id}/validate-impact` (armé seulement quand le geste est envisagé,
  `staleTime: 0`) — `ValidateDialog` n'affiche l'avertissement « salle perdue » que si l'impact est
  N>0 (zéro bruit préventif) et désactive « Valider » tant que la réponse est en vol ou en échec
  (bouton « Réessayer », jamais un impact inconnu présenté comme vide). La validation elle-même
  dépointe alors ces matchs (`UNPLACED` + raison persistante `venue_lost`, heure conservée) —
  détail métier : `specs/courantes/module-matchs.md` §11 « Le périmètre engagé ».
- « Rouvrir » (`POST /api/schedules/{id}/reopen`, bouton `/planning` autonome uniquement,
  `!embedded`) **dépointe** le plan (inv. 2) : la version survit et redevient éditable. Toute
  navigation qui suit **déclare son mode** (fix terrain 2026-08-19 défaut 3, `PlanningPage.tsx`
  `reopen()`) — jamais un `jumpTo("generate")` nu qui laisserait le mode ambiant du `localStorage`
  décider : la version rouverte est résolue (`schedulePlanId` → plan → `calendarEntryId`) et le
  wizard s'ouvre en mode période (`startPeriodMode`) si ce plan n'est pas `SEASON`, en mode saison
  (`exitPeriodMode`) sinon.
  La même règle route `SeasonSchedulesModal.consult()` (« Plannings de la saison ») : plan
  **pointé** → `/planning` ; plan **non pointé** → `/wizard`, mode déclaré pareil.
- Il n'existe **pas** de « Définir principal » : le pointeur se déplace **en validant**, et par rien
  d'autre (`set-baseline` supprimé, inv. 18). Le ★ de la saison = `seasonPlan.chosenScheduleId`
  de `/api/me`.
- La liste « Plannings de la saison » (`SeasonSchedulesModal`) affiche l'état du **plan**, pas le
  statut brut de la version (fix terrain 2026-08-19 défaut 1) : pointé → « Validé » ; `COMPLETED`
  non pointé → « Terminé · à valider » ; ouvert (`PENDING`/`GENERATING`) → « … · en cours ». Deux
  plans `COMPLETED` peuvent donc porter des libellés différents selon lequel est pointé.
- **« Modifier les données du club »** (`SeasonPlanBanner.tsx`, P4-268, décision fondateur
  2026-09-28) : un second bouton, distinct de « Ouvrir », rendu **seulement quand le socle est
  validé** — sort d'un éventuel mode période (`exitPeriodMode`) et ouvre le wizard sur l'étape
  Équipes (`jumpTo("teams")`), **sans** poser de pointeur ni le déplacer : ce n'est ni « Valider »
  ni « Rouvrir », le plan de saison reste en vigueur tel quel. Sert à compléter le modèle du club
  (coach déclaré tard, équipe, gymnase) pendant la saison, sans détruire les plans de période
  futurs qu'un « Rouvrir » supprimerait (§2bis de `accueil-cockpit-temporel.md`). `WizardLayout`
  dérive `seasonEditLocked = !periodMode && socleValidated` : dans cet état, un bandeau
  (`NoticeBanner`, ton `accent`) dit « le planning de la saison reste en vigueur — vos
  modifications s'appliqueront à la prochaine génération » et les étapes **Contraintes** et
  **Génération** sont verrouillées dans le `StepRail` — confort seul, le serveur refuse déjà la
  génération d'une version de saison pointée (`SocleGuard`, §3.2bis de
  `planning-lifecycle-validated.md`). Gymnases et Coachs restent pleinement accessibles ; l'étape
  Équipes l'est aussi **à une exception près** (voir plus bas : le nombre de séances d'une équipe
  existante y est verrouillé, comme les créneaux).
  L'étape Gymnases (`VenuesStep`/`VenueAvailabilityGrid`) y passe la grille des créneaux
  d'entraînement en **lecture seule** (`readOnly`, cellules et créneaux non cliquables, mention
  « rouvrez le planning pour les modifier ») — la fiche du gymnase reste éditable, et un
  deep-link `?slot=` n'y ouvre plus l'éditeur de créneau. Hors ce régime (onboarding, ou après
  un « Rouvrir »), la grille garde l'édition normale.
  L'étape Équipes (`TeamsStep`) y passe le champ **« Séances/sem » d'une équipe existante en
  lecture seule** (`readOnly` — décision fondateur 2026-09-28 : le nombre de séances par semaine
  est une **contrainte** du planning en vigueur, au même titre que les créneaux, `useSocleValidated()`
  gouverne le verrou). La valeur reste **visible** (readOnly, pas `disabled` — le champ ne sort pas
  de l'ordre de tabulation et reste lu), et une explication unique au-dessus de la liste dit
  « Rouvrez le planning de la saison pour modifier le nombre de séances ». Le **rang** (priorité,
  flèches/« Trier ») et le **niveau** restent éditables (le niveau garde sa garde « équipe engagée »).
  ⚠ La **création** d'une équipe reste libre : le champ « Séances/sem » du formulaire d'ajout n'est
  jamais verrouillé (sinon aucune équipe neuve ne pourrait recevoir son nombre de séances) — le
  verrou porte sur l'ÉDITION d'une équipe existante, pas sur la création. La surcharge de période
  (`PeriodTeams`, mode période) et la mutualisation ne passent PAS par ce chemin et sont inchangées.
  Hors ce régime (onboarding, ou après un « Rouvrir »), le champ redevient éditable.
- **Invalidation croisée wizard→planning des liens coach** (`wizard/queries.ts`) : les mutations
  de lien équipe↔coach (`useCreateTeamCoach`/`useDeleteTeamCoach`) et coach-joueur
  (`useCreateCoachPlayer`/`useDeleteCoachPlayer`/`useDeleteCoach`) invalident désormais, en plus
  de leurs clés `["wizard", …]`, les clés NUES `["team_coaches"]`/`["coach_player_memberships"]`
  que lit `planning/queries.ts` pour le repli `lookups.teamCoach` de `planning/lib/grid.ts` — sans
  ce correctif, un coach lié depuis « Modifier les données du club » n'apparaissait sur une séance
  générée sans coach qu'après le `staleTime` de 5 min du Planning.
- **Radar « une personne à deux endroits » (P4-269, décision fondateur 2026-09-28)** :
  `usePlacedConflicts()` (`planning/queries.ts`, `queryKey: ["training", "placed-conflicts"]`,
  `staleTime: 10 000`) lit `GET /api/training/placed-conflicts` — le backend recalcule à la volée,
  depuis les séances **placées** de la version **en vigueur** du plan SEASON et les liens COURANTS
  (coach MAIN/ASSISTANT + joueur actif), toute paire qui met la même personne dans deux gymnases
  **différents** au même instant (le même gymnase reste la mutualisation voulue, jamais un
  conflit) ; `seasonPlanChosen: false` (aucune version pointée) veut dire « pas de planning en
  vigueur à scanner », une liste vide n'y signifie pas « tout va bien ». Une seule primitive de
  présentation, `PlacedConflictsNotice` (`planning/PlacedConflictsNotice.tsx`, `role` réglé par
  l'appelant), **trois rendus** : l'encart de l'étape Coachs du wizard (`CoachesStep`, rafraîchi
  après chaque mutation de lien — `wizard/queries.ts` invalide la clé) ; le bandeau de `/planning`,
  page **autonome** seulement (`!embedded && !scoped`) et seulement sur la version en vigueur
  (`isReadOnly`) ; la pastille du bandeau de saison du cockpit (`SeasonPlanBanner`), qui compte les
  **personnes** distinctes (pas les paires). Détail métier complet :
  [`planning-lifecycle-validated.md`](../../specs/courantes/planning-lifecycle-validated.md) §2.
- **Bandeau « version antérieure » (P4-98, 2026-09-29)** : `pickLandingScheduleId` atterrit
  délibérément sur la version **en vigueur** (`isChosen`), qui peut être plus ancienne que la
  dernière `COMPLETED` — sans signal, le gestionnaire pouvait croire regarder du frais.
  `lib/versions.ts::laterCompletedVersionId(displayed, schedules)` compare `displayed` à
  `representativeVersion(...)` sur la MÊME portée de plan que `scopeInFlight` (saison via
  `isSeasonPlanType`, sinon `schedulePlanId`) : `null` si `displayed` est déjà la plus récente
  terminée du plan (un échec tout frais n'est pas « antérieur ») ; sinon l'id de la dernière
  `COMPLETED`. `PlanningPage.tsx` rend alors un `NoticeBanner` ton **muted** `role="status"`
  (distinct du warning « périmé » ci-dessus) + bouton « Ouvrir la dernière version » —
  **sélection locale seule** (`setSelectedScheduleId`), aucune écriture serveur ni navigation : la
  version en vigueur reste le calendrier. Muet pendant une génération (`showGenerationWaiting`).

### 6.6 ter Informations du club (fiche FFBB — 100 % lecture seule sauf le siège)

Le `PATCH /api/club/info` et ses quatre champs (comité éditable, correspondant, président, salle
principale) n'existent plus (`etat-des-lieux.md` §3) — l'index FFBB `organismes` ne connaît aucune
personne physique ni aucun lien club→salle. La route `/club` expose une section
**« Informations du club »** (admin uniquement, `AccordionSection`, `ClubInfoSection`) **lecture
seule FFBB**, à une exception près :

- **Identité** (`ReadOnlyField`) : Code FFBB, Ligue, Zone de vacances, Comité — auto-dérivés,
  aucune saisie. Bouton « Actualiser depuis la FFBB » (`POST /api/club/ffbb-import`,
  `FfbbClubPopulator::populate`) est le SEUL geste de correction — il n'écrase plus le siège s'il en
  existe déjà un (`only-fill-when-empty`, § ci-dessous).
- **Siège du club** (`ClubSiegeSubsection`, amende la décision 2026-08-04 — SEULE saisie de la
  page) : un `AddressGeocodeField` (primitive partagée, [`frontend-components.md`](frontend-components.md) §3) pose une
  adresse en texte, le serveur RE-géocode via `PATCH /api/club/siege` (`ClubSiegeController`,
  `backend/docs/geo-api.md` §1bis) et écrit adresse/CP/ville/lat/lon depuis SON hit fédéral —
  jamais des coordonnées client (patron SEC-15). Motif : la fédération ne fournit pas d'adresse
  fiable pour estimer les trajets vers les adversaires (`specs/courantes/module-matchs.md` §1). Un
  siège déjà choisi À LA MAIN n'est plus écrasé par « Actualiser depuis la FFBB »
  (`FfbbClubPopulator::applyClub`, only-fill-when-empty comme le seed BCCL).
- **Contact** (`ReadOnlyField`/liens) : téléphone, email, site — lecture seule FFBB, rafraîchis par
  « Actualiser depuis la FFBB ».
- Deep-link `?section=informations` (posé par le bandeau « Trajets indisponibles » de Configuration
  › Adversaires du module matchs) ouvre d'emblée cette section.

> **RGPD (minimisation).** Président et correspondant, contacts personnels, ont été **retirés en
> entier** de l'écran et de `/api/me` le 2026-08-04 (aucun automatique possible, aucune saisie
> manuelle voulue). Le siège du club est une donnée d'**établissement**, pas une PII (cohérent avec
> `Club.php:156-158`) — [`../../docs/security/rgpd.md`](../../docs/security/rgpd.md) §2.

### 6.6 quater Statistiques d'utilisation des gymnases (P3-22, 2026-08-17)

La route `/club` expose un encart **« Statistiques d'utilisation »** (`VenueStatsSection`) qui
remplace intégralement l'ancien calcul front (`computeVenueStats`/`seasonWeeks` de
`lib/venueStats.ts`, une moyenne hebdomadaire du planning en vigueur + une projection saison
brute, livré le 2026-08-04 — **supprimé avec ses tests**, `lib/venueStats.ts` ne garde plus que
`formatHours`). **Tout le calcul est désormais SERVEUR** (`GET /api/venue-usage-stats`, détail
route : `backend-inventory.md`) — le front n'agrège plus rien de métier, il affiche.

- **Deux tableaux** (`UsageTable`) : **par gymnase** et **par niveau**, mêmes colonnes — un jour
  par colonne (lundi→samedi, dimanche seulement s'il porte des heures) puis Réalisé / À venir /
  Total, et une **ligne TOTAL par jour** visuellement marquée (`border-t-2`, gras) — le chiffre
  que le gestionnaire pose sur la table de négociation avec la mairie (« le lundi, on a 8 h »).
- **Sélecteur de plage** (`<input type="date">` Du/Au) **borné à la saison courante** (`min`/`max`
  sur `season.startDate`/`season.endDate`) ; défaut = saison entière. La plage réellement
  appliquée est redite sous les champs (`data.range.from/to`, celle que le backend a résolue).
- **Sans planning en vigueur** (`me.seasonPlan.chosenScheduleId` null), la section **dit
  pourquoi** elle est vide plutôt que d'afficher des tableaux à zéro — même doctrine que le
  reste de l'app (pas de silence).
- **Ventilation par niveau, une ligne par `TeamLevel` réellement utilisé** (aucune table de
  regroupement front — le backend sérialise déjà le libellé, `TeamLevel::label()`). ⚑ **Le
  libellé de niveau existe donc en DEUX endroits** : `TeamLevel::label()` côté backend
  (source pour cet encart) et `LEVEL_LABEL` du wizard (`features/wizard/lib/labels.ts`, pour
  l'étape Équipes qui affiche le niveau sans aller-retour réseau) — duplication **assumée**
  (présentation, pas une décision métier, cf. `.claude/rules/frontend.md` régime « présentation
  autorisée »), les deux tables restant alignées par convention (docblock de `TeamLevel::label()`
  le rappelle) plutôt que par un test de parité.

> Les §6.7 (retouche manuelle) et §6.7 bis (transcription depuis le socle) vivent désormais dans `frontend-workloop.md`.

### 6.8 Loading states, error boundaries et ÉCRANS SYSTÈME (P5-14, 2026-08-21)

Chaque route a :

- Un skeleton loader pendant le chargement initial (pas de spinner vide)
- Un error boundary React qui affiche un message + bouton "Réessayer"
- Pas de page blanche en cas d'erreur API

**Les écrans système passent tous par UNE primitive** — `shared/components/ui/system-screen.tsx`
(`SystemScreen`). Elle porte la **forme** et rien d'autre : nom produit (via `PRODUCT_NAME`, jamais
un littéral), titre, corps, **un** geste principal + un secondaire, et une ligne « Code incident »
discrète quand le consommateur en fournit une. ⚠ **Aucune prop `variant`, aucun `switch` sur un type
d'écran** : chaque écran est un CONSOMMATEUR qui apporte sa copie et ses gestes. C'est ce qui tient la
règle « un seul composant d'état, jamais un deuxième » sans fabriquer un composant fourre-tout.
⚠ Contrainte dure : la primitive rend **sans aucun provider** — elle sert sous `ErrorBoundary`, monté
hors providers ; donc pas de `useQuery`, pas de `FeedbackDialog` à l'intérieur.

**Le logotype de pied est cliquable vers la vitrine (P4-302, 2026-10-05)** : le `BrandMark` du
pied de `SystemScreen` est enveloppé dans un `<a href={PRODUCT_SITE_URL} target="_blank"
rel="noopener">` (`shared/lib/product.ts`) — seul lien vers le produit porté par un écran système,
cohérent avec `system-pages/503.html`/`maintenance.html` (mêmes pages de panne, logotype cliquable
vers le même domaine, `.claude/rules/system-pages.md`). Décisions fermées (logo FIXE, le logotype
EST le lien, généralisé à tout écran du même type) : `specs/courantes/etat-des-lieux.md` §2.
`features/planning/GenerationServiceDown.tsx` (§ plus bas) ne passe pas par `SystemScreen` et
n'est pas concerné.

| Écran | Consommateur | Déclencheur |
|---|---|---|
| **404** | `app/NotFoundPage` | catch-all `*` et `/admin/*` (§2) |
| **403** | `app/ForbiddenPage` | `RouteErrorBoundary` sur une `Response` 403 — **porte générique**, aucun câblage page par page |
| **Hors ligne** (pleine page) | `app/OfflineScreen` | échec de chunk hors ligne, et échec au boot |
| **500** | `app/ServerErrorScreen` | crash de rendu (`ErrorBoundary`), et 5xx au boot |
| **Session expirée** | bloc dans `features/auth/LoginPage` | marqueur one-shot posé par `shared/api/client.ts` |
| **JavaScript coupé** | bloc `<noscript>` statique dans `index.html` | le navigateur lui-même — seul canal qui s'affiche quand la SPA ne peut pas rendre |

**La 404 sert AUSSI au refus tenant** — une ressource d'un autre club rend 404, jamais 403 (un 403
confirmerait son existence). ⚠ **Sa copie doit rester MUETTE sur les droits** : y ajouter « vous n'avez
pas accès » la transformerait en oracle d'existence. Le commentaire de `NotFoundPage` le dit, parce
que c'est exactement l'« amélioration » qu'une relecture bien intentionnée ajouterait.

**Le 403 n'a aujourd'hui aucun chemin UI qui le produise** (le front masque les gestes de gestion en
amont, `shared/lib/roles.ts` ; le 403 saison s'auto-guérit dans `client.ts`). L'écran et sa porte sont
livrés quand même — décision fondateur : le jour où un Membre atteint une route de gestion, il existe.

**JavaScript coupé (`<noscript>`, P5-14 vague 3)** : sans lui, `<div id="root">` reste vide
— page blanche muette, le cas de panne le plus silencieux. Bloc statique français (cause + geste :
réactiver puis recharger), **sans marque** (le `<title>` est l'unique littéral toléré d'`index.html`,
`shared/lib/product.ts:8-11`), zéro ressource externe, thème `prefers-color-scheme` en CSS pur.
Gardé par `frontend/tooling/noscript.test.ts` (existence, français, absence de marque, autonomie).

**La copie des messages d'erreur SERVEUR a sa règle** : `backend/docs/error-copy.md` — français
métier dès qu'un gestionnaire peut lire (nominal ou course), anglais toléré seulement hors de tout
chemin UI. Le front n'a rien à traduire : `errorMessage.ts` (`shared/lib/errorMessage.ts`, maison
UNIQUE d'erreur front depuis P4-263 — `shared/api/errors.ts`/`apiErrorMessage` a disparu) reprend
le corps 4xx tel quel, sauf s'il n'est qu'une reason-phrase HTTP anglaise brute reconnue (liste
FERMÉE `ENGLISH_STATUS_TEXTS`, filet contre un 4xx nu de Symfony/API Platform) — auquel cas il
tombe sur le repli français par statut ; il route sur `code`, jamais sur la phrase.

**Session expirée — pourquoi un marqueur et pas une page** : le 401 est capté dans `client.ts`, mais
« Se reconnecter » **EST** le formulaire de `/login` déjà présent ; une page dédiée ajouterait un clic
pour rien. Le marqueur est **one-shot**, en `sessionStorage`, sous une clé **sans nom de marque**
(leçon de `clubscheduler:wish-draft:`, piégée par le renommage). ⚠ Surtout **pas** un query param :
une URL partagée ou mise en favori afficherait le message à tort.

⚠ **`AuthGuard` n'envoie plus les 5xx vers `/login`.** Il le faisait pour TOUTE erreur de `/api/me`,
réseau compris — donc le serveur tombait et l'application répondait « reconnectez-vous ». Le vrai 401
est déjà éjecté par `client.ts`. Désormais : hors ligne → écran hors ligne, sinon → écran 500 dont
« Réessayer » refait le `refetch`.

**Le code d'incident** est l'`X-Request-Id` de corrélation déjà posé sur chaque requête et retenu 10 min
sur un ≥ 500 — jamais une stack, une exception ou du SQL. Il est déjà joint automatiquement à un
signalement : l'utilisateur n'a pas à le recopier.

**Le BANDEAU hors-ligne (livré le 2026-08-22)** — `app/OfflineBanner.tsx`, monté **dans le flux** de
`RootShell`, avant le contenu : il **empile, il ne recouvre pas** (aucun overlay, aucun z-index), et
il est monté **une seule fois** pour toutes les routes — page publique de doléances comprise, où le
coach sans réseau dans un gymnase est le cas nominal. ⚠ Il **ne double pas** `app/OfflineScreen.tsx`,
la page PLEINE qui sert quand il n'y a **rien derrière** (chunk ou `/api/me` en échec au boot) : deux
formes, deux portées, elles coexistent sans se contredire.

**Une seule source de vérité pour l'état réseau** : `shared/lib/online.ts` (`useOnline`), qui lit
l'**`onlineManager` de TanStack** — le même que celui qui décide de mettre les mutations en pause.
`RouteErrorBoundary` et `AuthGuard` y ont convergé ; plus personne ne lit `navigator.onLine` en
parallèle, sinon le bandeau et la file pourraient se contredire. ⚠ L'`onlineManager` naît
**optimiste** (`#online = true`, il ne bascule que sur un événement `window`) : `main.tsx` le **sème**
depuis `navigator.onLine` avant le render, sans quoi un démarrage hors ligne se croirait en ligne.

**Quatre états, tous dérivés du code — le compteur est le nombre RÉEL de mutations en pause**
(`useMutationState` sur `m.state.isPaused`), jamais une estimation :

| État | Ce qu'il dit | Geste |
|---|---|---|
| hors ligne, rien en attente | « Vous êtes hors ligne. Vos données restent consultables. » | aucun |
| hors ligne, N en attente | « N modification(s) en attente… **gardez cet onglet ouvert.** » | **aucun** |
| en ligne, envois en cours | l'envoi reprend | « Envoyer maintenant » **si** des mutations restent en pause |
| de retour | « De retour en ligne. » (+ « Vos modifications sont parties. » si c'est vrai) | s'efface seul à 5 s |

⚠ **Pourquoi aucun bouton hors ligne** : `resumePausedMutations()` est un **no-op** quand
`onlineManager.isOnline()` est faux (query-core `queryClient.js:206`). Un bouton y serait un mensonge
cliquable. ⚠ **Pourquoi « gardez cet onglet ouvert »** : la file vit **en mémoire** — aucun bloc
`mutations` persisté dans `shared/lib/queryClient.ts`, un rechargement la perd. La **persistance est
un lot séparé** ; tant qu'elle n'existe pas, la copie ne promet pas plus que ce que le code tient.

⚠ **Une mutation en PAUSE ne voile plus l'écran.** `ActionVeil` la comptait comme un geste en vol :
hors ligne, un clic bloquait à 0 ms puis annonçait à 10 s que « l'action continue en arrière-plan » —
**faux**, rien ne continue, elle est garée. Le prédicat `saving` l'exclut désormais. ⚠ Le contexte
**`long` reste inchangé** : un déplacement sous verdict garé qui repartirait plus tard doit rester
sous le régime bouton-Abandonner, jamais relâché en silence.

**A11y** : le conteneur visible n'est **pas** une région live ; une région `sr-only` `role="status"`
`aria-live="polite"` ne reçoit que les **transitions** — le compteur qui s'incrémente ne ré-annonce
pas (patron AUD-FRT-23/24). La couleur ne porte jamais seule le sens (icône + phrase distinctes).

**L'écran « LE SERVICE DE CALCUL NE RÉPOND PAS » (livré le 2026-08-22)** —
`features/planning/GenerationServiceDown.tsx`. ⚠ Il ne passe **pas** par `SystemScreen` : celle-ci
sert les écrans système de ROUTE, alors qu'ici c'est un **état du rail de génération**, dont la
scène EST l'identité. Il réutilise la scène de `GenerationWaiting` **sans la dupliquer** — le décor
est extrait en `GenerationScene` (cadre + deux bandes + mini-grille), avec un prop `halted` ; ce
n'est pas un `variant` déguisé, c'est un décor à deux états.

**À l'arrêt veut dire à l'arrêt** : aucune case ne se remplit, le chrono est barré, le ballon roule
au sol — un seul mouvement, lent et **arythmique**, qui ne vise aucune case. L'écran ne doit pas être
mort, mais il ne doit **jamais** laisser croire qu'un calcul tourne.
⚠ **Piège CSS, corrigé et gardé** : le bloc `prefers-reduced-motion` force `.gw-anim { opacity: 1
!important }`. Transposé tel quel, l'état arrêté aurait affiché une grille **PLEINE** — l'inverse de
l'intention, et une faute de VÉRACITÉ, pas de cosmétique. L'override `.gw-halted .gw-anim` le bat
par spécificité, et un test lit le CSS source (jsdom ne calcule aucune mise en page).

**La DISTINCTION, qui est la vraie raison de cet écran** : jusqu'ici, un service injoignable et un
planning **infaisable** empruntaient le même chemin d'échec — alors que les gestes attendus sont
opposés (attendre vs corriger ses contraintes). Elle se dérive du **`type`** du diagnostic, via
`features/planning/lib/serviceFailure.ts` (**miroir déclaré**) : `engine_timeout`, `engine_error`,
`internal_error`, `engine_status` sont écrits **uniquement par le backend quand le service n'a pas
répondu**. ⚠ **`engine_failed` en est EXCLU à dessein** — il signifie que le moteur A RÉPONDU
« failed », donc que le planning est infaisable. `isServiceDown` n'est vrai que si les diagnostics
ERROR sont **non vides et TOUS** dans la liste.
⚠ **Aucun affichage de causes en parallèle** : celui de P4-99 (`failureExplanations` /
`failureSuggestions`) reste seul et **inchangé** ; on n'a ajouté qu'un aiguillage.
Le miroir est gardé **dans les deux sens** par `backend/tests/CrossStack/EngineFailureTypeMirrorTest`
(groupe `contract`, job `engine-semantics`, required check) — sans lui, un type ajouté au handler
ferait classer une **panne** en « infaisable », en silence.

**« Il n'y a rien à corriger de votre côté »** est la seule phrase auto-disculpante du jeu d'écrans,
et elle est là pour ça : c'est le seul écran qu'on peut confondre avec une faute de saisie.

⚠ **La « référence support » n'est jamais fabriquée.** L'échec de génération est **asynchrone** — le
POST rend 202, l'échec arrive en `status: FAILED` dans des réponses **200** — or `lastIncidentStore`
n'enregistre un incident que sur un **≥ 500** (depuis P4-129, 2026-08-23, il capture `{status, url, code?, requestId?}` — request-id présent OU NON : le 502 nginx qui a motivé la ligne n'en portait pas, et l'ancien rail ne retenait rien ; le wrapper `readRecentIncidentRequestId` préserve le contrat de la modale de signalement). La ligne « Code incident » ne s'affiche donc que
si une valeur fraîche existe (rare ici). La corrélation honnête est le **`scheduleId`**, joint
automatiquement au signalement — à condition que « Contacter le support » ouvre `FeedbackDialog` en
**`variant="contextual"`** : en `free`, le contexte reste à quai.

**`launch.isError` seul ne route PAS vers cet écran** : un 4xx est du métier servi (422 épinglage
orphelin, 403 crédits) et garde son `launchReason` ; un échec réseau du POST relève du rail
hors-ligne/500 — le service de calcul n'a même pas été sollicité.

**En DEV seulement (P4-129)** : sous le message grand public de tout écran système (et de
`GenerationServiceDown`), un bloc repliable « Détails techniques (dev) » — statut réel, URL, code
machine s'il existe, `X-Request-Id`, horodatage figé au montage — en deux groupes (« Cet écran » /
« Dernier incident serveur (peut être sans lien avec cet écran) »). Gardé `import.meta.env.DEV` lu
au RENDU : physiquement absent du bundle prod (prouvé par grep du bundle ET par test sous
`vi.stubEnv`), hors de toute live region, jamais focusé au montage.

**Hors de cette tranche** : la **503/maintenance**, geste d'ops (Caddy `handle_errors`), pas du code
applicatif ; et cet écran dans la boucle de travail `/planning`, qui garde son traitement propre.

---

### 6.9 Largeurs — écran dense, page fiche, modale (P4-107)

**La règle, en une phrase : la largeur se choisit par le TYPE d'écran, jamais au cas par cas
dans le fichier qui la subit.** Elle n'était écrite nulle part avant le 2026-08-21 — c'est ce
silence qui a produit la dérive corrigée par la 3ᵉ tranche de P4-107 (six modales avaient
bricolé quatre valeurs différentes, deux pages fiche vivaient à une largeur de mobile élargi
sous un shell devenu pleine largeur).

| Type d'écran | Largeur | Où elle vit |
|---|---|---|
| **Dense** (grille de planning, wizard, module matchs, scène d'attente) | **pleine largeur** — le shell ne borne rien (`AppLayout.tsx`, 1ʳᵉ tranche, PR #613) | l'écran lui-même |
| **Fiche** (Club, Profil, Nouveautés) | **832 px** (`--container-fiche: 52rem`) | `FichePage` (`shared/components/ui/fiche-page.tsx`) |
| **Texte long** (Confidentialité) | `max-w-2xl` | la page |
| **Modale** | 4 paliers nommés — 448 / 576 / 768 / 1152 px | `MODAL_WIDTH` (`shared/components/ui/modal-width.ts`) |

**Modales — la prop `size`, et rien d'autre.** `Modal` n'a **plus de prop `className`** : choisir
un palier est le seul geste offert, et `tsc` rougit sur toute récidive. Les paliers montent avec
le viewport puis **s'arrêtent** (tous atteignent leur plafond dès `lg:`, soit 1024 px de
viewport : un portable et un 1920 affichent la même largeur) :

- `sm` — confirmation, geste destructif : 448 px, **constant** (elle ne grandit jamais) ;
- `md` — **défaut**, formulaire de 6 champs au plus : plafond 576 px ;
- `lg` — formulaire long, liste : plafond 768 px (sous la largeur des fiches) ;
- `xl` — contenu tabulaire, comparaison de plannings : plafond 1152 px.

`confirm-dialog.tsx` et `EvictConfirmDialog.tsx` recopient le markup du panneau (duplication
assumée et commentée) mais **lisent `MODAL_WIDTH.sm`** : deux copies du markup, une seule
échelle.

**Pourquoi un plafond.** La passe de design `ui-ux-pro-max` n'endosse que des échelles qui
terminent sur une largeur fixe (`DON'T Full-width text on large screens`) : une modale qui
suivrait indéfiniment le viewport rejouerait sur 1920 px l'anti-pattern qu'on corrige sur 448.

**Pourquoi une borne de lisibilité dans les fiches.** `FichePage` porte `[&_p]:max-w-prose` :
la seule mesure chiffrée du corpus de design est 65-75 caractères par ligne, et elle vaut à
l'INTÉRIEUR d'un conteneur plus large — élargir le cadre sans borner les paragraphes
échangerait un défaut contre un autre. La borne ne vit pas dans `AccordionSection` : il a
quatre autres consommateurs (écrans du wizard, pleine largeur par conception) qu'elle aurait
reflowés en silence.

**Ce qui garde quoi.** `modal-size.test.tsx` et `fiche-page.test.tsx` épinglent les CLASSES
(égalité d'ENSEMBLE : une classe manquante rougit, une classe en trop aussi — c'est elle qui
attrape une reprise de croissance au-delà du plafond). Ils ne peuvent pas voir qu'une classe
n'engendre aucun CSS : jsdom n'a pas de moteur de mise en page. Les PIXELS se mesurent en
Playwright — `tests/e2e/width-calibration.spec.ts` (fiche à 832 px et paragraphe borné, sur
1920×1080) et `tests/e2e/modal-reachability.spec.ts` (le palier `xl` atteint 1152 px et s'y
arrête).

**Écrans denses : la largeur se DÉPENSE, elle ne se subit pas (4ᵉ tranche, 2026-08-21).** Un
écran dense n'a pas de cap — le défaut n'est jamais « trop étroit », c'est « la place est là et
personne ne s'en sert ». Deux formes, mesurées à 1920×1080 :

- **Une liste de lignes courtes devient un TABLEAU**, pas une pile de barres pleine largeur.
  Les contraintes du wizard étalaient ~50 caractères sur ~1650 px avec les actions à ~1400 px du
  libellé qu'elles concernent. `<table>` + `<thead>`/`<tbody>` **sémantiques** — pas une grille de
  `<div>` : c'est la seule règle de sévérité HAUTE rendue par la passe de design sur ce lot, et
  c'est elle qui fait annoncer « Règle : pas après » par un lecteur d'écran. Les regroupements
  survivent en lignes d'en-tête (`<th scope="rowgroup">`, pour ne pas polluer les en-têtes de
  colonnes). Cible de clic : `p-1.5 -m-1.5` autour d'une icône de 16 px donne 28 px cliquables
  **sans épaissir la ligne**.
- **Un champ se dimensionne sur la valeur qu'il doit MONTRER.** Le tableau Équipes faisait
  l'inverse : le nom prenait ~1050 px pour afficher `SM1` pendant que les sélecteurs coupaient
  leur propre valeur (« Homn » pour « Homme ») — nommément un DON'T du corpus de design
  (« Overflow or cut off »).

⚑ **Ce que le corpus de design ne dit PAS, et qu'on n'a donc pas le droit de lui faire dire** :
il est muet sur les lignes de groupe dans un tableau, sur la largeur d'un tableau de données, sur
la taille d'une tuile de KPI et sur les accordéons. La borne de la bande de cartes du Récap est
un choix ERGONOMIQUE (distance œil-chiffre), pas une prescription : `max-w-3xl` est un jeton du
corpus emprunté hors de sa règle (elle porte sur la longueur de ligne d'un TEXTE).

**La hauteur suit la même règle.** La grille de saisie Gymnases affiche 08:00→23:00 : la plage
reste ENTIÈRE (on y crée des créneaux au clic — rogner rendrait 09:00 inatteignable, et P4-37
interdit de masquer ce qui existe), mais la vue **s'ouvre positionnée sur la bande utile** du
gymnase affiché. Positionnement **instantané** (`useLayoutEffect`, jamais `smooth` : au montage
il n'y a aucun saut à adoucir, et animer fabriquerait le « forced scroll effect » que le corpus
interdit), rejoué au changement de gymnase (chaque gymnase a SA bande), et **jamais** repris une
fois que l'utilisateur a défilé. Aucune annonce lecteur d'écran : le DOM ne change pas, seul
l'offset diffère — la gouttière d'heures collante affiche « 17:30 » et dit à elle seule qu'on
n'est pas au début.

⚠ **Reste hors de ce chantier** : la grille de PLANNING, qui est réellement saturée (5 jours ×
9 gymnases débordent au-delà de 1920) — autre problème, autres leviers ; le module matchs a depuis
eu sa refonte UX propre (P2-26, livrée — `specs/courantes/module-matchs.md`). Le pseudo-tableau en
`<span>` de l'étape Équipes (`TeamsStep.tsx`) reste lui aussi en l'état : ce lot n'y touche que des
largeurs.

---

