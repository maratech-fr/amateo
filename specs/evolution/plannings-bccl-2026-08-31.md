# Programme plannings BCCL — saison, reprises, overlay « Mateo indisponible »

> Détail actif de la ligne **P2-58** de [`roadmap.md`](roadmap.md). Cadré avec le fondateur le
> 2026-08-31 (session de travail sur sa base de jeu `amateo_local`). Ce fichier consigne les
> décisions ARBITRÉES, les faits VÉRIFIÉS au code, et l'état d'avancement — pour reprendre le
> chantier sans ré-arbitrer si la session s'interrompt.

## 0. Le principe directeur (décision fondateur, non négociable)

**Le planning réel du club est la SPÉCIFICATION.** Les fichiers
`business/5-donnees/plannings-bccl/*.txt` (local-only, jamais versionnés) décrivent ce que le
gestionnaire a réellement défini — on ne les « tord » pas pour rentrer dans les limites de
l'application : **c'est l'application qui doit les tolérer**. Quand une limite du produit empêche
de représenter le réel (cf. §4 mutualisations), c'est une évolution produit à faire, pas un
contournement de données.

## 1. La cible finale

À la fin du programme, `BcclSeeder` (profil **dev**, club réel ARA0069036, `mara.mb@bccl.fr`)
contient **quatre plannings** :

1. le planning de **saison** (socle) — déjà transcrit et choisi (90 créneaux) ;
2. la **reprise du 17 août** — transcrite (25 créneaux) ;
3. la **reprise du 24 août** — transcrite (38 créneaux) ;
4. l'**overlay « Mateo indisponible »** du **31/08 au 16/10** (veille de la Toussaint) — à
   construire, remplace l'incident seedé actuel (« travaux », 18/08→30/09, fenêtre périmée).

Et chaque planning de vacances/overlay a subi **l'exercice de vérité du solveur** (§2).

## 2. La méthode par planning (validée le 2026-08-31)

Ordre : **reprise 17 août → reprise 24 août → overlay Mateo** (la saison a déjà eu son exercice).

1. **Contraintes co-construites** — proposition dérivée de la structure du planning cible,
   arbitrée par le fondateur AVANT toute écriture (règle : jamais de réglage du club réel sans
   options posées).
2. **Génération** par le solveur, en mode période.
3. **Mesure d'écart** créneau par créneau contre la cible transcrite (combien justes, combien
   déplaçables, combien inatteignables). Un écart est un RÉSULTAT (signal produit), jamais
   quelque chose à maquiller.
4. **Quelques déplacements manuels** — la donnée « ce qu'un vrai gestionnaire vivra ».
5. **La version validée est le fichier**, à la lettre (transcription), quoi qu'ait produit le
   solveur. ⚠ **Pas de tout-en-réservation** : solution de facilité explicitement refusée.

Conduite : chaque écriture en base annoncée AVANT, GO par GO (base de jeu du fondateur).

## 3. Décisions déjà arbitrées (2026-08-31)

| # | décision | détail |
|---|---|---|
| D1 | **La semaine du 31/08 est couverte par l'OVERLAY** | pour le BCCL. Les vacances d'été du référentiel finissent le lundi 31/08, la saison commence le 01/09 — semaine à cheval. |
| D2 | **L'incident seedé est REMPLACÉ** | « Matéo indisponible (travaux) » 18/08→30/09 → « incident » 31/08→16/10. Pas de coexistence. |
| D3 | **La durée d'une indisponibilité devient modifiable, dans LES DEUX SENS** | étendre ET rétrécir. Aujourd'hui figée par construction dès qu'un plan existe (`CalendarEntryStateProcessor:118` — « Supprimez la période puis recréez-la », choix ADR-0002 : grille copiée à la naissance). Rétrécir pose la question des créneaux orphelins hors fenêtre. Axe §7.1 planning lifecycle — cadrage dédié. |
| D4 | **Semaine charnière : le gestionnaire CHOISIT le type qui la couvre** | une semaine à cheval vacances/saison (ex. 31/08) est proposée en reprise OU en overlay **tant qu'elle n'est gérée par aucun** ; dès qu'un la prend, l'autre ne la propose plus. Motif fondateur : « pour un humain, il est impensable que la semaine du 31 août ne soit pas la semaine de reprise — mais d'autres structures peuvent voir l'inverse. D'où le choix. » |
| D5 | **Les mutualisations « comme le gestionnaire l'entend » = P2-51, déjà cadré** | Reconfirmé le 2026-08-31 (« je dois pouvoir faire mes mutualisations comme je l'entends ») — voir §4 : le cadrage du 2026-08-25 tient, ses décisions sont figées, le lot entre dans ce programme. |
| D6 | Le nom d'équipe `Veterans` reste sans accent | les fichiers du fondateur l'écrivent de deux façons (`Vétérans`/`Véterans`) ; renommer toucherait 5 sites pour du cosmétique. À rouvrir s'il y tient. |

## 4. Les mutualisations : le lot P2-51, déjà cadré — PAS un nouveau chantier

**Le réel** : 8 partages de créneau (lignes « + » du fichier saison), 5 équipes
multi-appartenances (U9F1, U9F2, U9M1, U9M2, U13F2). Le fondateur l'a reconfirmé le 2026-08-31 :
le planning réel est la spécification, l'app doit savoir le dire.

**Ce besoin est DÉJÀ cadré : [`mutualisation-par-creneau.md`](mutualisation-par-creneau.md)
(P2-51, 2026-08-25)** — la même liste de 8 partages, verbatim, y figure. Ses décisions sont
figées, à ne pas re-discuter :
- la mutualisation **par créneau** s'AJOUTE (« ce créneau précis est partagé par ces équipes »),
  patron `MatchSlotRotation` ; le groupe {équipes, K} existant **reste intact** ;
- motif profond, revérifié indépendamment le 2026-08-31 : lever l'unicité un-groupe-par-équipe ne
  suffirait PAS — l'exact-K du solveur **se double-compte sur les partages imbriqués** (la séance
  du trio CEC compte aussi pour la paire incluse). La représentation par groupes est
  structurellement incapable de dire le terrain.

Les 3 **CEC** du mercredi restent correctement AFFICHÉS par le `groupLabel` du créneau (P2-17,
`BcclSeeder:394-396,491`) — mais leur sémantique solveur attend, elle aussi, P2-51.

**Conséquence de séquencement (à trancher au démarrage du lot suivant)** : l'exercice solveur des
reprises (§2) a besoin d'une sémantique de mutualisation pour être fidèle. Deux voies —
attendre P2-51 (effort L), ou un palliatif provisoire par groupes déclarés là où il est SAIN
(paires sans imbrication ni multi-appartenance uniquement). Non tranché le 2026-08-31.

**Question OUVERTE (c)** : libellé sur les 5 créneaux des paires pour l'affichage fusionné (la
grille ne fusionne que les créneaux libellés — `grid.ts:100,343`) ? Un groupe déclaré ou un
partage P2-51 change le solveur, PAS la fusion visuelle. Non tranché.

## 5. Défauts et travaux connexes relevés en chemin

- **Bandeau « périmé » sur base FRAÎCHE (défaut seeder)** : les 3 versions transcrites naissent
  avec `constraints_changed_since_generation=true` et `resources_changed=true` — le seeder crée
  les versions PUIS continue d'insérer, et `ConstraintChangeStaleScheduleListener` estampille à
  chaque écriture. Faux par construction (la transcription égale l'état final seedé). Correctif :
  remettre les drapeaux à zéro en fin de seed. → **PR seeder à venir**.
- **`make play` ne seede que le club démo** (ARA9999999, sans plannings — décision « la démo reste
  avant première génération », `BcclSeedProfile:186`, qui NE s'applique qu'à la démo). La base de
  jeu du fondateur doit porter le **BCCL réel complet** : apprendre à `make play` à seeder les
  deux clubs (aujourd'hui : `make fixtures` manuel, fait le 2026-08-31).
- **La mémoire « recalage seed à faire » était périmée** : les 90 placements du fichier saison
  sont DÉJÀ identiques au seeder (diff mécanique, seul écart l'accent Veterans), la correction
  Anna U11M1 est faite (`BcclSeeder:810`). Les mutualisations « à recaler » = la représentation P2-51 (§4), pas les créneaux.

- **Les 10 passerelles réelles ne sont pas seedées** (P5-23, déjà tracée) : le modèle `TeamLink`
  les porte depuis P2-34, indépendantes de P2-51 — peuvent entrer au seeder à tout moment.

## 6. État d'avancement

| étape | état |
|---|---|
| Base de jeu = BCCL réel + plannings (via `make fixtures`) | ✅ 2026-08-31 |
| Diff seeder ↔ fichier saison (90=90) | ✅ vérifié |
| Consignation du programme (ce fichier) | ✅ |
| PR seeder : drapeaux « périmé » (+ passerelles P5-23 si le fondateur valide) | ⬜ suivant |
| P2-51 mutualisation par créneau — démarrage (cadrage déjà fait) | ⬜ |
| Exercice solveur reprise 17 août | ⬜ séquencement §4 à trancher |
| Exercice solveur reprise 24 août | ⬜ |
| Overlay Mateo 31/08→16/10 (D2, D1) + exercice | ⬜ |
| Semaine charnière au choix (D4) — cadrage | ⬜ |
| Durée d'indispo modifiable (D3) — cadrage | ⬜ |
| PR seeder finale : overlay transcrit + `make play` complet | ⬜ |
